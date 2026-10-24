import './office-navigation.js';

const $ = id => document.getElementById(`warehouse${id}`);
const base = '/api/materiales/bodegas';
const esc = v => String(v ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&#039;');
let rows = []; let canEdit = false; let selected = null; let busy = false; let loadSeq = 0; let historySeq = 0; let historyId = null; let historyPage = 1; let historyLast = 1;
const retries = new Map();
const uuid = () => globalThis.crypto?.randomUUID?.() ?? 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => { const n = Math.random() * 16 | 0; return (c === 'x' ? n : n & 3 | 8).toString(16); });
const time = value => new Intl.DateTimeFormat('es-CL', { timeZone: 'America/Santiago', dateStyle: 'short', timeStyle: 'short' }).format(new Date(value.replace(' ', 'T') + (/[Z+]/.test(value) ? '' : 'Z')));
async function api(path = '', options = {}) {
    const response = await fetch(base + path, { cache: 'no-store', ...options, headers: { Accept: 'application/json', 'Content-Type': 'application/json', Authorization: `Bearer ${localStorage.getItem('estiba_wms_office_token') || ''}` } });
    const body = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(response.status === 401 ? 'Tu sesión venció. Ingresa nuevamente desde Materiales.' : response.status === 403 ? 'Tu perfil no permite esta acción.' : Object.values(body.errors || {}).flat()[0] || body.message || 'No se pudo completar la operación.');
    return body;
}
function controls() {
    $('New').hidden = !canEdit; $('New').disabled = busy; $('Reload').disabled = busy; $('Fields').disabled = busy || !canEdit;
    $('Form').querySelector('[type=submit]').disabled = busy || !canEdit;
    $('Close').disabled = busy;
}
function render() {
    const q = $('Search').value.trim().toLocaleLowerCase('es');
    const filtered = rows.filter(a => ($('Inactive').checked || a.activo) && `${a.codigo} ${a.nombre}`.toLocaleLowerCase('es').includes(q));
    $('List').innerHTML = filtered.map(a => `<article class="custody-card"><p class="eyebrow">${esc(a.codigo)} · ${a.activo ? 'Activa' : 'Inactiva'}</p><h2>${esc(a.nombre)}</h2><p>${esc(a.descripcion)}</p><p>${a.requiere_ubicacion_fisica ? 'Con cámara y sesión de estiba' : 'Por bodega y folio, sin plano de cámara'}${a.central ? ' · Bodega de recepción inicial' : ''}</p><p>${a.con_existencias ? 'Tiene existencias o reservas' : 'Sin existencias ni reservas'}</p><div class="warehouse-tools"><a class="secondary-button" href="/oficina/materiales/almacenes?almacen_id=${encodeURIComponent(a.id)}">Ver existencias</a><a class="secondary-button" href="/oficina/materiales/capacidad?almacen_id=${encodeURIComponent(a.id)}">Capacidad</a><button class="secondary-button" type="button" data-history="${esc(a.id)}">Historial</button>${canEdit ? `<button class="secondary-button" type="button" data-edit="${esc(a.id)}">Editar</button>` : ''}</div></article>`).join('') || '<p>No hay bodegas en este filtro.</p>';
}
async function load() {
    const seq = ++loadSeq;
    const data = await api(); if (seq !== loadSeq) return;
    rows = data.data; canEdit = data.puede_administrar === true; render(); controls();
}
function edit(id = null) {
    if (busy || !canEdit) return;
    selected = id ? rows.find(a => a.id === id) : null;
    const form = $('Form'); form.reset(); $('Error').textContent = '';
    for (const name of ['codigo', 'nombre', 'descripcion']) form.elements[name].value = selected?.[name] || '';
    form.elements.codigo.readOnly = Boolean(selected);
    form.elements.activo.value = selected?.activo === false ? '0' : '1';
    form.elements.requiere_ubicacion_fisica.value = selected?.requiere_ubicacion_fisica ? '1' : '0';
    form.elements.activo.disabled = selected?.central === true;
    form.elements.requiere_ubicacion_fisica.disabled = selected?.central === true;
    $('EditTitle').textContent = selected ? `Editar ${selected.codigo}` : 'Crear bodega física';
    $('Dialog').showModal();
}
$('Form').addEventListener('submit', async event => {
    event.preventDefault(); if (busy || !canEdit) return;
    const form = event.currentTarget;
    const data = { version: selected?.version ?? 0, codigo: form.elements.codigo.value.trim().toUpperCase(), nombre: form.elements.nombre.value.trim(), descripcion: form.elements.descripcion.value.trim() || null, activo: form.elements.activo.value === '1', requiere_ubicacion_fisica: form.elements.requiere_ubicacion_fisica.value === '1', motivo: form.elements.motivo.value.trim() };
    const id = selected?.id || ''; const key = JSON.stringify([id, data]); if (!retries.has(key)) retries.set(key, uuid());
    busy = true; controls(); $('Error').textContent = '';
    try {
        const saved = await api(id ? `/${id}` : '', { method: id ? 'PUT' : 'POST', body: JSON.stringify({ ...data, operacion_id: retries.get(key) }) });
        $('Dialog').close(); $('Notice').textContent = `${saved.data.nombre}: cambio guardado y auditado.`;
        try { await load(); } catch { $('Notice').textContent += ' No se pudo refrescar la lista; pulsa Actualizar.'; }
    } catch (error) { $('Error').textContent = error.message; }
    finally { busy = false; controls(); }
});
async function history(id, page = 1) {
    const seq = ++historySeq; historyId = id; $('HistoryPanel').hidden = false;
    $('History').textContent = 'Cargando historial…';
    const d = await api(`/historial?almacen_id=${encodeURIComponent(id)}&page=${page}`); if (seq !== historySeq) return;
    historyPage = d.current_page; historyLast = d.last_page;
    $('HistoryTitle').textContent = `Historial · ${rows.find(a => a.id === id)?.nombre || 'Bodega'}`;
    $('History').innerHTML = d.data.map(e => { const before = typeof e.antes === 'string' ? JSON.parse(e.antes) : e.antes; const after = typeof e.despues === 'string' ? JSON.parse(e.despues) : e.despues; return `<article class="warehouse-history-row"><strong>${esc(e.usuario)} · ${esc(time(e.ocurrido_at))}</strong><p>${esc(e.motivo)}</p><p>${before ? `${esc(before.nombre)} · ${before.activo ? 'Activa' : 'Inactiva'}` : 'Creación'} → ${esc(after.nombre)} · ${after.activo ? 'Activa' : 'Inactiva'}</p></article>`; }).join('') || '<p>Sin cambios registrados.</p>';
    $('HistoryPage').textContent = `${historyPage} / ${historyLast}`; $('HistoryPrevious').disabled = page <= 1; $('HistoryNext').disabled = page >= historyLast;
}
$('List').addEventListener('click', event => { const b = event.target.closest('[data-edit],[data-history]'); if (!b) return; if (b.dataset.edit) edit(b.dataset.edit); else void history(b.dataset.history).catch(e => { $('History').textContent = e.message; }); });
$('New').addEventListener('click', () => edit()); $('Close').addEventListener('click', () => { if (!busy) $('Dialog').close(); });
$('Dialog').addEventListener('cancel', event => { if (busy) event.preventDefault(); });
$('Search').addEventListener('input', render); $('Inactive').addEventListener('change', render);
$('Reload').addEventListener('click', () => void load().catch(e => { $('Notice').textContent = e.message; }));
$('HistoryPrevious').addEventListener('click', () => void history(historyId, historyPage - 1).catch(e => { $('History').textContent = e.message; }));
$('HistoryNext').addEventListener('click', () => void history(historyId, historyPage + 1).catch(e => { $('History').textContent = e.message; }));
void load().catch(e => { $('Notice').textContent = e.message; });
