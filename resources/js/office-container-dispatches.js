import { loadContainerCatalog, containerLabel } from './office-container-catalog.js';
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
    reload: byId('reloadButton'),
    form: byId('dispatchForm'),
    error: byId('dispatchError'),
    lines: byId('dispatchLines'),
    list: byId('guideList'),
    count: byId('guideCount'),
    physical: byId('physicalCount'),
    reserved: byId('reservedCount'),
    available: byId('availableCount'),
    drafts: byId('draftCount'),
    formEyebrow: byId('formEyebrow'),
    formTitle: byId('formTitle'),
    formHelp: byId('formHelp'),
    saveDraft: byId('saveDraftButton'),
    cancelEdit: byId('cancelEditButton'),
    statusFilter: byId('guideStatusFilter'),
    loading: byId('officeLoading'),
    loadingText: byId('officeLoadingText'),
    toasts: byId('officeToasts'),
};
const keys = {
    token: 'estiba_wms_office_token',
    identity: 'estiba_wms_office_identity',
};
let types = [];
const state = {
    token: localStorage.getItem(keys.token),
    identity: read(keys.identity),
    catalog: null,
    guides: [],
    editing: null,
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
    if (containerLabel(value)) return containerLabel(value);
    return String(value || '')
        .replaceAll('_', ' ')
        .replace(/^./, (character) => character.toUpperCase());
}

function formatDate(value) {
    if (!value) return '—';
    return new Intl.DateTimeFormat('es-CL', {
        dateStyle: 'short',
        timeStyle: 'short',
    }).format(new Date(value));
}

function localDateTime(value = new Date()) {
    const date = value instanceof Date ? value : new Date(value);
    const local = new Date(date.getTime() - date.getTimezoneOffset() * 60000);
    return local.toISOString().slice(0, 16);
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
    setTimeout(() => notification.remove(), 5000);
}

function uuid() {
    const cryptoApi = globalThis.crypto;
    if (typeof cryptoApi.randomUUID === 'function') return cryptoApi.randomUUID();
    const bytes = cryptoApi.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 15) | 64;
    bytes[8] = (bytes[8] & 63) | 128;
    const hex = Array.from(bytes, (number) => number.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
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

async function download(path, fallbackName) {
    const response = await fetch(path, {
        headers: {
            Accept: 'application/pdf',
            Authorization: `Bearer ${state.token}`,
        },
    });
    if (!response.ok) {
        const data = await response.json().catch(() => ({}));
        throw new Error(data?.message || 'No fue posible descargar el respaldo.');
    }
    const disposition = response.headers.get('Content-Disposition') || '';
    const match = disposition.match(/filename="([^"]+)"/);
    const blobUrl = URL.createObjectURL(await response.blob());
    const anchor = document.createElement('a');
    anchor.href = blobUrl;
    anchor.download = match?.[1] || fallbackName;
    anchor.click();
    setTimeout(() => URL.revokeObjectURL(blobUrl), 1000);
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
    document.querySelector('.dispatch-form-panel').classList.toggle(
        'is-hidden',
        state.identity?.puede_gestionar_despacho_envases !== true,
    );
    return true;
}

function selectedClientId() {
    return elements.form.elements.cliente_id.value;
}

function sourceOptions(type, property, selected = '') {
    const automaticLabel = property === 'propia'
        ? 'Asignación automática FIFO de existencia propia'
        : 'Asignación automática FIFO con trazabilidad';
    const clientId = selectedClientId();
    const origins = (state.catalog?.origenes || []).filter((origin) => {
        if (origin.tipo_envase !== type || origin.propiedad !== property || origin.disponible <= 0) {
            return false;
        }
        return property !== 'cliente' || origin.cliente?.id === clientId;
    });
    return `<option value="">${automaticLabel}</option>${origins.map((origin) => {
        const owner = origin.cliente?.nombre ? ` · ${origin.cliente.nombre}` : '';
        const selectedAttribute = origin.id === selected ? ' selected' : '';
        return `<option value="${origin.id}"${selectedAttribute}>${escapeHtml(origin.documento)}${escapeHtml(owner)} · físico ${origin.fisico} · reservado ${origin.reservado} · disponible ${origin.disponible}</option>`;
    }).join('')}`;
}

