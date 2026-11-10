import { BarController, BarElement, CategoryScale, Chart, Legend, LinearScale, Tooltip } from 'chart.js';
import { createOperationalPoller } from './shared/operational-poller';
import { bulkWidths, productStateRows } from './shared/management-product-states';
import { readManagementChartPaletteSafely } from './shared/management-chart-theme';

// Gerencia solo dibuja barras (horizontales y apiladas) con leyenda y tooltip. Registrar
// solo esas piezas evita cargar 'chart.js/auto', que trae todos los tipos de gráfico.
Chart.register(BarController, BarElement, CategoryScale, LinearScale, Legend, Tooltip);

const byId = (id) => document.getElementById(id);

const elements = {
    access: byId('officeAccess'),
    app: byId('officeApp'),
    login: byId('officeLoginForm'),
    loginError: byId('officeLoginError'),
    userName: byId('officeUserName'),
    userRole: byId('officeUserRole'),
    initials: byId('officeInitials'),
    logout: byId('officeLogoutButton'),
    camerasNav: byId('officeCamerasNav'),
    loadsNav: byId('officeLoadsNav'),
    materialsNav: byId('officeMaterialsNav'),
    prefrioNav: byId('officePrefrioNav'),
    accessesNav: byId('officeAccessesNav'),
    romanaNav: byId('officeRomanaNav'),
    rawMaterialNav: byId('officeRawMaterialNav'),
    seasonSelect: byId('managementSeasonSelect'),
    seasonStatus: byId('managementSeasonStatus'),
    refresh: byId('refreshDashboardButton'),
    lastUpdated: byId('lastUpdatedAt'),
    refreshStatus: byId('refreshStatus'),
    cameraOccupancyKpi: byId('cameraOccupancyKpi'),
    cameraOccupancyProgress: byId('cameraOccupancyProgress'),
    cameraOccupancyDetail: byId('cameraOccupancyDetail'),
    cameraOccupancyList: byId('cameraOccupancyChart'),
    productPalletsKpi: byId('productPalletsKpi'),
    productSaldosKpi: byId('productSaldosKpi'),
    productBoxesKpi: byId('productBoxesKpi'),
    productAvailabilityDetail: byId('productAvailabilityDetail'),
    materialItems: byId('materialItemsKpi'),
    materialFolios: byId('materialFoliosKpi'),
    materialUnits: byId('materialUnitsKpi'),
    precoolingAvailable: byId('precoolingAvailableKpi'),
    precoolingOccupancyProgress: byId('precoolingOccupancyProgress'),
    precoolingDetail: byId('precoolingDetail'),
    weighbridgeSeasonKpi: byId('weighbridgeSeasonKpi'),
    weighbridgeTodayKpi: byId('weighbridgeTodayKpi'),
    weighbridgeWeekKpi: byId('weighbridgeWeekKpi'),
    weighbridgeDetail: byId('weighbridgeDetail'),
    weighbridgeSummary: byId('weighbridgeChartSummary'),
    weighbridgeClientRows: byId('weighbridgeClientRows'),
    materialUnitSelect: byId('materialUnitSelect'),
    materialSummary: byId('materialChartSummary'),
    productEmpty: byId('productEmpty'),
    productDetails: byId('productDetails'),
    productPallets: byId('productPalletsMetric'),
    productPalletsDetail: byId('productPalletsDetail'),
    productSaldos: byId('productSaldosMetric'),
    productSaldosDetail: byId('productSaldosDetail'),
    productBoxes: byId('productBoxesMetric'),
    productBoxesDetail: byId('productBoxesDetail'),
    productOccupancy: byId('productOccupancyMetric'),
    productOccupancyDetail: byId('productOccupancyDetail'),
    productStateRows: byId('productStateRows'),
    productExporterRows: byId('productExporterRows'),
    productAgingRows: byId('productAgingRows'),
    productAgingThreshold: byId('productAgingThreshold'),
    productUndated: byId('productUndated'),
    precoolingSummary: byId('precoolingChartSummary'),
    cameraRows: byId('cameraDetailRows'),
    verificationSummary: byId('managementVerificationSummary'),
    verificationRows: byId('managementVerificationRows'),
    alerts: byId('managementAlerts'),
    alertCount: byId('alertCount'),
    alertTabCount: byId('alertTabCount'),
    alertSummary: byId('alertSummary'),
    materialOperationDetail: byId('materialOperationDetail'),
    activeLoads: byId('activeLoadsMetric'),
    pendingLoads: byId('pendingLoadsMetric'),
    preparingLoads: byId('preparingLoadsMetric'),
    separatedLoads: byId('separatedLoadsMetric'),
    loadFoliosPending: byId('loadFoliosPendingMetric'),
    loadFoliosDock: byId('loadFoliosDockMetric'),
    loadIncidents: byId('loadIncidentsMetric'),
    loadsClosedToday: byId('loadsClosedTodayMetric'),
    loadList: byId('managementLoadList'),
    validationProcessed: byId('validationProcessedMetric'),
    validationApproved: byId('validationApprovedMetric'),
    validationObserved: byId('validationObservedMetric'),
    validationRejected: byId('validationRejectedMetric'),
    validationConflicts: byId('validationConflictsMetric'),
    validationLatest: byId('managementValidationLatest'),
    materialOpenDispatches: byId('materialOpenDispatchesMetric'),
    materialPartialDispatches: byId('materialPartialDispatchesMetric'),
    materialReceptionsToday: byId('materialReceptionsTodayMetric'),
    materialReceptionDrafts: byId('materialReceptionDraftsMetric'),
    precoolingActive: byId('precoolingActiveMetric'),
    precoolingOverdue: byId('precoolingOverdueMetric'),
    precoolingApproved: byId('precoolingApprovedMetric'),
    precoolingReprocess: byId('precoolingReprocessMetric'),
    precoolingAverage: byId('precoolingAverageMetric'),
    precoolingList: byId('managementPrecoolingList'),
    weighbridgeEntry: byId('weighbridgeEntryMetric'),
    weighbridgeContainers: byId('weighbridgeContainersMetric'),
    weighbridgeTare: byId('weighbridgeTareMetric'),
    weighbridgeSeason: byId('weighbridgeSeasonMetric'),
    weighbridgeSeasonDetail: byId('weighbridgeSeasonDetail'),
    weighbridgeWeek: byId('weighbridgeWeekMetric'),
    weighbridgeWeekDetail: byId('weighbridgeWeekDetail'),
    weighbridgeToday: byId('weighbridgeTodayMetric'),
    weighbridgeTodayDetail: byId('weighbridgeTodayDetail'),
    weighbridgeClients: byId('weighbridgeClientsMetric'),
    weighbridgeClientsDetail: byId('weighbridgeClientsDetail'),
    rawLotsActive: byId('rawLotsActiveMetric'),
    rawHydrocoolerPending: byId('rawHydrocoolerPendingMetric'),
    rawHydrocoolerActive: byId('rawHydrocoolerActiveMetric'),
    rawHydrocoolerHeld: byId('rawHydrocoolerHeldMetric'),
    rawAssignmentPending: byId('rawAssignmentPendingMetric'),
    rawInCamera: byId('rawInCameraMetric'),
    rawPartialDelivery: byId('rawPartialDeliveryMetric'),
    rawConfirmedToday: byId('rawConfirmedTodayMetric'),
    rawOutsideCircuit: byId('rawOutsideCircuitMetric'),
    containerTypeButtons: document.querySelectorAll('[data-management-container-type]'),
    containerBinsStock: byId('containerBinsStock'),
    containerTotesStock: byId('containerTotesStock'),
    containerEsponjasStock: byId('containerEsponjasStock'),
    containerStock: byId('containerStockMetric'),
    containerStockUnit: byId('containerStockUnit'),
    containerEntriesToday: byId('containerEntriesTodayMetric'),
    containerEntriesUnit: byId('containerEntriesUnit'),
    containerExitsToday: byId('containerExitsTodayMetric'),
    containerExitsUnit: byId('containerExitsUnit'),
    containerClientBalances: byId('containerClientBalances'),
    containerFlowDescription: byId('containerFlowDescription'),
    containerMovementsToday: byId('containerMovementsTodayMetric'),
    containerPendingReview: byId('containerPendingReviewMetric'),
    containerObserved: byId('containerObservedMetric'),
    loading: byId('officeLoading'),
    loadingText: byId('officeLoadingText'),
    toasts: byId('officeToasts'),
};

