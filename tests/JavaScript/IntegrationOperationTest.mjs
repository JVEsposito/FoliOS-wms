import assert from 'node:assert/strict';
import test from 'node:test';
import { waitIntegrationOperation } from '../../resources/js/shared/integration-operation.js';

const id = '00000000-0000-4000-8000-000000000001';
const accepted = { operacion: { id, estado: 'en_cola', fase: 'En cola' } };
const done = { id, estado: 'correcta', fase: 'Finalizada', respuesta: { codigo_http: 200, contenido: { data: [{ Pallet: '00021', disponibilidad: null }] } } };

test('consulta avance con GET y entrega el resultado sin repetir el POST', async () => {
    const phases = [];
    const calls = [];
    const operations = [{ id, estado: 'en_curso', fase: 'Consultando' }, done];
    const result = await waitIntegrationOperation(accepted, {
        wait: async () => {}, onProgress: (op) => phases.push(op.estado),
        fetcher: async (url, options) => { calls.push([url, options]); return { ok: true, json: async () => ({ operacion: operations.shift() }) }; },
    });
    assert.deepEqual(result, done.respuesta.contenido);
    assert.deepEqual(phases, ['en_cola', 'en_curso', 'correcta']);
    assert.equal(calls.length, 2);
    assert.ok(calls.every(([url, opts]) => url === `/api/integraciones/operaciones/${id}` && opts.method === 'GET' && opts.cache === 'no-store'));
    assert.equal(result.data[0].disponibilidad, null);
});

test('descarta la respuesta si cambia la sesión o selección durante la espera', async () => {
    let valid = true;
    await assert.rejects(waitIntegrationOperation(accepted, {
        wait: async () => {}, valid: () => valid,
        fetcher: async () => { valid = false; return { ok: true, json: async () => ({ operacion: done }) }; },
    }), { name: 'AbortError' });
});

test('muestra fallo y resultado vencido sin reintentar la operación', async () => {
    for (const code of [409, 410, 422]) {
        await assert.rejects(waitIntegrationOperation({ operacion: { ...done, estado: 'fallida', respuesta: { codigo_http: code, contenido: { message: 'Consulta no publicada' } } } }), { message: 'Consulta no publicada', status: code });
    }
});

test('acota el sondeo pendiente y no sigue URLs arbitrarias', async () => {
    let requests = 0;
    await assert.rejects(waitIntegrationOperation(accepted, { attempts: 2, wait: async () => {}, fetcher: async () => { requests++; return { ok: true, json: async () => accepted }; } }), /sigue pendiente/);
    assert.equal(requests, 2);
    await assert.rejects(waitIntegrationOperation({ operacion: { ...accepted.operacion, id: 'https://otro.example' } }), /Identificador/);
});
