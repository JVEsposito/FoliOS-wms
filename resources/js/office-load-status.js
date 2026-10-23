import { createOperationalPoller } from './shared/operational-poller';
import { loadReceptionDependencies } from './shared/load-reception-dependencies.js';

const byId = (id) => document.getElementById(id);
const keys = { token: 'estiba_wms_office_token', identity: 'estiba_wms_office_identity' };
const elements = {
    access: byId('officeAccess'), app: byId('officeApp'), login: byId('officeLoginForm'), loginError: byId('officeLoginError'),
    reload: byId('reloadLoadStatus'), live: byId('loadStatusLive'), updated: byId('loadStatusUpdated'), season: byId('loadStatusSeason'),
    search: byId('activeLoadSearch'), count: byId('activeLoadCount'), list: byId('activeLoadList'), empty: byId('activeLoadEmpty'),
    content: byId('activeLoadContent'), breadcrumb: byId('activeLoadBreadcrumb'), code: byId('activeLoadCode'), status: byId('activeLoadState'),
    priority: byId('activeLoadPriority'), management: byId('openLoadManagement'), reference: byId('activeLoadReference'), schedule: byId('activeLoadSchedule'),
    camera: byId('activeLoadCamera'), cluster: byId('activeLoadCluster'), truck: byId('activeLoadTruck'), dock: byId('activeLoadDock'),
    incidents: byId('activeLoadIncidents'), concentrationValue: byId('concentrationValue'), concentrationBar: byId('concentrationBar'),
    concentrationComplete: byId('concentrationComplete'), concentrationPending: byId('concentrationPending'), separationValue: byId('separationValue'),
    separationBar: byId('separationBar'), separationComplete: byId('separationComplete'), separationPending: byId('separationPending'),
    dispatchState: byId('dispatchState'), dispatchMessage: byId('dispatchMessage'), locationSummary: byId('locationSummary'),
    locations: byId('loadLocationList'), folioSummary: byId('folioSummary'), folios: byId('activeLoadFolios'),
    loading: byId('officeLoading'), loadingText: byId('officeLoadingText'), toasts: byId('officeToasts'),
};

const state = {
    token: localStorage.getItem(keys.token),
    identity: readJson(keys.identity),
    loads: [], selectedId: null, etag: null, poller: null, loading: false,
};

const statusLabels = {
    pendiente: 'Pendiente', en_preparacion: 'En preparación', despacho_parcial: 'Despacho parcial',
    en_separacion: 'En separación', separada: 'Separada', separacion_completa: 'Separación completa',
};
const priorityLabels = { normal: 'Normal', alta: 'Alta', urgente: 'Urgente' };

class ApiError extends Error {
    constructor(message, status = 0) { super(message); this.status = status; }
}

function readJson(key) {
    try { return JSON.parse(localStorage.getItem(key) || 'null'); } catch { return null; }
}

