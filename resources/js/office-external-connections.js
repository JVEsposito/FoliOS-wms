import { createOriginExplorer } from './shared/origin-explorer.js';

const byId = (id) => document.getElementById(id);
const form = byId('externalConnectionForm');
const table = byId('externalConnectionsBody');
const tab = byId('administration-tab-external-connections');
const panel = byId('administration-panel-external-connections');
const state = { items: [], generation: 0, busy: false, session: null, allowed: null };
const token = () => localStorage.getItem('estiba_wms_office_token') || '';
const escapeHtml = (value) => String(value ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&#039;');
const explorer = form ? createOriginExplorer({ api, authorized, session: token, escapeHtml }) : null;

function authorized() {
    try {
        return Boolean(token()) && JSON.parse(localStorage.getItem('estiba_wms_office_identity') || 'null')?.puede_administrar_accesos === true;
    } catch { return false; }
}

function resetForm() {
    form.reset();
    form.elements.id.value = '';
    form.elements.version_conocida.value = '';
    form.elements.password.required = true;
    byId('externalConnectionPasswordHint').textContent = 'Se guarda cifrada y no se vuelve a mostrar.';
}

function setBusy(busy) {
    state.busy = busy;
    form.querySelectorAll('input, button').forEach((element) => { element.disabled = busy; });
    table.querySelectorAll('button').forEach((element) => { element.disabled = busy || element.dataset.connectionInactive === 'true'; });
    form.setAttribute('aria-busy', String(busy));
}

function clearPrivateData() {
    explorer?.clear();
    state.generation += 1;
    state.session = null;
    state.allowed = null;
    state.items = [];
    resetForm();
    table.replaceChildren();
    byId('externalConnectionError').textContent = '';
    byId('externalConnectionStatus').textContent = '';
    byId('externalConnectionsSummary').textContent = 'Sin cargar';
    form.hidden = true;
    setBusy(false);
}

async function api(path, options = {}) {
    const requestToken = token();
    const response = await fetch(`/api/administracion/conexiones-externas${path}`, {
        ...options,
        cache: 'no-store',
        headers: { Accept: 'application/json', Authorization: `Bearer ${requestToken}`, ...(options.body ? { 'Content-Type': 'application/json' } : {}) },
    });
    const data = await response.json().catch(() => ({}));
    if ((response.status === 401 || response.status === 403) && requestToken === token()) clearPrivateData();
    if (!response.ok) {
        const message = response.status >= 500 ? 'No fue posible completar la operación.' : Object.values(data.errors || {}).flat()[0] || data.message || 'No fue posible completar la operación.';
        throw new Error(message);
    }
    return data;
}

function render() {
    byId('externalConnectionsSummary').textContent = `${state.items.length} registradas`;
    table.innerHTML = state.items.map((item) => {
        const test = item.ultima_prueba_estado === 'correcta' ? 'Correcta' : item.ultima_prueba_estado === 'fallida' ? 'Fallida' : 'Sin probar';
        return `<tr>
            <td><strong>${escapeHtml(item.nombre)}</strong><small>MySQL · lectura</small></td>
            <td>${item.activa ? 'Activa' : 'Inactiva'}</td>
            <td>${test}${item.ultima_prueba_at ? `<small>${escapeHtml(new Date(item.ultima_prueba_at).toLocaleString('es-CL'))}</small>` : ''}</td>
            <td>${escapeHtml(item.actualizado_por || '—')}</td>
            <td><div class="admin-season-actions"><button type="button" data-edit-connection="${escapeHtml(item.id)}">Editar</button><button type="button" data-test-connection="${escapeHtml(item.id)}" data-connection-inactive="${!item.activa}" ${item.activa ? '' : 'disabled'}>Probar conexión</button><button type="button" data-explore-connection="${escapeHtml(item.id)}" data-connection-inactive="${!item.activa}" ${item.activa ? '' : 'disabled'}>Explorar origen</button></div></td>
        </tr>`;
    }).join('') || '<tr class="admin-empty"><td colspan="5">No hay conexiones configuradas. Completa el formulario para crear una.</td></tr>';
}

async function load() {
    if (!authorized() || state.busy) return;
    const generation = ++state.generation;
    const session = token();
    try {
        const response = await api('');
        if (generation !== state.generation || session !== token() || !authorized()) return;
        state.items = response.data || [];
        form.hidden = false;
        render();
    } catch (error) {
        if (generation === state.generation && authorized()) byId('externalConnectionError').textContent = error.message;
    }
}

function syncSession() {
    if (!form) return;
    const allowed = authorized();
    const session = token();
    if (state.session === session && state.allowed === allowed) return;
    clearPrivateData();
    state.session = session;
    state.allowed = allowed;
    form.hidden = !allowed;
    tab.classList.toggle('is-hidden', !allowed);
    if (!allowed && tab.getAttribute('aria-selected') === 'true') byId('administration-tab-seasons').click();
    if (allowed && !panel.hidden) void load();
}

form?.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (state.busy || !authorized()) return;
    const data = Object.fromEntries(new FormData(form));
    const id = data.id;
    delete data.id;
    data.puerto = Number(data.puerto);
    data.activa = form.elements.activa.checked;
    if (!id) delete data.version_conocida;
    else data.version_conocida = Number(data.version_conocida);
    if (id && !data.password) delete data.password;
    form.elements.password.value = '';
    byId('externalConnectionError').textContent = '';
    byId('externalConnectionStatus').textContent = '';
    const generation = ++state.generation;
    const session = token();
    setBusy(true);
    try {
        await api(id ? `/${encodeURIComponent(id)}` : '', { method: id ? 'PUT' : 'POST', body: JSON.stringify(data) });
        if (generation !== state.generation || session !== token() || !authorized()) return;
        explorer.clear();
        resetForm();
        byId('externalConnectionStatus').textContent = 'Conexión guardada. Puedes comprobarla con «Probar conexión».';
    } catch (error) {
        if (generation === state.generation && session === token() && authorized()) byId('externalConnectionError').textContent = error.message;
    } finally {
        delete data.password;
        if (generation === state.generation && session === token() && authorized()) {
            setBusy(false);
            await load();
        }
    }
});

