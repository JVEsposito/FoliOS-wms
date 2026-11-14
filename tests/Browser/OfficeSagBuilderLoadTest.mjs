import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { extname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
const identity = { nombre: 'Jefatura', rol: 'administrador', modulos_acceso: ['frigorifico.inspeccion-sag'] };
const catalogs = {
    tipos_lote: [{ value: 'inspeccion_origen', label: 'Inspección Origen', tipo_aprobacion: 'AO' }],
    tipos_aprobacion: [{ value: 'AO', label: 'Aprobado Origen' }],
    bloques: [{ id: 'b1', codigo: 'UE', nombre: 'Unión Europea', paises: [] }],
    paises: [{ id: 'p1', codigo: 'CN', nombre: 'China', es_iso_oficial: true }],
};
const options = { clientes: [{ id: 'c1', codigo: 'AGU', nombre: 'La Aguada', especies: [{ id: 'e1', nombre: 'Cereza', variedades: [], csg: [] }] }], condiciones_termicas: [] };

async function open(browser, failing) {
    const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    await context.addInitScript((user) => {
        localStorage.setItem('estiba_wms_office_token', 'fixture');
        localStorage.setItem('estiba_wms_office_identity', JSON.stringify(user));
    }, identity);
    const html = readFileSync(resolve(root, 'storage/app/ui/oficina/inspeccion-sag.html'), 'utf8');
    let fail = failing;
    await context.route('http://localhost/**', (route) => {
        const path = new URL(route.request().url()).pathname;
        if (path === '/oficina/frigorifico/inspeccion-sag') return route.fulfill({ body: html, contentType: 'text/html' });
        if (path.startsWith('/build/') || path.startsWith('/brand/')) {
            try { return route.fulfill({ body: readFileSync(resolve(root, `public${path}`)), contentType: ({ '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml', '.woff2': 'font/woff2' })[extname(path)] || 'application/octet-stream' }); } catch { return route.fulfill({ status: 404 }); }
        }
        if (fail && path === fail) return route.fulfill({ status: 500, json: { message: 'Server Error' } });
        if (path === '/api/inspeccion-sag/resumen') return route.fulfill({ json: { lotes_activos: 2, pallets_en_inspeccion: 10, finalizados_hoy: 0, autorizaciones_hoy: 0 } });
        if (path === '/api/inspeccion-sag/catalogos') return route.fulfill({ json: catalogs });
        if (path === '/api/inspeccion-sag/folios/opciones') return route.fulfill({ json: options });
        if (path === '/api/inspeccion-sag/lotes') return route.fulfill({ json: { data: [] } });
        return route.fulfill({ json: { data: {} } });
    });
    const page = await context.newPage(); const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await page.goto('http://localhost/oficina/frigorifico/inspeccion-sag');
    return { context, page, errors, recover: () => { fail = null; } };
}

const launch = () => chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });

test('si falla una consulta, el armado de lotes conserva el resto y muestra el error', async () => {
    const browser = await launch();
    try {
        const { context, page, errors, recover } = await open(browser, '/api/inspeccion-sag/folios/opciones');
        const banner = page.locator('#sagLoadError');
        await banner.waitFor();
        assert.match(await banner.innerText(), /clientes y especies: Error del servidor \(código 500\)/);
        // Tipos y destinos llegan aunque fallen las opciones de cliente.
        await page.locator('#sagBuilderForm [name="tipo"] option[value="inspeccion_origen"]').waitFor({ state: 'attached' });
        await page.waitForFunction(() => document.querySelector('#activeLotsMetric').textContent === '2');
        assert.equal(await page.locator('#sagBuilderForm [name="tipo"] option').count(), 2);
        assert.match(await page.locator('#destinationOptions').innerText(), /CN · China/);
        assert.equal(await page.locator('#activeLotsMetric').innerText(), '2');

        recover();
        await page.locator('#reloadSagButton').click();
        await page.waitForFunction(() => document.querySelector('#sagLoadError').hidden);
        await page.locator('[data-sag-panel="nueva"]').click();
        await page.locator('#sagBuilderForm [name="cliente"]').selectOption('c1');
        assert.equal(await page.locator('#sagBuilderForm [name="especie"] option').count(), 2);
        assert.deepEqual(errors, []);
        await context.close();
    } finally { await browser.close(); }
});

test('una consulta que no responde termina en aviso y no bloquea el resto', async () => {
    const browser = await launch();
    try {
        const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
        await context.addInitScript((user) => {
            localStorage.setItem('estiba_wms_office_token', 'fixture');
            localStorage.setItem('estiba_wms_office_identity', JSON.stringify(user));
        }, identity);
        const page = await context.newPage();
        await page.clock.install();
        const html = readFileSync(resolve(root, 'storage/app/ui/oficina/inspeccion-sag.html'), 'utf8');
        await context.route('http://localhost/**', (route) => {
            const path = new URL(route.request().url()).pathname;
            if (path === '/oficina/frigorifico/inspeccion-sag') return route.fulfill({ body: html, contentType: 'text/html' });
            if (path.startsWith('/build/')) return route.fulfill({ body: readFileSync(resolve(root, `public${path}`)), contentType: ({ '.js': 'text/javascript', '.css': 'text/css' })[extname(path)] || 'application/octet-stream' });
            if (path === '/api/inspeccion-sag/folios/opciones') return undefined; // nunca responde
            if (path === '/api/inspeccion-sag/catalogos') return route.fulfill({ json: catalogs });
            if (path === '/api/inspeccion-sag/resumen') return route.fulfill({ json: { lotes_activos: 2 } });
            if (path === '/api/inspeccion-sag/lotes') return route.fulfill({ json: { data: [] } });
            return route.fulfill({ json: {} });
        });
        const pendingOptions = page.waitForRequest('**/api/inspeccion-sag/folios/opciones');
        await page.goto('http://localhost/oficina/frigorifico/inspeccion-sag');
        await pendingOptions;
        await page.locator('#sagBuilderForm [name="tipo"] option[value="inspeccion_origen"]').waitFor({ state: 'attached' });
        await page.waitForFunction(() => document.querySelector('#activeLotsMetric').textContent === '2');
        assert.equal(await page.locator('#sagLoadError').isHidden(), true);
        await page.clock.fastForward(31000);
        await page.locator('#sagLoadError').waitFor();
        assert.match(await page.locator('#sagLoadError').innerText(), /clientes y especies: El servidor no respondió en 30 segundos/);
        await context.close();
    } finally { await browser.close(); }
});

test('con todo disponible no aparece aviso de error', async () => {
    const browser = await launch();
    try {
        const { context, page, errors } = await open(browser, null);
        await page.locator('#sagBuilderForm [name="cliente"] option[value="c1"]').waitFor({ state: 'attached' });
        assert.equal(await page.locator('#sagLoadError').isHidden(), true);
        assert.deepEqual(errors, []);
        await context.close();
    } finally { await browser.close(); }
});
