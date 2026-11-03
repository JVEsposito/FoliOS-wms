export function packingReturnContainerLabel(type) {
    return type === 'caja_3_4' ? 'CAJAS 3/4' : 'BINS';
}

export function packingReturnQuantity(record) {
    const quantity = Number(record?.cantidad_envases ?? record?.cantidad_bins ?? 1);
    return `${new Intl.NumberFormat('es-CL').format(quantity)} ${packingReturnContainerLabel(record?.tipo_envase)}`;
}

export function packingReturnTotals(summary) {
    const bins = Number(summary?.bins_retornados ?? 0);
    const boxes = Number(summary?.cajas_3_4_retornadas ?? 0);
    return boxes > 0 ? `${new Intl.NumberFormat("es-CL").format(bins)} BINS · ${new Intl.NumberFormat("es-CL").format(boxes)} CAJAS 3/4` : `${new Intl.NumberFormat("es-CL").format(bins)} BINS`;
}
