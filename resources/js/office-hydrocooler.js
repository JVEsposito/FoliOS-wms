import { createOperationalPoller } from './shared/operational-poller';

const byId = (id) => document.getElementById(id);
const elements = {
    access: byId('officeAccess'), app: byId('officeApp'), login: byId('officeLoginForm'),
    loginError: byId('officeLoginError'), logout: byId('officeLogoutButton'),
    userName: byId('officeUserName'), userRole: byId('officeUserRole'), initials: byId('officeInitials'),
    reload: byId('reloadButton'), season: byId('seasonDescription'), pending: byId('pendingCount'),
    active: byId('activeCount'), retained: byId('retainedCount'), activeKilos: byId('activeKilos'), completedToday: byId('completedToday'),
    averageDuration: byId('averageDuration'), tabs: [...document.querySelectorAll('[data-tray]')],
    filters: byId('hydrocoolerFilters'), equipmentFilter: byId('equipmentFilter'), list: byId('hydrocoolerList'),
    equipmentOptions: byId('equipmentOptions'), startDialog: byId('startDialog'), startForm: byId('startForm'),
    startTitle: byId('startTitle'), startDescription: byId('startDescription'), startSummary: byId('startSummary'),
    startError: byId('startError'), finishDialog: byId('finishDialog'), finishForm: byId('finishForm'),
    finishTitle: byId('finishTitle'), finishDescription: byId('finishDescription'), finishSummary: byId('finishSummary'),
    finishError: byId('finishError'), loading: byId('officeLoading'), loadingText: byId('officeLoadingText'),
    releaseDialog: byId('releaseDialog'), releaseForm: byId('releaseForm'), releaseTitle: byId('releaseTitle'),
    releaseDescription: byId('releaseDescription'), releaseError: byId('releaseError'),
    toasts: byId('officeToasts'),
    registerButtons: [...document.querySelectorAll('[data-register]')],
};
const keys = { token: 'estiba_wms_office_token', identity: 'estiba_wms_office_identity' };
const state = {
    token: localStorage.getItem(keys.token), identity: readJson(keys.identity), summary: null,
    lots: [], tray: 'pendientes', selected: null, poller: null,
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
function can(capability) {
    return state.identity?.[capability] === true || state.identity?.capacidades?.[capability] === true;
}
function label(value) {
    const labels = {
        pendiente_hidrocooler: 'Pendiente', hidrocooler_en_curso: 'En curso',
        hidrocooler_retenido: 'Retenido', retenidos: 'retenidos',
        pendiente_asignacion: 'A cámara MP', disponible_proceso: 'Directo a proceso',
        camara: 'Cámara MP', proceso: 'Directo a proceso', bins: 'bins', totes: 'totes', esponjas: 'esponjas',
        conforme: 'Conforme', no_conforme: 'No conforme', sin_novedad: 'Sin novedad',
        filtrado: 'Filtrado', recambio: 'Recambio',
        digitador_materia_prima: 'Digitador de materia prima', supervisor_frio: 'Supervisor de frío',
        operador_romana: 'Operador de Romana', administrador: 'Administrador', consulta: 'Solo consulta',
    };
    return labels[value] || String(value || '').replaceAll('_', ' ').replace(/^./, (letter) => letter.toUpperCase());
}
function formatNumber(value, decimals = 0) {
    return new Intl.NumberFormat('es-CL', { minimumFractionDigits: decimals, maximumFractionDigits: decimals }).format(Number(value || 0));
}
function formatKilos(value) { return `${formatNumber(value, 3)} kg`; }
function formatTemperature(value) { return value === null || value === undefined ? '—' : `${formatNumber(value, 2)} °C`; }
function formatDate(value) {
    if (!value) return '—';
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? '—' : new Intl.DateTimeFormat('es-CL', { dateStyle: 'short', timeStyle: 'short' }).format(date);
}
function formatDuration(value) {
    const minutes = Number(value || 0);
    if (minutes < 60) return `${minutes} min`;
    const hours = Math.floor(minutes / 60); const rest = minutes % 60;
    return rest ? `${hours} h ${rest} min` : `${hours} h`;
}
function localDateTimeValue(date = new Date()) {
    return new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
}
function setBusy(active, text = 'Procesando…') {
    elements.loadingText.textContent = text;
    elements.loading.classList.toggle('is-hidden', !active);
    elements.loading.setAttribute('aria-hidden', String(!active));
}
function toast(message, error = false) {
    const item = document.createElement('div'); item.className = `toast${error ? ' toast--error' : ''}`;
    item.textContent = message; elements.toasts.append(item); window.setTimeout(() => item.remove(), 5000);
}
function persist(payload) {
    state.token = payload.token; state.identity = payload.usuario;
    localStorage.setItem(keys.token, payload.token);
    localStorage.setItem(keys.identity, JSON.stringify(payload.usuario));
}
function clearSession() {
    state.token = null; state.identity = null; state.summary = null; state.lots = [];
    localStorage.removeItem(keys.token); localStorage.removeItem(keys.identity);
    state.poller?.stop(); state.poller = null;
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
async function download(path) {
    const headers = new Headers({ Accept: '*/*' });
    if (state.token) headers.set('Authorization', `Bearer ${state.token}`);
    let response;
    try { response = await fetch(path, { headers }); }
    catch { throw new ApiError('No fue posible descargar la planilla.'); }
    if (!response.ok) {
        const data = await response.json().catch(() => ({}));
        throw new ApiError(errorMessage(data, 'No fue posible descargar la planilla.'), response.status);
    }
    const disposition = response.headers.get('Content-Disposition') || '';
    const filename = disposition.match(/filename="?([^";]+)"?/i)?.[1] || 'registro-hidrocooler';
    const url = URL.createObjectURL(await response.blob());
    const link = document.createElement('a'); link.href = url; link.download = filename;
    document.body.append(link); link.click(); link.remove(); URL.revokeObjectURL(url);
}
function showApp() {
    if (!can('puede_consultar_hidrocooler_materia_prima')) return false;
    elements.access.classList.add('is-hidden'); elements.app.classList.remove('is-hidden');
    const name = state.identity?.nombre || 'Usuario';
    elements.userName.textContent = name; elements.userRole.textContent = label(state.identity?.rol);
    elements.initials.textContent = name.split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0]).join('').toUpperCase();
    return true;
}
function renderSummary() {
    const summary = state.summary || {};
    elements.pending.textContent = formatNumber(summary.pendientes);
    elements.active.textContent = formatNumber(summary.en_curso);
    elements.retained.textContent = formatNumber(summary.retenidos);
    elements.activeKilos.textContent = formatNumber(summary.kilos_en_curso, 3);
    elements.completedToday.textContent = formatNumber(summary.completados_hoy);
    elements.averageDuration.textContent = formatDuration(summary.duracion_promedio_hoy);
    elements.season.textContent = summary.temporada
        ? `${summary.temporada.nombre} · ${summary.temporada.codigo}. Cada ciclo corresponde exclusivamente a un lote.`
        : 'No existe una temporada global activa.';

    const selected = elements.equipmentFilter.value;
    const options = summary.equipos || [];
    elements.equipmentFilter.innerHTML = '<option value="">Todos los equipos</option>'
        + options.map((equipment) => `<option value="${escapeHtml(equipment)}">${escapeHtml(equipment)}</option>`).join('');
    elements.equipmentFilter.value = options.includes(selected) ? selected : '';
    elements.equipmentOptions.innerHTML = options.map((equipment) => `<option value="${escapeHtml(equipment)}"></option>`).join('');
}
function currentDuration(start) {
    const date = new Date(start); if (Number.isNaN(date.getTime())) return 0;
    return Math.max(0, Math.floor((Date.now() - date.getTime()) / 60000));
}
function card(lot) {
    const cycle = lot.hidrocooler;
    const active = lot.estado === 'hidrocooler_en_curso';
    const complete = Boolean(cycle?.termino_at);
    const status = lot.estado === 'hidrocooler_retenido' ? 'Retenido' : complete ? label(cycle.destino_salida) : label(lot.estado);
    const duration = complete ? cycle.duracion_minutos : (active ? currentDuration(cycle?.inicio_at) : null);
    const product = [lot.trazabilidad.especie, lot.trazabilidad.variedad, lot.trazabilidad.cuartel].filter(Boolean).join(' · ');
    const canOperate = can('puede_operar_hidrocooler_materia_prima');
    const action = canOperate && lot.estado === 'pendiente_hidrocooler'
        ? `<button class="primary-button" data-start="${escapeHtml(lot.id)}" type="button">Iniciar ciclo</button>`
        : canOperate && active
            ? `<button class="primary-button" data-finish="${escapeHtml(lot.id)}" type="button">Finalizar ciclo</button>`
            : can('puede_supervisar_lotes_materia_prima') && lot.estado === 'hidrocooler_retenido'
                ? `<button class="primary-button" data-release="${escapeHtml(lot.id)}" type="button">Evaluar y liberar</button>`
            : '';
    const note = [cycle?.observacion_inicio, cycle?.observacion].filter(Boolean).join(' · ');
    return `<article class="cycle-card${active ? ' is-active' : ''}${complete ? ' is-complete' : ''}${lot.estado === 'hidrocooler_retenido' ? ' is-retained' : ''}">
        <div class="cycle-card__heading"><div><h3>${escapeHtml(lot.numero_lote)}</h3><p>${escapeHtml(lot.cliente?.nombre)} · recepción ${escapeHtml(lot.recepcion?.numero_recepcion)}</p></div><span class="hydro-status">${escapeHtml(status)}</span></div>
        <div class="cycle-facts">
            <div><span>CICLO / EQUIPO</span><strong>${escapeHtml(cycle?.codigo || 'Por iniciar')}<br>${escapeHtml(cycle?.equipo || 'Sin equipo')}</strong></div>
            <div><span>PRODUCTO</span><strong>${escapeHtml(product || 'Sin detalle')}</strong></div>
            <div><span>ENVASES / KILOS</span><strong>${escapeHtml(cycle?.cantidad_envases ?? lot.envases.cantidad_primarios)} ${escapeHtml(label(lot.envases.primario))}<br>${escapeHtml(formatKilos(cycle?.kilos_netos ?? lot.pesos.kilos_netos_confirmados))}</strong></div>
            <div><span>OPERADOR / DURACIÓN</span><strong>${escapeHtml(cycle?.operador || 'Pendiente')}<br>${duration === null ? 'Pendiente' : escapeHtml(formatDuration(duration))}</strong></div>
            <div><span>TURNO / BOMBAS</span><strong>${escapeHtml(cycle?.turno ? `Turno ${cycle.turno}` : 'Pendiente')}<br>${cycle?.cantidad_bombas_funcionando === null || cycle?.cantidad_bombas_funcionando === undefined ? '—' : `${cycle.cantidad_bombas_funcionando} bombas`}</strong></div>
        </div>
        <div class="cycle-temperature"><div><span>INICIAL FRUTA</span>${escapeHtml(formatTemperature(cycle?.temperatura_inicial_c))}</div><div><span>OBJETIVO</span>${escapeHtml(formatTemperature(cycle?.temperatura_objetivo_c))}</div><div><span>FINAL FRUTA</span>${escapeHtml(formatTemperature(cycle?.temperatura_c))}</div></div>
        ${cycle ? `<div class="cycle-quality"><div><span>CLORO / PH INICIO → FIN</span>${cycle.cloro_libre_ppm === null ? '—' : `${escapeHtml(formatNumber(cycle.cloro_libre_ppm, 2))} ppm · pH ${escapeHtml(formatNumber(cycle.ph_agua, 2))}`} → ${cycle.cloro_libre_final_ppm === null ? '—' : `${escapeHtml(formatNumber(cycle.cloro_libre_final_ppm, 2))} ppm · pH ${escapeHtml(formatNumber(cycle.ph_agua_final, 2))}`}</div><div><span>AGUA / DOSIFICADOR</span>${cycle.condicion_visual_agua ? escapeHtml(label(cycle.condicion_visual_agua)) : '—'} · ${cycle.dosificador_operativo === null ? '—' : (cycle.dosificador_operativo ? 'Operativo' : 'No operativo')}</div><div><span>CONTROL AGUA</span>${cycle.manejo_agua ? escapeHtml(label(cycle.manejo_agua)) : '—'}</div></div>` : ''}
        ${cycle?.motivo_retencion ? `<p class="cycle-note">Desviación: ${escapeHtml(cycle.motivo_retencion)}${cycle.liberado_at ? ` · Liberado por ${escapeHtml(cycle.liberado_por || 'supervisión')} ${escapeHtml(formatDate(cycle.liberado_at))}` : ' · pendiente de evaluación'}</p>` : ''}
        <p class="cycle-note">${cycle ? `Inicio ${escapeHtml(formatDate(cycle.inicio_at))}${cycle.termino_at ? ` · término ${escapeHtml(formatDate(cycle.termino_at))}` : ''}${note ? ` · ${escapeHtml(note)}` : ''}` : `Confirmado ${escapeHtml(formatDate(lot.confirmado_at))} · CSG ${escapeHtml(lot.trazabilidad.csg)}`}</p>
        ${action ? `<div class="cycle-card__actions">${action}</div>` : ''}
    </article>`;
}
function renderLots() {
    elements.tabs.forEach((tab) => tab.classList.toggle('is-active', tab.dataset.tray === state.tray));
    elements.list.innerHTML = state.lots.length
        ? state.lots.map(card).join('')
        : `<div class="hydrocooler-empty">No existen ciclos o lotes en la bandeja ${escapeHtml(label(state.tray))} para estos filtros.</div>`;
}
function query() {
    const params = new URLSearchParams(new FormData(elements.filters));
    params.set('bandeja', state.tray); params.set('per_page', '200');
    return params.toString();
}
async function downloadRegister(action) {
    const [type, format] = action.split('-');
    const params = new URLSearchParams(type === 'filled' ? new FormData(elements.filters) : undefined);
    params.set('formato', format);
    const path = type === 'blank'
        ? `/api/materia-prima/hidrocooler/registro/en-blanco?${params}`
        : `/api/materia-prima/hidrocooler/registro?${params}`;
    setBusy(true, 'Generando planilla de Hidrocooler…');
    try { await download(path); toast('Planilla de Hidrocooler descargada.'); }
    catch (error) { toast(error.message, true); }
    finally { setBusy(false); }
}
async function load({ silent = false } = {}) {
    if (!silent) setBusy(true, 'Actualizando Hidrocooler…');
    try {
        const [summary, lots] = await Promise.all([
            api('/api/materia-prima/hidrocooler/resumen'),
            api(`/api/materia-prima/hidrocooler/lotes?${query()}`),
        ]);
        state.summary = summary; state.lots = lots.data || [];
        renderSummary(); renderLots();
    } catch (error) {
        if (!silent) toast(error.message, true); else throw error;
    } finally { if (!silent) setBusy(false); }
}
function summaryMarkup(lot) {
    return `<div><span>LOTE</span><strong>${escapeHtml(lot.numero_lote)}</strong></div><div><span>PRODUCTO</span><strong>${escapeHtml(lot.trazabilidad.especie)} · ${escapeHtml(lot.trazabilidad.variedad)}</strong></div><div><span>ENVASES</span><strong>${escapeHtml(lot.envases.cantidad_primarios)} ${escapeHtml(label(lot.envases.primario))}</strong></div><div><span>NETO</span><strong>${escapeHtml(formatKilos(lot.pesos.kilos_netos_confirmados))}</strong></div>`;
}
function openStart(lotId) {
    const lot = state.lots.find((item) => item.id === lotId); if (!lot) return;
    state.selected = lot; elements.startForm.reset(); elements.startError.textContent = '';
    const form = elements.startForm.elements; form.lote_id.value = lot.id; form.operacion_id.value = uuid();
    form.operador.value = state.identity?.nombre || 'Usuario conectado';
    form.inicio_at.value = localDateTimeValue(); form.inicio_at.max = localDateTimeValue();
    elements.startTitle.textContent = `Iniciar ${lot.numero_lote}`;
    elements.startDescription.textContent = 'La composición y el peso del lote quedarán congelados en este ciclo.';
    elements.startSummary.innerHTML = summaryMarkup(lot); elements.startDialog.showModal(); form.equipo.focus();
}
function openFinish(lotId) {
    const lot = state.lots.find((item) => item.id === lotId); if (!lot?.hidrocooler) return;
    state.selected = lot; elements.finishForm.reset(); elements.finishError.textContent = '';
    const form = elements.finishForm.elements; form.lote_id.value = lot.id; form.operacion_id.value = uuid();
    form.termino_at.value = localDateTimeValue(); form.termino_at.max = localDateTimeValue();
    form.termino_at.min = localDateTimeValue(new Date(lot.hidrocooler.inicio_at));
    form.destino_salida.value = 'camara';
    const direct = elements.finishForm.querySelector('input[name="destino_salida"][value="proceso"]');
    direct.disabled = lot.envases.primario !== 'bins';
    direct.closest('label')?.classList.toggle('is-disabled', direct.disabled);
    elements.finishTitle.textContent = `Finalizar ${lot.numero_lote}`;
    elements.finishDescription.textContent = `${lot.hidrocooler.codigo} · ${lot.hidrocooler.equipo} · inicio ${formatDate(lot.hidrocooler.inicio_at)}.`;
    elements.finishSummary.innerHTML = summaryMarkup(lot); elements.finishDialog.showModal(); form.termino_at.focus();
}
function openRelease(lotId) {
    const lot = state.lots.find((item) => item.id === lotId); if (!lot?.hidrocooler) return;
    state.selected = lot; elements.releaseForm.reset(); elements.releaseError.textContent = '';
    const form = elements.releaseForm.elements; form.lote_id.value = lot.id; form.operacion_id.value = uuid();
    elements.releaseTitle.textContent = `Evaluar ${lot.numero_lote}`;
    elements.releaseDescription.textContent = `${lot.hidrocooler.motivo_retencion}. Destino tras liberar: ${label(lot.hidrocooler.destino_salida)}. Solo supervisión puede disponer del producto.`;
    elements.releaseDialog.showModal(); form.temperatura_verificacion_c.focus();
}
async function submitStart() {
    const data = new FormData(elements.startForm);
    const payload = {
        operacion_id: data.get('operacion_id'), equipo: String(data.get('equipo') || '').trim(),
        turno: data.get('turno'), cantidad_bombas_funcionando: Number(data.get('cantidad_bombas_funcionando')),
        inicio_at: new Date(data.get('inicio_at')).toISOString(),
        temperatura_inicial_c: Number(data.get('temperatura_inicial_c')),
        temperatura_objetivo_c: Number(data.get('temperatura_objetivo_c')),
        temperatura_agua_inicial_c: data.get('temperatura_agua_inicial_c') === '' ? null : Number(data.get('temperatura_agua_inicial_c')),
        cloro_libre_ppm: Number(data.get('cloro_libre_ppm')), ph_agua: Number(data.get('ph_agua')),
        control_inicial_conforme: data.get('control_inicial_conforme') === '1',
        condicion_visual_agua: data.get('condicion_visual_agua'),
        dosificador_operativo: data.get('dosificador_operativo') === '1', manejo_agua: data.get('manejo_agua'),
        observacion_inicio: String(data.get('observacion_inicio') || '').trim() || null,
    };
    setBusy(true, 'Iniciando ciclo…');
    try {
        await api(`/api/materia-prima/lotes/${data.get('lote_id')}/hidrocooler/iniciar`, { method: 'POST', body: JSON.stringify(payload) });
        elements.startDialog.close(); toast('Ciclo iniciado y lote bloqueado en Hidrocooler.'); await load();
    } catch (error) { elements.startError.textContent = error.message; }
    finally { setBusy(false); }
}
async function submitFinish() {
    const data = new FormData(elements.finishForm);
    const payload = {
        operacion_id: data.get('operacion_id'), termino_at: new Date(data.get('termino_at')).toISOString(),
        temperatura_c: Number(data.get('temperatura_c')),
        temperatura_agua_final_c: data.get('temperatura_agua_final_c') === '' ? null : Number(data.get('temperatura_agua_final_c')),
        cloro_libre_final_ppm: Number(data.get('cloro_libre_final_ppm')),
        ph_agua_final: Number(data.get('ph_agua_final')),
        condicion_visual_agua_final: data.get('condicion_visual_agua_final'),
        dosificador_operativo_final: data.get('dosificador_operativo_final') === '1',
        control_final_conforme: data.get('control_final_conforme') === '1',
        destino_salida: data.get('destino_salida'), observacion: String(data.get('observacion') || '').trim() || null,
        accion_correctiva: String(data.get('accion_correctiva') || '').trim() || null,
    };
    setBusy(true, 'Finalizando ciclo…');
    try {
        const response = await api(`/api/materia-prima/lotes/${data.get('lote_id')}/hidrocooler/completar`, { method: 'POST', body: JSON.stringify(payload) });
        elements.finishDialog.close();
        toast(response.data?.estado === 'hidrocooler_retenido' ? 'Ciclo finalizado; lote retenido hasta evaluación de supervisión.' : payload.destino_salida === 'proceso' ? 'Ciclo finalizado; lote disponible directo a Fruta a proceso.' : 'Ciclo finalizado; lote pendiente de asignación a cámara.');
        await load();
    } catch (error) { elements.finishError.textContent = error.message; }
    finally { setBusy(false); }
}
async function submitRelease() {
    const data = new FormData(elements.releaseForm);
    const payload = {
        operacion_id: data.get('operacion_id'),
        temperatura_verificacion_c: Number(data.get('temperatura_verificacion_c')),
        cloro_libre_verificacion_ppm: Number(data.get('cloro_libre_verificacion_ppm')),
        ph_agua_verificacion: Number(data.get('ph_agua_verificacion')),
        control_verificacion_conforme: data.get('control_verificacion_conforme') === '1',
        evaluacion_producto: String(data.get('evaluacion_producto') || '').trim(),
        verificacion_liberacion: String(data.get('verificacion_liberacion') || '').trim(),
    };
    setBusy(true, 'Verificando liberación…');
    try {
        await api(`/api/materia-prima/lotes/${data.get('lote_id')}/hidrocooler/liberar`, { method: 'POST', body: JSON.stringify(payload) });
        elements.releaseDialog.close(); toast('Lote liberado por supervisión con evaluación registrada.'); await load();
    } catch (error) { elements.releaseError.textContent = error.message; }
    finally { setBusy(false); }
}

