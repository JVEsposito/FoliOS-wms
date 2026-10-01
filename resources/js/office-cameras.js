import { mountCameraVacating } from './shared/camera-vacating';
import { cameraDisplayName } from './shared/camera-display';
import { bandAffinityText } from './shared/camera-band-affinity';
import { canSuperviseEmergency, confirmsCameraCode, emergencyEndpoint, emergencyError } from './shared/camera-emergency';
import {
    bandNumberingLabel,
    LEFT_TO_RIGHT,
    orderBandsForCamera,
} from './shared/camera-layout';
import {
    beginOperationalCameraSnapshot,
    formatOperationalTemperature,
    operationalPositionDescription,
    operationalPositionTone,
    operationalTemperatureAverage,
} from './shared/camera-operations';

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
    accessesNav: byId('officeAccessesNav'),
    managementNav: byId('officeManagementNav'),
    romanaNav: byId('officeRomanaNav'),
    rawMaterialNav: byId('officeRawMaterialNav'),
    loadsNav: byId('officeLoadsNav'),
    materialsNav: byId('officeMaterialsNav'),
    prefrioNav: byId('officePrefrioNav'),
    moduleTabs: byId('configurationModuleTabs'),
    cameraModule: byId('cameraModuleButton'),
    dockModule: byId('dockModuleButton'),
    workspace: byId('officeWorkspace'),
    dockWorkspace: byId('dockWorkspace'),
    catalogEyebrow: byId('cameraCatalogEyebrow'),
    catalogTitle: byId('cameraCatalogTitle'),
    reload: byId('reloadOfficeButton'),
    cameraList: byId('officeCameraList'),
    nextCode: byId('nextCameraCode'),
    createForm: byId('createCameraForm'),
    createError: byId('createCameraError'),
    reset: byId('resetCameraButton'),
    cancelEdit: byId('cancelEditCameraButton'),
    deactivate: byId('deactivateCameraButton'),
    save: byId('saveCameraButton'),
    saveText: byId('saveCameraButtonText'),
    eyebrow: byId('configurationEyebrow'),
    title: byId('configurationTitle'),
    description: byId('configurationDescription'),
    codeLabel: byId('cameraCodeLabel'),
    preview: byId('cameraPreview'),
    levelTabs: byId('previewLevelTabs'),
    capacity: byId('previewCapacity'),
    active: byId('previewActive'),
    disabled: byId('previewDisabled'),
    bandDirection: byId('previewBandDirection'),
    loading: byId('officeLoading'),
    loadingText: byId('officeLoadingText'),
    toasts: byId('officeToasts'),
    dockList: byId('officeDockList'),
    reloadDocks: byId('reloadDocksButton'),
    dockConfiguration: byId('dockConfiguration'),
    dockForm: byId('dockForm'),
    dockFormError: byId('dockFormError'),
    dockFormEyebrow: byId('dockFormEyebrow'),
    dockFormTitle: byId('dockFormTitle'),
    dockFormDescription: byId('dockFormDescription'),
    nextDockCode: byId('nextDockCode'),
    cancelEditDock: byId('cancelEditDockButton'),
    saveDockText: byId('saveDockButtonText'),
    cameraOpsStatus: byId('cameraOpsStatus'),
    cameraOpsUpdatedAt: byId('cameraOpsUpdatedAt'),
    cameraOpsRefresh: byId('cameraOpsRefresh'),
    cameraOpsCount: byId('cameraOpsCount'),
    operationalCameraList: byId('operationalCameraList'),
    cameraOpsEmpty: byId('cameraOpsEmpty'),
    cameraOpsEmptyTitle: byId('cameraOpsEmptyTitle'),
    cameraOpsEmptyMessage: byId('cameraOpsEmptyMessage'),
    cameraOpsWorkspace: byId('cameraOpsWorkspace'),
    cameraOpsName: byId('cameraOpsName'),
    cameraOpsMeta: byId('cameraOpsMeta'),
    cameraOpsAccess: byId('cameraOpsAccess'),
    cameraOpsCapacity: byId('cameraOpsCapacity'),
    cameraOpsOccupied: byId('cameraOpsOccupied'),
    cameraOpsReserved: byId('cameraOpsReserved'),
    cameraOpsAvailable: byId('cameraOpsAvailable'),
    cameraOpsUnpositioned: byId('cameraOpsUnpositioned'),
    cameraOpsOccupancy: byId('cameraOpsOccupancy'),
    cameraOpsOccupancyBar: byId('cameraOpsOccupancyBar'),
    cameraBandMapSummary: byId('cameraBandMapSummary'),
    cameraBandMap: byId('cameraBandMap'),
    cameraBandRows: byId('cameraBandRows'),
    cameraBandDetail: byId('cameraBandDetail'),
    cameraEnvironmentStatus: byId('cameraEnvironmentStatus'),
    cameraTemperatureAverage: byId('cameraTemperatureAverage'),
    cameraTemperatureStart: byId('cameraTemperatureStart'),
    cameraTemperatureMiddle: byId('cameraTemperatureMiddle'),
    cameraTemperatureEnd: byId('cameraTemperatureEnd'),
    cameraEnvironmentEvidence: byId('cameraEnvironmentEvidence'),
    cameraManeuverCount: byId('cameraManeuverCount'),
    cameraManeuverList: byId('cameraManeuverList'),
    cameraRecentList: byId('cameraRecentList'),
    cameraEmergency: byId('cameraEmergency'),
    cameraEmergencyStatus: byId('cameraEmergencyStatus'),
    cameraEmergencyDeclare: byId('cameraEmergencyDeclare'),
    cameraEmergencyCancel: byId('cameraEmergencyCancel'),
    cameraEmergencyDialog: byId('cameraEmergencyDialog'),
    cameraEmergencyForm: byId('cameraEmergencyForm'),
    cameraEmergencyDialogTitle: byId('cameraEmergencyDialogTitle'),
    cameraEmergencyDialogHelp: byId('cameraEmergencyDialogHelp'),
    cameraEmergencyConfirmationLabel: byId('cameraEmergencyConfirmationLabel'),
    cameraEmergencyError: byId('cameraEmergencyError'),
    cameraEmergencySubmit: byId('cameraEmergencySubmit'),
    cameraEmergencyClose: byId('cameraEmergencyClose'),
};

const keys = {
    token: 'estiba_wms_office_token',
    identity: 'estiba_wms_office_identity',
};

const state = {
    token: localStorage.getItem(keys.token),
    identity: readJson(keys.identity),
    cameras: [],
    selectedLevel: 1,
    disabled: new Set(),
    mode: 'create',
    editingCamera: null,
    originalDimensions: null,
    activeModule: 'cameras',
    docks: [],
    dockMode: 'create',
    editingDock: null,
    operationalCameras: [],
    selectedOperationalCameraId: null,
    selectedOperationalPlan: null,
    selectedOperationalBand: null,
    operationalEnvironment: null,
    operationalMovements: [],
    operationalRequestGeneration: 0,
};

class ApiError extends Error {
    constructor(message, status) {
        super(message);
        this.status = status;
    }
}

function readJson(key) {
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

function errorMessage(data, fallback) {
    return Object.values(data?.errors || {}).flat()[0] || data?.message || fallback;
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
        const fallback = 'No fue posible completar la operación.';
        throw new ApiError((path.startsWith('/api/evacuaciones-emergencia/') || path.startsWith('/api/desocupaciones-camara/'))
            ? emergencyError(data, fallback)
            : errorMessage(data, fallback), response.status);
    }
    return data;
}

