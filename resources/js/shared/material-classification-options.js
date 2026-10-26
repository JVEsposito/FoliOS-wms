import { materialCatalogLabel } from './material-catalog-label.js';

const compareNames = (a, b) => a.nombre.localeCompare(b.nombre, 'es');

/** Los inactivos solo se ofrecen al ítem que ya los tenía asignados. */
export function materialCategoryOptions(categories, original = '') {
    const options = categories.filter(category => category.activo || category.nombre === original)
        .map(category => ({ value: category.nombre, label: `${materialCatalogLabel(category.nombre)}${category.activo ? '' : ' · inactiva'}`, nombre: category.nombre }));
    if (original && !options.some(option => option.value === original)) {
        options.push({ value: original, label: `${materialCatalogLabel(original)} · categoría actual`, nombre: original });
    }
    return options.sort(compareNames);
}

export function materialItemTypeOptions(types, originalId = '') {
    return types.filter(type => type.activo || type.id === originalId).slice().sort(compareNames)
        .map(type => ({ value: type.id, label: `${materialCatalogLabel(type.nombre)}${type.activo ? '' : ' · inactivo'}` }));
}

export function materialItemTypeId(item, types) {
    if (item?.tipo_item_material_id) return item.tipo_item_material_id;
    if (!item?.categoria_operacional) return '';
    return types.find(type => type.codigo_base === item.categoria_operacional)?.id || '';
}
