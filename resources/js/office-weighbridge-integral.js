// Corrección administrativa de una recepción ya validada: guías, conteo por segmento, propiedad y destare.
// El servidor recibe el estado correcto completo y deja ajustes trazables en la cuenta corriente.

const PROPERTY_LABELS = { cliente: 'Cliente', propia: 'Propia', arrendada: 'Arrendada' };
const EXIT_LABELS = { mismos: 'Mismos envases', diferentes: 'Otros envases o cantidades', vacio: 'Camión vacío' };

export function createIntegralCorrection({ dialog, api, escapeHtml: esc, operationUuid, toast, onSaved }) {
    let estado = null;
    let attempt = null;
    let tipos = [];

    const number = (value) => Math.max(0, Math.trunc(Number(value) || 0));
    const kg = (value) => `${new Intl.NumberFormat('es-CL', { maximumFractionDigits: 3 }).format(Number(value) || 0)} kg`;
    const typeName = (code) => estado.tipos_envase.find((tipo) => tipo.codigo === code)?.nombre || code;
    const amounts = (list) => Object.fromEntries((list || []).map((item) => [item.tipo_envase, Number(item.cantidad) || 0]));
    const toList = (map) => tipos.map((tipo) => ({ tipo_envase: tipo, cantidad: number(map[tipo]) })).filter((item) => item.cantidad > 0);

    function inputs(prefix, id, map) {
        return tipos.map((tipo) => `<td><input class="integral-qty" type="number" min="0" max="100000" step="1" inputmode="numeric"
            data-${prefix}="${esc(id)}" data-tipo="${esc(tipo)}" value="${number(map[tipo])}" aria-label="${esc(typeName(tipo))}"></td>`).join('');
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
        // Conserva lo digitado al agregar un tipo de envase y volver a dibujar.
        if (!dialog.querySelector('#integralForm')) return;
        const guides = read('guia');
        const segments = read('segmento');
        const received = read('recibido');
        estado.guias.forEach((g) => { g.numero_guia_despacho = dialog.querySelector(`[data-numero="${g.id}"]`)?.value ?? g.numero_guia_despacho; g.envases = toList(guides[g.id] || {}); });
        estado.segmentos.forEach((s) => { s.envases = toList(segments[s.id] || {}); });
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
        estado.motivo = dialog.querySelector('#integralForm').elements.motivo_correccion.value;
    }

    function validated() {
        if (estado.tipo_recepcion === 'solo_envases') return amounts(estado.envases_validados);
        const sums = {};
        estado.segmentos.forEach((s) => s.envases.forEach((e) => { sums[e.tipo_envase] = (sums[e.tipo_envase] || 0) + e.cantidad; }));
        return sums;
    }
    function declared() {
        const sums = {};
        estado.guias.forEach((g) => g.envases.forEach((e) => { sums[e.tipo_envase] = (sums[e.tipo_envase] || 0) + e.cantidad; }));
        return sums;
    }

    function preview() {
        snapshot();
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

    function render() {
        const cerrada = Boolean(estado.destare);
        const lotes = estado.lotes.length
            ? `<ul class="integral-lots">${estado.lotes.map((lote) => `<li><strong>${esc(lote.numero_lote)}</strong> · ${esc(lote.kilos_netos === null ? 'sin kilos' : kg(lote.kilos_netos))}${lote.con_entregas ? ' · <em>con entregas a proceso: no admite cambios de conteo ni de kilos</em>' : ''}</li>`).join('')}</ul>`
            : '<p class="integral-help">Esta recepción aún no tiene lotes.</p>';
        const otros = estado.tipos_envase.filter((tipo) => !tipos.includes(tipo.codigo));
        dialog.innerHTML = `<form class="weighbridge-dialog__shell integral-shell" id="integralForm" novalidate>
            <header class="weighbridge-dialog__heading"><div><p class="eyebrow">CORRECCIÓN ADMINISTRATIVA · RECEPCIÓN VALIDADA</p>
                <h2>Corregir ${esc(estado.numero_recepcion)}</h2>
                <p>Registra los valores correctos. La cuenta corriente conserva lo original y agrega el ajuste enlazado; los lotes recalculan sus kilos con el destare.</p></div>
                <button class="dialog-close" type="button" data-cerrar aria-label="Cerrar">×</button></header>
            <section><h3>Guías y envases declarados</h3>
                <div class="integral-scroll"><table class="integral-table"><thead><tr><th>N° guía</th>${tipos.map((tipo) => `<th>${esc(typeName(tipo))}</th>`).join('')}</tr></thead><tbody>
                ${estado.guias.map((g) => `<tr><td><input class="integral-guide" data-numero="${esc(g.id)}" value="${esc(g.numero_guia_despacho)}" maxlength="80" required aria-label="Número de guía"></td>${inputs('guia', g.id, amounts(g.envases))}</tr>`).join('')}
                </tbody></table></div></section>
            ${estado.tipo_recepcion === 'solo_envases' ? `<section><h3>Envases recibidos</h3><div class="integral-scroll"><table class="integral-table"><thead>${header('')}</thead><tbody>
                <tr><td>Conteo real</td>${inputs('recibido', 'total', amounts(estado.envases_validados))}</tr></tbody></table></div></section>`
            : `<section><h3>Conteo de Validación MP por segmento</h3>
                ${estado.cantidades_editables ? '' : '<p class="integral-help">En pesaje acumulativo el conteo viene de las tandas: corrígelas en Romana.</p>'}
                <div class="integral-scroll"><table class="integral-table"><thead>${header('Segmento')}</thead><tbody>
                ${estado.segmentos.map((s) => `<tr><td><strong>${s.secuencia}</strong> · Guía ${esc(estado.guias.find((g) => g.id === s.guia_recepcion_romana_id)?.numero_guia_despacho || '—')}<small>CSG ${esc(s.csg || '—')} · ${esc(s.variedad || '')}${s.lotes.length ? ` · Lote ${esc(s.lotes.join(', '))}` : ''}</small></td>${inputs('segmento', s.id, amounts(s.envases))}</tr>`).join('')}
                </tbody></table></div></section>`}
            <div class="integral-add">${otros.length ? `<label class="field"><span>Agregar tipo de envase</span><select id="integralAddType"><option value="">Seleccionar…</option>${otros.map((tipo) => `<option value="${esc(tipo.codigo)}">${esc(tipo.nombre)}</option>`).join('')}</select></label>` : ''}</div>
            ${estado.propiedad_editable ? `<section><h3>Propiedad de los envases</h3><div class="integral-grid">${tipos.map((tipo) => `<label class="field"><span>${esc(typeName(tipo))}</span><select data-propiedad="${esc(tipo)}">${Object.entries(PROPERTY_LABELS).map(([value, text]) => `<option value="${value}" ${estado.propiedades[tipo] === value ? 'selected' : ''}>${text}</option>`).join('')}</select></label>`).join('')}</div></section>` : ''}
            ${cerrada ? `<section><h3>Destare</h3><div class="integral-grid">
                <label class="field"><span>Tara del camión *</span><input name="peso_tara" type="number" min="0.01" step="0.01" inputmode="decimal" value="${esc(estado.destare.peso_tara)}" required></label>
                <label class="field"><span>Salida de envases *</span><select name="modo_salida_envases">${Object.entries(EXIT_LABELS).map(([value, text]) => `<option value="${value}" ${estado.destare.modo_salida_envases === value ? 'selected' : ''}>${text}</option>`).join('')}</select></label>
                <label class="field"><span>Guía de salida</span><input name="numero_guia_salida" maxlength="80" value="${esc(estado.destare.numero_guia_salida || '')}"></label></div>
                <div class="integral-scroll ${estado.destare.modo_salida_envases === 'diferentes' ? '' : 'is-hidden'}" id="integralExit"><table class="integral-table"><thead>${header('Envases que salen')}</thead><tbody><tr><td>Cantidad</td>${inputs('salida', 'total', amounts(estado.destare.salida_envases))}</tr></tbody></table></div>
                <div class="integral-grid">${tipos.map((tipo) => `<label class="field"><span>Tara unitaria · ${esc(typeName(tipo))}</span><input data-tara="${esc(tipo)}" type="number" min="0.001" step="0.001" inputmode="decimal" value="${esc(estado.taras[tipo] ?? '')}" required></label>`).join('')}</div>
            </section>` : ''}
            <section><h3>Lotes de materia prima</h3>${lotes}</section>
            <section><h3>Resultado</h3><div id="integralPreview" aria-live="polite"></div></section>
            <label class="field"><span>Motivo de la corrección *</span><textarea name="motivo_correccion" rows="3" minlength="10" maxlength="2000" required placeholder="Qué estaba mal y cómo se verificó">${esc(estado.motivo || '')}</textarea></label>
            <p class="form-error" id="integralError" role="alert"></p>
            <footer class="dialog-actions"><button class="secondary-button" type="button" data-cerrar>Cancelar</button><button class="primary-button" type="submit">Guardar corrección</button></footer>
        </form>`;
        preview();
    }

    dialog.addEventListener('input', () => { if (estado) preview(); });
    dialog.addEventListener('change', (event) => {
        if (!estado) return;
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
        const data = {
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
            const respuesta = await api(`/api/romana/recepciones/${estado.recepcion_id}/correccion-integral`, { method: 'PUT', body: JSON.stringify(data) });
            dialog.close();
            toast('Recepción corregida. La cuenta corriente y los lotes quedaron ajustados.');
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
            tipos = [...new Set([...base.envases.map((e) => e.tipo_envase), ...base.guias.flatMap((g) => g.envases.map((e) => e.tipo_envase))])];
            attempt = null;
            render();
            dialog.showModal();
        },
    };
}
