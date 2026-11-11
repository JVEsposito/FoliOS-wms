import { createValidationCorrection } from './shared/validation-correction.js';

const byId = (id) => document.getElementById(id);
const elements = {
    access: byId('officeAccess'), app: byId('officeApp'), login: byId('officeLoginForm'), loginError: byId('officeLoginError'),
    userName: byId('officeUserName'), userRole: byId('officeUserRole'), initials: byId('officeInitials'), logout: byId('officeLogoutButton'),
    search: byId('ptaSearch'), empty: byId('ptaEmpty'), folio: byId('ptaFolio'),
    type: byId('ptaFolioType'), title: byId('ptaFolioTitle'), state: byId('ptaFolioState'), notice: byId('ptaNotice'),
    attributes: byId('ptaAttributes'), labelLink: byId('ptaLabelLink'),
    productButton: byId('ptaProductButton'), productBlocked: byId('ptaProductBlocked'),
    thermalForm: byId('ptaThermalForm'), conditions: byId('ptaConditions'), thermalEffect: byId('ptaThermalEffect'),
    thermalError: byId('ptaThermalError'), thermalSubmit: byId('ptaThermalSubmit'), thermalBlocked: byId('ptaThermalBlocked'),
    history: byId('ptaHistory'),
    loading: byId('officeLoading'), loadingText: byId('officeLoadingText'), toasts: byId('officeToasts'),
};
const keys = { token: 'estiba_wms_office_token', identity: 'estiba_wms_office_identity' };
const state = { token: localStorage.getItem(keys.token), identity: readJson(keys.identity), ficha: null, operationId: null, submitted: null };

const CONDITIONS = {
    prefrio_aprobado: { label: 'Prefrío aprobado', effect: 'Queda habilitado para almacenar: disponible si está en cámara o por ubicar si no.' },
    condicion_heredada: { label: 'Condición heredada', effect: 'Habilitado sin proceso de túnel propio (hereda la de sus orígenes). Disponible o por ubicar.' },
    pendiente_prefrio: { label: 'Pendiente de prefrío', effect: 'Vuelve a esperar túnel. Conserva su ubicación y su carga.' },
    requiere_reproceso: { label: 'Requiere reproceso', effect: 'Debe volver a túnel. Conserva su ubicación y su carga.' },
    retenido: { label: 'Retenido', effect: 'Queda bloqueado: se suspende su carga y se planifica su segregación.' },
    en_proceso: { label: 'En proceso en túnel' },
};
const STATES = {
    pendiente_prefrio: 'Pendiente de prefrío', pendiente_ubicacion: 'Pendiente de ubicación', disponible: 'Disponible', bloqueado: 'Bloqueado',
    despachado: 'Despachado', anulado: 'Anulado', agotado: 'Agotado', retirado_definitivo: 'Retirado',
};
const LOAD_STATES = {
    borrador: 'borrador', pendiente: 'pendiente', en_preparacion: 'en preparación', despacho_parcial: 'despacho parcial',
    en_separacion: 'en separación', separada: 'separada', separacion_completa: 'separación completa',
};
const ATTRIBUTES = [
    ['exportadora', 'Cliente'], ['marca', 'Marca'], ['especie', 'Especie'], ['variedad', 'Variedad'], ['calibre', 'Calibre'],
    ['envase', 'Embalaje'], ['categoria', 'Categoría'], ['csg', 'CSG'], ['cantidad_cajas', 'Cajas'], ['tipo_bulto', 'Tipo de bulto'],
    ['condicion_termica', 'Prefrío'], ['estado_operacional', 'Estado'],
];

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
    try { response = await fetch(path, { ...options, headers }); } catch { throw new ApiError('No fue posible conectar con el servidor.'); }
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
}

const valueText = (field, value) => {
    if (value === null || value === undefined || value === '') return '—';
    if (field === 'condicion_termica') return CONDITIONS[value]?.label || statusText(value);
    if (field === 'estado_operacional') return STATES[value] || statusText(value);
    if (field === 'tipo_bulto') return statusText(value);
    if (field === 'cantidad_cajas') return new Intl.NumberFormat('es-CL').format(value);
    return String(value);
};

