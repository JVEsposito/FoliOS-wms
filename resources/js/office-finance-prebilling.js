import {
    acceptText,
    adjustmentLabel,
    adjustmentNote,
    clientSummary,
    closeSummary,
    closureCaptureText,
    closureText,
    countText,
    decisionText,
    detailText,
    differenceQuantity,
    differenceSide,
    factChangeText,
    factReason,
    formatAmount,
    formatMoment,
    monthLabel,
    pendingCloseWarning,
    pendingText,
    periodText,
    priceChangeText,
    priceOrigin,
    quantityText,
    reopeningText,
    reviewText,
    signedAmount,
    totalsText,
} from './shared/finance-prebilling-logic';
import { formatPrice } from './shared/finance-tariffs-logic';

/** Hechos visibles por diferencia; el resto queda en «Ver más». */
const VISIBLE_FACTS = 5;

const byId = (id) => document.getElementById(id);
const elements = {
    access: byId('officeAccess'),
    app: byId('officeApp'),
    login: byId('officeLoginForm'),
    loginError: byId('officeLoginError'),
    userName: byId('officeUserName'),
    userRole: byId('officeUserRole'),
    initials: byId('officeInitials'),
    logout: byId('officeLogoutButton'),
    month: byId('prebillingMonth'),
    exportButton: byId('prebillingExport'),
    status: byId('prebillingStatus'),
    closure: byId('prebillingClosure'),
    review: byId('prebillingReview'),
    kpis: byId('prebillingKpis'),
    differences: byId('prebillingDifferences'),
    differencesNote: byId('prebillingDifferencesNote'),
    differencesList: byId('prebillingDifferencesList'),
    decisions: byId('prebillingDecisions'),
    decisionsTitle: byId('prebillingDecisionsTitle'),
    decisionsList: byId('prebillingDecisionsList'),
    filters: byId('prebillingFilters'),
    search: byId('prebillingSearch'),
    clients: byId('prebillingClients'),
    unlinked: byId('prebillingUnlinked'),
    unlinkedList: byId('prebillingUnlinkedList'),
    detail: byId('prebillingDetail'),
    detailEyebrow: byId('prebillingDetailEyebrow'),
    detailTitle: byId('prebillingDetailTitle'),
    detailSummary: byId('prebillingDetailSummary'),
    detailList: byId('prebillingDetailList'),
    closeDialog: byId('prebillingCloseDialog'),
    closeForm: byId('prebillingCloseForm'),
    closeTitle: byId('prebillingCloseTitle'),
    closeSummary: byId('prebillingCloseSummary'),
    closeCapture: byId('prebillingCloseCapture'),
    closePending: byId('prebillingClosePending'),
    closePendingText: byId('prebillingClosePendingText'),
    closeConfirm: byId('prebillingCloseConfirm'),
    closeMessage: byId('prebillingCloseMessage'),
    closeSubmit: byId('prebillingCloseSubmit'),
    reasonDialog: byId('prebillingReasonDialog'),
    reasonForm: byId('prebillingReasonForm'),
    reasonEyebrow: byId('prebillingReasonEyebrow'),
    reasonTitle: byId('prebillingReasonTitle'),
    reasonSummary: byId('prebillingReasonSummary'),
    reasonLabel: byId('prebillingReasonLabel'),
    reasonText: byId('prebillingReasonText'),
    reasonNote: byId('prebillingReasonNote'),
    reasonMessage: byId('prebillingReasonMessage'),
    reasonSubmit: byId('prebillingReasonSubmit'),
    loading: byId('officeLoading'),
    loadingText: byId('officeLoadingText'),
    toasts: byId('officeToasts'),
};
const keys = { token: 'estiba_wms_office_token', identity: 'estiba_wms_office_identity' };
const state = {
    token: localStorage.getItem(keys.token),
    identity: readJson(keys.identity),
    data: null,
    details: new Map(),
    differences: null,
    review: [],
    reason: null,
    // Respuestas que llegan tarde (otro mes, o el mes cambió de estado) no pisan las nuevas.
    loads: 0,
    differenceLoads: 0,
};

function readJson(key) {
    try { return JSON.parse(localStorage.getItem(key) || 'null'); } catch { return null; }
}

