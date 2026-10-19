import {
    clientSummary,
    countText,
    detailText,
    formatAmount,
    monthLabel,
    pendingText,
    periodText,
    priceOrigin,
    quantityText,
    totalsText,
} from './shared/finance-prebilling-logic';
import { formatPrice } from './shared/finance-tariffs-logic';

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
    kpis: byId('prebillingKpis'),
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
};

function readJson(key) {
    try { return JSON.parse(localStorage.getItem(key) || 'null'); } catch { return null; }
}

function escapeHtml(value) {
    return String(value ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&#039;');
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
    if (state.token) headers.set('Authorization', `Bearer ${state.token}`);
    let response;
    try {
        response = await fetch(path, { ...options, headers });
    } catch {
        return { ok: false, status: 0, data: { message: 'No fue posible conectar con el servidor.' } };
    }
    const data = await response.json().catch(() => ({}));
    if (response.status === 401) clearSession();

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

function renderStatus(data) {
    const pending = Number(data.resumen?.hechos_sin_precio || 0) + Number(data.resumen?.hechos_sin_cliente || 0);
    const last = data.ultima_captura;
    elements.status.className = `fin-status${pending ? ' fin-status--warning' : ''}`;
    elements.status.innerHTML = `<strong>${escapeHtml(periodText(data))}</strong>`
        + `<small>${pending ? `${escapeHtml(countText(pending, 'hecho queda pendiente', 'hechos quedan pendientes'))} (sin precio o sin cliente) y no se valorizan. ` : ''}`
        + `${last ? `Última lectura de la operación: ${escapeHtml(formatDateTime(last.iniciada_at))}` : 'Todavía no hay lecturas de la operación.'}</small>`;
}

function formatDateTime(value) {
    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? '—' : new Intl.DateTimeFormat('es-CL', { dateStyle: 'short', timeStyle: 'short' }).format(date);
}

function renderKpis(data) {
    const summary = data.resumen || {};
    const cards = [
        { label: 'Clientes', value: String(summary.clientes || 0), note: 'con montos en el mes' },
        ...(data.totales || []).map((total) => ({ label: `Total ${total.moneda}`, value: formatAmount(total), note: 'suma de las líneas' })),
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

function clientCard(client) {
    const lines = client.lineas || [];
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

async function load() {
    busy(true, 'Calculando la prefactura…');
    try {
        const response = await api(`/api/finanzas/prefactura?mes=${encodeURIComponent(month())}`);
        if (!response.ok) {
            toast(errorText(response.data, 'No fue posible calcular la prefactura.'), true);
            return;
        }
        state.data = response.data;
        state.details.clear();
        renderStatus(state.data);
        renderKpis(state.data);
        renderClients();
        renderUnlinked(state.data);
    } finally {
        busy(false);
    }
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
}

elements.login.addEventListener('submit', async (event) => {
    event.preventDefault();
    elements.loginError.textContent = '';
    const response = await api('/api/acceso-oficina', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
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
    const button = event.target.closest('[data-prebilling-detail]');
    if (button) openDetail(button.dataset.prebillingDetail, button.dataset.prebillingLine);
});
elements.detail.addEventListener('click', (event) => {
    if (event.target === elements.detail || event.target.closest('[data-fin-close]')) elements.detail.close();
});

if (state.token && showApp()) start();
