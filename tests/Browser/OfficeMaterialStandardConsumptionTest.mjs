import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
const html = readFileSync(resolve(root, 'storage/app/ui/oficina/rebaja-estandar.html'), 'utf8').replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, '');
const groups = [
    { clave: 'g1', fecha: '2026-11-10', cliente: { id: 'c1', nombre: 'Falco <Fruit>' }, marca: 'FALCO FRUIT', proceso: 'AR01STD20016', envase: { id: 'v1', nombre: 'FALCO 5KG', codigo: 'FC50PM' }, variedad: 'LAPINS', calibre: 'XL', categoria: 'CAT1', cajas: 158, pallets: 2, estandar: { codigo: 'FC50PM', origen: 'propio', version: 1, vigente_desde: '2026-11-01' }, problema: null, seleccionado: true },
    { clave: 'g2', fecha: '2026-11-11', cliente: { id: 'c1', nombre: 'Falco <Fruit>' }, marca: 'OTRA', proceso: null, envase: { id: 'v1', nombre: 'FALCO 5KG', codigo: 'FC50PM' }, cajas: 30, pallets: 1, estandar: { codigo: 'FC50PM', origen: 'planta', version: 2, vigente_desde: '2026-11-01' }, problema: null, seleccionado: true },
    { clave: 'ajuste-a1', tipo: 'ajuste', motivo: 'Anulados', fecha: '2026-11-09', cliente: { id: 'c1', nombre: 'Falco <Fruit>' }, marca: 'FALCO FRUIT', proceso: 'AR01STD20016', envase: { id: 'v1', nombre: 'FALCO 5KG', codigo: 'FC50PM' }, cajas: -20, pallets: 1, estandar: { codigo: 'FC50PM', origen: 'propio', version: 1, vigente_desde: '2026-11-01' }, problema: null, seleccionado: true },
    { clave: 'g3', fecha: '2026-11-11', cliente: { id: 'c1', nombre: 'Falco <Fruit>' }, marca: 'FALCO FRUIT', proceso: 'AR01STD20016', envase: { id: 'v2', nombre: 'FALCO 2,5KG' }, cajas: 12, pallets: 1, estandar: null, problema: 'sin_estandar', seleccionado: false },
];
const material = (codigo, teorico, ahora, faltante) => ({ item_material_id: `i-${codigo}`, codigo, nombre: `Insumo ${codigo}`, unidad_medida: 'UN', redondeo: 'entero', dueno: 'Falco', teorico, fraccion_anterior: 0, pendiente_anterior: 0, a_descontar: ahora + faltante, disponible: ahora, a_descontar_ahora: ahora, faltante, fraccion_resultante: 0 });

async function fixture({ width = 1366, canRebate = true } = {}) {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
    const context = await browser.newContext({ viewport: { width, height: 1000 } });
    const page = await context.newPage(); const calls = []; const errors = [];
    await context.addInitScript(({ width }) => { localStorage.setItem('estiba_wms_office_token', 'token'); localStorage.setItem('estiba_wms_office_theme', width === 390 ? 'dark-industrial' : 'light-professional'); }, { width });
    page.on('pageerror', (e) => errors.push(e.message));
    await context.route('http://localhost/**', async (route) => {
        const url = new URL(route.request().url()); const path = url.pathname; const method = route.request().method();
        if (path === '/test') return route.fulfill({ body: html, contentType: 'text/html' });
        if (path.startsWith('/build/')) return route.fulfill({ body: readFileSync(resolve(root, 'public' + path)), contentType: path.endsWith('.css') ? 'text/css' : 'application/octet-stream' });
        if (path === '/rebate.js') return route.fulfill({ body: readFileSync(resolve(root, 'resources/js/office-material-standard-consumption.js'), 'utf8').replace("import './office-navigation';", ''), contentType: 'text/javascript' });
        const body = route.request().postData(); calls.push({ method, path, body: body ? JSON.parse(body) : null });
        if (path.endsWith('/rebajas-estandar/saldos')) return route.fulfill({ json: { data: [{ item: { codigo: 'AR5048', nombre: 'ETIQUETA', unidad_medida: 'UN' }, pendiente: -20, fraccion: 0 }, { item: { codigo: '031000', nombre: 'PALLET', unidad_medida: 'UN' }, pendiente: 0, fraccion: 0.1656 }] } });
        if (path.endsWith('/rebajas-estandar/opciones')) return route.fulfill({ json: { almacenes: [{ id: 'a1', nombre: 'Packing línea 1', centro_costo: 'PACK-01' }], clientes: [{ id: 'c1', nombre: 'Falco <Fruit>' }], puede_rebajar: canRebate, hoy: '2026-11-11' } });
        if (path.endsWith('/previsualizar')) {
            const selected = JSON.parse(body).grupos;
            const chosen = groups.filter((g) => !g.problema && (!selected || selected.includes(g.clave)));
            const cajas = chosen.reduce((s, g) => s + g.cajas, 0);
            return route.fulfill({ json: { almacen: { id: 'a1', nombre: 'Packing línea 1' }, grupos: groups.map((g) => ({ ...g, seleccionado: chosen.includes(g) })),
                materiales: [material('031026', cajas, Math.min(cajas, 150), Math.max(0, cajas - 150)), material('031000', cajas * 0.0062, 1, 0), { ...material('AR5048', -20, 0, 0), faltante: -20, a_descontar: -20 }], totales: { grupos: 4, cajas: 200, cajas_seleccionadas: cajas, cajas_sin_estandar: 12, faltantes: cajas > 150 ? 1 : 0 } } });
        }
        if (path.endsWith('/rebajas-estandar') && method === 'POST') return route.fulfill({ status: 201, json: { data: { id: 'r1', codigo: 'REB-000001', almacen: { nombre: 'Packing línea 1' }, cajas: 188, pallets: 3, resumen: { con_faltante: 1 }, movimientos: 3, observacion: null,
            produccion: [{ fecha: '2026-11-10', cliente: 'Falco <Fruit>', marca: 'FALCO FRUIT', proceso: 'AR01STD20016', embalaje: 'FALCO 5KG', cajas: 158, pallets: 2 }],
            consumos: [{ item: { codigo: '031026', nombre: 'TAPA' }, teorico: 188, a_descontar: 188, descontado: 150, pendiente: 38, fraccion_resultante: 0, folios: 2 }] } } });
        if (path.endsWith('/rebajas-estandar')) return route.fulfill({ json: { data: [{ id: 'r1', codigo: 'REB-000001', almacen: { nombre: 'Packing línea 1' }, desde: '2026-11-10', hasta: '2026-11-11', cajas: 188, pallets: 3, usuario: 'Ana', confirmada_at: '2026-11-16T15:00:00Z', resumen: { con_faltante: 1 } }], meta: { page: 1, last_page: 1, total: 1 } } });
        return route.fulfill({ status: 404, json: { message: 'no' } });
    });
    await page.goto('http://localhost/test');
    await page.evaluate(async () => { document.documentElement.dataset.officeTheme = localStorage.getItem('estiba_wms_office_theme'); document.querySelector('[data-office-shell]')?.remove(); await import('/rebate.js'); });
    await page.locator('#rebateFilters [name="almacen_id"] option[value="a1"]').waitFor({ state: 'attached' });
    return { browser, page, calls, errors };
}

