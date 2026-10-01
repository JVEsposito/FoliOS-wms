import { showOfficeToast } from './office-toast.js';

const byId = (id) => document.getElementById(id);

const elements = {
    access: byId('officeAccess'),
    app: byId('officeApp'),
    loginForm: byId('officeLoginForm'),
    loginError: byId('officeLoginError'),
    userName: byId('officeUserName'),
    userRole: byId('officeUserRole'),
    initials: byId('officeInitials'),
    logout: byId('officeLogoutButton'),
    camerasNav: byId('officeCamerasNav'),
    materialsNav: byId('officeMaterialsNav'),
    prefrioNav: byId('officePrefrioNav'),
    accessesNav: byId('officeAccessesNav'),
    managementNav: byId('officeManagementNav'),
    romanaNav: byId('officeRomanaNav'),
    reload: byId('reloadLoadsButton'),
    newLoad: byId('newLoadButton'),
    directDispatch: byId('directDispatchButton'),
    emptyNewLoad: byId('emptyNewLoadButton'),
    search: byId('loadSearch'),
    statusFilter: byId('loadStatusFilter'),
    incidentFilter: byId('loadIncidentFilter'),
    catalogSummary: byId('loadCatalogSummary'),
    loadPageSize: byId('loadPageSize'),
    loadPagination: byId('loadPagination'),
    list: byId('loadList'),
    empty: byId('loadEmptyState'),
    editor: byId('loadEditorContent'),
    editorEyebrow: byId('loadEditorEyebrow'),
    editorTitle: byId('loadEditorTitle'),
    editorDescription: byId('loadEditorDescription'),
    statusBadge: byId('loadStatusBadge'),
    priorityBadge: byId('loadPriorityBadge'),
    audit: byId('loadAudit'),
    version: byId('loadVersion'),
    updatedAt: byId('loadUpdatedAt'),
    headerForm: byId('loadHeaderForm'),
    externalOrderLabel: byId('externalOrderLabel'),
    externalOrderHelp: byId('externalOrderHelp'),
    headerError: byId('loadHeaderError'),
    targetCamera: byId('targetCameraSelect'),
    targetDock: byId('targetDockSelect'),
    discardNew: byId('discardNewLoadButton'),
    save: byId('saveLoadButton'),
    saveText: byId('saveLoadButtonText'),
    operation: byId('loadOperation'),
    totalFolios: byId('loadTotalFolios'),
    totalCameras: byId('loadTotalCameras'),
    concentration: byId('loadConcentration'),
    atDock: byId('loadAtDock'),
    incidentCount: byId('loadIncidentCount'),
    truckDockPanel: byId('truckDockPanel'),
    truckDockTitle: byId('truckDockTitle'),
    truckDockDetail: byId('truckDockDetail'),
    concentrationMessage: byId('loadConcentrationMessage'),
    concentrationBar: byId('loadConcentrationBar'),
    concentratedFolios: byId('loadConcentratedFolios'),
    mainCluster: byId('loadMainCluster'),
    distribution: byId('loadDistribution'),
    folioAddSection: byId('folioAddSection'),
    folioInput: byId('folioInput'),
    folioInputCount: byId('folioInputCount'),
    addFolios: byId('addFoliosButton'),
    folioErrors: byId('folioErrors'),
    availableFolioSearch: byId('availableFolioSearch'),
    availableFolioSummary: byId('availableFolioSummary'),
    availableFolioList: byId('availableFolioList'),
    availableFolioTableBody: byId('availableFolioTableBody'),
    availableFolioSelectPage: byId('availableFolioSelectPage'),
    availableFolioPageSize: byId('availableFolioPageSize'),
    availableFolioPagination: byId('availableFolioPagination'),
    reloadAvailableFolios: byId('reloadAvailableFoliosButton'),
    folioTableBody: byId('folioTableBody'),
    folioTableHint: byId('folioTableHint'),
    incidentsSection: byId('loadIncidentsSection'),
    incidentsSummary: byId('loadIncidentsSummary'),
    incidentsList: byId('loadIncidentsList'),
    commandBar: byId('loadCommandBar'),
    commandTitle: byId('loadCommandTitle'),
    commandDescription: byId('loadCommandDescription'),
    cancel: byId('cancelLoadButton'),
    truckDock: byId('truckDockButton'),
    closeDispatch: byId('closeDispatchButton'),
    publish: byId('publishLoadButton'),
    incidentDialog: byId('incidentResolutionDialog'),
    incidentForm: byId('incidentResolutionForm'),
    incidentContext: byId('incidentResolutionContext'),
    incidentError: byId('incidentResolutionError'),
    replacementPicker: byId('replacementPicker'),
    replacementSearch: byId('replacementSearch'),
    replacementSummary: byId('replacementSummary'),
    replacementList: byId('replacementList'),
    closeDialog: byId('closeDispatchDialog'),
    closeForm: byId('closeDispatchForm'),
    closeContext: byId('closeDispatchContext'),
    closeError: byId('closeDispatchError'),
    truckDockDialog: byId('truckDockDialog'),
    truckDockForm: byId('truckDockForm'),
    truckDockSelect: byId('truckDockSelect'),
    truckDockError: byId('truckDockError'),
    releaseDockDialog: byId('releaseDockDialog'),
    releaseDockForm: byId('releaseDockForm'),
    releaseDockContext: byId('releaseDockContext'),
    releaseDockError: byId('releaseDockError'),
    directDialog: byId('directDispatchDialog'),
    directForm: byId('directDispatchForm'),
    directDock: byId('directDockSelect'),
    directSearch: byId('directFolioSearch'),
    directFoliosInput: byId('directFoliosInput'),
    directSelectPage: byId('directSelectPage'),
    directCandidateSummary: byId('directCandidateSummary'),
    directSelectionSummary: byId('directSelectionSummary'),
    directFolioList: byId('directFolioList'),
    directFolioPagination: byId('directFolioPagination'),
    directError: byId('directDispatchError'),
    loading: byId('officeLoading'),
    loadingText: byId('officeLoadingText'),
    toasts: byId('officeToasts'),
};

const keys = {
    token: 'estiba_wms_office_token',
    identity: 'estiba_wms_office_identity',
};

const statusLabels = {
    borrador: 'Borrador',
    pendiente: 'Pendiente',
    en_preparacion: 'En preparación',
    despacho_parcial: 'Despacho parcial',
    en_separacion: 'En separación',
    separada: 'Separada',
    separacion_completa: 'Separación completa',
    despachada: 'En andén',
    cerrada: 'Cerrada',
    cancelada: 'Cancelada',
};

const incidentTypeLabels = {
    caja_aplastada: 'Caja aplastada',
    zuncho_roto: 'Zuncho roto',
    pallet_mojado: 'Pallet mojado',
    pallet_inestable: 'Pallet inestable',
    folio_ilegible: 'Folio ilegible',
    diferencia_ubicacion: 'Diferencia de ubicación',
    folio_no_encontrado: 'Folio no encontrado',
    retencion_calidad: 'Retención de calidad',
    sector_inaccesible: 'Sector inaccesible',
    otro: 'Otro',
};

const priorityLabels = {
    normal: 'Normal',
    alta: 'Alta',
    urgente: 'Urgente',
};

const state = {
    token: localStorage.getItem(keys.token),
    identity: readJson(keys.identity),
    loads: [],
    cameras: [],
    docks: [],
    availableFolios: [],
    directFolios: [],
    incidents: [],
    replacements: [],
    loadPagination: emptyPagination(25),
    availablePagination: emptyPagination(10),
    directPagination: emptyPagination(25),
    loadRequestId: 0,
    availableRequestId: 0,
    directRequestId: 0,
    selected: null,
    selectedIncident: null,
    selectedReplacement: null,
    mode: 'empty',
};

let loadSearchTimer;
let availableSearchTimer;
let replacementSearchTimer;
let directSearchTimer;

class ApiError extends Error {
    constructor(message, status, data = {}) {
        super(message);
        this.name = 'ApiError';
        this.status = status;
        this.data = data;
    }
}

function readJson(key) {
    try {
        return JSON.parse(localStorage.getItem(key) || 'null');
    } catch {
        return null;
    }
}

function emptyPagination(perPage) {
    return {
        currentPage: 1,
        lastPage: 1,
        perPage,
        from: 0,
        to: 0,
        total: 0,
    };
}

function paginationFrom(response, fallbackPerPage) {
    const meta = response?.meta || {};
    return {
        currentPage: Number(meta.current_page || 1),
        lastPage: Math.max(1, Number(meta.last_page || 1)),
        perPage: Number(meta.per_page || fallbackPerPage),
        from: Number(meta.from || 0),
        to: Number(meta.to || 0),
        total: Number(meta.total || 0),
    };
}

