import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

import { menuMemory, menuSelector } from '../../resources/js/shared/office-menu-memory.js';
import { DEFAULT_OFFICE_THEME } from '../../resources/js/shared/office-preferences.js';

const source = readFileSync(new URL('../../resources/js/office-arranque.js', import.meta.url), 'utf8');

function element(attributes = {}) {
    const attrs = { ...attributes };
    return {
        attrs,
        hasAttribute: (name) => name in attrs,
        getAttribute: (name) => attrs[name] ?? null,
        setAttribute: (name, value) => { attrs[name] = String(value); },
    };
}

function boot({ local = {}, session = {}, path = '/oficina/materia-prima', search = '', soloTema = false } = {}) {
    const root = element();
    const metas = { 'meta[name="color-scheme"]': element(), 'meta[name="theme-color"]': element() };
    const appended = [];
    const replaced = [];
    const storage = (values) => ({ getItem: (key) => values[key] ?? null });
    const window = {
        localStorage: storage(local),
        sessionStorage: storage(session),
        location: { pathname: path, search, hash: '', replace: (url) => replaced.push(url) },
        document: {
            documentElement: root,
            currentScript: element(soloTema ? { 'data-solo-tema': '' } : {}),
            querySelector: (selector) => metas[selector] ?? null,
            createElement: () => ({ id: '', textContent: '' }),
            head: { appendChild: (node) => appended.push(node) },
        },
    };
    vm.runInNewContext(source, { window });
    return { root, metas, appended, replaced };
}

const session = { estiba_wms_office_token: '12|abcdefghijklmnopqrstuvwxyz', estiba_wms_office_identity: '{"nombre":"Ana"}' };

test('con sesión la oficina se pinta directo y con el tema guardado', () => {
    const { root, metas, replaced } = boot({ local: { ...session, estiba_wms_office_theme: 'dark-industrial' } });

    assert.equal(root.attrs['data-office-session'], 'activa');
    assert.equal(root.attrs['data-office-theme'], 'dark-industrial');
    assert.equal(metas['meta[name="color-scheme"]'].attrs.content, 'dark');
    assert.equal(metas['meta[name="theme-color"]'].attrs.content, '#0f1c24');
    assert.deepEqual(replaced, []);
});

test('el tema por omisión es el mismo de las preferencias de Oficina', () => {
    assert.equal(boot({ local: { ...session, estiba_wms_office_theme: 'otro' } }).root.attrs['data-office-theme'], DEFAULT_OFFICE_THEME);
    const navigation = readFileSync(new URL('../../resources/js/office-navigation.js', import.meta.url), 'utf8');
    assert.match(navigation, /'#0f1c24' : '#102f43'/);
    assert.match(source, /'#0f1c24' : '#102f43'/);
});

test('sin sesión no pinta la oficina y va a /FoliOS con el regreso', () => {
    const { root, replaced } = boot({ path: '/oficina/consultas/busqueda', search: '?q=F-1' });
    assert.equal(root.attrs['data-office-session'], 'ausente');
    assert.deepEqual(replaced, ['/FoliOS?volver=%2Foficina%2Fconsultas%2Fbusqueda%3Fq%3DF-1']);

    assert.deepEqual(boot({ path: '/otra' }).replaced, ['/FoliOS']);
    assert.deepEqual(boot({ local: { estiba_wms_office_token: 'x' } }).replaced.length, 1, 'sin identidad tampoco hay sesión');
});

test('la entrada única solo toma el tema', () => {
    const { root, replaced } = boot({ soloTema: true });
    assert.equal(root.attrs['data-office-theme'], 'light-professional');
    assert.equal(root.attrs['data-office-session'], undefined);
    assert.deepEqual(replaced, []);
});

test('oculta antes de pintar el menú recordado de la misma sesión', () => {
    const hidden = [
        element({ 'data-domain-key': 'gerencia' }),
        element({ 'data-office-key': 'romana', 'data-office-domain': 'materia-prima', 'data-navigation-permissions': 'puede_consultar_romana', 'data-navigation-module': 'materia-prima.romana' }),
        element({ 'data-navigation-permissions': 'x"]{}*{display:none' }),
    ];
    const memory = menuMemory(session.estiba_wms_office_token, hidden);
    assert.deepEqual(memory.ocultos, [
        '[data-domain-key="gerencia"]',
        '[data-office-key="romana"][data-office-domain="materia-prima"][data-navigation-permissions="puede_consultar_romana"][data-navigation-module="materia-prima.romana"]',
    ]);
    assert.equal(menuSelector(element({})), null);

    const tampered = { ...memory, ocultos: [...memory.ocultos, 'body{display:none}', '[data-x="a"]{}*'] };
    const { appended } = boot({ local: session, session: { estiba_wms_office_menu: JSON.stringify(tampered) } });
    assert.equal(appended.length, 1);
    assert.equal(appended[0].id, 'officeMenuRemembered');
    assert.equal(appended[0].textContent, `${memory.ocultos.join(',')}{display:none!important}`);

    const other = { ...memory, sesion: 'otra-sesion' };
    assert.equal(boot({ local: session, session: { estiba_wms_office_menu: JSON.stringify(other) } }).appended.length, 0);
    assert.equal(boot({ local: session, session: { estiba_wms_office_menu: '{roto' } }).appended.length, 0);
});

test('office.css oculta el acceso propio y muestra la oficina cuando hay sesión', () => {
    const css = readFileSync(new URL('../../resources/css/office.css', import.meta.url), 'utf8');
    assert.match(css, /html\[data-office-session="activa"\] \.office-access \{ display: none !important; \}/);
    assert.match(css, /html\[data-office-session="activa"\] \.office-app\.is-hidden \{ display: block !important; \}/);
    assert.match(css, /html\[data-office-session="ausente"\] body \{ visibility: hidden; \}/);
    assert.match(css, /:is\(\.loading, \.loading-overlay\):not\(\.is-hidden\) \{ animation: office-loading-in 160ms ease-out 350ms both; \}/);
});
