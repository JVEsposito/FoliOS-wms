import { createUuid } from './shared/uuid.js';
const $ = (id) => document.getElementById(`bodega${id}`);
const esc = (v) => String(v ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&#039;');
const state = { rows: [], edit: null, pending: null, busy: false, can: false, loaded: false, sequence: 0, historySequence: 0, timezone: 'America/Santiago' };
function date(value) {
    const iso = String(value).replace(' ', 'T');
    const d = new Date(/Z$|[+-]\d{2}:\d{2}$/.test(iso) ? iso : `${iso}Z`);
    return Number.isNaN(d.getTime()) ? value : new Intl.DateTimeFormat('es-CL', { dateStyle: 'short', timeStyle: 'medium', timeZone: state.timezone }).format(d);
}
async function api(path = '', options = {}) {
    const r = await fetch(`/api/materiales/bodegas${path}`, { cache: 'no-store', ...options, headers: { Accept: 'application/json', 'Content-Type': 'application/json', Authorization: `Bearer ${localStorage.getItem('estiba_wms_office_token') || ''}` } });
    const body = await r.json().catch(() => ({}));
    if (!r.ok) {
        const error = new Error(r.status === 401 ? 'Tu sesión venció. Ingresa nuevamente desde Materiales.' : r.status === 403 ? 'Tu perfil no tiene permiso para esta acción.' : Object.values(body.errors || {}).flat()[0] || body.message || 'No se pudo completar la operación.');
        error.status = r.status; throw error;
    }
    return body;
}
function controls() {
    $('Fields').disabled = state.busy || !state.can;
    $('Reload').disabled = state.busy;
    $('New').hidden = !state.can; $('New').disabled = state.busy;
    $('Cancel').disabled = state.busy;
    $('Form').querySelector('[type="submit"]').disabled = state.busy || !state.can;
    $('List').querySelectorAll('[data-edit-bodega]').forEach((b) => { b.disabled = state.busy || !state.can; });
}
function edit(id = null) {
    if (state.busy || !state.can) return;
    state.edit = id ? state.rows.find((w) => w.id === id) : null; state.pending = null;
    const f = $('Form'); f.reset();
    for (const name of ['codigo', 'nombre', 'descripcion']) f.elements[name].value = state.edit?.[name] || '';
    f.elements.codigo.readOnly = Boolean(state.edit);
    f.elements.activo.value = state.edit ? String(Number(state.edit.activo)) : '1';
    $('Title').textContent = state.edit ? `Editar ${state.edit.codigo}` : 'Crear bodega física';
    f.hidden = false; controls(); f.scrollIntoView({ block: 'nearest' });
}
async function load() {
    const sequence = ++state.sequence;
    state.can = false; controls(); $('Notice').textContent = 'Consultando bodegas…';
    try {
        const body = await api(); if (sequence !== state.sequence) return false;
        state.rows = body.data; state.can = body.puede_configurar === true; state.loaded = true;
        state.timezone = body.zona_horaria || 'America/Santiago';
        if (state.edit && !state.pending && state.can) edit(state.edit.id);
        $('List').innerHTML = state.rows.map((w) => `<article><p><strong>${esc(w.codigo)} · ${esc(w.nombre)}</strong> · ${Number(w.activo) ? 'Activa' : 'Inactiva'} · versión ${esc(w.version_bodega)}${w.descripcion ? `<br>${esc(w.descripcion)}` : ''}</p>${state.can ? `<button class="secondary-button" type="button" data-edit-bodega="${esc(w.id)}">Editar bodega</button>` : ''}</article>`).join('') || '<p>Sin bodegas físicas registradas.</p>';
        $('Notice').textContent = state.can ? '' : 'Consulta: tu perfil no permite administrar bodegas.';
        return true;
    } catch (error) {
        if (sequence === state.sequence) { state.can = false; $('Notice').textContent = error.message; }
        return false;
    } finally { if (sequence === state.sequence) controls(); }
}
async function history(page = 1) {
    const sequence = ++state.historySequence; $('HistoryRows').textContent = 'Consultando historial…'; $('HistoryPages').replaceChildren();
    try {
        const body = await api(`/historial?page=${page}`); if (sequence !== state.historySequence) return;
        $('HistoryRows').innerHTML = body.data.map((e) => `<details><summary>${esc(date(e.ocurrido_at))} · ${esc(e.usuario)} · ${esc(e.resultado.codigo)}</summary><p>Antes: ${e.anterior ? `${esc(e.anterior.nombre)} · ${Number(e.anterior.activo) ? 'Activa' : 'Inactiva'}` : 'Sin registro anterior'}</p><p>Después: ${esc(e.resultado.nombre)} · ${Number(e.resultado.activo) ? 'Activa' : 'Inactiva'}</p></details>`).join('') || '<p>Sin cambios registrados.</p>';
        const m = body.meta;
        $('HistoryPages').innerHTML = `<button type="button" class="secondary-button" data-page="${m.pagina - 1}" ${m.pagina <= 1 ? 'disabled' : ''}>Anterior</button><span>Página ${m.pagina} de ${m.paginas}</span><button type="button" class="secondary-button" data-page="${m.pagina + 1}" ${m.pagina >= m.paginas ? 'disabled' : ''}>Siguiente</button>`;
    } catch (error) { if (sequence === state.historySequence) $('HistoryRows').textContent = error.message; }
}
$('Directory').addEventListener('toggle', () => { if ($('Directory').open && !state.loaded && !state.busy) void load(); });
$('Reload').addEventListener('click', () => { if (!state.busy) void load(); });
$('New').addEventListener('click', () => edit());
$('Cancel').addEventListener('click', () => { if (!state.busy) { state.edit = null; state.pending = null; $('Form').hidden = true; } });
$('List').addEventListener('click', (event) => { const button = event.target.closest('[data-edit-bodega]'); if (button) edit(button.dataset.editBodega); });
$('Form').addEventListener('input', () => { state.pending = null; });
$('Form').addEventListener('submit', async (event) => {
    event.preventDefault(); if (state.busy || !state.can) return;
    const f = event.target;
    const operation = state.pending || { path: state.edit ? `/${state.edit.id}` : '', method: state.edit ? 'PUT' : 'POST', body: {
        operacion_id: createUuid(), version: Number(state.edit?.version_bodega || 0), codigo: f.elements.codigo.value.trim().toUpperCase(),
        nombre: f.elements.nombre.value.trim(), descripcion: f.elements.descripcion.value.trim() || null, activo: f.elements.activo.value === '1',
    } };
    state.pending = operation; state.busy = true; controls(); $('Notice').textContent = 'Guardando bodega…';
    try {
        await api(operation.path, { method: operation.method, body: JSON.stringify(operation.body) });
        state.pending = null; state.edit = null; f.hidden = true;
        const refreshed = await load();
        $('Notice').textContent = refreshed ? 'Bodega guardada y registrada en el historial. Las existencias se gestionan desde Inventario CC.' : 'Bodega guardada. No se pudo actualizar la consulta; pulsa Actualizar bodegas.';
        if ($('History').open) void history();
        window.dispatchEvent(new CustomEvent('estiba:material-warehouses-updated'));
    } catch (error) {
        if (error.status && error.status < 500) state.pending = null;
        if (error.status === 401 || error.status === 403) state.can = false;
        $('Notice').textContent = error.message;
    } finally { state.busy = false; controls(); }
});
$('History').addEventListener('toggle', () => { if ($('History').open) void history(); });
$('HistoryPages').addEventListener('click', (event) => { const button = event.target.closest('[data-page]'); if (button && !button.disabled) void history(Number(button.dataset.page)); });
controls();