function escapeHtml(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function firstValidationError(errors) {
    if (!errors || Array.isArray(errors)) return null;
    return Object.values(errors).flat().find(Boolean) || null;
}

function errorMessage(data, fallback) {
    return firstValidationError(data?.errors) || data?.message || fallback;
}

function operationId() {
    if (globalThis.crypto?.randomUUID) return globalThis.crypto.randomUUID();
    const bytes = new Uint8Array(16);
    if (globalThis.crypto?.getRandomValues) globalThis.crypto.getRandomValues(bytes);
    else bytes.forEach((_, index) => { bytes[index] = Math.floor(Math.random() * 256); });
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = [...bytes].map((byte) => byte.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

async function api(path, options = {}) {
    const headers = new Headers(options.headers || {});
    headers.set('Accept', 'application/json');
    if (state.token) headers.set('Authorization', `Bearer ${state.token}`);
    if (options.body) headers.set('Content-Type', 'application/json');

    let response;
    try {
        response = await fetch(path, { ...options, headers });
    } catch {
        throw new ApiError('No fue posible conectar con Laravel.', 0);
    }

    const data = response.status === 204 ? null : await response.json().catch(() => ({}));
    if (!response.ok) {
        if (response.status === 401 && path !== '/api/acceso-oficina') clearSession();
        throw new ApiError(
            errorMessage(data, 'No fue posible completar la operación.'),
            response.status,
            data,
        );
    }

    return data;
}

function setBusy(active, message = 'Procesando…') {
    elements.loadingText.textContent = message;
    elements.loading.classList.toggle('is-hidden', !active);
    elements.loading.setAttribute('aria-hidden', String(!active));
    elements.app.setAttribute('aria-busy', String(active));
}

function toast(message, error = false) { showOfficeToast(elements.toasts, message, error); }

function persistSession(payload) {
    state.token = payload.token;
    state.identity = payload.usuario;
    localStorage.setItem(keys.token, payload.token);
    localStorage.setItem(keys.identity, JSON.stringify(payload.usuario));
}

function clearSession() {
    state.token = null;
    state.identity = null;
    state.loads = [];
    state.cameras = [];
    state.docks = [];
    state.availableFolios = [];
    state.incidents = [];
    state.replacements = [];
    state.loadPagination = emptyPagination(Number(elements.loadPageSize.value));
    state.availablePagination = emptyPagination(Number(elements.availableFolioPageSize.value));
    state.selected = null;
    state.selectedIncident = null;
    state.selectedReplacement = null;
    state.mode = 'empty';
    localStorage.removeItem(keys.token);
    localStorage.removeItem(keys.identity);
    elements.app.classList.add('is-hidden');
    elements.access.classList.remove('is-hidden');
}

function showApp() {
    elements.access.classList.add('is-hidden');
    elements.app.classList.remove('is-hidden');
    const name = state.identity?.nombre || 'Despachador';
    elements.userName.textContent = name;
    elements.userRole.textContent = statusText(state.identity?.rol || 'oficina');
    elements.initials.textContent = name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0])
        .join('')
        .toUpperCase();
    elements.camerasNav.classList.remove('is-hidden');
    elements.accessesNav.classList.toggle(
        'is-hidden',
        state.identity?.puede_administrar_accesos !== true,
    );
    elements.managementNav.classList.toggle(
        'is-hidden',
        state.identity?.puede_consultar_panel_gerencial !== true,
    );
    elements.romanaNav.classList.toggle(
        'is-hidden',
        state.identity?.puede_consultar_romana !== true,
    );
    elements.materialsNav.classList.toggle(
        'is-hidden',
        state.identity?.puede_consultar_despachos_materiales !== true,
    );
    elements.prefrioNav.classList.toggle(
        'is-hidden',
        state.identity?.puede_consultar_prefrio !== true,
    );
    const canManage = state.identity?.puede_gestionar_cargas === true;
    elements.newLoad.classList.toggle('is-hidden', !canManage);
    elements.emptyNewLoad.classList.toggle('is-hidden', !canManage);
    elements.directDispatch.classList.toggle(
        'is-hidden',
        !canManage || state.identity?.puede_cerrar_despacho_frigorifico !== true,
    );
}

function statusText(value) {
    return String(value || '')
        .replaceAll('_', ' ')
        .replace(/^./, (character) => character.toUpperCase());
}

function formatDate(value, fallback = '—') {
    if (!value) return fallback;
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return fallback;
    return new Intl.DateTimeFormat('es-CL', {
        dateStyle: 'short',
        timeStyle: 'short',
    }).format(date);
}

function canEdit(load) {
    return state.identity?.puede_gestionar_cargas === true
        && ['borrador', 'pendiente'].includes(load?.estado);
}

function canPublish(load) {
    return state.identity?.puede_gestionar_cargas === true
        && load?.estado === 'borrador'
        && load.total_folios >= 1;
}

function canCancel(load) {
    return state.identity?.puede_gestionar_cargas === true
        && ['borrador', 'pendiente'].includes(load?.estado);
}

function canResolveIncidents() {
    return state.identity?.puede_resolver_comercialmente_carga === true
        || state.identity?.puede_resolver_reparacion_carga === true;
}

function canCloseDispatch(load) {
    return state.identity?.puede_cerrar_despacho_frigorifico === true
        && load?.estado === 'despachada';
}

function canManageTruckPresence(load) {
    if (state.identity?.puede_gestionar_cargas !== true || !load) return false;
    if (load.camion_en_anden) return load.estado !== 'cerrada';

    return ['pendiente', 'en_preparacion', 'despacho_parcial', 'en_separacion', 'separada', 'separacion_completa']
        .includes(load.estado);
}

function parseFolios(value = elements.folioInput.value) {
    const seen = new Set();

    return String(value)
        .split(/[\s,;]+/)
        .map((folio) => folio.trim())
        .filter((folio) => {
            if (!folio || seen.has(folio)) return false;
            seen.add(folio);
            return true;
        });
}

function parseDirectFolios(value = elements.directFoliosInput.value) {
    const seen = new Set();

    return String(value)
        .split(/[\s,;]+/)
        .map((folio) => folio.trim())
        .filter((folio) => {
            if (!folio || seen.has(folio)) return false;
            seen.add(folio);
            return true;
        });
}

function localDateTimeValue(date = new Date()) {
    const local = new Date(date.getTime() - (date.getTimezoneOffset() * 60_000));
    return local.toISOString().slice(0, 16);
}

function localDateTimeToIso(value) {
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? null : date.toISOString();
}

function syncDirectFolioInput(folios) {
    elements.directFoliosInput.value = [...new Set(folios)].slice(0, 26).join('\n');
    renderDirectFolios();
}

function remainingFolioSlots(load = state.selected) {
    return Math.max(0, 26 - Number(load?.total_folios || 0));
}

function statusClass(status) {
    return String(status || 'draft').replaceAll('_', '-');
}

function priorityClass(priority) {
    return String(priority || 'normal').replaceAll('_', '-');
}

function distributionText(load) {
    if (load.modalidad_salida === 'directa_prefrio') return 'Salida directa desde Prefrío';
    if (!load.distribucion?.length) return 'Sin distribución disponible';
    return load.distribucion
        .map((item) => `${item.camara.codigo}: ${item.cantidad}`)
        .join(' · ');
}

function externalNumbers(load) {
    return [...new Set((load.embarque?.numeros_externos || []).filter(Boolean))];
}

function loadReferenceText(load) {
    const external = externalNumbers(load);
    if (load.embarque) {
        return `${load.embarque.codigo} · ${external.length ? `Ext. ${external.join(' / ')}` : 'Sin N.º externo'}`;
    }
    return load.numero_orden_externa ? `Ext. ${load.numero_orden_externa}` : 'Sin N.º externo';
}

function renderPagination(element, pagination, dataAttribute) {
    if (pagination.total === 0) {
        element.innerHTML = '';
        return;
    }

    element.innerHTML = `
        <button data-${dataAttribute}="${pagination.currentPage - 1}" type="button"${pagination.currentPage <= 1 ? ' disabled' : ''}>Anterior</button>
        <span>Página <strong>${pagination.currentPage}</strong> de ${pagination.lastPage}</span>
        <button data-${dataAttribute}="${pagination.currentPage + 1}" type="button"${pagination.currentPage >= pagination.lastPage ? ' disabled' : ''}>Siguiente</button>
    `;
}

function renderCatalog() {
    const loads = state.loads;
    const pagination = state.loadPagination;
    elements.catalogSummary.textContent = pagination.total
        ? `${pagination.from}–${pagination.to} de ${pagination.total} órdenes`
        : '0 órdenes';
    renderPagination(elements.loadPagination, pagination, 'load-page');

    if (!loads.length) {
        elements.list.innerHTML = `
            <div class="empty-state">
                No hay órdenes que coincidan con los filtros actuales.
            </div>
        `;
        return;
    }

    elements.list.innerHTML = loads.map((load) => {
        const selected = state.selected?.id === load.id;
        const truck = load.camion_en_anden;
        return `
            <button class="load-card${selected ? ' is-selected' : ''}" data-load-id="${escapeHtml(load.id)}" type="button">
                <div class="load-card__line load-card__line--main">
                    <strong class="load-card__code">${escapeHtml(load.codigo)}</strong>
                    <span class="load-card__external">${escapeHtml(loadReferenceText(load))}</span>
                    <span class="status-badge status-badge--${statusClass(load.estado)}">${escapeHtml(statusLabels[load.estado] || statusText(load.estado))}</span>
                </div>
                <div class="load-card__line load-card__line--detail">
                    <span class="load-card__distribution">${escapeHtml(distributionText(load))}</span>
                    <span class="priority-dot priority-dot--${priorityClass(load.prioridad)}">${escapeHtml(priorityLabels[load.prioridad] || statusText(load.prioridad))}</span>
                    <span class="load-card__count">${truck ? `🚚 ${escapeHtml(truck.anden?.nombre || 'En andén')} · ` : ''}${load.incidencias_abiertas ? `⚠ ${load.incidencias_abiertas} · ` : ''}${load.total_folios} / 26</span>
                </div>
            </button>
        `;
    }).join('');
}

function populateCameraOptions(selectedId = '') {
    elements.targetCamera.innerHTML = [
        '<option value="">Sin cámara objetivo</option>',
        ...state.cameras.map((camera) => {
            const total = Number(camera.ocupacion?.total || 0);
            const occupied = Number(camera.ocupacion?.ocupadas || 0);
            const available = Math.max(0, total - occupied);

            return `
                <option value="${escapeHtml(camera.id)}"${camera.id === selectedId ? ' selected' : ''}>
                    ${escapeHtml(camera.codigo)} · ${escapeHtml(camera.nombre)} · ${available} libres de ${total}
                </option>
            `;
        }),
    ].join('');
}

function populateDockOptions(selectedId = '') {
    elements.targetDock.innerHTML = [
        '<option value="">Sin andén previsto</option>',
        ...state.docks.map((dock) => `
            <option value="${escapeHtml(dock.id)}"${dock.id === selectedId ? ' selected' : ''}>
                ${escapeHtml(dock.codigo)} · ${escapeHtml(dock.nombre)}
            </option>
        `),
    ].join('');
}

function populateDirectDockOptions(selectedId = '') {
    elements.directDock.innerHTML = [
        '<option value="">Selecciona un andén</option>',
        ...state.docks.map((dock) => `
            <option value="${escapeHtml(dock.id)}"${dock.id === selectedId ? ' selected' : ''}>
                ${escapeHtml(dock.codigo)} · ${escapeHtml(dock.nombre)}
            </option>
        `),
    ].join('');
}

function populateTruckDockOptions(selectedId = '') {
    elements.truckDockSelect.innerHTML = [
        '<option value="">Selecciona un andén disponible</option>',
        ...state.docks.map((dock) => {
            const occupied = dock.ocupacion && dock.ocupacion.carga?.id !== state.selected?.id;
            const occupation = occupied
                ? ` · ocupado por ${dock.ocupacion.carga?.codigo || dock.ocupacion.patente}`
                : '';

            return `
                <option value="${escapeHtml(dock.id)}"${dock.id === selectedId ? ' selected' : ''}${occupied ? ' disabled' : ''}>
                    ${escapeHtml(dock.codigo)} · ${escapeHtml(dock.nombre)}${escapeHtml(occupation)}
                </option>
            `;
        }),
    ].join('');
}

function setHeaderDisabled(disabled) {
    for (const field of elements.headerForm.elements) {
        if (field.type !== 'submit' && field.type !== 'button') field.disabled = disabled;
    }
    elements.save.disabled = disabled;
}

function clearFolioErrors() {
    elements.folioErrors.innerHTML = '';
    elements.folioErrors.classList.add('is-hidden');
}

function showFolioErrors(error) {
    const detailed = Array.isArray(error?.data?.errores) ? error.data.errores : [];
    if (!detailed.length) {
        elements.folioErrors.innerHTML = `<strong>No se incorporó ningún folio.</strong><span>${escapeHtml(error.message)}</span>`;
    } else {
        elements.folioErrors.innerHTML = `
            <strong>No se incorporó ningún folio. Corrige el lote y vuelve a intentarlo.</strong>
            <ul>
                ${detailed.map((item) => `
                    <li><b>${escapeHtml(item.folio || 'Folio')}</b><span>${escapeHtml(item.mensaje || item.codigo || 'No asignable')}</span></li>
                `).join('')}
            </ul>
        `;
    }
    elements.folioErrors.classList.remove('is-hidden');
}

function renderAvailableFolios() {
    const selected = new Set(parseFolios());
    const folios = state.availableFolios;
    const pagination = state.availablePagination;

    elements.availableFolioSummary.textContent = pagination.total
        ? `Mostrando ${pagination.from}–${pagination.to} de ${pagination.total} folios disponibles · ${selected.size} seleccionados`
        : '0 folios disponibles';
    renderPagination(elements.availableFolioPagination, pagination, 'available-page');
    elements.availableFolioSelectPage.disabled = folios.length === 0;
    elements.availableFolioSelectPage.checked = folios.length > 0
        && folios.every((folio) => selected.has(folio.numero_folio));
    elements.availableFolioSelectPage.indeterminate = !elements.availableFolioSelectPage.checked
        && folios.some((folio) => selected.has(folio.numero_folio));

    if (!folios.length) {
        elements.availableFolioTableBody.innerHTML = `
            <tr class="available-folios__empty">
                <td colspan="9">${elements.availableFolioSearch.value.trim() ? 'No hay folios que coincidan con la búsqueda.' : 'No existen pallets completos disponibles sin una carga asignada.'}</td>
            </tr>
        `;
        return;
    }

    elements.availableFolioTableBody.innerHTML = folios.map((folio) => {
        const isSelected = selected.has(folio.numero_folio);
        const sag = folio.condicion_sag
            ? `${folio.condicion_sag.codigo} · ${folio.condicion_sag.nombre}`
            : '—';
        return `
            <tr class="${isSelected ? 'is-selected' : ''}">
                <td class="available-folios__check"><input data-available-folio="${escapeHtml(folio.numero_folio)}" type="checkbox" aria-label="Seleccionar ${escapeHtml(folio.numero_folio)}"${isSelected ? ' checked' : ''}></td>
                <td><strong>${escapeHtml(folio.numero_folio)}</strong></td>
                <td><span class="folio-type">${escapeHtml(statusText(folio.tipo_bulto))}</span></td>
                <td><strong>${escapeHtml(folio.variedad || '—')}</strong><small>${escapeHtml(folio.calibre || 'Sin calibre')}</small></td>
                <td><strong>${escapeHtml(folio.marca || '—')}</strong><small>${escapeHtml(folio.exportadora || 'Sin exportadora')}</small></td>
                <td>${escapeHtml(sag)}</td>
                <td><strong class="location-label">${escapeHtml(folio.ubicacion?.camara?.codigo || 'Prefrío')}</strong><small>${escapeHtml(folio.ubicacion?.camara?.nombre || 'Sin cámara')}</small></td>
                <td><span class="location-label">${escapeHtml(folio.ubicacion?.posicion?.etiqueta || 'En túnel')}</span></td>
                <td>${escapeHtml(formatDate(folio.fecha_ingreso))}</td>
            </tr>
        `;
    }).join('');
}

function showEmpty() {
    state.mode = 'empty';
    state.selected = null;
    elements.empty.classList.remove('is-hidden');
    elements.editor.classList.add('is-hidden');
    renderCatalog();
}

function startNew() {
    state.mode = 'new';
    state.selected = null;
    elements.empty.classList.add('is-hidden');
    elements.editor.classList.remove('is-hidden');
    elements.operation.classList.add('is-hidden');
    elements.audit.classList.add('is-hidden');
    elements.discardNew.classList.remove('is-hidden');
    elements.headerForm.reset();
    populateCameraOptions();
    populateDockOptions();
    elements.headerError.textContent = '';
    elements.editorEyebrow.textContent = 'NUEVA ORDEN';
    elements.editorTitle.textContent = 'Nuevo borrador';
    elements.editorDescription.textContent = 'Crea el encabezado y luego incorpora los folios.';
    elements.externalOrderLabel.textContent = 'Número de orden externa';
    elements.externalOrderHelp.textContent = 'Referencia informada por el cliente.';
    elements.statusBadge.className = 'status-badge status-badge--draft';
    elements.statusBadge.textContent = 'Sin guardar';
    elements.priorityBadge.classList.add('is-hidden');
    elements.saveText.textContent = 'Crear borrador';
    setHeaderDisabled(false);
    clearFolioErrors();
    renderCatalog();
    elements.headerForm.elements.numero_orden_externa.focus();
}

function renderDistribution(load) {
    if (load.modalidad_salida === 'directa_prefrio') {
        elements.distribution.innerHTML = `
            <span class="distribution-chip">
                <b>PREFRÍO</b>
                <span>Salida directa a ${escapeHtml(load.anden_previsto?.codigo || 'andén')}</span>
                <strong>${Number(load.total_folios || 0)}</strong>
            </span>
        `;
        return;
    }

    if (!load.distribucion?.length) {
        elements.distribution.innerHTML = '<span class="distribution-empty">Sin folios asignados</span>';
        return;
    }

    elements.distribution.innerHTML = load.distribucion.map((item) => `
        <span class="distribution-chip">
            <b>${escapeHtml(item.camara.codigo)}</b>
            <span>${escapeHtml(item.camara.nombre)}</span>
            <strong>${item.cantidad}</strong>
        </span>
    `).join('');
}

function renderConcentration(load) {
    const progress = load.progreso || {};
    const percentage = Math.max(0, Math.min(100, Number(progress.porcentaje || 0)));
    const total = Number(progress.total || load.total_folios || 0);
    const concentrated = Number(progress.concentrados || 0);
    const missing = Number(progress.faltantes || 0);
    const atDock = Number(progress.en_anden || 0);

    elements.concentration.textContent = `${percentage}%`;
    elements.atDock.textContent = `${atDock} / ${total}`;
    elements.incidentCount.textContent = String(load.incidencias_abiertas || 0);
    elements.concentrationBar.style.width = `${percentage}%`;
    elements.concentrationBar.classList.toggle('is-ready', progress.cumple_umbral === true);
    elements.concentratedFolios.textContent = `${concentrated} de ${total} concentrados`;

    if (total === 0) {
        elements.concentrationMessage.textContent = 'La carga todavía no posee folios vigentes.';
    } else if (atDock === total) {
        elements.concentrationMessage.textContent = 'Todos los folios se encuentran en andén. La salida documental ya puede confirmarse.';
    } else if (progress.cumple_umbral) {
        elements.concentrationMessage.textContent = missing
            ? `Cumple el umbral del 80%; todavía faltan ${missing} ${missing === 1 ? 'pallet' : 'pallets'} por incorporar al grupo principal.`
            : 'La carga está completamente concentrada.';
    } else {
        elements.concentrationMessage.textContent = `Faltan ${missing} ${missing === 1 ? 'pallet' : 'pallets'} para alcanzar una concentración operacional suficiente.`;
    }

    const cluster = progress.grupo_principal;
    elements.mainCluster.textContent = cluster
        ? `${cluster.camara.codigo} · nivel ${cluster.nivel} · bandas ${cluster.banda_desde}–${cluster.banda_hasta}`
        : (atDock ? `${atDock} ${atDock === 1 ? 'folio enviado' : 'folios enviados'} a andén` : 'Sin grupo físico principal');
}

function renderIncidents() {
    const incidents = state.incidents;
    const open = incidents.filter((incident) => incident.estado === 'abierta').length;
    elements.incidentsSummary.textContent = incidents.length
        ? `${open} abiertas · ${incidents.length} registradas`
        : 'Sin incidencias registradas';

    if (!incidents.length) {
        elements.incidentsList.innerHTML = '<div class="incident-empty">No se han reportado incidencias físicas para esta carga.</div>';
        return;
    }

    elements.incidentsList.innerHTML = incidents.map((incident) => {
        const openIncident = incident.estado === 'abierta';
        const location = incident.ubicacion_reportada;
        const locationText = location?.camara?.codigo && location?.posicion?.etiqueta
            ? `${location.camara.codigo} · ${location.posicion.etiqueta}`
            : 'Ubicación no disponible';
        return `
            <article class="incident-card${openIncident ? ' is-open' : ''}">
                <div class="incident-card__status"><span>${openIncident ? 'ABIERTA' : 'RESUELTA'}</span><time>${escapeHtml(formatDate(incident.reportada_at))}</time></div>
                <div class="incident-card__body">
                    <div><strong>${escapeHtml(incident.folio?.numero_folio || 'Folio')}</strong><span>${escapeHtml(incidentTypeLabels[incident.tipo] || statusText(incident.tipo))}</span></div>
                    <p>${escapeHtml(incident.descripcion || 'Sin descripción adicional.')}</p>
                    <small>${escapeHtml(locationText)} · Reportó ${escapeHtml(incident.reportado_por?.nombre || 'operación')}</small>
                    ${incident.tipo_resolucion ? `<small class="incident-resolution">${escapeHtml(statusText(incident.tipo_resolucion))}${incident.resuelta_por?.nombre ? ` · ${escapeHtml(incident.resuelta_por.nombre)}` : ''}</small>` : ''}
                </div>
                ${openIncident && canResolveIncidents() ? `<button class="secondary-button" data-resolve-incident="${escapeHtml(incident.id)}" type="button">Resolver incidencia</button>` : ''}
            </article>
        `;
    }).join('');
}

function renderFolios(load) {
    if (!load.folios?.length) {
        elements.folioTableBody.innerHTML = `
            <tr class="folio-empty-row">
                <td colspan="6">Todavía no hay folios asignados a esta orden.</td>
            </tr>
        `;
        return;
    }

    const editable = canEdit(load);
    elements.folioTableBody.innerHTML = load.folios.map((folio) => {
        const location = folio.estado_carga === 'despachado' ? 'Despachado'
            : folio.estado_carga === 'en_anden' ? 'En andén'
                : folio.ubicacion ? `En cámara ${folio.ubicacion.camara.codigo} · ${folio.ubicacion.posicion.etiqueta}`
                    : folio.prefrio?.estado === 'aprobado' ? `Aprobado en túnel ${folio.prefrio.tunel}`
                        : folio.prefrio ? `En túnel ${folio.prefrio.tunel} · prefrío en curso`
                            : 'Pendiente de prefrío';
        return `
            <tr>
                <td><strong>${escapeHtml(folio.numero_folio)}</strong><small>${escapeHtml(folio.estado_operacional || '—')}</small></td>
                <td>${escapeHtml(statusText(folio.tipo_bulto || '—'))}</td>
                <td><span class="folio-state folio-state--${statusClass(folio.estado_carga)}">${escapeHtml(statusText(folio.estado_carga || 'pendiente'))}</span></td>
                <td><span class="location-label">${escapeHtml(location)}</span></td>
                <td>${escapeHtml(formatDate(folio.asignado_at))}</td>
                <td class="folio-action-cell">
                    ${editable ? `<button class="folio-remove" data-remove-folio="${escapeHtml(folio.id)}" data-folio-number="${escapeHtml(folio.numero_folio)}" type="button">Quitar</button>` : ''}
                </td>
            </tr>
        `;
    }).join('');
}

function renderCommands(load) {
    const editable = canEdit(load);
    const draft = load.estado === 'borrador';
    const pending = load.estado === 'pendiente';

    elements.folioAddSection.classList.toggle('is-hidden', !editable);
    elements.cancel.classList.toggle('is-hidden', !canCancel(load));
    elements.publish.classList.toggle('is-hidden', !draft);
    elements.closeDispatch.classList.toggle('is-hidden', !canCloseDispatch(load));
    elements.truckDock.classList.toggle('is-hidden', !canManageTruckPresence(load));
    elements.truckDock.textContent = load.camion_en_anden ? 'Liberar andén' : 'Camión en andén';
    elements.truckDock.classList.toggle('danger-button', Boolean(load.camion_en_anden));
    elements.truckDock.classList.toggle('secondary-button', !load.camion_en_anden);
    elements.publish.disabled = !canPublish(load);

    if (draft) {
        elements.commandTitle.textContent = load.total_folios
            ? 'Borrador listo para revisión'
            : 'Borrador sin folios';
        elements.commandDescription.textContent = load.total_folios
            ? 'Al publicar, la orden aparecerá en la operación y en el plano de estiba.'
            : 'Agrega al menos un folio para habilitar la publicación.';
        elements.folioTableHint.textContent = 'El borrador todavía no es visible para el camarero.';
    } else if (pending) {
        elements.commandTitle.textContent = 'Orden publicada y todavía editable';
        elements.commandDescription.textContent = 'Podrás modificarla hasta que la operación inicie la separación.';
        elements.folioTableHint.textContent = 'Esta orden ya está disponible para la operación.';
    } else if (load.estado === 'despachada') {
        elements.commandTitle.textContent = 'Carga completa en andén';
        elements.commandDescription.textContent = 'Registra patente y conductor cuando el vehículo haya sido cargado.';
        elements.folioTableHint.textContent = 'Los folios ya salieron de las cámaras y esperan el cierre documental.';
    } else if (load.estado === 'cerrada') {
        elements.commandTitle.textContent = `Despacho cerrado · ${load.cierre?.patente || 'sin patente'}`;
        elements.commandDescription.textContent = `${load.cierre?.conductor || 'Conductor no informado'} · salida física ${formatDate(load.cierre?.cerrada_at)} · registro ${formatDate(load.cierre?.registrada_at)}`;
        elements.folioTableHint.textContent = load.modalidad_salida === 'directa_prefrio'
            ? 'Registro histórico de salida directa desde Prefrío, sin ubicación ficticia.'
            : 'Registro histórico de los folios despachados.';
    } else {
        elements.commandTitle.textContent = statusLabels[load.estado] || statusText(load.estado);
        elements.commandDescription.textContent = 'El encabezado y los folios quedan en modo de consulta.';
        elements.folioTableHint.textContent = 'La orden no admite modificaciones en su estado actual.';
    }
}

function renderTruckPresence(load) {
    const presence = load.camion_en_anden;
    elements.truckDockPanel.classList.toggle('is-hidden', !presence);
    if (!presence) return;

    const dock = presence.anden?.nombre || presence.anden?.codigo || 'andén';
    elements.truckDockTitle.textContent = `${presence.patente} · ${dock}`;
    elements.truckDockDetail.textContent = [
        `Llegada ${formatDate(presence.ingresada_at)}`,
        presence.conductor ? `conductor ${presence.conductor}` : null,
        'el WMS prioriza sus pallets completos directamente a este andén',
    ].filter(Boolean).join(' · ');
}

function renderSelected(load) {
    state.mode = 'detail';
    state.selected = load;
    elements.empty.classList.add('is-hidden');
    elements.editor.classList.remove('is-hidden');
    elements.operation.classList.remove('is-hidden');
    elements.audit.classList.remove('is-hidden');
    elements.discardNew.classList.add('is-hidden');
    elements.headerError.textContent = '';
    clearFolioErrors();

    const editable = canEdit(load);
    elements.editorEyebrow.textContent = load.modalidad_salida === 'directa_prefrio'
        ? 'SALIDA DIRECTA DESDE PREFRÍO'
        : (load.estado === 'borrador' ? 'ORDEN EN PREPARACIÓN' : 'ORDEN DE CARGA');
    elements.editorTitle.textContent = load.codigo;
    const linkedShipment = Boolean(load.embarque);
    const linkedExternalNumbers = externalNumbers(load);
    elements.editorDescription.textContent = linkedShipment
        ? `Embarque interno ${load.embarque.codigo} · ${linkedExternalNumbers.length ? `N.º externo ${linkedExternalNumbers.join(' / ')}` : 'sin N.º externo informado'}`
        : (load.numero_orden_externa ? `N.º externo ${load.numero_orden_externa}` : 'Sin número externo asociado.');
    elements.statusBadge.className = `status-badge status-badge--${statusClass(load.estado)}`;
    elements.statusBadge.textContent = statusLabels[load.estado] || statusText(load.estado);
    elements.priorityBadge.className = `priority-badge priority-badge--${priorityClass(load.prioridad)}`;
    elements.priorityBadge.textContent = priorityLabels[load.prioridad] || statusText(load.prioridad);
    elements.version.textContent = `#${load.version}`;
    elements.updatedAt.textContent = formatDate(load.updated_at);

    elements.externalOrderLabel.textContent = linkedShipment ? 'N.º externo del cliente' : 'Número de orden externa';
    elements.externalOrderHelp.textContent = linkedShipment
        ? `Se administra en los instructivos del embarque interno ${load.embarque.codigo}.`
        : 'Referencia informada por el cliente.';
    elements.headerForm.elements.numero_orden_externa.value = linkedShipment
        ? linkedExternalNumbers.join(' / ')
        : (load.numero_orden_externa || '');
    elements.headerForm.elements.prioridad.value = load.prioridad || 'normal';
    elements.headerForm.elements.observacion.value = load.observacion || '';
    populateCameraOptions(load.camara_objetivo?.id || '');
    populateDockOptions(load.anden_previsto?.id || '');
    elements.saveText.textContent = editable ? 'Guardar encabezado' : 'Solo lectura';
    setHeaderDisabled(!editable);
    elements.headerForm.elements.numero_orden_externa.disabled = !editable || linkedShipment;

    elements.totalFolios.textContent = `${load.total_folios} / 26`;
    elements.totalCameras.textContent = String(load.distribucion?.length || 0);
    renderConcentration(load);
    renderTruckPresence(load);
    renderDistribution(load);
    renderFolios(load);
    renderIncidents();
    renderCommands(load);
    elements.folioInput.value = '';
    updateFolioInputCount();
    renderAvailableFolios();
    renderCatalog();
}

function syncLoad(load) {
    const index = state.loads.findIndex((item) => item.id === load.id);
    if (index === -1) state.loads.unshift(load);
    else state.loads[index] = load;
    renderSelected(load);
}

async function loadCatalog(page = state.loadPagination.currentPage) {
    const requestId = ++state.loadRequestId;
    const params = new URLSearchParams({
        page: String(page),
        per_page: elements.loadPageSize.value,
    });
    const query = elements.search.value.trim();
    const status = elements.statusFilter.value;
    if (query) params.set('q', query);
    if (status) params.set('estado', status);
    if (elements.incidentFilter.checked) params.set('solo_con_incidencias', '1');

    const response = await api(`/api/cargas?${params}`);
    if (requestId !== state.loadRequestId) return;

    const pagination = paginationFrom(response, Number(elements.loadPageSize.value));
    if (pagination.currentPage > pagination.lastPage) {
        await loadCatalog(pagination.lastPage);
        return;
    }

    state.loads = response.data;
    state.loadPagination = pagination;
    renderCatalog();
}

async function loadCameras() {
    const response = await api('/api/camaras');
    state.cameras = response.data;
    populateCameraOptions(state.selected?.camara_objetivo?.id || '');
}

async function loadDocks() {
    const response = await api('/api/andenes');
    state.docks = response.data;
    populateDockOptions(state.selected?.anden_previsto?.id || '');
    populateDirectDockOptions();
    populateTruckDockOptions(state.selected?.camion_en_anden?.anden?.id || '');
}

async function loadIncidents(loadId) {
    if (!loadId) {
        state.incidents = [];
        renderIncidents();
        return;
    }

    const params = new URLSearchParams({ carga_id: loadId, per_page: '50' });
    const response = await api(`/api/cargas/incidencias?${params}`);
    state.incidents = response.data;
    renderIncidents();
}

async function loadAvailableFolios(page = state.availablePagination.currentPage) {
    const requestId = ++state.availableRequestId;
    const params = new URLSearchParams({
        page: String(page),
        per_page: elements.availableFolioPageSize.value,
    });
    const query = elements.availableFolioSearch.value.trim();
    if (query) params.set('q', query);

    const response = await api(`/api/cargas/folios-disponibles?${params}`);
    if (requestId !== state.availableRequestId) return;

    const pagination = paginationFrom(response, Number(elements.availableFolioPageSize.value));
    if (pagination.currentPage > pagination.lastPage) {
        await loadAvailableFolios(pagination.lastPage);
        return;
    }

    state.availableFolios = response.data;
    state.availablePagination = pagination;
    renderAvailableFolios();
}

function renderDirectFolios() {
    const selected = new Set(parseDirectFolios());
    const folios = state.directFolios;
    const pagination = state.directPagination;

    elements.directSelectionSummary.textContent = `${selected.size} seleccionados · máximo 26`;
    elements.directCandidateSummary.textContent = pagination.total
        ? `Mostrando ${pagination.from}–${pagination.to} de ${pagination.total} disponibles`
        : '0 folios disponibles';
    renderPagination(elements.directFolioPagination, pagination, 'direct-page');

    elements.directSelectPage.disabled = folios.length === 0;
    elements.directSelectPage.checked = folios.length > 0
        && folios.every((folio) => selected.has(folio.numero_folio));
    elements.directSelectPage.indeterminate = !elements.directSelectPage.checked
        && folios.some((folio) => selected.has(folio.numero_folio));

    if (!folios.length) {
        elements.directFolioList.innerHTML = `
            <div class="direct-folio-empty">
                ${elements.directSearch.value.trim()
                    ? 'No hay folios que coincidan con la búsqueda.'
                    : 'No existen folios aprobados en Prefrío, sin ubicación y disponibles para despacho.'}
            </div>
        `;
        return;
    }

    elements.directFolioList.innerHTML = folios.map((folio) => {
        const selectedOnPage = selected.has(folio.numero_folio);
        const sag = folio.condicion_sag
            ? `${folio.condicion_sag.codigo} · ${folio.condicion_sag.nombre}`
            : 'Sin condición SAG';
        return `
            <label class="direct-folio-item">
                <input data-direct-folio="${escapeHtml(folio.numero_folio)}" type="checkbox"${selectedOnPage ? ' checked' : ''}>
                <span class="direct-folio-item__identity">
                    <strong>${escapeHtml(folio.numero_folio)}</strong>
                    <small>${escapeHtml(statusText(folio.tipo_bulto))}</small>
                </span>
                <span class="direct-folio-item__detail">
                    <span>${escapeHtml(folio.variedad || 'Sin variedad')} · ${escapeHtml(folio.calibre || 'Sin calibre')} · ${escapeHtml(folio.marca || 'Sin marca')}</span>
                    <small>${escapeHtml(sag)} · Prefrío ${escapeHtml(folio.prefrio?.codigo || 'aprobado')} · ${escapeHtml(formatDate(folio.prefrio?.finalizado_at))}</small>
                </span>
            </label>
        `;
    }).join('');
}

async function loadDirectFolios(page = state.directPagination.currentPage) {
    const requestId = ++state.directRequestId;
    const params = new URLSearchParams({
        page: String(page),
        per_page: '25',
    });
    const query = elements.directSearch.value.trim();
    if (query) params.set('q', query);

    const response = await api(`/api/cargas/folios-salida-directa-prefrio?${params}`);
    if (requestId !== state.directRequestId) return;

    const pagination = paginationFrom(response, 25);
    if (pagination.currentPage > pagination.lastPage) {
        await loadDirectFolios(pagination.lastPage);
        return;
    }

    state.directFolios = response.data;
    state.directPagination = pagination;
    renderDirectFolios();
}

async function openDirectDispatchDialog() {
    elements.directForm.reset();
    elements.directError.textContent = '';
    elements.directSearch.value = '';
    elements.directFoliosInput.value = '';
    elements.directForm.elements.ocurrido_at.value = localDateTimeValue();
    populateDirectDockOptions();
    state.directFolios = [];
    state.directPagination = emptyPagination(25);
    elements.directCandidateSummary.textContent = 'Cargando folios…';
    elements.directFolioList.innerHTML = '<div class="direct-folio-empty">Buscando folios disponibles…</div>';
    elements.directDialog.showModal();

    try {
        await loadDirectFolios(1);
        elements.directSearch.focus();
    } catch (error) {
        elements.directError.textContent = error.message;
        elements.directCandidateSummary.textContent = 'No fue posible cargar los folios.';
    }
}

async function loadInitialData() {
    const requests = [loadCatalog(1), loadCameras(), loadDocks()];
    if (state.identity?.puede_gestionar_cargas === true) {
        requests.push(loadAvailableFolios(1));
    }
    await Promise.all(requests);
}

async function selectLoad(id, { busy = true } = {}) {
    if (busy) setBusy(true, 'Cargando orden…');
    try {
        const [response, incidents] = await Promise.all([
            api(`/api/cargas/${id}`),
            api(`/api/cargas/incidencias?carga_id=${encodeURIComponent(id)}&per_page=50`),
        ]);
        state.incidents = incidents.data;
        syncLoad(response.data);
    } catch (error) {
        toast(error.message, true);
    } finally {
        if (busy) setBusy(false);
    }
}

async function recoverConflict(error) {
    if (error.status !== 409 || !state.selected?.id) return false;
    const id = state.selected.id;
    toast('La orden cambió en otra sesión. Recargamos la versión más reciente.', true);
    await selectLoad(id, { busy: false });
    await loadAvailableFolios(state.availablePagination.currentPage);
    elements.headerError.textContent = 'La acción no se guardó porque otra persona había modificado la orden. Revisa los datos actualizados y vuelve a intentarlo.';
    return true;
}

function loadPayload(includeVersion = false) {
    const form = new FormData(elements.headerForm);
    const payload = {
        numero_orden_externa: state.selected?.embarque
            ? null
            : (String(form.get('numero_orden_externa') || '').trim() || null),
        prioridad: form.get('prioridad') || 'normal',
        camara_objetivo_id: form.get('camara_objetivo_id') || null,
        anden_previsto_id: form.get('anden_previsto_id') || null,
        observacion: String(form.get('observacion') || '').trim() || null,
    };
    if (includeVersion) payload.version_esperada = state.selected.version;
    return payload;
}

function updateFolioInputCount() {
    const count = parseFolios().length;
    const remaining = remainingFolioSlots();
    const exceedsCapacity = state.mode === 'detail' && count > remaining;
    const countLabel = `${count} ${count === 1 ? 'folio único detectado' : 'folios únicos detectados'}`;

    elements.folioInputCount.textContent = exceedsCapacity
        ? `${countLabel} · solo ${remaining} ${remaining === 1 ? 'cupo disponible' : 'cupos disponibles'}`
        : countLabel;
    elements.folioInputCount.classList.toggle('is-error', exceedsCapacity);
    elements.addFolios.disabled = count === 0 || exceedsCapacity;
}

function renderReplacementCandidates() {
    elements.replacementSummary.textContent = state.replacements.length
        ? `${state.replacements.length} folios equivalentes disponibles`
        : 'No hay folios equivalentes disponibles.';

    elements.replacementList.innerHTML = state.replacements.length
        ? state.replacements.map((folio) => {
            const selected = state.selectedReplacement?.id === folio.id;
            return `
                <button class="replacement-item${selected ? ' is-selected' : ''}" data-replacement-id="${escapeHtml(folio.id)}" type="button">
                    <span><strong>${escapeHtml(folio.numero_folio)}</strong><small>${escapeHtml(folio.variedad || 'Sin variedad')} · ${escapeHtml(folio.calibre || 'Sin calibre')}</small></span>
                    <span><b>${escapeHtml(folio.ubicacion?.camara?.codigo || '—')}</b><small>${escapeHtml(folio.ubicacion?.posicion?.etiqueta || 'Sin ubicación')}</small></span>
                </button>
            `;
        }).join('')
        : '<div class="replacement-empty">Prueba otra búsqueda o coordina el despacho parcial.</div>';
}

async function loadReplacementCandidates() {
    const originalId = state.selectedIncident?.folio?.id;
    if (!originalId) return;
    const params = new URLSearchParams({ equivalente_a: originalId, per_page: '25' });
    const query = elements.replacementSearch.value.trim();
    if (query) params.set('q', query);
    elements.replacementSummary.textContent = 'Buscando equivalencias…';
    const response = await api(`/api/cargas/folios-disponibles?${params}`);
    state.replacements = response.data;
    if (!state.replacements.some((folio) => folio.id === state.selectedReplacement?.id)) {
        state.selectedReplacement = null;
    }
    renderReplacementCandidates();
}

function openIncidentDialog(incident) {
    state.selectedIncident = incident;
    state.selectedReplacement = null;
    state.replacements = [];
    elements.incidentForm.reset();
    elements.incidentError.textContent = '';
    elements.replacementPicker.classList.add('is-hidden');
    elements.replacementList.innerHTML = '';
    elements.incidentContext.textContent = `${incident.folio?.numero_folio || 'Folio'} · ${incidentTypeLabels[incident.tipo] || statusText(incident.tipo)}`;

    const canCommercial = state.identity?.puede_resolver_comercialmente_carga === true;
    for (const input of elements.incidentForm.elements.resolucion) {
        input.disabled = !canCommercial && input.value !== 'reparado';
    }
    elements.incidentForm.elements.resolucion.value = canCommercial ? 'despacho_parcial' : 'reparado';
    elements.incidentDialog.showModal();
}

function openCloseDialog() {
    elements.closeForm.reset();
    elements.closeError.textContent = '';
    elements.closeContext.textContent = `${state.selected.codigo} · ${state.selected.total_folios} folios en andén`;
    elements.closeForm.elements.ocurrido_at.value = localDateTimeValue();
    elements.closeForm.elements.patente.value = state.selected.camion_en_anden?.patente || '';
    elements.closeForm.elements.conductor.value = state.selected.camion_en_anden?.conductor || '';
    elements.closeDialog.showModal();
    (elements.closeForm.elements.patente.value
        ? elements.closeForm.elements.conductor
        : elements.closeForm.elements.patente).focus();
}

function openTruckDockDialog() {
    elements.truckDockForm.reset();
    elements.truckDockError.textContent = '';
    elements.truckDockForm.elements.ingresada_at.value = localDateTimeValue();
    populateTruckDockOptions(state.selected?.anden_previsto?.id || '');
    elements.truckDockDialog.showModal();
    elements.truckDockSelect.focus();
}

function openReleaseDockDialog() {
    const presence = state.selected?.camion_en_anden;
    if (!presence) return;
    elements.releaseDockForm.reset();
    elements.releaseDockError.textContent = '';
    elements.releaseDockContext.textContent = `${presence.patente} · ${presence.anden?.nombre || 'andén'} · llegada ${formatDate(presence.ingresada_at)}.`;
    elements.releaseDockDialog.showModal();
    elements.releaseDockForm.elements.motivo.focus();
}

elements.loginForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    elements.loginError.textContent = '';
    const data = Object.fromEntries(new FormData(elements.loginForm));
    setBusy(true, 'Validando acceso…');
    try {
        const payload = await api('/api/acceso-oficina', {
            method: 'POST',
            body: JSON.stringify(data),
        });
        if (!payload.usuario.puede_consultar_cargas) {
            throw new ApiError('Tu perfil no puede consultar órdenes de carga.', 403);
        }
        persistSession(payload);
        showApp();
        await loadInitialData();
        showEmpty();
    } catch (error) {
        elements.loginError.textContent = error.message;
    } finally {
        setBusy(false);
    }
});

