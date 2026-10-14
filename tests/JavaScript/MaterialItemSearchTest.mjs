import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

import { filterMaterialItems, materialItemsSummary, normalizeSearchText } from '../../resources/js/shared/material-item-search.js';

const items = [
    { id: '1', codigo: 'AR-001', nombre: 'Adhesivo barra', categoria: 'ART OFICINA', categoria_operacional: 'insumo', unidad_medida: 'unidad', codigo_externo: 'AR1222', activo: true, cliente: { codigo: 'LAG', nombre: 'La Aguada' } },
    { id: '2', codigo: 'MAT-CAJ-010', nombre: 'Caja cartón 10 kg', categoria: 'Cajas', categoria_operacional: 'material_mp', unidad_medida: 'unidades', activo: true, cliente: { codigo: 'FSUR', nombre: 'Frutícola Sur' } },
    { id: '3', codigo: 'MAT-GAG-001', nombre: 'Maleta armada 2.5 kg', categoria: 'Cajas', categoria_operacional: 'material_pt', unidad_medida: 'unidades', activo: false, cliente: { codigo: 'LAG', nombre: 'La Aguada' } },
    { id: '4', codigo: 'VAR-77', nombre: 'Etiqueta genérica', categoria: null, categoria_operacional: null, unidad_medida: 'rollos', activo: true, cliente: { codigo: 'LAG', nombre: 'La Aguada' } },
];
const ids = (list) => list.map((item) => item.id);

test('sin filtros devuelve todo el catálogo', () => {
    assert.deepEqual(ids(filterMaterialItems(items)), ['1', '2', '3', '4']);
});

test('busca por código, descripción, categoría, código ERP y cliente sin distinguir tildes ni mayúsculas', () => {
    assert.deepEqual(ids(filterMaterialItems(items, { query: 'mat-caj' })), ['2']);
    assert.deepEqual(ids(filterMaterialItems(items, { query: 'carton' })), ['2']);
    assert.deepEqual(ids(filterMaterialItems(items, { query: 'CAJAS' })), ['2', '3']);
    assert.deepEqual(ids(filterMaterialItems(items, { query: 'ar1222' })), ['1']);
    assert.deepEqual(ids(filterMaterialItems(items, { query: 'fruticola' })), ['2']);
});

test('cada palabra debe aparecer en algún campo', () => {
    assert.deepEqual(ids(filterMaterialItems(items, { query: 'aguada cajas' })), ['3']);
    assert.deepEqual(ids(filterMaterialItems(items, { query: '  caja   2.5  ' })), ['3']);
    assert.deepEqual(ids(filterMaterialItems(items, { query: 'caja inexistente' })), []);
});

test('filtra por tipo operacional, incluido «sin tipo», y puede ocultar inactivos', () => {
    assert.deepEqual(ids(filterMaterialItems(items, { type: 'material_pt' })), ['3']);
    assert.deepEqual(ids(filterMaterialItems(items, { type: 'sin_tipo' })), ['4']);
    assert.deepEqual(ids(filterMaterialItems(items, { query: 'cajas', includeInactive: false })), ['2']);
    assert.deepEqual(ids(filterMaterialItems(items, { query: 'insumo' })), ['1']);
});

test('el contador indica cuántos se ven del total', () => {
    assert.equal(materialItemsSummary(458, 458), '458 registrados');
    assert.equal(materialItemsSummary(12, 458), '12 de 458 registrados');
    assert.equal(normalizeSearchText('  Cartón ÁRBOL '), 'carton arbol');
});

test('la oficina de Materiales usa la búsqueda en la lista de ítems', () => {
    const script = readFileSync(new URL('../../resources/js/office-materials.js', import.meta.url), 'utf8');
    assert.match(script, /filterMaterialItems\(allItems, \{/);
    assert.match(script, /elements\.itemSearch\?\.addEventListener\('input', scheduleItemSearch\)/);
    assert.match(script, /materialItemsSummary\(items\.length, allItems\.length\)/);
});