function render(ficha) {
    state.ficha = ficha;
    const folio = ficha.folio;
    elements.empty.classList.add('is-hidden');
    elements.folio.classList.remove('is-hidden');
    elements.type.textContent = folio.tipo_bulto === 'saldo' ? 'SALDO' : 'PALLET';
    elements.title.textContent = `Folio ${folio.numero_folio}`;
    elements.state.textContent = [
        STATES[folio.estado_operacional] || statusText(folio.estado_operacional),
        CONDITIONS[folio.condicion_termica]?.label,
        folio.ubicacion ? `${folio.ubicacion.camara} · ${folio.ubicacion.posicion}` : 'Sin ubicación',
    ].filter(Boolean).join(' · ');
    elements.labelLink.href = `/oficina/validacion/etiquetas?folio=${encodeURIComponent(folio.numero_folio)}`;

    const notices = [];
    if (!ficha.editable) notices.push(['blocked', ficha.motivo_bloqueo]);
    if (ficha.carga) notices.push(['load', `Está en la carga ${ficha.carga.codigo} (${LOAD_STATES[ficha.carga.estado] || statusText(ficha.carga.estado).toLowerCase()}). Los cambios se aplican igual y quedan asociados a esa carga.`]);
    if (ficha.proceso_prefrio) notices.push(['process', `Está en el proceso de prefrío ${ficha.proceso_prefrio.codigo}. Su estado de prefrío se cambia al terminar o retirarlo del túnel.`]);
    elements.notice.hidden = notices.length === 0;
    elements.notice.innerHTML = notices.map(([kind, text]) => `<p data-kind="${kind}">${escapeHtml(text)}</p>`).join('');

    elements.attributes.innerHTML = ATTRIBUTES.map(([field, label]) => `<div><dt>${label}</dt><dd>${escapeHtml(valueText(field, folio[field]))}</dd></div>`).join('');

    elements.productButton.disabled = !ficha.puede_cambiar_producto;
    elements.productBlocked.hidden = ficha.puede_cambiar_producto;
    elements.productBlocked.textContent = ficha.editable ? 'El folio no tiene una validación aprobada que corregir.' : ficha.motivo_bloqueo;

    const conditions = (ficha.condiciones || []).filter((value) => value !== folio.condicion_termica);
    elements.conditions.innerHTML = `<legend>Nuevo estado <small>actual: ${escapeHtml(CONDITIONS[folio.condicion_termica]?.label || statusText(folio.condicion_termica))}</small></legend>${conditions
        .map((value) => `<label class="pta-condition"><input type="radio" name="condicion" value="${value}"><span>${escapeHtml(CONDITIONS[value]?.label || statusText(value))}</span></label>`).join('')}`;
    elements.thermalForm.hidden = !ficha.puede_cambiar_condicion;
    elements.thermalBlocked.hidden = ficha.puede_cambiar_condicion;
    elements.thermalBlocked.textContent = ficha.editable ? 'Mientras el folio esté en un túnel, su estado de prefrío lo define ese proceso.' : ficha.motivo_bloqueo;
    elements.thermalForm.reset();
    elements.thermalEffect.textContent = '';
    elements.thermalError.textContent = '';
    state.operationId = uuid();
    state.submitted = null;

    elements.history.innerHTML = ficha.historial.length ? ficha.historial.map((change) => {
        const fields = ATTRIBUTES.filter(([field]) => (change.antes?.[field] ?? null) !== (change.despues?.[field] ?? null));
        return `<article class="pta-change">
            <header><strong>${change.tipo === 'condicion_termica' ? 'Estado de prefrío' : 'Producto'}</strong><span>${escapeHtml(change.usuario || 'Administración')} · ${formatDate(change.created_at)}${change.carga_codigo ? ` · carga ${escapeHtml(change.carga_codigo)}` : ''}</span></header>
            <p>${escapeHtml(change.motivo)}</p>
            ${fields.length ? `<ul>${fields.map(([field, label]) => `<li><span>${label}</span><del>${escapeHtml(valueText(field, change.antes?.[field]))}</del><b>${escapeHtml(valueText(field, change.despues?.[field]))}</b></li>`).join('')}</ul>` : ''}
        </article>`;
    }).join('') : '<p class="pta-history__empty">Este folio no tiene cambios de administración.</p>';
}