const keys = {
    token: 'estiba_wms_office_token',
    identity: 'estiba_wms_office_identity',
};

const state = {
    token: localStorage.getItem(keys.token),
    identity: readJson(keys.identity),
    dashboard: null,
    seasonId: null,
    containerType: 'bins',
    loading: false,
    poller: null,
    charts: new Map(),
};

const defaultChartColor = Chart.defaults.color;
const fallbackPalette = {
    cyan: defaultChartColor,
    cyanLight: defaultChartColor,
    blue: defaultChartColor,
    purple: defaultChartColor,
    green: defaultChartColor,
    amber: defaultChartColor,
    red: defaultChartColor,
    quiet: defaultChartColor,
    muted: defaultChartColor,
    grid: Chart.defaults.borderColor,
    tooltipBg: Chart.defaults.plugins.tooltip.backgroundColor,
    tooltipBorder: Chart.defaults.borderColor,
    tooltipText: Chart.defaults.plugins.tooltip.bodyColor,
};
let palette = fallbackPalette;

class ApiError extends Error {
    constructor(message, status) {
        super(message);
        this.status = status;
    }
}

// Misma familia que el resto de Oficina (FoliOS v2): Archivo autoalojada, no Inter.
Chart.defaults.font.family = '"Archivo Variable", "Archivo", "Segoe UI", Roboto, Helvetica, Arial, sans-serif';

function applyChartTheme() {
    palette = readManagementChartPaletteSafely(palette, fallbackPalette);
    Chart.defaults.color = palette.muted;
    Chart.defaults.borderColor = palette.grid;
    Object.assign(Chart.defaults.plugins.tooltip, {
        backgroundColor: palette.tooltipBg,
        borderColor: palette.tooltipBorder,
        borderWidth: 1,
        titleColor: palette.tooltipText,
        bodyColor: palette.tooltipText,
    });
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

function cameraAreaLabel(content) {
    if (content === 'materiales') return 'Materiales';
    if (content === 'materia_prima') return 'Materia prima';
    return 'Producto terminado';
}

function cameraAreaClass(content) {
    if (content === 'materiales') return ' area-chip--materials';
    if (content === 'materia_prima') return ' area-chip--raw-material';
    return '';
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
        throw new ApiError('No fue posible conectar con el servidor.', 0);
    }

    const data = response.status === 204 ? null : await response.json().catch(() => ({}));
    if (!response.ok) {
        if (response.status === 401 && path !== '/api/acceso-oficina') clearSession();
        throw new ApiError(errorMessage(data, 'No fue posible completar la consulta.'), response.status);
    }

    return data;
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
    state.dashboard = null;
    state.seasonId = null;
    localStorage.removeItem(keys.token);
    localStorage.removeItem(keys.identity);
    state.poller?.stop();
    state.poller = null;
    state.charts.forEach((chart) => chart.destroy());
    state.charts.clear();
    elements.app.classList.add('is-hidden');
    elements.access.classList.remove('is-hidden');
}

function showApp() {
    if (state.identity?.puede_consultar_panel_gerencial !== true) return false;

    elements.access.classList.add('is-hidden');
    elements.app.classList.remove('is-hidden');
    const name = state.identity?.nombre || 'Gerencia';
    elements.userName.textContent = name;
    elements.userRole.textContent = humanize(state.identity?.rol || 'consulta');
    elements.initials.textContent = name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0])
        .join('')
        .toUpperCase();
    elements.camerasNav.classList.toggle('is-hidden', state.identity?.ambito_camaras === 'ninguno');
    elements.loadsNav.classList.toggle('is-hidden', state.identity?.puede_consultar_cargas !== true);
    elements.materialsNav.classList.toggle('is-hidden', state.identity?.puede_consultar_despachos_materiales !== true);
    elements.prefrioNav.classList.toggle('is-hidden', state.identity?.puede_consultar_prefrio !== true);
    elements.accessesNav.classList.toggle('is-hidden', state.identity?.puede_administrar_accesos !== true);
    elements.romanaNav.classList.toggle('is-hidden', state.identity?.puede_consultar_romana !== true);
    elements.rawMaterialNav.classList.toggle('is-hidden', state.identity?.puede_consultar_materia_prima !== true);

    return true;
}

function humanize(value) {
    return String(value || '')
        .replaceAll('_', ' ')
        .replace(/^./, (character) => character.toUpperCase());
}

function setBusy(active, message = 'Actualizando indicadores…') {
    elements.loadingText.textContent = message;
    elements.loading.classList.toggle('is-hidden', !active);
    elements.loading.setAttribute('aria-hidden', String(!active));
    elements.refresh.disabled = active;
    elements.seasonSelect.disabled = active;
}

function toast(message, error = false) {
    const item = document.createElement('div');
    item.className = `toast${error ? ' toast--error' : ''}`;
    item.textContent = message;
    elements.toasts.append(item);
    window.setTimeout(() => item.remove(), 4500);
}

function formatInteger(value) {
    return new Intl.NumberFormat('es-CL', { maximumFractionDigits: 0 }).format(Number(value || 0));
}

function formatQuantity(value) {
    return new Intl.NumberFormat('es-CL', { maximumFractionDigits: 3 }).format(Number(value || 0));
}

function formatWeight(value) {
    return new Intl.NumberFormat('es-CL', { maximumFractionDigits: 1 }).format(Number(value || 0));
}

function formatKilos(value) {
    return `${formatInteger(Math.round(Number(value || 0)))} kg`;
}

function plural(value, singular, pluralForm = `${singular}s`) {
    return `${formatInteger(value)} ${Number(value) === 1 ? singular : pluralForm}`;
}

