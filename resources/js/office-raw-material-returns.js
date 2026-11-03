import { createOperationalPoller } from './shared/operational-poller';
import { packingReturnQuantity } from './shared/packing-return-containers';

const byId = (id) => document.getElementById(id);
const elements = {
    access: byId('officeAccess'), app: byId('officeApp'), login: byId('officeLoginForm'),
    loginError: byId('officeLoginError'), logout: byId('officeLogoutButton'),
    userName: byId('officeUserName'), userRole: byId('officeUserRole'), initials: byId('officeInitials'),
    reload: byId('reloadButton'), registeredBins: byId('registeredBins'), registeredKilos: byId('registeredKilos'),
    pendingBins: byId('pendingBins'), legacyReturns: byId('legacyReturns'), sections: [...document.querySelectorAll('[data-return-section]')],
    panels: [...document.querySelectorAll('[data-return-panel]')], binForm: byId('binReturnForm'), totalKilos: byId('binTotalKilos'),
    processSelect: byId('processSelect'), addOrigin: byId('addOriginButton'), originRows: byId('originRows'), originBalance: byId('originBalance'),
    binError: byId('binReturnError'), recentBins: byId('recentBins'), pendingList: byId('pendingBinList'), legacyList: byId('legacyList'),
    binListSearch: byId('binListSearch'), binListState: byId('binListState'), binListCount: byId('binListCount'),
    editDialog: byId('editBinDialog'), editForm: byId('editBinForm'), editTitle: byId('editBinTitle'),
    editDescription: byId('editBinDescription'), editError: byId('editBinError'), editGreenTotal: byId('editGreenTotalKilos'),
    editObservation: byId('editBinObservation'), editOrigins: byId('editBinOrigins'), editGreenBalance: byId('editGreenBalance'),
    editProcessSelect: byId('editProcessSelect'), editAddOrigin: byId('editAddOriginButton'),
    editDefinitiveSection: byId('editDefinitiveSection'), editDefinitiveTotal: byId('editDefinitiveTotalKilos'),
    editDefinitiveBalance: byId('editDefinitiveBalance'),
    regularizeDialog: byId('regularizeDialog'), regularizeForm: byId('regularizeForm'), regularizeTitle: byId('regularizeTitle'),
    regularizeDescription: byId('regularizeDescription'), regularizeObservation: byId('regularizeObservation'), regularizeError: byId('regularizeError'),
    regularizeTotal: byId('regularizeTotalKilos'), regularizeOrigins: byId('regularizeOrigins'), regularizeBalance: byId('regularizeBalance'),
    migrationDialog: byId('legacyMigrationDialog'), migrationForm: byId('legacyMigrationForm'), migrationTitle: byId('legacyMigrationTitle'),
    migrationDescription: byId('legacyMigrationDescription'), migrationTotal: byId('migrationTotalKilos'), migrationOrigins: byId('migrationOrigins'),
    migrationBalance: byId('migrationBalance'), migrationError: byId('legacyMigrationError'), loading: byId('officeLoading'),
    loadingText: byId('officeLoadingText'), toasts: byId('officeToasts'),
};

const keys = { token: 'estiba_wms_office_token', identity: 'estiba_wms_office_identity' };
const state = {
    token: localStorage.getItem(keys.token), identity: readJson(keys.identity), section: 'recepcion',
    summary: {}, processes: [], bins: [], legacy: [], catalogs: { tipos_resultado: [] }, origins: [], editOrigins: [], selectedBin: null, selectedLegacy: null,
    regularizationOperationId: null, editOperationId: null, poller: null,
};

class ApiError extends Error {
    constructor(message, status = 0) { super(message); this.status = status; }
}

function readJson(key) {
    try { return JSON.parse(localStorage.getItem(key) || 'null'); } catch { return null; }
}
function escapeHtml(value) {
    return String(value ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&#039;');
}
function errorMessage(data, fallback) {
    return Object.values(data?.errors || {}).flat()[0] || data?.message || fallback;
}
function uuid() {
    if (typeof crypto.randomUUID === 'function') return crypto.randomUUID();
    const values = crypto.getRandomValues(new Uint8Array(16));
    values[6] = (values[6] & 15) | 64; values[8] = (values[8] & 63) | 128;
    const hex = [...values].map((item) => item.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}
function formatNumber(value, digits = 0) {
    return new Intl.NumberFormat('es-CL', { minimumFractionDigits: digits, maximumFractionDigits: digits }).format(Number(value || 0));
}
function formatKilos(value) { return `${formatNumber(value, 3)} kg`; }
function formatDate(value) {
    if (!value) return '—';
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? '—' : new Intl.DateTimeFormat('es-CL', { dateStyle: 'short', timeStyle: 'short' }).format(date);
}
function label(value) {
    const labels = {
        pendiente_regularizacion: 'Pendiente de regularizar', regularizado: 'Regularizado', anulado: 'Anulado',
        administrador: 'Administrador', supervisor_frio: 'Supervisor de frío', camarero_frio: 'Camarero',
    };
    return labels[value] || String(value || '').replaceAll('_', ' ').replace(/^./, (letter) => letter.toUpperCase());
}
function setBusy(active, text = 'Procesando…') {
    elements.loadingText.textContent = text;
    elements.loading.classList.toggle('is-hidden', !active);
    elements.loading.setAttribute('aria-hidden', String(!active));
}
function toast(message, error = false) {
    const item = document.createElement('div');
    item.className = `toast${error ? ' toast--error' : ''}`;
    item.textContent = message; elements.toasts.append(item); window.setTimeout(() => item.remove(), 5000);
}
function persist(payload) {
    state.token = payload.token; state.identity = payload.usuario;
    localStorage.setItem(keys.token, payload.token); localStorage.setItem(keys.identity, JSON.stringify(payload.usuario));
}
function clearSession() {
    state.token = null; state.identity = null;
    localStorage.removeItem(keys.token); localStorage.removeItem(keys.identity);
    elements.app.classList.add('is-hidden'); elements.access.classList.remove('is-hidden');
}
async function api(path, options = {}) {
    const headers = new Headers(options.headers || {}); headers.set('Accept', 'application/json');
    if (state.token) headers.set('Authorization', `Bearer ${state.token}`);
    if (options.body) headers.set('Content-Type', 'application/json');
    let response;
    try { response = await fetch(path, { ...options, headers }); }
    catch { throw new ApiError('No fue posible conectar con Laravel.'); }
    const data = response.status === 204 ? null : await response.json().catch(() => ({}));
    if (!response.ok) {
        if (response.status === 401 && path !== '/api/acceso-oficina') clearSession();
        throw new ApiError(errorMessage(data, 'No fue posible completar la operación.'), response.status);
    }
    return data;
}
function can(capability) {
    return state.identity?.[capability] === true || state.identity?.capacidades?.[capability] === true;
}
function canResolveLegacyReturn() {
    return can('puede_entregar_fruta_proceso')
        || can('puede_corregir_entregas_fruta_proceso');
}
function showApp() {
    if (!can('puede_consultar_fruta_proceso')) return false;
    elements.access.classList.add('is-hidden'); elements.app.classList.remove('is-hidden');
    const name = state.identity?.nombre || 'Usuario';
    elements.userName.textContent = name; elements.userRole.textContent = label(state.identity?.rol);
    elements.initials.textContent = name.split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0]).join('').toUpperCase();
    return true;
}

