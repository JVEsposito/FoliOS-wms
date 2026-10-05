export function outgoingInspectionRows(rows) {
    return rows.filter((row) => Number(row.cantidad) > 0).map((row) => row.tipo_envase);
}
export function dispatchInspectionPayload(types, values, observation = '') {
    const items = types.map((type) => {
        const item = values[type];
        if (!item || !['si', 'no'].includes(item.limpieza) || !['buena', 'regular', 'mala'].includes(item.condicion)) throw new Error('Completa limpieza y condición de cada envase de salida para emitir RC-02.');
        return { tipo_envase: type, limpieza: item.limpieza === 'si', condicion: item.condicion, nota: item.nota?.trim() || null };
    });
    return { items, observacion: observation.trim() || null };
}