table?.addEventListener('click', async (event) => {
    if (state.busy || !authorized()) return;
    const edit = event.target.closest('[data-edit-connection]');
    const test = event.target.closest('[data-test-connection]');
    const explore = event.target.closest('[data-explore-connection]');
    const item = state.items.find((candidate) => candidate.id === (edit?.dataset.editConnection || test?.dataset.testConnection || explore?.dataset.exploreConnection));
    if (!item) return;
    if (explore && item.activa) { void explorer.open(item); return; }
    if (edit) {
        resetForm();
        for (const field of ['id', 'nombre', 'host', 'puerto', 'usuario', 'base_datos']) form.elements[field].value = item[field] ?? '';
        form.elements.version_conocida.value = item.version;
        form.elements.activa.checked = item.activa;
        form.elements.password.required = !item.password_configurada;
        byId('externalConnectionPasswordHint').textContent = 'Deja vacía la contraseña para conservar la guardada. Escribe una nueva para reemplazarla.';
        byId('externalConnectionError').textContent = '';
        byId('externalConnectionStatus').textContent = `Editando ${item.nombre}. La prueba usa la configuración guardada.`;
        form.elements.nombre.focus();
        return;
    }
    if (!test || !item.activa) return;
    const generation = ++state.generation;
    const session = token();
    byId('externalConnectionError').textContent = '';
    byId('externalConnectionStatus').textContent = 'Comprobando desde el servidor de FoliOS…';
    setBusy(true);
    try {
        const result = await api(`/${encodeURIComponent(item.id)}/probar`, { method: 'POST', body: JSON.stringify({ version_conocida: item.version }) });
        if (generation === state.generation && session === token() && authorized()) byId('externalConnectionStatus').textContent = result.message;
    } catch (error) {
        if (generation === state.generation && session === token() && authorized()) {
            byId('externalConnectionStatus').textContent = '';
            byId('externalConnectionError').textContent = error.message;
        }
    } finally {
        if (generation === state.generation && session === token() && authorized()) { setBusy(false); await load(); }
    }
});

byId('newExternalConnection')?.addEventListener('click', () => { resetForm(); byId('externalConnectionStatus').textContent = ''; byId('externalConnectionError').textContent = ''; });
byId('reloadAccessesButton')?.addEventListener('click', () => { if (panel && !panel.hidden) void load(); });
document.addEventListener('estiba:office-panel-change', (event) => {
    if (event.detail?.group === 'administration' && event.detail?.panel === 'external-connections') void load();
});
window.addEventListener('estiba:office-session', syncSession);
window.addEventListener('storage', (event) => { if (['estiba_wms_office_token', 'estiba_wms_office_identity'].includes(event.key) || event.key === null) syncSession(); });
if (form) syncSession();
