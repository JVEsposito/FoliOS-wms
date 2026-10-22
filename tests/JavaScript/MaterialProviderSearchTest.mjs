import assert from 'node:assert/strict';
import test from 'node:test';
import { filterMaterialProviders } from '../../resources/js/shared/material-provider-search.js';

const providers = [
    { id: '1', codigo_interno: 'PRV-001', codigo: 'CSF-01', nombre: 'Cartón San Fernando', activo: true, clientes: [{ id: 'aguada', codigo: 'LAG', nombre: 'La Aguada', categorias: ['Cajas'] }] },
    { id: '2', codigo_interno: 'PRV-002', codigo: 'ADH', nombre: 'Adhesivos del Sur', activo: false, clientes: [{ id: 'falco', codigo: 'FAL', nombre: 'Falco' }] },
    { id: '3', codigo_interno: 'PRV-003', codigo: 'FILM', nombre: 'Embalajes del Sur', activo: true, clientes: [{ id: 'aguada', nombre: 'La Aguada' }, { id: 'falco', nombre: 'Falco' }] },
];
const ids = (filters) => filterMaterialProviders(providers, filters).map((provider) => provider.id);

test('encuentra códigos internos y comerciales aunque se omitan separadores', () => {
    assert.deepEqual(ids({ query: 'prv001' }), ['1']);
    assert.deepEqual(ids({ query: 'csf01' }), ['1']);
    assert.deepEqual(ids({ query: 'CSF_01' }), ['1']);
});

test('combina palabras del proveedor y su cliente sin distinguir tildes', () => {
    assert.deepEqual(ids({ query: 'CARTON aguada' }), ['1']);
    assert.deepEqual(ids({ query: 'sur falco' }), ['2', '3']);
    assert.deepEqual(ids({ query: 'cajas aguada' }), ['1']);
});

test('combina cliente y estado, incluidos proveedores con varios clientes', () => {
    assert.deepEqual(ids({ clientId: 'falco', status: 'activo' }), ['3']);
    assert.deepEqual(ids({ status: 'inactivo' }), ['2']);
    assert.deepEqual(ids({ clientId: 'aguada', query: 'sur', status: 'activo' }), ['3']);
    assert.deepEqual(ids({ clientId: 'falco', query: 'carton' }), []);
});

test('limpiar los filtros recupera el catálogo completo sin modificar sus registros', () => {
    const before = structuredClone(providers);
    assert.deepEqual(ids({ query: '  ' }), ['1', '2', '3']);
    ids({ query: 'sin resultados' });
    assert.deepEqual(providers, before);
    assert.deepEqual(ids(), ['1', '2', '3']);
});