function renderSummary() {
    elements.registeredBins.textContent = formatNumber(state.summary.bins_registrados);
    byId('registeredBoxes').textContent = formatNumber(state.summary.cajas_3_4_registradas || 0);
    elements.registeredKilos.textContent = formatNumber(state.summary.kilos_registrados, 3);
    elements.pendingBins.textContent = formatNumber(state.summary.pendientes_regularizacion);
    elements.legacyReturns.textContent = formatNumber(state.summary.retornos_anteriores_pendientes);
}
function processLabel(process) {
    return `${process.numero_lote || 'Lote'} · ${process.numero_orden} · ${process.linea_proceso} · turno ${process.turno}`;
}
function renderProcessSelect() {
    const selectedKeys = new Set(state.origins.map((origin) => origin.clave));
    const available = state.processes.filter((process) => !selectedKeys.has(process.clave));
    elements.processSelect.innerHTML = available.length
        ? `<option value="">Seleccionar proceso</option>${available.map((process) => `<option value="${escapeHtml(process.clave)}">${escapeHtml(processLabel(process))} · ${formatNumber(process.bins_enviados)} bins enviados</option>`).join('')}`
        : '<option value="">No quedan procesos disponibles</option>';
}
function renderOriginRows() {
    elements.originRows.innerHTML = state.origins.length
        ? state.origins.map((origin) => `<div class="origin-row" data-origin-key="${escapeHtml(origin.clave)}"><div class="origin-row__identity"><strong>${escapeHtml(processLabel(origin))}</strong><small>${origin.viajes} viaje(s) · enviados ${formatNumber(origin.bins_enviados)} bins${origin.kilos_enviados === null ? '' : ` · ${escapeHtml(formatKilos(origin.kilos_enviados))}`}</small></div><input data-origin-kilos type="number" min="0.001" max="999999999.999" step="0.001" inputmode="decimal" placeholder="Kilos aportados" value="${escapeHtml(origin.kilos_aportados ?? '')}"><button data-remove-origin type="button" aria-label="Quitar proceso">×</button></div>`).join('')
        : '<div class="return-empty">Agrega al menos un proceso de origen.</div>';
    renderProcessSelect(); renderBalance();
}
function originPayload() {
    return state.origins.map((origin) => ({
        lote_materia_prima_id: origin.lote_materia_prima_id,
        numero_orden: origin.numero_orden,
        linea_proceso: origin.linea_proceso,
        turno: origin.turno,
        kilos_aportados: Number(origin.kilos_aportados || 0),
    }));
}
function balance(total, origins) {
    const totalValue = Number(total || 0); const distributed = origins.reduce((sum, origin) => sum + Number(origin.kilos_aportados || 0), 0);
    return { total: totalValue, distributed, difference: totalValue - distributed, ok: totalValue > 0 && Math.abs(totalValue - distributed) < 0.0005 };
}
function paintBalance(element, data) {
    element.classList.toggle('is-balanced', data.ok);
    element.innerHTML = `<span>Distribuido</span><strong>${formatKilos(data.distributed)} / ${formatKilos(data.total)}</strong><small>${data.ok ? 'Cuadratura correcta ✓' : `${data.difference >= 0 ? 'Faltan' : 'Exceden'} ${formatKilos(Math.abs(data.difference))}`}</small>`;
}
function renderBalance() { paintBalance(elements.originBalance, balance(elements.totalKilos.value, state.origins)); }
function normalized(value) {
    return String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
}
function filteredBins() {
    const query = normalized(elements.binListSearch.value);
    const selectedState = elements.binListState.value;
    return state.bins.filter((bin) => {
        if (selectedState && bin.estado !== selectedState) return false;
        if (!query) return true;
        const searchable = [
            bin.folio_provisional,
            bin.folio_definitivo,
            bin.observacion,
            bin.envase,
            bin.tipo_envase,
            bin.nombre_resultado,
            ...bin.origenes.flatMap((origin) => [
                origin.numero_lote,
                origin.numero_orden,
                origin.linea_proceso,
                origin.turno,
            ]),
        ].map(normalized).join(' ');
        return searchable.includes(query);
    });
}
function addOrigin() {
    const process = state.processes.find((item) => item.clave === elements.processSelect.value); if (!process) return;
    state.origins.push({ ...process, kilos_aportados: '' }); renderOriginRows();
}

