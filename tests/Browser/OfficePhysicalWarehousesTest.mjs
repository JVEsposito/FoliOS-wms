import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';
const root = fileURLToPath(new URL('../../', import.meta.url));
const html = readFileSync(resolve(root, 'storage/app/ui/oficina/bodegas.html'), 'utf8').replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, '');
async function fixture({ width = 1366, edit = true, lost = false } = {}) {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
    const context = await browser.newContext({ viewport: { width, height: 950 } }); const page = await context.newPage(); const errors = []; const writes = [];
    let data = [{ id: 'w1', codigo: 'BOD-CENTRAL', nombre: 'Bodega Central', activo: true, requiere_ubicacion_fisica: true, central: true, version: 1 }, { id: 'w2', codigo: 'BOD-2', nombre: 'Embalajes <img src=x onerror=alert(1)>', activo: true, requiere_ubicacion_fisica: false, version: 4 }, { id: 'w3', codigo: 'BOD-3', nombre: 'Bodega antigua', activo: false, version: 1 }];
    page.on('pageerror', e => errors.push(e.message));
    await context.addInitScript(() => { localStorage.setItem('estiba_wms_office_token', 'token'); Object.defineProperty(crypto, 'randomUUID', { value: undefined }); });
    await context.route('http://localhost/**', async route => {
        const req = route.request(); const url = new URL(req.url());
        if (url.pathname === '/test') return route.fulfill({ body: html, contentType: 'text/html' });
        if (url.pathname.startsWith('/build/')) return route.fulfill({ body: readFileSync(resolve(root, 'public' + url.pathname)), contentType: url.pathname.endsWith('.css') ? 'text/css' : 'application/octet-stream' });
        if (url.pathname === '/office-navigation.js') return route.fulfill({ body: '', contentType: 'text/javascript' });
        if (url.pathname === '/office-material-physical-warehouses.js') return route.fulfill({ body: readFileSync(resolve(root, 'resources/js/office-material-physical-warehouses.js')), contentType: 'text/javascript' });
        if (url.pathname.endsWith('/historial')) return route.fulfill({ json: { data: [{ id: 'e1', usuario: 'Ana Pérez', ocurrido_at: '2026-10-23 13:00:00', motivo: '<b>Cambio auditado</b>', antes: null, despues: { nombre: 'Embalajes', activo: true } }], current_page: 1, last_page: 1 } });
        if (req.method() === 'POST' || req.method() === 'PUT') {
            const body = req.postDataJSON(); writes.push(body);
            if (lost && writes.length === 1) return route.fulfill({ status: 503, json: { message: 'Respuesta perdida, reintenta.' } });
            const saved = { ...body, id: req.method() === 'POST' ? 'w4' : url.pathname.split('/').at(-1), version: body.version + 1 };
            data = [...data.filter(a => a.id !== saved.id), saved]; return route.fulfill({ status: req.method() === 'POST' ? 201 : 200, json: { data: saved } });
        }
        if (url.pathname === '/api/materiales/bodegas') return route.fulfill({ json: { data, puede_administrar: edit } });
        return route.fulfill({ status: 404 });
    });
    await page.goto('http://localhost/test');
    await page.evaluate(async () => { document.querySelector('[data-office-shell]')?.remove(); await import('/office-material-physical-warehouses.js'); });
    await page.locator('[data-history="w1"]').waitFor();
    return { browser, page, writes, errors };
}

test('crear en PC conserva UUID tras perder respuesta y funciona sin crypto.randomUUID en HTTP de planta', async () => {
    const { browser, page, writes, errors } = await fixture({ lost: true });
    try {
        await page.getByRole('button', { name: 'Crear bodega', exact: true }).click();
        for (const [key, value] of Object.entries({ codigo: 'bod-envases', nombre: 'Bodega Envases', motivo: 'Separar almacenaje de envases' })) await page.locator(`#warehouseForm [name="${key}"]`).fill(value);
        await page.getByRole('button', { name: 'Guardar bodega' }).click();
        await page.locator('#warehouseError').filter({ hasText: 'Respuesta perdida' }).waitFor();
        await page.getByRole('button', { name: 'Guardar bodega' }).click();
        await page.locator('#warehouseNotice').filter({ hasText: 'cambio guardado y auditado' }).waitFor();
        assert.deepEqual(writes[0], writes[1]); assert.match(writes[0].operacion_id, /^[0-9a-f-]{36}$/);
        assert.equal(writes[0].requiere_ubicacion_fisica, false); assert.equal(writes[0].codigo, 'BOD-ENVASES');
        await page.locator('[data-history="w4"]').click(); await page.locator('#warehouseHistory').filter({ hasText: 'Ana Pérez' }).waitFor();
        assert.equal(await page.locator('#warehouseHistory b').count(), 0);
        await page.screenshot({ path: resolve(root, 'storage/app/ui/oficina/pr11-bodegas-1366.png'), fullPage: true });
        assert.deepEqual(errors, []);
    } finally { await browser.close(); }
});

test('consulta en celular filtra inactivas, escapa nombres y conserva enlaces de bodega', async () => {
    const { browser, page, writes, errors } = await fixture({ width: 390, edit: false });
    try {
        assert.equal(await page.locator('#warehouseNew').isHidden(), true); assert.equal(await page.locator('[data-edit]').count(), 0);
        assert.equal(await page.locator('#warehouseList img').count(), 0);
        await page.locator('#warehouseInactive').check(); await page.locator('#warehouseSearch').fill('antigua');
        assert.equal(await page.locator('#warehouseList article').count(), 1);
        await page.locator('#warehouseSearch').fill('BOD-2');
        assert.match(await page.locator('#warehouseList a').first().getAttribute('href'), /almacen_id=w2/);
        await page.screenshot({ path: resolve(root, 'storage/app/ui/oficina/pr11-bodegas-390.png'), fullPage: true });
        assert.equal(writes.length, 0); assert.deepEqual(errors, []);
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
    } finally { await browser.close(); }
});

test('editar mantiene el código y protege el modo y estado de Bodega Central', async () => {
    const { browser, page, writes, errors } = await fixture();
    try {
        await page.locator('[data-edit="w1"]').click();
        assert.equal(await page.locator('#warehouseForm [name="codigo"]').getAttribute('readonly'), '');
        assert.equal(await page.locator('#warehouseForm [name="activo"]').isDisabled(), true);
        assert.equal(await page.locator('#warehouseForm [name="requiere_ubicacion_fisica"]').isDisabled(), true);
        await page.locator('#warehouseForm [name="nombre"]').fill('Central de materiales');
        await page.locator('#warehouseForm [name="motivo"]').fill('Actualizar nombre del recinto');
        await page.getByRole('button', { name: 'Guardar bodega' }).click();
        await page.locator('#warehouseNotice').filter({ hasText: 'cambio guardado' }).waitFor();
        assert.equal(writes[0].version, 1); assert.equal(writes[0].codigo, 'BOD-CENTRAL'); assert.equal(writes[0].activo, true); assert.equal(writes[0].requiere_ubicacion_fisica, true);
        assert.deepEqual(errors, []);
    } finally { await browser.close(); }
});