const cameraName = (camera) => camera?.nombre || camera?.codigo || 'Cámara';

function formatDuration(minutes) {
    if (minutes === null || minutes === undefined) return 'Sin objetivo';
    const total = Math.max(0, Number(minutes || 0));
    const hours = Math.floor(total / 60);
    const remainder = Math.round(total % 60);
    if (!hours) return `${remainder} min`;
    return `${hours} h ${String(remainder).padStart(2, '0')} min`;
}

function formatDate(value) {
    if (!value) return 'Sin actualizar';

    return new Intl.DateTimeFormat('es-CL', {
        day: 'numeric',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    }).format(new Date(value));
}

function clampPercentage(value) {
    return Math.max(0, Math.min(100, Number(value || 0)));
}

// Omite el código cuando el nombre ya lo contiene ("Temporada cerezas 2026–2027" · "2026-2027").
function seasonLabel(season) {
    const normalize = (value) => String(value ?? '').replace(/[\u2012-\u2015]/g, '-');
    return normalize(season.nombre).includes(normalize(season.codigo))
        ? season.nombre
        : `${season.nombre} · ${season.codigo}`;
}

function renderSeasonOptions(data) {
    const selected = data.temporada;
    const seasons = data.temporadas || [];
    state.seasonId = selected?.id || null;
    elements.seasonSelect.innerHTML = seasons.length
        ? seasons.map((season) => `<option value="${escapeHtml(season.id)}">${escapeHtml(seasonLabel(season))}${season.activa ? ' (activa)' : ''}</option>`).join('')
        : '<option value="">Sin temporadas configuradas</option>';
    elements.seasonSelect.value = state.seasonId || '';
    elements.seasonStatus.textContent = selected?.activa
        ? 'Temporada global activa · capacidad física actual'
        : 'Consulta histórica · capacidad física actual';
}

function renderDashboard(data) {
    state.dashboard = data;
    applyChartTheme();
    renderSeasonOptions(data);
    const capacity = data.camaras.resumen;
    const products = data.productos;
    const loads = data.cargas;
    const validation = data.validacion;
    const materials = data.materiales;
    const precooling = data.prefrio;
    const weighbridge = data.romana;
    const rawMaterial = data.materia_prima;
    const containers = data.envases;

    elements.lastUpdated.textContent = formatDate(data.generado_at);
    elements.refreshStatus.textContent = `Se actualiza cada ${data.actualizacion_segundos} s`;

    elements.weighbridgeSeasonKpi.textContent = formatInteger(Math.round(weighbridge.kilos_temporada || 0));
    elements.weighbridgeTodayKpi.textContent = formatInteger(Math.round(weighbridge.peso_neto_hoy || 0));
    elements.weighbridgeWeekKpi.textContent = formatInteger(Math.round(weighbridge.kilos_7_dias || 0));
    elements.weighbridgeDetail.textContent = `${plural(weighbridge.recepciones_temporada || 0, 'recepción', 'recepciones')} · ${plural(weighbridge.clientes_temporada || 0, 'cliente')}`;

    elements.productPalletsKpi.textContent = formatInteger(products.pallets);
    elements.productSaldosKpi.textContent = formatInteger(products.saldos);
    elements.productBoxesKpi.textContent = formatInteger(products.cajas);
    elements.productAvailabilityDetail.textContent = `${plural(products.disponibles_despacho, 'folio')} listos para despacho · ${formatInteger(products.comprometidos_carga)} en carga`;

    elements.cameraOccupancyKpi.textContent = formatQuantity(capacity.ocupacion_porcentaje);
    elements.cameraOccupancyProgress.style.width = `${clampPercentage(capacity.ocupacion_porcentaje)}%`;
    elements.cameraOccupancyDetail.textContent = `${formatInteger(capacity.ocupadas)} de ${formatInteger(capacity.operativas)} posiciones · ${formatInteger(capacity.disponibles)} libres`;

    elements.materialItems.textContent = formatInteger(materials.items_con_stock);
    elements.materialFolios.textContent = formatInteger(materials.folios_con_stock);
    elements.materialUnits.textContent = formatInteger(materials.unidades_medida.length);
    elements.materialOperationDetail.textContent = `${plural(materials.despachos_abiertos, 'despacho abierto', 'despachos abiertos')} · ${plural(materials.recepciones_borrador, 'recepción sin confirmar', 'recepciones sin confirmar')}`;

    elements.precoolingAvailable.textContent = formatInteger(precooling.disponibles);
    elements.precoolingOccupancyProgress.style.width = `${clampPercentage(precooling.ocupacion_porcentaje)}%`;
    elements.precoolingDetail.textContent = `${plural(precooling.folios_pendientes, 'folio')} en espera · ${plural(precooling.procesos_atrasados, 'proceso atrasado', 'procesos atrasados')}`;

    renderCameraOccupancy(data.camaras.detalle);
    renderProducts(products, data.camaras.por_contenido.productos);
    renderLoads(loads);
    renderValidation(validation);
    renderMaterialUnitOptions(materials.unidades_medida);
    renderMaterialChart();
    renderMaterialsOperation(materials);
    renderMaterialVerifications(materials.verificaciones);
    const replenishment = materials.reposicion;
    byId('materialReplenishmentSummary').textContent = replenishment ? `${replenishment.quiebre} en quiebre · ${replenishment.bajo_minimo} bajo mínimo · ${replenishment.sobre_maximo} sobre máximo · Cobertura media: ${replenishment.cobertura_media === null ? 'sin consumo' : replenishment.cobertura_media + ' días'} · ${replenishment.dias_periodo} días de consumo` : 'Sin datos de reposición';
    byId('materialReplenishmentRows').innerHTML = (replenishment?.menor_cobertura || []).map((r) => `<tr><td>${escapeHtml(r.cliente)}</td><td>${escapeHtml(r.codigo)} · ${escapeHtml(r.item)}</td><td>${r.disponible} ${escapeHtml(r.unidad)}</td><td>${r.dias_cobertura} días</td><td>${escapeHtml(r.estado.replaceAll('_', ' '))}</td></tr>`).join('') || '<tr><td colspan="5">Sin ítems con consumo.</td></tr>';
    renderPrecoolingChart(precooling);
    renderPrecoolingOperation(precooling);
    renderWeighbridgeChart(weighbridge);
    renderWeighbridgeOperation(weighbridge);
    renderRawMaterial(rawMaterial, containers);
    renderCameraTable(data.camaras.detalle);
    renderVerifications(data.verificaciones);
    renderAlerts(data.alertas);
}

