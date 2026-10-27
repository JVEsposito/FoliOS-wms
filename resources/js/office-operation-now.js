import { createAvailabilityTracker, operatorPresenceLabel } from './shared/operator-availability';
import { createUuid } from './shared/uuid.js';
import { createOperationalPoller } from './shared/operational-poller';
import { buildAttention, buildOperationTabBadges } from './shared/operation-now-attention';
import { createOperationTabs } from './shared/operation-now-tabs';
import { buildCycleComparison, renderCycleComparison } from './shared/operation-cycle-comparison';
import { buildCycleReplay, renderCycleReplay } from './shared/operation-cycle-replay';
import { plannerProcessCause, plannerProcessWarnings } from './shared/planner-process-health';
import { cameraReferenceLabel } from './shared/camera-display';
import { maneuverSituation, plannerCapacityText, plannerEmptyDetail } from './shared/operation-planner-presentation';
import {
    buildManeuverInterventionRequest,
    createManeuverSupervisionDrawer,
    operationLocationLabel,
} from './shared/operation-supervision-drawer';
import {
    autoLayout,
    availableCatalog,
    catalogKey,
    connectionProblem,
    describeConnections,
    moveElement,
    reconcilePlantSnapshot,
    removeElement,
    resizeElement,
    unconnectedPlaces,
} from './shared/plant-layout';
import {
    AGROROSARIO_PLANT,
    buildPlantFromTemplate,
    keepElementAsArea,
    linkElementToPlace,
    unassignedPlaces,
} from './shared/plant-templates';
import {
    buildPlantIndex,
    plantNodeLabel,
    plantNodeModel,
    plantNodeTitle,
    tunnelStateLabel,
    tunnelStateTone,
} from './shared/plant-view';

const tokenKey = 'estiba_wms_office_token';
const identityKey = 'estiba_wms_office_identity';
const byId = (id) => document.getElementById(id);

const elements = {
    app: byId('operationNowApp'),
    content: byId('officeContent'),
    tabsNav: document.querySelector('.operation-now-tabs'),
    workspace: byId('operationWorkspace'),
    refresh: byId('operationRefresh'),
    loading: byId('operationLoading'),
    loadingText: byId('operationLoadingText'),
    toasts: byId('operationToasts'),
    connection: byId('operationConnection'),
    connectionTitle: byId('operationConnectionTitle'),
    connectionDetail: byId('operationConnectionDetail'),
    liveSignal: byId('operationLiveSignal'),
    liveText: byId('operationLiveText'),
    season: byId('operationSeason'),
    updatedAt: byId('operationUpdatedAt'),
    date: byId('operationDate'),
    time: byId('operationTime'),
    timezone: byId('operationTimezone'),
    shift: byId('operationShift'),
    cameraRows: byId('operationCameraRows'),
    operatorList: byId('operationOperatorList'),
    tunnelList: byId('operationTunnelList'),
    verificationRows: byId('operationVerificationRows'),
    verificationSummary: byId('operationVerificationSummary'),
    plannerPanel: byId('operationPlannerPanel'),
    plannerMode: byId('plannerModeSignal'),
    plannerFreshness: byId('plannerFreshnessSignal'),
    plannerProcessAlert: byId('plannerProcessAlert'),
    plannerHealth: byId('plannerHealthSignal'),
    plannerTechnical: byId('plannerTechnical'),
    plannerCycleMeta: byId('plannerCycleMeta'),
    plannerComparisonOpen: byId('plannerComparisonOpen'),
    plannerRiskRows: byId('plannerRiskRows'),
    plannerDecisionRows: byId('plannerDecisionRows'),
    supervisionDialog: byId('operationSupervisionDialog'),
    supervisionContent: byId('operationSupervisionContent'),
    supervisionClose: byId('operationSupervisionClose'),
    comparisonDialog: byId('operationCycleComparisonDialog'),
    comparisonContent: byId('operationCycleComparisonContent'),
    comparisonClose: byId('operationCycleComparisonClose'),
    facilityMap: byId('operationFacilityMap'),
    facilityStatus: byId('operationFacilityStatus'),
    facilitySubtitle: byId('operationFacilitySubtitle'),
    mapZoomLabel: byId('operationMapZoomLabel'),
    mapEdit: byId('operationMapEdit'),
    mapDialog: byId('operationMapDialog'),
    mapDialogTitle: byId('operationMapDialogTitle'),
    mapDialogHint: byId('operationMapDialogHint'),
    mapSave: byId('operationMapSave'),
    mapCatalog: byId('operationMapCatalog'),
    mapCatalogItems: byId('operationMapCatalogItems'),
    mapInspector: byId('operationMapInspector'),
    mapSelectionName: byId('operationMapSelectionName'),
    mapConnect: byId('operationMapConnect'),
    mapConnectionList: byId('operationMapConnectionList'),
    mapNetworkSummary: byId('operationMapNetworkSummary'),
    editorStage: byId('operationEditorStage'),
    editorZoomLabel: byId('operationEditorZoomLabel'),
    mapTemplate: byId('operationMapTemplate'),
    mapTemplateReport: byId('operationMapTemplateReport'),
    mapProportion: byId('operationMapProportion'),
    mapReferenceField: byId('operationMapReferenceField'),
    mapReference: byId('operationMapReference'),
    alertsPanel: byId('operationAlertsPanel'),
    alertRows: byId('operationAlertRows'),
    alertCount: byId('operationAlertCount'),
    alertNoun: byId('operationAlertNoun'),
    alertBreakdown: byId('operationAlertBreakdown'),
    attentionFilter: document.querySelector('.operation-now-filter'),
    sourceRows: byId('operationSourceRows'),
    sourcesTime: byId('operationSourcesTime'),
    plantSheetBody: byId('operationPlantSheetBody'),
};

const state = {
    token: localStorage.getItem(tokenKey),
    identity: readJson(identityKey),
    snapshot: null,
    loading: false,
    poller: null,
    operatorPoller: null,
    operatorSnapshot: null,
    operatorReading: false,
    operatorFresh: false,
    availabilityTracker: createAvailabilityTracker(),
    clockTimer: null,
    clockOffsetMs: 0,
    mapZoom: 1,
    editorZoom: 1,
    mapProportion: 16 / 9,
    mapEditing: false,
    mapDraft: [],
    mapConnections: [],
    mapConnecting: null,
    mapSelectedId: null,
    mapDrag: null,
    mapRevision: 0,
    mapSaving: false,
    selectedManeuverId: null,
    interventionSaving: false,
    comparisonLoading: false,
    comparisonModel: null,
    replayLoading: false,
    attention: null,
    attentionFilter: 'all',
    attentionExpanded: new Set(),
    plantSelectedId: null,
};

const supervisionDrawer = createManeuverSupervisionDrawer({
    dialog: elements.supervisionDialog,
    content: elements.supervisionContent,
    closeButton: elements.supervisionClose,
    onClosed: () => {
        state.selectedManeuverId = null;
    },
    canIntervene: () => can('puede_supervisar'),
    onAction: executeManeuverIntervention,
});

