const byId = (id) => document.getElementById(id);
const elements = {
    access: byId('officeAccess'), app: byId('officeApp'), login: byId('officeLoginForm'), loginError: byId('officeLoginError'),
    userName: byId('officeUserName'), userRole: byId('officeUserRole'), initials: byId('officeInitials'), logout: byId('officeLogoutButton'),
    camerasNav: byId('officeCamerasNav'), loadsNav: byId('officeLoadsNav'), materialsNav: byId('officeMaterialsNav'), prefrioNav: byId('officePrefrioNav'), accessesNav: byId('officeAccessesNav'), managementNav: byId('officeManagementNav'), romanaNav: byId('officeRomanaNav'),
    reload: byId('reloadValidationButton'), seasonSelector: byId('seasonSelector'), filters: byId('validationFilters'), history: byId('validationHistoryBody'), userFilter: byId('validationUserFilter'), blankRegister: byId('downloadBlankValidationRegisterButton'), exportRegister: byId('exportValidationRegisterButton'),
    catalogVersion: byId('catalogVersion'), articleCount: byId('activeArticleCount'), originCount: byId('activeOriginCount'), combinationCount: byId('activeCombinationCount'), observedCount: byId('observedCount'),
    correctionDialog: byId('validationCorrectionDialog'), correctionForm: byId('validationCorrectionForm'), correctionError: byId('validationCorrectionError'), correctionCancel: byId('cancelValidationCorrection'), correctionTitle: byId('validationCorrectionTitle'), correctionState: byId('validationCorrectionState'),
    loading: byId('officeLoading'), loadingText: byId('officeLoadingText'), toasts: byId('officeToasts'),
};
const keys = { token: 'estiba_wms_office_token', identity: 'estiba_wms_office_identity' };
const state = {
    token: localStorage.getItem(keys.token), identity: readJson(keys.identity), seasons: [], season: null,
    filterSeasons: [], filterSeason: null, validators: [], articles: [], origins: [], categories: [], combinations: [], history: [],
    correctionTarget: null, correctionOperationId: null, summary: null, catalogCache: null,
};

