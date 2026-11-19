const escape = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

function restrictionText(alert) {
    const targets = alert.todos_destinos ? 'todos los destinos' : (alert.destinos || []).map((d) => d.nombre).join(', ');
    if (alert.bloquea_salida === true) {
        if (alert.destino_sin_definir) {
            return 'Define el destino del instructivo antes de salir · Restricción vigente para ' + targets;
        }
        const destination = alert.destino || 'todos los destinos';
        return 'Bloquea la salida a ' + destination
            + (alert.todos_destinos && alert.destino ? ' · Retención para todos los destinos' : '');
    }
    if (alert.bloquea_salida === false && targets) {
        return (alert.destino ? 'Destino ' + alert.destino + ' · ' : '') + 'Restricción en otros destinos (' + targets + ')';
    }
    return (targets ? 'Despacho restringido: ' + targets : 'Sin restricción de destino')
        + (alert.destino_sin_definir ? ' · Define el destino del instructivo antes de salir' : '');
}

export function sagLotAlertText(alert) {
    return (alert.folio ? 'Folio ' + alert.folio + ' · ' : '') + 'Lote ' + alert.lote + ' · '
        + (alert.estado === 'retenido' ? 'Retenido' : 'Con observaciones') + ' · '
        + (alert.instructivo_numero ? 'Instructivo ' + alert.instructivo_numero + ' · ' : '')
        + restrictionText(alert)
        + (alert.instruccion_segregacion ? ' · Segregación indicada: ' + alert.instruccion_segregacion : '');
}

function compactAlerts(alerts) {
    const blocked = alerts.filter((alert) => alert.bloquea_salida);
    const folios = new Set(blocked.map((alert) => alert.folio || alert.folio_id).filter(Boolean));
    const destinations = [...new Set((blocked.length ? blocked : alerts).map((alert) => alert.destino).filter(Boolean))];
    const label = folios.size ? folios.size + (folios.size === 1 ? ' folio bloqueado' : ' folios bloqueados')
        : (alerts.every(isOtherDestination) ? 'Restricciones en otros destinos' : 'Seguimiento SAG');
    if (blocked.some((alert) => alert.destino_sin_definir)) {
        destinations.push('destino pendiente');
    }
    return '<span class="sag-lot-alerts__compact">' + escape([label, ...destinations].join(' · ')) + '</span>';
}

function isOtherDestination(alert) {
    return alert.bloquea_salida === false && alert.destinos?.length > 0 && !alert.instruccion_segregacion;
}

function alertList(groups) {
    return groups.length ? '<ul>' + groups.map(({ alert, folios }) => {
        const detail = folios.size
            ? '<span class="sag-lot-alerts__folios">' + escape('Folios (' + folios.size + ') · ' + [...folios].join(', ')) + '</span>'
            : '';
        return '<li>' + escape(sagLotAlertText({ ...alert, folio: null })) + detail + '</li>';
    }).join('') + '</ul>' : '';
}

export function sagLotAlertsHtml(alerts = [], { compact = false } = {}) {
    if (!alerts.length) return '';
    if (compact) return compactAlerts(alerts);

    const groups = new Map();
    for (const alert of alerts) {
        const key = JSON.stringify([alert.id ?? sagLotAlertText({ ...alert, folio: null }), alert.instructivo_id]);
        if (!groups.has(key)) groups.set(key, { alert, folios: new Set() });
        if (alert.folio) groups.get(key).folios.add(alert.folio);
    }
    const active = [];
    const other = [];
    for (const group of groups.values()) {
        (isOtherDestination(group.alert) ? other : active).push(group);
    }
    active.sort((a, b) => Number(Boolean(b.alert.bloquea_salida)) - Number(Boolean(a.alert.bloquea_salida)));
    const details = other.length ? '<details><summary>Restricciones en otros destinos (' + other.length + ')</summary>'
        + alertList(other) + '</details>' : '';

    return '<div class="sag-lot-alerts" role="alert"><strong>SAG · Control de lotes</strong>'
        + alertList(active) + details + '</div>';
}
