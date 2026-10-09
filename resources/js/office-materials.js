import { createClientTransfers } from './office-material-client-transfers.js';
import { openItemPhotoPanel, loadPrivateItemImages, releasePrivateItemImages } from './shared/private-item-photos';
import { createOperationalPoller } from './shared/operational-poller';

const byId = (id) => document.getElementById(id);
const elements = {
    access: byId('officeAccess'), app: byId('officeApp'), login: byId('officeLoginForm'),
    loginError: byId('officeLoginError'), userName: byId('officeUserName'), userRole: byId('officeUserRole'),
    initials: byId('officeInitials'), logout: byId('officeLogoutButton'), camerasNav: byId('officeCamerasNav'),
    loadsNav: byId('officeLoadsNav'), prefrioNav: byId('officePrefrioNav'), accessesNav: byId('officeAccessesNav'), managementNav: byId('officeManagementNav'), romanaNav: byId('officeRomanaNav'),
    reload: byId('reloadMaterialsButton'), admin: byId('materialsAdminCatalogs'), itemForm: byId('itemMaterialForm'),
    itemError: byId('itemMaterialError'), itemCancel: byId('cancelItemEdit'), itemList: byId('itemsMaterialList'),
    seasonList: byId('seasonsMaterialList'),
    seasonSelector: byId('materialSeasonSelector'), seasonActive: byId('materialsSeasonActive'),
    clientList: byId('clientsMaterialList'),
    providerForm: byId('providerMaterialForm'), providerError: byId('providerMaterialError'),
    providerCancel: byId('cancelProviderEdit'), providerList: byId('providersMaterialList'),
    providerClientOptions: byId('providerClientOptions'), providerCategoryOptions: byId('providerCategoryOptions'), providersSummary: byId('providersSummary'),
    destinationForm: byId('destinationMaterialForm'), destinationError: byId('destinationMaterialError'),
    destinationCancel: byId('cancelDestinationEdit'), destinationList: byId('destinationsMaterialList'),
    dispatchForm: byId('dispatchMaterialForm'), dispatchError: byId('dispatchMaterialError'),
    dispatchDestination: byId('dispatchDestination'), dispatchLines: byId('dispatchMaterialLines'),
    dispatchAssignee: byId('dispatchAssignee'), dispatchAssigneeField: byId('dispatchAssigneeField'),
    dispatchFolioSelection: byId('dispatchFolioSelection'), dispatchFifoReasonField: byId('dispatchFifoReasonField'), dispatchSubmit: byId('dispatchSubmit'),
    dispatchTraceDialog: byId('materialDispatchTraceDialog'), dispatchTraceTitle: byId('dispatchTraceTitle'), dispatchTraceBody: byId('dispatchTraceBody'),
    dispatchTraceClose: byId('closeMaterialDispatchTrace'), dispatchReassignForm: byId('dispatchReassignForm'), dispatchReassignError: byId('dispatchReassignError'),
    stockSync: byId('materialsStockSync'),
    addDispatchLine: byId('addDispatchLine'), dispatchList: byId('dispatchMaterialList'),
    inventorySearch: byId('materialsInventorySearch'), inventoryClient: byId('materialsInventoryClient'), inventorySummary: byId('materialsInventorySummary'), inventoryBody: byId('materialsInventoryBody'),
    inventoryPageSize: byId('materialsInventoryPageSize'), inventoryPrevious: byId('materialsInventoryPrevious'), inventoryNext: byId('materialsInventoryNext'), inventoryPage: byId('materialsInventoryPage'),
    clientCount: byId('materialsClientCount'), itemCount: byId('materialsItemCount'), folioCount: byId('materialsFolioCount'),
    dispatchCount: byId('materialsDispatchCount'), destinationCount: byId('materialsDestinationCount'),
    seasonsSummary: byId('seasonsSummary'), clientsSummary: byId('clientsSummary'), itemsSummary: byId('itemsSummary'), destinationsSummary: byId('destinationsSummary'),
    importOpen: byId('openMaterialImport'), importDialog: byId('materialImportDialog'),
    importClose: byId('closeMaterialImport'), importForm: byId('materialImportForm'),
    importTemplate: byId('downloadMaterialTemplate'), importError: byId('materialImportError'),
    importPreview: byId('materialImportPreview'), importMetrics: byId('materialImportMetrics'),
    importErrors: byId('materialImportErrors'), importRows: byId('materialImportRows'),
    importConfirm: byId('confirmMaterialImport'), importConfirmationHelp: byId('materialImportConfirmationHelp'),
    importHistory: byId('materialImportHistory'),
    directDispatchDialog: byId('materialDirectDispatchDialog'), directDispatchForm: byId('materialDirectDispatchForm'),
    directDispatchContext: byId('materialDirectDispatchContext'), directDispatchError: byId('materialDirectDispatchError'),
    directDispatchClose: byId('closeMaterialDirectDispatch'), directDispatchCancel: byId('cancelMaterialDirectDispatch'),
    directConsumptionDialog: byId('materialDirectConsumptionDialog'), directConsumptionForm: byId('materialDirectConsumptionForm'),
    directConsumptionContext: byId('materialDirectConsumptionContext'), directConsumptionError: byId('materialDirectConsumptionError'),
    directConsumptionClose: byId('closeMaterialDirectConsumption'), directConsumptionCancel: byId('cancelMaterialDirectConsumption'),
    correctionDialog: byId('materialCorrectionDialog'), correctionForm: byId('materialCorrectionForm'),
    correctionContext: byId('materialCorrectionContext'), correctionError: byId('materialCorrectionError'),
    correctionClose: byId('closeMaterialCorrection'), correctionCancel: byId('cancelMaterialCorrection'),
    regularizationDialog: byId('materialRegularizationDialog'), regularizationForm: byId('materialRegularizationForm'),
    regularizationContext: byId('materialRegularizationContext'), regularizationError: byId('materialRegularizationError'),
    regularizationClose: byId('closeMaterialRegularization'), regularizationCancel: byId('cancelMaterialRegularization'),
    loading: byId('officeLoading'), loadingText: byId('officeLoadingText'), toasts: byId('officeToasts'),
};
const keys = { token: 'estiba_wms_office_token', identity: 'estiba_wms_office_identity' };
const state = {
    token: localStorage.getItem(keys.token), identity: readJson(keys.identity),
    seasons: [], selectedSeasonId: null, clients: [], providers: [], items: [], destinations: [], dispatches: [], inventory: [], inventorySummary: [], inventoryItems: [], inventoryTotals: {}, inventoryMeta: null, inventoryCurrentPage: 1, imports: [], importPreview: null, dispatchOperationId: null, directDispatchOperationId: null, directDispatchFolioId: null, directConsumptionOperationId: null, directConsumptionFolioId: null, correctionOperationId: null,
    cancellationOperations: new Map(), blockOperations: new Map(), operationalRefreshPromise: null, inventorySyncedAt: null,
    regularizationItemId: null, regularizationOperationId: null,
    assignees: [], dispatchFolios: new Map(), dispatchAllocations: new Map(), dispatchPreviewVersion: 0, reassignOperationId: null, traceDispatchId: null,
    operationalPoller: null,
};
const operationalRefreshIntervalMs = 30000;
const mainDataSections = new Set(['resumen', 'catalogos', 'inventario', 'despachos', 'vencimientos']);
const operationalDataSections = new Set(['resumen', 'inventario', 'despachos']);
const materialDispatchSummaryPath = '/api/materiales/despachos?vista=resumen';
const materialInventorySummaryPath = '/api/materiales/inventario?vista=resumen';
let inventorySearchTimer;
let inventoryRequestSequence = 0;

