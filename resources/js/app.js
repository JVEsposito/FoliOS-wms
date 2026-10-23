import { bandAffinityText } from './shared/camera-band-affinity';
import { createOperationalPoller } from './shared/operational-poller';
import { cameraDisplayName } from './shared/camera-display';
import { bandNumberingLabel, orderBandsForCamera } from './shared/camera-layout';

const $ = (id) => document.getElementById(id);

const elements = {
    accessScreen: $('accessScreen'),
    accessForm: $('accessForm'),
    accessError: $('accessError'),
    appShell: $('appShell'),
    networkStatus: $('networkStatus'),
    globalSessionStatus: $('globalSessionStatus'),
    operatorButton: $('operatorButton'),
    operatorInitials: $('operatorInitials'),
    operatorName: $('operatorName'),
    deviceName: $('deviceName'),
    logoutButton: $('logoutButton'),
    cameraList: $('cameraList'),
    refreshCamerasButton: $('refreshCamerasButton'),
    planBreadcrumb: $('planBreadcrumb'),
    planTitle: $('planTitle'),
    planSubtitle: $('planSubtitle'),
    planVersion: $('planVersion'),
    occupancyValue: $('occupancyValue'),
    occupancyBar: $('occupancyBar'),
    occupancyDetail: $('occupancyDetail'),
    lockBanner: $('lockBanner'),
    lockTitle: $('lockTitle'),
    lockMessage: $('lockMessage'),
    positionMap: $('positionMap'),
    selectedPositionLabel: $('selectedPositionLabel'),
    selectedPositionState: $('selectedPositionState'),
    folioCard: $('folioCard'),
    selectedFolioType: $('selectedFolioType'),
    selectedFolioNumber: $('selectedFolioNumber'),
    selectedFolioVarietyLabel: $('selectedFolioVarietyLabel'),
    selectedFolioVariety: $('selectedFolioVariety'),
    selectedFolioCaliberLabel: $('selectedFolioCaliberLabel'),
    selectedFolioCaliber: $('selectedFolioCaliber'),
    selectedFolioSagLabel: $('selectedFolioSagLabel'),
    selectedFolioSag: $('selectedFolioSag'),
    locateButton: $('locateButton'),
    moveButton: $('moveButton'),
    materialDispatchButton: $('materialDispatchButton'),
    sessionButton: $('sessionButton'),
    sessionButtonTitle: $('sessionButtonTitle'),
    sessionButtonSubtitle: $('sessionButtonSubtitle'),
    refreshPlanButton: $('refreshPlanButton'),
    actionNote: $('actionNote'),
    recentList: $('recentList'),
    lastSyncText: $('lastSyncText'),
    locateDialog: $('locateDialog'),
    locateForm: $('locateForm'),
    locateDestinationText: $('locateDestinationText'),
    locateError: $('locateError'),
    locateFolioNumber: $('locateFolioNumber'),
    locateFolioLookupStatus: $('locateFolioLookupStatus'),
    locateSubmitButton: $('locateSubmitButton'),
    sagSelect: $('sagSelect'),
    locateTypeSelect: $('locateTypeSelect'),
    materialItemSelect: $('materialItemSelect'),
    materialDispatchDialog: $('materialDispatchDialog'),
    materialDispatchForm: $('materialDispatchForm'),
    materialDispatchTitle: $('materialDispatchTitle'),
    materialDispatchOrigin: $('materialDispatchOrigin'),
    materialBalance: $('materialBalance'),
    materialDispatchSelect: $('materialDispatchSelect'),
    materialDestinationField: $('materialDestinationField'),
    materialDestinationSelect: $('materialDestinationSelect'),
    materialAmountLabel: $('materialAmountLabel'),
    materialFifoNotice: $('materialFifoNotice'),
    materialDispatchError: $('materialDispatchError'),
    moveDialog: $('moveDialog'),
    moveForm: $('moveForm'),
    moveDialogTitle: $('moveDialogTitle'),
    moveOriginText: $('moveOriginText'),
    moveCameraSelect: $('moveCameraSelect'),
    moveDestinationGrid: $('moveDestinationGrid'),
    moveDestinationHint: $('moveDestinationHint'),
    moveError: $('moveError'),
    confirmMoveButton: $('confirmMoveButton'),
    loadingOverlay: $('loadingOverlay'),
    loadingText: $('loadingText'),
    toastRegion: $('toastRegion'),
};

const storageKeys = {
    token: 'estiba_wms_token',
    identity: 'estiba_wms_identity',
    deviceCode: 'estiba_wms_device_code',
};

const state = {
    token: localStorage.getItem(storageKeys.token),
    identity: readStoredJson(storageKeys.identity),
    cameras: [],
    conditions: [],
    materialCatalog: { items: [], destinos: [] },
    materialDispatches: [],
    selectedCameraId: null,
    plan: null,
    camerasEtag: null,
    planEtags: new Map(),
    recentEtags: new Map(),
    selectedPosition: null,
    destinationPlan: null,
    moveDestination: null,
    materialCreateOperationId: null,
    materialWithdrawOperationId: null,
    locateLookup: null,
    locateLookupTimer: null,
    locateLookupSequence: 0,
    polling: false,
    poller: null,
};

class ApiError extends Error {
    constructor(message, status, data = {}) {
        super(message);
        this.name = 'ApiError';
        this.status = status;
        this.data = data;
    }
}

function readStoredJson(key) {
    try {
        return JSON.parse(localStorage.getItem(key) || 'null');
    } catch {
        return null;
    }
}