function setBusy(active, message = 'Procesando…') {
    elements.loadingText.textContent = message;
    elements.loading.classList.toggle('is-hidden', !active);
    elements.loading.setAttribute('aria-hidden', String(!active));
    elements.app.setAttribute('aria-busy', String(active));
}

function toast(message, error = false) {
    const item = document.createElement('div');
    item.className = `toast${error ? ' toast--error' : ''}`;
    item.textContent = message;
    elements.toasts.append(item);
    window.setTimeout(() => item.remove(), 4500);
}

function persistSession(payload) {
    state.token = payload.token;
    state.identity = payload.usuario;
    localStorage.setItem(keys.token, payload.token);
    localStorage.setItem(keys.identity, JSON.stringify(payload.usuario));
}

function clearSession() {
    state.token = null;
    state.identity = null;
    switchConfigurationModule('cameras');
    localStorage.removeItem(keys.token);
    localStorage.removeItem(keys.identity);
    elements.app.classList.add('is-hidden');
    elements.access.classList.remove('is-hidden');
}

function showApp() {
    elements.access.classList.add('is-hidden');
    elements.app.classList.remove('is-hidden');
    const name = state.identity?.nombre || 'Supervisor';
    elements.userName.textContent = name;
    elements.userRole.textContent = state.identity?.rol || 'oficina';
    elements.initials.textContent = name.split(/\s+/).slice(0, 2).map((part) => part[0]).join('').toUpperCase();
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
    elements.rawMaterialNav.classList.toggle(
        'is-hidden',
        state.identity?.puede_consultar_materia_prima !== true,
    );
    elements.loadsNav.classList.toggle(
        'is-hidden',
        state.identity?.puede_consultar_cargas !== true,
    );
    elements.materialsNav.classList.toggle(
        'is-hidden',
        state.identity?.puede_consultar_despachos_materiales !== true,
    );
    elements.prefrioNav.classList.toggle(
        'is-hidden',
        state.identity?.puede_consultar_prefrio !== true,
    );
    const canManageDocks = userCanManageDocks();
    elements.moduleTabs.classList.toggle('is-hidden', !canManageDocks);
    if (!canManageDocks && state.activeModule === 'docks') {
        switchConfigurationModule('cameras');
    }
    applyCreationContentScope();
    const readOnly = state.identity?.puede_configurar_camaras !== true;
    elements.workspace.classList.toggle('is-read-only', readOnly);
    elements.catalogEyebrow.textContent = readOnly ? 'CONSULTA OPERACIONAL' : 'CONFIGURACIÓN';
    elements.catalogTitle.textContent = readOnly ? 'Disponibilidad de cámaras' : 'Cámaras creadas';
}

function userCanManageDocks() {
    return state.identity?.puede_gestionar_andenes === true
        || state.identity?.puede_administrar_camaras === true;
}

function switchConfigurationModule(module) {
    if (module === 'docks' && !userCanManageDocks()) return;

    state.activeModule = module;
    const showDocks = module === 'docks';
    elements.workspace.classList.toggle('is-hidden', showDocks);
    elements.dockWorkspace.classList.toggle('is-hidden', !showDocks);
    elements.cameraModule.classList.toggle('is-active', !showDocks);
    elements.dockModule.classList.toggle('is-active', showDocks);
    elements.cameraModule.setAttribute('aria-selected', String(!showDocks));
    elements.dockModule.setAttribute('aria-selected', String(showDocks));
}

function allowedCreationContent() {
    const canCreateProducts = state.identity?.puede_crear_camaras_productos === true;
    const canCreateMaterials = state.identity?.puede_crear_camaras_materiales === true;
    const canCreateRawMaterial = state.identity?.puede_crear_camaras_materia_prima === true;
    const allowed = [
        canCreateProducts ? 'productos' : null,
        canCreateMaterials ? 'materiales' : null,
        canCreateRawMaterial ? 'materia_prima' : null,
    ].filter(Boolean);

    if (allowed.length === 1) return allowed[0];

    return null;
}

function applyCreationContentScope() {
    const content = elements.createForm.elements.contenido;
    const forced = allowedCreationContent();

    for (const option of content.options) {
        option.hidden = forced !== null && option.value !== forced;
        option.disabled = forced !== null && option.value !== forced;
    }

    if (forced && state.mode === 'create') content.value = forced;
}

function statusText(value) {
    return String(value || '')
        .replaceAll('_', ' ')
        .replace(/^./, (character) => character.toUpperCase());
}

function dimensions() {
    const data = new FormData(elements.createForm);
    return {
        bandas: Math.max(1, Math.min(40, Number(data.get('bandas')) || 1)),
        posiciones: Math.max(1, Math.min(40, Number(data.get('posiciones_por_banda')) || 1)),
        niveles: Math.max(1, Math.min(10, Number(data.get('niveles')) || 1)),
    };
}

function keyOf(band, position, level) {
    return `${band}:${position}:${level}`;
}

function labelOf(band, position, level) {
    return `B${String(band).padStart(2, '0')}-P${String(position).padStart(2, '0')}-N${level}`;
}

function normalizeDisabled() {
    const { bandas, posiciones, niveles } = dimensions();
    state.disabled = new Set([...state.disabled].filter((key) => {
        const [band, position, level] = key.split(':').map(Number);
        return band <= bandas && position <= posiciones && level <= niveles;
    }));
    if (state.selectedLevel > niveles) state.selectedLevel = niveles;
}

function renderPreview() {
    normalizeDisabled();
    const { bandas, posiciones, niveles } = dimensions();
    const total = bandas * posiciones * niveles;
    elements.capacity.textContent = String(total);
    elements.disabled.textContent = String(state.disabled.size);
    elements.active.textContent = String(total - state.disabled.size);
    const layout = {
        sentido_numeracion_bandas: elements.createForm.elements.sentido_numeracion_bandas.value,
    };
    elements.bandDirection.textContent = `${bandNumberingLabel(layout)}.`;

    elements.levelTabs.innerHTML = Array.from({ length: niveles }, (_, index) => index + 1).map((level) => `
        <button class="level-tab${state.selectedLevel === level ? ' is-active' : ''}" data-level="${level}" type="button">Nivel ${level}</button>
    `).join('');

    const visualBands = orderBandsForCamera(
        layout,
        Array.from({ length: bandas }, (_, index) => index + 1),
    );
    elements.preview.innerHTML = visualBands.map((band) => {
        const cells = Array.from({ length: posiciones }, (_, positionIndex) => positionIndex + 1).map((position) => {
            const key = keyOf(band, position, state.selectedLevel);
            const disabled = state.disabled.has(key);
            return `
                <button class="preview-position${disabled ? ' is-disabled' : ''}" data-coordinate="${key}" type="button">
                    <span>P${String(position).padStart(2, '0')}</span>
                    <strong>${labelOf(band, position, state.selectedLevel)}</strong>
                    <small>${disabled ? 'Fuera de servicio' : 'Operativa'}</small>
                </button>
            `;
        }).join('');
        return `<section class="preview-band"><h3>BANDA ${String(band).padStart(2, '0')}</h3>${cells}</section>`;
    }).join('');
}

function isOperationalCameraMode() {
    return elements.app.dataset.cameraMode === 'operacion';
}

function formatNumber(value) {
    return new Intl.NumberFormat('es-CL').format(Number(value || 0));
}

