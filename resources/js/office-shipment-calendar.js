import { showOfficeToast } from './office-toast.js';

const byId = (id) => document.getElementById(id);
const elements = {
    access: byId('officeAccess'), app: byId('officeApp'), loginForm: byId('officeLoginForm'),
    loginError: byId('officeLoginError'), userName: byId('officeUserName'), userRole: byId('officeUserRole'),
    initials: byId('officeInitials'), logout: byId('officeLogoutButton'), season: byId('seasonSummary'),
    date: byId('calendarDate'), mode: byId('calendarMode'), previous: byId('previousPeriod'),
    next: byId('nextPeriod'), today: byId('todayButton'), newShipment: byId('newShipment'),
    grid: byId('shipmentGrid'), scroll: byId('calendarScroll'), dialog: byId('shipmentDialog'),
    form: byId('shipmentForm'), formError: byId('shipmentFormError'), dialogTitle: byId('shipmentDialogTitle'),
    dialogEyebrow: byId('shipmentDialogEyebrow'), dialogHelp: byId('shipmentDialogHelp'),
    closeDialog: byId('closeShipmentDialog'), dismiss: byId('dismissShipment'), addInstruction: byId('addInstruction'),
    instructionList: byId('instructionList'), instructionTemplate: byId('instructionTemplate'),
    overbook: byId('overbookAuthorization'), overbookReason: byId('overbookReason'),
    loadConfirmation: byId('loadConfirmation'), save: byId('saveShipment'), confirm: byId('confirmShipment'),
    cancel: byId('cancelShipment'), loading: byId('officeLoading'), loadingText: byId('officeLoadingText'),
    toasts: byId('officeToasts'),
};

const keys = { token: 'estiba_wms_office_token', identity: 'estiba_wms_office_identity' };
const state = {
    token: localStorage.getItem(keys.token),
    identity: readJson(keys.identity),
    anchor: todayLocal(),
    mode: 'week',
    season: null,
    windows: [],
    shipments: [],
    catalogs: { clientes: [], camaras: [], andenes: [], paises: [], puertos: [] },
    permissions: { gestionar: false, autorizar_sobrecupo: false },
    selected: null,
};

class ApiError extends Error {
    constructor(message, status, data = {}) { super(message); this.status = status; this.data = data; }
}

function readJson(key) { try { return JSON.parse(localStorage.getItem(key) || 'null'); } catch { return null; } }
function escapeHtml(value) { return String(value ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&#039;'); }
function todayLocal() { const now = new Date(); return formatDateKey(now); }
function parseDate(value) { return new Date(`${value}T12:00:00`); }
function formatDateKey(value) {
    const date = value instanceof Date ? value : parseDate(value);
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}
function addDays(value, amount) { const date = parseDate(value); date.setDate(date.getDate() + amount); return formatDateKey(date); }
function mondayOf(value) { const date = parseDate(value); date.setDate(date.getDate() - ((date.getDay() + 6) % 7)); return formatDateKey(date); }
function dateLabel(value, options = { weekday: 'short', day: '2-digit', month: 'short' }) { return new Intl.DateTimeFormat('es-CL', options).format(parseDate(value)); }
function statusText(value) { return String(value || '').replaceAll('_', ' ').replace(/^./, (letter) => letter.toUpperCase()); }
function modeText(value) { return ({ maritimo: 'Marítimo', aereo: 'Aéreo', terrestre: 'Terrestre', por_confirmar: 'Por confirmar' })[value] || statusText(value); }
function transportPointType(value) { return ({ maritimo: 'Puerto', aereo: 'Aeropuerto', terrestre: 'Paso' })[value] || statusText(value); }

async function api(path, options = {}) {
    const headers = new Headers(options.headers || {});
    headers.set('Accept', 'application/json');
    if (state.token) headers.set('Authorization', `Bearer ${state.token}`);
    if (options.body) headers.set('Content-Type', 'application/json');
    let response;
    try { response = await fetch(path, { ...options, headers }); }
    catch { throw new ApiError('No fue posible conectar con Laravel.', 0); }
    const data = response.status === 204 ? null : await response.json().catch(() => ({}));
    if (!response.ok) {
        if (response.status === 401 && path !== '/api/acceso-oficina') clearSession();
        throw new ApiError(Object.values(data?.errors || {}).flat()[0] || data?.message || 'No fue posible completar la operación.', response.status, data);
    }
    return data;
}

function setBusy(active, message = 'Procesando…') {
    elements.loadingText.textContent = message;
    elements.loading.classList.toggle('is-hidden', !active);
    elements.loading.setAttribute('aria-hidden', String(!active));
}
function toast(message, error = false) { showOfficeToast(elements.toasts, message, error); }
function persistSession(payload) {
    state.token = payload.token; state.identity = payload.usuario;
    localStorage.setItem(keys.token, payload.token); localStorage.setItem(keys.identity, JSON.stringify(payload.usuario));
    window.dispatchEvent(new CustomEvent('estiba:office-session', { detail: { authenticated: true, identity: payload.usuario } }));
}
function clearSession() {
    state.token = null; state.identity = null;
    localStorage.removeItem(keys.token); localStorage.removeItem(keys.identity);
    elements.app.classList.add('is-hidden'); elements.access.classList.remove('is-hidden');
    window.dispatchEvent(new CustomEvent('estiba:office-session', { detail: { authenticated: false } }));
}
function showApp() {
    elements.access.classList.add('is-hidden'); elements.app.classList.remove('is-hidden');
    const name = state.identity?.nombre || 'Usuario';
    elements.userName.textContent = name; elements.userRole.textContent = statusText(state.identity?.rol || 'oficina');
    elements.initials.textContent = name.split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0]).join('').toUpperCase();
}