[elements.newLoad, elements.emptyNewLoad].forEach((button) => {
    button.addEventListener('click', startNew);
});

elements.discardNew.addEventListener('click', showEmpty);

elements.search.addEventListener('input', () => {
    window.clearTimeout(loadSearchTimer);
    elements.catalogSummary.textContent = 'Buscando órdenes…';
    loadSearchTimer = window.setTimeout(() => {
        void loadCatalog(1).catch((error) => toast(error.message, true));
    }, 300);
});

elements.statusFilter.addEventListener('change', () => {
    void loadCatalog(1).catch((error) => toast(error.message, true));
});

elements.incidentFilter.addEventListener('change', () => {
    void loadCatalog(1).catch((error) => toast(error.message, true));
});

elements.loadPageSize.addEventListener('change', () => {
    void loadCatalog(1).catch((error) => toast(error.message, true));
});

elements.loadPagination.addEventListener('click', (event) => {
    const button = event.target.closest('[data-load-page]');
    if (!button || button.disabled) return;
    void loadCatalog(Number(button.dataset.loadPage)).catch((error) => toast(error.message, true));
});

elements.list.addEventListener('click', (event) => {
    const button = event.target.closest('[data-load-id]');
    if (!button) return;
    void selectLoad(button.dataset.loadId);
});