class ApiError extends Error {
    constructor(message, status = 0) {
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

function capabilities() {
    return { ...(state.identity?.capacidades || {}), ...(state.identity || {}) };
}

function can(permission) {
    return state.identity?.rol === 'administrador' || capabilities()[permission] === true;
}

function hasModule(module) {
    const modules = capabilities().modulos_acceso;
    return !Array.isArray(modules) || modules.includes(module);
}

function escapeHtml(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function humanize(value) {
    return String(value || '')
        .replaceAll('_', ' ')
        .replace(/^./, (letter) => letter.toUpperCase());
}

function number(value, maximumFractionDigits = 0) {
    if (value === null || value === undefined || value === '') return '—';
    const parsed = Number(value);
    if (!Number.isFinite(parsed)) return '—';
    return new Intl.NumberFormat('es-CL', { maximumFractionDigits }).format(parsed);
}

function percent(value) {
    return `${number(value, 1)} %`;
}

function temperature(value) {
    const parsed = Number(value);
    return Number.isFinite(parsed) ? `${number(parsed, 1)} °C` : '—';
}

function dateTime(value, options = {}) {
    if (!value) return '—';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '—';

    try {
        return new Intl.DateTimeFormat('es-CL', {
            dateStyle: options.timeOnly ? undefined : 'short',
            timeStyle: 'short',
            timeZone: state.snapshot?.jornada?.zona_horaria || undefined,
        }).format(date);
    } catch {
        return new Intl.DateTimeFormat('es-CL', {
            dateStyle: options.timeOnly ? undefined : 'short',
            timeStyle: 'short',
        }).format(date);
    }
}

function duration(minutes) {
    if (minutes === null || minutes === undefined) return 'Sin objetivo';
    const total = Math.max(0, Math.round(Number(minutes) || 0));
    const hours = Math.floor(total / 60);
    const remainder = total % 60;
    return hours ? `${hours} h ${String(remainder).padStart(2, '0')} min` : `${remainder} min`;
}

function clampedPercent(value) {
    return Math.max(0, Math.min(100, Number(value) || 0));
}

function toneForOccupancy(level) {
    return { critica: 'critical', advertencia: 'warning', normal: 'success' }[level] || 'neutral';
}

function toneForEnvironment(status) {
    return { vigente: 'success', vencido: 'critical', pendiente: 'warning' }[status] || 'neutral';
}

function toneForPriority(priority) {
    return { critica: 'critical', urgente: 'critical', alta: 'warning', normal: 'info' }[priority] || 'neutral';
}

function toneForPlannerDecision(decision) {
    return {
        en_ejecucion: 'success',
        seleccionada: 'info',
        alternativa: 'neutral',
        excluida_conflicto: 'critical',
        fuera_frontera: 'warning',
        fuera_rollout: 'warning',
        fuera_planificador: 'neutral',
        sin_destino: 'critical',
    }[decision] || 'neutral';
}

function toneForTunnel(tunnel) {
    if (!tunnel.operable || ['mantenimiento', 'fuera_servicio', 'inactivo'].includes(tunnel.estado_operacional)) return 'neutral';
    if (tunnel.proceso_activo?.objetivo_excedido) return 'critical';
    if (tunnel.estado_operacional === 'pendiente_verificacion') return 'warning';
    if (tunnel.estado_operacional === 'disponible') return 'success';
    return 'info';
}

function signal(text, tone = 'neutral') {
    return `<span class="operation-now-signal" data-tone="${tone}">${escapeHtml(text)}</span>`;
}

function empty(title, detail) {
    return `<div class="operation-now-empty"><strong>${escapeHtml(title)}</strong><span>${escapeHtml(detail)}</span></div>`;
}

function setText(id, value) {
    const element = byId(id);
    if (element) element.textContent = value;
}

function errorMessage(data, fallback) {
    return Object.values(data?.errors || {}).flat()[0] || data?.message || fallback;
}

function validateSnapshot(data) {
    const valid = data
        && data.jornada
        && data.temporada
        && data.sincronizacion?.operaciones_hoy
        && Array.isArray(data.camaras)
        && Array.isArray(data.camareros)
        && data.prefrio?.resumen
        && Array.isArray(data.prefrio?.tuneles)
        && data.incidencias?.resumen
        && Array.isArray(data.incidencias?.abiertas)
        && data.planificador?.despliegue
        && data.planificador?.salud?.riesgos
        && data.planificador?.arbitraje?.vigencia
        && data.planta
        && Array.isArray(data.planta?.catalogo)
        && Array.isArray(data.planta?.elementos);

    if (!valid) throw new ApiError('El servidor entregó una lectura operacional incompleta.');
    return data;
}

function clearSession() {
    state.token = null;
    state.operatorSnapshot = null;
    localStorage.removeItem(tokenKey);
    localStorage.removeItem(identityKey);
    state.poller?.stop();
    state.operatorPoller?.stop();
    window.clearInterval(state.clockTimer);
    window.location.replace('/oficina/accesos');
}

async function api(path, options = {}) {
    const headers = new Headers(options.headers || {});
    headers.set('Accept', 'application/json');
    headers.set('Authorization', `Bearer ${state.token}`);
    const controller = new AbortController();
    const timeout = window.setTimeout(() => controller.abort(), 12_000);

    let response;
    try {
        response = await fetch(path, { ...options, headers, signal: controller.signal });
    } catch (error) {
        const message = error?.name === 'AbortError'
            ? 'El servidor tardó demasiado en responder.'
            : 'No fue posible conectar con el servidor.';
        throw new ApiError(message);
    } finally {
        window.clearTimeout(timeout);
    }

    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
        if (response.status === 401) clearSession();
        throw new ApiError(errorMessage(data, 'No fue posible consultar la operación.'), response.status);
    }

    return data;
}

function setBusy(active, message = 'Consultando la operación…') {
    elements.loadingText.textContent = message;
    elements.loading.classList.toggle('is-hidden', !active);
    elements.loading.setAttribute('aria-hidden', String(!active));
    elements.workspace.setAttribute('aria-busy', String(active));
    elements.refresh.disabled = active;
}

function toast(message, error = false) {
    const node = document.createElement('div');
    node.className = `toast${error ? ' toast--error' : ''}`;
    node.textContent = message;
    elements.toasts.append(node);
    window.setTimeout(() => node.remove(), 5000);
}

async function executeManeuverIntervention({
    action,
    reason,
    priority,
    decision,
}) {
    if (state.interventionSaving) return false;
    state.interventionSaving = true;

    try {
        const operationId = createUuid();
        const request = buildManeuverInterventionRequest(
            decision,
            action,
            { reason, priority },
            operationId,
        );
        await api(request.path, {
            method: request.method,
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(request.body),
        });
        const labels = {
            pausar: 'Maniobra pausada y auditada.',
            reanudar: 'Maniobra reanudada y enviada a recálculo.',
            repriorizar: 'Prioridad actualizada y enviada a recálculo.',
        };
        toast(labels[action] || 'Intervención registrada.');
        await load({ silent: true, propagate: true });

        return true;
    } catch (error) {
        toast(error.message || 'No fue posible intervenir la maniobra.', true);
        if (error.status === 409) {
            await load({ silent: true }).catch(() => false);
        }

        return false;
    } finally {
        state.interventionSaving = false;
    }
}

function renderClock() {
    if (!state.snapshot) return;
    const now = new Date(Date.now() + state.clockOffsetMs);
    const timeZone = state.snapshot.jornada?.zona_horaria;

    try {
        elements.date.textContent = new Intl.DateTimeFormat('es-CL', {
            weekday: 'short', day: '2-digit', month: 'short', year: 'numeric', timeZone,
        }).format(now);
        elements.time.textContent = new Intl.DateTimeFormat('es-CL', {
            hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false, timeZone,
        }).format(now);
    } catch {
        elements.date.textContent = state.snapshot.jornada?.fecha || '—';
        elements.time.textContent = state.snapshot.jornada?.hora || '--:--:--';
    }
    elements.timezone.textContent = timeZone || 'Hora operacional';
}

function startClock() {
    window.clearInterval(state.clockTimer);
    renderClock();
    state.clockTimer = window.setInterval(renderClock, 1000);
}

function renderHeader(data) {
    const generated = new Date(data.generado_at);
    state.clockOffsetMs = Number.isNaN(generated.getTime()) ? 0 : generated.getTime() - Date.now();
    elements.season.textContent = data.temporada
        ? `${data.temporada.codigo} · ${data.temporada.nombre}`
        : 'Sin temporada activa';
    elements.updatedAt.textContent = `Última lectura: ${dateTime(data.generado_at)}`;
    elements.shift.textContent = data.jornada?.turno
        ? `Turno: ${humanize(data.jornada.turno)}`
        : 'Turno sin configurar';

    const status = data.sincronizacion?.estado || 'sin_actividad';
    const tone = { aceptada: 'success', procesando: 'info', pendiente: 'warning', rechazada: 'critical', conflicto: 'critical' }[status] || 'neutral';
    const statusLabel = {
        aceptada: 'Sincronizado',
        procesando: 'Procesando',
        pendiente: 'Pendiente',
        rechazada: 'Con rechazo',
        conflicto: 'Con conflicto',
    }[status] || 'Sin actividad sincronizada';
    elements.liveSignal.dataset.tone = tone;
    elements.liveText.textContent = statusLabel;
    startClock();
}

function renderMetrics(data) {
    const cameras = data.camaras || [];
    const productCameras = cameras.filter((camera) => camera.contenido === 'productos');
    const capacity = productCameras.reduce((sum, camera) => sum + Number(camera.capacidad_operativa || 0), 0);
    const occupied = productCameras.reduce((sum, camera) => sum + Number(camera.ocupadas || 0), 0);
    const occupancy = capacity ? (occupied / capacity) * 100 : 0;
    const environmentalDue = productCameras.filter((camera) => camera.control_ambiental?.requiere_control).length;
    const operators = data.camareros || [];
    const activeTasks = operators.filter((operator) => operator.tarea_actual).length;
    const precooling = data.prefrio?.resumen || {};

    setText('cameraPanelSummary', `${number(productCameras.length)} ${productCameras.length === 1 ? 'cámara' : 'cámaras'} · ${percent(occupancy)} ocupado · ${number(environmentalDue)} con control pendiente`);
    setText('operatorPanelCount', number(operators.length));
    setText('precoolingPanelSummary', `${number(precooling.procesos_activos)} en proceso · ${number(precooling.procesos_fuera_objetivo)} fuera de objetivo`);

    const cameraSummary = byId('cameraPanelSummary');
    if (cameraSummary) {
        cameraSummary.title = `${number(occupied)} de ${number(capacity)} posiciones PT; ${number(environmentalDue)} ${environmentalDue === 1 ? 'cámara requiere control' : 'cámaras requieren control'}`;
    }

    const operatorCount = byId('operatorPanelCount');
    if (operatorCount) operatorCount.title = `${number(activeTasks)} con tarea vigente`;
}

// Minutos entre un registro y la lectura vigente: la edad se mide contra el servidor,
// no contra el reloj del equipo que mira.
function minutesBefore(value, reference) {
    const moment = new Date(value).getTime();
    const base = new Date(reference).getTime();
    if (!Number.isFinite(moment) || !Number.isFinite(base)) return null;
    return Math.max(0, Math.round((base - moment) / 60_000));
}

function ago(minutes) {
    if (minutes === null || minutes === undefined) return '';
    return minutes < 1 ? 'hace menos de 1 min' : `hace ${duration(minutes)}`;
}

function renderSync(sync = {}, generatedAt = null) {
    const latest = sync.ultima_operacion;
    setText('syncLatestOperation', latest ? `${humanize(latest.tipo)} · ${humanize(latest.estado)}` : 'Sin actividad registrada');
    const age = latest ? ago(minutesBefore(latest.recibida_servidor_at, generatedAt)) : '';
    setText('syncLatestContext', latest
        ? [latest.usuario?.nombre || 'Usuario no informado', latest.dispositivo?.codigo || 'Sin dispositivo', dateTime(latest.recibida_servidor_at), age].filter(Boolean).join(' · ')
        : 'Esperando evidencia de dispositivos');
    const counts = sync.operaciones_hoy || {};
    // Los ceros quedan neutros: el color se reserva para lo que exige mirar.
    const tones = { aceptada: 'success', pendiente: 'warning', procesando: 'info', rechazada: 'critical', conflicto: 'critical' };
    Object.entries({
        aceptada: 'syncAccepted',
        pendiente: 'syncPending',
        procesando: 'syncProcessing',
        rechazada: 'syncRejected',
        conflicto: 'syncConflict',
    }).forEach(([status, id]) => {
        const value = Number(counts[status] || 0);
        setText(id, number(value));
        const cell = byId(id)?.parentElement;
        if (cell) cell.dataset.tone = value > 0 ? tones[status] : 'neutral';
    });
}

function plannerModeLabel(mode) {
    return { off: 'Detenido', shadow: 'Observando', guided: 'Guiado' }[mode] || humanize(mode || 'sin modo');
}

// Estado del planificador en palabras de supervisión, sin nombres de configuración.
function plannerModeStatus(mode) {
    return {
        off: 'Planificador apagado',
        shadow: 'Planificador observando',
        guided: 'Planificador dirigiendo',
    }[mode] || `Planificador ${humanize(mode || 'sin modo').toLowerCase()}`;
}

function plannerHealthStatus(status) {
    return {
        saludable: 'Sin advertencias del planificador',
        advertencia: 'Planificador con advertencias',
        critico: 'Planificador con alertas críticas',
    }[status] || 'Sin lectura del planificador';
}

// «Fuera de frontera» agrupa causas distintas; el factor decisivo evita atribuir una
// espera de cupo a una maniobra que en realidad está pausada.
function plannerDecisionLabel(decision, factor = '') {
    if (decision === 'fuera_frontera') {
        return {
            pausa_supervision: 'Pausada por supervisión',
            pausa_discrepancia_sin_retiro: 'Pausada · en espera de supervisión',
            objetivo_pausado: 'Objetivo pausado',
            frontera_completa: 'Esperando cupo',
        }[factor] || 'No publicada ahora';
    }
    return {
        en_ejecucion: 'En curso',
        seleccionada: 'Lista para tomar',
        alternativa: 'En espera',
        excluida_conflicto: 'Bloqueada por otra',
        fuera_rollout: 'Cámara sin planificador',
        fuera_planificador: 'Gestión manual',
        sin_destino: 'Sin destino posible',
    }[decision] || humanize(decision);
}

function plannerFreshnessLabel(status) {
    return {
        detenido: 'detenido',
        pendiente: 'pendiente',
        recalculando: 'recalculando',
        actual: 'al día',
        atrasado: 'atrasado',
        error: 'con error',
    }[status] || humanize(status || 'sin lectura');
}

function plannerRoute(step) {
    if (!step) return 'Sin paso activo';
    const origin = operationLocationLabel(step.origen, 'Inicio');
    const destination = operationLocationLabel(step.destino, 'Sin destino');
    return `${origin} → ${destination}`;
}

function plannerConflictDetail(decision) {
    const conflicts = Array.isArray(decision.conflictos) ? decision.conflictos : [];
    if (!conflicts.length) return decision.motivo || 'Sin observaciones adicionales';
    const resources = [...new Set(conflicts.map((conflict) => String(conflict.recurso || '').split(':')[0]).filter(Boolean))];
    const resourceLabel = resources.length ? ` · ${resources.map(humanize).join(', ')}` : '';
    return `${decision.motivo || 'Recursos incompatibles'} · ${number(conflicts.length)} ${conflicts.length === 1 ? 'conflicto' : 'conflictos'}${resourceLabel}`;
}

function renderPlanner(planner = {}) {
    const deployment = planner.despliegue || {};
    const health = planner.salud || {};
    const risks = health.riesgos || {};
    const cycle = planner.arbitraje?.ciclo;
    const freshness = planner.arbitraje?.vigencia || {};
    const processCause = plannerProcessCause(planner.procesos);
    const processWarnings = plannerProcessWarnings(planner.procesos, (value) => dateTime(value, { timeOnly: true }));
    const summary = cycle?.resumen || {};
    const decisions = Array.isArray(cycle?.decisiones) ? cycle.decisiones : [];
    const mode = deployment.mode_global || 'off';
    const healthTone = { saludable: 'success', advertencia: 'warning', critico: 'critical' }[health.estado] || 'neutral';
    const modeTone = { guided: 'success', shadow: 'info', off: 'neutral' }[mode] || 'neutral';
    const freshnessTone = {
        actual: 'success',
        recalculando: 'info',
        pendiente: 'warning',
        atrasado: 'warning',
        error: 'critical',
        detenido: 'neutral',
    }[freshness.estado] || 'neutral';
    const panelTone = freshnessTone === 'critical' || healthTone === 'critical'
        ? 'critical'
        : (processWarnings.length || freshnessTone === 'warning' || healthTone === 'warning' ? 'warning' : healthTone);

    elements.plannerPanel.dataset.tone = panelTone;
    elements.plannerMode.dataset.tone = modeTone;
    elements.plannerMode.textContent = plannerModeStatus(mode);
    elements.plannerFreshness.dataset.tone = freshnessTone;
    const delayedByProcess = processCause && ['atrasado', 'pendiente', 'recalculando'].includes(freshness.estado);
    elements.plannerFreshness.textContent = `Cálculo ${plannerFreshnessLabel(freshness.estado)}${delayedByProcess ? ` · ${processCause}` : ''}`;
    elements.plannerFreshness.title = [freshness.detalle, delayedByProcess ? processWarnings.join(' ') : ''].filter(Boolean).join(' ') || 'Sin detalle del último cálculo';
    elements.plannerProcessAlert.hidden = processWarnings.length === 0;
    elements.plannerProcessAlert.textContent = processWarnings.join(' ');
    elements.plannerHealth.dataset.tone = healthTone;
    elements.plannerHealth.textContent = plannerHealthStatus(health.estado);
    elements.plannerCycleMeta.textContent = cycle
        ? `Evaluado ${dateTime(freshness.evaluado_at, { timeOnly: true })} · ${number(decisions.length)} decisiones`
        : 'Sin cálculo vigente';
    elements.plannerCycleMeta.title = freshness.detalle || '';
    elements.plannerComparisonOpen.disabled = !cycle || state.comparisonLoading;
    elements.plannerComparisonOpen.title = cycle
        ? 'Comparar el ciclo vigente con su antecedente confirmado'
        : 'Todavía no existe un ciclo para comparar';

    elements.plannerTechnical.hidden = state.identity?.rol !== 'administrador';
    setText('plannerTechnicalMode', `${plannerModeLabel(mode)} (${mode})`);
    setText('plannerCompute', deployment.compute === 'tablet' ? 'Tablet' : humanize(deployment.compute));
    setText('plannerHorizon', deployment.horizon === 'rolling' ? 'Continuo' : humanize(deployment.horizon));
    setText('plannerRollout', deployment.rollout_limitado
        ? `${number(deployment.camaras_configuradas?.length || 0)} cámaras`
        : 'Toda la planta');
    setText('plannerCapacity', plannerCapacityText(cycle));
    setText('plannerRunningCount', cycle ? number(summary.en_curso || 0) : '—');
    setText('plannerPausedCount', cycle ? number(summary.pausadas || 0) : '—');
    const unpositioned = planner.conciliacion || {};
    setText('plannerUnpositionedCount', number(unpositioned.sin_objetivo || 0));
    byId('plannerUnpositionedCount').title = `${number(unpositioned.saldos_sin_objetivo || 0)} saldos sin ubicación ni objetivo se informan por separado`;
    setText('plannerSelectedCount', cycle ? number(summary.seleccionada || 0) : '—');
    setText('plannerAlternativeCount', cycle ? number(summary.alternativa || 0) : '—');
    setText('plannerConflictCount', cycle ? number(summary.excluida_conflicto || 0) : '—');
    setText('plannerRolloutCount', cycle ? number(summary.fuera_rollout || 0) : '—');
    setText('plannerNoDestinationCount', cycle ? number(summary.sin_destino || 0) : '—');

    const riskDefinitions = [
        ['leases_vencidos_activos', 'Reservas de tarea vencidas', 'critical'],
        ['tareas_estancadas', 'Tareas detenidas', 'warning'],
        ['custodias_temporales_activas', 'Pallets fuera de su posición', 'warning'],
        ['maniobras_completadas_con_custodia', 'Pallets sin devolver', 'critical'],
        ['discrepancias_abiertas', 'Diferencias reportadas', 'warning'],
        ['maniobras_sin_destino', 'Maniobras sin destino posible', 'warning'],
        ['destinos_rechazados_repetidos', 'Pallets con destino rechazado varias veces', 'warning'],
    ];
    elements.plannerRiskRows.innerHTML = riskDefinitions.map(([key, label, warningTone]) => {
        const value = Number(risks[key] || 0);
        return `<span data-tone="${value ? warningTone : 'neutral'}"><strong>${escapeHtml(number(value))}</strong>${escapeHtml(label)}</span>`;
    }).join('');

    if (!cycle) {
        const stopped = mode === 'off';
        elements.plannerDecisionRows.innerHTML = `<tr><td colspan="6">${empty(
            stopped ? 'Planificador detenido por configuración' : 'Sin cálculo vigente',
            stopped ? 'La operación continúa con asignación manual de movimientos.' : (processWarnings.join(' ') || freshness.detalle || 'El planificador todavía no confirma qué maniobras ofrecer.'),
        )}</td></tr>`;
        return;
    }

    if (!decisions.length) {
        elements.plannerDecisionRows.innerHTML = `<tr><td colspan="6">${empty('Sin maniobras pendientes', plannerEmptyDetail(freshness))}</td></tr>`;
        return;
    }

    elements.plannerDecisionRows.innerHTML = decisions.map((decision) => {
        const progress = decision.progreso || {};
        const step = decision.paso_actual;
        const responsible = decision.responsable?.nombre || 'Sin asignar';
        const device = decision.dispositivo?.codigo || 'Sin dispositivo';
        const stepTitle = step?.instruccion || humanize(step?.tipo_movimiento || 'sin instrucción');
        const situation = maneuverSituation(decision);

        return `<tr data-decision="${escapeHtml(decision.decision)}">
            <td><span class="operation-now-code">#${escapeHtml(number(decision.orden))}</span>${signal(situation?.label || plannerDecisionLabel(decision.decision, decision.explicacion?.factor_decisivo?.codigo), situation?.tone || toneForPlannerDecision(decision.decision))}</td>
            <td><strong>${escapeHtml(decision.titulo || 'Maniobra sin título')}</strong><span class="operation-now-subtext">Prioridad ${escapeHtml(humanize(decision.prioridad))} · ${escapeHtml(humanize(decision.objetivo?.tipo || 'sin objetivo'))}</span></td>
            <td>${signal(situation?.label || humanize(decision.estado), situation?.tone || toneForPriority(decision.prioridad))}<div class="operation-now-planner__progress"><span class="operation-now-meter" data-tone="${situation?.tone || toneForPlannerDecision(decision.decision)}" style="--operation-progress:${clampedPercent(progress.porcentaje)}%"><i></i></span><small>${escapeHtml(number(progress.pasos_completados))}/${escapeHtml(number(progress.pasos_total))}</small></div></td>
            <td><span class="operation-now-code">${escapeHtml(step?.folio?.numero_folio || 'Sin folio')}</span><span class="operation-now-subtext">${escapeHtml(plannerRoute(step))} · ${escapeHtml(stepTitle)}</span></td>
            <td><strong>${escapeHtml(responsible)}</strong><span class="operation-now-subtext">${escapeHtml(device)}</span></td>
            <td><span class="operation-now-planner__reason">${escapeHtml(situation?.reason || plannerConflictDetail(decision))}</span><button type="button" class="operation-now-planner__detail" data-maneuver-detail="${escapeHtml(decision.maniobra_id)}">Ver detalle</button></td>
        </tr>`;
    }).join('');

    if (state.selectedManeuverId) {
        const selected = decisions.find((decision) => decision.maniobra_id === state.selectedManeuverId);
        if (selected) supervisionDrawer.update(selected);
        else supervisionDrawer.close();
    }
}

function renderCameras(cameras = []) {
    const productCameras = cameras.filter((camera) => camera.contenido === 'productos');
    if (!productCameras.length) {
        elements.cameraRows.innerHTML = `<tr><td colspan="4">${empty('Sin cámaras PT activas', 'No existen cámaras de producto terminado disponibles.')}</td></tr>`;
        return;
    }

    elements.cameraRows.innerHTML = productCameras.map((camera) => {
        const control = camera.control_ambiental;
        const occupancyTone = toneForOccupancy(camera.nivel_ocupacion);
        const environmentTone = toneForEnvironment(control?.estado);
        const readings = control?.temperaturas_c;
        const readingDetail = readings
            ? `Inicio ${temperature(readings.inicio)} · Medio ${temperature(readings.medio)} · Fondo ${temperature(readings.fondo)}`
            : '';
        const currentTemperature = readings
            ? `<span class="operation-now-temperature" title="${escapeHtml(readingDetail)}">${escapeHtml(temperature(readings.promedio))}</span>`
            : '<strong class="operation-now-no-reading">SIN REGISTRO</strong>';
        const controlStatus = control
            ? `${signal(humanize(control.estado), environmentTone)}<span class="operation-now-subtext"${control.vigente_hasta ? ` title="Vigente hasta ${escapeHtml(dateTime(control.vigente_hasta, { timeOnly: true }))}"` : ''}>${control.capturado_at ? `Registro ${escapeHtml(dateTime(control.capturado_at, { timeOnly: true }))}` : 'Sin captura informada'}</span>`
            : '<span class="operation-now-subtext">Sin control configurado</span>';

        return `<tr>
            <td><span class="operation-now-code">${escapeHtml(cameraReferenceLabel(camera))}</span></td>
            <td>
                ${signal(`${number(camera.ocupadas)} / ${number(camera.capacidad_operativa)} · ${percent(camera.ocupacion_porcentaje)}`, occupancyTone)}
                <span class="operation-now-meter" data-tone="${occupancyTone}" style="--operation-progress:${clampedPercent(camera.ocupacion_porcentaje)}%"><i></i></span>
            </td>
            <td>${currentTemperature}</td>
            <td>${controlStatus}</td>
        </tr>`;
    }).join('');
}

function taskLocation(endpoint) {
    if (!endpoint?.camara) return 'Sin ubicación física';
    return [cameraReferenceLabel(endpoint.camara), endpoint.posicion?.etiqueta].filter(Boolean).join(' · ');
}

function renderOperators(operators = []) {
    const orderedOperators = [...operators].sort((left, right) => {
        const weight = { critica: 0, urgente: 0, alta: 1, normal: 2 };
        const leftTask = left.tarea_actual;
        const rightTask = right.tarea_actual;
        return Number(!leftTask) - Number(!rightTask)
            || (weight[leftTask?.prioridad] ?? 9) - (weight[rightTask?.prioridad] ?? 9)
            || String(left.usuario?.nombre || '').localeCompare(String(right.usuario?.nombre || ''), 'es');
    });
    setText('operatorPanelCount', number(orderedOperators.length));
    if (!orderedOperators.length) {
        elements.operatorList.innerHTML = empty('Sin camareros activos', 'No existen tareas asumidas ni sesiones de cámara con actividad reciente.');
        return;
    }

    elements.operatorList.innerHTML = orderedOperators.map((operator) => {
        const task = operator.tarea_actual;
        const currentCamera = operator.ubicacion_actual?.camara;
        const locationType = operator.ubicacion_actual?.tipo;
        const locationLabel = locationType === 'transito' ? 'En tránsito'
            : locationType === 'destino_previsto' ? 'Destino previsto'
                : locationType === 'origen' ? 'Origen de la tarea' : locationType === 'sin_ubicacion' ? 'Sin ubicación reciente' : 'Cámara de sesión';
        const taskMarkup = task ? `<div class="operation-now-operator__task">
            <div class="operation-now-operator__task-head">
                <div><span class="operation-now-operator__label">TAREA ${escapeHtml(humanize(task.estado).toUpperCase())}</span><p><span class="operation-now-code">${escapeHtml(task.folio?.numero_folio || 'Sin folio')}</span></p></div>
                ${signal(humanize(task.prioridad), toneForPriority(task.prioridad))}
            </div>
            <p>${escapeHtml(task.instruccion || task.plan?.titulo || humanize(task.tipo_movimiento))}</p>
            <div class="operation-now-route"><span>${escapeHtml(taskLocation(task.origen))}</span><b aria-label="hacia">→</b><span>${escapeHtml(task.destino_logico?.nombre || taskLocation(task.destino))}</span></div>
        </div>` : `<div class="operation-now-operator__task">${signal(operatorPresenceLabel(operator), operator.estado_presencia === 'disponible' ? 'success' : 'warning')}${operator.disponible_desde ? `<p>Disponible desde ${escapeHtml(dateTime(operator.disponible_desde, { timeOnly: true }))}</p>` : ''}</div>`;

        return `<article class="operation-now-operator">
            <div class="operation-now-operator__identity">
                <h3>${escapeHtml(operator.usuario?.nombre || 'Camarero')}</h3>
                <span class="operation-now-operator__meta">${escapeHtml(operator.dispositivo?.codigo || 'Sin dispositivo')} · actividad ${escapeHtml(dateTime(operator.ultima_actividad_at, { timeOnly: true }))}</span>
                <div class="operation-now-operator__status">${signal(operatorPresenceLabel(operator), operator.estado_presencia === 'disponible' ? 'success' : operator.estado_presencia === 'desconectado' ? 'warning' : 'info')}</div>
            </div>
            <div class="operation-now-operator__location"><span class="operation-now-operator__label">${escapeHtml(locationLabel.toUpperCase())}</span><p><strong>${escapeHtml(currentCamera ? cameraReferenceLabel(currentCamera) : locationLabel)}</strong></p></div>
            ${taskMarkup}
        </article>`;
    }).join('');
}

function currentMapElements(plant = state.snapshot?.planta) {
    return plant?.configurado ? plant.elementos : autoLayout(plant?.catalogo || []);
}

function mapTypeLabel(type) {
    return { camara: 'Cámara', tunel: 'Túnel', anden: 'Andén', almacen: 'Bodega', zona: 'Área' }[type] || 'Recinto';
}

const PLANT_GLYPHS = {
    wrench: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14.7 6.3a4 4 0 0 0-5.4 5.2L3.5 17.3a1.8 1.8 0 0 0 2.6 2.6l5.8-5.8a4 4 0 0 0 5.2-5.4l-2.5 2.5-2.4-.4-.4-2.4Z"/></svg>',
    truck: '<svg viewBox="0 0 40 72" preserveAspectRatio="xMidYMid meet" aria-hidden="true"><rect x="6" y="2" width="28" height="48" rx="2"/><path d="M6 12h28M6 22h28M6 32h28M6 42h28" class="plant-glyph__slats"/><rect x="9" y="53" width="22" height="16" rx="4"/><rect x="12" y="56" width="16" height="5" rx="1" class="plant-glyph__glass"/></svg>',
    dock: '<svg viewBox="0 0 40 64" preserveAspectRatio="xMidYMid meet" aria-hidden="true"><path d="M8 4h24v56H8z" class="plant-glyph__outline"/><path d="M12 12h16M12 22h16M12 32h16M12 42h16M12 52h16" class="plant-glyph__outline"/></svg>',
    racks: '<svg viewBox="0 0 64 32" preserveAspectRatio="xMidYMid meet" aria-hidden="true"><g class="plant-glyph__outline"><rect x="2" y="3" width="17" height="26"/><rect x="24" y="3" width="17" height="26"/><rect x="46" y="3" width="16" height="26"/><path d="M2 12h17M2 20h17M24 12h17M24 20h17M46 12h16M46 20h16"/></g></svg>',
    bins: '<svg viewBox="0 0 64 28" preserveAspectRatio="xMidYMid meet" aria-hidden="true"><g><rect x="2" y="6" width="13" height="18" rx="1"/><rect x="18" y="6" width="13" height="18" rx="1"/><rect x="34" y="6" width="13" height="18" rx="1"/><rect x="50" y="6" width="12" height="18" rx="1"/></g><path d="M2 12h60M2 18h60" class="plant-glyph__slats"/></svg>',
    repa: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h11l-3-3M20 17H9l3 3" class="plant-glyph__outline"/></svg>',
    person: '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="7" r="4"/><path d="M4 21a8 8 0 0 1 16 0Z"/></svg>',
    forklift: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 17V8h7l3 5v4ZM15 4v15h6M5 7V4h4"/><circle cx="6" cy="19" r="2"/><circle cx="12" cy="19" r="2"/></svg>',
    alert: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 2 21h20ZM12 10v5M12 18h.01"/></svg>',
};

function plantCrewMarkup(crew) {
    if (!crew || (!crew.personas.length && !crew.equipos.length)) return '';
    const people = crew.personas.length
        ? `<span class="plant-node__crew-item" data-crew="personal" title="${escapeHtml(crew.personas.join(', '))}">${PLANT_GLYPHS.person}${number(crew.personas.length)}</span>`
        : '';
    const devices = crew.equipos.length
        ? `<span class="plant-node__crew-item" data-crew="equipo" title="${escapeHtml(crew.equipos.join(', '))}">${PLANT_GLYPHS.forklift}${number(crew.equipos.length)}</span>`
        : '';
    return `<span class="plant-node__crew">${people}${devices}</span>`;
}

function mapNodeMarkup(item, editable = false, index = buildPlantIndex(state.snapshot || {}), unconnected = false, selectable = false) {
    const model = plantNodeModel(item, index);
    const selected = editable ? state.mapSelectedId === item.id : selectable && state.plantSelectedId === item.id;
    const selection = selectable ? ` tabindex="0" role="button" aria-pressed="${selected}"` : '';
    const connecting = editable && state.mapConnecting === item.id;
    const showName = model.kind !== 'camara' && model.name && model.name !== model.code;
    const meter = model.meter
        ? `<span class="plant-node__meter" data-tone="${escapeHtml(model.meter.tone)}"${model.meter.label ? ` title="${escapeHtml(`${model.meter.value}% ${model.meter.label}`)}"` : ''}><i style="--plant-meter:${model.meter.value}%"></i></span>`
        : '';
    const alerts = model.alerts.length
        ? `<span class="plant-node__alert" title="${escapeHtml(model.alerts.join(' · '))}">${PLANT_GLYPHS.alert}</span>`
        : '';

    return `<article class="operation-map-node plant-node${selected ? ' is-selected' : ''}${connecting ? ' is-connecting' : ''}${unconnected ? ' is-unconnected' : ''}${model.fill ? ' is-filled' : ''}" data-id="${escapeHtml(item.id)}" data-type="${escapeHtml(item.tipo)}" data-zone="${escapeHtml(model.zone || '')}" data-state="${escapeHtml(model.state)}" data-tone="${escapeHtml(model.tone)}" data-rotation="${Number(item.rotacion) || 0}"${selection} style="left:${item.x / 100}%;top:${item.y / 100}%;width:${item.ancho / 100}%;height:${item.alto / 100}%;--node-w:${item.ancho / 100};--node-h:${item.alto / 100}" aria-label="${escapeHtml(plantNodeLabel(model))}" title="${escapeHtml(plantNodeLabel(model))}">
        <span class="plant-node__tag">${escapeHtml(model.tag)}</span>
        ${alerts}
        ${model.glyph ? `<span class="plant-node__glyph" data-glyph="${escapeHtml(model.glyph)}">${PLANT_GLYPHS[model.glyph] || ''}</span>` : ''}
        <strong class="plant-node__code">${escapeHtml(plantNodeTitle(model))}</strong>
        ${model.value ? `<span class="plant-node__value">${escapeHtml(model.value)}</span>` : ''}
        ${meter}
        ${model.detail ? `<small class="plant-node__detail">${escapeHtml(model.detail)}</small>` : ''}
        ${showName ? `<small class="plant-node__name">${escapeHtml(model.name)}</small>` : ''}
        ${plantCrewMarkup(model.crew)}
        ${unconnected ? '<span class="plant-node__access">Sin acceso</span>' : ''}
        ${editable ? '<button class="operation-map-node__resize" type="button" aria-label="Redimensionar"></button>' : ''}
    </article>`;
}

const MAP_LAYER = { zona: 0, pasillo: 1 };

function mapDoorMarkup(connection, byId) {
    const from = byId.get(connection.desde);
    const to = byId.get(connection.hacia);
    if (!from || !to) return '';
    const point = connection.point || {
        x: Math.round((from.x + from.ancho / 2 + to.x + to.ancho / 2) / 2),
        y: Math.round((from.y + from.alto / 2 + to.y + to.alto / 2) / 2),
    };
    const label = `${connection.valid ? 'Acceso' : 'Conexión sin borde compartido'}: ${from.nombre} ↔ ${to.nombre}`;
    return `<span class="plant-door${connection.valid ? '' : ' is-invalid'}" data-connection="${escapeHtml(connection.id)}" style="left:${point.x / 100}%;top:${point.y / 100}%" title="${escapeHtml(label)}" aria-label="${escapeHtml(label)}"></span>`;
}

function renderMapStage(target, items, editable = false, connections = [], selectable = false) {
    if (!items.length) {
        target.innerHTML = empty('Plano sin recintos', editable ? 'Agrega recintos desde el catálogo o dibuja una nueva área.' : 'Administración aún no ha configurado la planta.');
        return;
    }
    const index = buildPlantIndex(state.snapshot || {});
    const ordered = [...items].sort((left, right) => (MAP_LAYER[left.tipo] ?? 2) - (MAP_LAYER[right.tipo] ?? 2));
    const byId = new Map(items.map((item) => [item.id, item]));
    const unconnected = editable ? new Set(unconnectedPlaces(items, connections)) : new Set();
    const nodes = ordered.map((item) => mapNodeMarkup(item, editable, index, unconnected.has(item.id), selectable)).join('');
    const doors = describeConnections(items, connections).map((connection) => mapDoorMarkup(connection, byId)).join('');
    target.innerHTML = nodes + doors;
}

function setMapZoom(kind, value) {
    const normalized = Math.max(.6, Math.min(3, Math.round(value * 10) / 10));
    const stage = kind === 'editor' ? elements.editorStage : elements.facilityMap;
    const label = kind === 'editor' ? elements.editorZoomLabel : elements.mapZoomLabel;
    state[kind === 'editor' ? 'editorZoom' : 'mapZoom'] = normalized;
    stage.style.width = `${normalized * 100}%`;
    label.textContent = `${Math.round(normalized * 100)} %`;
}

// Ancho/alto del dibujo. Las coordenadas son relativas a cada eje: sin la proporción
// real, una planta más alta que ancha se vería estirada a lo ancho.
function plantAspect(plant) {
    const value = Number(plant?.proporcion);
    return Number.isFinite(value) && value > 0 ? value : 16 / 9;
}

function renderFacility(data) {
    const plant = data.planta;
    const items = currentMapElements(plant);
    elements.facilityMap.style.setProperty('--plant-aspect', String(plantAspect(plant)));
    renderMapStage(elements.facilityMap, items, false, plant.configurado ? (plant.conexiones || []) : [], true);
    elements.facilityStatus.textContent = plant.configurado ? `Plano v${plant.version}` : 'Distribución automática';
    elements.facilityStatus.dataset.tone = plant.configurado ? 'success' : 'warning';
    elements.facilitySubtitle.textContent = plant.configurado
        ? `${plant.nombre} · estados actualizados en vivo`
        : 'Borrador automático: falta guardar la distribución física';
    elements.mapEdit.hidden = !plant.puede_editar;
    setMapZoom('map', state.mapZoom);
    renderPlantSheet(items);
}

const PLANT_STATE_LABELS = {
    operativo: 'Operativo',
    mantencion: 'En mantención',
    sin_referencia: 'Sin referencia vigente',
    circulacion: 'Circulación',
    no_operativo: 'No operativo',
};

const PLANT_SHEET_LINKS = {
    camara: ['/oficina/frigorifico/camaras', 'Ver cámaras'],
    tunel: ['/oficina/prefrio', 'Ver prefrío'],
    anden: ['/oficina/frigorifico/despacho/estatus', 'Ver despacho'],
};

// Ficha lateral del plano: el recinto elegido se lee completo sin abrir otro módulo y se
// mantiene seleccionado entre refrescos mientras exista en la planta.
function renderPlantSheet(items = currentMapElements(state.snapshot?.planta)) {
    const item = items.find((candidate) => candidate.id === state.plantSelectedId);
    if (!item) {
        state.plantSelectedId = null;
        elements.plantSheetBody.innerHTML = '<p class="operation-now-plant-sheet__hint">Selecciona un recinto en el plano para ver su estado, sus alertas y quién trabaja ahí.</p>';
        return;
    }

    const model = plantNodeModel(item, buildPlantIndex(state.snapshot || {}));
    // Las condiciones del recinto salen de la misma lista de Atención: la ficha nunca dice
    // «sin alertas» de un recinto que Atención sí muestra.
    const conditions = state.attention
        ? state.attention.rows
            .filter((row) => row.items.some((entry) => entry.code === model.code))
            .map((row) => ({ text: row.condition, tone: row.severity }))
        : model.alerts.map((text) => ({ text, tone: 'warning' }));
    const people = model.crew?.personas || [];
    const devices = model.crew?.equipos || [];
    const link = PLANT_SHEET_LINKS[model.kind];
    const facts = [
        model.value ? [humanize(model.valueLabel || 'valor'), model.value, model.tone] : null,
        model.detail ? ['Detalle', model.detail] : null,
        ['Personal', people.length ? people.join(', ') : 'Sin personal registrado'],
        ['Equipos', devices.length ? devices.join(', ') : 'Sin equipos registrados'],
    ].filter(Boolean);
    const stateTone = model.state === 'operativo' ? 'success' : model.tone;

    elements.plantSheetBody.innerHTML = `<p class="operation-now-plant-sheet__tag">${escapeHtml(model.tag)}</p>
        <strong class="operation-now-plant-sheet__code">${escapeHtml(plantNodeTitle(model))}</strong>
        ${model.kind !== 'camara' && model.name && model.name !== model.code ? `<p class="operation-now-plant-sheet__name">${escapeHtml(model.name)}</p>` : ''}
        ${signal(PLANT_STATE_LABELS[model.state] || humanize(model.state), stateTone)}
        <dl class="operation-now-plant-sheet__facts">${facts.map(([term, value, tone]) => `<div><dt>${escapeHtml(term)}</dt><dd${tone ? ` data-tone="${escapeHtml(tone)}"` : ''}>${escapeHtml(value)}</dd></div>`).join('')}</dl>
        ${conditions.length
            ? `<ul class="operation-now-plant-sheet__alerts" aria-label="Condiciones abiertas del recinto">${conditions.map((condition) => `<li>${signal(condition.text, condition.tone)}</li>`).join('')}</ul>`
            : '<p class="operation-now-plant-sheet__calm">Sin condiciones abiertas en este recinto.</p>'}
        ${link ? `<a class="operation-now-action" href="${link[0]}">${escapeHtml(link[1])} <span aria-hidden="true">→</span></a>` : ''}`;
}

function selectPlantNode(node) {
    if (!node?.dataset.id) return;
    state.plantSelectedId = state.plantSelectedId === node.dataset.id ? null : node.dataset.id;
    elements.facilityMap.querySelectorAll('.plant-node[data-id]').forEach((candidate) => {
        const selected = candidate.dataset.id === state.plantSelectedId;
        candidate.classList.toggle('is-selected', selected);
        candidate.setAttribute('aria-pressed', String(selected));
    });
    renderPlantSheet();
}

const ATTENTION_ITEM_LIMIT = 6;
const ATTENTION_LEVELS = { critical: 'Alta', warning: 'Media' };

function canResolveVerification(incident) {
    return incident.origen === 'verificacion'
        && (state.identity?.rol === 'administrador'
            || state.identity?.rol === (incident.contenido === 'materiales' ? 'supervisor_materiales' : 'supervisor_frio'));
}

function incidentNote(incident) {
    return [incidentContext(incident), incident.reportado_por?.nombre, ago(incident.antiguedad_minutos)].filter(Boolean).join(' · ');
}

function incidentActions(incident) {
    const actions = [];
    if (canResolveVerification(incident)) {
        actions.push(`<button type="button" data-resolve-verification="${escapeHtml(incident.id)}">Resolver sin ajuste</button>`);
    }
    if (incident.ajustable_materiales) {
        actions.push(`<a href="/oficina/materiales/almacenes?incidencia=${encodeURIComponent(incident.id)}&folio=${encodeURIComponent(incident.folio.id)}&camara=${encodeURIComponent(incident.contexto.camara_id)}">Revisar ajuste</a>`);
    }
    return actions.length ? `<span class="operation-now-alert__item-actions">${actions.join('')}</span>` : '';
}

function attentionItemMarkup(item) {
    const title = item.incident?.detalle ? ` title="${escapeHtml(item.incident.detalle)}"` : '';
    return `<li${title}><span class="operation-now-code">${escapeHtml(item.code)}</span>${item.note ? `<small>${escapeHtml(item.note)}</small>` : ''}${item.incident ? incidentActions(item.incident) : ''}</li>`;
}

function attentionRowMarkup(row) {
    const expanded = state.attentionExpanded.has(row.key);
    const visible = expanded ? row.items : row.items.slice(0, ATTENTION_ITEM_LIMIT);
    const hidden = row.items.length - visible.length;
    const toggle = row.items.length > ATTENTION_ITEM_LIMIT
        ? `<li class="operation-now-alert__more"><button type="button" data-attention-expand="${escapeHtml(row.key)}" aria-expanded="${expanded}">${expanded ? 'Ver menos' : `Ver ${number(hidden)} más`}</button></li>`
        : '';
    const items = visible.length ? `<ul class="operation-now-alert__items">${visible.map(attentionItemMarkup).join('')}${toggle}</ul>` : '';
    const action = row.href
        ? `<a class="operation-now-action" href="${escapeHtml(row.href)}">${escapeHtml(row.action)} <span aria-hidden="true">→</span></a>`
        : `<span class="operation-now-alert__guidance">${escapeHtml(row.action)}</span>`;

    return `<article class="operation-now-alert" data-severity="${row.severity}">
        <span class="operation-now-alert__level operation-now-signal" data-tone="${row.severity}">${ATTENTION_LEVELS[row.severity]}</span>
        <div class="operation-now-alert__main">
            <strong class="operation-now-alert__condition">${escapeHtml(row.condition)}</strong>
            ${row.detail ? `<p>${escapeHtml(row.detail)}</p>` : ''}
            ${items}
        </div>
        <div class="operation-now-alert__origin"><span>${escapeHtml(row.source)}</span>${row.ageMinutes !== null ? `<small>${escapeHtml(`Abierta ${ago(row.ageMinutes)}`)}</small>` : ''}</div>
        ${action}
    </article>`;
}

function renderAttentionRows() {
    const attention = state.attention;
    if (!attention) return;
    document.querySelectorAll('[data-attention-filter]').forEach((button) => {
        button.setAttribute('aria-pressed', String(button.dataset.attentionFilter === state.attentionFilter));
    });

    if (!attention.total) {
        elements.alertRows.innerHTML = '<p class="operation-now-alert-list__calm">Emergencias, ambiente, ocupación, prefrío, planificador, incidencias y sincronización no presentan observaciones.</p>';
        return;
    }

    const rows = attention.rows.filter((row) => state.attentionFilter === 'all' || row.severity === state.attentionFilter);
    elements.alertRows.innerHTML = rows.length
        ? rows.map(attentionRowMarkup).join('')
        : empty(
            state.attentionFilter === 'critical' ? 'Sin condiciones de severidad alta' : 'Sin condiciones de severidad media',
            'Las demás condiciones siguen en «Todas».',
        );
}

function renderAttention(data) {
    const attention = buildAttention(data, { describeIncident: incidentNote });
    state.attention = attention;
    const keys = new Set(attention.rows.map((row) => row.key));
    state.attentionExpanded.forEach((key) => {
        if (!keys.has(key)) state.attentionExpanded.delete(key);
    });

    elements.alertsPanel.dataset.tone = attention.critical ? 'critical' : (attention.total ? 'warning' : 'neutral');
    elements.attentionFilter.hidden = attention.total === 0;
    elements.alertCount.hidden = attention.total === 0;
    elements.alertCount.textContent = number(attention.total);
    elements.alertNoun.textContent = attention.total === 0
        ? 'Sin condiciones abiertas'
        : (attention.total === 1 ? 'condición abierta' : 'condiciones abiertas');
    elements.alertBreakdown.textContent = attention.total
        ? `${number(attention.critical)} ${attention.critical === 1 ? 'alta' : 'altas'} · ${number(attention.warning)} ${attention.warning === 1 ? 'media' : 'medias'} · ordenadas por severidad y antigüedad`
        : `Fuentes revisadas a las ${dateTime(data.generado_at, { timeOnly: true })}`;
    elements.sourcesTime.textContent = `Lectura ${dateTime(data.generado_at, { timeOnly: true })}`;
    elements.sourceRows.innerHTML = attention.sources.map((source) => `<li data-tone="${source.severity || 'neutral'}">
        <i aria-hidden="true"></i><span>${escapeHtml(source.name)}</span><strong>${escapeHtml(number(source.count))}</strong>
    </li>`).join('');

    renderAttentionRows();
    renderTabBadges(buildOperationTabBadges(data, attention));
}

function renderTabBadges(badges) {
    document.querySelectorAll('[data-tab-badge]').forEach((node) => {
        const badge = badges[node.dataset.tabBadge];
        node.hidden = !badge;
        node.textContent = badge?.text || '';
        node.dataset.tone = badge?.tone || 'neutral';
        if (badge?.label) node.title = badge.label;
        else node.removeAttribute('title');
    });
    renderOperatorAvailabilityBadge();
}

function renderOperatorAvailabilityBadge() {
    const badge = document.querySelector('[data-tab-badge="personal"]');
    if (!badge || !state.operatorSnapshot) return;
    badge.hidden = false;
    const count = Number(state.operatorSnapshot.disponibles) || 0;
    badge.textContent = state.operatorFresh ? `${number(count)} ${count === 1 ? 'disponible' : 'disponibles'}` : 'Sin confirmar';
    badge.dataset.tone = state.operatorFresh && count > 0 ? 'success' : 'neutral';
    badge.title = state.operatorFresh ? 'Camareros conectados sin labores pendientes' : 'Disponibilidad sin confirmar';
}

function tunnelProgress(tunnel) {
    const process = tunnel.proceso_activo;
    const foreign = tunnel.proceso_otra_temporada;
    if (!process && foreign) {
        return `<div class="operation-now-tunnel-progress">
            <strong>—</strong>
            <span class="operation-now-meter" data-tone="critical" style="--operation-progress:100%"><i></i></span>
            <small>${escapeHtml(`${foreign.codigo} de ${foreign.temporada_codigo || 'otra temporada'} sin cerrar`)}</small>
        </div>`;
    }
    if (!process) {
        return `<div class="operation-now-tunnel-progress">
            <strong>0 %</strong>
            <span class="operation-now-meter" data-tone="neutral" style="--operation-progress:0%"><i></i></span>
            <small>Sin proceso activo</small>
        </div>`;
    }

    const progress = process.avance_tiempo_objetivo_porcentaje;
    const hasProgress = progress !== null && progress !== undefined;
    const tone = process.objetivo_excedido
        ? 'critical'
        : (tunnel.estado_operacional === 'pendiente_verificacion' ? 'warning' : 'success');
    const detail = hasProgress
        ? `${duration(process.transcurridos_minutos)} de ${duration(process.duracion_objetivo_minutos)}${process.objetivo_excedido ? ` · excedido ${duration(process.minutos_sobre_objetivo)}` : ''}`
        : 'Sin inicio registrado';

    return `<div class="operation-now-tunnel-progress">
        <strong>${hasProgress ? escapeHtml(percent(progress)) : '0 %'}</strong>
        <span class="operation-now-meter" data-tone="${tone}" style="--operation-progress:${clampedPercent(progress)}%"><i></i></span>
        <small>${escapeHtml(detail)}</small>
    </div>`;
}

function tunnelProduct(process) {
    const products = Array.isArray(process?.productos) ? process.productos : [];
    if (!products.length) {
        return '<span class="operation-now-no-product">—</span><span class="operation-now-subtext">Sin informar</span>';
    }

    const labels = products.map((product) => product.etiqueta).filter(Boolean);
    const folios = products.reduce((total, product) => total + (Number(product.folios) || 0), 0);
    const main = products.length === 1 ? labels[0] : `Mixto · ${number(products.length)} productos`;
    const detail = products.length === 1
        ? `${number(folios)} ${folios === 1 ? 'folio' : 'folios'}`
        : labels.join(' · ');

    return `<strong class="operation-now-product" title="${escapeHtml(labels.join(' · '))}">${escapeHtml(main)}</strong><span class="operation-now-subtext">${escapeHtml(detail)}</span>`;
}

function renderTunnels(tunnels = []) {
    if (!tunnels.length) {
        elements.tunnelList.innerHTML = `<tr><td colspan="4">${empty('Sin túneles configurados', 'No existe infraestructura de prefrío para mostrar.')}</td></tr>`;
        return;
    }

    elements.tunnelList.innerHTML = tunnels.map((tunnel) => `<tr>
        <td><span class="operation-now-code">${escapeHtml(tunnel.codigo)}</span><span class="operation-now-subtext">${tunnel.proceso_otra_temporada
            ? `${number(tunnel.posiciones_sin_cerrar)} posiciones registradas sin cerrar`
            : `${number(tunnel.posiciones_ocupadas)} de ${number(tunnel.capacidad_posiciones)} posiciones`}</span></td>
        <td>${signal(tunnelStateLabel(tunnel), tunnelStateTone(tunnel))}</td>
        <td>${tunnelProgress(tunnel)}</td>
        <td>${tunnelProduct(tunnel.proceso_activo)}</td>
    </tr>`).join('');
}

function incidentContext(incident) {
    if (incident.origen === 'recepcion_embalada') {
        return [incident.contexto?.recepcion?.planta, incident.contexto?.recepcion?.guia].filter(Boolean).join(' · ');
    }
    if (incident.origen === 'verificacion') {
        return [incident.contexto?.camara, incident.contexto?.posicion].filter(Boolean).join(' · ');
    }
    if (incident.origen === 'carga') {
        const load = incident.contexto?.carga;
        const location = incident.contexto?.ubicacion_reportada;
        return [load?.codigo, location?.camara && cameraReferenceLabel(location.camara), location?.posicion?.etiqueta].filter(Boolean).join(' · ');
    }
    const maneuver = incident.contexto?.maniobra;
    const task = incident.contexto?.tarea;
    return [maneuver?.titulo, taskLocation(task?.origen), taskLocation(task?.destino)].filter(Boolean).join(' · ');
}

function renderVerifications(data = {}) {
    const summary = data.resumen || {};
    elements.verificationSummary.textContent = data.habilitada
        ? ['productos', 'materiales'].map((tipo) => { const s = data.por_contenido?.[tipo] || {}; return `${tipo === 'materiales' ? 'Materiales' : 'Frío'}: ${number(s.pendientes)} pendientes · ${number(s.completadas)} completadas · ${number(s.vencidas)} vencidas`; }).join(' | ')
        : 'Desactivada';
    const rounds = [...(data.rondas || [])].sort((a, b) => String(a.contenido).localeCompare(String(b.contenido)) || a.camarero.localeCompare(b.camarero));
    elements.verificationRows.innerHTML = rounds.length ? rounds.map((round) => `<tr>
        <td><strong>${escapeHtml(round.camarero)}</strong><span class="operation-now-subtext">${round.contenido === 'materiales' ? 'Materiales' : 'Frío'} · Turno ${escapeHtml(round.turno)}</span></td>
        <td>${number(round.completadas)} de ${number(round.objetivo)} · ${number(round.pendientes)} pendientes</td>
        <td>${signal(humanize(round.estado), round.estado === 'vencida' ? 'critical' : round.estado === 'completada' ? 'success' : 'warning')}</td>
        <td>${escapeHtml(dateTime(round.vence_at))}</td>
    </tr>`).join('') : `<tr><td colspan="4">${empty(data.habilitada ? 'Sin rondas generadas en este turno' : 'Verificación de ubicaciones desactivada', 'Solo se generan cuando un camarero comienza a trabajar.')}</td></tr>`;
}

function render(data) {
    state.snapshot = data;
    renderHeader(data);
    renderMetrics(data);
    renderSync(data.sincronizacion, data.generado_at);
    renderPlanner(data.planificador);
    renderCameras(data.camaras);
    renderOperators(state.operatorSnapshot ? (state.operatorFresh ? state.operatorSnapshot.camareros : state.operatorSnapshot.camareros.map((operator) => ({ ...operator, estado_presencia: 'sin_confirmar', disponible_desde: null }))) : data.camareros);
    renderTunnels(data.prefrio?.tuneles);
    renderVerifications(data.verificaciones);
    // La lista de atención y las insignias se recalculan en cada lectura; la pestaña
    // visible no cambia aunque aparezca una condición nueva. Va antes del plano porque
    // la ficha del recinto lee sus condiciones.
    renderAttention(data);
    renderFacility(data);
    elements.workspace.setAttribute('aria-busy', 'false');
}

function showConnectionIssue(error) {
    elements.connection.hidden = false;
    elements.connection.dataset.tone = state.snapshot ? 'warning' : 'critical';
    elements.connectionTitle.textContent = state.snapshot ? 'Última lectura conservada' : 'Operación no disponible';
    elements.connectionDetail.textContent = state.snapshot
        ? `${error.message} Los datos visibles corresponden a ${dateTime(state.snapshot.generado_at)}.`
        : error.message;
    elements.liveSignal.dataset.tone = 'critical';
    elements.liveText.textContent = navigator.onLine ? 'Servidor sin respuesta' : 'Equipo sin conexión';
}

async function load({ blocking = false, silent = false, propagate = false } = {}) {
    if (state.loading) return false;
    const requestMapRevision = state.mapRevision;
    state.loading = true;
    setBusy(blocking, 'Consultando la operación…');

    try {
        const payload = await api('/api/operacion-ahora', { cache: 'no-store' });
        const incoming = validateSnapshot(payload.data);
        render(reconcilePlantSnapshot(incoming, state.snapshot, requestMapRevision, state.mapRevision));
        elements.connection.hidden = true;
        if (!silent) toast('Operación actualizada.');
        return true;
    } catch (error) {
        showConnectionIssue(error);
        if (!silent || !state.snapshot) toast(error.message, true);
        if (error.status === 403) window.location.replace('/oficina/administracion');
        if (propagate) throw error;
        return false;
    } finally {
        state.loading = false;
        setBusy(false);
    }
}

async function loadOperatorAvailability() {
    const token = state.token;
    if (!token || state.operatorReading) return;
    state.operatorReading = true;
    try {
        const response = await api('/api/operacion-ahora/camareros');
        const data = response.data;
        if (token !== state.token) return;
        if (!Array.isArray(data.camareros) || !data.temporada_id) throw new Error('Lectura de camareros incompleta.');
        const received = state.availabilityTracker.receive(data);
        if (!received.applied) return;
        state.operatorSnapshot = data;
        state.operatorFresh = true;
        renderOperators(data.camareros);
        renderOperatorAvailabilityBadge();
        setText('operatorAvailableCount', `Disponibles: ${number(data.disponibles)}`);
        setText('operatorAvailabilityNotice', `${number(data.disponibles)} camareros disponibles · última lectura ${dateTime(data.generado_at, { timeOnly: true })}`);
        for (const operator of received.alerts) toast(`${operator.usuario.nombre} terminó sus labores y quedó disponible.`);
    } catch (error) {
        if (token !== state.token) return;
        state.operatorFresh = false;
        renderOperatorAvailabilityBadge();
        setText('operatorAvailableCount', 'Disponibles: sin confirmar');
        setText('operatorAvailabilityNotice', `Sin comunicación reciente. La disponibilidad no está confirmada.${state.operatorSnapshot ? ` Última lectura ${dateTime(state.operatorSnapshot.generado_at, { timeOnly: true })}.` : ''}`);
        if (state.operatorSnapshot) renderOperators(state.operatorSnapshot.camareros.map((operator) => ({ ...operator, estado_presencia: 'sin_confirmar', disponible_desde: null })));
        throw error;
    } finally {
        if (token === state.token) state.operatorReading = false;
    }
}

function startPolling() {
    state.poller?.stop();
    const seconds = Math.max(10, Number(state.snapshot?.actualizacion_sugerida_segundos || 30));
    state.poller = createOperationalPoller(
        () => load({ silent: true, propagate: true }),
        {
            intervalMs: seconds * 1000,
            canRun: () => Boolean(state.token) && !state.loading && !state.mapSaving && !state.interventionSaving,
            onError: (error) => showConnectionIssue(error),
        },
    );
    state.poller.start();
    state.operatorPoller?.stop();
    state.operatorPoller = createOperationalPoller(loadOperatorAvailability, {
        intervalMs: 5_000, maxBackoffMs: 30_000,
        canRun: () => Boolean(state.token) && !state.operatorReading,
        onError: () => {},
    });
    state.operatorPoller.start();
    void loadOperatorAvailability().catch(() => {});
}

async function logout() {
    try {
        await fetch('/api/acceso-oficina', {
            method: 'DELETE',
            headers: { Accept: 'application/json', Authorization: `Bearer ${state.token}` },
        });
    } catch {
        // La sesión local se cierra aunque el servidor no responda.
    }
    clearSession();
}

function renderMapCatalog() {
    const available = availableCatalog(state.snapshot?.planta?.catalogo || [], state.mapDraft);
    elements.mapCatalogItems.innerHTML = available.length
        ? available.map((item) => `<button type="button" data-map-add="${escapeHtml(catalogKey(item.tipo, item.id))}">
            <span data-type="${escapeHtml(item.tipo)}">${escapeHtml(mapTypeLabel(item.tipo))}</span>
            <strong>${escapeHtml(item.codigo)}</strong><small>${escapeHtml(item.nombre)}</small>
        </button>`).join('')
        : '<p>Todos los recintos existentes ya están incorporados.</p>';
}

function renderConnectionList(selected) {
    const byId = new Map(state.mapDraft.map((item) => [item.id, item]));
    const own = describeConnections(state.mapDraft, state.mapConnections)
        .filter((connection) => selected && (connection.desde === selected.id || connection.hacia === selected.id));
    elements.mapConnectionList.innerHTML = own.length
        ? own.map((connection) => {
            const other = byId.get(connection.desde === selected.id ? connection.hacia : connection.desde);
            return `<li${connection.valid ? '' : ' class="is-invalid"'}><span>${escapeHtml(other?.nombre || 'Elemento eliminado')}${connection.valid ? '' : ' · sin borde compartido'}</span><button type="button" data-connection-remove="${escapeHtml(connection.id)}" aria-label="Quitar conexión con ${escapeHtml(other?.nombre || 'elemento')}">Quitar</button></li>`;
        }).join('')
        : '<li class="is-empty">Sin conexiones</li>';
}

function renderNetworkSummary() {
    const places = state.mapDraft.filter((item) => !['zona', 'pasillo'].includes(item.tipo)).length;
    const withoutAccess = unconnectedPlaces(state.mapDraft, state.mapConnections).length;
    const invalid = describeConnections(state.mapDraft, state.mapConnections).filter((connection) => !connection.valid).length;
    elements.mapNetworkSummary.dataset.tone = invalid ? 'critical' : (withoutAccess ? 'warning' : 'success');
    elements.mapNetworkSummary.innerHTML = `<strong>RED DE LA PLANTA</strong>
        <span>${number(state.mapConnections.length)} conexiones · ${number(places - withoutAccess)} de ${number(places)} recintos con acceso</span>
        ${invalid ? `<span>${number(invalid)} conexiones quedaron sin borde compartido; acerca los elementos o quítalas antes de guardar.</span>` : ''}`;
}

const RECINTO_TYPES = ['camara', 'tunel', 'anden', 'almacen'];

function renderProportionField() {
    const value = String(Math.round(state.mapProportion * 10_000) / 10_000);
    const select = elements.mapProportion;
    if (![...select.options].some((option) => Math.abs(Number(option.value) - state.mapProportion) < .0001)) {
        select.querySelector('[data-custom]')?.remove();
        select.insertAdjacentHTML('beforeend', `<option value="${value}" data-custom>Proporción guardada (${escapeHtml(number(state.mapProportion, 2))})</option>`);
    }
    select.value = [...select.options].find((option) => Math.abs(Number(option.value) - state.mapProportion) < .0001)?.value || value;
}

// Un recinto del dibujo puede apuntar a otro del catálogo sin perder su forma ni sus
// conexiones; así se resuelve lo que el plano base no pudo enlazar solo. Un recinto que
// todavía no existe en FoliOS (una cámara nueva) puede quedar como área y enlazarse
// después, cuando se cree.
const KEEP_AS_AREA = 'area';
const PLACE_GROUPS = [['camara', 'Cámaras'], ['tunel', 'Túneles'], ['anden', 'Andenes'], ['almacen', 'Bodegas']];

function placeOption(item) {
    return `<option value="${escapeHtml(catalogKey(item.tipo, item.id))}">${escapeHtml([item.codigo, item.nombre].filter(Boolean).join(' · '))}</option>`;
}

function renderReferenceField(selected) {
    const isPlace = Boolean(selected && RECINTO_TYPES.includes(selected.tipo));
    const isZone = selected?.tipo === 'zona';
    elements.mapReferenceField.hidden = !isPlace && !isZone;
    if (!isPlace && !isZone) return;
    const catalog = state.snapshot?.planta?.catalogo || [];
    const available = availableCatalog(catalog, state.mapDraft);

    if (isZone) {
        elements.mapReference.innerHTML = `<option value="">Área del dibujo, sin recinto</option>${PLACE_GROUPS
            .map(([type, label]) => {
                const items = available.filter((item) => item.tipo === type);
                return items.length ? `<optgroup label="${escapeHtml(label)}">${items.map(placeOption).join('')}</optgroup>` : '';
            })
            .join('')}`;
        elements.mapReference.value = '';
        return;
    }

    const current = catalog.find((item) => item.tipo === selected.tipo && item.id === selected.referencia_id);
    elements.mapReference.innerHTML = [
        current ? '' : '<option value="">Sin asignar</option>',
        ...[...(current ? [current] : []), ...available.filter((item) => item.tipo === selected.tipo)].map(placeOption),
        `<option value="${KEEP_AS_AREA}">Dejar como área (aún no existe en FoliOS)</option>`,
    ].join('');
    elements.mapReference.value = current ? catalogKey(current.tipo, current.id) : '';
}

function renderEditor() {
    elements.editorStage.style.setProperty('--plant-aspect', String(state.mapProportion));
    renderMapStage(elements.editorStage, state.mapDraft, state.mapEditing, state.mapConnections);
    renderMapCatalog();
    renderProportionField();
    const selected = state.mapDraft.find((item) => item.id === state.mapSelectedId);
    elements.mapInspector.hidden = !state.mapEditing || !selected;
    elements.mapSelectionName.textContent = selected?.nombre || 'Ninguno';
    renderReferenceField(selected);
    elements.mapConnect.textContent = state.mapConnecting ? 'Cancelar conexión' : 'Conectar con…';
    elements.mapConnect.setAttribute('aria-pressed', String(Boolean(state.mapConnecting)));
    elements.editorStage.classList.toggle('is-connecting', Boolean(state.mapConnecting));
    renderConnectionList(selected);
    renderNetworkSummary();
    setMapZoom('editor', state.editorZoom);
}

function openMapDialog(editing) {
    const plant = state.snapshot?.planta;
    if (!plant) return;
    state.mapEditing = Boolean(editing && plant.puede_editar);
    state.mapDraft = JSON.parse(JSON.stringify(currentMapElements(plant)));
    state.mapConnections = JSON.parse(JSON.stringify(plant.configurado ? (plant.conexiones || []) : []));
    state.mapConnecting = null;
    state.mapSelectedId = null;
    state.mapProportion = plantAspect(plant);
    elements.mapTemplateReport.hidden = true;
    elements.mapDialogTitle.textContent = state.mapEditing ? 'Editar plano operacional' : plant.nombre;
    elements.mapDialogHint.textContent = state.mapEditing
        ? 'Mueve, redimensiona y organiza la representación real de la planta'
        : 'Vista ampliada con estados operacionales en vivo';
    elements.mapCatalog.hidden = !state.mapEditing;
    elements.mapSave.hidden = !state.mapEditing;
    elements.mapDialog.classList.toggle('is-editing', state.mapEditing);
    renderEditor();
    elements.mapDialog.showModal();
}

function addCatalogItem(key) {
    const item = (state.snapshot?.planta?.catalogo || [])
        .find((candidate) => catalogKey(candidate.tipo, candidate.id) === key);
    if (!item) return;
    const sequence = state.mapDraft.length;
    const x = 400 + (sequence % 5) * 1800;
    const y = 400 + (Math.floor(sequence / 5) % 5) * 1500;
    state.mapDraft.push({
        id: createUuid(),
        tipo: item.tipo,
        referencia_id: item.id,
        nombre: item.nombre || item.codigo,
        categoria: null,
        x,
        y,
        ancho: 1500,
        alto: 1100,
        rotacion: 0,
    });
    state.mapSelectedId = state.mapDraft.at(-1).id;
    renderEditor();
}

function selectedMapElement() {
    return state.mapDraft.find((item) => item.id === state.mapSelectedId);
}

async function saveMap() {
    const pending = unassignedPlaces(state.mapDraft);
    if (pending.length) {
        toast(`Asigna un recinto de FoliOS a ${pending.map((item) => item.nombre).join(', ')}, déjalo como área o quítalo antes de guardar.`, true);
        state.mapSelectedId = pending[0].id;
        renderEditor();
        return;
    }
    state.mapSaving = true;
    elements.mapSave.disabled = true;
    elements.mapSave.textContent = 'Guardando…';
    try {
        const response = await api('/api/administracion/operacion-ahora/plano', {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                version_esperada: state.snapshot.planta.version,
                nombre: state.snapshot.planta.nombre || 'Planta principal',
                proporcion: Math.round(state.mapProportion * 10_000) / 10_000,
                elementos: state.mapDraft,
                conexiones: state.mapConnections,
            }),
        });
        state.snapshot.planta = {
            ...state.snapshot.planta,
            ...response.data,
            configurado: true,
        };
        state.mapRevision += 1;
        renderFacility(state.snapshot);
        elements.mapDialog.close();
        toast(`Plano guardado como versión ${response.data.version}.`);
    } catch (error) {
        toast(error.status === 409 ? `${error.message} Se conservaron tus cambios en pantalla.` : error.message, true);
    } finally {
        state.mapSaving = false;
        elements.mapSave.disabled = false;
        elements.mapSave.textContent = 'Guardar plano';
    }
}