class ApiError extends Error { constructor(message, status) { super(message); this.status = status; } }
function readJson(key) { try { return JSON.parse(localStorage.getItem(key) || 'null'); } catch { return null; } }
function escapeHtml(value) { return String(value ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&#039;'); }
function errorMessage(data, fallback) { return Object.values(data?.errors || {}).flat()[0] || data?.message || fallback; }
function statusText(value) { return String(value || '').replaceAll('_', ' ').replace(/^./, (letter) => letter.toUpperCase()); }
function itemTypeLabel(value) { return ({ insumo: 'Insumo', material_mp: 'Material MP', material_pt: 'Material PT' })[value] || 'Sin tipo operacional'; }
function quantity(value) { return new Intl.NumberFormat('es-CL', { maximumFractionDigits: 3 }).format(Number(value || 0)); }
function dateTime(value) { return value ? new Intl.DateTimeFormat('es-CL', { dateStyle: 'short', timeStyle: 'short' }).format(new Date(value)) : 'Pendiente'; }
function operationUuid() {
    if (typeof crypto.randomUUID === 'function') return crypto.randomUUID();
    const bytes = crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 0x0f) | 0x40; bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = [...bytes].map((value) => value.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

async function api(path, options = {}) {
    const headers = new Headers(options.headers || {}); headers.set('Accept', 'application/json');
    if (state.token) headers.set('Authorization', `Bearer ${state.token}`);
    if (options.body && ! (options.body instanceof FormData)) headers.set('Content-Type', 'application/json');
    let response;
    try { response = await fetch(path, { ...options, headers }); } catch { throw new ApiError('No fue posible conectar con Laravel.', 0); }
    const data = response.status === 204 ? null : await response.json().catch(() => ({}));
    if (!response.ok) { if (response.status === 401) clearSession(); throw new ApiError(errorMessage(data, 'No fue posible completar la operación.'), response.status); }
    return data;
}
function setBusy(active, message = 'Procesando…') { elements.loadingText.textContent = message; elements.loading.classList.toggle('is-hidden', !active); elements.loading.setAttribute('aria-hidden', String(!active)); }
function toast(message, error = false) { const node = document.createElement('div'); node.className = `toast${error ? ' toast--error' : ''}`; node.textContent = message; elements.toasts.append(node); window.setTimeout(() => node.remove(), 4500); }
function persist(payload) { state.token = payload.token; state.identity = payload.usuario; localStorage.setItem(keys.token, payload.token); localStorage.setItem(keys.identity, JSON.stringify(payload.usuario)); window.dispatchEvent(new CustomEvent('estiba:office-session', { detail: { authenticated: true, identity: payload.usuario } })); }
function clearSession() { state.token = null; state.identity = null; localStorage.removeItem(keys.token); localStorage.removeItem(keys.identity); elements.app.classList.add('is-hidden'); elements.access.classList.remove('is-hidden'); window.dispatchEvent(new CustomEvent('estiba:office-session', { detail: { authenticated: false } })); }
function showApp() {
    elements.access.classList.add('is-hidden'); elements.app.classList.remove('is-hidden');
    const name = state.identity?.nombre || 'Usuario'; elements.userName.textContent = name; elements.userRole.textContent = statusText(state.identity?.rol);
    elements.initials.textContent = name.split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0]).join('').toUpperCase();
    elements.accessesNav.classList.toggle('is-hidden', state.identity?.puede_administrar_accesos !== true);
    elements.managementNav.classList.toggle('is-hidden', state.identity?.puede_consultar_panel_gerencial !== true);
    elements.romanaNav.classList.toggle('is-hidden', state.identity?.puede_consultar_romana !== true);
    elements.loadsNav.classList.toggle('is-hidden', state.identity?.puede_consultar_cargas !== true);
    elements.prefrioNav.classList.toggle('is-hidden', state.identity?.puede_consultar_prefrio !== true);
    elements.admin.classList.toggle('is-hidden', state.identity?.puede_administrar_catalogos_materiales !== true);
    elements.dispatchForm.classList.toggle(
        'is-hidden',
        state.identity?.puede_gestionar_despachos_materiales !== true,
    );
}

function activeMaterialsSection() {
    return elements.app?.dataset.materialsSection || 'resumen';
}

function mainDataIsRequired() {
    return mainDataSections.has(activeMaterialsSection());
}

function operationalDataIsRequired() {
    return operationalDataSections.has(activeMaterialsSection());
}

function selectedSeason() { return state.seasons.find((season) => season.id === state.selectedSeasonId) || null; }
function seasonClients() { return state.clients.filter((client) => client.temporada?.id === state.selectedSeasonId); }
function globalClients() {
    const prioritized = [...state.clients].sort((left, right) =>
        Number(right.temporada?.id === state.selectedSeasonId) - Number(left.temporada?.id === state.selectedSeasonId));
    return [...new Map(prioritized.filter((client) => client.cliente_id).map((client) => [client.cliente_id, client])).values()];
}
function seasonItems() { return state.items.filter((item) => item.cliente?.temporada?.id === state.selectedSeasonId); }
function activeItems() { return state.items.filter((item) => item.activo && item.cliente?.activo !== false && item.cliente?.temporada?.activa !== false); }
function activeDestinations() { return state.destinations.filter((destination) => destination.activo); }
function directDispatchDestinations() { return activeDestinations().filter((destination) => destination.tipo !== 'fisica'); }
function directConsumptionCenters() { return activeDestinations().filter((destination) => destination.tipo === 'virtual' && destination.centro_costo); }
function normalizedSearch(value) { return String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase(); }
function itemLabel(item) { return `${item.cliente?.temporada?.codigo || ''} · ${item.cliente?.codigo || ''} · ${item.codigo} · ${item.nombre}`; }
function itemSearchText(item) { return normalizedSearch(`${itemLabel(item)} ${item.cliente?.nombre || ''} ${item.categoria || ''}`); }
function availableStock(itemId) {
    return Math.round(state.inventoryItems
        .filter((summary) => summary.item.id === itemId)
        .reduce((total, summary) => total + Number(summary.cantidad_disponible || 0), 0) * 1000) / 1000;
}
function closeItemResults(except = null) {
    elements.dispatchLines.querySelectorAll('.material-item-results').forEach((results) => {
        if (results === except) return;
        results.classList.add('is-hidden');
        results.closest('.material-item-picker')?.querySelector('.material-item-search')?.setAttribute('aria-expanded', 'false');
    });
}
function matchingItems(query) {
    const normalized = normalizedSearch(query);
    return activeItems()
        .filter((item) => !normalized || itemSearchText(item).includes(normalized))
        .sort((left, right) => {
            const stockDifference = Number(availableStock(right.id) > 0) - Number(availableStock(left.id) > 0);
            return stockDifference || itemLabel(left).localeCompare(itemLabel(right), 'es');
        })
        .slice(0, 12);
}
function renderItemResults(row) {
    const input = row.querySelector('.material-item-search');
    const results = row.querySelector('.material-item-results');
    const matches = matchingItems(input.value);
    results.innerHTML = matches.map((item) => {
        const stock = availableStock(item.id);
        return `<button class="material-item-option" data-select-item="${item.id}" type="button" role="option"${stock <= 0 ? ' disabled' : ''}><span>${escapeHtml(itemLabel(item))}</span><strong>${stock > 0 ? `${quantity(stock)} ${escapeHtml(item.unidad_medida)} disponibles` : 'Sin stock disponible'}</strong></button>`;
    }).join('') || '<p class="material-item-empty">No hay ítems que coincidan con la búsqueda.</p>';
    results.classList.remove('is-hidden');
    input.setAttribute('aria-expanded', 'true');
}
function updateDispatchLine(row) {
    const itemId = row.querySelector('[name="item_material_id"]').value;
    const input = row.querySelector('.material-item-search');
    const amount = row.querySelector('[name="cantidad"]');
    const stockHint = row.querySelector('.material-stock');
    const item = activeItems().find((candidate) => candidate.id === itemId);
    if (!item) {
        stockHint.textContent = 'Escribe para buscar un ítem con existencia.';
        stockHint.classList.remove('material-stock--empty');
        amount.disabled = true;
        amount.removeAttribute('max');
        row.classList.remove('dispatch-line--unavailable');
        return;
    }
    const stock = availableStock(item.id);
    if (document.activeElement !== input) input.value = itemLabel(item);
    amount.disabled = stock <= 0;
    amount.max = String(stock);
    amount.setAttribute('aria-describedby', stockHint.id);
    stockHint.textContent = stock > 0
        ? `Stock disponible: ${quantity(stock)} ${item.unidad_medida}`
        : `Sin stock disponible en cámaras de materiales`;
    stockHint.classList.toggle('material-stock--empty', stock <= 0);
    row.classList.toggle('dispatch-line--unavailable', stock <= 0 || Number(amount.value || 0) > stock);
}
function refreshDispatchLines() {
    elements.dispatchLines.querySelectorAll('.dispatch-line').forEach((row) => {
        updateDispatchLine(row);
        if (!row.querySelector('.material-item-results').classList.contains('is-hidden')) renderItemResults(row);
    });
    elements.stockSync.textContent = state.inventorySyncedAt
        ? `Stock actualizado ${state.inventorySyncedAt}`
        : 'Consultando stock disponible…';
}
function renderMetrics() {
    elements.seasonActive.textContent = state.seasons.find((season) => season.activa)?.codigo || '—'; elements.clientCount.textContent = String(seasonClients().filter((client) => client.activo).length); elements.itemCount.textContent = String(seasonItems().filter((item) => item.activo && item.cliente?.activo !== false).length); elements.folioCount.textContent = String(state.inventoryTotals.folios || 0);
    elements.dispatchCount.textContent = String(state.dispatches.filter((dispatch) => ['pendiente', 'parcial'].includes(dispatch.estado)).length);
    elements.destinationCount.textContent = String(activeDestinations().length);
}
function renderSeasons() {
    elements.seasonsSummary.textContent = `${state.seasons.length} registradas`;
    elements.seasonSelector.innerHTML = state.seasons.map((season) => `<option value="${season.id}">${escapeHtml(season.codigo)} · ${escapeHtml(season.nombre)}${season.activa ? ' (activa)' : ''}</option>`).join('') || '<option value="">Sin temporadas</option>';
    elements.seasonSelector.value = state.selectedSeasonId || '';
    elements.seasonList.innerHTML = state.seasons.map((season) => `<article class="material-row${season.activa ? '' : ' is-inactive'}"><div><strong>${escapeHtml(season.codigo)} · ${escapeHtml(season.nombre)}</strong><small>${escapeHtml(season.fecha_inicio || 'Sin inicio')} → ${escapeHtml(season.fecha_fin || 'Sin término')} · ${season.clientes_activos} clientes · ${season.items_activos} ítems${season.activa ? ' · activa global' : ''}</small></div></article>`).join('') || '<p class="empty-state">No existen temporadas. Debes crearla en Accesos.</p>';
}
function renderClients() {
    const clients = seasonClients();
    elements.clientsSummary.textContent = `${clients.length} registrados`;
    elements.clientList.innerHTML = clients.map((client) => `<article class="material-row${client.activo ? '' : ' is-inactive'}"><div><strong>${escapeHtml(client.codigo)} · ${escapeHtml(client.nombre)}</strong><small>${client.items_activos} ítems activos${client.codigo_externo ? ` · ERP ${escapeHtml(client.codigo_externo)}` : ''} · administrado en Accesos</small></div></article>`).join('') || '<p class="empty-state">No existen clientes en esta temporada. Créalo o actívalo en Accesos.</p>';
    const current = elements.itemForm.elements.cliente_material_id.value;
    elements.itemForm.elements.cliente_material_id.innerHTML = '<option value="">Selecciona un cliente</option>' + clients.filter((client) => client.activo).map((client) => `<option value="${client.id}">${escapeHtml(client.codigo)} · ${escapeHtml(client.nombre)}</option>`).join('');
    if ([...elements.itemForm.elements.cliente_material_id.options].some((option) => option.value === current)) elements.itemForm.elements.cliente_material_id.value = current;
}
function providerCategoryKey(clientId, category) { return `${clientId}|${String(category || '').trim().toLocaleLowerCase('es-CL')}`; }
function providerCategoryAssignments() {
    const selectedClients = new Set([...elements.providerClientOptions.querySelectorAll('input:checked')].map((input) => input.value));
    const clientCatalogs = new Map(state.clients.map((client) => [client.id, client]));
    const unique = new Map();
    seasonItems().filter((item) => item.activo && item.categoria?.trim()).forEach((item) => {
        const client = clientCatalogs.get(item.cliente?.id);
        if (!client?.cliente_id || !selectedClients.has(client.cliente_id)) return;
        const category = item.categoria.trim();
        const key = providerCategoryKey(client.cliente_id, category);
        const current = unique.get(key) || { key, clientId: client.cliente_id, clientCode: client.codigo, category, total: 0, typed: 0 };
        current.total += 1;
        current.typed += Number(Boolean(item.categoria_operacional));
        unique.set(key, current);
    });
    return [...unique.values()].sort((left, right) => `${left.clientCode} ${left.category}`.localeCompare(`${right.clientCode} ${right.category}`, 'es'));
}
function renderProviderCategories(checkedKeys = null) {
    const checked = checkedKeys || new Set([...elements.providerCategoryOptions.querySelectorAll('input:checked')].map((input) => input.dataset.key));
    const assignments = providerCategoryAssignments();
    elements.providerCategoryOptions.innerHTML = assignments.map((assignment) => { const pending = assignment.total - assignment.typed; const detail = pending > 0 ? `${assignment.total} ítems · ${assignment.typed} tipificados · ${pending} pendientes` : `${assignment.total} ${assignment.total === 1 ? 'ítem tipificado' : 'ítems tipificados'}`; return `<label><input name="categorias" type="checkbox" data-key="${escapeHtml(assignment.key)}" data-client-id="${assignment.clientId}" data-category="${escapeHtml(assignment.category)}"${checked.has(assignment.key) ? ' checked' : ''}><span>${escapeHtml(assignment.clientCode)} · ${escapeHtml(assignment.category)} · ${escapeHtml(detail)}</span></label>`; }).join('') || '<p class="empty-state">El cliente seleccionado no posee categorías comerciales activas en esta temporada.</p>';
}
function renderProviders() {
    if (!elements.providerForm) return;
    elements.providersSummary.textContent = `${state.providers.length} registrados`;
    const checked = new Set([...elements.providerClientOptions.querySelectorAll('input:checked')].map((input) => input.value));
    const checkedCategories = new Set([...elements.providerCategoryOptions.querySelectorAll('input:checked')].map((input) => input.dataset.key));
    elements.providerClientOptions.innerHTML = globalClients()
        .filter((client) => client.activo)
        .map((client) => `<label><input name="cliente_ids" type="checkbox" value="${client.cliente_id}"${checked.has(client.cliente_id) ? ' checked' : ''}><span>${escapeHtml(client.codigo)} · ${escapeHtml(client.nombre)}</span></label>`)
        .join('') || '<p class="empty-state">No existen clientes activos en Accesos.</p>';
    renderProviderCategories(checkedCategories);
    elements.providerList.innerHTML = state.providers.map((provider) => {
        const clients = (provider.clientes || []).map((client) => `${client.codigo} · ${client.nombre}`).join(', ');
        const categories = (provider.categorias || []).map((entry) => entry.categoria).filter(Boolean);
        return `<article class="material-row${provider.activo ? '' : ' is-inactive'}"><div><strong>${escapeHtml(provider.codigo)} · ${escapeHtml(provider.nombre)}</strong><small>${escapeHtml(clients || 'Sin clientes asociados')} · ${escapeHtml([...new Set(categories)].join(', ') || 'Sin categorías')}${provider.codigo_externo ? ` · ERP ${escapeHtml(provider.codigo_externo)}` : ''}</small></div><button data-edit-provider="${provider.id}" type="button">Editar</button></article>`;
    }).join('') || '<p class="empty-state">No existen proveedores registrados.</p>';
}
function renderItems() {
    const canAdminister = state.identity?.puede_administrar_catalogos_materiales === true;
    const canRegularize = canAdminister
        && state.identity?.puede_administrar_recetas_materiales === true;
    const items = seasonItems();
    elements.itemsSummary.textContent = `${items.length} registrados`;
    releasePrivateItemImages(elements.itemList);
    elements.itemList.innerHTML = items.map((item) => {
        const consolidated = item.regularizacion?.item_canonico;
        const detail = consolidated
            ? `Consolidado en ${consolidated.codigo} · ${consolidated.nombre}`
            : `${item.categoria || 'Sin categoría comercial'} · ${itemTypeLabel(item.categoria_operacional)} · ${item.unidad_medida} · ${item.folios_activos} folios activos`;
        const actions = canAdminister && !consolidated
            ? `<div class="material-row__actions"><button data-edit-item="${item.id}" type="button">Editar</button>${canRegularize && item.activo && item.categoria_operacional === 'material_pt' ? `<button data-regularize-item="${item.id}" type="button">Consolidar con MP</button>` : ''}</div>`
            : '';
        return `<article class="material-row${item.activo ? '' : ' is-inactive'}">${item.foto_principal ? `<img class="item-photo-thumb" alt="${escapeHtml(item.nombre)}" data-private-item-photo="${escapeHtml(item.foto_principal.miniatura_url)}">` : ''}<div><strong>${escapeHtml(item.cliente?.codigo || 'SIN CLIENTE')} · ${escapeHtml(item.codigo)} · ${escapeHtml(item.nombre)}</strong><small>${escapeHtml(detail)}</small></div><button data-item-photos="${item.id}" type="button">Fotos (${item.cantidad_fotos ?? 0})</button>${actions}</article>`;
    }).join('') || '<p class="empty-state">No existen ítems en esta temporada.</p>';
    void loadPrivateItemImages(elements.itemList);
    refreshDispatchLines();
}
function renderDestinations() {
    const canAdminister = state.identity?.puede_administrar_catalogos_materiales === true;
    elements.destinationsSummary.textContent = `${state.destinations.length} registrados`;
    elements.destinationList.innerHTML = state.destinations.map((destination) => `<article class="material-row${destination.activo ? '' : ' is-inactive'}"><div><strong>${escapeHtml(destination.nombre)}</strong><small>${escapeHtml(destination.centro_costo)}${destination.descripcion ? ` · ${escapeHtml(destination.descripcion)}` : ''}</small></div>${canAdminister ? `<button data-edit-destination="${destination.id}" type="button">Editar</button>` : ''}</article>`).join('') || '<p class="empty-state">No existen destinos.</p>';
    elements.dispatchDestination.innerHTML = '<option value="">Selecciona un destino</option>' + activeDestinations().map((destination) => `<option value="${destination.id}">${escapeHtml(destination.nombre)} · ${escapeHtml(destination.centro_costo)}</option>`).join('');
}
function renderDispatches() {
    elements.dispatchList.innerHTML = state.dispatches.map((dispatch) => {
        const detail = dispatch.items.map((item) => `${item.item.cliente?.temporada?.codigo || ''}/${item.item.cliente?.codigo || ''}/${item.item.codigo}: ${quantity(item.cantidad_despachada)}/${quantity(item.cantidad_solicitada)} ${item.unidad_medida}`).join(' · ');
        const shortage = dispatch.items.some((item) => Number(item.cantidad_reservada) + Number(item.cantidad_despachada) < Number(item.cantidad_solicitada));
        const canCancel = state.identity?.puede_cancelar_despachos_materiales === true;
        return `<article class="dispatch-row"><div><strong>${escapeHtml(dispatch.codigo)} · ${escapeHtml(dispatch.destino.nombre)}</strong><small>${escapeHtml(dispatch.destino.centro_costo)} · ${escapeHtml(dispatch.modalidad === 'directo' ? 'Directo desde bodega' : 'Delegado a tablet')} · ${escapeHtml(detail)}${dispatch.items.some((item) => item.alerta_vencimiento) ? ' · Reserva insuficiente por vencimiento' : shortage ? ' · Falta existencia por reservar' : ''}</small></div><div class="dispatch-row__state"><span>${escapeHtml(statusText(dispatch.estado))}</span><button data-trace-dispatch="${dispatch.id}" type="button">Ver trazabilidad</button>${canCancel && ['pendiente', 'parcial'].includes(dispatch.estado) ? `<button data-cancel-dispatch="${dispatch.id}" type="button">Cancelar</button>` : ''}</div></article>`;
    }).join('') || '<p class="empty-state">No existen despachos de materiales.</p>';
}
function renderInventory() {
    const clients = seasonClients().filter((client) => client.activo);
    const currentClient = elements.inventoryClient.value;
    elements.inventoryClient.innerHTML = '<option value="">Todos los clientes</option>' + clients.map((client) => `<option value="${client.id}">${escapeHtml(client.codigo)} · ${escapeHtml(client.nombre)}</option>`).join('');
    elements.inventoryClient.value = clients.some((client) => client.id === currentClient) ? currentClient : '';
    const activeClientId = elements.inventoryClient.value;
    const rows = state.inventory;
    const selectedSummary = activeClientId ? state.inventorySummary.find((summary) => summary.cliente.id === activeClientId) : null;
    const query = elements.inventorySearch.value.trim();
    elements.inventorySummary.textContent = selectedSummary && !query
        ? `${selectedSummary.folios} folios · ${selectedSummary.items} ítems · ${selectedSummary.posiciones} posiciones`
        : `${Number(state.inventoryMeta?.total || 0)} folios coincidentes · ${Number(state.inventoryTotals.clientes || 0)} clientes`;
    const canCorrect = state.identity?.puede_corregir_items_estibados_materiales === true;
    const canManageBlock = state.identity?.puede_gestionar_bloqueos_materiales === true;
    const canDispatchDirect = state.identity?.puede_gestionar_despachos_materiales === true
        && state.identity?.puede_retirar_materiales === true;
    elements.inventoryBody.innerHTML = rows.map((folio) => {
        const blocked = folio.estado_operacional === 'bloqueado';
        const canBlock = canManageBlock
            && ['disponible', 'pendiente_ubicacion'].includes(folio.estado_operacional)
            && Number(folio.cantidad_reservada || 0) <= 0;
        const status = blocked
            ? `<strong class="material-status--blocked">Bloqueado</strong><small>${escapeHtml(folio.motivo_bloqueo || 'Sin motivo')}</small>`
            : folio.estado_ubicacion === 'pendiente_ubicacion'
                ? '<strong>Pendiente de ubicación</strong>'
                : folio.reservable
                    ? '<strong>Disponible</strong>'
                    : '<strong>No disponible</strong>';
    const canDirect = canDispatchDirect
            && !blocked
            && Boolean(folio.camara)
            && folio.estado_ubicacion !== 'pendiente_ubicacion'
            && Number(folio.cantidad_disponible || 0) > 0;
        const canConsumeDirect = state.identity?.puede_gestionar_despachos_materiales === true
            && folio.categoria_operacional === 'insumo'
            && folio.almacen?.codigo === 'BOD-CENTRAL'
            && folio.reservable === true
            && Number(folio.cantidad_disponible || 0) > 0;
        const actions = [
            canConsumeDirect ? `<button data-direct-consumption="${folio.folio_id}" type="button">Consumir insumo</button>` : '',
            canDirect ? `<button data-direct-dispatch="${folio.folio_id}" type="button">Despachar directo</button>` : '',
            state.identity?.puede_transferir_folios_materiales_clientes === true ? `<button data-transfer-client="${folio.folio_id}" type="button">Transferir a otro cliente</button>` : '',
            canCorrect ? `<button data-correct-material="${folio.folio_id}" type="button">Corregir código</button>` : '',
            canManageBlock ? `<button data-correct-expiry="${folio.folio_id}" type="button">Corregir vencimiento</button>` : '',
            canManageBlock && blocked && folio.vencimiento?.estado !== 'vencido' && !folio.vencimiento?.bloqueado_por_vencimiento
                ? `<button data-release-material="${folio.folio_id}" type="button">Liberar</button>`
                : canBlock
                    ? `<button data-block-material="${folio.folio_id}" type="button">Bloquear</button>`
                    : '',
        ].filter(Boolean).join(' ');

        return `<tr><td><strong>${escapeHtml(folio.numero_folio)}</strong><small>${escapeHtml(folio.lote || 'Sin lote')}</small>${expiryBadge(folio.vencimiento)}</td><td><strong>${escapeHtml(folio.item.cliente?.codigo || '—')} · ${escapeHtml(folio.item.cliente?.nombre || '—')}</strong><small>${escapeHtml(folio.item.cliente?.temporada?.codigo || '—')}</small></td><td><strong>${escapeHtml(folio.item.codigo)}</strong><small>${escapeHtml(folio.item.nombre)} · ${escapeHtml(itemTypeLabel(folio.categoria_operacional))}</small></td><td>${quantity(folio.cantidad_actual)} ${escapeHtml(folio.unidad_medida)}</td><td>${quantity(folio.cantidad_reservada)}</td><td>${quantity(folio.cantidad_disponible)}</td><td>${status}</td><td><strong>${escapeHtml(folio.camara?.codigo || 'Sin cámara')}</strong><small>${escapeHtml(folio.posicion?.etiqueta || 'Sin posición')}</small></td><td>${actions || '—'}</td></tr>`;
    }).join('') || '<tr><td colspan="9">No existen folios coincidentes.</td></tr>';
    const currentPage = Number(state.inventoryMeta?.current_page || 1);
    const lastPage = Math.max(1, Number(state.inventoryMeta?.last_page || 1));
    elements.inventoryPage.textContent = `Página ${currentPage} de ${lastPage}`;
    elements.inventoryPrevious.disabled = currentPage <= 1;
    elements.inventoryNext.disabled = currentPage >= lastPage;
}
function renderImportHistory() {
    elements.importHistory.innerHTML = state.imports.map((entry) => `<article class="material-row"><div><strong>${escapeHtml(entry.nombre_archivo)}</strong><small>${escapeHtml(entry.creado_por?.nombre || 'Usuario')} · ${escapeHtml(dateTime(entry.created_at))}</small></div><span class="material-import-action">${escapeHtml(statusText(entry.estado))}</span></article>`).join('') || '<p class="empty-state">Aún no existen importaciones.</p>';
}
function renderImportPreview() {
    const preview = state.importPreview;
    elements.importPreview.classList.toggle('is-hidden', !preview);
    if (!preview) return;
    const summary = preview.resumen || {};
    const metrics = [
        ['FILAS LEÍDAS', summary.filas_leidas],
        ['VÁLIDAS', summary.filas_validas],
        ['CON ERROR', summary.filas_con_error],
        ['NUEVOS', preview.estado === 'confirmada' ? summary.creados : summary.nuevos_estimados],
        ['ACTUALIZACIONES', preview.estado === 'confirmada' ? summary.actualizados : summary.actualizaciones_estimadas],
    ];
    elements.importMetrics.innerHTML = metrics.map(([label, value]) => `<article><span>${label}</span><strong>${Number(value || 0)}</strong></article>`).join('');
    const errors = preview.errores || [];
    elements.importErrors.classList.toggle('is-hidden', errors.length === 0);
    elements.importErrors.innerHTML = errors.map((error) => `<p><strong>Fila ${Number(error.fila || 0)}${error.codigo ? ` · ${escapeHtml(error.codigo)}` : ''}:</strong> ${escapeHtml(error.mensaje)}</p>`).join('');
    elements.importRows.innerHTML = (preview.filas || []).slice(0, 100).map((row) => `<tr><td>${Number(row.fila)}</td><td><strong>${escapeHtml(row.temporada_codigo)}</strong><small>${escapeHtml(row.temporada_nombre || '')}</small></td><td><strong>${escapeHtml(row.cliente_codigo)}</strong><small>${escapeHtml(row.cliente_nombre || '')}</small></td><td><strong>${escapeHtml(row.codigo)}</strong></td><td>${escapeHtml(row.nombre)}<small>Mínimo: ${row.stock_minimo == null ? 'sin cambio' : quantity(row.stock_minimo)} · Reorden: ${row.punto_reorden == null ? 'sin cambio' : quantity(row.punto_reorden)} · Máximo: ${row.stock_maximo == null ? 'sin cambio' : quantity(row.stock_maximo)}</small></td><td>${escapeHtml(itemTypeLabel(row.categoria_operacional))}</td><td>${escapeHtml(row.unidad_medida)}</td><td><span class="material-import-action">${escapeHtml(statusText(row.accion))}</span></td></tr>`).join('') || '<tr><td colspan="8">No existen filas válidas para mostrar.</td></tr>';
    const omitted = preview.resumen?.columnas_omitidas || [];
    byId('materialImportOmitted').textContent = omitted.length ? `Columnas informativas o desconocidas omitidas: ${omitted.join(', ')}` : '';
    const confirmed = preview.estado === 'confirmada';
    elements.importConfirm.disabled = errors.length > 0 || confirmed;
    elements.importConfirmationHelp.textContent = confirmed
        ? `Importación confirmada: ${Number(summary.creados || 0)} creados, ${Number(summary.actualizados || 0)} actualizados y ${Number(summary.sin_cambios || 0)} sin cambios.`
        : errors.length > 0
            ? 'Corrige la planilla y vuelve a previsualizarla. No se aplicó ningún cambio.'
            : 'La confirmación actualizará solo el catálogo. Los ítems ausentes permanecerán sin cambios.';
}
function renderAll() { renderSeasons(); renderClients(); renderProviders(); renderItems(); renderDestinations(); renderDispatches(); renderInventory(); renderMetrics(); renderImportHistory(); }

function inventoryApiPath({ summary = false } = {}) {
    if (summary) return materialInventorySummaryPath;
    const query = new URLSearchParams({
        per_page: elements.inventoryPageSize.value || '25',
        page: String(state.inventoryCurrentPage),
    });
    const clientId = elements.inventoryClient.value;
    const search = elements.inventorySearch.value.trim();
    if (clientId) query.set('cliente_id', clientId);
    if (search) query.set('q', search);
    return `/api/materiales/inventario?${query}`;
}

function applyInventoryResponse(inventory) {
    state.inventory = inventory.data || [];
    state.inventorySummary = inventory.resumen_clientes || [];
    state.inventoryItems = inventory.resumen_items || [];
    state.inventoryTotals = inventory.resumen || {};
    state.inventoryMeta = inventory.meta || null;
    state.inventorySyncedAt = new Date().toLocaleTimeString('es-CL', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
}

async function loadInventoryPage(page = 1) {
    if (activeMaterialsSection() !== 'inventario') return;
    state.inventoryCurrentPage = Math.max(1, Number(page) || 1);
    const requestSequence = ++inventoryRequestSequence;
    const inventory = await api(inventoryApiPath());
    if (requestSequence !== inventoryRequestSequence) return;

    const lastPage = Math.max(1, Number(inventory.meta?.last_page || 1));
    if (state.inventoryCurrentPage > lastPage) {
        return loadInventoryPage(lastPage);
    }

    applyInventoryResponse(inventory);
    renderInventory();
}

const clientTransfers = createClientTransfers({ api, getIdentity: () => state.identity, getClients: seasonClients, getToken: () => state.token, uuid: operationUuid, onRefresh: loadAll });

async function loadAll() {
    window.dispatchEvent(new Event('materiales:reposicion-actualizada'));
    const section = activeMaterialsSection();
    if (section === 'vencimientos') return loadExpiryPage();
    if (!mainDataSections.has(section)) return;

    const catalogAdmin = state.identity?.puede_administrar_catalogos_materiales === true
        && section === 'catalogos';
    const needsDispatches = section === 'resumen' || section === 'despachos';
    const needsInventory = operationalDataSections.has(section);
    const [catalog, dispatches, inventory] = await Promise.all([
        catalogAdmin
            ? Promise.all([
                api('/api/administracion/materiales/temporadas'),
                api('/api/administracion/materiales/clientes'),
                api('/api/administracion/materiales/items'),
                api('/api/administracion/materiales/destinos'),
                api('/api/administracion/materiales/importaciones'),
                api('/api/administracion/materiales/proveedores'),
            ])
            : api('/api/materiales/catalogo'),
        needsDispatches ? api(materialDispatchSummaryPath) : Promise.resolve(null),
        needsInventory ? api(inventoryApiPath({ summary: section !== 'inventario' })) : Promise.resolve(null),
    ]);
    if (catalogAdmin) {
        state.seasons = catalog[0].data;
        state.clients = catalog[1].data;
        state.items = catalog[2].data;
        state.destinations = catalog[3].data;
        state.imports = catalog[4].data;
        state.providers = catalog[5].data;
    } else {
        state.seasons = catalog.temporada ? [catalog.temporada] : [];
        state.clients = catalog.clientes;
        state.items = catalog.items;
        state.destinations = catalog.destinos;
        state.imports = [];
        state.providers = [];
    }
    if (!state.seasons.some((season) => season.id === state.selectedSeasonId)) state.selectedSeasonId = state.seasons.find((season) => season.activa)?.id || state.seasons[0]?.id || null;
    if (dispatches) state.dispatches = dispatches.data;
    if (inventory) {
        applyInventoryResponse(inventory);
    }
    renderAll();
    if (section === 'inventario') await clientTransfers.refresh();
    if (section === 'despachos') await loadDispatchAssignees();
}

async function refreshOperationalData({ required = false } = {}) {
    const section = activeMaterialsSection();
    if (!operationalDataSections.has(section)) return false;
    if (!state.token || state.identity?.puede_consultar_despachos_materiales !== true) {
        if (required) throw new ApiError('No fue posible verificar el stock disponible con la sesión actual.', 403);
        return false;
    }

    const needsDispatches = section === 'resumen' || section === 'despachos';
    const operation = state.operationalRefreshPromise || Promise.all([
        needsDispatches ? api(materialDispatchSummaryPath) : Promise.resolve(null),
        api(inventoryApiPath({ summary: section !== 'inventario' })),
    ]);
    const ownsOperation = state.operationalRefreshPromise === null;
    if (ownsOperation) state.operationalRefreshPromise = operation;

    try {
        const [dispatches, inventory] = await operation;
        if (dispatches) state.dispatches = dispatches.data;
        applyInventoryResponse(inventory);
        if (needsDispatches) renderDispatches();
        if (section === 'inventario') renderInventory();
        if (section === 'resumen') renderMetrics();
        if (section === 'despachos') refreshDispatchLines();
        return true;
    } catch (error) {
        if (error.status !== 401) console.warn('No fue posible actualizar el stock de materiales.', error);
        if (required) {
            throw new ApiError(
                'No fue posible verificar el stock actual. Revisa la conexión e inténtalo nuevamente.',
                error.status || 0,
            );
        }
        return false;
    } finally {
        if (ownsOperation && state.operationalRefreshPromise === operation) state.operationalRefreshPromise = null;
    }
}

function addDispatchLine(itemId = '', amount = '') {
    const row = document.createElement('div'); row.className = 'dispatch-line';
    const hintId = `material-stock-${operationUuid()}`;
    const item = activeItems().find((candidate) => candidate.id === itemId);
    row.innerHTML = `<div class="material-item-picker"><input class="material-item-search" type="search" value="${escapeHtml(item ? itemLabel(item) : '')}" placeholder="Buscar ítem, código o cliente" autocomplete="off" role="combobox" aria-autocomplete="list" aria-expanded="false"><input name="item_material_id" type="hidden" value="${escapeHtml(itemId)}"><div class="material-item-results is-hidden" role="listbox"></div><small class="material-stock" id="${hintId}" aria-live="polite"></small></div><input name="cantidad" type="number" min="0.001" step="0.001" value="${escapeHtml(amount)}" placeholder="Cantidad" required><button data-remove-line type="button" aria-label="Quitar">×</button>`;
    elements.dispatchLines.append(row);
    updateDispatchLine(row);
}
function dispatchItemsPayload() {
    const seen = new Set();
    return [...elements.dispatchLines.querySelectorAll('.dispatch-line')].map((row, index) => {
        const itemId = row.querySelector('[name="item_material_id"]').value;
        const amount = Number(row.querySelector('[name="cantidad"]').value || 0);
        const item = activeItems().find((candidate) => candidate.id === itemId);
        if (!item) throw new ApiError(`Selecciona un ítem válido en la línea ${index + 1}.`, 422);
        if (seen.has(itemId)) throw new ApiError(`${item.nombre} está repetido en la solicitud.`, 422);
        seen.add(itemId);
        const stock = availableStock(itemId);
        if (stock <= 0) throw new ApiError(`${item.nombre} no tiene stock disponible.`, 422);
        if (amount <= 0) throw new ApiError(`Indica una cantidad válida para ${item.nombre}.`, 422);
        if (amount > stock) throw new ApiError(`${item.nombre}: solicitaste ${quantity(amount)} ${item.unidad_medida}, pero solo hay ${quantity(stock)} disponibles.`, 422);
        return { item_material_id: itemId, cantidad: amount };
    });
}
function isDirectDispatch() { return elements.dispatchForm.elements.modalidad.value === 'directo'; }
async function loadDispatchAssignees() {
    if (state.identity?.puede_gestionar_despachos_materiales !== true) return;
    const response = await api('/api/materiales/despachos/camareros');
    state.assignees = response.data;
    const options = '<option value="">Cualquier camarero autorizado</option>'
        + state.assignees.map((person) => `<option value="${Number(person.id)}">${escapeHtml(person.nombre)}</option>`).join('');
    elements.dispatchAssignee.innerHTML = options;
    elements.dispatchReassignForm.elements.asignado_a_user_id.innerHTML = options;
}
function toggleDispatchMode() {
    const direct = isDirectDispatch();
    elements.dispatchAssigneeField.classList.toggle('is-hidden', direct);
    elements.dispatchFolioSelection.classList.toggle('is-hidden', !direct);
    elements.dispatchFifoReasonField.classList.toggle('is-hidden', !direct);
    elements.dispatchSubmit.textContent = direct ? 'Entregar ahora' : 'Crear despacho y reservar';
    elements.dispatchAssignee.disabled = direct;
    if (direct) void refreshDispatchFolioSelection();
    else { ++state.dispatchPreviewVersion; elements.dispatchFifoReasonField.classList.add('is-hidden'); }
}
async function refreshDispatchFolioSelection() {
    if (!isDirectDispatch()) return;
    const version = ++state.dispatchPreviewVersion;
    const lines = [...elements.dispatchLines.querySelectorAll('.dispatch-line')]
        .map((row) => ({ itemId: row.querySelector('[name="item_material_id"]').value, amount: Number(row.querySelector('[name="cantidad"]').value) }))
        .filter((line) => line.itemId && line.amount > 0);
    if (!lines.length) { elements.dispatchFolioSelection.innerHTML = '<p>Elige ítems y cantidades para distribuir la entrega entre folios.</p>'; return; }
    elements.dispatchFolioSelection.textContent = 'Consultando folios disponibles en orden FIFO…';
    try {
        await Promise.all(lines.map(async (line) => {
            if (state.dispatchFolios.has(line.itemId)) return;
            const folios = [];
            let page = 1;
            let lastPage = 1;
            do {
                const query = new URLSearchParams({ item_material_id: line.itemId, per_page: '100', page: String(page) });
                const response = await api(`/api/materiales/inventario?${query}`);
                folios.push(...response.data.filter((folio) => folio.reservable && Number(folio.cantidad_disponible) > 0));
                lastPage = Number(response.meta?.last_page || 1);
                page += 1;
            } while (page <= lastPage && page <= 10);
            if (page <= lastPage) throw new ApiError('Hay más de 1.000 folios de un mismo ítem. Acota la entrega desde inventario.', 422);
            folios.sort((a, b) => String(a.fecha_ingreso).localeCompare(String(b.fecha_ingreso)) || a.numero_folio.localeCompare(b.numero_folio));
            state.dispatchFolios.set(line.itemId, folios);
        }));
        if (version !== state.dispatchPreviewVersion) return;
        elements.dispatchFolioSelection.innerHTML = lines.map((line) => {
            const item = activeItems().find((candidate) => candidate.id === line.itemId);
            const folios = state.dispatchFolios.get(line.itemId) || [];
            let pending = line.amount;
            return `<section class="dispatch-folio-group"><h3>${escapeHtml(item?.codigo)} · ${escapeHtml(item?.nombre)} · ${quantity(line.amount)} ${escapeHtml(item?.unidad_medida)}</h3>${folios.map((folio) => {
                const suggested = Math.round(Math.min(pending, Number(folio.cantidad_disponible)) * 1000) / 1000;
                pending = Math.max(0, Math.round((pending - suggested) * 1000) / 1000);
                const value = state.dispatchAllocations.has(folio.folio_id) ? state.dispatchAllocations.get(folio.folio_id) : suggested;
                return `<label class="dispatch-folio-row"><span>${escapeHtml(folio.numero_folio)} · ${escapeHtml(folio.camara?.codigo || 'Sin cámara')}/${escapeHtml(folio.posicion?.etiqueta || 'Sin posición')} · disponible ${quantity(folio.cantidad_disponible)}</span><input type="number" data-dispatch-folio="${folio.folio_id}" data-item-id="${line.itemId}" data-fifo="${suggested}" min="0" max="${Number(folio.cantidad_disponible)}" step="0.001" value="${value}"></label>`;
            }).join('') || '<p>Sin folios disponibles.</p>'}${pending > 0 ? `<p class="form-error">Faltan ${quantity(pending)} ${escapeHtml(item?.unidad_medida)} para completar el despacho.</p>` : ''}</section>`;
        }).join('');
        updateDispatchFifoReason();
    } catch (error) {
        if (version === state.dispatchPreviewVersion) elements.dispatchFolioSelection.textContent = error.message;
    }
}
function updateDispatchFifoReason() {
    const exception = [...elements.dispatchFolioSelection.querySelectorAll('[data-dispatch-folio]')]
        .some((input) => Math.abs(Number(input.value || 0) - Number(input.dataset.fifo)) > 0.0001);
    elements.dispatchFifoReasonField.classList.toggle('is-hidden', !exception);
    elements.dispatchFifoReasonField.querySelector('textarea').required = exception;
}
function directDispatchWithdrawals(items) {
    const inputs = [...elements.dispatchFolioSelection.querySelectorAll('[data-dispatch-folio]')];
    if (!inputs.length) throw new ApiError('Espera a que se carguen los folios disponibles.', 422);
    const retiros = inputs.filter((input) => Number(input.value) > 0).map((input) => ({ folio_id: input.dataset.dispatchFolio, cantidad: Number(input.value) }));
    if (retiros.length > 100) throw new ApiError('La entrega admite un máximo de 100 folios.', 422);
    for (const item of items) {
        const chosen = inputs.filter((input) => input.dataset.itemId === item.item_material_id)
            .reduce((sum, input) => sum + Number(input.value || 0), 0);
        if (Math.abs(chosen - item.cantidad) > 0.0001) throw new ApiError('Distribuye toda la cantidad solicitada entre los folios del ítem.', 422);
        if (inputs.some((input) => input.dataset.itemId === item.item_material_id
            && (Number(input.value) < 0 || Number(input.value) > Number(input.max)))) throw new ApiError('Un folio supera su cantidad disponible.', 422);
    }
    if (!elements.dispatchFifoReasonField.classList.contains('is-hidden')
        && elements.dispatchFifoReasonField.querySelector('textarea').value.trim().length < 5) throw new ApiError('Explica la excepción FIFO (mínimo 5 caracteres).', 422);
    return retiros;
}
async function openDispatchTrace(dispatchId) {
    state.traceDispatchId = dispatchId;
    elements.dispatchTraceTitle.textContent = 'Cargando trazabilidad…';
    elements.dispatchTraceBody.textContent = '';
    elements.dispatchReassignForm.classList.add('is-hidden');
    if (!elements.dispatchTraceDialog.open) elements.dispatchTraceDialog.showModal();
    try {
        const { data: dispatch } = await api(`/api/materiales/despachos/${dispatchId}`);
        if (state.traceDispatchId !== dispatchId) return;
        elements.dispatchTraceTitle.textContent = `${dispatch.codigo} · ${dispatch.destino.nombre}`;
        const events = [{ at: dispatch.created_at, label: 'Solicitud creada', detail: dispatch.creado_por?.nombre || 'Oficina' },
            ...(dispatch.asignaciones || []).map((entry) => ({ at: entry.created_at, label: 'Asignación', detail: `${entry.usuario.nombre} · ${entry.motivo} · por ${entry.realizada_por.nombre}` })),
            ...dispatch.items.flatMap((item) => (item.retiros || []).map((entry) => ({ at: entry.retirado_at, label: 'Entrega', detail: `${entry.folio.numero_folio} · ${quantity(entry.cantidad_retirada)} ${item.unidad_medida} · ${entry.usuario?.nombre || 'Usuario'} · ${entry.dispositivo?.codigo || 'Oficina'}${entry.siguio_fifo ? ' · FIFO' : ` · Excepción FIFO: ${entry.motivo_excepcion_fifo || 'sin motivo histórico'}`}` }))),
            ...(dispatch.cancelacion ? [{ at: dispatch.cancelacion.cancelado_at, label: 'Cancelación', detail: dispatch.cancelacion.motivo }] : [])]
            .sort((a, b) => String(a.at).localeCompare(String(b.at)));
        elements.dispatchTraceBody.innerHTML = `<p><strong>${escapeHtml(dispatch.modalidad === 'directo' ? 'Directo desde bodega' : 'Delegado a tablet')}</strong> · ${escapeHtml(statusText(dispatch.estado))} · ${escapeHtml(dispatch.destino.centro_costo)}</p><p>Asignado: ${escapeHtml(dispatch.asignado_a?.nombre || 'Sin asignación individual')}</p>`
            + dispatch.items.map((item) => `<section class="dispatch-trace-item"><h3>${escapeHtml(item.item.codigo)} · ${escapeHtml(item.item.nombre)} · ${quantity(item.cantidad_despachada)}/${quantity(item.cantidad_solicitada)} ${escapeHtml(item.unidad_medida)}</h3>`
                + `<p>Reserva FIFO registrada: ${(item.reservas_fifo || []).map((folio) => `${escapeHtml(folio.numero_folio)} (${quantity(folio.cantidad)}) · ${escapeHtml(statusText(folio.estado))}`).join(' · ') || 'Sin reserva registrada'}</p>`
                + (item.retiros || []).map((entry) => `<p><strong>${escapeHtml(entry.folio.numero_folio)}</strong> · ${quantity(entry.cantidad_retirada)} ${escapeHtml(item.unidad_medida)} · ${escapeHtml(entry.camara?.codigo || 'Sin cámara')}/${escapeHtml(entry.posicion?.etiqueta || 'Sin posición')} · ${escapeHtml(entry.usuario?.nombre || 'Usuario')} · ${escapeHtml(entry.dispositivo?.codigo || 'Oficina')} · ${escapeHtml(dateTime(entry.retirado_at))} · ${entry.siguio_fifo ? 'FIFO' : `<strong>Excepción FIFO: ${escapeHtml(entry.motivo_excepcion_fifo || 'sin motivo histórico')}</strong>`}</p>`).join('') + '</section>').join('')
            + `<h3>Historial</h3><ol>${events.map((entry) => `<li><strong>${escapeHtml(entry.label)}</strong> · ${escapeHtml(dateTime(entry.at))} · ${escapeHtml(entry.detail)}</li>`).join('')}</ol>`;
        const canReassign = dispatch.modalidad === 'delegado' && ['pendiente', 'parcial'].includes(dispatch.estado)
            && state.identity?.puede_gestionar_despachos_materiales === true;
        elements.dispatchReassignForm.classList.toggle('is-hidden', !canReassign);
        elements.dispatchReassignForm.elements.asignado_a_user_id.value = dispatch.asignado_a?.id || '';
    } catch (error) { elements.dispatchTraceBody.textContent = error.message; }
}
function resetItemForm() { elements.itemForm.reset(); elements.itemForm.elements.id.value = ''; elements.itemForm.elements.activo.checked = true; elements.itemCancel.classList.add('is-hidden'); elements.itemError.textContent = ''; }
function resetProviderForm() { elements.providerForm.reset(); elements.providerForm.elements.id.value = ''; elements.providerForm.elements.activo.checked = true; elements.providerCancel.classList.add('is-hidden'); elements.providerError.textContent = ''; renderProviders(); }
function resetDestinationForm() { elements.destinationForm.reset(); elements.destinationForm.elements.id.value = ''; elements.destinationForm.elements.activo.checked = true; elements.destinationCancel.classList.add('is-hidden'); elements.destinationError.textContent = ''; }
function resetImportPreview() { state.importPreview = null; elements.importError.textContent = ''; renderImportPreview(); }

elements.login.addEventListener('submit', async (event) => { event.preventDefault(); elements.loginError.textContent = ''; setBusy(true, 'Validando acceso…'); try { const payload = await api('/api/acceso-oficina', { method: 'POST', body: JSON.stringify(Object.fromEntries(new FormData(elements.login))) }); if (payload.usuario.puede_consultar_despachos_materiales !== true) throw new ApiError('Tu perfil no puede consultar materiales.', 403); persist(payload); showApp(); await loadAll(); } catch (error) { elements.loginError.textContent = error.message; } finally { setBusy(false); } });
elements.providerForm.elements.codigo.addEventListener('input', (event) => { event.target.value = event.target.value.toUpperCase().replace(/[^A-Z0-9._-]/g, ''); });
elements.providerForm.addEventListener('submit', async (event) => {
    event.preventDefault(); elements.providerError.textContent = '';
    const data = Object.fromEntries(new FormData(elements.providerForm));
    const id = data.id; delete data.id;
    data.activo = elements.providerForm.elements.activo.checked;
    data.cliente_ids = [...elements.providerClientOptions.querySelectorAll('input:checked')].map((input) => input.value);
    data.categorias = [...elements.providerCategoryOptions.querySelectorAll('input:checked')].map((input) => ({ cliente_id: input.dataset.clientId, categoria: input.dataset.category }));
    setBusy(true, 'Guardando proveedor…');
    try {
        await api(id ? `/api/administracion/materiales/proveedores/${id}` : '/api/administracion/materiales/proveedores', { method: id ? 'PUT' : 'POST', body: JSON.stringify(data) });
        resetProviderForm(); await loadAll(); toast('Proveedor, clientes y categorías actualizados.');
    } catch (error) { elements.providerError.textContent = error.message; } finally { setBusy(false); }
});
elements.itemForm.addEventListener('submit', async (event) => { event.preventDefault(); elements.itemError.textContent = ''; const data = Object.fromEntries(new FormData(elements.itemForm)); const id = data.id; delete data.id; data.activo = elements.itemForm.elements.activo.checked; setBusy(true, 'Guardando ítem…'); try { await api(id ? `/api/administracion/materiales/items/${id}` : '/api/administracion/materiales/items', { method: id ? 'PUT' : 'POST', body: JSON.stringify(data) }); resetItemForm(); await loadAll(); toast('Ítem guardado correctamente.'); } catch (error) { elements.itemError.textContent = error.message; } finally { setBusy(false); } });
elements.destinationForm.addEventListener('submit', async (event) => { event.preventDefault(); elements.destinationError.textContent = ''; const data = Object.fromEntries(new FormData(elements.destinationForm)); const id = data.id; delete data.id; data.activo = elements.destinationForm.elements.activo.checked; setBusy(true, 'Guardando destino…'); try { await api(id ? `/api/administracion/materiales/destinos/${id}` : '/api/administracion/materiales/destinos', { method: id ? 'PUT' : 'POST', body: JSON.stringify(data) }); resetDestinationForm(); await loadAll(); toast('Destino guardado correctamente.'); } catch (error) { elements.destinationError.textContent = error.message; } finally { setBusy(false); } });
elements.seasonSelector.addEventListener('change', () => { state.selectedSeasonId = elements.seasonSelector.value || null; resetItemForm(); resetProviderForm(); renderAll(); });
elements.providerList.addEventListener('click', (event) => {
    const button = event.target.closest('[data-edit-provider]'); if (!button) return;
    const provider = state.providers.find((candidate) => candidate.id === button.dataset.editProvider); if (!provider) return;
    for (const field of ['id', 'codigo', 'nombre', 'codigo_externo']) elements.providerForm.elements[field].value = provider[field] || '';
    elements.providerForm.elements.activo.checked = provider.activo;
    const clientIds = new Set((provider.clientes || []).map((client) => client.id));
    elements.providerClientOptions.querySelectorAll('input').forEach((input) => { input.checked = clientIds.has(input.value); });
    const categoryKeys = new Set((provider.categorias || []).map((entry) => providerCategoryKey(entry.cliente_id, entry.categoria)));
    renderProviderCategories(categoryKeys);
    elements.providerCancel.classList.remove('is-hidden');
});
elements.itemList.addEventListener('click', (event) => { const button = event.target.closest('[data-edit-item]'); if (!button) return; const item = state.items.find((candidate) => candidate.id === button.dataset.editItem); if (!item) return; for (const field of ['id', 'codigo', 'nombre', 'categoria', 'categoria_operacional', 'unidad_medida', 'codigo_externo', 'dias_alerta_vencimiento', 'stock_minimo', 'punto_reorden', 'stock_maximo']) elements.itemForm.elements[field].value = item[field] ?? ''; elements.itemForm.elements.cliente_material_id.value = item.cliente?.id || ''; elements.itemForm.elements.activo.checked = item.activo; elements.itemCancel.classList.remove('is-hidden'); });
elements.itemList.addEventListener('click', (event) => {
    const button = event.target.closest('[data-regularize-item]');
    if (!button || !elements.regularizationDialog) return;
    const item = state.items.find((candidate) => candidate.id === button.dataset.regularizeItem);
    if (!item) return;
    const candidates = state.items.filter((candidate) => candidate.activo
        && candidate.categoria_operacional === 'material_mp'
        && candidate.cliente?.id === item.cliente?.id
        && candidate.unidad_medida === item.unidad_medida);
    elements.regularizationForm.reset();
    elements.regularizationError.textContent = candidates.length ? '' : 'No existe un Material MP activo del mismo cliente y unidad.';
    elements.regularizationContext.textContent = `${item.cliente?.codigo || 'SIN CLIENTE'} · ${item.codigo} · ${item.nombre}`;
    elements.regularizationForm.elements.item_canonico_id.innerHTML = '<option value="">Selecciona el MP canónico</option>'
        + candidates.map((candidate) => `<option value="${candidate.id}">${escapeHtml(candidate.codigo)} · ${escapeHtml(candidate.nombre)} · ${escapeHtml(candidate.unidad_medida)}</option>`).join('');
    elements.regularizationForm.querySelector('[type="submit"]').disabled = candidates.length === 0;
    state.regularizationItemId = item.id;
    state.regularizationOperationId = operationUuid();
    elements.regularizationDialog.showModal();
});
elements.regularizationForm?.addEventListener('submit', async (event) => {
    event.preventDefault();
    elements.regularizationError.textContent = '';
    const data = Object.fromEntries(new FormData(elements.regularizationForm));
    const submit = elements.regularizationForm.querySelector('[type="submit"]');
    submit.disabled = true;
    try {
        const response = await api(`/api/administracion/materiales/items/${state.regularizationItemId}/regularizar`, {
            method: 'POST',
            body: JSON.stringify({ ...data, operacion_id: state.regularizationOperationId }),
        });
        elements.regularizationDialog.close();
        await loadAll();
        const count = response.data.recetas_versionadas?.length || 0;
        toast(`Ítems consolidados correctamente${count ? `; ${count} receta(s) versionada(s)` : ''}.`);
    } catch (error) {
        elements.regularizationError.textContent = error.message;
        submit.disabled = false;
    }
});
[elements.regularizationClose, elements.regularizationCancel].filter(Boolean).forEach((button) =>
    button.addEventListener('click', () => elements.regularizationDialog.close()));
elements.destinationList.addEventListener('click', (event) => { const button = event.target.closest('[data-edit-destination]'); if (!button) return; const destination = state.destinations.find((candidate) => candidate.id === button.dataset.editDestination); if (!destination) return; for (const field of ['id', 'nombre', 'centro_costo', 'descripcion', 'codigo_externo']) elements.destinationForm.elements[field].value = destination[field] || ''; elements.destinationForm.elements.activo.checked = destination.activo; elements.destinationCancel.classList.remove('is-hidden'); });
elements.providerClientOptions.addEventListener('change', () => renderProviderCategories());
elements.providerCancel.addEventListener('click', resetProviderForm); elements.itemCancel.addEventListener('click', resetItemForm); elements.destinationCancel.addEventListener('click', resetDestinationForm);
elements.importOpen.addEventListener('click', () => elements.importDialog.showModal());
elements.importClose.addEventListener('click', () => elements.importDialog.close());
elements.importTemplate.addEventListener('click', () => {
    const content = '\uFEFFtemporada_codigo;cliente_codigo;codigo;nombre;categoria;tipo_item;unidad_medida;codigo_externo;activo;stock_minimo;punto_reorden;stock_maximo\n2026-2027;AG-001;CAJ-5KG;Caja cartón 5 kg;Cajas;material_mp;unidad;ERP-1054;si;100;200;500\n';
    const url = URL.createObjectURL(new Blob([content], { type: 'text/csv;charset=utf-8' }));
    const link = document.createElement('a'); link.href = url; link.download = 'plantilla_catalogo_materiales.csv'; link.click();
    URL.revokeObjectURL(url);
});
elements.importForm.addEventListener('submit', async (event) => {
    event.preventDefault(); resetImportPreview(); setBusy(true, 'Revisando planilla de materiales…');
    try {
        const response = await api('/api/administracion/materiales/importaciones/previsualizar', { method: 'POST', body: new FormData(elements.importForm) });
        state.importPreview = response.data; renderImportPreview();
        const history = await api('/api/administracion/materiales/importaciones'); state.imports = history.data; renderImportHistory();
    } catch (error) { elements.importError.textContent = error.message; } finally { setBusy(false); }
});
elements.importConfirm.addEventListener('click', async () => {
    if (!state.importPreview || state.importPreview.estado !== 'borrador') return;
    setBusy(true, 'Confirmando catálogo de materiales…');
    try {
        const response = await api(`/api/administracion/materiales/importaciones/${state.importPreview.id}/confirmar`, { method: 'POST' });
        state.importPreview = response.data; await loadAll(); renderImportPreview(); toast('Catálogo de materiales importado correctamente.');
    } catch (error) { elements.importError.textContent = error.message; } finally { setBusy(false); }
});
elements.addDispatchLine.addEventListener('click', () => { state.dispatchOperationId = null; addDispatchLine(); void refreshDispatchFolioSelection(); });
elements.dispatchLines.addEventListener('click', (event) => {
    const remove = event.target.closest('[data-remove-line]');
    if (remove && elements.dispatchLines.children.length > 1) {
        state.dispatchOperationId = null; remove.closest('.dispatch-line').remove(); state.dispatchAllocations.clear(); void refreshDispatchFolioSelection(); return;
    }
    const option = event.target.closest('[data-select-item]');
    if (!option) return;
    const row = option.closest('.dispatch-line');
    const item = activeItems().find((candidate) => candidate.id === option.dataset.selectItem);
    if (!item) return;
    row.querySelector('[name="item_material_id"]').value = item.id;
    row.querySelector('.material-item-search').value = itemLabel(item);
    closeItemResults(); updateDispatchLine(row); state.dispatchOperationId = null;
    row.querySelector('[name="cantidad"]').focus();
    state.dispatchAllocations.clear(); void refreshDispatchFolioSelection();
});
elements.dispatchLines.addEventListener('focusin', (event) => {
    const input = event.target.closest('.material-item-search');
    if (!input) return;
    const results = input.closest('.material-item-picker').querySelector('.material-item-results');
    closeItemResults(results); renderItemResults(input.closest('.dispatch-line'));
});
elements.dispatchLines.addEventListener('input', (event) => {
    const row = event.target.closest('.dispatch-line');
    if (!row) return;
    if (event.target.matches('.material-item-search')) {
        row.querySelector('[name="item_material_id"]').value = '';
        row.querySelector('[name="cantidad"]').value = '';
        updateDispatchLine(row); renderItemResults(row);
    } else if (event.target.matches('[name="cantidad"]')) {
        updateDispatchLine(row);
        state.dispatchAllocations.clear(); void refreshDispatchFolioSelection();
    }
});
elements.dispatchLines.addEventListener('keydown', (event) => {
    if (!event.target.matches('.material-item-search')) return;
    if (event.key === 'Escape') { closeItemResults(); event.target.blur(); }
    if (event.key === 'Enter') {
        const first = event.target.closest('.material-item-picker').querySelector('[data-select-item]:not(:disabled)');
        if (first) { event.preventDefault(); first.click(); }
    }
});
document.addEventListener('click', (event) => { if (!event.target.closest('.material-item-picker')) closeItemResults(); });
elements.dispatchForm.addEventListener('change', (event) => {
    if (event.target.matches('[name="modalidad"]')) { state.dispatchAllocations.clear(); toggleDispatchMode(); }
});
elements.dispatchFolioSelection.addEventListener('input', (event) => {
    if (!event.target.matches('[data-dispatch-folio]')) return;
    state.dispatchAllocations.set(event.target.dataset.dispatchFolio, Number(event.target.value || 0));
    state.dispatchOperationId = null;
    updateDispatchFifoReason();
});
elements.dispatchForm.addEventListener('submit', async (event) => {
    event.preventDefault(); elements.dispatchError.textContent = '';
    try {
        await refreshOperationalData({ required: true });
    } catch (error) {
        elements.dispatchError.textContent = error.message;
        return;
    }
    let items;
    try { items = dispatchItemsPayload(); } catch (error) { elements.dispatchError.textContent = error.message; return; }
    const form = new FormData(elements.dispatchForm);
    state.dispatchOperationId ||= operationUuid();
    const direct = isDirectDispatch();
    const payload = { operacion_id: state.dispatchOperationId, destino_material_id: form.get('destino_material_id'), observacion: form.get('observacion') };
    try {
        if (direct) {
            payload.retiros = directDispatchWithdrawals(items);
            if (!elements.dispatchFifoReasonField.classList.contains('is-hidden')) payload.motivo_excepcion_fifo = String(form.get('motivo_excepcion_fifo')).trim();
        } else {
            payload.items = items;
            if (form.get('asignado_a_user_id')) payload.asignado_a_user_id = Number(form.get('asignado_a_user_id'));
        }
    } catch (error) { elements.dispatchError.textContent = error.message; return; }
    setBusy(true, direct ? 'Entregando desde bodega…' : 'Creando despacho y reservando existencia…');
    try {
        const response = await api(direct ? '/api/materiales/despachos/directos' : '/api/materiales/despachos', { method: 'POST', body: JSON.stringify(payload) });
        state.dispatchOperationId = null; state.dispatchFolios.clear(); state.dispatchAllocations.clear(); elements.dispatchForm.reset(); elements.dispatchLines.innerHTML = ''; addDispatchLine(); toggleDispatchMode();
        await loadAll(); toast(`${response.data.codigo} ${direct ? 'entregado' : 'creado'} correctamente.`);
    } catch (error) { elements.dispatchError.textContent = error.message; } finally { setBusy(false); }
});
elements.dispatchForm.addEventListener('input', () => { state.dispatchOperationId = null; });
elements.dispatchList.addEventListener('click', async (event) => {
    const trace = event.target.closest('[data-trace-dispatch]');
    if (trace) { await openDispatchTrace(trace.dataset.traceDispatch); return; }
    const button = event.target.closest('[data-cancel-dispatch]');
    if (!button) return;
    const dispatchId = button.dataset.cancelDispatch;
    const previous = state.cancellationOperations.get(dispatchId);
    const reason = window.prompt(
        'Indica el motivo de la cancelación. Se liberarán las cantidades reservadas:',
        previous?.reason || '',
    );
    if (reason === null) return;
    const normalizedReason = reason.trim();
    if (normalizedReason.length < 3) { toast('El motivo debe contener al menos 3 caracteres.', true); return; }
    const operation = previous?.reason === normalizedReason
        ? previous
        : { id: operationUuid(), reason: normalizedReason };
    state.cancellationOperations.set(dispatchId, operation);
    setBusy(true, 'Cancelando despacho…');
    try {
        await api(`/api/materiales/despachos/${dispatchId}/cancelar`, {
            method: 'POST',
            body: JSON.stringify({ operacion_id: operation.id, motivo: operation.reason }),
        });
        state.cancellationOperations.delete(dispatchId);
        await loadAll();
        toast('Despacho cancelado y reservas liberadas.');
    } catch (error) {
        toast(error.message, true);
    } finally {
        setBusy(false);
    }
});
elements.dispatchTraceClose.addEventListener('click', () => { state.traceDispatchId = null; elements.dispatchTraceDialog.close(); });
elements.dispatchReassignForm.addEventListener('input', () => { state.reassignOperationId = null; });
elements.dispatchReassignForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    elements.dispatchReassignError.textContent = '';
    const form = new FormData(elements.dispatchReassignForm);
    const assigned = Number(form.get('asignado_a_user_id'));
    const reason = String(form.get('motivo') || '').trim();
    if (!assigned || reason.length < 5) { elements.dispatchReassignError.textContent = 'Elige un camarero e indica el motivo (mínimo 5 caracteres).'; return; }
    const operationId = state.reassignOperationId ||= operationUuid();
    setBusy(true, 'Reasignando despacho…');
    try {
        await api(`/api/materiales/despachos/${state.traceDispatchId}/reasignar`, {
            method: 'POST', body: JSON.stringify({ operacion_id: operationId, asignado_a_user_id: assigned, motivo: reason }),
        });
        state.reassignOperationId = null;
        elements.dispatchReassignForm.reset();
        await openDispatchTrace(state.traceDispatchId);
        await loadAll();
        toast('Reasignación registrada en el historial.');
    } catch (error) { elements.dispatchReassignError.textContent = error.message; } finally { setBusy(false); }
});
elements.inventorySearch.addEventListener('input', () => {
    window.clearTimeout(inventorySearchTimer);
    inventorySearchTimer = window.setTimeout(() => {
        loadInventoryPage(1).catch((error) => toast(error.message, true));
    }, 300);
});
elements.inventoryClient.addEventListener('change', () => {
    loadInventoryPage(1).catch((error) => toast(error.message, true));
});
elements.inventoryPageSize.addEventListener('change', () => {
    loadInventoryPage(1).catch((error) => toast(error.message, true));
});
elements.inventoryPrevious.addEventListener('click', () => {
    loadInventoryPage(state.inventoryCurrentPage - 1).catch((error) => toast(error.message, true));
});
elements.inventoryNext.addEventListener('click', () => {
    loadInventoryPage(state.inventoryCurrentPage + 1).catch((error) => toast(error.message, true));
});
function closeDirectDispatchDialog() {
    state.directDispatchOperationId = null;
    state.directDispatchFolioId = null;
    elements.directDispatchDialog.close();
}
elements.directDispatchClose.addEventListener('click', closeDirectDispatchDialog);
elements.directDispatchCancel.addEventListener('click', closeDirectDispatchDialog);
elements.directDispatchForm.addEventListener('input', () => {
    state.directDispatchOperationId = null;
});
elements.directDispatchForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    elements.directDispatchError.textContent = '';
    const payload = Object.fromEntries(new FormData(elements.directDispatchForm));
    payload.operacion_id = state.directDispatchOperationId ||= operationUuid();
    if (!payload.observacion) delete payload.observacion;
    setBusy(true, 'Despachando material al centro de costo…');
    try {
        const response = await api('/api/materiales/despachos/directos', {
            method: 'POST',
            body: JSON.stringify(payload),
        });
        closeDirectDispatchDialog();
        await loadAll();
        toast(`${response.data.codigo} completado. La custodia fue trasladada al centro de costo.`);
    } catch (error) {
        elements.directDispatchError.textContent = error.message;
    } finally {
        setBusy(false);
    }
});
function closeDirectConsumptionDialog() {
    state.directConsumptionOperationId = null;
    state.directConsumptionFolioId = null;
    elements.directConsumptionDialog.close();
}
elements.directConsumptionClose.addEventListener('click', closeDirectConsumptionDialog);
elements.directConsumptionCancel.addEventListener('click', closeDirectConsumptionDialog);
elements.directConsumptionForm.addEventListener('input', () => {
    state.directConsumptionOperationId = null;
});
elements.directConsumptionForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    elements.directConsumptionError.textContent = '';
    const payload = Object.fromEntries(new FormData(elements.directConsumptionForm));
    const folio = state.inventory.find((candidate) => candidate.folio_id === payload.folio_id);
    const center = directConsumptionCenters().find(
        (candidate) => candidate.id === payload.centro_costo_id,
    );
    if (!folio || !center) return;
    const confirmed = window.confirm(
        `¿Confirmas consumir ${quantity(payload.cantidad)} ${folio.unidad_medida} de ${folio.numero_folio} e imputarlo a ${center.nombre}?`,
    );
    if (!confirmed) return;
    payload.operacion_id = state.directConsumptionOperationId ||= operationUuid();
    for (const key of ['documento_relacionado', 'motivo_excepcion_fifo']) {
        if (!payload[key]) delete payload[key];
    }
    setBusy(true, 'Registrando consumo directo…');
    try {
        await api('/api/materiales/almacenes/movimientos', {
            method: 'POST',
            body: JSON.stringify(payload),
        });
        closeDirectConsumptionDialog();
        await loadAll();
        toast(`Consumo registrado para ${center.nombre}; Bodega Central fue descontada.`);
    } catch (error) {
        elements.directConsumptionError.textContent = error.message;
    } finally {
        setBusy(false);
    }
});
elements.inventoryBody.addEventListener('click', async (event) => {
    const consumptionButton = event.target.closest('[data-direct-consumption]');
    if (consumptionButton) {
        const folio = state.inventory.find(
            (candidate) => candidate.folio_id === consumptionButton.dataset.directConsumption,
        );
        if (!folio
            || folio.categoria_operacional !== 'insumo'
            || folio.almacen?.codigo !== 'BOD-CENTRAL'
            || folio.reservable !== true
            || Number(folio.cantidad_disponible || 0) <= 0) return;
        const centers = directConsumptionCenters();
        if (!centers.length) {
            toast('No existen centros de costo virtuales activos para imputar el consumo.', true);
            return;
        }
        state.directConsumptionFolioId = folio.folio_id;
        state.directConsumptionOperationId = operationUuid();
        elements.directConsumptionForm.reset();
        elements.directConsumptionForm.elements.folio_id.value = folio.folio_id;
        elements.directConsumptionForm.elements.almacen_origen_id.value = folio.almacen.id;
        elements.directConsumptionForm.elements.centro_costo_id.innerHTML = '<option value="">Selecciona un centro de costo</option>'
            + centers.map((center) => `<option value="${center.id}">${escapeHtml(center.nombre)} · ${escapeHtml(center.centro_costo)}</option>`).join('');
        elements.directConsumptionForm.elements.cantidad.max = String(folio.cantidad_disponible);
        elements.directConsumptionForm.elements.cantidad.value = String(folio.cantidad_disponible);
        elements.directConsumptionContext.textContent = `${folio.numero_folio} · ${folio.item.codigo} · ${folio.item.nombre} · ${folio.camara?.codigo || 'Bodega Central'} / ${folio.posicion?.etiqueta || 'Sin posición'} · máximo ${quantity(folio.cantidad_disponible)} ${folio.unidad_medida}`;
        elements.directConsumptionError.textContent = '';
        elements.directConsumptionDialog.showModal();
        return;
    }

    const directButton = event.target.closest('[data-direct-dispatch]');
    if (directButton) {
        const folio = state.inventory.find(
            (candidate) => candidate.folio_id === directButton.dataset.directDispatch,
        );
        if (!folio || !folio.camara || Number(folio.cantidad_disponible || 0) <= 0) return;
        const destinations = directDispatchDestinations();
        if (!destinations.length) {
            toast('No existen centros de costo activos para realizar el despacho.', true);
            return;
        }
        state.directDispatchFolioId = folio.folio_id;
        state.directDispatchOperationId = operationUuid();
        elements.directDispatchForm.reset();
        elements.directDispatchForm.elements.folio_id.value = folio.folio_id;
        elements.directDispatchForm.elements.destino_material_id.innerHTML = '<option value="">Selecciona un centro de costo</option>'
            + destinations.map((destination) => `<option value="${destination.id}">${escapeHtml(destination.nombre)} · ${escapeHtml(destination.centro_costo)}</option>`).join('');
        elements.directDispatchForm.elements.cantidad.max = String(folio.cantidad_disponible);
        elements.directDispatchForm.elements.cantidad.value = String(folio.cantidad_disponible);
        elements.directDispatchContext.textContent = `${folio.numero_folio} · ${folio.item.codigo} · ${folio.item.nombre} · ${folio.camara.codigo} / ${folio.posicion?.etiqueta || 'Sin posición'} · máximo ${quantity(folio.cantidad_disponible)} ${folio.unidad_medida}`;
        elements.directDispatchError.textContent = '';
        elements.directDispatchDialog.showModal();
        return;
    }

    const blockButton = event.target.closest('[data-block-material], [data-release-material]');
    if (blockButton && state.identity?.puede_gestionar_bloqueos_materiales === true) {
        const releasing = Boolean(blockButton.dataset.releaseMaterial);
        const folioId = blockButton.dataset.releaseMaterial || blockButton.dataset.blockMaterial;
        const folio = state.inventory.find((candidate) => candidate.folio_id === folioId);
        if (!folio) return;
        const key = `${releasing ? 'liberar' : 'bloquear'}:${folioId}`;
        const previous = state.blockOperations.get(key);
        const reason = window.prompt(
            releasing
                ? `Indica por qué se libera ${folio.numero_folio}:`
                : `Indica por qué se bloquea ${folio.numero_folio}:`,
            previous?.reason || '',
        );
        if (reason === null) return;
        const normalizedReason = reason.trim();
        if (normalizedReason.length < 5) {
            toast('El motivo debe contener al menos 5 caracteres.', true);
            return;
        }
        const operation = previous?.reason === normalizedReason
            ? previous
            : { id: operationUuid(), reason: normalizedReason };
        state.blockOperations.set(key, operation);
        setBusy(true, releasing ? 'Liberando material…' : 'Bloqueando material…');
        try {
            await api(`/api/materiales/inventario/${folioId}/${releasing ? 'liberar-bloqueo' : 'bloquear'}`, {
                method: 'POST',
                body: JSON.stringify({ operacion_id: operation.id, motivo: operation.reason }),
            });
            state.blockOperations.delete(key);
            await loadAll();
            toast(releasing ? 'Material liberado y disponible según su ubicación.' : 'Material bloqueado y retirado de la disponibilidad.');
        } catch (error) {
            toast(error.message, true);
        } finally {
            setBusy(false);
        }
        return;
    }

    const button = event.target.closest('[data-correct-material]');
    if (!button || state.identity?.puede_corregir_items_estibados_materiales !== true) return;
    const folio = state.inventory.find((candidate) => candidate.folio_id === button.dataset.correctMaterial);
    if (!folio) return;
    const alternatives = activeItems().filter((item) => item.id !== folio.item.id
        && item.cliente.id === folio.item.cliente.id
        && item.unidad_medida === folio.unidad_medida);
    if (!alternatives.length) { toast('No existen otros ítems activos compatibles para este cliente y unidad.', true); return; }
    elements.correctionForm.reset();
    elements.correctionForm.elements.folio_id.value = folio.folio_id;
    elements.correctionForm.elements.item_material_id.innerHTML = '<option value="">Selecciona el código correcto</option>' + alternatives.map((item) => `<option value="${item.id}">${escapeHtml(item.cliente.nombre)} · ${escapeHtml(item.codigo)} · ${escapeHtml(item.nombre)}</option>`).join('');
    elements.correctionContext.textContent = `${folio.numero_folio} · ${folio.item.cliente.nombre} · código actual ${folio.item.codigo}`;
    elements.correctionError.textContent = '';
    state.correctionOperationId = operationUuid();
    elements.correctionDialog.showModal();
});
function closeCorrectionDialog() { state.correctionOperationId = null; elements.correctionDialog.close(); }
elements.correctionClose.addEventListener('click', closeCorrectionDialog);
elements.correctionCancel.addEventListener('click', closeCorrectionDialog);
elements.correctionForm.addEventListener('submit', async (event) => {
    event.preventDefault(); elements.correctionError.textContent = '';
    const form = Object.fromEntries(new FormData(elements.correctionForm));
    setBusy(true, 'Corrigiendo ítem y registrando kardex…');
    try {
        await api(`/api/materiales/inventario/${form.folio_id}/corregir-item`, {
            method: 'POST',
            body: JSON.stringify({ operacion_id: state.correctionOperationId, item_material_id: form.item_material_id, motivo: form.motivo }),
        });
        closeCorrectionDialog(); await loadAll(); toast('Ítem corregido y auditado correctamente.');
    } catch (error) { elements.correctionError.textContent = error.message; } finally { setBusy(false); }
});
elements.reload.addEventListener('click', async () => {
    if (!mainDataIsRequired()) return;
    setBusy(true, 'Actualizando materiales…');
    try { await loadAll(); toast('Información actualizada.'); } catch (error) { toast(error.message, true); } finally { setBusy(false); }
});
window.addEventListener('estiba:materials-updated', () => void refreshOperationalData());
elements.logout.addEventListener('click', async () => { try { await api('/api/acceso-oficina', { method: 'DELETE' }); } finally { clearSession(); } });