elements.headerForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    elements.headerError.textContent = '';
    const creating = state.mode === 'new';
    if (!creating && !canEdit(state.selected)) return;

    setBusy(true, creating ? 'Creando borrador…' : 'Guardando encabezado…');
    try {
        const response = await api(
            creating ? '/api/cargas' : `/api/cargas/${state.selected.id}`,
            {
                method: creating ? 'POST' : 'PUT',
                body: JSON.stringify(loadPayload(!creating)),
            },
        );
        syncLoad(response.data);
        await loadCatalog(creating ? 1 : state.loadPagination.currentPage);
        toast(`${response.data.codigo} fue ${creating ? 'creada' : 'actualizada'} correctamente.`);
    } catch (error) {
        if (!(await recoverConflict(error))) { elements.headerError.textContent = error.message; toast(error.message, true); }
    } finally {
        setBusy(false);
    }
});

elements.folioInput.addEventListener('input', () => {
    clearFolioErrors();
    updateFolioInputCount();
    renderAvailableFolios();
});

elements.availableFolioSearch.addEventListener('input', () => {
    window.clearTimeout(availableSearchTimer);
    elements.availableFolioSummary.textContent = 'Buscando folios disponibles…';
    availableSearchTimer = window.setTimeout(() => {
        void loadAvailableFolios(1).catch((error) => toast(error.message, true));
    }, 300);
});