function renderLines(values = {}) {
    elements.lines.innerHTML = types.map((type) => {
        const value = values[type] || {};
        const property = value.propiedad || 'propia';
        return `<article class="dispatch-line" data-type="${type}">
            <label><span>Tipo</span><strong>${label(type)}</strong></label>
            <label><span>Cantidad</span><input name="cantidad_${type}" type="number" min="0" value="${Number(value.cantidad || 0)}"></label>
            <label><span>Propiedad</span><select name="propiedad_${type}">
                <option value="propia"${property === 'propia' ? ' selected' : ''}>Propia</option>
                <option value="arrendada"${property === 'arrendada' ? ' selected' : ''}>Arrendada</option>
                <option value="cliente"${property === 'cliente' ? ' selected' : ''}>Del cliente</option>
            </select></label>
            <label><span>Movimiento de origen</span><select name="origen_${type}">${sourceOptions(type, property, value.origen || '')}</select><small>FIFO automático puede dividir la cantidad entre varias guías.</small></label>
        </article>`;
    }).join('');
    elements.lines.querySelectorAll('[data-type]').forEach((row) => {
        row.querySelector(`[name="propiedad_${row.dataset.type}"]`).addEventListener('change', (event) => {
            row.querySelector(`[name="origen_${row.dataset.type}"]`).innerHTML = sourceOptions(
                row.dataset.type,
                event.target.value,
            );
        });
    });
}

function refreshSourceOptions() {
    elements.lines.querySelectorAll('[data-type]').forEach((row) => {
        const property = row.querySelector(`[name="propiedad_${row.dataset.type}"]`).value;
        const source = row.querySelector(`[name="origen_${row.dataset.type}"]`);
        source.innerHTML = sourceOptions(row.dataset.type, property, source.value);
    });
}

function renderInventory() {
    const inventory = state.catalog?.inventario || [];
    elements.physical.textContent = inventory.reduce((sum, item) => sum + Number(item.fisico), 0);
    elements.reserved.textContent = inventory.reduce((sum, item) => sum + Number(item.reservado), 0);
    elements.available.textContent = inventory.reduce((sum, item) => sum + Number(item.disponible), 0);
    elements.drafts.textContent = state.guides.filter((guide) => guide.estado === 'borrador').length;
}

function guideEffect(guide) {
    const total = guide.resumen.reduce((sum, line) => sum + Number(line.cantidad), 0);
    if (guide.estado === 'borrador') {
        return `<div class="guide-impact guide-impact--reserved"><b>${total} reservados</b><span>Existencia física sin cambio · cuenta corriente sin movimiento hasta confirmar</span></div>`;
    }
    if (guide.estado === 'confirmada') {
        return `<div class="guide-impact guide-impact--confirmed"><b>Salida confirmada: −${total}</b><span>Existencia y cuenta corriente descontadas</span></div>`;
    }
    if (guide.estado === 'anulada') {
        return `<div class="guide-impact guide-impact--cancelled"><b>Impacto neto 0</b><span>Salida original conservada y movimientos compensatorios registrados</span></div>`;
    }
    return '<div class="guide-impact guide-impact--cancelled"><b>Sin impacto</b><span>Reserva liberada sin modificar cuenta corriente</span></div>';
}

function renderGuides(guides) {
    state.guides = guides;
    elements.count.textContent = `${guides.length} guía${guides.length === 1 ? '' : 's'}`;
    elements.list.innerHTML = guides.length ? guides.map((guide) => {
        const canManage = state.identity?.puede_gestionar_despacho_envases === true;
        const canReverse = state.identity?.puede_anular_despacho_envases === true;
        const lines = guide.resumen.map((line) => `<span>${line.cantidad} ${label(line.tipo_envase)} · ${label(line.propiedad)}</span>`).join('');
        const origins = guide.detalles.map((line) => `<li>${line.cantidad} ${label(line.tipo_envase)} · ${escapeHtml(line.origen || 'Sin origen')}</li>`).join('');
        const statusReason = guide.motivo_cancelacion || guide.motivo_anulacion;
        return `<article class="guide-card">
            <div class="guide-card__head"><div><strong>${escapeHtml(guide.numero)}</strong><small> · ${escapeHtml(guide.cliente.nombre)} · ${formatDate(guide.salida_at)}</small></div><span class="guide-state guide-state--${guide.estado}">${label(guide.estado)}</span></div>
            <div class="guide-lines">${lines}</div>
            ${guideEffect(guide)}
            <details class="guide-trace"><summary>Ver trazabilidad y responsables</summary><ul>${origins}</ul><p>Preparó: ${escapeHtml(guide.creado_por || '—')} · Confirmó: ${escapeHtml(guide.confirmado_por || '—')}</p>${guide.documento_hash ? `<p>Integridad: <code>${escapeHtml(guide.documento_hash)}</code></p>` : ''}${statusReason ? `<p>Motivo: ${escapeHtml(statusReason)}</p>` : ''}</details>
            <div class="guide-card__foot"><small>${escapeHtml(guide.patente_camion || 'Sin transporte informado')} · temporada ${escapeHtml(guide.temporada.codigo)}</small><div class="guide-actions">
                <button data-document="${guide.id}">↓ ${guide.estado === 'borrador' ? 'PDF borrador' : 'PDF respaldo'}</button>
                ${guide.puede_editar && canManage ? `<button data-edit="${guide.id}">Editar</button>` : ''}
                ${guide.puede_cancelar && canManage ? `<button class="danger" data-cancel="${guide.id}">Cancelar borrador</button>` : ''}
                ${guide.puede_confirmar && canManage ? `<button class="primary-button compact" data-confirm="${guide.id}">Confirmar salida</button>` : ''}
                ${guide.puede_anular && canReverse ? `<button class="danger" data-reverse="${guide.id}">Anular y reversar</button>` : ''}
                ${guide.comprobante_anulacion_disponible ? `<button data-reversal-document="${guide.id}">↓ Comprobante de anulación</button>` : ''}
            </div></div>
        </article>`;
    }).join('') : '<p>No existen guías de envases para este filtro.</p>';
    renderInventory();
}