function binOriginsMarkup(bin) {
    return `<div class="bin-origins">${bin.origenes.map((origin) => `<div class="bin-origin"><div><strong>${escapeHtml(origin.numero_lote || 'Lote')} · ${escapeHtml(origin.numero_orden)}</strong><small>${escapeHtml(origin.linea_proceso)} · turno ${escapeHtml(origin.turno)} · verde ${escapeHtml(formatKilos(origin.kilos_aportados_verdes ?? origin.kilos_aportados))}</small></div><strong>${origin.kilos_aportados_definitivos === null ? 'Definitivo pendiente' : `Definitivo ${escapeHtml(formatKilos(origin.kilos_aportados_definitivos))}`}</strong></div>`).join('')}</div>`;
}
function binCard(bin, pending = false) {
    const actions = [];
    if (pending) actions.push(`<button data-regularize-bin="${escapeHtml(bin.id)}" type="button">Regularizar folio y kilos</button>`);
    if (canResolveLegacyReturn() && bin.estado !== 'anulado') actions.push(`<button data-edit-bin="${escapeHtml(bin.id)}" type="button">Modificar</button>`);
    if (canResolveLegacyReturn() && bin.estado !== 'anulado') actions.push(`<button data-annul-bin="${escapeHtml(bin.id)}" type="button">Anular retorno</button>`);
    const correction = bin.modificado_at
        ? `<div class="return-correction"><strong>Modificado ${escapeHtml(formatDate(bin.modificado_at))}</strong><span>${escapeHtml(bin.modificado_por || 'Usuario')} · ${escapeHtml(bin.motivo_ultima_modificacion || 'Corrección de digitación')}</span></div>`
        : '';
    const audit = bin.anulado_at
        ? `<div class="return-annulment"><strong>Anulado ${escapeHtml(formatDate(bin.anulado_at))}</strong><span>${escapeHtml(bin.anulado_por || 'Usuario')} · ${escapeHtml(bin.motivo_anulacion || 'Sin motivo')}</span></div>`
        : '';
    return `<article class="bin-card"><div class="bin-card__heading"><div><h3>${escapeHtml(bin.folio_provisional)}${bin.folio_definitivo ? ` → ${escapeHtml(bin.folio_definitivo)}` : ''}</h3><p>${escapeHtml(formatDate(bin.registrado_at))} · ${escapeHtml(bin.registrado_por || 'Usuario')}${bin.retorno_legacy ? ` · migrado desde ${escapeHtml(bin.retorno_legacy)}` : ''}</p></div><span class="return-status${bin.estado === 'pendiente_regularizacion' ? ' is-warning' : ''}">${escapeHtml(label(bin.estado))}</span></div><div class="bin-facts"><div><span>ENVASES</span><strong>${escapeHtml(packingReturnQuantity(bin))}</strong></div><div><span>PESO VERDE</span><strong>${escapeHtml(formatKilos(bin.kilos_totales_verdes ?? bin.kilos_totales))}</strong></div><div><span>PESO DEFINITIVO</span><strong>${bin.kilos_totales_definitivos === null ? 'Pendiente' : escapeHtml(formatKilos(bin.kilos_totales_definitivos))}</strong></div><div><span>PROCESOS</span><strong>${formatNumber(bin.origenes.length)}</strong></div><div><span>CLASIFICACIÓN</span><strong>${escapeHtml(bin.nombre_resultado || bin.tipo_resultado?.nombre || 'Pendiente')}</strong></div></div>${binOriginsMarkup(bin)}${correction}${audit}${actions.length ? `<div class="bin-card__actions" data-office-action-menu>${actions.join('')}</div>` : ''}</article>`;
}
function renderBins() {
    const visibleBins = filteredBins();
    elements.binListCount.textContent = `${formatNumber(visibleBins.length)} de ${formatNumber(state.bins.length)} retornos de la temporada`;
    elements.recentBins.innerHTML = visibleBins.length
        ? visibleBins.map((bin) => binCard(bin)).join('')
        : '<div class="return-empty">No hay retornos que coincidan con los filtros.</div>';
    const pending = state.bins.filter((bin) => bin.estado === 'pendiente_regularizacion');
    elements.pendingList.innerHTML = pending.length ? pending.map((bin) => binCard(bin, true)).join('') : '<div class="return-empty">No hay folios provisionales pendientes. La bandeja está al día.</div>';
}
function renderLegacy() {
    elements.legacyList.innerHTML = state.legacy.length ? state.legacy.map((item) => {
        const results = item.resultados.map((result) => `${result.numero_sublote || 'Resultado'} · ${packingReturnQuantity(result)}${result.kilos_netos === null ? '' : ` · ${formatKilos(result.kilos_netos)}`}`).join(' | ');
        const processes = item.procesos.map((process) => `<div class="bin-origin"><div><strong>${escapeHtml(process.numero_lote || 'Lote')} · ${escapeHtml(process.numero_orden)}</strong><small>${escapeHtml(process.linea_proceso)} · turno ${escapeHtml(process.turno)}</small></div></div>`).join('');
        const actions = canResolveLegacyReturn()
            ? `<div class="legacy-card__actions" data-office-action-menu>${item.migrable ? `<button data-migrate-legacy="${escapeHtml(item.id)}" type="button">Migrar</button>` : ''}<button data-discard-legacy="${escapeHtml(item.id)}" type="button">Descartar y reingresar</button></div>`
            : '';
        return `<article class="legacy-card"><div class="legacy-card__heading"><div><h3>${escapeHtml(item.numero)}</h3><p>${escapeHtml(formatDate(item.registrado_at))} · ${escapeHtml(item.registrado_por || 'Usuario')}</p></div><span class="return-status${item.migrable ? '' : ' is-warning'}">${item.migrable ? 'Migrable' : 'Reingreso requerido'}</span></div><div class="bin-facts"><div><span>RESULTADO ANTERIOR</span><strong>${escapeHtml(results)}</strong></div><div><span>PROCESOS</span><strong>${formatNumber(item.procesos.length)}</strong></div><div class="legacy-action-fact"><span>ACCIÓN</span>${actions || '<strong>Sin acciones disponibles</strong>'}</div></div><div class="bin-origins">${processes}</div>${item.motivo_no_migrable ? `<small>${escapeHtml(item.motivo_no_migrable)}</small>` : ''}</article>`;
    }).join('') : '<div class="return-empty">No quedan retornos del modelo anterior pendientes de resolver.</div>';
}
function renderSection() {
    elements.sections.forEach((button) => button.classList.toggle('is-active', button.dataset.returnSection === state.section));
    elements.panels.forEach((panel) => panel.classList.toggle('is-hidden', panel.dataset.returnPanel !== state.section));
}

