import {
    advanceDemoScenario,
    disableDemoSession,
    enableDemoSession,
    readDemoSession,
    restoreDemoScenario,
} from './demo/demo-session';

const byId = (id) => document.getElementById(id);
const elements = {
    access: byId('officeAccess'),
    activation: byId('demoActivation'),
    app: byId('demoApp'),
    login: byId('officeLoginForm'),
    loginError: byId('officeLoginError'),
    safetyConfirmation: byId('demoSafetyConfirmation'),
    activationError: byId('demoActivationError'),
    enable: byId('enableDemoButton'),
    restore: byId('restoreDemoButton'),
    exit: byId('exitDemoButton'),
    advance: byId('advanceDemoButton'),
    administratorName: byId('demoAdministratorName'),
    sessionSince: byId('demoSessionSince'),
    loading: byId('officeLoading'),
    loadingText: byId('officeLoadingText'),
    toasts: byId('officeToasts'),
    traceabilityForm: byId('demoTraceabilityForm'),
    traceabilityQuery: byId('demoTraceabilityQuery'),
};
const authKeys = {
    token: 'estiba_wms_office_token',
    identity: 'estiba_wms_office_identity',
};
const state = {
    token: localStorage.getItem(authKeys.token),
    identity: readLocalIdentity(),
    demo: null,
};
const integer = new Intl.NumberFormat('es-CL');
const decimal = new Intl.NumberFormat('es-CL', { maximumFractionDigits: 1 });
const dateTime = new Intl.DateTimeFormat('es-CL', {
    day: '2-digit',
    month: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
});

class ApiError extends Error {
    constructor(message, status) {
        super(message);
        this.status = status;
    }
}

function readLocalIdentity() {
    try {
        return JSON.parse(localStorage.getItem(authKeys.identity) || 'null');
    } catch {
        return null;
    }
}