function formatPercent(value) {
    return `${Number(value || 0).toLocaleString('es-CL', { maximumFractionDigits: 1 })} %`;
}

function formatDateTime(value) {
    if (!value) return 'Sin fecha informada';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return 'Fecha no disponible';
    return new Intl.DateTimeFormat('es-CL', {
        dateStyle: 'short',
        timeStyle: 'short',
    }).format(date);
}

function operationalTone(percentage) {
    if (Number(percentage) >= 95) return 'critical';
    if (Number(percentage) >= 80) return 'warning';
    return 'success';
}

function bandTone(band) {
    if (band?.modo !== 'operativa' || band?.estado === 'bloqueada') return 'critical';
    if (!band?.acepta_nuevos_ingresos || Number(band?.capacidad?.porcentaje || 0) >= 80) return 'warning';
    return 'success';
}

function cameraAccess(camera) {
    const session = camera?.acceso?.sesion;
    if (!camera?.acceso?.bloqueada) return { tone: 'success', text: 'Disponible para operación' };
    if (session?.es_propia) return { tone: 'warning', text: 'Sesión propia en edición' };
    return {
        tone: 'warning',
        text: `En uso por ${session?.usuario?.nombre || 'otro usuario'}`,
    };
}

function allowedUses(band) {
    const uses = Array.isArray(band?.usos_permitidos) ? band.usos_permitidos : [];
    return uses.length ? uses.map(statusText).join(', ') : 'Sin restricción informada';
}

function affinityText(band) {
    return bandAffinityText(band);
}

function renderOperationalCameraList() {
    elements.cameraOpsCount.textContent = formatNumber(state.operationalCameras.length);
    if (!state.operationalCameras.length) {
        elements.operationalCameraList.innerHTML = '<div class="camera-ops-empty"><strong>Sin cámaras PT visibles</strong><span>No hay cámaras de producto terminado disponibles para este perfil.</span></div>';
        return;
    }

    elements.operationalCameraList.innerHTML = state.operationalCameras.map((camera) => {
        const occupied = Number(camera.ocupacion?.ocupadas || 0);
        const total = Number(camera.ocupacion?.total || 0);
        const percentage = Number(camera.ocupacion?.porcentaje || 0);
        const access = cameraAccess(camera);
        const selected = camera.id === state.selectedOperationalCameraId;
        return `
            <button class="camera-ops__camera${selected ? ' is-selected' : ''}" data-operational-camera="${escapeHtml(camera.id)}" type="button" aria-pressed="${selected}">
                <span class="camera-ops__camera-line"><strong>${escapeHtml(cameraDisplayName(camera))}</strong><i class="camera-ops__signal" data-tone="${operationalTone(percentage)}">${escapeHtml(formatPercent(percentage))}</i></span>
                <span class="camera-ops__camera-capacity">${formatNumber(occupied)} ocupadas · ${formatNumber(total)} efectivas</span>
                <span class="camera-ops__meter" data-tone="${operationalTone(percentage)}"><b style="width:${Math.min(100, percentage)}%"></b></span>
                <span class="camera-ops__camera-access camera-ops__signal" data-tone="${access.tone}">${escapeHtml(access.text)}</span>
            </button>
        `;
    }).join('');
}

function renderOperationalSummary(plan) {
    const bands = Array.isArray(plan?.bandas_operacionales) ? plan.bandas_operacionales : [];
    const totals = bands.reduce((result, band) => ({
        capacity: result.capacity + Number(band.capacidad?.efectiva || 0),
        occupied: result.occupied + Number(band.capacidad?.ocupadas || 0),
        reserved: result.reserved + Number(band.capacidad?.reservadas || 0),
        available: result.available + Number(band.capacidad?.disponibles || 0),
    }), { capacity: 0, occupied: 0, reserved: 0, available: 0 });
    const committed = totals.occupied + totals.reserved;
    const percentage = totals.capacity > 0 ? (committed / totals.capacity) * 100 : 0;

    elements.cameraOpsCapacity.textContent = formatNumber(totals.capacity);
    elements.cameraOpsOccupied.textContent = formatNumber(totals.occupied);
    elements.cameraOpsReserved.textContent = formatNumber(totals.reserved);
    elements.cameraOpsAvailable.textContent = formatNumber(totals.available);
    elements.cameraOpsUnpositioned.textContent = formatNumber(plan?.folios_sin_posicion?.length || 0);
    elements.cameraOpsOccupancy.textContent = formatPercent(percentage);
    elements.cameraOpsOccupancyBar.style.width = `${Math.min(100, percentage)}%`;
    elements.cameraOpsOccupancyBar.dataset.tone = operationalTone(percentage);
}

function renderOperationalEnvironment(environment) {
    const unavailable = environment?.unavailable === true;
    const item = environment?.camaras?.find((entry) => entry.camara?.id === state.selectedOperationalCameraId);
    const record = item?.ultimo_registro;
    const temperatures = record?.temperaturas;
    const average = operationalTemperatureAverage(temperatures);
    const stateText = unavailable
        ? 'Sin permiso de consulta'
        : item?.estado === 'vigente'
            ? 'Vigente'
            : item?.estado === 'vencido'
                ? 'Control vencido'
                : 'Pendiente';
    const tone = item?.estado === 'vigente' ? 'success' : unavailable ? 'neutral' : 'warning';

    elements.cameraEnvironmentStatus.textContent = stateText;
    elements.cameraEnvironmentStatus.dataset.tone = tone;
    elements.cameraTemperatureStart.textContent = formatOperationalTemperature(temperatures?.inicio_c);
    elements.cameraTemperatureMiddle.textContent = formatOperationalTemperature(temperatures?.medio_c);
    elements.cameraTemperatureEnd.textContent = formatOperationalTemperature(temperatures?.fondo_c);
    elements.cameraTemperatureAverage.textContent = formatOperationalTemperature(average);
    elements.cameraEnvironmentEvidence.textContent = unavailable
        ? 'El perfil actual no puede consultar el control ambiental.'
        : record
            ? `Capturado ${formatDateTime(record.capturado_at)} · vigente hasta ${formatDateTime(record.vigente_hasta)}${record.registrado_por?.nombre ? ` · ${record.registrado_por.nombre}` : ''}`
            : 'No existe una lectura disponible para esta cámara.';
}

function bandPositions(plan, number) {
    return (plan?.posiciones || [])
        .filter((position) => Number(position.banda) === Number(number))
        .sort((left, right) => Number(right.posicion) - Number(left.posicion) || Number(left.nivel) - Number(right.nivel));
}

function renderOperationalBandDetail(band) {
    if (!band) {
        elements.cameraBandDetail.innerHTML = '<div class="camera-ops-empty">Selecciona una banda del plano o de la tabla.</div>';
        return;
    }
    const capacity = band.capacidad || {};
    const tone = bandTone(band);
    elements.cameraBandDetail.innerHTML = `
        <div class="camera-ops__band-detail-heading">
            <div><span>BANDA SELECCIONADA</span><strong>B${String(band.numero).padStart(2, '0')}</strong></div>
            <span class="camera-ops__signal" data-tone="${tone}">${escapeHtml(statusText(band.estado))}</span>
        </div>
        <dl class="camera-ops__band-facts">
            <div><dt>Capacidad</dt><dd>${formatNumber(capacity.ocupadas)} ocupadas + ${formatNumber(capacity.reservadas)} reservadas / ${formatNumber(capacity.efectiva)} efectivas</dd></div>
            <div><dt>Disponible</dt><dd>${formatNumber(capacity.disponibles)} posiciones · ${escapeHtml(formatPercent(capacity.porcentaje))} comprometido</dd></div>
            <div><dt>Usos</dt><dd>${escapeHtml(allowedUses(band))}</dd></div>
            <div><dt>Afinidad</dt><dd>${escapeHtml(affinityText(band))}</dd></div>
            <div><dt>Ingresos</dt><dd>${band.acepta_nuevos_ingresos ? 'Acepta nuevos ingresos' : 'No acepta nuevos ingresos'}</dd></div>
        </dl>
        ${band.motivo_estado ? `<p class="camera-ops__band-reason">${escapeHtml(band.motivo_estado)}</p>` : ''}
    `;
}

