const escape = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

export function sagLotAlertText(alert) {
    const targets = alert.todos_destinos ? 'todos los destinos' : (alert.destinos || []).map((d) => d.nombre).join(', ');
    return (alert.folio ? 'Folio ' + alert.folio + ' · ' : '') + 'Lote ' + alert.lote + ' · '
        + (alert.estado === 'retenido' ? 'Retenido' : 'Con observaciones') + ' · '
        + (targets ? 'Despacho restringido: ' + targets : 'Sin restricción de destino')
        + (alert.destino_sin_definir ? ' · Define el destino del instructivo antes de salir' : '')
        + (alert.instruccion_segregacion ? ' · Segregación indicada: ' + alert.instruccion_segregacion : '');
}

export function sagLotAlertsHtml(alerts = []) {
    return alerts.length ? '<div class="sag-lot-alerts" role="alert"><strong>SAG · Control de lotes</strong><ul>'
        + alerts.map((a) => '<li>' + escape(sagLotAlertText(a)) + '</li>').join('') + '</ul></div>' : '';
}
