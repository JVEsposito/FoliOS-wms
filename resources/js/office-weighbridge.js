import { createReceptionGuides, guideProblem } from './office-weighbridge-guides.js';
import { createIntegralCorrection } from './office-weighbridge-integral.js';
import { createGatehouseRomana } from './office-weighbridge-porteria';
import { dispatchInspectionPayload } from './office-container-inspection.js';
import { receptionSteps } from './shared/weighbridge-steps.js';
import { loadContainerCatalog, containerLabel, calculateNetAllocation } from './office-container-catalog.js';
import { createOperationalPoller } from './shared/operational-poller';

const byId = (id) => document.getElementById(id);
const elements = {
    access: byId('officeAccess'), app: byId('officeApp'), login: byId('officeLoginForm'), loginError: byId('officeLoginError'),
    userName: byId('officeUserName'), userRole: byId('officeUserRole'), initials: byId('officeInitials'), logout: byId('officeLogoutButton'),
    managementNav: byId('officeManagementNav'), rawMaterialNav: byId('officeRawMaterialNav'), containerAccountsNav: byId('officeContainerAccountsNav'), camerasNav: byId('officeCamerasNav'), loadsNav: byId('officeLoadsNav'), materialsNav: byId('officeMaterialsNav'), validationNav: byId('officeValidationNav'), prefrioNav: byId('officePrefrioNav'), accessesNav: byId('officeAccessesNav'),
    rc02Reception: byId('downloadRc02ReceptionButton'), rc02Dispatch: byId('downloadRc02DispatchButton'), inspectionPanel: byId('dispatchInspectionPanel'), inspectionItems: byId('dispatchInspectionItems'), inspectionObservation: byId('dispatchInspectionObservation'),
    reload: byId('reloadButton'), blankWeighingForm: byId('downloadBlankWeighingFormButton'), newReception: byId('newReceptionButton'), filters: byId('receptionFilters'), tableBody: byId('receptionTableBody'),
    entryCount: byId('entryCount'), containerWeighingCount: byId('containerWeighingCount'), exitCount: byId('exitCount'), closedCount: byId('closedCount'), netWeight: byId('netWeight'),
    paginationSummary: byId('paginationSummary'), previousPage: byId('previousPageButton'), nextPage: byId('nextPageButton'),
    detail: byId('receptionDetail'), detailTitle: byId('detailTitle'), detailSubtitle: byId('detailSubtitle'), detailFacts: byId('detailFacts'), detailTimeline: byId('detailTimeline'), weightBalance: byId('weightBalance'),
    deleteReception: byId('deleteReceptionButton'), annulReception: byId('annulReceptionButton'), annulDialog: byId('annulReceptionDialog'), annulForm: byId('annulReceptionForm'), annulSummary: byId('annulReceptionSummary'), annulError: byId('annulReceptionError'),
    correctGrossWeight: byId('correctGrossWeightButton'), grossWeightDialog: byId('grossWeightDialog'), grossWeightForm: byId('grossWeightForm'), grossWeightDescription: byId('grossWeightDescription'), grossWeightCurrent: byId('grossWeightCurrent'), grossWeightPreview: byId('grossWeightPreview'), grossWeightReadingField: byId('grossWeightReadingField'), grossWeightError: byId('grossWeightError'), grossWeightCancel: byId('grossWeightCancel'),
    editReception: byId('editReceptionButton'), confirmEntry: byId('confirmEntryButton'), addContainerWeighing: byId('addContainerWeighingButton'), closeReception: byId('closeReceptionButton'), correctExit: byId('correctExitButton'), downloadReceipt: byId('downloadReceiptButton'), closeDetail: byId('closeDetailButton'),
    containerWeighingPanel: byId('containerWeighingPanel'), containerWeighingProgress: byId('containerWeighingProgress'), containerWeighingList: byId('containerWeighingList'),
    receptionDialog: byId('receptionDialog'), receptionForm: byId('receptionForm'), receptionDialogTitle: byId('receptionDialogTitle'), receptionDialogDescription: byId('receptionDialogDescription'), receptionFormError: byId('receptionFormError'), saveReception: byId('saveReceptionButton'),
    serviceField: byId('serviceField'), containerEntryDateField: byId('containerEntryDateField'), containerConceptField: byId('containerConceptField'), standardContainerLines: byId('standardContainerLines'), containerWeighingFields: byId('containerWeighingFields'), containerWeighingLines: byId('containerWeighingLines'), addContainerWeighingLine: byId('addContainerWeighingLine'), grossWeightField: byId('grossWeightField'), administrativeCorrectionField: byId('administrativeCorrectionField'), administrativeTareField: byId('administrativeTareField'), administrativeNetContainerField: byId('administrativeNetContainerField'),
    tareDialog: byId('tareDialog'), tareForm: byId('tareForm'), tareDescription: byId('tareDescription'), tareFormError: byId('tareFormError'), outboundContainerTares: byId('outboundContainerTares'), outboundContainerTareList: byId('outboundContainerTareList'), outboundGuideField: byId('outboundGuideField'), outboundExtraField: byId('outboundExtraField'), outboundExtraType: byId('outboundExtraType'), outboundAddType: byId('outboundAddType'), exitCorrectionReasonField: byId('exitCorrectionReasonField'), containerTarePreviewRow: byId('containerTarePreviewRow'), containerTarePreview: byId('containerTarePreview'), netWeightPreview: byId('netWeightPreview'), netPerContainerPreview: byId('netPerContainerPreview'),
    containerWeighingDialog: byId('containerWeighingDialog'), containerWeighingForm: byId('containerWeighingForm'), containerWeighingDescription: byId('containerWeighingDescription'), containerWeighingFormError: byId('containerWeighingFormError'), containerWeighingQuantities: byId('containerWeighingQuantities'), containerWeighingTarePreview: byId('containerWeighingTarePreview'), containerWeighingNetPreview: byId('containerWeighingNetPreview'),
    loading: byId('officeLoading'), loadingText: byId('officeLoadingText'), toasts: byId('officeToasts'),
};

const keys = { token: 'estiba_wms_office_token', identity: 'estiba_wms_office_identity' };
const state = {
    token: localStorage.getItem(keys.token),
    identity: readJson(keys.identity),
    catalogs: { temporadas: [], clientes: [], especies: [], tipos_servicio: [], tipos_envase: [], tipos_recepcion: [], conceptos_envases: [] },
    receptions: [],
    selected: null,
    page: 1,
    meta: null,
    poller: null,
    administrativeCorrection: false,
    tareCorrection: false,
};

const gatehouseRomana = createGatehouseRomana({ getToken: () => state.token });
let receptionGuides;
function guidesEditor() {
    return receptionGuides ??= createReceptionGuides({
        form: elements.receptionForm, panel: byId('receptionGuidesPanel'), list: byId('receptionGuidesList'), add: byId('addReceptionGuide'),
        types: () => state.catalogs.tipos_envase, escapeHtml,
        readContainers: () => elements.receptionForm.elements.tipo_recepcion.value === 'fruta_pesaje_envases'
            ? readWeighingLines().map((line) => ({ tipo_envase: line.tipo_envase, cantidad: Number(line.cantidad || 0) })).filter((e) => e.cantidad > 0)
            : state.catalogs.tipos_envase.map((t) => ({ tipo_envase: t.codigo, cantidad: Number(elements.receptionForm.elements[`cantidad_${t.codigo}`].value || 0) })).filter((e) => e.cantidad > 0),
        writeTotals(totals, multiple) {
            state.catalogs.tipos_envase.forEach((t) => {
                const input = elements.receptionForm.elements[`cantidad_${t.codigo}`];
                input.value = totals[t.codigo] || 0; input.readOnly = multiple;
            });
            if (elements.receptionForm.elements.tipo_recepcion.value === 'fruta_pesaje_envases') {
                const previous = readWeighingLines();
                const lines = Object.entries(totals).filter(([, count]) => count > 0).map(([tipo_envase, cantidad]) => ({
                    ...(previous.find((e) => e.tipo_envase === tipo_envase) || { tara_unitaria: '', propiedad: elements.receptionForm.elements[`propiedad_${tipo_envase}`]?.value || '' }), tipo_envase, cantidad,
                }));
                renderWeighingLines(lines);
            }
            lockGuideTotals(multiple); syncContainerProperties(); syncAdministrativeNetContainerOptions();
        },
    });
}
function lockGuideTotals(multiple = receptionGuides?.multiple() === true) {
    elements.standardContainerLines.querySelector('legend').textContent = multiple ? 'Totales de envases del camión *' : 'Envases declarados en la guía *';
    elements.standardContainerLines.querySelectorAll('input[type="number"]').forEach((input) => { input.readOnly = multiple; });
    elements.containerWeighingLines.querySelectorAll('[data-line="cantidad"]').forEach((input) => { input.readOnly = multiple; });
    elements.containerWeighingLines.querySelectorAll('[data-line="tipo_envase"]').forEach((input) => { input.disabled = multiple; });
    const rows = elements.containerWeighingLines.querySelectorAll('[data-weighing-line]');
    elements.containerWeighingLines.querySelectorAll('[data-remove-weighing-line]').forEach((input) => { input.disabled = multiple || rows.length === 1; });
    elements.addContainerWeighingLine.classList.toggle('is-hidden', multiple);
}


class ApiError extends Error {
    constructor(message, status, data = {}) { super(message); this.name = 'ApiError'; this.status = status; this.data = data; }
}