function escapeHtml(value) {
    return String(value ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;').replaceAll("'", '&#039;');
}

function dateTime(value) {
    if (!value) return 'Sin programación';
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? 'Sin programación' : new Intl.DateTimeFormat('es-CL', { dateStyle: 'short', timeStyle: 'short' }).format(date);
}

function setBusy(active, message = 'Consultando operación…') {
    elements.loadingText.textContent = message;
    elements.loading.classList.toggle('is-hidden', !active);
    elements.loading.setAttribute('aria-hidden', String(!active));
}

function toast(message, error = false) {
    const item = document.createElement('div');
    item.className = `toast${error ? ' toast--error' : ''}`;
    item.textContent = message;
    elements.toasts.append(item);
    window.setTimeout(() => item.remove(), 5000);
}

function clearSession() {
    localStorage.removeItem(keys.token);
    localStorage.removeItem(keys.identity);
    state.poller?.stop();
    window.location.replace('/oficina/accesos');
}

function discardSession() {
    localStorage.removeItem(keys.token);
    localStorage.removeItem(keys.identity);
    state.token = null;
    state.identity = null;
}

function hasAccess() {
    const capabilities = { ...(state.identity?.capacidades || {}), ...(state.identity || {}) };
    const modules = capabilities.modulos_acceso;
    return (state.identity?.rol === 'administrador' || capabilities.puede_consultar_cargas === true)
        && (!Array.isArray(modules) || modules.includes('frigorifico.cargas'));
}

async function request(path, options = {}) {
    const headers = new Headers(options.headers || {});
    headers.set('Accept', 'application/json');
    if (state.token) headers.set('Authorization', `Bearer ${state.token}`);
    if (options.body) headers.set('Content-Type', 'application/json');
    let response;
    try { response = await fetch(path, { ...options, headers }); } catch { throw new ApiError('No fue posible conectar con Laravel.'); }
    if (response.status === 304) return { unchanged: true };
    const payload = await response.json().catch(() => ({}));
    if (!response.ok) {
        if (response.status === 401 && path !== '/api/acceso-oficina') clearSession();
        throw new ApiError(Object.values(payload.errors || {}).flat()[0] || payload.message || 'No fue posible consultar las cargas.', response.status);
    }
    return { payload, etag: response.headers.get('ETag') };
}

function selectedLoad() { return state.loads.find((load) => load.id === state.selectedId) || null; }

function filteredLoads() {
    const query = elements.search.value.trim().toLocaleLowerCase('es');
    if (!query) return state.loads;
    return state.loads.filter((load) => [load.codigo, load.numero_orden_externa, load.embarque?.codigo, ...(load.embarque?.numeros_externos || [])]
        .filter(Boolean).some((value) => String(value).toLocaleLowerCase('es').includes(query)));
}

function renderList() {
    const loads = filteredLoads();
    elements.count.textContent = String(state.loads.length);
    elements.season.textContent = `${state.loads.length} ${state.loads.length === 1 ? 'carga activa' : 'cargas activas'} · ordenadas por prioridad`;
    elements.list.innerHTML = loads.length ? loads.map((load) => {
        const progress = load.progreso || {};
        const selected = load.id === state.selectedId;
        const reference = load.embarque?.codigo || load.numero_orden_externa || 'Sin referencia externa';
        return `<button class="load-status-card${selected ? ' is-selected' : ''}" type="button" data-load-id="${escapeHtml(load.id)}">
            <span><strong>${escapeHtml(load.codigo)}</strong><i data-priority="${escapeHtml(load.prioridad)}">${escapeHtml(priorityLabels[load.prioridad] || load.prioridad)}</i></span>
            <small>${escapeHtml(reference)}</small><b>${Number(progress.porcentaje || 0)}% concentrada · ${Number(progress.en_anden || 0)} en andén</b>
        </button>`;
    }).join('') : '<div class="load-status-list__empty">No hay cargas que coincidan con la búsqueda.</div>';
}

function locationLabel(folio) {
    if (folio.anden) return folio.anden.nombre || folio.anden.codigo;
    if (folio.ubicacion) return `${folio.ubicacion.camara?.nombre || 'Cámara'} · ${folio.ubicacion.posicion?.etiqueta || 'posición sin etiqueta'}`;
    return 'Sin ubicación en cámara';
}

function renderLocations(load) {
    const rows = [...(load.distribucion || []).map((item) => ({
        key: item.camara.id, name: item.camara.nombre, detail: item.camara.codigo, count: item.cantidad, tone: 'camera',
    }))];
    const atDock = Number(load.progreso?.en_anden || 0);
    if (atDock) rows.push({ key: 'dock', name: load.camion_en_anden?.anden?.nombre || load.anden_previsto?.nombre || 'Andén', detail: 'Separados', count: atDock, tone: 'dock' });
    const located = rows.reduce((sum, row) => sum + Number(row.count || 0), 0);
    const withoutLocation = Math.max(0, Number(load.total_folios || 0) - located);
    if (withoutLocation) rows.push({ key: 'other', name: 'Sin cámara', detail: 'Prefrío, tránsito o pendiente', count: withoutLocation, tone: 'other' });
    elements.locationSummary.textContent = `${load.total_folios} folios`;
    elements.locations.innerHTML = rows.length ? rows.map((row) => `<article data-tone="${row.tone}"><span><strong>${escapeHtml(row.name)}</strong><small>${escapeHtml(row.detail)}</small></span><b>${row.count}</b></article>`).join('') : '<p>La carga todavía no contiene folios.</p>';
}

function renderDetail() {
    const load = selectedLoad();
    elements.empty.classList.toggle('is-hidden', Boolean(load));
    elements.content.classList.toggle('is-hidden', !load);
    if (!load) return;
    const progress = load.progreso || {};
    const total = Number(load.total_folios || 0);
    const atDock = Number(progress.en_anden || 0);
    const concentration = Math.max(0, Math.min(100, Number(progress.porcentaje || 0)));
    const separation = total ? Math.max(0, Math.min(100, Math.round((atDock / total) * 100))) : 0;
    const main = progress.grupo_principal;
    const shipmentNumbers = load.embarque?.numeros_externos || [];
    const scheduled = load.embarque?.fecha_programada
        ? `${load.embarque.fecha_programada} ${load.embarque.hora_programada || ''}`.trim()
        : null;

    elements.code.textContent = load.codigo;
    elements.status.textContent = statusLabels[load.estado] || load.estado;
    elements.status.dataset.tone = load.camion_en_anden ? 'success' : 'info';
    elements.priority.textContent = priorityLabels[load.prioridad] || load.prioridad;
    elements.priority.dataset.priority = load.prioridad;
    elements.breadcrumb.textContent = `Cargas · ${load.embarque?.codigo || 'Orden independiente'}`;
    elements.management.href = `/oficina/frigorifico/despacho/cargas?carga=${encodeURIComponent(load.id)}`;
    elements.reference.textContent = shipmentNumbers.join(' / ') || load.numero_orden_externa || load.embarque?.codigo || 'Sin referencia';
    elements.schedule.textContent = scheduled ? `Salida ${scheduled}` : 'Sin programación de embarque';
    elements.camera.textContent = load.camara_objetivo?.nombre || 'Sin cámara objetivo';
    elements.cluster.textContent = main ? `${main.camara?.nombre || main.camara?.codigo || 'Cámara'} · bandas ${main.banda_desde}–${main.banda_hasta}` : 'Sin grupo principal';
    elements.truck.textContent = load.camion_en_anden?.patente || 'Sin camión';
    elements.dock.textContent = load.camion_en_anden ? `${load.camion_en_anden.anden?.nombre || 'Andén'} · desde ${dateTime(load.camion_en_anden.ingresada_at)}` : (load.anden_previsto?.nombre ? `Previsto: ${load.anden_previsto.nombre}` : 'Sin presencia registrada');
    elements.incidents.textContent = `${load.incidencias_abiertas || 0} abiertas`;
    elements.concentrationValue.textContent = `${concentration}%`;
    elements.concentrationBar.style.width = `${concentration}%`;
    elements.concentrationComplete.textContent = `${progress.concentrados || 0} concentrados`;
    elements.concentrationPending.textContent = `${progress.faltantes || 0} pendientes`;
    elements.separationValue.textContent = `${separation}%`;
    elements.separationBar.style.width = `${separation}%`;
    elements.separationComplete.textContent = `${atDock} en andén`;
    elements.separationPending.textContent = `${Math.max(0, total - atDock)} pendientes`;
    elements.dispatchState.textContent = load.camion_en_anden ? 'Camión presente' : 'En espera';
    elements.dispatchState.dataset.tone = load.camion_en_anden ? 'success' : 'neutral';
    elements.dispatchMessage.textContent = load.camion_en_anden
        ? `${load.camion_en_anden.patente} está en ${load.camion_en_anden.anden?.nombre || 'andén'}; el WMS prioriza el retiro directo.`
        : 'Registra la presencia del camión desde Cargas para activar la prioridad a andén.';
    const dependencies = loadReceptionDependencies(progress, Boolean(load.camion_en_anden));
    if (dependencies) elements.dispatchMessage.textContent += ` ${dependencies}`;
    renderLocations(load);
    elements.folioSummary.textContent = `${total} folios`;
    elements.folios.innerHTML = total ? load.folios.map((folio) => `<tr><td><strong>${escapeHtml(folio.numero_folio)}</strong><small>${escapeHtml(folio.tipo_bulto || 'bulto')}</small></td><td>${escapeHtml(locationLabel(folio))}</td><td><span data-state="${escapeHtml(folio.estado_carga)}">${escapeHtml(String(folio.estado_carga || '').replaceAll('_', ' '))}</span></td></tr>`).join('') : '<tr><td colspan="3">Sin folios asignados.</td></tr>';
}

function render() { renderList(); renderDetail(); }

async function loadStatus({ blocking = false } = {}) {
    if (state.loading) return;
    state.loading = true;
    if (blocking) setBusy(true);
    try {
        const headers = state.etag ? { 'If-None-Match': state.etag } : {};
        const result = await request('/api/cargas/pendientes', { headers, cache: 'no-store' });
        if (!result.unchanged) {
            state.loads = Array.isArray(result.payload?.data) ? result.payload.data : [];
            state.etag = result.etag || null;
            if (!state.loads.some((load) => load.id === state.selectedId)) state.selectedId = state.loads[0]?.id || null;
            render();
        }
        elements.live.dataset.tone = 'success';
        elements.live.querySelector('strong').textContent = 'Operación sincronizada';
        elements.updated.textContent = `Actualizado ${new Intl.DateTimeFormat('es-CL', { timeStyle: 'medium' }).format(new Date())}`;
    } catch (error) {
        elements.live.dataset.tone = 'critical';
        elements.live.querySelector('strong').textContent = 'Sin conexión operacional';
        elements.updated.textContent = error.message;
        if (blocking) toast(error.message, true);
        throw error;
    } finally { state.loading = false; setBusy(false); }
}

function showApp() {
    elements.access.classList.add('is-hidden');
    elements.app.classList.remove('is-hidden');
    const name = state.identity?.nombre || state.identity?.name || 'Usuario';
    byId('officeUserName').textContent = name;
    byId('officeUserRole').textContent = String(state.identity?.rol || 'consulta').replaceAll('_', ' ');
    byId('officeInitials').textContent = name.split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0]).join('').toUpperCase();
}

