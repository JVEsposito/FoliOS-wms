// FoliOS v2: avance de la recepción en pasos (hecho, ahora, después). El texto del paso
// actual dice qué falta; el estado también se lee sin color.
export function receptionSteps(reception) {
    const status = reception.estado;
    if (status === 'anulada') return [{ label: 'Recepción anulada', state: 'done' }];
    const closed = status === 'cerrado';
    const entryDone = status !== 'en_bascula_ingreso';
    if (reception.tipo_recepcion === 'solo_envases') {
        return [
            { label: entryDone ? 'Ingreso documental' : 'Confirmar ingreso', state: entryDone ? 'done' : 'now' },
            { label: closed ? 'Cerrada' : 'Listo para cerrar', state: closed ? 'done' : entryDone ? 'now' : 'next' },
        ];
    }
    if (reception.pesaje_envases) {
        const weighing = reception.pesaje_envases;
        const weighingNow = status === 'en_pesaje_envases';
        return [
            { label: entryDone ? 'Ingreso' : 'Confirmar ingreso', state: entryDone ? 'done' : 'now' },
            { label: weighingNow ? `Pesaje de envases ${weighing.cantidad_pesada}/${weighing.cantidad_declarada}` : 'Pesaje de envases', state: weighingNow ? 'now' : entryDone ? 'done' : 'next' },
            { label: closed ? 'Cerrada' : 'Cierre', state: closed ? 'done' : 'next' },
        ];
    }
    const validated = reception.estado_validacion_mp === 'validada';
    const validationLabel = reception.estado_validacion_mp === 'en_curso' ? 'Validación MP en curso' : 'Validación MP pendiente';
    const tareNow = status === 'en_bascula_salida' && validated;
    return [
        { label: entryDone ? 'Ingreso' : 'Confirmar ingreso', state: entryDone ? 'done' : 'now' },
        { label: validated ? 'Validación MP' : entryDone ? validationLabel : 'Validación MP', state: validated ? 'done' : entryDone ? 'now' : 'next' },
        { label: tareNow ? 'Destare pendiente' : 'Destare', state: closed ? 'done' : tareNow ? 'now' : 'next' },
        { label: closed ? 'Cerrada' : 'Cierre', state: closed ? 'done' : 'next' },
    ];
}