function renderOperationalBands(plan) {
    const bands = [...(plan?.bandas_operacionales || [])].sort((left, right) => Number(left.numero) - Number(right.numero));
    const visualBands = orderBandsForCamera(plan, bands, (band) => band.numero);
    elements.cameraBandMapSummary.textContent = `${formatNumber(bands.length)} bandas · ${formatNumber(plan?.posiciones?.length || 0)} posiciones`;
    if (!bands.length) {
        elements.cameraBandMap.innerHTML = '<div class="camera-ops-empty">Esta cámara no tiene bandas operacionales informadas.</div>';
        elements.cameraBandRows.innerHTML = '<tr><td colspan="5">Sin bandas operacionales.</td></tr>';
        renderOperationalBandDetail(null);
        return;
    }

    if (!bands.some((band) => Number(band.numero) === Number(state.selectedOperationalBand))) {
        state.selectedOperationalBand = Number(bands[0].numero);
    }

    elements.cameraBandMap.innerHTML = `
        <div class="camera-ops__orientation"><strong>↑ FONDO</strong><span>${escapeHtml(bandNumberingLabel(plan))} · posiciones físicas informadas por el WMS</span></div>
        <div class="camera-ops__bands">${visualBands.map((band) => {
            const capacity = band.capacidad || {};
            const positions = bandPositions(plan, band.numero);
            const selected = Number(band.numero) === Number(state.selectedOperationalBand);
            return `
                <button class="camera-ops__band${selected ? ' is-selected' : ''}" data-operational-band="${Number(band.numero)}" type="button" aria-pressed="${selected}">
                    <span class="camera-ops__band-heading"><strong>B${String(band.numero).padStart(2, '0')}</strong><i class="camera-ops__signal" data-tone="${bandTone(band)}">${escapeHtml(statusText(band.estado))}</i></span>
                    <span class="camera-ops__band-capacity">${formatNumber(capacity.ocupadas)} ocupadas · ${formatNumber(capacity.reservadas)} reservadas · ${formatNumber(capacity.disponibles)} libres</span>
                    <span class="camera-ops__positions">${positions.length ? positions.map((position) => `
                        <span class="camera-ops__position" data-tone="${operationalPositionTone(position)}" title="${escapeHtml(position.etiqueta || '')}">
                            <span>${escapeHtml(position.etiqueta || `Posición ${position.posicion} · Nivel ${position.nivel}`)}</span>
                            <strong>${escapeHtml(operationalPositionDescription(position))}</strong>
                        </span>
                    `).join('') : '<span class="camera-ops-empty">Sin posiciones</span>'}</span>
                    <span class="camera-ops__band-foot">${escapeHtml(affinityText(band))}</span>
                </button>
            `;
        }).join('')}</div>
        <div class="camera-ops__orientation camera-ops__orientation--entrance"><strong>↓ ENTRADA</strong><span>La vista representa estado, no coordenadas métricas.</span></div>
    `;

    elements.cameraBandRows.innerHTML = bands.map((band) => {
        const capacity = band.capacidad || {};
        const selected = Number(band.numero) === Number(state.selectedOperationalBand);
        return `
            <tr class="${selected ? 'is-selected' : ''}">
                <td><button data-operational-band="${Number(band.numero)}" type="button">B${String(band.numero).padStart(2, '0')}</button></td>
                <td><span class="camera-ops__signal" data-tone="${bandTone(band)}">${escapeHtml(statusText(band.estado))}</span><small>${escapeHtml(statusText(band.modo))}</small></td>
                <td><strong>${formatNumber(Number(capacity.ocupadas || 0) + Number(capacity.reservadas || 0))} / ${formatNumber(capacity.efectiva)}</strong><small>${escapeHtml(formatPercent(capacity.porcentaje))} comprometido</small></td>
                <td>${escapeHtml(allowedUses(band))}</td>
                <td>${escapeHtml(affinityText(band))}</td>
            </tr>
        `;
    }).join('');
    renderOperationalBandDetail(bands.find((band) => Number(band.numero) === Number(state.selectedOperationalBand)));
}

function renderOperationalManeuvers(plan) {
    const reservations = (plan?.posiciones || [])
        .filter((position) => position.reserva_operacional)
        .sort((left, right) => String(left.reserva_operacional.vence_at || '').localeCompare(String(right.reserva_operacional.vence_at || '')));
    elements.cameraManeuverCount.textContent = formatNumber(reservations.length);
    elements.cameraManeuverList.innerHTML = reservations.length ? reservations.map((position) => {
        const reservation = position.reserva_operacional;
        return `
            <article class="camera-ops__event">
                <i class="camera-ops__event-marker camera-ops__signal" data-tone="warning"></i>
                <div><strong>${escapeHtml(position.etiqueta || `Banda ${position.banda}, posición ${position.posicion}`)}</strong><p>Tarea ${escapeHtml(reservation.tarea_movimiento_id || 'sin identificador')} · prioridad ${escapeHtml(statusText(reservation.prioridad || 'no informada'))}</p><small>${escapeHtml(reservation.responsable?.nombre || 'Sin responsable')} · vence ${escapeHtml(formatDateTime(reservation.vence_at))}</small></div>
            </article>
        `;
    }).join('') : '<div class="camera-ops-empty"><strong>Sin maniobras activas</strong><span>No existen posiciones reservadas en esta cámara.</span></div>';
}

function movementRoute(movement) {
    const origin = movement?.origen;
    const destination = movement?.destino;
    const end = (value) => value?.posicion?.etiqueta || value?.camara?.codigo || 'Exterior';
    return `${end(origin)} → ${end(destination)}`;
}

function renderOperationalMovements(movements) {
    elements.cameraRecentList.innerHTML = movements.length ? movements.map((movement) => `
        <article class="camera-ops__event">
            <i class="camera-ops__event-marker camera-ops__signal" data-tone="info"></i>
            <div><strong>${escapeHtml(statusText(movement.tipo_movimiento))} · ${escapeHtml(movement.folio?.numero_folio || 'Folio no informado')}</strong><p>${escapeHtml(movementRoute(movement))}</p><small>${escapeHtml(movement.usuario?.nombre || 'Usuario no informado')} · ${escapeHtml(formatDateTime(movement.created_at))}</small></div>
        </article>
    `).join('') : '<div class="camera-ops-empty"><strong>Sin eventos recientes</strong><span>No hay movimientos registrados para esta cámara.</span></div>';
}

function showOperationalPlaceholder(title, message) {
    elements.cameraOpsEmptyTitle.textContent = title;
    elements.cameraOpsEmptyMessage.textContent = message;
    elements.cameraOpsEmpty.classList.remove('is-hidden');
    elements.cameraOpsWorkspace.classList.add('is-hidden');
}