function escapeHtml(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function compactObject(object) {
    return Object.fromEntries(
        Object.entries(object).filter(([, value]) => value !== '' && value !== null && value !== undefined),
    );
}

function operationUuid() {
    if (typeof crypto.randomUUID === 'function') return crypto.randomUUID();

    const bytes = crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = [...bytes].map((value) => value.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

function validationMessage(data, fallback) {
    const messages = Object.values(data?.errors || {}).flat();
    return messages[0] || data?.message || fallback;
}

async function api(path, options = {}) {
    const {
        acceptNotModified = false,
        onResponse = null,
        ...requestOptions
    } = options;
    const headers = new Headers(requestOptions.headers || {});
    headers.set('Accept', 'application/json');

    if (state.token) {
        headers.set('Authorization', `Bearer ${state.token}`);
    }

    if (requestOptions.body && !(requestOptions.body instanceof FormData)) {
        headers.set('Content-Type', 'application/json');
    }

    let response;

    try {
        response = await fetch(path, { ...requestOptions, headers });
    } catch {
        throw new ApiError('No fue posible conectar con el servidor. Revisa la conexión de red.', 0);
    }

    if (typeof onResponse === 'function') onResponse(response);
    if (response.status === 304 && acceptNotModified) return null;

    const data = response.status === 204
        ? null
        : await response.json().catch(() => ({}));

    if (! response.ok) {
        if (response.status === 401 && path !== '/api/acceso-tablet') {
            clearSession();
        }

        throw new ApiError(
            validationMessage(data, 'La operación no pudo completarse.'),
            response.status,
            data,
        );
    }

    return data;
}

async function loadPlan(cameraId, { conditional = false } = {}) {
    const headers = new Headers();
    const etag = conditional ? state.planEtags.get(cameraId) : null;
    if (etag) headers.set('If-None-Match', etag);

    const response = await api(`/api/camaras/${cameraId}/plano`, {
        headers,
        acceptNotModified: conditional,
        onResponse: (httpResponse) => {
            const nextEtag = httpResponse.headers.get('ETag');
            if (nextEtag) state.planEtags.set(cameraId, nextEtag);
        },
    });

    return response?.data ?? null;
}

async function loadCameras({ conditional = false } = {}) {
    const headers = new Headers();
    if (conditional && state.camerasEtag) {
        headers.set('If-None-Match', state.camerasEtag);
    }

    const response = await api('/api/camaras', {
        headers,
        acceptNotModified: conditional,
        onResponse: (httpResponse) => {
            const nextEtag = httpResponse.headers.get('ETag');
            if (nextEtag) state.camerasEtag = nextEtag;
        },
    });

    return response?.data ?? null;
}

async function loadRecent(cameraId, { conditional = false } = {}) {
    const headers = new Headers();
    const etag = conditional ? state.recentEtags.get(cameraId) : null;
    if (etag) headers.set('If-None-Match', etag);

    const response = await api(
        `/api/movimientos/recientes?camara_id=${encodeURIComponent(cameraId)}&limite=8`,
        {
            headers,
            acceptNotModified: conditional,
            onResponse: (httpResponse) => {
                const nextEtag = httpResponse.headers.get('ETag');
                if (nextEtag) state.recentEtags.set(cameraId, nextEtag);
            },
        },
    );

    return response?.data ?? null;
}

async function apiWithPhysicalWarnings(path, payload) {
    try {
        return await api(path, { method: 'POST', body: JSON.stringify(payload) });
    } catch (error) {
        const warnings = error.data?.codigo === 'confirmacion_requerida'
            && Array.isArray(error.data?.advertencias)
            ? error.data.advertencias
            : [];

        if (warnings.length === 0) throw error;

        const message = warnings
            .map((warning) => `${warning.titulo}\n${warning.mensaje}`)
            .join('\n\n');

        if (! window.confirm(`Confirmar excepción física\n\n${message}`)) {
            throw new ApiError('Operación cancelada: no se confirmaron las advertencias físicas.', 499);
        }

        return api(path, {
            method: 'POST',
            body: JSON.stringify({
                ...payload,
                advertencias_confirmadas: warnings.map((warning) => warning.codigo),
            }),
        });
    }
}

function setBusy(active, message = 'Sincronizando…') {
    elements.loadingText.textContent = message;
    elements.loadingOverlay.classList.toggle('is-hidden', ! active);
}

async function withBusy(message, callback) {
    setBusy(true, message);

    try {
        return await callback();
    } finally {
        setBusy(false);
    }
}

function showToast(message, type = 'success') {
    const toast = document.createElement('div');
    toast.className = `toast${type === 'error' ? ' is-error' : ''}${type === 'warning' ? ' is-warning' : ''}`;
    toast.textContent = message;
    elements.toastRegion.append(toast);

    window.setTimeout(() => toast.remove(), 4200);
}

function persistSession(token, identity) {
    state.token = token;
    state.identity = identity;
    localStorage.setItem(storageKeys.token, token);
    localStorage.setItem(storageKeys.identity, JSON.stringify(identity));
    localStorage.setItem(storageKeys.deviceCode, identity.dispositivo.codigo);
}

function clearSession() {
    state.token = null;
    state.identity = null;
    state.cameras = [];
    state.plan = null;
    state.camerasEtag = null;
    state.planEtags.clear();
    state.recentEtags.clear();
    state.selectedCameraId = null;
    state.selectedPosition = null;
    state.materialCatalog = { items: [], destinos: [] };
    state.materialDispatches = [];
    localStorage.removeItem(storageKeys.token);
    localStorage.removeItem(storageKeys.identity);
    elements.appShell.classList.add('is-hidden');
    elements.accessScreen.classList.remove('is-hidden');
    elements.logoutButton.classList.add('is-hidden');
}

function showApplication() {
    elements.accessScreen.classList.add('is-hidden');
    elements.appShell.classList.remove('is-hidden');

    const name = state.identity?.usuario?.nombre || 'Operador';
    const initials = name
        .split(/\s+/)
        .slice(0, 2)
        .map((part) => part[0])
        .join('')
        .toUpperCase();

    elements.operatorInitials.textContent = initials || 'OP';
    elements.operatorName.textContent = name;
    elements.deviceName.textContent = state.identity?.dispositivo?.nombre || 'Tablet';
}

function selectedCamera() {
    return state.cameras.find((camera) => camera.id === state.selectedCameraId) || null;
}

function ownSession(plan = state.plan) {
    if (plan?.acceso?.modo !== 'edicion' || ! plan.acceso.sesion?.es_propia) {
        return null;
    }

    return plan.acceso.sesion;
}

function canOperate() {
    return navigator.onLine
        && state.plan?.acceso?.modo === 'edicion'
        && Boolean(ownSession());
}

function positionLabel(position) {
    return position?.etiqueta || `B${String(position?.banda).padStart(2, '0')}-P${String(position?.posicion).padStart(2, '0')}-N${position?.nivel}`;
}

function typeLabel(type) {
    return {
        ubicacion_inicial: 'Ubicación inicial',
        reubicacion: 'Reubicación',
        traslado_entre_camaras: 'Cambio de cámara',
        retiro: 'Retiro',
        reversion: 'Reversión',
    }[type] || type;
}

function updateNetworkStatus() {
    const online = navigator.onLine;
    const dot = elements.networkStatus.querySelector('.status-dot');
    elements.networkStatus.querySelector('span').textContent = online ? 'En línea' : 'Sin conexión';
    dot.className = `status-dot ${online ? 'status-dot--green' : 'status-dot--red'}`;
    updateActions();
}

async function signIn(form) {
    const values = Object.fromEntries(new FormData(form));
    values.email = values.email.trim().toLowerCase();
    values.codigo_dispositivo = values.codigo_dispositivo.trim().toUpperCase();
    elements.accessError.textContent = '';

    const data = await withBusy('Validando acceso…', () => api('/api/acceso-tablet', {
        method: 'POST',
        body: JSON.stringify(values),
    }));

    persistSession(data.token, {
        usuario: data.usuario,
        dispositivo: data.dispositivo,
    });
    form.password.value = '';
    showApplication();
    await loadApplication();
}

async function signOut() {
    try {
        await api('/api/acceso-tablet', { method: 'DELETE' });
    } catch {
        // El token se elimina localmente incluso si el servidor no está disponible.
    }

    clearSession();
}

async function loadApplication() {
    await withBusy('Cargando cámaras…', async () => {
        const [cameraResponse, conditionResponse, materialCatalog, materialDispatchResponse] = await Promise.all([
            api('/api/camaras'),
            api('/api/condiciones-sag'),
            api('/api/materiales/catalogo'),
            api('/api/materiales/despachos?estados=pendiente,parcial&vista=operacion'),
        ]);

        state.cameras = cameraResponse.data || [];
        state.conditions = conditionResponse.data || [];
        state.materialCatalog = materialCatalog || { items: [], destinos: [] };
        state.materialDispatches = materialDispatchResponse.data || [];
        renderConditions();
        renderMaterialCatalog();
        renderCameras();

        if (state.cameras.length > 0) {
            await selectCamera(state.cameras[0].id, false);
        } else {
            renderEmptyCameras();
        }
    });
}

function renderConditions() {
    const options = state.conditions.map((condition) => (
        `<option value="${escapeHtml(condition.id)}">${escapeHtml(condition.codigo)} · ${escapeHtml(condition.nombre)}</option>`
    ));

    elements.sagSelect.innerHTML = '<option value="">Sin especificar</option>' + options.join('');
}

function renderMaterialCatalog() {
    elements.materialItemSelect.innerHTML = '<option value="">Selecciona un ítem</option>'
        + state.materialCatalog.items.map((item) => (
            `<option value="${escapeHtml(item.id)}">${escapeHtml(item.codigo)} · ${escapeHtml(item.nombre)} · ${escapeHtml(item.unidad_medida)}</option>`
        )).join('');
    elements.materialDestinationSelect.innerHTML = '<option value="">Selecciona un destino</option>'
        + state.materialCatalog.destinos.map((destination) => (
            `<option value="${escapeHtml(destination.id)}">${escapeHtml(destination.nombre)} · ${escapeHtml(destination.centro_costo)}</option>`
        )).join('');
}

function renderEmptyCameras() {
    elements.cameraList.innerHTML = '<div class="recent-empty">No existen cámaras activas para mostrar.</div>';
    elements.positionMap.innerHTML = `
        <div class="empty-state">
            <div class="empty-state__icon">▦</div>
            <strong>Sin cámaras configuradas</strong>
            <span>Un administrador debe crear cámaras y posiciones antes de operar.</span>
        </div>`;
    updateActions();
}

function renderCameras() {
    if (state.cameras.length === 0) {
        renderEmptyCameras();
        return;
    }

    elements.cameraList.innerHTML = state.cameras.map((camera) => {
        const selected = camera.id === state.selectedCameraId;
        const access = camera.acceso || {};
        const locked = access.bloqueada;
        const own = access.sesion?.es_propia;
        const label = own ? 'Edición propia' : locked ? 'En uso' : 'Disponible';
        const dot = own ? 'status-dot--cyan' : locked ? 'status-dot--amber' : 'status-dot--green';
        const percentage = Number(camera.ocupacion?.porcentaje || 0);

        return `
            <button class="camera-card${selected ? ' is-active' : ''}" type="button" data-camera-id="${escapeHtml(camera.id)}">
                <span class="camera-card__top">
                    <strong>${escapeHtml(cameraDisplayName(camera))}</strong>
                    <span class="camera-card__state"><i class="status-dot ${dot}"></i>${label}</span>
                </span>
                <span class="camera-card__meta">
                    <span>${escapeHtml(statusText(camera.contenido))}</span>
                    <span>${percentage}%</span>
                </span>
                <span class="mini-progress"><i style="width:${Math.min(100, percentage)}%"></i></span>
            </button>`;
    }).join('');

    elements.cameraList.querySelectorAll('[data-camera-id]').forEach((button) => {
        button.addEventListener('click', () => selectCamera(button.dataset.cameraId));
    });
}

async function reloadCameraList() {
    const cameras = await loadCameras();
    state.cameras = cameras || [];
    renderCameras();
}

async function selectCamera(cameraId, showLoading = true) {
    state.selectedCameraId = cameraId;
    state.selectedPosition = null;
    renderCameras();

    const load = async () => {
        const [loadedPlan, recentResponse] = await Promise.all([
            loadPlan(cameraId),
            loadRecent(cameraId),
        ]);

        state.plan = loadedPlan;
        renderPlan();
        renderRecent(recentResponse || []);
        updateLastSync();
    };

    if (showLoading) {
        await withBusy('Cargando plano…', load);
    } else {
        await load();
    }
}

async function refreshCurrent({ quiet = false } = {}) {
    if (! state.selectedCameraId) return;

    const refresh = async () => {
        const cameraId = state.selectedCameraId;
        const [loadedCameras, loadedPlan, loadedMovements] = await Promise.all([
            loadCameras({ conditional: true }),
            loadPlan(cameraId, { conditional: true }),
            loadRecent(cameraId, { conditional: true }),
        ]);

        if (loadedCameras) state.cameras = loadedCameras;
        if (loadedPlan) state.plan = loadedPlan;
        renderCameras();
        renderPlan();
        if (loadedMovements) renderRecent(loadedMovements);
        updateLastSync();
    };

    if (quiet) {
        await refresh();
    } else {
        await withBusy('Actualizando plano…', refresh);
        showToast('Plano actualizado.');
    }
}

function renderPlan() {
    const plan = state.plan;

    if (! plan) return;

    elements.planBreadcrumb.textContent = plan.contenido === 'materiales'
        ? `BODEGA DE MATERIALES · ${plan.tipo.toUpperCase()}`
        : `PLANO DE ESTIBA · ${plan.tipo.toUpperCase()}`;
    elements.planTitle.textContent = cameraDisplayName(plan);
    elements.planSubtitle.textContent = `${plan.posiciones.length} posiciones configuradas`;
    elements.planVersion.textContent = `v${plan.version_plano}`;
    elements.occupancyValue.textContent = `${plan.ocupacion.porcentaje}%`;
    elements.occupancyBar.style.width = `${Math.min(100, plan.ocupacion.porcentaje)}%`;
    elements.occupancyDetail.textContent = `${plan.ocupacion.ocupadas} de ${plan.ocupacion.total} posiciones`;

    renderLockState();
    renderPositionMap();
    renderSelection();
    updateActions();
}

function renderLockState() {
    const access = state.plan?.acceso;
    const sessionChipDot = elements.globalSessionStatus.querySelector('.status-dot');

    if (access?.modo === 'solo_lectura') {
        const session = access.sesion;
        elements.lockBanner.classList.remove('is-hidden');
        elements.lockTitle.textContent = 'Cámara en modificación';
        elements.lockMessage.textContent = `${session?.usuario?.nombre || 'Otro operador'} trabaja desde ${session?.dispositivo?.nombre || 'otra tablet'}. El plano está en modo consulta.`;
    } else {
        elements.lockBanner.classList.add('is-hidden');
    }

    if (access?.modo === 'edicion') {
        elements.globalSessionStatus.classList.add('is-editing');
        sessionChipDot.className = 'status-dot status-dot--cyan';
        elements.globalSessionStatus.querySelector('span').textContent = `Editando ${cameraDisplayName(state.plan)}`;
    } else {
        elements.globalSessionStatus.classList.remove('is-editing');
        sessionChipDot.className = 'status-dot';
        elements.globalSessionStatus.querySelector('span').textContent = 'Solo consulta';
    }
}

function renderPositionMap() {
    const positions = state.plan?.posiciones || [];

    if (positions.length === 0) {
        elements.positionMap.innerHTML = `
            <div class="empty-state">
                <div class="empty-state__icon">▦</div>
                <strong>Cámara sin posiciones</strong>
                <span>Configura el plano de esta cámara antes de comenzar una estiba.</span>
            </div>`;
        return;
    }

    const levels = [...new Set(positions.map((position) => Number(position.nivel)))].sort((a, b) => a - b);
    const bands = orderBandsForCamera(
        state.plan,
        [...new Set(positions.map((position) => Number(position.banda)))],
    );
    const maxPosition = Math.max(...positions.map((position) => Number(position.posicion)));
    const operationalBands = new Map(
        (state.plan?.bandas_operacionales || []).map((band) => [Number(band.numero), band]),
    );
    const lookup = new Map(positions.map((position) => [
        `${position.nivel}|${position.banda}|${position.posicion}`,
        position,
    ]));

    elements.positionMap.innerHTML = levels.map((level) => {
        const bandColumns = bands.map((band) => {
            const operationalBand = operationalBands.get(band);
            const cells = [];
            for (let positionNumber = 1; positionNumber <= maxPosition; positionNumber += 1) {
                const position = lookup.get(`${level}|${band}|${positionNumber}`);
                cells.push(position ? renderPositionCell(position) : '<span class="position-cell--gap"></span>');
            }

            return `
                <div class="band-column${operationalBand ? ` is-${escapeHtml(operationalBand.estado)}` : ''}">
                    ${renderOperationalBandHeading(band, operationalBand)}
                    ${cells.join('')}
                </div>`;
        }).join('');

        return `
            <section class="level-group">
                <div class="level-heading">NIVEL ${level}</div>
                <div class="map-orientation"><strong>↑ FONDO</strong><span>${escapeHtml(bandNumberingLabel(state.plan))} · P01 se ocupa primero</span></div>
                <div class="band-layout">
                    ${bandColumns}
                </div>
                <div class="map-orientation map-orientation--entrance"><strong>↓ ENTRADA</strong></div>
            </section>`;
    }).join('');

    elements.positionMap.querySelectorAll('[data-position-id]').forEach((button) => {
        button.addEventListener('click', () => selectPosition(button.dataset.positionId));
    });
}

function renderOperationalBandHeading(band, operationalBand) {
    if (!operationalBand) {
        return `<strong class="band-heading">BANDA ${String(band).padStart(2, '0')}</strong>`;
    }

    const stateLabels = {
        libre: 'LIBRE',
        parcial: 'PARCIAL',
        completa: 'COMPLETA',
        bloqueada: 'BLOQUEADA',
        en_vaciado: 'EN VACIADO',
    };
    const useLabels = {
        transito_pt: 'Tránsito PT',
        inspeccion: 'Inspección',
        retenidos: 'Retenidos',
    };
    const uses = (operationalBand.usos_permitidos || [])
        .map((use) => useLabels[use] || use)
        .join(' · ');
    const affinity = operationalBandAffinityLabel(operationalBand);

    return `
        <div class="band-heading">
            <strong>BANDA ${String(band).padStart(2, '0')}</strong>
            <span>${escapeHtml(stateLabels[operationalBand.estado] || operationalBand.estado)} · ${Number(operationalBand.capacidad?.disponibles || 0)}/${Number(operationalBand.capacidad?.efectiva || 0)} libres</span>
            <small class="band-heading__affinity">${escapeHtml(affinity)}</small>
            <small>${escapeHtml(uses)}</small>
        </div>`;
}

function operationalBandAffinityLabel(operationalBand) {
    const affinity = operationalBand.afinidad;
    if (affinity?.activa || affinity?.entrantes?.pallets_completos) return bandAffinityText(operationalBand);
    return affinity?.fuera_alcance
        ? `${affinity.fuera_alcance} bulto(s) fuera del planificador`
        : 'Sin afinidad · banda liberada';
}

function renderPositionCell(position) {
    const selected = state.selectedPosition?.id === position.id;
    const blocked = position.estado !== 'activa';
    const occupied = position.ocupada;
    const reserved = position.reservada;
    const saldo = position.folio?.tipo_bulto === 'saldo';
    const material = position.folio?.tipo_bulto === 'material';
    const classes = [
        'position-cell',
        selected ? 'is-selected' : '',
        occupied ? 'is-occupied' : '',
        saldo ? 'is-saldo' : '',
        material ? 'is-material' : '',
        reserved ? 'is-reserved' : '',
        blocked ? 'is-blocked' : '',
    ].filter(Boolean).join(' ');
    const folio = position.folio?.numero_folio
        || (blocked ? 'NO DISPONIBLE' : reserved ? 'RESERVADA' : 'LIBRE');
    const detail = occupied
        ? (position.folio?.material?.item?.nombre || position.folio?.variedad || position.folio?.tipo_bulto || 'Bulto')
        : (blocked
            ? position.estado.replaceAll('_', ' ')
            : reserved
                ? position.reserva_operacional?.responsable?.nombre || 'Tarea en ejecución'
                : 'Disponible');

    return `
        <button class="${classes}" type="button" data-position-id="${escapeHtml(position.id)}">
            <span class="position-cell__location">${escapeHtml(position.etiqueta || `B${position.banda} · P${position.posicion}`)}</span>
            <strong class="position-cell__folio">${escapeHtml(folio)}</strong>
            <span class="position-cell__meta"><span>${escapeHtml(detail)}</span><span>${occupied ? (material ? 'M' : saldo ? 'S' : 'P') : reserved ? 'R' : '○'}</span></span>
        </button>`;
}

function selectPosition(positionId) {
    state.selectedPosition = state.plan?.posiciones.find((position) => position.id === positionId) || null;
    renderPositionMap();
    renderSelection();
    updateActions();
}

function renderSelection() {
    const position = state.selectedPosition;

    if (! position) {
        elements.selectedPositionLabel.textContent = 'Ninguna';
        elements.selectedPositionState.textContent = 'Toca una posición del plano';
        elements.folioCard.classList.add('is-hidden');
        elements.folioCard.classList.remove('is-saldo');
        elements.folioCard.classList.remove('is-material');
        return;
    }

    elements.selectedPositionLabel.textContent = positionLabel(position);
    elements.selectedPositionState.textContent = position.estado !== 'activa'
        ? 'Posición no disponible'
        : position.ocupada
            ? 'Ocupada por un folio'
            : position.reservada
                ? `Reservada · ${position.reserva_operacional?.responsable?.nombre || 'tarea en ejecución'}`
                : 'Libre para ubicación';

    if (! position.folio) {
        elements.folioCard.classList.add('is-hidden');
        elements.folioCard.classList.remove('is-saldo');
        elements.folioCard.classList.remove('is-material');
        return;
    }

    const folio = position.folio;
    elements.folioCard.classList.remove('is-hidden');
    elements.folioCard.classList.toggle('is-saldo', folio.tipo_bulto === 'saldo');
    elements.folioCard.classList.toggle('is-material', folio.tipo_bulto === 'material');
    elements.selectedFolioType.textContent = folio.tipo_bulto.toUpperCase();
    elements.selectedFolioNumber.textContent = folio.numero_folio;
    if (folio.tipo_bulto === 'material') {
        elements.selectedFolioVarietyLabel.textContent = 'Ítem';
        elements.selectedFolioCaliberLabel.textContent = 'Saldo';
        elements.selectedFolioSagLabel.textContent = 'Disponible';
        elements.selectedFolioVariety.textContent = folio.material?.item?.nombre || '—';
        elements.selectedFolioCaliber.textContent = `${folio.material?.cantidad_actual || '0'} ${folio.material?.unidad_medida || ''}`;
        elements.selectedFolioSag.textContent = `${folio.material?.cantidad_disponible || '0'} ${folio.material?.unidad_medida || ''}`;
    } else {
        elements.selectedFolioVarietyLabel.textContent = 'Variedad';
        elements.selectedFolioCaliberLabel.textContent = 'Calibre';
        elements.selectedFolioSagLabel.textContent = 'Condición';
        elements.selectedFolioVariety.textContent = folio.variedad || '—';
        elements.selectedFolioCaliber.textContent = folio.calibre || '—';
        elements.selectedFolioSag.textContent = folio.condicion_sag?.codigo || '—';
    }
}

function updateActions() {
    const plan = state.plan;
    const position = state.selectedPosition;
    const operating = canOperate();
    const isConsultation = state.identity?.usuario?.rol === 'consulta';

    elements.refreshPlanButton.disabled = ! plan || ! navigator.onLine;
    elements.locateButton.disabled = ! operating
        || ! position
        || position.ocupada
        || position.reservada
        || position.estado !== 'activa';
    elements.moveButton.disabled = ! operating
        || ! position?.ocupada
        || position.estado !== 'activa';
    const canDispatchMaterial = operating && position?.folio?.tipo_bulto === 'material';
    elements.materialDispatchButton.classList.toggle('is-hidden', state.plan?.contenido !== 'materiales');
    elements.materialDispatchButton.disabled = ! canDispatchMaterial;

    if (! plan) {
        elements.sessionButton.disabled = true;
        elements.actionNote.textContent = 'Selecciona una cámara para comenzar.';
        return;
    }

    if (plan.acceso.modo === 'edicion') {
        elements.sessionButton.disabled = ! navigator.onLine;
        elements.sessionButtonTitle.textContent = 'Cerrar estiba';
        elements.sessionButtonSubtitle.textContent = 'Liberar la cámara';
        elements.actionNote.textContent = position
            ? 'La cámara está en edición. Confirma cada movimiento antes de continuar.'
            : 'Sesión activa: selecciona una posición para operar.';
    } else if (plan.acceso.modo === 'disponible') {
        elements.sessionButton.disabled = isConsultation || ! navigator.onLine;
        elements.sessionButtonTitle.textContent = 'Abrir estiba';
        elements.sessionButtonSubtitle.textContent = isConsultation ? 'Tu perfil es solo consulta' : 'Iniciar sesión de edición';
        elements.actionNote.textContent = isConsultation
            ? 'Tu perfil permite revisar el plano, pero no modificarlo.'
            : 'Abre la estiba para habilitar movimientos en esta cámara.';
    } else {
        elements.sessionButton.disabled = true;
        elements.sessionButtonTitle.textContent = 'Cámara en uso';
        elements.sessionButtonSubtitle.textContent = 'Edición bloqueada';
        elements.actionNote.textContent = 'La edición se habilitará cuando el otro operador cierre su sesión.';
    }
}

async function toggleSession() {
    if (! state.plan) return;

    if (state.plan.acceso.modo === 'edicion') {
        const confirmed = window.confirm(`¿Cerrar la estiba de ${cameraDisplayName(state.plan)} y liberar la cámara?`);
        if (! confirmed) return;

        await withBusy('Cerrando estiba…', () => api(`/api/sesiones/${ownSession().id}/cerrar`, {
            method: 'POST',
            body: JSON.stringify({ motivo: 'Cierre desde interfaz tablet' }),
        }));
        showToast('Estiba cerrada y cámara liberada.');
    } else {
        await withBusy('Abriendo estiba…', () => api(`/api/camaras/${state.plan.id}/sesiones`, {
            method: 'POST',
        }));
        showToast(`Sesión de edición abierta en ${cameraDisplayName(state.plan)}.`);
    }

    await refreshCurrent({ quiet: true });
}

function openLocateDialog() {
    const position = state.selectedPosition;
    if (! position || position.ocupada || position.reservada || ! canOperate()) return;

    elements.locateForm.reset();
    elements.locateError.textContent = '';
    elements.locateDestinationText.textContent = `Destino: ${cameraDisplayName(state.plan)} · ${positionLabel(position)}`;
    renderConditions();
    renderMaterialCatalog();
    const isMaterial = state.plan.contenido === 'materiales';
    elements.locateTypeSelect.innerHTML = isMaterial
        ? '<option value="material">Material</option>'
        : '<option value="pallet">Pallet completo</option><option value="saldo">Saldo incompleto</option>';
    document.querySelectorAll('.product-locate-field').forEach((field) => {
        field.classList.toggle('is-hidden', isMaterial);
    });
    document.querySelectorAll('.material-locate-field').forEach((field) => {
        field.classList.toggle('is-hidden', ! isMaterial);
    });
    elements.materialItemSelect.required = isMaterial;
    elements.locateForm.cantidad_material.required = isMaterial;
    resetLocateLookup();
    elements.locateDialog.showModal();
    window.setTimeout(() => elements.locateForm.numero_folio.focus(), 50);
}

function normalizedFolioNumber(value) {
    return String(value || '').trim().toUpperCase();
}

function locateDetailControls() {
    const form = elements.locateForm;

    return [
        elements.locateTypeSelect,
        elements.sagSelect,
        form.variedad,
        form.calibre,
        form.marca,
        form.exportadora,
        elements.materialItemSelect,
        form.cantidad_material,
        form.lote_material,
        form.proveedor_material,
        form.observacion_material,
    ].filter(Boolean);
}

function setLocateDetailsLocked(locked) {
    locateDetailControls().forEach((control) => {
        control.disabled = locked;
    });
}

function setLocateLookupStatus(message = '', type = '') {
    elements.locateFolioLookupStatus.textContent = message;
    elements.locateFolioLookupStatus.className = `folio-lookup-status form-field--wide${type ? ` is-${type}` : ''}`;
}

function clearLocateDetailValues() {
    const form = elements.locateForm;

    elements.sagSelect.value = '';
    elements.materialItemSelect.value = '';
    [
        form.variedad,
        form.calibre,
        form.marca,
        form.exportadora,
        form.cantidad_material,
        form.lote_material,
        form.proveedor_material,
        form.observacion_material,
    ].filter(Boolean).forEach((control) => {
        control.value = '';
    });
}

function resetLocateLookup({ clearValues = false } = {}) {
    window.clearTimeout(state.locateLookupTimer);
    state.locateLookupTimer = null;
    state.locateLookup = null;
    state.locateLookupSequence += 1;
    setLocateDetailsLocked(false);
    setLocateLookupStatus();
    elements.locateSubmitButton.disabled = false;

    if (clearValues) clearLocateDetailValues();
}

function ensureSelectValue(select, value, label) {
    if (! value || [...select.options].some((option) => option.value === value)) return;

    select.add(new Option(label || value, value));
}

function applyLocateLookup(data) {
    state.locateLookup = data;

    if (! data.existe) {
        setLocateDetailsLocked(false);
        elements.locateSubmitButton.disabled = false;
        setLocateLookupStatus('Folio nuevo. Completa sus datos antes de confirmar la ubicación.');

        return;
    }

    const form = elements.locateForm;
    const compatible = state.plan.contenido === 'materiales'
        ? data.tipo_bulto === 'material'
        : data.tipo_bulto !== 'material';

    if ([...elements.locateTypeSelect.options].some((option) => option.value === data.tipo_bulto)) {
        elements.locateTypeSelect.value = data.tipo_bulto;
    }

    ensureSelectValue(
        elements.sagSelect,
        data.condicion_sag?.id,
        data.condicion_sag ? `${data.condicion_sag.codigo} · ${data.condicion_sag.nombre}` : '',
    );
    elements.sagSelect.value = data.condicion_sag?.id || '';
    form.variedad.value = data.variedad || '';
    form.calibre.value = data.calibre || '';
    form.marca.value = data.marca || '';
    form.exportadora.value = data.exportadora || '';

    if (data.material) {
        ensureSelectValue(
            elements.materialItemSelect,
            data.material.item_material_id,
            `${data.material.item.codigo} · ${data.material.item.nombre}`,
        );
        elements.materialItemSelect.value = data.material.item_material_id;
        form.cantidad_material.value = data.material.cantidad || '';
        form.lote_material.value = data.material.lote || '';
        form.proveedor_material.value = data.material.proveedor || '';
        form.observacion_material.value = data.material.observacion || '';
    }

    setLocateDetailsLocked(true);
    const available = data.disponible_ubicacion && compatible;
    elements.locateSubmitButton.disabled = ! available;
    const source = data.origen_sistema === 'validacion'
        ? 'Datos recuperados desde Validación.'
        : 'Datos recuperados del folio existente.';
    const compatibilityMessage = compatible
        ? data.mensaje_disponibilidad
        : 'El tipo de folio no corresponde a esta cámara.';
    const recommendationMessage = locateRecommendationMessage(data);
    setLocateLookupStatus(
        `${source} ${compatibilityMessage}${recommendationMessage ? ` ${recommendationMessage}` : ''}`,
        available ? 'success' : 'warning',
    );
}

function locateRecommendationMessage(data) {
    const recommendation = data.recomendacion_ubicacion;

    if (! recommendation?.aplica) return '';
    if (! recommendation.disponible || ! recommendation.mejor) {
        return `Sugerencia WMS: ${recommendation.motivo}`;
    }

    const best = recommendation.mejor;
    const position = best.posicion.etiqueta
        || `B${String(best.banda.numero).padStart(2, '0')} · P${String(best.posicion.posicion).padStart(2, '0')} · N${best.posicion.nivel}`;
    const currentDestination = state.selectedPosition?.id === best.posicion.id
        ? 'El destino seleccionado es el recomendado.'
        : `${best.camara.nombre} · Banda ${String(best.banda.numero).padStart(2, '0')} · ${position}.`;

    return `Sugerencia WMS: ${currentDestination} ${best.afinidad.motivo}`;
}

async function lookupLocateFolio(numeroFolio) {
    const normalized = normalizedFolioNumber(numeroFolio);

    window.clearTimeout(state.locateLookupTimer);
    state.locateLookupTimer = null;

    if (! normalized) {
        resetLocateLookup({ clearValues: true });

        return null;
    }

    const sequence = ++state.locateLookupSequence;
    setLocateLookupStatus('Consultando información del folio…', 'loading');
    elements.locateSubmitButton.disabled = true;

    try {
        const camera = state.plan?.id ? `&camara_id=${encodeURIComponent(state.plan.id)}` : '';
        const response = await api(`/api/movimientos/consultar-folio?numero_folio=${encodeURIComponent(normalized)}${camera}`);

        if (sequence !== state.locateLookupSequence
            || normalizedFolioNumber(elements.locateFolioNumber.value) !== normalized) {
            return null;
        }

        applyLocateLookup(response.data);

        return response.data;
    } catch (error) {
        if (sequence === state.locateLookupSequence) {
            state.locateLookup = null;
            elements.locateSubmitButton.disabled = true;
            setLocateLookupStatus('No fue posible consultar el folio. Revisa la conexión e inténtalo nuevamente.', 'warning');
            elements.locateError.textContent = error.message;
        }

        return null;
    }
}

async function locateFolio(form) {
    const position = state.selectedPosition;
    if (! position || ! ownSession()) return;

    const numeroFolio = normalizedFolioNumber(elements.locateFolioNumber.value);
    elements.locateFolioNumber.value = numeroFolio;
    const lookup = await lookupLocateFolio(numeroFolio);

    if (! lookup
        || (lookup.existe && (! lookup.disponible_ubicacion || elements.locateSubmitButton.disabled))) {
        return;
    }

    const values = Object.fromEntries(new FormData(form));
    const tipoBulto = lookup.existe ? lookup.tipo_bulto : values.tipo_bulto;
    const descriptiveData = compactObject({
        condicion_sag_id: values.condicion_sag_id,
        variedad: values.variedad?.trim(),
        calibre: values.calibre?.trim(),
        marca: values.marca?.trim(),
        exportadora: values.exportadora?.trim(),
    });
    const payload = {
        operacion_id: operationUuid(),
        numero_folio: numeroFolio,
        tipo_bulto: tipoBulto,
        posicion_destino_id: position.id,
        sesion_destino_id: ownSession().id,
        version_destino_conocida: state.plan.version_plano,
        generado_dispositivo_at: new Date().toISOString(),
        ...(! lookup.existe && Object.keys(descriptiveData).length > 0 ? { datos_folio: descriptiveData } : {}),
        ...(tipoBulto === 'material' ? {
            datos_material: compactObject({
                item_material_id: lookup.existe ? lookup.material?.item_material_id : values.item_material_id,
                cantidad: lookup.existe ? Number(lookup.material?.cantidad) : Number(values.cantidad_material),
                lote: lookup.existe ? lookup.material?.lote : values.lote_material?.trim(),
                proveedor: lookup.existe ? lookup.material?.proveedor : values.proveedor_material?.trim(),
                observacion: lookup.existe ? lookup.material?.observacion : values.observacion_material?.trim(),
            }),
        } : {}),
    };

    elements.locateError.textContent = '';

    try {
        await withBusy(
            'Registrando ubicación…',
            () => apiWithPhysicalWarnings('/api/movimientos/ubicar', payload),
        );
        elements.locateDialog.close();
        showToast(`Folio ${payload.numero_folio} ubicado correctamente.`);
        await refreshCurrent({ quiet: true });
    } catch (error) {
        elements.locateError.textContent = error.message;

        if (error.status === 409) {
            await refreshCurrent({ quiet: true });
        }
    }
}

function materialDispatchDetail(dispatch, folio) {
    return dispatch?.items?.find((detail) => detail.item.id === folio?.material?.item?.id) || null;
}

function matchingMaterialDispatches(folio = state.selectedPosition?.folio) {
    return state.materialDispatches.filter((dispatch) => {
        const detail = materialDispatchDetail(dispatch, folio);
        return detail && Number(detail.cantidad_pendiente) > 0
            && (!dispatch.asignado_a_user_id || String(dispatch.asignado_a_user_id) === String(state.identity?.usuario?.id));
    });
}

function openMaterialDispatchDialog() {
    const position = state.selectedPosition;
    const material = position?.folio?.material;
    if (! material || ! canOperate()) return;

    elements.materialDispatchForm.reset();
    state.materialCreateOperationId = operationUuid();
    state.materialWithdrawOperationId = operationUuid();
    elements.materialDispatchError.textContent = '';
    elements.materialDispatchTitle.textContent = material.item.nombre;
    elements.materialDispatchOrigin.textContent = `${position.folio.numero_folio} · ${cameraDisplayName(state.plan)} · ${positionLabel(position)}`;
    elements.materialBalance.innerHTML = `
        <div><span>SALDO ACTUAL</span><strong>${escapeHtml(material.cantidad_actual)} ${escapeHtml(material.unidad_medida)}</strong></div>
        <div><span>DISPONIBLE</span><strong>${escapeHtml(material.cantidad_disponible)} ${escapeHtml(material.unidad_medida)}</strong></div>`;
    elements.materialDispatchSelect.innerHTML = '<option value="">Nuevo despacho y retiro desde tablet</option>'
        + matchingMaterialDispatches(position.folio).map((dispatch) => (
            `<option value="${escapeHtml(dispatch.id)}">${escapeHtml(dispatch.codigo)} · ${escapeHtml(dispatch.destino.nombre)} · ${escapeHtml(dispatch.destino.centro_costo)}</option>`
        )).join('');
    renderMaterialCatalog();
    updateMaterialDispatchForm();
    elements.materialDispatchDialog.showModal();
}

function materialDispatchMaximum() {
    const folio = state.selectedPosition?.folio;
    const material = folio?.material;
    const dispatch = state.materialDispatches.find((candidate) => (
        candidate.id === elements.materialDispatchSelect.value
    ));
    const detail = materialDispatchDetail(dispatch, folio);
    const ownReservation = Number(detail?.sugerencias_fifo?.find((suggestion) => (
        suggestion.folio_id === folio?.id
    ))?.cantidad || 0);
    const folioAvailable = Number(material?.cantidad_disponible || 0) + ownReservation;

    return detail
        ? Math.min(folioAvailable, Number(detail.cantidad_pendiente))
        : folioAvailable;
}

function updateMaterialDispatchForm() {
    const folio = state.selectedPosition?.folio;
    const material = folio?.material;
    const dispatch = state.materialDispatches.find((candidate) => (
        candidate.id === elements.materialDispatchSelect.value
    ));
    const detail = materialDispatchDetail(dispatch, folio);
    const maximum = materialDispatchMaximum();
    const amountInput = elements.materialDispatchForm.cantidad;

    elements.materialDestinationField.classList.toggle('is-hidden', Boolean(dispatch));
    elements.materialDestinationSelect.required = ! dispatch;
    elements.materialAmountLabel.textContent = `Cantidad a despachar * · máximo ${maximum.toFixed(3)} ${material?.unidad_medida || ''}`;
    amountInput.max = String(maximum);

    if (! dispatch || ! detail) {
        elements.materialFifoNotice.classList.add('is-hidden');
        elements.materialFifoNotice.classList.remove('is-override');
        elements.materialFifoNotice.textContent = '';
        const reason = elements.materialDispatchForm.querySelector('[name="motivo_excepcion_fifo"]');
        reason.closest('label').classList.add('is-hidden');
        reason.required = false;
        return;
    }

    const followsFifo = detail.sugerencias_fifo[0]?.folio_id === folio.id
        && Number(amountInput.value || 0) <= Number(detail.sugerencias_fifo[0]?.cantidad || 0);
    elements.materialFifoNotice.classList.remove('is-hidden');
    elements.materialFifoNotice.classList.toggle('is-override', ! followsFifo);
    const reason = elements.materialDispatchForm.querySelector('[name="motivo_excepcion_fifo"]');
    reason.closest('label').classList.toggle('is-hidden', followsFifo);
    reason.required = ! followsFifo;
    elements.materialFifoNotice.innerHTML = followsFifo
        ? `<strong>Folio sugerido por FIFO.</strong> <span>Pendiente ${escapeHtml(detail.cantidad_pendiente)} ${escapeHtml(detail.unidad_medida)} para ${escapeHtml(dispatch.destino.nombre)}.</span>`
        : `<strong>Selección distinta de FIFO.</strong> <span>Se permitirá el retiro y quedará registrado en la trazabilidad.</span>`;
}

async function dispatchMaterial(form) {
    const position = state.selectedPosition;
    const material = position?.folio?.material;
    const session = ownSession();
    if (! material || ! session) return;

    const values = Object.fromEntries(new FormData(form));
    const amount = Number(values.cantidad);
    const maximum = materialDispatchMaximum();
    elements.materialDispatchError.textContent = '';

    if (! Number.isFinite(amount) || amount <= 0 || amount > maximum) {
        elements.materialDispatchError.textContent = `Ingresa una cantidad entre 0,001 y ${maximum.toFixed(3)} ${material.unidad_medida}.`;
        return;
    }
    if (values.despacho_id && !elements.materialFifoNotice.classList.contains('is-hidden')
        && elements.materialFifoNotice.classList.contains('is-override')
        && String(values.motivo_excepcion_fifo || '').trim().length < 5) {
        elements.materialDispatchError.textContent = 'Explica la excepción FIFO (mínimo 5 caracteres).';
        return;
    }

    try {
        await withBusy('Despachando material…', async () => {
            let dispatchId = values.despacho_id;

            if (! dispatchId) {
                if (! values.destino_material_id) {
                    throw new ApiError('Selecciona el destino y centro de costo.', 422);
                }
                const created = await api('/api/materiales/despachos', {
                    method: 'POST',
                    body: JSON.stringify({
                        operacion_id: state.materialCreateOperationId,
                        destino_material_id: values.destino_material_id,
                        items: [{
                            item_material_id: material.item.id,
                            cantidad: amount,
                        }],
                    }),
                });
                dispatchId = created.data.id;
            }

            await api(`/api/materiales/despachos/${dispatchId}/retirar`, {
                method: 'POST',
                body: JSON.stringify({
                    operacion_id: state.materialWithdrawOperationId,
                    ...(values.motivo_excepcion_fifo?.trim() ? { motivo_excepcion_fifo: values.motivo_excepcion_fifo.trim() } : {}),
                    retiros: [{
                        folio_id: position.folio.id,
                        cantidad: amount,
                        sesion_estiba_id: session.id,
                    }],
                }),
            });
        });
        elements.materialDispatchDialog.close();
        showToast(`${amount} ${material.unidad_medida} despachadas desde ${position.folio.numero_folio}.`);
        await refreshMaterialData();
        await refreshCurrent({ quiet: true });
    } catch (error) {
        elements.materialDispatchError.textContent = error.message;
        if (error.status === 409) await refreshCurrent({ quiet: true });
    }
}

async function refreshMaterialData() {
    const [catalog, dispatchResponse] = await Promise.all([
        api('/api/materiales/catalogo'),
        api('/api/materiales/despachos?estados=pendiente,parcial&vista=operacion'),
    ]);
    state.materialCatalog = catalog || { items: [], destinos: [] };
    state.materialDispatches = dispatchResponse.data || [];
    renderMaterialCatalog();
}

async function openMoveDialog() {
    const position = state.selectedPosition;
    if (! position?.folio || ! canOperate()) return;

    elements.moveForm.reset();
    elements.moveError.textContent = '';
    elements.moveDialogTitle.textContent = `Mover ${position.folio.numero_folio}`;
    elements.moveOriginText.textContent = `Origen: ${cameraDisplayName(state.plan)} · ${positionLabel(position)}`;
    elements.moveCameraSelect.innerHTML = state.cameras
        .filter((camera) => camera.estado === 'activa' && camera.contenido === state.plan.contenido)
        .map((camera) => `<option value="${escapeHtml(camera.id)}">${escapeHtml(cameraDisplayName(camera))}</option>`)
        .join('');
    elements.moveCameraSelect.value = state.plan.id;
    state.moveDestination = null;
    elements.confirmMoveButton.disabled = true;
    elements.moveDialog.showModal();
    await loadDestinationPlan(state.plan.id);
}

async function loadDestinationPlan(cameraId) {
    elements.moveDestinationGrid.innerHTML = '<div class="recent-empty">Cargando posiciones…</div>';
    elements.moveDestinationHint.textContent = 'Consultando plano';
    state.moveDestination = null;
    elements.confirmMoveButton.disabled = true;

    try {
        state.destinationPlan = cameraId === state.plan.id
            ? state.plan
            : await loadPlan(cameraId);
        renderDestinations();
    } catch (error) {
        elements.moveDestinationGrid.innerHTML = '<div class="recent-empty">No fue posible cargar el destino.</div>';
        elements.moveError.textContent = error.message;
    }
}

function renderDestinations() {
    const plan = state.destinationPlan;
    const positions = plan?.posiciones || [];
    const free = positions.filter(isAvailableDestination);

    if (plan?.acceso?.modo === 'solo_lectura') {
        elements.moveDestinationGrid.innerHTML = '<div class="recent-empty">La cámara de destino está siendo modificada por otro operador.</div>';
        elements.moveDestinationHint.textContent = 'Destino bloqueado';
        return;
    }

    if (free.length === 0) {
        elements.moveDestinationGrid.innerHTML = '<div class="recent-empty">No hay posiciones libres en esta cámara.</div>';
        elements.moveDestinationHint.textContent = 'Sin disponibilidad';
        return;
    }

    elements.moveDestinationHint.textContent = `${free.length} posiciones disponibles`;
    const levels = [...new Set(positions.map((position) => Number(position.nivel)))].sort((a, b) => a - b);
    const bands = orderBandsForCamera(
        plan,
        [...new Set(positions.map((position) => Number(position.banda)))],
    );
    const maxPosition = Math.max(...positions.map((position) => Number(position.posicion)));
    const lookup = new Map(positions.map((position) => [
        `${position.nivel}|${position.banda}|${position.posicion}`,
        position,
    ]));

    elements.moveDestinationGrid.innerHTML = levels.map((level) => {
        const bandColumns = bands.map((band) => {
            const cells = [];
            for (let positionNumber = 1; positionNumber <= maxPosition; positionNumber += 1) {
                const position = lookup.get(`${level}|${band}|${positionNumber}`);
                cells.push(position
                    ? renderDestinationPosition(position)
                    : '<span class="destination-position destination-position--gap" aria-hidden="true"></span>');
            }

            return `
                <div class="destination-band">
                    <strong class="destination-band__heading">BANDA ${String(band).padStart(2, '0')}</strong>
                    ${cells.join('')}
                </div>`;
        }).join('');

        return `
            <section class="destination-level">
                <div class="destination-level__heading">NIVEL ${level}</div>
                <div class="destination-orientation"><strong>↑ FONDO</strong><span>${escapeHtml(bandNumberingLabel(plan))} · P01 se ocupa primero</span></div>
                <div class="destination-band-layout">${bandColumns}</div>
                <div class="destination-orientation destination-orientation--entrance"><strong>↓ ENTRADA</strong></div>
            </section>`;
    }).join('');

    elements.moveDestinationGrid.querySelectorAll('[data-destination-id]').forEach((button) => {
        button.addEventListener('click', () => {
            state.moveDestination = free.find((position) => position.id === button.dataset.destinationId);
            elements.moveDestinationGrid.querySelectorAll('.destination-button').forEach((candidate) => {
                candidate.classList.toggle('is-selected', candidate === button);
            });
            elements.confirmMoveButton.disabled = false;
        });
    });
}

function isAvailableDestination(position) {
    return position.estado === 'activa'
        && ! position.ocupada
        && ! position.reservada
        && position.id !== state.selectedPosition?.id;
}

function renderDestinationPosition(position) {
    const available = isAvailableDestination(position);
    const isOrigin = position.id === state.selectedPosition?.id;
    const status = isOrigin
        ? 'ORIGEN'
        : position.ocupada
            ? `OCUPADA${position.folio?.numero_folio ? ` · ${position.folio.numero_folio}` : ''}`
            : position.reservada
                ? 'RESERVADA · TAREA EN EJECUCIÓN'
                : position.estado === 'activa' ? 'LIBRE' : 'NO DISPONIBLE';
    const content = `
        <span class="destination-position__number">P${String(position.posicion).padStart(2, '0')}</span>
        <strong>${escapeHtml(positionLabel(position))}</strong>
        <small>${escapeHtml(status)}</small>`;

    if (! available) {
        return `<div class="destination-position is-unavailable" aria-disabled="true">${content}</div>`;
    }

    return `
        <button class="destination-position destination-button" type="button" data-destination-id="${escapeHtml(position.id)}">
            ${content}
        </button>`;
}

async function ensureDestinationSession() {
    if (state.destinationPlan.id === state.plan.id) {
        return ownSession().id;
    }

    if (state.destinationPlan.acceso.modo === 'edicion'
        && state.destinationPlan.acceso.sesion?.es_propia) {
        return state.destinationPlan.acceso.sesion.id;
    }

    if (state.destinationPlan.acceso.modo !== 'disponible') {
        throw new ApiError('La cámara de destino no está disponible para edición.', 409);
    }

    const response = await api(`/api/camaras/${state.destinationPlan.id}/sesiones`, {
        method: 'POST',
    });
    state.destinationPlan.acceso = {
        modo: 'edicion',
        bloqueada: true,
        sesion: { ...response.data, es_propia: true },
    };
    showToast(`Se abrió una sesión en ${cameraDisplayName(state.destinationPlan)} para completar el traslado.`);

    return response.data.id;
}

async function moveFolio() {
    const origin = state.selectedPosition;
    const destination = state.moveDestination;
    const originSession = ownSession();

    if (! origin?.folio || ! destination || ! originSession) return;

    elements.moveError.textContent = '';

    try {
        await withBusy('Confirmando movimiento…', async () => {
            const destinationSessionId = await ensureDestinationSession();
            await apiWithPhysicalWarnings('/api/movimientos/mover', {
                    operacion_id: operationUuid(),
                    folio_id: origin.folio.id,
                    posicion_destino_id: destination.id,
                    sesion_origen_id: originSession.id,
                    sesion_destino_id: destinationSessionId,
                    version_origen_conocida: state.plan.version_plano,
                    version_destino_conocida: state.destinationPlan.version_plano,
                    generado_dispositivo_at: new Date().toISOString(),
            });
        });

        const changedCamera = state.destinationPlan.id !== state.plan.id;
        elements.moveDialog.close();
        showToast(changedCamera
            ? `Folio trasladado a ${cameraDisplayName(state.destinationPlan)}.`
            : 'Folio reubicado dentro de la cámara.');
        await refreshCurrent({ quiet: true });
    } catch (error) {
        elements.moveError.textContent = error.message;

        if (error.status === 409) {
            await refreshCurrent({ quiet: true });
        }
    }
}

function renderRecent(movements) {
    if (movements.length === 0) {
        elements.recentList.innerHTML = '<div class="recent-empty">Aún no hay movimientos registrados en esta cámara.</div>';
        return;
    }

    elements.recentList.innerHTML = movements.map((movement) => {
        const origin = movement.origen
            ? `${cameraDisplayName(movement.origen.camara)} · ${movement.origen.posicion.etiqueta || `B${movement.origen.posicion.banda}`}`
            : 'Ingreso';
        const destination = movement.destino
            ? `${cameraDisplayName(movement.destino.camara)} · ${movement.destino.posicion.etiqueta || `B${movement.destino.posicion.banda}`}`
            : 'Salida';
        const date = new Date(movement.created_at || movement.recibido_servidor_at);

        return `
            <article class="recent-item">
                <span class="recent-item__icon">${movement.tipo_movimiento === 'ubicacion_inicial' ? '＋' : '⇄'}</span>
                <span class="recent-item__copy">
                    <strong>${escapeHtml(movement.folio.numero_folio)} · ${escapeHtml(typeLabel(movement.tipo_movimiento))}</strong>
                    <span>${escapeHtml(origin)} → ${escapeHtml(destination)}</span>
                </span>
                <time datetime="${escapeHtml(date.toISOString())}">${date.toLocaleTimeString('es-CL', { hour: '2-digit', minute: '2-digit' })}</time>
            </article>`;
    }).join('');
}

function updateLastSync() {
    elements.lastSyncText.textContent = `Actualizado ${new Date().toLocaleTimeString('es-CL', { hour: '2-digit', minute: '2-digit' })}`;
}

function closeDialog(id) {
    const dialog = $(id);
    if (dialog?.open) dialog.close();
}

async function poll() {
    if (state.polling
        || ! state.token
        || ! navigator.onLine
        || document.hidden
        || elements.locateDialog.open
        || elements.moveDialog.open
        || elements.materialDispatchDialog.open) {
        return;
    }

    state.polling = true;

    try {
        await refreshCurrent({ quiet: true });
    } catch (error) {
        if (error.status !== 401) {
            console.warn('No fue posible actualizar el plano automáticamente.', error);
        }
    } finally {
        state.polling = false;
    }
}

elements.accessForm?.addEventListener('submit', async (event) => {
    event.preventDefault();

    try {
        await signIn(event.currentTarget);
    } catch (error) {
        elements.accessError.textContent = error.message;
    }
});

elements.operatorButton?.addEventListener('click', () => {
    const opening = elements.logoutButton.classList.contains('is-hidden');
    elements.logoutButton.classList.toggle('is-hidden', ! opening);
    elements.operatorButton.setAttribute('aria-expanded', String(opening));
});
elements.logoutButton?.addEventListener('click', signOut);
elements.refreshCamerasButton?.addEventListener('click', () => refreshCurrent());
elements.refreshPlanButton?.addEventListener('click', () => refreshCurrent());
elements.sessionButton?.addEventListener('click', async () => {
    try {
        await toggleSession();
    } catch (error) {
        showToast(error.message, error.status === 409 ? 'warning' : 'error');
        await refreshCurrent({ quiet: true }).catch(() => {});
    }
});
elements.locateButton?.addEventListener('click', openLocateDialog);
elements.moveButton?.addEventListener('click', openMoveDialog);
elements.materialDispatchButton?.addEventListener('click', openMaterialDispatchDialog);
elements.locateForm?.addEventListener('submit', (event) => {
    event.preventDefault();
    locateFolio(event.currentTarget);
});
elements.locateFolioNumber?.addEventListener('input', (event) => {
    elements.locateError.textContent = '';
    const hadLookup = state.locateLookup !== null;
    resetLocateLookup({ clearValues: hadLookup });
    const numeroFolio = normalizedFolioNumber(event.currentTarget.value);

    if (! numeroFolio) return;

    state.locateLookupTimer = window.setTimeout(() => {
        lookupLocateFolio(numeroFolio);
    }, 300);
});
elements.locateFolioNumber?.addEventListener('keydown', async (event) => {
    if (event.key !== 'Enter') return;

    event.preventDefault();
    elements.locateError.textContent = '';
    const lookup = await lookupLocateFolio(event.currentTarget.value);

    if (lookup?.existe && lookup.disponible_ubicacion && ! elements.locateSubmitButton.disabled) {
        elements.locateSubmitButton.focus();
    }
});
elements.locateDialog?.addEventListener('close', () => {
    resetLocateLookup();
});
elements.moveForm?.addEventListener('submit', (event) => {
    event.preventDefault();
    moveFolio();
});
elements.materialDispatchForm?.addEventListener('submit', (event) => {
    event.preventDefault();
    dispatchMaterial(event.currentTarget);
});
elements.materialDispatchSelect?.addEventListener('change', () => {
    elements.materialDispatchError.textContent = '';
    updateMaterialDispatchForm();
});
elements.materialDispatchForm?.elements.cantidad?.addEventListener('input', updateMaterialDispatchForm);
elements.moveCameraSelect?.addEventListener('change', (event) => {
    elements.moveError.textContent = '';
    loadDestinationPlan(event.currentTarget.value);
});
document.querySelectorAll('[data-close-dialog]').forEach((button) => {
    button.addEventListener('click', () => closeDialog(button.dataset.closeDialog));
});
window.addEventListener('online', async () => {
    updateNetworkStatus();
    showToast('Conexión recuperada. Actualizando datos.');
    await poll();
});
window.addEventListener('offline', () => {
    updateNetworkStatus();
    showToast('Sin conexión. Las operaciones quedan temporalmente bloqueadas.', 'warning');
});

const rememberedDeviceCode = localStorage.getItem(storageKeys.deviceCode);
if (rememberedDeviceCode && elements.accessForm) {
    elements.accessForm.codigo_dispositivo.value = rememberedDeviceCode;
}

updateNetworkStatus();

if (state.token && state.identity) {
    showApplication();
    loadApplication().catch((error) => {
        if (error.status !== 401) {
            showToast(error.message, 'error');
        }
    });
}

state.poller = createOperationalPoller(
    poll,
    {
        intervalMs: 30000,
        canRun: () => Boolean(state.token)
            && !elements.locateDialog.open
            && !elements.moveDialog.open
            && !elements.materialDispatchDialog.open,
    },
);
state.poller.start();
