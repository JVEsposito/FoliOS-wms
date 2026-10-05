import assert from 'node:assert/strict';
import test from 'node:test';
import { createLabelOperation } from '../../resources/js/shared/pt-label-operation.js';

test('reintentos de descarga conservan operación incluso si cambia el orden de la selección', () => {
    let calls = 0;
    const operations = createLabelOperation(() => `id-${++calls}`);
    const data = { tipo: 'ventana', copias: 1, validaciones: [{ id: 'b', version: '2' }, { id: 'a', version: '1' }] };
    assert.equal(operations.prepare(data).operacion_id, 'id-1');
    assert.equal(operations.prepare({ ...data, validaciones: [...data.validaciones].reverse() }).operacion_id, 'id-1');
    assert.equal(calls, 1);
});

test('cambiar datos, copias o completar la descarga crea una nueva operación', () => {
    let calls = 0;
    const operations = createLabelOperation(() => `id-${++calls}`);
    const data = { tipo: 'ventana', copias: 1, validaciones: [{ id: 'a', version: '1' }] };
    operations.prepare(data);
    assert.equal(operations.prepare({ ...data, copias: 2 }).operacion_id, 'id-2');
    assert.equal(operations.prepare({ ...data, validaciones: [{ id: 'a', version: '2' }] }).operacion_id, 'id-3');
    operations.clear();
    assert.equal(operations.prepare(data).operacion_id, 'id-4');
});