function renderVerifications(verification = {}) {
    const weekly = verification.periodos?.[7] || {};
    const monthly = verification.periodos?.[30] || {};
    const compliance = (period) => period.cumplimiento?.generadas
        ? `${formatQuantity(period.cumplimiento.porcentaje)} % (${period.cumplimiento.completadas} de ${period.cumplimiento.generadas} rondas)`
        : 'Sin rondas';
    elements.verificationSummary.textContent = `Cumplimiento: 7 días ${compliance(weekly)} · 30 días ${compliance(monthly)}${verification.habilitada ? '' : ' · función desactivada'}`;
    const byCamera = new Map();
    for (const [days, period] of [[7, weekly], [30, monthly]]) {
        for (const camera of period.camaras || []) {
            byCamera.set(cameraName(camera), { ...(byCamera.get(cameraName(camera)) || {}), [days]: camera });
        }
    }
    elements.verificationRows.innerHTML = byCamera.size ? [...byCamera].map(([code, values]) => `<tr>
        <td>${escapeHtml(code)}</td>
        <td>${values[7] ? `${formatQuantity(values[7].exactitud_porcentaje)} % (${values[7].correctas}/${values[7].total})` : 'Sin verificaciones'}</td>
        <td>${values[30] ? `${formatQuantity(values[30].exactitud_porcentaje)} % (${values[30].correctas}/${values[30].total})` : 'Sin verificaciones'}</td>
    </tr>`).join('') : '<tr><td colspan="3">Sin posiciones verificadas en los últimos 30 días.</td></tr>';
}

function replaceChart(name, canvasId, configuration) {
    state.charts.get(name)?.destroy();
    const canvas = byId(canvasId);
    state.charts.set(name, new Chart(canvas, configuration));
}

function stackedBarOptions(horizontal = true) {
    return {
        responsive: true,
        maintainAspectRatio: false,
        indexAxis: horizontal ? 'y' : 'x',
        interaction: { mode: 'index', intersect: false },
        plugins: {
            legend: { display: false },
            tooltip: {
                backgroundColor: palette.tooltipBg,
                borderColor: palette.tooltipBorder,
                titleColor: palette.tooltipText,
                bodyColor: palette.tooltipText,
                borderWidth: 1,
                padding: 11,
            },
        },
        scales: {
            x: { stacked: true, beginAtZero: true, grid: { color: palette.grid }, ticks: { precision: 0 } },
            y: { stacked: true, grid: { display: false } },
        },
    };
}

const CAMERA_AREAS = [
    ['productos', 'Producto terminado'],
    ['materia_prima', 'Materia prima'],
    ['materiales', 'Materiales'],
];

function occupancyTone(percent) {
    if (percent >= 90) return 'alert';
    if (percent >= 75) return 'watch';
    return 'normal';
}

function renderCameraOccupancy(cameras) {
    const groups = CAMERA_AREAS
        .map(([content, label]) => [label, cameras.filter((camera) => (camera.contenido || 'productos') === content)])
        .filter(([, items]) => items.length);
    elements.cameraOccupancyList.innerHTML = groups.length
        ? groups.map(([label, items]) => `
            <div class="management-occupancy__group" role="listitem">
                <h3>${escapeHtml(label)}</h3>
                ${items.map((camera) => {
                    const percent = clampPercentage(camera.ocupacion_porcentaje);
                    return `<div class="management-occupancy__row" data-tone="${occupancyTone(percent)}">
                        <span class="management-occupancy__name" title="${escapeHtml(camera.codigo)}">${escapeHtml(cameraName(camera))}</span>
                        <span class="management-occupancy__track" aria-hidden="true"><i style="width:${percent}%"></i></span>
                        <span class="management-occupancy__value"><b>${formatQuantity(camera.ocupacion_porcentaje)} %</b>${formatInteger(camera.ocupadas)} / ${formatInteger(camera.operativas)}</span>
                    </div>`;
                }).join('')}
            </div>`).join('')
        : '<div class="management-empty">No hay cámaras activas configuradas.</div>';
}

function bulkBar(pallets, saldos, max, tone = 'neutral') {
    const widths = bulkWidths(pallets, saldos, max);
    return `<span class="management-bulk-bar" data-tone="${tone}" aria-hidden="true">${widths.pallet ? `<i class="management-bulk management-bulk--pallet" style="width:${widths.pallet}%"></i>` : ''}${widths.saldo ? `<i class="management-bulk management-bulk--saldo" style="width:${widths.saldo}%"></i>` : ''}</span>`;
}

function renderProducts(products, occupancy) {
    const { empty, rows, totals } = productStateRows(products);
    elements.productEmpty.hidden = !empty;
    elements.productDetails.hidden = empty;
    if (empty) return;

    elements.productPallets.textContent = formatInteger(products.pallets);
    elements.productPalletsDetail.textContent = `bultos completos · ${formatInteger(products.cajas_pallets)} cajas`;
    elements.productSaldos.textContent = formatInteger(products.saldos);
    elements.productSaldosDetail.textContent = `bultos parciales por consolidar · ${formatInteger(products.cajas_saldos)} cajas`;
    elements.productBoxes.textContent = formatInteger(products.cajas);
    elements.productBoxesDetail.textContent = `${formatInteger(products.ingresados_hoy)} folios ingresados hoy`;
    elements.productOccupancy.textContent = `${formatQuantity(occupancy.ocupacion_porcentaje)} %`;
    elements.productOccupancyDetail.textContent = `${formatInteger(occupancy.ocupadas)} de ${formatInteger(occupancy.operativas)} posiciones operativas`;

    const max = Math.max(0, ...rows.map((row) => row.total));
    elements.productStateRows.innerHTML = rows.map((row) => `
        <div class="management-state-row${row.total ? '' : ' is-zero'}" data-state="${row.key}" data-tone="${row.tone}" role="row">
            <span role="cell" class="management-state-row__label"><i aria-hidden="true"></i><span>${escapeHtml(row.label)}${row.hint ? `<small>${escapeHtml(row.hint)}</small>` : ''}</span></span>
            <span role="cell" class="management-state-row__bar">${bulkBar(row.pallets, row.saldos, max, row.tone)}<em>${row.total ? `${formatQuantity(row.percent)} %` : ''}</em></span>
            <span role="cell" data-label="Pallets">${formatInteger(row.pallets)}</span>
            <span role="cell" data-label="Saldos" class="management-state-row__saldos">${formatInteger(row.saldos)}</span>
            <span role="cell" data-label="Cajas">${formatInteger(row.cajas)}</span>
        </div>`).join('') + `
        <div class="management-state-row management-state-row--total" role="row">
            <span role="cell" class="management-state-row__label"><span>Total en planta</span></span>
            <span role="cell" class="management-state-row__bar"></span>
            <span role="cell" data-label="Pallets">${formatInteger(totals.pallets)}</span>
            <span role="cell" data-label="Saldos" class="management-state-row__saldos">${formatInteger(totals.saldos)}</span>
            <span role="cell" data-label="Cajas">${formatInteger(totals.cajas)}</span>
        </div>`;

    const exporters = products.exportadoras?.detalle || [];
    const others = products.exportadoras?.otras;
    const exporterMax = Math.max(0, ...exporters.map((row) => row.pallets + row.saldos));
    elements.productExporterRows.innerHTML = exporters.length
        ? exporters.map((row) => `
            <div class="management-exporter">
                <div class="management-exporter__head"><strong>${escapeHtml(row.nombre)}</strong><span>${formatInteger(row.cajas)} cajas</span></div>
                ${bulkBar(row.pallets, row.saldos, exporterMax)}
                <small>${plural(row.pallets, 'pallet')} · ${plural(row.saldos, 'saldo')}</small>
            </div>`).join('') + (others ? `<p class="management-exporters__more">Otras ${formatInteger(others.cantidad)}: ${plural(others.pallets, 'pallet')} · ${plural(others.saldos, 'saldo')} · ${formatInteger(others.cajas)} cajas</p>` : '')
        : '<div class="management-empty">Sin exportadora informada en los folios.</div>';

    const aging = products.antiguedad;
    const agingMax = Math.max(0, ...aging.tramos.map((row) => row.total));
    elements.productAgingThreshold.textContent = `alerta sobre ${aging.umbral_alerta_dias} días`;
    elements.productAgingRows.innerHTML = aging.tramos.map(({ desde_dias: from, hasta_dias: to, total, pallets, saldos }, index) => {
        const label = to === null ? `Más de ${aging.umbral_alerta_dias} días` : `${from} a ${to} días`;
        const tone = index === aging.tramos.length - 1 && total ? 'alert' : 'neutral';
        // Una respuesta sin desglose por tipo se dibuja completa como pallets.
        const palletCount = pallets ?? total;
        const saldoCount = saldos ?? 0;
        return `<div class="management-products__aging-row" data-tone="${tone}"><span>${label}</span>${bulkBar(palletCount, saldoCount, agingMax, tone)}<strong>${formatInteger(total)}</strong></div>`;
    }).join('');
    elements.productUndated.hidden = !aging.sin_fecha;
    elements.productUndated.textContent = `${plural(aging.sin_fecha, 'folio')} sin fecha de ingreso`;
}