function renderSelectedOperationalCamera() {
    const plan = state.selectedOperationalPlan;
    if (!plan) return;
    const access = cameraAccess(plan);
    elements.cameraOpsEmpty.classList.add('is-hidden');
    elements.cameraOpsWorkspace.classList.remove('is-hidden');
    elements.cameraOpsName.textContent = cameraDisplayName(plan);
    const unclosed = Number(plan.registros_sin_cerrar || 0);
    elements.cameraOpsMeta.textContent = `${statusText(plan.tipo)} · ${statusText(plan.contenido)} · versión de plano ${plan.version_plano}${unclosed ? ` · ${formatNumber(unclosed)} ${unclosed === 1 ? 'registro' : 'registros'} sin cerrar de otra temporada` : ''}`;
    elements.cameraOpsAccess.textContent = access.text;
    elements.cameraOpsAccess.dataset.tone = access.tone;
    cameraVacating.render(plan);
    renderEmergency(plan);
    renderOperationalSummary(plan);
    renderOperationalBands(plan);
    renderOperationalManeuvers(plan);
    renderOperationalMovements(state.operationalMovements);
    renderOperationalEnvironment(state.operationalEnvironment);
}

function renderEmergency(plan) {
    const emergency = plan.emergencia;
    const supervisor = canSuperviseEmergency(state.identity);
    elements.cameraEmergency.dataset.tone = emergency ? 'critical' : 'neutral';
    elements.cameraEmergencyDeclare.hidden = !supervisor || Boolean(emergency);
    elements.cameraEmergencyCancel.hidden = !supervisor || !emergency;
    if (!emergency) {
        elements.cameraEmergencyStatus.textContent = 'Sin evacuación de emergencia activa.';
        return;
    }
    const progress = `${formatNumber(emergency.pallets_evacuados)} de ${formatNumber(emergency.pallets_objetivo)} pallets evacuados · ${formatPercent(emergency.porcentaje_actual)}`;
    elements.cameraEmergencyStatus.innerHTML = `<strong>Evacuación de emergencia activa en ${escapeHtml(plan.codigo)}</strong><span>${escapeHtml(emergency.motivo)} · ${escapeHtml(emergency.declarada_por || 'Supervisor no informado')} · ${escapeHtml(formatDateTime(emergency.declarada_at))}</span><span>${escapeHtml(progress)}</span>`;
}

function openEmergencyDialog(action) {
    const plan = state.selectedOperationalPlan;
    if (!plan || !canSuperviseEmergency(state.identity)) return;
    elements.cameraEmergencyForm.reset();
    elements.cameraEmergencyError.textContent = '';
    elements.cameraEmergencyDialog.dataset.action = action;
    const declaring = action === 'declarar';
    elements.cameraEmergencyDialogTitle.textContent = declaring ? 'Declarar evacuación de emergencia' : 'Cancelar emergencia';
    elements.cameraEmergencyDialogHelp.textContent = declaring
        ? `La acción es inmediata. Escribe ${plan.codigo} para confirmarla.`
        : 'Indica el motivo de la cancelación.';
    elements.cameraEmergencyConfirmationLabel.hidden = !declaring;
    elements.cameraEmergencyForm.elements.confirmacion.required = declaring;
    elements.cameraEmergencySubmit.textContent = declaring ? 'Declarar emergencia' : 'Cancelar emergencia';
    elements.cameraEmergencyDialog.showModal();
}

async function loadOperationalCamera(id) {
    const requestGeneration = ++state.operationalRequestGeneration;
    beginOperationalCameraSnapshot(state, id);
    renderOperationalCameraList();
    showOperationalPlaceholder(
        'Cargando cámara…',
        'Consultando plano, reservas, control ambiental y movimientos recientes.',
    );
    elements.cameraOpsStatus.textContent = 'Consultando cámara…';
    setBusy(true, 'Cargando operación de la cámara…');
    try {
        const [planResponse, movementsResponse, environmentResponse] = await Promise.all([
            api(`/api/camaras/${id}/plano`),
            api(`/api/movimientos/recientes?camara_id=${encodeURIComponent(id)}&limite=8`).catch((error) => {
                if (error.status !== 403) throw error;
                return { data: [] };
            }),
            api(`/api/control-ambiental/estado?camara_id=${encodeURIComponent(id)}`).catch((error) => {
                if (error.status !== 403) throw error;
                return { unavailable: true, camaras: [] };
            }),
        ]);
        if (requestGeneration !== state.operationalRequestGeneration) return;
        state.selectedOperationalPlan = planResponse.data;
        state.operationalMovements = movementsResponse.data || [];
        state.operationalEnvironment = environmentResponse;
        renderSelectedOperationalCamera();
        elements.cameraOpsStatus.textContent = 'Información vigente';
        elements.cameraOpsUpdatedAt.textContent = `Última lectura: ${formatDateTime(new Date().toISOString())}`;
    } catch (error) {
        if (requestGeneration !== state.operationalRequestGeneration) return;
        elements.cameraOpsStatus.textContent = 'No fue posible actualizar';
        showOperationalPlaceholder(
            'No fue posible cargar la cámara',
            'La información anterior se descartó para evitar mostrar datos de otra cámara.',
        );
        toast(error.message, true);
    } finally {
        if (requestGeneration === state.operationalRequestGeneration) setBusy(false);
    }
}

async function loadOperationalCameras() {
    elements.cameraOpsStatus.textContent = 'Consultando cámaras…';
    const response = await api('/api/camaras');
    state.operationalCameras = (response.data || []).filter((camera) => camera.contenido === 'productos');
    if (!state.operationalCameras.some((camera) => camera.id === state.selectedOperationalCameraId)) {
        state.selectedOperationalCameraId = state.operationalCameras[0]?.id || null;
        state.selectedOperationalBand = null;
    }
    renderOperationalCameraList();
    if (state.selectedOperationalCameraId) {
        await loadOperationalCamera(state.selectedOperationalCameraId);
    } else {
        showOperationalPlaceholder(
            'Sin cámaras PT visibles',
            'No hay cámaras de producto terminado disponibles para este perfil.',
        );
        elements.cameraOpsStatus.textContent = 'Sin cámaras PT visibles';
        elements.cameraOpsUpdatedAt.textContent = 'Última lectura: —';
    }
}

