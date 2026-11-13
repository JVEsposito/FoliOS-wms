const byId = (id) => document.getElementById(id);
const elements = {
    access: byId('officeAccess'), app: byId('officeApp'), login: byId('officeLoginForm'), loginError: byId('officeLoginError'),
    userName: byId('officeUserName'), userRole: byId('officeUserRole'), initials: byId('officeInitials'), logout: byId('officeLogoutButton'),
    camerasNav: byId('officeCamerasNav'), loadsNav: byId('officeLoadsNav'), materialsNav: byId('officeMaterialsNav'), validationNav: byId('officeValidationNav'), accessesNav: byId('officeAccessesNav'), managementNav: byId('officeManagementNav'), romanaNav: byId('officeRomanaNav'),
    reload: byId('reloadPrefrioButton'), newTunnel: byId('newTunnelButton'), newProcess: byId('newProcessButton'),
    viewStatus: byId('prefrioViewStatus'), updatedAt: byId('prefrioUpdatedAt'),
    activeTunnels: byId('activeTunnelCount'), running: byId('runningProcessCount'), pending: byId('pendingVerificationCount'), reprocess: byId('reprocessCount'), activeFolios: byId('activeFolioCount'),
    tunnelSummary: byId('tunnelSummary'), tunnelList: byId('tunnelList'), filters: byId('processFilters'), processBody: byId('processTableBody'),
    detail: byId('processDetail'), detailTitle: byId('processDetailTitle'), detailSubtitle: byId('processDetailSubtitle'), detailMetrics: byId('processDetailMetrics'),
    tunnelMap: byId('processTunnelMap'), timeline: byId('processTimeline'), refreshProcess: byId('refreshProcessButton'), closeDetail: byId('closeProcessDetailButton'),
    decisionPanel: byId('decisionPanel'), decisionFolios: byId('decisionFolios'), decisionError: byId('decisionError'),
    decisionOccurredAt: byId('decisionOccurredAt'), operationalPanel: byId('operationalPanel'), operationalForm: byId('operationalActionForm'),
    operationalAction: byId('operationalActionSelect'), operationalReadingField: byId('operationalReadingField'), operationalReadingTitle: byId('operationalReadingTitle'),
    operationalReadingNote: byId('operationalReadingNote'), operationalError: byId('operationalActionError'),
    r41Panel: byId('r41Panel'), r41Status: byId('r41Status'), r41Readings: byId('r41Readings'), r41DataForm: byId('r41DataForm'), r41DataError: byId('r41DataError'), r41Download: byId('r41DownloadButton'),
    approve: byId('approveProcessButton'), reprocessButton: byId('reprocessProcessButton'), cancelProcess: byId('cancelProcessButton'),
    tunnelDialog: byId('tunnelDialog'), tunnelForm: byId('tunnelForm'), tunnelDialogTitle: byId('tunnelDialogTitle'), tunnelError: byId('tunnelFormError'), tunnelPreview: byId('tunnelPreview'), tunnelPreviewSummary: byId('tunnelPreviewSummary'),
    processDialog: byId('processDialog'), processForm: byId('processForm'), processError: byId('processFormError'),
    reasonDialog: byId('reasonDialog'), reasonForm: byId('reasonForm'), reasonTitle: byId('reasonTitle'), reasonDescription: byId('reasonDescription'), reasonEyebrow: byId('reasonEyebrow'), reasonError: byId('reasonError'), confirmReason: byId('confirmReasonButton'),
    correctProcess: byId('correctProcessButton'), correctionDialog: byId('correctionDialog'), correctionForm: byId('correctionForm'), correctionTitle: byId('correctionDialogTitle'), correctionEvents: byId('correctionEvents'), correctionFolios: byId('correctionFolios'), correctionNewPosition: byId('correctionNewPosition'), correctionError: byId('correctionError'),
    loading: byId('officeLoading'), loadingText: byId('officeLoadingText'), toasts: byId('officeToasts'),
};
const keys = { token: 'estiba_wms_office_token', identity: 'estiba_wms_office_identity' };
// POEMP R-41: tres sectores con sensor exterior e interior a cada lado, en el orden de la planilla.
const r41Sectors = [['ventilador', 'Ventilador'], ['medio', 'Medio'], ['puerta', 'Puerta']];
const r41Points = ['izq_ex', 'izq_in', 'der_in', 'der_ex'];
const activeStates = new Set(['borrador', 'cargando', 'listo_para_iniciar', 'en_proceso', 'pendiente_verificacion']);
const state = {
    token: localStorage.getItem(keys.token), identity: readJson(keys.identity), tunnels: [], processes: [], summary: null, selectedTunnelId: null,
    selectedProcess: null, reasonMode: null,
};

