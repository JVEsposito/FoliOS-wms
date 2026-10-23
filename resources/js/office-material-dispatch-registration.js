const esc = value => String(value ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&#039;');
const date = value => value ? new Intl.DateTimeFormat('es-CL', { timeZone: 'America/Santiago', dateStyle: 'short', timeStyle: 'short' }).format(new Date(value)) : 'No declarada';
const labels = { pendiente: 'Provisional · pendiente', vencida: 'Provisional · plazo vencido', regularizada: 'Regularizada', no_requerida: 'Respaldo sin pendientes' };

export function dispatchRegistrationLabel(delivery) {
    return [delivery.retroactivo ? 'Retroactiva' : '', labels[delivery.estado] || 'Entrega', delivery.regularizada_fuera_plazo ? 'Fuera de plazo' : ''].filter(Boolean).join(' · ');
}

export function readDispatchRegistration(form) {
    const fields = form.elements;
    const registro = {};
    for (const name of ['fecha_despacho', 'numero_documento', 'fecha_documento', 'motivo', 'motivo_autorizacion']) {
        const value = fields[`registro_${name}`]?.value.trim();
        if (value) registro[name] = value;
    }
    if (fields.registro_provisional?.checked) registro.provisional = true;
    if (fields.registro_aceptar_fuera_plazo?.checked) registro.aceptar_fuera_plazo = true;
    return Object.keys(registro).length ? { registro } : {};
}

export function dispatchRegistrationDetail(delivery, canAuthorize) {
    const audit = (delivery.regularizaciones || []).map(event => `<p>Regularizó ${esc(event.usuario?.name)} · ${esc(date(event.ocurrido_at))}: ${esc(event.motivo)}${event.fuera_plazo ? ' · Fuera de plazo' : ''}</p><p>Documento anterior: ${esc(event.antes.numero_documento || 'Pendiente')} · Documento resultante: ${esc(event.despues.numero_documento)} · Emisión: ${esc(String(event.despues.fecha_documento || '').slice(0, 10))}</p>`).join('');
    return `<section class="dispatch-trace-item" data-delivery="${esc(delivery.id)}"><h3>${esc(dispatchRegistrationLabel(delivery))}</h3><p>Entrega física: ${esc(date(delivery.fecha_despacho))} · Registro real: ${esc(date(delivery.registrado_at))} · ${esc(delivery.registrado_por?.name || 'Usuario auditado')}</p><p>Documento: ${esc(delivery.numero_documento || 'Sin documento declarado')} · Emisión: ${esc(delivery.fecha_documento || 'No declarada')}</p>${delivery.provisional ? `<p>Plazo: ${esc(date(delivery.fecha_limite))} · Cinco días corridos</p>` : ''}${delivery.motivo ? `<p>Motivo del registro: ${esc(delivery.motivo)}</p>` : ''}${delivery.autorizado_at ? `<p>Autorizó ${esc(delivery.autorizado_por?.name)} · ${esc(date(delivery.autorizado_at))}: ${esc(delivery.motivo_autorizacion)}</p>` : ''}<p>Retiros: ${(delivery.retiros || []).map(r => `${esc(r.numero_folio || 'Folio registrado')} · ${esc(r.cantidad_retirada)}`).join(' / ') || 'Consultar detalle de folios'}</p>${audit}${canAuthorize && ['pendiente', 'vencida'].includes(delivery.estado) ? `<form class="materials-form" data-regularize-dispatch="${esc(delivery.id)}"><input name="version_conocida" type="hidden" value="${esc(delivery.version)}"><label><span>Número de documento *</span><input name="numero_documento" maxlength="50" value="${esc(delivery.numero_documento)}" required></label><label><span>Fecha de emisión *</span><input name="fecha_documento" type="date" value="${esc(delivery.fecha_documento)}" required></label><label><span>Motivo de regularización *</span><textarea name="motivo" minlength="5" maxlength="1000" required></textarea></label>${delivery.estado === 'vencida' ? '<label><input name="aceptar_fuera_plazo" type="checkbox" required> Regularizo expresamente fuera de cinco días corridos</label>' : ''}<p>Conserva folios, cantidades, reservas y movimientos de la entrega ejecutada.</p><p role="alert" data-regularization-error></p><button class="primary-button" type="submit">Regularizar respaldo</button></form>` : ''}</section>`;
}
