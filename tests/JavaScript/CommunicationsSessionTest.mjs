import test from 'node:test';
import assert from 'node:assert/strict';
import { communicationsSession, createCommunicationsClient } from '../../resources/js/shared/communications-client.js';

function session(user = { id: 1, rol: 'consulta' }, token = 'token-a') {
    globalThis.localStorage = { getItem: key => key.endsWith('_token') ? token : JSON.stringify(user) };
}
test('comunicaciones: una cuenta de cliente o una sesión incompleta no abre el canal interno', () => {
    session({ id: 2, rol: 'cliente' }); assert.equal(communicationsSession(), null);
    session({ id: 2, rol: 'consulta', es_cliente: true }); assert.equal(communicationsSession(), null);
    session({ id: 1, rol: 'consulta' }, ''); assert.equal(communicationsSession(), null);
    session(); assert.equal(communicationsSession().id, 1);
});
test('comunicaciones: un mensaje privado demorado se descarta después de cambiar de usuario', async () => {
    session(); let resolve;
    globalThis.fetch = () => new Promise(r => { resolve = r; });
    const client = createCommunicationsClient();
    const pending = client.request('/api/comunicaciones/chat/conversaciones/4/mensajes');
    const rejected = assert.rejects(pending, { name: 'AbortError' });
    session({ id: 3, rol: 'consulta' }, 'token-b'); client.reset();
    resolve(new Response(JSON.stringify({ data: [{ texto: 'Privado del usuario anterior' }] }), { headers: { 'Content-Type': 'application/json' } }));
    await rejected;
});
test('comunicaciones: cancela errores tardíos de la sesión anterior y conserva el transporte privado', async () => {
    session(); let reject;
    globalThis.fetch = () => new Promise((_, r) => { reject = r; });
    const client = createCommunicationsClient();
    const pending = client.request('/api/comunicaciones/resumen');
    const rejected = assert.rejects(pending, { name: 'AbortError' });
    client.reset(); reject(new Error('Error de la sesión anterior')); await rejected;
    let captured;
    globalThis.fetch = async (url, options) => { captured = { url, options }; return new Response('{"data":{"id":1}}', { headers: { 'Content-Type': 'application/json' } }); };
    const body = new FormData(); body.append('nombre', 'Sticker');
    await client.request('/api/comunicaciones/stickers', { method: 'POST', body });
    assert.equal(captured.url, '/api/comunicaciones/stickers');
    assert.equal(captured.options.headers.Authorization, 'Bearer token-a');
    assert.equal(captured.options.body, body);
    assert.equal(captured.options.headers['Content-Type'], undefined);
    await assert.rejects(client.request('https://example.com/datos'), /inválida/);
});
