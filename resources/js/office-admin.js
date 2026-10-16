import './office-external-connections.js';

const byId = (id) => document.getElementById(id);

const elements = {
    access: byId('officeAccess'),
    app: byId('officeApp'),
    loginForm: byId('officeLoginForm'),
    loginError: byId('officeLoginError'),
    userName: byId('officeUserName'),
    userRole: byId('officeUserRole'),
    initials: byId('officeInitials'),
    logout: byId('officeLogoutButton'),
    reload: byId('reloadAccessesButton'),
    activeUsers: byId('activeUsersCount'),
    activeDevices: byId('activeDevicesCount'),
    activeAccessSessions: byId('activeAccessSessionsCount'),
    accessSessionsSummary: byId('accessSessionsSummary'),
    accessSessionsTableBody: byId('accessSessionsTableBody'),
    accessSessionsPrevious: byId('accessSessionsPrevious'),
    accessSessionsNext: byId('accessSessionsNext'),
    accessSessionsPage: byId('accessSessionsPage'),
    activeClients: byId('activeClientsCount'),
    activeSeason: byId('activeSeasonCode'),
    lastDeviceAccess: byId('lastDeviceAccess'),
    seasonsSummary: byId('seasonsSummary'),
    seasonsTableBody: byId('seasonsTableBody'),
    clientsSummary: byId('globalClientsSummary'),
    clientsTableBody: byId('globalClientsTableBody'),
    usersSummary: byId('usersSummary'),
    devicesSummary: byId('devicesSummary'),
    usersTableBody: byId('usersTableBody'),
    devicesTableBody: byId('devicesTableBody'),
    userForm: byId('createUserForm'),
    userError: byId('createUserError'),
    deviceForm: byId('createDeviceForm'),
    deviceError: byId('createDeviceError'),
    seasonForm: byId('seasonForm'),
    seasonError: byId('seasonError'),
    seasonCancel: byId('cancelSeasonEdit'),
    clientForm: byId('globalClientForm'),
    clientError: byId('globalClientError'),
    clientCancel: byId('cancelGlobalClientEdit'),
    migrationForm: byId('seasonMigrationForm'),
    migrationTitle: byId('seasonMigrationTitle'),
    migrationError: byId('seasonMigrationError'),
    migrationCancel: byId('cancelSeasonMigration'),
    classificationForm: byId('seasonClassificationForm'),
    classificationTitle: byId('seasonClassificationTitle'),
    classificationHint: byId('seasonClassificationHint'),
    classificationError: byId('seasonClassificationError'),
    classificationCancel: byId('cancelSeasonClassification'),
    archiveDialog: byId('seasonArchiveDialog'),
    archiveDescription: byId('seasonArchiveDescription'),
    archiveEligibility: byId('seasonArchiveEligibility'),
    archiveList: byId('seasonArchiveList'),
    archiveCreate: byId('createSeasonArchive'),
    closingDialog: byId('seasonClosingDialog'),
    closingTitle: byId('seasonClosingTitle'),
    closingDescription: byId('seasonClosingDescription'),
    closingStatus: byId('seasonClosingStatus'),
    closingSummary: byId('seasonClosingSummary'),
    closingDetail: byId('seasonClosingDetail'),
    closingDetailTitle: byId('seasonClosingDetailTitle'),
    closingHowTo: byId('seasonClosingHowTo'),
    closingBlock: byId('seasonClosingBlock'),
    closingItems: byId('seasonClosingItems'),
    closingSelectAll: byId('seasonClosingSelectAll'),
    closingForm: byId('seasonClosingForm'),
    closingEffect: byId('seasonClosingEffect'),
    closingError: byId('seasonClosingError'),
    closingSubmit: byId('seasonClosingSubmit'),
    closingHistoryList: byId('seasonClosingHistoryList'),
    closingNotify: byId('notifySeasonClosing'),
    resetDialog: byId('operationalResetDialog'),
    resetForm: byId('operationalResetForm'),
    resetDescription: byId('operationalResetDescription'),
    resetPreview: byId('operationalResetPreview'),
    resetPhrase: byId('operationalResetPhrase'),
    resetError: byId('operationalResetError'),
    resetClose: byId('closeOperationalReset'),
    resetCancel: byId('cancelOperationalReset'),
    loading: byId('officeLoading'),
    loadingText: byId('officeLoadingText'),
    toasts: byId('officeToasts'),
};

const keys = {
    token: 'estiba_wms_office_token',
    identity: 'estiba_wms_office_identity',
};

const state = {
    token: localStorage.getItem(keys.token),
    identity: readJson(keys.identity),
    users: [],
    devices: [],
    accessSessions: [],
    accessSessionsPage: 1,
    accessSessionsLastPage: 1,
    accessSessionsTotal: 0,
    seasons: [],
    clients: [],
    resetPreview: null,
};

class ApiError extends Error {
    constructor(message, status, data = {}) {
        super(message);
        this.name = 'ApiError';
        this.status = status;
        this.data = data;
    }
}

