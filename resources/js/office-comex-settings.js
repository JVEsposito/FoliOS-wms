import {
    MANAGEMENT,
    MOMENTS,
    asksText,
    countText,
    formatMoment,
    managementLabel,
    requirementText,
    scopeText,
} from './shared/comex-logic';

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
    status: byId('settingsStatus'),
    typesActions: byId('settingsTypesActions'),
    types: byId('settingsTypes'),
    clientFilters: byId('settingsClientFilters'),
    clientSearch: byId('settingsClientSearch'),
    clientManagement: byId('settingsClientManagement'),
    clients: byId('settingsClients'),
    typeDialog: byId('typeDialog'),
    typeForm: byId('typeForm'),
    typeEyebrow: byId('typeEyebrow'),
    typeTitle: byId('typeTitle'),
    typeName: byId('typeName'),
    typeDescription: byId('typeDescription'),
    typeMoment: byId('typeMoment'),
    typeRequirement: byId('typeRequirement'),
    typeCountriesField: byId('typeCountriesField'),
    typeCountrySearch: byId('typeCountrySearch'),
    typeCountriesChosen: byId('typeCountriesChosen'),
    typeCountries: byId('typeCountries'),
    typeOperationField: byId('typeOperationField'),
    typeOrder: byId('typeOrder'),
    typeActive: byId('typeActive'),
    typeMessage: byId('typeMessage'),
    typeSubmit: byId('typeSubmit'),
    clientDialog: byId('clientDialog'),
    clientForm: byId('clientForm'),
    clientTitle: byId('clientTitle'),
    clientNote: byId('clientNote'),
    clientObservation: byId('clientObservation'),
    clientMessage: byId('clientMessage'),
    clientSubmit: byId('clientSubmit'),
    loading: byId('officeLoading'),
    loadingText: byId('officeLoadingText'),
    toasts: byId('officeToasts'),
};
const keys = { token: 'estiba_wms_office_token', identity: 'estiba_wms_office_identity' };
const state = {
    token: localStorage.getItem(keys.token),
    identity: readJson(keys.identity),
    data: null,
    type: null,
    client: null,
    countries: new Set(),
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

function toast(message, failed = false) {
    const node = document.createElement('div');
    node.className = `toast${failed ? ' toast--error' : ''}`;
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

function canConfigure() {
    return state.data?.permisos?.configurar === true;
}

function renderStatus() {
    const types = state.data.tipos;
    const active = types.filter((type) => type.activo).length;
    const missing = state.data.sugeridos_faltantes.length;
    elements.status.className = `cx-status${active ? '' : ' cx-status--alerta'}`;
    elements.status.innerHTML = `<strong>${escapeHtml(countText(active, 'documento activo', 'documentos activos'))}${types.length > active ? ` y ${escapeHtml(countText(types.length - active, 'inactivo'))}` : ''}.</strong>`
        + `<small>${canConfigure() ? '' : 'Puede consultar la configuración; para cambiarla necesita el módulo Configuración Comex. '}`
        + `${missing ? `${escapeHtml(countText(missing, 'documento sugerido no está', 'documentos sugeridos no están'))} en la lista.` : 'Los documentos sugeridos ya están en la lista.'}</small>`;
}

function renderTypes() {
    const types = state.data.tipos;
    const missing = state.data.sugeridos_faltantes;
    elements.typesActions.innerHTML = canConfigure()
        ? (missing.length ? `<button class="secondary-button" type="button" data-settings-suggested title="${escapeHtml(missing.map((item) => item.nombre).join(', '))}">Agregar sugeridos (${missing.length})</button>` : '')
            + '<button class="primary-button" type="button" data-settings-new>Agregar documento</button>'
        : '';
    if (!types.length) {
        elements.types.innerHTML = '<p class="cx-vacio">Todavía no hay documentos. Agregue los sugeridos o defina los suyos.</p>';
        return;
    }
    elements.types.innerHTML = '<div class="cx-table-scroll"><table class="cx-table cx-table--tipos"><thead><tr>'
        + '<th>Documento</th><th>Se necesita</th><th>Se exige</th><th>Aplica a</th><th>Pide</th><th>Estado</th><th></th>'
        + '</tr></thead><tbody>'
        + types.map((type) => `<tr class="${type.activo ? '' : 'is-inactivo'}">`
            + `<td class="cx-c-nombre"><strong>${escapeHtml(type.nombre)}</strong>${type.descripcion ? `<small>${escapeHtml(type.descripcion)}</small>` : ''}</td>`
            + `<td class="cx-c-momento" data-etiqueta="Se necesita">${escapeHtml(MOMENTS[type.momento] || type.momento)}</td>`
            + `<td class="cx-c-exige" data-etiqueta="Se exige">${escapeHtml(requirementText(type))}</td>`
            + `<td class="cx-c-aplica" data-etiqueta="Aplica a">${escapeHtml(scopeText(type))}${type.dato_operacion ? `<small>Referencia: ${escapeHtml(state.data.datos_operacion.find((item) => item.clave === type.dato_operacion)?.nombre || type.dato_operacion)} de la operación</small>` : ''}</td>`
            + `<td class="cx-c-pide" data-etiqueta="Pide">${escapeHtml(asksText(type))}</td>`
            + `<td class="cx-c-estado"><span class="cx-chip cx-chip--${type.activo ? 'ok' : 'neutro'}">${type.activo ? 'Activo' : 'Inactivo'}</span></td>`
            + `<td class="cx-c-acciones">${canConfigure() ? `<button class="cx-boton" type="button" data-settings-type="${type.id}">Editar</button>` : ''}</td>`
            + '</tr>').join('')
        + '</tbody></table></div>';
}

function renderClients() {
    const term = elements.clientSearch.value.trim().toLocaleLowerCase('es');
    const management = elements.clientManagement.value;
    const clients = state.data.clientes
        .filter((client) => !term || client.nombre.toLocaleLowerCase('es').includes(term))
        .filter((client) => !management || client.gestion === management);
    if (!clients.length) {
        elements.clients.innerHTML = `<p class="cx-vacio">${state.data.clientes.length ? 'Ningún cliente coincide con la búsqueda.' : 'No hay clientes en la operación.'}</p>`;
        return;
    }
    elements.clients.innerHTML = '<ul class="cx-lista-clientes">'
        + clients.map((client) => '<li>'
            + `<div><strong>${escapeHtml(client.nombre)}</strong>`
            + `<small>${escapeHtml(client.configurado ? `Actualizado por ${client.actualizado_por || 'usuario sin nombre'} el ${formatMoment(client.actualizado_at)}` : 'Por omisión: servicio')}${client.activo ? '' : ' · inactivo en la operación'}</small>`
            + `${client.observacion ? `<small>${escapeHtml(client.observacion)}</small>` : ''}</div>`
            + `<span class="cx-chip cx-chip--${client.gestion === 'sin_seguimiento' ? 'neutro' : (client.gestion === 'propia' ? 'info' : 'ok')}">${escapeHtml(managementLabel(client.gestion))}</span>`
            + (canConfigure() ? `<button class="cx-boton" type="button" data-settings-client="${escapeHtml(client.cliente_id)}">Cambiar</button>` : '')
            + '</li>').join('')
        + '</ul>';
}

function render(data) {
    state.data = data;
    renderStatus();
    renderTypes();
    renderClients();
}

async function load() {
    const response = await api('/api/comex/configuracion');
    if (!response.ok) {
        elements.status.className = 'cx-status cx-status--alerta';
        elements.status.textContent = errorText(response.data, 'No fue posible cargar la configuración.');
        return;
    }
    render(response.data);
}

/* Formulario de un documento. */

function renderCountries() {
    const term = elements.typeCountrySearch.value.trim().toLocaleLowerCase('es');
    const countries = state.data.paises.filter((country) => state.countries.has(country.iso2) || !term || country.nombre.toLocaleLowerCase('es').includes(term) || country.iso2.toLowerCase() === term);
    elements.typeCountries.innerHTML = countries
        .sort((a, b) => Number(state.countries.has(b.iso2)) - Number(state.countries.has(a.iso2)) || a.nombre.localeCompare(b.nombre, 'es'))
        .slice(0, 60)
        .map((country) => `<label><input type="checkbox" value="${escapeHtml(country.iso2)}"${state.countries.has(country.iso2) ? ' checked' : ''}> <span>${escapeHtml(country.nombre)}</span></label>`).join('')
        || '<p class="cx-form__nota">Ningún país coincide con la búsqueda.</p>';
    const chosen = state.data.paises.filter((country) => state.countries.has(country.iso2)).map((country) => country.nombre);
    elements.typeCountriesChosen.textContent = chosen.length ? `Elegidos: ${chosen.join(', ')}.` : 'Elija al menos un país.';
}

function updateTypeForm() {
    elements.typeCountriesField.hidden = elements.typeRequirement.value !== 'paises';
    if (!elements.typeCountriesField.hidden) renderCountries();
}

function openType(id) {
    const type = id ? state.data.tipos.find((item) => String(item.id) === String(id)) : null;
    state.type = type;
    elements.typeForm.reset();
    elements.typeEyebrow.textContent = type ? (type.sugerido ? 'DOCUMENTO SUGERIDO' : 'DOCUMENTO') : 'NUEVO DOCUMENTO';
    elements.typeTitle.textContent = type ? `Editar: ${type.nombre}` : 'Agregar documento';
    elements.typeName.value = type?.nombre || '';
    elements.typeDescription.value = type?.descripcion || '';
    elements.typeMoment.value = type?.momento || 'antes_salida';
    elements.typeRequirement.value = type?.exigencia || 'siempre';
    elements.typeOperationField.innerHTML = '<option value="">Ninguno</option>'
        + state.data.datos_operacion.map((item) => `<option value="${escapeHtml(item.clave)}">${escapeHtml(item.nombre)}</option>`).join('');
    elements.typeOperationField.value = type?.dato_operacion || '';
    elements.typeOrder.value = type ? String(type.orden) : '';
    elements.typeActive.checked = type ? type.activo : true;
    for (const input of elements.typeForm.querySelectorAll('input[name="transportes"]')) input.checked = !type || !type.transportes.length || type.transportes.includes(input.value);
    for (const input of elements.typeForm.querySelectorAll('input[name="gestiones"]')) input.checked = !type || !type.gestiones.length || type.gestiones.includes(input.value);
    for (const name of ['pide_numero', 'pide_fecha', 'pide_archivo']) elements.typeForm.querySelector(`input[name="${name}"]`).checked = type ? type[name] : name !== 'pide_fecha';
    state.countries = new Set((type?.paises || []).map((country) => country.iso2));
    elements.typeCountrySearch.value = '';
    elements.typeMessage.textContent = '';
    updateTypeForm();
    elements.typeDialog.showModal();
    elements.typeName.focus();
}

function checked(name) {
    return [...elements.typeForm.querySelectorAll(`input[name="${name}"]:checked`)].map((input) => input.value);
}

async function submitType(event) {
    event.preventDefault();
    if (elements.typeSubmit.disabled) return;
    const body = {
        nombre: elements.typeName.value.trim(),
        descripcion: elements.typeDescription.value.trim() || null,
        momento: elements.typeMoment.value,
        exigencia: elements.typeRequirement.value,
        paises: elements.typeRequirement.value === 'paises' ? [...state.countries] : [],
        transportes: checked('transportes'),
        gestiones: checked('gestiones'),
        pide_numero: elements.typeForm.querySelector('input[name="pide_numero"]').checked,
        pide_fecha: elements.typeForm.querySelector('input[name="pide_fecha"]').checked,
        pide_archivo: elements.typeForm.querySelector('input[name="pide_archivo"]').checked,
        dato_operacion: elements.typeOperationField.value || null,
        orden: elements.typeOrder.value === '' ? null : Number(elements.typeOrder.value),
        activo: elements.typeActive.checked,
        ...(state.type ? { version: state.type.version } : {}),
    };
    const problem = body.nombre.length < 3 ? 'Indique el nombre del documento (al menos 3 caracteres).'
        : (!body.transportes.length ? 'Elija al menos una modalidad de transporte.'
            : (!body.gestiones.length ? 'Elija al menos un tipo de cliente.'
                : (body.exigencia === 'paises' && !body.paises.length ? 'Elija al menos un país de destino que exija este documento.' : '')));
    if (problem) {
        elements.typeMessage.textContent = problem;
        return;
    }
    elements.typeSubmit.disabled = true;
    busy(true, 'Guardando el documento…');
    const response = await api(state.type ? `/api/comex/configuracion/tipos/${state.type.id}` : '/api/comex/configuracion/tipos', {
        method: state.type ? 'PUT' : 'POST',
        body: JSON.stringify(body),
    });
    busy(false);
    elements.typeSubmit.disabled = false;
    if (!response.ok) {
        elements.typeMessage.textContent = errorText(response.data, 'No fue posible guardar el documento.');
        if (response.data?.errors?.version) load();
        return;
    }
    elements.typeDialog.close();
    toast(response.data.message);
    load();
}

async function loadSuggested() {
    busy(true, 'Agregando los documentos sugeridos…');
    const response = await api('/api/comex/configuracion/sugeridos', { method: 'POST' });
    busy(false);
    if (!response.ok) {
        toast(errorText(response.data, 'No fue posible agregar los documentos sugeridos.'), true);
        return;
    }
    toast(response.data.message);
    load();
}

/* Gestión de un cliente. */

function updateClientNote() {
    const value = elements.clientForm.querySelector('input[name="gestion"]:checked')?.value;
    elements.clientNote.textContent = MANAGEMENT[value]?.note || '';
}

function openClient(id) {
    const client = state.data.clientes.find((item) => item.cliente_id === id);
    if (!client) return;
    state.client = client;
    elements.clientForm.reset();
    elements.clientTitle.textContent = client.nombre;
    elements.clientForm.querySelector(`input[name="gestion"][value="${client.gestion}"]`).checked = true;
    elements.clientObservation.value = client.observacion || '';
    elements.clientMessage.textContent = '';
    updateClientNote();
    elements.clientDialog.showModal();
}

async function submitClient(event) {
    event.preventDefault();
    const client = state.client;
    if (!client || elements.clientSubmit.disabled) return;
    elements.clientSubmit.disabled = true;
    busy(true, 'Guardando…');
    const response = await api(`/api/comex/configuracion/clientes/${encodeURIComponent(client.cliente_id)}`, {
        method: 'PUT',
        body: JSON.stringify({
            gestion: elements.clientForm.querySelector('input[name="gestion"]:checked')?.value,
            observacion: elements.clientObservation.value.trim() || null,
            version: client.version,
        }),
    });
    busy(false);
    elements.clientSubmit.disabled = false;
    if (!response.ok) {
        elements.clientMessage.textContent = errorText(response.data, 'No fue posible guardar la gestión del cliente.');
        if (response.data?.errors?.version) load();
        return;
    }
    elements.clientDialog.close();
    toast(response.data.message);
    load();
}

function showApp() {
    if (state.identity?.puede_consultar_comex !== true && state.identity?.capacidades?.puede_consultar_comex !== true) return false;
    elements.access.classList.add('is-hidden');
    elements.app.classList.remove('is-hidden');
    const name = state.identity?.nombre || 'Usuario';
    if (elements.userName) elements.userName.textContent = name;
    if (elements.userRole) elements.userRole.textContent = String(state.identity?.rol || '').replaceAll('_', ' ').replace(/^./, (letter) => letter.toUpperCase());
    if (elements.initials) elements.initials.textContent = name.split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0]).join('').toUpperCase();

    return true;
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
        elements.loginError.textContent = 'Su perfil no tiene acceso a Comex.';
        return;
    }
    load();
});
elements.logout?.addEventListener('click', clearSession);
elements.typesActions.addEventListener('click', (event) => {
    if (event.target.closest('[data-settings-new]')) openType(null);
    if (event.target.closest('[data-settings-suggested]')) loadSuggested();
});
elements.types.addEventListener('click', (event) => {
    const edit = event.target.closest('[data-settings-type]');
    if (edit) openType(edit.dataset.settingsType);
});
elements.clientFilters.addEventListener('submit', (event) => event.preventDefault());
elements.clientSearch.addEventListener('input', renderClients);
elements.clientManagement.addEventListener('change', renderClients);
elements.clients.addEventListener('click', (event) => {
    const edit = event.target.closest('[data-settings-client]');
    if (edit) openClient(edit.dataset.settingsClient);
});
elements.typeRequirement.addEventListener('change', updateTypeForm);
elements.typeCountrySearch.addEventListener('input', renderCountries);
elements.typeCountrySearch.addEventListener('keydown', (event) => {
    if (event.key === 'Enter') event.preventDefault();
});
elements.typeCountries.addEventListener('change', (event) => {
    const input = event.target.closest('input[type="checkbox"]');
    if (!input) return;
    if (input.checked) state.countries.add(input.value); else state.countries.delete(input.value);
    renderCountries();
});
elements.typeForm.addEventListener('submit', submitType);
elements.clientForm.addEventListener('change', updateClientNote);
elements.clientForm.addEventListener('submit', submitClient);
for (const dialog of [elements.typeDialog, elements.clientDialog]) {
    dialog.addEventListener('click', (event) => {
        if (event.target === dialog || event.target.closest('[data-cx-close]')) dialog.close();
    });
}

if (state.token && showApp()) load();
