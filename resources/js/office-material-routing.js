const $ = id => document.getElementById(`routing${id}`);
const esc = v => String(v ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&#039;');
const uuid = () => globalThis.crypto?.randomUUID?.() ?? 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => { const n = Math.random() * 16 | 0; return (c === 'x' ? n : n & 3 | 8).toString(16); });
const when = value => new Intl.DateTimeFormat('es-CL', { timeZone: 'America/Santiago', dateStyle: 'short', timeStyle: 'short' }).format(new Date(String(value).replace(' ', 'T') + (/Z$|[+-]\d{2}:\d{2}$/.test(value) ? '' : 'Z')));
const state = { rows: [], warehouses: [], clients: [], can: false, loaded: false, busy: false, editing: null, retry: null, page: 1, seq: 0, historySeq: 0 };
async function api(path = '', options = {}) {
    const r = await fetch(`/api/materiales/asignacion-bodegas${path}`, { cache: 'no-store', ...options, headers: { Accept: 'application/json', 'Content-Type': 'application/json', Authorization: `Bearer ${localStorage.getItem('estiba_wms_office_token') || ''}` } });
    const d = await r.json().catch(() => ({}));
    if (!r.ok) { const e = new Error(r.status === 401 ? 'Tu sesión venció. Ingresa nuevamente desde Materiales.' : r.status === 403 ? 'Tu perfil no permite configurar reglas.' : Object.values(d.errors || {}).flat()[0] || d.message || 'No se pudo completar la operación.'); e.status = r.status; throw e; }
    return d;
}
function controls() { $('New').hidden = !state.can; $('New').disabled = state.busy; $('Reload').disabled = state.busy; $('Fields').disabled = state.busy || !state.can; $('Form').querySelector('[type=submit]').disabled = state.busy || !state.can; $('Cancel').disabled = state.busy; $('Rows').querySelectorAll('button').forEach(b => { b.disabled = state.busy; }); }
function pages(target, meta) { $(target).innerHTML = `<button class="secondary-button" type="button" data-page="${meta.pagina - 1}" ${meta.pagina <= 1 ? 'disabled' : ''}>Anterior</button><span>${meta.pagina} / ${meta.paginas}</span><button class="secondary-button" type="button" data-page="${meta.pagina + 1}" ${meta.pagina >= meta.paginas ? 'disabled' : ''}>Siguiente</button>`; }
const category = value => ({ insumo: 'Insumo', material_mp: 'Material de embalaje sin preparar', material_pt: 'Material preparado para línea' }[value] || 'Todos los tipos');
async function load(page = state.page) {
    const seq = ++state.seq; state.can = false; controls(); $('Notice').textContent = 'Consultando reglas…';
    try {
        const d = await api(`?page=${page}`); if (seq !== state.seq) return false;
        state.loaded = true; state.rows = d.data; state.warehouses = d.almacenes; state.clients = d.clientes; state.can = d.puede_configurar === true; state.page = d.meta.pagina;
        $('Rows').innerHTML = state.rows.map(r => `<article><p><strong>${esc(r.prioridad)} · ${esc(r.nombre)}</strong> · ${Number(r.activo) ? 'Activa' : 'Inactiva'} · versión ${esc(r.version)}</p><p>${esc(r.cliente || 'Todos los clientes')} · ${esc(category(r.categoria_operacional))} → ${esc(r.bodega)}${Number(r.bodega_activa) ? '' : ' (bodega inactiva: no se aplica)'}</p>${state.can ? `<button class="secondary-button" type="button" data-edit="${esc(r.id)}">Editar regla</button>` : ''}</article>`).join('') || '<p>No hay reglas: los ingresos nuevos conservan Bodega Central.</p>';
        pages('Pages', d.meta); $('Notice').textContent = state.can ? '' : 'Consulta: tu perfil no permite modificar reglas.';
        return true;
    } catch (e) { if (seq === state.seq) { state.can = false; $('Notice').textContent = e.message; } return false; }
    finally { if (seq === state.seq) controls(); }
}
function edit(id = null) {
    if (state.busy || !state.can) return;
    state.editing = id ? state.rows.find(r => r.id === id) : null; state.retry = null;
    const f = $('Form'); f.reset();
    f.elements.cliente_id.innerHTML = '<option value="">Todos los clientes</option>' + state.clients.map(c => `<option value="${esc(c.id)}">${esc(c.codigo)} · ${esc(c.nombre)}</option>`).join('');
    f.elements.almacen_material_id.innerHTML = '<option value="">Selecciona bodega</option>' + state.warehouses.map(w => `<option value="${esc(w.id)}">${esc(w.codigo)} · ${esc(w.nombre)}${Number(w.activo) ? '' : ' (inactiva)'}</option>`).join('');
    // Preserve historical selection if a client was deactivated; backend validates reactivation.
    if (state.editing?.cliente_id && !state.clients.some(c => c.id === state.editing.cliente_id)) f.elements.cliente_id.add(new Option(`${state.editing.cliente || 'Cliente inactivo'} (inactivo)`, state.editing.cliente_id));
    if (state.editing) for (const n of ['nombre', 'prioridad', 'cliente_id', 'categoria_operacional', 'almacen_material_id']) f.elements[n].value = state.editing[n] ?? '';
    f.elements.activo.value = state.editing ? String(Number(state.editing.activo)) : '1';
    $('Title').textContent = state.editing ? 'Editar regla de recepción' : 'Crear regla de recepción'; f.hidden = false; controls(); f.scrollIntoView({ block: 'nearest' });
}
async function history(page = 1) {
    const seq = ++state.historySeq; $('HistoryRows').textContent = 'Consultando historial…';
    try { const d = await api(`/historial?page=${page}`); if (seq !== state.historySeq) return;
        $('HistoryRows').innerHTML = d.data.map(e => `<details><summary>${esc(when(e.ocurrido_at))} · ${esc(e.usuario)} · ${esc(e.despues.nombre)}</summary><p>${esc(e.motivo)}</p><p>Antes: ${esc(e.antes ? `${e.antes.nombre} · prioridad ${e.antes.prioridad} · ${Number(e.antes.activo) ? 'Activa' : 'Inactiva'}` : 'Creación')}</p><p>Después: prioridad ${esc(e.despues.prioridad)} · ${Number(e.despues.activo) ? 'Activa' : 'Inactiva'}</p></details>`).join('') || '<p>Sin cambios registrados.</p>'; pages('HistoryPages', d.meta);
    } catch (e) { if (seq === state.historySeq) $('HistoryRows').textContent = e.message; }
}
$('Panel').addEventListener('toggle', () => { if ($('Panel').open && !state.loaded && !state.busy) void load(); });
$('Reload').addEventListener('click', () => { if (!state.busy) void load(); });
$('New').addEventListener('click', () => edit());
$('Rows').addEventListener('click', e => { const b = e.target.closest('[data-edit]'); if (b) edit(b.dataset.edit); });
$('Cancel').addEventListener('click', () => { if (!state.busy) { $('Form').hidden = true; state.retry = null; state.editing = null; } });
$('Form').addEventListener('input', () => { if (!state.busy) state.retry = null; });
$('Form').addEventListener('change', () => { if (!state.busy) state.retry = null; });
$('Form').addEventListener('submit', async e => {
    e.preventDefault(); if (state.busy || !state.can) return;
    const f = e.currentTarget;
    const op = state.retry || { id: state.editing?.id || '', body: { operacion_id: uuid(), version: state.editing?.version || 0, nombre: f.elements.nombre.value.trim(), prioridad: Number(f.elements.prioridad.value), cliente_id: f.elements.cliente_id.value || null, categoria_operacional: f.elements.categoria_operacional.value || null, almacen_material_id: f.elements.almacen_material_id.value, activo: f.elements.activo.value === '1', motivo: f.elements.motivo.value.trim() } };
    state.retry = op; state.busy = true; controls(); $('Notice').textContent = 'Guardando regla…';
    try { await api(op.id ? `/${op.id}` : '', { method: op.id ? 'PUT' : 'POST', body: JSON.stringify(op.body) });
        state.retry = null; state.editing = null; f.hidden = true; const loaded = await load(); $('Notice').textContent = loaded ? 'Regla guardada y auditada. Se aplicará a las próximas confirmaciones.' : 'Regla guardada. Actualiza la consulta.'; if ($('History').open) void history();
    } catch (error) { if (error.status && error.status < 500) state.retry = null; if (error.status === 401 || error.status === 403) state.can = false; $('Notice').textContent = error.message; }
    finally { state.busy = false; controls(); }
});
$('History').addEventListener('toggle', () => { if ($('History').open) void history(); });
for (const [target, fn] of [['Pages', load], ['HistoryPages', history]]) $(target).addEventListener('click', e => { const b = e.target.closest('[data-page]'); if (b && !b.disabled && !state.busy) void fn(Number(b.dataset.page)); });
window.addEventListener('estiba:material-warehouses-updated', () => { state.loaded = false; if ($('Panel').open && !state.busy) void load(); });
controls();