function readJson(key) { try { return JSON.parse(localStorage.getItem(key) || 'null'); } catch { return null; } }
function escapeHtml(value) { return String(value ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&#039;'); }
function errorMessage(data, fallback) { return Object.values(data?.errors || {}).flat()[0] || data?.message || fallback; }
function operationUuid() {
    if (typeof crypto.randomUUID === 'function') return crypto.randomUUID();
    const bytes = crypto.getRandomValues(new Uint8Array(16)); bytes[6] = (bytes[6] & 0x0f) | 0x40; bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = [...bytes].map((value) => value.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}
function formatDate(value, fallback = 'Pendiente') {
    if (!value) return fallback;
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? fallback : new Intl.DateTimeFormat('es-CL', { dateStyle: 'short', timeStyle: 'short' }).format(date);
}
function formatWeight(value, fallback = '—') {
    if (value === null || value === undefined || value === '') return fallback;
    return `${new Intl.NumberFormat('es-CL', { minimumFractionDigits: 2, maximumFractionDigits: 3 }).format(Number(value))} kg`;
}
function label(value) {
    if (containerLabel(value)) return containerLabel(value);
    const labels = {
        recepcion_eliminada: 'Recepción eliminada por el administrador',
        peso_bruto_corregido: 'Peso bruto corregido',
        mismos: 'Se va con los mismos', diferentes: 'Se va con más o menos', vacio: 'Se va vacío',
        en_bascula_ingreso: 'En báscula ingreso', en_pesaje_envases: 'Pesaje acumulativo', en_bascula_salida: 'Pendiente de destare', cerrado: 'Cerrado',
        anulada: 'Anulada', recepcion_anulada: 'Recepción anulada', ingreso_registrado: 'Ingreso registrado', ingreso_actualizado: 'Antecedentes de ingreso actualizados', correccion_administrativa: 'Corrección administrativa', ingreso_confirmado: 'Ingreso confirmado', pesaje_envases_registrado: 'Tanda de envases pesada', pesaje_envases_anulado: 'Tanda de pesaje anulada', recepcion_cerrada: 'Recepción cerrada', salida_envases_corregida: 'Salida de envases corregida', correccion_integral: 'Corrección administrativa de recepción validada',
        almacenaje: 'Almacenaje', proceso: 'Proceso', prefrio: 'Pre-frío', fruta_con_envases: 'Fruta con envases', fruta_pesaje_envases: 'Fruta con pesaje acumulativo', solo_envases: 'Solo envases', termo: 'Camión termo', plano: 'Camión plano', compra: 'Compra', arriendo: 'Arriendo', pendiente: 'Pendiente', en_curso: 'En curso', validada: 'Validada',
    };
    return labels[value] || String(value || '').replaceAll('_', ' ').replace(/^./, (character) => character.toUpperCase());
}
function guideNumbers(reception) { return reception.guias?.length ? reception.guias.map((g) => g.numero_guia_despacho).join(' / ') : reception.numero_guia_despacho; }
function stateBadge(status) {
    const style = status === 'cerrado' ? 'closed' : ['en_bascula_salida', 'en_pesaje_envases'].includes(status) ? 'exit' : 'entry';
    return `<span class="state-badge state-badge--${style}">${escapeHtml(label(status))}</span>`;
}
function receptionStateLabel(reception, status) {
    if (status === 'anulada' && reception.eliminada) return 'Eliminada';
    if (reception.tipo_recepcion !== 'solo_envases') return label(status);
    if (status === 'en_bascula_ingreso') return 'Ingreso documental';
    if (status === 'en_bascula_salida') return 'Listo para cerrar';
    return label(status);
}
function receptionTrack(reception) {
    const steps = receptionSteps(reception);
    const spoken = { done: 'hecho', now: 'en curso', next: 'pendiente' };
    return `<ol class="rec-steps" style="--rec-steps: ${steps.length}" aria-label="Avance de la recepción">${steps.map((step) => `<li class="rec-step rec-step--${step.state}"${step.state === 'now' ? ' aria-current="step"' : ''}><span>${escapeHtml(step.label)}</span><span class="rec-sr">, ${spoken[step.state]}</span></li>`).join('')}</ol>`;
}
// Hoy se muestra solo la hora; otro día, fecha corta y hora (misma zona que formatDate).
function formatTime(value) {
    if (!value) return 'pendiente';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return 'pendiente';
    const today = new Date().toDateString() === date.toDateString();
    return new Intl.DateTimeFormat('es-CL', today ? { timeStyle: 'short' } : { dateStyle: 'short', timeStyle: 'short' }).format(date);
}
function formatKpiWeight(value) {
    const number = new Intl.NumberFormat('es-CL', { maximumFractionDigits: 0 }).format(Number(value || 0));
    return `${escapeHtml(number)} <span class="kpi-unit">kg</span>`;
}
function receptionStateBadge(reception) {
    if (reception.tipo_recepcion !== 'solo_envases' || reception.estado === 'anulada') return stateBadge(reception.estado);
    const contextualLabel = receptionStateLabel(reception, reception.estado);
    const style = reception.estado === 'cerrado' ? 'closed' : reception.estado === 'en_bascula_salida' ? 'exit' : 'entry';
    return `<span class="state-badge state-badge--${style}">${escapeHtml(contextualLabel)}</span>`;
}
function setBusy(active, message = 'Procesando…') {
    elements.loadingText.textContent = message;
    elements.loading.classList.toggle('is-hidden', !active);
    elements.loading.setAttribute('aria-hidden', String(!active));
}
function toast(message, error = false) {
    const node = document.createElement('div'); node.className = `toast${error ? ' toast--error' : ''}`; node.textContent = message; elements.toasts.append(node); window.setTimeout(() => node.remove(), 4500);
}
function persist(payload) {
    state.token = payload.token; state.identity = payload.usuario;
    localStorage.setItem(keys.token, payload.token); localStorage.setItem(keys.identity, JSON.stringify(payload.usuario));
}
function clearSession() {
    gatehouseRomana.stop();
    state.token = null; state.identity = null; state.receptions = []; state.selected = null;
    localStorage.removeItem(keys.token); localStorage.removeItem(keys.identity); state.poller?.stop(); state.poller = null;
    elements.app.classList.add('is-hidden'); elements.access.classList.remove('is-hidden');
}

async function api(path, options = {}) {
    const headers = new Headers(options.headers || {}); headers.set('Accept', 'application/json');
    if (state.token) headers.set('Authorization', `Bearer ${state.token}`);
    if (options.body) headers.set('Content-Type', 'application/json');
    let response;
    try { response = await fetch(path, { ...options, headers }); } catch { throw new ApiError('No fue posible conectar con Laravel.', 0); }
    const data = response.status === 204 ? null : await response.json().catch(() => ({}));
    if (!response.ok) {
        if (response.status === 401 && path !== '/api/acceso-oficina') clearSession();
        throw new ApiError(errorMessage(data, 'No fue posible completar la operación.'), response.status, data);
    }
    return data;
}

function showApp() {
    if (state.identity?.puede_consultar_romana !== true) return false;
    elements.access.classList.add('is-hidden'); elements.app.classList.remove('is-hidden');
    const name = state.identity?.nombre || 'Usuario de romana';
    elements.userName.textContent = name; elements.userRole.textContent = label(state.identity?.rol || 'consulta');
    elements.initials.textContent = name.split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0]).join('').toUpperCase();
    elements.newReception.classList.toggle('is-hidden', state.identity?.puede_operar_romana !== true);
    elements.rawMaterialNav.classList.toggle('is-hidden', state.identity?.puede_consultar_materia_prima !== true);
    elements.containerAccountsNav.classList.toggle('is-hidden', state.identity?.puede_consultar_cuenta_envases !== true);
    elements.managementNav.classList.toggle('is-hidden', state.identity?.puede_consultar_panel_gerencial !== true);
    elements.camerasNav.classList.toggle('is-hidden', state.identity?.ambito_camaras === 'ninguno');
    elements.loadsNav.classList.toggle('is-hidden', state.identity?.puede_consultar_cargas !== true);
    elements.materialsNav.classList.toggle('is-hidden', state.identity?.puede_consultar_despachos_materiales !== true);
    elements.validationNav.classList.toggle('is-hidden', state.identity?.puede_consultar_validaciones_pallet !== true);
    elements.prefrioNav.classList.toggle('is-hidden', state.identity?.puede_consultar_prefrio !== true);
    elements.accessesNav.classList.toggle('is-hidden', state.identity?.puede_administrar_accesos !== true);
    return true;
}

function fillCatalogs() {
    const form = elements.receptionForm.elements;
    const quantities = Object.fromEntries(state.catalogs.tipos_envase.map((t) => [t.codigo, form[`cantidad_${t.codigo}`]?.value || '0']));
    const properties = Object.fromEntries(state.catalogs.tipos_envase.map((t) => [t.codigo, form[`propiedad_${t.codigo}`]?.value || '']));
    byId('standardContainerLines').innerHTML = '<legend>Envases declarados en la guía *</legend><small data-container-property-help>Indica su propiedad. Propios y arrendados corresponden a envases de planta que regresan con fruta.</small>'
        + state.catalogs.tipos_envase.map((t) => `<div class="container-entry-line"><label><span>${escapeHtml(t.nombre)}</span><input name="cantidad_${t.codigo}" type="number" min="0" max="100000" value="${quantities[t.codigo]}"></label><label class="is-hidden" data-container-property="${t.codigo}"><span>Propiedad *</span><select name="propiedad_${t.codigo}" aria-label="Propiedad de ${escapeHtml(t.nombre)}" disabled>${propertyOptions(properties[t.codigo])}</select></label></div>`).join('');
    renderWeighingLines(readWeighingLines());
    const activeSeasons = state.catalogs.temporadas.filter((season) => season.activa);
    form.temporada_id.innerHTML = '<option value="">Seleccionar temporada activa</option>' + activeSeasons.map((season) => `<option value="${escapeHtml(season.id)}">${escapeHtml(season.nombre)} · ${escapeHtml(season.codigo)}</option>`).join('');
    elements.filters.elements.temporada_id.innerHTML = '<option value="">Temporada activa</option>' + state.catalogs.temporadas.map((season) => `<option value="${escapeHtml(season.id)}">${escapeHtml(season.codigo)}${season.activa ? ' (activa)' : ''}</option>`).join('');
    form.cliente_id.innerHTML = '<option value="">Seleccionar cliente activo</option>' + state.catalogs.clientes.map((client) => `<option value="${escapeHtml(client.id)}">${escapeHtml(client.nombre)}${client.codigo ? ` · ${escapeHtml(client.codigo)}` : ''}</option>`).join('');
    form.tipo_servicio.innerHTML = state.catalogs.tipos_servicio.map((type) => `<option value="${escapeHtml(type.codigo)}">${escapeHtml(type.nombre)}</option>`).join('');
    form.tipo_recepcion.innerHTML = state.catalogs.tipos_recepcion.map((type) => `<option value="${escapeHtml(type.codigo)}">${escapeHtml(type.nombre)}</option>`).join('');
    form.especie_validacion_id.innerHTML = '<option value="">Seleccionar especie</option>' + state.catalogs.especies.map((species) => `<option value="${escapeHtml(species.id)}">${escapeHtml(species.nombre)}</option>`).join('');
    form.concepto_envases.innerHTML = state.catalogs.conceptos_envases.map((type) => `<option value="${escapeHtml(type.codigo)}">${escapeHtml(type.nombre)}</option>`).join('');
    syncContainerProperties();
}

const containerProperties = { cliente: 'Del cliente', propia: 'Propios', arrendada: 'Arrendados' };
function propertyOptions(selected = '') {
    return '<option value="">Seleccionar</option>' + Object.entries(containerProperties)
        .map(([value, name]) => `<option value="${value}"${value === selected ? ' selected' : ''}>${name}</option>`).join('');
}
function syncContainerProperties() {
    const form = elements.receptionForm.elements;
    const fruit = form.tipo_recepcion.value !== 'solo_envases';
    const standard = fruit && form.tipo_recepcion.value !== 'fruta_pesaje_envases';
    document.querySelector('[data-container-property-help]')?.classList.toggle('is-hidden', !fruit);
    elements.standardContainerLines.querySelectorAll('[data-container-property]').forEach((field) => {
        const active = standard && Number(form[`cantidad_${field.dataset.containerProperty}`]?.value || 0) > 0;
        field.classList.toggle('is-hidden', !active);
        field.querySelector('select').disabled = !active;
        field.querySelector('select').required = active;
    });
}

/*
 * Pesaje acumulativo con uno o más tipos de envase con fruta. Cada fila declara
 * el total a pesar, la tara por envase y su propiedad; las tandas pueden mezclarlos.
 */
function fruitContainerTypes() { return state.catalogs.tipos_envase.filter((t) => t.contiene_fruta); }
function readWeighingLines() {
    return [...elements.containerWeighingLines.querySelectorAll('[data-weighing-line]')].map((row) => ({
        tipo_envase: row.querySelector('[data-line="tipo_envase"]').value,
        cantidad: row.querySelector('[data-line="cantidad"]').value,
        tara_unitaria: row.querySelector('[data-line="tara_unitaria"]').value,
        propiedad: row.querySelector('[data-line="propiedad"]').value,
    }));
}
function renderWeighingLines(lines) {
    const types = fruitContainerTypes();
    const rows = lines.length ? lines : [{ tipo_envase: types[0]?.codigo || '', cantidad: '1', tara_unitaria: '', propiedad: '' }];
    elements.containerWeighingLines.innerHTML = rows.map((line, index) => `<div class="container-weighing-line" data-weighing-line>
        <label><span>Tipo de envase</span><select data-line="tipo_envase" aria-label="Tipo de envase ${index + 1}">${types.map((t) => `<option value="${escapeHtml(t.codigo)}"${t.codigo === line.tipo_envase ? ' selected' : ''}>${escapeHtml(t.nombre)}</option>`).join('')}</select></label>
        <label><span>Total a pesar</span><input data-line="cantidad" type="number" min="1" max="100000" value="${escapeHtml(line.cantidad ?? '1')}" aria-label="Total a pesar ${index + 1}"></label>
        <label class="weight-field"><span>Tara por envase</span><div><input data-line="tara_unitaria" type="number" min="0.001" max="1000" step="0.001" inputmode="decimal" value="${escapeHtml(line.tara_unitaria ?? '')}" aria-label="Tara por envase ${index + 1}"><b>kg</b></div></label>
        <label><span>Propiedad del envase *</span><select data-line="propiedad" aria-label="Propiedad del envase ${index + 1}">${propertyOptions(line.propiedad || '')}</select></label>
        <button type="button" data-remove-weighing-line aria-label="Quitar tipo de envase ${index + 1}" title="Quitar este tipo de envase"${rows.length === 1 ? ' disabled' : ''}>×</button>
    </div>`).join('');
    elements.addContainerWeighingLine.disabled = rows.length >= types.length;
    lockGuideTotals();
}
elements.addContainerWeighingLine.addEventListener('click', () => {
    const lines = readWeighingLines();
    const used = new Set(lines.map((line) => line.tipo_envase));
    const next = fruitContainerTypes().find((t) => !used.has(t.codigo));
    if (!next) return;
    renderWeighingLines([...lines, { tipo_envase: next.codigo, cantidad: '1', tara_unitaria: '', propiedad: '' }]);
    elements.containerWeighingLines.querySelectorAll('[data-weighing-line]')[lines.length]?.querySelector('[data-line="cantidad"]').focus();
});
elements.containerWeighingLines.addEventListener('click', (event) => {
    const button = event.target.closest('[data-remove-weighing-line]');
    if (!button) return;
    const rows = [...elements.containerWeighingLines.querySelectorAll('[data-weighing-line]')];
    const index = rows.indexOf(button.closest('[data-weighing-line]'));
    renderWeighingLines(readWeighingLines().filter((_, position) => position !== index));
});

function filterQuery() {
    const values = Object.fromEntries(new FormData(elements.filters));
    const query = new URLSearchParams({ por_pagina: '30', pagina: String(state.page) });
    Object.entries(values).forEach(([key, value]) => { if (value) query.set(key, value); });
    query.set('page', String(state.page));
    query.delete('pagina');
    return query.toString();
}

function renderList(payload) {
    state.receptions = payload.data; state.meta = payload.meta;
    elements.entryCount.textContent = String(payload.resumen.en_bascula_ingreso);
    elements.containerWeighingCount.textContent = String(payload.resumen.en_pesaje_envases);
    elements.exitCount.textContent = String(payload.resumen.en_bascula_salida);
    elements.exitCount.closest('article')?.classList.toggle('has-attention', Number(payload.resumen.en_bascula_salida) > 0);
    elements.closedCount.textContent = String(payload.resumen.cerradas);
    elements.netWeight.innerHTML = formatKpiWeight(payload.resumen.peso_neto);
    elements.paginationSummary.textContent = `${payload.meta.total} ${payload.meta.total === 1 ? 'recepción' : 'recepciones'} · página ${payload.meta.pagina_actual} de ${payload.meta.ultima_pagina}`;
    elements.previousPage.disabled = payload.meta.pagina_actual <= 1;
    elements.nextPage.disabled = payload.meta.pagina_actual >= payload.meta.ultima_pagina;

    if (!state.receptions.length) {
        elements.tableBody.innerHTML = '<tr class="weighbridge-empty"><td colspan="4">No existen recepciones para los filtros seleccionados.</td></tr>';
        return;
    }

    elements.tableBody.innerHTML = state.receptions.map((reception) => {
        const onlyContainers = reception.tipo_recepcion === 'solo_envases';
        const weightLabel = onlyContainers ? 'Sin pesaje' : reception.pesaje_envases ? 'Tandas' : reception.peso_neto === null ? 'Bruto' : 'Neto';
        const weightValue = onlyContainers
            ? 'Documental'
            : reception.pesaje_envases
                ? `${reception.pesaje_envases.cantidad_pesada} de ${reception.pesaje_envases.cantidad_declarada}`
                : formatWeight(reception.peso_neto, formatWeight(reception.peso_bruto));
        const truck = [reception.patente_camion, reception.tipo_camion ? label(reception.tipo_camion).toLowerCase() : null].filter(Boolean).join(', ');
        return `
        <tr class="rec-row" data-reception-id="${escapeHtml(reception.id)}" tabindex="0">
            <td><span class="rec-k">Recepción</span><span class="rec-title-row"><strong class="rec-code">${escapeHtml(reception.numero_recepcion || 'Ingreso abierto')}</strong>${reception.puede_eliminar ? `<button type="button" class="secondary-button rec-delete-button" data-delete-reception-id="${escapeHtml(reception.id)}" aria-label="Eliminar recepción ${escapeHtml(reception.numero_recepcion)}">Eliminar</button>` : ''}</span><small>Ingreso ${escapeHtml(formatTime(reception.ingreso_at))}</small></td>
            <td><span class="rec-k">Cliente y guía</span><strong>${escapeHtml(reception.cliente.nombre)}</strong><small>Guía <span class="rec-mono">${escapeHtml(guideNumbers(reception))}</span></small></td>
            <td><span class="rec-k">Transporte y declaración</span><strong>${escapeHtml(truck)}</strong><small>${escapeHtml(envasesLabel(reception))} · ${escapeHtml(reception.nombre_conductor)}</small></td>
            <td class="rec-weight"><span class="rec-k">${escapeHtml(weightLabel)}</span><strong class="rec-code">${escapeHtml(weightValue)}</strong></td>
        </tr>
        <tr class="rec-track" data-reception-id="${escapeHtml(reception.id)}"><td colspan="4">${receptionTrack(reception)}</td></tr>`;
    }).join('');
}

async function loadCatalogs() { const [catalogs, containers] = await Promise.all([api('/api/romana/catalogos'), loadContainerCatalog(api)]); state.catalogs = { ...catalogs, tipos_envase: containers }; fillCatalogs(); }
async function loadReceptions({ silent = false } = {}) {
    const payload = await api(`/api/romana/recepciones?${filterQuery()}`); renderList(payload);
    if (state.selected) {
        const exists = state.receptions.some((item) => item.id === state.selected.id);
        if (exists) await selectReception(state.selected.id, { silent: true });
        else closeDetail();
    }
    if (!silent) toast('Recepciones actualizadas.');
}

function fact(title, value, weight = false) { return `<article class="detail-fact${weight ? ' detail-fact--weight' : ''}"><span>${escapeHtml(title)}</span><strong>${escapeHtml(value ?? '—')}</strong></article>`; }
function grossWeightAudit(event) {
    if (event.tipo !== 'peso_bruto_corregido') return '';
    const before = event.datos.pesaje_anterior || event.datos.anterior;
    const after = event.datos.pesaje_posterior || event.datos.posterior;
    return `<small>Bruto: ${escapeHtml(formatWeight(before.peso_bruto))} → ${escapeHtml(formatWeight(after.peso_bruto))}${event.datos.pesaje_anterior ? ` · Tanda ${escapeHtml(before.secuencia)}` : ''}</small>`;
}
/** Envases con fruta de lo validado; las respuestas anteriores no traen los vacíos. */
function withFruit(item) { return item.cantidad_con_fruta ?? item.cantidad_validada; }
function envasesLabel(reception) { return (reception.envases || []).map((item) => `${item.cantidad_declarada} ${label(item.tipo_envase)}${item.propiedad ? ` (${containerProperties[item.propiedad] || item.propiedad})` : ''}`).join(' · ') || 'Sin envases'; }
/** Tipos de envase del pesaje acumulativo; las respuestas antiguas traen uno solo. */
function weighingTypes(weighing) {
    if (!weighing) return [];
    return weighing.tipos?.length ? weighing.tipos : [{ tipo_envase: weighing.tipo_envase, cantidad_declarada: weighing.cantidad_declarada, cantidad_pesada: weighing.cantidad_pesada, cantidad_pendiente: weighing.cantidad_pendiente, tara_unitaria: weighing.tara_unitaria }];
}
function readingContainersLabel(reading) {
    const lines = reading.envases?.length ? reading.envases : [{ tipo_envase: reading.tipo_envase, cantidad: reading.cantidad_envases }];
    return lines.map((line) => `${line.cantidad} ${label(line.tipo_envase)}`).join(' + ');
}
function weighingTotalLabel(weighing) {
    const types = weighingTypes(weighing);
    return types.length > 1
        ? `${weighing.cantidad_declarada} envases (${types.map((item) => `${item.cantidad_declarada} ${label(item.tipo_envase)}`).join(', ')})`
        : `${weighing.cantidad_declarada} ${label(weighing.tipo_envase)}`;
}
function renderContainerWeighings(reception, canOperate) {
    const weighing = reception.pesaje_envases;
    elements.containerWeighingPanel.classList.toggle('is-hidden', !weighing);
    if (!weighing) {
        elements.containerWeighingList.innerHTML = '';
        return;
    }
    const types = weighingTypes(weighing);
    elements.containerWeighingProgress.textContent = types.length > 1
        ? `${weighing.cantidad_pesada} / ${weighing.cantidad_declarada} envases · faltan ${weighing.cantidad_pendiente} · ${types.map((item) => `${label(item.tipo_envase)} ${item.cantidad_pesada}/${item.cantidad_declarada}`).join(' · ')}`
        : `${weighing.cantidad_pesada} / ${weighing.cantidad_declarada} ${label(weighing.tipo_envase)} · faltan ${weighing.cantidad_pendiente}`;
    const readings = reception.lecturas_pesaje_envases || [];
    elements.containerWeighingList.innerHTML = readings.length ? readings.map((reading) => `
        <article class="container-weighing-row${reading.anulado ? ' container-weighing-row--annulled' : ''}">
            <div><span>TANDA</span><strong>#${reading.secuencia}</strong></div>
            <div><span>ENVASES</span><strong>${escapeHtml(readingContainersLabel(reading))}</strong></div>
            <div><span>BRUTO</span><strong>${escapeHtml(formatWeight(reading.peso_bruto))}</strong></div>
            <div><span>TARA</span><strong>${escapeHtml(formatWeight(reading.peso_tara))}</strong></div>
            <div><span>NETO</span><strong>${escapeHtml(formatWeight(reading.peso_neto))}</strong></div>
            <div><span>${reading.anulado ? 'ANULADO' : 'REGISTRADO'}</span><strong>${escapeHtml(formatDate(reading.anulado_at || reading.pesado_at))}</strong>${reading.motivo_anulacion ? `<small>${escapeHtml(reading.motivo_anulacion)}</small>` : ''}</div>
            ${canOperate && !reading.anulado && reception.puede_registrar_pesaje ? `<button type="button" data-annul-weighing-id="${escapeHtml(reading.id)}">Anular</button>` : '<span></span>'}
        </article>`).join('') : '<p class="weighbridge-empty">Aún no se registran tandas. Puedes comenzar con 1, 3 o cualquier cantidad pendiente.</p>';
}
/** Bins de la planta agregados en Validación MP; se prestan a la cuenta del cliente y vuelven en el destare. */
function plantBinsFact(reception) {
    const loan = reception.bins_prestados_planta;
    if (!loan?.cantidad) return '';
    return `${loan.cantidad} ${loan.cantidad === 1 ? 'prestado' : 'prestados'} · ${loan.devuelto ? 'devuelto internamente' : 'se devuelve al cerrar'}`;
}

function renderDetail(reception) {
    state.selected = reception;
    elements.detail.classList.remove('is-hidden');
    elements.detailTitle.textContent = reception.numero_recepcion || `Ingreso · ${reception.patente_camion}`;
    elements.detailSubtitle.innerHTML = `${receptionStateBadge(reception)} · Guía ${escapeHtml(guideNumbers(reception))} · ${escapeHtml(reception.cliente.nombre)}`;
    const weighingKinds = weighingTypes(reception.pesaje_envases);
    const weighingFacts = reception.pesaje_envases ? [
        ...(weighingKinds.length > 1 ? [
            fact('ENVASES PESADOS', weighingKinds.map((item) => label(item.tipo_envase)).join(' · ')),
            fact('TARA POR ENVASE', weighingKinds.map((item) => `${label(item.tipo_envase)} ${formatWeight(item.tara_unitaria)}`).join(' · ')),
        ] : [
            fact('ENVASE PESADO', label(reception.pesaje_envases.tipo_envase)),
            fact('TARA UNITARIA', formatWeight(reception.pesaje_envases.tara_unitaria), true),
        ]),
        fact('AVANCE', `${reception.pesaje_envases.cantidad_pesada} / ${reception.pesaje_envases.cantidad_declarada}`),
        fact('PENDIENTES', String(reception.pesaje_envases.cantidad_pendiente)),
    ] : [];
    const standardWeightFacts = reception.tipo_recepcion === 'solo_envases' ? [
        fact('PESAJE', 'No aplica · recepción exclusiva de envases'),
    ] : [
        fact(reception.pesaje_envases ? 'BRUTO ACUMULADO' : 'PESO BRUTO', formatWeight(reception.peso_bruto), true),
        fact(reception.pesaje_envases ? 'TARA ACUMULADA' : 'TARA CAMIÓN', formatWeight(reception.peso_tara), true),
        ...(reception.modo_salida_envases && reception.peso_tara_envases !== 0 ? [
            fact('DIFERENCIA DE TARA DE ENVASES', formatWeight(reception.peso_tara_envases), true),
        ] : []),
        fact('PESO NETO', formatWeight(reception.peso_neto), true),
    ];
    const outboundTareFacts = reception.modo_salida_envases ? [
        fact(
            'TARAS UNITARIAS',
            reception.envases
                .map((item) => `${label(item.tipo_envase)} ${formatWeight(item.tara_unitaria_salida)}/u`)
                .join(' · '),
        ),
    ] : [];
    elements.detailFacts.innerHTML = [
        fact('INGRESO', formatDate(reception.ingreso_at)), fact(reception.tipo_recepcion === 'solo_envases' ? 'CIERRE DOCUMENTAL' : reception.pesaje_envases ? 'CIERRE DE PESAJE' : 'SALIDA / DESTARE', formatDate(reception.salida_at)), fact('TEMPORADA GLOBAL', `${reception.temporada.nombre} · ${reception.temporada.codigo}`), fact('CLIENTE', reception.cliente.nombre), fact('TIPO RECEPCIÓN', label(reception.tipo_recepcion)), fact('SERVICIO / CONCEPTO', reception.tipo_recepcion === 'solo_envases' ? label(reception.concepto_envases) : label(reception.tipo_servicio)), fact('GUÍAS', guideNumbers(reception)),
        ...(reception.destare_pendiente_validacion ? [fact('DESTARE', 'Espera la Validación MP completa en la PDA')] : []),
        ...(reception.modo_salida_envases ? [fact('SALIDA ENVASES', label(reception.modo_salida_envases)), fact('GUÍA DE SALIDA', reception.numero_guia_salida || 'Sin salida'), fact('ENVASES RETIRADOS', (reception.salida_envases || []).filter((x) => x.cantidad > 0).map((x) => `${x.cantidad} ${label(x.tipo_envase)}`).join(' · ') || 'Ninguno')] : []),
        ...(plantBinsFact(reception) ? [fact('BINS DE PLANTA', plantBinsFact(reception))] : []),
        fact('CAMIÓN', reception.patente_camion), fact('TIPO DE CAMIÓN', reception.tipo_camion ? label(reception.tipo_camion) : 'No informado'), fact('CARRO', reception.patente_carro || 'No informado'), fact('CONDUCTOR', reception.nombre_conductor), fact('RUT', reception.rut_conductor), fact('ENVASES DECLARADOS', envasesLabel(reception)), fact('VALIDACIÓN MP', label(reception.estado_validacion_mp)),
        ...weighingFacts,
        ...outboundTareFacts,
        ...standardWeightFacts,
        ...(reception.observacion_conteo?.length ? [fact('CONTEO VALIDACIÓN MP', reception.observacion_conteo.join(' '))] : []),
        fact('VERSIÓN', String(reception.version)), fact('OBS. INGRESO', reception.observacion || 'Sin observaciones'), fact('OBS. CIERRE', reception.observacion_cierre || 'Sin observaciones'),
    ].join('');
    elements.detailTimeline.innerHTML = (reception.eventos || []).map((event) => `<article class="timeline-item"><i></i><div><strong>${escapeHtml(label(event.tipo))}</strong><small>${escapeHtml(event.usuario?.nombre || 'Sistema')} · ${escapeHtml(event.estado_anterior ? `${receptionStateLabel(reception, event.estado_anterior)} → ${receptionStateLabel(reception, event.estado_nuevo)}` : receptionStateLabel(reception, event.estado_nuevo))}</small>${grossWeightAudit(event)}${event.datos?.motivo ? `<small>Motivo: ${escapeHtml(event.datos.motivo)}</small>` : ''}</div><time>${escapeHtml(formatDate(event.ocurrido_at))}</time></article>`).join('');
    elements.weightBalance.innerHTML = reception.tipo_recepcion === 'solo_envases'
        ? '<div class="net-row"><span>Recepción documental</span><strong>Sin registro de kilos</strong></div>'
        : `<div><span>Bruto${reception.pesaje_envases ? ' acumulado' : ''}</span><strong>${escapeHtml(formatWeight(reception.peso_bruto))}</strong></div><div><span>Tara ${reception.pesaje_envases ? 'acumulada' : 'camión'}</span><strong>${escapeHtml(formatWeight(reception.peso_tara))}</strong></div>${reception.modo_salida_envases && reception.peso_tara_envases !== 0 ? `<div><span>Diferencia tara envases</span><strong>${escapeHtml(formatWeight(reception.peso_tara_envases))}</strong></div>` : ''}${reception.pesaje_envases ? (weighingTypes(reception.pesaje_envases).length > 1
            ? weighingTypes(reception.pesaje_envases).map((item) => `<div><span>Neto por envase · ${escapeHtml(label(item.tipo_envase))}</span><strong>${escapeHtml(formatWeight(item.peso_neto_por_envase))}</strong></div>`).join('')
            : `<div><span>Neto promedio por envase</span><strong>${escapeHtml(formatWeight(reception.peso_neto_por_envase))}</strong></div>`) : ''}<div class="net-row"><span>Neto legal</span><strong>${escapeHtml(formatWeight(reception.peso_neto))}</strong></div>`;
    const canOperate = state.identity?.puede_operar_romana === true;
    const canCorrect = state.identity?.puede_corregir_recepciones_romana === true
        && reception.correccion_administrativa_disponible
        && !reception.puede_editar;
    elements.annulReception.classList.toggle('is-hidden', !reception.puede_anular || Boolean(reception.puede_eliminar));
    elements.deleteReception.classList.toggle('is-hidden', !reception.puede_eliminar);
    elements.correctGrossWeight.classList.toggle('is-hidden', state.identity?.rol !== 'administrador' || !reception.puede_corregir_peso_bruto);
    byId('integralCorrectionButton').classList.toggle('is-hidden', state.identity?.rol !== 'administrador'
        || reception.estado_validacion_mp !== 'validada' || reception.estado === 'anulada' || Boolean(reception.eliminada));
    elements.editReception.textContent = canCorrect ? 'Corregir recepción' : 'Editar ingreso';
    elements.editReception.classList.toggle('is-hidden', (!canOperate || !reception.puede_editar) && !canCorrect);
    elements.confirmEntry.classList.toggle('is-hidden', !canOperate || !reception.puede_confirmar_ingreso);
    elements.addContainerWeighing.classList.toggle('is-hidden', !canOperate || !reception.puede_registrar_pesaje);
    elements.closeReception.textContent = reception.tipo_recepcion === 'solo_envases'
        ? 'Cerrar recepción de envases'
        : reception.pesaje_envases ? 'Cerrar pesaje acumulativo' : 'Registrar destare y cerrar';
    elements.closeReception.classList.toggle('is-hidden', !canOperate || !reception.puede_cerrar);
    elements.correctExit.classList.toggle('is-hidden', !state.identity?.puede_corregir_recepciones_romana || !reception.puede_corregir_salida);
    elements.downloadReceipt.classList.toggle('is-hidden', !reception.aviso_recibo_disponible);
    elements.rc02Reception.classList.toggle('is-hidden', !reception.rc02_recepcion_disponible);
    elements.rc02Dispatch.classList.toggle('is-hidden', !reception.rc02_despacho_disponible);
    renderContainerWeighings(reception, canOperate);
    elements.detail.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

async function selectReception(id, { silent = false } = {}) {
    if (!silent) setBusy(true, 'Abriendo expediente de recepción…');
    try { const payload = await api(`/api/romana/recepciones/${id}`); renderDetail(payload.data); }
    catch (error) { toast(error.message, true); }
    finally { if (!silent) setBusy(false); }
}
function grossWeightReading() {
    const correction = state.grossWeightCorrection;
    if (!correction) return null;
    return correction.reception.pesaje_envases
        ? correction.readings.find((reading) => reading.id === elements.grossWeightForm.elements.pesaje_id.value)
        : correction.reception;
}
function updateGrossWeightPreview(reset = false) {
    const reading = grossWeightReading();
    if (!reading) return;
    const form = elements.grossWeightForm.elements;
    if (reset) form.peso_bruto.value = reading.peso_bruto;
    const tare = Number(reading.peso_tara || 0) + Number(reading.peso_tara_envases || 0);
    elements.grossWeightCurrent.textContent = `Bruto registrado: ${formatWeight(reading.peso_bruto)} · Tara total: ${reading.peso_tara === null ? 'Pendiente de destare' : formatWeight(tare)}`;
    elements.grossWeightPreview.textContent = reading.peso_tara === null ? 'El neto se calculará al registrar el destare.'
        : `Neto resultante: ${formatWeight(Number(form.peso_bruto.value) - tare)}`;
}
elements.correctGrossWeight.addEventListener('click', () => {
    const reception = state.selected;
    if (state.identity?.rol !== 'administrador' || !reception?.puede_corregir_peso_bruto) return;
    const readings = (reception.lecturas_pesaje_envases || []).filter((reading) => !reading.anulado);
    elements.grossWeightForm.reset();
    state.grossWeightCorrection = { reception, readings, operacion_id: operationUuid(), version_conocida: reception.version, submitting: false };
    elements.grossWeightDescription.textContent = `${reception.numero_recepcion} · Guía ${reception.numero_guia_despacho}`;
    elements.grossWeightReadingField.classList.toggle('is-hidden', !reception.pesaje_envases);
    const select = elements.grossWeightForm.elements.pesaje_id;
    select.disabled = !reception.pesaje_envases; select.required = Boolean(reception.pesaje_envases);
    select.innerHTML = readings.map((reading) => `<option value="${escapeHtml(reading.id)}">Tanda ${escapeHtml(reading.secuencia)} · ${escapeHtml(formatWeight(reading.peso_bruto))}</option>`).join('');
    elements.grossWeightError.textContent = '';
    byId('grossWeightLots').innerHTML = (reception.lotes_materia_prima || []).map((lot) => `<p><strong>${escapeHtml(lot.numero_lote)}</strong> · ${escapeHtml(label(lot.estado))} · ${escapeHtml(formatWeight(lot.kilos_netos))}</p>`).join('')
        || '<p>Esta recepción no tiene lotes registrados.</p>';
    byId('grossWeightLotsLink').href = `/oficina/materia-prima/lotes?buscar=${encodeURIComponent(reception.numero_recepcion)}`;
    updateGrossWeightPreview(true);
    elements.grossWeightDialog.showModal();
});
elements.grossWeightForm.elements.pesaje_id.addEventListener('change', () => updateGrossWeightPreview(true));
elements.grossWeightForm.elements.peso_bruto.addEventListener('input', () => updateGrossWeightPreview());
elements.grossWeightCancel.addEventListener('click', () => elements.grossWeightDialog.close());
elements.grossWeightDialog.addEventListener('cancel', (event) => { if (state.grossWeightCorrection?.submitting) event.preventDefault(); });
elements.grossWeightForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const attempt = state.grossWeightCorrection;
    if (!attempt || attempt.submitting || !elements.grossWeightForm.reportValidity()) return;
    const submit = elements.grossWeightForm.querySelector('[type="submit"]');
    const data = { operacion_id: attempt.operacion_id, version_conocida: attempt.version_conocida,
        peso_bruto: elements.grossWeightForm.elements.peso_bruto.value,
        motivo_correccion: elements.grossWeightForm.elements.motivo_correccion.value.trim(),
        ...(attempt.reception.pesaje_envases ? { pesaje_id: elements.grossWeightForm.elements.pesaje_id.value } : {}) };
    // Conserva el UUID para repetir exactamente un envío cuya respuesta se perdió.
    if (attempt.payload && JSON.stringify(attempt.payload) !== JSON.stringify(data)) data.operacion_id = operationUuid();
    attempt.operacion_id = data.operacion_id; attempt.payload = data; attempt.submitting = true;
    const fields = [...elements.grossWeightForm.querySelectorAll('input, textarea, select')];
    const disabled = fields.map((field) => field.disabled);
    fields.forEach((field) => { field.disabled = true; });
    submit.disabled = true; elements.grossWeightCancel.disabled = true; elements.grossWeightError.textContent = '';
    try {
        const payload = await api(`/api/romana/recepciones/${attempt.reception.id}/peso-bruto`, { method: 'PUT', body: JSON.stringify(data) });
        elements.grossWeightDialog.close(); renderDetail(payload.data); toast('Peso bruto corregido y neto recalculado.');
        await loadReceptions({ silent: true });
    } catch (error) { elements.grossWeightError.textContent = error.message; }
    finally {
        attempt.submitting = false; submit.disabled = false; elements.grossWeightCancel.disabled = false;
        fields.forEach((field, index) => { field.disabled = disabled[index]; });
    }
});
function closeDetail() { state.selected = null; elements.detail.classList.add('is-hidden'); }
const integralCorrection = createIntegralCorrection({
    dialog: byId('integralCorrectionDialog'), api, escapeHtml, operationUuid, toast,
    onSaved: async (recepcion) => { renderDetail(recepcion); await loadReceptions({ silent: true }); },
});
byId('integralCorrectionButton').addEventListener('click', async () => {
    if (!state.selected) return;
    setBusy(true, 'Preparando la corrección…');
    try { await integralCorrection.open(state.selected); } catch (error) { toast(error.message, true); } finally { setBusy(false); }
});

