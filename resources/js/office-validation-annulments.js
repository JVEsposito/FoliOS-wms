import { createValidationCorrection } from './shared/validation-correction.js';

const byId = (id) => document.getElementById(id);
const elements = {
    access: byId('officeAccess'), app: byId('officeApp'), login: byId('officeLoginForm'), loginError: byId('officeLoginError'),
    userName: byId('officeUserName'), userRole: byId('officeUserRole'), initials: byId('officeInitials'), logout: byId('officeLogoutButton'),
    reload: byId('reloadButton'), filter: byId('annulmentFilter'), categoryFilter: byId('historyCategoryFilter'),
    candidates: byId('candidateList'), history: byId('historyList'), permissionNotice: byId('permissionNotice'),
    total: byId('totalAnnulled'), today: byId('todayAnnulled'), candidateCount: byId('candidateCount'), topReason: byId('topReason'),
    dialog: byId('annulmentDialog'), form: byId('annulmentForm'), dialogTitle: byId('annulmentDialogTitle'), error: byId('annulmentError'),
    cancel: byId('cancelAnnulment'), cancelBottom: byId('cancelAnnulmentBottom'),
    loading: byId('officeLoading'), loadingText: byId('officeLoadingText'), toasts: byId('officeToasts'),
};
const keys = { token: 'estiba_wms_office_token', identity: 'estiba_wms_office_identity' };
const state = {
    token: localStorage.getItem(keys.token),
    identity: readJson(keys.identity),
    candidates: [],
    history: [],
    summary: {},
    target: null,
    operationId: null,
};
const reasonLabels = {
    folio_incorrecto: 'Folio incorrecto',
    cantidad_cajas_incorrecta: 'Cantidad de cajas incorrecta',
    articulo_incorrecto: 'Artículo incorrecto',
    cliente_origen_incorrecto: 'Cliente / origen incorrecto',
    pallet_duplicado: 'Pallet duplicado',
    error_etiqueta: 'Error de etiqueta',
    otro: 'Otro',
};

