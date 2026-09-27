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
    pendingList: byId('pendingList'),
    reservations: byId('reservationList'),
    body: byId('movementsBody'),
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
};

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

async function downloadGuide(guideId, number) {
    const response = await fetch(`/api/envases/guias-despacho/${guideId}/documento`, {
        headers: {
            Accept: 'application/pdf',
            Authorization: `Bearer ${state.token}`,
        },
    });
    if (!response.ok) throw new Error('No fue posible descargar el respaldo de la guía.');
    const url = URL.createObjectURL(await response.blob());
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = `guia-envases-${number.toLowerCase()}.pdf`;
    anchor.click();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
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
    addOptions(elements.filters.elements.cliente_id, state.catalogs.clientes, (item) => `${item.codigo} · ${item.nombre}`);
    addOptions(
        elements.filters.elements.temporada_id,
        state.catalogs.temporadas,
        (item) => `${item.codigo} · ${item.nombre}${item.activa ? ' (activa)' : ''}`,
    );
    addOptions(elements.filters.elements.tipo_envase, state.catalogs.tipos_envase, (item) => item.nombre);
}

function render(data) {
    state.movements = data.data;
    elements.confirmed.textContent = data.resumen.movimientos_confirmados;
    elements.reserved.textContent = data.resumen.envases_reservados;
    elements.pending.textContent = data.resumen.lineas_pendientes_validacion;
    elements.observed.textContent = data.resumen.observados;
    elements.sync.textContent = formatDate(data.resumen.sincronizado_at);
    elements.balances.innerHTML = data.balances.length
        ? data.balances.map((balance) => `<article class="balance-item"><div><b>${escapeHtml(balance.cliente.nombre)}</b><small>${escapeHtml(label(balance.tipo_envase))}</small></div><strong class="${balance.saldo < 0 ? 'negative' : 'positive'}">${balance.saldo > 0 ? '+' : ''}${balance.saldo}</strong></article>`).join('')
        : '<p>Sin saldos confirmados.</p>';
    elements.pendingList.innerHTML = data.pendientes.length
        ? data.pendientes.map((pending) => `<article class="pending-item"><div><b>${escapeHtml(pending.numero_recepcion)} · ${escapeHtml(pending.cliente.nombre)}</b><small>${escapeHtml(pending.numero_guia)} · ${formatDate(pending.ingreso_at)}</small></div><strong>${pending.cantidad_declarada} ${escapeHtml(label(pending.tipo_envase))}</strong></article>`).join('')
        : '<p>No hay recepciones en verde.</p>';
    elements.reservations.innerHTML = data.reservas.length
        ? data.reservas.map((reservation) => `<article class="reservation-item"><div><b>${escapeHtml(reservation.numero)} · ${escapeHtml(reservation.cliente.nombre)}</b><small>Salida prevista ${formatDate(reservation.salida_at)} · cuenta corriente aún sin movimiento</small></div><div class="reservation-lines">${reservation.lineas.map((line) => `<span>${line.cantidad} ${label(line.tipo_envase)} · ${label(line.propiedad)}</span>`).join('')}</div><button data-guide-document="${reservation.guia_id}" data-guide-number="${escapeHtml(reservation.numero)}">↓ PDF borrador</button></article>`).join('')
        : '<p>No hay envases reservados en borradores.</p>';
    elements.body.innerHTML = data.data.length
        ? data.data.map((movement) => {
            const guideButton = movement.documento_tipo === 'guia_despacho_envases' && movement.documento_id
                ? `<button class="document-button" data-guide-document="${movement.documento_id}" data-guide-number="${escapeHtml(movement.numero_documento)}">↓ Respaldo</button>`
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
            const documentNote = movement.corregido ? ' · propiedad corregida' : movement.cantidad_corregida ? ' · cantidad corregida' : movement.correccion_propiedad ? ' · ajuste de propiedad' : movement.correccion_cantidad ? ' · ajuste de cantidad' : '';
            const correction = movement.correccion_propiedad || movement.correccion_cantidad;
            const trace = correction
                ? `<br><small>Motivo: ${escapeHtml(correction.motivo)} · ${formatDate(correction.corregido_at)} · ${escapeHtml(movement.creado_por?.nombre || '')}</small>`
                : '';
            return `<tr><td>${formatDate(movement.ocurrido_at)}</td><td><b>${escapeHtml(movement.cliente?.nombre)}</b><br><small>${escapeHtml(movement.numero_documento)}${documentNote}</small>${trace}</td><td>${escapeHtml(label(movement.tipo_envase))} · ${movement.cantidad}</td><td class="${movement.impacto_cuenta < 0 ? 'impact-negative' : 'impact-positive'}">${movement.impacto_cuenta > 0 ? '+' : ''}${movement.impacto_cuenta}</td><td>${escapeHtml(label(movement.propiedad))}</td><td>${escapeHtml(label(movement.estado_revision))}</td><td><div class="table-actions">${guideButton}${reviewButton}${propertyButton}${quantityButton}${!guideButton && !reviewButton && !propertyButton && !quantityButton ? '—' : ''}</div></td></tr>`;
        }).join('')
        : '<tr><td colspan="7">Aún no hay movimientos confirmados.</td></tr>';
}

async function load() {
    busy(true, 'Actualizando cuenta corriente…');
    try {
        const parameters = new URLSearchParams(new FormData(elements.filters));
        for (const [key, value] of [...parameters]) {
            if (!value) parameters.delete(key);
        }
        render(await api(`/api/envases/cuenta-corriente/movimientos?${parameters}`));
    } catch (error) {
        toast(error.message, true);
    } finally {
        busy(false);
    }
}

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
elements.filters.addEventListener('submit', (event) => {
    event.preventDefault();
    load();
});
document.addEventListener('click', async (event) => {
    const reviewButton = event.target.closest('[data-review]');
    const propertyButton = event.target.closest('[data-correct-property]');
    const quantityButton = event.target.closest('[data-correct-quantity]');
    const documentButton = event.target.closest('[data-guide-document]');
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