function configureAdministrativeCorrection(enabled) {
    const closedCorrection = enabled
        && state.selected?.estado === 'cerrado'
        && state.selected?.tipo_recepcion !== 'solo_envases';
    state.administrativeCorrection = enabled;
    elements.administrativeCorrectionField.classList.toggle('is-hidden', !enabled);
    elements.administrativeTareField.classList.toggle('is-hidden', !closedCorrection);
    elements.administrativeNetContainerField.classList.toggle('is-hidden', !closedCorrection);
    elements.receptionForm.elements.motivo_correccion.required = enabled;
    elements.receptionForm.elements.peso_tara.required = closedCorrection;
    elements.receptionForm.elements.peso_tara.disabled = !closedCorrection;
    elements.receptionForm.elements.tipo_envase_calculo_neto.required = closedCorrection;
    elements.receptionForm.elements.tipo_envase_calculo_neto.disabled = !closedCorrection;
    elements.saveReception.textContent = enabled ? 'Guardar corrección' : 'Guardar ingreso';
    if (!enabled) elements.receptionForm.elements.motivo_correccion.value = '';
}

function syncAdministrativeNetContainerOptions(preferredType = null) {
    if (!state.administrativeCorrection || state.selected?.estado !== 'cerrado') return;
    const form = elements.receptionForm.elements;
    const select = form.tipo_envase_calculo_neto;
    const selected = preferredType || select.value || state.selected.tipo_envase_calculo_neto;
    const available = state.catalogs.tipos_envase.filter((t) => t.contiene_fruta).map((t) => t.codigo)
        .map((type) => ({ type, quantity: Number(form[`cantidad_${type}`].value || 0) }))
        .filter((item) => item.quantity > 0);
    select.innerHTML = available.map((item) => `<option value="${escapeHtml(item.type)}">${escapeHtml(label(item.type))} · ${item.quantity} declarados</option>`).join('');
    if (available.some((item) => item.type === selected)) select.value = selected;
}