class ApiError extends Error {
    constructor(message, status, data = {}) { super(message); this.name = 'ApiError'; this.status = status; this.data = data; }
}
function readJson(key) { try { return JSON.parse(localStorage.getItem(key) || 'null'); } catch { return null; } }
function escapeHtml(value) { return String(value ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&#039;'); }
function statusText(value) { return String(value || '').replaceAll('_', ' ').replace(/^./, (letter) => letter.toUpperCase()); }
function errorMessage(data, fallback) { return Object.values(data?.errors || {}).flat()[0] || data?.message || fallback; }
function formatDate(value, fallback = 'Sin fecha') { if (!value) return fallback; const date = new Date(value); return Number.isNaN(date.getTime()) ? fallback : new Intl.DateTimeFormat('es-CL', { dateStyle: 'short', timeStyle: 'short' }).format(date); }
function setBusy(active, message = 'Procesando…') { elements.loadingText.textContent = message; elements.loading.classList.toggle('is-hidden', !active); elements.loading.setAttribute('aria-hidden', String(!active)); }
function toast(message, error = false) { const node = document.createElement('div'); node.className = `toast${error ? ' toast--error' : ''}`; node.textContent = message; elements.toasts.append(node); window.setTimeout(() => node.remove(), 4500); }
function persist(payload) { state.token = payload.token; state.identity = payload.usuario; localStorage.setItem(keys.token, payload.token); localStorage.setItem(keys.identity, JSON.stringify(payload.usuario)); }
function clearSession() { state.token = null; state.identity = null; localStorage.removeItem(keys.token); localStorage.removeItem(keys.identity); elements.app.classList.add('is-hidden'); elements.access.classList.remove('is-hidden'); }
function operationUuid() { if (typeof crypto.randomUUID === 'function') return crypto.randomUUID(); const bytes = crypto.getRandomValues(new Uint8Array(16)); bytes[6] = (bytes[6] & 0x0f) | 0x40; bytes[8] = (bytes[8] & 0x3f) | 0x80; const hex = [...bytes].map((value) => value.toString(16).padStart(2, '0')).join(''); return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`; }

async function api(path, options = {}) {
    const headers = new Headers(options.headers || {}); headers.set('Accept', 'application/json');
    if (state.token) headers.set('Authorization', `Bearer ${state.token}`);
    if (options.body && !(options.body instanceof FormData)) headers.set('Content-Type', 'application/json');
    let response;
    try { response = await fetch(path, { ...options, headers }); } catch { throw new ApiError('No fue posible conectar con Laravel.', 0); }
    const data = response.status === 204 ? null : await response.json().catch(() => ({}));
    if (!response.ok) { if (response.status === 401) clearSession(); throw new ApiError(errorMessage(data, 'No fue posible completar la operación.'), response.status, data); }
    return data;
}

async function download(path) {
    const headers = new Headers({ Accept: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });
    if (state.token) headers.set('Authorization', `Bearer ${state.token}`);
    let response;
    try { response = await fetch(path, { headers }); } catch { throw new ApiError('No fue posible conectar con Laravel.', 0); }
    if (!response.ok) {
        const data = await response.json().catch(() => ({}));
        if (response.status === 401) clearSession();
        throw new ApiError(errorMessage(data, 'No fue posible generar el registro RRPP-01.'), response.status, data);
    }
    const blob = await response.blob();
    const disposition = response.headers.get('Content-Disposition') || '';
    const match = disposition.match(/filename="?([^";]+)"?/i);
    const filename = match?.[1] || 'RRPP-01.xlsx';
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = url; anchor.download = filename; document.body.append(anchor); anchor.click(); anchor.remove();
    URL.revokeObjectURL(url);
}

function showApp() {
    elements.access.classList.add('is-hidden'); elements.app.classList.remove('is-hidden');
    const name = state.identity?.nombre || 'Usuario'; elements.userName.textContent = name; elements.userRole.textContent = statusText(state.identity?.rol);
    elements.initials.textContent = name.split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0]).join('').toUpperCase();
    elements.accessesNav.classList.toggle('is-hidden', state.identity?.puede_administrar_accesos !== true);
    elements.managementNav.classList.toggle('is-hidden', state.identity?.puede_consultar_panel_gerencial !== true);
    elements.romanaNav.classList.toggle('is-hidden', state.identity?.puede_consultar_romana !== true);
    elements.camerasNav.classList.toggle('is-hidden', state.identity?.ambito_camaras === 'ninguno');
    elements.loadsNav.classList.toggle('is-hidden', state.identity?.puede_consultar_cargas !== true);
    elements.materialsNav.classList.toggle('is-hidden', state.identity?.puede_consultar_despachos_materiales !== true);
    elements.prefrioNav.classList.toggle('is-hidden', state.identity?.puede_consultar_prefrio !== true);
}

async function loadFilterOptions(seasonId = null) {
    const suffix = seasonId ? `?temporada_id=${encodeURIComponent(seasonId)}` : '';
    const response = await api(`/api/validacion/registro/opciones${suffix}`);
    state.filterSeasons = response.temporadas || [];
    state.filterSeason = response.temporada || null;
    state.validators = response.validadores || [];
    renderFilterOptions();
}

function renderFilterOptions() {
    const currentUser = elements.userFilter.value;
    elements.seasonSelector.innerHTML = state.filterSeasons.map((season) => `<option value="${season.id}"${season.id === state.filterSeason?.id ? ' selected' : ''}>${escapeHtml(season.codigo)} · ${escapeHtml(season.nombre)}${season.activa ? ' (activa)' : ''}</option>`).join('') || '<option value="">Sin temporadas</option>';
    elements.userFilter.innerHTML = `<option value="">Todos los encargados</option>${state.validators.map((user) => `<option value="${user.id}">${escapeHtml(user.nombre)}</option>`).join('')}`;
    if ([...elements.userFilter.options].some((option) => option.value === currentUser)) elements.userFilter.value = currentUser;
}

async function loadHistory(seasonId = state.filterSeason?.id || null) {
    const params = historyParams(seasonId);
    const response = await api(`/api/validacion/pallets?${params}`);
    state.history = response.data || [];
    renderHistory();
}

function historyParams(seasonId = state.filterSeason?.id || null) {
    const params = new URLSearchParams();
    const values = Object.fromEntries(new FormData(elements.filters));
    for (const [key, value] of Object.entries(values)) if (String(value).trim()) params.set(key, String(value).trim());
    if (seasonId) params.set('temporada_id', seasonId);
    params.set('per_page', '25');
    return params;
}

async function loadSummary(seasonId = null) {
    const response = await api(`/api/validacion/registro/resumen?${historyParams(seasonId)}`);
    if (state.catalogCache && (state.catalogCache.seasonId !== response.temporada?.id || state.catalogCache.version !== response.temporada?.version_catalogo)) {
        state.catalogCache = null;
        state.articles = []; state.origins = []; state.categories = []; state.combinations = [];
    }
    state.summary = response;
    renderMetrics();
}

async function loadCatalogContext(seasonId = null) {
    if (state.identity?.puede_consultar_catalogos_validacion !== true) return;
    if (state.catalogCache?.seasonId === seasonId && state.catalogCache?.version === state.summary?.temporada?.version_catalogo) return;
    const suffix = seasonId ? `?temporada_id=${encodeURIComponent(seasonId)}` : '';
    const response = await api(`/api/administracion/validacion${suffix}`);
    state.seasons = response.temporadas || []; state.season = response.temporada || null; state.articles = response.articulos || [];
    state.origins = response.origenes || []; state.categories = response.categorias || []; state.combinations = response.combinaciones || [];
    state.catalogCache = { seasonId: state.season?.id, version: state.season?.version_catalogo };
}

async function loadAll(seasonId = null) {
    await Promise.all([loadFilterOptions(seasonId), loadSummary(seasonId), loadHistory(seasonId)]);
}

function renderMetrics() {
    elements.catalogVersion.textContent = state.summary?.temporada?.version_catalogo ?? '—';
    elements.articleCount.textContent = String(state.summary?.articulos_activos ?? 0);
    elements.originCount.textContent = String(state.summary?.origenes_activos ?? 0);
    elements.combinationCount.textContent = String(state.summary?.combinaciones_activas ?? 0);
    elements.observedCount.textContent = String(state.summary?.observados ?? 0);
}

function renderHistory() {
    elements.history.innerHTML = state.history.map((item) => {
        const article = item.catalogo?.articulo || {}; const origin = item.catalogo?.origen || {};
        const resultClass = item.estado === 'conflicto' ? 'conflicto' : item.resultado;
        const category = item.catalogo?.categoria || {};
        const lastCorrection = item.correcciones?.[0];
        const correction = item.puede_corregir === true
            ? `<button class="validation-correction-button" data-correct-validation="${item.id}" type="button">Corregir</button>`
            : '<span class="validation-action-unavailable">No disponible</span>';
        return `<tr><td><strong>${escapeHtml(item.numero_folio)}</strong><small>Intento ${item.numero_intento} · ${escapeHtml(statusText(item.tipo_bulto))}</small></td><td><strong>${escapeHtml(article.especie || 'Sin artículo')} · ${escapeHtml(article.variedad || '')}</strong><small>${escapeHtml(category.nombre || 'Sin categoría')} · ${escapeHtml(article.calibre || '')} · ${escapeHtml(article.envase || '')}</small></td><td><strong>${escapeHtml(origin.cliente || 'Sin origen')}</strong><small>${escapeHtml(origin.marca || '')} · CSG ${escapeHtml(origin.csg || '—')}</small></td><td><span class="validation-result validation-result--${escapeHtml(resultClass)}">${escapeHtml(item.estado === 'conflicto' ? 'Conflicto' : item.resultado)}</span>${item.motivo ? `<small>${escapeHtml(statusText(item.motivo))}</small>` : ''}</td><td><strong>${escapeHtml(item.usuario?.nombre || '—')}</strong><small>${escapeHtml(item.dispositivo?.codigo || '')}</small></td><td>${escapeHtml(formatDate(item.generado_dispositivo_at))}<small>${item.linea_proceso && item.turno ? `Línea ${escapeHtml(item.linea_proceso)} · Turno ${escapeHtml(item.turno)}` : 'Sin jornada histórica'}</small>${lastCorrection ? `<small>Corregido ${escapeHtml(formatDate(lastCorrection.corregido_at))}</small>` : ''}</td><td>${correction}</td></tr>`;
    }).join('') || '<tr><td class="empty-validation" colspan="7">No existen validaciones coincidentes.</td></tr>';
}

function renderCorrectionOrigins(selectedId = '') {
    const articleId = elements.correctionForm.elements.articulo_validacion_id.value;
    const allowed = new Set(state.combinations
        .filter((item) => item.activo && item.articulo_validacion_id === articleId)
        .map((item) => item.origen_validacion_id));
    const origins = state.origins.filter((item) => item.activo && allowed.has(item.id));
    elements.correctionForm.elements.origen_validacion_id.innerHTML = `<option value="">Selecciona un origen autorizado</option>${origins.map((item) => `<option value="${item.id}">${escapeHtml(item.cliente)} · ${escapeHtml(item.marca)} · CSG ${escapeHtml(item.csg)}</option>`).join('')}`;
    if (origins.some((item) => item.id === selectedId)) elements.correctionForm.elements.origen_validacion_id.value = selectedId;
}

function openCorrection(item) {
    state.correctionTarget = item;
    state.correctionOperationId = operationUuid();
    elements.correctionTitle.textContent = `Corregir ${item.numero_folio}`;
    const operationalState = statusText(item.folio?.estado_operacional || 'sin estado');
    const thermalCondition = statusText(item.folio?.condicion_termica || 'sin condición térmica');
    elements.correctionState.textContent = `Estado actual: ${operationalState} · ${thermalCondition}. Estos estados no serán modificados.`;
    const form = elements.correctionForm;
    const activeArticles = state.articles.filter((article) => article.activo);
    const activeCategories = state.categories.filter((category) => category.activo);
    form.elements.articulo_validacion_id.innerHTML = `<option value="">Selecciona un artículo</option>${activeArticles.map((article) => `<option value="${article.id}">${escapeHtml(article.especie)} · ${escapeHtml(article.variedad)} · ${escapeHtml(article.calibre)} · ${escapeHtml(article.envase)}</option>`).join('')}`;
    form.elements.categoria_validacion_id.innerHTML = `<option value="">Selecciona una categoría</option>${activeCategories.map((category) => `<option value="${category.id}">${escapeHtml(category.nombre)}</option>`).join('')}`;
    form.elements.tipo_bulto.value = item.tipo_bulto || 'pallet';
    form.elements.cantidad_cajas.value = item.cantidad_cajas || 1;
    form.elements.linea_proceso.value = item.linea_proceso || 1;
    form.elements.turno.value = item.turno || 'A';
    form.elements.articulo_validacion_id.value = item.articulo_validacion_id || '';
    form.elements.categoria_validacion_id.value = item.categoria_validacion_id || '';
    renderCorrectionOrigins(item.origen_validacion_id || '');
    form.elements.motivo_correccion.value = '';
    elements.correctionError.textContent = '';
    elements.correctionDialog.showModal();
}

function closeCorrection() {
    elements.correctionDialog.close();
    elements.correctionForm.reset();
    elements.correctionError.textContent = '';
    state.correctionTarget = null;
    state.correctionOperationId = null;
}

async function submitCorrection(event) {
    event.preventDefault();
    if (!state.correctionTarget || !state.correctionOperationId) return;
    const submitButton = event.submitter ?? elements.correctionForm.querySelector('button[type="submit"]');
    const payload = Object.fromEntries(new FormData(elements.correctionForm));
    payload.operacion_id = state.correctionOperationId;
    payload.cantidad_cajas = Number(payload.cantidad_cajas);
    payload.linea_proceso = Number(payload.linea_proceso);
    elements.correctionError.textContent = '';
    if (submitButton) submitButton.disabled = true;
    setBusy(true, 'Corrigiendo validación y folio…');
    try {
        const response = await api(`/api/validacion/pallets/${state.correctionTarget.id}/corregir`, {
            method: 'PUT',
            body: JSON.stringify(payload),
        });
        closeCorrection();
        await Promise.all([loadHistory(state.filterSeason?.id), loadSummary(state.filterSeason?.id)]);
        toast(response.message || 'Validación corregida y auditada.');
    } catch (error) {
        elements.correctionError.textContent = error.message;
    } finally {
        if (submitButton) submitButton.disabled = false;
        setBusy(false);
    }
}

elements.login.addEventListener('submit', async (event) => { event.preventDefault(); elements.loginError.textContent = ''; setBusy(true, 'Validando acceso…'); try { const payload = await api('/api/acceso-oficina', { method: 'POST', body: JSON.stringify(Object.fromEntries(new FormData(elements.login))) }); if (payload.usuario.puede_consultar_validaciones_pallet !== true) throw new ApiError('Tu perfil no puede consultar validaciones.', 403); persist(payload); showApp(); await loadAll(); } catch (error) { elements.loginError.textContent = error.message; } finally { setBusy(false); } });
elements.logout.addEventListener('click', async () => { try { await api('/api/acceso-oficina', { method: 'DELETE' }); } catch {} clearSession(); });
elements.reload.addEventListener('click', () => { setBusy(true, 'Actualizando validación…'); void loadAll(state.filterSeason?.id).catch((error) => toast(error.message, true)).finally(() => setBusy(false)); });
elements.filters.addEventListener('submit', (event) => { event.preventDefault(); setBusy(true, 'Consultando historial…'); void Promise.all([loadHistory(state.filterSeason?.id), loadSummary(state.filterSeason?.id)]).catch((error) => toast(error.message, true)).finally(() => setBusy(false)); });
elements.history.addEventListener('click', (event) => { const button = event.target.closest('[data-correct-validation]'); if (!button) return; const item = state.history.find((candidate) => candidate.id === button.dataset.correctValidation); if (!item) return; setBusy(true, 'Cargando catálogo de corrección…'); void loadCatalogContext(state.filterSeason?.id).then(() => openCorrection(item)).catch((error) => toast(error.message, true)).finally(() => setBusy(false)); });
elements.correctionForm.addEventListener('submit', (event) => { void submitCorrection(event); });
elements.correctionForm.elements.articulo_validacion_id.addEventListener('change', () => renderCorrectionOrigins());
elements.correctionCancel.addEventListener('click', closeCorrection);
elements.correctionDialog.addEventListener('cancel', (event) => { event.preventDefault(); closeCorrection(); });
elements.seasonSelector.addEventListener('change', () => { setBusy(true, 'Cambiando temporada…'); void loadAll(elements.seasonSelector.value || null).catch((error) => toast(error.message, true)).finally(() => setBusy(false)); });
elements.blankRegister.addEventListener('click', () => {
    setBusy(true, 'Preparando formulario RRPP-01 en blanco…');
    void download('/api/validacion/registro/rrpp-01/en-blanco')
        .catch((error) => toast(error.message, true))
        .finally(() => setBusy(false));
});
elements.exportRegister.addEventListener('click', () => {
    const values = Object.fromEntries(new FormData(elements.filters));
    if (!values.fecha) { toast('Selecciona la fecha del registro RRPP-01.', true); return; }
    if (!state.filterSeason?.id) { toast('No existe una temporada seleccionada.', true); return; }
    const params = new URLSearchParams({ temporada_id: state.filterSeason.id, fecha: String(values.fecha) });
    for (const key of ['folio', 'resultado', 'linea_proceso', 'turno', 'user_id']) {
        if (String(values[key] || '').trim()) params.set(key, String(values[key]).trim());
    }
    setBusy(true, 'Generando registro RRPP-01…');
    void download(`/api/validacion/registro/rrpp-01?${params}`)
        .catch((error) => toast(error.message, true))
        .finally(() => setBusy(false));
});

if (state.token && state.identity?.puede_consultar_validaciones_pallet === true) { showApp(); setBusy(true, 'Cargando validación…'); void loadAll().catch(() => clearSession()).finally(() => setBusy(false)); }
