import assert from 'node:assert/strict';
import test from 'node:test';

import { createOfficeSessionSync } from '../../resources/js/shared/office-session-sync.js';

const tokenKey = 'estiba_wms_office_token';
const settle = () => new Promise((resolve) => setImmediate(resolve));

function deferred() {
    let release;
    const promise = new Promise((resolve) => { release = resolve; });
    return { promise, release };
}

function sessionEvent(target) {
    target.dispatchEvent(new Event('estiba:office-session'));
}

function storageEvent(target, key) {
    target.dispatchEvent(Object.assign(new Event('storage'), { key }));
}

test('inicio de sesión carga y cierre limpia sin esperar un temporizador', async () => {
    const windowTarget = new EventTarget();
    const loads = [];
    const clears = [];
    let token = null;
    const session = createOfficeSessionSync({
        tokenKey,
        getToken: () => token,
        windowTarget,
        onSessionChange: (next) => clears.push(next),
        load: async (startedToken) => { loads.push(startedToken); },
    });

    session.start();
    token = 'usuario-a';
    sessionEvent(windowTarget);
    await settle();
    assert.deepEqual(loads, ['usuario-a']);

    token = null;
    sessionEvent(windowTarget);
    assert.equal(clears.at(-1), null);
    assert.deepEqual(loads, ['usuario-a']);
    session.stop();
});

test('storage ignora otras claves y atiende el token de otra pestaña', async () => {
    const windowTarget = new EventTarget();
    const loads = [];
    let token = null;
    const session = createOfficeSessionSync({
        tokenKey,
        getToken: () => token,
        windowTarget,
        onSessionChange: () => {},
        load: async (startedToken) => { loads.push(startedToken); },
    });

    session.start();
    token = 'usuario-b';
    storageEvent(windowTarget, 'estiba_wms_office_identity');
    await settle();
    assert.deepEqual(loads, []);

    storageEvent(windowTarget, tokenKey);
    await settle();
    assert.deepEqual(loads, ['usuario-b']);
    session.stop();
});

test('storage con clave nula limpia la sesión tras localStorage.clear en otra pestaña', async () => {
    const windowTarget = new EventTarget();
    const clears = [];
    let token = 'usuario-a';
    const session = createOfficeSessionSync({
        tokenKey,
        getToken: () => token,
        windowTarget,
        onSessionChange: (next) => clears.push(next),
        load: async () => {},
    });

    session.start();
    await settle();
    token = null;
    storageEvent(windowTarget, null);
    assert.equal(clears.at(-1), null);
    session.stop();
});

test('recarga solicitada durante otra carga espera hasta publicar los datos nuevos', async () => {
    const first = deferred();
    const second = deferred();
    const loads = [];
    let published = 0;
    const session = createOfficeSessionSync({
        tokenKey,
        getToken: () => 'usuario-a',
        windowTarget: new EventTarget(),
        onSessionChange: () => {},
        load: async (token, isCurrent, showErrors) => {
            loads.push({ token, showErrors });
            await (loads.length === 1 ? first.promise : second.promise);
            if (isCurrent()) published += 1;
        },
    });

    session.start();
    await settle();
    const afterSave = session.reload(true);
    first.release();
    await settle();
    assert.deepEqual(loads, [
        { token: 'usuario-a', showErrors: false },
        { token: 'usuario-a', showErrors: true },
    ]);
    assert.equal(published, 1);
    let resolved = false;
    void afterSave.then(() => { resolved = true; });
    await settle();
    assert.equal(resolved, false);

    second.release();
    await afterSave;
    assert.equal(resolved, true);
    assert.equal(published, 2);
    session.stop();
});

test('varios cambios durante la carga producen solo una recarga con el último token', async () => {
    const windowTarget = new EventTarget();
    const first = deferred();
    const loads = [];
    let token = 'usuario-a';
    const session = createOfficeSessionSync({
        tokenKey,
        getToken: () => token,
        windowTarget,
        onSessionChange: () => {},
        load: async (startedToken) => {
            loads.push(startedToken);
            if (startedToken === 'usuario-a') await first.promise;
        },
    });

    session.start();
    await settle();
    token = 'usuario-b';
    sessionEvent(windowTarget);
    token = 'usuario-c';
    sessionEvent(windowTarget);
    assert.deepEqual(loads, ['usuario-a']);

    first.release();
    await settle();
    assert.deepEqual(loads, ['usuario-a', 'usuario-c']);
    session.stop();
});

test('la respuesta anterior no puede publicar datos luego de cambiar de sesión', async () => {
    const windowTarget = new EventTarget();
    const first = deferred();
    const visible = [];
    let token = 'usuario-a';
    const session = createOfficeSessionSync({
        tokenKey,
        getToken: () => token,
        windowTarget,
        onSessionChange: () => { visible.length = 0; },
        load: async (startedToken, isCurrent) => {
            if (startedToken === 'usuario-a') await first.promise;
            if (isCurrent()) visible.push(startedToken);
        },
    });

    session.start();
    await settle();
    token = 'usuario-b';
    sessionEvent(windowTarget);
    assert.deepEqual(visible, []);

    first.release();
    await settle();
    assert.deepEqual(visible, ['usuario-b']);
    session.stop();
});
