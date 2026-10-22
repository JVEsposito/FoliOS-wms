import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
const partial = readFileSync(resolve(root, 'resources/views/office/partials/material-reception-transport.blade.php'), 'utf8');
const styles = readFileSync(resolve(root, 'resources/css/office-materials.css'), 'utf8');
const script = readFileSync(resolve(root, 'resources/js/office-material-reception-transport.js'), 'utf8');
const driver = { id: 'driver', rut: '11111111-1', nombre: 'Juan Pérez', telefono: '111', version: 2 };
const trip = { conductor: driver, empresa: { rut: '76123456-0', nombre: 'Transportes Sur', telefono: '222', version: 1 },
    vehiculo: { patente: 'ABCD12', descripcion: 'Camión azul', version: 1 }, patente_carro: 'XY1234' };

async function fixture(width, handler) {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
    const page = await browser.newPage({ viewport: { width, height: 900 } });
    const errors = []; page.on('pageerror', error => errors.push(error.message));
    await page.route('http://localhost/**', async route => {
        const url = new URL(route.request().url());
        if (url.pathname === '/test') return route.fulfill({ contentType: 'text/html', body: `<style>:root{--line:#888;--muted:#444;--text:#111;--deep:#fff}*{box-sizing:border-box}body{margin:12px}form{max-width:960px;margin:auto}${styles}</style><form class="material-reception-form">${partial}</form>` });
        if (url.pathname === '/transport.js') return route.fulfill({ contentType: 'text/javascript', body: script });
        return handler(route, url);
    });
    await page.goto('http://localhost/test');
    await page.evaluate(async () => {
        const { createReceptionTransport } = await import('/transport.js');
        window.transport = createReceptionTransport(document.querySelector('form'), async path => {
            const response = await fetch(path);
            const body = await response.json();
            if (!response.ok) throw new Error(body.message);
            return body;
        });
        window.transport.populate(null);
    });
    return { browser, page, errors };
}

test('RUT recupera conductor, permite otro camión y separa corrección del viaje de actualización de la ficha', async () => {
    for (const width of [1366, 390]) {
        const { browser, page, errors } = await fixture(width, async (route, url) => route.fulfill({ json: url.pathname.endsWith('/conductor')
            ? { data: driver, ultimo_viaje: { guia: 'GD-1', transporte: trip } } : { data: null } }));
        try {
            const field = name => page.locator(`[name="${name}"]`);
            assert.deepEqual(await page.evaluate(() => window.transport.payload()), {});
            await field('conductor_rut').fill('11.111.111-1');
            await page.locator('[data-transport-lookup="conductor"]').click();
            await page.waitForFunction(() => document.querySelector('[name="conductor_nombre"]').value === 'Juan Pérez');
            await page.locator('#useMaterialTransportLastTrip').click();
            assert.equal(await field('patente').inputValue(), 'ABCD12');
            assert.equal(await field('transportista').inputValue(), 'Transportes Sur');
            await field('patente').fill('WX-YZ 34');
            await page.locator('[data-transport-lookup="vehiculo"]').click();
            await page.waitForFunction(() => document.querySelector('[data-transport-status="vehiculo"]').textContent.startsWith('Sin registro'));
            await field('vehiculo_descripcion').fill('Camión nuevo');
            await field('conductor_telefono').fill('999');
            let data = await page.evaluate(() => window.transport.payload().transporte);
            assert.equal(data.conductor.actualizar_directorio, false);
            assert.equal(data.conductor.telefono, '999');
            assert.equal(data.vehiculo.patente, 'WXYZ34');
            assert.equal(data.vehiculo.descripcion, 'Camión nuevo');
            assert.equal(data.patente_carro, 'XY1234');
            await field('conductor_actualizar').check();
            data = await page.evaluate(() => window.transport.payload().transporte);
            assert.equal(data.conductor.actualizar_directorio, true);
            assert.equal(data.conductor.version_conocida, 2);
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false);
            await page.evaluate(trip => { window.transport.populate({ transporte: trip }); window.transport.setReadonly(true); }, trip);
            assert.equal(await field('conductor_rut').isDisabled(), true);
            assert.equal(await field('conductor_actualizar').isDisabled(), true);
            assert.deepEqual(errors, []);
        } finally { await browser.close(); }
    }
});

test('una búsqueda demorada no reemplaza al conductor nuevo ni un expediente que se volvió a abrir', async () => {
    let release; const pending = new Promise(resolve => { release = resolve; });
    const { browser, page, errors } = await fixture(1366, async (route, url) => {
        if (url.searchParams.get('rut') === '11111111-1') await pending;
        return route.fulfill({ json: { data: url.searchParams.get('rut') === '11111111-1' ? driver
            : { ...driver, rut: '22222222-2', nombre: 'Otra persona' } } });
    });
    try {
        const started = page.waitForRequest(request => request.url().includes('rut=11111111-1'));
        await page.locator('[name="conductor_rut"]').fill('11111111-1');
        await page.locator('[data-transport-lookup="conductor"]').click();
        await started;
        await page.locator('[name="conductor_rut"]').fill('22222222-2');
        await page.locator('[data-transport-lookup="conductor"]').click();
        await page.waitForFunction(() => document.querySelector('[name="conductor_nombre"]').value === 'Otra persona');
        assert.equal(await page.locator('[name="conductor_nombre"]').inputValue(), 'Otra persona');
        await page.evaluate(() => window.transport.populate({ transporte: { conductor: { rut: '33333333-3', nombre: 'Expediente reabierto', version: 1 } } }));
        const response = page.waitForResponse(response => response.url().includes('rut=11111111-1'));
        release(); await response;
        await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
        assert.equal(await page.locator('[name="conductor_nombre"]').inputValue(), 'Expediente reabierto');
        assert.deepEqual(errors, []);
    } finally { release(); await browser.close(); }
});
