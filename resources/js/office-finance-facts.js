import {
    READ_ORIGINS,
    READ_STATES,
    VERSION_REASONS,
    factDetail,
    kpiLabel,
    kpiValue,
    measuresText,
    versionChanges,
} from './shared/finance-facts-logic';

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
    month: byId('financeMonth'),
    capture: byId('financeCaptureButton'),
    status: byId('financeStatus'),
    kpis: byId('financeKpis'),
    filters: byId('financeFilters'),
    client: byId('financeClient'),
    source: byId('financeSource'),
    factsBody: byId('financeFactsBody'),
    factsCount: byId('financeFactsCount'),
    readsBody: byId('financeReadsBody'),
    readsCount: byId('financeReadsCount'),
    readsHelp: byId('financeReadsHelp'),
    previous: byId('financePrevious'),
    next: byId('financeNext'),
    pageStatus: byId('financePageStatus'),
    history: byId('financeHistory'),
    historySource: byId('financeHistorySource'),
    historyTitle: byId('financeHistoryTitle'),
    historyList: byId('financeHistoryList'),
    loading: byId('officeLoading'),
    loadingText: byId('officeLoadingText'),
    toasts: byId('officeToasts'),
};
const keys = { token: 'estiba_wms_office_token', identity: 'estiba_wms_office_identity' };
const REFRESH_MS = 60_000;
const state = {
    token: localStorage.getItem(keys.token),
    identity: readJson(keys.identity),
    data: null,
    page: 1,
    timer: null,
    searchTimer: null,
};

function readJson(key) {
    try { return JSON.parse(localStorage.getItem(key) || 'null'); } catch { return null; }
}