function beginMapPointer(event) {
    if (!state.mapEditing || event.button !== 0) return;
    const node = event.target.closest('.operation-map-node');
    if (!node) return;
    const item = state.mapDraft.find((candidate) => candidate.id === node.dataset.id);
    if (!item) return;
    event.preventDefault();
    if (state.mapConnecting) {
        finishConnection(item.id);
        return;
    }
    state.mapSelectedId = item.id;
    state.mapDrag = {
        id: item.id,
        mode: event.target.closest('.operation-map-node__resize') ? 'resize' : 'move',
        startX: event.clientX,
        startY: event.clientY,
        initial: { ...item },
    };
    renderEditor();
}

function finishConnection(targetId) {
    const origin = state.mapConnecting;
    if (targetId === origin) return;
    const problem = connectionProblem(state.mapDraft, origin, targetId, state.mapConnections);
    if (problem) {
        toast(problem, true);
        return;
    }
    state.mapConnections.push({ id: createUuid(), desde: origin, hacia: targetId });
    state.mapConnecting = null;
    renderEditor();
}

function moveMapPointer(event) {
    if (!state.mapDrag) return;
    const rect = elements.editorStage.getBoundingClientRect();
    const deltaX = (event.clientX - state.mapDrag.startX) / rect.width * 10_000;
    const deltaY = (event.clientY - state.mapDrag.startY) / rect.height * 10_000;
    const index = state.mapDraft.findIndex((item) => item.id === state.mapDrag.id);
    if (index < 0) return;
    state.mapDraft[index] = state.mapDrag.mode === 'resize'
        ? resizeElement(state.mapDrag.initial, deltaX, deltaY)
        : moveElement(state.mapDrag.initial, deltaX, deltaY);
    renderEditor();
}