async function load({ silent = false } = {}) {
    if (!silent) setBusy(true, 'Actualizando retornos…');
    try {
        const [summary, processes, bins, legacy] = await Promise.all([
            api('/api/materia-prima/fruta-proceso/retornos-bin/resumen'),
            api('/api/materia-prima/fruta-proceso/retornos-bin/procesos'),
            api('/api/materia-prima/fruta-proceso/retornos-bin/bins'),
            api('/api/materia-prima/fruta-proceso/retornos-bin/legacy'),
        ]);
        state.summary = summary || {}; state.processes = processes.data || [];
        state.bins = bins.data || []; state.legacy = legacy.data || [];
        renderSummary(); renderProcessSelect(); renderOriginRows(); renderBins(); renderLegacy(); renderSection();
    } catch (error) { toast(error.message, true); }
    finally { if (!silent) setBusy(false); }
}
async function loadCatalogs() {
    state.catalogs = await api('/api/materia-prima/fruta-proceso/retornos-bin/catalogos') || { tipos_resultado: [] };
}

async function submitBin() {
    const container = readContainerFields(elements.binForm, elements.binError); if (!container) return;
    const data = new FormData(elements.binForm); const balanceData = balance(data.get('kilos_totales'), state.origins);
    if (!state.origins.length) { elements.binError.textContent = 'Agrega al menos un proceso de origen.'; return; }
    if (state.origins.some((origin) => Number(origin.kilos_aportados || 0) <= 0)) { elements.binError.textContent = 'Informa los kilos aportados por cada proceso.'; return; }
    if (!balanceData.ok) { elements.binError.textContent = 'Los kilos por proceso deben cuadrar exactamente con el peso total del retorno.'; return; }
    const payload = { operacion_id: uuid(), ...container, kilos_totales: Number(data.get('kilos_totales')), observacion: String(data.get('observacion') || '').trim() || null, origenes: originPayload() };
    setBusy(true, 'Registrando retorno…'); elements.binError.textContent = '';
    try {
        const response = await api('/api/materia-prima/fruta-proceso/retornos-bin/bins', { method: 'POST', body: JSON.stringify(payload) });
        elements.binForm.reset(); state.origins = []; syncContainerFields(elements.binForm, 'returnContainerQuantityLabel'); renderOriginRows(); toast(`Retorno registrado como ${response.data.folio_provisional}.`); await load({ silent: true });
    } catch (error) { elements.binError.textContent = error.message; }
    finally { setBusy(false); }
}

function openEdit(id) {
    const bin = state.bins.find((item) => item.id === id);
    if (!bin || bin.estado === 'anulado') return;
    const regularized = bin.estado === 'regularizado' && Boolean(bin.regularizado_at);
    state.selectedBin = bin;
    state.editOperationId = uuid();
    elements.editForm.reset();
    elements.editForm.elements.bin_id.value = bin.id;
    elements.editTitle.textContent = `Modificar ${bin.folio_provisional}`;
    elements.editDescription.textContent = regularized
        ? 'Corrige los procesos de origen, los datos verdes y los definitivos. El folio provisional se conservará.'
        : 'Corrige los procesos de origen, los kilos verdes o la observación antes de regularizar.';
    elements.editGreenTotal.value = bin.kilos_totales_verdes ?? bin.kilos_totales;
    elements.editObservation.value = bin.observacion || '';
    elements.editForm.elements.tipo_envase.value = bin.tipo_envase || 'bins';
    elements.editForm.elements.cantidad_envases.value = bin.cantidad_envases ?? 1;
    syncContainerFields(elements.editForm, 'editReturnContainerQuantityLabel');
    elements.editDefinitiveSection.classList.toggle('is-hidden', !regularized);
    state.editOrigins = bin.origenes.map((origin) => ({
        ...origin,
        clave: origin.clave,
        kilos_aportados: origin.kilos_aportados_verdes ?? origin.kilos_aportados,
        kilos_aportados_definitivos: origin.kilos_aportados_definitivos ?? '',
    }));

    const types = [...state.catalogs.tipos_resultado];
    if (regularized && bin.tipo_resultado && !types.some((type) => type.id === bin.tipo_resultado.id)) {
        types.push(bin.tipo_resultado);
    }
    elements.editForm.elements.tipo_resultado_packing_id.innerHTML = `<option value="">Seleccionar</option>${types.map((type) => `<option value="${escapeHtml(type.id)}">${escapeHtml(type.codigo)} · ${escapeHtml(type.nombre)}</option>`).join('')}`;
    elements.editForm.elements.folio_definitivo.value = regularized ? (bin.folio_definitivo || '') : '';
    elements.editForm.elements.tipo_resultado_packing_id.value = regularized ? (bin.tipo_resultado?.id || '') : '';
    elements.editForm.elements.nombre_resultado.value = regularized ? (bin.nombre_resultado || '') : '';
    elements.editDefinitiveTotal.value = regularized ? (bin.kilos_totales_definitivos ?? '') : '';

    elements.editError.textContent = '';
    renderEditOriginRows();
    elements.editDialog.showModal();
}