function readJson(key) {
    try {
        return JSON.parse(localStorage.getItem(key) || 'null');
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

function operationUuid() {
    if (typeof crypto.randomUUID === 'function') return crypto.randomUUID();
    const bytes = crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = [...bytes].map((value) => value.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

function errorMessage(data, fallback) {
    return Object.values(data?.errors || {}).flat()[0] || data?.message || fallback;
}

function userValidationMessage(data) {
    const password = String(data.password || '');

    if (password.length < 10) return 'La contraseña debe tener al menos 10 caracteres.';
    if (!/\p{L}/u.test(password) || !/\p{N}/u.test(password)) {
        return 'La contraseña debe contener al menos una letra y un número.';
    }
    if (password !== String(data.password_confirmation || '')) {
        return 'La confirmación de la contraseña no coincide.';
    }

    return null;
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
        throw new ApiError('No fue posible conectar con Laravel.', 0);
    }

    const data = response.status === 204 ? null : await response.json().catch(() => ({}));
    if (!response.ok) {
        if (response.status === 401 && path !== '/api/acceso-oficina') clearSession();
        throw new ApiError(errorMessage(data, 'No fue posible completar la operación.'), response.status, data);
    }

    return data;
}

function setBusy(active, message = 'Procesando…') {
    elements.loadingText.textContent = message;
    elements.loading.classList.toggle('is-hidden', !active);
    elements.loading.setAttribute('aria-hidden', String(!active));
    elements.app.setAttribute('aria-busy', String(active));
}

function toast(message, error = false) {
    const item = document.createElement('div');
    item.className = `toast${error ? ' toast--error' : ''}`;
    item.textContent = message;
    elements.toasts.append(item);
    window.setTimeout(() => item.remove(), 4500);
}

function persistSession(payload) {
    state.token = payload.token;
    state.identity = payload.usuario;
    localStorage.setItem(keys.token, payload.token);
    localStorage.setItem(keys.identity, JSON.stringify(payload.usuario));
    window.dispatchEvent(new CustomEvent('estiba:office-session', {
        detail: { authenticated: true, identity: payload.usuario },
    }));
}

function clearSession() {
    state.token = null;
    state.identity = null;
    state.users = [];
    state.devices = [];
    state.accessSessions = [];
    state.seasons = [];
    state.clients = [];
    localStorage.removeItem(keys.token);
    localStorage.removeItem(keys.identity);
    elements.app.classList.add('is-hidden');
    elements.access.classList.remove('is-hidden');
    window.dispatchEvent(new CustomEvent('estiba:office-session', {
        detail: { authenticated: false },
    }));
}

function showApp() {
    elements.access.classList.add('is-hidden');
    elements.app.classList.remove('is-hidden');
    elements.app.classList.toggle('is-read-only', state.identity?.solo_consulta === true);
    const sessionsTab = byId('administration-tab-sessions');
    const canManageSessions = state.identity?.puede_administrar_accesos === true;
    sessionsTab.classList.toggle('is-hidden', !canManageSessions);
    if (!canManageSessions && sessionsTab.getAttribute('aria-selected') === 'true') {
        byId('administration-tab-users').click();
    }
    const name = state.identity?.nombre || 'Usuario';
    elements.userName.textContent = name;
    elements.userRole.textContent = statusText(state.identity?.rol || 'administrador');
    elements.initials.textContent = name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0])
        .join('')
        .toUpperCase();
}

function statusText(value) {
    return String(value || '')
        .replaceAll('_', ' ')
        .replace(/^./, (character) => character.toUpperCase());
}

function formatDate(value, fallback = 'Sin accesos') {
    if (!value) return fallback;
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return fallback;
    return new Intl.DateTimeFormat('es-CL', {
        dateStyle: 'short',
        timeStyle: 'short',
    }).format(date);
}

function statusBadge(active) {
    return `<span class="access-status access-status--${active ? 'active' : 'inactive'}">${active ? 'Activo' : 'Inactivo'}</span>`;
}

function renderUsers() {
    elements.activeUsers.textContent = String(state.users.filter((user) => user.activo).length);
    elements.usersSummary.textContent = `${state.users.length} ${state.users.length === 1 ? 'registrado' : 'registrados'}`;

    if (!state.users.length) {
        elements.usersTableBody.innerHTML = '<tr class="admin-empty"><td colspan="3">No existen usuarios registrados.</td></tr>';
        return;
    }

    elements.usersTableBody.innerHTML = state.users.map((user) => `
        <tr>
            <td><strong>${escapeHtml(user.nombre)}</strong><small>${escapeHtml(user.email)}</small></td>
            <td><span class="role-badge">${escapeHtml(user.perfil?.nombre || statusText(user.rol))}</span><small>${escapeHtml(statusText(user.rol))}</small></td>
            <td>${statusBadge(user.activo)}</td>
        </tr>
    `).join('');
}

function renderDevices() {
    const active = state.devices.filter((device) => device.activo);
    const accesses = state.devices
        .map((device) => device.ultimo_acceso_at)
        .filter(Boolean)
        .sort()
        .reverse();

    elements.activeDevices.textContent = String(active.length);
    elements.lastDeviceAccess.textContent = formatDate(accesses[0]);
    elements.devicesSummary.textContent = `${state.devices.length} ${state.devices.length === 1 ? 'registrada' : 'registradas'}`;

    if (!state.devices.length) {
        elements.devicesTableBody.innerHTML = '<tr class="admin-empty"><td colspan="3">No existen tablets registradas.</td></tr>';
        return;
    }

    elements.devicesTableBody.innerHTML = state.devices.map((device) => `
        <tr>
            <td><strong>${escapeHtml(device.codigo)}</strong><small>${escapeHtml(device.nombre)} · ${escapeHtml(statusText(device.plataforma))}</small></td>
            <td>${escapeHtml(formatDate(device.ultimo_acceso_at))}</td>
            <td>${statusBadge(device.activo)}</td>
        </tr>
    `).join('');
}

function renderAccessSessions() {
    elements.activeAccessSessions.textContent = state.identity?.puede_administrar_accesos
        ? String(state.accessSessionsTotal) : '—';
    elements.accessSessionsSummary.textContent = `${state.accessSessionsTotal} ${state.accessSessionsTotal === 1 ? 'sesión' : 'sesiones'}`;
    elements.accessSessionsPage.textContent = `Página ${state.accessSessionsPage} de ${state.accessSessionsLastPage}`;
    elements.accessSessionsPrevious.disabled = state.accessSessionsPage <= 1;
    elements.accessSessionsNext.disabled = state.accessSessionsPage >= state.accessSessionsLastPage;

    if (!state.accessSessions.length) {
        elements.accessSessionsTableBody.innerHTML = '<tr class="admin-empty"><td colspan="5">No hay sesiones con acceso vigente.</td></tr>';
        return;
    }

    elements.accessSessionsTableBody.innerHTML = state.accessSessions.map((session) => {
        const user = session.usuario;
        const device = session.dispositivo;
        const access = session.tipo === 'tablet'
            ? `${escapeHtml(device?.nombre || 'Tablet')} · ${escapeHtml(device?.codigo || '')}`
            : 'Oficina PC';
        return `<tr>
            <td><strong>${escapeHtml(user.nombre)}</strong><small>${escapeHtml(user.email)}</small></td>
            <td><strong>${access}</strong>${session.es_actual ? '<small>Tu sesión actual</small>' : ''}</td>
            <td>${escapeHtml(formatDate(session.creada_at, '—'))}</td>
            <td>${escapeHtml(formatDate(session.ultima_actividad_at, 'Sin seguimiento'))}</td>
            <td><button class="admin-session-disconnect" data-disconnect-session="${Number(session.id)}" type="button">Desconectar</button></td>
        </tr>`;
    }).join('');
}

async function loadAccessSessions(page = state.accessSessionsPage) {
    const response = await api(`/api/administracion/sesiones-acceso?page=${page}`);
    state.accessSessions = response.data || [];
    state.accessSessionsPage = response.pagina || 1;
    state.accessSessionsLastPage = response.ultima_pagina || 1;
    state.accessSessionsTotal = response.total || 0;
    renderAccessSessions();
}

function dateOnly(value, fallback = 'Sin fecha') {
    if (!value) return fallback;
    const [year, month, day] = String(value).slice(0, 10).split('-');
    return year && month && day ? `${day}-${month}-${year}` : fallback;
}

function resetSeasonForm() {
    elements.seasonForm.reset();
    elements.seasonForm.elements.id.value = '';
    elements.seasonForm.elements.intervalo_embarques_minutos.value = '60';
    elements.seasonForm.elements.tipo.disabled = false;
    updateSeasonTypeForm();
    elements.seasonError.textContent = '';
    elements.seasonCancel.classList.add('is-hidden');
}

function updateSeasonTypeForm() {
    const form = elements.seasonForm.elements;
    const isTest = form.tipo.value === 'prueba';
    form.fecha_inicio.required = !isTest;
    form.fecha_fin.required = !isTest;
    byId('seasonStartLabel').textContent = isTest ? 'Inicio' : 'Inicio *';
    byId('seasonEndLabel').textContent = isTest ? 'Término' : 'Término *';
    form.activa.disabled = isTest;
    if (isTest) form.activa.checked = false;
    byId('seasonTypeHint').textContent = form.tipo.disabled
        ? 'El tipo solo cambia con «Declarar prueba» o «Declarar productiva» y un motivo.'
        : 'Las temporadas de prueba no se activan ni aparecen en reportes operacionales.';
}

function resetMigrationForm() {
    elements.migrationForm.reset();
    elements.migrationForm.elements.temporada_destino_id.value = '';
    elements.migrationError.textContent = '';
    elements.migrationForm.classList.add('is-hidden');
}

function resetClassificationForm() {
    elements.classificationForm.reset();
    elements.classificationError.textContent = '';
    elements.classificationForm.classList.add('is-hidden');
}

function openClassificationForm(seasonId, type) {
    const season = state.seasons.find((candidate) => candidate.id === seasonId);
    if (!season) return;
    resetMigrationForm();
    elements.classificationForm.reset();
    elements.classificationForm.elements.temporada_id.value = seasonId;
    elements.classificationForm.elements.tipo.value = type;
    elements.classificationTitle.textContent = type === 'prueba'
        ? `Declarar ${season.codigo} como temporada de prueba`
        : `Declarar ${season.codigo} como temporada productiva`;
    elements.classificationHint.textContent = type === 'prueba'
        ? 'Solo cambia el tipo de esta temporada: deja de ofrecerse en reportes y selectores y no podrá activarse. No mueve ni borra datos de la temporada activa ni de Materiales.'
        : 'La temporada vuelve a los reportes. Requiere fechas que no se crucen con otra temporada productiva.';
    elements.classificationError.textContent = '';
    elements.classificationForm.classList.remove('is-hidden');
    elements.classificationForm.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    elements.classificationForm.elements.motivo.focus();
}

function seasonTypeBadge(season) {
    return season.tipo === 'prueba'
        ? '<span class="access-status access-status--test" title="No se activa ni aparece en reportes">Prueba</span>'
        : '';
}

function seasonActions(season) {
    const closing = `<button data-closing-season="${season.id}" type="button">Cierre</button>`;
    if (season.activa) {
        return `<button data-edit-season="${season.id}" type="button">Editar</button>${closing}<button class="admin-season-reset" data-reset-season="${season.id}" type="button">Reiniciar PT + MP</button>`;
    }
    const archive = state.identity?.rol === 'administrador' ? `<button data-archive-season="${season.id}" type="button">Archivo</button>` : '';
    if (season.tipo === 'prueba') {
        return `<button data-edit-season="${season.id}" type="button">Editar</button>${closing}<button data-classify-season="${season.id}" data-classify-type="productiva" type="button">Declarar productiva</button>${archive}`;
    }
    return `<button data-edit-season="${season.id}" type="button">Editar</button><button data-migrate-season="${season.id}" type="button">Migrar datos</button><button data-activate-season="${season.id}" type="button">Activar</button>${closing}<button data-classify-season="${season.id}" data-classify-type="prueba" type="button">Declarar prueba</button>${archive}`;
}

function openMigrationForm(destinationId) {
    const destination = state.seasons.find((season) => season.id === destinationId);
    if (!destination) return;
    const sources = state.seasons.filter((season) => season.id !== destinationId);
    elements.migrationForm.reset();
    elements.migrationForm.elements.temporada_destino_id.value = destinationId;
    elements.migrationTitle.textContent = `Migrar datos hacia ${destination.codigo}`;
    elements.migrationForm.elements.temporada_origen_id.innerHTML = sources.map((season) =>
        `<option value="${season.id}"${season.activa ? ' selected' : ''}>${escapeHtml(season.codigo)} · ${escapeHtml(season.nombre)}${season.activa ? ' (activa)' : ''}${season.tipo === 'prueba' ? ' (prueba)' : ''}</option>`,
    ).join('');
    elements.migrationForm.elements.copiar_catalogo_validacion.checked = true;
    elements.migrationForm.elements.copiar_catalogo_materiales.checked = true;
    elements.migrationForm.elements.activar_destino.checked = false;
    elements.migrationForm.elements.migrar_inventario_materiales.checked = false;
    elements.migrationError.textContent = '';
    elements.migrationForm.classList.remove('is-hidden');
    elements.migrationForm.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function renderSeasons() {
    const active = state.seasons.find((season) => season.activa);
    elements.activeSeason.textContent = active?.codigo || '—';
    elements.seasonsSummary.textContent = `${state.seasons.length} ${state.seasons.length === 1 ? 'registrada' : 'registradas'}`;

    if (!state.seasons.length) {
        elements.seasonsTableBody.innerHTML = '<tr class="admin-empty"><td colspan="5">No existen temporadas. Crea la primera configuración transversal.</td></tr>';
        return;
    }

    elements.seasonsTableBody.innerHTML = state.seasons.map((season) => `
        <tr>
            <td><strong>${escapeHtml(season.codigo)} · ${escapeHtml(season.nombre)}</strong><small>${season.prefijo_documental ? `Prefijo ${escapeHtml(season.prefijo_documental)}` : 'Sin prefijo documental'} · Versión de catálogo ${Number(season.version_catalogo || 1)} · ${Number(season.migraciones_recibidas || 0)} migraciones recibidas</small>${season.clasificacion ? `<small title="${escapeHtml(season.clasificacion.motivo || '')}">Declarada ${season.clasificacion.tipo_nuevo === 'prueba' ? 'de prueba' : 'productiva'} por ${escapeHtml(season.clasificacion.clasificado_por || '—')}</small>` : ''}</td>
            <td>${escapeHtml(dateOnly(season.fecha_inicio))} → ${escapeHtml(dateOnly(season.fecha_fin))}</td>
            <td><strong>${Number(season.intervalo_embarques_minutos || 60)} min</strong><small>24 horas · flujo global</small></td>
            <td>${statusBadge(season.activa)}${seasonTypeBadge(season)}</td>
            <td><div class="admin-season-actions">${seasonActions(season)}</div></td>
        </tr>
    `).join('');
}

// Una activación rechazada por pendientes abre el cierre de la temporada que los tiene.
function openClosingForPending(error) {
    if (error?.data?.codigo !== 'temporada_con_pendientes') return;
    const seasonId = error.data.pendientes?.[0]?.temporada?.id;
    if (seasonId) void openSeasonClosing(seasonId, error.message);
}

// Cierre de temporada: diagnóstico de pendientes, regularización auditada y aviso a responsables.
const closingState = { seasonId: null, payload: null, category: null, selected: new Set(), notice: null };

function closingDate(value) {
    if (!value) return '—';
    const date = new Date(String(value).replace(' ', 'T'));
    return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleString('es-CL', { dateStyle: 'short', timeStyle: 'short' });
}

function closingCategory() {
    return closingState.payload?.categorias?.find((category) => category.categoria === closingState.category) || null;
}

function renderClosingSummary() {
    const payload = closingState.payload;
    const categories = payload?.categorias || [];
    const total = Number(payload?.total || 0);
    const active = Boolean(payload?.temporada?.activa);
    const located = Number(payload?.folios_en_camaras || 0);
    elements.closingStatus.textContent = !total
        ? 'No quedan registros sin cerrar en esta temporada.'
        : active
            ? `Quedan ${total.toLocaleString('es-CL')} registros sin cerrar. Mientras existan, no se puede activar otra temporada.`
            : `Quedan ${total.toLocaleString('es-CL')} registros sin cerrar.${located ? ` ${located.toLocaleString('es-CL')} folios PT todavía figuran en cámaras e impiden activar otra temporada.` : ' La temporada está inactiva, así que no bloquean la activación.'}`;
    if (closingState.notice) elements.closingStatus.textContent = closingState.notice;
    elements.closingStatus.dataset.tone = total ? 'warning' : 'success';
    elements.closingNotify.disabled = !total;
    elements.closingSummary.innerHTML = categories.map((category) => {
        const pending = Number(category.total || 0);
        const regularized = Number(category.regularizados || 0);
        const note = !category.aplica
            ? 'Solo en la temporada activa'
            : regularized ? `${regularized.toLocaleString('es-CL')} regularizados` : 'Sin regularizaciones';
        return `<button type="button" data-closing-category="${escapeHtml(category.categoria)}" data-pending="${pending > 0}" aria-pressed="${category.categoria === closingState.category}"${pending ? '' : ' disabled'}>
            <span>${escapeHtml(category.etiqueta)}</span><strong>${pending.toLocaleString('es-CL')}</strong><small>${escapeHtml(note)}</small>
        </button>`;
    }).join('');
}

function renderClosingDetail() {
    const category = closingCategory();
    if (!category || !Number(category.total)) {
        elements.closingDetail.classList.add('is-hidden');
        return;
    }
    const items = category.items || [];
    elements.closingDetail.classList.remove('is-hidden');
    elements.closingDetailTitle.textContent = `${category.etiqueta} · ${Number(category.total).toLocaleString('es-CL')}`;
    elements.closingHowTo.textContent = `Cierre normal: ${category.como_cerrar}${items.length < Number(category.total) ? ` Se muestran los ${items.length} más antiguos.` : ''}`;
    elements.closingBlock.hidden = !category.bloqueo_regularizacion;
    elements.closingBlock.textContent = category.bloqueo_regularizacion || '';
    elements.closingEffect.textContent = `Al regularizar: ${category.efecto_regularizacion} Queda registrado con tu usuario, el motivo y una fotografía del registro.`;
    elements.closingItems.innerHTML = items.map((item) => `<tr>
        <td><input type="checkbox" data-closing-item="${escapeHtml(item.id)}" aria-label="Seleccionar ${escapeHtml(item.referencia)}"${closingState.selected.has(item.id) ? ' checked' : ''}${category.bloqueo_regularizacion ? ' disabled' : ''}></td>
        <td><strong>${escapeHtml(item.referencia)}</strong></td>
        <td>${escapeHtml(item.detalle || '—')}</td>
        <td>${escapeHtml(item.responsable?.nombre || 'Sin responsable')}</td>
        <td>${escapeHtml(closingDate(item.fecha))}</td>
    </tr>`).join('');
    elements.closingSelectAll.checked = items.length > 0 && items.every((item) => closingState.selected.has(item.id));
    elements.closingSelectAll.disabled = Boolean(category.bloqueo_regularizacion);
    updateClosingSubmit();
}

function updateClosingSubmit() {
    const category = closingCategory();
    const count = closingState.selected.size;
    elements.closingSubmit.disabled = !count || Boolean(category?.bloqueo_regularizacion);
    elements.closingSubmit.textContent = count ? `Regularizar ${count} ${count === 1 ? 'registro' : 'registros'}` : 'Regularizar seleccionados';
}

function renderClosingHistory() {
    const history = closingState.payload?.regularizaciones_recientes || [];
    elements.closingHistoryList.innerHTML = history.length ? `<table class="admin-archive-table"><thead><tr><th>Registro</th><th>Motivo</th><th>Por</th><th>Fecha</th></tr></thead><tbody>${history.map((entry) => `<tr>
        <td><strong>${escapeHtml(entry.referencia)}</strong><small>${escapeHtml(entry.etiqueta_categoria)} · estaba ${escapeHtml(statusText(entry.estado_anterior || '—'))}</small></td>
        <td>${escapeHtml(entry.etiqueta_motivo)}<small>${escapeHtml(entry.motivo)}</small></td>
        <td>${escapeHtml(entry.regularizado_por || '—')}</td>
        <td>${escapeHtml(closingDate(entry.regularizado_at))}</td>
    </tr>`).join('')}</tbody></table>` : '<p>Sin regularizaciones.</p>';
}

async function loadSeasonClosing() {
    if (!closingState.seasonId) return;
    try {
        const payload = (await api(`/api/administracion/temporadas/${closingState.seasonId}/cierre`)).data;
        closingState.payload = payload;
        const select = elements.closingForm.elements.motivo_categoria;
        const current = select.value;
        select.innerHTML = `<option value="">Selecciona…</option>${(payload.motivos || []).map((reason) => `<option value="${escapeHtml(reason.valor)}">${escapeHtml(reason.etiqueta)}</option>`).join('')}`;
        select.value = current;
        const pendingCategories = (payload.categorias || []).filter((category) => Number(category.total) > 0);
        if (!pendingCategories.some((category) => category.categoria === closingState.category)) {
            closingState.category = pendingCategories[0]?.categoria || null;
            closingState.selected.clear();
        }
        const visible = new Set((closingCategory()?.items || []).map((item) => item.id));
        for (const id of [...closingState.selected]) if (!visible.has(id)) closingState.selected.delete(id);
        renderClosingSummary();
        renderClosingDetail();
        renderClosingHistory();
    } catch (error) {
        elements.closingSummary.innerHTML = `<p>${escapeHtml(error.message)}</p>`;
        elements.closingDetail.classList.add('is-hidden');
    }
}

async function openSeasonClosing(seasonId, notice = null) {
    const season = state.seasons.find((candidate) => candidate.id === seasonId);
    if (!season) return;
    Object.assign(closingState, { seasonId: season.id, payload: null, category: null, notice });
    closingState.selected.clear();
    elements.closingTitle.textContent = `Cierre de ${season.codigo}`;
    elements.closingDescription.textContent = `${season.nombre}${season.tipo === 'prueba' ? ' · temporada de prueba' : ''}${season.activa ? ' · activa' : ''}. Lo que la temporada deja abierto: ciérralo en su módulo o, si ya no corresponde, regularízalo con motivo. Materiales no participa.`;
    elements.closingSummary.innerHTML = '<p>Cargando diagnóstico…</p>';
    elements.closingStatus.textContent = '';
    elements.closingDetail.classList.add('is-hidden');
    elements.closingForm.reset();
    elements.closingError.textContent = '';
    elements.closingDialog.showModal();
    await loadSeasonClosing();
}

function closeSeasonClosing() {
    closingState.seasonId = null;
    elements.closingDialog.close();
}

byId('closeSeasonClosing')?.addEventListener('click', closeSeasonClosing);
byId('cancelSeasonClosing')?.addEventListener('click', closeSeasonClosing);
byId('refreshSeasonClosing')?.addEventListener('click', () => {
    closingState.notice = null;
    void loadSeasonClosing();
});
elements.closingSummary?.addEventListener('click', (event) => {
    const button = event.target.closest('[data-closing-category]');
    if (!button || button.disabled) return;
    closingState.category = button.dataset.closingCategory;
    closingState.selected.clear();
    elements.closingError.textContent = '';
    renderClosingSummary();
    renderClosingDetail();
});
elements.closingItems?.addEventListener('change', (event) => {
    const checkbox = event.target.closest('[data-closing-item]');
    if (!checkbox) return;
    if (checkbox.checked) closingState.selected.add(checkbox.dataset.closingItem);
    else closingState.selected.delete(checkbox.dataset.closingItem);
    const items = closingCategory()?.items || [];
    elements.closingSelectAll.checked = items.length > 0 && items.every((item) => closingState.selected.has(item.id));
    updateClosingSubmit();
});
elements.closingSelectAll?.addEventListener('change', () => {
    for (const item of closingCategory()?.items || []) {
        if (elements.closingSelectAll.checked) closingState.selected.add(item.id);
        else closingState.selected.delete(item.id);
    }
    renderClosingDetail();
});
elements.closingForm?.addEventListener('submit', async (event) => {
    event.preventDefault();
    elements.closingError.textContent = '';
    const category = closingCategory();
    const form = elements.closingForm.elements;
    const reason = form.motivo.value.trim();
    if (!category || !closingState.selected.size) {
        elements.closingError.textContent = 'Selecciona al menos un registro.';
        return;
    }
    if (!form.motivo_categoria.value) {
        elements.closingError.textContent = 'Elige la categoría del motivo.';
        return;
    }
    if (reason.length < 10) {
        elements.closingError.textContent = 'Describe el motivo con al menos 10 caracteres.';
        return;
    }
    const count = closingState.selected.size;
    if (!window.confirm(`¿Regularizar ${count} ${count === 1 ? 'registro' : 'registros'} de ${category.etiqueta.charAt(0).toLowerCase()}${category.etiqueta.slice(1)}? ${category.efecto_regularizacion} No se puede deshacer.`)) return;
    setBusy(true, 'Regularizando registros…');
    try {
        const result = (await api(`/api/administracion/temporadas/${closingState.seasonId}/cierre/regularizar`, {
            method: 'POST',
            body: JSON.stringify({
                categoria: category.categoria,
                ids: [...closingState.selected],
                motivo_categoria: form.motivo_categoria.value,
                motivo: reason,
            }),
        })).data;
        closingState.selected.clear();
        closingState.notice = null;
        form.motivo.value = '';
        toast(`${result.regularizados} ${result.regularizados === 1 ? 'registro regularizado' : 'registros regularizados'}.`);
        await loadSeasonClosing();
    } catch (error) {
        elements.closingError.textContent = error.message;
    } finally {
        setBusy(false);
    }
});
elements.closingNotify?.addEventListener('click', async () => {
    if (!closingState.seasonId) return;
    elements.closingNotify.disabled = true;
    try {
        const result = (await api(`/api/administracion/temporadas/${closingState.seasonId}/cierre/avisar`, { method: 'POST' })).data;
        const notified = result.avisados || [];
        const fresh = notified.filter((entry) => entry.nuevo).length;
        const orphan = Number(result.sin_responsable || 0);
        toast(notified.length
            ? `Aviso enviado a ${notified.length} ${notified.length === 1 ? 'responsable' : 'responsables'}${fresh < notified.length ? ` (${notified.length - fresh} ya tenían este aviso)` : ''}.${orphan ? ` ${orphan} sin responsable identificado.` : ''}`
            : `No hay responsables identificados${orphan ? ` para ${orphan} registros` : ''}.`);
    } catch (error) {
        toast(error.message, true);
    } finally {
        elements.closingNotify.disabled = !Number(closingState.payload?.total || 0);
    }
});

// Archivo de temporada: solo administradores y solo temporadas cerradas hace más de 60 días.
const archiveState = { seasonId: null, timer: null };

function archiveStatus(archive) {
    return { en_proceso: 'En preparación', verificado: 'Verificado', fallido: 'Falló' }[archive.estado] || archive.estado;
}

function archiveSize(bytes) {
    if (!bytes) return '—';
    const value = Number(bytes);
    return value < 1048576
        ? `${Math.max(1, Math.round(value / 1024)).toLocaleString('es-CL')} KB`
        : `${(value / 1048576).toLocaleString('es-CL', { maximumFractionDigits: 1 })} MB`;
}

function renderSeasonArchive(payload) {
    const { elegible, motivo, archivos } = payload;
    elements.archiveEligibility.textContent = elegible ? 'La temporada puede archivarse.' : motivo;
    elements.archiveEligibility.dataset.tone = elegible ? 'success' : 'warning';
    const inProgress = archivos.some((archive) => archive.estado === 'en_proceso');
    elements.archiveCreate.disabled = !elegible || inProgress;
    elements.archiveList.innerHTML = archivos.length ? `<table class="admin-archive-table"><thead><tr><th>Solicitado</th><th>Estado</th><th>Contenido</th><th>Tamaño</th><th><span class="office-visually-hidden">Acción</span></th></tr></thead><tbody>${archivos.map((archive) => `<tr>
        <td>${escapeHtml(new Date(archive.creado_at).toLocaleString('es-CL', { dateStyle: 'short', timeStyle: 'short' }))}<small>${escapeHtml(archive.solicitado_por || '')}</small></td>
        <td><span data-archive-state="${escapeHtml(archive.estado)}">${escapeHtml(archiveStatus(archive))}</span>${archive.mensaje_error ? `<small>${escapeHtml(archive.mensaje_error)}</small>` : ''}</td>
        <td>${archive.tablas ? `${Number(archive.tablas)} tablas · ${Number(archive.filas).toLocaleString('es-CL')} filas<small>Excel: ${escapeHtml(archive.excel.join(', ') || '—')}</small>` : '—'}</td>
        <td>${escapeHtml(archiveSize(archive.tamano_bytes))}</td>
        <td>${archive.estado === 'verificado' ? `<button type="button" data-download-archive="${escapeHtml(archive.id)}">Descargar</button>` : ''}</td>
    </tr>`).join('')}</tbody></table>` : '<p>Esta temporada todavía no tiene archivos.</p>';
    window.clearTimeout(archiveState.timer);
    if (inProgress && elements.archiveDialog.open) archiveState.timer = window.setTimeout(() => void loadSeasonArchive(), 10000);
}

async function loadSeasonArchive() {
    if (!archiveState.seasonId) return;
    try {
        renderSeasonArchive((await api(`/api/administracion/temporadas/${archiveState.seasonId}/archivos`)).data);
    } catch (error) {
        elements.archiveList.innerHTML = `<p>${escapeHtml(error.message)}</p>`;
    }
}

async function openSeasonArchive(seasonId) {
    const season = state.seasons.find((candidate) => candidate.id === seasonId);
    if (!season) return;
    archiveState.seasonId = season.id;
    elements.archiveDescription.textContent = `Temporada ${season.codigo} · ${season.nombre}. Respaldo restaurable: esquema, datos, Excel de líneas limpias y verificación de integridad. Generarlo no borra ningún dato.`;
    elements.archiveList.innerHTML = '<p>Cargando archivos…</p>';
    elements.archiveEligibility.textContent = '';
    elements.archiveCreate.disabled = true;
    elements.archiveDialog.showModal();
    await loadSeasonArchive();
}

async function downloadSeasonArchive(archiveId) {
    setBusy(true, 'Descargando archivo de temporada…');
    try {
        const response = await fetch(`/api/administracion/archivos-temporada/${encodeURIComponent(archiveId)}/descargar`, {
            headers: { Accept: 'application/zip', Authorization: `Bearer ${state.token}` },
        });
        if (!response.ok) throw new ApiError('No fue posible descargar el archivo.', response.status);
        const name = /filename="?([^";]+)"?/.exec(response.headers.get('Content-Disposition') ?? '')?.[1] ?? 'archivo-temporada.zip';
        const url = URL.createObjectURL(await response.blob());
        const link = document.createElement('a');
        link.href = url;
        link.download = name;
        link.click();
        window.setTimeout(() => URL.revokeObjectURL(url), 1000);
    } catch (error) {
        toast(error.message, true);
    } finally {
        setBusy(false);
    }
}