function endMapPointer() {
    state.mapDrag = null;
}

byId('operationMapZoomOut').addEventListener('click', () => setMapZoom('map', state.mapZoom - .1));
byId('operationMapZoomIn').addEventListener('click', () => setMapZoom('map', state.mapZoom + .1));
byId('operationEditorZoomOut').addEventListener('click', () => setMapZoom('editor', state.editorZoom - .1));
byId('operationEditorZoomIn').addEventListener('click', () => setMapZoom('editor', state.editorZoom + .1));
byId('operationMapExpand').addEventListener('click', () => openMapDialog(false));
elements.mapEdit.addEventListener('click', () => openMapDialog(true));
byId('operationMapClose').addEventListener('click', () => elements.mapDialog.close());
elements.mapSave.addEventListener('click', () => void saveMap());
elements.mapCatalogItems.addEventListener('click', (event) => {
    const button = event.target.closest('[data-map-add]');
    if (button) addCatalogItem(button.dataset.mapAdd);
});
function templateReport(result) {
    // Cada enlace queda a la vista: es lo que hay que revisar antes de guardar.
    const lines = [
        `<strong>${escapeHtml(number(result.asignados.length))} recintos enlazados. Revísalos antes de guardar:</strong>`,
        `<ul>${result.asignados.map((link) => `<li>${escapeHtml(link.plano)} <span aria-hidden="true">→</span> ${escapeHtml(link.recinto)}</li>`).join('')}</ul>`,
    ];
    if (result.faltantes.length) {
        lines.push(`<span>Por asignar: ${result.faltantes.map((item) => escapeHtml(item.nombre)).join(', ')}. Selecciónalos y elige su recinto; si todavía no existe en FoliOS, déjalo como área y enlázalo cuando se cree.</span>`);
    }
    if (result.sinUbicar.length) {
        lines.push(`<span>En FoliOS pero no en el dibujo: ${result.sinUbicar.map((item) => escapeHtml(item.codigo || item.nombre)).join(', ')}.</span>`);
    }
    return lines.join('');
}