function range() {
    if (state.mode === 'day') return { from: state.anchor, to: state.anchor, dates: [state.anchor] };
    const from = mondayOf(state.anchor);
    return { from, to: addDays(from, 6), dates: Array.from({ length: 7 }, (_, index) => addDays(from, index)) };
}

async function loadCalendar({ preserveScroll = false } = {}) {
    const { from, to } = range();
    const previousScroll = elements.scroll.scrollTop;
    setBusy(true, 'Cargando ventanas de embarque…');
    try {
        const payload = await api(`/api/embarques?desde=${encodeURIComponent(from)}&hasta=${encodeURIComponent(to)}`);
        state.season = payload.temporada; state.windows = payload.ventanas || []; state.shipments = payload.embarques || [];
        state.catalogs = payload.catalogos || state.catalogs; state.permissions = payload.permisos || state.permissions;
        elements.season.textContent = `${state.season.codigo} · intervalo global ${state.season.intervalo_embarques_minutos} minutos · 24 horas`;
        renderCalendar(); populateCatalogs();
        if (preserveScroll) elements.scroll.scrollTop = previousScroll;
        else scrollToCurrentHour();
    } catch (error) { toast(error.message, true); }
    finally { setBusy(false); }
}

function renderCalendar() {
    const { dates } = range();
    const filtered = state.windows.filter((window) => dates.includes(window.fecha));
    const times = [...new Set(filtered.map((window) => window.hora))];
    const byKey = new Map(filtered.map((window) => [`${window.fecha}|${window.hora}`, window]));
    elements.grid.style.gridTemplateColumns = `76px repeat(${dates.length}, minmax(190px, 1fr))`;
    const html = ['<div class="shipment-grid__corner" role="columnheader"></div>'];
    dates.forEach((date) => html.push(`<div class="shipment-grid__day" role="columnheader"><strong>${escapeHtml(dateLabel(date))}</strong><small>${escapeHtml(date)}</small></div>`));

    times.forEach((time) => {
        html.push(`<div class="shipment-grid__time" role="rowheader" data-calendar-time="${time}">${escapeHtml(time)}</div>`);
        dates.forEach((date) => html.push(renderWindow(byKey.get(`${date}|${time}`), date, time)));
    });
    elements.grid.innerHTML = html.join('');
}

function renderWindow(window, date, time) {
    if (!window) return '<div class="shipment-window"></div>';
    const cancelled = state.shipments.filter((shipment) => shipment.estado === 'cancelado' && shipment.fecha_programada === date && shipment.hora_programada === time);
    const active = window.embarques || [];
    const cards = [...active.map((summary) => {
        const shipment = state.shipments.find((candidate) => candidate.id === summary.id) || summary;
        return renderShipmentCard(shipment);
    }), ...cancelled.map(renderShipmentCard)].join('');

    if (window.disponible) {
        return `<div class="shipment-window" role="gridcell">${cards}<button class="shipment-window__available" data-open-window data-date="${date}" data-time="${time}" type="button">Disponible<br><small>Crear embarque aquí</small></button></div>`;
    }
    const occupied = `<div class="shipment-window__occupied">Ventana ocupada${window.ocupada_por?.length ? ` · ${escapeHtml(window.ocupada_por.join(', '))}` : ''}</div>`;
    const overbook = state.permissions.autorizar_sobrecupo
        ? `<button class="shipment-window__overbook" data-open-window data-overbook="1" data-date="${date}" data-time="${time}" type="button">+ Autorizar sobrecupo</button>` : '';
    return `<div class="shipment-window" role="gridcell">${cards || occupied}${overbook}</div>`;
}