function closeSeasonArchive() {
    window.clearTimeout(archiveState.timer);
    archiveState.seasonId = null;
    elements.archiveDialog.close();
}

byId('closeSeasonArchive')?.addEventListener('click', closeSeasonArchive);
byId('cancelSeasonArchive')?.addEventListener('click', closeSeasonArchive);
elements.archiveList?.addEventListener('click', (event) => {
    const download = event.target.closest('[data-download-archive]');
    if (download) void downloadSeasonArchive(download.dataset.downloadArchive);
});
elements.archiveCreate?.addEventListener('click', async () => {
    if (!archiveState.seasonId) return;
    elements.archiveCreate.disabled = true;
    try {
        await api(`/api/administracion/temporadas/${archiveState.seasonId}/archivos`, { method: 'POST' });
        toast('Archivo en preparación. Puedes cerrar esta ventana; el proceso continúa en el servidor.');
    } catch (error) {
        toast(error.message, true);
    }
    await loadSeasonArchive();
});

function renderResetPreview(preview) {
    const scopes = preview.resumen || {};
    const cards = Object.entries(scopes).map(([scope, counts]) => {
        const total = Object.values(counts).reduce((sum, value) => sum + Number(value || 0), 0);
        const details = Object.entries(counts)
            .filter(([, value]) => Number(value) > 0)
            .map(([key, value]) => `${statusText(key)}: ${Number(value)}`)
            .join(' · ');
        return `<article><span>${escapeHtml(statusText(scope))}</span><strong>${total}</strong><small>${escapeHtml(details || 'Sin registros')}</small></article>`;
    }).join('');
    elements.resetPreview.innerHTML = cards || '<p>No hay registros operacionales para eliminar.</p>';
}

