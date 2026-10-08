import './office-navigation';
const $ = (id) => document.getElementById(id);
const token = localStorage.getItem('estiba_wms_office_token');
const escape = (v) => String(v ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&#039;');
const qty = (v) => v === null ? '—' : new Intl.NumberFormat('es-CL', { maximumFractionDigits: 3 }).format(v);
let take = null; let catalogs = {}; let result = null; let busy = false;
let retry = null;
async function api(path, data) {
    const response = await fetch(path, { method: data ? 'POST' : 'GET', headers: { Accept: 'application/json', Authorization: `Bearer ${token}`, ...(data ? { 'Content-Type': 'application/json' } : {}) }, ...(data ? { body: JSON.stringify(data) } : {}) });
    const body = await response.json();
    if (!response.ok) throw new Error(Object.values(body.errors || {}).flat()[0] || body.message || 'No se pudo completar la operación.');
    return body;
}
async function mutate(path, data) {
    const key = JSON.stringify({ path, data });
    if (retry?.key !== key) retry = { key, id: crypto.randomUUID() };
    const response = await api(path, { ...data, operacion_id: retry.id }); retry = null; return response;
}
async function run(work) {
    if (busy) return; busy = true;
    $('notice').textContent = ''; $('decisionError').textContent = '';
    document.querySelectorAll('button').forEach((b) => { b.disabled = true; });
    try { await work(); } catch (e) { $('notice').textContent = e.message; $('decisionError').textContent = e.message; }
    finally { busy = false; document.querySelectorAll('button').forEach((b) => { b.disabled = false; }); }
}
function options(rows, label, blank = '') { return blank + rows.map((r) => `<option value="${escape(r.id)}">${escape(r[label])}</option>`).join(''); }
async function load() {
    const assigned = [...$('create').elements.camarero_ids.selectedOptions].map((o) => o.value);
    const body = await api('/api/materiales/tomas'); catalogs = body.catalogos;
    $('create').elements.camara_ids.innerHTML = options(catalogs.camaras, 'codigo');
    $('create').elements.camarero_ids.innerHTML = options(catalogs.camareros, 'name');
    [...$('create').elements.camarero_ids.options].forEach((o) => { o.selected = assigned.includes(o.value); });
    $('create').elements.categoria.innerHTML = '<option value="">Todas</option>' + catalogs.categorias.map((c) => `<option>${escape(c)}</option>`).join('');
    $('takes').innerHTML = '<option value="">Selecciona una toma</option>' + body.data.map((t) => `<option value="${escape(t.id)}">${escape(t.created_at)} · ${escape(t.estado)} · ${escape(t.id.slice(0, 8))}</option>`).join('');
    if (take) { $('takes').value = take.id; await select(take.id); }
}
async function select(id) { if (!id) { $('detail').hidden = true; take = null; return; } take = (await api(`/api/materiales/tomas/${id}`)).data; render(); }
function render() {
    $('detail').hidden = false; $('heading').textContent = `Toma ${take.id.slice(0, 8)} · ${take.estado}`;
    $('scope').textContent = `${take.camaras.join(', ')} · ${take.categoria || 'Todas las categorías'} · Abierta por ${take.abierta_por}`;
    $('metrics').textContent = `Exactitud por presencia: ${qty(take.exactitud_presencia_pct)} % · por cantidad: ${qty(take.exactitud_cantidad_pct)} %`;
    const buttons = take.estado === 'borrador' ? '<button data-action="abrir">Abrir toma y asignar posiciones</button>' : take.estado === 'en_conteo' ? '<button data-action="revisar">Enviar a revisión</button>' : take.estado === 'en_revision' ? '<button data-action="aprobar">Aprobar y aplicar</button>' : '';
    $('controls').innerHTML = buttons + (!['aprobada', 'anulada'].includes(take.estado) ? '<button data-action="anular">Anular toma</button>' : '') + '<button data-download="excel">Exportar Excel</button><button data-download="acta">Acta PDF</button>';
    $('progress').textContent = `${take.posiciones.filter((p) => p.estado === 'contada').length} de ${take.posiciones.length} posiciones contadas · ` + take.posiciones.map((p) => `${p.camara} ${p.posicion}: ${p.camarero} (${p.estado})`).join(' · ');
    const filters = $('filters').elements;
    filters.item_id.innerHTML = options([...new Map(take.diferencias.filter((r) => r.item_id).map((r) => [r.item_id, { id: r.item_id, item: r.item }])).values()], 'item', '<option value="">Todos</option>');
    filters.categoria.innerHTML = '<option value="">Todas</option>' + [...new Set(take.diferencias.map((r) => r.categoria))].map((c) => `<option>${escape(c)}</option>`).join('');
    renderRows();
    $('totals').innerHTML = [...take.totales_items, ...take.totales_categorias].map((t) => `<p>${escape(t.item)} / ${escape(t.categoria)} (${escape(t.unidad)}): esperado ${qty(t.esperado)}, contado ${qty(t.contado)}, diferencia ${qty(t.diferencia)}</p>`).join('');
}
function renderRows() {
    const f = $('filters').elements;
    const rows = take.diferencias.filter((r) => (!f.tipo.value || r.tipo === f.tipo.value) && (!f.item_id.value || r.item_id === f.item_id.value) && (!f.categoria.value || r.categoria === f.categoria.value));
    $('rows').innerHTML = rows.map((r) => `<tr><td>${escape(r.folio)}<br>${escape(r.camara)} ${escape(r.posicion)}</td><td>${escape(r.item)}<br>${escape(r.categoria)} · ${escape(r.unidad)}</td><td>${qty(r.esperado)}</td><td>${qty(r.contado)}</td><td>${qty(r.diferencia_absoluta)} / ${qty(r.diferencia_pct)} %</td><td>${escape(r.tipo)}<br>${escape(r.accion || '')}${take.estado === 'en_revision' && r.tipo !== 'coincide' ? `<button data-decision="${escape(r.id)}">Elegir acción</button>` : ''}</td></tr>`).join('') || '<tr><td colspan="6">Sin resultados para estos filtros.</td></tr>';
}
$('create').addEventListener('submit', (e) => { e.preventDefault(); void run(async () => { const f = e.target.elements; const body = await mutate('/api/materiales/tomas', { camara_ids: [...f.camara_ids.selectedOptions].map((o) => o.value), categoria: f.categoria.value || null }); take = body.data; await load(); }); });
$('takes').addEventListener('change', (e) => void run(() => select(e.target.value)));
$('reload').addEventListener('click', () => void run(load));
$('filters').addEventListener('change', renderRows);
$('controls').addEventListener('click', (e) => void run(async () => {
    const action = e.target.dataset.action; const download = e.target.dataset.download;
    if (download) {
        const query = new URLSearchParams(new FormData($('filters'))); const response = await fetch(`/api/materiales/tomas/${take.id}/${download}?${query}`, { headers: { Authorization: `Bearer ${token}`, Accept: download === 'acta' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' } });
        if (!response.ok) throw new Error('No se pudo generar el documento.');
        const url = URL.createObjectURL(await response.blob()); const a = document.createElement('a'); a.href = url; a.download = download === 'acta' ? 'acta-toma-inventario.pdf' : 'toma-inventario.xlsx'; a.click(); URL.revokeObjectURL(url); return;
    }
    if (!action) return;
    const data = { version: take.version };
    if (action === 'abrir') { data.camarero_ids = [...$('create').elements.camarero_ids.selectedOptions].map((o) => Number(o.value)); if (!data.camarero_ids.length) throw new Error('Selecciona los camareros de la toma.'); }
    if (action === 'anular') { const reason = prompt('Motivo de anulación'); if (!reason?.trim()) return; data.motivo = reason; }
    if (action === 'aprobar' && !confirm('Se aplicarán todos los ajustes y reubicaciones elegidos. ¿Aprobar la toma?')) return;
    take = (await mutate(`/api/materiales/tomas/${take.id}/${action}`, data)).data; render();
}));
$('rows').addEventListener('click', (e) => { const id = e.target.dataset.decision; if (!id) return; result = take.diferencias.find((r) => r.id === id); const form = $('decide'); form.reset(); form.elements.accion.value = result.accion || 'aceptar_sin_ajuste'; form.elements.motivo.value = result.motivo || ''; form.elements.posicion_destino_id.innerHTML = options(take.posiciones.map((p) => ({ id: p.posicion_id, nombre: `${p.camara} ${p.posicion}` })), 'nombre'); form.elements.posicion_destino_id.value = result.posicion_id; $('decisionContext').textContent = `${result.folio}: ${result.tipo}`; destination(); $('decision').showModal(); });
function destination() { const required = $('decide').elements.accion.value === 'reubicar'; $('destinationLabel').hidden = !required; $('decide').elements.posicion_destino_id.required = required; }
$('decide').elements.accion.addEventListener('change', destination);
$('cancelDecision').addEventListener('click', () => $('decision').close());
$('decide').addEventListener('submit', (e) => { e.preventDefault(); void run(async () => { const f = e.target.elements; take = (await mutate(`/api/materiales/tomas/resultados/${result.id}/decidir`, { version: take.version, accion: f.accion.value, motivo: f.motivo.value, ...(f.accion.value === 'reubicar' ? { posicion_destino_id: f.posicion_destino_id.value } : {}) })).data; $('decision').close(); render(); }); });
if (!token) window.location.replace('/oficina/materiales'); else void run(load);