function renderShipmentCard(shipment) {
    const total = shipment.totales?.instructivos ?? shipment.instructivos?.length ?? 0;
    const cargo = shipment.carga?.codigo || shipment.carga_codigo;
    const overbook = shipment.sobrecupo || shipment.sobrecupo === true;
    return `<article class="shipment-card shipment-card--${escapeHtml(shipment.estado)}${overbook ? ' shipment-card--overbook' : ''}">
        <strong>${escapeHtml(shipment.codigo)}</strong>
        <small>${escapeHtml(shipment.cliente?.nombre || shipment.cliente || 'Cliente')} · ${escapeHtml(modeText(shipment.modalidad))}</small>
        <small>${Number(total)} instructivo(s)${cargo ? ` · ${escapeHtml(cargo)}` : ''}${overbook ? ' · Sobrecupo' : ''}</small>
        <select data-shipment-action="${shipment.id}" aria-label="Acciones de ${escapeHtml(shipment.codigo)}">
            <option value="">Seleccionar acción</option>
            <option value="view">${state.permissions.gestionar ? 'Ver o editar' : 'Ver detalle'}</option>
            ${shipment.estado === 'tentativo' && state.permissions.gestionar ? '<option value="confirm">Completar y crear orden CAR</option><option value="cancel">Cancelar embarque</option>' : ''}
            ${cargo ? '<option value="load">Abrir orden de carga</option>' : ''}
        </select>
    </article>`;
}

function scrollToCurrentHour() {
    const hour = `${String(new Date().getHours()).padStart(2, '0')}:`;
    const target = [...elements.grid.querySelectorAll('[data-calendar-time]')].find((node) => node.dataset.calendarTime.startsWith(hour));
    target?.scrollIntoView({ block: 'start' });
}

function populateCatalogs() {
    const form = elements.form.elements;
    form.cliente_id.innerHTML = '<option value="">Selecciona cliente</option>' + state.catalogs.clientes.map((client) => `<option value="${client.id}"${client.codigo_folio_materiales ? '' : ' disabled'}>${escapeHtml(client.codigo)} · ${escapeHtml(client.nombre)}${client.codigo_folio_materiales ? '' : ' · sin sigla documental'}</option>`).join('');
    form.camara_objetivo_id.innerHTML = '<option value="">Sin cámara objetivo</option>' + state.catalogs.camaras.map((camera) => `<option value="${camera.id}">${escapeHtml(camera.codigo)} · ${escapeHtml(camera.nombre)}</option>`).join('');
    form.anden_previsto_id.innerHTML = '<option value="">Sin andén previsto</option>' + state.catalogs.andenes.map((dock) => `<option value="${dock.id}">${escapeHtml(dock.codigo)} · ${escapeHtml(dock.nombre)}</option>`).join('');
    form.puerto_embarque_id.innerHTML = '<option value="">Sin punto de embarque informado</option>' + state.catalogs.paises.map((country) => {
        const ports = state.catalogs.puertos.filter((port) => port.pais_id === country.id);
        if (!ports.length) return '';
        return `<optgroup label="${escapeHtml(country.iso_alpha2)} · ${escapeHtml(country.nombre_es)}">${ports.map((port) => `<option value="${port.id}">${escapeHtml(transportPointType(port.tipo))} · ${escapeHtml(port.nombre)} (${escapeHtml(port.codigo)})</option>`).join('')}</optgroup>`;
    }).join('');
}

function populateTimeOptions(selected = '') {
    const form = elements.form.elements;
    const date = form.fecha_programada.value;
    const interval = Number(state.season?.intervalo_embarques_minutos || 60);
    const options = ['<option value="">Selecciona una ventana</option>'];
    for (let minutes = 0; minutes < 1440; minutes += interval) {
        const time = `${String(Math.floor(minutes / 60)).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`;
        const window = state.windows.find((candidate) => candidate.fecha === date && candidate.hora === time);
        const others = (window?.ocupada_por || []).filter((code) => code !== state.selected?.codigo);
        const occupied = others.length > 0;
        const disabled = occupied && !state.permissions.autorizar_sobrecupo;
        options.push(`<option value="${time}"${time === selected ? ' selected' : ''}${disabled ? ' disabled' : ''}>${time}${occupied ? ` · ocupado por ${escapeHtml(others.join(', '))}` : ' · disponible'}</option>`);
    }
    form.hora_programada.innerHTML = options.join('');
    updateOverbookControls();
}

