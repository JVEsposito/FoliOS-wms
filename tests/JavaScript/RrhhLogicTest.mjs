import assert from 'node:assert/strict';
import test from 'node:test';
import { importRows, newOperationId, sourceConfiguration } from '../../resources/js/shared/rrhh-logic.js';

test('el lote acepta el contrato y rechaza contenido ambiguo o desbordado', () => {
    const rows = [{ id_externo: '001' }];
    assert.deepEqual(importRows(rows), rows); assert.deepEqual(importRows({ marcajes: rows }), rows);
    for (const value of [null, {}, [], [null], ['cadena'], new Array(1001).fill({})]) assert.throws(() => importRows(value));
});
test('los identificadores de operación permiten reintentos sin usar identificadores de personas', () => {
    const ids = new Set(Array.from({ length: 30 }, () => newOperationId()));
    assert.equal(ids.size, 30);
    for (const id of ids) assert.match(id, /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
});
test('editar la fuente no reemplaza credenciales por campos vacíos del navegador', () => {
    assert.deepEqual(sourceConfiguration({ base_url: '', password: '', api_key: '   ', nombre: 'fuente' }), {});
    assert.deepEqual(sourceConfiguration({ base_url: 'https://example.invalid', password: 'valor-ficticio' }), { base_url: 'https://example.invalid', password: 'valor-ficticio' });
});