function escapeHtml(value) {
    return String(value ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&#039;');
}

function capitalize(text) {
    return text.charAt(0).toUpperCase() + text.slice(1);
}

function busy(active, text = 'Procesando…') {
    elements.loadingText.textContent = text;
    elements.loading.classList.toggle('is-hidden', !active);
    elements.loading.setAttribute('aria-hidden', String(!active));
}

function toast(message, error = false) {
    const node = document.createElement('div');
    node.className = `toast${error ? ' toast--error' : ''}`;
    node.textContent = message;
    elements.toasts.append(node);
    window.setTimeout(() => node.remove(), 6000);
}

function clearSession() {
    localStorage.removeItem(keys.token);
    localStorage.removeItem(keys.identity);
    location.reload();
}

async function api(path, options = {}) {
    const headers = new Headers(options.headers || {});
    headers.set('Accept', 'application/json');
    if (options.body) headers.set('Content-Type', 'application/json');
    if (state.token) headers.set('Authorization', `Bearer ${state.token}`);
    let response;
    try {
        response = await fetch(path, { ...options, headers });
    } catch {
        return { ok: false, status: 0, data: { message: 'No fue posible conectar con el servidor.' } };
    }
    const data = await response.json().catch(() => ({}));
    if (response.status === 401) clearSession();
    if (response.status === 429) data.message = 'Demasiadas solicitudes seguidas. Espere un minuto e intente de nuevo.';

    return { ok: response.ok, status: response.status, data };
}

function errorText(data, fallback) {
    return Object.values(data?.errors || {}).flat()[0] || data?.message || fallback;
}

function currentMonth() {
    const now = new Date();

    return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
}

function month() {
    return elements.month.value || currentMonth();
}

function can(permission) {
    return state.data?.permisos?.[permission] === true;
}

function renderStatus(data) {
    const pending = Number(data.resumen?.hechos_sin_precio || 0) + Number(data.resumen?.hechos_sin_cliente || 0);
    const closed = data.estado === 'cerrado';
    const last = data.ultima_captura;
    const pendingNote = !pending ? '' : closed
        ? `${countText(pending, 'hecho quedó', 'hechos quedaron')} sin cobrar en el cierre (sin precio o sin cliente). `
        : `${countText(pending, 'hecho queda pendiente', 'hechos quedan pendientes')} (sin precio o sin cliente) y no se valorizan. `;
    elements.status.className = `fin-status${pending ? ' fin-status--warning' : ''}`;
    elements.status.innerHTML = `<strong>${escapeHtml(periodText(data))}</strong>`
        + `<small>${escapeHtml(pendingNote)}`
        + `${last ? `Última lectura de la operación: ${escapeHtml(formatMoment(last.iniciada_at))}.` : 'Todavía no hay lecturas de la operación.'}</small>`;
}

/** Estado del cierre del mes y lo que este usuario puede hacer con él. */
function renderClosure(data) {
    const closed = data.estado === 'cerrado';
    const label = closed ? 'Cerrado' : (!data.corte ? 'Futuro' : (data.a_la_fecha ? 'En curso' : 'Abierto'));
    const notes = [];
    if (closed) notes.push(closureCaptureText(data.cierre));
    else if (data.para_cerrar?.posible) notes.push('Al cerrarlo, lo que se cobra queda congelado.');
    if (closed && can('reabrir') && !data.para_reabrir?.posible && data.para_reabrir?.motivo) notes.push(data.para_reabrir.motivo);
    // La última reapertura explica la versión vigente; las anteriores quedan contadas.
    const [lastReopening, ...earlier] = data.reaperturas || [];
    if (lastReopening) notes.push(reopeningText(lastReopening) + (earlier.length ? ` Antes se reabrió ${countText(earlier.length, 'vez', 'veces')}.` : ''));

    let action = '';
    if (closed && can('reabrir') && data.para_reabrir?.posible) {
        action = '<button class="secondary-button" type="button" data-prebilling-reopen>Reabrir el mes…</button>';
    } else if (!closed && can('cerrar') && data.para_cerrar?.posible) {
        action = `<button class="primary-button" type="button" data-prebilling-close>Cerrar ${escapeHtml(monthLabel(data.periodo.mes))}…</button>`;
    }

    elements.closure.hidden = false;
    elements.closure.className = `fin-cierre${closed ? ' is-cerrado' : ''}`;
    elements.closure.innerHTML = '<div class="fin-cierre__estado">'
        + `<span class="fin-chip fin-chip--${closed ? 'cerrado' : 'abierto'}">${label}</span>`
        + `<div><strong>${escapeHtml(closureText(data))}</strong>${notes.map((note) => `<small>${escapeHtml(note)}</small>`).join('')}</div>`
        + '</div>'
        + (action ? `<div class="fin-cierre__acciones">${action}</div>` : '');
}

/** Meses cerrados con diferencias pendientes, para ir a revisarlas. */
function renderReview() {
    const months = state.review || [];
    elements.review.hidden = !months.length;
    elements.review.innerHTML = months.length
        ? '<strong>Diferencias por revisar en meses cerrados</strong>'
            + `<div class="fin-revision__meses">${months.map((item) => `<button class="fin-boton" type="button" data-prebilling-month="${escapeHtml(item.mes)}"${item.mes === state.data?.periodo?.mes ? ' aria-current="true"' : ''}>${escapeHtml(capitalize(reviewText(item)))}</button>`).join('')}</div>`
        : '';
}

function renderKpis(data) {
    const summary = data.resumen || {};
    const cards = [
        { label: 'Clientes', value: String(summary.clientes || 0), note: 'con montos en el mes' },
        ...(data.totales || []).map((total) => ({ label: `Total ${total.moneda}`, value: formatAmount(total), note: summary.ajustes ? 'líneas y ajustes' : 'suma de las líneas' })),
        ...(summary.ajustes ? [{ label: 'Ajustes', value: String(summary.ajustes), note: 'de meses cerrados' }] : []),
        { label: 'Sin precio', value: String(summary.hechos_sin_precio || 0), note: 'hechos sin valorizar', alert: summary.hechos_sin_precio > 0 },
        { label: 'Sin cliente', value: String(summary.hechos_sin_cliente || 0), note: 'hechos sin vincular', alert: summary.hechos_sin_cliente > 0 },
    ];
    elements.kpis.innerHTML = cards.map((card) => `<div class="fin-kpi fin-kpi--dato${card.alert ? ' is-pendiente' : ''}">`
        + `<span>${escapeHtml(card.label)}</span><strong>${escapeHtml(card.value)}</strong><small>${escapeHtml(card.note)}</small></div>`).join('');
}

function lineRow(client, line) {
    return '<tr>'
        + `<td class="fin-c-servicio">${escapeHtml(line.servicio_nombre)}<small>${escapeHtml(line.unidad_nombre)}</small></td>`
        + `<td class="fin-c-precio-aplicado">${escapeHtml(formatPrice({ moneda: line.moneda, moneda_simbolo: line.simbolo, precio: line.precio }))}<small>${escapeHtml(priceOrigin(line))}</small></td>`
        + `<td class="fin-num fin-c-cantidad">${escapeHtml(quantityText(line))}<small>${escapeHtml(countText(line.hechos, 'hecho'))}</small></td>`
        + `<td class="fin-num fin-c-monto"><strong>${escapeHtml(formatAmount({ simbolo: line.simbolo, moneda: line.moneda, monto: line.monto }))}</strong></td>`
        + `<td class="fin-c-acciones"><button class="fin-boton" type="button" data-prebilling-detail="${escapeHtml(client.cliente_id)}" data-prebilling-line="${line.tarifa_id}">Hechos</button></td>`
        + '</tr>';
}

function adjustmentRow(adjustment) {
    const price = adjustment.precio ? formatPrice({ moneda: adjustment.moneda, moneda_simbolo: adjustment.simbolo, precio: adjustment.precio }) : 'Sin precio vigente';

    return '<tr>'
        + `<td class="fin-c-servicio">${escapeHtml(adjustmentLabel(adjustment))}<small>${escapeHtml(`${adjustment.servicio_nombre}, ${adjustment.unidad_nombre}`)}</small></td>`
        + `<td class="fin-c-precio-aplicado">${escapeHtml(price)}<small>${escapeHtml(adjustmentNote(adjustment))}</small></td>`
        + `<td class="fin-num fin-c-cantidad">${escapeHtml(differenceQuantity(adjustment))}</td>`
        + `<td class="fin-num fin-c-monto"><strong>${escapeHtml(signedAmount(adjustment))}</strong></td>`
        + `<td class="fin-c-acciones">${adjustment.puede_deshacerse && can('cerrar') ? `<button class="fin-boton fin-boton--anular" type="button" data-adjustment-undo="${adjustment.id}">Deshacer…</button>` : ''}</td>`
        + '</tr>';
}

function clientCard(client) {
    const lines = client.lineas || [];
    const adjustments = client.ajustes || [];
    const pending = client.sin_precio || [];

    return `<details class="fin-cliente" data-prebilling-client="${escapeHtml(client.cliente_id)}">`
        + '<summary>'
        + `<span class="fin-cliente__nombre">${escapeHtml(client.cliente_nombre)}</span>`
        + `<strong class="fin-cliente__total">${escapeHtml(totalsText(client.totales))}</strong>`
        + `<small class="${pending.length ? 'fin-alerta' : ''}">${escapeHtml(clientSummary(client))}</small>`
        + '</summary>'
        + (lines.length ? '<div class="fin-table-scroll"><table class="fin-table fin-table--prefactura">'
            + '<thead><tr><th>Servicio</th><th>Precio aplicado</th><th class="fin-num">Cantidad</th><th class="fin-num">Monto</th><th>Detalle</th></tr></thead>'
            + `<tbody>${lines.map((line) => lineRow(client, line)).join('')}</tbody></table></div>` : '')
        + (adjustments.length ? '<div class="fin-ajustes"><p class="fin-ajustes__titulo">Ajustes de meses cerrados</p><div class="fin-table-scroll"><table class="fin-table fin-table--prefactura">'
            + '<thead><tr><th>Ajuste</th><th>Precio y decisión</th><th class="fin-num">Cantidad</th><th class="fin-num">Monto</th><th>Acción</th></tr></thead>'
            + `<tbody>${adjustments.map(adjustmentRow).join('')}</tbody></table></div></div>` : '')
        + (pending.length ? `<ul class="fin-pendientes">${pending.map((item) => `<li class="fin-alerta">${escapeHtml(pendingText(item))}</li>`).join('')}</ul>`
            + '<a class="fin-boton fin-boton--enlace" href="/oficina/finanzas/tarifario">Completar en el tarifario</a>' : '')
        + '</details>';
}

function renderClients() {
    const data = state.data;
    const term = elements.search.value.trim().toLocaleLowerCase('es');
    const clients = (data?.clientes || []).filter((client) => !term || client.cliente_nombre.toLocaleLowerCase('es').includes(term));
    if (!clients.length) {
        elements.clients.innerHTML = `<p class="fin-vacio">${term ? 'Ningún cliente coincide con la búsqueda.' : (data?.corte ? 'No hay hechos valorizados en este mes.' : 'Este mes todavía no empieza.')}</p>`;
        return;
    }
    elements.clients.innerHTML = clients.map(clientCard).join('');
}

function renderUnlinked(data) {
    const items = data.sin_cliente || [];
    elements.unlinked.hidden = !items.length;
    elements.unlinkedList.innerHTML = items.map((item) => `<li class="fin-alerta">${escapeHtml(item.nombre)}: ${escapeHtml(countText(item.hechos, 'hecho'))}</li>`).join('');
}

function factItem(fact) {
    return `<li><strong>${escapeHtml(fact.referencia || fact.origen_id)}</strong> <span>${escapeHtml(factChangeText(fact))}</span>`
        + `<small>${escapeHtml(factReason(fact))}</small></li>`;
}

function differenceCard(item, index, destination) {
    const facts = item.hechos || [];
    const visible = facts.slice(0, VISIBLE_FACTS);
    const rest = facts.slice(VISIBLE_FACTS);
    const negative = String(item.diferencia.monto).trim().startsWith('-');

    return `<article class="fin-diferencia" data-difference="${index}">`
        + '<header class="fin-diferencia__titulo">'
        + `<div><strong>${escapeHtml(item.cliente_nombre)}</strong><small>${escapeHtml(`${item.servicio_nombre}, ${item.unidad_nombre}`)}</small></div>`
        + `<strong class="fin-diferencia__monto${negative ? ' is-abono' : ''}">${escapeHtml(signedAmount({ simbolo: item.simbolo, moneda: item.moneda, monto: item.diferencia.monto }))}</strong>`
        + '</header>'
        + '<dl class="fin-diferencia__cifras">'
        + `<div><dt>Cobrado</dt><dd>${escapeHtml(differenceSide(item.antes, item))}</dd></div>`
        + `<div><dt>Hoy</dt><dd>${escapeHtml(differenceSide(item.ahora, item))}</dd></div>`
        + `<div><dt>Diferencia</dt><dd>${escapeHtml(differenceQuantity(item.diferencia))}</dd></div>`
        + '</dl>'
        + (item.cambio_precio ? `<p class="fin-diferencia__precio">${escapeHtml(priceChangeText(item))}</p>` : '')
        + (visible.length ? `<ul class="fin-diferencia__hechos">${visible.map(factItem).join('')}</ul>` : '')
        + (rest.length ? `<details class="fin-diferencia__mas"><summary>Ver ${escapeHtml(countText(rest.length, 'hecho más', 'hechos más'))}</summary><ul class="fin-diferencia__hechos">${rest.map(factItem).join('')}</ul></details>` : '')
        + (item.hechos_total > facts.length ? `<p class="fin-diferencia__nota">Se muestran ${escapeHtml(countText(facts.length, 'hecho'))} de ${escapeHtml(String(item.hechos_total))}.</p>` : '')
        + (can('cerrar') ? '<footer class="fin-acciones">'
            + `<button class="fin-boton fin-boton--principal" type="button" data-difference-accept="${index}">Aceptar: ${negative ? 'abonar' : 'cobrar'} en ${escapeHtml(monthLabel(destination))}</button>`
            + `<button class="fin-boton fin-boton--anular" type="button" data-difference-discard="${index}">Descartar…</button>`
            + '</footer>' : '')
        + '</article>';
}

function decisionItem(decision) {
    const undo = can('cerrar') && decision.puede_deshacerse;

    return `<li class="${decision.anulada_at ? 'is-anulada' : ''}">`
        + `<strong>${escapeHtml(`${decision.cliente_nombre} · ${decision.servicio_nombre}, ${decision.unidad_nombre}: ${differenceQuantity(decision)} · ${signedAmount(decision)}`)}</strong>`
        + `<small>${escapeHtml(decisionText(decision))}</small>`
        + (undo ? `<button class="fin-boton fin-boton--anular" type="button" data-decision-undo="${decision.id}">Deshacer…</button>` : '')
        + '</li>';
}

/** Diferencias de un mes cerrado: se calculan aparte para no demorar la prefactura. */
function renderDifferences() {
    const closed = state.data?.estado === 'cerrado';
    elements.differences.hidden = !closed;
    if (!closed) return;

    const review = state.differences;
    if (!review || review.error) {
        elements.differencesList.innerHTML = `<p class="fin-vacio">${escapeHtml(review?.error || 'Comparando el mes cerrado con la operación y el tarifario de hoy…')}</p>`;
        elements.decisions.hidden = true;
        return;
    }

    const destination = review.primer_mes_abierto;
    elements.differencesNote.textContent = 'Lo que cambió en la operación o en el tarifario después de cerrar el mes. '
        + `Aceptada, una diferencia se cobra o se abona en ${monthLabel(destination)}; descartada, queda con su motivo.`;
    const items = review.diferencias || [];
    elements.differencesList.innerHTML = items.length
        ? items.map((item, index) => differenceCard(item, index, destination)).join('')
        : '<p class="fin-vacio">Sin diferencias pendientes: lo cobrado coincide con la operación y el tarifario de hoy.</p>';
    const decisions = review.decisiones || [];
    elements.decisions.hidden = !decisions.length;
    elements.decisionsTitle.textContent = `Decisiones tomadas (${decisions.length})`;
    elements.decisionsList.innerHTML = decisions.map(decisionItem).join('');
}

function render(data) {
    state.data = data;
    state.details.clear();
    renderStatus(data);
    renderClosure(data);
    renderReview();
    renderKpis(data);
    renderClients();
    renderUnlinked(data);
    renderDifferences();
}

async function load() {
    const sequence = ++state.loads;
    busy(true, 'Calculando la prefactura…');
    try {
        const response = await api(`/api/finanzas/prefactura?mes=${encodeURIComponent(month())}`);
        if (sequence !== state.loads) return;
        if (!response.ok) {
            toast(errorText(response.data, 'No fue posible calcular la prefactura.'), true);
            return;
        }
        state.differences = null;
        render(response.data);
    } finally {
        if (sequence === state.loads) busy(false);
    }
    if (sequence === state.loads) loadDifferences();
}

async function loadDifferences() {
    const key = state.data?.periodo?.mes;
    if (state.data?.estado !== 'cerrado') return;
    const sequence = ++state.differenceLoads;
    state.differences = null;
    renderDifferences();
    const response = await api(`/api/finanzas/prefactura/diferencias?mes=${encodeURIComponent(key)}`);
    if (sequence !== state.differenceLoads || state.data?.periodo?.mes !== key || state.data?.estado !== 'cerrado') return;
    state.differences = response.ok ? response.data : { error: errorText(response.data, 'No fue posible comparar el mes cerrado.') };
    renderDifferences();
}

async function loadReview() {
    const response = await api('/api/finanzas/prefactura/diferencias/resumen');
    state.review = response.ok ? (response.data.data || []) : [];
    renderReview();
}

async function openDetail(clientId, lineId) {
    const client = state.data?.clientes.find((item) => item.cliente_id === clientId);
    const line = client?.lineas.find((item) => String(item.tarifa_id) === String(lineId));
    if (!client || !line) return;
    elements.detailEyebrow.textContent = client.cliente_nombre.toUpperCase();
    elements.detailTitle.textContent = `${line.servicio_nombre}, ${line.unidad_nombre}`;
    elements.detailSummary.textContent = `${formatPrice({ moneda: line.moneda, moneda_simbolo: line.simbolo, precio: line.precio })} · ${quantityText(line)} · ${formatAmount({ simbolo: line.simbolo, moneda: line.moneda, monto: line.monto })}`;
    elements.detailList.innerHTML = '<li>Cargando…</li>';
    elements.detail.showModal();

    const key = `${month()}|${clientId}|${lineId}`;
    if (!state.details.has(key)) {
        const response = await api(`/api/finanzas/prefactura/detalle?mes=${encodeURIComponent(month())}&cliente=${encodeURIComponent(clientId)}&tarifa=${encodeURIComponent(lineId)}`);
        if (!response.ok) {
            elements.detailList.innerHTML = `<li>${escapeHtml(errorText(response.data, 'No fue posible cargar el detalle.'))}</li>`;
            return;
        }
        state.details.set(key, response.data.data);
    }
    const detail = state.details.get(key)?.lineas.find((item) => String(item.tarifa_id) === String(lineId))?.detalle || [];
    elements.detailList.innerHTML = detail.length
        ? detail.map((item) => `<li><strong>${escapeHtml(item.referencia || `Hecho ${item.hecho_id}`)}</strong><small>${escapeHtml(detailText(item))}</small></li>`).join('')
        : '<li>Sin hechos.</li>';
}

function openClose() {
    const data = state.data;
    if (!data) return;
    const warning = pendingCloseWarning(data.resumen);
    elements.closeTitle.textContent = `Cerrar ${monthLabel(data.periodo.mes)}`;
    elements.closeSummary.textContent = closeSummary(data);
    elements.closeCapture.textContent = data.ultima_captura
        ? `Última lectura de la operación: ${formatMoment(data.ultima_captura.iniciada_at)}. Lo que llegue después se revisará como diferencia.`
        : 'Todavía no hay lecturas de la operación: el mes se cerraría sin hechos capturados.';
    elements.closePending.hidden = !warning;
    elements.closePendingText.textContent = warning;
    elements.closeConfirm.checked = false;
    elements.closeMessage.textContent = '';
    elements.closeDialog.showModal();
}

async function submitClose(event) {
    event.preventDefault();
    const data = state.data;
    if (!elements.closePending.hidden && !elements.closeConfirm.checked) {
        elements.closeMessage.textContent = 'Confirme que esos hechos quedarán sin cobrar, o complételos antes de cerrar.';
        return;
    }
    elements.closeSubmit.disabled = true;
    busy(true, 'Cerrando el mes…');
    try {
        const response = await api('/api/finanzas/prefactura/cierres', {
            method: 'POST',
            body: JSON.stringify({ mes: data.periodo.mes, confirmar_pendientes: elements.closeConfirm.checked }),
        });
        if (!response.ok) {
            elements.closeMessage.textContent = errorText(response.data, 'No fue posible cerrar el mes.');
            // Aparecieron pendientes desde que se cargó la prefactura: se piden de nuevo.
            if (response.data?.errors?.confirmar_pendientes) {
                elements.closePending.hidden = false;
                elements.closePendingText.textContent = response.data.errors.confirmar_pendientes[0];
                elements.closeMessage.textContent = '';
            }
            return;
        }
        elements.closeDialog.close();
        state.differences = null;
        render(response.data);
        toast(`${capitalize(monthLabel(data.periodo.mes))} quedó cerrado.`);
    } finally {
        elements.closeSubmit.disabled = false;
        busy(false);
    }
    loadDifferences();
    loadReview();
}

/** Diálogo de motivo para reabrir, decidir una diferencia o deshacer una decisión. */
function openReason(config) {
    state.reason = config;
    elements.reasonEyebrow.textContent = config.eyebrow;
    elements.reasonTitle.textContent = config.title;
    elements.reasonSummary.textContent = config.summary;
    elements.reasonLabel.textContent = config.required ? 'Motivo' : 'Nota (opcional)';
    elements.reasonText.value = '';
    elements.reasonText.required = config.required;
    elements.reasonText.placeholder = config.placeholder || '';
    elements.reasonNote.textContent = config.note || '';
    elements.reasonMessage.textContent = '';
    elements.reasonSubmit.textContent = config.submit;
    elements.reasonSubmit.className = config.danger ? 'danger-button' : 'primary-button';
    elements.reasonDialog.showModal();
}

async function submitReason(event) {
    event.preventDefault();
    const config = state.reason;
    if (!config) return;
    const reason = elements.reasonText.value.trim();
    if (config.required && reason.length < 5) {
        elements.reasonMessage.textContent = 'Explique el motivo en al menos 5 caracteres.';
        return;
    }
    elements.reasonSubmit.disabled = true;
    busy(true, config.busy);
    let result;
    try {
        result = await config.run(reason);
    } finally {
        elements.reasonSubmit.disabled = false;
        busy(false);
    }
    if (!result.ok) {
        elements.reasonMessage.textContent = result.message;
        return;
    }
    elements.reasonDialog.close();
    toast(result.message);
}

function differenceSummary(item) {
    return `${item.cliente_nombre} · ${item.servicio_nombre}, ${item.unidad_nombre}: ${differenceQuantity(item.diferencia)} · `
        + `${signedAmount({ simbolo: item.simbolo, moneda: item.moneda, monto: item.diferencia.monto })}`;
}

async function decide(item, decision, reason) {
    const closedMonth = state.data.periodo.mes;
    const response = await api('/api/finanzas/prefactura/diferencias', {
        method: 'POST',
        body: JSON.stringify({
            mes: closedMonth,
            cliente_id: item.cliente_id,
            tarifa_id: item.tarifa_id,
            moneda: item.moneda,
            decision,
            ...item.diferencia,
            motivo: reason || null,
        }),
    });
    loadDifferences();
    loadReview();
    if (!response.ok) return { ok: false, message: errorText(response.data, 'No fue posible registrar la decisión.') };

    return {
        ok: true,
        message: decision === 'aceptada'
            ? `Diferencia aceptada: ${acceptText(item, response.data.data.mes_destino).toLocaleLowerCase('es')}`
            : 'Diferencia descartada con su motivo.',
    };
}

function openDifference(index, decision) {
    const review = state.differences;
    const item = review?.diferencias?.[index];
    if (!item) return;
    const accept = decision === 'aceptada';
    openReason({
        eyebrow: 'DIFERENCIA DESPUÉS DEL CIERRE',
        title: accept ? 'Aceptar la diferencia' : 'Descartar la diferencia',
        summary: differenceSummary(item),
        required: !accept,
        placeholder: accept ? 'Por ejemplo, la operación corrigió la salida del folio.' : 'Por ejemplo, ya se cobró con una nota de débito.',
        note: accept ? `${acceptText(item, review.primer_mes_abierto)} Mientras ese mes siga abierto, se puede deshacer.` : 'Queda registrada con su motivo y deja de estar pendiente.',
        submit: accept ? 'Aceptar' : 'Descartar',
        danger: !accept,
        busy: 'Registrando la decisión…',
        run: (reason) => decide(item, decision, reason),
    });
}

function openUndo(id, origin) {
    const decision = state.differences?.decisiones?.find((item) => String(item.id) === String(id));
    const adjustment = state.data?.clientes?.flatMap((client) => client.ajustes || []).find((item) => String(item.id) === String(id));
    const source = decision || (adjustment && { ...adjustment, mes: adjustment.mes_origen });
    if (!source) return;
    openReason({
        eyebrow: 'DECISIÓN',
        title: 'Deshacer la decisión',
        summary: `${source.cliente_nombre} · ${source.servicio_nombre}, ${source.unidad_nombre}: ${differenceQuantity(source)} · ${signedAmount(source)}`,
        required: true,
        placeholder: 'Por ejemplo, se aceptó por error.',
        note: `La diferencia vuelve a quedar pendiente en ${monthLabel(source.mes)}.`,
        submit: 'Deshacer',
        danger: true,
        busy: 'Deshaciendo la decisión…',
        run: async (reason) => {
            const response = await api(`/api/finanzas/prefactura/diferencias/${encodeURIComponent(id)}/anular`, { method: 'POST', body: JSON.stringify({ motivo: reason }) });
            if (!response.ok) return { ok: false, message: errorText(response.data, 'No fue posible deshacer la decisión.') };
            if (origin === 'ajuste') load(); else loadDifferences();
            loadReview();

            return { ok: true, message: `Decisión deshecha: la diferencia vuelve a estar pendiente en ${monthLabel(source.mes)}.` };
        },
    });
}

function openReopen() {
    const data = state.data;
    if (!data?.cierre) return;
    openReason({
        eyebrow: 'REAPERTURA',
        title: `Reabrir ${monthLabel(data.periodo.mes)}`,
        summary: closureText(data),
        required: true,
        placeholder: 'Por ejemplo, faltaba la recepción del día 30.',
        note: 'El mes vuelve a calcularse con los hechos y precios de hoy. Las decisiones sobre sus diferencias quedan sin efecto y, '
            + `al cerrarlo de nuevo, se guardará la versión ${data.cierre.version + 1}.`,
        submit: 'Reabrir el mes',
        danger: true,
        busy: 'Reabriendo el mes…',
        run: async (reason) => {
            const response = await api(`/api/finanzas/prefactura/cierres/${encodeURIComponent(data.periodo.mes)}/reabrir`, { method: 'POST', body: JSON.stringify({ motivo: reason }) });
            if (!response.ok) return { ok: false, message: errorText(response.data, 'No fue posible reabrir el mes.') };
            state.differences = null;
            render(response.data);
            loadReview();

            return { ok: true, message: `${capitalize(monthLabel(data.periodo.mes))} quedó abierto de nuevo.` };
        },
    });
}

async function exportExcel() {
    elements.exportButton.disabled = true;
    busy(true, 'Preparando el Excel…');
    try {
        const response = await fetch(`/api/finanzas/prefactura/exportar?mes=${encodeURIComponent(month())}`, {
            headers: { Accept: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', Authorization: `Bearer ${state.token}` },
        });
        if (response.status === 401) clearSession();
        if (!response.ok) {
            toast('No fue posible preparar el Excel de la prefactura.', true);
            return;
        }
        const name = /filename="?([^";]+)"?/.exec(response.headers.get('Content-Disposition') ?? '')?.[1] ?? `prefactura-${month()}.xlsx`;
        const url = URL.createObjectURL(await response.blob());
        const link = document.createElement('a');
        link.href = url;
        link.download = name;
        link.click();
        window.setTimeout(() => URL.revokeObjectURL(url), 1000);
        toast(`Excel de ${monthLabel(month())} descargado.`);
    } catch {
        toast('No fue posible conectar con el servidor.', true);
    } finally {
        elements.exportButton.disabled = false;
        busy(false);
    }
}

