import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
const html = readFileSync(resolve(root, 'storage/app/ui/oficina/consumos-ot.html'), 'utf8').replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, '');
const form = readFileSync(resolve(root, 'resources/views/office/materials.blade.php'), 'utf8').match(/<form[^>]*id="materialDirectConsumptionForm"[\s\S]*?<\/form>/)[0];
const order = { id: 'o1', codigo: 'OT-000001', referencia_ot: 'ARM-200', temporada: '2026–2027', fecha_operacional: '2026-10-25', estado: 'cerrada', bodega: 'Bodega Norte', cliente: 'Cliente <Norte>', proceso: 'Armado', linea: 'L1', turno: 'Día', operador: 'Juan', creada_por: 'Ana', salida_real: 50, unidad_salida: 'cajas', merma_produccion: null,
    componentes: [{ item_id: 'film', codigo: 'FILM', nombre: 'Film <prueba>', unidad: 'kg', estandar_planificado: 20, esperado: 10, retirado: 20, devuelto: 7, compartido: 0, consumo_neto: 13, merma_declarada: 3, utilizado: 10, desviacion: 0, desviacion_pct: 0, estado: 'dentro_tolerancia' }] };
async function fixture({ width = 1366, allocation = false } = {}) {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
    const context = await browser.newContext({ viewport: { width, height: 1000 }, acceptDownloads: true });
    const page = await context.newPage(); const calls = []; const errors = [];
    await context.addInitScript(({ width }) => { localStorage.setItem('estiba_wms_office_token', 'token'); localStorage.setItem('estiba_wms_office_theme', width === 390 ? 'dark-industrial' : 'light-professional'); }, { width });
    page.on('pageerror', e => errors.push(e.message));
    await context.route('http://localhost/**', async route => {
        const url = new URL(route.request().url()); const path = url.pathname;
        if (path === '/test') return route.fulfill({ body: allocation ? `<html><body>${form}</body></html>` : html, contentType: 'text/html' });
        if (path.startsWith('/build/')) return route.fulfill({ body: readFileSync(resolve(root, 'public' + path)), contentType: path.endsWith('.css') ? 'text/css' : 'application/octet-stream' });
        if (path === '/consumptions.js') return route.fulfill({ body: readFileSync(resolve(root, 'resources/js/office-material-consumptions.js'), 'utf8').replace("import './office-navigation';", ''), contentType: 'text/javascript' });
        if (path === '/allocation.js') return route.fulfill({ body: readFileSync(resolve(root, 'resources/js/office-material-consumption-allocation.js')), contentType: 'text/javascript' });
        calls.push({ method: route.request().method(), path, query: Object.fromEntries(url.searchParams) });
        if (path.endsWith('/consumos/excel')) return route.fulfill({ body: 'PK-test', contentType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });
        if (path.includes('/ordenes/o1/consumos')) return route.fulfill({ json: { data: { ...order, registros: [{ tipo: 'devolucion_transformacion', cantidad: 7, unidad: 'kg', operador: 'Juan', registrado_por: 'Ana', fecha_registro: '2026-10-25T13:00:00Z', motivo: '<img src=x onerror=alert(1)>' }] } } });
        if (path.endsWith('/consumos')) return route.fulfill({ json: { data: [order], meta: { page: Number(url.searchParams.get('page') || 1), last_page: 2, total: 26 }, temporadas: [{ id: 's1', nombre: '2026–2027', activa: true }], clientes: [{ id: 'c1', nombre: 'Cliente Norte' }], bodegas: [{ id: 'b1', nombre: 'Bodega Norte' }] } });
        return route.fulfill({ status: 404 });
    });
    await page.goto('http://localhost/test');
    if (allocation) {
        await page.evaluate(async () => {
            const { setupConsumptionAllocation } = await import('/allocation.js'); const form = document.querySelector('form');
            window.allocation = setupConsumptionAllocation(form, async () => ({ data: [{ id: 'o1', referencia_ot: 'OT-1' }, { id: 'o2', referencia_ot: 'OT-2' }] }), () => true);
            window.allocation.reset(); form.elements.cantidad.value = '10'; form.elements.folio_id.value = 'f1';
        });
    } else {
        await page.evaluate(async () => { document.documentElement.dataset.officeTheme = localStorage.getItem('estiba_wms_office_theme'); document.querySelector('[data-office-shell]')?.remove(); await import('/consumptions.js'); });
        await page.locator('.materials-consumption-order').waitFor();
    }
    return { browser, page, calls, errors };
}

