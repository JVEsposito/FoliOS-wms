import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { extname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
const html = readFileSync(resolve(root, 'storage/app/ui/oficina/consultas-busqueda.html'), 'utf8');

test('búsqueda: la tarjeta del folio muestra sus cajas junto al tipo de bulto y la condición térmica', async () => {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
    try {
        for (const width of [1280, 1024]) {
            const context = await browser.newContext({ viewport: { width, height: 900 } });
            const identity = { id: 1, nombre: 'Supervisor', rol: 'supervisor_frio', puede_consultar_oficina_consultas: true, modulos_acceso: ['consultas.busqueda'] };
            await context.addInitScript((user) => {
                localStorage.setItem('estiba_wms_office_token', 'fixture');
                localStorage.setItem('estiba_wms_office_identity', JSON.stringify(user));
            }, identity);
            const errors = [];
            const folio = (numero, cajas) => ({ id: numero, numero, cantidad_cajas: cajas, tipo_bulto: 'pallet', estado: 'pendiente_ubicacion', condicion_termica: 'prefrio_aprobado',
                variedad: 'SANTINA', calibre: '2JD', marca: 'GOLD', exportadora: 'FALCO FRUIT', fecha_ingreso: '2026-11-17T03:25:00+00:00', temporada: 'TEMP-2627', ubicacion: null });
            await context.route('http://localhost/**', async (route) => {
                const path = new URL(route.request().url()).pathname;
                if (path === '/oficina/consultas/busqueda') return route.fulfill({ body: html, contentType: 'text/html' });
                if (path.startsWith('/build/') || path.startsWith('/brand/')) {
                    try { return route.fulfill({ body: readFileSync(resolve(root, `public${path}`)), contentType: ({ '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml', '.woff2': 'font/woff2' })[extname(path)] || 'application/octet-stream' }); }
                    catch { return route.fulfill({ status: 404, body: '' }); }
                }
                if (path === '/api/acceso-oficina') return route.fulfill({ json: { usuario: identity } });
                if (path === '/api/consultas/buscar') return route.fulfill({ json: { termino: '6000002990', limite_por_categoria: 20,
                    folios: [folio('6000002990', 1120), folio('6000002991', 1), folio('6000002992', null)], lotes: [], productores: [], recepciones: [] } });
                if (path === '/api/consultas/resumen') return route.fulfill({ json: { data: {} } });
                return route.fulfill({ json: { data: [] } });
            });
            const page = await context.newPage(); page.on('pageerror', (error) => errors.push(error.message));
            await page.goto('http://localhost/oficina/consultas/busqueda?q=6000002990');
            await page.locator('.result-card--folio').first().waitFor();
            const status = await page.locator('.result-card--folio .folio-card-status').allInnerTexts();
            assert.match(status[0], /Pallet\s+1\.120 cajas\s+Prefrío aprobado/);
            assert.match(status[1], /1 caja\b/);
            assert.doesNotMatch(status[2], /caja/);
            // La tarjeta no se desborda dentro de la grilla de resultados.
            assert.equal(await page.locator('.result-card--folio').first().evaluate((card) => card.scrollWidth <= card.clientWidth), true);
            assert.deepEqual(errors, []);
            await context.close();
        }
    } finally { await browser.close(); }
});