elements.mapTemplate.addEventListener('click', () => {
    if (!state.mapEditing) return;
    if (state.mapDraft.length && !window.confirm('El plano base reemplaza el borrador actual del editor. Nada se guarda hasta pulsar «Guardar plano». ¿Continuar?')) return;
    const result = buildPlantFromTemplate(AGROROSARIO_PLANT, state.snapshot?.planta?.catalogo || []);
    state.mapDraft = result.elementos;
    state.mapConnections = result.conexiones;
    state.mapProportion = result.proporcion;
    state.mapSelectedId = unassignedPlaces(result.elementos)[0]?.id || null;
    state.mapConnecting = null;
    elements.mapTemplateReport.innerHTML = templateReport(result);
    elements.mapTemplateReport.dataset.tone = result.faltantes.length ? 'warning' : 'success';
    elements.mapTemplateReport.hidden = false;
    renderEditor();
});

elements.mapProportion.addEventListener('change', () => {
    const value = Number(elements.mapProportion.value);
    if (!Number.isFinite(value) || value <= 0) return;
    state.mapProportion = value;
    renderEditor();
});

elements.mapReference.addEventListener('change', () => {
    const index = state.mapDraft.findIndex((item) => item.id === state.mapSelectedId);
    if (index < 0) return;
    const selected = state.mapDraft[index];
    const value = elements.mapReference.value;
    if (value === KEEP_AS_AREA) {
        state.mapDraft[index] = keepElementAsArea(selected);
    } else if (value) {
        const item = (state.snapshot?.planta?.catalogo || [])
            .find((candidate) => catalogKey(candidate.tipo, candidate.id) === value);
        if (item) state.mapDraft[index] = linkElementToPlace(selected, item);
    } else if (selected.tipo !== 'zona') {
        state.mapDraft[index] = { ...selected, referencia_id: null };
    }
    renderEditor();
});