function renderLoads(loads) {
    elements.activeLoads.textContent = formatInteger(loads.activas);
    elements.pendingLoads.textContent = formatInteger(loads.pendientes);
    elements.preparingLoads.textContent = formatInteger(loads.en_preparacion);
    elements.separatedLoads.textContent = formatInteger(loads.separadas);
    elements.loadFoliosPending.textContent = formatInteger(loads.folios_pendientes);
    elements.loadFoliosDock.textContent = formatInteger(loads.folios_en_anden);
    elements.loadIncidents.textContent = formatInteger(loads.folios_con_incidencia);
    elements.loadsClosedToday.textContent = formatInteger(loads.cerradas_hoy);

    elements.loadList.innerHTML = loads.detalle?.length
        ? loads.detalle.map((load) => `
            <a class="management-operation-row" href="/oficina/frigorifico/despacho/estatus">
                <div>
                    <strong>${escapeHtml(load.codigo)}</strong>
                    <span>${escapeHtml(load.orden_embarque || 'Sin orden externa')} · ${escapeHtml(humanize(load.estado))}</span>
                </div>
                <div class="management-operation-row__metrics">
                    <span>${formatInteger(load.folios_asignados)} folios</span>
                    <span>${escapeHtml(load.camara_objetivo || 'Sin cámara')}</span>
                    <span>${formatDuration(load.antiguedad_minutos)}</span>
                </div>
            </a>
        `).join('')
        : '<div class="management-empty">No hay cargas activas.</div>';
}

function renderValidation(validation) {
    elements.validationProcessed.textContent = formatInteger(validation.procesados_hoy);
    elements.validationApproved.textContent = formatInteger(validation.aprobados_hoy);
    elements.validationObserved.textContent = formatInteger(validation.observados_hoy);
    elements.validationRejected.textContent = formatInteger(validation.rechazados_hoy);
    elements.validationConflicts.textContent = formatInteger(validation.conflictos_hoy);

    const latest = validation.ultima_validacion;
    elements.validationLatest.innerHTML = latest
        ? `<span>ÚLTIMO REGISTRO RECIBIDO</span><strong>${escapeHtml(latest.folio)}</strong><small>${escapeHtml(humanize(latest.resultado))} · ${escapeHtml(formatDate(latest.recibido_at))}</small>`
        : '<span>ÚLTIMO REGISTRO RECIBIDO</span><strong>Sin actividad hoy</strong><small>No hay validaciones informadas.</small>';
}

function renderMaterialsOperation(materials) {
    elements.materialOpenDispatches.textContent = formatInteger(materials.despachos_abiertos);
    elements.materialPartialDispatches.textContent = formatInteger(materials.despachos_parciales);
    elements.materialReceptionsToday.textContent = formatInteger(materials.recepciones_confirmadas_hoy);
    elements.materialReceptionDrafts.textContent = formatInteger(materials.recepciones_borrador);
    for (const [key, id] of [['por_vencer', 'materialNearExpiry'], ['vencido', 'materialExpired']]) {
        const group = materials.vencimientos?.[key];
        const target = byId(id);
        if (target) target.textContent = `${formatInteger(group?.folios || 0)} folios · ${(group?.cantidades || []).map((unit) => `${formatQuantity(unit.cantidad)} ${unit.unidad_medida}`).join(' · ') || 'Sin stock'}`;
    }
}

function renderMaterialUnitOptions(units) {
    const previous = elements.materialUnitSelect.value;
    elements.materialUnitSelect.innerHTML = units.length
        ? units.map((unit) => `<option value="${escapeHtml(unit.unidad_medida)}">${escapeHtml(unit.unidad_medida)}</option>`).join('')
        : '<option value="">Sin stock</option>';

    if (units.some((unit) => unit.unidad_medida === previous)) {
        elements.materialUnitSelect.value = previous;
    }
}

function renderMaterialChart() {
    const units = state.dashboard?.materiales?.unidades_medida || [];
    const selected = units.find((unit) => unit.unidad_medida === elements.materialUnitSelect.value) || units[0];
    const items = (selected?.items || []).slice(0, 8);

    replaceChart('materials', 'materialStockChart', {
        type: 'bar',
        data: {
            labels: items.map((item) => `${item.cliente.codigo} · ${item.codigo}`),
            datasets: [
                { label: 'Disponible', data: items.map((item) => item.cantidad_disponible), backgroundColor: palette.purple, borderRadius: 5, borderSkipped: false },
                { label: 'Reservado', data: items.map((item) => item.cantidad_reservada), backgroundColor: palette.amber, borderRadius: 5, borderSkipped: false },
                { label: 'Bloqueado', data: items.map((item) => item.cantidad_bloqueada), backgroundColor: palette.red, borderRadius: 5, borderSkipped: false },
                { label: 'Pendiente de ubicación', data: items.map((item) => item.cantidad_pendiente_ubicacion), backgroundColor: palette.quiet, borderRadius: 5, borderSkipped: false },
                { label: 'No disponible', data: items.map((item) => item.cantidad_no_disponible), backgroundColor: palette.muted, borderRadius: 5, borderSkipped: false },
            ],
        },
        options: {
            ...stackedBarOptions(true),
            plugins: {
                ...stackedBarOptions(true).plugins,
                tooltip: {
                    ...stackedBarOptions(true).plugins.tooltip,
                    callbacks: {
                        title: (contexts) => {
                            const item = items[contexts[0]?.dataIndex];
                            return item ? `${item.cliente.nombre} · ${item.nombre}` : '';
                        },
                        label: (context) => `${context.dataset.label}: ${formatQuantity(context.raw)} ${selected?.unidad_medida || ''}`,
                    },
                },
            },
            scales: {
                ...stackedBarOptions(true).scales,
                x: { ...stackedBarOptions(true).scales.x, ticks: { callback: (value) => formatQuantity(value) } },
            },
        },
    });

    elements.materialSummary.innerHTML = selected ? `
        <span><b>${formatQuantity(selected.cantidad_actual)} ${escapeHtml(selected.unidad_medida)}</b>stock actual</span>
        <span><b>${formatQuantity(selected.cantidad_disponible)} ${escapeHtml(selected.unidad_medida)}</b>disponible</span>
        <span><b>${formatQuantity(selected.cantidad_reservada)} ${escapeHtml(selected.unidad_medida)}</b>reservado</span>
        <span><b>${formatQuantity(selected.cantidad_bloqueada)} ${escapeHtml(selected.unidad_medida)}</b>bloqueado</span>
        <span><b>${formatQuantity(selected.cantidad_pendiente_ubicacion)} ${escapeHtml(selected.unidad_medida)}</b>pendiente de ubicación</span>
        <span><b>${formatQuantity(selected.cantidad_no_disponible)} ${escapeHtml(selected.unidad_medida)}</b>no disponible</span>
    ` : '<span>Sin materiales con stock</span>';
}

