import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve, extname } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
const html = readFileSync(resolve(root, 'storage/app/ui/oficina/estatus.html'), 'utf8');
const load = () => ({ id: 'load-a', codigo: 'Carga A', estado: 'en_preparacion', prioridad: 'alta', total_folios: 5,
    progreso: { porcentaje: 60, concentrados: 3, faltantes: 2, en_anden: 0, dependencias: [
        { numero_folio: '1001', origen: 'Túnel 1', responsable: 'Camarero A' },
        { numero_folio: '1002', origen: 'Túnel 1', responsable: 'Camarero A' },
    ] }, folios: [], distribucion: [] });

for (const width of [1280, 390]) test(`Oficina distingue acopio físico de recepciones y actualiza al entregar en ${width}px`, async () => {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined,
        args: ['--no-sandbox', '--disable-gpu'] });
    try {
        const context = await browser.newContext({ viewport: { width, height: 900 } });
        await context.addInitScript(() => {
            localStorage.setItem('estiba_wms_office_token', 'fixture');
            localStorage.setItem('estiba_wms_office_identity', JSON.stringify({ nombre: 'Consulta fixture', rol: 'consulta', puede_consultar_cargas: true }));
        });
        let current = load();
        await context.route('http://localhost/**', async (route) => {
            const path = new URL(route.request().url()).pathname;
            assert.equal(route.request().method(), 'GET');
            if (path === '/oficina/frigorifico/despacho/estatus') return route.fulfill({ body: html, contentType: 'text/html' });
            if (path.startsWith('/build/') || path.startsWith('/brand/')) {
                try { return route.fulfill({ body: readFileSync(resolve(root, `public${path}`)), contentType:
                    ({ '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml', '.woff2': 'font/woff2' })[extname(path)] || 'application/octet-stream' }); }
                catch { return route.fulfill({ status: 404 }); }
            }
            if (path === '/api/cargas/pendientes') return route.fulfill({ json: { data: [current] } });
            if (path === '/api/oficina/contexto') return route.fulfill({ json: { data: { planta: 'Fixture', temporada: null, avisos_cierre: [], avisos_materiales: [] } } });
            return route.fulfill({ json: { data: [] } });
        });
        const page = await context.newPage(); const errors = [];
        page.on('pageerror', (error) => errors.push(error.message));
        await page.goto('http://localhost/oficina/frigorifico/despacho/estatus');
        await page.locator('#dispatchMessage').filter({ hasText: 'Túnel 1 (2)' }).waitFor();
        assert.match(await page.locator('#dispatchMessage').innerText(), /2 pallets pendientes de recepción.*Reciben: Camarero A/);
        assert.equal(await page.locator('#concentrationValue').innerText(), '60%');
        current = { ...current, progreso: { porcentaje: 100, concentrados: 5, faltantes: 0, en_anden: 0, dependencias: [] } };
        await page.locator('#reloadLoadStatus').click();
        await page.locator('#concentrationValue').filter({ hasText: '100%' }).waitFor();
        assert.doesNotMatch(await page.locator('#dispatchMessage').innerText(), /pendientes de recepción|Túnel 1/);
        assert.deepEqual(errors, []);
        await context.close();
    } finally { await browser.close(); }
});
