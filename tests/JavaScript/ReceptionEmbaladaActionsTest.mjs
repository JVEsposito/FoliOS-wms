import assert from 'node:assert/strict';
import test from 'node:test';
import { createReceptionActions } from '../../resources/js/shared/recepcion-embalada-actions.js';

test('aceptación conserva la operación ante descarga fallida y renueva al cambiar la revisión', async () => {
    const bodies = []; let calls = 0; let fail = true;
    const actions = createReceptionActions(async (path, options) => {
        bodies.push(JSON.parse(options.body));
        if (fail) { fail = false; throw new Error('Conexión interrumpida'); }
        return { estado: 'aceptada' };
    }, () => `op-${++calls}`);
    await assert.rejects(actions.accept('recepcion', 'v1'));
    await actions.accept('recepcion', 'v1');
    assert.equal(bodies[0].operacion_id, bodies[1].operacion_id);
    await actions.accept('recepcion', 'v2');
    assert.equal(bodies[2].operacion_id, 'op-2');
});

test('anulación envía el motivo y la lectura no muta la recepción', async () => {
    const requests = [];
    const actions = createReceptionActions(async (...args) => { requests.push(args); return {}; }, () => 'operation');
    await actions.load('recepcion');
    await actions.annul('recepcion', '  Guía errónea  ');
    assert.equal(requests[0][1], undefined);
    assert.equal(JSON.parse(requests[1][1].body).motivo, 'Guía errónea');
});
