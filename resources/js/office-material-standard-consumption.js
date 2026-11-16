import './office-navigation';

// Rebaja por estándar: lo validado en PT descuenta del almacén de Packing según el estándar.
const $ = (id) => document.getElementById(id);
const escape = (v) => String(v ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&#039;');
const qty = (v) => new Intl.NumberFormat('es-CL', { maximumFractionDigits: 6 }).format(Number(v || 0));
const dmy = (v) => (v ? String(v).slice(0, 10).split('-').reverse().join('-') : '—');
const pallets = (n) => `${n} ${n === 1 ? 'pallet' : 'pallets'}`;
const pending = (v) => (v > 0 ? `<strong>${qty(v)}</strong>` : v < 0 ? `<span class="standards-credit">${qty(-v)} a favor</span>` : '0');
const when = (v) => (v ? new Intl.DateTimeFormat('es-CL', { timeZone: 'America/Santiago', dateStyle: 'short', timeStyle: 'short' }).format(new Date(v)) : '—');
const token = localStorage.getItem('estiba_wms_office_token');
const problems = { sin_estandar: 'Sin estándar vigente', insumos_sin_item: 'Insumos sin homologar', sin_cliente: 'Sin cliente', sin_embalaje: 'Sin embalaje' };
const state = { busy: false, options: null, preview: null, selected: new Set(), attempt: null, previewTimer: null };

async function api(url, options = {}) {
    const response = await fetch(url, { ...options, headers: { Accept: 'application/json', 'Content-Type': 'application/json', Authorization: `Bearer ${token}`, ...(options.headers || {}) } });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(Object.values(data.errors || {}).flat()[0] || data.message || 'No fue posible completar la operación.');
    return data;
}
async function run(work) {
    if (state.busy) return;
    state.busy = true; $('rebateNotice').textContent = '';
    try { await work(); } catch (error) { $('rebateNotice').textContent = error.message; }
    finally { state.busy = false; }
}
function filters() {
    const data = Object.fromEntries([...new FormData($('rebateFilters'))].filter(([, v]) => String(v).trim() !== '').map(([k, v]) => [k, String(v).trim()]));
    return data;
}
function uuid() {
    if (crypto.randomUUID) return crypto.randomUUID();
    return '10000000-1000-4000-8000-100000000000'.replace(/[018]/g, (c) => (c ^ (crypto.getRandomValues(new Uint8Array(1))[0] & (15 >> (c / 4)))).toString(16));
}

function renderPreview() {
    const p = state.preview; if (!p) return;
    $('rebateGroupsCard').hidden = false; $('rebateMaterialsCard').hidden = false;
    $('rebateTotals').textContent = `${p.totales.grupos} grupos · ${qty(p.totales.cajas)} cajas validadas sin rebajar · ${qty(p.totales.cajas_seleccionadas)} cajas elegidas`
        + (p.totales.cajas_sin_estandar ? ` · ${qty(p.totales.cajas_sin_estandar)} cajas sin poder rebajar` : '') + ` · almacén ${p.almacen.nombre}`;
    $('rebateGroups').innerHTML = p.grupos.map((g) => `<tr class="${g.problema ? 'standards-row--off' : ''}">
        <td><input type="checkbox" data-group="${escape(g.clave)}" ${g.problema ? 'disabled' : ''} ${state.selected.has(g.clave) ? 'checked' : ''} aria-label="Elegir grupo"></td>
        <td>${dmy(g.fecha)}${g.tipo === 'ajuste' ? `<small><span class="standards-badge standards-badge--ajuste">Ajuste</span> ${escape(g.motivo || '')}</small>` : ''}</td><td>${escape(g.cliente?.nombre || '—')}</td><td>${escape(g.marca || '—')}</td><td>${escape(g.proceso || 'Sin proceso')}</td>
        <td>${escape(g.envase?.nombre || '—')}<small>${escape([g.envase?.codigo, g.variedad, g.calibre, g.categoria].filter(Boolean).join(' · '))}</small></td>
        <td>${qty(g.cajas)}<small>${pallets(g.pallets)}</small></td>
        <td>${g.estandar ? `${escape(g.estandar.codigo)} v${g.estandar.version}<small>${g.estandar.origen === 'propio' ? 'Propio' : 'Planta'} · desde ${dmy(g.estandar.vigente_desde)}</small>` : ''}${g.problema ? `<small class="standards-warning">${escape(problems[g.problema] || g.problema)}</small>` : ''}</td></tr>`).join('')
        || '<tr><td colspan="8">No hay producción validada sin rebajar para esos filtros.</td></tr>';
    $('rebateAll').checked = p.grupos.some((g) => !g.problema) && p.grupos.filter((g) => !g.problema).every((g) => state.selected.has(g.clave));
    $('rebateMaterials').innerHTML = p.materiales.map((m) => `<tr class="${m.faltante > 0 ? 'standards-row--shortage' : ''}">
        <td><strong>${escape(m.codigo)}</strong><small>${escape(m.nombre)}${m.dueno ? ` · ${escape(m.dueno)}` : ''}${m.unidad_medida ? ` · ${escape(m.unidad_medida)}` : ''}</small></td>
        <td>${m.redondeo === 'entero' ? 'Entero' : 'Decimal'}</td><td>${qty(m.teorico)}</td><td>${qty(m.fraccion_anterior)}</td><td>${qty(m.pendiente_anterior)}</td>
        <td>${qty(m.a_descontar)}</td><td>${qty(m.disponible)}</td><td>${qty(m.a_descontar_ahora)}</td><td>${pending(m.faltante)}</td></tr>`).join('')
        || '<tr><td colspan="9">Sin materiales para los grupos elegidos.</td></tr>';
    void balances();
    const canConfirm = state.options?.puede_rebajar === true && (state.selected.size > 0 || p.materiales.some((m) => m.a_descontar_ahora > 0));
    $('rebateConfirm').hidden = !canConfirm;
}
async function preview(keepSelection = false) {
    const body = filters();
    if (keepSelection) body.grupos = [...state.selected];
    state.preview = await api('/api/materiales/rebajas-estandar/previsualizar', { method: 'POST', body: JSON.stringify(body) });
    if (!keepSelection) state.selected = new Set(state.preview.grupos.filter((g) => g.seleccionado).map((g) => g.clave));
    state.attempt = null;
    renderPreview();
}
function schedulePreview() {
    clearTimeout(state.previewTimer);
    state.previewTimer = setTimeout(() => void run(() => preview(true)), 350);
}
async function balances() {
    const warehouse = $('rebateFilters').elements.almacen_id.value; if (!warehouse) return;
    try {
        const { data } = await api(`/api/materiales/rebajas-estandar/saldos?almacen_id=${encodeURIComponent(warehouse)}`);
        $('rebateBalancesCard').hidden = data.length === 0;
        $('rebateBalances').innerHTML = data.length ? `<div class="custody-table-wrap"><table class="custody-table standards-table"><thead><tr><th>Ítem</th><th>Pendiente</th><th>Fracción</th></tr></thead><tbody>${data.map((b) => `<tr><td><strong>${escape(b.item?.codigo)}</strong><small>${escape(b.item?.nombre)}${b.item?.unidad_medida ? ` · ${escape(b.item.unidad_medida)}` : ''}</small></td><td>${pending(b.pendiente)}</td><td>${qty(b.fraccion)}</td></tr>`).join('')}</tbody></table></div>` : '';
    } catch { $('rebateBalancesCard').hidden = true; }
}
async function history() {
    const params = new URLSearchParams(); const warehouse = $('rebateFilters').elements.almacen_id.value; if (warehouse) params.set('almacen_id', warehouse);
    const { data } = await api(`/api/materiales/rebajas-estandar?${params}`);
    $('rebateHistoryCard').hidden = false;
    $('rebateHistory').innerHTML = data.map((r) => `<div class="standards-history-row"><div><strong>${escape(r.codigo)}</strong> · ${escape(r.almacen?.nombre)} · ${qty(r.cajas)} cajas, ${pallets(r.pallets)}
        <small>Días ${dmy(r.desde)} a ${dmy(r.hasta)} · ${escape(r.usuario)} · ${when(r.confirmada_at)}${r.resumen?.con_faltante ? ` · ${r.resumen.con_faltante} ítems con faltante` : ''}</small></div>
        <button type="button" class="secondary-button" data-rebate="${escape(r.id)}">Ver</button></div>`).join('') || '<p>Sin rebajas todavía.</p>';
}
function renderDetail(r) {
    $('rebateDetail').innerHTML = `<h3>${escape(r.codigo)} · ${escape(r.almacen?.nombre)}</h3><p class="materials-help">${r.movimientos} consumos registrados en el almacén con este código.${r.observacion ? ` ${escape(r.observacion)}` : ''}</p>
        <div class="custody-table-wrap"><table class="custody-table standards-table"><thead><tr><th>Día</th><th>Cliente</th><th>Marca</th><th>Proceso</th><th>Embalaje</th><th>Cajas</th></tr></thead><tbody>${r.produccion.map((p) => `<tr><td>${dmy(p.fecha)}</td><td>${escape(p.cliente)}</td><td>${escape(p.marca || '—')}</td><td>${escape(p.proceso || 'Sin proceso')}</td><td>${escape(p.embalaje)}</td><td>${qty(p.cajas)}<small>${pallets(p.pallets)}</small></td></tr>`).join('') || '<tr><td colspan="6">Solo pendientes anteriores.</td></tr>'}</tbody></table></div>
        <div class="custody-table-wrap"><table class="custody-table standards-table"><thead><tr><th>Ítem</th><th>Teórico</th><th>A descontar</th><th>Descontado</th><th>Pendiente</th><th>Fracción que sigue</th></tr></thead><tbody>${r.consumos.map((c) => `<tr class="${c.pendiente > 0 ? 'standards-row--shortage' : ''}"><td><strong>${escape(c.item?.codigo)}</strong><small>${escape(c.item?.nombre)}</small></td><td>${qty(c.teorico)}</td><td>${qty(c.a_descontar)}</td><td>${qty(c.descontado)}<small>${c.folios} folios</small></td><td>${pending(c.pendiente)}</td><td>${qty(c.fraccion_resultante)}</td></tr>`).join('')}</tbody></table></div>`;
}

$('rebateFilters').addEventListener('submit', (e) => { e.preventDefault(); void run(() => preview(false)); });
$('rebateGroups').addEventListener('change', (e) => {
    const box = e.target.closest('[data-group]'); if (!box) return;
    if (box.checked) state.selected.add(box.dataset.group); else state.selected.delete(box.dataset.group);
    schedulePreview();
});
$('rebateAll').addEventListener('change', (e) => {
    state.selected = new Set(e.target.checked ? state.preview.grupos.filter((g) => !g.problema).map((g) => g.clave) : []);
    schedulePreview();
});
$('rebateConfirm').addEventListener('submit', (e) => {
    e.preventDefault();
    void run(async () => {
        const body = { ...filters(), observacion: $('rebateConfirm').elements.observacion.value.trim() || null,
            grupos: state.preview.grupos.filter((g) => state.selected.has(g.clave)).map((g) => ({ clave: g.clave, cajas: g.cajas })) };
        // Repetir exactamente el mismo envío conserva su identificador: no crea una segunda rebaja.
        const payload = JSON.stringify(body);
        state.attempt = state.attempt?.payload === payload ? state.attempt : { payload, id: uuid() };
        $('rebateSubmit').disabled = true;
        try {
            const { data } = await api('/api/materiales/rebajas-estandar', { method: 'POST', body: JSON.stringify({ ...body, operacion_id: state.attempt.id }) });
            state.attempt = null; $('rebateConfirm').reset();
            await preview(false); await history(); renderDetail(data);
            $('rebateNotice').textContent = `${data.codigo} confirmada: ${qty(data.cajas)} cajas.${data.resumen?.con_faltante ? ` ${data.resumen.con_faltante} ítems quedaron con faltante pendiente.` : ''}`;
        } finally { $('rebateSubmit').disabled = false; }
    });
});
$('rebateHistoryReload').addEventListener('click', () => void run(history));
$('rebateExport').addEventListener('submit', (e) => {
    e.preventDefault();
    const detail = e.submitter?.value === 'produccion' ? 'produccion' : 'consumos';
    void run(async () => {
        const f = $('rebateExport').elements; const params = new URLSearchParams({ desde: f.desde.value, hasta: f.hasta.value, detalle: detail });
        const warehouse = $('rebateFilters').elements.almacen_id.value; if (warehouse) params.set('almacen_id', warehouse);
        const response = await fetch(`/api/materiales/rebajas-estandar/excel?${params}`, { headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' } });
        if (!response.ok) { const data = await response.json().catch(() => ({})); throw new Error(Object.values(data.errors || {}).flat()[0] || data.message || 'No fue posible exportar.'); }
        const url = URL.createObjectURL(await response.blob()); const a = document.createElement('a'); a.href = url; a.download = `${detail === 'produccion' ? 'produccion-rebajada' : 'rebajas-estandar'}.xlsx`; a.click(); setTimeout(() => URL.revokeObjectURL(url), 1000);
    });
});
$('rebateHistory').addEventListener('click', (e) => {
    const button = e.target.closest('[data-rebate]'); if (!button) return;
    void run(async () => renderDetail((await api(`/api/materiales/rebajas-estandar/${encodeURIComponent(button.dataset.rebate)}`)).data));
});

async function init() {
    state.options = await api('/api/materiales/rebajas-estandar/opciones');
    const form = $('rebateFilters').elements;
    form.almacen_id.innerHTML = state.options.almacenes.map((a) => `<option value="${escape(a.id)}">${escape(a.nombre)}${a.centro_costo ? ` · ${escape(a.centro_costo)}` : ''}</option>`).join('') || '<option value="">No hay centros de costo activos</option>';
    form.cliente_id.innerHTML = '<option value="">Todos</option>' + state.options.clientes.map((c) => `<option value="${escape(c.id)}">${escape(c.nombre)}</option>`).join('');
    form.desde.value = state.options.hoy; form.hasta.value = state.options.hoy;
    const monthAgo = new Date(`${state.options.hoy}T12:00:00`); monthAgo.setDate(monthAgo.getDate() - 30);
    $('rebateExport').elements.desde.value = monthAgo.toISOString().slice(0, 10); $('rebateExport').elements.hasta.value = state.options.hoy;
}
if (!token) location.replace('/oficina/materiales');
else void run(init);