async function openOperationalReset(seasonId) {
    const season = state.seasons.find((candidate) => candidate.id === seasonId && candidate.activa);
    if (!season) return;
    state.resetPreview = null;
    elements.resetForm.reset();
    elements.resetError.textContent = '';
    elements.resetPreview.innerHTML = '<p>Calculando registros de la temporada activa…</p>';
    elements.resetDescription.textContent = `Temporada ${season.codigo} · ${season.nombre}. La temporada activa se mantiene; solo se elimina su operación de Frigorífico y Materia Prima.`;
    elements.resetDialog.showModal();
    setBusy(true, 'Preparando vista previa del reinicio…');
    try {
        const response = await api(`/api/administracion/temporadas/${season.id}/reinicio-operacional`);
        state.resetPreview = response.data;
        elements.resetPhrase.textContent = response.data.frase_confirmacion;
        renderResetPreview(response.data);
    } catch (error) {
        elements.resetError.textContent = error.message;
    } finally {
        setBusy(false);
    }
}

function closeOperationalReset() {
    state.resetPreview = null;
    elements.resetDialog.close();
}

function resetClientForm() {
    elements.clientForm.reset();
    elements.clientForm.elements.id.value = '';
    elements.clientForm.elements.activo.checked = true;
    elements.clientError.textContent = '';
    elements.clientCancel.classList.add('is-hidden');
}

