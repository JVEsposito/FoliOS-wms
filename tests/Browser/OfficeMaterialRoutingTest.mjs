import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';
const root = fileURLToPath(new URL('../../', import.meta.url));
// Laravel exports absolute localhost assets; keep them on the insecure test origin.
const html = readFileSync(resolve(root, 'storage/app/ui/oficina/capacidad.html'), 'utf8')
    .replaceAll('http://localhost/build/', 'http://folios.test/build/')
    .replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, '');
async function fixture({ width = 1366, can = true, lost = false } = {}) {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
    const context = await browser.newContext({ viewport: { width, height: 1000 } }); const page = await context.newPage(); const writes = []; const errors = [];
    let rows = [{ id: 'r1', nombre: 'Insumos <Norte>', prioridad: 10, cliente_id: null, cliente: null, categoria_operacional: 'insumo', almacen_material_id: 'w1', bodega: 'Bodega Norte', bodega_activa: 1, activo: 1, version: 3 }];
    await context.addInitScript(({ width }) => { localStorage.setItem('estiba_wms_office_token', 'token'); localStorage.setItem('estiba_wms_office_theme', width === 390 ? 'dark-industrial' : 'light-professional'); }, { width });
    page.on('pageerror', e => errors.push(e.message));
    await context.route('http://folios.test/**', async route => {
        const req = route.request(); const path = new URL(req.url()).pathname;
        if (path === '/test') return route.fulfill({ body: html, contentType: 'text/html' });
        if (path.startsWith('/build/')) return route.fulfill({ body: readFileSync(resolve(root, 'public' + path)), contentType: path.endsWith('.css') ? 'text/css' : 'application/octet-stream' });
        if (path === '/shared/uuid.js') return route.fulfill({ body: readFileSync(resolve(root, 'resources/js/shared/uuid.js')), contentType: 'text/javascript' });
        if (path === '/office-material-routing.js') return route.fulfill({ body: readFileSync(resolve(root, 'resources/js/office-material-routing.js')), contentType: 'text/javascript' });
        if (path.endsWith('/historial')) return route.fulfill({ json: { data: [{ id: 'e1', usuario: 'Ana Pérez', motivo: '<b>Redistribuir ingresos</b>', antes: null, despues: rows[0], ocurrido_at: '2026-10-24 13:00:00' }], meta: { pagina: 1, paginas: 1 } } });
        if (req.method() === 'POST' || req.method() === 'PUT') {
            const body = req.postDataJSON(); writes.push(body);
            if (lost && writes.length === 1) return route.fulfill({ status: 503, json: { message: 'Respuesta perdida. Reintenta.' } });
            const saved = { ...body, id: req.method() === 'POST' ? 'r2' : 'r1', version: body.version + 1, bodega: 'Bodega Norte', bodega_activa: 1 };
            rows = [...rows.filter(r => r.id !== saved.id), saved]; return route.fulfill({ status: req.method() === 'POST' ? 201 : 200, json: { data: saved } });
        }
        if (path === '/api/materiales/asignacion-bodegas') return route.fulfill({ json: { data: rows, meta: { pagina: 1, paginas: 1 }, puede_configurar: can, almacenes: [{ id: 'w1', codigo: 'BOD-N', nombre: 'Bodega Norte', activo: 1 }], clientes: [{ id: 'c1', codigo: 'CLI', nombre: 'Cliente de prueba' }] } });
        return route.fulfill({ status: 404 });
    });
    await page.goto('http://folios.test/test');
    await page.evaluate(async () => { document.querySelector('[data-office-shell]')?.remove(); await import('/office-material-routing.js'); });
    await page.locator('#routingPanel > summary').click(); await page.locator('#routingRows').filter({ hasText: 'Insumos <Norte>' }).waitFor();
    return { browser, page, writes, errors };
}

test('reglas en PC preservan UUID después de perder respuesta y auditan el motivo sin HTML ejecutable', async () => {
    const { browser, page, writes, errors } = await fixture({ lost: true });
    try {
        await page.getByRole('button', { name: 'Crear regla', exact: true }).click();
        await page.locator('#routingForm [name="nombre"]').fill('Embalajes del cliente');
        await page.locator('#routingForm [name="prioridad"]').fill('20');
        await page.locator('#routingForm [name="cliente_id"]').selectOption('c1');
        await page.locator('#routingForm [name="categoria_operacional"]').selectOption('material_mp');
        await page.locator('#routingForm [name="almacen_material_id"]').selectOption('w1');
        await page.locator('#routingForm [name="motivo"]').fill('Organizar el ingreso de embalajes');
        await page.getByRole('button', { name: 'Guardar regla', exact: true }).click();
        await page.locator('#routingNotice').filter({ hasText: 'Respuesta perdida' }).waitFor();
        await page.getByRole('button', { name: 'Guardar regla', exact: true }).click();
        await page.locator('#routingNotice').filter({ hasText: 'Regla guardada y auditada' }).waitFor();
        assert.deepEqual(writes[0], writes[1]); assert.match(writes[0].operacion_id, /^[0-9a-f-]{36}$/);
        assert.equal(writes[0].cliente_id, 'c1'); assert.equal(writes[0].categoria_operacional, 'material_mp');
        await page.locator('#routingHistory > summary').click(); await page.locator('#routingHistoryRows').filter({ hasText: 'Ana Pérez' }).waitFor();
        await page.locator('#routingHistoryRows summary').click();
        assert.equal(await page.locator('#routingHistoryRows b').count(), 0);
        await page.screenshot({ path: resolve(root, 'storage/app/ui/oficina/pr12-reglas-1366.png'), fullPage: true });
        assert.deepEqual(errors, []);
    } finally { await browser.close(); }
});

test('consulta desde celular muestra destino y prioridad sin permitir configurar', async () => {
    const { browser, page, writes, errors } = await fixture({ width: 390, can: false });
    try {
        assert.equal(await page.locator('#routingNew').isHidden(), true);
        assert.equal(await page.locator('#routingRows [data-edit]').count(), 0);
        assert.match(await page.locator('#routingRows').textContent(), /Bodega Norte/);
        assert.equal(await page.locator('#routingRows norte').count(), 0);
        await page.screenshot({ path: resolve(root, 'storage/app/ui/oficina/pr12-reglas-390.png'), fullPage: true });
        assert.equal(writes.length, 0); assert.deepEqual(errors, []);
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
    } finally { await browser.close(); }
});

test('desactivar una regla conserva filtros, bodega y versión y evita cambiar ingresos anteriores', async () => {
    const { browser, page, writes, errors } = await fixture();
    try {
        await page.locator('#routingRows [data-edit="r1"]').click();
        await page.locator('#routingForm [name="activo"]').selectOption('0');
        await page.locator('#routingForm [name="motivo"]').fill('Suspender la regla para nuevos ingresos');
        await page.getByRole('button', { name: 'Guardar regla', exact: true }).click();
        await page.locator('#routingNotice').filter({ hasText: 'Regla guardada y auditada' }).waitFor();
        assert.equal(writes[0].version, 3); assert.equal(writes[0].activo, false); assert.equal(writes[0].almacen_material_id, 'w1'); assert.equal(writes[0].categoria_operacional, 'insumo');
        assert.deepEqual(errors, []);
    } finally { await browser.close(); }
});