byId('operationMapZoneForm').addEventListener('submit', (event) => {
    event.preventDefault();
    const name = byId('operationMapZoneName').value.trim();
    if (!name) return;
    const kind = byId('operationMapZoneType').value;
    // Un pasillo es un elemento de circulación angosto; el resto son áreas.
    state.mapDraft.push(kind === 'pasillo'
        ? {
            id: createUuid(), tipo: 'pasillo', referencia_id: null, nombre: name,
            categoria: null, x: 500, y: 500, ancho: 3000, alto: 300, rotacion: 0,
        }
        : {
            id: createUuid(), tipo: 'zona', referencia_id: null, nombre: name,
            categoria: kind, x: 500, y: 500, ancho: 2200, alto: 1400, rotacion: 0,
        });
    state.mapSelectedId = state.mapDraft.at(-1).id;
    event.target.reset();
    renderEditor();
});
byId('operationMapRotate').addEventListener('click', () => {
    const selected = selectedMapElement();
    if (!selected) return;
    const availableWidth = 10_000 - selected.x;
    const availableHeight = 10_000 - selected.y;
    [selected.ancho, selected.alto] = [Math.min(selected.alto, availableWidth), Math.min(selected.ancho, availableHeight)];
    selected.rotacion = (selected.rotacion + 90) % 360;
    renderEditor();
});
byId('operationMapRemove').addEventListener('click', () => {
    const result = removeElement(state.mapDraft, state.mapConnections, state.mapSelectedId);
    state.mapDraft = result.elementos;
    state.mapConnections = result.conexiones;
    state.mapSelectedId = null;
    state.mapConnecting = null;
    renderEditor();
});
elements.mapConnect.addEventListener('click', () => {
    state.mapConnecting = state.mapConnecting ? null : state.mapSelectedId;
    renderEditor();
});
elements.mapConnectionList.addEventListener('click', (event) => {
    const button = event.target.closest('[data-connection-remove]');
    if (!button) return;
    state.mapConnections = state.mapConnections.filter((connection) => connection.id !== button.dataset.connectionRemove);
    renderEditor();
});
elements.mapDialog.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape' || !state.mapConnecting) return;
    event.preventDefault();
    state.mapConnecting = null;
    renderEditor();
});
elements.editorStage.addEventListener('pointerdown', beginMapPointer);
window.addEventListener('pointermove', moveMapPointer);
window.addEventListener('pointerup', endMapPointer);
elements.mapDialog.addEventListener('click', (event) => {
    if (event.target === elements.mapDialog) elements.mapDialog.close();
});