function updateOverbookControls(force = false) {
    const form = elements.form.elements;
    const window = state.windows.find((candidate) => candidate.fecha === form.fecha_programada.value && candidate.hora === form.hora_programada.value);
    const occupied = force || (window?.ocupada_por || []).some((code) => code !== state.selected?.codigo);
    const visible = occupied && state.permissions.autorizar_sobrecupo;
    elements.overbook.classList.toggle('is-hidden', !visible);
    if (!visible) { form.autorizar_sobrecupo.checked = false; elements.overbookReason.classList.add('is-hidden'); }
    else if (force) form.autorizar_sobrecupo.checked = true;
    elements.overbookReason.classList.toggle('is-hidden', !visible || !form.autorizar_sobrecupo.checked);
}

function addInstruction(data = {}) {
    const fragment = elements.instructionTemplate.content.cloneNode(true);
    const card = fragment.querySelector('.instruction-card');
    const country = card.querySelector('[data-field="pais_destino_id"]');
    country.innerHTML = '<option value="">Selecciona país</option>' + state.catalogs.paises.map((item) => `<option value="${item.id}">${escapeHtml(item.iso_alpha2)} · ${escapeHtml(item.nombre_es)}</option>`).join('');
    country.value = data.pais_destino_id ?? '';
    populateInstructionPorts(card, country.value, data.puerto_destino_id ?? '');
    card.querySelectorAll('[data-field]').forEach((input) => {
        if (!['pais_destino_id', 'puerto_destino_id'].includes(input.dataset.field)) input.value = data[input.dataset.field] ?? '';
    });
    elements.instructionList.append(fragment); renumberInstructions();
}
function populateInstructionPorts(card, countryId, selected = '') {
    const port = card.querySelector('[data-field="puerto_destino_id"]');
    const options = state.catalogs.puertos.filter((item) => item.pais_id === countryId);
    port.innerHTML = '<option value="">Selecciona punto de destino</option>' + options.map((item) => `<option value="${item.id}">${escapeHtml(transportPointType(item.tipo))} · ${escapeHtml(item.nombre)} (${escapeHtml(item.codigo)})</option>`).join('');
    port.disabled = !countryId;
    port.value = selected;
}
function renumberInstructions() {
    const cards = [...elements.instructionList.querySelectorAll('.instruction-card')];
    cards.forEach((card, index) => { card.querySelector('[data-instruction-number]').textContent = String(index + 1); card.querySelector('[data-remove-instruction]').disabled = cards.length === 1; });
}

function resetDialog() {
    elements.form.reset(); state.selected = null; elements.formError.textContent = ''; elements.instructionList.innerHTML = '';
    elements.form.elements.id.value = ''; elements.form.elements.version_esperada.value = '';
    elements.cancel.classList.add('is-hidden'); elements.confirm.classList.add('is-hidden'); elements.loadConfirmation.classList.add('is-hidden');
    elements.save.classList.remove('is-hidden'); elements.overbook.classList.add('is-hidden'); elements.overbookReason.classList.add('is-hidden');
    [...elements.form.elements].forEach((field) => { field.disabled = false; });
    addInstruction(); populateCatalogs();
}

function openNew(date = state.anchor, time = '', overbook = false) {
    resetDialog();
    elements.dialogTitle.textContent = 'Nuevo embarque'; elements.dialogEyebrow.textContent = 'SOLICITUD TENTATIVA';
    elements.dialogHelp.textContent = 'La fecha y la hora se seleccionan manualmente; el sistema no asigna el siguiente cupo.';
    elements.form.elements.fecha_programada.value = date || state.anchor;
    populateTimeOptions(time); elements.form.elements.hora_programada.value = time;
    updateOverbookControls(overbook); elements.dialog.showModal();
}

