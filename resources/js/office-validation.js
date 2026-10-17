import { loadIndependentSections } from './office-validation-loading.js';
import { createValidationCorrection } from './shared/validation-correction.js';
import { validationDayContext, validationJourneyLabel, validationOriginLabel } from './shared/validation-row.js';

const byId = (id) => document.getElementById(id);
const elements = {
    access: byId('officeAccess'), app: byId('officeApp'), login: byId('officeLoginForm'), loginError: byId('officeLoginError'),
    userName: byId('officeUserName'), userRole: byId('officeUserRole'), initials: byId('officeInitials'), logout: byId('officeLogoutButton'),
    camerasNav: byId('officeCamerasNav'), loadsNav: byId('officeLoadsNav'), materialsNav: byId('officeMaterialsNav'), prefrioNav: byId('officePrefrioNav'), accessesNav: byId('officeAccessesNav'), managementNav: byId('officeManagementNav'), romanaNav: byId('officeRomanaNav'),
    reload: byId('reloadValidationButton'), seasonSelector: byId('seasonSelector'), filters: byId('validationFilters'), history: byId('validationHistoryBody'), userFilter: byId('validationUserFilter'), blankRegister: byId('downloadBlankValidationRegisterButton'), exportRegister: byId('exportValidationRegisterButton'),
    catalogVersion: byId('catalogVersion'), articleCount: byId('activeArticleCount'), originCount: byId('activeOriginCount'), combinationCount: byId('activeCombinationCount'), observedCount: byId('observedCount'),
    dayValidated: byId('dayValidatedCount'), dayValidatedLabel: byId('dayValidatedLabel'), dayApproved: byId('dayApprovedCount'), dayRejected: byId('dayRejectedCount'), lines: byId('validationLines'), linesTitle: byId('validationLinesTitle'),
    loading: byId('officeLoading'), loadingText: byId('officeLoadingText'), toasts: byId('officeToasts'),
};
const keys = { token: 'estiba_wms_office_token', identity: 'estiba_wms_office_identity' };
const state = {
    token: localStorage.getItem(keys.token), identity: readJson(keys.identity), seasons: [], season: null,
    filterSeasons: [], filterSeason: null, validators: [], history: [], summary: null,
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
    state.summary = response;
    renderMetrics();
}

async function loadAll(seasonId = null) {
    await loadIndependentSections(
        [() => loadFilterOptions(seasonId), () => loadSummary(seasonId), () => loadHistory(seasonId)],
        (error) => toast(error?.message || 'No fue posible cargar una sección de Validación.', true),
    );
}

function renderMetrics() {
    elements.catalogVersion.textContent = state.summary?.temporada?.version_catalogo ?? '—';
    elements.articleCount.textContent = String(state.summary?.articulos_activos ?? 0);
    elements.originCount.textContent = String(state.summary?.origenes_activos ?? 0);
    elements.combinationCount.textContent = String(state.summary?.combinaciones_activas ?? 0);
    const day = state.summary?.dia || {};
    elements.observedCount.textContent = String(day.observados ?? 0);
    elements.dayValidated.textContent = String(day.validados ?? 0);
    elements.dayApproved.textContent = String(day.aprobados ?? 0);
    elements.dayRejected.textContent = String(day.rechazados ?? 0);
    elements.observedCount.closest('article').classList.toggle('has-attention', day.observados > 0);
    const context = validationDayContext(day.fecha, validationTimeZone());
    elements.dayValidatedLabel.textContent = context.label;
    elements.linesTitle.textContent = context.title;
    elements.lines.innerHTML = (day.por_linea || []).map((line) => {
        const validators = (line.validadores || []).map((user) =>
            `${user.nombre || '—'}${user.dispositivo ? `, ${user.dispositivo}` : ''}`).join(' · ') || 'Sin validaciones';
        return `<article class="validation-line"><header><h3>Línea ${escapeHtml(line.linea)}</h3><span>${escapeHtml(validators)}</span></header><div class="validation-line__cells"><span><b>${escapeHtml(line.validados)}</b>validados</span><span class="${line.observados > 0 ? 'is-attention' : ''}"><b>${escapeHtml(line.observados)}</b>observados</span><span class="${line.rechazados > 0 ? 'is-critical' : ''}"><b>${escapeHtml(line.rechazados)}</b>${line.rechazados === 1 ? 'rechazo' : 'rechazos'}</span></div></article>`;
    }).join('');
}

