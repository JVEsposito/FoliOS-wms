let catalog = [];

export async function loadContainerCatalog(api) {
    const response = await api('/api/envases/catalogo');
    catalog = response.data;
    return catalog;
}

export function containerLabel(code) {
    return catalog.find((item) => item.codigo === code)?.nombre;
}

export function calculateNetAllocation(net, quantities, selection, references = {}) {
    if (!selection.length) throw new Error('Selecciona al menos un envase con fruta para el reparto.');
    const rows = selection.map((code) => {
        const type = catalog.find((item) => item.codigo === code);
        if (!type?.contiene_fruta || !(quantities[code] > 0)) throw new Error('El reparto requiere envases con fruta y cantidad positiva.');
        if (selection.length > 1 && !(references[code] > 0)) throw new Error(`Falta configurar el peso de referencia de ${type.nombre} para esta especie en Administración.`);
        return { codigo: code, cantidad: quantities[code], referencia: selection.length === 1 ? 1 : references[code] };
    });
    const total = rows.reduce((sum, row) => sum + row.cantidad * row.referencia, 0);
    return rows.map((row) => ({ ...row, neto_unitario: net * row.referencia / total }));
}