async function search(folio) {
    setBusy(true, `Buscando ${folio}…`);
    try {
        const response = await api(`/api/administracion/atributos-pt/folios?folio=${encodeURIComponent(folio)}`);
        render(response.data);
        const url = new URL(window.location.href);
        url.searchParams.set('folio', folio);
        window.history.replaceState(null, '', url);
    } catch (error) {
        toast(error.message, true);
    } finally {
        setBusy(false);
    }
}

async function reload() {
    if (!state.ficha) return;
    const response = await api(`/api/administracion/atributos-pt/folios/${encodeURIComponent(state.ficha.folio.id)}`);
    render(response.data);
}

async function submitThermal(event) {
    event.preventDefault();
    const condition = elements.thermalForm.elements.condicion?.value;
    const reason = elements.thermalForm.elements.motivo.value.trim();
    if (!condition) { elements.thermalError.textContent = 'Elige el nuevo estado de prefrío.'; return; }
    if (reason.length < 10) { elements.thermalError.textContent = 'Describe el motivo con al menos 10 caracteres.'; return; }
    const payloadKey = JSON.stringify({ condition, reason });
    if (state.submitted !== null && state.submitted !== payloadKey) state.operationId = uuid();
    state.submitted = payloadKey;
    elements.thermalSubmit.disabled = true;
    elements.thermalError.textContent = '';
    setBusy(true, 'Cambiando estado de prefrío…');
    try {
        const response = await api(`/api/administracion/atributos-pt/folios/${encodeURIComponent(state.ficha.folio.id)}/condicion-termica`, {
            method: 'POST', body: JSON.stringify({ operacion_id: state.operationId, condicion: condition, motivo: reason }),
        });
        render(response.data);
        toast(response.message || 'Estado de prefrío cambiado.');
    } catch (error) {
        elements.thermalError.textContent = error.message;
    } finally {
        elements.thermalSubmit.disabled = false;
        setBusy(false);
    }
}

const correction = createValidationCorrection({
    api, setBusy, toast,
    onSaved: () => reload(),
    endpoints: {
        context: (folioId, client) => `/api/administracion/atributos-pt/folios/${encodeURIComponent(folioId)}/producto${client ? `?cliente=${encodeURIComponent(client)}` : ''}`,
        save: (folioId) => ({ url: `/api/administracion/atributos-pt/folios/${encodeURIComponent(folioId)}/producto`, method: 'POST' }),
        note: 'Cambio de administración: se corrigen la validación y el folio. Su cámara, carga y número se conservan. En Cuadratura queda cuadrado frente a planta.',
    },
});

elements.search.addEventListener('submit', (event) => {
    event.preventDefault();
    const folio = elements.search.elements.folio.value.trim();
    if (folio) void search(folio);
});
elements.productButton.addEventListener('click', () => { if (state.ficha) void correction.open(state.ficha.folio.id, state.ficha.folio.numero_folio); });
elements.thermalForm.addEventListener('submit', (event) => { void submitThermal(event); });
elements.conditions.addEventListener('change', (event) => {
    elements.thermalEffect.textContent = CONDITIONS[event.target.value]?.effect || '';
    elements.thermalError.textContent = '';
});
elements.login.addEventListener('submit', async (event) => {
    event.preventDefault();
    const credentials = Object.fromEntries(new FormData(elements.login));
    elements.loginError.textContent = '';
    setBusy(true, 'Validando acceso…');
    try {
        const payload = await api('/api/acceso-oficina', { method: 'POST', body: JSON.stringify(credentials) });
        if (payload.usuario.puede_cambiar_atributos_pt !== true) throw new ApiError('Este módulo es solo para administradores.', 403);
        persist(payload);
        start();
    } catch (error) {
        elements.loginError.textContent = error.message;
    } finally {
        setBusy(false);
    }
});
elements.logout?.addEventListener('click', async () => { try { await api('/api/acceso-oficina', { method: 'DELETE' }); } catch {} clearSession(); });

function start() {
    showApp();
    const initial = new URLSearchParams(window.location.search).get('folio');
    if (initial) {
        elements.search.elements.folio.value = initial;
        void search(initial);
    } else {
        elements.search.elements.folio.focus();
    }
}

if (state.token && state.identity?.puede_cambiar_atributos_pt === true) start();
else clearSession();