function renderClients() {
    elements.activeClients.textContent = String(state.clients.filter((client) => client.activo).length);
    elements.clientsSummary.textContent = `${state.clients.length} ${state.clients.length === 1 ? 'registrado' : 'registrados'}`;

    if (!state.clients.length) {
        elements.clientsTableBody.innerHTML = '<tr class="admin-empty"><td colspan="6">No existen clientes globales registrados.</td></tr>';
        return;
    }

    elements.clientsTableBody.innerHTML = state.clients.map((client) => {
        const aliases = (client.aliases || [])
            .filter((alias) => alias.codigo && alias.codigo !== client.codigo)
            .map((alias) => alias.codigo);
        const aliasDetail = [...new Set(aliases)].length
            ? ` · alias: ${escapeHtml([...new Set(aliases)].join(', '))}`
            : '';
        const presence = `${Number(client.presencias?.materiales || 0)} temporadas de Materiales · ${Number(client.presencias?.validacion || 0)} temporadas de Validación`;

        return `
            <tr>
                <td><strong>${escapeHtml(client.codigo)} · ${escapeHtml(client.nombre)}</strong><small>Maestro transversal${aliasDetail}</small></td>
                <td>${client.codigo_folio_materiales ? `<strong>${escapeHtml(client.codigo_folio_materiales)}</strong><small>F / I / E + 7 dígitos</small>` : 'Pendiente'}</td>
                <td>${escapeHtml(client.codigo_externo || '—')}</td>
                <td>${escapeHtml(presence)}</td>
                <td>${statusBadge(client.activo)}</td>
                <td><div class="admin-season-actions"><button data-edit-client="${client.id}" type="button">Editar</button></div></td>
            </tr>
        `;
    }).join('');
}

