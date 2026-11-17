import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve, extname } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
const html = readFileSync(resolve(root, 'storage/app/ui/oficina/sag-lotes-retenidos.html'), 'utf8');

test('registra una retención y libera parcialmente un bloque sin perder el borrador ante un conflicto', async () => {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
    try {
        const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
        await context.addInitScript(() => {
            localStorage.setItem('estiba_wms_office_token', 'fixture');
            localStorage.setItem('estiba_wms_office_identity', JSON.stringify({ nombre: 'Contraparte SAG', rol: 'supervisor_frio', puede_gestionar_lotes_retenidos_sag: true }));
        });
        let record = null, fail = false;
        const writes = [], errors = [];
        const detail = () => ({ registro: record, paises_restringidos: ['us', 'cl'], folios: [{ id: 'folio', folio: '60001', activo: true, cajas_lote: 1, cajas_pallet: 120, estado: 'en_camara', csg: '89868', ubicacion: 'CAM-09', carga: 'CAR-1' }], historial: [] });
        await context.route('http://localhost/**', async route => {
            const request = route.request(), path = new URL(request.url()).pathname;
            if (path === '/oficina/frigorifico/lotes-retenidos-sag') return route.fulfill({ body: html, contentType: 'text/html' });
            if (path.startsWith('/build/') || path.startsWith('/brand/')) {
                try { return route.fulfill({ body: readFileSync(resolve(root, 'public' + path)), contentType: ({ '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml', '.woff2': 'font/woff2' })[extname(path)] || 'application/octet-stream' }); }
                catch { return route.fulfill({ status: 404 }); }
            }
            if (path === '/api/sag/lotes-retenidos/catalogo') return route.fulfill({ json: { temporada: { nombre: 'TEMP-2627' }, fecha_planta: '2026-11-17', clientes: [{ id: 'client', codigo: 'FALCO', nombre: 'FALCO FRUIT' }], paises: [{ id: 'us', iso_alpha2: 'US', nombre_es: 'Estados Unidos' }, { id: 'cl', iso_alpha2: 'CL', nombre_es: 'Chile' }], bloques: [{ id: 'block', codigo: 'TEST', nombre: 'Bloque de prueba' }] } });
            if (path.startsWith('/api/sag/lotes-retenidos') && ['POST', 'PUT'].includes(request.method())) {
                const data = request.postDataJSON(); writes.push(data);
                if (fail) return route.fulfill({ status: 409, json: { message: 'El lote cambió. Actualiza antes de resolver.' } });
                assert.match(data.operacion_id, /^[0-9a-f-]{36}$/);
                record = { ...record, ...data, id: 'case', cliente: { nombre: 'FALCO FRUIT' }, version: (record?.version || 0) + 1, updated_at: '2026-11-17T15:00:00Z' };
                return route.fulfill({ json: detail() });
            }
            if (path === '/api/sag/lotes-retenidos') return route.fulfill({ json: { data: record ? [record] : [], last_page: 1 } });
            if (path === '/api/sag/lotes-retenidos/case') return route.fulfill({ json: detail() });
            return route.fulfill({ json: { data: [] } });
        });
        const page = await context.newPage(); page.on('pageerror', e => errors.push(e.message));
        await page.goto('http://localhost/oficina/frigorifico/lotes-retenidos-sag');
        await page.locator('#slNew').click();
        await page.locator('#slForm [name=cliente_id]').selectOption('client');
        await page.locator('#slForm [name=numero_lote]').fill('10056');
        await page.locator('#slForm [name=estado]').selectOption('retenido');
        await page.locator('#slForm [name=observacion]').fill('Detección informada por contraparte.');
        await page.locator('[data-destination="bloque|block"]').check();
        await page.locator('#slSave').click();
        await page.locator('#slVersion').filter({ hasText: 'VERSIÓN 1' }).waitFor();
        assert.deepEqual(writes[0].destinos, [{ tipo: 'bloque', id: 'block' }]);
        assert.equal(await page.locator('#slForm [name=cliente_id]').isDisabled(), true);
        assert.match(await page.locator('#slFolios').textContent(), /60001.*1 \/ 120.*89868.*CAM-09.*CAR-1/);
        await page.locator('#slExpand').click();
        await page.locator('[data-destination="pais|cl"]').uncheck();
        await page.locator('#slForm [name=resolucion]').fill('Liberación parcial; se mantiene restringido Estados Unidos.');
        fail = true; await page.locator('#slSave').click();
        await page.locator('#slError').filter({ hasText: 'El lote cambió' }).waitFor();
        assert.match(await page.locator('#slForm [name=resolucion]').inputValue(), /Liberación parcial/);
        fail = false; await page.locator('#slSave').click();
        await page.locator('#slVersion').filter({ hasText: 'VERSIÓN 2' }).waitFor();
        assert.equal(writes[1].operacion_id, writes[2].operacion_id);
        assert.deepEqual(writes[2].destinos, [{ tipo: 'pais', id: 'us' }]);
        assert.equal(writes[2].version, 1);
        assert.equal('cliente_id' in writes[2], false);
        assert.equal('numero_lote' in writes[2], false);
        assert.deepEqual(errors, []);
        await context.close();
    } finally { await browser.close(); }
});
