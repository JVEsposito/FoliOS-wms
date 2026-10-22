import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
const html = readFileSync(resolve(root, 'storage/app/ui/oficina/accesos.html'), 'utf8');
const source = readFileSync(resolve(root, 'resources/js/office-external-connections.js'), 'utf8');
const fixture = { id: '00000000-0000-4000-8000-000000000001', nombre: 'Origen de prueba', host: '127.0.0.2', puerto: 13306, usuario: 'lector_fixture', base_datos: 'origen_fixture', activa: true, version: 1, password_configurada: true, ultima_prueba_estado: null };

async function setup(browser, handler, admin = true) {
    const context = await browser.newContext({ viewport: { width: 1280, height: 800 } });
    await context.addInitScript((allowed) => {
        localStorage.setItem('estiba_wms_office_token', 'token_fixture');
        localStorage.setItem('estiba_wms_office_identity', JSON.stringify({ nombre: 'Administrador de prueba', puede_administrar_accesos: allowed }));
    }, admin);
    await context.route('http://localhost/**', async (route) => {
        const path = new URL(route.request().url()).pathname;
        if (path === '/oficina/accesos') return route.fulfill({ body: html, contentType: 'text/html' });
        if (path === '/shared/origin-explorer.js') return route.fulfill({ body: readFileSync(resolve(root, 'resources/js/shared/origin-explorer.js')), contentType: 'text/javascript' });
        if (path === '/fixture-external.js') return route.fulfill({ body: source, contentType: 'text/javascript' });
        // La sección se ejecuta sobre el HTML Blade real; el resto de los módulos no participa.
        if (path.endsWith('.js')) return route.abort();
        if (path.startsWith('/build/')) return route.fulfill({ body: readFileSync(resolve(root, `public${path}`)), contentType: path.endsWith('.css') ? 'text/css' : 'application/octet-stream' });
        if (path.startsWith('/api/administracion/conexiones-externas')) return handler(route);
        return route.fulfill({ status: 404, body: '' });
    });
    const page = await context.newPage();
    await page.goto('http://localhost/oficina/accesos');
    await page.addScriptTag({ url: 'http://localhost/fixture-external.js', type: 'module' });
    await page.waitForFunction(() => !document.getElementById('externalConnectionForm').hidden || document.getElementById('administration-tab-external-connections').classList.contains('is-hidden'));
    return { context, page };
}

async function openPanel(page) {
    await page.evaluate(() => {
        document.getElementById('administration-panel-external-connections').hidden = false;
        document.dispatchEvent(new CustomEvent('estiba:office-panel-change', { detail: { group: 'administration', panel: 'external-connections' } }));
    });
}

async function launch() {
    return chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
}

test('explora vistas, prueba sin filtros y descarta una muestra al perder la sesión', async () => {
    const browser = await launch();
    try {
        let release;
        let started;
        let delayed = false;
        const pending = new Promise((resolve) => { release = resolve; });
        const requested = new Promise((resolve) => { started = resolve; });
        const calls = [];
        const { context, page } = await setup(browser, async (route) => {
            const path = new URL(route.request().url()).pathname;
            const body = route.request().postDataJSON();
            if (route.request().method() === 'GET') return route.fulfill({ json: { data: [fixture] } });
            if (path.endsWith('/estructura')) return route.fulfill({ json: { data: [{ nombre: 'vista_existencias', tipo: 'VIEW' }, { nombre: 'catalogo', tipo: 'BASE TABLE' }] } });
            if (path.endsWith('/columnas')) return route.fulfill({ json: { data: [{ nombre: 'folio_real', tipo: 'varchar' }, { nombre: 'bodega_real', tipo: 'varchar' }] } });
            if (path.endsWith('/muestra')) {
                calls.push(body);
                if (delayed) { started(); await pending; }
                return route.fulfill({ json: { data: { columnas: ['folio_real', 'bodega_real'], filas: [{ folio_real: '<img src=x>', bodega_real: 'PLANTA' }], limite: 10 } } });
            }
            return route.fulfill({ json: {} });
        });
        await openPanel(page);
        await page.locator('[data-explore-connection]').click();
        await page.locator('#originObjects').selectOption('vista_existencias');
        await page.locator('#originColumns input').first().waitFor();
        await page.locator('#originPreview').click();
        await page.waitForFunction(() => document.getElementById('originStatus').textContent.includes('Lectura correcta'));
        assert.deepEqual(calls[0].filtros, []);
        assert.equal(await page.locator('#originSample img').count(), 0);
        assert.match(await page.locator('#originSample').textContent(), /<img src=x>/);
        await page.locator('#originFilterAdd').click();
        await page.locator('#originFilters select').selectOption('bodega_real');
        await page.locator('#originFilters input').fill('PLANTA');
        delayed = true;
        await page.locator('#originPreview').click();
        await requested;
        await page.evaluate(() => {
            localStorage.removeItem('estiba_wms_office_token');
            window.dispatchEvent(new Event('estiba:office-session'));
        });
        release();
        await page.waitForLoadState('networkidle');
        assert.deepEqual(calls[1].filtros, [{ columna: 'bodega_real', valor: 'PLANTA' }]);
        assert.equal(await page.locator('#originExplorer').isHidden(), true);
        assert.equal(await page.locator('#originSample').textContent(), '');
        await context.close();
    } finally { await browser.close(); }
});