function escapeHtml(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function isAdministrator(identity) {
    return identity?.rol === 'administrador' && identity?.puede_habilitar_demo !== false;
}

function errorMessage(data, fallback) {
    return Object.values(data?.errors || {}).flat()[0] || data?.message || fallback;
}

async function api(path, options = {}, token = state.token) {
    const headers = new Headers(options.headers || {});
    headers.set('Accept', 'application/json');
    if (token) headers.set('Authorization', `Bearer ${token}`);
    if (options.body) headers.set('Content-Type', 'application/json');

    let response;
    try {
        response = await fetch(path, { ...options, headers });
    } catch {
        throw new ApiError('No fue posible conectar con Laravel.', 0);
    }

    const data = response.status === 204 ? null : await response.json().catch(() => ({}));
    if (!response.ok) {
        if (response.status === 401 && path !== '/api/acceso-oficina') clearAuthentication();
        throw new ApiError(errorMessage(data, 'No fue posible completar la operación.'), response.status);
    }

    return data;
}

function persistAuthentication(payload) {
    state.token = payload.token;
    state.identity = payload.usuario;
    localStorage.setItem(authKeys.token, payload.token);
    localStorage.setItem(authKeys.identity, JSON.stringify(payload.usuario));
    window.dispatchEvent(new CustomEvent('estiba:office-session', {
        detail: { authenticated: true, identity: payload.usuario },
    }));
}

function clearAuthentication() {
    state.token = null;
    state.identity = null;
    state.demo = null;
    localStorage.removeItem(authKeys.token);
    localStorage.removeItem(authKeys.identity);
    disableDemoSession(sessionStorage);
    showOnly(elements.access);
}

function showOnly(target) {
    [elements.access, elements.activation, elements.app].forEach((element) => {
        element?.classList.toggle('is-hidden', element !== target);
    });
}

function setBusy(active, message = 'Preparando escenario demo…') {
    elements.loadingText.textContent = message;
    elements.loading.classList.toggle('is-hidden', !active);
    elements.loading.setAttribute('aria-hidden', String(!active));
}

function toast(message, error = false) {
    const node = document.createElement('div');
    node.className = `toast${error ? ' toast--error' : ''}`;
    node.textContent = message;
    elements.toasts.append(node);
    window.setTimeout(() => node.remove(), 4500);
}

function showLogin(message = '') {
    disableDemoSession(sessionStorage);
    state.demo = null;
    elements.loginError.textContent = message;
    showOnly(elements.access);
}

function showActivation() {
    if (!isAdministrator(state.identity)) {
        showLogin('Solo una cuenta administradora puede habilitar la versión demo.');
        return;
    }

    elements.safetyConfirmation.checked = false;
    elements.enable.disabled = true;
    elements.activationError.textContent = '';
    showOnly(elements.activation);
}

function showDemo(demo) {
    state.demo = demo;
    elements.administratorName.textContent = demo.session.administratorName || 'Administrador';
    elements.sessionSince.textContent = `Demo habilitada ${dateTime.format(new Date(demo.session.enabledAt))}`;
    showOnly(elements.app);
    activateTab('summary');
    renderDataset(demo.dataset);
}

function number(value) {
    return integer.format(Number(value || 0));
}

function weight(value) {
    return `${integer.format(Number(value || 0))} kg`;
}

function kpi(label, value, detail, tone = '') {
    return `<article class="demo-kpi ${tone ? `demo-kpi--${tone}` : ''}"><span>${escapeHtml(label)}</span><strong>${escapeHtml(value)}</strong><small>${escapeHtml(detail)}</small></article>`;
}

function statusClass(value) {
    const normalized = String(value || '').toLocaleLowerCase('es-CL');
    if (normalized.includes('urgente') || normalized.includes('atención')) return 'danger';
    if (normalized.includes('prepar') || normalized.includes('validación') || normalized.includes('proceso')) return 'warning';
    return 'success';
}

function status(value) {
    return `<span class="demo-status demo-status--${statusClass(value)}">${escapeHtml(value)}</span>`;
}

function renderDataset(dataset) {
    renderSummary(dataset);
    renderRawMaterial(dataset.rawMaterial);
    renderRefrigerated(dataset.refrigerated);
    renderMaterials(dataset.materials);
    renderTraceability(dataset.traceability);
}

function renderSummary(dataset) {
    const { meta, summary, refrigerated, alerts, audit } = dataset;
    byId('demoScenarioTitle').textContent = meta.scenarioName;
    byId('demoScenarioMeta').textContent = `${meta.seasonCode} · corte simulado ${meta.cut} · información 100% ficticia`;
    byId('demoKpiGrid').innerHTML = [
        kpi('Recepción hoy', weight(summary.netKilogramsReceived), 'Materia prima neta'),
        kpi('Folios activos', number(summary.activeFolios), `${summary.fullPallets} pallets · ${summary.balances} saldos`),
        kpi('Ocupación cámaras', `${decimal.format(summary.occupancy)}%`, `${summary.occupiedPositions} de ${summary.totalPositions} posiciones`),
        kpi('Cargas activas', number(summary.activeLoads), 'Preparación y despacho'),
        kpi('Inventario materiales', number(summary.materialItems), 'Ítems con stock'),
    ].join('');

    byId('demoOverallOccupancy').textContent = `${decimal.format(summary.occupancy)}%`;
    byId('demoCameraBars').innerHTML = refrigerated.cameras.map((camera) => `
        <article class="demo-bar-row">
            <div><strong>${escapeHtml(camera.name)}</strong><span>${camera.occupied}/${camera.total} posiciones</span></div>
            <div class="demo-progress"><span style="width: ${camera.occupancy}%"></span></div>
            <b>${decimal.format(camera.occupancy)}%</b>
        </article>
    `).join('');

    byId('demoAlertCount').textContent = String(alerts.length);
    byId('demoAlertList').innerHTML = alerts.map((alert) => `
        <article class="demo-alert demo-alert--${escapeHtml(alert.level)}">
            <span>${escapeHtml(alert.area)}</span><strong>${escapeHtml(alert.title)}</strong><small>${escapeHtml(alert.detail)}</small>
        </article>
    `).join('');

    byId('demoFlowReceptions').textContent = number(dataset.rawMaterial.receivedToday);
    byId('demoFlowHydrocooler').textContent = number(dataset.rawMaterial.hydrocoolerLots);
    byId('demoFlowPrecooling').textContent = number(refrigerated.activeProcesses);
    byId('demoFlowLoads').textContent = number(summary.activeLoads);
    byId('demoAuditList').innerHTML = audit.slice(0, 4).map((entry) => `
        <article><time>${dateTime.format(new Date(entry.time))}</time><div><strong>${escapeHtml(entry.action)}</strong><small>${escapeHtml(entry.detail)}</small></div></article>
    `).join('');
}

function renderRawMaterial(rawMaterial) {
    byId('demoRawMaterialKpis').innerHTML = [
        kpi('Recepciones', number(rawMaterial.receivedToday), 'Registradas hoy'),
        kpi('Por validar', number(rawMaterial.pendingValidation), 'Recepciones pendientes', 'warning'),
        kpi('En hidrocooler', number(rawMaterial.hydrocoolerLots), 'Lotes en ciclo'),
        kpi('Liberados', number(rawMaterial.availableForProcess), 'Disponibles para proceso', 'success'),
    ].join('');

    byId('demoHydrocoolerList').innerHTML = rawMaterial.hydrocoolers.map((unit) => {
        const progress = Math.round((unit.processed / unit.containers) * 100);
        return `<article class="demo-unit">
            <header><div><strong>${escapeHtml(unit.name)}</strong><small>${escapeHtml(unit.lot)} · ${escapeHtml(unit.variety)}</small></div>${status(unit.status)}</header>
            <div class="demo-unit__metrics"><span><b>${unit.processed}/${unit.containers}</b> bins</span><span><b>${unit.pumpsWorking}/${unit.pumpsTotal}</b> bombas funcionando</span><span><b>${decimal.format(unit.waterTemperature)} °C</b> agua</span></div>
            <div class="demo-progress"><span style="width: ${progress}%"></span></div>
        </article>`;
    }).join('');

    byId('demoRawMaterialLots').innerHTML = rawMaterial.lots.map((lot) => `
        <article class="demo-unit"><header><div><strong>${escapeHtml(lot.number)}</strong><small>${escapeHtml(lot.client)} · ${escapeHtml(lot.variety)}</small></div>${status(lot.condition)}</header><div class="demo-unit__metrics"><span><b>${lot.containers}</b> bins</span><span><b>${escapeHtml(lot.destination)}</b> destino</span></div></article>
    `).join('');

    byId('demoReceptionRows').innerHTML = rawMaterial.receptions.map((reception) => `
        <tr><td><strong>${escapeHtml(reception.number)}</strong><small>${dateTime.format(new Date(reception.time))}</small></td><td>${escapeHtml(reception.client)}</td><td>${escapeHtml(reception.guide)}</td><td>${escapeHtml(reception.containers)}</td><td>${weight(reception.kilograms)}</td><td>${status(reception.status)}</td></tr>
    `).join('');
}

function renderRefrigerated(refrigerated) {
    byId('demoAverageCycle').textContent = `${decimal.format(refrigerated.averageCycleHours)} h promedio`;
    byId('demoPrecoolingList').innerHTML = refrigerated.precooling.map((process) => `
        <article class="demo-unit"><header><div><strong>${escapeHtml(process.tunnel)}</strong><small>${escapeHtml(process.process)} · ${process.folios} folios</small></div>${status(process.status)}</header><div class="demo-unit__metrics"><span><b>${number(process.packages)}</b> cajas</span><span><b>${escapeHtml(process.elapsed)}</b> transcurrido</span><span><b>${decimal.format(process.pulp)} °C</b> pulpa</span></div></article>
    `).join('');

    byId('demoCameraList').innerHTML = refrigerated.cameras.map((camera) => `
        <article class="demo-unit"><header><div><strong>${escapeHtml(camera.name)}</strong><small>${escapeHtml(camera.content)}</small></div><b>${decimal.format(camera.occupancy)}%</b></header><div class="demo-progress"><span style="width: ${camera.occupancy}%"></span></div><div class="demo-unit__metrics"><span><b>${camera.occupied}</b> ocupadas</span><span><b>${camera.total - camera.occupied}</b> disponibles</span>${camera.temperature === null ? '' : `<span><b>${decimal.format(camera.temperature)} °C</b> ambiente</span>`}</div></article>
    `).join('');

    byId('demoLoadRows').innerHTML = refrigerated.loads.map((load) => `
        <tr><td><strong>${escapeHtml(load.code)}</strong></td><td>${escapeHtml(load.client)}</td><td>${escapeHtml(load.destination)}</td><td>${load.ready}/${load.folios} folios</td><td>${escapeHtml(load.window)}</td><td>${status(load.status)}</td></tr>
    `).join('');
}

function renderMaterials(materials) {
    byId('demoMaterialKpis').innerHTML = [
        kpi('Ítems activos', number(materials.activeItems), 'Con existencia'),
        kpi('Stock total', number(materials.stockUnits), 'Unidades equivalentes'),
        kpi('Reservado', number(materials.reservedUnits), 'Para producción'),
        kpi('Despachos', number(materials.openDispatches), 'Abiertos hoy'),
    ].join('');

    byId('demoMaterialRows').innerHTML = materials.items.map((item) => `
        <tr><td><strong>${escapeHtml(item.code)}</strong></td><td>${escapeHtml(item.client)}</td><td>${escapeHtml(item.name)}<small>${escapeHtml(item.unit)}</small></td><td>${number(item.stock)}</td><td>${number(item.reserved)}</td><td><strong>${number(item.available)}</strong></td><td>${escapeHtml(item.warehouse)}</td></tr>
    `).join('');
    byId('demoMaterialDispatches').innerHTML = materials.dispatches.map((dispatch) => `
        <article class="demo-unit"><header><div><strong>${escapeHtml(dispatch.code)}</strong><small>${escapeHtml(dispatch.destination)}</small></div>${status(dispatch.status)}</header><div class="demo-unit__metrics"><span><b>${dispatch.items}</b> ítems</span><span><b>${number(dispatch.units)}</b> unidades</span></div></article>
    `).join('');
}

function renderTraceability(records) {
    const options = byId('demoTraceabilityOptions');
    options.innerHTML = records.map((record) => `<option value="${escapeHtml(record.key)}">${escapeHtml(record.type)}</option>`).join('');
    renderTraceabilityRecord(records[0]);
}

function renderTraceabilityRecord(record) {
    const result = byId('demoTraceabilityResult');
    if (!record) {
        result.innerHTML = '<div class="demo-empty"><strong>Sin coincidencias</strong><span>Prueba con uno de los ejemplos sugeridos.</span></div>';
        return;
    }

    result.innerHTML = `
        <header><div><span>${escapeHtml(record.type)}</span><h2>${escapeHtml(record.title)}</h2><p>${escapeHtml(record.subtitle)}</p></div><b>Expediente ficticio</b></header>
        <div class="demo-timeline">${record.events.map((event) => `<article><time>${dateTime.format(new Date(event.time))}</time><i></i><div><strong>${escapeHtml(event.area)}</strong><span>${escapeHtml(event.detail)}</span></div></article>`).join('')}</div>
    `;
}

function activateTab(tab) {
    document.querySelectorAll('[data-demo-tab]').forEach((button) => {
        const active = button.dataset.demoTab === tab;
        button.classList.toggle('is-active', active);
        button.setAttribute('aria-pressed', String(active));
    });
    document.querySelectorAll('[data-demo-panel]').forEach((panel) => {
        panel.classList.toggle('is-hidden', panel.dataset.demoPanel !== tab);
    });
}

async function handleLogin(event) {
    event.preventDefault();
    elements.loginError.textContent = '';
    const form = new FormData(elements.login);
    setBusy(true, 'Validando cuenta administradora…');
    try {
        const payload = await api('/api/acceso-oficina', {
            method: 'POST',
            body: JSON.stringify({ email: form.get('email'), password: form.get('password') }),
        }, null);
        if (!isAdministrator(payload.usuario)) {
            await api('/api/acceso-oficina', { method: 'DELETE' }, payload.token).catch(() => null);
            throw new ApiError('La cuenta ingresada no posee rol Administrador.', 403);
        }
        persistAuthentication(payload);
        elements.login.reset();
        showActivation();
    } catch (error) {
        elements.loginError.textContent = error.message;
    } finally {
        setBusy(false);
    }
}

async function handleEnable() {
    elements.activationError.textContent = '';
    setBusy(true, 'Creando datos ficticios en esta pestaña…');
    try {
        const payload = await api('/api/demo/autorizar');
        const identity = { ...state.identity, puede_habilitar_demo: true };
        const demo = enableDemoSession(sessionStorage, identity, payload.data);
        showDemo(demo);
        toast('Demo local habilitada. La temporada productiva permanece intacta.');
    } catch (error) {
        elements.activationError.textContent = error.message;
        if (error.status === 401) showLogin('La sesión de oficina expiró. Ingresa nuevamente.');
    } finally {
        setBusy(false);
    }
}

function handleRestore() {
    try {
        const dataset = restoreDemoScenario(sessionStorage);
        state.demo.dataset = dataset;
        renderDataset(dataset);
        activateTab('summary');
        toast('El escenario ficticio volvió a su estado inicial.');
    } catch (error) {
        showActivation();
        toast(error.message, true);
    }
}

function handleAdvance() {
    try {
        const dataset = advanceDemoScenario(sessionStorage);
        state.demo.dataset = dataset;
        renderDataset(dataset);
        toast(`Corte demo ${dataset.meta.cut} simulado localmente.`);
    } catch (error) {
        showActivation();
        toast(error.message, true);
    }
}

function handleExit() {
    disableDemoSession(sessionStorage);
    state.demo = null;
    window.location.assign('/oficina/administracion');
}

function handleTraceability(event) {
    event.preventDefault();
    const query = elements.traceabilityQuery.value.trim().toLocaleLowerCase('es-CL');
    const record = state.demo?.dataset.traceability.find((candidate) =>
        [candidate.key, candidate.type, candidate.title, candidate.subtitle]
            .some((value) => String(value).toLocaleLowerCase('es-CL').includes(query)),
    );
    renderTraceabilityRecord(record);
}

function bindEvents() {
    elements.login.addEventListener('submit', handleLogin);
    elements.safetyConfirmation.addEventListener('change', () => {
        elements.enable.disabled = !elements.safetyConfirmation.checked;
    });
    elements.enable.addEventListener('click', handleEnable);
    elements.restore.addEventListener('click', handleRestore);
    elements.advance.addEventListener('click', handleAdvance);
    elements.exit.addEventListener('click', handleExit);
    elements.traceabilityForm.addEventListener('submit', handleTraceability);
    document.querySelectorAll('[data-demo-tab]').forEach((button) => {
        button.addEventListener('click', () => activateTab(button.dataset.demoTab));
    });
}

function boot() {
    bindEvents();
    if (!state.token || !state.identity) {
        showLogin();
        return;
    }
    if (!isAdministrator(state.identity)) {
        showLogin('La versión demo requiere una cuenta con rol Administrador.');
        return;
    }

    const demo = readDemoSession(sessionStorage, state.identity.id);
    if (demo) showDemo(demo);
    else showActivation();
}

boot();
