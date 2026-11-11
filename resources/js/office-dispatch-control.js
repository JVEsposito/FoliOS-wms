import { ESTADOS_PALLET, gruposEncabezado, palletsOrdenados, temperatura, textoValor, tonoInspeccion } from './shared/dispatch-control.js';

const byId = (id) => document.getElementById(id);
const elements = {
    access: byId('officeAccess'), app: byId('officeApp'), login: byId('officeLoginForm'), loginError: byId('officeLoginError'),
    userName: byId('officeUserName'), userRole: byId('officeUserRole'), initials: byId('officeInitials'), logout: byId('officeLogoutButton'),
    filters: byId('dcxFilters'), rows: byId('dcxRows'), count: byId('dcxCount'), page: byId('dcxPage'), prev: byId('dcxPrev'), next: byId('dcxNext'),
    detail: byId('dcxDetail'), detailState: byId('dcxDetailState'), detailTitle: byId('dcxDetailTitle'), detailMeta: byId('dcxDetailMeta'),
    download: byId('dcxDownload'), missing: byId('dcxMissing'), kpis: byId('dcxKpis'), header: byId('dcxHeader'), inspection: byId('dcxInspection'),
    pallets: byId('dcxPallets'), closing: byId('dcxClosing'), photos: byId('dcxPhotos'),
    reopen: byId('dcxReopen'), reopenForm: byId('dcxReopenForm'), reopenError: byId('dcxReopenError'),
    history: byId('dcxHistory'), historyList: byId('dcxHistoryList'),
    loading: byId('officeLoading'), loadingText: byId('officeLoadingText'), toasts: byId('officeToasts'),
};
const keys = { token: 'estiba_wms_office_token', identity: 'estiba_wms_office_identity' };
const state = { token: localStorage.getItem(keys.token), identity: readJson(keys.identity), catalog: null, page: 1, lastPage: 1, control: null, photoUrls: [], reopenOperation: null };

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

async function request(path, options = {}) {
    const headers = new Headers(options.headers || {});
    headers.set('Accept', options.accept || 'application/json');
    if (state.token) headers.set('Authorization', `Bearer ${state.token}`);
    if (options.body) headers.set('Content-Type', 'application/json');
    let response;
    try { response = await fetch(path, { ...options, headers }); } catch { throw new ApiError('No fue posible conectar con el servidor.'); }
    if (!response.ok) {
        const data = await response.json().catch(() => ({}));
        if (response.status === 401) clearSession();
        throw new ApiError(errorMessage(data, 'No fue posible completar la operación.'), response.status);
    }
    return response;
}
async function api(path, options = {}) {
    const response = await request(path, options);
    return response.status === 204 ? null : response.json().catch(() => ({}));
}

function showApp() {
    elements.access.classList.add('is-hidden');
    elements.app.classList.remove('is-hidden');
    const name = state.identity?.nombre || 'Usuario';
    if (elements.userName) elements.userName.textContent = name;
    if (elements.userRole) elements.userRole.textContent = statusText(state.identity?.rol);
    if (elements.initials) elements.initials.textContent = name.split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0]).join('').toUpperCase();
}

const stateLabel = (estado) => (estado === 'cerrado' ? 'Cerrada' : 'En curso');