elements.plannerDecisionRows.addEventListener('click', (event) => {
    const button = event.target.closest('[data-maneuver-detail]');
    if (!button) return;
    const decisions = state.snapshot?.planificador?.arbitraje?.ciclo?.decisiones || [];
    const selected = decisions.find((decision) => decision.maniobra_id === button.dataset.maneuverDetail);
    if (!selected) {
        toast('La maniobra ya no pertenece al ciclo vigente.', true);
        return;
    }

    state.selectedManeuverId = selected.maniobra_id;
    supervisionDrawer.open(selected);
});

async function openCycleComparison() {
    if (state.comparisonLoading || !state.snapshot?.planificador?.arbitraje?.ciclo) return;
    state.comparisonLoading = true;
    state.comparisonModel = null;
    elements.plannerComparisonOpen.disabled = true;
    elements.comparisonContent.innerHTML = '<div class="operation-now-empty">Comparando ciclos confirmados…</div>';
    elements.comparisonDialog.showModal();

    try {
        const payload = await api('/api/operacion-ahora/planificador/comparacion', { cache: 'no-store' });
        state.comparisonModel = buildCycleComparison(payload.data);
        elements.comparisonContent.innerHTML = renderCycleComparison(state.comparisonModel);
    } catch (error) {
        elements.comparisonContent.innerHTML = `<div class="operation-now-empty"><strong>No fue posible comparar los ciclos</strong><span>${escapeHtml(error.message)}</span></div>`;
    } finally {
        state.comparisonLoading = false;
        elements.plannerComparisonOpen.disabled = !state.snapshot?.planificador?.arbitraje?.ciclo;
    }
}