function renderEditProcessSelect() {
    const selectedKeys = new Set(state.editOrigins.map((origin) => origin.clave));
    const available = state.processes.filter((process) => !selectedKeys.has(process.clave));
    elements.editProcessSelect.innerHTML = available.length
        ? `<option value="">Seleccionar proceso para agregar</option>${available.map((process) => `<option value="${escapeHtml(process.clave)}">${escapeHtml(processLabel(process))}</option>`).join('')}`
        : '<option value="">No quedan procesos disponibles</option>';
}

function syncEditOriginValues() {
    state.editOrigins.forEach((origin) => {
        const row = elements.editOrigins.querySelector(
            `[data-edit-origin-key="${CSS.escape(origin.clave)}"]`,
        );
        if (!row) return;
        origin.kilos_aportados = row.querySelector('[data-edit-green-kilos]')?.value || '';
        origin.kilos_aportados_definitivos =
            row.querySelector('[data-edit-definitive-kilos]')?.value || '';
    });
}

function renderEditOriginRows() {
    const regularized = state.selectedBin?.estado === 'regularizado'
        && Boolean(state.selectedBin?.regularizado_at);
    elements.editOrigins.innerHTML = state.editOrigins.length
        ? state.editOrigins.map((origin) => `<div class="edit-origin${regularized ? '' : ' is-pending'}" data-edit-origin-key="${escapeHtml(origin.clave)}"><div class="edit-origin__identity"><strong>${escapeHtml(processLabel(origin))}</strong><small>${origin.id ? 'Origen registrado' : 'Nuevo origen agregado en esta corrección'}</small></div><label><span>Kg verdes</span><input data-edit-green-kilos type="number" min="0.001" max="999999999.999" step="0.001" inputmode="decimal" value="${escapeHtml(origin.kilos_aportados ?? '')}"></label>${regularized ? `<label><span>Kg definitivos</span><input data-edit-definitive-kilos type="number" min="0.001" max="999999999.999" step="0.001" inputmode="decimal" value="${escapeHtml(origin.kilos_aportados_definitivos ?? '')}"></label>` : ''}<button data-remove-edit-origin type="button" aria-label="Quitar proceso">×</button></div>`).join('')
        : '<div class="return-empty">Agrega al menos un proceso de origen.</div>';
    renderEditProcessSelect();
    renderEditBalances();
}

function addEditOrigin() {
    syncEditOriginValues();
    const process = state.processes.find(
        (item) => item.clave === elements.editProcessSelect.value,
    );
    if (!process) return;
    state.editOrigins.push({
        ...process,
        id: null,
        kilos_aportados: '',
        kilos_aportados_definitivos: '',
    });
    renderEditOriginRows();
}

function editOriginsPayload() {
    if (!state.selectedBin) return [];
    const regularized = state.selectedBin.estado === 'regularizado' && Boolean(state.selectedBin.regularizado_at);
    return state.editOrigins.map((origin) => {
        const row = elements.editOrigins.querySelector(`[data-edit-origin-key="${CSS.escape(origin.clave)}"]`);
        const payload = {
            origen_id: origin.id || null,
            lote_materia_prima_id: origin.lote_materia_prima_id,
            numero_orden: origin.numero_orden,
            linea_proceso: origin.linea_proceso,
            turno: origin.turno,
            kilos_aportados: Number(row?.querySelector('[data-edit-green-kilos]')?.value || 0),
        };
        if (regularized) {
            payload.kilos_aportados_definitivos = Number(
                row?.querySelector('[data-edit-definitive-kilos]')?.value || 0,
            );
        }
        return payload;
    });
}
function renderEditBalances() {
    const origins = editOriginsPayload();
    paintBalance(
        elements.editGreenBalance,
        balance(elements.editGreenTotal.value, origins),
    );
    if (state.selectedBin?.estado === 'regularizado') {
        paintBalance(
            elements.editDefinitiveBalance,
            balance(
                elements.editDefinitiveTotal.value,
                origins.map((origin) => ({
                    kilos_aportados: origin.kilos_aportados_definitivos,
                })),
            ),
        );
    }
}
async function submitEdit() {
    if (!state.selectedBin || !state.editOperationId) return;
    const bin = state.selectedBin;
    const regularized = bin.estado === 'regularizado' && Boolean(bin.regularizado_at);
    const container = readContainerFields(elements.editForm, elements.editError); if (!container) return;
    const data = new FormData(elements.editForm);
    const origins = editOriginsPayload();
    const greenBalance = balance(data.get('kilos_totales'), origins);
    const reason = String(data.get('motivo') || '').trim();

    if (reason.length < 5) {
        elements.editError.textContent = 'Ingresa un motivo de al menos 5 caracteres.';
        return;
    }
    if (origins.some((origin) => origin.kilos_aportados <= 0) || !greenBalance.ok) {
        elements.editError.textContent = 'Los kilos verdes por proceso deben cuadrar exactamente con el total.';
        return;
    }

    const payload = {
        operacion_id: state.editOperationId,
        ...container,
        motivo: reason,
        kilos_totales: Number(data.get('kilos_totales')),
        observacion: String(data.get('observacion') || '').trim() || null,
        origenes: origins,
    };
    if (regularized) {
        const folio = String(data.get('folio_definitivo') || '').trim();
        const type = String(data.get('tipo_resultado_packing_id') || '');
        const definitiveBalance = balance(
            data.get('kilos_totales_definitivos'),
            origins.map((origin) => ({
                kilos_aportados: origin.kilos_aportados_definitivos,
            })),
        );
        if (!folio || !type) {
            elements.editError.textContent = 'Completa el folio definitivo y la clasificación.';
            return;
        }
        if (origins.some((origin) => origin.kilos_aportados_definitivos <= 0) || !definitiveBalance.ok) {
            elements.editError.textContent = 'Los kilos definitivos por proceso deben cuadrar exactamente con el total.';
            return;
        }
        Object.assign(payload, {
            folio_definitivo: folio,
            tipo_resultado_packing_id: type,
            nombre_resultado: String(data.get('nombre_resultado') || '').trim() || null,
            kilos_totales_definitivos: Number(data.get('kilos_totales_definitivos')),
        });
    }

    setBusy(true, 'Guardando corrección auditada…');
    elements.editError.textContent = '';
    try {
        await api(`/api/materia-prima/fruta-proceso/retornos-bin/bins/${bin.id}`, {
            method: 'PUT',
            body: JSON.stringify(payload),
        });
        elements.editDialog.close();
        state.editOperationId = null;
        state.editOrigins = [];
        toast(`${bin.folio_provisional} fue modificado y el cambio quedó auditado.`);
        await load({ silent: true });
    } catch (error) {
        elements.editError.textContent = error.message;
    } finally {
        setBusy(false);
    }
}