test('vista previa marca faltantes, recalcula al quitar un grupo y confirma con un identificador estable', async () => {
    const { browser, page, calls, errors } = await fixture();
    try {
        assert.equal(await page.locator('#rebateFilters [name="desde"]').inputValue(), '2026-11-11');
        await page.locator('#rebateFilters [name="desde"]').fill('2026-11-10');
        await page.getByRole('button', { name: 'Vista previa' }).click(); await page.locator('#rebateGroups tr').nth(3).waitFor();
        assert.match(await page.locator('#rebateGroups').textContent(), /Ajuste Anulados/);
        assert.match(await page.locator('#rebateMaterials').textContent(), /20 a favor/);
        await page.locator('#rebateBalances table').waitFor();
        assert.match(await page.locator('#rebateBalances').textContent(), /0,1656/);
        assert.equal(await page.locator('[data-group="g3"]').isDisabled(), true);
        assert.match(await page.locator('#rebateGroups').textContent(), /Sin estándar vigente/);
        assert.equal(await page.locator('#rebateMaterials .standards-row--shortage').count(), 1);
        await page.screenshot({ path: resolve(root, 'storage/app/ui/oficina/rebaja-estandar-1366.png'), fullPage: true });

        await page.locator('[data-group="g2"]').uncheck();
        await page.waitForFunction(() => document.querySelector('#rebateTotals').textContent.includes('138 cajas elegidas'));
        assert.deepEqual(calls.filter((c) => c.path.endsWith('/previsualizar')).at(-1).body.grupos, ['g1', 'ajuste-a1']);
        await page.locator('[data-group="g2"]').check();
        await page.waitForFunction(() => document.querySelector('#rebateTotals').textContent.includes('168 cajas elegidas'));

        await page.getByRole('button', { name: 'Confirmar rebaja' }).click();
        await page.locator('#rebateDetail h3').waitFor();
        const post = calls.find((c) => c.method === 'POST' && c.path.endsWith('/rebajas-estandar'));
        assert.match(post.body.operacion_id, /^[0-9a-f-]{36}$/);
        assert.deepEqual(post.body.grupos, [{ clave: 'g1', cajas: 158 }, { clave: 'g2', cajas: 30 }, { clave: 'ajuste-a1', cajas: -20 }]);
        assert.equal(post.body.almacen_id, 'a1');
        assert.match(await page.locator('#rebateNotice').textContent(), /REB-000001 confirmada: 188 cajas\. 1 ítems quedaron con faltante/);
        assert.match(await page.locator('#rebateDetail').textContent(), /Falco <Fruit>/);
        assert.equal(await page.locator('#rebateDetail fruit').count(), 0);
        assert.deepEqual(errors, []);
    } finally { await browser.close(); }
});

test('sin permiso de rebaja solo consulta y en celular cabe en el ancho', async () => {
    const { browser, page, errors } = await fixture({ width: 390, canRebate: false });
    try {
        await page.getByRole('button', { name: 'Vista previa' }).click(); await page.locator('#rebateMaterials tr').first().waitFor();
        assert.equal(await page.locator('#rebateConfirm').isHidden(), true);
        const layout = await page.evaluate(() => ({ width: document.documentElement.scrollWidth, viewport: innerWidth }));
        assert.ok(layout.width <= layout.viewport, JSON.stringify(layout));
        await page.screenshot({ path: resolve(root, 'storage/app/ui/oficina/rebaja-estandar-390.png'), fullPage: true });
        assert.deepEqual(errors, []);
    } finally { await browser.close(); }
});