async function loadAccesses() {
    const [response, seasons, clients] = await Promise.all([
        api('/api/administracion/accesos'),
        api('/api/administracion/temporadas'),
        api('/api/administracion/clientes'),
        ...(state.identity?.puede_administrar_accesos ? [loadAccessSessions()] : []),
    ]);
    state.users = response.usuarios;
    state.devices = response.dispositivos;
    state.seasons = seasons.data || [];
    state.clients = clients.data || [];
    renderUsers();
    renderDevices();
    renderSeasons();
    renderClients();
}

elements.accessSessionsTableBody.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-disconnect-session]');
    if (!button || !elements.accessSessionsTableBody.contains(button)) return;
    const session = state.accessSessions.find((candidate) => candidate.id === Number(button.dataset.disconnectSession));
    if (!session) return;
    const label = session.tipo === 'tablet' ? session.dispositivo?.nombre || 'tablet' : 'Oficina PC';
    const message = `¿Desconectar el acceso de ${session.usuario.nombre} en ${label}?${session.tipo === 'tablet' ? ' Si tiene una cámara abierta, también se cerrará y liberará.' : ''}`;
    if (!window.confirm(message)) return;

    setBusy(true, 'Desconectando sesión…');
    try {
        const result = await api(`/api/administracion/sesiones-acceso/${session.id}`, { method: 'DELETE' });
        if (result.sesion_actual_cerrada) {
            clearSession();
            elements.loginError.textContent = 'Cerraste tu propia sesión. Vuelve a iniciar sesión para continuar.';
            return;
        }
        const page = state.accessSessions.length === 1 && state.accessSessionsPage > 1
            ? state.accessSessionsPage - 1 : state.accessSessionsPage;
        await loadAccessSessions(page);
        toast(`Acceso de ${session.usuario.nombre} desconectado.${result.sesiones_camara_cerradas ? ` ${result.sesiones_camara_cerradas} sesión(es) de cámara cerradas.` : ''}`);
    } catch (error) {
        toast(error.message, true);
    } finally {
        setBusy(false);
    }
});

