import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve, extname } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
const html = readFileSync(resolve(root, 'storage/app/ui/oficina/proveedores.html'), 'utf8');
const vendor = { id: '11111111-1111-4111-8111-111111111111', rut: '76123456-0', razon_social: 'Mantención Frío', activo: true, pagos_bloqueados: false, version: 1 };
const invoice = { id: '22222222-2222-4222-8222-222222222222', tipo: '33', folio: '123', fecha_emision: '2026-10-01', fecha_recepcion: '2026-10-02', vencimiento: '2026-11-01', total: '119000', neto: '100000', exento: '0', iva: '19000', otros: '0', version: 1, guias: [], lineas: [{ cuenta: '5101', descripcion: 'Mantención', monto: '100000', ceco: 'FRIO' }], respaldo: 'Factura recibida OC-100.' };
async function setup(browser, width, manage = true) {
    const context = await browser.newContext({ viewport: { width, height: 1000 }, timezoneId: 'America/Santiago' });
    const identity = { nombre: 'Finanzas Prueba', rol: manage ? 'administrador' : 'consulta', puede_consultar_proveedores_finanzas: true, puede_gestionar_proveedores_finanzas: manage, modulos_acceso: ['finanzas.proveedores'] };
    await context.addInitScript((user) => { localStorage.setItem('estiba_wms_office_token', 'supplier_test_token'); localStorage.setItem('estiba_wms_office_identity', JSON.stringify(user)); }, identity);
    const writes = []; let posts = 0;
    await context.route('http://localhost/**', async (route) => {
        const req = route.request(); const path = new URL(req.url()).pathname;
        if (path === '/oficina/finanzas/proveedores') return route.fulfill({ body: html, contentType: 'text/html' });
        if (path.startsWith('/build/') || path.startsWith('/brand/folios/')) { try { return route.fulfill({ body: readFileSync(resolve(root, `public${path}`)), contentType: ({ '.js': 'text/javascript', '.css': 'text/css', '.woff2': 'font/woff2', '.svg': 'image/svg+xml' })[extname(path)] || 'application/octet-stream' }); } catch { return route.fulfill({ status: 404, body: '' }); } }
        if (path === '/api/acceso-oficina') return route.fulfill({ json: { usuario: identity } });
        if (path === '/api/oficina/contexto') return route.fulfill({ json: { data: { planta: 'Prueba' } } });
        const base = '/api/finanzas/proveedores';
        if (path === base) return route.fulfill({ json: { data: { data: [vendor], current_page: 1, last_page: 1 }, cuentas: [{ codigo: '5101', nombre: 'Gastos de operación', tipo: 'gasto', activa: true }], tipos: { 33: 'Factura afecta recibida', 34: 'Factura exenta recibida', 52: 'Guía de despacho recibida' }, puede_gestionar: manage } });
        if (path === `${base}/referencias`) return route.fulfill({ json: { data: [] } });
        if (path === `${base}/documentos` && req.method() === 'GET') return route.fulfill({ json: { data: { data: [invoice], current_page: 1, last_page: 1 } } });
        if (path === `${base}/documentos` && req.method() === 'POST') { writes.push(req.postDataJSON()); posts++; return posts === 1 ? route.fulfill({ status: 503, json: { message: 'Servicio temporalmente ocupado. Reintente.' } }) : route.fulfill({ status: 201, json: { data: invoice } }); }
        if (path === `${base}/documentos/${invoice.id}/registrar`) { writes.push(req.postDataJSON()); return route.fulfill({ json: { data: { ...invoice, registrado_at: '2026-10-02T12:00:00Z' } } }); }
        if (path.startsWith('/api/')) return route.fulfill({ json: {} });
        return route.fulfill({ status: 404, body: '' });
    });
    const page = await context.newPage(); const errors = []; page.on('pageerror', (e) => errors.push(e.message));
    await page.goto('http://localhost/oficina/finanzas/proveedores'); await page.locator('#cpSuppliers').getByText(vendor.razon_social).waitFor({ timeout: 5000 }).catch(async (e) => { await page.screenshot({ path: `/tmp/proveedores-failure-${width}.png`, fullPage: true }); throw new Error(`${e.message}; page errors: ${JSON.stringify(errors)}; status: ${await page.locator('#cpMessage').textContent()}`); });
    return { context, page, writes, errors };
}

test('documento de compra conserva UUID al reintentar y exige confirmación contable en escritorio y móvil', async () => {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
    try {
        for (const width of [390, 1280]) {
            const { context, page, writes, errors } = await setup(browser, width);
            await page.locator('#cpSuppliers').getByRole('button', { name: 'Documentos', exact: true }).click();
            await page.locator('#cpNewDocument').click();
            const form = page.locator('#cpDocumentForm');
            await form.locator('[name="folio"]').fill('456'); await form.locator('[name="neto"]').fill('100000'); await form.locator('[name="iva"]').fill('19000');
            await form.locator('[name="respaldo"]').fill('Factura recibida para mantención.'); await form.locator('[data-line="descripcion"]').fill('Mantención de equipos'); await form.locator('[data-line="monto"]').fill('100000'); await form.locator('[data-line="ceco"]').fill('FRIO');
            await form.getByRole('button', { name: 'Guardar borrador' }).click(); await form.locator('[data-cp-error]').getByText('Servicio temporalmente ocupado. Reintente.').waitFor();
            await form.getByRole('button', { name: 'Guardar borrador' }).click(); await page.waitForFunction(() => !document.getElementById('cpDocumentDialog').open);
            assert.equal(writes.length, 2); assert.equal(writes[0].operacion_id, writes[1].operacion_id); assert.equal(writes[1].lineas[0].ceco, 'FRIO'); assert.equal(writes[1].neto, '100000');
            await page.locator('#cpDocuments').getByRole('button', { name: 'Registrar', exact: true }).click();
            assert.equal(writes.length, 2, 'Abrir confirmación no contabiliza.');
            await page.locator('#cpActionForm [type="submit"]').click(); await page.waitForFunction(() => !document.getElementById('cpActionDialog').open);
            assert.equal(writes[2].version, 1); assert.match(writes[2].fecha_contable, /^\d{4}-\d{2}-\d{2}$/); assert.deepEqual(errors, []);
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
            await context.close();
        }
    } finally { await browser.close(); }
});

test('consulta de proveedores muestra costos sin ofrecer escrituras', async () => {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
    try {
        const { context, page, writes, errors } = await setup(browser, 1280, false);
        assert.equal(await page.locator('#cpNewSupplier').isVisible(), false);
        await page.locator('#cpSuppliers').getByRole('button', { name: 'Documentos', exact: true }).click(); await page.locator('#cpDocuments').getByText('Borrador', { exact: true }).waitFor();
        assert.equal(await page.locator('#cpNewDocument').isVisible(), false); assert.equal(await page.locator('#cpDocuments').getByRole('button', { name: 'Registrar', exact: true }).count(), 0);
        assert.deepEqual(writes, []); assert.deepEqual(errors, []); await context.close();
    } finally { await browser.close(); }
});
