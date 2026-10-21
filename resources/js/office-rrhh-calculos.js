import { newOperationId } from './shared/rrhh-logic';

export function initCalculosLegales({ api, escape, table, choices, open, pages, message }) {
    const $ = (id) => document.getElementById(id);
    const path = '/api/rrhh/nomina/legal';
    const state = { person: null, rows: [], operation: null, previous: 0, afps: [], commissions: new Map() };
    const labels = { cotizaciones: 'Cotizaciones y aportes', anticipo50: 'Anticipo artículo 50', anual50: 'Cierre artículo 50', reparto47: 'Reparto artículo 47', impuesto: 'Impuesto único', honorarios: 'Honorarios', haber: 'Haber', descuento: 'Descuento del trabajador', aporte_empleador: 'Aporte del empleador', informativo: 'Referencia' };
    const money = (v) => `${new Intl.NumberFormat('es-CL').format(v)} CLP`;
    const safe = async (fn) => { try { await fn(); } catch (e) { message(e.message); } };
    const values = (form) => Object.fromEntries([...new FormData(form)].filter(([, v]) => v !== ''));
    document.addEventListener('rrhh:ficha', (e) => { state.person = e.detail; safe(() => history()); });
    $('rlNew').addEventListener('click', () => safe(async () => { const f = $('rlForm'); f.reset(); f.elements.mes.value = $('rnMonth').value; state.operation = newOperationId(); $('rlAdvances').replaceChildren(); $('rlPreview').replaceChildren(); await refreshContext(); kinds(); open('rlDialog'); }));
    $('rlNewIndex').addEventListener('click', () => safe(async () => { const f = $('rlIndexForm'); f.reset(); f.elements.mes.value = $('rnMonth').value; state.commissions.clear(); $('rlCommissions').replaceChildren(); await Promise.all([indexVersion(), afps()]); open('rlIndexDialog'); }));
    $('rlRefreshIndices').addEventListener('click', () => safe(() => showIndices()));
    $('rlForm').elements.tipo.addEventListener('change', kinds); $('rlForm').elements.mes.addEventListener('change', () => safe(refreshContext));
    $('rlIndexForm').elements.mes.addEventListener('change', () => safe(indexVersion)); $('rlAfpSearch').addEventListener('input', () => safe(() => afps($('rlAfpSearch').value)));
    $('rlAddCommission').addEventListener('click', () => {
        const i = state.afps.find((i) => i.id === $('rlAfp').value); if (!i || state.commissions.has(i.id)) return;
        const row = document.createElement('label'); const span = document.createElement('span'); span.textContent = `${i.razon_social} · comisión (%)`; const input = document.createElement('input'); input.inputMode = 'decimal'; input.required = true; input.pattern = '[0-9]+([.][0-9]{1,6})?'; row.append(span, input); $('rlCommissions').append(row); state.commissions.set(i.id, input);
    });
    $('rlAddAdvance').addEventListener('click', () => {
        if ($('rlAdvances').children.length >= 12) return;
        const row = document.createElement('div'); row.className = 'rh-form'; row.innerHTML = '<label><span>Mes del anticipo</span><input type="month" data-mes required></label><label><span>Total anticipado en CLP</span><input inputmode="decimal" data-monto required></label><label><span>Factor de reajuste respaldado</span><input inputmode="decimal" data-factor required></label><button type="button">Quitar este mes</button>';
        row.querySelector('[data-mes]').value = $('rlForm').elements.mes.value; row.querySelector('button').addEventListener('click', () => row.remove()); $('rlAdvances').append(row);
    });
    $('rlSimulate').addEventListener('click', async () => { const error = $('rlForm').querySelector('[data-rh-error]'); error.textContent = ''; try { const r = await api(`${path}/simular`, 'POST', calculationData()); render('rlPreview', r.data); } catch (e) { error.textContent = e.message; } });
    for (const f of document.querySelectorAll('[data-legal-form]')) f.addEventListener('submit', async (e) => {
        e.preventDefault(); const b = e.submitter; if (b.disabled) return; b.disabled = true;
        try {
            if (f.id === 'rlIndexForm') { const d = values(f); await api(`${path}/indices`, 'POST', { ...d, version_anterior: state.previous, comisiones: [...state.commissions].map(([institucion_id, input]) => ({ institucion_id, tasa: input.value })) }); await showIndices(); }
            else { const d = calculationData(); await api(`${path}/calculos`, 'POST', d); $('rnMonth').value = d.mes; $('rnLoad').click(); }
            f.closest('dialog').close(); message('Preparación guardada con sus antecedentes; no acredita pago.');
        } catch (error) { f.querySelector('[data-rh-error]').textContent = error.message; } finally { b.disabled = false; }
    });
    document.addEventListener('click', (e) => { const b = e.target.closest('[data-rl-result]'); if (b) { const r = state.rows.find((r) => r.id === b.dataset.rlResult); render('rlResult', r.calculo, r.entrada.respaldo); open('rlResultDialog'); } });
    function kinds() {
        const f = $('rlForm'); const kind = f.elements.tipo.value;
        for (const fieldset of f.querySelectorAll('[data-rl-kind]')) { const active = fieldset.dataset.rlKind === kind; fieldset.hidden = !active; fieldset.disabled = !active; for (const input of fieldset.querySelectorAll('[data-rl-required]')) input.required = active; }
        f.elements.parametro_id.required = ['impuesto', 'cotizaciones'].includes(kind); f.elements.parametro_id.disabled = !f.elements.parametro_id.required; f.elements.contrato_id.required = kind === 'cotizaciones'; $('rlPreview').replaceChildren();
    }
    function calculationData() {
        const f = $('rlForm'); const root = values(f); const fieldset = f.querySelector(`[data-rl-kind="${f.elements.tipo.value}"]`); const datos = {};
        for (const input of fieldset.querySelectorAll('[name]')) { if (input.disabled || input.value === '') continue; datos[input.name] = input.type === 'checkbox' ? input.checked : input.value; }
        for (const k of ['cic_completa', 'retiene_receptor']) if (datos[k] !== undefined) datos[k] = datos[k] === '1';
        if (root.tipo === 'anual50') datos.anticipos = [...$('rlAdvances').children].map((r) => ({ mes: r.querySelector('[data-mes]').value, monto: r.querySelector('[data-monto]').value, factor_reajuste: r.querySelector('[data-factor]').value }));
        return { operacion_id: state.operation, persona_id: state.person.id, tipo: root.tipo, mes: root.mes, respaldo: root.respaldo, ...(root.contrato_id ? { contrato_id: root.contrato_id } : {}), ...(root.parametro_id ? { parametro_id: root.parametro_id } : {}), datos };
    }
    let contextGeneration = 0;
    async function refreshContext() {
        const generation = ++contextGeneration; const mes = $('rlForm').elements.mes.value;
        const [indices, contracts] = await Promise.all([api(`${path}/indices?mes=${mes}`), api(`/api/rrhh/prevision/contratos?persona_id=${state.person.id}&vigente_en=${mes}-01`)]); if (generation !== contextGeneration) return;
        $('rlForm').elements.parametro_id.innerHTML = choices((indices.data?.data || []).map((p) => [p.id, `${p.mes} · versión ${p.version} · UF ${p.uf} / UTM ${p.utm}`]), 'Seleccione índices revisados');
        $('rlForm').elements.contrato_id.innerHTML = choices((contracts.data?.data || []).map((c) => [c.id, `${c.empleador.razon_social} · ${c.referencia} · ${c.tipo}`]), 'Sin contrato de referencia'); await Promise.all(['afc', 'fapp'].map((tipo) => destination(tipo)));
    }
    let afpGeneration = 0;
    async function afps(buscar = '') { const generation = ++afpGeneration; const d = await api(`/api/rrhh/prevision/directorio/institucion?${new URLSearchParams({ regimen: 'afp', buscar })}`); if (generation !== afpGeneration) return; state.afps = (d.data?.data || []).filter((i) => i.activo); $('rlAfp').innerHTML = choices(state.afps.map((i) => [i.id, `${i.rut} · ${i.razon_social}`]), 'Seleccione AFP para añadir su comisión'); }
    const destinations = new Map();
    async function destination(tipo, buscar = '') { const generation = (destinations.get(tipo) || 0) + 1; destinations.set(tipo, generation); const d = await api(`/api/rrhh/prevision/directorio/institucion?${new URLSearchParams({ tipo_entidad: tipo, buscar })}`); if (generation !== destinations.get(tipo)) return; $('rlForm').elements[`${tipo}_id`].innerHTML = choices((d.data?.data || []).filter((i) => i.activo).map((i) => [i.id, i.razon_social]), 'Por asignar / sin destinatario registrado'); }
    for (const input of document.querySelectorAll('[data-rl-destination]')) input.addEventListener('input', () => safe(() => destination(input.dataset.rlDestination, input.value)));
    async function indexVersion() { const mes = $('rlIndexForm').elements.mes.value; const d = await api(`${path}/indices?mes=${mes}`); if (mes !== $('rlIndexForm').elements.mes.value) return; state.previous = d.data?.data?.[0]?.version || 0; $('rlIndexVersion').textContent = state.previous ? `Creará la versión ${state.previous + 1}; se conserva la versión ${state.previous}.` : 'Primera versión del mes.'; }
    async function showIndices(page = 1) { const mes = $('rnMonth').value; const d = await api(`${path}/indices?mes=${mes}&page=${page}`); $('rlIndices').innerHTML = table(['Período / versión', 'UF / UTM', 'Respaldo'], (d.data?.data || []).map((p) => `<tr><td>${p.mes} · ${p.version}<small>UF al ${p.fecha_uf}</small></td><td>${escape(p.uf)} / ${escape(p.utm)}</td><td>${escape(p.respaldo)}</td></tr>`).join('')); if (d.data) pages('rlIndexPages', d.data, showIndices); }
    async function history(page = 1) { const person = state.person.id; const mes = $('rnMonth').value; const d = await api(`${path}/calculos?${new URLSearchParams({ persona_id: person, mes, page })}`); if (state.person.id !== person || $('rnMonth').value !== mes) return; state.rows = d.data?.data || []; $('rlHistory').innerHTML = table(['Cálculo / mes', 'Estado', 'Detalle'], state.rows.map((r) => `<tr><td>${escape(labels[r.tipo])}<small>${r.mes}</small></td><td>Preparación${r.calculo.ejercicio_abierto ? ' · proyección de ejercicio abierto' : ''}</td><td><button type="button" data-rl-result="${r.id}">Ver bases y resultado</button></td></tr>`).join('')); if (d.data) pages('rlHistoryPages', d.data, history); }
    function render(id, r, respaldo = '') {
        const keys = { tope_mensual_referencia: 'Tope mensual de referencia', tope_anual_proporcional: 'Tope anual proporcional', base_previsional: 'Base previsional limitada', base_afc: 'Base AFC limitada', fondo_a_distribuir: 'Fondo legal a distribuir', rebaja: 'Cantidad a rebajar en el impuesto', salud_deducible_tributaria_maxima: 'Salud deducible de la base tributaria' };
        $(id).innerHTML = `${r.ejercicio_abierto ? '<p><strong>Proyección de ejercicio abierto: el cierre requiere antecedentes definitivos.</strong></p>' : ''}<p>${escape(r.alcance)}</p>${respaldo ? `<p>Respaldo: ${escape(respaldo)}</p>` : ''}${table(['Concepto', 'Base / tasa', 'Importe y destinatario'], r.lineas.map((l) => `<tr><td>${escape(l.nombre)}<small>${escape(labels[l.tipo])}</small></td><td>${l.base === null ? '' : money(l.base)}${l.tasa === null ? '' : `<small>${escape(l.tasa)}%</small>`}</td><td>${money(l.monto)}<small>${l.institucion ? escape(l.institucion.razon_social) : l.destino ? 'Destinatario por asignar' : ''}</small></td></tr>`).join(''))}<p>${Object.entries(r.totales).map(([k,v]) => `${escape(labels[k])}: ${money(v)}`).join(' · ')}</p>${r.detalle.exceso_a_revisar && r.detalle.exceso_a_revisar !== '0' ? `<p>Exceso de anticipos a revisar: ${money(r.detalle.exceso_a_revisar)}. No se ordena un descuento automático.</p>` : ''}`;
        $(id).insertAdjacentHTML('beforeend', Object.entries(keys).filter(([k]) => r.detalle[k] !== undefined).map(([k, label]) => `<p>${label}: ${money(r.detalle[k])}</p>`).join(''));
        if (r.detalle.factor) $(id).insertAdjacentHTML('beforeend', `<p>Factor del impuesto: ${escape(r.detalle.factor)}</p>`);
        if (r.parametros) $(id).insertAdjacentHTML('beforeend', `<p>Índices ${escape(r.parametros.mes)} · versión ${r.parametros.version} · UF ${escape(r.parametros.uf)} / UTM ${escape(r.parametros.utm)}<br>${escape(r.parametros.respaldo)}</p>`);
        if (r.detalle.anticipos?.length) $(id).insertAdjacentHTML('beforeend', table(['Mes', 'Anticipo', 'Factor', 'Reajustado'], r.detalle.anticipos.map((a) => `<tr><td>${escape(a.mes)}</td><td>${money(a.monto)}</td><td>${escape(a.factor_reajuste)}</td><td>${money(a.actualizado)}</td></tr>`).join('')));
    }
}