function renderCameras() {
    if (!state.cameras.length) {
        elements.cameraList.innerHTML = '<div class="empty-state">Aún no existen cámaras configuradas.</div>';
        return;
    }

    const canConfigure = state.identity?.puede_configurar_camaras === true;
    const operationalMode = elements.app.dataset.cameraMode === 'operacion';
    if (!canConfigure) {
        elements.cameraList.innerHTML = state.cameras.map((camera) => {
            const occupied = Number(camera.ocupacion?.ocupadas || 0);
            const total = Number(camera.ocupacion?.total || 0);
            const available = Math.max(0, total - occupied);
            const percentage = total > 0 ? Math.min(100, (occupied / total) * 100) : 0;
            const session = camera.acceso?.sesion;
            const accessText = camera.acceso?.bloqueada
                ? `En edición por ${session?.usuario?.nombre || 'otro usuario'}`
                : 'Disponible para operación';
            const displayName = cameraDisplayName(camera);

            return `
                <article class="camera-item camera-item--consultation">
                    <div><strong>${escapeHtml(operationalMode ? displayName : camera.codigo)}</strong><span class="state-dot" title="Activa"></span></div>
                    ${operationalMode ? '' : `<h3>${escapeHtml(displayName)}</h3>`}
                    <p>${escapeHtml(statusText(camera.tipo))} · ${escapeHtml(statusText(camera.contenido))} · ${escapeHtml(accessText)}</p>
                    <div class="camera-availability">
                        <div><strong>${available}</strong><span>libres</span></div>
                        <div><strong>${occupied}</strong><span>ocupadas</span></div>
                        <div><strong>${total}</strong><span>totales</span></div>
                    </div>
                    <div class="camera-occupancy" aria-label="${escapeHtml(`${occupied} de ${total} posiciones ocupadas`)}">
                        <span style="width: ${percentage}%"></span>
                    </div>
                </article>
            `;
        }).join('');
        return;
    }

    const canAdminister = state.identity?.puede_administrar_camaras === true;
    const canSupervise = state.identity?.capacidades?.puede_supervisar === true;
    elements.cameraList.innerHTML = state.cameras.map((camera) => {
        const displayName = cameraDisplayName(camera);

        return `
            <article class="camera-item${camera.estado === 'inactiva' ? ' is-inactive' : ''}">
                <div><strong>${escapeHtml(operationalMode ? displayName : camera.codigo)}</strong><span class="state-dot${camera.estado === 'inactiva' ? ' is-inactive' : ''}" title="${camera.estado === 'inactiva' ? 'Inactiva' : 'Activa'}"></span></div>
                ${operationalMode ? '' : `<h3>${escapeHtml(displayName)}</h3>`}
                <p>${escapeHtml(statusText(camera.contenido))} · ${camera.dimensiones.bandas} bandas · ${camera.dimensiones.posiciones_por_banda} posiciones · ${camera.dimensiones.niveles} niveles · ${escapeHtml(bandNumberingLabel(camera))}</p>
                <div class="camera-item__capacity"><span>${camera.capacidad.activas} operativas</span><span>${camera.capacidad.ocupadas} ocupadas</span></div>
                ${(canAdminister || (canSupervise && camera.acceso?.bloqueada)) ? `<div class="camera-item__actions">${canAdminister ? `<button data-edit-camera="${camera.id}" type="button">Editar cámara</button>` : ''}${canSupervise && camera.acceso?.bloqueada ? `<button data-force-close-session="${camera.acceso.sesion?.id || ''}" type="button">Cerrar sesión forzosamente</button>` : ''}</div>` : ''}
            </article>
        `;
    }).join('');
}

function resetForm() {
    state.mode = 'create';
    state.editingCamera = null;
    state.originalDimensions = null;
    state.disabled.clear();
    state.selectedLevel = 1;
    elements.createForm.reset();
    elements.createForm.elements.bandas.value = 3;
    elements.createForm.elements.posiciones_por_banda.value = 4;
    elements.createForm.elements.niveles.value = 2;
    elements.createForm.elements.sentido_numeracion_bandas.value = LEFT_TO_RIGHT;
    elements.createForm.elements.contenido.value = allowedCreationContent() || 'productos';
    applyCreationContentScope();
    elements.eyebrow.textContent = 'NUEVO PLANO';
    elements.title.textContent = 'Crear cámara';
    elements.description.textContent = 'Define la estructura y revisa cada banda antes de confirmar.';
    elements.codeLabel.textContent = 'PRÓXIMO CÓDIGO';
    elements.saveText.textContent = 'Crear cámara y posiciones';
    elements.cancelEdit.classList.add('is-hidden');
    elements.deactivate.classList.add('is-hidden');
    elements.createError.textContent = '';
    renderPreview();
}

function editForm(camera) {
    state.mode = 'edit';
    state.editingCamera = camera;
    state.originalDimensions = { ...camera.dimensiones };
    state.selectedLevel = 1;
    state.disabled = new Set((camera.posiciones_fuera_servicio || []).map((coordinate) => keyOf(
        coordinate.banda,
        coordinate.posicion,
        coordinate.nivel,
    )));
    elements.createForm.elements.nombre.value = camera.nombre;
    elements.createForm.elements.tipo.value = camera.tipo;
    elements.createForm.elements.contenido.value = camera.contenido;
    elements.createForm.elements.bandas.value = camera.dimensiones.bandas;
    elements.createForm.elements.posiciones_por_banda.value = camera.dimensiones.posiciones_por_banda;
    elements.createForm.elements.niveles.value = camera.dimensiones.niveles;
    elements.createForm.elements.sentido_numeracion_bandas.value = camera.sentido_numeracion_bandas || LEFT_TO_RIGHT;
    elements.eyebrow.textContent = 'ADMINISTRAR PLANO';
    elements.title.textContent = `Editar ${camera.codigo}`;
    elements.description.textContent = 'Los cambios de tamaño conservan el historial de todas las posiciones retiradas.';
    elements.codeLabel.textContent = 'CÓDIGO INMUTABLE';
    elements.nextCode.textContent = camera.codigo;
    elements.saveText.textContent = 'Guardar cambios';
    elements.cancelEdit.classList.remove('is-hidden');
    elements.deactivate.classList.remove('is-hidden');
    elements.deactivate.textContent = camera.estado === 'activa' ? 'Desactivar cámara' : 'Reactivar cámara';
    elements.createError.textContent = '';
    renderPreview();
    document.querySelector('.configuration')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

async function loadCameraForEdit(id) {
    setBusy(true, 'Cargando plano de la cámara…');
    try {
        const response = await api(`/api/configuracion/camaras/${id}`);
        editForm(response.data);
    } catch (error) {
        toast(error.message, true);
    } finally {
        setBusy(false);
    }
}

async function loadConfiguration() {
    if (isOperationalCameraMode()) {
        await loadOperationalCameras();
        return;
    }

    if (state.identity?.puede_configurar_camaras !== true) {
        const cameras = await api('/api/camaras');
        state.cameras = cameras.data;
        renderCameras();
        return;
    }

    const [cameras, next, operationalCameras] = await Promise.all([
        api('/api/configuracion/camaras'),
        api('/api/configuracion/camaras/siguiente-codigo'),
        api('/api/camaras'),
    ]);
    const operationalById = new Map(
        operationalCameras.data.map((camera) => [camera.id, camera]),
    );
    state.cameras = cameras.data.map((camera) => ({
        ...camera,
        acceso: operationalById.get(camera.id)?.acceso || null,
    }));
    elements.nextCode.textContent = next.data.codigo;
    renderCameras();
}

function suggestedDockCode() {
    const lastNumber = state.docks.reduce((highest, dock) => {
        const match = String(dock.codigo || '').match(/^AND-(\d+)$/i);
        return match ? Math.max(highest, Number(match[1])) : highest;
    }, 0);

    return `AND-${String(lastNumber + 1).padStart(2, '0')}`;
}

function renderDocks() {
    if (!state.docks.length) {
        elements.dockList.innerHTML = '<div class="empty-state">Aún no existen andenes. Crea el primero desde el formulario.</div>';
        return;
    }

    elements.dockList.innerHTML = state.docks.map((dock) => `
        <article class="camera-item dock-item${dock.activo ? '' : ' is-inactive'}">
            <div><strong>${escapeHtml(dock.codigo)}</strong><span class="state-dot${dock.activo ? '' : ' is-inactive'}" title="${dock.activo ? 'Activo' : 'Inactivo'}"></span></div>
            <h3>${escapeHtml(dock.nombre)}</h3>
            <p>${dock.codigo_externo ? `Código externo: ${escapeHtml(dock.codigo_externo)}` : 'Sin código externo'}</p>
            <div class="dock-item__status"><span>${dock.activo ? 'Disponible para cargas' : 'Fuera de operación'}</span></div>
            <div class="camera-item__actions"><button data-edit-dock="${dock.id}" type="button">Editar andén</button></div>
        </article>
    `).join('');
}

function resetDockForm() {
    state.dockMode = 'create';
    state.editingDock = null;
    elements.dockForm.reset();
    elements.dockForm.elements.codigo.value = suggestedDockCode();
    elements.dockForm.elements.activo.checked = true;
    elements.dockFormEyebrow.textContent = 'NUEVO ANDÉN';
    elements.dockFormTitle.textContent = 'Crear andén';
    elements.dockFormDescription.textContent = 'Registra los puntos físicos donde se concentran y despachan las cargas.';
    elements.nextDockCode.parentElement.querySelector('span').textContent = 'CÓDIGO SUGERIDO';
    elements.nextDockCode.textContent = suggestedDockCode();
    elements.saveDockText.textContent = 'Crear andén';
    elements.cancelEditDock.classList.add('is-hidden');
    elements.dockFormError.textContent = '';
}

function editDockForm(dock) {
    state.dockMode = 'edit';
    state.editingDock = dock;
    elements.dockForm.elements.codigo.value = dock.codigo;
    elements.dockForm.elements.nombre.value = dock.nombre;
    elements.dockForm.elements.codigo_externo.value = dock.codigo_externo || '';
    elements.dockForm.elements.activo.checked = dock.activo;
    elements.dockFormEyebrow.textContent = 'ADMINISTRAR ANDÉN';
    elements.dockFormTitle.textContent = `Editar ${dock.codigo}`;
    elements.dockFormDescription.textContent = 'Actualiza su identificación o cambia su disponibilidad operacional.';
    elements.nextDockCode.parentElement.querySelector('span').textContent = 'ESTADO ACTUAL';
    elements.nextDockCode.textContent = dock.activo ? 'ACTIVO' : 'INACTIVO';
    elements.saveDockText.textContent = 'Guardar cambios';
    elements.cancelEditDock.classList.remove('is-hidden');
    elements.dockFormError.textContent = '';
    elements.dockConfiguration.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

async function loadDocks() {
    const response = await api('/api/andenes?incluir_inactivos=1');
    state.docks = response.data;
    renderDocks();

    if (state.dockMode === 'edit' && state.editingDock) {
        const updated = state.docks.find((dock) => dock.id === state.editingDock.id);
        updated ? editDockForm(updated) : resetDockForm();
        return;
    }

    resetDockForm();
}

async function openConfigurationModule(module) {
    switchConfigurationModule(module);
    if (module !== 'docks') return;

    setBusy(true, 'Cargando andenes…');
    try {
        await loadDocks();
    } catch (error) {
        toast(error.message, true);
    } finally {
        setBusy(false);
    }
}

elements.loginForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    elements.loginError.textContent = '';
    const data = Object.fromEntries(new FormData(elements.loginForm));
    setBusy(true, 'Validando acceso…');
    try {
        const payload = await api('/api/acceso-oficina', { method: 'POST', body: JSON.stringify(data) });
        persistSession(payload);
        showApp();
        await loadConfiguration();
    } catch (error) {
        elements.loginError.textContent = error.message;
    } finally {
        setBusy(false);
    }
});

