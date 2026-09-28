import { cameraReferenceLabel } from './shared/camera-display';

const tokenKey = 'estiba_wms_office_token';
const identityKey = 'estiba_wms_office_identity';
const byId = (id) => document.getElementById(id);
const elements = {
    app: byId('discrepanciesApp'), reload: byId('discrepanciesReload'),
    filters: byId('discrepanciesFilters'), clear: byId('discrepanciesClear'),
    open: byId('openDiscrepanciesCount'), resolved: byId('resolvedDiscrepanciesCount'),
    missing: byId('missingPalletsCount'),
    results: byId('discrepanciesResults'), error: byId('discrepanciesError'),
    list: byId('discrepanciesList'), previous: byId('discrepanciesPrevious'),
    next: byId('discrepanciesNext'), page: byId('discrepanciesPage'),
    loading: byId('discrepanciesLoading'), loadingText: byId('discrepanciesLoadingText'),
    toasts: byId('discrepanciesToasts'),
};
const state = {
    token: localStorage.getItem(tokenKey),
    identity: readJson(identityKey),
    response: null,
    page: 1,
};

class ApiError extends Error {
    constructor(message, status = 0) { super(message); this.status = status; }
}
function readJson(key) {
    try { return JSON.parse(localStorage.getItem(key) || 'null'); } catch { return null; }
}
function capabilities() { return { ...(state.identity?.capacidades || {}), ...(state.identity || {}) }; }
function can(permission) { return state.identity?.rol === 'administrador' || capabilities()[permission] === true; }
function hasModule(module) {
    const modules = capabilities().modulos_acceso;
    return !Array.isArray(modules) || modules.includes(module);
}
function escapeHtml(value) {
    return String(value ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&#039;');
}
function statusText(value) {
    if (value === 'pallet_no_encontrado') return 'Pallet no encontrado';
    return String(value || '').replaceAll('_', ' ').replace(/^./, (letter) => letter.toUpperCase());
}
function formatDate(value) {
    const date = new Date(value);
    return value && !Number.isNaN(date.getTime())
        ? new Intl.DateTimeFormat('es-CL', { dateStyle: 'short', timeStyle: 'short' }).format(date)
        : '—';
}
function errorMessage(data, fallback) {
    return Object.values(data?.errors || {}).flat()[0] || data?.message || fallback;
}
function clearSession() {
    localStorage.removeItem(tokenKey);
    localStorage.removeItem(identityKey);
    window.location.replace('/oficina/accesos');
}
function setBusy(active, message = 'Consultando discrepancias…') {
    elements.loadingText.textContent = message;
    elements.loading.classList.toggle('is-hidden', !active);
    elements.loading.setAttribute('aria-hidden', String(!active));
}
function toast(message, error = false) {
    const node = document.createElement('div');
    node.className = `toast${error ? ' toast--error' : ''}`;
    node.textContent = message;
    elements.toasts.append(node);
    window.setTimeout(() => node.remove(), 5000);
}
async function api(path, options = {}) {
    const headers = new Headers(options.headers || {});
    headers.set('Accept', 'application/json');
    headers.set('Authorization', `Bearer ${state.token}`);
    if (options.body) headers.set('Content-Type', 'application/json');
    let response;
    try { response = await fetch(path, { ...options, headers }); }
    catch { throw new ApiError('No fue posible conectar con Laravel.'); }
    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
        if (response.status === 401) clearSession();
        throw new ApiError(errorMessage(data, 'No fue posible completar la operación.'), response.status);
    }
    return data;
}
function queryParameters() {
    const values = Object.fromEntries(new FormData(elements.filters));
    const parameters = new URLSearchParams({ pagina: String(state.page) });
    Object.entries(values).forEach(([key, value]) => {
        const normalized = String(value || '').trim();
        if (normalized) parameters.set(key, normalized);
    });
    return parameters;
}
function locationText(location) {
    if (!location) return 'Sin ubicación';
    return [cameraReferenceLabel({ codigo: location.codigo, nombre: location.camara }), location.posicion].filter(Boolean).join(' · ');
}
function restrictionText(action, restriction) {
    const labels = {
        tarea_en_proceso: 'Hay un pallet en movimiento: la única decisión segura es verificar y reanudar ese paso.',
        custodia_temporal_activa: 'Hay pallets fuera de banda: utiliza Retorno seguro para cerrar su custodia.',
        sin_custodia_temporal: 'No existen pallets extraídos que deban volver a banda.',
        plan_no_replanificable: 'Este objetivo no posee un planificador rolling para reconstruir el trabajo pendiente.',
    };
    return labels[restriction] ? `${action}: ${labels[restriction]}` : '';
}
function renderOpenActions(item) {
    const cancelRestriction = item.restricciones?.cancelar;
    const replanRestriction = item.restricciones?.replanificar;
    const returnRestriction = item.restricciones?.retorno_seguro;
    const restrictions = [
        restrictionText('Recalcular', replanRestriction),
        restrictionText('Retorno seguro', returnRestriction),
        restrictionText('Cancelar', cancelRestriction),
    ].filter(Boolean);
    return `<form class="discrepancy-resolution" data-resolution="${escapeHtml(item.id)}" data-version="${item.maniobra.version}">
        <label><span>Fundamento de la decisión *</span><textarea class="eui-input" name="resolucion" minlength="3" maxlength="500" required placeholder="Describe la verificación física realizada"></textarea></label>
        <div class="discrepancy-resolution__actions">
            <button class="eui-button eui-button--confirm" name="accion" value="reanudar_maniobra" type="submit">Reanudar ruta verificada</button>
            <button class="eui-button" name="accion" value="replanificar_sufijo" type="submit"${replanRestriction ? ' disabled' : ''}>Recalcular trabajo pendiente</button>
            <button class="eui-button eui-button--critical" name="accion" value="retorno_seguro" type="submit"${returnRestriction ? ' disabled' : ''}>Ordenar retorno seguro</button>
            <button class="eui-button discrepancy-cancel" name="accion" value="cancelar_maniobra" type="submit"${cancelRestriction ? ' disabled' : ''}>Cancelar sin reemplazo</button>
        </div>
        ${restrictions.length ? `<ul class="discrepancy-restriction">${restrictions.map((restriction) => `<li>${escapeHtml(restriction)}</li>`).join('')}</ul>` : ''}
    </form>`;
}
function renderResolution(item) {
    return `<div class="discrepancy-card__audit"><p><strong>${escapeHtml(statusText(item.accion_resolucion))}</strong><br>${escapeHtml(item.resolucion || 'Sin fundamento registrado')}</p><small>${escapeHtml(item.resuelta_por?.nombre || 'Supervisor')} · ${escapeHtml(formatDate(item.resuelta_at))}</small></div>`;
}
function renderCard(item) {
    const open = item.estado === 'abierta';
    return `<article class="discrepancy-card discrepancy-card--${open ? 'open' : 'resolved'}">
        <header class="discrepancy-card__header">
            <div><small>${escapeHtml(statusText(item.tipo))} · ${escapeHtml(formatDate(item.reportada_at))}</small><h3>${escapeHtml(item.maniobra.titulo)}</h3></div>
            <span class="discrepancy-status discrepancy-status--${open ? 'open' : 'resolved'}">${escapeHtml(statusText(item.estado))}</span>
        </header>
        <div class="discrepancy-card__grid">
            <div><span>FOLIO</span><strong>${escapeHtml(item.folio.numero)}</strong><small>${escapeHtml(item.maniobra.plan.titulo)}</small></div>
            <div><span>PASO ${item.tarea.secuencia ?? '—'}</span><strong>${escapeHtml(statusText(item.tarea.tipo_paso))}</strong><small>${escapeHtml(statusText(item.tarea.estado))}</small></div>
            <div><span>ORIGEN</span><strong>${escapeHtml(locationText(item.tarea.origen))}</strong><small>Estado confirmado previo</small></div>
            <div><span>DESTINO</span><strong>${escapeHtml(locationText(item.tarea.destino))}</strong><small>Instrucción cuestionada</small></div>
        </div>
        <p class="discrepancy-card__detail"><strong>Reporte:</strong> ${escapeHtml(item.detalle || 'Sin detalle adicional')} · ${escapeHtml(item.reportada_por?.nombre || 'Camarero')} · ${escapeHtml(item.dispositivo?.nombre || item.dispositivo?.codigo || 'Tablet')}</p>
        ${open ? renderOpenActions(item) : renderResolution(item)}
    </article>`;
}
function render() {
    const response = state.response || {};
    const items = response.data || [];
    const meta = response.meta || {};
    elements.open.textContent = String(response.resumen?.abiertas || 0);
    elements.resolved.textContent = String(response.resumen?.resueltas || 0);
    elements.missing.textContent = String(response.resumen?.pallets_no_encontrados || 0);
    elements.results.textContent = `${meta.total || 0} ${Number(meta.total) === 1 ? 'registro' : 'registros'}`;
    elements.list.innerHTML = items.length
        ? items.map(renderCard).join('')
        : '<div class="eui-empty discrepancies-empty"><p class="eui-empty__title">Sin discrepancias para esta selección</p><p class="eui-empty__description">No existen casos que coincidan con los filtros actuales.</p></div>';
    const current = Number(meta.pagina_actual || 1);
    const last = Number(meta.ultima_pagina || 1);
    elements.page.textContent = `Página ${current} de ${last}`;
    elements.previous.disabled = current <= 1;
    elements.next.disabled = current >= last;
}
async function load() {
    setBusy(true);
    elements.error.textContent = '';
    try {
        state.response = await api(`/api/discrepancias-maniobra?${queryParameters()}`);
        render();
    } catch (error) {
        if (error instanceof ApiError && error.status === 403) {
            window.location.replace('/oficina/accesos');
        } else {
            elements.error.textContent = error.message;
        }
    } finally { setBusy(false); }
}
async function resolve(form, action) {
    const resolution = String(new FormData(form).get('resolucion') || '').trim();
    if (resolution.length < 3) {
        toast('Escribe el fundamento de la decisión.', true);
        form.querySelector('textarea')?.focus();
        return;
    }
    const progress = {
        reanudar_maniobra: 'Reanudando ruta verificada…',
        replanificar_sufijo: 'Recalculando trabajo pendiente…',
        retorno_seguro: 'Publicando retorno seguro…',
        cancelar_maniobra: 'Cancelando maniobra…',
    };
    const success = {
        reanudar_maniobra: 'Ruta verificada y reanudada.',
        replanificar_sufijo: 'Sufijo descartado y recalculado.',
        retorno_seguro: 'Retorno seguro enviado a la misma tablet.',
        cancelar_maniobra: 'Maniobra cancelada sin reemplazo.',
    };
    setBusy(true, progress[action] || 'Aplicando decisión supervisada…');
    try {
        await api(`/api/discrepancias-maniobra/${form.dataset.resolution}/resolver`, {
            method: 'POST',
            body: JSON.stringify({ accion: action, version_maniobra: Number(form.dataset.version), resolucion: resolution }),
        });
        toast(success[action] || 'Decisión supervisada aplicada.');
        await load();
    } catch (error) {
        toast(error.message, true);
        if (error instanceof ApiError && error.status === 409) await load();
    } finally { setBusy(false); }
}

