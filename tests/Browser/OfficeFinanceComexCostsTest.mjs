import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { extname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
test('consulta de costos muestra real cerrado y reverso posterior como valores distintos', async () => {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox'] });
    try {
        const context = await browser.newContext({ viewport: { width: 390, height: 900 } }); const errors = [];
        const identity = { nombre: 'Consulta financiera', rol: 'consulta', puede_consultar_costos_comex: true, modulos_acceso: ['finanzas.proveedores', 'comex.comercial'] };
        const costo = { id: 'cost', concepto: 'Flete de prueba', fuente: { booking: { codigo: 'BK-PRUEBA', cliente: { nombre: 'Cliente' } }, contenedores: [] }, estado: 'cerrado', tratamiento: 'por_definir', previsto: '100.500000', comprometido: null, moneda: 'USD', conversion: { previsto_clp: '90450', comprometido_clp: null, tasa_clp: '900', fecha: '2026-10-01', fuente: 'Cotización de prueba', politica: 'Manual' }, cierre: { real_clp: '100000' }, respaldo: 'Respaldo de prueba' };
        await context.addInitScript((u) => { localStorage.setItem('estiba_wms_office_token', 'test'); localStorage.setItem('estiba_wms_office_identity', JSON.stringify(u)); }, identity);
        await context.route('http://localhost/**', async (route) => {
            const path = new URL(route.request().url()).pathname;
            if (path === '/oficina/finanzas/costos-comex') return route.fulfill({ contentType: 'text/html', body: readFileSync(resolve(root, 'storage/app/ui/oficina/costos-comex.html')) });
            if (path.startsWith('/build/') || path.startsWith('/brand/folios/')) {
                try { return route.fulfill({ body: readFileSync(resolve(root, `public${path}`)), contentType: ({ '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml' })[extname(path)] || 'application/octet-stream' }); } catch { return route.fulfill({ status: 404, body: '' }); }
            }
            let json = {};
            if (path === '/api/acceso-oficina') json = { usuario: identity };
            if (path.endsWith('/contexto')) json = { data: {} };
            if (path === '/api/finanzas/costos-comex') json = { data: { data: [costo], current_page: 1, last_page: 1 }, puede_gestionar: false, monedas: { CLP: 0, USD: 2 } };
            if (path.endsWith('/costs/cost') || path.endsWith('/costos-comex/cost')) json = { data: { costo, real_vigente_clp: '0', real_cierre_clp: '100000', imputaciones: [], alertas: ['Cambió o se anuló el documento 123. Revise su distribución.'] } };
            if (path.endsWith('/historial')) json = { data: { data: [], current_page: 1, last_page: 1 } };
            return route.fulfill({ json });
        });
        const page = await context.newPage(); page.on('pageerror', (e) => errors.push(e.message)); await page.goto('http://localhost/oficina/finanzas/costos-comex');
        await page.locator('[data-cost]').click(); await page.waitForFunction(() => document.getElementById('fcDetail').open);
        assert.match(await page.locator('#fcAlerts').textContent(), /anuló/);
        assert.match(await page.locator('#fcSummary').textContent(), /Real vigente distribuido0 CLP/); assert.match(await page.locator('#fcSummary').textContent(), /Real conservado al cierre100000 CLP/);
        assert.equal(await page.locator('#fcNew').isVisible(), false); assert.equal(await page.locator('[data-fc-action=imputar]').count(), 0); assert.deepEqual(errors, []); await context.close();
    } finally { await browser.close(); }
});
