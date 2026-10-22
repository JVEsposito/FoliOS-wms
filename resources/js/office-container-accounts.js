const byId = (id) => document.getElementById(id);
const elements = {
    access: byId('officeAccess'),
    app: byId('officeApp'),
    login: byId('officeLoginForm'),
    loginError: byId('officeLoginError'),
    name: byId('officeUserName'),
    role: byId('officeUserRole'),
    initials: byId('officeInitials'),
    logout: byId('officeLogoutButton'),
    rawMaterialNav: byId('officeRawMaterialNav'),
    reload: byId('reloadButton'),
    filters: byId('filtersForm'),
    confirmed: byId('confirmedCount'),
    reserved: byId('reservedCount'),
    pending: byId('pendingCount'),
    observed: byId('observedCount'),
    sync: byId('syncTime'),
    balances: byId('balanceList'),
    stock: byId('stockList'),
    ownDelivered: byId('ownDeliveredList'),
    pendingList: byId('pendingList'),
    reservations: byId('reservationList'),
    body: byId('movementsBody'),
    movementsNote: byId('movementsNote'),
    movementsExcel: byId('movementsExcelButton'),
    reviewDialog: byId('reviewDialog'),
    reviewForm: byId('reviewForm'),
    reviewError: byId('reviewError'),
    propertyDialog: byId('propertyDialog'),
    propertyForm: byId('propertyForm'),
    propertySummary: byId('propertySummary'),
    propertyError: byId('propertyError'),
    propertyCancel: byId('propertyCancel'),
    quantityDialog: byId('quantityDialog'),
    quantityForm: byId('quantityForm'),
    quantitySummary: byId('quantitySummary'),
    quantityError: byId('quantityError'),
    quantityCancel: byId('quantityCancel'),
    adjustmentNew: byId('accountAdjustmentNew'),
    adjustmentDialog: byId('accountAdjustmentDialog'),
    adjustmentForm: byId('accountAdjustmentForm'),
    adjustmentFields: byId('accountAdjustmentFields'),
    adjustmentTitle: byId('accountAdjustmentTitle'),
    adjustmentPreview: byId('accountAdjustmentPreview'),
    adjustmentError: byId('accountAdjustmentError'),
    adjustmentCancel: byId('accountAdjustmentCancel'),
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
    identity: read(keys.identity),
    catalogs: null,
    movements: [],
    balances: [],
    adjustment: null,
    parameters: new URLSearchParams(),
};
const MOVEMENTS_LIMIT = 300;

