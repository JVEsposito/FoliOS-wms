import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

import { menuMemory, menuSelector } from '../../resources/js/shared/office-menu-memory.js';
import { DEFAULT_OFFICE_THEME, OFFICE_THEME_COLORS, normalizeOfficeTheme, officeThemeColorScheme } from '../../resources/js/shared/office-preferences.js';

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
    assert.match(navigation, /OFFICE_THEME_COLORS\[officeTheme\]/);
    for (const [theme, color] of Object.entries(OFFICE_THEME_COLORS)) {
        const identity = { rol: 'administrador', temas_personales: ['dark-bit', 'dark-bit-neon'] };
        const { root, metas } = boot({ local: {
            ...session,
            estiba_wms_office_identity: JSON.stringify(identity),
            estiba_wms_office_admin_theme: theme,
        } });
        assert.equal(root.attrs['data-office-theme'], normalizeOfficeTheme(theme, identity));
        assert.equal(metas['meta[name="theme-color"]'].attrs.content, color);
        assert.equal(metas['meta[name="color-scheme"]'].attrs.content, officeThemeColorScheme(theme));
    }
});

test('la preferencia rosada anterior se recupera como Princesa Estelar solo con la sesión de Administrador', () => {
    const local = { ...session, estiba_wms_office_theme: 'light-professional', estiba_wms_office_admin_theme: 'dark-rose' };
    const admin = { ...local, estiba_wms_office_identity: '{"rol":"administrador"}' };
    const { root, metas } = boot({ local: admin });
    assert.equal(root.attrs['data-office-theme'], 'dark-princess');
    assert.equal(metas['meta[name="color-scheme"]'].attrs.content, 'dark');
    assert.equal(metas['meta[name="theme-color"]'].attrs.content, OFFICE_THEME_COLORS['dark-princess']);
    assert.equal(boot({ local }).root.attrs['data-office-theme'], 'light-professional');
    assert.equal(boot({ local: admin, soloTema: true }).root.attrs['data-office-theme'], 'light-professional');
    assert.equal(boot({ local: { ...admin, estiba_wms_office_token: null } }).root.attrs['data-office-theme'], 'light-professional');
    assert.equal(boot({ local: { ...local, estiba_wms_office_theme: 'light-garden' } }).root.attrs['data-office-theme'], 'light-professional');
});

test('los modos bit y neón se aplican antes de pintar solo a la cuenta habilitada', () => {
    const local = { ...session, estiba_wms_office_theme: 'light-professional', estiba_wms_office_personal_theme: 'dark-bit' };
    const personal = { ...local, estiba_wms_office_identity: '{"rol":"supervisor_frio","temas_personales":["dark-bit"]}' };
    const { root, metas } = boot({ local: personal });
    assert.equal(root.attrs['data-office-theme'], 'dark-bit');
    assert.equal(metas['meta[name="color-scheme"]'].attrs.content, 'dark');
    assert.equal(metas['meta[name="theme-color"]'].attrs.content, '#1a1c2c');
    assert.equal(boot({ local }).root.attrs['data-office-theme'], 'light-professional');
    assert.equal(boot({ local: personal, soloTema: true }).root.attrs['data-office-theme'], 'light-professional');
    const neon = { ...personal, estiba_wms_office_personal_theme: 'dark-bit-neon' };
    assert.equal(boot({ local: neon }).root.attrs['data-office-theme'], 'dark-industrial', 'sin dark-bit-neon en la identidad no se aplica');
    const both = { ...neon, estiba_wms_office_identity: '{"temas_personales":["dark-bit","dark-bit-neon"]}' };
    assert.equal(boot({ local: both }).root.attrs['data-office-theme'], 'dark-bit-neon');
    assert.equal(boot({ local: both }).metas['meta[name="theme-color"]'].attrs.content, '#0b0820');
    const admin = { ...session, estiba_wms_office_theme: 'light-professional', estiba_wms_office_admin_theme: 'dark-bit-neon',
        estiba_wms_office_identity: '{"rol":"administrador","temas_personales":["dark-bit","dark-bit-neon"]}' };
    assert.equal(boot({ local: admin }).root.attrs['data-office-theme'], 'dark-bit-neon', 'el Administrador guarda bit y neón en su clave');
    const other = { ...session, estiba_wms_office_theme: 'dark-bit', estiba_wms_office_identity: '{"rol":"administrador"}' };
    assert.equal(boot({ local: other }).root.attrs['data-office-theme'], 'dark-industrial');
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