const expiryState = { estado: 'por_vencer', page: 1, lastPage: 1, rows: [], sequence: 0, correctionId: null };
function expiryBadge(info) {
    return info?.etiqueta ? `<span class="material-expiry-badge${info.estado === 'vencido' ? ' material-expiry-badge--expired' : ''}">${escapeHtml(info.etiqueta)}</span>` : '';
}
function expiryQuery() {
    const query = new URLSearchParams({ estado: expiryState.estado, page: String(expiryState.page) });
    for (const [key, id] of [['cliente_id', 'materialExpiryClient'], ['categoria', 'materialExpiryCategory'], ['almacen_id', 'materialExpiryWarehouse']]) {
        const value = byId(id)?.value;
        if (value) query.set(key, value);
    }
    return query;
}
async function loadExpiryPage() {
    const sequence = ++expiryState.sequence;
    const response = await api(`/api/materiales/vencimientos?${expiryQuery()}`);
    if (sequence !== expiryState.sequence) return;
    expiryState.rows = response.data;
    expiryState.lastPage = response.meta.last_page;
    for (const [id, values, categorical] of [['materialExpiryClient', response.filtros.clientes], ['materialExpiryCategory', response.filtros.categorias, true], ['materialExpiryWarehouse', response.filtros.almacenes]]) {
        const select = byId(id); const previous = select.value;
        select.innerHTML = '<option value="">Todos</option>' + values.map((value) => `<option value="${escapeHtml(categorical ? value : value.id)}">${escapeHtml(categorical ? value : value.nombre)}</option>`).join('');
        select.value = previous;
    }
    byId('materialExpirySummary').textContent = ['por_vencer', 'vencido'].map((key) => `${key === 'vencido' ? 'Vencidos' : 'Por vencer'}: ${response.resumen[key].folios} folios · ${response.resumen[key].cantidades.map((unit) => `${quantity(unit.cantidad)} ${unit.unidad_medida}`).join(' · ') || 'Sin stock'}`).join(' / ');
    byId('materialExpiryBody').innerHTML = response.data.map((row) => `<tr><td><strong>${escapeHtml(row.numero_folio)}</strong></td><td>${escapeHtml(row.cliente)}<br>${escapeHtml(row.codigo_item)} · ${escapeHtml(row.item)}</td><td>${escapeHtml(row.vencimiento.fecha?.split('-').reverse().join('-'))}</td><td>${expiryBadge(row.vencimiento)}<small>${escapeHtml(row.motivo_bloqueo || '')}</small></td><td>${quantity(row.cantidad)} ${escapeHtml(row.unidad_medida)}</td><td>${escapeHtml(row.almacen)}<br>${escapeHtml(row.camara || 'Sin cámara')} · ${escapeHtml(row.posicion || 'Sin posición')}</td><td>${state.identity?.puede_gestionar_bloqueos_materiales ? `<button type="button" data-correct-expiry="${row.folio_id}">Corregir vencimiento</button>` : '—'}</td></tr>`).join('') || '<tr><td colspan="7">Sin folios para estos filtros.</td></tr>';
    byId('materialExpiryPage').textContent = `${response.meta.current_page} / ${response.meta.last_page} · ${response.meta.total} saldos`;
    byId('materialExpiryPrevious').disabled = expiryState.page <= 1;
    byId('materialExpiryNext').disabled = expiryState.page >= expiryState.lastPage;
}
async function refreshExpiry() {
    byId('materialExpiryError').textContent = '';
    try { await loadExpiryPage(); } catch (error) { byId('materialExpiryError').textContent = error.message; }
}
for (const id of ['materialExpiryClient', 'materialExpiryCategory', 'materialExpiryWarehouse']) {
    byId(id)?.addEventListener('change', () => { expiryState.page = 1; void refreshExpiry(); });
}
document.querySelectorAll('[data-expiry-tab]').forEach((button) => button.addEventListener('click', () => {
    expiryState.estado = button.dataset.expiryTab; expiryState.page = 1;
    document.querySelectorAll('[data-expiry-tab]').forEach((tab) => {
        const active = tab === button;
        tab.setAttribute('aria-pressed', String(active));
        tab.classList.toggle('primary-button', active); tab.classList.toggle('secondary-button', !active);
    });
    void refreshExpiry();
}));
byId('materialExpiryPrevious')?.addEventListener('click', () => { expiryState.page = Math.max(1, expiryState.page - 1); void refreshExpiry(); });
byId('materialExpiryNext')?.addEventListener('click', () => { expiryState.page = Math.min(expiryState.lastPage, expiryState.page + 1); void refreshExpiry(); });
byId('exportMaterialExpiry')?.addEventListener('click', async () => {
    const button = byId('exportMaterialExpiry'); button.disabled = true;
    try {
        const response = await fetch(`/api/materiales/vencimientos/exportar?${expiryQuery()}`, { headers: { Authorization: `Bearer ${state.token}` } });
        if (!response.ok) throw new Error(errorMessage(await response.json(), 'No se pudo exportar.'));
        const url = URL.createObjectURL(await response.blob());
        const link = document.createElement('a'); link.href = url; link.download = 'vencimientos_materiales.xlsx'; link.click(); URL.revokeObjectURL(url);
    } catch (error) { byId('materialExpiryError').textContent = error.message; }
    finally { button.disabled = false; }
});
document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-correct-expiry]');
    if (!button || state.identity?.puede_gestionar_bloqueos_materiales !== true) return;
    const row = [...state.inventory, ...expiryState.rows].find((folio) => folio.folio_id === button.dataset.correctExpiry);
    if (!row) return;
    const form = byId('materialExpiryCorrectionForm'); form.reset();
    form.elements.folio_id.value = row.folio_id; form.elements.fecha_vencimiento.value = row.vencimiento?.fecha || '';
    expiryState.correctionId = operationUuid(); byId('materialExpiryCorrectionError').textContent = '';
    byId('materialExpiryCorrectionContext').textContent = row.numero_folio;
    byId('materialExpiryCorrectionDialog').showModal();
});
for (const id of ['closeMaterialExpiryCorrection', 'cancelMaterialExpiryCorrection']) byId(id)?.addEventListener('click', () => byId('materialExpiryCorrectionDialog').close());
byId('materialExpiryCorrectionForm')?.addEventListener('submit', async (event) => {
    event.preventDefault(); const form = event.currentTarget; const button = form.querySelector('[type="submit"]'); button.disabled = true;
    try {
        const data = Object.fromEntries(new FormData(form));
        await api(`/api/materiales/inventario/${data.folio_id}/corregir-vencimiento`, { method: 'POST', body: JSON.stringify({ ...data, operacion_id: expiryState.correctionId }) });
        byId('materialExpiryCorrectionDialog').close(); await loadAll(); toast('Vencimiento corregido y auditado.');
    } catch (error) { byId('materialExpiryCorrectionError').textContent = error.message; }
    finally { button.disabled = false; }
});