function showApp() {
    if (state.identity?.puede_consultar_finanzas !== true && state.identity?.capacidades?.puede_consultar_finanzas !== true) return false;
    elements.access.classList.add('is-hidden');
    elements.app.classList.remove('is-hidden');
    const name = state.identity?.nombre || 'Usuario';
    if (elements.userName) elements.userName.textContent = name;
    if (elements.userRole) elements.userRole.textContent = String(state.identity?.rol || '').replaceAll('_', ' ').replace(/^./, (letter) => letter.toUpperCase());
    if (elements.initials) elements.initials.textContent = name.split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0]).join('').toUpperCase();

    return true;
}

function start() {
    elements.month.value ||= currentMonth();
    load();
    loadReview();
}

elements.login.addEventListener('submit', async (event) => {
    event.preventDefault();
    elements.loginError.textContent = '';
    const response = await api('/api/acceso-oficina', {
        method: 'POST',
        body: JSON.stringify(Object.fromEntries(new FormData(elements.login))),
    });
    if (!response.ok) {
        elements.loginError.textContent = errorText(response.data, 'No fue posible ingresar.');
        return;
    }
    state.token = response.data.token;
    state.identity = response.data.usuario;
    localStorage.setItem(keys.token, state.token);
    localStorage.setItem(keys.identity, JSON.stringify(state.identity));
    if (!showApp()) {
        elements.loginError.textContent = 'Su perfil no tiene acceso a Finanzas.';
        return;
    }
    start();
});
elements.logout?.addEventListener('click', clearSession);
elements.month.addEventListener('change', load);
elements.exportButton.addEventListener('click', exportExcel);
elements.filters.addEventListener('submit', (event) => event.preventDefault());
elements.search.addEventListener('input', renderClients);
elements.clients.addEventListener('click', (event) => {
    const detail = event.target.closest('[data-prebilling-detail]');
    if (detail) openDetail(detail.dataset.prebillingDetail, detail.dataset.prebillingLine);
    const undo = event.target.closest('[data-adjustment-undo]');
    if (undo) openUndo(undo.dataset.adjustmentUndo, 'ajuste');
});
elements.closure.addEventListener('click', (event) => {
    if (event.target.closest('[data-prebilling-close]')) openClose();
    if (event.target.closest('[data-prebilling-reopen]')) openReopen();
});
elements.review.addEventListener('click', (event) => {
    const target = event.target.closest('[data-prebilling-month]');
    if (!target) return;
    elements.month.value = target.dataset.prebillingMonth;
    load();
});
elements.differences.addEventListener('click', (event) => {
    const accept = event.target.closest('[data-difference-accept]');
    if (accept) openDifference(Number(accept.dataset.differenceAccept), 'aceptada');
    const discard = event.target.closest('[data-difference-discard]');
    if (discard) openDifference(Number(discard.dataset.differenceDiscard), 'descartada');
    const undo = event.target.closest('[data-decision-undo]');
    if (undo) openUndo(undo.dataset.decisionUndo, 'decision');
});
elements.closeForm.addEventListener('submit', submitClose);
elements.reasonForm.addEventListener('submit', submitReason);
for (const dialog of [elements.detail, elements.closeDialog, elements.reasonDialog]) {
    dialog.addEventListener('click', (event) => {
        if (event.target === dialog || event.target.closest('[data-fin-close]')) dialog.close();
    });
}

if (state.token && showApp()) start();