function validationTimeZone() {
    return document.querySelector('[data-validation-timezone]')?.dataset.validationTimezone || 'America/Santiago';
}

function renderHistory() {
    elements.history.innerHTML = state.history.map((item) => {
        const article = item.catalogo?.articulo || {}; const origin = item.catalogo?.origen || {};
        const resultClass = item.estado === 'conflicto' ? 'conflicto' : item.resultado;
        const category = item.catalogo?.categoria || {};
        const lastCorrection = item.correcciones?.[0];
        const correction = item.puede_corregir === true
            ? `<button class="validation-correction-button" data-correct-validation="${escapeHtml(item.id)}" type="button">Corregir</button>` : '';
        const resultLabel = { aprobado: 'Aprobado', observado: 'Observado', rechazado: 'Rechazo definitivo', conflicto: 'Conflicto' }[resultClass] || statusText(resultClass);
        return `<tr class="validation-record validation-record--${escapeHtml(resultClass)}"><td><strong class="validation-code">${escapeHtml(item.numero_folio)}</strong><small>${item.tipo_bulto === 'pallet' ? 'Pallet completo' : 'Saldo'}, ${escapeHtml(item.cantidad_cajas)} cajas${item.numero_intento > 1 ? ` · intento ${escapeHtml(item.numero_intento)}` : ''}</small></td><td><strong>${escapeHtml([article.especie || 'Sin artículo', article.variedad, article.calibre].filter(Boolean).join(' '))}</strong><small>${escapeHtml(article.envase || '—')} · ${escapeHtml(category.nombre || 'Sin categoría')}</small></td><td><strong>${escapeHtml(validationOriginLabel(item.catalogo))}</strong><small>${escapeHtml(origin.cliente || 'Sin origen')} · ${escapeHtml(origin.marca || '')}</small></td><td><span class="validation-result validation-result--${escapeHtml(resultClass)}"><i aria-hidden="true"></i>${escapeHtml(resultLabel)}</span>${item.motivo ? `<small>${escapeHtml(statusText(item.motivo))}</small>` : ''}</td><td><strong>${escapeHtml(item.usuario?.nombre || '—')}</strong><small>${escapeHtml(validationJourneyLabel(item, validationTimeZone()))}</small>${lastCorrection ? `<small>Corregido ${escapeHtml(formatDate(lastCorrection.corregido_at))}</small>` : ''}</td><td class="validation-record__action">${correction}</td></tr>`;
    }).join('') || '<tr><td class="empty-validation" colspan="6">No existen validaciones coincidentes.</td></tr>';
}

elements.login.addEventListener('submit', async (event) => { event.preventDefault(); elements.loginError.textContent = ''; setBusy(true, 'Validando acceso…'); try { const payload = await api('/api/acceso-oficina', { method: 'POST', body: JSON.stringify(Object.fromEntries(new FormData(elements.login))) }); if (payload.usuario.puede_consultar_validaciones_pallet !== true) throw new ApiError('Tu perfil no puede consultar validaciones.', 403); persist(payload); showApp(); await loadAll(); } catch (error) { elements.loginError.textContent = error.message; } finally { setBusy(false); } });
elements.logout.addEventListener('click', async () => { try { await api('/api/acceso-oficina', { method: 'DELETE' }); } catch {} clearSession(); });
elements.reload.addEventListener('click', () => { setBusy(true, 'Actualizando validación…'); void loadAll(state.filterSeason?.id).catch((error) => toast(error.message, true)).finally(() => setBusy(false)); });
elements.filters.addEventListener('submit', (event) => { event.preventDefault(); setBusy(true, 'Consultando historial…'); void Promise.all([loadHistory(state.filterSeason?.id), loadSummary(state.filterSeason?.id)]).catch((error) => toast(error.message, true)).finally(() => setBusy(false)); });
const correction = createValidationCorrection({
    api, setBusy, toast,
    onSaved: () => Promise.all([loadHistory(state.filterSeason?.id), loadSummary(state.filterSeason?.id)]),
});
elements.history.addEventListener('click', (event) => {
    const button = event.target.closest('[data-correct-validation]');
    if (!button) return;
    const item = state.history.find((candidate) => candidate.id === button.dataset.correctValidation);
    if (item) void correction.open(item.id, item.numero_folio);
});
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
