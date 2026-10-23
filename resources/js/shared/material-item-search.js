/**
 * Búsqueda directa en el catálogo de ítems de Materiales.
 * Sin distinguir mayúsculas ni tildes; cada palabra debe aparecer en algún campo.
 */
export const MATERIAL_ITEM_TYPES = Object.freeze({
    insumo: 'Insumo',
    material_mp: 'Material MP',
    material_pt: 'Material PT',
});

export function normalizeSearchText(value) {
    return String(value ?? '')
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .trim();
}

function searchableText(item) {
    return normalizeSearchText([
        item.codigo,
        item.nombre,
        item.categoria,
        item.tipo_item?.nombre,
        item.codigo_externo,
        item.unidad_medida,
        item.cliente?.codigo,
        item.cliente?.nombre,
        MATERIAL_ITEM_TYPES[item.categoria_operacional] || 'Sin tipo',
    ].filter(Boolean).join(' '));
}

/**
 * @param {Array<object>} items
 * @param {{ query?: string, type?: string, typeId?: string, includeInactive?: boolean }} filters
 *   type: '' (todos), 'insumo', 'material_mp', 'material_pt' o 'sin_tipo'.
 */
export function filterMaterialItems(items, { query = '', type = '', typeId = '', includeInactive = true } = {}) {
    const words = normalizeSearchText(query).split(/\s+/).filter(Boolean);

    return items.filter((item) => {
        if (!includeInactive && !item.activo) return false;
        if (typeId && item.tipo_item_material_id !== typeId) return false;
        if (type === 'sin_tipo' && item.categoria_operacional) return false;
        if (type && type !== 'sin_tipo' && item.categoria_operacional !== type) return false;
        if (!words.length) return true;
        const text = searchableText(item);

        return words.every((word) => text.includes(word));
    });
}

/** Texto del contador: «458 registrados» o «12 de 458 registrados». */
export function materialItemsSummary(visible, total) {
    return visible === total ? `${total} registrados` : `${visible} de ${total} registrados`;
}