function openRegularize(id) {
    const bin = state.bins.find((item) => item.id === id); if (!bin) return;
    state.selectedBin = bin; elements.regularizeForm.reset(); elements.regularizeForm.elements.bin_id.value = bin.id;
    state.regularizationOperationId = uuid();
    elements.regularizeTitle.textContent = `Regularizar ${bin.folio_provisional}`;
    elements.regularizeDescription.textContent = `${packingReturnQuantity(bin)} · Peso verde registrado: ${formatKilos(bin.kilos_totales_verdes ?? bin.kilos_totales)}. Cuadraturas debe confirmar el total y cada proceso; el folio provisional se conservará.`;
    const sourceObservation = String(bin.observacion || '').trim();
    elements.regularizeObservation.textContent = sourceObservation || 'Sin observación registrada.';
    elements.regularizeObservation.closest('.return-source-observation')?.classList.toggle('is-empty', !sourceObservation);
    elements.regularizeForm.elements.tipo_resultado_packing_id.innerHTML = `<option value="">Seleccionar</option>${state.catalogs.tipos_resultado.map((type) => `<option value="${escapeHtml(type.id)}">${escapeHtml(type.codigo)} · ${escapeHtml(type.nombre)}</option>`).join('')}`;
    elements.regularizeTotal.value = bin.kilos_totales_definitivos ?? bin.kilos_totales;
    elements.regularizeOrigins.innerHTML = bin.origenes.map((origin) => `<div class="migration-origin" data-regularize-origin-id="${escapeHtml(origin.id)}"><div class="migration-origin__identity"><strong>${escapeHtml(processLabel(origin))}</strong><small>Kilos verdes registrados: ${escapeHtml(formatKilos(origin.kilos_aportados_verdes ?? origin.kilos_aportados))}</small></div><input data-regularize-kilos type="number" min="0.001" max="999999999.999" step="0.001" inputmode="decimal" value="${escapeHtml(origin.kilos_aportados_definitivos ?? origin.kilos_aportados)}" aria-label="Kilos definitivos de ${escapeHtml(processLabel(origin))}"></div>`).join('');
    renderRegularizeBalance();
    elements.regularizeError.textContent = ''; elements.regularizeDialog.showModal();
}
function regularizeOriginsPayload() {
    if (!state.selectedBin) return [];
    return state.selectedBin.origenes.map((origin) => {
        const row = elements.regularizeOrigins.querySelector(`[data-regularize-origin-id="${CSS.escape(origin.id)}"]`);
        return {
            origen_id: origin.id,
            kilos_aportados_definitivos: Number(row?.querySelector('[data-regularize-kilos]')?.value || 0),
        };
    });
}
function renderRegularizeBalance() {
    const origins = regularizeOriginsPayload().map((origin) => ({
        kilos_aportados: origin.kilos_aportados_definitivos,
    }));
    paintBalance(elements.regularizeBalance, balance(elements.regularizeTotal.value, origins));
}
async function submitRegularize() {
    if (!state.selectedBin) return;
    const data = new FormData(elements.regularizeForm); const folio = String(data.get('folio_definitivo') || '').trim(); const type = String(data.get('tipo_resultado_packing_id') || '');
    if (!folio || !type) { elements.regularizeError.textContent = 'Completa el folio definitivo y la clasificación.'; return; }
    const origins = regularizeOriginsPayload();
    const balanceData = balance(data.get('kilos_totales_definitivos'), origins.map((origin) => ({ kilos_aportados: origin.kilos_aportados_definitivos })));
    if (origins.some((origin) => origin.kilos_aportados_definitivos <= 0) || !balanceData.ok) { elements.regularizeError.textContent = 'Confirma los kilos definitivos de todos los procesos y cuádralos exactamente con el total.'; return; }
    setBusy(true, 'Confirmando cuadratura definitiva…');
    try {
        await api(`/api/materia-prima/fruta-proceso/retornos-bin/bins/${state.selectedBin.id}/regularizar`, { method: 'POST', body: JSON.stringify({ operacion_id: state.regularizationOperationId, folio_definitivo: folio, tipo_resultado_packing_id: type, nombre_resultado: String(data.get('nombre_resultado') || '').trim() || null, kilos_totales_definitivos: Number(data.get('kilos_totales_definitivos')), origenes: origins }) });
        elements.regularizeDialog.close(); toast(`${state.selectedBin.folio_provisional} quedó regularizado con sus kilos definitivos.`); state.regularizationOperationId = null; await load({ silent: true });
    } catch (error) { elements.regularizeError.textContent = error.message; }
    finally { setBusy(false); }
}

