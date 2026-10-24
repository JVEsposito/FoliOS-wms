import { createUuid } from './shared/uuid.js';
import './office-navigation';
const $ = (id) => document.getElementById(id);
const labels = { merma: 'Merma', faltante: 'Faltante', sobrante: 'Sobrante', error_conteo: 'Error de conteo', diferencia_ubicacion: 'Diferencia de ubicación', otro: 'Otro motivo' };
const when = v => v ? new Intl.DateTimeFormat('es-CL', { timeZone: 'America/Santiago', dateStyle: 'short', timeStyle: 'short' }).format(new Date(v)) : '—';
let page = 1; let lastPage = 1;
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
    if (retry?.key !== key) retry = { key, id: createUuid() };
    const response = await api(path, { ...data, operacion_id: retry.id }); retry = null; return response;
}
async function run(work) {
    if (busy) return; busy = true;
    $('notice').textContent = ''; $('decisionError').textContent = '';
    document.querySelectorAll('button').forEach((b) => { b.disabled = true; });
    try { await work(); } catch (e) { $('notice').textContent = e.message; $('decisionError').textContent = e.message; }
    finally { busy = false; document.querySelectorAll('button').forEach((b) => { b.disabled = false; }); $('previousPage').disabled = page <= 1; $('nextPage').disabled = page >= lastPage; }
}
function options(rows, label, blank = '') { return blank + rows.map((r) => `<option value="${escape(r.id)}">${escape(r[label])}</option>`).join(''); }
async function load() {
    const assigned = [...$('create').elements.camarero_ids.selectedOptions].map((o) => o.value);
    const body = await api(`/api/materiales/tomas?${new URLSearchParams({ page, ...($('season').value ? { temporada_id: $('season').value } : {}) })}`); catalogs = body.catalogos; catalogs.activa = body.temporada_activa_id;
    const season = $('season').value || catalogs.activa; $('season').innerHTML = options(catalogs.temporadas, 'nombre'); $('season').value = season;
    $('creation').hidden = season !== catalogs.activa;
    page = body.meta?.page || 1; lastPage = body.meta?.last_page || 1; $('pageInfo').textContent = `${page} / ${lastPage}`;
    $('create').elements.almacen_id.innerHTML = options(catalogs.bodegas, 'nombre', '<option value="">Selecciona una bodega</option>');
    $('create').elements.camara_ids.innerHTML = options(catalogs.camaras, 'codigo');
    $('create').elements.camarero_ids.innerHTML = options(catalogs.camareros, 'name');
    [...$('create').elements.camarero_ids.options].forEach((o) => { o.selected = assigned.includes(o.value); });
    $('create').elements.categoria.innerHTML = '<option value="">Todas</option>' + catalogs.categorias.map((c) => `<option>${escape(c)}</option>`).join('');
    $('takes').innerHTML = '<option value="">Selecciona una toma</option>' + body.data.map((t) => `<option value="${escape(t.id)}">${escape(when(t.created_at))} · ${t.finalidad === 'cierre_temporada' ? 'Cierre' : 'Conteo'} · ${escape(t.estado)} · ${escape(t.id.slice(0, 8))}</option>`).join('');
    if (take && body.data.some(t => t.id === take.id)) { $('takes').value = take.id; await select(take.id); } else await select('');
    creationScope();
}
async function select(id) { if (!id) { $('detail').hidden = true; take = null; return; } take = (await api(`/api/materiales/tomas/${id}`)).data; render(); }
function canApprove() {
    try {
        const identity = JSON.parse(localStorage.getItem('estiba_wms_office_identity') || 'null');
        return (identity?.puede_aprobar_ajustes_materiales ?? identity?.capacidades?.puede_aprobar_ajustes_materiales ?? ['administrador', 'supervisor_materiales'].includes(identity?.rol)) === true;
    } catch { return false; }
}
function render() {
    $('detail').hidden = false; $('heading').textContent = `Toma ${take.id.slice(0, 8)} · ${take.estado}`;
    $('scope').textContent = `${take.temporada} · ${take.bodega || 'Todas las bodegas del alcance'} · ${take.camaras.join(', ')} · ${take.categoria || 'Todas las categorías'} · Abierta por ${take.abierta_por}`;
    $('metrics').textContent = `Exactitud por presencia: ${qty(take.exactitud_presencia_pct)} % · por cantidad: ${qty(take.exactitud_cantidad_pct)} %`;
    $('approval').textContent = take.aprobada_at ? `Aprobada por ${take.aprobada_por} · ${when(take.aprobada_at)}` : '';
    const historical = take.temporada_id !== catalogs.activa;
    const buttons = historical ? '' : take.estado === 'borrador' ? '<button data-action="abrir">Abrir toma y asignar posiciones</button>' : take.estado === 'en_conteo' ? '<button data-action="revisar">Enviar a revisión</button>' : take.estado === 'en_revision' && canApprove() ? '<button data-action="aprobar">Aprobar y aplicar</button>' : '';
    $('controls').innerHTML = buttons + (!historical && !['aprobada', 'anulada'].includes(take.estado) ? '<button data-action="anular">Anular toma</button>' : '') + '<button data-download="excel">Exportar Excel</button><button data-download="acta">Acta PDF</button>';
    $('progress').textContent = `${take.posiciones.filter((p) => p.estado === 'contada').length} de ${take.posiciones.length} posiciones contadas · ` + take.posiciones.map((p) => `${p.camara} ${p.posicion}: ${p.camarero} (${p.estado})`).join(' · ');
    const filters = $('filters').elements;
    filters.cliente_id.innerHTML = options([...new Map(take.diferencias.filter(r => r.cliente_id).map(r => [r.cliente_id, { id: r.cliente_id, nombre: r.cliente }])).values()], 'nombre', '<option value="">Todos</option>');
    filters.item_id.innerHTML = options([...new Map(take.diferencias.filter((r) => r.item_id).map((r) => [r.item_id, { id: r.item_id, item: r.item }])).values()], 'item', '<option value="">Todos</option>');
    filters.categoria.innerHTML = '<option value="">Todas</option>' + [...new Set(take.diferencias.map((r) => r.categoria))].map((c) => `<option>${escape(c)}</option>`).join('');
    renderRows();
    $('closingSummary').hidden = take.finalidad !== 'cierre_temporada';
    $('closingTotals').innerHTML = (take.resumen_cierre || []).map(t => `<p><strong>${escape(t.bodega)} · ${escape(t.cliente)} · ${escape(t.item)} (${escape(t.unidad)})</strong><br>Esperado ${qty(t.esperado)} · Contado ${qty(t.contado)} · Faltante observado ${qty(t.faltante_observado)} · Sobrante observado ${qty(t.sobrante_observado)} · Merma aplicada ${qty(t.merma_aplicada)} · Ajuste aplicado ${qty(t.ajuste_aplicado)}</p>`).join('');
    $('decisionHistory').innerHTML = (take.historial_decisiones || []).map(d => `<p>${escape(when(d.fecha))} · ${escape(d.usuario)} · ${escape(d.folio)} · ${escape(labels[d.clasificacion] || 'Sin clasificar')} · ${escape(d.accion)}: ${escape(d.motivo)}${d.vigente ? '' : ' (lectura reemplazada)'}</p>`).join('') || '<p>Sin decisiones registradas.</p>';
    $('totals').innerHTML = [...take.totales_items, ...take.totales_categorias].map((t) => `<p>${escape(t.item)} / ${escape(t.categoria)} (${escape(t.unidad)}): esperado ${qty(t.esperado)}, contado ${qty(t.contado)}, diferencia ${qty(t.diferencia)}</p>`).join('');
}
function renderRows() {
    const f = $('filters').elements;
    const rows = take.diferencias.filter((r) => (!f.tipo.value || r.tipo === f.tipo.value) && (!f.item_id.value || r.item_id === f.item_id.value) && (!f.categoria.value || r.categoria === f.categoria.value) && (!f.clasificacion.value || r.clasificacion === f.clasificacion.value) && (!f.cliente_id.value || r.cliente_id === f.cliente_id.value));
    $('rows').innerHTML = rows.map((r) => `<tr><td>${escape(r.folio)}<br>${escape(r.camara)} ${escape(r.posicion)}</td><td>${escape(r.item)}<br>${escape(r.categoria)} · ${escape(r.unidad)}</td><td>${qty(r.esperado)}</td><td>${qty(r.contado)}</td><td>${qty(r.diferencia_absoluta)} / ${qty(r.diferencia_pct)} %</td><td>${escape(r.tipo)}<br>${escape(r.accion || '')}<br>${escape(labels[r.clasificacion] || '')}<br>${escape(r.motivo || '')}${r.decidida_por ? `<br>${escape(r.decidida_por)} · ${escape(when(r.decidida_at))}` : ''}${take.temporada_id === catalogs.activa && take.estado === 'en_revision' && r.tipo !== 'coincide' ? `<button class="secondary-button" data-decision="${escape(r.id)}">Elegir acción</button>` : ''}</td></tr>`).join('') || '<tr><td colspan="6">Sin resultados para estos filtros.</td></tr>';
}
$('create').addEventListener('submit', (e) => { e.preventDefault(); void run(async () => { const f = e.target.elements; const body = await mutate('/api/materiales/tomas', { camara_ids: [...f.camara_ids.selectedOptions].map((o) => o.value), categoria: f.categoria.value || null, finalidad: f.finalidad.value, ...(f.finalidad.value === 'cierre_temporada' ? { almacen_id: f.almacen_id.value } : {}) }); page = 1; take = body.data; await load(); }); });
$('takes').addEventListener('change', (e) => void run(() => select(e.target.value)));
$('season').addEventListener('change', () => void run(async () => { take = null; page = 1; await load(); }));
$('previousPage').addEventListener('click', () => void run(async () => { take = null; page--; await load(); }));
$('nextPage').addEventListener('click', () => void run(async () => { take = null; page++; await load(); }));
function creationScope() { const f = $('create').elements; const closing = f.finalidad.value === 'cierre_temporada'; $('warehouseLabel').hidden = !closing; f.almacen_id.required = closing; f.categoria.disabled = closing; if (closing) f.categoria.value = ''; }
$('create').elements.finalidad.addEventListener('change', creationScope);
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
$('rows').addEventListener('click', (e) => { const id = e.target.dataset.decision; if (!id) return; result = take.diferencias.find((r) => r.id === id); const form = $('decide'); form.reset(); form.elements.accion.value = result.accion || 'aceptar_sin_ajuste'; form.elements.motivo.value = result.motivo || ''; form.elements.clasificacion.required = take.finalidad === 'cierre_temporada'; form.elements.clasificacion.value = result.clasificacion || ''; form.elements.posicion_destino_id.innerHTML = options(take.posiciones.map((p) => ({ id: p.posicion_id, nombre: `${p.camara} ${p.posicion}` })), 'nombre'); form.elements.posicion_destino_id.value = result.posicion_id; $('decisionContext').textContent = `${result.folio}: ${result.tipo}`; destination(); $('decision').showModal(); });
function destination() { const action = $('decide').elements.accion.value; const required = ['reubicar', 'reubicar_y_ajustar'].includes(action); $('destinationLabel').hidden = !required; $('decide').elements.posicion_destino_id.required = required; $('decisionContext').textContent = `${result.folio}: ${result.tipo}` + (action === 'reubicar_y_ajustar' ? ` · Saldo al contar: ${qty(result.cantidad_saldo_al_contar)} · Contado: ${qty(result.contado)} · Ajuste: ${qty(result.diferencia_reubicacion)} ${result.unidad || ''}` : ''); }
$('decide').elements.accion.addEventListener('change', destination);
$('cancelDecision').addEventListener('click', () => $('decision').close());
$('decide').addEventListener('submit', (e) => { e.preventDefault(); void run(async () => { const f = e.target.elements; take = (await mutate(`/api/materiales/tomas/resultados/${result.id}/decidir`, { version: take.version, accion: f.accion.value, clasificacion: f.clasificacion.value || null, motivo: f.motivo.value, ...(['reubicar', 'reubicar_y_ajustar'].includes(f.accion.value) ? { posicion_destino_id: f.posicion_destino_id.value } : {}) })).data; $('decision').close(); render(); }); });
if (!token) window.location.replace('/oficina/materiales'); else void run(load);