async function openExisting(id, focusConfirmation = false) {
    setBusy(true, 'Cargando embarque…');
    try {
        const response = await api(`/api/embarques/${id}`); const shipment = response.data;
        resetDialog(); state.selected = shipment;
        elements.dialogTitle.textContent = shipment.codigo; elements.dialogEyebrow.textContent = statusText(shipment.estado);
        elements.dialogHelp.textContent = `${shipment.cliente.codigo} · ${shipment.intervalo_minutos} minutos reservados`;
        const form = elements.form.elements;
        form.id.value = shipment.id; form.version_esperada.value = shipment.version; form.cliente_id.value = shipment.cliente.id;
        for (const field of ['modalidad', 'fecha_programada', 'referencia_correo', 'observacion', 'nave_vuelo', 'transportista', 'puerto_embarque_id', 'contenedor', 'sello', 'patente_camion', 'patente_trasera', 'documentos']) form[field].value = shipment[field] ?? '';
        populateTimeOptions(shipment.hora_programada); form.hora_programada.value = shipment.hora_programada;
        elements.instructionList.innerHTML = ''; (shipment.instructivos || []).forEach(addInstruction); if (!shipment.instructivos?.length) addInstruction();
        const editable = state.permissions.gestionar && shipment.estado !== 'cancelado';
        elements.save.classList.toggle('is-hidden', !editable); elements.cancel.classList.toggle('is-hidden', !editable);
        const tentative = editable && shipment.estado === 'tentativo';
        elements.confirm.classList.toggle('is-hidden', !tentative); elements.loadConfirmation.classList.toggle('is-hidden', !tentative);
        if (!editable) [...elements.form.elements].forEach((field) => { if (!['id', 'version_esperada'].includes(field.name)) field.disabled = true; });
        elements.dialog.showModal();
        if (focusConfirmation) { elements.loadConfirmation.open = true; elements.loadConfirmation.scrollIntoView({ block: 'center' }); }
    } catch (error) { toast(error.message, true); }
    finally { setBusy(false); }
}

function instructionPayload() {
    return [...elements.instructionList.querySelectorAll('.instruction-card')].map((card) => {
        const row = {}; card.querySelectorAll('[data-field]').forEach((field) => {
            row[field.dataset.field] = field.type === 'number' && field.value !== '' ? Number(field.value) : field.value;
        }); return row;
    });
}
function shipmentPayload() {
    const form = elements.form.elements;
    const payload = {};
    for (const field of ['cliente_id', 'modalidad', 'fecha_programada', 'hora_programada', 'referencia_correo', 'observacion', 'nave_vuelo', 'transportista', 'puerto_embarque_id', 'contenedor', 'sello', 'patente_camion', 'patente_trasera', 'documentos']) payload[field] = form[field].value;
    payload.instructivos = instructionPayload(); payload.autorizar_sobrecupo = form.autorizar_sobrecupo.checked;
    payload.motivo_sobrecupo = form.motivo_sobrecupo.value;
    if (form.id.value) payload.version_esperada = Number(form.version_esperada.value);
    return payload;
}

async function saveCurrent({ close = true } = {}) {
    elements.formError.textContent = '';
    if (!elements.form.reportValidity()) return null;
    const id = elements.form.elements.id.value;
    setBusy(true, id ? 'Actualizando embarque…' : 'Creando embarque…');
    try {
        const response = await api(id ? `/api/embarques/${id}` : '/api/embarques', { method: id ? 'PUT' : 'POST', body: JSON.stringify(shipmentPayload()) });
        state.selected = response.data; elements.form.elements.id.value = response.data.id; elements.form.elements.version_esperada.value = response.data.version;
        await loadCalendar({ preserveScroll: true });
        if (close) elements.dialog.close();
        toast(`${response.data.codigo} guardado correctamente.`);
        return response.data;
    } catch (error) { elements.formError.textContent = error.message; toast(error.message, true); return null; }
    finally { setBusy(false); }
}

