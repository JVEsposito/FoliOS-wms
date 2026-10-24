import assert from 'node:assert/strict';
import { webcrypto } from 'node:crypto';
import test from 'node:test';
import { createUuid } from '../../resources/js/shared/uuid.js';
import { AGROROSARIO_PLANT, buildPlantFromTemplate } from '../../resources/js/shared/plant-templates.js';

const uuidV4 = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;

test('HTTPS uses the native UUID implementation with its Crypto receiver', () => {
    const cryptoApi = { randomUUID() { assert.equal(this, cryptoApi); return 'native-uuid'; } };
    assert.equal(createUuid(cryptoApi), 'native-uuid');
});

test('HTTP fallback sets the UUID v4 version and variant without losing other random bits', () => {
    for (const [fill, expected] of [
        [0, '00000000-0000-4000-8000-000000000000'],
        [255, 'ffffffff-ffff-4fff-bfff-ffffffffffff'],
    ]) {
        const cryptoApi = { getRandomValues(bytes) { assert.equal(this, cryptoApi); assert.equal(bytes.length, 16); bytes.fill(fill); return bytes; } };
        assert.equal(createUuid(cryptoApi), expected);
    }
});

test('HTTP generates distinct cryptographic UUIDs when randomUUID is absent', () => {
    const cryptoApi = { getRandomValues: webcrypto.getRandomValues.bind(webcrypto) };
    const ids = Array.from({ length: 1000 }, () => createUuid(cryptoApi));
    ids.forEach((id) => assert.match(id, uuidV4));
    assert.equal(new Set(ids).size, ids.length);
});

test('a non-callable randomUUID still uses getRandomValues', () => {
    assert.match(createUuid({ randomUUID: null, getRandomValues: webcrypto.getRandomValues.bind(webcrypto) }), uuidV4);
});

test('an unsupported browser never substitutes weak operation identifiers', () => {
    for (const cryptoApi of [null, {}, { randomUUID: false, getRandomValues: false }]) {
        assert.throws(() => createUuid(cryptoApi), /identificadores seguros/);
    }
});

test('plant templates use HTTP-compatible IDs by default and preserve connection references', (t) => {
    const original = Object.getOwnPropertyDescriptor(globalThis, 'crypto');
    Object.defineProperty(globalThis, 'crypto', { configurable: true, value: { getRandomValues: webcrypto.getRandomValues.bind(webcrypto) } });
    t.after(() => Object.defineProperty(globalThis, 'crypto', original));
    const result = buildPlantFromTemplate(AGROROSARIO_PLANT);
    const ids = new Set(result.elementos.map((element) => element.id));
    result.elementos.forEach((element) => assert.match(element.id, uuidV4));
    result.conexiones.forEach((connection) => {
        assert.match(connection.id, uuidV4);
        assert.ok(ids.has(connection.desde));
        assert.ok(ids.has(connection.hacia));
    });
});