elements.createForm.addEventListener('input', (event) => {
    if (['bandas', 'posiciones_por_banda', 'niveles', 'sentido_numeracion_bandas'].includes(event.target.name)) renderPreview();
});

elements.levelTabs.addEventListener('click', (event) => {
    const button = event.target.closest('[data-level]');
    if (!button) return;
    state.selectedLevel = Number(button.dataset.level);
    renderPreview();
});

elements.preview.addEventListener('click', (event) => {
    const button = event.target.closest('[data-coordinate]');
    if (!button) return;
    const key = button.dataset.coordinate;
    state.disabled.has(key) ? state.disabled.delete(key) : state.disabled.add(key);
    renderPreview();
});

elements.reset.addEventListener('click', () => {
    if (state.mode === 'edit' && state.editingCamera) {
        editForm(state.editingCamera);
        return;
    }
    state.disabled.clear();
    state.selectedLevel = 1;
    renderPreview();
});

elements.cancelEdit.addEventListener('click', async () => {
    resetForm();
    await loadConfiguration();
});

elements.cameraList.addEventListener('click', (event) => {
    const forceButton = event.target.closest('[data-force-close-session]');
    if (forceButton) {
        const sessionId = forceButton.dataset.forceCloseSession;
        const reason = window.prompt('Indica el motivo del cierre forzoso:');
        if (reason === null) return;
        const normalizedReason = reason.trim();
        if (normalizedReason.length < 3) {
            toast('El motivo debe contener al menos 3 caracteres.', true);
            return;
        }
        void (async () => {
            setBusy(true, 'Cerrando sesión…');
            try {
                await api(`/api/sesiones/${sessionId}/cerrar-forzosamente`, {
                    method: 'POST',
                    body: JSON.stringify({ motivo: normalizedReason }),
                });
                await loadConfiguration();
                toast('Sesión cerrada y cámara liberada.');
            } catch (error) {
                toast(error.message, true);
            } finally {
                setBusy(false);
            }
        })();
        return;
    }

    const button = event.target.closest('[data-edit-camera]');
    if (!button) return;
    void loadCameraForEdit(button.dataset.editCamera);
});

elements.createForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    elements.createError.textContent = '';
    const form = new FormData(elements.createForm);
    const payload = {
        nombre: String(form.get('nombre') || '').trim(),
        tipo: form.get('tipo'),
        contenido: form.get('contenido'),
        bandas: Number(form.get('bandas')),
        posiciones_por_banda: Number(form.get('posiciones_por_banda')),
        niveles: Number(form.get('niveles')),
        sentido_numeracion_bandas: form.get('sentido_numeracion_bandas'),
        posiciones_fuera_servicio: [...state.disabled].map((key) => {
            const [banda, posicion, nivel] = key.split(':').map(Number);
            return { banda, posicion, nivel };
        }),
    };

    if (state.mode === 'edit' && state.originalDimensions) {
        const shrinks = payload.bandas < state.originalDimensions.bandas
            || payload.posiciones_por_banda < state.originalDimensions.posiciones_por_banda
            || payload.niveles < state.originalDimensions.niveles;
        if (shrinks && !window.confirm('El nuevo plano es menor. Las posiciones retiradas conservarán su historial y una posición ocupada impedirá el cambio. ¿Deseas continuar?')) {
            return;
        }
    }

    setBusy(true, state.mode === 'edit' ? 'Guardando cambios…' : 'Creando cámara y posiciones…');
    try {
        const editing = state.mode === 'edit';
        const path = editing
            ? `/api/configuracion/camaras/${state.editingCamera.id}`
            : '/api/configuracion/camaras';
        const response = await api(path, {
            method: editing ? 'PUT' : 'POST',
            body: JSON.stringify(payload),
        });
        toast(`${response.data.codigo} fue ${editing ? 'actualizada' : 'creada'} correctamente.`);
        resetForm();
        await loadConfiguration();
    } catch (error) {
        elements.createError.textContent = error.message;
    } finally {
        setBusy(false);
    }
});