function renderPrecoolingChart(precooling) {
    const tunnels = precooling.tuneles;
    replaceChart('precooling', 'precoolingChart', {
        type: 'bar',
        data: {
            labels: tunnels.map((tunnel) => tunnel.nombre || tunnel.codigo),
            datasets: [
                { label: 'Ocupadas', data: tunnels.map((tunnel) => tunnel.ocupadas), backgroundColor: palette.blue, borderRadius: 5, borderSkipped: false },
                { label: 'Disponibles', data: tunnels.map((tunnel) => tunnel.disponibles), backgroundColor: palette.quiet, borderRadius: 5, borderSkipped: false },
            ],
        },
        options: stackedBarOptions(true),
    });

    elements.precoolingSummary.innerHTML = `
        <span><b>${formatInteger(precooling.procesos_activos)}</b>procesos activos</span>
        <span><b>${formatInteger(precooling.folios_pendientes)}</b>folios pendientes</span>
    `;
}

function renderPrecoolingOperation(precooling) {
    elements.precoolingActive.textContent = formatInteger(precooling.procesos_activos);
    elements.precoolingOverdue.textContent = formatInteger(precooling.procesos_atrasados);
    elements.precoolingApproved.textContent = formatInteger(precooling.aprobados_hoy);
    elements.precoolingReprocess.textContent = formatInteger(precooling.reprocesos_hoy);
    elements.precoolingAverage.textContent = formatDuration(precooling.duracion_promedio_minutos_7d);

    const active = (precooling.tuneles || []).filter((tunnel) => tunnel.proceso_activo);
    elements.precoolingList.innerHTML = active.length
        ? active.map((tunnel) => {
            const process = tunnel.proceso_activo;
            return `
                <a class="management-operation-row${process.atrasado ? ' is-critical' : ''}" href="/oficina/prefrio">
                    <div>
                        <strong>${escapeHtml(tunnel.nombre || tunnel.codigo)} · ${escapeHtml(process.codigo)}</strong>
                        <span>${escapeHtml(humanize(process.estado))} · ${formatInteger(tunnel.ocupadas)}/${formatInteger(tunnel.capacidad)} posiciones</span>
                    </div>
                    <div class="management-operation-row__metrics">
                        <span>${process.setpoint === null ? 'Sin setpoint' : `${formatQuantity(process.setpoint)} °C`}</span>
                        <span>${formatDuration(process.transcurridos_minutos)} / ${formatDuration(process.duracion_objetivo_minutos)}</span>
                        <span>${process.atrasado ? 'Atrasado' : 'Dentro de objetivo'}</span>
                    </div>
                </a>
            `;
        }).join('')
        : '<div class="management-empty">No hay procesos activos en túneles.</div>';
}

function seriesColors() {
    return [palette.blue, palette.amber, palette.purple, palette.green, palette.cyanLight];
}

function renderWeighbridgeChart(weighbridge) {
    const days = weighbridge.tendencia_diaria || [];
    const series = weighbridge.series_clientes || [];
    const colors = seriesColors();
    const colorFor = (serie, index) => (serie.clave === 'otros' ? palette.quiet : colors[index % colors.length]);
    const datasets = series.length
        ? series.map((serie, index) => ({
            label: serie.nombre,
            data: days.map((day) => Number(day.por_cliente?.[serie.clave] || 0)),
            backgroundColor: colorFor(serie, index),
            borderRadius: 3,
            borderSkipped: false,
            maxBarThickness: 46,
        }))
        : [{ label: 'Kilos', data: days.map((day) => day.peso_neto), backgroundColor: palette.amber, borderRadius: 3, maxBarThickness: 46 }];

    replaceChart('weighbridge', 'weighbridgeReceptionChart', {
        type: 'bar',
        data: { labels: days.map((day) => day.etiqueta), datasets },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { display: false },
                tooltip: {
                    padding: 11,
                    filter: (item) => Number(item.raw) > 0,
                    callbacks: {
                        label: (context) => `${context.dataset.label}: ${formatKilos(context.raw)}`,
                        footer: (items) => {
                            const day = days[items[0]?.dataIndex];
                            return day ? `Total ${formatKilos(day.peso_neto)} · ${plural(day.recepciones, 'recepción', 'recepciones')}` : '';
                        },
                    },
                },
            },
            scales: {
                x: { stacked: true, grid: { display: false } },
                y: { stacked: true, beginAtZero: true, grid: { color: palette.grid }, border: { display: false }, ticks: { maxTicksLimit: 5, callback: (value) => formatInteger(value) } },
            },
        },
    });

    const weekly = new Map((weighbridge.clientes || []).map((client) => [String(client.id), client.kilos_7_dias]));
    const otherKilos = Math.max(0, Number(weighbridge.kilos_7_dias || 0) - series
        .filter((serie) => serie.clave !== 'otros')
        .reduce((sum, serie) => sum + Number(weekly.get(serie.clave) || 0), 0));
    elements.weighbridgeSummary.innerHTML = series.length
        ? series.map((serie, index) => `<span><i style="background:${colorFor(serie, index)}"></i>${escapeHtml(serie.nombre)} <b>${formatKilos(serie.clave === 'otros' ? otherKilos : weekly.get(serie.clave))}</b></span>`).join('')
        : '<span>Sin kilos recepcionados en los últimos 7 días.</span>';
}