elements.availableFolioPageSize.addEventListener('change', () => {
    void loadAvailableFolios(1).catch((error) => toast(error.message, true));
});

elements.availableFolioPagination.addEventListener('click', (event) => {
    const button = event.target.closest('[data-available-page]');
    if (!button || button.disabled) return;
    void loadAvailableFolios(Number(button.dataset.availablePage))
        .catch((error) => toast(error.message, true));
});

elements.availableFolioList.addEventListener('change', (event) => {
    const checkbox = event.target.closest('[data-available-folio]');
    if (!checkbox) return;

    const folios = new Set(parseFolios());
    if (checkbox.checked) {
        if (!folios.has(checkbox.dataset.availableFolio) && folios.size >= remainingFolioSlots()) {
            checkbox.checked = false;
            toast('La orden alcanzó el máximo de 26 folios.', true);
            renderAvailableFolios();
            return;
        }
        folios.add(checkbox.dataset.availableFolio);
    } else {
        folios.delete(checkbox.dataset.availableFolio);
    }
    elements.folioInput.value = [...folios].join('\n');
    clearFolioErrors();
    updateFolioInputCount();
    renderAvailableFolios();
});

elements.availableFolioSelectPage.addEventListener('change', () => {
    const folios = new Set(parseFolios());

    if (!elements.availableFolioSelectPage.checked) {
        state.availableFolios.forEach((folio) => folios.delete(folio.numero_folio));
    } else {
        let remaining = Math.max(0, remainingFolioSlots() - folios.size);
        let omitted = 0;
        state.availableFolios.forEach((folio) => {
            if (folios.has(folio.numero_folio)) return;
            if (remaining <= 0) {
                omitted += 1;
                return;
            }
            folios.add(folio.numero_folio);
            remaining -= 1;
        });
        if (omitted > 0) toast(`Se alcanzó el máximo de 26 folios; ${omitted} quedaron sin seleccionar.`, true);
    }

    elements.folioInput.value = [...folios].join('\n');
    clearFolioErrors();
    updateFolioInputCount();
    renderAvailableFolios();
});