async function loadCatalogs() {
    const [catalog, containers] = await Promise.all([api('/api/envases/guias-despacho/catalogos'), loadContainerCatalog(api)]);
    state.catalog = catalog; types = containers.map((t) => t.codigo);
    const selected = elements.form.elements.cliente_id.value;
    elements.form.elements.cliente_id.innerHTML = '<option value="">Seleccionar cliente</option>'
        + state.catalog.clientes.map((client) => `<option value="${client.id}">${escapeHtml(client.codigo)} · ${escapeHtml(client.nombre)}</option>`).join('');
    elements.form.elements.cliente_id.value = selected;
    if (!elements.lines.children.length) renderLines();
    if (!elements.form.elements.salida_at.value) {
        elements.form.elements.salida_at.value = localDateTime();
    }
}

async function loadGuides() {
    const query = elements.statusFilter.value
        ? `?estado=${encodeURIComponent(elements.statusFilter.value)}`
        : '';
    renderGuides((await api(`/api/envases/guias-despacho${query}`)).data);
}

async function reloadAll() {
    busy(true, 'Actualizando disponibilidad y guías…');
    try {
        await loadCatalogs();
        await loadGuides();
        refreshSourceOptions();
    } catch (error) {
        toast(error.message, true);
    } finally {
        busy(false);
    }
}

function resetForm() {
    state.editing = null;
    elements.form.reset();
    elements.formEyebrow.textContent = 'NUEVA GUÍA';
    elements.formTitle.textContent = 'Preparar borrador';
    elements.formHelp.textContent = 'Al guardar se asignan los movimientos de origen por FIFO y las cantidades quedan reservadas.';
    elements.saveDraft.innerHTML = 'Crear borrador y reservar <span>→</span>';
    elements.cancelEdit.classList.add('is-hidden');
    elements.error.textContent = '';
    renderLines();
    elements.form.elements.salida_at.value = localDateTime();
}

function editGuide(guide) {
    state.editing = guide;
    const values = {};
    guide.resumen.forEach((summary) => {
        const allocations = guide.detalles.filter((detail) => (
            detail.tipo_envase === summary.tipo_envase && detail.propiedad === summary.propiedad
        ));
        values[summary.tipo_envase] = {
            cantidad: summary.cantidad,
            propiedad: summary.propiedad,
            origen: allocations.length === 1 ? allocations[0].movimiento_origen_id : '',
        };
    });
    elements.form.elements.cliente_id.value = guide.cliente.id;
    elements.form.elements.salida_at.value = localDateTime(guide.salida_at);
    elements.form.elements.patente_camion.value = guide.patente_camion || '';
    elements.form.elements.nombre_conductor.value = guide.conductor?.nombre || '';
    elements.form.elements.rut_conductor.value = guide.conductor?.rut || '';
    elements.form.elements.observacion.value = guide.observacion || '';
    renderLines(values);
    elements.formEyebrow.textContent = guide.numero;
    elements.formTitle.textContent = 'Editar borrador reservado';
    elements.formHelp.textContent = 'Guardar recalcula las reservas y conserva el evento anterior en la auditoría.';
    elements.saveDraft.innerHTML = 'Guardar cambios <span>→</span>';
    elements.cancelEdit.classList.remove('is-hidden');
    document.querySelector('.dispatch-form-panel').scrollIntoView({ behavior: 'smooth' });
}

function formPayload() {
    const form = Object.fromEntries(new FormData(elements.form));
    const details = types.map((type) => ({
        tipo_envase: type,
        cantidad: Number(form[`cantidad_${type}`] || 0),
        propiedad: form[`propiedad_${type}`],
        movimiento_origen_id: form[`origen_${type}`] || null,
    })).filter((detail) => detail.cantidad > 0);
    return {
        cliente_id: form.cliente_id,
        salida_at: new Date(form.salida_at).toISOString(),
        patente_camion: form.patente_camion?.toUpperCase() || null,
        rut_conductor: form.rut_conductor || null,
        nombre_conductor: form.nombre_conductor || null,
        observacion: form.observacion || null,
        detalles: details,
    };
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
        if (!showOffice()) throw new Error('Tu perfil no puede consultar envases.');
        await reloadAll();
    } catch (error) {
        elements.loginError.textContent = error.message;
    }
});