class ApiError extends Error {
    constructor(message, status, data = {}) { super(message); this.name = 'ApiError'; this.status = status; this.data = data; }
}
function readJson(key) { try { return JSON.parse(localStorage.getItem(key) || 'null'); } catch { return null; } }
function escapeHtml(value) { return String(value ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&#039;'); }
function errorMessage(data, fallback) { return Object.values(data?.errors || {}).flat()[0] || data?.message || fallback; }
function formatDate(value, fallback = 'Sin fecha') { if (!value) return fallback; const date = new Date(value); return Number.isNaN(date.getTime()) ? fallback : new Intl.DateTimeFormat('es-CL', { dateStyle: 'short', timeStyle: 'short' }).format(date); }
function localDateTimeValue(value = new Date()) { const date = new Date(value); const offset = date.getTimezoneOffset() * 60000; return new Date(date.getTime() - offset).toISOString().slice(0, 16); }
function occurredAt(value) { const date = new Date(value); if (!value || Number.isNaN(date.getTime())) throw new ApiError('Ingresa una fecha y hora válida.', 422); return date.toISOString(); }
function statusText(value) {
    const labels = {
        borrador: 'Borrador', cargando: 'Cargando', listo_para_iniciar: 'Listo para iniciar', en_proceso: 'En proceso', pendiente_verificacion: 'Pendiente de verificación',
        aprobado: 'Aprobado', requiere_reproceso: 'Requiere reproceso', cancelado: 'Cancelado', operativo: 'Operativo', mantenimiento: 'Mantenimiento', fuera_de_servicio: 'Fuera de servicio',
        activo: 'Activo', inactivo: 'Inactivo', carga_iniciada: 'Carga iniciada', pallet_agregado: 'Pallet agregado', pallet_retirado: 'Pallet retirado', armado_confirmado: 'Armado confirmado',
        proceso_iniciado: 'Proceso iniciado', inversion_registrada: 'Inversión registrada', pausa: 'Pausa', reanudacion: 'Reanudación', deshielo: 'Deshielo', lectura: 'Lectura',
        verificacion_final: 'Verificación final', aprobacion: 'Aprobación', reproceso: 'Reproceso', cancelacion: 'Cancelación', correccion_administrativa: 'Corrección administrativa',
    };
    return labels[value] || String(value || '').replaceAll('_', ' ').replace(/^./, (letter) => letter.toUpperCase());
}
function positionMeta(number) {
    return { side: number % 2 === 1 ? 'A' : 'B', depth: Math.ceil(number / 2) };
}
function setBusy(active, message = 'Procesando…') { elements.loadingText.textContent = message; elements.loading.classList.toggle('is-hidden', !active); elements.loading.setAttribute('aria-hidden', String(!active)); }
function toast(message, error = false) { const node = document.createElement('div'); node.className = `toast${error ? ' toast--error' : ''}`; node.textContent = message; elements.toasts.append(node); window.setTimeout(() => node.remove(), 4500); }
function operationUuid() { if (typeof crypto.randomUUID === 'function') return crypto.randomUUID(); const bytes = crypto.getRandomValues(new Uint8Array(16)); bytes[6] = (bytes[6] & 0x0f) | 0x40; bytes[8] = (bytes[8] & 0x3f) | 0x80; const hex = [...bytes].map((value) => value.toString(16).padStart(2, '0')).join(''); return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`; }
function persist(payload) { state.token = payload.token; state.identity = payload.usuario; localStorage.setItem(keys.token, payload.token); localStorage.setItem(keys.identity, JSON.stringify(payload.usuario)); }
function clearSession() { state.token = null; state.identity = null; localStorage.removeItem(keys.token); localStorage.removeItem(keys.identity); elements.app.classList.add('is-hidden'); elements.access.classList.remove('is-hidden'); }
async function api(path, options = {}) {
    const headers = new Headers(options.headers || {}); headers.set('Accept', 'application/json');
    if (state.token) headers.set('Authorization', `Bearer ${state.token}`);
    if (options.body && !(options.body instanceof FormData)) headers.set('Content-Type', 'application/json');
    let response;
    try { response = await fetch(path, { ...options, headers }); } catch { throw new ApiError('No fue posible conectar con Laravel.', 0); }
    const data = response.status === 204 ? null : await response.json().catch(() => ({}));
    if (!response.ok) { if (response.status === 401) clearSession(); throw new ApiError(errorMessage(data, 'No fue posible completar la operación.'), response.status, data); }
    return data;
}

function showApp() {
    if (state.identity?.puede_consultar_prefrio !== true) { clearSession(); elements.loginError.textContent = 'El usuario no posee acceso a Prefrío.'; return false; }
    elements.access.classList.add('is-hidden'); elements.app.classList.remove('is-hidden');
    const name = state.identity?.nombre || 'Usuario'; elements.userName.textContent = name; elements.userRole.textContent = statusText(state.identity?.rol);
    elements.initials.textContent = name.split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0]).join('').toUpperCase();
    elements.accessesNav.classList.toggle('is-hidden', state.identity?.puede_administrar_accesos !== true);
    elements.managementNav.classList.toggle('is-hidden', state.identity?.puede_consultar_panel_gerencial !== true);
    elements.romanaNav.classList.toggle('is-hidden', state.identity?.puede_consultar_romana !== true);
    elements.camerasNav.classList.toggle('is-hidden', state.identity?.ambito_camaras === 'ninguno');
    elements.loadsNav.classList.toggle('is-hidden', state.identity?.puede_consultar_cargas !== true);
    elements.materialsNav.classList.toggle('is-hidden', state.identity?.puede_consultar_despachos_materiales !== true);
    elements.validationNav.classList.toggle('is-hidden', state.identity?.puede_consultar_validaciones_pallet !== true);
    elements.newTunnel.classList.toggle('is-hidden', state.identity?.puede_administrar_tuneles_prefrio !== true);
    elements.newProcess.classList.toggle('is-hidden', state.identity?.puede_operar_prefrio !== true);
    return true;
}

async function loadTunnels() {
    const response = await api('/api/prefrio/tuneles'); state.tunnels = response.data || [];
    if (state.selectedTunnelId && !state.tunnels.some((item) => item.id === state.selectedTunnelId)) state.selectedTunnelId = null;
    renderTunnels(); renderProcessTunnelOptions(); renderProcessFormOptions(); renderMetrics();
}
async function loadProcesses() {
    const params = new URLSearchParams(); const values = Object.fromEntries(new FormData(elements.filters));
    for (const [key, value] of Object.entries(values)) if (String(value).trim()) params.set(key, String(value).trim());
    params.set('per_page', '50');
    const response = await api(`/api/prefrio/procesos?${params}`); state.processes = response.data || [];
    renderProcesses(); renderMetrics();
}
async function loadSummary() { state.summary = await api('/api/prefrio/resumen'); renderMetrics(); }
function markViewUpdated() {
    elements.viewStatus.textContent = 'Información vigente';
    elements.updatedAt.textContent = `Última lectura: ${formatDate(new Date())}`;
}
async function loadAll() {
    elements.viewStatus.textContent = 'Consultando operación…';
    await Promise.all([loadTunnels(), loadProcesses(), loadSummary()]);
    if (state.selectedProcess?.id) await selectProcess(state.selectedProcess.id, false, false);
    markViewUpdated();
}

function renderMetrics() {
    elements.activeTunnels.textContent = String(state.tunnels.filter((item) => item.estado_administrativo === 'activo' && item.estado_tecnico === 'operativo').length);
    elements.running.textContent = String(state.summary?.en_proceso ?? '—');
    elements.pending.textContent = String(state.summary?.pendiente_verificacion ?? '—');
    elements.reprocess.textContent = String(state.summary?.requiere_reproceso ?? '—');
    elements.activeFolios.textContent = String(state.summary?.folios_activos ?? '—');
}
function renderTunnels() {
    elements.tunnelSummary.textContent = `${state.tunnels.length} configurados`;
    elements.tunnelList.innerHTML = state.tunnels.map((item) => {
        const active = item.proceso_activo;
        const inactive = item.estado_administrativo !== 'activo' || item.estado_tecnico !== 'operativo';
        return `<article class="tunnel-card${state.selectedTunnelId === item.id ? ' is-selected' : ''}${inactive ? ' is-inactive' : ''}" data-tunnel-id="${item.id}">
            <div class="tunnel-card__top"><strong>${escapeHtml(item.codigo)}</strong><span class="status-pill status-pill--${escapeHtml(active?.estado || item.estado_tecnico)}">${escapeHtml(active ? statusText(active.estado) : statusText(item.estado_tecnico))}</span></div>
            <div><h3>${escapeHtml(item.nombre)}</h3><p>${item.capacidad_posiciones} posiciones · setpoint ${item.setpoint_habitual ?? '—'} °C</p></div>
            <div class="tunnel-card__meta"><span>${escapeHtml(statusText(item.estado_administrativo))}</span><span>versión ${item.version_configuracion}</span></div>
            <div class="tunnel-card__footer"><span>${active ? `${escapeHtml(active.codigo)} · ${active.folios_cargados} folios` : 'Sin proceso activo'}</span>${state.identity?.puede_administrar_tuneles_prefrio === true ? `<button data-edit-tunnel="${item.id}" type="button">Configurar</button>` : ''}</div>
        </article>`;
    }).join('') || '<p class="empty-prefrio">No existen túneles configurados.</p>';
}
function renderProcessTunnelOptions() {
    const select = elements.filters.elements.tunel_prefrio_id; const current = select.value;
    select.innerHTML = `<option value="">Todos los túneles</option>${state.tunnels.map((item) => `<option value="${item.id}">${escapeHtml(item.codigo)} · ${escapeHtml(item.nombre)}</option>`).join('')}`;
    if ([...select.options].some((option) => option.value === current)) select.value = current;
}
function renderProcessFormOptions() {
    const select = elements.processForm.elements.tunel_prefrio_id; const current = select.value;
    const available = state.tunnels.filter((item) => item.estado_administrativo === 'activo' && item.estado_tecnico === 'operativo' && !item.proceso_activo);
    select.innerHTML = `<option value="">Selecciona un túnel disponible</option>${available.map((item) => `<option value="${item.id}">${escapeHtml(item.codigo)} · ${escapeHtml(item.nombre)} · ${item.capacidad_posiciones} posiciones</option>`).join('')}`;
    if ([...select.options].some((option) => option.value === current)) select.value = current;
}
function renderProcesses() {
    elements.processBody.innerHTML = state.processes.map((item) => {
        const activeFolios = (item.folios || []).filter((folio) => !['retirado', 'cancelado'].includes(folio.estado));
        const occupiedPositions = new Set(activeFolios.map((folio) => folio.posicion?.id).filter(Boolean)).size;
        const finalText = item.estado === 'aprobado' ? 'Habilitado' : item.estado === 'requiere_reproceso' ? 'Retenido' : item.estado === 'cancelado' ? 'Cancelado' : 'Pendiente';
        const selected = state.selectedProcess?.id === item.id;
        return `<tr class="${selected ? 'is-selected' : ''}" data-process-id="${item.id}" tabindex="0" role="button" aria-label="Abrir ${escapeHtml(item.codigo)}" aria-current="${selected ? 'true' : 'false'}">
            <td><strong>${escapeHtml(item.codigo)}</strong><small>v${item.version} · ${escapeHtml(item.formato_referencia || 'Sin formato')}</small></td>
            <td><strong>${escapeHtml(item.tunel?.codigo || '—')}</strong><small>${escapeHtml(item.tunel?.nombre || '')}</small></td>
            <td><span class="status-pill status-pill--${escapeHtml(item.estado)}">${escapeHtml(statusText(item.estado))}</span></td>
            <td><strong>${occupiedPositions}/${item.tunel?.capacidad_posiciones || '—'} posiciones</strong><small>${activeFolios.length} folios</small></td>
            <td>${escapeHtml(formatDate(item.iniciado_at, 'No iniciado'))}</td>
            <td><strong>${escapeHtml(finalText)}</strong><small>${escapeHtml(formatDate(item.finalizado_at, '—'))}</small></td>
        </tr>`;
    }).join('') || '<tr><td class="empty-prefrio" colspan="6">No existen procesos coincidentes.</td></tr>';
}

async function selectProcess(id, scroll = true, manageBusy = true) {
    if (manageBusy) setBusy(true, 'Cargando proceso…');
    try {
        const response = await api(`/api/prefrio/procesos/${id}`); state.selectedProcess = response.data; renderProcesses(); renderProcessDetail();
        if (scroll) elements.detail.scrollIntoView({ behavior: 'smooth', block: 'start' });
    } catch (error) {
        toast(error.message, true);
        if (!manageBusy) throw error;
    } finally {
        if (manageBusy) setBusy(false);
    }
}
function renderProcessDetail() {
    const process = state.selectedProcess; if (!process) { elements.detail.classList.add('is-hidden'); return; }
    elements.detail.classList.remove('is-hidden'); elements.detailTitle.textContent = `${process.codigo} · ${process.tunel?.codigo || 'Sin túnel'}`;
    elements.detailSubtitle.textContent = `${process.tunel?.nombre || ''} · ${statusText(process.estado)} · setpoint ${process.setpoint} °C · versión ${process.version}`;
    const occupied = (process.folios || []).filter((item) => !['retirado', 'cancelado'].includes(item.estado));
    const occupiedPositions = new Set(occupied.map((item) => item.posicion?.id).filter(Boolean)).size;
    elements.detailMetrics.innerHTML = [
        ['ESTADO', statusText(process.estado)], ['POSICIONES', `${occupiedPositions}/${process.tunel?.capacidad_posiciones || '—'}`], ['FOLIOS', occupied.length], ['INICIO', formatDate(process.iniciado_at, 'No iniciado')], ['FINAL', formatDate(process.finalizado_at, 'Pendiente')],
    ].map(([label, value]) => `<article><span>${escapeHtml(label)}</span><strong>${escapeHtml(value)}</strong></article>`).join('');
    elements.correctProcess.classList.toggle('is-hidden', state.identity?.rol !== 'administrador');
    renderTunnelMap(process, occupied); renderTimeline(process); renderR41(process); renderOperationalPanel(process); renderDecisionPanel(process, occupied);
}
/** Lecturas del R-41 en orden, con la lectura final que acompañó el término del enfriado. */
function r41Readings(process) {
    return (process.eventos || [])
        .filter((event) => event.tipo === 'lectura' || (event.tipo === 'verificacion_final' && event.datos?.datos?.lectura_final))
        .map((event) => ({ at: event.ocurrido_at, final: event.tipo === 'verificacion_final', sectors: event.tipo === 'lectura' ? event.datos?.sectores || null : event.datos.datos.lectura_final, temperature: event.datos?.temperatura ?? null }))
        .sort((a, b) => new Date(a.at) - new Date(b.at));
}
function r41Value(value) { return value === null || value === undefined || value === '' ? '—' : Number(value).toLocaleString('es-CL', { minimumFractionDigits: 1, maximumFractionDigits: 1 }); }
function renderR41(process) {
    const registro = process.registro_r41 || {};
    const readings = r41Readings(process);
    const overdue = registro.lectura_vencida === true;
    elements.r41Status.dataset.tone = overdue || (process.estado === 'en_proceso' && registro.exige_lectura_final && !registro.ultima_lectura_completa) ? 'warning' : '';
    elements.r41Status.textContent = process.estado === 'en_proceso'
        ? [overdue ? `Lectura vencida: correspondía a las ${formatDate(registro.proxima_lectura_at)}.` : registro.proxima_lectura_at ? `Próxima lectura: ${formatDate(registro.proxima_lectura_at)} (cada ${registro.intervalo_lectura_minutos} min).` : '',
            registro.ultima_lectura_completa ? 'La última lectura está completa y sirve de lectura final.' : 'Al terminar el enfriado se exige una lectura completa: 3 sectores, interior y exterior de ambos lados.'].filter(Boolean).join(' ')
        : `${readings.length} ${readings.length === 1 ? 'lectura registrada' : 'lecturas registradas'}.`;
    elements.r41Readings.innerHTML = readings.map((reading, index) => r41Sectors.map(([sector, name], row) => {
        const label = row === 0 ? `<th scope="row" rowspan="3">${reading.final ? 'Final' : index + 1} · ${escapeHtml(formatDate(reading.at))}${reading.sectors ? '' : `<br><small>${escapeHtml(r41Value(reading.temperature))} °C sin sector</small>`}</th>` : '';
        return `<tr class="${row === 0 ? 'r41-reading-start' : ''}${reading.final ? ' is-final' : ''}">${label}<th scope="row">${name}</th>${r41Points.map((point) => { const value = reading.sectors?.[sector]?.[point]; return `<td class="${value === null || value === undefined ? 'is-empty' : ''}">${escapeHtml(r41Value(value))}</td>`; }).join('')}</tr>`;
    }).join('')).join('') || '<tr><td colspan="6">Sin lecturas registradas.</td></tr>';
    const data = registro.datos || {};
    for (const field of ['temperatura_objetivo', 'servicio', 'supervisor', 'jefe_frio']) elements.r41DataForm.elements[field].value = data[field] ?? '';
    const canEdit = state.identity?.puede_operar_prefrio === true;
    [...elements.r41DataForm.elements].forEach((field) => { field.disabled = !canEdit; });
    elements.r41DataError.textContent = '';
}
function r41GridValues() {
    const sectors = {}; let filled = 0;
    for (const [sector] of r41Sectors) {
        sectors[sector] = {};
        for (const point of r41Points) {
            const raw = elements.operationalForm.elements[`r41_${sector}_${point}`].value.trim();
            sectors[sector][point] = raw === '' ? null : Number(raw.replace(',', '.'));
            if (raw !== '') filled += 1;
        }
    }
    return { sectors, filled, total: r41Sectors.length * r41Points.length };
}
function renderTunnelMap(process, occupied) {
    const byPosition = new Map();
    occupied.forEach((item) => {
        const number = item.posicion?.numero;
        if (!number) return;
        byPosition.set(number, [...(byPosition.get(number) || []), item]);
    });
    const capacity = Number(process.tunel?.capacidad_posiciones || 0);
    elements.tunnelMap.innerHTML = Array.from({ length: capacity }, (_, index) => {
        const number = index + 1; const items = byPosition.get(number) || [];
        const reprocess = items.some((item) => item.estado === 'requiere_reproceso');
        const boxes = items.reduce((total, item) => total + Number(item.folio?.cantidad_cajas || 0), 0);
        const summary = items.length === 0
            ? 'Libre'
            : items.length === 1
                ? items[0].folio?.numero_folio || 'Ocupado'
                : `${items.length} saldos${boxes > 0 ? ` · ${boxes} cajas` : ''}`;
        const folios = items.length > 1
            ? items.map((item) => item.folio?.numero_folio || 'Folio').join(' · ')
            : '';
        const meta = positionMeta(number);
        return `<article class="tunnel-slot${items.length ? ' is-occupied' : ''}${reprocess ? ' is-reprocess' : ''}"><strong>P${String(number).padStart(2, '0')}</strong><small>Lado ${meta.side} · Prof. ${meta.depth}</small><span>${escapeHtml(summary)}</span>${folios ? `<small>${escapeHtml(folios)}</small>` : ''}</article>`;
    }).join('') || '<p class="empty-prefrio">El túnel no posee posiciones configuradas.</p>';
}
function renderTimeline(process) {
    elements.timeline.innerHTML = (process.eventos || []).map((event) => `<article class="event-item"><strong>${escapeHtml(statusText(event.tipo))}</strong><span>${escapeHtml(event.usuario?.nombre || 'Usuario')} · ${escapeHtml(event.dispositivo?.codigo || 'Oficina')}</span><small>${escapeHtml(formatDate(event.ocurrido_at))}${event.observacion ? ` · ${escapeHtml(event.observacion)}` : ''}</small></article>`).join('') || '<p class="empty-prefrio">Sin eventos registrados.</p>';
}
function renderDecisionPanel(process, occupied) {
    const supervisor = state.identity?.puede_supervisar_prefrio === true; const terminal = !activeStates.has(process.estado);
    elements.decisionPanel.classList.toggle('is-hidden', !supervisor || terminal);
    if (!supervisor || terminal) return;
    const pending = process.estado === 'pendiente_verificacion';
    elements.approve.classList.toggle('is-hidden', !pending); elements.reprocessButton.classList.toggle('is-hidden', !pending); elements.cancelProcess.classList.toggle('is-hidden', terminal);
    elements.decisionFolios.classList.toggle('is-hidden', !pending);
    elements.decisionFolios.innerHTML = occupied.map((item) => `<article class="decision-folio" data-decision-folio="${item.folio.id}"><div class="decision-folio__title"><strong>${escapeHtml(item.folio.numero_folio)}</strong><small>${escapeHtml(item.posicion?.etiqueta || '')}</small></div><div class="decision-folio__fields"><input name="temperatura_final" type="number" min="-20" max="50" step="0.1" value="${item.temperatura_final ?? ''}" placeholder="Temp. final"><input name="observacion" maxlength="1000" value="${escapeHtml(item.observacion || '')}" placeholder="Observación"></div></article>`).join('') || '<p class="empty-prefrio">El proceso no posee folios activos.</p>';
    if (!elements.decisionOccurredAt.value) elements.decisionOccurredAt.value = localDateTimeValue();
    elements.decisionError.textContent = '';
}
function renderOperationalPanel(process) {
    const canOperate = state.identity?.puede_operar_prefrio === true;
    const actions = process.estado === 'borrador' || process.estado === 'cargando'
        ? [{ value: 'confirmar_armado', label: 'Confirmar armado' }]
        : process.estado === 'listo_para_iniciar'
            ? [{ value: 'iniciar', label: 'Iniciar proceso' }]
            : process.estado === 'en_proceso'
                ? [
                    { value: 'inversion_registrada', label: 'Registrar inversión' }, { value: 'pausa', label: 'Registrar pausa' },
                    { value: 'reanudacion', label: 'Registrar reanudación' }, { value: 'deshielo', label: 'Registrar deshielo' },
                    { value: 'lectura', label: 'Registrar lectura de temperatura' }, { value: 'verificar', label: 'Finalizar y enviar a verificación' },
                ]
                : [];
    elements.operationalPanel.classList.toggle('is-hidden', !canOperate || !actions.length);
    if (!canOperate || !actions.length) return;
    elements.operationalAction.innerHTML = actions.map((action) => `<option value="${action.value}">${action.label}</option>`).join('');
    elements.operationalForm.elements.ocurrido_at.value = localDateTimeValue();
    elements.operationalForm.elements.observacion.value = '';
    elements.operationalError.textContent = '';
    syncOperationalTemperature();
}
function syncOperationalTemperature() {
    const action = elements.operationalAction.value;
    const registro = state.selectedProcess?.registro_r41 || {};
    const finalRequired = action === 'verificar' && registro.exige_lectura_final !== false && !registro.ultima_lectura_completa;
    const visible = action === 'lectura' || action === 'verificar';
    elements.operationalReadingField.classList.toggle('is-hidden', !visible);
    elements.operationalReadingTitle.textContent = action === 'verificar' ? 'Lectura final (°C)' : 'Lectura de temperatura (°C)';
    elements.operationalReadingNote.dataset.tone = finalRequired ? 'warning' : '';
    elements.operationalReadingNote.textContent = action === 'lectura'
        ? 'Lectura horaria: registra los sensores que midas; puede quedar parcial.'
        : finalRequired
            ? 'Obligatoria para terminar: completa los 3 sectores, interior y exterior de ambos lados.'
            : 'La última lectura horaria está completa y servirá de lectura final. Si mides de nuevo, completa los 12 sensores.';
    if (!visible) r41Points.forEach((point) => r41Sectors.forEach(([sector]) => { elements.operationalForm.elements[`r41_${sector}_${point}`].value = ''; }));
}
function decisionResults() {
    return [...elements.decisionFolios.querySelectorAll('[data-decision-folio]')].map((node) => {
        const temperature = node.querySelector('[name="temperatura_final"]').value;
        const observation = node.querySelector('[name="observacion"]').value.trim();
        return { folio_id: node.dataset.decisionFolio, temperatura_final: temperature === '' ? null : Number(temperature), observation: observation || null };
    }).map(({ observation, ...item }) => ({ ...item, observacion: observation }));
}


function correctionPositionOptions(process, selected = '') {
    const tunnel = state.tunnels.find((item) => item.id === process.tunel?.id);
    const positions = (tunnel?.posiciones || []).filter((item) => item.activa || item.id === selected);
    return `<option value="">Seleccionar posición</option>${positions.map((item) => `<option value="${item.id}"${item.id === selected ? ' selected' : ''}>${escapeHtml(item.etiqueta || `P${String(item.numero).padStart(2, '0')}`)}</option>`).join('')}`;
}
function correctionNullableNumber(value) {
    return value === '' || value === null || value === undefined ? null : Number(value);
}
function openCorrectionDialog() {
    const process = state.selectedProcess;
    if (!process || state.identity?.rol !== 'administrador') return;
    elements.correctionForm.reset();
    elements.correctionError.textContent = '';
    elements.correctionTitle.textContent = `${process.codigo} · Corregir historial`;
    elements.correctionForm.elements.setpoint.value = process.setpoint ?? '';
    elements.correctionForm.elements.duracion_objetivo_minutos.value = process.duracion_objetivo_minutos ?? '';
    elements.correctionForm.elements.formato_referencia.value = process.formato_referencia ?? '';
    elements.correctionForm.elements.observacion.value = process.observacion ?? '';
    const editableEvents = (process.eventos || []).filter((event) => event.tipo !== 'correccion_administrativa');
    elements.correctionEvents.innerHTML = editableEvents.map((event) => `
        <article class="history-editor-row" data-correction-event="${event.id}">
            <div class="history-editor-row__identity"><strong>${escapeHtml(statusText(event.tipo))}</strong><small>${escapeHtml(event.usuario?.nombre || 'Usuario')}</small></div>
            <label><span>Fecha y hora *</span><input name="ocurrido_at" type="datetime-local" value="${event.ocurrido_at ? localDateTimeValue(event.ocurrido_at) : ''}" required></label>
            <label><span>Observación</span><input name="observacion" maxlength="2000" value="${escapeHtml(event.observacion || '')}"></label>
        </article>`).join('') || '<p class="empty-prefrio">No existen eventos editables.</p>';
    elements.correctionFolios.innerHTML = (process.folios || []).map((item) => {
        const included = !['retirado', 'cancelado'].includes(item.estado);
        return `
        <article class="history-editor-row history-editor-row--folio" data-correction-folio="${item.id}">
            <div class="history-editor-row__identity"><strong>${escapeHtml(item.folio?.numero_folio || 'Folio')}</strong><small>${escapeHtml(statusText(item.estado))}</small><label class="history-editor-row__toggle"><input data-included type="checkbox"${included ? ' checked' : ''}><span>Incluir</span></label></div>
            <label><span>Posición</span><select name="posicion_tunel_prefrio_id">${correctionPositionOptions(process, item.posicion?.id || '')}</select></label>
            <label><span>Fecha de carga</span><input name="cargado_at" type="datetime-local" value="${item.cargado_at ? localDateTimeValue(item.cargado_at) : ''}"></label>
            <label><span>Temp. inicial</span><input name="temperatura_inicial" type="number" min="-20" max="50" step="0.1" value="${item.temperatura_inicial ?? ''}"></label>
            <label><span>Temp. final</span><input name="temperatura_final" type="number" min="-20" max="50" step="0.1" value="${item.temperatura_final ?? ''}"></label>
            <label><span>Observación</span><input name="observacion" maxlength="2000" value="${escapeHtml(item.observacion || '')}"></label>
        </article>`;
    }).join('') || '<p class="empty-prefrio">El proceso no posee folios.</p>';
    elements.correctionNewPosition.innerHTML = correctionPositionOptions(process);
    elements.correctionForm.elements.nuevo_cargado_at.value = process.iniciado_at
        ? localDateTimeValue(process.iniciado_at)
        : '';
    elements.correctionDialog.showModal();
}
function correctionPayload() {
    const process = state.selectedProcess;
    const form = elements.correctionForm;
    const newNumber = form.elements.nuevo_numero_folio.value.trim();
    const events = [...elements.correctionEvents.querySelectorAll('[data-correction-event]')].map((node) => ({
        id: node.dataset.correctionEvent,
        ocurrido_at: occurredAt(node.querySelector('[name="ocurrido_at"]').value),
        observacion: node.querySelector('[name="observacion"]').value.trim() || null,
    }));
    const folios = [...elements.correctionFolios.querySelectorAll('[data-correction-folio]')].map((node) => {
        const included = node.querySelector('[data-included]').checked;
        const loadedAt = node.querySelector('[name="cargado_at"]').value;
        return {
            id: node.dataset.correctionFolio,
            incluido: included,
            posicion_tunel_prefrio_id: node.querySelector('[name="posicion_tunel_prefrio_id"]').value || null,
            cargado_at: loadedAt ? occurredAt(loadedAt) : null,
            temperatura_inicial: correctionNullableNumber(node.querySelector('[name="temperatura_inicial"]').value),
            temperatura_final: correctionNullableNumber(node.querySelector('[name="temperatura_final"]').value),
            observacion: node.querySelector('[name="observacion"]').value.trim() || null,
        };
    });
    let newFolio = null;
    if (newNumber) {
        if (!form.elements.nuevo_posicion_tunel_prefrio_id.value || !form.elements.nuevo_cargado_at.value) {
            throw new ApiError('Para agregar un folio indica su posición y fecha de carga.', 422);
        }
        newFolio = {
            numero_folio: newNumber,
            posicion_tunel_prefrio_id: form.elements.nuevo_posicion_tunel_prefrio_id.value,
            cargado_at: occurredAt(form.elements.nuevo_cargado_at.value),
            temperatura_inicial: correctionNullableNumber(form.elements.nuevo_temperatura_inicial.value),
            temperatura_final: correctionNullableNumber(form.elements.nuevo_temperatura_final.value),
            observacion: form.elements.nuevo_observacion.value.trim() || null,
        };
    }
    return {
        operacion_id: operationUuid(),
        version_conocida: process.version,
        motivo: form.elements.motivo.value.trim(),
        proceso: {
            setpoint: Number(form.elements.setpoint.value),
            duracion_objetivo_minutos: correctionNullableNumber(form.elements.duracion_objetivo_minutos.value),
            formato_referencia: form.elements.formato_referencia.value.trim() || null,
            observacion: form.elements.observacion.value.trim() || null,
        },
        eventos: events,
        folios,
        nuevo_folio: newFolio,
    };
}
async function saveCorrection() {
    const process = state.selectedProcess;
    elements.correctionError.textContent = '';
    setBusy(true, 'Guardando corrección auditada…');
    try {
        const payload = correctionPayload();
        const response = await api(`/api/administracion/prefrio/procesos/${process.id}/corregir`, {
            method: 'PUT',
            body: JSON.stringify(payload),
        });
        state.selectedProcess = response.data;
        elements.correctionDialog.close();
        renderProcessDetail();
        toast('Historial corregido. Se conservó la auditoría completa.');
        await Promise.all([loadProcesses(), loadTunnels(), loadSummary()]);
        markViewUpdated();
    } catch (error) {
        elements.correctionError.textContent = error.message;
    } finally {
        setBusy(false);
    }
}

function openTunnelDialog(tunnel = null) {
    elements.tunnelForm.reset(); elements.tunnelError.textContent = ''; elements.tunnelForm.elements.id.value = tunnel?.id || '';
    elements.tunnelDialogTitle.textContent = tunnel ? `${tunnel.codigo} · Editar túnel` : 'Nuevo túnel';
    if (tunnel) for (const field of ['nombre', 'capacidad_posiciones', 'setpoint_habitual', 'estado_administrativo', 'estado_tecnico', 'codigo_externo', 'observacion']) if (elements.tunnelForm.elements[field]) elements.tunnelForm.elements[field].value = tunnel[field] ?? '';
    else { elements.tunnelForm.elements.capacidad_posiciones.value = 22; elements.tunnelForm.elements.setpoint_habitual.value = -1.5; elements.tunnelForm.elements.estado_administrativo.value = 'activo'; elements.tunnelForm.elements.estado_tecnico.value = 'operativo'; }
    renderTunnelPreview(); elements.tunnelDialog.showModal();
}
function renderTunnelPreview() {
    const capacity = Math.max(2, Math.min(100, Number(elements.tunnelForm.elements.capacidad_posiciones.value || 2)));
    elements.tunnelPreviewSummary.textContent = `${capacity} posiciones`;
    elements.tunnelPreview.innerHTML = Array.from({ length: capacity }, (_, index) => {
        const number = index + 1; const meta = positionMeta(number);
        return `<article class="tunnel-slot"><strong>P${String(number).padStart(2, '0')}</strong><small>Lado ${meta.side} · Prof. ${meta.depth}</small><span>Activa</span></article>`;
    }).join('');
}
function openProcessDialog() {
    elements.processForm.reset(); elements.processError.textContent = ''; renderProcessFormOptions();
    const first = elements.processForm.elements.tunel_prefrio_id.options[1]; if (first) { elements.processForm.elements.tunel_prefrio_id.value = first.value; syncProcessSetpoint(); }
    elements.processForm.elements.ocurrido_at.value = localDateTimeValue();
    elements.processDialog.showModal();
}
function syncProcessSetpoint() {
    const tunnel = state.tunnels.find((item) => item.id === elements.processForm.elements.tunel_prefrio_id.value);
    if (tunnel?.setpoint_habitual !== null && tunnel?.setpoint_habitual !== undefined) elements.processForm.elements.setpoint.value = tunnel.setpoint_habitual;
}
function openReasonDialog(mode) {
    state.reasonMode = mode; elements.reasonForm.reset(); elements.reasonError.textContent = '';
    const reprocess = mode === 'reprocess'; elements.reasonEyebrow.textContent = reprocess ? 'REPROCESO' : 'CANCELACIÓN'; elements.reasonTitle.textContent = reprocess ? 'Enviar proceso a reproceso' : 'Cancelar proceso';
    elements.reasonForm.elements.ocurrido_at.value = localDateTimeValue();
    elements.reasonDescription.textContent = reprocess ? 'Los folios quedarán retenidos y podrán incorporarse a un nuevo ciclo.' : 'La cancelación conserva el historial y retiene los folios cuando el ciclo ya había iniciado.';
    elements.confirmReason.textContent = reprocess ? 'Confirmar reproceso' : 'Confirmar cancelación'; elements.reasonDialog.showModal();
}

async function saveTunnel() {
    const values = Object.fromEntries(new FormData(elements.tunnelForm)); const id = values.id; delete values.id;
    values.capacidad_posiciones = Number(values.capacidad_posiciones); values.setpoint_habitual = values.setpoint_habitual === '' ? null : Number(values.setpoint_habitual);
    const path = id ? `/api/administracion/prefrio/tuneles/${id}` : '/api/administracion/prefrio/tuneles';
    setBusy(true, id ? 'Actualizando túnel…' : 'Creando túnel…');
    try { await api(path, { method: id ? 'PUT' : 'POST', body: JSON.stringify(values) }); elements.tunnelDialog.close(); toast(id ? 'Túnel actualizado.' : 'Túnel creado.'); await loadAll(); }
    catch (error) { elements.tunnelError.textContent = error.message; } finally { setBusy(false); }
}
async function saveProcess() {
    const values = Object.fromEntries(new FormData(elements.processForm));
    const payload = { operacion_id: operationUuid(), tunel_prefrio_id: values.tunel_prefrio_id, setpoint: Number(values.setpoint), duracion_objetivo_minutos: values.duracion_objetivo_minutos ? Number(values.duracion_objetivo_minutos) : null, formato_referencia: values.formato_referencia.trim() || null, observacion: values.observacion.trim() || null, ocurrido_at: occurredAt(values.ocurrido_at) };
    setBusy(true, 'Creando proceso…');
    try { const response = await api('/api/prefrio/procesos', { method: 'POST', body: JSON.stringify(payload) }); elements.processDialog.close(); toast(`Proceso ${response.data.codigo} creado.`); await loadAll(); await selectProcess(response.data.id); }
    catch (error) { elements.processError.textContent = error.message; } finally { setBusy(false); }
}
async function approveProcess() {
    const process = state.selectedProcess; if (!process || !window.confirm(`¿Aprobar ${process.codigo} y habilitar sus folios para almacenamiento?`)) return;
    setBusy(true, 'Aprobando proceso…');
    try { const response = await api(`/api/prefrio/procesos/${process.id}/aprobar`, { method: 'POST', body: JSON.stringify({ operacion_id: operationUuid(), version_conocida: process.version, resultados: decisionResults(), ocurrido_at: occurredAt(elements.decisionOccurredAt.value) }) }); state.selectedProcess = response.data; renderProcessDetail(); toast('Proceso aprobado y folios habilitados.'); await Promise.all([loadTunnels(), loadProcesses(), loadSummary()]); markViewUpdated(); }
    catch (error) { elements.decisionError.textContent = error.message; } finally { setBusy(false); }
}
async function submitReason() {
    const process = state.selectedProcess; const values = Object.fromEntries(new FormData(elements.reasonForm)); const mode = state.reasonMode;
    const payload = { operacion_id: operationUuid(), version_conocida: process.version, motivo: values.motivo.trim(), observacion: values.observacion.trim() || null, ocurrido_at: occurredAt(values.ocurrido_at) };
    if (mode === 'reprocess') payload.resultados = decisionResults();
    const path = mode === 'reprocess' ? `/api/prefrio/procesos/${process.id}/reprocesar` : `/api/prefrio/procesos/${process.id}/cancelar`;
    setBusy(true, mode === 'reprocess' ? 'Registrando reproceso…' : 'Cancelando proceso…');
    try { const response = await api(path, { method: 'POST', body: JSON.stringify(payload) }); elements.reasonDialog.close(); state.selectedProcess = response.data; renderProcessDetail(); toast(mode === 'reprocess' ? 'Proceso enviado a reproceso.' : 'Proceso cancelado.'); await Promise.all([loadTunnels(), loadProcesses(), loadSummary()]); markViewUpdated(); }
    catch (error) { elements.reasonError.textContent = error.message; } finally { setBusy(false); }
}

async function submitOperationalAction() {
    const process = state.selectedProcess; if (!process) return;
    const values = Object.fromEntries(new FormData(elements.operationalForm));
    const action = values.accion;
    const eventTypes = new Set(['inversion_registrada', 'pausa', 'reanudacion', 'deshielo', 'lectura']);
    const routes = {
        confirmar_armado: `/api/prefrio/procesos/${process.id}/confirmar-armado`,
        iniciar: `/api/prefrio/procesos/${process.id}/iniciar`,
        verificar: `/api/prefrio/procesos/${process.id}/verificar`,
    };
    const path = eventTypes.has(action) ? `/api/prefrio/procesos/${process.id}/eventos/${action}` : routes[action];
    if (!path) { elements.operationalError.textContent = 'La acción seleccionada no es válida.'; return; }
    const payload = { operacion_id: operationUuid(), version_conocida: process.version, ocurrido_at: occurredAt(values.ocurrido_at), observacion: String(values.observacion || '').trim() || null };
    if (action === 'lectura' || action === 'verificar') {
        const { sectors, filled, total } = r41GridValues();
        if (action === 'lectura' && filled === 0) { elements.operationalError.textContent = 'Ingresa al menos una temperatura de la lectura.'; return; }
        if (action === 'verificar' && filled > 0 && filled < total) { elements.operationalError.textContent = `La lectura final lleva los ${total} sensores: faltan ${total - filled}.`; return; }
        if (action === 'lectura') payload.datos = { sectores: sectors };
        else if (filled === total) payload.datos = { lectura_final: sectors };
    }
    setBusy(true, 'Registrando acción de prefrío…');
    try {
        const response = await api(path, { method: 'POST', body: JSON.stringify(payload) });
        state.selectedProcess = response.data; renderProcessDetail(); toast('Acción registrada con su fecha y hora operacional.');
        await Promise.all([loadTunnels(), loadProcesses(), loadSummary()]);
        markViewUpdated();
    } catch (error) { elements.operationalError.textContent = error.message; }
    finally { setBusy(false); }
}

async function saveR41Data() {
    const process = state.selectedProcess; if (!process) return;
    const values = Object.fromEntries(new FormData(elements.r41DataForm));
    const payload = { temperatura_objetivo: values.temperatura_objetivo === '' ? null : Number(values.temperatura_objetivo), servicio: values.servicio.trim() || null, supervisor: values.supervisor.trim() || null, jefe_frio: values.jefe_frio.trim() || null };
    setBusy(true, 'Guardando datos de la planilla…');
    try { const response = await api(`/api/prefrio/procesos/${process.id}/registro-r41`, { method: 'PUT', body: JSON.stringify(payload) }); state.selectedProcess = response.data; renderProcessDetail(); toast('Datos de la planilla R-41 guardados.'); }
    catch (error) { elements.r41DataError.textContent = error.message; } finally { setBusy(false); }
}
async function downloadR41() {
    const process = state.selectedProcess; if (!process) return;
    setBusy(true, 'Generando planilla R-41…');
    try {
        const response = await fetch(`/api/prefrio/procesos/${process.id}/registro-r41.xlsx`, { headers: { Accept: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', Authorization: `Bearer ${state.token}` } });
        if (!response.ok) { const data = await response.json().catch(() => ({})); throw new ApiError(errorMessage(data, 'No fue posible generar la planilla R-41.'), response.status, data); }
        const url = URL.createObjectURL(await response.blob());
        const link = document.createElement('a'); link.href = url; link.download = `R-41_${process.codigo}.xlsx`;
        document.body.append(link); link.click(); link.remove(); window.setTimeout(() => URL.revokeObjectURL(url), 1000);
    } catch (error) { toast(error.message, true); } finally { setBusy(false); }
}

async function bootstrap() {
    if (!state.token || !state.identity) return;
    if (!showApp()) return;
    setBusy(true, 'Cargando Prefrío…');
    try { await loadAll(); } catch (error) { elements.viewStatus.textContent = 'No fue posible actualizar'; toast(error.message, true); if (error.status === 403) clearSession(); } finally { setBusy(false); }
}

elements.login.addEventListener('submit', async (event) => {
    event.preventDefault(); elements.loginError.textContent = ''; setBusy(true, 'Validando acceso…');
    const values = Object.fromEntries(new FormData(elements.login));
    try { const payload = await api('/api/acceso-oficina', { method: 'POST', body: JSON.stringify(values) }); persist(payload); if (showApp()) await loadAll(); }
    catch (error) { elements.loginError.textContent = error.message; } finally { setBusy(false); }
});
elements.logout.addEventListener('click', async () => { try { await api('/api/acceso-oficina', { method: 'DELETE' }); } catch {} clearSession(); });
elements.reload.addEventListener('click', async () => { setBusy(true, 'Actualizando tablero…'); try { await loadAll(); toast('Prefrío actualizado.'); } catch (error) { elements.viewStatus.textContent = 'No fue posible actualizar'; toast(error.message, true); } finally { setBusy(false); } });
elements.filters.addEventListener('submit', async (event) => { event.preventDefault(); setBusy(true, 'Filtrando procesos…'); try { await loadProcesses(); } catch (error) { toast(error.message, true); } finally { setBusy(false); } });
elements.tunnelList.addEventListener('click', (event) => { const edit = event.target.closest('[data-edit-tunnel]'); if (edit) { event.stopPropagation(); openTunnelDialog(state.tunnels.find((item) => item.id === edit.dataset.editTunnel)); return; } const card = event.target.closest('[data-tunnel-id]'); if (!card) return; state.selectedTunnelId = card.dataset.tunnelId; renderTunnels(); elements.filters.elements.tunel_prefrio_id.value = state.selectedTunnelId; elements.filters.requestSubmit(); });
elements.processBody.addEventListener('click', (event) => { const row = event.target.closest('[data-process-id]'); if (row) selectProcess(row.dataset.processId); });
elements.processBody.addEventListener('keydown', (event) => {
    if (!['Enter', ' '].includes(event.key)) return;
    const row = event.target.closest('[data-process-id]');
    if (!row) return;
    event.preventDefault();
    selectProcess(row.dataset.processId);
});
elements.newTunnel.addEventListener('click', () => openTunnelDialog()); elements.newProcess.addEventListener('click', openProcessDialog);
elements.tunnelForm.elements.capacidad_posiciones.addEventListener('input', renderTunnelPreview);
elements.tunnelForm.addEventListener('submit', (event) => { event.preventDefault(); if (event.submitter?.value === 'cancel') { elements.tunnelDialog.close(); return; } if (!elements.tunnelForm.reportValidity()) return; saveTunnel(); });
elements.processForm.elements.tunel_prefrio_id.addEventListener('change', syncProcessSetpoint);
elements.processForm.addEventListener('submit', (event) => { event.preventDefault(); if (event.submitter?.value === 'cancel') { elements.processDialog.close(); return; } if (!elements.processForm.reportValidity()) return; saveProcess(); });
elements.refreshProcess.addEventListener('click', () => state.selectedProcess && selectProcess(state.selectedProcess.id, false));
elements.correctProcess.addEventListener('click', openCorrectionDialog);
elements.correctionForm.addEventListener('submit', (event) => { event.preventDefault(); if (event.submitter?.value === 'cancel') { elements.correctionDialog.close(); return; } if (!elements.correctionForm.reportValidity()) return; void saveCorrection(); });
elements.closeDetail.addEventListener('click', () => { state.selectedProcess = null; elements.detail.classList.add('is-hidden'); renderProcesses(); });
elements.approve.addEventListener('click', approveProcess); elements.reprocessButton.addEventListener('click', () => openReasonDialog('reprocess')); elements.cancelProcess.addEventListener('click', () => openReasonDialog('cancel'));
elements.reasonForm.addEventListener('submit', (event) => { event.preventDefault(); if (event.submitter?.value === 'cancel') { elements.reasonDialog.close(); return; } if (!elements.reasonForm.reportValidity()) return; submitReason(); });
elements.operationalAction.addEventListener('change', syncOperationalTemperature);
elements.r41DataForm.addEventListener('submit', (event) => { event.preventDefault(); if (!elements.r41DataForm.reportValidity()) return; void saveR41Data(); });
elements.r41Download.addEventListener('click', () => void downloadR41());
elements.operationalForm.addEventListener('submit', (event) => { event.preventDefault(); if (!elements.operationalForm.reportValidity()) return; void submitOperationalAction(); });

bootstrap();
