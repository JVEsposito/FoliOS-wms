import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

import {
    entryFromResponse,
    FOLIOS_ENTRY_PATH,
    loginErrorMessage,
    officeHomeHref,
    officeHomePath,
} from '../../resources/js/shared/folios-entry.js';

test('solo acepta rutas internas de Oficina como inicio', () => {
    assert.equal(officeHomePath('/oficina/romana'), '/oficina/romana');
    assert.equal(officeHomePath('/oficina/frigorifico/despacho/cargas'), '/oficina/frigorifico/despacho/cargas');

    for (const value of ['https://ejemplo.cl/oficina', '//ejemplo.cl/oficina', '/oficina/../api', '/oficina?x=1', '/api/camaras', '', null, 42]) {
        assert.equal(officeHomePath(value), null, String(value));
    }
});

test('el logo lleva al inicio del usuario o a la entrada única', () => {
    assert.equal(officeHomeHref({ inicio: '/oficina/materiales' }), '/oficina/materiales');
    assert.equal(officeHomeHref({ rol: 'despachador' }), FOLIOS_ENTRY_PATH);
    assert.equal(officeHomeHref(null), '/FoliOS');
});

test('una sesión vigente con inicio redirige y guarda la identidad', () => {
    const usuario = { nombre: 'Ana', inicio: '/oficina/romana' };

    assert.deepEqual(entryFromResponse(200, { usuario }), {
        view: 'redirect',
        destination: '/oficina/romana',
        identity: usuario,
    });
});

test('una sesión sin oficinas habilitadas no redirige', () => {
    const usuario = { nombre: 'Ana', inicio: null };

    assert.deepEqual(entryFromResponse(200, { usuario }), { view: 'sin-oficinas', identity: usuario });
});

test('una sesión vencida o sin acceso vuelve al formulario y limpia la sesión', () => {
    assert.deepEqual(entryFromResponse(401, {}), { view: 'acceso', clearSession: true, message: '' });

    const revoked = entryFromResponse(403, {});
    assert.equal(revoked.view, 'acceso');
    assert.equal(revoked.clearSession, true);
    assert.match(revoked.message, /ya no tiene acceso/);
});

test('sin conexión o con error del servidor ofrece reintentar sin borrar la sesión', () => {
    for (const status of [0, 500]) {
        const entry = entryFromResponse(status, null);
        assert.equal(entry.view, 'error');
        assert.equal(entry.clearSession, undefined);
        assert.ok(entry.message.length > 0);
    }
});

test('mensajes del inicio de sesión fallido', () => {
    assert.equal(
        loginErrorMessage(422, { errors: { email: ['Las credenciales ingresadas no son válidas.'] } }),
        'Las credenciales ingresadas no son válidas.',
    );
    assert.match(loginErrorMessage(429, { message: 'Too Many Attempts.' }), /Demasiados intentos/);
    assert.match(loginErrorMessage(0, null), /No fue posible conectar/);
    assert.match(loginErrorMessage(500, {}), /No fue posible iniciar sesión/);
});

test('la página de inicio y el menú usan la misma sesión de Oficina', () => {
    const page = readFileSync(new URL('../../resources/js/folios-inicio.js', import.meta.url), 'utf8');
    const navigation = readFileSync(new URL('../../resources/js/office-navigation.js', import.meta.url), 'utf8');
    const entry = readFileSync(new URL('../../resources/js/shared/folios-entry.js', import.meta.url), 'utf8');

    assert.match(entry, /OFFICE_TOKEN_KEY = 'estiba_wms_office_token'/);
    assert.match(entry, /OFFICE_IDENTITY_KEY = 'estiba_wms_office_identity'/);
    assert.match(navigation, /const tokenKey = 'estiba_wms_office_token'/);
    assert.match(navigation, /const identityKey = 'estiba_wms_office_identity'/);
    assert.match(page, /request\('\/api\/acceso-oficina', \{ token \}\)/);
    assert.match(page, /window\.location\.replace\(entry\.destination\)/);
});