elements.reloadAvailableFolios.addEventListener('click', async () => {
    setBusy(true, 'Actualizando folios disponibles…');
    try {
        await loadAvailableFolios(state.availablePagination.currentPage);
        toast('Existencia de folios actualizada.');
    } catch (error) {
        toast(error.message, true);
    } finally {
        setBusy(false);
    }
});

elements.addFolios.addEventListener('click', async () => {
    const folios = parseFolios();
    if (!folios.length || !canEdit(state.selected)) return;
    clearFolioErrors();
    setBusy(true, `Validando ${folios.length} ${folios.length === 1 ? 'folio' : 'folios'}…`);
    try {
        const response = await api(`/api/cargas/${state.selected.id}/folios`, {
            method: 'POST',
            body: JSON.stringify({
                folios,
                version_esperada: state.selected.version,
            }),
        });
        syncLoad(response.data);
        await Promise.all([
            loadCatalog(state.loadPagination.currentPage),
            loadAvailableFolios(state.availablePagination.currentPage),
        ]);
        toast(`${folios.length} ${folios.length === 1 ? 'folio incorporado' : 'folios incorporados'} a ${response.data.codigo}.`);
    } catch (error) {
        if (!(await recoverConflict(error))) { showFolioErrors(error); toast(error.message, true); }
    } finally {
        setBusy(false);
    }
});