elements.accessSessionsPrevious.addEventListener('click', async () => {
    try { await loadAccessSessions(state.accessSessionsPage - 1); } catch (error) { toast(error.message, true); }
});
elements.accessSessionsNext.addEventListener('click', async () => {
    try { await loadAccessSessions(state.accessSessionsPage + 1); } catch (error) { toast(error.message, true); }
});

document.addEventListener('estiba:office-panel-change', (event) => {
    if (event.detail?.group === 'administration' && event.detail?.panel === 'sessions'
        && state.token && state.identity?.puede_administrar_accesos === true) {
        void loadAccessSessions().catch((error) => toast(error.message, true));
    }
});

elements.loginForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    elements.loginError.textContent = '';
    const data = Object.fromEntries(new FormData(elements.loginForm));
    setBusy(true, 'Validando acceso administrativo…');
    try {
        const payload = await api('/api/acceso-oficina', {
            method: 'POST',
            body: JSON.stringify(data),
        });
        state.token = payload.token;
        if (payload.usuario.puede_consultar_accesos !== true) {
            await api('/api/acceso-oficina', { method: 'DELETE' });
            clearSession();
            throw new ApiError('Tu perfil no puede consultar Accesos y Temporadas.', 403);
        }
        persistSession(payload);
        showApp();
        await loadAccesses();
    } catch (error) {
        elements.loginError.textContent = error.message;
    } finally {
        setBusy(false);
    }
});

elements.userForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    elements.userError.textContent = '';
    const data = Object.fromEntries(new FormData(elements.userForm));
    const validationMessage = userValidationMessage(data);
    if (validationMessage) {
        elements.userError.textContent = validationMessage;
        return;
    }
    setBusy(true, 'Creando usuario…');
    try {
        const response = await api('/api/administracion/usuarios', {
            method: 'POST',
            body: JSON.stringify(data),
        });
        elements.userForm.reset();
        await loadAccesses();
        toast(`${response.usuario.nombre} fue creado correctamente.`);
    } catch (error) {
        elements.userError.textContent = error.message;
    } finally {
        setBusy(false);
    }
});

elements.deviceForm.elements.codigo.addEventListener('input', (event) => {
    const cursor = event.target.selectionStart;
    event.target.value = event.target.value.toUpperCase().replace(/[^A-Z0-9-]/g, '');
    event.target.setSelectionRange(cursor, cursor);
});

elements.deviceForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    elements.deviceError.textContent = '';
    const data = Object.fromEntries(new FormData(elements.deviceForm));
    setBusy(true, 'Autorizando tablet…');
    try {
        const response = await api('/api/administracion/dispositivos', {
            method: 'POST',
            body: JSON.stringify(data),
        });
        elements.deviceForm.reset();
        await loadAccesses();
        toast(`${response.dispositivo.codigo} quedó autorizada.`);
    } catch (error) {
        elements.deviceError.textContent = error.message;
    } finally {
        setBusy(false);
    }
});

elements.seasonForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    elements.seasonError.textContent = '';
    const data = Object.fromEntries(new FormData(elements.seasonForm));
    const id = data.id;
    delete data.id;
    data.activa = elements.seasonForm.elements.activa.checked;
    setBusy(true, 'Guardando temporada transversal…');
    try {
        await api(id ? `/api/administracion/temporadas/${id}` : '/api/administracion/temporadas', {
            method: id ? 'PUT' : 'POST',
            body: JSON.stringify(data),
        });
        resetSeasonForm();
        await loadAccesses();
        toast('La temporada quedó disponible para todas las oficinas.');
    } catch (error) {
        elements.seasonError.textContent = error.message;
        openClosingForPending(error);
    } finally {
        setBusy(false);
    }
});

elements.clientForm.elements.codigo.addEventListener('input', (event) => {
    event.target.value = event.target.value.toUpperCase().replace(/[^A-Z0-9._-]/g, '');
});
elements.clientForm.elements.codigo_folio_materiales.addEventListener('input', (event) => {
    event.target.value = event.target.value.toUpperCase().replace(/[^A-Z]/g, '').slice(0, 2);
});

elements.clientForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    elements.clientError.textContent = '';
    const data = Object.fromEntries(new FormData(elements.clientForm));
    const id = data.id;
    delete data.id;
    data.activo = elements.clientForm.elements.activo.checked;
    setBusy(true, 'Guardando cliente transversal…');
    try {
        await api(id ? `/api/administracion/clientes/${id}` : '/api/administracion/clientes', {
            method: id ? 'PUT' : 'POST',
            body: JSON.stringify(data),
        });
        resetClientForm();
        await loadAccesses();
        toast('Cliente actualizado para todas las oficinas.');
    } catch (error) {
        elements.clientError.textContent = error.message;
    } finally {
        setBusy(false);
    }
});

elements.clientsTableBody.addEventListener('click', (event) => {
    const button = event.target.closest('[data-edit-client]');
    if (!button) return;
    const client = state.clients.find((candidate) => candidate.id === button.dataset.editClient);
    if (!client) return;
    for (const field of ['id', 'codigo', 'nombre', 'codigo_externo', 'codigo_folio_materiales']) {
        elements.clientForm.elements[field].value = client[field] || '';
    }
    elements.clientForm.elements.activo.checked = client.activo;
    elements.clientCancel.classList.remove('is-hidden');
    elements.clientForm.elements.codigo.focus();
});

