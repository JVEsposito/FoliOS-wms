import {
    CURRENCY_PRESETS,
    TARIFF_EVENTS,
    TARIFF_STATES,
    eventLines,
    formatDay,
    formatPrice,
    parsePriceInput,
    summaryText,
    unitsFor,
    validityText,
} from './shared/finance-tariffs-logic';

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
    newPrice: byId('tariffNewButton'),
    status: byId('tariffStatus'),
    filters: byId('tariffFilters'),
    client: byId('tariffClient'),
    service: byId('tariffService'),
    effective: byId('tariffEffective'),
    effectiveTitle: byId('tariffEffectiveTitle'),
    effectiveHelp: byId('tariffEffectiveHelp'),
    effectiveList: byId('tariffEffectiveList'),
    pricesBody: byId('tariffPricesBody'),
    pricesCount: byId('tariffPricesCount'),
    newCurrency: byId('tariffNewCurrency'),
    presets: byId('tariffCurrencyPresets'),
    currenciesBody: byId('tariffCurrenciesBody'),
    currenciesCount: byId('tariffCurrenciesCount'),
    dialog: byId('tariffDialog'),
    form: byId('tariffForm'),
    dialogEyebrow: byId('tariffDialogEyebrow'),
    dialogTitle: byId('tariffDialogTitle'),
    formClient: byId('tariffFormClient'),
    formService: byId('tariffFormService'),
    formUnit: byId('tariffFormUnit'),
    formCurrency: byId('tariffFormCurrency'),
    formPrice: byId('tariffFormPrice'),
    formFrom: byId('tariffFormFrom'),
    formTo: byId('tariffFormTo'),
    formNote: byId('tariffFormNote'),
    formReplace: byId('tariffFormReplace'),
    formReplaceRow: byId('tariffFormReplaceRow'),
    formPreview: byId('tariffFormPreview'),
    formMessage: byId('tariffFormMessage'),
    formSubmit: byId('tariffFormSubmit'),
    annulDialog: byId('tariffAnnulDialog'),
    annulForm: byId('tariffAnnulForm'),
    annulSummary: byId('tariffAnnulSummary'),
    annulMessage: byId('tariffAnnulMessage'),
    history: byId('tariffHistory'),
    historyEyebrow: byId('tariffHistoryEyebrow'),
    historyTitle: byId('tariffHistoryTitle'),
    historyList: byId('tariffHistoryList'),
    currencyDialog: byId('currencyDialog'),
    currencyForm: byId('currencyForm'),
    currencyTitle: byId('currencyDialogTitle'),
    currencyActiveRow: byId('currencyActiveRow'),
    currencyMessage: byId('currencyFormMessage'),
    loading: byId('officeLoading'),
    loadingText: byId('officeLoadingText'),
    toasts: byId('officeToasts'),
};
const keys = { token: 'estiba_wms_office_token', identity: 'estiba_wms_office_identity' };
const state = {
    token: localStorage.getItem(keys.token),
    identity: readJson(keys.identity),
    data: null,
    editing: null,
    annulling: null,
    editingCurrency: null,
};

function readJson(key) {
    try { return JSON.parse(localStorage.getItem(key) || 'null'); } catch { return null; }
}