async function loadList(page = state.page) {
    const params = new URLSearchParams(Object.entries(Object.fromEntries(new FormData(elements.filters))).filter(([, value]) => value));
    params.set('page', String(page));
    elements.rows.innerHTML = '<tr><td colspan="7">Cargando…</td></tr>';
    try {
        const result = await api(`/api/control-despacho/controles?${params}`);
        state.page = result.meta.current_page;
        state.lastPage = result.meta.last_page;
        elements.count.textContent = `${result.meta.total} ${result.meta.total === 1 ? 'planilla' : 'planillas'}`;
        elements.page.textContent = `${state.page} / ${state.lastPage}`;
        elements.prev.disabled = state.page <= 1;
        elements.next.disabled = state.page >= state.lastPage;
        elements.rows.innerHTML = result.data.length ? result.data.map((row) => `
            <tr data-id="${escapeHtml(row.id)}" tabindex="0" aria-selected="${state.control?.id === row.id}">
                <td><strong>${escapeHtml(row.carga.codigo)}</strong>${row.embarque ? `<small>${escapeHtml(row.embarque)}</small>` : ''}</td>
                <td>${escapeHtml(row.exportadora || '—')}</td>
                <td>${escapeHtml(row.contenedor || '—')}${row.tipo_camion ? `<small>${escapeHtml(textoValor(row.tipo_camion))}</small>` : ''}</td>
                <td>${row.pallets}</td>
                <td><span class="dcx-chip" data-tone="${row.estado === 'cerrado' ? 'ok' : 'curso'}">${stateLabel(row.estado)}</span></td>
                <td>${formatDate(row.iniciado_at)}<small>${escapeHtml(row.iniciado_por || '')}</small></td>
                <td>${formatDate(row.cerrado_at)}<small>${escapeHtml(row.cerrado_por || '')}</small></td>
            </tr>`).join('') : '<tr><td colspan="7">No hay planillas con estos filtros.</td></tr>';
    } catch (error) {
        elements.rows.innerHTML = `<tr><td colspan="7">${escapeHtml(error.message)}</td></tr>`;
    }
}

async function openControl(id) {
    setBusy(true, 'Abriendo planilla…');
    try {
        const result = await api(`/api/control-despacho/controles/${encodeURIComponent(id)}`);
        renderControl(result.data);
        elements.rows.querySelectorAll('tr[data-id]').forEach((row) => row.setAttribute('aria-selected', String(row.dataset.id === id)));
        elements.detail.scrollIntoView({ behavior: 'smooth', block: 'start' });
    } catch (error) {
        toast(error.message, true);
    } finally {
        setBusy(false);
    }
}

function definitionList(rows) {
    return `<dl class="dcx-fields">${rows.map((row) => `<div><dt>${escapeHtml(row.etiqueta)}</dt><dd>${escapeHtml(row.valor)}</dd></div>`).join('')}</dl>`;
}