function openNewReception() {
    elements.receptionForm.reset(); elements.receptionForm.elements.recepcion_id.value = ''; elements.receptionFormError.textContent = '';
    elements.receptionForm.elements.peso_bruto.readOnly = false;
    elements.receptionForm.elements.peso_bruto.title = '';
    state.catalogs.tipos_envase.forEach(({ codigo }) => { elements.receptionForm.elements[`cantidad_${codigo}`].value = 0; elements.receptionForm.elements[`propiedad_${codigo}`].value = ''; });
    receptionGuides?.reset();
    lockGuideTotals(false);
    renderWeighingLines([]);
    configureAdministrativeCorrection(false);
    elements.receptionDialogTitle.textContent = 'Registrar ingreso';
    const activeSeasons = state.catalogs.temporadas.filter((season) => season.activa);
    if (!activeSeasons.length) {
        toast('No existe una temporada global activa para recibir.', true); return;
    }
    if (!state.catalogs.clientes.length) {
        toast('No existen clientes operacionales activos para recibir.', true); return;
    }
    if (activeSeasons.length === 1) elements.receptionForm.elements.temporada_id.value = activeSeasons[0].id;
    guidesEditor().reset();
    lockGuideTotals();
    toggleReceptionType();
    elements.receptionDialog.showModal();
}
function openEditReception() {
    const canCorrect = state.identity?.puede_corregir_recepciones_romana === true
        && state.selected?.correccion_administrativa_disponible
        && !state.selected?.puede_editar;
    if (!state.selected?.puede_editar && !canCorrect) return;
    const reception = state.selected; const form = elements.receptionForm.elements;
    elements.receptionForm.reset(); elements.receptionFormError.textContent = '';
    configureAdministrativeCorrection(canCorrect);
    elements.receptionDialogTitle.textContent = canCorrect ? 'Corregir recepción' : 'Editar pesaje de ingreso';
    form.recepcion_id.value = reception.id; form.temporada_id.value = reception.temporada.id; form.cliente_id.value = reception.cliente.id; form.tipo_recepcion.value = reception.tipo_recepcion; form.especie_validacion_id.value = reception.especie_validacion_id || ''; form.fecha_ingreso.value = reception.fecha_ingreso || ''; form.concepto_envases.value = reception.concepto_envases || ''; form.tipo_servicio.value = reception.tipo_servicio;
    form.numero_guia_despacho.value = reception.numero_guia_despacho;
    state.catalogs.tipos_envase.map((t) => t.codigo).forEach((tipo) => {
        const item = reception.envases.find((envase) => envase.tipo_envase === tipo);
        form[`cantidad_${tipo}`].value = item?.cantidad_declarada || 0;
        form[`propiedad_${tipo}`].value = item?.propiedad || (item ? 'cliente' : '');
    });
    renderWeighingLines(reception.pesaje_envases
        ? (reception.pesaje_envases.tipos || []).map((item) => ({
            tipo_envase: item.tipo_envase,
            cantidad: String(item.cantidad_declarada),
            tara_unitaria: item.tara_unitaria ?? '',
            propiedad: reception.envases.find((envase) => envase.tipo_envase === item.tipo_envase)?.propiedad || 'cliente',
        }))
        : []);
    form.patente_camion.value = reception.patente_camion; form.tipo_camion.value = reception.tipo_camion || ''; form.patente_carro.value = reception.patente_carro || ''; form.rut_conductor.value = reception.rut_conductor; form.nombre_conductor.value = reception.nombre_conductor;
    form.peso_bruto.value = reception.peso_bruto ?? ''; form.observacion.value = reception.observacion || '';
    form.peso_bruto.readOnly = state.identity?.rol !== 'administrador';
    form.peso_bruto.title = form.peso_bruto.readOnly ? 'Solo el administrador puede corregir un peso bruto ya registrado.' : '';
    if (canCorrect && reception.estado === 'cerrado') {
        form.peso_tara.value = reception.peso_tara;
        syncAdministrativeNetContainerOptions(reception.tipo_envase_calculo_neto);
        form.tipo_envase_calculo_neto.value = reception.tipo_envase_calculo_neto;
    }
    guidesEditor().reset(reception.guias || []);
    lockGuideTotals();
    toggleReceptionType();
    elements.receptionDialog.showModal();
}