function openMigration(id) {
    const legacy = state.legacy.find((item) => item.id === id); if (!legacy || !legacy.migrable) return;
    state.selectedLegacy = legacy; elements.migrationForm.reset(); elements.migrationForm.elements.retorno_id.value = legacy.id;
    elements.migrationTitle.textContent = `Migrar ${legacy.numero}`;
    elements.migrationDescription.textContent = 'Asigna los kilos reales de este bin a uno o más procesos originales.';
    elements.migrationTotal.value = legacy.kilos_sugeridos ?? '';
    elements.migrationOrigins.innerHTML = legacy.procesos.map((process) => `<div class="migration-origin" data-migration-key="${escapeHtml(process.clave)}"><div class="migration-origin__identity"><strong>${escapeHtml(processLabel(process))}</strong><small>Proceso disponible en el retorno anterior</small></div><input data-migration-kilos type="number" min="0" step="0.001" inputmode="decimal" placeholder="Kilos de este proceso"></div>`).join('');
    if (legacy.procesos.length === 1 && legacy.kilos_sugeridos) elements.migrationOrigins.querySelector('[data-migration-kilos]').value = legacy.kilos_sugeridos;
    elements.migrationError.textContent = ''; renderMigrationBalance(); elements.migrationDialog.showModal();
}
function migrationOriginsPayload() {
    if (!state.selectedLegacy) return [];
    return state.selectedLegacy.procesos.map((process) => {
        const row = elements.migrationOrigins.querySelector(`[data-migration-key="${CSS.escape(process.clave)}"]`);
        return { ...process, kilos_aportados: Number(row?.querySelector('[data-migration-kilos]')?.value || 0) };
    }).filter((origin) => origin.kilos_aportados > 0).map((origin) => ({ lote_materia_prima_id: origin.lote_materia_prima_id, numero_orden: origin.numero_orden, linea_proceso: origin.linea_proceso, turno: origin.turno, kilos_aportados: origin.kilos_aportados }));
}
function renderMigrationBalance() { paintBalance(elements.migrationBalance, balance(elements.migrationTotal.value, migrationOriginsPayload())); }
async function submitMigration() {
    if (!state.selectedLegacy) return;
    const data = new FormData(elements.migrationForm); const origins = migrationOriginsPayload(); const balanceData = balance(data.get('kilos_totales'), origins);
    if (!origins.length || !balanceData.ok) { elements.migrationError.textContent = 'Distribuye exactamente el peso total entre los procesos que aportaron kilos.'; return; }
    setBusy(true, 'Migrando retorno anterior…');
    try {
        const response = await api(`/api/materia-prima/fruta-proceso/retornos-bin/legacy/${state.selectedLegacy.id}/migrar`, { method: 'POST', body: JSON.stringify({ operacion_id: uuid(), kilos_totales: Number(data.get('kilos_totales')), motivo: String(data.get('motivo') || '').trim() || null, origenes: origins }) });
        elements.migrationDialog.close(); toast(`${state.selectedLegacy.numero} migrado como ${response.data.folio_provisional}.`); await load({ silent: true });
    } catch (error) { elements.migrationError.textContent = error.message; }
    finally { setBusy(false); }
}
async function discardLegacy(id) {
    const legacy = state.legacy.find((item) => item.id === id); if (!legacy) return;
    const reason = window.prompt(`Motivo para descartar ${legacy.numero} y volver a ingresar sus bins (mínimo 5 caracteres):`);
    if (reason === null) return; if (reason.trim().length < 5) { toast('Ingresa un motivo de al menos 5 caracteres.', true); return; }
    if (!window.confirm(`Se anulará operacionalmente ${legacy.numero}. Luego debes reingresar cada bin por separado. ¿Continuar?`)) return;
    setBusy(true, 'Descartando retorno anterior…');
    try {
        await api(`/api/materia-prima/fruta-proceso/retornos-bin/legacy/${legacy.id}/descartar`, { method: 'POST', body: JSON.stringify({ operacion_id: uuid(), motivo: reason.trim() }) });
        toast(`${legacy.numero} quedó descartado para reingreso.`); await load({ silent: true });
    } catch (error) { toast(error.message, true); }
    finally { setBusy(false); }
}

async function annulBin(id) {
    const bin = state.bins.find((item) => item.id === id); if (!bin || bin.estado === 'anulado') return;
    const reason = window.prompt(`Motivo para anular ${bin.folio_provisional} (mínimo 5 caracteres):`);
    if (reason === null) return;
    if (reason.trim().length < 5) { toast('Ingresa un motivo de al menos 5 caracteres.', true); return; }
    if (!window.confirm(`Se anulará operacionalmente ${bin.folio_provisional}. El historial se conservará y podrás reingresar el retorno correctamente. ¿Continuar?`)) return;
    setBusy(true, 'Anulando retorno…');
    try {
        await api(`/api/materia-prima/fruta-proceso/retornos-bin/bins/${bin.id}/anular`, { method: 'POST', body: JSON.stringify({ operacion_id: uuid(), motivo: reason.trim() }) });
        toast(`${bin.folio_provisional} quedó anulado.`); await load({ silent: true });
    } catch (error) { toast(error.message, true); }
    finally { setBusy(false); }
}