function renderControl(control) {
    state.control = control;
    state.reopenOperation = null;
    const catalog = state.catalog;
    elements.detail.classList.remove('is-hidden');
    elements.detailState.textContent = `${stateLabel(control.estado).toUpperCase()} · POEMP R-24`;
    elements.detailTitle.textContent = `Carga ${control.carga.codigo}`;
    elements.detailMeta.textContent = [
        control.carga.embarque ? `Embarque ${control.carga.embarque}` : null,
        control.carga.anden ? `Andén ${control.carga.anden}` : null,
        `Iniciada ${formatDate(control.iniciado_at)} por ${control.iniciado_por || '—'}`,
        control.cerrado_at ? `Cerrada ${formatDate(control.cerrado_at)} por ${control.cerrado_por || '—'}` : null,
    ].filter(Boolean).join(' · ');

    elements.missing.hidden = control.faltantes_cierre.length === 0;
    elements.missing.innerHTML = control.faltantes_cierre.length
        ? `<strong>Para cerrar falta:</strong><ul>${control.faltantes_cierre.map((item) => `<li>${escapeHtml(item)}</li>`).join('')}</ul>` : '';

    const totals = control.totales;
    elements.kpis.innerHTML = [
        ['Pallets', totals.pallets], ['Cargados', totals.cargados], ['Con posición', totals.posicionados],
        ['Con T° pulpa', totals.con_temperatura], ['Cajas PCD', totals.cajas_pcd], ['Cajas guía', control.cierre.cajas_guia ?? '—'],
    ].map(([label, value]) => `<div><span>${label}</span><strong>${escapeHtml(textoValor(value))}</strong></div>`).join('');

    elements.header.innerHTML = gruposEncabezado(catalog.encabezado, control.encabezado)
        .map((group) => `<h4>${escapeHtml(group.titulo)}</h4>${definitionList(group.filas)}`).join('') || '<p class="dcx-muted">Sin datos de encabezado.</p>';
    elements.inspection.innerHTML = catalog.inspeccion.map((item) => {
        const value = control.inspeccion[item.clave];
        return `<li data-tone="${tonoInspeccion(item.clave, value)}"><span>${escapeHtml(item.etiqueta)}</span><strong>${value ? escapeHtml(textoValor(value)) : 'Sin respuesta'}</strong></li>`;
    }).join('');

    const pallets = palletsOrdenados(control.pallets);
    elements.pallets.innerHTML = pallets.length ? pallets.map((pallet) => `
        <tr data-placed="${pallet.posicion ? 'si' : 'no'}">
            <td>${pallet.posicion?.posicion ?? '—'}</td>
            <td>${escapeHtml(pallet.posicion?.codigo_pe || '—')}</td>
            <td><strong>${escapeHtml(pallet.numero_folio)}</strong></td>
            <td>${escapeHtml([pallet.especie, pallet.variedad].filter(Boolean).join(' · ') || '—')}</td>
            <td>${escapeHtml(textoValor(pallet.posicion?.cajas ?? pallet.cajas_folio))}</td>
            <td>${temperatura(pallet.posicion?.temperatura)}</td>
            <td>${escapeHtml(ESTADOS_PALLET[pallet.estado_carga] || statusText(pallet.estado_carga))}</td>
            <td>${pallet.posicion ? `${formatDate(pallet.posicion.registrado_at)}<small>${escapeHtml(pallet.posicion.registrado_por || '')}</small>` : '—'}</td>
        </tr>`).join('') : '<tr><td colspan="8">La carga no tiene pallets.</td></tr>';

    const closingRows = catalog.cierre
        .filter((field) => control.cierre[field.clave] !== null && control.cierre[field.clave] !== undefined && control.cierre[field.clave] !== '')
        .map((field) => ({ etiqueta: field.etiqueta, valor: textoValor(control.cierre[field.clave]) }));
    elements.closing.innerHTML = closingRows.length ? definitionList(closingRows) : '<p class="dcx-muted">Sin datos de cierre.</p>';

    renderPhotos(control.fotos);
    const canReopen = state.identity?.puede_reabrir_control_despacho === true && control.estado === 'cerrado';
    elements.reopen.hidden = !canReopen;
    elements.reopenForm.reset();
    elements.reopenError.textContent = '';
    elements.history.hidden = control.reaperturas.length === 0;
    elements.historyList.innerHTML = control.reaperturas.map((item) => `<li><strong>${formatDate(item.reabierto_at)} · ${escapeHtml(item.nombre)}</strong><span>${escapeHtml(item.motivo)}</span></li>`).join('');
}

async function renderPhotos(photos) {
    state.photoUrls.forEach((url) => URL.revokeObjectURL(url));
    state.photoUrls = [];
    if (!photos.length) {
        elements.photos.innerHTML = '<p class="dcx-muted">Sin fotos. Son opcionales mientras no se defina el set fotográfico estándar.</p>';
        return;
    }
    elements.photos.innerHTML = photos.map((photo) => `<button type="button" class="dcx-photo" data-url="${escapeHtml(photo.url)}" aria-label="Abrir foto ${photo.orden}"><img alt="" data-src="${escapeHtml(photo.miniatura_url)}"><span>${formatDate(photo.subida_at)}</span></button>`).join('');
    for (const image of elements.photos.querySelectorAll('img[data-src]')) {
        try {
            const blob = await (await request(image.dataset.src, { accept: 'image/*' })).blob();
            const url = URL.createObjectURL(blob);
            state.photoUrls.push(url);
            image.src = url;
        } catch {
            image.closest('.dcx-photo')?.setAttribute('data-error', 'true');
        }
    }
}