test('consulta por proceso, turno y período; pagina y exporta con el mismo filtro', async () => {
    const { browser, page, calls, errors } = await fixture();
    try {
        await page.locator('[name="proceso"]').fill('Armado'); await page.locator('[name="turno"]').fill('Día'); await page.locator('[name="desde"]').fill('2026-10-01');
        await page.getByRole('button', { name: 'Consultar', exact: true }).click(); await page.waitForFunction(() => document.querySelector('#consumptionPrevious').disabled);
        await page.getByRole('button', { name: 'Siguiente', exact: true }).click(); await page.locator('#consumptionPage').filter({ hasText: '2 / 2' }).waitFor();
        const download = page.waitForEvent('download'); await page.getByRole('button', { name: 'Exportar Excel' }).click(); assert.equal((await download).suggestedFilename(), 'consumos-por-ot.xlsx');
        const excel = calls.find(c => c.path.endsWith('/excel')); assert.equal(excel.query.proceso, 'Armado'); assert.equal(excel.query.turno, 'Día'); assert.equal(excel.query.desde, '2026-10-01'); assert.equal(excel.query.page, undefined);
        assert.equal(calls.filter(c => c.method !== 'GET').length, 0); assert.deepEqual(errors, []);
    } finally { await browser.close(); }
});

test('detalle distingue operador y registrador y escapa evidencia', async () => {
    const { browser, page, errors } = await fixture();
    try {
        await page.getByRole('button', { name: 'Ver movimientos y responsables' }).click(); await page.locator('[data-record-list] h3').waitFor();
        const text = await page.locator('[data-record-list]').textContent(); assert.match(text, /Operador: Juan/); assert.match(text, /Registrado por: Ana/); assert.match(text, /<img/);
        assert.equal(await page.locator('[data-record-list] img').count(), 0); assert.equal(await page.locator('#consumptionOrders prueba').count(), 0);
        assert.deepEqual(errors, []);
        await page.screenshot({ path: resolve(root, 'storage/app/ui/oficina/pr14-consumos-1366.png'), fullPage: true });
    } finally { await browser.close(); }
});

test('consulta en celular conserva tabla desplazable y formulario dentro del ancho', async () => {
    const { browser, page, errors } = await fixture({ width: 390 });
    try {
        const layout = await page.evaluate(() => ({ width: document.documentElement.scrollWidth, viewport: innerWidth })); assert.ok(layout.width <= layout.viewport, JSON.stringify(layout));
        assert.match(await page.locator('#consumptionOrders').textContent(), /Dentro de tolerancia/); assert.deepEqual(errors, []);
        await page.screenshot({ path: resolve(root, 'storage/app/ui/oficina/pr14-consumos-390.png'), fullPage: true });
    } finally { await browser.close(); }
});

test('reparto entre OT exige suma exacta, conserva datos y permite quitar una línea', async () => {
    const { browser, page, errors } = await fixture({ allocation: true });
    try {
        await page.locator('[data-allocation-toggle]').check(); await page.locator('[data-allocation-select] option[value="o1"]').waitFor({ state: 'attached' });
        for (const [id, quantity] of [['o1', '3.5'], ['o2', '6.5']]) {
            await page.locator('[data-allocation-select]').selectOption(id); await page.getByRole('button', { name: 'Agregar OT' }).click(); await page.locator(`[data-order="${id}"] [data-allocation-quantity]`).fill(quantity);
        }
        await page.locator('[data-order="o1"] [data-allocation-waste]').fill('0.5');
        const payload = await page.evaluate(() => window.allocation.payload()); assert.equal(payload.length, 2); assert.equal(payload[0].cantidad_merma, '0.5');
        await page.locator('[data-order="o1"] [data-allocation-remove]').click();
        assert.match(await page.evaluate(() => { try { window.allocation.payload(); return ''; } catch(e) { return e.message; } }), /sumar exactamente/);
        await page.locator('[data-allocation-toggle]').uncheck(); assert.equal(await page.evaluate(() => window.allocation.payload()), null); assert.deepEqual(errors, []);
    } finally { await browser.close(); }
});