async function boot() {
    if (activeMaterialsSection() === 'despachos') addDispatchLine();
    if (!state.token || state.identity?.puede_consultar_despachos_materiales !== true) return;
    showApp();
    if (!mainDataIsRequired()) return;
    setBusy(true, 'Cargando materiales…');
    try { await loadAll(); } catch (error) { if (error.status !== 401) toast(error.message, true); } finally { setBusy(false); }
}
if (operationalDataIsRequired()) {
    state.operationalPoller = createOperationalPoller(
        refreshOperationalData,
        {
            intervalMs: operationalRefreshIntervalMs,
            canRun: () => Boolean(state.token) && operationalDataIsRequired(),
        },
    );
    state.operationalPoller.start();
}
void boot();

elements.itemList.addEventListener('click', (e) => {
    const id = e.target.closest('[data-item-photos]')?.dataset.itemPhotos;
    if (id) void openItemPhotoPanel(state.items.find((item) => item.id === id), loadAll);
});
byId('downloadFullMaterialCatalog').addEventListener('click', async () => {
    const button = byId('downloadFullMaterialCatalog'); button.disabled = true;
    try {
        const query = new URLSearchParams(); if (state.selectedSeasonId) query.set('temporada_id', state.selectedSeasonId);
        const r = await fetch(`/api/materiales/items/catalogo/exportar/xlsx?${query}`, { headers: { Authorization: `Bearer ${state.token}` } });
        if (!r.ok) throw new Error('No se pudo descargar el catálogo.');
        const url = URL.createObjectURL(await r.blob()); const link = document.createElement('a'); link.href = url; link.download = 'catalogo-items-materiales.xlsx'; link.click(); URL.revokeObjectURL(url);
    } catch (e) { toast(e.message, true); } finally { button.disabled = false; }
});