elements.filters.addEventListener('submit', (event) => { event.preventDefault(); state.page = 1; void load(); });
elements.clear.addEventListener('click', () => { elements.filters.reset(); state.page = 1; void load(); });
elements.reload.addEventListener('click', () => void load());
elements.previous.addEventListener('click', () => { if (state.page > 1) { state.page -= 1; void load(); } });
elements.next.addEventListener('click', () => {
    if (state.page < Number(state.response?.meta?.ultima_pagina || 1)) { state.page += 1; void load(); }
});
elements.list.addEventListener('submit', (event) => {
    const form = event.target.closest('[data-resolution]');
    if (!form) return;
    event.preventDefault();
    void resolve(form, event.submitter?.value || '');
});

async function boot() {
    if (!state.token || !state.identity || !can('puede_supervisar') || !hasModule('frigorifico.camaras')) {
        window.location.replace('/oficina/accesos');
        return;
    }
    const name = state.identity.nombre || state.identity.name || 'Usuario';
    byId('officeUserName').textContent = name;
    byId('officeUserRole').textContent = statusText(state.identity.rol || 'supervisión');
    byId('officeInitials').textContent = name.split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0]).join('').toUpperCase();
    byId('officeLogoutButton').addEventListener('click', clearSession);
    elements.app.classList.remove('is-hidden');
    await load();
}

void boot();
