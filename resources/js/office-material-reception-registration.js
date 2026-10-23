const zone = () => document.getElementById('materialReceptionsWorkspace')?.dataset.operationalTimezone || 'America/Santiago';
const escape = (value) => String(value ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&#039;');
const date = (value) => value ? new Intl.DateTimeFormat('es-CL', { timeZone: zone(), dateStyle: 'short', timeStyle: 'short' }).format(new Date(value)) : 'No declarada';
const localInput = (value) => {
    if (!value) return '';
    const parts = Object.fromEntries(new Intl.DateTimeFormat('en-CA', { timeZone: zone(), year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).formatToParts(new Date(value)).map(({ type, value }) => [type, value]));
    return `${parts.year}-${parts.month}-${parts.day}T${parts.hour}:${parts.minute}`;
};
export function registrationLabel(record) {
    const info = record.registro;
    if (!info) return '';
    return [info.retroactivo ? 'Retroactiva' : '', info.provisional ? ({ por_autorizar: 'Provisional · por autorizar', pendiente: 'Provisional · pendiente', vencida: 'Provisional · plazo vencido', regularizada: info.regularizada_fuera_plazo ? 'Regularizada fuera de plazo' : 'Regularizada' }[info.estado] || 'Provisional') : ''].filter(Boolean).join(' · ');
}
export function registrationDetail(record) {
    const info = record.registro;
    if (!info?.fecha_recepcion && !info?.provisional) return '';
    return `<section class="material-reception-warning" aria-label="Registro y regularización"><strong>${escape(registrationLabel(record) || 'Ingreso físico')}</strong><p>Recepción física: ${escape(date(info.fecha_recepcion))} · Registro en sistema: ${escape(date(record.created_at))}${info.provisional ? ` · Plazo: ${escape(date(info.fecha_limite))}` : ''}</p>${info.motivo ? `<p>Motivo de ingreso: ${escape(info.motivo)}</p>` : ''}${info.autorizado_at ? `<p>Autorizó ${escape(info.autorizado_por?.name || 'Usuario registrado en auditoría')} · ${escape(date(info.autorizado_at))}: ${escape(info.motivo_autorizacion)}</p>` : ''}${info.regularizado_at ? `<p>Regularizó ${escape(info.regularizado_por?.name || 'Usuario registrado en auditoría')} · ${escape(date(info.regularizado_at))}: ${escape(info.motivo_regularizacion)}</p>` : ''}</section>`;
}
export function createReceptionRegistration(form) {
    let originalDate = null;
    const sync = () => {
        const provisional = form.elements.provisional?.checked === true;
        if (!form.elements.provisional) return;
        form.elements.numero_guia_despacho.required = !provisional;
        form.querySelectorAll('[name="cantidad_documental"]').forEach((input) => { input.required = !provisional; });
        form.elements.motivo_registro_tardio.required = provisional || Boolean(form.elements.fecha_recepcion.value && form.elements.fecha_recepcion.value.slice(0, 10) < localInput(new Date()).slice(0, 10));
    };
    form.addEventListener('change', sync);
    return {
        sync,
        populate(record) {
            if (!form.elements.provisional) return;
            originalDate = record?.registro?.fecha_recepcion || null;
            form.elements.provisional.checked = record?.registro?.provisional === true;
            form.elements.fecha_recepcion.value = localInput(record?.registro?.fecha_recepcion || (!record ? new Date() : null));
            form.elements.motivo_registro_tardio.value = record?.registro?.motivo || '';
            sync();
        },
        payload() {
            if (!form.elements.provisional) return {};
            sync();
            return { provisional: form.elements.provisional.checked, ...(form.elements.fecha_recepcion.value ? { fecha_recepcion: originalDate && localInput(originalDate) === form.elements.fecha_recepcion.value ? originalDate : form.elements.fecha_recepcion.value } : {}), motivo_registro_tardio: form.elements.motivo_registro_tardio.value || null };
        },
    };
}
function lateAcknowledgement(form, info) {
    const late = Boolean(info?.fecha_limite && new Date(info.fecha_limite) < new Date());
    form.querySelector('[data-late-ack]').hidden = !late;
    form.elements.aceptar_fuera_plazo.required = late;
    return late;
}
export function requestReceptionAuthorization(record) {
    if (!record.registro?.requiere_autorizacion) return Promise.resolve({});
    const dialog = document.getElementById('materialReceptionAuthorizationDialog');
    const form = document.getElementById('materialReceptionAuthorizationForm');
    form.reset(); lateAcknowledgement(form, record.registro);
    document.getElementById('materialReceptionAuthorizationSummary').textContent = `${registrationLabel(record)} · Recepción física: ${date(record.registro.fecha_recepcion)} · Plazo: ${date(record.registro.fecha_limite)}`;
    return new Promise((resolve, reject) => {
        let completed = false;
        form.onsubmit = (event) => {
            event.preventDefault(); if (!form.reportValidity()) return;
            completed = true;
            const payload = { motivo_autorizacion: form.elements.motivo_autorizacion.value.trim(), aceptar_fuera_plazo: form.elements.aceptar_fuera_plazo.checked };
            dialog.close(); resolve(payload);
        };
        dialog.addEventListener('close', () => { if (!completed) reject(new Error('Autorización cancelada. La recepción continúa en borrador.')); }, { once: true });
        dialog.querySelector('[data-close-registration]').onclick = () => dialog.close();
        dialog.showModal();
    });
}
const operations = new Map();
export function resetReceptionRegularization() {
    operations.clear();
    for (const id of ['materialReceptionAuthorizationDialog', 'materialReceptionRegularizationDialog']) document.getElementById(id)?.close();
}
export function openReceptionRegularization(record, { api, uuid, onSaved }) {
    const dialog = document.getElementById('materialReceptionRegularizationDialog');
    const form = document.getElementById('materialReceptionRegularizationForm');
    const error = document.getElementById('materialReceptionRegularizationError');
    const sessionToken = localStorage.getItem('estiba_wms_office_token');
    form.reset(); error.textContent = ''; lateAcknowledgement(form, record.registro);
    document.getElementById('materialReceptionRegularizationInfo').textContent = `Cliente: ${record.cliente?.nombre || '—'} · Recepción física: ${date(record.registro.fecha_recepcion)} · Plazo: ${date(record.registro.fecha_limite)}`;
    form.elements.numero_guia_despacho.value = record.numero_guia_despacho || '';
    form.elements.fecha_documento.value = record.fecha_documento || '';
    document.getElementById('materialReceptionRegularizationLines').innerHTML = (record.detalles || []).map((line) => `<label><span>${escape(line.item?.nombre)} · Cantidad documental (${escape(line.unidad_medida)}) *</span><input type="number" data-documentary-detail="${escape(line.id)}" value="${escape(line.cantidad_documental ?? '')}" required min="0.001" max="99999999999.999" step="0.001"></label>`).join('');
    const button = form.querySelector('[type="submit"]'); button.disabled = false;
    form.onsubmit = async (event) => {
        event.preventDefault(); if (button.disabled || !form.reportValidity()) return;
        button.disabled = true; error.textContent = '';
        try {
            if (!record.fotos?.some((photo) => photo.tipo === 'documento')) throw new Error('Agrega primero la foto de la guía o factura desde el expediente de recepción.');
            const data = { version_conocida: record.version, numero_guia_despacho: form.elements.numero_guia_despacho.value.trim(), fecha_documento: form.elements.fecha_documento.value, motivo: form.elements.motivo.value.trim(), aceptar_fuera_plazo: form.elements.aceptar_fuera_plazo.checked, detalles: [...form.querySelectorAll('[data-documentary-detail]')].map((input) => ({ id: input.dataset.documentaryDetail, cantidad_documental: Number(input.value) })) };
            const key = `${record.id}:${JSON.stringify(data)}`;
            if (!operations.has(key)) operations.set(key, uuid());
            await api(`/api/materiales/recepciones/${encodeURIComponent(record.id)}/regularizar`, { method: 'POST', body: JSON.stringify({ operacion_id: operations.get(key), ...data }) });
            if (localStorage.getItem('estiba_wms_office_token') !== sessionToken) return;
            dialog.close(); await onSaved();
        } catch (reason) { if (dialog.open) error.textContent = reason.message; }
        finally { button.disabled = false; }
    };
    dialog.querySelector('[data-close-registration]').onclick = () => dialog.close();
    dialog.showModal();
}