elements.seasonsTableBody.addEventListener('click', async (event) => {
    const edit = event.target.closest('[data-edit-season]');
    const activate = event.target.closest('[data-activate-season]');
    const migrate = event.target.closest('[data-migrate-season]');
    const reset = event.target.closest('[data-reset-season]');
    const archive = event.target.closest('[data-archive-season]');
    const classify = event.target.closest('[data-classify-season]');
    const closing = event.target.closest('[data-closing-season]');
    if (closing) await openSeasonClosing(closing.dataset.closingSeason);
    if (archive) await openSeasonArchive(archive.dataset.archiveSeason);
    if (classify) openClassificationForm(classify.dataset.classifySeason, classify.dataset.classifyType);
    if (edit) {
        const season = state.seasons.find((candidate) => candidate.id === edit.dataset.editSeason);
        if (!season) return;
        for (const field of ['id', 'codigo', 'nombre', 'fecha_inicio', 'fecha_fin', 'prefijo_documental', 'intervalo_embarques_minutos']) {
            elements.seasonForm.elements[field].value = season[field] || '';
        }
        elements.seasonForm.elements.tipo.value = season.tipo;
        elements.seasonForm.elements.tipo.disabled = true;
        elements.seasonForm.elements.activa.checked = season.activa;
        updateSeasonTypeForm();
        elements.seasonCancel.classList.remove('is-hidden');
        elements.seasonForm.elements.codigo.focus();
    }
    if (migrate) openMigrationForm(migrate.dataset.migrateSeason);
    if (reset) await openOperationalReset(reset.dataset.resetSeason);
    if (activate) {
        setBusy(true, 'Activando temporada para todas las oficinas…');
        try {
            await api(`/api/administracion/temporadas/${activate.dataset.activateSeason}/activar`, { method: 'POST' });
            resetSeasonForm();
            await loadAccesses();
            toast('Temporada global activada.');
        } catch (error) {
            toast(error.message, true);
            openClosingForPending(error);
        } finally {
            setBusy(false);
        }
    }
});

elements.resetForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    elements.resetError.textContent = '';
    const preview = state.resetPreview;
    if (!preview) {
        elements.resetError.textContent = 'Vuelve a abrir el reinicio para cargar una vista previa vigente.';
        return;
    }
    const form = elements.resetForm.elements;
    const data = {
        operacion_id: operationUuid(),
        motivo: form.motivo.value,
        password: form.password.value,
        confirmacion: form.confirmacion.value,
        confirmar_exclusion_bodega: form.confirmar_exclusion_bodega.checked,
        confirmar_preservar_configuracion: form.confirmar_preservar_configuracion.checked,
    };
    if (data.confirmacion !== preview.frase_confirmacion) {
        elements.resetError.textContent = `Escribe exactamente: ${preview.frase_confirmacion}`;
        return;
    }
    if (!data.confirmar_exclusion_bodega || !data.confirmar_preservar_configuracion) {
        elements.resetError.textContent = 'Confirma ambas condiciones de protección antes de continuar.';
        return;
    }

    setBusy(true, 'Reiniciando Frigorífico y Materia Prima…');
    try {
        await api(`/api/administracion/temporadas/${preview.temporada.id}/reinicio-operacional`, {
            method: 'POST',
            body: JSON.stringify(data),
        });
        closeOperationalReset();
        await loadAccesses();
        toast(`Temporada ${preview.temporada.codigo}: Frigorífico y Materia Prima quedaron en cero.`);
    } catch (error) {
        elements.resetError.textContent = error.message;
    } finally {
        setBusy(false);
    }
});

elements.resetClose.addEventListener('click', closeOperationalReset);
elements.resetCancel.addEventListener('click', closeOperationalReset);

elements.migrationForm.elements.migrar_inventario_materiales.addEventListener('change', (event) => {
    if (!event.target.checked) return;
    elements.migrationForm.elements.copiar_catalogo_materiales.checked = true;
    elements.migrationForm.elements.activar_destino.checked = true;
});

elements.migrationForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    elements.migrationError.textContent = '';
    const form = elements.migrationForm.elements;
    const destinationId = form.temporada_destino_id.value;
    const data = {
        temporada_origen_id: form.temporada_origen_id.value,
        copiar_catalogo_validacion: form.copiar_catalogo_validacion.checked,
        copiar_catalogo_materiales: form.copiar_catalogo_materiales.checked,
        migrar_inventario_materiales: form.migrar_inventario_materiales.checked,
        activar_destino: form.activar_destino.checked,
    };
    if (!data.copiar_catalogo_validacion && !data.copiar_catalogo_materiales && !data.migrar_inventario_materiales) {
        elements.migrationError.textContent = 'Selecciona al menos un catálogo o el inventario de bodega.';
        return;
    }
    if (data.migrar_inventario_materiales && !window.confirm('Se trasladará todo el inventario vivo y se activará la temporada de destino para todas las oficinas. ¿Deseas continuar?')) return;

    setBusy(true, 'Migrando datos entre temporadas…');
    try {
        const response = await api(`/api/administracion/temporadas/${destinationId}/migrar`, {
            method: 'POST',
            body: JSON.stringify(data),
        });
        const inventory = response.data.resumen.inventario;
        resetMigrationForm();
        await loadAccesses();
        toast(`Migración completada: ${inventory.folios} folios de bodega trasladados.`);
    } catch (error) {
        elements.migrationError.textContent = error.message;
        openClosingForPending(error);
    } finally {
        setBusy(false);
    }
});

elements.migrationCancel.addEventListener('click', resetMigrationForm);
elements.classificationCancel.addEventListener('click', resetClassificationForm);
elements.seasonForm.elements.prefijo_documental.addEventListener('input', (event) => {
    event.target.value = event.target.value.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 6);
});

elements.classificationForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    elements.classificationError.textContent = '';
    const form = elements.classificationForm.elements;
    const type = form.tipo.value;
    const action = type === 'prueba' ? 'declarar-prueba' : 'declarar-productiva';
    setBusy(true, 'Actualizando el tipo de temporada…');
    try {
        await api(`/api/administracion/temporadas/${form.temporada_id.value}/${action}`, {
            method: 'POST',
            body: JSON.stringify({ motivo: form.motivo.value }),
        });
        resetClassificationForm();
        await loadAccesses();
        toast(type === 'prueba' ? 'La temporada quedó declarada de prueba.' : 'La temporada volvió a ser productiva.');
    } catch (error) {
        elements.classificationError.textContent = error.message;
    } finally {
        setBusy(false);
    }
});

elements.seasonCancel.addEventListener('click', resetSeasonForm);
elements.seasonForm.elements.tipo.addEventListener('change', updateSeasonTypeForm);
updateSeasonTypeForm();
elements.clientCancel.addEventListener('click', resetClientForm);

elements.reload.addEventListener('click', async () => {
    setBusy(true, 'Actualizando accesos…');
    try {
        await loadAccesses();
        toast('Listados actualizados.');
    } catch (error) {
        toast(error.message, true);
    } finally {
        setBusy(false);
    }
});

window.addEventListener('estiba:access-profile-saved', () => {
    void loadAccesses();
});

elements.logout.addEventListener('click', async () => {
    try {
        await api('/api/acceso-oficina', { method: 'DELETE' });
    } finally {
        clearSession();
    }
});

async function boot() {
    if (!state.token || state.identity?.puede_consultar_accesos !== true) {
        if (state.token) {
            clearSession();
            elements.loginError.textContent = 'Inicia sesión con un perfil autorizado para consultar Accesos y Temporadas.';
        }
        return;
    }
    showApp();
    setBusy(true, 'Cargando configuración transversal…');
    try {
        await loadAccesses();
    } catch (error) {
        if (error.status !== 401) toast(error.message, true);
    } finally {
        setBusy(false);
    }
}

void boot();
