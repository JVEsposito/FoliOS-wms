import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { extname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
test('consulta imprime la revisión aprobada histórica y distingue el borrador de reemplazo', async () => {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox'] });
    try {
        const context = await browser.newContext({ viewport: { width: 390, height: 900 } });
        const identity = { nombre: 'Consulta comercial', rol: 'consulta', puede_consultar_comercial_comex: true, modulos_acceso: ['comex.comercial'] };
        const party = { id: 'party', razon_social: 'Comprador histórico 商业 <script>', tax_id: 'FOREIGN-123', pais_iso2: 'CN', direccion: 'Dirección histórica', ciudad: 'Shanghai' };
        const datos = { fecha: '2026-10-01', vigente_hasta: '2027-03-31', temporada_referencia: '2026-2027', referencia_pedido: 'Pedido de prueba', negocio: 'firme', moneda: 'USD', incoterm: 'FOB', incoterm_version: '2020', lugar_incoterm: 'Puerto de prueba', modalidad: 'maritimo', condicion_pago: 'A 30 días', partes: { vendedor: party, comprador: party, notify: [] }, contrata_flete: party, lineas: [{ sku: 'CEREZA', descripcion: 'Cerezas Santina', cantidad: '120', unidad: 'caja', precio: '10.125', importe: '1215.00' }], valorizacion: { total: '1215.00', decimales: 2 }, respaldo: 'Acuerdo de prueba verificado' };
        const aprobada = { id: 'approved', numero: 1, estado: 'aprobada', preparado_por: 'Ana', aprobado_por: 'Luis', datos };
        const propuesta = { id: 'draft', numero: 2, estado: 'borrador', preparado_por: 'Ana', datos: { ...datos, valorizacion: { total: '9999.00', decimales: 2 } } };
        const nota = { id: 'nota', folio: 'NV-PRUEBA', cliente: { nombre: 'Cliente de servicios' }, gestion: 'servicio', estado: 'abierta', revision_activa_id: aprobada.id, version: 4 };
        const paginated = (data) => ({ data: { data, current_page: 1, last_page: 1 } });
        await context.addInitScript((u) => { localStorage.setItem('estiba_wms_office_token', 'test'); localStorage.setItem('estiba_wms_office_identity', JSON.stringify(u)); window.print = () => { window.printed = true; }; }, identity);
        await context.route('http://localhost/**', async (route) => {
            const path = new URL(route.request().url()).pathname;
            if (path === '/oficina/comex/notas') return route.fulfill({ contentType: 'text/html', body: readFileSync(resolve(root, 'storage/app/ui/oficina/notas.html')) });
            if (path.startsWith('/build/') || path.startsWith('/brand/folios/')) {
                try { return route.fulfill({ body: readFileSync(resolve(root, `public${path}`)), contentType: ({ '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml' })[extname(path)] || 'application/octet-stream' }); }
                catch { return route.fulfill({ status: 404, body: '' }); }
            }
            let json = {};
            if (path === '/api/acceso-oficina') json = { usuario: identity };
            if (path.endsWith('/contexto')) json = { permisos: { gestionar: false, aprobar: false }, data: {} };
            if (path === '/api/comex/comercial/notas') json = { ...paginated([nota]), monedas: { USD: 2 }, incoterms: ['FOB'], roles: ['vendedor', 'comprador'] };
            if (path.endsWith('/notas/nota')) json = { data: { nota, propuesta, aprobada }, puede_decidir: false };
            if (path.endsWith('/revisiones')) json = paginated([propuesta, aprobada]);
            if (path.endsWith('/historial')) json = paginated([]);
            return route.fulfill({ json });
        });
        const page = await context.newPage(); const errors = []; page.on('pageerror', (e) => errors.push(e.message));
        await page.goto('http://localhost/oficina/comex/notas'); await page.locator('[data-note]').click();
        await page.waitForFunction(() => document.getElementById('cnDetail').open);
        assert.equal(await page.locator('#cnNew').isVisible(), false); assert.equal(await page.locator('#cnActions button').count(), 0);
        assert.equal(await page.locator('#cnRevision').inputValue(), 'approved');
        assert.match(await page.locator('#cnDocument').textContent(), /1215.00/);
        assert.match(await page.locator('#cnDocument').textContent(), /商业 <script>/); assert.equal(await page.locator('#cnDocument script').count(), 0);
        await page.locator('#cnPrint').click(); assert.equal(await page.evaluate(() => window.printed), true);
        assert.match(await page.locator('#cnPrintSheet').textContent(), /No tributario/);
        await page.emulateMedia({ media: 'print' }); assert.equal(await page.locator('#cnPrintSheet').isVisible(), true); assert.equal(await page.locator('#officeApp').isVisible(), false);
        await page.emulateMedia({ media: 'screen' }); await page.locator('#cnRevision').selectOption('draft');
        assert.match(await page.locator('#cnDocument').textContent(), /9999.00/); assert.match(await page.locator('#cnDocument').textContent(), /borrador/);
        assert.deepEqual(errors, []); await context.close();
    } finally { await browser.close(); }
});