function renderWeighbridgeOperation(weighbridge) {
    elements.weighbridgeEntry.textContent = formatInteger(weighbridge.en_bascula_ingreso);
    elements.weighbridgeContainers.textContent = formatInteger(weighbridge.en_pesaje_envases);
    elements.weighbridgeTare.textContent = formatInteger(weighbridge.pendientes_destare);
    elements.weighbridgeSeason.textContent = formatKilos(weighbridge.kilos_temporada);
    elements.weighbridgeSeasonDetail.textContent = plural(weighbridge.recepciones_temporada || 0, 'recepción', 'recepciones');
    elements.weighbridgeWeek.textContent = formatKilos(weighbridge.kilos_7_dias);
    elements.weighbridgeWeekDetail.textContent = plural(weighbridge.recepciones_7_dias || 0, 'recepción', 'recepciones');
    elements.weighbridgeToday.textContent = formatKilos(weighbridge.peso_neto_hoy);
    elements.weighbridgeTodayDetail.textContent = `${plural(weighbridge.cerradas_hoy, 'recepción cerrada', 'recepciones cerradas')} · ${plural(weighbridge.clientes_hoy, 'cliente')}`;
    elements.weighbridgeClients.textContent = formatInteger(weighbridge.clientes_temporada || 0);
    elements.weighbridgeClientsDetail.textContent = 'con kilos en la temporada';

    const clients = weighbridge.clientes || [];
    const max = Math.max(0, ...clients.map((client) => client.kilos_temporada));
    elements.weighbridgeClientRows.innerHTML = clients.length
        ? clients.map((client) => `
            <div class="management-client-kilos__row">
                <div class="management-client-kilos__head"><strong>${escapeHtml(client.nombre)}</strong><b>${formatKilos(client.kilos_temporada)}</b></div>
                <span class="management-share" aria-hidden="true"><i style="width:${max ? Math.max(1.5, (client.kilos_temporada / max) * 100) : 0}%"></i></span>
                <small><span>${formatQuantity(client.participacion_porcentaje)} % del total</span><span>7 días ${formatKilos(client.kilos_7_dias)}</span><span>hoy ${formatKilos(client.kilos_hoy)}</span><span>${plural(client.recepciones_temporada, 'recepción', 'recepciones')}</span></small>
            </div>`).join('')
        : '<div class="management-empty">Sin recepciones cerradas en la temporada.</div>';
}

function renderRawMaterial(rawMaterial, containers) {
    elements.rawLotsActive.textContent = formatInteger(rawMaterial.lotes_activos);
    elements.rawHydrocoolerPending.textContent = formatInteger(rawMaterial.pendientes_hidrocooler);
    elements.rawHydrocoolerActive.textContent = formatInteger(rawMaterial.hidrocooler_en_curso);
    elements.rawHydrocoolerHeld.textContent = formatInteger(rawMaterial.hidrocooler_retenido);
    elements.rawAssignmentPending.textContent = formatInteger(rawMaterial.pendientes_asignacion);
    elements.rawInCamera.textContent = formatInteger(rawMaterial.en_camara);
    elements.rawPartialDelivery.textContent = formatInteger(rawMaterial.entrega_parcial);
    elements.rawConfirmedToday.textContent = `${formatInteger(rawMaterial.confirmados_hoy)} lotes · ${formatWeight(rawMaterial.kilos_confirmados_hoy)} kg`;
    elements.rawOutsideCircuit.textContent = `${formatInteger(rawMaterial.borradores)} borradores · ${formatInteger(rawMaterial.entregados_proceso)} entregados`;

    elements.containerMovementsToday.textContent = formatInteger(containers.movimientos_hoy);
    elements.containerPendingReview.textContent = formatInteger(containers.pendientes_revision);
    elements.containerObserved.textContent = formatInteger(containers.observados);
    renderContainerType();
}

function renderContainerType() {
    const types = state.dashboard?.envases?.tipos || {};
    const type = state.containerType;
    const selected = types[type];
    if (!selected) return;

    elements.containerBinsStock.textContent = formatInteger(types.bins?.existencia);
    elements.containerTotesStock.textContent = formatInteger(types.totes?.existencia);
    elements.containerEsponjasStock.textContent = formatInteger(types.esponjas?.existencia);
    elements.containerTypeButtons.forEach((button) => {
        const active = button.dataset.managementContainerType === type;
        button.classList.toggle('is-active', active);
        button.setAttribute('aria-pressed', String(active));
    });

    elements.containerStock.textContent = formatInteger(selected.existencia);
    elements.containerStockUnit.textContent = `${type} registrados en esta temporada`;
    elements.containerEntriesToday.textContent = formatInteger(selected.entradas_hoy);
    elements.containerEntriesUnit.textContent = `${type} ingresados o revertidos`;
    elements.containerExitsToday.textContent = formatInteger(selected.salidas_hoy);
    elements.containerExitsUnit.textContent = `${type} despachados`;

    const balances = selected.saldos_clientes || [];
    elements.containerClientBalances.innerHTML = balances.length
        ? balances.map((item) => `<div class="management-container-balance"><span>${escapeHtml(item.cliente)}</span><strong class="${item.saldo < 0 ? 'is-negative' : ''}">${item.saldo > 0 ? '+' : ''}${formatInteger(item.saldo)}</strong></div>`).join('')
        : '<div class="management-empty">Sin saldos registrados para este tipo de envase.</div>';

    const days = selected.tendencia_diaria || [];
    const entries = days.reduce((sum, day) => sum + Number(day.entradas), 0);
    const exits = days.reduce((sum, day) => sum + Number(day.salidas), 0);
    elements.containerFlowDescription.textContent = `${formatInteger(entries)} ${type} entraron y ${formatInteger(exits)} salieron en los últimos 7 días. Incluye reversiones de despacho en entradas.`;
    replaceChart('containers', 'containerFlowChart', {
        type: 'bar',
        data: {
            labels: days.map((day) => day.etiqueta),
            datasets: [
                { label: 'Entradas', data: days.map((day) => day.entradas), backgroundColor: palette.green, borderRadius: 4 },
                { label: 'Salidas', data: days.map((day) => day.salidas), backgroundColor: palette.amber, borderRadius: 4 },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom' },
                tooltip: { callbacks: { label: (context) => `${context.dataset.label}: ${formatInteger(context.raw)} ${type}` } },
            },
            scales: {
                x: { grid: { display: false } },
                y: { beginAtZero: true, grid: { color: palette.grid }, ticks: { precision: 0 } },
            },
        },
    });
}

function renderCameraTable(cameras) {
    elements.cameraRows.innerHTML = cameras.length
        ? cameras.map((camera) => `
            <tr>
                <td><strong>${escapeHtml(cameraName(camera))}</strong><small>${escapeHtml(camera.codigo)}</small></td>
                <td><span class="area-chip${cameraAreaClass(camera.contenido)}">${escapeHtml(cameraAreaLabel(camera.contenido))}</span></td>
                <td>${formatInteger(camera.ocupadas)}</td>
                <td>${formatInteger(camera.disponibles)}</td>
                <td>${formatInteger(camera.no_operativas)}</td>
                <td><span class="usage-chip${camera.ocupacion_porcentaje >= 90 ? ' usage-chip--warning' : ''}">${formatQuantity(camera.ocupacion_porcentaje)} %</span></td>
            </tr>
        `).join('')
        : '<tr><td colspan="6"><div class="management-empty">No hay cámaras activas configuradas.</div></td></tr>';
}