elements.login.addEventListener('submit', async (event) => {
    event.preventDefault(); elements.loginError.textContent = ''; setBusy(true, 'Validando acceso…');
    try {
        const data = new FormData(elements.login); const payload = await api('/api/acceso-oficina', { method: 'POST', body: JSON.stringify({ email: data.get('email'), password: data.get('password') }) });
        persist(payload); if (!showApp()) { clearSession(); throw new ApiError('Tu perfil no tiene acceso a Fruta a proceso.', 403); } await Promise.all([loadCatalogs(), load({ silent: true })]);
    } catch (error) { elements.loginError.textContent = error.message; }
    finally { setBusy(false); }
});
elements.logout.addEventListener('click', async () => { try { await api('/api/acceso-oficina', { method: 'DELETE' }); } catch {} clearSession(); });
elements.reload.addEventListener('click', () => void Promise.all([loadCatalogs(), load()]));
elements.binListSearch.addEventListener('input', renderBins);
elements.binListState.addEventListener('change', renderBins);
elements.sections.forEach((button) => button.addEventListener('click', () => { state.section = button.dataset.returnSection; renderSection(); }));
elements.addOrigin.addEventListener('click', addOrigin);
elements.originRows.addEventListener('click', (event) => {
    const remove = event.target.closest('[data-remove-origin]'); if (!remove) return;
    const row = remove.closest('[data-origin-key]'); state.origins = state.origins.filter((origin) => origin.clave !== row.dataset.originKey); renderOriginRows();
});
elements.originRows.addEventListener('input', (event) => {
    const input = event.target.closest('[data-origin-kilos]'); if (!input) return;
    const row = input.closest('[data-origin-key]'); const origin = state.origins.find((item) => item.clave === row.dataset.originKey); if (origin) origin.kilos_aportados = input.value; renderBalance();
});
elements.totalKilos.addEventListener('input', renderBalance);
elements.binForm.addEventListener('submit', (event) => { event.preventDefault(); void submitBin(); });
function handleBinAction(event) {
    const regularize = event.target.closest('[data-regularize-bin]'); if (regularize) openRegularize(regularize.dataset.regularizeBin);
    const edit = event.target.closest('[data-edit-bin]'); if (edit) openEdit(edit.dataset.editBin);
    const annul = event.target.closest('[data-annul-bin]'); if (annul) void annulBin(annul.dataset.annulBin);
}
elements.recentBins.addEventListener('click', handleBinAction);
elements.pendingList.addEventListener('click', handleBinAction);
elements.editAddOrigin.addEventListener('click', addEditOrigin);
elements.editOrigins.addEventListener('click', (event) => {
    const remove = event.target.closest('[data-remove-edit-origin]');
    if (!remove) return;
    syncEditOriginValues();
    const row = remove.closest('[data-edit-origin-key]');
    state.editOrigins = state.editOrigins.filter(
        (origin) => origin.clave !== row.dataset.editOriginKey,
    );
    renderEditOriginRows();
});
elements.editOrigins.addEventListener('input', renderEditBalances);
elements.editGreenTotal.addEventListener('input', renderEditBalances);
elements.editDefinitiveTotal.addEventListener('input', renderEditBalances);
elements.editForm.addEventListener('submit', (event) => {
    event.preventDefault();
    if (event.submitter?.value === 'cancel') {
        elements.editDialog.close();
        state.editOperationId = null;
        state.editOrigins = [];
        return;
    }
    void submitEdit();
});
elements.regularizeOrigins.addEventListener('input', renderRegularizeBalance);
elements.regularizeTotal.addEventListener('input', renderRegularizeBalance);
elements.regularizeForm.addEventListener('submit', (event) => { event.preventDefault(); if (event.submitter?.value === 'cancel') { elements.regularizeDialog.close(); state.regularizationOperationId = null; return; } void submitRegularize(); });
elements.legacyList.addEventListener('click', (event) => {
    const migrate = event.target.closest('[data-migrate-legacy]'); if (migrate) openMigration(migrate.dataset.migrateLegacy);
    const discard = event.target.closest('[data-discard-legacy]'); if (discard) void discardLegacy(discard.dataset.discardLegacy);
});
elements.migrationOrigins.addEventListener('input', renderMigrationBalance);
elements.migrationTotal.addEventListener('input', renderMigrationBalance);
elements.migrationForm.addEventListener('submit', (event) => { event.preventDefault(); if (event.submitter?.value === 'cancel') { elements.migrationDialog.close(); return; } void submitMigration(); });

if (state.token && state.identity && showApp()) void Promise.all([loadCatalogs(), load()]); else clearSession();
state.poller = createOperationalPoller(
    () => load({ silent: true }),
    {
        intervalMs: 30000,
        canRun: () => Boolean(state.token)
            && !elements.app.classList.contains('is-hidden')
            && !elements.editDialog.open
            && !elements.regularizeDialog.open
            && !elements.migrationDialog.open,
    },
);
state.poller.start();

function syncContainerFields(form, labelId) {
    const boxes = form.elements.tipo_envase.value === 'caja_3_4';
    const quantity = form.elements.cantidad_envases;
    quantity.max = boxes ? '100000' : '1';
    quantity.readOnly = !boxes;
    if (!boxes) quantity.value = '1';
    byId(labelId).textContent = boxes ? 'Cantidad de cajas 3/4 *' : 'Cantidad de bins *';
}
for (const [form, labelId] of [[elements.binForm, 'returnContainerQuantityLabel'], [elements.editForm, 'editReturnContainerQuantityLabel']]) {
    form.elements.tipo_envase.addEventListener('change', () => syncContainerFields(form, labelId));
    syncContainerFields(form, labelId);
}

function readContainerFields(form, errorElement) {
    const tipo_envase = form.elements.tipo_envase.value;
    const cantidad_envases = Number(form.elements.cantidad_envases.value);
    if (!['bins', 'caja_3_4'].includes(tipo_envase) || !Number.isInteger(cantidad_envases) || cantidad_envases < 1 || cantidad_envases > (tipo_envase === 'bins' ? 1 : 100000)) {
        errorElement.textContent = tipo_envase === 'bins' ? 'Registra un bin por folio.' : 'Ingresa una cantidad entera de cajas 3/4 entre 1 y 100000.';
        return null;
    }
    return { tipo_envase, cantidad_envases };
}
