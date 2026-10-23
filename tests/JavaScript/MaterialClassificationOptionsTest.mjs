import assert from 'node:assert/strict';
import test from 'node:test';
import { materialCategoryOptions, materialItemTypeOptions, materialItemTypeId } from '../../resources/js/shared/material-classification-options.js';
import { filterMaterialItems } from '../../resources/js/shared/material-item-search.js';

test('categorías ofrece activas y conserva la representación histórica solo para el ítem que ya la usa', () => {
    const categories = [{ nombre: 'Cajas', activo: true }, { nombre: 'Adhesivos', activo: false }];
    assert.deepEqual(materialCategoryOptions(categories).map(option => option.value), ['Cajas']);
    assert.deepEqual(materialCategoryOptions(categories, 'Adhesivos').map(option => option.value), ['Adhesivos', 'Cajas']);
    assert.ok(materialCategoryOptions(categories, ' CAJAS ').some(option => option.value === ' CAJAS '));
    assert.equal(categories.length, 2);
});

test('un tipo desactivado permanece seleccionable al editar su ítem y se omite al crear otro', () => {
    const types = [{ id: 'mp', nombre: 'Material MP', activo: true }, { id: 'adh', nombre: 'Adhesivo', activo: false }];
    assert.deepEqual(materialItemTypeOptions(types).map(option => option.value), ['mp']);
    assert.deepEqual(materialItemTypeOptions(types, 'adh').map(option => option.value), ['adh', 'mp']);
});

test('se conserva un tipo configurable y los ítems antiguos usan el tipo base correspondiente', () => {
    const types = [{ id: 'mp', codigo_base: 'material_mp' }, { id: 'adh', codigo_base: null }];
    assert.equal(materialItemTypeId({ tipo_item_material_id: 'adh', categoria_operacional: 'insumo' }, types), 'adh');
    assert.equal(materialItemTypeId({ categoria_operacional: 'material_mp' }, types), 'mp');
    assert.equal(materialItemTypeId({ categoria_operacional: null }, types), '');
});

test('la búsqueda combina el tipo configurable, las palabras y su función operacional', () => {
    const items = [
        { id: 1, nombre: 'Pegamento transparente', activo: true, tipo_item_material_id: 'adh', tipo_item: { nombre: 'Adhesívo' }, categoria_operacional: 'insumo' },
        { id: 2, nombre: 'Pegamento industrial', activo: true, tipo_item_material_id: 'ind', tipo_item: { nombre: 'Químico' }, categoria_operacional: 'insumo' },
    ];
    assert.deepEqual(filterMaterialItems(items, { query: 'adhesivo transparente', type: 'insumo', typeId: 'adh' }).map(item => item.id), [1]);
    assert.deepEqual(filterMaterialItems(items, { type: 'material_mp', typeId: 'adh' }), []);
});