const ALERT_LEVELS = {
    critica: { label: 'Crítico', plural: 'críticos', tone: 'critical' },
    advertencia: { label: 'Atención', plural: 'de atención', tone: 'warning' },
    informativa: { label: 'Aviso', plural: 'avisos', tone: 'info' },
};

function alertMetric(alert) {
    if (typeof alert.metrica === 'number') return `${formatQuantity(alert.metrica)}${alert.unidad ? ` <small>${escapeHtml(alert.unidad)}</small>` : ''}`;
    return escapeHtml(alert.metrica ?? '');
}

function renderAlerts(alerts) {
    const count = formatInteger(alerts.length);
    elements.alertCount.textContent = count;
    if (elements.alertTabCount) elements.alertTabCount.textContent = count;
    const byLevel = Object.entries(ALERT_LEVELS)
        .map(([level, meta]) => [meta, alerts.filter((alert) => alert.nivel === level).length])
        .filter(([, total]) => total > 0);
    elements.alertSummary.querySelectorAll('.management-alert-chip').forEach((chip) => chip.remove());
    elements.alertSummary.insertAdjacentHTML('afterbegin', byLevel
        .map(([meta, total]) => `<span class="management-alert-chip" data-tone="${meta.tone}">${formatInteger(total)} ${total === 1 ? meta.label.toLowerCase() : meta.plural}</span>`)
        .join(''));
    elements.alerts.innerHTML = alerts.length
        ? alerts.map((alert) => {
            const level = ALERT_LEVELS[alert.nivel] || ALERT_LEVELS.advertencia;
            return `
            <a class="management-alert" data-tone="${level.tone}" href="${escapeHtml(alert.href || '#')}">
                <span class="management-alert__level">${level.label}</span>
                <div>
                    <small>${escapeHtml(alert.area || 'Operación')}</small>
                    <strong>${escapeHtml(alert.titulo)}</strong>
                    <span>${escapeHtml(alert.detalle)}</span>
                </div>
                <b>${alertMetric(alert)}</b>
            </a>`;
        }).join('')
        : '<div class="management-empty"><strong>Operación sin focos críticos.</strong><span>No hay excepciones activas en la instantánea actual.</span></div>';
}

async function loadDashboard({ blocking = false, silent = false } = {}) {
    if (state.loading) return;

    state.loading = true;
    setBusy(blocking, 'Actualizando indicadores…');
    elements.refresh.disabled = true;
    elements.refreshStatus.textContent = 'Consultando la operación actual…';

    try {
        const params = new URLSearchParams();
        if (state.seasonId) params.set('temporada_id', state.seasonId);
        const suffix = params.size ? `?${params}` : '';
        const payload = await api(`/api/gerencia/resumen${suffix}`);
        renderDashboard(payload.data);
        if (!silent) toast('Panel actualizado con la información actual.');
    } catch (error) {
        elements.refreshStatus.textContent = 'No fue posible actualizar; se conserva la última lectura.';
        if (error.status === 403) {
            clearSession();
            elements.loginError.textContent = 'Tu perfil no posee acceso al panel gerencial.';
        } else if (!silent || !state.dashboard) {
            toast(error.message, true);
        }
    } finally {
        state.loading = false;
        setBusy(false);
        elements.refresh.disabled = false;
    }
}

function startAutoRefresh() {
    state.poller?.stop();
    const seconds = Number(state.dashboard?.actualizacion_segundos || 30);
    state.poller = createOperationalPoller(
        () => loadDashboard({ silent: true }),
        {
            intervalMs: seconds * 1000,
            canRun: () => Boolean(state.token) && !state.loading,
        },
    );
    state.poller.start();
}

elements.login.addEventListener('submit', async (event) => {
    event.preventDefault();
    elements.loginError.textContent = '';
    setBusy(true, 'Validando acceso…');

    try {
        const payload = await api('/api/acceso-oficina', {
            method: 'POST',
            body: JSON.stringify(Object.fromEntries(new FormData(elements.login))),
        });
        if (payload.usuario.puede_consultar_panel_gerencial !== true) {
            throw new ApiError('Tu perfil no posee acceso al panel gerencial.', 403);
        }
        persistSession(payload);
        showApp();
        await loadDashboard({ blocking: true, silent: true });
        if (state.dashboard) startAutoRefresh();
    } catch (error) {
        elements.loginError.textContent = error.message;
    } finally {
        setBusy(false);
    }
});

elements.logout.addEventListener('click', async () => {
    try {
        await api('/api/acceso-oficina', { method: 'DELETE' });
    } catch {
        // La sesión local igualmente debe cerrarse.
    }
    clearSession();
});

elements.refresh.addEventListener('click', () => void loadDashboard());
elements.seasonSelect.addEventListener('change', () => {
    state.seasonId = elements.seasonSelect.value || null;
    void loadDashboard({ blocking: true });
});
elements.materialUnitSelect.addEventListener('change', renderMaterialChart);
elements.containerTypeButtons.forEach((button) => button.addEventListener('click', () => {
    state.containerType = button.dataset.managementContainerType;
    renderContainerType();
}));

document.addEventListener('estiba:office-panel-change', (event) => {
    if (event.detail?.group !== 'management') return;

    window.requestAnimationFrame(() => {
        state.charts.forEach((chart) => {
            if (!chart.canvas.closest('[hidden]')) chart.resize();
        });
    });
});

new MutationObserver(() => {
    if (!state.dashboard) return;

    applyChartTheme();
    renderMaterialChart();
    renderPrecoolingChart(state.dashboard.prefrio);
    renderWeighbridgeChart(state.dashboard.romana);
    renderContainerType();
}).observe(document.documentElement, { attributes: true, attributeFilter: ['data-office-theme'] });

async function boot() {
    if (!state.token || state.identity?.puede_consultar_panel_gerencial !== true) return;
    if (!showApp()) return;

    await loadDashboard({ blocking: true, silent: true });
    if (state.dashboard) startAutoRefresh();
}

void boot();

function renderMaterialVerifications(data = {}) {
    byId('materialVerificationSummary').textContent = data.habilitada ? 'La verificación registra diferencias sin ajustar existencias.' : 'Verificación de materiales desactivada.';
    const pct = (value) => value?.porcentaje == null ? 'Sin datos' : `${value.porcentaje} %`;
    byId('materialVerificationRows').innerHTML = [7, 30].flatMap((days) => (data.periodos?.[days]?.camaras || []).map((camera) => `<tr>
        <td>${escapeHtml(cameraName(camera))}</td><td>${days} días</td><td>${pct(camera.ubicacion)}</td><td>${pct(camera.cantidad)}</td><td>${pct(camera.cumplimiento)} (${camera.cumplimiento.completadas}/${camera.cumplimiento.generadas})</td>
    </tr>`)).join('') || '<tr><td colspan="5">Sin cámaras de materiales.</td></tr>';
}