elements.login.addEventListener('submit', async (event) => {
    event.preventDefault(); elements.loginError.textContent = ''; setBusy(true, 'Validando acceso a Romana…');
    try {
        const payload = await api('/api/acceso-oficina', { method: 'POST', body: JSON.stringify(Object.fromEntries(new FormData(elements.login))) });
        state.token = payload.token;
        if (payload.usuario.puede_consultar_romana !== true) {
            await api('/api/acceso-oficina', { method: 'DELETE' }).catch(() => {}); state.token = null;
            throw new ApiError('Tu perfil no posee acceso al módulo de Romana.', 403);
        }
        persist(payload); showApp(); setBusy(true, 'Cargando operación de Romana…'); await Promise.all([loadCatalogs(), loadReceptions({ silent: true })]); startRefresh();
    } catch (error) { elements.loginError.textContent = error.message; }
    finally { setBusy(false); }
});

elements.receptionForm.addEventListener('submit', async (event) => {
    if (event.submitter?.value === 'cancel') return;
    event.preventDefault(); elements.receptionFormError.textContent = '';
    const data = Object.fromEntries(new FormData(elements.receptionForm)); const id = data.recepcion_id; delete data.recepcion_id; data.operacion_id = operationUuid();
    if (!data.tipo_camion) {
        elements.receptionFormError.textContent = 'Selecciona el tipo de camión.';
        elements.receptionForm.elements.tipo_camion.focus();
        return;
    }
    const guias = receptionGuides?.payload();
    if (guias) {
        const error = guideProblem(guias);
        if (error) { elements.receptionFormError.textContent = error; return; }
        data.guias = guias; data.numero_guia_despacho = guias[0].numero_guia_despacho;
    }
    const soloEnvases = data.tipo_recepcion === 'solo_envases';
    const cumulativeWeighing = data.tipo_recepcion === 'fruta_pesaje_envases';
    if (cumulativeWeighing) {
        const lines = readWeighingLines();
        const rows = [...elements.containerWeighingLines.querySelectorAll('[data-weighing-line]')];
        const problem = (index, field, message) => {
            elements.receptionFormError.textContent = message;
            rows[index]?.querySelector(`[data-line="${field}"]`)?.focus();
        };
        const repeated = lines.findIndex((line, index) => lines.findIndex((other) => other.tipo_envase === line.tipo_envase) !== index);
        if (repeated >= 0) return problem(repeated, 'tipo_envase', `${label(lines[repeated].tipo_envase)} está repetido: cada tipo de envase va en una sola fila.`);
        const withoutQuantity = lines.findIndex((line) => !(Number(line.cantidad) >= 1));
        if (withoutQuantity >= 0) return problem(withoutQuantity, 'cantidad', `Indica cuántos ${label(lines[withoutQuantity].tipo_envase)} se pesarán.`);
        const withoutTare = lines.findIndex((line) => !(Number(line.tara_unitaria) > 0));
        if (withoutTare >= 0) return problem(withoutTare, 'tara_unitaria', `Configura la tara por envase de ${label(lines[withoutTare].tipo_envase)}.`);
        const withoutProperty = lines.findIndex((line) => !line.propiedad);
        if (withoutProperty >= 0) return problem(withoutProperty, 'propiedad', `Selecciona la propiedad de ${label(lines[withoutProperty].tipo_envase)}: del cliente, propios o arrendados por planta.`);
        data.envases = lines.map((line) => ({ tipo_envase: line.tipo_envase, cantidad: Number(line.cantidad), tara_unitaria: Number(line.tara_unitaria), propiedad: line.propiedad }));
    } else {
        data.envases = state.catalogs.tipos_envase.map((t) => t.codigo).map((tipo) => ({ tipo_envase: tipo, cantidad: Number(data[`cantidad_${tipo}`] || 0), ...(!soloEnvases ? { propiedad: data[`propiedad_${tipo}`] || '' } : {}) })).filter((item) => item.cantidad > 0);
        const missingProperty = !soloEnvases && data.envases.find((item) => !item.propiedad);
        if (missingProperty) {
            elements.receptionFormError.textContent = `Selecciona la propiedad de ${label(missingProperty.tipo_envase)}: del cliente, propios o arrendados por planta.`;
            elements.receptionForm.elements[`propiedad_${missingProperty.tipo_envase}`].focus();
            return;
        }
    }
    state.catalogs.tipos_envase.map((t) => t.codigo).forEach((tipo) => { delete data[`cantidad_${tipo}`]; delete data[`propiedad_${tipo}`]; });
    if (cumulativeWeighing || soloEnvases) delete data.peso_bruto;
    const administrativeCorrection = Boolean(id && state.administrativeCorrection);
    if (administrativeCorrection) data.version_conocida = state.selected?.version;
    else delete data.motivo_correccion;
    setBusy(true, administrativeCorrection ? 'Aplicando corrección administrativa…' : id ? 'Actualizando ingreso…' : soloEnvases ? 'Registrando recepción documental…' : 'Registrando pesaje de ingreso…');
    try {
        const path = administrativeCorrection
            ? `/api/romana/recepciones/${id}/corregir`
            : id ? `/api/romana/recepciones/${id}` : '/api/romana/recepciones';
        const payload = await api(path, { method: id ? 'PUT' : 'POST', body: JSON.stringify(data) });
        elements.receptionDialog.close(); await loadReceptions({ silent: true }); await selectReception(payload.data.id, { silent: true });
        toast(administrativeCorrection ? 'Recepción corregida con trazabilidad.' : id ? 'Ingreso actualizado.' : soloEnvases ? 'Recepción de envases registrada sin kilos.' : 'Pesaje de ingreso registrado.');
    } catch (error) { elements.receptionFormError.textContent = error.message; }
    finally { setBusy(false); }
});