elements.logout.addEventListener('click', () => {
    localStorage.removeItem(keys.token);
    localStorage.removeItem(keys.identity);
    location.reload();
});
elements.reload.addEventListener('click', reloadAll);
elements.statusFilter.addEventListener('change', loadGuides);
elements.cancelEdit.addEventListener('click', resetForm);
elements.form.elements.cliente_id.addEventListener('change', refreshSourceOptions);

elements.form.addEventListener('submit', async (event) => {
    event.preventDefault();
    elements.error.textContent = '';
    const payload = formPayload();
    if (!payload.detalles.length) {
        elements.error.textContent = 'Ingresa al menos una cantidad de envases.';
        return;
    }
    busy(true, state.editing ? 'Actualizando reservas…' : 'Creando reserva…');
    try {
        if (state.editing) {
            await api(`/api/envases/guias-despacho/${state.editing.id}`, {
                method: 'PUT',
                body: JSON.stringify({
                    ...payload,
                    version: state.editing.version,
                }),
            });
            toast(`${state.editing.numero} actualizada y reservas recalculadas.`);
        } else {
            const data = await api('/api/envases/guias-despacho', {
                method: 'POST',
                body: JSON.stringify({
                    ...payload,
                    operacion_id: uuid(),
                }),
            });
            toast(`${data.data.numero} creada: disponibilidad reservada, cuenta corriente aún sin movimiento.`);
        }
        resetForm();
        await reloadAll();
    } catch (error) {
        elements.error.textContent = error.message;
    } finally {
        busy(false);
    }
});

elements.list.addEventListener('click', async (event) => {
    const edit = event.target.closest('[data-edit]');
    const confirm = event.target.closest('[data-confirm]');
    const cancel = event.target.closest('[data-cancel]');
    const reverse = event.target.closest('[data-reverse]');
    const documentButton = event.target.closest('[data-document]');
    const reversalDocument = event.target.closest('[data-reversal-document]');
    const id = edit?.dataset.edit
        || confirm?.dataset.confirm
        || cancel?.dataset.cancel
        || reverse?.dataset.reverse
        || documentButton?.dataset.document
        || reversalDocument?.dataset.reversalDocument;
    const guide = state.guides.find((item) => item.id === id);
    if (!guide) return;

    try {
        if (edit) {
            editGuide(guide);
            return;
        }
        if (documentButton) {
            await download(
                `/api/envases/guias-despacho/${guide.id}/documento`,
                `guia-envases-${guide.numero.toLowerCase()}.pdf`,
            );
            return;
        }
        if (reversalDocument) {
            await download(
                `/api/envases/guias-despacho/${guide.id}/comprobante-anulacion`,
                `anulacion-${guide.numero.toLowerCase()}.pdf`,
            );
            return;
        }
        if (confirm && window.confirm(
            `¿Confirmar la salida ${guide.numero}?\n\nSe descontará la existencia, se liberará la reserva y la cuenta corriente del cliente recibirá el movimiento. El respaldo final quedará inmutable.`,
        )) {
            busy(true, 'Confirmando salida y generando respaldo…');
            await api(`/api/envases/guias-despacho/${guide.id}/confirmar`, {
                method: 'POST',
                body: JSON.stringify({
                    version: guide.version,
                    salida_at: guide.salida_at,
                }),
            });
            toast(`${guide.numero} confirmada: existencia y cuenta corriente actualizadas.`);
            await reloadAll();
        }
        if (cancel) {
            const reason = window.prompt('Motivo de cancelación del borrador:');
            if (reason) {
                busy(true, 'Cancelando borrador y liberando reserva…');
                await api(`/api/envases/guias-despacho/${guide.id}/cancelar`, {
                    method: 'POST',
                    body: JSON.stringify({ motivo: reason }),
                });
                toast(`${guide.numero} cancelada; la reserva quedó liberada.`);
                await reloadAll();
            }
        }
        if (reverse) {
            const reason = window.prompt(
                'Motivo obligatorio de anulación. Se crearán movimientos compensatorios:',
            );
            if (reason && window.confirm('¿Confirmar la anulación y reversa contable?')) {
                busy(true, 'Anulando y creando reversas…');
                await api(`/api/envases/guias-despacho/${guide.id}/anular`, {
                    method: 'POST',
                    body: JSON.stringify({ motivo: reason }),
                });
                toast(`${guide.numero} anulada con reversa de existencia y cuenta corriente.`);
                await reloadAll();
            }
        }
    } catch (error) {
        toast(error.message, true);
    } finally {
        busy(false);
    }
});

if (state.token && showOffice()) {
    reloadAll();
}
