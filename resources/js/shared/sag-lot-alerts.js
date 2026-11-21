const escape = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

export const SAG_LOT_STATES = {
    con_observaciones: 'Con observaciones',
    retenido: 'Retenido',
    liberado_con_restricciones: 'Liberado con restricciones',
    liberado: 'Liberado',
};

function hasDestinationRestrictions(alert) {
    return !alert.todos_destinos && alert.destinos?.length > 0;
}

export function sagLotOperationalAlerts(alerts) {
    return (alerts ?? []).flatMap((alert) => {
        if (alert.estado === 'liberado' || alert.cajas_lote === 0) return [];
        if (!hasDestinationRestrictions(alert) || alert.bloquea_salida === true) return [alert];
        return alert.instruccion_segregacion
            ? [{ ...alert, solo_segregacion: true, destinos: [] }]
            : [];
    });
}

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
    return targets ? 'Despacho restringido a ' + targets : 'Sin restricción de destino';
}

export function sagLotAlertText(alert) {
    const estado = alert.estado === 'retenido' && hasDestinationRestrictions(alert)
        ? 'liberado_con_restricciones' : alert.estado;
    return [
        alert.carga && 'Carga ' + alert.carga,
        alert.folio && 'Folio ' + alert.folio,
        'Lote ' + alert.lote,
        !alert.solo_segregacion && SAG_LOT_STATES[estado],
        alert.instructivo_numero && 'Instructivo ' + alert.instructivo_numero,
        !alert.solo_segregacion && restrictionText(alert),
        alert.instruccion_segregacion && 'Segregación indicada · ' + alert.instruccion_segregacion,
    ].filter(Boolean).join(' · ');
}

function compactAlerts(alerts) {
    const blocked = alerts.filter((alert) => alert.bloquea_salida);
    const folios = new Set(blocked.map((alert) => alert.folio || alert.folio_id).filter(Boolean));
    const destinations = [...new Set((blocked.length ? blocked : alerts).map((alert) => alert.destino).filter(Boolean))];
    const label = folios.size ? folios.size + (folios.size === 1 ? ' folio bloqueado' : ' folios bloqueados') : 'Seguimiento SAG';
    if (blocked.some((alert) => alert.destino_sin_definir)) {
        destinations.push('destino pendiente');
    }
    return '<span class="sag-lot-alerts__compact">' + escape([label, ...destinations].join(' · ')) + '</span>';
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
    const active = sagLotOperationalAlerts(alerts);
    if (!active.length) return '';
    if (compact) return compactAlerts(active);

    const groups = new Map();
    for (const alert of active) {
        const key = JSON.stringify([alert.id ?? sagLotAlertText({ ...alert, folio: null }), alert.carga_id, alert.instructivo_id]);
        if (!groups.has(key)) groups.set(key, { alert, folios: new Set() });
        if (alert.folio) groups.get(key).folios.add(alert.folio);
    }
    const ordered = [...groups.values()].sort((a, b) => Number(Boolean(b.alert.bloquea_salida)) - Number(Boolean(a.alert.bloquea_salida)));

    return '<div class="sag-lot-alerts" role="alert"><strong>SAG · Control de lotes</strong>'
        + alertList(ordered) + '</div>';
}