elements.login.addEventListener('submit', async (event) => {
    event.preventDefault(); elements.loginError.textContent = ''; setBusy(true, 'Validando acceso…');
    try {
        const form = new FormData(elements.login);
        const result = await request('/api/acceso-oficina', { method: 'POST', body: JSON.stringify({ email: form.get('email'), password: form.get('password') }) });
        state.token = result.payload.token; state.identity = result.payload.usuario;
        localStorage.setItem(keys.token, state.token); localStorage.setItem(keys.identity, JSON.stringify(state.identity));
        if (!hasAccess()) {
            discardSession();
            throw new ApiError('Tu perfil no puede consultar el estatus operativo.', 403);
        }
        showApp(); setBusy(false); await loadStatus({ blocking: true }); startPolling();
    } catch (error) { elements.loginError.textContent = error.message; } finally { setBusy(false); }
});

elements.list.addEventListener('click', (event) => {
    const button = event.target.closest('[data-load-id]');
    if (!button) return;
    state.selectedId = button.dataset.loadId; render();
});
elements.search.addEventListener('input', renderList);
elements.reload.addEventListener('click', () => void (state.poller?.runNow() || loadStatus({ blocking: true })));
byId('officeLogoutButton')?.addEventListener('click', async () => {
    try { await request('/api/acceso-oficina', { method: 'DELETE' }); } catch { /* El cierre local no depende de la red. */ }
    clearSession();
});

function startPolling() {
    state.poller?.stop();
    state.poller = createOperationalPoller(() => loadStatus(), { intervalMs: 15_000, canRun: () => Boolean(state.token) && !state.loading });
    state.poller.start();
}

async function boot() {
    if (!state.token || !state.identity) return;
    if (!hasAccess()) { clearSession(); return; }
    showApp();
    try { await loadStatus({ blocking: true }); startPolling(); } catch { /* La vista conserva el error y permite reintentar. */ }
}

void boot();