function accountOperationId() {
    if (typeof crypto.randomUUID === 'function') return crypto.randomUUID();
    // Las oficinas también se usan por HTTP en la red local de la planta.
    const bytes = crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 15) | 64;
    bytes[8] = (bytes[8] & 63) | 128;
    const hex = [...bytes].map((value) => value.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

function read(key) {
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

function label(value) {
    return String(value || '').replaceAll('_', ' ').replace(/^./, (character) => character.toUpperCase());
}

function formatDate(value) {
    if (!value) return '—';
    return new Intl.DateTimeFormat('es-CL', {
        dateStyle: 'short',
        timeStyle: 'short',
    }).format(new Date(value));
}

function busy(active, text = 'Procesando…') {
    elements.loadingText.textContent = text;
    elements.loading.classList.toggle('is-hidden', !active);
}

function toast(message, error = false) {
    const notification = document.createElement('div');
    notification.className = `toast${error ? ' toast--error' : ''}`;
    notification.textContent = message;
    elements.toasts.append(notification);
    setTimeout(() => notification.remove(), 4000);
}

async function api(path, options = {}) {
    const headers = new Headers(options.headers || {});
    headers.set('Accept', 'application/json');
    if (state.token) headers.set('Authorization', `Bearer ${state.token}`);
    if (options.body) headers.set('Content-Type', 'application/json');
    const response = await fetch(path, { ...options, headers });
    const data = response.status === 204 ? null : await response.json().catch(() => ({}));
    if (!response.ok) {
        throw new Error(
            Object.values(data?.errors || {}).flat()[0]
            || data?.message
            || 'No fue posible completar la operación.',
        );
    }
    return data;
}

async function download(path, fallbackName, errorMessage) {
    const response = await fetch(path, {
        headers: {
            Accept: 'application/pdf, application/vnd.openxmlformats-officedocument.spreadsheetml.sheet, image/*, application/json',
            Authorization: `Bearer ${state.token}`,
        },
    });
    if (!response.ok) {
        const data = await response.json().catch(() => ({}));
        throw new Error(Object.values(data?.errors || {}).flat()[0] || data?.message || errorMessage);
    }
    const disposition = response.headers.get('Content-Disposition') || '';
    const name = disposition.match(/filename="?([^";]+)"?/)?.[1] || fallbackName;
    const url = URL.createObjectURL(await response.blob());
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = name;
    anchor.click();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
}

function downloadGuide(guideId, number) {
    return download(
        `/api/envases/guias-despacho/${guideId}/documento`,
        `guia-envases-${number.toLowerCase()}.pdf`,
        'No fue posible descargar el respaldo de la guía.',
    );
}

/** Temporada y fechas aplicadas: el estado de cuenta es de un cliente, sin los demás filtros. */
function statementPath(clientId, format) {
    const parameters = new URLSearchParams({ cliente_id: clientId, formato: format });
    ['temporada_id', 'desde', 'hasta'].forEach((key) => {
        if (state.parameters.get(key)) parameters.set(key, state.parameters.get(key));
    });
    return `/api/envases/cuenta-corriente/estado-cuenta?${parameters}`;
}

function showOffice() {
    if (state.identity?.puede_consultar_cuenta_envases !== true) return false;
    elements.access.classList.add('is-hidden');
    elements.app.classList.remove('is-hidden');
    elements.name.textContent = state.identity.nombre;
    elements.role.textContent = label(state.identity.rol);
    elements.initials.textContent = state.identity.nombre
        .split(/\s+/)
        .slice(0, 2)
        .map((part) => part[0])
        .join('')
        .toUpperCase();
    elements.rawMaterialNav.classList.toggle(
        'is-hidden',
        state.identity?.puede_consultar_materia_prima !== true,
    );
    elements.adjustmentNew.classList.add('is-hidden');
    return true;
}

function addOptions(select, items, labelFunction) {
    select.insertAdjacentHTML(
        'beforeend',
        items.map((item) => `<option value="${escapeHtml(item.id || item.codigo)}">${escapeHtml(labelFunction(item))}</option>`).join(''),
    );
}

async function loadCatalogs() {
    state.catalogs = await api('/api/envases/cuenta-corriente/catalogos');
    // El perfil guardado en una sesión anterior puede no conocer esta capacidad.
    state.identity.puede_ajustar_cuenta_envases = state.catalogs.puede_ajustar === true;
    elements.adjustmentNew.classList.toggle('is-hidden', !state.identity.puede_ajustar_cuenta_envases);
    addOptions(elements.filters.elements.cliente_id, state.catalogs.clientes, (item) => `${item.codigo} · ${item.nombre}`);
    addOptions(
        elements.filters.elements.temporada_id,
        state.catalogs.temporadas,
        (item) => `${item.codigo} · ${item.nombre}${item.activa ? ' (activa)' : ''}`,
    );
    addOptions(elements.filters.elements.tipo_envase, state.catalogs.tipos_envase, (item) => item.nombre);
    addOptions(elements.adjustmentForm.elements.cliente_id, state.catalogs.clientes, (item) => `${item.codigo} · ${item.nombre}`);
    addOptions(elements.adjustmentForm.elements.tipo_envase, state.catalogs.tipos_envase, (item) => item.nombre);
}

function typeName(code) {
    return state.catalogs?.tipos_envase?.find((item) => item.codigo === code)?.nombre || label(code);
}

function signed(value) {
    return `${value > 0 ? '+' : ''}${value}`;
}

function renderBalances(balances) {
    if (!balances.length) return '<p>Sin saldos de cuenta en la temporada.</p>';
    const clients = new Map();
    balances.forEach((balance) => {
        const client = clients.get(balance.cliente.id) || { cliente: balance.cliente, lines: [] };
        client.lines.push(balance);
        clients.set(balance.cliente.id, client);
    });
    return [...clients.values()].map(({ cliente, lines }) => `<article class="balance-client">
        <header class="balance-client__header"><div><b>${escapeHtml(cliente.nombre)}</b><small>${escapeHtml(cliente.codigo)}</small></div><div class="balance-actions" data-office-action-menu><button type="button" data-statement="${escapeHtml(cliente.id)}" data-format="pdf" data-action-label="Estado de cuenta en PDF">↓ Estado de cuenta PDF</button><button type="button" data-statement="${escapeHtml(cliente.id)}" data-format="xlsx" data-action-label="Estado de cuenta en Excel">↓ Estado de cuenta Excel</button></div></header>
        ${lines.map((line) => `<div class="balance-line"><div><b>${escapeHtml(typeName(line.tipo_envase))}</b><small>${line.saldo < 0 ? 'El cliente debe envases a la planta' : line.saldo > 0 ? 'La planta debe envases al cliente' : 'Cuenta saldada'}</small>${line.propios_entregados ? `<small>${line.propios_entregados} envases propios de la planta entregados en la temporada</small>` : ''}</div><strong class="${line.saldo < 0 ? 'negative' : 'positive'}">${signed(line.saldo)}</strong></div>`).join('')}
    </article>`).join('');
}

function renderStock(stock) {
    elements.stock.innerHTML = stock.tipos.length
        ? stock.tipos.map((type) => `<article class="stock-card">
            <header><b>${escapeHtml(type.nombre)}</b><span>En planta <strong>${type.en_planta.total}</strong></span></header>
            <div class="stock-card__split">
                <div class="stock-card__own"><span>Propios</span><strong>${type.en_planta.propia}</strong></div>
                <div><span>Arrendados</span><strong>${type.en_planta.arrendada}</strong></div>
                <div><span>De clientes</span><strong>${type.en_planta.cliente}</strong></div>
                <div><span>Reservados</span><strong>${type.reservado.total}</strong></div>
                <div><span>Disponibles</span><strong>${type.disponible}</strong></div>
            </div>
            <p>Propios: ${type.propios.ingresados} ingresados · ${type.propios.despachados} entregados a clientes · ${type.propios.en_planta} en planta${type.reservado.propia ? ` (${type.reservado.propia} reservados en borradores)` : ''}</p>
        </article>`).join('')
        : '<p>Sin envases en planta en la temporada.</p>';
    elements.ownDelivered.innerHTML = stock.propios_entregados.length
        ? stock.propios_entregados.map((line) => `<div class="balance-item"><div><b>${escapeHtml(line.cliente.nombre)}</b><small>${escapeHtml(line.cliente.codigo)} · ${escapeHtml(typeName(line.tipo_envase))}</small></div><strong>${line.cantidad}</strong></div>`).join('')
        : '<p>No se han entregado envases propios en la temporada.</p>';
}

function render(data) {
    state.movements = data.data;
    state.balances = data.balances;
    elements.confirmed.textContent = data.resumen.movimientos_confirmados;
    elements.reserved.textContent = data.resumen.envases_reservados;
    elements.pending.textContent = data.resumen.lineas_pendientes_validacion;
    elements.observed.textContent = data.resumen.observados;
    elements.sync.textContent = formatDate(data.resumen.sincronizado_at);
    elements.balances.innerHTML = renderBalances(data.balances);
    renderStock(data.existencia);
    elements.movementsNote.textContent = data.data.length >= MOVEMENTS_LIMIT
        ? `Se muestran los ${MOVEMENTS_LIMIT} más recientes; el Excel trae todos los del filtro.`
        : 'Ingreso y salida conservan fecha y hora exactas.';
    elements.pendingList.innerHTML = data.pendientes.length
        ? data.pendientes.map((pending) => `<article class="pending-item"><div><b>${escapeHtml(pending.numero_recepcion)} · ${escapeHtml(pending.cliente.nombre)}</b><small>${escapeHtml(pending.numero_guia)} · ${formatDate(pending.ingreso_at)}</small></div><strong>${pending.cantidad_declarada} ${escapeHtml(label(pending.tipo_envase))}</strong></article>`).join('')
        : '<p>No hay recepciones en verde.</p>';
    elements.reservations.innerHTML = data.reservas.length
        ? data.reservas.map((reservation) => `<article class="reservation-item"><div><b>${escapeHtml(reservation.numero)} · ${escapeHtml(reservation.cliente.nombre)}</b><small>Salida prevista ${formatDate(reservation.salida_at)} · cuenta corriente aún sin movimiento</small></div><div class="reservation-lines">${reservation.lineas.map((line) => `<span>${line.cantidad} ${label(line.tipo_envase)} · ${label(line.propiedad)}</span>`).join('')}</div><button data-guide-document="${reservation.guia_id}" data-guide-number="${escapeHtml(reservation.numero)}">↓ PDF borrador</button></article>`).join('')
        : '<p>No hay envases reservados en borradores.</p>';
    elements.body.innerHTML = data.data.length
        ? data.data.map((movement) => {
            const guideButton = movement.documento_tipo === 'guia_despacho_envases' && movement.documento_id
                ? `<button class="document-button" data-guide-document="${movement.documento_id}" data-guide-number="${escapeHtml(movement.numero_documento)}">↓ PDF interno</button>`
                : '';
            const emittedButton = movement.guia_emitida?.archivo
                ? `<button class="document-button" data-emitted-guide="${movement.documento_id}" data-emitted-number="${escapeHtml(movement.guia_emitida.numero || movement.numero_documento)}">↓ Guía emitida</button>`
                : '';
            const emittedNote = movement.guia_emitida
                ? (movement.guia_emitida.numero ? ` · Guía N° ${escapeHtml(movement.guia_emitida.numero)}` : ' · sin N° de guía emitida')
                : '';
            const reviewButton = state.identity?.puede_revisar_cuenta_envases
                ? `<button class="review-button" data-review="${movement.id}">Revisar</button>`
                : '';
            const propertyButton = state.identity?.puede_corregir_propiedad_envases && movement.puede_corregir_propiedad
                ? `<button class="property-button" data-correct-property="${escapeHtml(movement.id)}">Corregir propiedad</button>`
                : '';
            const quantityButton = state.identity?.puede_corregir_cantidad_envases && movement.puede_corregir_cantidad
                ? `<button class="property-button" data-correct-quantity="${escapeHtml(movement.id)}">Corregir cantidad</button>`
                : '';
            const reverseButton = state.identity?.puede_ajustar_cuenta_envases && movement.puede_revertir_ajuste
                ? `<button data-reverse-adjustment="${escapeHtml(movement.id)}">Revertir asiento</button>` : '';
            const documentNote = movement.corregido ? ' · propiedad corregida' : movement.cantidad_corregida ? ' · cantidad corregida' : movement.correccion_propiedad ? ' · ajuste de propiedad' : movement.correccion_cantidad ? ' · ajuste de cantidad' : '';
            const correction = movement.correccion_propiedad || movement.correccion_cantidad || movement.ajuste_cuenta;
            const trace = correction
                ? `<br><small>Motivo: ${escapeHtml(correction.motivo)} · ${formatDate(correction.corregido_at || correction.registrado_at)} · ${escapeHtml(movement.creado_por?.nombre || '')}</small>`
                : '';
            const account = movement.impacto_cuenta === 0
                ? '<td class="impact-none" title="No mueve la cuenta de ningún cliente">—</td>'
                : `<td class="${movement.impacto_cuenta < 0 ? 'impact-negative' : 'impact-positive'}">${signed(movement.impacto_cuenta)}</td>`;
            return `<tr><td>${formatDate(movement.ocurrido_at)}</td><td><b>${escapeHtml(movement.cliente?.nombre)}</b><br><small>${escapeHtml(movement.movimiento)} · ${escapeHtml(movement.numero_documento)}${emittedNote}${documentNote}</small>${trace}</td><td>${escapeHtml(typeName(movement.tipo_envase))} · ${movement.cantidad}</td>${account}<td class="impact-stock">${signed(movement.impacto_existencia)}</td><td>${escapeHtml(label(movement.propiedad))}</td><td>${escapeHtml(label(movement.estado_revision))}</td><td><div class="table-actions">${guideButton}${emittedButton}${reviewButton}${propertyButton}${quantityButton}${reverseButton}${!guideButton && !reviewButton && !propertyButton && !quantityButton && !reverseButton ? '—' : ''}</div></td></tr>`;
        }).join('')
        : '<tr><td colspan="8">Aún no hay movimientos confirmados.</td></tr>';
}

async function load() {
    busy(true, 'Actualizando cuenta corriente…');
    try {
        const parameters = new URLSearchParams(new FormData(elements.filters));
        for (const [key, value] of [...parameters]) {
            if (!value) parameters.delete(key);
        }
        render(await api(`/api/envases/cuenta-corriente/movimientos?${parameters}`));
        state.parameters = parameters;
    } catch (error) {
        toast(error.message, true);
    } finally {
        busy(false);
    }
}

function updateAdjustmentPreview() {
    const form = elements.adjustmentForm.elements;
    const origin = state.adjustment?.origin;
    const client = origin?.cliente?.id || form.cliente_id.value;
    const type = origin?.tipo_envase || form.tipo_envase.value;
    const balance = state.balances.find((line) => line.cliente.id === client && line.tipo_envase === type)?.saldo || 0;
    const delta = origin ? -origin.impacto_cuenta : Number(form.cantidad.value);
    elements.adjustmentPreview.textContent = Number.isInteger(delta) && delta !== 0
        ? `Saldo actual de este tipo (todas las propiedades): ${signed(balance)} → ${signed(balance + delta)}. Ajuste: ${signed(delta)} envases.`
        : 'Indique una cantidad entera distinta de cero.';
}

function openAdjustment(origin = null) {
    const season = state.catalogs.temporadas.find((item) => item.activa);
    const selected = elements.filters.elements.temporada_id.value;
    if (!season || (selected && selected !== season.id)) {
        toast('Seleccione la temporada activa para registrar un asiento.', true);
        return;
    }
    elements.adjustmentForm.reset();
    elements.adjustmentFields.disabled = Boolean(origin);
    elements.adjustmentFields.hidden = Boolean(origin);
    elements.adjustmentTitle.textContent = origin ? `Revertir ${origin.numero_documento}` : 'Registrar apertura o ajuste';
    elements.adjustmentForm.elements.cliente_id.value = elements.filters.elements.cliente_id.value;
    elements.adjustmentForm.elements.fecha.value = state.catalogs.hoy;
    elements.adjustmentForm.elements.fecha.max = state.catalogs.hoy;
    elements.adjustmentForm.elements.fecha.min = origin?.ocurrido_at?.slice(0, 10) || '';
    elements.adjustmentError.textContent = '';
    state.adjustment = { origin, operacion_id: accountOperationId(), temporada_id: season.id };
    updateAdjustmentPreview();
    elements.adjustmentDialog.showModal();
}

elements.adjustmentNew.addEventListener('click', () => openAdjustment());
elements.adjustmentCancel.addEventListener('click', () => elements.adjustmentDialog.close());
elements.adjustmentForm.addEventListener('input', updateAdjustmentPreview);
elements.adjustmentForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const submit = elements.adjustmentForm.querySelector('[type="submit"]');
    const { origin, operacion_id, temporada_id } = state.adjustment;
    const fields = Object.fromEntries(new FormData(elements.adjustmentForm));
    const body = origin
        ? { operacion_id, fecha: fields.fecha, motivo: fields.motivo }
        : { ...fields, operacion_id, temporada_id, cantidad: Number(fields.cantidad) };
    submit.disabled = true;
    elements.adjustmentError.textContent = '';
    try {
        await api(origin ? `/api/envases/cuenta-corriente/movimientos/${origin.id}/revertir-ajuste` : '/api/envases/cuenta-corriente/ajustes', {
            method: 'POST', body: JSON.stringify(body),
        });
        elements.adjustmentDialog.close();
        toast('Asiento registrado en la cuenta de envases.');
        await load();
    } catch (error) {
        elements.adjustmentError.textContent = error.message;
    } finally {
        submit.disabled = false;
    }
});