elements.deactivate.addEventListener('click', async () => {
    if (!state.editingCamera) return;
    const activate = state.editingCamera.estado === 'inactiva';
    const message = activate
        ? `¿Reactivar ${state.editingCamera.codigo} para la operación en tablets?`
        : `¿Desactivar ${state.editingCamera.codigo}? Dejará de aparecer en tablets y no puede contener folios.`;
    if (!window.confirm(message)) return;

    const form = new FormData(elements.createForm);
    setBusy(true, activate ? 'Reactivando cámara…' : 'Desactivando cámara…');
    try {
        let response;
        if (activate) {
            response = await api(`/api/configuracion/camaras/${state.editingCamera.id}`, {
                method: 'PUT',
                body: JSON.stringify({
                    nombre: String(form.get('nombre') || '').trim(),
                    tipo: form.get('tipo'),
                    contenido: form.get('contenido'),
                    bandas: Number(form.get('bandas')),
                    posiciones_por_banda: Number(form.get('posiciones_por_banda')),
                    niveles: Number(form.get('niveles')),
                    sentido_numeracion_bandas: form.get('sentido_numeracion_bandas'),
                    estado: 'activa',
                    posiciones_fuera_servicio: [...state.disabled].map((key) => {
                        const [banda, posicion, nivel] = key.split(':').map(Number);
                        return { banda, posicion, nivel };
                    }),
                }),
            });
        } else {
            response = await api(`/api/configuracion/camaras/${state.editingCamera.id}`, { method: 'DELETE' });
        }
        toast(`${response.data.codigo} fue ${activate ? 'reactivada' : 'desactivada'} correctamente.`);
        resetForm();
        await loadConfiguration();
    } catch (error) {
        elements.createError.textContent = error.message;
    } finally {
        setBusy(false);
    }
});

elements.cameraEmergencyDeclare?.addEventListener('click', () => openEmergencyDialog('declarar'));
elements.cameraEmergencyCancel?.addEventListener('click', () => openEmergencyDialog('cancelar'));
elements.cameraEmergencyClose?.addEventListener('click', () => elements.cameraEmergencyDialog.close());
elements.cameraEmergencyForm?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const plan = state.selectedOperationalPlan;
    const action = elements.cameraEmergencyDialog.dataset.action;
    if (!plan || !canSuperviseEmergency(state.identity)) return;
    const motive = elements.cameraEmergencyForm.elements.motivo.value.trim();
    if (motive.length < 3) {
        elements.cameraEmergencyError.textContent = 'Ingresa un motivo de al menos tres caracteres.';
        return;
    }
    if (action === 'declarar' && !confirmsCameraCode(elements.cameraEmergencyForm.elements.confirmacion.value, plan.codigo)) {
        elements.cameraEmergencyError.textContent = `Escribe exactamente ${plan.codigo} para declarar la emergencia.`;
        return;
    }
    elements.cameraEmergencySubmit.disabled = true;
    try {
        await api(emergencyEndpoint(plan.id, action), {
            method: 'POST',
            body: JSON.stringify({ motivo: motive }),
        });
        elements.cameraEmergencyDialog.close();
        await loadOperationalCamera(plan.id);
    } catch (error) {
        elements.cameraEmergencyError.textContent = error.message;
    } finally {
        elements.cameraEmergencySubmit.disabled = false;
    }
});

elements.cameraOpsRefresh?.addEventListener('click', async () => {
    setBusy(true, 'Actualizando operación de cámaras…');
    try {
        await loadOperationalCameras();
        toast('Operación de cámaras actualizada.');
    } catch (error) {
        elements.cameraOpsStatus.textContent = 'No fue posible actualizar';
        toast(error.message, true);
    } finally {
        setBusy(false);
    }
});

elements.operationalCameraList?.addEventListener('click', (event) => {
    const button = event.target.closest('[data-operational-camera]');
    if (!button || button.dataset.operationalCamera === state.selectedOperationalCameraId) return;
    state.selectedOperationalBand = null;
    void loadOperationalCamera(button.dataset.operationalCamera);
});

function selectOperationalBand(event) {
    const button = event.target.closest('[data-operational-band]');
    if (!button || !state.selectedOperationalPlan) return;
    state.selectedOperationalBand = Number(button.dataset.operationalBand);
    renderOperationalBands(state.selectedOperationalPlan);
}

elements.cameraBandMap?.addEventListener('click', selectOperationalBand);
elements.cameraBandRows?.addEventListener('click', selectOperationalBand);

elements.reload.addEventListener('click', async () => {
    setBusy(true, 'Actualizando cámaras…');
    try {
        await loadConfiguration();
        toast(state.identity?.puede_configurar_camaras === true
            ? 'Configuración actualizada.'
            : 'Disponibilidad de cámaras actualizada.');
    } catch (error) {
        toast(error.message, true);
    } finally {
        setBusy(false);
    }
});

elements.cameraModule.addEventListener('click', () => {
    void openConfigurationModule('cameras');
});

elements.dockModule.addEventListener('click', () => {
    void openConfigurationModule('docks');
});

elements.reloadDocks.addEventListener('click', async () => {
    setBusy(true, 'Actualizando andenes…');
    try {
        await loadDocks();
        toast('Listado de andenes actualizado.');
    } catch (error) {
        toast(error.message, true);
    } finally {
        setBusy(false);
    }
});

elements.dockList.addEventListener('click', (event) => {
    const button = event.target.closest('[data-edit-dock]');
    if (!button) return;

    const dock = state.docks.find((item) => item.id === button.dataset.editDock);
    if (dock) editDockForm(dock);
});

elements.cancelEditDock.addEventListener('click', () => {
    resetDockForm();
});

elements.dockForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    elements.dockFormError.textContent = '';

    const form = new FormData(elements.dockForm);
    const payload = {
        codigo: String(form.get('codigo') || '').trim().toUpperCase(),
        nombre: String(form.get('nombre') || '').trim(),
        codigo_externo: String(form.get('codigo_externo') || '').trim() || null,
        activo: elements.dockForm.elements.activo.checked,
    };
    const editing = state.dockMode === 'edit' && state.editingDock;

    if (editing && state.editingDock.activo && !payload.activo
        && !window.confirm(`${state.editingDock.codigo} dejará de estar disponible para nuevas operaciones. ¿Deseas continuar?`)) {
        return;
    }

    setBusy(true, editing ? 'Guardando andén…' : 'Creando andén…');
    try {
        const response = await api(
            editing
                ? `/api/administracion/andenes/${state.editingDock.id}`
                : '/api/administracion/andenes',
            {
                method: editing ? 'PUT' : 'POST',
                body: JSON.stringify(payload),
            },
        );
        toast(`${response.data.codigo} fue ${editing ? 'actualizado' : 'creado'} correctamente.`);
        state.dockMode = 'create';
        state.editingDock = null;
        await loadDocks();
    } catch (error) {
        elements.dockFormError.textContent = error.message;
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
    renderPreview();
    resetDockForm();
    if (!state.token) return;
    showApp();
    setBusy(
        true,
        isOperationalCameraMode()
            ? 'Cargando operación de cámaras…'
            : state.identity?.puede_configurar_camaras === true
            ? 'Cargando configuración…'
            : 'Cargando disponibilidad de cámaras…',
    );
    try {
        await loadConfiguration();
    } catch (error) {
        if (error.status !== 401) toast(error.message, true);
    } finally {
        setBusy(false);
    }
}

const cameraVacating = mountCameraVacating({
    root: document,
    currentCamera: () => state.selectedOperationalPlan,
    identity: () => state.identity,
    api,
    refresh: loadOperationalCamera,
    notify: toast,
});

void boot();