function openReceptionRemoval(reception, deleting = false) {
    if (!reception || !(deleting ? reception.puede_eliminar : reception.puede_anular)) return;
    state.annulment = { id: reception.id, operacion_id: operationUuid(), version_conocida: reception.version, deleting, submitting: false };
    elements.annulForm.reset(); elements.annulError.textContent = '';
    elements.annulSummary.textContent = `${reception.numero_recepcion} · ${reception.cliente.nombre} · Guía ${reception.numero_guia_despacho} · ${envasesLabel(reception)}`;
    byId('annulReceptionTitle').textContent = deleting ? 'Eliminar recepción' : 'Anular recepción';
    byId('annulReceptionDescription').textContent = deleting
        ? 'La recepción y sus lotes dejarán de estar vigentes. Se corregirán los saldos de envases y se conservará el historial con tu nombre y el motivo.'
        : 'Se retirará de las recepciones vigentes y de los pendientes de Validación MP. Los ingresos confirmados se revertirán con trazabilidad. Si tiene lotes, reservas o correcciones vinculadas, se indicará qué debe resolverse.';
    byId('annulReceptionReasonLabel').textContent = deleting ? 'Motivo de eliminación' : 'Motivo de anulación';
    elements.annulForm.querySelector('[type="submit"]').textContent = deleting ? 'Eliminar recepción' : 'Confirmar anulación';
    elements.annulForm.querySelector('[type="submit"]').disabled = false;
    elements.annulDialog.showModal();
}
elements.annulReception.addEventListener('click', () => openReceptionRemoval(state.selected));
elements.deleteReception.addEventListener('click', () => openReceptionRemoval(state.selected, true));
byId('annulReceptionCancel').addEventListener('click', () => elements.annulDialog.close());
elements.annulForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (!state.annulment || state.annulment.submitting) return;
    const submit = elements.annulForm.querySelector('[type="submit"]');
    const attempt = state.annulment;
    const { id, operacion_id, version_conocida, deleting } = attempt;
    attempt.submitting = true;
    submit.disabled = true; elements.annulError.textContent = '';
    try {
        await api(`/api/romana/recepciones/${id}${deleting ? '' : '/anular'}`, { method: deleting ? 'DELETE' : 'POST', body: JSON.stringify({ operacion_id, version_conocida, motivo: elements.annulForm.elements.motivo.value.trim() }) });
        elements.annulDialog.close(); closeDetail();
        await loadReceptions({ silent: true }); toast(deleting ? 'Recepción eliminada de los registros vigentes. Historial disponible en Anuladas.' : 'Recepción anulada. Su historial queda disponible en el filtro Anuladas.');
    } catch (error) { elements.annulError.textContent = error.message; }
    finally { attempt.submitting = false; if (state.annulment === attempt) submit.disabled = false; }
});

elements.confirmEntry.addEventListener('click', async () => {
    if (!state.selected || !window.confirm(`¿Confirmar el ingreso de ${state.selected.patente_camion}? Después de esta acción solo un administrador podrá corregirlo mientras Validación MP no tome la recepción.`)) return;
    const soloEnvases = state.selected.tipo_recepcion === 'solo_envases';
    setBusy(true, soloEnvases ? 'Confirmando recepción documental…' : 'Confirmando ingreso y liberando camión…');
    try {
        const payload = await api(`/api/romana/recepciones/${state.selected.id}/confirmar-ingreso`, { method: 'POST', body: JSON.stringify({ operacion_id: operationUuid() }) });
        await loadReceptions({ silent: true });
        renderDetail(payload.data);
        toast(soloEnvases
            ? 'Ingreso documental confirmado. La recepción está lista para cerrar.'
            : 'Ingreso confirmado. El camión quedó pendiente de destare.');
    } catch (error) { toast(error.message, true); }
    finally { setBusy(false); }
});

elements.addContainerWeighing.addEventListener('click', () => {
    const weighing = state.selected?.pesaje_envases;
    if (!weighing || !state.selected?.puede_registrar_pesaje || weighing.cantidad_pendiente < 1) return;
    elements.containerWeighingForm.reset();
    elements.containerWeighingFormError.textContent = '';
    elements.containerWeighingTarePreview.textContent = '—';
    elements.containerWeighingNetPreview.textContent = '—';
    // Una tanda puede mezclar tipos: un campo por cada tipo con envases pendientes.
    const pending = weighingTypes(weighing).filter((item) => item.cantidad_pendiente > 0);
    const single = weighingTypes(weighing).length === 1;
    elements.containerWeighingQuantities.innerHTML = pending.map((item) => `<label class="field"><span>${single ? 'Cantidad de envases en esta tanda *' : `${escapeHtml(label(item.tipo_envase))} en esta tanda`}</span><input data-weighing-type="${escapeHtml(item.tipo_envase)}" data-tare="${escapeHtml(item.tara_unitaria ?? 0)}" type="number" min="0" max="${item.cantidad_pendiente}" value="${single ? Math.min(3, item.cantidad_pendiente) : 0}" inputmode="numeric"><small>Faltan ${item.cantidad_pendiente} de ${item.cantidad_declarada} · tara ${escapeHtml(formatWeight(item.tara_unitaria))} por envase</small></label>`).join('');
    elements.containerWeighingDescription.textContent = single
        ? `${weighing.cantidad_pesada}/${weighing.cantidad_declarada} ${label(weighing.tipo_envase)} pesados · faltan ${weighing.cantidad_pendiente} · tara ${formatWeight(weighing.tara_unitaria)} por unidad.`
        : `${weighing.cantidad_pesada}/${weighing.cantidad_declarada} envases pesados · faltan ${weighing.cantidad_pendiente}. Indica cuántos de cada tipo están sobre la báscula; la tanda puede mezclarlos.`;
    elements.containerWeighingDialog.showModal();
    elements.containerWeighingQuantities.querySelector('input')?.focus();
    updateContainerWeighingPreviews();
});

function containerWeighingLines() {
    return [...elements.containerWeighingQuantities.querySelectorAll('[data-weighing-type]')]
        .map((input) => ({ tipo_envase: input.dataset.weighingType, cantidad: Number(input.value || 0), tara: Number(input.dataset.tare || 0) }));
}
function updateContainerWeighingPreviews() {
    const lines = containerWeighingLines();
    const quantity = lines.reduce((sum, line) => sum + line.cantidad, 0);
    const gross = Number(elements.containerWeighingForm.elements.peso_bruto.value || 0);
    const tare = lines.reduce((sum, line) => sum + line.cantidad * line.tara, 0);
    elements.containerWeighingTarePreview.textContent = quantity > 0 ? formatWeight(tare) : '—';
    elements.containerWeighingNetPreview.textContent = quantity > 0 && gross > tare ? formatWeight(gross - tare) : '—';
}
elements.containerWeighingQuantities.addEventListener('input', updateContainerWeighingPreviews);
elements.containerWeighingForm.elements.peso_bruto.addEventListener('input', updateContainerWeighingPreviews);
elements.containerWeighingForm.addEventListener('submit', async (event) => {
    if (event.submitter?.value === 'cancel') return;
    event.preventDefault();
    if (!state.selected) return;
    elements.containerWeighingFormError.textContent = '';
    const data = Object.fromEntries(new FormData(elements.containerWeighingForm));
    const lines = containerWeighingLines().filter((line) => line.cantidad > 0);
    if (!lines.length) {
        elements.containerWeighingFormError.textContent = 'Indica cuántos envases trae esta tanda.';
        elements.containerWeighingQuantities.querySelector('input')?.focus();
        return;
    }
    data.envases = lines.map((line) => ({ tipo_envase: line.tipo_envase, cantidad: line.cantidad }));
    data.operacion_id = operationUuid();
    const total = lines.reduce((sum, line) => sum + line.cantidad, 0);
    setBusy(true, 'Registrando tanda y recalculando el peso neto…');
    try {
        const payload = await api(`/api/romana/recepciones/${state.selected.id}/pesajes-envases`, { method: 'POST', body: JSON.stringify(data) });
        elements.containerWeighingDialog.close();
        await loadReceptions({ silent: true });
        renderDetail(payload.data);
        toast(`Tanda registrada: ${total} envase(s).`);
    } catch (error) { elements.containerWeighingFormError.textContent = error.message; }
    finally { setBusy(false); }
});

elements.containerWeighingList.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-annul-weighing-id]');
    if (!button || !state.selected) return;
    const reason = window.prompt('Indica el motivo de la anulación de esta tanda:');
    if (reason === null) return;
    if (reason.trim().length < 5) {
        toast('El motivo debe contener al menos 5 caracteres.', true);
        return;
    }
    setBusy(true, 'Anulando lectura y recalculando acumulados…');
    try {
        const payload = await api(`/api/romana/recepciones/${state.selected.id}/pesajes-envases/${button.dataset.annulWeighingId}/anular`, {
            method: 'POST',
            body: JSON.stringify({ operacion_id: operationUuid(), motivo: reason.trim() }),
        });
        await loadReceptions({ silent: true });
        renderDetail(payload.data);
        toast('Lectura anulada con trazabilidad.');
    } catch (error) { toast(error.message, true); }
    finally { setBusy(false); }
});