elements.login.addEventListener('submit', async (event) => {
    event.preventDefault();
    elements.loginError.textContent = '';
    try {
        const data = await api('/api/acceso-oficina', {
            method: 'POST',
            body: JSON.stringify(Object.fromEntries(new FormData(elements.login))),
        });
        state.token = data.token;
        state.identity = data.usuario;
        localStorage.setItem(keys.token, data.token);
        localStorage.setItem(keys.identity, JSON.stringify(data.usuario));
        if (!showOffice()) throw new Error('Este usuario no tiene acceso a cuenta corriente de envases.');
        await loadCatalogs();
        await load();
    } catch (error) {
        elements.loginError.textContent = error.message;
    }
});

elements.logout.addEventListener('click', () => {
    localStorage.removeItem(keys.token);
    localStorage.removeItem(keys.identity);
    location.reload();
});
elements.reload.addEventListener('click', load);
elements.movementsExcel.addEventListener('click', async () => {
    busy(true, 'Generando Excel de movimientos…');
    try {
        await download(
            `/api/envases/cuenta-corriente/movimientos/excel?${state.parameters}`,
            'cuenta-envases.xlsx',
            'No fue posible generar el Excel de movimientos.',
        );
    } catch (error) {
        toast(error.message, true);
    } finally {
        busy(false);
    }
});
elements.filters.addEventListener('submit', (event) => {
    event.preventDefault();
    load();
});
document.addEventListener('click', async (event) => {
    const reverseButton = event.target.closest('[data-reverse-adjustment]');
    if (reverseButton) {
        const origin = state.movements.find((item) => item.id === reverseButton.dataset.reverseAdjustment);
        if (origin) openAdjustment(origin);
        return;
    }
    const reviewButton = event.target.closest('[data-review]');
    const propertyButton = event.target.closest('[data-correct-property]');
    const quantityButton = event.target.closest('[data-correct-quantity]');
    const documentButton = event.target.closest('[data-guide-document]');
    const statementButton = event.target.closest('[data-statement]');
    const emittedButton = event.target.closest('[data-emitted-guide]');
    if (emittedButton) {
        try {
            await download(
                `/api/envases/guias-despacho/${emittedButton.dataset.emittedGuide}/archivo-guia`,
                `guia-despacho-${emittedButton.dataset.emittedNumber.toLowerCase()}`,
                'No fue posible descargar la guía emitida.',
            );
        } catch (error) {
            toast(error.message, true);
        }
    }
    if (statementButton) {
        const format = statementButton.dataset.format;
        busy(true, 'Generando estado de cuenta…');
        try {
            await download(
                statementPath(statementButton.dataset.statement, format),
                `estado-cuenta-envases.${format}`,
                'No fue posible generar el estado de cuenta.',
            );
        } catch (error) {
            toast(error.message, true);
        } finally {
            busy(false);
        }
    }
    if (reviewButton) {
        elements.reviewForm.reset();
        elements.reviewForm.elements.movimiento_id.value = reviewButton.dataset.review;
        elements.reviewError.textContent = '';
        elements.reviewDialog.showModal();
    }
    if (propertyButton) {
        const movement = state.movements.find((item) => item.id === propertyButton.dataset.correctProperty);
        if (!movement) return;
        elements.propertyForm.reset();
        elements.propertyForm.elements.movimiento_id.value = movement.id;
        elements.propertyForm.elements.concepto_envases.value = movement.propiedad === 'propia' ? 'arriendo' : 'compra';
        updatePropertySummary(movement);
        elements.propertyError.textContent = '';
        elements.propertyDialog.showModal();
    }
    if (quantityButton) {
        const movement = state.movements.find((item) => item.id === quantityButton.dataset.correctQuantity);
        if (!movement) return;
        elements.quantityForm.reset();
        elements.quantityForm.elements.movimiento_id.value = movement.id;
        elements.quantityForm.elements.cantidad_correcta.max = movement.cantidad - 1;
        elements.quantityError.textContent = '';
        updateQuantitySummary(movement);
        elements.quantityDialog.showModal();
    }
    if (documentButton) {
        try {
            await downloadGuide(
                documentButton.dataset.guideDocument,
                documentButton.dataset.guideNumber,
            );
        } catch (error) {
            toast(error.message, true);
        }
    }
});
function updatePropertySummary(movement) {
    const target = elements.propertyForm.elements.concepto_envases.value === 'arriendo' ? 'Arrendada' : 'Propia';
    elements.propertySummary.textContent = `${movement.numero_documento} · ${movement.cliente?.nombre || ''} · ${label(movement.propiedad)} → ${target}`;
}
elements.propertyForm.elements.concepto_envases.addEventListener('change', () => {
    const movement = state.movements.find((item) => item.id === elements.propertyForm.elements.movimiento_id.value);
    if (movement) updatePropertySummary(movement);
});
elements.propertyCancel.addEventListener('click', () => elements.propertyDialog.close());
function updateQuantitySummary(movement) {
    const next = Number(elements.quantityForm.elements.cantidad_correcta.value);
    const difference = Number.isInteger(next) && next > 0 && next < movement.cantidad
        ? ` → ${next} (ajuste −${movement.cantidad - next})` : ' → …';
    elements.quantitySummary.textContent = `${movement.numero_documento} · ${movement.cliente?.nombre || ''} · ${label(movement.tipo_envase)} ${movement.cantidad}${difference}`;
}
elements.quantityForm.elements.cantidad_correcta.addEventListener('input', () => {
    const movement = state.movements.find((item) => item.id === elements.quantityForm.elements.movimiento_id.value);
    if (movement) updateQuantitySummary(movement);
});
elements.quantityCancel.addEventListener('click', () => elements.quantityDialog.close());
elements.quantityForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const data = Object.fromEntries(new FormData(elements.quantityForm));
    try {
        await api(`/api/envases/cuenta-corriente/movimientos/${data.movimiento_id}/corregir-cantidad`, {
            method: 'POST',
            body: JSON.stringify({ cantidad_correcta: Number(data.cantidad_correcta), motivo: data.motivo }),
        });
        elements.quantityDialog.close();
        toast('Cantidad corregida. El ajuste quedó registrado.');
        await load();
    } catch (error) {
        elements.quantityError.textContent = error.message;
    }
});
elements.propertyForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const data = Object.fromEntries(new FormData(elements.propertyForm));
    try {
        await api(`/api/envases/cuenta-corriente/movimientos/${data.movimiento_id}/corregir-propiedad`, {
            method: 'POST',
            body: JSON.stringify({ concepto_envases: data.concepto_envases, motivo: data.motivo }),
        });
        elements.propertyDialog.close();
        toast('Propiedad corregida. Los ajustes quedaron registrados.');
        await load();
    } catch (error) {
        elements.propertyError.textContent = error.message;
    }
});
elements.reviewForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (event.submitter?.value === 'cancel') {
        elements.reviewDialog.close();
        return;
    }
    const data = Object.fromEntries(new FormData(elements.reviewForm));
    try {
        await api(`/api/envases/cuenta-corriente/movimientos/${data.movimiento_id}/revisar`, {
            method: 'POST',
            body: JSON.stringify({ estado: data.estado, nota: data.nota }),
        });
        elements.reviewDialog.close();
        toast('Chequeo registrado.');
        await load();
    } catch (error) {
        elements.reviewError.textContent = error.message;
    }
});

if (state.token && showOffice()) {
    busy(true, 'Cargando cuenta corriente…');
    loadCatalogs().then(load).catch((error) => {
        toast(error.message, true);
        busy(false);
    });
}