test('formulario vacío, guardado, edición sin clave y prueba de la versión guardada', async () => {
    const browser = await launch();
    try {
        let items = [];
        const calls = [];
        const { context, page } = await setup(browser, async (route) => {
            const request = route.request();
            const payload = request.postDataJSON();
            calls.push({ method: request.method(), payload });
            if (request.method() === 'GET') return route.fulfill({ json: { data: items } });
            if (request.url().endsWith('/probar')) return route.fulfill({ json: { data: { ...items[0], ultima_prueba_estado: 'correcta' }, message: 'Conexión comprobada.' } });
            items = [{ ...fixture, ...payload, version: request.method() === 'PUT' ? 2 : 1 }];
            delete items[0].password;
            return route.fulfill({ status: request.method() === 'POST' ? 201 : 200, json: { data: items[0] } });
        });
        await openPanel(page);
        await page.waitForFunction(() => document.getElementById('externalConnectionsSummary').textContent === '0 registradas');
        for (const field of ['nombre', 'host', 'puerto', 'usuario', 'base_datos', 'password']) assert.equal(await page.locator(`#externalConnectionForm [name="${field}"]`).inputValue(), '');
        for (const field of ['nombre', 'host', 'puerto', 'usuario', 'base_datos']) await page.locator(`#externalConnectionForm [name="${field}"]`).fill(String(fixture[field]));
        await page.locator('#externalConnectionForm [name="password"]').fill('clave_solo_fixture');
        await page.evaluate(() => window.dispatchEvent(new Event('estiba:office-session')));
        assert.equal(await page.locator('#externalConnectionForm [name="password"]').inputValue(), 'clave_solo_fixture', 'refrescar la misma sesión conserva la edición');
        await page.locator('#externalConnectionForm [type="submit"]').click();
        await page.locator('[data-edit-connection]').waitFor();
        assert.equal(await page.locator('#externalConnectionForm [name="password"]').inputValue(), '');
        await page.locator('[data-edit-connection]').click();
        assert.equal(await page.locator('#externalConnectionForm [name="host"]').inputValue(), fixture.host);
        assert.equal(await page.locator('#externalConnectionForm [name="password"]').inputValue(), '');
        await page.locator('#externalConnectionForm [name="nombre"]').fill('Origen editado');
        await page.locator('#externalConnectionForm [type="submit"]').click();
        await page.waitForFunction(() => document.getElementById('externalConnectionsBody').textContent.includes('Origen editado') && !document.querySelector('[data-test-connection]').disabled);
        await page.locator('[data-test-connection]').click();
        await page.waitForFunction(() => document.getElementById('externalConnectionStatus').textContent === 'Conexión comprobada.');
        const update = calls.find((call) => call.method === 'PUT');
        assert.equal('password' in update.payload, false);
        assert.equal(update.payload.version_conocida, 1);
        assert.deepEqual(calls.findLast((call) => call.method === 'POST').payload, { version_conocida: 2 });
        const persisted = await page.evaluate(() => JSON.stringify({ local: { ...localStorage }, session: { ...sessionStorage } }));
        for (const value of ['clave_solo_fixture', fixture.host, fixture.usuario, fixture.base_datos]) assert.equal(persisted.includes(value), false);
        await context.close();
    } finally { await browser.close(); }
});

test('perder la sesión limpia el formulario y descarta una lista demorada', async () => {
    const browser = await launch();
    try {
        let release;
        let started;
        const pending = new Promise((resolve) => { release = resolve; });
        const requested = new Promise((resolve) => { started = resolve; });
        const { context, page } = await setup(browser, async (route) => {
            started();
            await pending;
            return route.fulfill({ json: { data: [fixture] } });
        });
        await openPanel(page);
        await requested;
        await page.locator('#externalConnectionForm [name="password"]').fill('clave_solo_fixture');
        await page.evaluate(() => {
            localStorage.removeItem('estiba_wms_office_token');
            localStorage.removeItem('estiba_wms_office_identity');
            window.dispatchEvent(new Event('estiba:office-session'));
        });
        release();
        await page.waitForLoadState('networkidle');
        assert.equal(await page.locator('#externalConnectionForm [name="password"]').inputValue(), '');
        assert.equal(await page.locator('#externalConnectionsBody').textContent(), '');
        assert.equal(await page.locator('#externalConnectionForm').isHidden(), true);
        assert.equal(await page.locator('#administration-tab-external-connections').isHidden(), true);
        await context.close();
    } finally { await browser.close(); }
});

test('un perfil sin administración no consulta las conexiones', async () => {
    const browser = await launch();
    try {
        const calls = [];
        const { context, page } = await setup(browser, (route) => { calls.push(route.request().url()); return route.fulfill({ json: { data: [fixture] } }); }, false);
        await openPanel(page);
        assert.equal(await page.locator('#administration-tab-external-connections').isHidden(), true);
        assert.equal(await page.locator('#externalConnectionForm').isHidden(), true);
        assert.deepEqual(calls, []);
        await context.close();
    } finally { await browser.close(); }
});