elements.folioTableBody.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-remove-folio]');
    if (!button || !canEdit(state.selected)) return;
    const folioNumber = button.dataset.folioNumber;
    if (!window.confirm(`¿Quitar ${folioNumber} de ${state.selected.codigo}? El evento quedará registrado.`)) return;

    setBusy(true, `Quitando ${folioNumber}…`);
    try {
        const response = await api(
            `/api/cargas/${state.selected.id}/folios/${button.dataset.removeFolio}`,
            {
                method: 'DELETE',
                body: JSON.stringify({
                    version_esperada: state.selected.version,
                    motivo: 'Desasignación desde oficina de despacho',
                }),
            },
        );
        syncLoad(response.data);
        await Promise.all([
            loadCatalog(state.loadPagination.currentPage),
            loadAvailableFolios(state.availablePagination.currentPage),
        ]);
        toast(`${folioNumber} fue retirado de la orden.`);
    } catch (error) {
        if (!(await recoverConflict(error))) toast(error.message, true);
    } finally {
        setBusy(false);
    }
});

elements.publish.addEventListener('click', async () => {
    if (!canPublish(state.selected)) return;
    if (!window.confirm(`¿Publicar ${state.selected.codigo} con ${state.selected.total_folios} folios? Quedará visible para los camareros y en el plano.`)) return;

    setBusy(true, 'Publicando orden para la operación…');
    try {
        const response = await api(`/api/cargas/${state.selected.id}/publicar`, {
            method: 'POST',
            body: JSON.stringify({ version_esperada: state.selected.version }),
        });
        syncLoad(response.data);
        await loadCatalog(state.loadPagination.currentPage);
        toast(`${response.data.codigo} fue publicada para la operación.`);
    } catch (error) {
        if (!(await recoverConflict(error))) toast(error.message, true);
    } finally {
        setBusy(false);
    }
});

elements.cancel.addEventListener('click', async () => {
    if (!canCancel(state.selected)) return;
    const reason = window.prompt(
        `Indica el motivo de cancelación de ${state.selected.codigo}.`,
        'Cancelación desde oficina de despacho',
    );
    if (reason === null) return;

    setBusy(true, 'Cancelando orden y liberando folios…');
    try {
        const response = await api(`/api/cargas/${state.selected.id}/cancelar`, {
            method: 'POST',
            body: JSON.stringify({
                version_esperada: state.selected.version,
                motivo: reason.trim() || null,
            }),
        });
        syncLoad(response.data);
        await Promise.all([
            loadCatalog(state.loadPagination.currentPage),
            loadAvailableFolios(state.availablePagination.currentPage),
        ]);
        toast(`${response.data.codigo} fue cancelada. Sus folios quedaron liberados.`);
    } catch (error) {
        if (!(await recoverConflict(error))) toast(error.message, true);
    } finally {
        setBusy(false);
    }
});

elements.incidentsList.addEventListener('click', (event) => {
    const button = event.target.closest('[data-resolve-incident]');
    if (!button) return;
    const incident = state.incidents.find((item) => item.id === button.dataset.resolveIncident);
    if (incident) openIncidentDialog(incident);
});

elements.incidentForm.addEventListener('change', (event) => {
    if (event.target.name !== 'resolucion') return;
    const replacement = event.target.value === 'reemplazo';
    elements.replacementPicker.classList.toggle('is-hidden', !replacement);
    elements.incidentError.textContent = '';
    if (replacement) {
        void loadReplacementCandidates().catch((error) => {
            elements.incidentError.textContent = error.message;
        });
    }
});

elements.replacementSearch.addEventListener('input', () => {
    window.clearTimeout(replacementSearchTimer);
    replacementSearchTimer = window.setTimeout(() => {
        void loadReplacementCandidates().catch((error) => {
            elements.incidentError.textContent = error.message;
        });
    }, 300);
});

elements.replacementList.addEventListener('click', (event) => {
    const button = event.target.closest('[data-replacement-id]');
    if (!button) return;
    state.selectedReplacement = state.replacements.find(
        (folio) => folio.id === button.dataset.replacementId,
    ) || null;
    renderReplacementCandidates();
});

elements.incidentForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const resolution = elements.incidentForm.elements.resolucion.value;
    const observation = String(elements.incidentForm.elements.observacion.value || '').trim();
    elements.incidentError.textContent = '';

    if (!resolution) {
        elements.incidentError.textContent = 'Selecciona un tipo de resolución.';
        return;
    }
    if (resolution === 'reemplazo' && !state.selectedReplacement) {
        elements.incidentError.textContent = 'Selecciona el folio equivalente que reemplazará al afectado.';
        return;
    }

    setBusy(true, 'Resolviendo incidencia…');
    try {
        await api(`/api/cargas/incidencias/${state.selectedIncident.id}/resolver`, {
            method: 'POST',
            body: JSON.stringify({
                operacion_id: operationId(),
                resolucion: resolution,
                folio_reemplazo_id: resolution === 'reemplazo' ? state.selectedReplacement.id : null,
                observacion: observation || null,
            }),
        });
        elements.incidentDialog.close();
        const loadId = state.selected.id;
        await Promise.all([
            selectLoad(loadId, { busy: false }),
            loadCatalog(state.loadPagination.currentPage),
            loadAvailableFolios(state.availablePagination.currentPage),
        ]);
        toast(`Incidencia de ${state.selectedIncident.folio?.numero_folio || 'folio'} resuelta correctamente.`);
    } catch (error) {
        elements.incidentError.textContent = error.message;
        toast(error.message, true);
    } finally {
        setBusy(false);
    }
});

elements.directDispatch.addEventListener('click', () => {
    if (state.identity?.puede_gestionar_cargas === true
        && state.identity?.puede_cerrar_despacho_frigorifico === true) {
        void openDirectDispatchDialog();
    }
});

elements.truckDock.addEventListener('click', () => {
    if (!canManageTruckPresence(state.selected)) return;
    if (state.selected.camion_en_anden) openReleaseDockDialog();
    else openTruckDockDialog();
});

