export function maneuverSituation(decision = {}) {
    if (['pausada_discrepancia', 'pausada_supervision'].includes(decision.estado)) {
        return {
            label: 'Pausada · en espera de supervisión', tone: 'warning',
            reason: decision.motivo || 'La maniobra espera una decisión de supervisión.',
        };
    }
    if (decision.estado === 'en_ejecucion' && decision.paso_actual?.estado !== 'en_proceso') {
        return {
            label: 'Tomada · destino reservado', tone: 'info',
            reason: 'El camarero tomó la maniobra y reservó el destino; el retiro aún no comienza.',
        };
    }
    if (decision.estado === 'en_ejecucion') {
        return {
            label: 'En ejecución', tone: 'success',
            reason: 'El retiro del pallet ya comenzó.',
        };
    }

    return null;
}

export function plannerCapacityText(cycle) {
    return cycle
        ? `${Number(cycle.cupos_ocupados || 0)} de ${Number(cycle.capacidad_ejecucion || 0)} ocupados`
        : 'Sin cálculo vigente';
}

export function plannerEmptyDetail(vigencia = {}) {
    return vigencia.estado === 'actual'
        ? 'El cálculo está al día y no encontró movimientos que ordenar.'
        : (vigencia.detalle || 'El planificador aún no confirma un cálculo vigente para mostrar tareas.');
}
