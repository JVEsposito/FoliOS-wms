import { showOfficeToast } from './office-toast.js';

const byId = (id) => document.getElementById(id);
const form = byId('recordFormatForm');
const errorRegion = byId('recordFormatError');
const table = byId('recordFormatsTableBody');
const state = { formats: [], busy: false, historyId: null, nextHistory: null };
const escapeHtml = (value) => String(value ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&#039;');

function identity() {
    try { return JSON.parse(localStorage.getItem('estiba_wms_office_identity') || 'null'); } catch { return null; }
}

async function api(path, options = {}) {
    const headers = { Accept: 'application/json', Authorization: `Bearer ${localStorage.getItem('estiba_wms_office_token')}` };
    if (options.body) headers['Content-Type'] = 'application/json';
    const response = await fetch(path, { ...options, headers });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(Object.values(data.errors || {}).flat()[0] || data.message || 'No fue posible consultar los formatos.');
    return data;
}

function resetForm() {
    form.reset();
    form.elements.id.value = '';
    form.elements.actualizado_at_conocido.value = '';
    form.elements.codigo.readOnly = false;
    byId('cancelRecordFormatEdit').classList.add('is-hidden');
}

async function loadFormats() {
    const authorized = identity()?.puede_administrar_accesos === true;
    byId('administration-tab-formats').classList.toggle('is-hidden', !authorized);
    if (!authorized || !localStorage.getItem('estiba_wms_office_token')) {
        state.formats = [];
        form.hidden = true;
        table.innerHTML = '';
        byId('recordFormatHistory').classList.add('is-hidden');
        return;
    }
    form.hidden = false;
    try {
        const response = await api('/api/administracion/formatos-registro');
        state.formats = response.data || [];
        byId('recordFormatsSummary').textContent = `${state.formats.length} registrados`;
        table.innerHTML = state.formats.map((item) => `<tr>
            <td><strong>${escapeHtml(item.codigo)}</strong><small>${escapeHtml(item.nombre)}</small></td>
            <td>${escapeHtml(item.version)}<small>${escapeHtml(item.fecha_vigencia)}</small></td>
            <td><span class="access-status access-status--${item.activo ? 'active' : 'inactive'}">${item.activo ? 'Activo' : 'Inactivo'}</span></td>
            <td>${escapeHtml(item.actualizado_por || 'Registro inicial')}<small>${escapeHtml(new Date(item.actualizado_at).toLocaleString('es-CL'))}</small></td>
            <td><div class="admin-season-actions"><button type="button" data-edit-format="${item.id}">Editar</button><button type="button" data-history-format="${item.id}">Historial</button></div></td>
        </tr>`).join('') || '<tr class="admin-empty"><td colspan="5">No hay formatos registrados.</td></tr>';
    } catch (error) { errorRegion.textContent = error.message; }
}

function snapshotText(data) {
    if (!data) return 'Creación';
    return escapeHtml(`${data.codigo} · ${data.nombre} · versión ${data.version} · ${String(data.fecha_vigencia).slice(0, 10)} · ${data.localidad} · ${data.activo ? 'Activo' : 'Inactivo'}`);
}

async function showHistory(id, next = false) {
    try {
        const response = await api(next ? state.nextHistory : `/api/administracion/formatos-registro/${id}/eventos`);
        const history = response.data;
        state.historyId = id;
        // El backend pagina para que el historial no crezca sin límite en el navegador.
        const nextUrl = history.next_page_url ? new URL(history.next_page_url, window.location.origin) : null;
        state.nextHistory = nextUrl ? nextUrl.pathname + nextUrl.search : null;
        byId('recordFormatHistory').classList.remove('is-hidden');
        byId('recordFormatHistoryTitle').textContent = `Historial de ${state.formats.find((item) => item.id === id)?.codigo || 'formato'}`;
        if (!next) byId('recordFormatHistoryBody').innerHTML = '';
        byId('recordFormatHistoryBody').insertAdjacentHTML('beforeend', history.data.map((event) => `<tr><td>${escapeHtml(new Date(event.created_at).toLocaleString('es-CL'))}<small>${escapeHtml(event.usuario?.name)}</small></td><td>${snapshotText(event.antes)}</td><td>${snapshotText(event.despues)}</td></tr>`).join('') || '<tr><td colspan="3">Registro inicial de despliegue, sin modificaciones.</td></tr>');
        byId('recordFormatHistoryMore').classList.toggle('is-hidden', !state.nextHistory);
    } catch (error) { errorRegion.textContent = error.message; }
}

form?.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (state.busy) return;
    errorRegion.textContent = '';
    const data = Object.fromEntries(new FormData(form));
    const id = data.id;
    delete data.id;
    if (!id) delete data.actualizado_at_conocido;
    data.activo = form.elements.activo.checked;
    state.busy = true;
    const submit = form.querySelector('[type="submit"]');
    submit.disabled = true;
    try {
        await api(`/api/administracion/formatos-registro${id ? `/${id}` : ''}`, { method: id ? 'PUT' : 'POST', body: JSON.stringify(data) });
        resetForm();
        await loadFormats();
        byId('recordFormatHistory').classList.add('is-hidden');
        showOfficeToast(byId('officeToasts'), 'Formato de registro guardado.');
    } catch (error) {
        errorRegion.textContent = error.message;
        showOfficeToast(byId('officeToasts'), error.message, true);
    } finally { state.busy = false; submit.disabled = false; }
});

table?.addEventListener('click', (event) => {
    const edit = event.target.closest('[data-edit-format]');
    const history = event.target.closest('[data-history-format]');
    if (history) { void showHistory(history.dataset.historyFormat); return; }
    if (!edit) return;
    const item = state.formats.find((item) => item.id === edit.dataset.editFormat);
    if (!item) return;
    for (const field of ['id', 'codigo', 'nombre', 'version', 'fecha_vigencia', 'localidad']) form.elements[field].value = item[field];
    form.elements.activo.checked = item.activo;
    form.elements.codigo.readOnly = true;
    form.elements.actualizado_at_conocido.value = item.actualizado_at;
    byId('cancelRecordFormatEdit').classList.remove('is-hidden');
    form.elements.version.focus();
});
byId('recordFormatHistoryMore')?.addEventListener('click', () => { if (state.nextHistory) void showHistory(state.historyId, true); });
byId('cancelRecordFormatEdit')?.addEventListener('click', resetForm);
byId('reloadAccessesButton')?.addEventListener('click', () => void loadFormats());
window.addEventListener('estiba:office-session', () => void loadFormats());
if (form) void loadFormats();