elements.truckDockForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const form = new FormData(elements.truckDockForm);
    const dockId = String(form.get('anden_id') || '').trim();
    const plate = String(form.get('patente') || '').trim().toUpperCase();
    const driver = String(form.get('conductor') || '').trim();
    const arrivedAt = localDateTimeToIso(String(form.get('ingresada_at') || ''));
    const observation = String(form.get('observacion') || '').trim();
    elements.truckDockError.textContent = '';

    if (!dockId || !plate || !arrivedAt) {
        elements.truckDockError.textContent = 'El andén, la patente y la fecha/hora de llegada son obligatorios.';
        return;
    }
    if (new Date(arrivedAt).getTime() > Date.now()) {
        elements.truckDockError.textContent = 'La fecha y hora de llegada no puede estar en el futuro.';
        return;
    }

    setBusy(true, 'Activando prioridad directa a andén…');
    try {
        const response = await api(`/api/cargas/${state.selected.id}/camion-en-anden`, {
            method: 'POST',
            body: JSON.stringify({
                operacion_id: operationId(),
                version_esperada: state.selected.version,
                anden_id: dockId,
                patente: plate,
                conductor: driver || null,
                ingresada_at: arrivedAt,
                observacion: observation || null,
            }),
        });
        elements.truckDockDialog.close();
        syncLoad(response.data);
        await Promise.all([loadDocks(), loadCatalog(state.loadPagination.currentPage)]);
        toast(`${response.data.codigo}: ${plate} presente; despacho directo priorizado.`);
    } catch (error) {
        if (await recoverConflict(error)) {
            elements.truckDockError.textContent = 'La carga cambió en otra sesión. Revisa la información actualizada antes de confirmar.';
        } else {
            elements.truckDockError.textContent = error.message;
            toast(error.message, true);
        }
    } finally {
        setBusy(false);
    }
});

elements.releaseDockForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const reason = String(new FormData(elements.releaseDockForm).get('motivo') || '').trim();
    elements.releaseDockError.textContent = '';
    if (!reason) {
        elements.releaseDockError.textContent = 'Indica por qué el camión dejó el andén.';
        return;
    }

    setBusy(true, 'Liberando andén y recalculando tareas…');
    try {
        const response = await api(`/api/cargas/${state.selected.id}/camion-en-anden/finalizar`, {
            method: 'POST',
            body: JSON.stringify({
                operacion_id: operationId(),
                version_esperada: state.selected.version,
                motivo: reason,
            }),
        });
        elements.releaseDockDialog.close();
        syncLoad(response.data);
        await Promise.all([loadDocks(), loadCatalog(state.loadPagination.currentPage)]);
        toast(`${response.data.codigo}: andén liberado y prioridad directa finalizada.`);
    } catch (error) {
        if (await recoverConflict(error)) {
            elements.releaseDockError.textContent = 'La carga cambió en otra sesión. Revisa si el camión continúa en andén.';
        } else {
            elements.releaseDockError.textContent = error.message;
            toast(error.message, true);
        }
    } finally {
        setBusy(false);
    }
});

elements.directSearch.addEventListener('input', () => {
    clearTimeout(directSearchTimer);
    directSearchTimer = setTimeout(() => {
        loadDirectFolios(1).catch((error) => {
            elements.directError.textContent = error.message;
        });
    }, 250);
});

elements.directFolioPagination.addEventListener('click', (event) => {
    const button = event.target.closest('[data-direct-page]');
    if (!button || button.disabled) return;
    loadDirectFolios(Number(button.dataset.directPage)).catch((error) => {
        elements.directError.textContent = error.message;
        toast(error.message, true);
    });
});

elements.directFoliosInput.addEventListener('input', () => {
    const count = parseDirectFolios().length;
    elements.directError.textContent = count > 26
        ? 'Una salida puede contener como máximo 26 folios.'
        : '';
    renderDirectFolios();
});

elements.directFolioList.addEventListener('change', (event) => {
    const checkbox = event.target.closest('[data-direct-folio]');
    if (!checkbox) return;

    const selected = new Set(parseDirectFolios());
    if (checkbox.checked && selected.size >= 26 && !selected.has(checkbox.dataset.directFolio)) {
        checkbox.checked = false;
        elements.directError.textContent = 'Una salida puede contener como máximo 26 folios.';
        return;
    }

    if (checkbox.checked) selected.add(checkbox.dataset.directFolio);
    else selected.delete(checkbox.dataset.directFolio);
    elements.directError.textContent = '';
    syncDirectFolioInput(selected);
});

elements.directSelectPage.addEventListener('change', () => {
    const selected = new Set(parseDirectFolios());
    const pageFolios = state.directFolios.map((folio) => folio.numero_folio);

    if (elements.directSelectPage.checked) {
        for (const folio of pageFolios) {
            if (selected.size >= 26) break;
            selected.add(folio);
        }
    } else {
        pageFolios.forEach((folio) => selected.delete(folio));
    }

    elements.directError.textContent = selected.size >= 26 && pageFolios.some((folio) => !selected.has(folio))
        ? 'Se seleccionaron los primeros 26 folios permitidos.'
        : '';
    syncDirectFolioInput(selected);
});

elements.directForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const form = new FormData(elements.directForm);
    const folios = parseDirectFolios();
    const dockId = String(form.get('anden_id') || '').trim();
    const plate = String(form.get('patente') || '').trim().toUpperCase();
    const driver = String(form.get('conductor') || '').trim();
    const occurredAt = localDateTimeToIso(String(form.get('ocurrido_at') || ''));
    const observation = String(form.get('observacion') || '').trim();
    const externalOrder = String(form.get('numero_orden_externa') || '').trim();
    elements.directError.textContent = '';

    if (folios.length < 1 || folios.length > 26) {
        elements.directError.textContent = 'Selecciona entre 1 y 26 folios.';
        return;
    }

    if (!dockId || !plate || !driver || !occurredAt) {
        elements.directError.textContent = 'Andén, patente, conductor y fecha/hora física son obligatorios.';
        return;
    }

    if (new Date(occurredAt).getTime() > Date.now()) {
        elements.directError.textContent = 'La fecha y hora física no puede estar en el futuro.';
        return;
    }

    setBusy(true, 'Registrando salida directa desde Prefrío…');
    try {
        const response = await api('/api/cargas/despacho-directo-prefrio', {
            method: 'POST',
            body: JSON.stringify({
                operacion_id: operationId(),
                folios,
                numero_orden_externa: externalOrder || null,
                prioridad: String(form.get('prioridad') || 'normal'),
                anden_id: dockId,
                patente: plate,
                conductor: driver,
                ocurrido_at: occurredAt,
                observacion: observation || null,
            }),
        });
        elements.directDialog.close();
        state.incidents = [];
        syncLoad(response.data);
        await Promise.all([
            loadCatalog(1),
            loadAvailableFolios(state.availablePagination.currentPage),
        ]);
        toast(`${response.data.codigo}: salida directa de ${folios.length} folios registrada con hora física ${formatDate(occurredAt)}.`);
    } catch (error) {
        elements.directError.textContent = error.message;
    } finally {
        setBusy(false);
    }
});

elements.closeDispatch.addEventListener('click', () => {
    if (canCloseDispatch(state.selected)) openCloseDialog();
});

elements.closeForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const form = new FormData(elements.closeForm);
    const plate = String(form.get('patente') || '').trim().toUpperCase();
    const driver = String(form.get('conductor') || '').trim();
    const observation = String(form.get('observacion') || '').trim();
    const occurredAt = localDateTimeToIso(String(form.get('ocurrido_at') || ''));
    elements.closeError.textContent = '';

    if (!plate || !driver || !occurredAt) {
        elements.closeError.textContent = 'La patente, el conductor y la fecha/hora física son obligatorios.';
        return;
    }

    if (new Date(occurredAt).getTime() > Date.now()) {
        elements.closeError.textContent = 'La fecha y hora física no puede estar en el futuro.';
        return;
    }

    setBusy(true, 'Cerrando despacho y confirmando salida…');
    try {
        const response = await api(`/api/cargas/${state.selected.id}/cerrar-despacho`, {
            method: 'POST',
            body: JSON.stringify({
                operacion_id: operationId(),
                patente: plate,
                conductor: driver,
                ocurrido_at: occurredAt,
                observacion: observation || null,
            }),
        });
        elements.closeDialog.close();
        state.incidents = [];
        syncLoad(response.data);
        await loadCatalog(state.loadPagination.currentPage);
        toast(`${response.data.codigo} quedó cerrada con salida de ${plate}.`);
    } catch (error) {
        elements.closeError.textContent = error.message;
        toast(error.message, true);
    } finally {
        setBusy(false);
    }
});

document.querySelectorAll('[data-close-dialog]').forEach((button) => {
    button.addEventListener('click', () => button.closest('dialog')?.close());
});

elements.reload.addEventListener('click', async () => {
    const selectedId = state.selected?.id;
    setBusy(true, 'Actualizando órdenes…');
    try {
        await loadInitialData();
        if (selectedId) await selectLoad(selectedId, { busy: false });
        else renderCatalog();
        toast('Órdenes actualizadas.');
    } catch (error) {
        toast(error.message, true);
    } finally {
        setBusy(false);
    }
});

elements.logout.addEventListener('click', async () => {
    try {
        await api('/api/acceso-oficina', { method: 'DELETE' });
    } finally {
        clearSession();
    }
});

async function boot() {
    updateFolioInputCount();
    if (!state.token || !state.identity?.puede_consultar_cargas) return;
    showApp();
    setBusy(true, 'Cargando órdenes de carga…');
    try {
        await loadInitialData();
        showEmpty();
    } catch (error) {
        if (error.status !== 401) toast(error.message, true);
    } finally {
        setBusy(false);
    }
}

void boot();
