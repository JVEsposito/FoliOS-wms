import { createOperationalPoller } from './shared/operational-poller';

const tokenKey = 'estiba_wms_office_token';
const identityKey = 'estiba_wms_office_identity';

const byId = (id) => document.getElementById(id);
const elements = {
    app: byId('integrityApp'),
    run: byId('integrityRunAudit'),
    purge: byId('integrityPurgeDispatched'),
    reload: byId('integrityReload'),
    lastAudit: byId('integrityLastAudit'),
    lastAuditDetail: byId('integrityLastAuditDetail'),
    active: byId('integrityActiveCount'),
    critical: byId('integrityCriticalCount'),
    warning: byId('integrityWarningCount'),
    resolved: byId('integrityResolvedCount'),
    results: byId('integrityResultsSummary'),
    filters: byId('integrityFilters'),
    moduleFilter: byId('integrityModuleFilter'),
    ruleFilter: byId('integrityRuleFilter'),
    clearFilters: byId('integrityClearFilters'),
    error: byId('integrityError'),
    body: byId('integrityFindingsBody'),
    history: byId('integrityAuditHistory'),
    previous: byId('integrityPreviousPage'),
    next: byId('integrityNextPage'),
    pageStatus: byId('integrityPageStatus'),
    loading: byId('integrityLoading'),
    loadingText: byId('integrityLoadingText'),
    toasts: byId('integrityToasts'),
};

const state = {
    token: localStorage.getItem(tokenKey),
    identity: readJson(identityKey),
    response: null,
    responseEtag: null,
    page: 1,
    poller: null,
};

const moduleLabels = {
    prefrio: 'Prefrío',
    camaras: 'Cámaras',
    cargas: 'Cargas',
    materiales: 'Materiales',
    repaletizaje: 'Repaletizaje',
};

class ApiError extends Error {
    constructor(message, status = 0) {
        super(message);
        this.name = 'ApiError';
        this.status = status;
    }
}

function readJson(key) {
    try {
        return JSON.parse(localStorage.getItem(key) || 'null');
    } catch {
        return null;
    }
}

function capabilities() {
    return {
        ...(state.identity?.capacidades || {}),
        ...(state.identity || {}),
    };
}

function can(permission) {
    const granted = capabilities()[permission];
    return granted === true || (granted === undefined && state.identity?.rol === 'administrador');
}

function escapeHtml(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function formatDate(value, fallback = '—') {
    if (!value) return fallback;
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return fallback;
    return new Intl.DateTimeFormat('es-CL', {
        dateStyle: 'short',
        timeStyle: 'short',
    }).format(date);
}

function formatDuration(value) {
    const milliseconds = Number(value || 0);
    return milliseconds >= 1000
        ? `${(milliseconds / 1000).toLocaleString('es-CL', { maximumFractionDigits: 1 })} s`
        : `${milliseconds} ms`;
}

function statusText(value) {
    return String(value || '')
        .replaceAll('_', ' ')
        .replace(/^./, (letter) => letter.toUpperCase());
}

function errorMessage(data, fallback) {
    return Object.values(data?.errors || {}).flat()[0]
        || data?.message
        || fallback;
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
        throw new ApiError('No fue posible conectar con Laravel.');
    }

    if (response.status === 304) {
        return {
            data: null,
            etag: response.headers.get('ETag'),
            notModified: true,
        };
    }

    const data = response.status === 204
        ? null
        : await response.json().catch(() => ({}));
    if (!response.ok) {
        throw new ApiError(
            errorMessage(data, 'No fue posible completar la operación.'),
            response.status,
        );
    }

    return {
        data,
        etag: response.headers.get('ETag'),
        notModified: false,
    };
}

function clearSession() {
    state.token = null;
    state.identity = null;
    localStorage.removeItem(tokenKey);
    localStorage.removeItem(identityKey);
    window.dispatchEvent(new CustomEvent('estiba:office-session', {
        detail: { authenticated: false },
    }));
    window.location.replace('/oficina/accesos');
}

