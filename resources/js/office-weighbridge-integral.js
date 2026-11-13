// Corrección administrativa de una recepción ya validada, en dos modos:
// - completa: guías, conteo por segmento, propiedad y destare; el servidor recibe el estado correcto completo;
// - solo cuenta de envases: lo recibido y lo entregado correctos por envase, sin tocar guías, lotes ni kilos.

const PROPERTY_LABELS = { cliente: 'Cliente', propia: 'Propia', arrendada: 'Arrendada' };
const EXIT_LABELS = { mismos: 'Mismos envases', diferentes: 'Otros envases o cantidades', vacio: 'Camión vacío' };
const SIDES = ['recibidos', 'entregados'];

export function createIntegralCorrection({ dialog, api, escapeHtml: esc, operationUuid, toast, onSaved }) {
    let estado = null;
    let attempt = null;
    let tipos = [];
    let mode = 'completa';
    // Lo digitado en «Solo cuenta de envases»: valores correctos por envase y lado, y el CSG de la diferencia.
    let cuenta = { valores: {}, productor: '' };

    const number = (value) => Math.max(0, Math.trunc(Number(value) || 0));
    const kg = (value) => `${new Intl.NumberFormat('es-CL', { maximumFractionDigits: 3 }).format(Number(value) || 0)} kg`;
    const typeName = (code) => estado.tipos_envase.find((tipo) => tipo.codigo === code)?.nombre || code;
    const amounts = (list) => Object.fromEntries((list || []).map((item) => [item.tipo_envase, Number(item.cantidad) || 0]));
    const toList = (map) => tipos.map((tipo) => ({ tipo_envase: tipo, cantidad: number(map[tipo]) })).filter((item) => item.cantidad > 0);
    const carriesFruit = (code) => estado.tipos_envase.find((tipo) => tipo.codigo === code)?.contiene_fruta === true;
    const emptyAmounts = (list) => Object.fromEntries((list || []).map((item) => [item.tipo_envase, Number(item.cantidad_vacia) || 0]));
    // En el segmento, `cantidad` son los envases con fruta y `cantidad_vacia` los que llegaron vacíos.
    const segmentList = (full, empty) => tipos.map((tipo) => ({ tipo_envase: tipo, cantidad: number(full[tipo]), cantidad_vacia: carriesFruit(tipo) ? number(empty[tipo]) : 0 }))
        .filter((item) => item.cantidad > 0 || item.cantidad_vacia > 0);
    const signed = (value) => `${value > 0 ? '+' : ''}${value}`;
    const accountInfo = (tipo) => estado.cuenta_envases?.tipos?.find((item) => item.tipo_envase === tipo)
        || { tipo_envase: tipo, recibidos_cuenta: 0, recibidos_registro: 0, entregados_cuenta: 0, entregados_registro: 0 };
    const sideEditable = (side) => side === 'recibidos' || Boolean(estado.cuenta_envases?.entregados_editables);
    const accountValue = (tipo, side) => cuenta.valores[tipo]?.[side] ?? accountInfo(tipo)[`${side}_cuenta`];
    const day = (iso) => (iso ? new Intl.DateTimeFormat('es-CL', { day: '2-digit', month: '2-digit', year: 'numeric' }).format(new Date(iso)) : '');

    function inputs(prefix, id, map) {
        return tipos.map((tipo) => `<td><input class="integral-qty" type="number" min="0" max="100000" step="1" inputmode="numeric"
            data-${prefix}="${esc(id)}" data-tipo="${esc(tipo)}" value="${number(map[tipo])}" aria-label="${esc(typeName(tipo))}"></td>`).join('');
    }
    function emptyInputs(id, map) {
        return tipos.map((tipo) => carriesFruit(tipo) ? `<td><input class="integral-qty" type="number" min="0" max="100000" step="1" inputmode="numeric"
            data-vacio="${esc(id)}" data-tipo="${esc(tipo)}" value="${number(map[tipo])}" aria-label="${esc(typeName(tipo))} vacíos"></td>` : '<td></td>').join('');
    }
    function header(first) {
        return `<tr><th>${first}</th>${tipos.map((tipo) => `<th>${esc(typeName(tipo))}</th>`).join('')}</tr>`;
    }
    function read(prefix) {
        const result = {};
        dialog.querySelectorAll(`[data-${prefix}]`).forEach((input) => {
            const id = input.dataset[prefix];
            result[id] ??= {};
            result[id][input.dataset.tipo] = number(input.value);
        });
        return result;
    }
    function snapshot() {
        // Conserva lo digitado al agregar un tipo de envase, cambiar de modo y volver a dibujar.
        const form = dialog.querySelector('#integralForm');
        if (!form) return;
        estado.motivo = form.elements.motivo_correccion.value;
        if (mode === 'cuenta') {
            SIDES.forEach((side) => dialog.querySelectorAll(`[data-cuenta-${side}]`).forEach((input) => {
                const tipo = input.getAttribute(`data-cuenta-${side}`);
                cuenta.valores[tipo] = { ...cuenta.valores[tipo], [side]: number(input.value) };
            }));
            const productor = dialog.querySelector('[name="productor_csg"]');
            if (productor) cuenta.productor = productor.value;
            return;
        }
        const guides = read('guia');
        const segments = read('segmento');
        const empties = read('vacio');
        const received = read('recibido');
        estado.guias.forEach((g) => { g.numero_guia_despacho = dialog.querySelector(`[data-numero="${g.id}"]`)?.value ?? g.numero_guia_despacho; g.envases = toList(guides[g.id] || {}); });
        estado.segmentos.forEach((s) => { s.envases = segmentList(segments[s.id] || {}, empties[s.id] || {}); });
        if (estado.tipo_recepcion === 'solo_envases') estado.envases_validados = toList(received.total || {});
        dialog.querySelectorAll('[data-propiedad]').forEach((select) => { estado.propiedades[select.dataset.propiedad] = select.value; });
        dialog.querySelectorAll('[data-tara]').forEach((input) => { estado.taras[input.dataset.tara] = input.value; });
        if (estado.destare) {
            const form = dialog.querySelector('#integralForm').elements;
            estado.destare.peso_tara = form.peso_tara.value;
            estado.destare.modo_salida_envases = form.modo_salida_envases.value;
            estado.destare.numero_guia_salida = form.numero_guia_salida.value;
            estado.destare.salida_envases = toList(read('salida').total || {});
        }
    }

    function validated() {
        if (estado.tipo_recepcion === 'solo_envases') return amounts(estado.envases_validados);
        const sums = {};
        // Lo recibido incluye los vacíos: son envases del cliente aunque no traigan fruta.
        estado.segmentos.forEach((s) => s.envases.forEach((e) => { sums[e.tipo_envase] = (sums[e.tipo_envase] || 0) + e.cantidad + (e.cantidad_vacia || 0); }));
        return sums;
    }
    function declared() {
        const sums = {};
        estado.guias.forEach((g) => g.envases.forEach((e) => { sums[e.tipo_envase] = (sums[e.tipo_envase] || 0) + e.cantidad; }));
        return sums;
    }

    function accountPreview() {
        const cambios = [];
        const efecto = {};
        tipos.forEach((tipo) => SIDES.forEach((side) => {
            const info = accountInfo(tipo);
            const cell = dialog.querySelector(`[data-ajuste-${side}="${CSS.escape(tipo)}"]`);
            const correcto = accountValue(tipo, side);
            const ajuste = correcto - info[`${side}_registro`];
            if (cell) {
                cell.textContent = signed(ajuste);
                cell.classList.toggle('integral-diff', ajuste !== 0);
            }
            if (!sideEditable(side) || correcto === info[`${side}_cuenta`]) return;
            cambios.push(`<li><strong>${esc(typeName(tipo))}</strong> · ${side}: ${info[`${side}_cuenta`]} → ${correcto} en cuenta
                <small>Queda un ajuste de ${signed(ajuste)} contra lo registrado (${info[`${side}_registro`]}).</small></li>`);
            efecto[tipo] = (efecto[tipo] || 0) + (correcto - info[`${side}_cuenta`]) * (side === 'recibidos' ? 1 : -1);
        }));
        const saldo = Object.entries(efecto).filter(([, value]) => value !== 0)
            .map(([tipo, value]) => `${esc(typeName(tipo))} ${signed(value)}`).join(' · ');
        dialog.querySelector('#integralPreview').innerHTML = cambios.length
            ? `<ul class="integral-lots">${cambios.join('')}</ul><p class="integral-net">Efecto en el saldo del cliente: <strong>${saldo || 'sin cambio'}</strong>
                <small>Sin efecto en guías, Validación MP, lotes, destare, kilos ni existencia física.</small></p>`
            : '<p class="integral-help">Sin cambios: indica los recibidos o entregados correctos.</p>';
    }

    function preview() {
        snapshot();
        if (mode === 'cuenta') {
            accountPreview();
            return;
        }
        const nuevos = validated();
        const antes = amounts(estado.original.envases.map((e) => ({ tipo_envase: e.tipo_envase, cantidad: e.cantidad_validada })));
        const filas = tipos.map((tipo) => {
            const diff = (nuevos[tipo] || 0) - (antes[tipo] || 0);
            return `<tr><td>${esc(typeName(tipo))}</td><td>${antes[tipo] || 0}</td><td><strong>${nuevos[tipo] || 0}</strong></td><td class="${diff ? 'integral-diff' : ''}">${diff > 0 ? '+' : ''}${diff}</td><td>${declared()[tipo] || 0}</td></tr>`;
        }).join('');
        let neto = '';
        if (estado.destare) {
            const d = estado.destare;
            const salidas = d.modo_salida_envases === 'mismos' ? nuevos : d.modo_salida_envases === 'vacio' ? {} : amounts(d.salida_envases);
            const taraEnvases = tipos.reduce((total, tipo) => total + ((nuevos[tipo] || 0) - (salidas[tipo] || 0)) * (Number(estado.taras[tipo]) || 0), 0);
            const resultado = Number(estado.original.destare.peso_bruto) - Number(d.peso_tara || 0) - taraEnvases;
            neto = `<p class="integral-net">Neto de fruta: ${esc(kg(estado.original.destare.peso_neto))} → <strong>${esc(kg(resultado))}</strong>
                <small>Bruto ${esc(kg(estado.original.destare.peso_bruto))} − tara ${esc(kg(d.peso_tara))} − envases que quedan ${esc(kg(taraEnvases))}</small></p>`;
        }
        dialog.querySelector('#integralPreview').innerHTML = `<table class="integral-table"><thead><tr><th>Envase</th><th>Recibido antes</th><th>Recibido corregido</th><th>Ajuste en cuenta</th><th>Declarado en guías</th></tr></thead><tbody>${filas}</tbody></table>${neto}`;
    }

    function addTypeField() {
        const otros = estado.tipos_envase.filter((tipo) => !tipos.includes(tipo.codigo));
        return `<div class="integral-add">${otros.length ? `<label class="field"><span>Agregar tipo de envase</span><select id="integralAddType"><option value="">Seleccionar…</option>${otros.map((tipo) => `<option value="${esc(tipo.codigo)}">${esc(tipo.nombre)}</option>`).join('')}</select></label>` : ''}</div>`;
    }

    function modeField() {
        const account = estado.cuenta_envases;
        if (!account) return '';
        return `<fieldset class="integral-mode"><legend>Qué corregir</legend>
            <label class="integral-mode__option"><input type="radio" name="integral_modo" value="completa" ${mode === 'completa' ? 'checked' : ''}>
                <span><strong>Recepción completa</strong><small>Guías, conteo, propiedad, destare, lotes y kilos.</small></span></label>
            <label class="integral-mode__option"><input type="radio" name="integral_modo" value="cuenta" ${mode === 'cuenta' ? 'checked' : ''} ${account.disponible ? '' : 'disabled'}>
                <span><strong>Solo cuenta de envases</strong><small>${account.disponible ? 'No toca guías, lotes ni kilos: sirve aunque los lotes tengan entregas a proceso.' : esc(account.motivo_no_disponible || 'No disponible para esta recepción.')}</small></span></label>
        </fieldset>`;
    }

    function accountTable(side) {
        const editable = sideEditable(side);
        return `<div class="integral-scroll"><table class="integral-table"><thead><tr><th>Envase</th><th>Registrado</th><th>En cuenta hoy</th><th>Correcto</th><th>Ajuste</th></tr></thead><tbody>
            ${tipos.map((tipo) => {
                const info = accountInfo(tipo);
                return `<tr><td>${esc(typeName(tipo))}</td><td>${info[`${side}_registro`]}</td><td>${info[`${side}_cuenta`]}</td>
                    <td><input class="integral-qty" type="number" min="0" max="100000" step="1" inputmode="numeric" data-cuenta-${side}="${esc(tipo)}"
                        value="${number(accountValue(tipo, side))}" aria-label="${esc(typeName(tipo))} ${side} correctos" ${editable ? '' : 'disabled'}></td>
                    <td data-ajuste-${side}="${esc(tipo)}"></td></tr>`;
            }).join('')}
        </tbody></table></div>`;
    }

    function accountBody() {
        const account = estado.cuenta_envases;
        const entregados = !account.cerrada
            ? '<p class="integral-help">La recepción aún no tiene destare: solo se ajustan los recibidos.</p>'
            : `${account.salida_conciliada ? '<p class="integral-help">La salida está conciliada con un despacho: sus entregados se corrigen en ese despacho.</p>' : ''}${accountTable('entregados')}`;
        const productor = account.exige_productor
            ? `<label class="field integral-producer"><span>Productor (CSG) de la diferencia de recibidos *</span><select name="productor_csg">
                <option value="">Seleccionar…</option>${account.productores.map((p) => `<option value="${esc(p.codigo)}" ${cuenta.productor === p.codigo ? 'selected' : ''}>CSG ${esc(p.codigo)}${p.nombre ? ` · ${esc(p.nombre)}` : ''}</option>`).join('')}
            </select></label>` : '';
        const historial = account.ajustes.length
            ? `<section><h3>Ajustes anteriores</h3><ul class="integral-lots">${account.ajustes.map((a) => `<li><strong>${esc(a.nombre)} · ${esc(a.lado)} ${a.registro} → ${a.correcto}</strong> (${signed(a.diferencia)})
                · ${esc(day(a.registrado_at))}${a.usuario ? ` · ${esc(a.usuario)}` : ''}${a.vigente ? '' : ' · <em>reemplazado</em>'}<small>Motivo: ${esc(a.motivo)}</small></li>`).join('')}</ul></section>`
            : '';
        return `<p class="integral-help">Registrado es lo que dejó la recepción (Validación MP y destare). Indica lo correcto: queda un solo ajuste por envase,
                con la fecha de la recepción, y el estado de cuenta lo muestra en su propia línea con el motivo.</p>
            <section><h3>Recibidos</h3>${accountTable('recibidos')}</section>
            <section><h3>Entregados en el destare</h3>${entregados}</section>
            ${addTypeField()}
            ${productor}
            ${historial}
            <section><h3>Resultado</h3><div id="integralPreview" aria-live="polite"></div></section>`;
    }

    function render() {
        const account = mode === 'cuenta';
        dialog.innerHTML = `<form class="weighbridge-dialog__shell integral-shell" id="integralForm" novalidate>
            <header class="weighbridge-dialog__heading"><div><p class="eyebrow">CORRECCIÓN ADMINISTRATIVA · RECEPCIÓN VALIDADA</p>
                <h2>Corregir ${esc(estado.numero_recepcion)}</h2>
                <p>${account
                    ? 'Ajusta solo la cuenta corriente de envases del cliente. Guías, lo declarado, Validación MP, lotes, destare y kilos quedan como están.'
                    : 'Registra los valores correctos. La cuenta corriente conserva lo original y agrega el ajuste enlazado; los lotes recalculan sus kilos con el destare.'}</p></div>
                <button class="dialog-close" type="button" data-cerrar aria-label="Cerrar">×</button></header>
            ${modeField()}
            ${account ? accountBody() : fullBody()}
            <label class="field"><span>Motivo de la corrección *</span><textarea name="motivo_correccion" rows="3" minlength="10" maxlength="2000" required
                placeholder="${account ? 'Qué estaba mal y cómo se verificó. Se imprime en el estado de cuenta del cliente.' : 'Qué estaba mal y cómo se verificó'}">${esc(estado.motivo || '')}</textarea></label>
            <p class="form-error" id="integralError" role="alert"></p>
            <footer class="dialog-actions"><button class="secondary-button" type="button" data-cerrar>Cancelar</button><button class="primary-button" type="submit">${account ? 'Guardar ajuste de cuenta' : 'Guardar corrección'}</button></footer>
        </form>`;
        preview();
    }

    function fullBody() {
        const cerrada = Boolean(estado.destare);
        const lotes = estado.lotes.length
            ? `<ul class="integral-lots">${estado.lotes.map((lote) => `<li><strong>${esc(lote.numero_lote)}</strong> · ${esc(lote.kilos_netos === null ? 'sin kilos' : kg(lote.kilos_netos))}${lote.con_entregas ? ` · <em>con entregas a proceso: no admite cambios de conteo ni de kilos${estado.cuenta_envases?.disponible ? '. Para corregir los envases del cliente usa «Solo cuenta de envases».' : ''}</em>` : ''}</li>`).join('')}</ul>`
            : '<p class="integral-help">Esta recepción aún no tiene lotes.</p>';
        return `<section><h3>Guías y envases declarados</h3>
                <div class="integral-scroll"><table class="integral-table"><thead><tr><th>N° guía</th>${tipos.map((tipo) => `<th>${esc(typeName(tipo))}</th>`).join('')}</tr></thead><tbody>
                ${estado.guias.map((g) => `<tr><td><input class="integral-guide" data-numero="${esc(g.id)}" value="${esc(g.numero_guia_despacho)}" maxlength="80" required aria-label="Número de guía"></td>${inputs('guia', g.id, amounts(g.envases))}</tr>`).join('')}
                </tbody></table></div></section>
            ${estado.tipo_recepcion === 'solo_envases' ? `<section><h3>Envases recibidos</h3><div class="integral-scroll"><table class="integral-table"><thead>${header('')}</thead><tbody>
                <tr><td>Conteo real</td>${inputs('recibido', 'total', amounts(estado.envases_validados))}</tr></tbody></table></div></section>`
            : `<section><h3>Conteo de Validación MP por segmento</h3>
                ${estado.cantidades_editables ? '' : '<p class="integral-help">En pesaje acumulativo el conteo viene de las tandas: corrígelas en Romana.</p>'}
                <div class="integral-scroll"><table class="integral-table"><thead>${header('Segmento')}</thead><tbody>
                ${estado.segmentos.map((s) => `<tr><td><strong>${s.secuencia}</strong> · Guía ${esc(estado.guias.find((g) => g.id === s.guia_recepcion_romana_id)?.numero_guia_despacho || '—')}<small>CSG ${esc(s.csg || '—')} · ${esc(s.variedad || '')}${s.lotes.length ? ` · Lote ${esc(s.lotes.join(', '))}` : ''} · con fruta</small></td>${inputs('segmento', s.id, amounts(s.envases))}</tr>
                <tr class="integral-empty-row"><td><small>Segmento ${s.secuencia} · vacíos: cuentan para la cuenta corriente, no para el lote ni el peso promedio</small></td>${emptyInputs(s.id, emptyAmounts(s.envases))}</tr>`).join('')}
                </tbody></table></div></section>`}
            ${addTypeField()}
            ${estado.propiedad_editable ? `<section><h3>Propiedad de los envases</h3><div class="integral-grid">${tipos.map((tipo) => `<label class="field"><span>${esc(typeName(tipo))}</span><select data-propiedad="${esc(tipo)}">${Object.entries(PROPERTY_LABELS).map(([value, text]) => `<option value="${value}" ${estado.propiedades[tipo] === value ? 'selected' : ''}>${text}</option>`).join('')}</select></label>`).join('')}</div></section>` : ''}
            ${cerrada ? `<section><h3>Destare</h3><div class="integral-grid">
                <label class="field"><span>Tara del camión *</span><input name="peso_tara" type="number" min="0.01" step="0.01" inputmode="decimal" value="${esc(estado.destare.peso_tara)}" required></label>
                <label class="field"><span>Salida de envases *</span><select name="modo_salida_envases">${Object.entries(EXIT_LABELS).map(([value, text]) => `<option value="${value}" ${estado.destare.modo_salida_envases === value ? 'selected' : ''}>${text}</option>`).join('')}</select></label>
                <label class="field"><span>Guía de salida</span><input name="numero_guia_salida" maxlength="80" value="${esc(estado.destare.numero_guia_salida || '')}"></label></div>
                <div class="integral-scroll ${estado.destare.modo_salida_envases === 'diferentes' ? '' : 'is-hidden'}" id="integralExit"><table class="integral-table"><thead>${header('Envases que salen')}</thead><tbody><tr><td>Cantidad</td>${inputs('salida', 'total', amounts(estado.destare.salida_envases))}</tr></tbody></table></div>
                <div class="integral-grid">${tipos.map((tipo) => `<label class="field"><span>Tara unitaria · ${esc(typeName(tipo))}</span><input data-tara="${esc(tipo)}" type="number" min="0.001" step="0.001" inputmode="decimal" value="${esc(estado.taras[tipo] ?? '')}" required></label>`).join('')}</div>
            </section>` : ''}
            <section><h3>Lotes de materia prima</h3>${lotes}</section>
            <section><h3>Resultado</h3><div id="integralPreview" aria-live="polite"></div></section>`;
    }

    dialog.addEventListener('input', () => { if (estado) preview(); });
    dialog.addEventListener('change', (event) => {
        if (!estado) return;
        if (event.target.name === 'integral_modo') {
            snapshot();
            mode = event.target.value;
            render();
            dialog.querySelector(`[name="integral_modo"][value="${mode}"]`)?.focus();
            return;
        }
        if (event.target.id === 'integralAddType' && event.target.value) {
            snapshot();
            tipos = [...tipos, event.target.value];
            render();
            return;
        }
        if (event.target.name === 'modo_salida_envases') dialog.querySelector('#integralExit')?.classList.toggle('is-hidden', event.target.value !== 'diferentes');
        preview();
    });
    dialog.addEventListener('click', (event) => {
        if (event.target.closest('[data-cerrar]') && !attempt?.submitting) dialog.close();
    });
    dialog.addEventListener('cancel', (event) => { if (attempt?.submitting) event.preventDefault(); });
    dialog.addEventListener('submit', async (event) => {
        event.preventDefault();
        const form = dialog.querySelector('#integralForm');
        if (!estado || attempt?.submitting || !form.reportValidity()) return;
        snapshot();
        const account = mode === 'cuenta';
        const data = account ? {
            version_conocida: estado.version, motivo: estado.motivo.trim(),
            productor_csg: estado.cuenta_envases.exige_productor ? (cuenta.productor || null) : null,
            envases: tipos.map((tipo) => ({ tipo_envase: tipo, recibidos: accountValue(tipo, 'recibidos'),
                entregados: sideEditable('entregados') ? accountValue(tipo, 'entregados') : null })),
        } : {
            version_conocida: estado.version, motivo_correccion: estado.motivo.trim(),
            guias: estado.guias.map((g) => ({ id: g.id, numero_guia_despacho: g.numero_guia_despacho.trim(), envases: g.envases })),
            ...(estado.tipo_recepcion === 'solo_envases'
                ? { envases_validados: estado.envases_validados }
                : { segmentos: estado.segmentos.map((s) => ({ id: s.id, envases: s.envases })) }),
            ...(estado.propiedad_editable ? { propiedades: tipos.filter((tipo) => validated()[tipo] > 0).map((tipo) => ({ tipo_envase: tipo, propiedad: estado.propiedades[tipo] || 'cliente' })) } : {}),
            destare: estado.destare ? {
                peso_tara: Number(estado.destare.peso_tara), modo_salida_envases: estado.destare.modo_salida_envases,
                numero_guia_salida: estado.destare.modo_salida_envases === 'vacio' ? null : (estado.destare.numero_guia_salida.trim() || null),
                salida_envases: estado.destare.modo_salida_envases === 'diferentes' ? estado.destare.salida_envases : [],
                taras_envases: tipos.filter((tipo) => Number(estado.taras[tipo]) > 0).map((tipo) => ({ tipo_envase: tipo, tara_unitaria: Number(estado.taras[tipo]) })),
            } : null,
        };
        // Conserva el UUID para repetir exactamente un envío cuya respuesta se perdió.
        data.operacion_id = attempt?.payload && JSON.stringify(attempt.payload) === JSON.stringify(data) ? attempt.operacion_id : operationUuid();
        const { operacion_id: _, ...comparable } = data;
        attempt = { operacion_id: data.operacion_id, payload: comparable, submitting: true };
        data.operacion_id = attempt.operacion_id;
        const error = dialog.querySelector('#integralError');
        const buttons = [...form.querySelectorAll('button')];
        buttons.forEach((button) => { button.disabled = true; });
        error.textContent = '';
        try {
            const respuesta = await api(`/api/romana/recepciones/${estado.recepcion_id}/${account ? 'ajuste-cuenta-envases' : 'correccion-integral'}`, { method: 'PUT', body: JSON.stringify(data) });
            dialog.close();
            toast(account ? 'Cuenta de envases ajustada. Guías, lotes y kilos no cambiaron.' : 'Recepción corregida. La cuenta corriente y los lotes quedaron ajustados.');
            await onSaved(respuesta.data);
        } catch (failure) {
            error.textContent = failure.message;
        } finally {
            attempt.submitting = false;
            buttons.forEach((button) => { button.disabled = false; });
        }
    });

    return {
        async open(reception) {
            const respuesta = await api(`/api/romana/recepciones/${reception.id}/correccion-integral`);
            const base = respuesta.data;
            estado = { ...structuredClone(base), original: base, motivo: '',
                envases_validados: base.envases.map((e) => ({ tipo_envase: e.tipo_envase, cantidad: e.cantidad_validada })),
                propiedades: Object.fromEntries(base.envases.map((e) => [e.tipo_envase, e.propiedad])),
                taras: Object.fromEntries(base.envases.map((e) => [e.tipo_envase, e.tara_unitaria_salida ?? ''])) };
            tipos = [...new Set([...base.envases.map((e) => e.tipo_envase), ...base.guias.flatMap((g) => g.envases.map((e) => e.tipo_envase)),
                ...(base.cuenta_envases?.tipos || []).map((t) => t.tipo_envase)])];
            attempt = null;
            mode = 'completa';
            cuenta = { valores: {}, productor: '' };
            render();
            dialog.showModal();
        },
    };
}