elements.login.addEventListener('submit', async (event) => {
    event.preventDefault(); elements.loginError.textContent = ''; setBusy(true, 'Verificando acceso…');
    try {
        const payload = await api('/api/acceso-oficina', { method: 'POST', body: JSON.stringify(Object.fromEntries(new FormData(elements.login))) });
        if (payload.usuario?.puede_consultar_hidrocooler_materia_prima !== true) throw new ApiError('El usuario no posee acceso a Hidrocooler.', 403);
        persist(payload); showApp(); await load(); startRefresh();
    } catch (error) { elements.loginError.textContent = error.message; }
    finally { setBusy(false); }
});
elements.logout.addEventListener('click', async () => {
    try { await api('/api/acceso-oficina', { method: 'DELETE' }); } finally { clearSession(); }
});
elements.reload.addEventListener('click', () => load());
elements.registerButtons.forEach((button) => button.addEventListener('click', () => downloadRegister(button.dataset.register)));
elements.filters.addEventListener('submit', (event) => { event.preventDefault(); void load(); });
elements.tabs.forEach((tab) => tab.addEventListener('click', () => { state.tray = tab.dataset.tray; void load(); }));
elements.list.addEventListener('click', (event) => {
    const start = event.target.closest('[data-start]'); if (start) { openStart(start.dataset.start); return; }
    const finish = event.target.closest('[data-finish]'); if (finish) { openFinish(finish.dataset.finish); return; }
    const release = event.target.closest('[data-release]'); if (release) openRelease(release.dataset.release);
});
elements.startForm.addEventListener('submit', (event) => {
    if (event.submitter?.value === 'cancel') return;
    event.preventDefault();
    if (elements.startForm.reportValidity()) void submitStart();
});
elements.finishForm.addEventListener('submit', (event) => {
    if (event.submitter?.value === 'cancel') return;
    event.preventDefault();
    if (elements.finishForm.reportValidity()) void submitFinish();
});
elements.releaseForm.addEventListener('submit', (event) => {
    if (event.submitter?.value === 'cancel') return;
    event.preventDefault();
    if (elements.releaseForm.reportValidity()) void submitRelease();
});

function startRefresh() {
    state.poller?.stop();
    state.poller = createOperationalPoller(() => load({ silent: true }), {
        intervalMs: 30000,
        canRun: () => Boolean(state.token) && !elements.startDialog.open && !elements.finishDialog.open && !elements.releaseDialog.open,
    });
    state.poller.start();
}
async function boot() {
    if (!state.token || !can('puede_consultar_hidrocooler_materia_prima')) {
        if (state.token) clearSession(); return;
    }
    if (!showApp()) return;
    setBusy(true, 'Cargando Hidrocooler…');
    try { await load({ silent: true }); startRefresh(); }
    catch (error) { if (error.status !== 401) toast(error.message, true); }
    finally { setBusy(false); }
}

void boot();