function escapeHtml(value) {
    return String(value ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&#039;');
}

function formatDate(value, withTime = true) {
    if (!value) return '—';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '—';

    return new Intl.DateTimeFormat('es-CL', withTime ? { dateStyle: 'short', timeStyle: 'short' } : { dateStyle: 'short' }).format(date);
}

function formatTime(value) {
    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? '' : new Intl.DateTimeFormat('es-CL', { timeStyle: 'short' }).format(date);
}

function formatDay(value) {
    const [year, month, day] = String(value || '').split('-');

    return day ? `${day}-${month}-${year}` : '—';
}

function ago(value) {
    const minutes = Math.round((Date.now() - new Date(value).getTime()) / 60_000);
    if (!Number.isFinite(minutes)) return '';
    if (minutes < 1) return 'recién';
    if (minutes < 60) return `hace ${minutes} min`;
    const hours = Math.round(minutes / 60);

    return hours < 48 ? `hace ${hours} h` : `hace ${Math.round(hours / 24)} días`;
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
    if (options.body) headers.set('Content-Type', 'application/json');
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

function query() {
    const filters = Object.fromEntries(new FormData(elements.filters));
    const params = new URLSearchParams({ mes: elements.month.value || currentMonth(), pagina: String(state.page) });
    for (const [key, value] of Object.entries(filters)) {
        if (String(value).trim() !== '') params.set(key, String(value).trim());
    }

    return params.toString();
}

function renderStatus(data) {
    const last = data.ultima_captura;
    elements.capture.hidden = data.puede_capturar !== true;
    if (!last) {
        elements.status.className = 'fin-status fin-status--warning';
        elements.status.innerHTML = '<strong>Todavía no hay lecturas.</strong><small>La primera recorre toda la operación; después solo se lee lo que cambia.</small>';
        return;
    }
    const failed = last.estado !== 'completada';
    elements.status.className = `fin-status${failed ? ' fin-status--error' : ''}`;
    elements.status.innerHTML = `<strong>Última lectura ${escapeHtml(ago(last.iniciada_at))} · ${escapeHtml(READ_STATES[last.estado] || last.estado)}</strong>`
        + `<small>${escapeHtml(formatDate(last.iniciada_at))} · ${escapeHtml(READ_ORIGINS[last.origen] || last.origen)}${last.duracion_ms !== null ? ` · ${(last.duracion_ms / 1000).toLocaleString('es-CL', { maximumFractionDigits: 1 })} s` : ''}${last.error ? ` · ${escapeHtml(last.error)}` : ''}</small>`;
}

function renderKpis(data) {
    elements.kpis.innerHTML = (data.resumen || []).map((summary) => {
        const active = elements.source.value === summary.fuente;

        return `<button class="fin-kpi${active ? ' is-active' : ''}" type="button" data-fin-source="${escapeHtml(summary.fuente)}" aria-pressed="${active}">`
            + `<span>${escapeHtml(summary.nombre)}</span><strong>${escapeHtml(kpiValue(summary))}</strong>`
            + `<small>${escapeHtml(kpiLabel(summary))}</small>`
            + (summary.sin_cliente ? `<em>${escapeHtml(summary.sin_cliente)} sin cliente</em>` : '')
            + '</button>';
    }).join('');
}

function syncOptions(select, options, placeholder) {
    const current = select.value;
    select.innerHTML = `<option value="">${escapeHtml(placeholder)}</option>`
        + options.map((option) => `<option value="${escapeHtml(option.value)}">${escapeHtml(option.label)}</option>`).join('');
    if (options.some((option) => option.value === current)) select.value = current;
}

function factRow(fact) {
    // Una estadía muestra su ingreso y su salida; lo demás, su fecha operacional y la hora.
    const date = fact.fuente === 'estadia'
        ? `${escapeHtml(formatDay(fact.fecha_operacional))}<small>${escapeHtml(formatTime(fact.ocurrido_at))}${fact.termina_at ? ` → ${escapeHtml(formatDate(fact.termina_at))}` : ' · abierta'}</small>`
        : `${escapeHtml(formatDay(fact.fecha_operacional))}<small>${escapeHtml(formatTime(fact.ocurrido_at))}</small>`;
    const annulled = fact.estado === 'anulado';
    const client = fact.cliente_id
        ? escapeHtml(fact.cliente_nombre)
        : `${escapeHtml(fact.cliente_nombre || '—')}<small class="fin-alerta">Sin cliente vinculado</small>`;

    return `<tr class="${annulled ? 'is-annulled' : ''}">`
        + `<td>${date}</td>`
        + `<td>${escapeHtml(fact.fuente_nombre)}</td>`
        + `<td><strong>${escapeHtml(fact.referencia || '—')}</strong>${fact.temporada_codigo ? `<small>${escapeHtml(fact.temporada_codigo)}</small>` : ''}</td>`
        + `<td>${client}</td>`
        + `<td class="fin-num">${escapeHtml(measuresText(fact))}</td>`
        + `<td>${escapeHtml(factDetail(fact))}</td>`
        + `<td><button class="fin-version${annulled ? ' fin-version--annulled' : ''}" type="button" data-fin-history="${fact.id}">v${fact.version}${annulled ? ' · anulado' : ''}</button></td>`
        + '</tr>';
}

function render() {
    const data = state.data;
    if (!data) return;
    renderStatus(data);
    syncOptions(elements.source, (data.fuentes || []).map((source) => ({ value: source.clave, label: source.nombre })), 'Todas las fuentes');
    syncOptions(elements.client, (data.clientes || []).map((client) => ({ value: client.id ?? 'sin_cliente', label: `${client.nombre} (${client.hechos})` })), 'Todos los clientes');
    renderKpis(data);

    const facts = data.data || [];
    elements.factsCount.textContent = String(data.meta?.total ?? facts.length);
    elements.factsBody.innerHTML = facts.length
        ? facts.map(factRow).join('')
        : '<tr class="fin-empty"><td colspan="7">No hay hechos para este mes y filtros.</td></tr>';
    elements.pageStatus.textContent = `Página ${data.meta?.pagina ?? 1} de ${Math.max(1, data.meta?.paginas ?? 1)}`;
    elements.previous.disabled = (data.meta?.pagina ?? 1) <= 1;
    elements.next.disabled = (data.meta?.pagina ?? 1) >= (data.meta?.paginas ?? 1);
}

function renderReads(payload) {
    const reads = payload.data || [];
    const names = Object.fromEntries((payload.fuentes || []).map((source) => [source.clave, source.nombre]));
    elements.readsCount.textContent = String(reads.length);
    const settings = payload.configuracion || {};
    elements.readsHelp.textContent = settings.programada
        ? `Automática cada ${settings.cada_minutos} min, repasando ${settings.margen_minutos} min hacia atrás. Una vez al día se relee todo.`
        : 'La lectura automática está pausada en este servidor.';
    elements.readsBody.innerHTML = reads.length ? reads.map((read) => {
        // En el orden de las fuentes (la base devuelve el resumen ordenado por clave).
        const found = (payload.fuentes || []).map((source) => [source.clave, read.resumen?.[source.clave]]).filter(([, count]) => count).map(([key, count]) => {
            if (count.error) return `<li class="fin-alerta">${escapeHtml(names[key] || key)}: ${escapeHtml(count.error)}</li>`;
            const parts = [
                count.nuevos && `${count.nuevos} nuevos`,
                count.versiones && `${count.versiones} cambios`,
                count.anulados && `${count.anulados} anulados`,
                count.eliminados && `${count.eliminados} eliminados`,
            ].filter(Boolean);
            return parts.length ? `<li>${escapeHtml(names[key] || key)}: ${escapeHtml(parts.join(', '))}</li>` : '';
        }).join('');

        return '<tr>'
            + `<td>${escapeHtml(formatDate(read.iniciada_at))}${read.solicitada_por ? `<small>${escapeHtml(read.solicitada_por)}</small>` : ''}</td>`
            + `<td>${escapeHtml(READ_ORIGINS[read.origen] || read.origen)}${read.conciliacion ? '<small>Con conciliación</small>' : ''}</td>`
            + `<td><span class="fin-chip fin-chip--${escapeHtml(read.estado)}">${escapeHtml(READ_STATES[read.estado] || read.estado)}</span></td>`
            + `<td class="fin-num">${read.duracion_ms === null ? '—' : `${(read.duracion_ms / 1000).toLocaleString('es-CL', { maximumFractionDigits: 1 })} s`}</td>`
            + `<td>${found ? `<ul class="fin-found">${found}</ul>` : '<small>Sin cambios</small>'}</td>`
            + '</tr>';
    }).join('') : '<tr class="fin-empty"><td colspan="5">Todavía no hay lecturas.</td></tr>';
}

async function load({ quiet = false } = {}) {
    if (!quiet) busy(true, 'Consultando hechos…');
    try {
        const response = await api(`/api/finanzas/hechos?${query()}`);
        if (!response.ok) {
            if (!quiet) toast(errorText(response.data, 'No fue posible consultar los hechos.'), true);
            return;
        }
        state.data = response.data;
        render();
    } finally {
        if (!quiet) busy(false);
    }
}

async function loadReads() {
    const response = await api('/api/finanzas/capturas');
    if (response.ok) renderReads(response.data);
}

async function capture() {
    elements.capture.disabled = true;
    busy(true, 'Leyendo la operación…');
    try {
        const response = await api('/api/finanzas/capturas', { method: 'POST' });
        toast(errorText(response.data, response.ok ? 'Lectura completada.' : 'No fue posible leer la operación.'), !response.ok || response.data?.data?.estado !== 'completada');
        await Promise.all([load({ quiet: true }), loadReads()]);
    } finally {
        elements.capture.disabled = false;
        busy(false);
    }
}

async function openHistory(id) {
    busy(true, 'Cargando historial…');
    try {
        const response = await api(`/api/finanzas/hechos/${encodeURIComponent(id)}`);
        if (!response.ok) {
            toast(errorText(response.data, 'No fue posible cargar el historial.'), true);
            return;
        }
        const fact = response.data.data;
        const versions = response.data.versiones || [];
        elements.historySource.textContent = String(fact.fuente_nombre || '').toUpperCase();
        elements.historyTitle.textContent = `${fact.referencia || 'Hecho'} · ${versions.length} ${versions.length === 1 ? 'versión' : 'versiones'}`;
        elements.historyList.innerHTML = versions.map((version, index) => {
            const previous = versions[index + 1];
            const changes = versionChanges(previous, version);
            return `<li class="${version.vigente ? 'is-current' : ''}">`
                + `<div><strong>v${version.version} · ${escapeHtml(VERSION_REASONS[version.motivo_version] || version.motivo_version)}</strong>`
                + `<small>Capturada ${escapeHtml(formatDate(version.capturado_at))}${version.vigente ? ' · vigente' : ''}</small></div>`
                + `<p>${escapeHtml(measuresText(version))}${factDetail(version) ? ` · ${escapeHtml(factDetail(version))}` : ''}</p>`
                + (changes.length ? `<ul>${changes.map((change) => `<li><span>${escapeHtml(change.label)}</span> ${escapeHtml(change.before)} → <strong>${escapeHtml(change.after)}</strong></li>`).join('')}</ul>` : '')
                + '</li>';
        }).join('');
        elements.history.showModal();
    } finally {
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
    loadReads();
    window.clearInterval(state.timer);
    state.timer = window.setInterval(() => {
        if (document.visibilityState === 'visible' && !elements.history.open) {
            load({ quiet: true });
            loadReads();
        }
    }, REFRESH_MS);
}

function reloadFromFirstPage() {
    state.page = 1;
    load();
}

elements.login.addEventListener('submit', async (event) => {
    event.preventDefault();
    elements.loginError.textContent = '';
    const response = await api('/api/acceso-oficina', { method: 'POST', body: JSON.stringify(Object.fromEntries(new FormData(elements.login))) });
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
elements.capture.addEventListener('click', capture);
elements.month.addEventListener('change', reloadFromFirstPage);
elements.filters.addEventListener('change', reloadFromFirstPage);
elements.filters.addEventListener('submit', (event) => {
    event.preventDefault();
    reloadFromFirstPage();
});
elements.filters.elements.q.addEventListener('input', () => {
    window.clearTimeout(state.searchTimer);
    state.searchTimer = window.setTimeout(reloadFromFirstPage, 350);
});
elements.kpis.addEventListener('click', (event) => {
    const card = event.target.closest('[data-fin-source]');
    if (!card) return;
    elements.source.value = elements.source.value === card.dataset.finSource ? '' : card.dataset.finSource;
    reloadFromFirstPage();
});
elements.previous.addEventListener('click', () => {
    state.page = Math.max(1, state.page - 1);
    load();
});
elements.next.addEventListener('click', () => {
    state.page += 1;
    load();
});
elements.factsBody.addEventListener('click', (event) => {
    const button = event.target.closest('[data-fin-history]');
    if (button) openHistory(button.dataset.finHistory);
});
elements.history.addEventListener('click', (event) => {
    if (event.target === elements.history || event.target.closest('[data-fin-close]')) elements.history.close();
});

if (state.token && showApp()) start();
