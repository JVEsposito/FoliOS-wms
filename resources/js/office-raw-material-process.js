import { createOperationalPoller } from './shared/operational-poller';

const byId = (id) => document.getElementById(id);
const elements = {
    access: byId('officeAccess'), app: byId('officeApp'), login: byId('officeLoginForm'),
    loginError: byId('officeLoginError'), logout: byId('officeLogoutButton'),
    userName: byId('officeUserName'), userRole: byId('officeUserRole'), initials: byId('officeInitials'),
    reload: byId('reloadButton'), season: byId('seasonDescription'), filters: byId('processFilters'),
    list: byId('processLotList'), openLots: byId('openLotsCount'), available: byId('availableBinsCount'),
    delivered: byId('deliveredBinsCount'), completed: byId('completedLotsCount'),
    pendingReturns: byId('pendingReturnsCount'), returnedBins: byId('returnedBinsCount'),
    recoveredKilos: byId('recoveredKilosCount'), pendingLocation: byId('pendingLocationCount'),
    panelEyebrow: byId('panelEyebrow'), panelTitle: byId('panelTitle'), panelDescription: byId('panelDescription'),
    sections: [...document.querySelectorAll('[data-process-section]')],
    deliveryDialog: byId('deliveryDialog'), deliveryForm: byId('deliveryForm'), deliveryTitle: byId('deliveryTitle'),
    deliveryDescription: byId('deliveryDescription'), deliverySummary: byId('deliverySummary'), deliveryError: byId('deliveryError'),
    returnDialog: byId('returnDialog'), returnForm: byId('returnForm'), returnTitle: byId('returnTitle'),
    returnDescription: byId('returnDescription'), returnSummary: byId('returnSummary'), returnResults: byId('returnResults'),
    addReturnResult: byId('addReturnResult'), returnOrigins: byId('returnOrigins'), returnError: byId('returnError'),
    locationDialog: byId('locationDialog'), locationForm: byId('locationForm'), locationTitle: byId('locationTitle'),
    locationDescription: byId('locationDescription'), locationError: byId('locationError'),
    loading: byId('officeLoading'), loadingText: byId('officeLoadingText'), toasts: byId('officeToasts'),
};
const keys = { token: 'estiba_wms_office_token', identity: 'estiba_wms_office_identity' };
const state = {
    token: localStorage.getItem(keys.token), identity: readJson(keys.identity), summary: null,
    lots: [], catalogs: { tipos_resultado: [], camaras: [] }, selected: null, section: 'entregas', poller: null,
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
function formatNumber(value) { return new Intl.NumberFormat('es-CL').format(Number(value || 0)); }
function formatKilos(value) {
    return value === null || value === undefined ? 'Sin informar' : `${new Intl.NumberFormat('es-CL', { maximumFractionDigits: 3 }).format(Number(value))} kg`;
}
function formatDate(value) {
    if (!value) return '—';
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? '—' : new Intl.DateTimeFormat('es-CL', { dateStyle: 'short', timeStyle: 'short' }).format(date);
}
function label(value) {
    const labels = {
        disponible_proceso: 'Directo desde Hidrocooler', asignado_camara: 'Disponible', entrega_parcial_proceso: 'Entrega parcial', entregado_proceso: 'Completado',
        pendiente: 'Pendiente de retorno', parcial: 'Retorno parcial', completado: 'Retorno cerrado',
        pendiente_ubicacion: 'Pendiente de ubicación', ubicado_camara: 'Ubicado en cámara', anulado: 'Anulado',
        camarero_frio: 'Camarero', supervisor_frio: 'Supervisor de frío', administrador: 'Administrador',
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
    item.className = `toast${error ? ' toast--error' : ''}`; item.textContent = message;
    elements.toasts.append(item); window.setTimeout(() => item.remove(), 5000);
}
function persist(payload) {
    state.token = payload.token; state.identity = payload.usuario;
    localStorage.setItem(keys.token, payload.token);
    localStorage.setItem(keys.identity, JSON.stringify(payload.usuario));
}
function clearSession() {
    state.token = null; state.identity = null; state.lots = []; state.summary = null;
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
function showApp() {
    if (!can('puede_consultar_fruta_proceso')) return false;
    elements.access.classList.add('is-hidden'); elements.app.classList.remove('is-hidden');
    const name = state.identity?.nombre || 'Usuario';
    elements.userName.textContent = name; elements.userRole.textContent = label(state.identity?.rol);
    elements.initials.textContent = name.split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0]).join('').toUpperCase();
    return true;
}
function renderSummary() {
    const summary = state.summary || {};
    elements.openLots.textContent = formatNumber(summary.lotes_abiertos);
    elements.available.textContent = formatNumber(summary.bins_disponibles);
    elements.delivered.textContent = formatNumber(summary.bins_entregados);
    elements.completed.textContent = formatNumber(summary.lotes_completados);
    elements.pendingReturns.textContent = formatNumber(summary.entregas_pendientes_retorno);
    elements.returnedBins.textContent = formatNumber(summary.bins_retornados);
    elements.recoveredKilos.textContent = formatNumber(summary.kilos_recuperados);
    elements.pendingLocation.textContent = formatNumber(summary.sublotes_pendientes_ubicacion);
    elements.season.textContent = summary.temporada
        ? `${summary.temporada.nombre} · ${summary.temporada.codigo}. Entregas y retornos vinculados al lote original.`
        : 'No existe una temporada global activa.';
}
function returnStatus(delivery) {
    const data = delivery.retorno || { estado: 'pendiente', bins_retornados: 0, kilos_recuperados: 0 };
    return `<div class="return-status-row"><span class="process-status">${escapeHtml(label(data.estado))}</span><span><strong>${formatNumber(data.bins_retornados)} bins</strong> · ${escapeHtml(formatKilos(data.kilos_recuperados))}</span></div>`;
}
function renderResult(result) {
    const location = result.camara ? `${result.camara.codigo} · ${result.camara.nombre}` : label(result.estado);
    return `<div class="return-result"><div><strong>${escapeHtml(result.numero_sublote)} · ${escapeHtml(result.nombre_resultado)}</strong><small>${formatNumber(result.cantidad_bins)} bins · ${escapeHtml(formatKilos(result.kilos_netos))}</small></div><span class="process-status">${escapeHtml(location)}</span>${result.puede_ubicar ? `<button data-locate="${escapeHtml(result.id)}" type="button">Ubicar</button>` : '<span></span>'}</div>`;
}
function renderReturnMovement(movement) {
    const origins = movement.origenes || [];
    const originMarkup = origins.length
        ? `<div class="return-movement__origins"><strong>Orígenes:</strong> ${origins.map((origin) => `${escapeHtml(origin.numero_lote || 'Lote')} · ${escapeHtml(origin.numero_orden)}${origin.cierra_entrega ? ' (cerrado)' : ' (abierto)'}`).join(' · ')}</div>`
        : '';
    return `<article class="return-movement${movement.anulado ? ' is-void' : ''}"><div class="return-movement__heading"><div><strong>${escapeHtml(movement.numero)}${movement.cierra_entrega ? ' · Cierre del viaje' : ' · Retorno parcial'}</strong><small>${escapeHtml(movement.registrado_por?.nombre)} · ${escapeHtml(formatDate(movement.registrado_at))}${movement.anulado ? ` · Anulado: ${escapeHtml(movement.motivo_anulacion)}` : ''}</small></div>${movement.puede_anular ? `<button data-annul-return="${escapeHtml(movement.id)}" type="button">Anular retorno</button>` : ''}</div>${originMarkup}${movement.resultados.map(renderResult).join('')}</article>`;
}
function renderDelivery(delivery) {
    const destination = `${delivery.linea_proceso} · turno ${delivery.turno} · orden ${delivery.numero_orden}`;
    const actions = [
        delivery.retorno?.puede_registrar ? `<button class="return-button" data-return="${escapeHtml(delivery.id)}" type="button">Registrar retorno</button>` : '',
        delivery.puede_anular ? `<button data-annul="${escapeHtml(delivery.id)}" type="button">Anular entrega</button>` : '',
    ].filter(Boolean).join('');
    return `<article class="process-delivery${delivery.anulado ? ' is-void' : ''}"><strong>${escapeHtml(delivery.cantidad_envases)} bins</strong><div><strong>${escapeHtml(destination)}</strong><small>${escapeHtml(delivery.entregado_por?.nombre)} · ${escapeHtml(formatDate(delivery.entregado_at))} · ${escapeHtml(formatKilos(delivery.kilos_enviados))}${delivery.anulado ? ` · Anulada: ${escapeHtml(delivery.motivo_anulacion)}` : ''}</small></div><div class="process-delivery-actions">${actions}</div></article>`;
}
function renderDeliveryLots() {
    return state.lots.map((lot) => {
        const complete = lot.progreso.disponibles === 0;
        const product = [lot.producto.especie, lot.producto.variedad, lot.producto.calibre].filter(Boolean).join(' · ');
        const origin = lot.camara
            ? `${lot.camara.codigo} · ${lot.camara.nombre}`
            : 'Salida directa desde Hidrocooler';
        const originLabel = lot.camara ? 'CÁMARA' : 'ORIGEN OPERACIONAL';
        return `<article class="process-lot-card${complete ? ' is-complete' : ''}"><div class="process-lot-card__heading"><div><h3>${escapeHtml(lot.numero_lote)}</h3><p>${escapeHtml(lot.cliente?.nombre)} · recepción ${escapeHtml(lot.recepcion?.numero_recepcion)}</p></div><span class="process-status">${escapeHtml(label(lot.estado))}</span></div><div class="process-facts"><div><span>${escapeHtml(originLabel)}</span><strong>${escapeHtml(origin)}</strong></div><div><span>PRODUCTO</span><strong>${escapeHtml(product)}</strong></div><div><span>ORIGEN</span><strong>CSG ${escapeHtml(lot.producto.csg)} · ${escapeHtml(lot.producto.predio)}</strong></div></div><div class="process-progress"><div class="process-progress__labels"><span><strong>${formatNumber(lot.progreso.entregados)}/${formatNumber(lot.progreso.total)}</strong> bins entregados</span><span>${formatNumber(lot.progreso.disponibles)} disponibles</span></div><div class="process-progress__track"><span style="width:${Math.min(100, Number(lot.progreso.porcentaje))}%"></span></div></div>${!complete && can('puede_entregar_fruta_proceso') ? `<div class="process-actions"><button class="primary-button" data-deliver="${escapeHtml(lot.id)}" type="button">+ Registrar viaje</button></div>` : ''}<details class="process-history"><summary>Historial de viajes (${lot.entregas.length})</summary><div class="process-history__list">${lot.entregas.length ? lot.entregas.map(renderDelivery).join('') : '<small>Este lote aún no registra entregas.</small>'}</div></details></article>`;
    }).join('');
}
function renderReturnCards() {
    const cards = state.lots.flatMap((lot) => lot.entregas.filter((delivery) => !delivery.anulado).map((delivery) => ({ lot, delivery })));
    return cards.map(({ lot, delivery }) => `<article class="process-lot-card return-card"><div class="process-lot-card__heading"><div><h3>${escapeHtml(lot.numero_lote)} · ${escapeHtml(delivery.numero_orden)}</h3><p>${escapeHtml(delivery.linea_proceso)} · turno ${escapeHtml(delivery.turno)} · ${escapeHtml(formatDate(delivery.entregado_at))}</p></div><span class="process-status">${escapeHtml(label(delivery.retorno.estado))}</span></div><div class="process-facts"><div><span>ENVIADO</span><strong>${formatNumber(delivery.cantidad_envases)} bins · ${escapeHtml(formatKilos(delivery.kilos_enviados))}</strong></div><div><span>RETORNADO</span><strong>${formatNumber(delivery.retorno.bins_retornados)} bins · ${escapeHtml(formatKilos(delivery.retorno.kilos_recuperados))}</strong></div><div><span>MERMA</span><strong>${escapeHtml(formatKilos(delivery.retorno.merma_kilos))}</strong></div></div>${returnStatus(delivery)}${delivery.retorno.puede_registrar ? `<div class="process-actions"><button class="primary-button" data-return="${escapeHtml(delivery.id)}" type="button">+ Registrar retorno</button></div>` : ''}<details class="process-history" open><summary>Retornos y sublotes (${delivery.retorno.movimientos.length})</summary><div class="process-history__list">${delivery.retorno.movimientos.length ? delivery.retorno.movimientos.map(renderReturnMovement).join('') : '<small>Packing todavía no registra retornos para este viaje.</small>'}</div></details></article>`).join('');
}
function renderLots() {
    const html = state.section === 'entregas' ? renderDeliveryLots() : renderReturnCards();
    elements.list.innerHTML = html || `<div class="process-empty">No existen ${state.section === 'entregas' ? 'lotes de bins' : 'viajes entregados a Packing'} para esta selección.</div>`;
}
function renderSection() {
    elements.sections.forEach((button) => button.classList.toggle('is-active', button.dataset.processSection === state.section));
    const returns = state.section === 'retornos';
    elements.panelEyebrow.textContent = returns ? 'CONTROL DE PRODUCCIÓN INTERNA' : 'CONTROL DE DESPACHO INTERNO';
    elements.panelTitle.textContent = returns ? 'Retornos desde Packing' : 'Lotes disponibles para proceso';
    elements.panelDescription.textContent = returns ? 'Clasifica cada devolución, genera sublotes y asígnalos a cámara.' : 'Registra viajes desde cámara MP o directamente desde Hidrocooler.';
}
async function load({ silent = false } = {}) {
    const query = new URLSearchParams(new FormData(elements.filters)); query.set('per_page', '200');
    if (state.section === 'retornos') query.set('estado', '');
    if (!silent) setBusy(true, 'Actualizando fruta a proceso…');
    try {
        let summary;
        let lots;
        if (silent) {
            summary = await api('/api/materia-prima/fruta-proceso/resumen');
            if (summary.revision && summary.revision === state.summary?.revision) {
                state.summary = summary;
                renderSummary();
                return;
            }
            lots = await api(`/api/materia-prima/fruta-proceso/lotes?${query}`);
        } else {
            [summary, lots] = await Promise.all([
                api('/api/materia-prima/fruta-proceso/resumen'),
                api(`/api/materia-prima/fruta-proceso/lotes?${query}`),
            ]);
        }
        state.summary = summary; state.lots = lots.data || [];
        renderSummary(); renderSection(); renderLots();
    } catch (error) {
        if (!silent) toast(error.message, true);
        else throw error;
    }
    finally { if (!silent) setBusy(false); }
}
async function loadCatalogs() {
    state.catalogs = await api('/api/materia-prima/fruta-proceso/catalogos');
}
function findDelivery(deliveryId) {
    for (const lot of state.lots) {
        const delivery = lot.entregas.find((item) => item.id === deliveryId);
        if (delivery) return { lot, delivery };
    }
    return null;
}
function findResult(sublotId) {
    for (const lot of state.lots) for (const delivery of lot.entregas) for (const movement of delivery.retorno.movimientos) {
        const result = movement.resultados.find((item) => item.id === sublotId);
        if (result) return { lot, delivery, movement, result };
    }
    return null;
}
function openDelivery(lotId) {
    const lot = state.lots.find((item) => item.id === lotId); if (!lot) return;
    state.selected = { lot }; elements.deliveryForm.reset(); elements.deliveryForm.elements.lote_id.value = lot.id;
    elements.deliveryForm.elements.cantidad_envases.max = String(lot.progreso.disponibles);
    elements.deliveryTitle.textContent = `Entregar ${lot.numero_lote}`;
    elements.deliveryDescription.textContent = lot.camara
        ? `${lot.progreso.disponibles} bins disponibles en ${lot.camara.codigo}.`
        : `${lot.progreso.disponibles} bins disponibles directamente desde Hidrocooler.`;
    elements.deliverySummary.innerHTML = `<div><span>LOTE</span><strong>${escapeHtml(lot.numero_lote)}</strong></div><div><span>PROGRESO</span><strong>${formatNumber(lot.progreso.entregados)}/${formatNumber(lot.progreso.total)}</strong></div><div><span>SALDO MÁXIMO</span><strong>${formatNumber(lot.progreso.disponibles)} bins</strong></div>`;
    elements.deliveryError.textContent = ''; elements.deliveryDialog.showModal();
}
async function submitDelivery() {
    const data = new FormData(elements.deliveryForm); const quantity = Number(data.get('cantidad_envases'));
    if (!Number.isInteger(quantity) || quantity < 1 || quantity > Number(state.selected?.lot?.progreso.disponibles || 0)) {
        elements.deliveryError.textContent = `Ingresa una cantidad entre 1 y ${state.selected?.lot?.progreso.disponibles || 0}.`; return;
    }
    const kilos = String(data.get('kilos_enviados') || '').trim();
    const payload = { operacion_id: uuid(), camara_origen_id: state.selected.lot.camara?.id ?? null, cantidad_envases: quantity, kilos_enviados: kilos ? Number(kilos) : null, linea_proceso: String(data.get('linea_proceso') || '').trim(), turno: data.get('turno'), numero_orden: String(data.get('numero_orden') || '').trim(), observacion: String(data.get('observacion') || '').trim() || null };
    if (!payload.linea_proceso || !payload.turno || !payload.numero_orden) { elements.deliveryError.textContent = 'Completa línea, turno y número de orden.'; return; }
    setBusy(true, 'Registrando viaje…');
    try { await api(`/api/materia-prima/fruta-proceso/lotes/${state.selected.lot.id}/entregas`, { method: 'POST', body: JSON.stringify(payload) }); elements.deliveryDialog.close(); toast('Viaje registrado y saldo actualizado.'); await load(); }
    catch (error) { elements.deliveryError.textContent = error.message; }
    finally { setBusy(false); }
}
function addResultRow() {
    const row = document.createElement('div'); row.className = 'return-result-row';
    row.innerHTML = `<label><span>Resultado *</span><select data-field="tipo" required><option value="">Seleccionar</option>${state.catalogs.tipos_resultado.map((type) => `<option value="${escapeHtml(type.id)}">${escapeHtml(type.nombre)}</option>`).join('')}</select></label><label><span>Nombre específico</span><input data-field="nombre" maxlength="100" placeholder="Obligatorio para Otro"></label><label><span>Bins *</span><input data-field="bins" type="number" min="1" max="100000" inputmode="numeric" required></label><label><span>Kilos netos</span><input data-field="kilos" type="number" min="0.001" max="999999999.999" step="0.001" inputmode="decimal" placeholder="Opcional"></label><button class="remove-result" type="button" aria-label="Quitar resultado">×</button>`;
    elements.returnResults.append(row);
}
function eligibleReturnOrigins() {
    return state.lots.flatMap((lot) => lot.entregas
        .filter((delivery) => !delivery.anulado && delivery.retorno?.puede_registrar)
        .map((delivery) => ({ lot, delivery })));
}
function renderReturnOrigins(primaryDeliveryId) {
    const origins = eligibleReturnOrigins();
    elements.returnOrigins.innerHTML = origins.map(({ lot, delivery }) => {
        const primary = delivery.id === primaryDeliveryId;
        return `<article class="return-origin${primary ? ' is-primary' : ''}">
            <label class="return-origin__selection">
                <input data-return-origin value="${escapeHtml(delivery.id)}" type="checkbox"${primary ? ' checked disabled' : ''}>
                <span><strong>${escapeHtml(lot.numero_lote)} · ${escapeHtml(delivery.numero_orden)}</strong><small>${escapeHtml(delivery.linea_proceso)} · turno ${escapeHtml(delivery.turno)} · ${formatNumber(delivery.cantidad_envases)} bins${primary ? ' · viaje principal' : ''}</small></span>
            </label>
            <label class="return-origin__close">
                <input data-return-close value="${escapeHtml(delivery.id)}" type="checkbox">
                <span>Cerrar este viaje</span>
            </label>
        </article>`;
    }).join('');
}
function collectReturnOrigins() {
    return [...elements.returnOrigins.querySelectorAll('[data-return-origin]')]
        .filter((input) => input.checked)
        .map((input) => ({
            entrega_fruta_proceso_id: input.value,
            cierra_entrega: elements.returnOrigins.querySelector(`[data-return-close][value="${CSS.escape(input.value)}"]`)?.checked === true,
        }));
}
function openReturn(deliveryId) {
    const selected = findDelivery(deliveryId); if (!selected) return;
    state.selected = selected; elements.returnForm.reset(); elements.returnResults.innerHTML = ''; addResultRow(); renderReturnOrigins(deliveryId);
    elements.returnForm.elements.entrega_id.value = deliveryId;
    elements.returnTitle.textContent = `Retorno de ${selected.lot.numero_lote}`;
    elements.returnDescription.textContent = `${selected.delivery.linea_proceso} · turno ${selected.delivery.turno} · orden ${selected.delivery.numero_orden}.`;
    elements.returnSummary.innerHTML = `<div><span>VIAJE</span><strong>${formatNumber(selected.delivery.cantidad_envases)} bins</strong></div><div><span>KILOS ENVIADOS</span><strong>${escapeHtml(formatKilos(selected.delivery.kilos_enviados))}</strong></div><div><span>RETORNADO</span><strong>${formatNumber(selected.delivery.retorno.bins_retornados)} bins</strong></div>`;
    elements.returnError.textContent = ''; elements.returnDialog.showModal();
}
function collectResults() {
    return [...elements.returnResults.querySelectorAll('.return-result-row')].map((row) => {
        const kilos = row.querySelector('[data-field="kilos"]').value.trim();
        return { tipo_resultado_packing_id: row.querySelector('[data-field="tipo"]').value, nombre_resultado: row.querySelector('[data-field="nombre"]').value.trim() || null, cantidad_bins: Number(row.querySelector('[data-field="bins"]').value), kilos_netos: kilos ? Number(kilos) : null };
    });
}
async function submitReturn() {
    const results = collectResults();
    if (!results.length || results.some((item) => !item.tipo_resultado_packing_id || !Number.isInteger(item.cantidad_bins) || item.cantidad_bins < 1)) { elements.returnError.textContent = 'Completa el tipo y la cantidad de bins de cada resultado.'; return; }
    const otherType = state.catalogs.tipos_resultado.find((item) => item.codigo === 'otro');
    if (results.some((item) => item.tipo_resultado_packing_id === otherType?.id && !item.nombre_resultado)) { elements.returnError.textContent = 'Especifica el nombre del resultado clasificado como Otro.'; return; }
    const data = new FormData(elements.returnForm);
    const origins = collectReturnOrigins();
    if (!origins.length || !origins.some((origin) => origin.entrega_fruta_proceso_id === state.selected.delivery.id)) { elements.returnError.textContent = 'El retorno debe conservar el viaje principal y al menos un origen.'; return; }
    const payload = { operacion_id: uuid(), entregas: origins, observacion: String(data.get('observacion') || '').trim() || null, resultados: results };
    setBusy(true, 'Creando sublotes de Packing…');
    try { await api(`/api/materia-prima/fruta-proceso/entregas/${state.selected.delivery.id}/retornos`, { method: 'POST', body: JSON.stringify(payload) }); elements.returnDialog.close(); toast('Retorno registrado; los sublotes quedaron pendientes de ubicación.'); await load(); }
    catch (error) { elements.returnError.textContent = error.message; }
    finally { setBusy(false); }
}
function openLocation(sublotId) {
    const selected = findResult(sublotId); if (!selected) return;
    state.selected = selected; elements.locationForm.reset(); elements.locationForm.elements.sublote_id.value = sublotId;
    elements.locationTitle.textContent = `Ubicar ${selected.result.numero_sublote}`;
    elements.locationDescription.textContent = `${selected.result.nombre_resultado} · ${selected.result.cantidad_bins} bins.`;
    elements.locationForm.elements.camara_id.innerHTML = `<option value="">Seleccionar cámara</option>${state.catalogs.camaras.map((camera) => `<option value="${escapeHtml(camera.id)}">${escapeHtml(camera.codigo)} · ${escapeHtml(camera.nombre)}</option>`).join('')}`;
    elements.locationError.textContent = ''; elements.locationDialog.showModal();
}
async function submitLocation() {
    const data = new FormData(elements.locationForm); const cameraId = String(data.get('camara_id') || '');
    if (!cameraId) { elements.locationError.textContent = 'Selecciona una cámara de materia prima.'; return; }
    setBusy(true, 'Asignando sublote a cámara…');
    try { await api(`/api/materia-prima/fruta-proceso/sublotes/${state.selected.result.id}/ubicar`, { method: 'POST', body: JSON.stringify({ operacion_id: uuid(), camara_id: cameraId, observacion: String(data.get('observacion') || '').trim() || null }) }); elements.locationDialog.close(); toast('Sublote ubicado en cámara.'); await load(); }
    catch (error) { elements.locationError.textContent = error.message; }
    finally { setBusy(false); }
}
async function annul(path, success, busyText) {
    const reason = window.prompt('Motivo de la anulación (mínimo 5 caracteres):');
    if (reason === null) return;
    if (reason.trim().length < 5) { toast('Ingresa un motivo de al menos 5 caracteres.', true); return; }
    setBusy(true, busyText);
    try { await api(path, { method: 'POST', body: JSON.stringify({ operacion_id: uuid(), motivo: reason.trim() }) }); toast(success); await load(); }
    catch (error) { toast(error.message, true); }
    finally { setBusy(false); }
}

elements.login.addEventListener('submit', async (event) => {
    event.preventDefault(); elements.loginError.textContent = ''; setBusy(true, 'Validando acceso…');
    try { const data = new FormData(elements.login); const payload = await api('/api/acceso-oficina', { method: 'POST', body: JSON.stringify({ email: data.get('email'), password: data.get('password') }) }); persist(payload); if (!showApp()) { clearSession(); throw new ApiError('Tu perfil no tiene acceso a Fruta a proceso.', 403); } await Promise.all([loadCatalogs(), load()]); }
    catch (error) { elements.loginError.textContent = error.message; }
    finally { setBusy(false); }
});
elements.logout.addEventListener('click', async () => { try { await api('/api/acceso-oficina', { method: 'DELETE' }); } catch {} clearSession(); });
elements.reload.addEventListener('click', () => void Promise.all([loadCatalogs(), load()]));
elements.filters.addEventListener('submit', (event) => { event.preventDefault(); void load(); });
elements.sections.forEach((button) => button.addEventListener('click', () => { state.section = button.dataset.processSection; void load(); }));
elements.list.addEventListener('click', (event) => {
    const deliver = event.target.closest('[data-deliver]'); if (deliver) openDelivery(deliver.dataset.deliver);
    const createReturn = event.target.closest('[data-return]'); if (createReturn) openReturn(createReturn.dataset.return);
    const locate = event.target.closest('[data-locate]'); if (locate) openLocation(locate.dataset.locate);
    const annulDelivery = event.target.closest('[data-annul]'); if (annulDelivery) void annul(`/api/materia-prima/fruta-proceso/entregas/${annulDelivery.dataset.annul}/anular`, 'Entrega anulada; el saldo del lote fue restituido.', 'Anulando entrega…');
    const annulReturn = event.target.closest('[data-annul-return]'); if (annulReturn) void annul(`/api/materia-prima/fruta-proceso/retornos/${annulReturn.dataset.annulReturn}/anular`, 'Retorno anulado; sus sublotes quedaron invalidados.', 'Anulando retorno…');
});
elements.deliveryForm.addEventListener('submit', (event) => { event.preventDefault(); if (event.submitter?.value === 'cancel') { elements.deliveryDialog.close(); return; } void submitDelivery(); });
elements.returnForm.addEventListener('submit', (event) => { event.preventDefault(); if (event.submitter?.value === 'cancel') { elements.returnDialog.close(); return; } void submitReturn(); });
elements.locationForm.addEventListener('submit', (event) => { event.preventDefault(); if (event.submitter?.value === 'cancel') { elements.locationDialog.close(); return; } void submitLocation(); });
elements.addReturnResult.addEventListener('click', addResultRow);
elements.returnResults.addEventListener('click', (event) => { const remove = event.target.closest('.remove-result'); if (remove && elements.returnResults.children.length > 1) remove.closest('.return-result-row').remove(); });
elements.returnOrigins.addEventListener('change', (event) => {
    const origin = event.target.closest('[data-return-origin]');
    if (!origin) return;
    const close = elements.returnOrigins.querySelector(`[data-return-close][value="${CSS.escape(origin.value)}"]`);
    if (close && !origin.checked) close.checked = false;
});

if (state.token && state.identity && showApp()) void Promise.all([loadCatalogs(), load()]); else clearSession();
state.poller = createOperationalPoller(
    () => load({ silent: true }),
    {
        intervalMs: 30000,
        canRun: () => Boolean(state.token)
            && !elements.app.classList.contains('is-hidden')
            && ![elements.deliveryDialog, elements.returnDialog, elements.locationDialog].some((dialog) => dialog.open),
    },
);
state.poller.start();