class ApiError extends Error {
    constructor(message, status = 0) { super(message); this.status = status; }
}
function readJson(key) { try { return JSON.parse(localStorage.getItem(key) || 'null'); } catch { return null; } }
function escapeHtml(value) { return String(value ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&#039;'); }
function formatDate(value) { if (!value) return '—'; const date = new Date(value); return Number.isNaN(date.getTime()) ? '—' : new Intl.DateTimeFormat('es-CL', { dateStyle: 'short', timeStyle: 'short' }).format(date); }
function statusText(value) { return String(value || '').replaceAll('_', ' ').replace(/^./, (letter) => letter.toUpperCase()); }
function errorMessage(data, fallback) { return Object.values(data?.errors || {}).flat()[0] || data?.message || fallback; }
function uuid() { if (typeof crypto.randomUUID === 'function') return crypto.randomUUID(); const bytes = crypto.getRandomValues(new Uint8Array(16)); bytes[6] = (bytes[6] & 15) | 64; bytes[8] = (bytes[8] & 63) | 128; const hex = [...bytes].map((value) => value.toString(16).padStart(2, '0')).join(''); return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`; }
function setBusy(active, text = 'Procesando…') { elements.loadingText.textContent = text; elements.loading.classList.toggle('is-hidden', !active); elements.loading.setAttribute('aria-hidden', String(!active)); }
function toast(message, error = false) { const node = document.createElement('div'); node.className = `toast${error ? ' toast--error' : ''}`; node.textContent = message; elements.toasts.append(node); window.setTimeout(() => node.remove(), 5000); }
function persist(payload) { state.token = payload.token; state.identity = payload.usuario; localStorage.setItem(keys.token, payload.token); localStorage.setItem(keys.identity, JSON.stringify(payload.usuario)); }
function clearSession() { state.token = null; state.identity = null; localStorage.removeItem(keys.token); localStorage.removeItem(keys.identity); elements.app.classList.add('is-hidden'); elements.access.classList.remove('is-hidden'); }

async function api(path, options = {}) {
    const headers = new Headers(options.headers || {});
    headers.set('Accept', 'application/json');
    if (state.token) headers.set('Authorization', `Bearer ${state.token}`);
    if (options.body) headers.set('Content-Type', 'application/json');
    let response;
    try { response = await fetch(path, { ...options, headers }); } catch { throw new ApiError('No fue posible conectar con Laravel.'); }
    const data = response.status === 204 ? null : await response.json().catch(() => ({}));
    if (!response.ok) {
        if (response.status === 401) clearSession();
        throw new ApiError(errorMessage(data, 'No fue posible completar la operación.'), response.status);
    }
    return data;
}

function showApp() {
    elements.access.classList.add('is-hidden');
    elements.app.classList.remove('is-hidden');
    const name = state.identity?.nombre || 'Usuario';
    if (elements.userName) elements.userName.textContent = name;
    if (elements.userRole) elements.userRole.textContent = statusText(state.identity?.rol);
    if (elements.initials) elements.initials.textContent = name.split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0]).join('').toUpperCase();
    const canAnnul = state.identity?.puede_rechazar_pallets === true;
    const canCorrect = state.identity?.puede_corregir_validaciones_pallet === true;
    elements.permissionNotice.textContent = canCorrect
        ? 'Puedes corregir los datos auditados o anular la validación. Un folio anulado podrá ingresarse nuevamente desde la PDA.'
        : canAnnul
            ? 'Puedes anular únicamente mientras el pallet no tenga actividad posterior. El número quedará disponible para una nueva validación.'
            : 'Modo consulta: corregir requiere administración y anular requiere supervisor de frío o administración.';
}

async function loadData() {
    const params = new URLSearchParams();
    const filter = Object.fromEntries(new FormData(elements.filter));
    if (String(filter.folio || '').trim()) params.set('folio', String(filter.folio).trim());
    if (elements.categoryFilter.value) params.set('motivo_categoria', elements.categoryFilter.value);
    // El catálogo para corregir se pide al abrir la corrección, solo para ese bulto.
    const response = await api(`/api/validacion/anulaciones?${params}`);
    state.candidates = response.candidatas || [];
    state.history = response.anulaciones || [];
    state.summary = response.resumen || {};
    render();
}

function render() {
    const canAnnul = state.identity?.puede_rechazar_pallets === true;
    const canCorrect = state.identity?.puede_corregir_validaciones_pallet === true;
    elements.candidateCount.textContent = String(state.candidates.length);
    elements.total.textContent = String(state.summary.total || 0);
    elements.today.textContent = String(state.summary.hoy || 0);
    const top = Object.entries(state.summary.por_categoria || {}).sort((a, b) => Number(b[1]) - Number(a[1]))[0];
    elements.topReason.textContent = top ? `${reasonLabels[top[0]] || statusText(top[0])} · ${top[1]}` : '—';

    elements.candidates.innerHTML = state.candidates.length ? state.candidates.map((item) => `
        <article class="annulment-card">
            <div class="annulment-card-main">
                <strong>${escapeHtml(item.numero_folio)}</strong>
                <span>${escapeHtml(statusText(item.tipo_bulto))} · ${item.cantidad_cajas} cajas · Línea ${escapeHtml(item.linea_proceso || '—')} · Turno ${escapeHtml(item.turno || '—')}</span>
                <small>Validado por ${escapeHtml(item.validador?.nombre || '—')} · ${escapeHtml(item.dispositivo?.codigo || '—')} · ${escapeHtml(formatDate(item.validado_at))}</small>
            </div>
            <div class="annulment-state"><span>PENDIENTE PRE-FRÍO</span><small>Sin actividad posterior</small></div>
            ${canCorrect ? `<button class="secondary-button" data-correct="${escapeHtml(item.id)}" type="button">Corregir datos</button>` : ''}
            ${canAnnul ? `<button class="danger-button" data-annul="${escapeHtml(item.id)}" type="button">Anular pallet</button>` : ''}
            ${!canCorrect && !canAnnul ? '<span class="annulment-readonly">Solo consulta</span>' : ''}
        </article>
    `).join('') : '<p class="annulment-empty">No hay pallets que cumplan las condiciones estrictas de anulación.</p>';

    elements.history.innerHTML = state.history.length ? state.history.map((item) => `
        <article class="annulment-history-card">
            <div><strong>${escapeHtml(item.numero_folio)}</strong><span>${escapeHtml(reasonLabels[item.motivo_categoria] || statusText(item.motivo_categoria))}</span></div>
            <p>${escapeHtml(item.motivo)}</p>
            <small>Validado por ${escapeHtml(item.validacion?.validador?.nombre || '—')} · anulado por ${escapeHtml(item.anulado_por?.nombre || '—')} · ${escapeHtml(formatDate(item.anulado_at))}</small>
            <small class="annulment-released">${item.folio?.activo ? `Folio reingresado · ${escapeHtml(statusText(item.folio.estado_operacional))}` : 'Número de folio disponible para una nueva validación'}</small>
        </article>
    `).join('') : '<p class="annulment-empty">Todavía no existen anulaciones para esta selección.</p>';
}

function openCorrection(id) {
    if (state.identity?.puede_corregir_validaciones_pallet !== true) return;
    const target = state.candidates.find((item) => item.id === id);
    if (target) void correction.open(target.id, target.numero_folio);
}

function openAnnulment(id) {
    if (state.identity?.puede_rechazar_pallets !== true) return;
    const target = state.candidates.find((item) => item.id === id);
    if (!target) return;
    state.target = target;
    state.operationId = uuid();
    elements.dialogTitle.textContent = `Anular ${target.numero_folio}`;
    elements.form.reset();
    elements.error.textContent = '';
    elements.dialog.showModal();
}

function closeAnnulment() {
    if (elements.dialog.open) elements.dialog.close();
    state.target = null;
    state.operationId = null;
    elements.form.reset();
    elements.error.textContent = '';
}

async function submitAnnulment(event) {
    event.preventDefault();
    if (!state.target || !state.operationId) return;
    const data = Object.fromEntries(new FormData(elements.form));
    elements.error.textContent = '';
    if (!data.motivo_categoria || String(data.motivo || '').trim().length < 5) {
        elements.error.textContent = 'Selecciona el tipo de error y explica el motivo con al menos 5 caracteres.';
        return;
    }
    setBusy(true, `Anulando ${state.target.numero_folio}…`);
    try {
        const response = await api(`/api/validacion/pallets/${state.target.id}/anular`, {
            method: 'POST',
            body: JSON.stringify({
                operacion_id: state.operationId,
                motivo_categoria: data.motivo_categoria,
                motivo: String(data.motivo).trim(),
            }),
        });
        closeAnnulment();
        await loadData();
        toast(response.message || 'Validación anulada y folio disponible para reingreso.');
    } catch (error) {
        elements.error.textContent = error.message;
    } finally {
        setBusy(false);
    }
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
        if (payload.usuario.puede_consultar_validaciones_pallet !== true) {
            throw new ApiError('Tu perfil no puede consultar validaciones.', 403);
        }
        persist(payload);
        showApp();
        await loadData();
    } catch (error) {
        elements.loginError.textContent = error.message;
    } finally {
        setBusy(false);
    }
});
elements.logout?.addEventListener('click', async () => { try { await api('/api/acceso-oficina', { method: 'DELETE' }); } catch {} clearSession(); });
elements.reload.addEventListener('click', () => { setBusy(true, 'Actualizando anulaciones…'); void loadData().catch((error) => toast(error.message, true)).finally(() => setBusy(false)); });
elements.filter.addEventListener('submit', (event) => { event.preventDefault(); setBusy(true, 'Buscando pallets…'); void loadData().catch((error) => toast(error.message, true)).finally(() => setBusy(false)); });
elements.categoryFilter.addEventListener('change', () => { setBusy(true, 'Filtrando auditoría…'); void loadData().catch((error) => toast(error.message, true)).finally(() => setBusy(false)); });
const correction = createValidationCorrection({
    api, setBusy, toast,
    onSaved: () => loadData(),
});
elements.candidates.addEventListener('click', (event) => {
    const correctButton = event.target.closest('[data-correct]');
    if (correctButton) { openCorrection(correctButton.dataset.correct); return; }
    const annulment = event.target.closest('[data-annul]');
    if (annulment) openAnnulment(annulment.dataset.annul);
});
elements.form.addEventListener('submit', (event) => { void submitAnnulment(event); });
elements.cancel.addEventListener('click', closeAnnulment);
elements.cancelBottom.addEventListener('click', closeAnnulment);
elements.dialog.addEventListener('cancel', (event) => { event.preventDefault(); closeAnnulment(); });

if (state.token && state.identity?.puede_consultar_validaciones_pallet === true) {
    showApp();
    setBusy(true, 'Cargando anulaciones…');
    void loadData().catch(() => clearSession()).finally(() => setBusy(false));
} else {
    clearSession();
}