elements.closeReception.addEventListener('click', async () => {
    if (!state.selected?.puede_cerrar) return;
    if (state.selected.pesaje_envases) {
        if (!window.confirm(`¿Cerrar el pesaje de ${weighingTotalLabel(state.selected.pesaje_envases)}? Después del cierre no se podrán agregar ni anular tandas.`)) return;
        setBusy(true, 'Cerrando pesaje acumulativo…');
        try {
            const payload = await api(`/api/romana/recepciones/${state.selected.id}/cerrar`, {
                method: 'POST',
                body: JSON.stringify({ operacion_id: operationUuid() }),
            });
            await loadReceptions({ silent: true });
            renderDetail(payload.data);
            toast(`${payload.data.numero_recepcion} cerrada con el total pesado.`);
        } catch (error) { toast(error.message, true); }
        finally { setBusy(false); }
        return;
    }
    if (state.selected.tipo_recepcion === 'solo_envases') {
        if (!window.confirm(`¿Cerrar la recepción documental ${state.selected.numero_recepcion}? No se registrarán kilos para este ingreso de envases.`)) return;
        setBusy(true, 'Cerrando recepción documental de envases…');
        try {
            const payload = await api(`/api/romana/recepciones/${state.selected.id}/cerrar`, {
                method: 'POST',
                body: JSON.stringify({ operacion_id: operationUuid(), salida_sin_envases: false }),
            });
            await loadReceptions({ silent: true });
            renderDetail(payload.data);
            toast(`${payload.data.numero_recepcion} cerrada sin registro de kilos.`);
        } catch (error) { toast(error.message, true); }
        finally { setBusy(false); }
        return;
    }
    openTareDialog(false);
});

function openTareDialog(correcting) {
    state.tareCorrection = correcting;
    elements.inspectionItems.replaceChildren();
    elements.inspectionObservation.value = correcting ? (state.selected.inspecciones_envases?.find((i) => i.tipo === 'despacho')?.observacion || '') : '';
    elements.tareForm.reset(); elements.tareFormError.textContent = ''; elements.containerTarePreview.textContent = '—'; elements.netWeightPreview.textContent = '—'; elements.netPerContainerPreview.textContent = '—';
    const containerSelect = elements.tareForm.elements.tipo_envase_calculo_neto;
    // El neto se reparte entre los envases con fruta: los vacíos no bajan el peso promedio.
    containerSelect.innerHTML = state.selected.envases.filter((item) => withFruit(item) > 0 && state.catalogs.tipos_envase.find((t) => t.codigo === item.tipo_envase)?.contiene_fruta).map((item) => `<option value="${escapeHtml(item.tipo_envase)}">${escapeHtml(label(item.tipo_envase))} · ${withFruit(item)} con fruta${item.cantidad_vacia ? ` (${item.cantidad_vacia} vacíos)` : ''}</option>`).join('');
    const countNotes = state.selected.observacion_conteo || [];
    byId('tareCountNote').hidden = countNotes.length === 0;
    byId('tareCountNoteList').innerHTML = countNotes.map((line) => `<li>${escapeHtml(line)}</li>`).join('');
    elements.outboundContainerTareList.innerHTML = state.selected.envases.map((item) => exitRow(item.tipo_envase, item.cantidad_validada, item.tara_unitaria_salida)).join('');
    elements.tareForm.elements.peso_tara.value = correcting ? state.selected.peso_tara : '';
    elements.tareForm.elements.peso_tara.readOnly = correcting;
    elements.tareForm.elements.tipo_envase_calculo_neto.disabled = correcting;
    const suggested = state.selected.configuracion_reparto?.sugerido;
    const selected = correcting ? (state.selected.reparto_neto_envases?.map((r) => r.tipo_envase) || [state.selected.tipo_envase_calculo_neto]) : [suggested || containerSelect.options[0]?.value];
    [...containerSelect.options].forEach((option) => { option.selected = selected.includes(option.value); });
    if (![...containerSelect.options].some((o) => o.selected) && containerSelect.options[0]) containerSelect.options[0].selected = true;
    if (correcting) {
        elements.tareForm.elements.modo_salida_envases.value = state.selected.modo_salida_envases;
        elements.tareForm.elements.numero_guia_salida.value = state.selected.numero_guia_salida || '';
        (state.selected.salida_envases || []).forEach((item) => {
            let row = exitTypes().find((r) => r.dataset.exitRow === item.tipo_envase);
            if (!row) {
                elements.outboundContainerTareList.insertAdjacentHTML('beforeend', exitRow(item.tipo_envase, 0, item.tara_unitaria, true));
                row = exitTypes().find((r) => r.dataset.exitRow === item.tipo_envase);
            }
            row.querySelector('[data-exit-quantity]').value = item.cantidad;
            row.querySelector('[data-container-tare]').value = item.tara_unitaria;
        });
    }
    elements.exitCorrectionReasonField.classList.toggle('is-hidden', !correcting);
    elements.tareForm.elements.motivo_correccion.required = correcting;
    const plantBins = state.selected.bins_prestados_planta;
    elements.tareDescription.textContent = `${state.selected.patente_camion} · bruto ${formatWeight(state.selected.peso_bruto)}. Completa la guía y el retiro físico de envases.`
        + (plantBins && !plantBins.devuelto ? ` Validación MP agregó ${plantBins.cantidad} ${plantBins.cantidad === 1 ? 'bin' : 'bins'} de planta: no sale${plantBins.cantidad === 1 ? '' : 'n'} con el camión y se devuelve${plantBins.cantidad === 1 ? '' : 'n'} internamente al cerrar.` : '');
    updateExitMode();
    elements.tareDialog.showModal();
    if (!correcting) elements.tareForm.elements.peso_tara.focus();
}
elements.correctExit.addEventListener('click', () => openTareDialog(true));

function exitRow(type, validated = 0, tare = '', extra = false) {
    return `<div data-exit-row="${escapeHtml(type)}" data-validated="${validated}">
        <label class="field"><span>${escapeHtml(label(type))} · entraron ${validated} validados</span><input data-exit-quantity="${escapeHtml(type)}" type="number" min="0" max="100000" step="1" value="${validated}" required></label>
        <label class="field"><span>Tara unitaria (kg) *</span><input data-container-tare="${escapeHtml(type)}" type="number" min="0.001" max="1000" step="0.001" inputmode="decimal" value="${escapeHtml(tare ?? '')}" required></label>
        ${extra ? '<button type="button" class="secondary-button" data-remove-exit-type>Quitar tipo</button>' : ''}
    </div>`;
}

function exitTypes() {
    return [...elements.outboundContainerTareList.querySelectorAll('[data-exit-row]')];
}

function updateExtraTypes() {
    const used = new Set(exitTypes().map((row) => row.dataset.exitRow));
    const available = state.catalogs.tipos_envase.filter((item) => !used.has(item.codigo));
    elements.outboundExtraType.innerHTML = available.map((item) => `<option value="${escapeHtml(item.codigo)}">${escapeHtml(item.nombre)}</option>`).join('');
    elements.outboundAddType.disabled = !available.length;
}

function updateExitMode() {
    const mode = elements.tareForm.elements.modo_salida_envases.value;
    const editable = mode === 'diferentes';
    elements.outboundGuideField.classList.toggle('is-hidden', mode === 'vacio');
    elements.tareForm.elements.numero_guia_salida.required = mode !== 'vacio';
    elements.outboundExtraField.classList.toggle('is-hidden', !editable);
    exitTypes().forEach((row) => {
        const field = row.querySelector('[data-exit-quantity]');
        if (mode === 'mismos') field.value = row.dataset.validated;
        if (mode === 'vacio') field.value = '0';
        field.readOnly = !editable;
        if (!editable && Number(row.dataset.validated) === 0) row.remove();
    });
    updateExtraTypes();
    updateNetPreviews();
}

function calculatedContainerTare() {
    return exitTypes().reduce((total, row) => {
        const tare = Number(row.querySelector('[data-container-tare]').value || 0);
        const outbound = elements.tareForm.elements.modo_salida_envases.value === 'vacio'
            ? 0 : Number(row.querySelector('[data-exit-quantity]').value || 0);
        return total + (Number(row.dataset.validated) - outbound) * tare;
    }, 0);
}

function updateDispatchInspection() {
    const empty = elements.tareForm.elements.modo_salida_envases.value === 'vacio';
    elements.inspectionPanel.classList.toggle('is-hidden', empty);
    const types = empty ? [] : exitTypes().filter((row) => Number(row.querySelector('[data-exit-quantity]').value) > 0).map((row) => row.dataset.exitRow);
    [...elements.inspectionItems.querySelectorAll('[data-inspection-type]')].forEach((row) => { if (!types.includes(row.dataset.inspectionType)) row.remove(); });
    types.forEach((type) => {
        if ([...elements.inspectionItems.querySelectorAll('[data-inspection-type]')].some((row) => row.dataset.inspectionType === type)) return;
        const previous = state.tareCorrection ? state.selected.inspecciones_envases?.find((i) => i.tipo === 'despacho')?.items.find((i) => i.tipo_envase === type) : null;
        elements.inspectionItems.insertAdjacentHTML('beforeend', `<div data-inspection-type="${escapeHtml(type)}"><strong>${escapeHtml(label(type))}</strong><label class="field"><span>Limpieza *</span><select data-clean required><option value="">Seleccionar</option><option value="si">Sí</option><option value="no">No</option></select></label><label class="field"><span>Condición *</span><select data-condition required><option value="">Seleccionar</option><option value="buena">Buena</option><option value="regular">Regular</option><option value="mala">Mala</option></select></label><label class="field"><span>Nota opcional</span><input data-note maxlength="500"></label></div>`);
        const row = [...elements.inspectionItems.querySelectorAll('[data-inspection-type]')].find((r) => r.dataset.inspectionType === type);
        row.querySelector('[data-clean]').value = previous ? (previous.limpieza ? 'si' : 'no') : '';
        row.querySelector('[data-condition]').value = previous?.condicion || '';
        row.querySelector('[data-note]').value = previous?.nota || '';
    });
}
function updateNetPreviews() {
    updateDispatchInspection();
    const tare = Number(elements.tareForm.elements.peso_tara.value);
    const difference = calculatedContainerTare();
    const gross = Number(state.selected?.peso_bruto || 0);
    const net = gross - tare - difference;
    elements.containerTarePreview.textContent = formatWeight(difference);
    elements.netWeightPreview.textContent = tare > 0 && net > 0 ? formatWeight(net) : '—';
    try {
        const selection = [...elements.tareForm.elements.tipo_envase_calculo_neto.selectedOptions].map((o) => o.value);
        const quantities = Object.fromEntries(state.selected.envases.map((i) => [i.tipo_envase, withFruit(i)]));
        const references = state.tareCorrection && state.selected.reparto_neto_envases
            ? Object.fromEntries(state.selected.reparto_neto_envases.map((r) => [r.tipo_envase, r.peso_referencia]))
            : state.selected.configuracion_reparto?.referencias || {};
        const rows = calculateNetAllocation(net, quantities, selection, references);
        elements.netPerContainerPreview.textContent = tare > 0 && net > 0 ? rows.map((r) => `${formatWeight(r.neto_unitario)} / ${label(r.codigo)}`).join(' · ') + (rows.length > 1 ? '. Reparto proporcional a referencias; el neto total proviene de la báscula.' : '') : '—';
    } catch (error) { elements.netPerContainerPreview.textContent = error.message; }

}
elements.tareForm.elements.peso_tara.addEventListener('input', updateNetPreviews);
elements.tareForm.elements.tipo_envase_calculo_neto.addEventListener('change', updateNetPreviews);
elements.tareForm.elements.modo_salida_envases.addEventListener('change', updateExitMode);
elements.outboundContainerTareList.addEventListener('input', updateNetPreviews);
elements.outboundContainerTareList.addEventListener('click', (event) => {
    if (!event.target.closest('[data-remove-exit-type]')) return;
    event.target.closest('[data-exit-row]').remove(); updateExtraTypes(); updateNetPreviews();
});
elements.outboundAddType.addEventListener('click', () => {
    const type = elements.outboundExtraType.value;
    if (!type) return;
    elements.outboundContainerTareList.insertAdjacentHTML('beforeend', exitRow(type, 0, '', true));
    updateExtraTypes(); updateNetPreviews();
});
elements.tareForm.addEventListener('submit', async (event) => {
    if (event.submitter?.value === 'cancel') return;
    event.preventDefault(); if (!state.selected) return; elements.tareFormError.textContent = '';
    const data = Object.fromEntries(new FormData(elements.tareForm));
    data.operacion_id = operationUuid();
    data.envases_reparto = [...elements.tareForm.elements.tipo_envase_calculo_neto.selectedOptions].map((o) => o.value);
    delete data.tipo_envase_calculo_neto;
    data.salida_envases = data.modo_salida_envases === 'diferentes' ? exitTypes().map((row) => ({
        tipo_envase: row.dataset.exitRow, cantidad: Number(row.querySelector('[data-exit-quantity]').value),
    })) : [];
    data.taras_envases = exitTypes().map((row) => ({
        tipo_envase: row.dataset.exitRow, tara_unitaria: Number(row.querySelector('[data-container-tare]').value),
    }));
    if (data.modo_salida_envases === 'vacio') data.numero_guia_salida = null;
    if (state.tareCorrection) data.version_conocida = state.selected.version;
    if (data.modo_salida_envases !== 'vacio') {
        try {
            const rows = [...elements.inspectionItems.querySelectorAll('[data-inspection-type]')];
            data.inspeccion_envases = dispatchInspectionPayload(rows.map((r) => r.dataset.inspectionType), Object.fromEntries(rows.map((r) => [r.dataset.inspectionType, { limpieza: r.querySelector('[data-clean]').value, condicion: r.querySelector('[data-condition]').value, nota: r.querySelector('[data-note]').value }])), elements.inspectionObservation.value);
        } catch (error) { elements.tareFormError.textContent = error.message; return; }
    }
    setBusy(true, state.tareCorrection ? 'Corrigiendo salida de envases…' : 'Calculando neto y cerrando recepción…');
    try {
        const path = state.tareCorrection ? 'corregir-salida-envases' : 'cerrar';
        const payload = await api(`/api/romana/recepciones/${state.selected.id}/${path}`, { method: 'POST', body: JSON.stringify(data) });
        elements.tareDialog.close(); await loadReceptions({ silent: true }); renderDetail(payload.data);
        toast(`${payload.data.numero_recepcion} ${state.tareCorrection ? 'salida corregida con reversas.' : 'cerrada correctamente.'}`);
    } catch (error) { elements.tareFormError.textContent = error.message; }
    finally { setBusy(false); }
});