elements.loginForm.addEventListener('submit', async (event) => {
    event.preventDefault(); elements.loginError.textContent = ''; setBusy(true, 'Validando acceso…');
    try {
        const payload = await api('/api/acceso-oficina', { method: 'POST', body: JSON.stringify(Object.fromEntries(new FormData(elements.loginForm))) });
        if (payload.usuario.puede_consultar_catalogo_cargas !== true) throw new ApiError('Tu perfil no puede consultar el calendario de embarques.', 403);
        persistSession(payload); showApp(); await loadCalendar();
    } catch (error) { elements.loginError.textContent = error.message; }
    finally { setBusy(false); }
});
elements.logout.addEventListener('click', async () => { try { await api('/api/acceso-oficina', { method: 'DELETE' }); } finally { clearSession(); } });
elements.mode.addEventListener('change', () => { state.mode = elements.mode.value; loadCalendar(); });
elements.date.addEventListener('change', () => { state.anchor = elements.date.value || todayLocal(); loadCalendar(); });
elements.today.addEventListener('click', () => { state.anchor = todayLocal(); elements.date.value = state.anchor; loadCalendar(); });
elements.previous.addEventListener('click', () => { state.anchor = addDays(state.anchor, state.mode === 'week' ? -7 : -1); elements.date.value = state.anchor; loadCalendar(); });
elements.next.addEventListener('click', () => { state.anchor = addDays(state.anchor, state.mode === 'week' ? 7 : 1); elements.date.value = state.anchor; loadCalendar(); });
elements.newShipment.addEventListener('click', () => openNew(state.anchor, ''));
elements.closeDialog.addEventListener('click', () => elements.dialog.close());
elements.dismiss.addEventListener('click', () => elements.dialog.close());
elements.addInstruction.addEventListener('click', () => addInstruction());
elements.instructionList.addEventListener('click', (event) => { const button = event.target.closest('[data-remove-instruction]'); if (!button) return; button.closest('.instruction-card').remove(); renumberInstructions(); });
elements.instructionList.addEventListener('change', (event) => {
    const country = event.target.closest('[data-field="pais_destino_id"]');
    if (!country) return;
    populateInstructionPorts(country.closest('.instruction-card'), country.value);
});
elements.form.elements.fecha_programada.addEventListener('change', () => populateTimeOptions(elements.form.elements.hora_programada.value));
elements.form.elements.hora_programada.addEventListener('change', () => updateOverbookControls());
elements.form.elements.autorizar_sobrecupo.addEventListener('change', () => updateOverbookControls());
elements.form.addEventListener('submit', async (event) => { event.preventDefault(); await saveCurrent(); });

elements.grid.addEventListener('click', (event) => {
    const button = event.target.closest('[data-open-window]');
    if (button) openNew(button.dataset.date, button.dataset.time, button.dataset.overbook === '1');
});
elements.grid.addEventListener('change', async (event) => {
    const select = event.target.closest('[data-shipment-action]'); if (!select || !select.value) return;
    const shipment = state.shipments.find((candidate) => candidate.id === select.dataset.shipmentAction);
    const action = select.value; select.value = '';
    if (action === 'view') await openExisting(shipment.id);
    if (action === 'confirm') await openExisting(shipment.id, true);
    if (action === 'cancel') { await openExisting(shipment.id); elements.cancel.click(); }
    if (action === 'load' && shipment.carga?.id) window.location.href = `/oficina/frigorifico/despacho/cargas?carga=${encodeURIComponent(shipment.carga.id)}`;
});

elements.confirm.addEventListener('click', async () => {
    const saved = await saveCurrent({ close: false }); if (!saved) return;
    const form = elements.form.elements; setBusy(true, 'Creando orden de carga…'); elements.formError.textContent = '';
    try {
        const response = await api(`/api/embarques/${saved.id}/confirmar`, { method: 'POST', body: JSON.stringify({
            version_esperada: saved.version, prioridad: form.prioridad.value,
            camara_objetivo_id: form.camara_objetivo_id.value || null,
            anden_previsto_id: form.anden_previsto_id.value || null,
        }) });
        elements.dialog.close(); await loadCalendar({ preserveScroll: true });
        toast(`${response.data.codigo} confirmado y vinculado a ${response.data.carga.codigo}.`);
    } catch (error) { elements.formError.textContent = error.message; toast(error.message, true); }
    finally { setBusy(false); }
});
elements.cancel.addEventListener('click', async () => {
    if (!state.selected) return; const reason = window.prompt(`Motivo de cancelación de ${state.selected.codigo}:`); if (!reason) return;
    setBusy(true, 'Cancelando embarque…');
    try {
        await api(`/api/embarques/${state.selected.id}/cancelar`, { method: 'POST', body: JSON.stringify({ version_esperada: Number(elements.form.elements.version_esperada.value), motivo: reason }) });
        elements.dialog.close(); await loadCalendar({ preserveScroll: true }); toast(`${state.selected.codigo} fue cancelado.`);
    } catch (error) { elements.formError.textContent = error.message; toast(error.message, true); }
    finally { setBusy(false); }
});

elements.date.value = state.anchor; elements.mode.value = state.mode;
if (state.token && state.identity?.puede_consultar_catalogo_cargas === true) { showApp(); loadCalendar(); }
else clearSession();