function handleApiError(error) {
    if (error instanceof ApiError && error.status === 401) {
        clearSession();
        return;
    }
    if (error instanceof ApiError && error.status === 403) {
        window.location.replace('/oficina/accesos');
        return;
    }
    elements.error.textContent = error.message;
}

function setBusy(active, message = 'Consultando integridad…') {
    elements.loadingText.textContent = message;
    elements.loading.classList.toggle('is-hidden', !active);
    elements.loading.setAttribute('aria-hidden', String(!active));
    elements.app.setAttribute('aria-busy', String(active));
}

function toast(message, error = false) {
    const node = document.createElement('div');
    node.className = `toast${error ? ' toast--error' : ''}`;
    node.textContent = message;
    elements.toasts.append(node);
    window.setTimeout(() => node.remove(), 5000);
}

function showApp() {
    elements.app.classList.remove('is-hidden');
    const name = state.identity?.nombre || state.identity?.name || 'Usuario';
    const userName = byId('officeUserName');
    const userRole = byId('officeUserRole');
    const initials = byId('officeInitials');
    if (userName) userName.textContent = name;
    if (userRole) userRole.textContent = statusText(state.identity?.rol || 'administración');
    if (initials) {
        initials.textContent = name.split(/\s+/).filter(Boolean).slice(0, 2)
            .map((part) => part[0]).join('').toUpperCase();
    }

    elements.run.classList.toggle(
        'is-hidden',
        !can('puede_ejecutar_integridad_operacional'),
    );
    elements.purge.classList.toggle('is-hidden', !can('puede_ejecutar_integridad_operacional'));

    const logout = byId('officeLogoutButton');
    if (logout) {
        logout.onclick = async () => {
            logout.disabled = true;
            try {
                await api('/api/acceso-oficina', { method: 'DELETE' });
            } finally {
                clearSession();
            }
        };
    }
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

async function load({ busy = true } = {}) {
    if (busy) setBusy(true);
    elements.error.textContent = '';
    try {
        const headers = new Headers();
        if (state.responseEtag) headers.set('If-None-Match', state.responseEtag);
        const result = await api(
            `/api/administracion/integridad-operacional?${queryParameters()}`,
            { headers },
        );
        if (result.notModified) return;
        state.response = result.data;
        state.responseEtag = result.etag;
        render();
    } catch (error) {
        handleApiError(error);
    } finally {
        if (busy) setBusy(false);
    }
}

function renderCatalog() {
    const selectedModule = elements.moduleFilter.value;
    const selectedRule = elements.ruleFilter.value;
    const rules = state.response?.catalogo?.reglas || [];
    const modules = [...new Set([
        ...(state.response?.catalogo?.modulos || []),
        ...rules.map((rule) => rule.modulo),
    ])].sort((left, right) => (moduleLabels[left] || left).localeCompare(
        moduleLabels[right] || right,
        'es',
    ));

    elements.moduleFilter.innerHTML = '<option value="">Todos los módulos</option>'
        + modules.map((module) => `<option value="${escapeHtml(module)}">${escapeHtml(moduleLabels[module] || statusText(module))}</option>`).join('');
    elements.ruleFilter.innerHTML = '<option value="">Todas las reglas</option>'
        + rules.map((rule) => `<option value="${escapeHtml(rule.codigo)}">${escapeHtml(rule.nombre)}</option>`).join('');

    if (modules.includes(selectedModule)) elements.moduleFilter.value = selectedModule;
    if (rules.some((rule) => rule.codigo === selectedRule)) elements.ruleFilter.value = selectedRule;
}

function renderSummary() {
    const summary = state.response?.resumen || {};
    const audit = state.response?.ultima_auditoria;
    elements.active.textContent = String(summary.activos || 0);
    elements.critical.textContent = String(summary.criticos || 0);
    elements.warning.textContent = String(summary.advertencias || 0);
    elements.resolved.textContent = String(summary.resueltos_total || 0);

    if (!audit) {
        elements.lastAudit.textContent = 'Todavía no ejecutada';
        elements.lastAuditDetail.textContent = 'El análisis automático se ejecuta cada 15 minutos.';
        return;
    }

    elements.lastAudit.textContent = `${formatDate(audit.finalizada_at || audit.iniciada_at)} · ${statusText(audit.estado)}`;
    elements.lastAuditDetail.textContent = audit.estado === 'en_ejecucion'
        ? 'Revisando folios y procesos en segundo plano…'
        : (audit.estado === 'fallida'
            ? audit.error || 'La última auditoría no pudo completarse.'
            : `${audit.hallazgos_activos} activos · ${audit.hallazgos_nuevos} nuevos · ${audit.hallazgos_resueltos} resueltos · ${formatDuration(audit.duracion_ms)}`);
}

function renderFindings() {
    const findings = state.response?.data || [];
    const meta = state.response?.meta || {};
    elements.results.textContent = `${meta.total || 0} ${Number(meta.total) === 1 ? 'registro' : 'registros'}`;

    if (!findings.length) {
        elements.body.innerHTML = `
            <tr class="integrity-empty">
                <td colspan="5">
                    <strong>Sin hallazgos para esta selección</strong>
                    <span>No existen contradicciones que coincidan con los filtros actuales.</span>
                </td>
            </tr>`;
    } else {
        elements.body.innerHTML = findings.map((finding) => `
            <tr>
                <td><span class="integrity-severity integrity-severity--${escapeHtml(finding.severidad)}">${escapeHtml(statusText(finding.severidad))}</span></td>
                <td><span class="integrity-module">${escapeHtml(moduleLabels[finding.modulo] || statusText(finding.modulo))}</span></td>
                <td>
                    <strong>${escapeHtml(finding.titulo)}</strong>
                    <span>${escapeHtml(finding.detalle)}</span>
                    <small>Regla: ${escapeHtml(finding.regla_codigo)}</small>
                </td>
                <td>
                    <strong class="integrity-reference">${escapeHtml(finding.referencia || '—')}</strong>
                    <small>${escapeHtml(statusText(finding.entidad_tipo))}</small>
                </td>
                <td>
                    <strong>${escapeHtml(formatDate(finding.detectado_ultima_vez_at))}</strong>
                    <small>Primera: ${escapeHtml(formatDate(finding.detectado_primera_vez_at))}</small>
                    <small>${finding.ocurrencias} ${finding.ocurrencias === 1 ? 'aparición' : 'apariciones'}</small>
                    ${finding.resuelto_at ? `<small>Resuelto: ${escapeHtml(formatDate(finding.resuelto_at))}</small>` : ''}
                </td>
            </tr>
        `).join('');
    }

    const current = Number(meta.pagina_actual || 1);
    const last = Number(meta.ultima_pagina || 1);
    elements.pageStatus.textContent = `Página ${current} de ${last}`;
    elements.previous.disabled = current <= 1;
    elements.next.disabled = current >= last;
}

function renderHistory() {
    const audits = state.response?.auditorias_recientes || [];
    if (!audits.length) {
        elements.history.innerHTML = '<p>Todavía no existen auditorías registradas.</p>';
        return;
    }

    elements.history.innerHTML = audits.map((audit) => `
        <article class="integrity-history-card${audit.estado === 'fallida' ? ' integrity-history-card--failed' : ''}">
            <div class="integrity-history-card__top">
                <strong>${escapeHtml(formatDate(audit.iniciada_at))}</strong>
                <span class="integrity-severity integrity-severity--${audit.estado === 'fallida' ? 'critico' : (audit.hallazgos_criticos > 0 ? 'advertencia' : 'informativo')}">${escapeHtml(statusText(audit.estado))}</span>
            </div>
            <p>${audit.estado === 'en_ejecucion'
        ? 'Revisando folios y procesos…'
        : (audit.estado === 'fallida'
            ? escapeHtml(audit.error || 'Auditoría incompleta')
            : `${audit.hallazgos_activos} activos · ${audit.hallazgos_nuevos} nuevos · ${audit.hallazgos_resueltos} resueltos`)}</p>
            <small>${escapeHtml(statusText(audit.origen))} · ${escapeHtml(formatDuration(audit.duracion_ms))}${audit.iniciada_por ? ` · ${escapeHtml(audit.iniciada_por.nombre)}` : ''}</small>
        </article>
    `).join('');
}

function render() {
    renderCatalog();
    renderSummary();
    renderFindings();
    renderHistory();
}

async function runAudit() {
    if (!can('puede_ejecutar_integridad_operacional')) return;
    elements.error.textContent = '';
    elements.run.disabled = true;
    setBusy(true, 'Programando auditoría…');
    try {
        const result = await api('/api/administracion/integridad-operacional/auditar', {
            method: 'POST',
        });
        state.page = 1;
        await load({ busy: false });
        toast(result.data?.message || 'Auditoría programada.');
    } catch (error) {
        if (error instanceof ApiError && error.status === 409) {
            toast(error.message, true);
        } else {
            handleApiError(error);
        }
    } finally {
        elements.run.disabled = false;
        setBusy(false);
    }
}

async function purgeDispatched() {
    if (!can('puede_ejecutar_integridad_operacional') || elements.purge.disabled) return;
    const path = '/api/administracion/integridad-operacional/purgar-despachados';
    elements.purge.disabled = true;
    elements.error.textContent = '';
    try {
        const { data: preview } = await api(path);
        if (!preview.cantidad) {
            toast('No hay ubicaciones pendientes de pallets despachados.');
            return;
        }
        const etiqueta = preview.folios.length < preview.cantidad ? 'Primeros folios' : 'Folios';
        if (!window.confirm(`Se liberarán ${preview.cantidad} ubicaciones en cámara. ${etiqueta}: ${preview.folios.join(', ')}. El historial de despacho se conservará. ¿Confirmas que estos pallets ya salieron de planta?`)) return;

        const { data } = await api(path, {
            method: 'POST',
            body: JSON.stringify({ confirmacion: true, revision: preview.revision }),
        });
        toast(`Ubicaciones liberadas: ${data.purgadas}. Los hallazgos se actualizarán en la próxima auditoría.`);
    } catch (error) {
        if (error instanceof ApiError && error.status === 409) {
            toast(error.message, true);
        } else {
            handleApiError(error);
        }
    } finally {
        elements.purge.disabled = false;
    }
}

elements.filters.addEventListener('submit', (event) => {
    event.preventDefault();
    state.page = 1;
    void load();
});
elements.clearFilters.addEventListener('click', () => {
    elements.filters.reset();
    state.page = 1;
    void load();
});
elements.reload.addEventListener('click', () => void load());
elements.run.addEventListener('click', () => void runAudit());
elements.purge.addEventListener('click', () => void purgeDispatched());
elements.previous.addEventListener('click', () => {
    if (state.page <= 1) return;
    state.page -= 1;
    void load();
});
elements.next.addEventListener('click', () => {
    const last = Number(state.response?.meta?.ultima_pagina || 1);
    if (state.page >= last) return;
    state.page += 1;
    void load();
});

async function boot() {
    if (!state.token || !state.identity) {
        window.location.replace('/oficina/accesos');
        return;
    }
    if (!can('puede_consultar_integridad_operacional')) {
        window.location.replace('/oficina/accesos');
        return;
    }

    showApp();
    await load();
    state.poller = createOperationalPoller(
        () => load({ busy: false }),
        { intervalMs: 60_000, canRun: () => Boolean(state.token) },
    );
    state.poller.start();
}

void boot();