async function openCycleReplay(reference) {
    if (state.replayLoading || !['actual', 'anterior'].includes(reference)) return;
    state.replayLoading = true;
    elements.comparisonContent.innerHTML = '<div class="operation-now-empty">Reproduciendo el ciclo desde su evidencia histórica…</div>';

    try {
        const payload = await api(`/api/operacion-ahora/planificador/replay?ciclo=${reference}`, { cache: 'no-store' });
        elements.comparisonContent.innerHTML = renderCycleReplay(buildCycleReplay(payload.data));
    } catch (error) {
        elements.comparisonContent.innerHTML = `<div class="operation-now-empty"><strong>No fue posible reproducir el ciclo</strong><span>${escapeHtml(error.message)}</span><button type="button" data-cycle-comparison-return>Volver a la comparación</button></div>`;
    } finally {
        state.replayLoading = false;
    }
}

elements.plannerComparisonOpen.addEventListener('click', () => void openCycleComparison());
elements.comparisonContent.addEventListener('click', (event) => {
    const replay = event.target.closest('[data-cycle-replay]');
    if (replay) {
        void openCycleReplay(replay.dataset.cycleReplay);
        return;
    }

    if (event.target.closest('[data-cycle-comparison-return]')) {
        elements.comparisonContent.innerHTML = state.comparisonModel
            ? renderCycleComparison(state.comparisonModel)
            : '<div class="operation-now-empty">La comparación ya no está disponible.</div>';
    }
});
elements.comparisonClose.addEventListener('click', () => elements.comparisonDialog.close());
elements.comparisonDialog.addEventListener('click', (event) => {
    if (event.target === elements.comparisonDialog) elements.comparisonDialog.close();
});

// Pestañas: solo la persona cambia de vista. Al cambiar, el contenido parte bajo la barra.
let tabsReady = false;
createOperationTabs({
    root: elements.content,
    onChange: () => {
        if (!tabsReady) return;
        const stuck = Number.parseFloat(getComputedStyle(elements.tabsNav).top);
        if (!Number.isFinite(stuck)) return;
        const offset = elements.workspace.getBoundingClientRect().top - stuck - elements.tabsNav.offsetHeight;
        if (offset < 0) window.scrollBy({ top: offset - 8 });
    },
});
tabsReady = true;

document.querySelectorAll('[data-attention-filter]').forEach((button) => {
    button.addEventListener('click', () => {
        state.attentionFilter = button.dataset.attentionFilter;
        renderAttentionRows();
    });
});

elements.alertRows.addEventListener('click', (event) => {
    const toggle = event.target.closest('[data-attention-expand]');
    if (!toggle) return;
    const key = toggle.dataset.attentionExpand;
    if (state.attentionExpanded.has(key)) state.attentionExpanded.delete(key);
    else state.attentionExpanded.add(key);
    renderAttentionRows();
    elements.alertRows.querySelector(`[data-attention-expand="${CSS.escape(key)}"]`)?.focus();
});

elements.facilityMap.addEventListener('click', (event) => selectPlantNode(event.target.closest('.plant-node')));
elements.facilityMap.addEventListener('keydown', (event) => {
    if (event.key !== 'Enter' && event.key !== ' ') return;
    const node = event.target.closest('.plant-node');
    if (!node) return;
    event.preventDefault();
    selectPlantNode(node);
});

elements.refresh.addEventListener('click', async () => {
    const success = await load();
    if (success && !state.poller) startPolling();
});

// PIN de operadores: el supervisor de frío restablece el PIN de camareros, operadores de
// Prefrío y validadores (el administrador también lo hace desde Accesos).
const pinElements = {
    open: byId('operationPinsOpen'),
    dialog: byId('operationPinsDialog'),
    close: byId('operationPinsClose'),
    content: byId('operationPinsContent'),
};

function pinStatusLabel(pin) {
    if (pin?.bloqueado_hasta) return `Bloqueado hasta ${new Date(pin.bloqueado_hasta).toLocaleTimeString('es-CL', { hour: '2-digit', minute: '2-digit' })}`;
    return pin?.configurado ? 'PIN creado' : 'Sin PIN';
}

async function loadOperatorPins() {
    pinElements.content.innerHTML = '<div class="operation-now-empty">Cargando operadores…</div>';
    try {
        const operators = (await api('/api/operacion/pines-operadores')).data || [];
        pinElements.content.innerHTML = operators.length ? `<table class="operation-pin-table"><thead><tr><th>Operador</th><th>Rol</th><th>Estado</th><th><span class="office-visually-hidden">Acción</span></th></tr></thead><tbody>${operators.map((operator) => `<tr>
            <td><strong>${escapeHtml(operator.nombre)}</strong></td>
            <td>${escapeHtml(humanize(operator.rol))}</td>
            <td><span data-pin-state="${operator.pin?.bloqueado_hasta ? 'bloqueado' : (operator.pin?.configurado ? 'creado' : 'sin')}">${escapeHtml(pinStatusLabel(operator.pin))}</span></td>
            <td><button type="button" data-reset-pin="${escapeHtml(operator.id)}" data-operator-name="${escapeHtml(operator.nombre)}" ${operator.pin?.configurado || operator.pin?.bloqueado_hasta ? '' : 'disabled'}>Restablecer</button></td>
        </tr>`).join('')}</tbody></table>` : '<div class="operation-now-empty">No hay operadores de frío activos.</div>';
    } catch (error) {
        pinElements.content.innerHTML = `<div class="operation-now-empty">${escapeHtml(error.message)}</div>`;
    }
}

pinElements.open?.addEventListener('click', () => {
    pinElements.dialog.showModal();
    void loadOperatorPins();
});
pinElements.close?.addEventListener('click', () => pinElements.dialog.close());
pinElements.content?.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-reset-pin]');
    if (!button || button.disabled) return;
    if (!window.confirm(`¿Restablecer el PIN de ${button.dataset.operatorName}? Deberá crear uno nuevo en la tablet.`)) return;
    button.disabled = true;
    try {
        await api(`/api/administracion/usuarios/${encodeURIComponent(button.dataset.resetPin)}/restablecer-pin`, { method: 'POST' });
        toast(`PIN de ${button.dataset.operatorName} restablecido.`);
        await loadOperatorPins();
    } catch (error) {
        toast(error.message, true);
        button.disabled = false;
    }
});

async function boot() {
    if (!state.token || !state.identity || !can('puede_consultar_panel_gerencial') || !hasModule('gerencia.panel')) {
        window.location.replace('/oficina/accesos');
        return;
    }

    const name = state.identity.nombre || state.identity.name || 'Usuario';
    setText('officeUserName', name);
    setText('officeUserRole', state.identity.perfil_acceso?.nombre || humanize(state.identity.rol || 'consulta'));
    setText('officeInitials', name.split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0]).join('').toUpperCase());
    byId('officeLogoutButton')?.addEventListener('click', () => void logout());
    elements.app.classList.remove('is-hidden');
    const shiftDutiesLink = byId('operationShiftDutiesOpen');
    if (shiftDutiesLink) shiftDutiesLink.hidden = !can('puede_supervisar_camaras_productos') || !hasModule('frigorifico.camaras');
    if (pinElements.open) pinElements.open.hidden = !can('puede_gestionar_pines_operadores');

    if (!navigator.onLine) showConnectionIssue(new ApiError('El equipo no tiene conexión de red.'));
    const success = await load({ blocking: true, silent: true });
    if (success) startPolling();
}

void boot();

const verificationResolutionDialog = document.createElement('dialog');
verificationResolutionDialog.innerHTML = '<form><h2>Resolver sin ajuste</h2><p>Esta resolución no cambia el inventario.</p><label>Tipo de resolución<select name="tipo_resolucion" required><option value="reubicado">Reubicado</option><option value="error_de_conteo">Error de conteo</option><option value="otro">Otro</option></select></label><label>Motivo<textarea name="motivo" required maxlength="2000"></textarea></label><p role="alert"></p><button type="submit">Resolver incidencia</button><button type="button" data-cancel-resolution>Cancelar</button></form>';
document.body.append(verificationResolutionDialog);
let verificationResolution = null;
elements.alertRows.addEventListener('click', (event) => {
    const button = event.target.closest('[data-resolve-verification]'); if (!button) return;
    verificationResolution = { id: button.dataset.resolveVerification, operation: createUuid() };
    verificationResolutionDialog.querySelector('form').reset(); verificationResolutionDialog.querySelector('[role="alert"]').textContent = ''; verificationResolutionDialog.showModal();
});
verificationResolutionDialog.querySelector('[data-cancel-resolution]').addEventListener('click', () => verificationResolutionDialog.close());
verificationResolutionDialog.querySelector('form').addEventListener('submit', async (event) => {
    event.preventDefault(); const form = event.target; const button = form.querySelector('[type="submit"]'); button.disabled = true;
    try {
        await api(`/api/verificaciones-ubicacion/incidencias/${verificationResolution.id}/resolver`, { method: 'POST', body: JSON.stringify({ operacion_id: verificationResolution.operation, tipo_resolucion: form.elements.tipo_resolucion.value, motivo: form.elements.motivo.value }) });
        verificationResolutionDialog.close(); await load({ blocking: false });
    } catch (error) { form.querySelector('[role="alert"]').textContent = error.message; }
    finally { button.disabled = false; }
});