async function downloadRc02(type) {
    if (!state.selected) return;
    setBusy(true, 'Generando RC-02…');
    try {
        const response = await fetch(`/api/romana/recepciones/${state.selected.id}/control-envases/${type}`, { headers: { Accept: 'application/pdf', Authorization: `Bearer ${state.token}` } });
        if (!response.ok) { const detail = await response.json().catch(() => ({})); throw new Error(detail.message || 'No fue posible generar RC-02.'); }
        const blob = await response.blob(); const url = URL.createObjectURL(blob); const anchor = document.createElement('a'); anchor.href = url; anchor.download = `rc02-${type}-${state.selected.numero_recepcion.toLowerCase()}.pdf`; anchor.click(); window.setTimeout(() => URL.revokeObjectURL(url), 1000);
    } catch (error) { toast(error.message, 'error'); }
    finally { setBusy(false); }
}
elements.rc02Reception.addEventListener('click', () => downloadRc02('recepcion'));
elements.rc02Dispatch.addEventListener('click', () => downloadRc02('despacho'));

elements.downloadReceipt.addEventListener('click', async () => {
    if (!state.selected?.aviso_recibo_disponible) return; setBusy(true, 'Generando registro de pesaje (RPR-01)…');
    try {
        const response = await fetch(`/api/romana/recepciones/${state.selected.id}/aviso-recibo`, { headers: { Accept: 'application/pdf', Authorization: `Bearer ${state.token}` } });
        if (!response.ok) { const data = await response.json().catch(() => ({})); throw new ApiError(errorMessage(data, 'No fue posible generar el PDF.'), response.status); }
        const blob = await response.blob(); const url = URL.createObjectURL(blob); const anchor = document.createElement('a'); anchor.href = url; anchor.download = `aviso-recibo-${state.selected.numero_recepcion.toLowerCase()}.pdf`; anchor.click(); window.setTimeout(() => URL.revokeObjectURL(url), 1000);
    } catch (error) { toast(error.message, true); }
    finally { setBusy(false); }
});

elements.blankWeighingForm.addEventListener('click', async () => {
    setBusy(true, 'Generando planilla de pesaje en blanco…');
    try {
        const response = await fetch('/api/romana/registro-pesaje/en-blanco', {
            headers: { Accept: 'application/pdf', Authorization: `Bearer ${state.token}` },
        });
        if (!response.ok) {
            const data = await response.json().catch(() => ({}));
            throw new ApiError(errorMessage(data, 'No fue posible generar la planilla en blanco.'), response.status);
        }
        const blob = await response.blob();
        const url = URL.createObjectURL(blob);
        const anchor = document.createElement('a');
        anchor.href = url;
        anchor.download = 'registro-pesaje-romana-en-blanco.pdf';
        anchor.click();
        window.setTimeout(() => URL.revokeObjectURL(url), 1000);
    } catch (error) {
        toast(error.message, true);
    } finally {
        setBusy(false);
    }
});

elements.tableBody.addEventListener('click', (event) => {
    const deletion = event.target.closest('[data-delete-reception-id]');
    if (deletion) {
        openReceptionRemoval(state.receptions.find((reception) => reception.id === deletion.dataset.deleteReceptionId), true);
        return;
    }
    const row = event.target.closest('[data-reception-id]');
    if (row) void selectReception(row.dataset.receptionId);
});
elements.tableBody.addEventListener('keydown', (event) => {
    if (event.target.closest('button')) return;
    const row = event.target.closest('[data-reception-id]');
    if (row && ['Enter', ' '].includes(event.key)) { event.preventDefault(); void selectReception(row.dataset.receptionId); }
});
elements.filters.addEventListener('submit', async (event) => { event.preventDefault(); state.page = 1; setBusy(true, 'Aplicando filtros…'); try { await loadReceptions({ silent: true }); } catch (error) { toast(error.message, true); } finally { setBusy(false); } });
elements.previousPage.addEventListener('click', async () => { if (state.page <= 1) return; state.page--; await loadReceptions({ silent: true }); });
elements.nextPage.addEventListener('click', async () => { if (state.meta && state.page >= state.meta.ultima_pagina) return; state.page++; await loadReceptions({ silent: true }); });
elements.reload.addEventListener('click', async () => { setBusy(true, 'Actualizando Romana…'); try { await Promise.all([loadCatalogs(), loadReceptions({ silent: true })]); toast('Romana actualizada.'); } catch (error) { toast(error.message, true); } finally { setBusy(false); } });
elements.newReception.addEventListener('click', openNewReception); elements.editReception.addEventListener('click', openEditReception); elements.closeDetail.addEventListener('click', closeDetail);
function toggleReceptionType() {
    const form = elements.receptionForm.elements;
    if (form.tipo_recepcion.value === 'solo_envases' && receptionGuides?.multiple()) {
        form.tipo_recepcion.value = 'fruta_con_envases';
        elements.receptionFormError.textContent = 'Para registrar solo envases, conserva una única guía o crea una recepción separada.';
    }
    const soloEnvases = form.tipo_recepcion.value === 'solo_envases';
    byId('receptionGuidesControl').classList.toggle('is-hidden', soloEnvases);
    receptionGuides?.refresh();
    const cumulativeWeighing = form.tipo_recepcion.value === 'fruta_pesaje_envases';
    elements.serviceField.classList.toggle('is-hidden', soloEnvases);
    document.getElementById('receptionSpeciesField').classList.toggle('is-hidden', soloEnvases);
    form.especie_validacion_id.required = !soloEnvases;
    elements.containerEntryDateField.classList.toggle('is-hidden', !soloEnvases);
    elements.containerConceptField.classList.toggle('is-hidden', !soloEnvases);
    elements.standardContainerLines.classList.toggle('is-hidden', cumulativeWeighing);
    elements.containerWeighingFields.classList.toggle('is-hidden', !cumulativeWeighing);
    elements.grossWeightField.classList.toggle('is-hidden', cumulativeWeighing || soloEnvases);
    form.tipo_servicio.required = !soloEnvases;
    form.fecha_ingreso.disabled = !soloEnvases;
    form.fecha_ingreso.required = soloEnvases;
    if (soloEnvases && !form.fecha_ingreso.value) form.fecha_ingreso.value = form.fecha_ingreso.max;
    form.concepto_envases.required = soloEnvases;
    form.peso_bruto.required = !cumulativeWeighing && !soloEnvases;
    syncContainerProperties();
    elements.receptionDialogDescription.textContent = soloEnvases
        ? 'Selecciona la fecha efectiva y registra guía, cliente, transporte y cantidades. Esta recepción no utiliza kilos.'
        : cumulativeWeighing
            ? 'Configura los envases y registra después sus pesajes por tandas.'
            : 'Captura los antecedentes documentales y el peso del camión cargado.';
    if (!state.administrativeCorrection) {
        elements.saveReception.textContent = form.recepcion_id.value
            ? 'Guardar cambios'
            : soloEnvases ? 'Guardar recepción de envases' : 'Guardar pesaje de ingreso';
    }
}
elements.receptionForm.elements.tipo_recepcion.addEventListener('change', toggleReceptionType);
byId('standardContainerLines').addEventListener('input', () => { syncAdministrativeNetContainerOptions(); syncContainerProperties(); });
elements.receptionForm.addEventListener('change', (event) => {
    if (event.target.name?.startsWith('propiedad_') && elements.receptionFormError.textContent.startsWith('Selecciona la propiedad')) elements.receptionFormError.textContent = '';
});
['patente_camion', 'patente_carro'].forEach((name) => elements.receptionForm.elements[name].addEventListener('input', (event) => { event.target.value = event.target.value.toUpperCase().replace(/[^A-Z0-9]/g, ''); }));
elements.logout.addEventListener('click', async () => { try { await api('/api/acceso-oficina', { method: 'DELETE' }); } finally { clearSession(); } });

function startRefresh() {
    gatehouseRomana.start();
    state.poller?.stop();
    state.poller = createOperationalPoller(
        () => loadReceptions({ silent: true }),
        {
            intervalMs: 30000,
            canRun: () => Boolean(state.token)
                && !elements.receptionDialog.open
                && !elements.tareDialog.open
                && !elements.containerWeighingDialog.open,
        },
    );
    state.poller.start();
}

async function boot() {
    if (!state.token || state.identity?.puede_consultar_romana !== true) { if (state.token) clearSession(); return; }
    if (!showApp()) return; setBusy(true, 'Cargando operación de Romana…');
    try { await Promise.all([loadCatalogs(), loadReceptions({ silent: true })]); startRefresh(); }
    catch (error) { if (error.status !== 401) toast(error.message, true); }
    finally { setBusy(false); }
}

void boot();
