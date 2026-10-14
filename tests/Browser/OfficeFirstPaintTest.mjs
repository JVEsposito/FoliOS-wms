import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
// EstructuraOficinaTest exporta la vista Blade después de compilar Vite.
const html = readFileSync(resolve(root, 'storage/app/ui/oficina/materia-prima.html'), 'utf8');
const token = '41|tokenDePruebaParaElPrimerPintado';
const identity = { nombre: 'Rosa Romana', rol: 'operador_romana' };

/**
 * Abre Materia Prima sin ejecutar sus módulos de Vite: así se ve exactamente lo que el
 * navegador pinta antes de que carguen, que es lo que aparecía al cambiar de pestaña.
 */
async function firstPaint(browser, storage) {
    const context = await browser.newContext({ viewport: { width: 1280, height: 800 } });
    await context.addInitScript(({ local, session }) => {
        if (sessionStorage.getItem('estiba_prueba_preparada')) return;
        sessionStorage.setItem('estiba_prueba_preparada', '1');
        Object.entries(local).forEach(([key, value]) => localStorage.setItem(key, value));
        Object.entries(session).forEach(([key, value]) => sessionStorage.setItem(key, value));
    }, storage);
    const visited = [];
    await context.route('http://localhost/**', (route) => {
        const { pathname, search } = new URL(route.request().url());
        visited.push(pathname + search);
        if (pathname.endsWith('.js')) return route.abort();
        if (pathname.startsWith('/build/')) {
            return route.fulfill({ body: readFileSync(resolve(root, `public${pathname}`)), contentType: 'text/css' });
        }
        if (pathname === '/oficina/materia-prima') return route.fulfill({ body: html, contentType: 'text/html' });
        if (pathname === '/FoliOS') return route.fulfill({ body: '<p id="entrada">FoliOS</p>', contentType: 'text/html' });
        return route.fulfill({ status: 404, body: '' });
    });
    const page = await context.newPage();
    await page.goto('http://localhost/oficina/materia-prima');
    return { context, page, visited };
}

test('con sesión, la oficina se pinta directo, con su tema y el menú del perfil', async () => {
    const browser = await chromium.launch({
        executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined,
        args: ['--no-sandbox', '--disable-gpu'],
    });
    try {
        const { context, page } = await firstPaint(browser, {
            local: {
                estiba_wms_office_token: token,
                estiba_wms_office_identity: JSON.stringify(identity),
                estiba_wms_office_theme: 'dark-industrial',
            },
            session: {
                estiba_wms_office_menu: JSON.stringify({
                    sesion: token.slice(-16),
                    ocultos: ['[data-domain-key="gerencia"]', '[data-domain-key="administracion"]'],
                }),
            },
        });
        const state = await page.evaluate(() => ({
            session: document.documentElement.dataset.officeSession,
            theme: document.documentElement.dataset.officeTheme,
            access: getComputedStyle(document.getElementById('officeAccess')).display,
            app: getComputedStyle(document.getElementById('officeApp')).display,
            sidebar: getComputedStyle(document.getElementById('officeSidebar')).position,
            gerencia: getComputedStyle(document.querySelector('[data-domain-key="gerencia"]')).display,
            materiales: getComputedStyle(document.querySelector('[data-domain-key="materiales"]')).display,
            name: document.getElementById('officeUserName').textContent,
            initials: document.getElementById('officeInitials').textContent,
        }));

        assert.equal(state.session, 'activa');
        assert.equal(state.theme, 'dark-industrial');
        assert.equal(state.access, 'none', 'el acceso propio de la oficina no se pinta');
        assert.equal(state.app, 'block');
        assert.notEqual(state.sidebar, 'static', 'el menú ya tiene sus estilos');
        assert.equal(state.gerencia, 'none', 'el menú recordado oculta las áreas que el perfil no tiene');
        assert.notEqual(state.materiales, 'none');
        assert.equal(state.name, 'Rosa Romana');
        assert.equal(state.initials, 'RR');
        await context.close();
    } finally {
        await browser.close();
    }
});

test('sin sesión, no pinta la oficina y va a /FoliOS con el regreso', async () => {
    const browser = await chromium.launch({
        executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined,
        args: ['--no-sandbox', '--disable-gpu'],
    });
    try {
        const { context, page, visited } = await firstPaint(browser, { local: {}, session: {} });
        await page.waitForURL('**/FoliOS?volver=%2Foficina%2Fmateria-prima');
        assert.equal(await page.locator('#entrada').textContent(), 'FoliOS');
        assert.ok(visited.includes('/FoliOS?volver=%2Foficina%2Fmateria-prima'));
        await context.close();
    } finally {
        await browser.close();
    }
});