function escapeHtml(value) {
    return String(value ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&#039;');
}

function formatDateTime(value) {
    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? '—' : new Intl.DateTimeFormat('es-CL', { dateStyle: 'short', timeStyle: 'short' }).format(date);
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

function canManage() {
    return state.data?.puede_gestionar === true;
}

function query() {
    const params = new URLSearchParams();
    for (const [key, value] of new FormData(elements.filters)) {
        if (String(value).trim() !== '') params.set(key, String(value).trim());
    }

    return params.toString();
}

function fillSelect(select, options, selected) {
    select.innerHTML = options.map((option) => (option.group
        ? `<optgroup label="${escapeHtml(option.group)}">${option.options.map((item) => `<option value="${escapeHtml(item.value)}">${escapeHtml(item.label)}</option>`).join('')}</optgroup>`
        : `<option value="${escapeHtml(option.value)}">${escapeHtml(option.label)}</option>`)).join('');
    const values = [...select.options].map((option) => option.value);
    if (selected !== undefined && selected !== null && values.includes(String(selected))) select.value = String(selected);
}

function renderStatus(data) {
    const currencies = data.monedas || [];
    if (!currencies.some((currency) => currency.activa)) {
        elements.status.className = 'fin-status fin-status--warning';
        elements.status.innerHTML = '<strong>Todavía no hay monedas activas.</strong>'
            + `<small>${canManage() ? 'Agréguelas en la pestaña Monedas (CLP, UF, USD u otra) antes de cargar precios.' : 'Administración debe agregarlas antes de cargar precios.'}</small>`;
        return;
    }
    const summary = data.resumen || {};
    if (!summary.vigentes && !summary.futuras) {
        elements.status.className = 'fin-status fin-status--warning';
        elements.status.innerHTML = '<strong>Todavía no hay precios vigentes ni programados.</strong>'
            + '<small>Conviene partir por la tarifa general y agregar precios propios solo para los clientes con condiciones distintas.</small>';
        return;
    }
    elements.status.className = 'fin-status';
    elements.status.innerHTML = `<strong>${escapeHtml(summaryText(summary, (data.servicios || []).length))}</strong>`
        + `<small>Hoy ${escapeHtml(formatDay(data.hoy))}. La prefactura aplicará estos precios a los hechos valorizables; el almacenaje se cobra por día o fracción, sin días libres.</small>`;
}

function renderFilters(data) {
    const withPrice = data.clientes.filter((client) => client.con_precio);
    const withoutPrice = data.clientes.filter((client) => !client.con_precio);
    const label = (client) => `${client.nombre}${client.activo ? '' : ' (inactivo)'}`;
    const current = elements.client.value;
    fillSelect(elements.client, [
        { value: '', label: 'Todos' },
        { value: 'general', label: 'Tarifa general' },
        ...(withPrice.length ? [{ group: 'Con precio propio', options: withPrice.map((client) => ({ value: client.id, label: label(client) })) }] : []),
        ...(withoutPrice.length ? [{ group: 'Sin precio propio', options: withoutPrice.map((client) => ({ value: client.id, label: label(client) })) }] : []),
    ], current);
    fillSelect(elements.service, [
        { value: '', label: 'Todos los servicios' },
        ...data.servicios.map((service) => ({ value: service.clave, label: service.nombre })),
    ], elements.service.value);
}

function priceRow(tariff) {
    const annulled = tariff.estado === 'anulada';
    const manage = canManage() && !annulled;
    const actions = `<button class="fin-boton" type="button" data-tariff-history="${tariff.id}">Historial</button>`
        + (manage ? `<button class="fin-boton" type="button" data-tariff-edit="${tariff.id}">Editar</button>` : '')
        + (manage ? `<button class="fin-boton fin-boton--anular" type="button" data-tariff-annul="${tariff.id}">Anular</button>` : '');

    // Las clases de celda ordenan la fila como tarjeta en celular.
    return `<tr class="${annulled ? 'is-annulled' : ''}">`
        + `<td class="fin-c-cliente"><strong>${escapeHtml(tariff.general ? 'Tarifa general' : tariff.cliente_nombre)}</strong></td>`
        + `<td class="fin-c-servicio">${escapeHtml(tariff.servicio_nombre)}${tariff.nota ? `<small>${escapeHtml(tariff.nota)}</small>` : ''}</td>`
        + `<td class="fin-num fin-c-precio"><strong>${escapeHtml(formatPrice(tariff))}</strong><small>${escapeHtml(tariff.moneda)} · ${escapeHtml(tariff.unidad_nombre)}</small></td>`
        + `<td class="fin-c-vigencia">${escapeHtml(validityText(tariff.vigente_desde, tariff.vigente_hasta))}${annulled && tariff.motivo_anulacion ? `<small>${escapeHtml(tariff.motivo_anulacion)}</small>` : ''}</td>`
        + `<td class="fin-c-estado"><span class="fin-chip fin-chip--${escapeHtml(tariff.estado)}">${escapeHtml(TARIFF_STATES[tariff.estado] || tariff.estado)}</span></td>`
        + `<td class="fin-c-acciones"><div class="fin-acciones">${actions}</div></td>`
        + '</tr>';
}

function renderPrices(data) {
    elements.pricesCount.textContent = String(data.data.length);
    if (!data.data.length) {
        const filtered = query() !== '';
        elements.pricesBody.innerHTML = `<tr class="fin-empty"><td colspan="6">${filtered
            ? 'No hay precios con estos filtros.'
            : (canManage() ? 'Todavía no hay precios. Use «+ Nuevo precio» para cargar la tarifa general.' : 'Todavía no hay precios.')}</td></tr>`;
        return;
    }
    elements.pricesBody.innerHTML = data.data.map(priceRow).join('');
}

function renderCurrencies(data) {
    const currencies = data.monedas || [];
    elements.currenciesCount.textContent = String(currencies.filter((currency) => currency.activa).length);
    elements.currenciesBody.innerHTML = currencies.length
        ? currencies.map((currency) => `<tr class="${currency.activa ? '' : 'is-inactiva'}">`
            + `<td><strong>${escapeHtml(currency.codigo)}</strong></td>`
            + `<td>${escapeHtml(currency.nombre)}</td>`
            + `<td>${escapeHtml(currency.simbolo)}</td>`
            + `<td class="fin-num">${escapeHtml(currency.decimales)}</td>`
            + `<td><span class="fin-chip fin-chip--${currency.activa ? 'vigente' : 'vencida'}">${currency.activa ? 'Activa' : 'Inactiva'}</span></td>`
            + `<td>${canManage() ? `<div class="fin-acciones"><button class="fin-boton" type="button" data-currency-edit="${escapeHtml(currency.codigo)}">Editar</button></div>` : ''}</td>`
            + '</tr>').join('')
        : '<tr class="fin-empty"><td colspan="6">Todavía no hay monedas.</td></tr>';

    const missing = CURRENCY_PRESETS.filter((preset) => !currencies.some((currency) => currency.codigo === preset.codigo));
    elements.presets.hidden = !canManage() || !missing.length;
    elements.presets.innerHTML = '<span>Agregar con un clic:</span>'
        + missing.map((preset) => `<button class="fin-boton" type="button" data-currency-preset="${escapeHtml(preset.codigo)}">+ ${escapeHtml(preset.nombre)} (${escapeHtml(preset.codigo)})</button>`).join('');
}

function render() {
    const data = state.data;
    elements.newPrice.hidden = !canManage();
    elements.newCurrency.hidden = !canManage();
    renderStatus(data);
    renderFilters(data);
    renderPrices(data);
    renderCurrencies(data);
}

async function loadEffective() {
    const client = elements.client.value;
    if (!client) {
        elements.effective.hidden = true;
        return;
    }
    const response = await api(`/api/finanzas/tarifas/efectiva?cliente=${encodeURIComponent(client)}`);
    if (!response.ok || client !== elements.client.value) return;

    const data = response.data;
    elements.effective.hidden = false;
    elements.effectiveTitle.textContent = data.cliente ? `Lo que se le cobra hoy a ${data.cliente.nombre}` : 'Tarifa general vigente hoy';
    elements.effectiveHelp.textContent = data.cliente
        ? 'En cada servicio, su precio propio si lo tiene; si no, la tarifa general.'
        : 'Rige en cada servicio para los clientes sin precio propio.';
    elements.effectiveList.innerHTML = data.servicios.map((service) => {
        const prices = service.precios.map((tariff) => `${formatPrice(tariff)} ${tariff.unidad_nombre}`).join(' · ');
        const origin = service.origen === 'propio'
            ? '<em class="fin-origen fin-origen--propio">Precio propio</em>'
            : (service.origen === 'general' ? '<em class="fin-origen">Tarifa general</em>' : '<em class="fin-origen">Sin precio</em>');

        return `<li class="${service.origen ? '' : 'is-empty'}"><span>${escapeHtml(service.nombre)}</span><strong>${escapeHtml(prices || '—')}</strong>${origin}</li>`;
    }).join('');
}

async function load({ quiet = false } = {}) {
    if (!quiet) busy(true, 'Cargando el tarifario…');
    try {
        const response = await api(`/api/finanzas/tarifas?${query()}`);
        if (!response.ok) {
            toast(errorText(response.data, 'No fue posible cargar el tarifario.'), true);
            return;
        }
        state.data = response.data;
        render();
    } finally {
        if (!quiet) busy(false);
    }
    await loadEffective();
}

function tariffById(id) {
    return state.data?.data.find((tariff) => String(tariff.id) === String(id)) || null;
}

function syncUnits(selected) {
    const units = unitsFor(state.data?.servicios, elements.formService.value);
    fillSelect(elements.formUnit, units.map((unit) => ({ value: unit.clave, label: unit.nombre })), selected || units[0]?.clave);
}

function updatePreview() {
    const text = elements.formPrice.value.trim();
    elements.formPreview.classList.remove('is-invalid');
    if (!text) {
        elements.formPreview.textContent = '';
        return;
    }
    const parsed = parsePriceInput(text);
    const currency = (state.data?.monedas || []).find((item) => item.codigo === elements.formCurrency.value);
    const unit = elements.formUnit.selectedOptions[0]?.textContent || '';
    elements.formPreview.classList.toggle('is-invalid', !parsed.ok);
    elements.formPreview.textContent = parsed.ok
        ? `Se guardará: ${formatPrice({ moneda: currency?.codigo, moneda_simbolo: currency?.simbolo, precio: parsed.value })} ${unit}.`
        : parsed.message;
}

function openPriceForm(tariff = null) {
    const data = state.data;
    const currencies = (data?.monedas || []).filter((currency) => currency.activa || currency.codigo === tariff?.moneda);
    if (!currencies.length) {
        toast('Primero agregue una moneda en la pestaña Monedas.', true);
        byId('finance-tariffs-tab-currencies')?.click();
        return;
    }
    state.editing = tariff;
    elements.form.reset();
    elements.formMessage.textContent = '';
    elements.dialogTitle.textContent = tariff ? 'Editar precio' : 'Nuevo precio';
    elements.dialogEyebrow.textContent = tariff ? `${tariff.cliente_nombre} · ${tariff.servicio_nombre}`.toUpperCase() : 'PRECIO';

    const filterClient = elements.client.value && elements.client.value !== 'general' ? elements.client.value : '';
    fillSelect(elements.formClient, [
        { value: '', label: 'Tarifa general' },
        ...data.clientes
            .filter((client) => client.activo || client.id === tariff?.cliente_id)
            .map((client) => ({ value: client.id, label: client.nombre })),
    ], tariff ? (tariff.cliente_id || '') : filterClient);
    fillSelect(
        elements.formService,
        data.servicios.map((service) => ({ value: service.clave, label: service.nombre })),
        tariff?.servicio || elements.service.value || data.servicios[0]?.clave,
    );
    syncUnits(tariff?.unidad);
    fillSelect(elements.formCurrency, currencies.map((currency) => ({ value: currency.codigo, label: `${currency.codigo} · ${currency.nombre}` })), tariff?.moneda || currencies[0].codigo);
    elements.formPrice.value = tariff ? tariff.precio.replace('.', ',') : '';
    elements.formFrom.value = tariff?.vigente_desde || data.hoy;
    elements.formTo.value = tariff?.vigente_hasta || '';
    elements.formNote.value = tariff?.nota || '';
    for (const field of [elements.formClient, elements.formService, elements.formUnit]) field.disabled = Boolean(tariff);
    elements.formReplaceRow.hidden = Boolean(tariff);
    elements.formReplace.checked = true;
    elements.formSubmit.textContent = tariff ? 'Guardar cambios' : 'Guardar precio';
    updatePreview();
    elements.dialog.showModal();
    (tariff ? elements.formPrice : elements.formClient).focus();
}

async function submitPrice(event) {
    event.preventDefault();
    elements.formMessage.textContent = '';
    const parsed = parsePriceInput(elements.formPrice.value);
    if (!parsed.ok) {
        elements.formMessage.textContent = parsed.message;
        elements.formPrice.focus();
        return;
    }
    if (!elements.formFrom.value) {
        elements.formMessage.textContent = 'Indique desde qué fecha rige el precio.';
        elements.formFrom.focus();
        return;
    }
    const editing = state.editing;
    const body = {
        moneda: elements.formCurrency.value,
        precio: parsed.value,
        vigente_desde: elements.formFrom.value,
        vigente_hasta: elements.formTo.value || null,
        nota: elements.formNote.value.trim() || null,
    };
    if (!editing) {
        Object.assign(body, {
            cliente_id: elements.formClient.value || null,
            servicio: elements.formService.value,
            unidad: elements.formUnit.value,
            cerrar_anterior: elements.formReplace.checked,
        });
    }
    elements.formSubmit.disabled = true;
    try {
        const response = await api(editing ? `/api/finanzas/tarifas/${editing.id}` : '/api/finanzas/tarifas', {
            method: editing ? 'PUT' : 'POST',
            body: JSON.stringify(body),
        });
        if (!response.ok) {
            elements.formMessage.textContent = errorText(response.data, 'No fue posible guardar el precio.');
            return;
        }
        elements.dialog.close();
        state.editing = null;
        toast(editing ? 'Precio actualizado.' : 'Precio guardado.');
        await load({ quiet: true });
    } finally {
        elements.formSubmit.disabled = false;
    }
}

function openAnnul(tariff) {
    state.annulling = tariff;
    elements.annulForm.reset();
    elements.annulMessage.textContent = '';
    elements.annulSummary.textContent = `${tariff.cliente_nombre} · ${tariff.servicio_nombre} ${tariff.unidad_nombre} · ${formatPrice(tariff)} · ${validityText(tariff.vigente_desde, tariff.vigente_hasta)}`;
    elements.annulDialog.showModal();
    elements.annulForm.elements.motivo.focus();
}

async function submitAnnul(event) {
    event.preventDefault();
    const reason = elements.annulForm.elements.motivo.value.trim();
    if (reason.length < 5) {
        elements.annulMessage.textContent = 'Indique un motivo de al menos 5 caracteres.';
        return;
    }
    const button = elements.annulForm.querySelector('[type="submit"]');
    button.disabled = true;
    try {
        const response = await api(`/api/finanzas/tarifas/${state.annulling.id}/anular`, { method: 'POST', body: JSON.stringify({ motivo: reason }) });
        if (!response.ok) {
            elements.annulMessage.textContent = errorText(response.data, 'No fue posible anular el precio.');
            return;
        }
        elements.annulDialog.close();
        state.annulling = null;
        toast('Precio anulado.');
        await load({ quiet: true });
    } finally {
        button.disabled = false;
    }
}

async function openHistory(id) {
    const tariff = tariffById(id);
    elements.historyEyebrow.textContent = tariff ? `${tariff.cliente_nombre} · ${tariff.servicio_nombre}`.toUpperCase() : 'HISTORIAL';
    elements.historyTitle.textContent = tariff ? `${formatPrice(tariff)} ${tariff.unidad_nombre}` : 'Historial del precio';
    elements.historyList.innerHTML = '<li>Cargando…</li>';
    elements.history.showModal();
    const response = await api(`/api/finanzas/tarifas/${id}`);
    if (!response.ok) {
        elements.historyList.innerHTML = `<li>${escapeHtml(errorText(response.data, 'No fue posible cargar el historial.'))}</li>`;
        return;
    }
    const events = response.data.eventos || [];
    elements.historyList.innerHTML = events.length
        ? events.map((event, index) => `<li class="${index === 0 ? 'is-current' : ''}">`
            + `<div><strong>${escapeHtml(TARIFF_EVENTS[event.accion] || event.accion)}</strong>`
            + `<small>${escapeHtml(formatDateTime(event.fecha))}${event.usuario ? ` · ${escapeHtml(event.usuario)}` : ''}</small></div>`
            + (event.motivo ? `<p>${escapeHtml(event.motivo)}</p>` : '')
            + `<ul>${eventLines(event).map((line) => `<li>${escapeHtml(line)}</li>`).join('')}</ul>`
            + '</li>').join('')
        : '<li>Sin historial.</li>';
}

function openCurrency(currency = null, preset = null) {
    const form = elements.currencyForm;
    const values = currency || preset || { codigo: '', nombre: '', simbolo: '', decimales: 0 };
    state.editingCurrency = currency;
    form.reset();
    elements.currencyMessage.textContent = '';
    form.elements.codigo.value = values.codigo;
    form.elements.codigo.disabled = Boolean(currency);
    form.elements.nombre.value = values.nombre;
    form.elements.simbolo.value = values.simbolo;
    form.elements.decimales.value = String(values.decimales);
    form.elements.activa.checked = currency ? currency.activa : true;
    elements.currencyActiveRow.hidden = !currency;
    elements.currencyTitle.textContent = currency ? `Editar ${currency.codigo}` : 'Nueva moneda';
    elements.currencyDialog.showModal();
    (currency || preset ? form.elements.nombre : form.elements.codigo).focus();
}

async function submitCurrency(event) {
    event.preventDefault();
    const form = elements.currencyForm;
    const editing = state.editingCurrency;
    const decimals = form.elements.decimales.value;
    const body = {
        nombre: form.elements.nombre.value.trim(),
        simbolo: form.elements.simbolo.value.trim(),
        decimales: decimals === '' ? null : Number(decimals),
    };
    if (editing) body.activa = form.elements.activa.checked;
    else body.codigo = form.elements.codigo.value.trim();
    const button = form.querySelector('[type="submit"]');
    button.disabled = true;
    try {
        const response = await api(editing ? `/api/finanzas/monedas/${encodeURIComponent(editing.codigo)}` : '/api/finanzas/monedas', {
            method: editing ? 'PUT' : 'POST',
            body: JSON.stringify(body),
        });
        if (!response.ok) {
            elements.currencyMessage.textContent = errorText(response.data, 'No fue posible guardar la moneda.');
            return;
        }
        elements.currencyDialog.close();
        state.editingCurrency = null;
        toast('Moneda guardada.');
        await load({ quiet: true });
    } finally {
        button.disabled = false;
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
    load();
});
elements.logout?.addEventListener('click', clearSession);
elements.filters.addEventListener('change', () => load());
elements.filters.addEventListener('submit', (event) => {
    event.preventDefault();
    load();
});
elements.newPrice.addEventListener('click', () => openPriceForm());
elements.pricesBody.addEventListener('click', (event) => {
    const history = event.target.closest('[data-tariff-history]');
    const edit = event.target.closest('[data-tariff-edit]');
    const annul = event.target.closest('[data-tariff-annul]');
    if (history) openHistory(history.dataset.tariffHistory);
    if (edit && tariffById(edit.dataset.tariffEdit)) openPriceForm(tariffById(edit.dataset.tariffEdit));
    if (annul && tariffById(annul.dataset.tariffAnnul)) openAnnul(tariffById(annul.dataset.tariffAnnul));
});
elements.formService.addEventListener('change', () => {
    syncUnits();
    updatePreview();
});
for (const field of [elements.formUnit, elements.formCurrency]) field.addEventListener('change', updatePreview);
elements.formPrice.addEventListener('input', updatePreview);
elements.form.addEventListener('submit', submitPrice);
elements.annulForm.addEventListener('submit', submitAnnul);
elements.newCurrency.addEventListener('click', () => openCurrency());
elements.presets.addEventListener('click', (event) => {
    const button = event.target.closest('[data-currency-preset]');
    const preset = CURRENCY_PRESETS.find((item) => item.codigo === button?.dataset.currencyPreset);
    if (preset) openCurrency(null, preset);
});
elements.currenciesBody.addEventListener('click', (event) => {
    const button = event.target.closest('[data-currency-edit]');
    const currency = (state.data?.monedas || []).find((item) => item.codigo === button?.dataset.currencyEdit);
    if (currency) openCurrency(currency);
});
elements.currencyForm.addEventListener('submit', submitCurrency);
for (const dialog of [elements.dialog, elements.annulDialog, elements.history, elements.currencyDialog]) {
    dialog.addEventListener('click', (event) => {
        // Los formularios solo se cierran con sus botones; el historial, también tocando fuera.
        if (event.target.closest('[data-fin-close]') || (dialog === elements.history && event.target === dialog)) dialog.close();
    });
}

if (state.token && showApp()) load();