async function download() {
    const control = state.control;
    if (!control) return;
    setBusy(true, 'Generando planilla…');
    try {
        const response = await request(`/api/control-despacho/controles/${encodeURIComponent(control.id)}/planilla.xlsx`, { accept: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });
        const url = URL.createObjectURL(await response.blob());
        const link = document.createElement('a');
        link.href = url;
        link.download = `R-24_${control.carga.codigo}.xlsx`;
        document.body.append(link);
        link.click();
        link.remove();
        window.setTimeout(() => URL.revokeObjectURL(url), 1000);
    } catch (error) {
        toast(error.message, true);
    } finally {
        setBusy(false);
    }
}

elements.filters.addEventListener('submit', (event) => { event.preventDefault(); void loadList(1); });
elements.prev.addEventListener('click', () => void loadList(state.page - 1));
elements.next.addEventListener('click', () => void loadList(state.page + 1));
elements.rows.addEventListener('click', (event) => { const row = event.target.closest('tr[data-id]'); if (row) void openControl(row.dataset.id); });
elements.rows.addEventListener('keydown', (event) => { const row = event.target.closest('tr[data-id]'); if (row && (event.key === 'Enter' || event.key === ' ')) { event.preventDefault(); void openControl(row.dataset.id); } });
elements.download.addEventListener('click', () => void download());
elements.photos.addEventListener('click', async (event) => {
    const button = event.target.closest('.dcx-photo');
    if (!button) return;
    try {
        const url = URL.createObjectURL(await (await request(button.dataset.url, { accept: 'image/*' })).blob());
        state.photoUrls.push(url);
        window.open(url, '_blank', 'noopener');
    } catch (error) {
        toast(error.message, true);
    }
});
elements.reopenForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const control = state.control;
    const motivo = String(new FormData(elements.reopenForm).get('motivo') || '').trim();
    elements.reopenError.textContent = '';
    if (motivo.length < 10) { elements.reopenError.textContent = 'Explica el motivo (mínimo 10 caracteres).'; return; }
    state.reopenOperation ||= uuid();
    setBusy(true, 'Reabriendo planilla…');
    try {
        const result = await api(`/api/control-despacho/controles/${encodeURIComponent(control.id)}/reabrir`, {
            method: 'POST', body: JSON.stringify({ operacion_id: state.reopenOperation, version: control.version, motivo }),
        });
        renderControl(result.data);
        toast('Planilla reabierta: consolidación puede corregirla desde la PDA.');
        void loadList();
    } catch (error) {
        elements.reopenError.textContent = error.message;
        if (error.status === 409 || error.status === 422) state.reopenOperation = null;
    } finally {
        setBusy(false);
    }
});
elements.login.addEventListener('submit', async (event) => {
    event.preventDefault();
    const credentials = Object.fromEntries(new FormData(elements.login));
    elements.loginError.textContent = '';
    setBusy(true, 'Validando acceso…');
    try {
        const payload = await api('/api/acceso-oficina', { method: 'POST', body: JSON.stringify(credentials) });
        if (payload.usuario.puede_consultar_control_despacho !== true) throw new ApiError('Tu perfil no tiene el módulo Control de despacho (R-24).', 403);
        persist(payload);
        void start();
    } catch (error) {
        elements.loginError.textContent = error.message;
    } finally {
        setBusy(false);
    }
});
elements.logout?.addEventListener('click', async () => { try { await api('/api/acceso-oficina', { method: 'DELETE' }); } catch {} clearSession(); });

async function start() {
    showApp();
    try {
        state.catalog = (await api('/api/control-despacho/catalogo')).data;
    } catch (error) {
        toast(error.message, true);
        return;
    }
    await loadList(1);
    const initial = new URLSearchParams(window.location.search).get('control');
    if (initial) void openControl(initial);
}

if (state.token && state.identity?.puede_consultar_control_despacho === true) void start();
else clearSession();
