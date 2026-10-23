import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
const html = readFileSync(resolve(root, 'storage/app/ui/oficina/materiales.html'), 'utf8');
const season = { id: 'season', codigo: '2026-27', nombre: 'Temporada', activa: true };
const client = { id: 'client', codigo: 'GE', nombre: 'Cliente General', activo: true, temporada: season };
const item = { id: 'item', codigo: 'CAJ-PL', nombre: 'Caja plana', categoria: 'Embalaje', categoria_operacional: 'material_mp', unidad_medida: 'UN', activo: true, cliente: client };
const warehouses = [{ id: 'central', codigo: 'BOD-CENTRAL', nombre: 'Bodega Central', tipo: 'fisica' }, { id: 'norte', codigo: 'BOD-NORTE', nombre: 'Bodega Norte', tipo: 'fisica' }];
const balances = warehouses.map((w, i) => ({ saldo_id: `saldo-${w.id}`, folio_id: 'same-folio', numero_folio: 'FGE0000001', lote: 'L-1', item,
    categoria_operacional: 'material_mp', estado_operacional: 'disponible', almacen: w, camara: { id: `camera-${w.id}`, codigo: `MAT-${w.id.toUpperCase()}` },
    posicion: { etiqueta: 'B01-P01-N1' }, cantidad_actual: i ? '40.000' : '60.000', cantidad_disponible: i ? '40.000' : '60.000', cantidad_reservada: '0.000', unidad_medida: 'UN', reservable: true }));
const recipe = { id: 'recipe', nombre: 'Armar cajas', activa: true, temporada: season, cliente: client, item_salida: { codigo: 'CAJ-PT', nombre: 'Caja armada', unidad_medida: 'UN' },
    versiones: [{ id: 'version', estado: 'activa', numero_version: 1, cantidad_base_salida: 1, unidad_medida_salida: 'UN', unidades_por_folio_salida: 10,
        componentes: [{ item, cantidad_estandar: 1, unidad_medida: 'UN', es_componente_principal: true }] }] };

async function fixture(section, { intercept } = {}) {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
    const page = await context.newPage(); const calls = []; const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));
    await context.addInitScript(() => {
        localStorage.setItem('estiba_wms_office_token', 'test-token');
        localStorage.setItem('estiba_wms_office_identity', JSON.stringify({ nombre: 'Supervisora', rol: 'supervisor_materiales',
            puede_consultar_despachos_materiales: true, puede_gestionar_despachos_materiales: true, puede_retirar_materiales: true,
            puede_consultar_transformaciones_materiales: true, puede_gestionar_transformaciones_materiales: true }));
    });
    await context.route('http://localhost/**', async (route) => {
        const r = route.request(); const url = new URL(r.url()); const path = url.pathname;
        if (path.startsWith('/build/')) return route.fulfill({ body: readFileSync(resolve(root, `public${path}`)), contentType: path.endsWith('.js') ? 'text/javascript' : 'text/css' });
        if (path.startsWith('/oficina/materiales')) return route.fulfill({ body: html.replace('data-materials-section="resumen"', `data-materials-section="${section}"`), contentType: 'text/html' });
        const call = { path, query: url.searchParams, method: r.method(), body: r.postDataJSON() }; calls.push(call);
        if (intercept && await intercept(route, call)) return;
        if (r.method() === 'POST') return route.fulfill({ status: 201, json: { data: { id: 'created', codigo: 'DM-001' } } });
        if (path === '/api/oficina/contexto') return route.fulfill({ json: { data: { temporada: season, planta: 'Planta de prueba', avisos_materiales: [], avisos_cierre: [] } } });
        if (path === '/api/materiales/catalogo') return route.fulfill({ json: { temporada: season, clientes: [client], items: [item],
            destinos: [{ id: 'packing', tipo: 'virtual', nombre: 'Packing', centro_costo: 'PACK', activo: true }], bodegas: warehouses } });
        if (path === '/api/materiales/inventario') return route.fulfill({ json: { data: balances.filter((b) => !url.searchParams.get('almacen_id') || b.almacen.id === url.searchParams.get('almacen_id')),
            bodegas: warehouses, resumen_clientes: [], resumen_items: [{ item, cantidad_disponible: '100.000' }], resumen_items_almacenes: balances,
            resumen: { clientes: 1, folios: 1 }, meta: { total: 2, last_page: 1, current_page: 1 } } });
        if (path === '/api/materiales/transformaciones/recetas') return route.fulfill({ json: { data: [recipe] } });
        return route.fulfill({ json: { data: [] } });
    });
    await page.goto(`http://localhost/oficina/materiales/${section}`);
    return { browser, page, calls, errors };
}

test('despacho directo consulta y entrega solo desde la bodega elegida', async () => {
    const { browser, page, calls, errors } = await fixture('despachos');
    try {
        await page.waitForFunction(() => document.querySelector('#dispatchWarehouse option[value="norte"]'));
        await page.selectOption('#dispatchWarehouse', 'norte');
        await page.fill('.material-item-search', 'Caja plana');
        await page.locator('[data-select-item="item"]').click();
        assert.match(await page.locator('.material-stock').textContent(), /40 UN/);
        await page.fill('#dispatchMaterialLines [name="cantidad"]', '10');
        await page.selectOption('#dispatchDestination', 'packing');
        await page.check('#dispatchMaterialForm [name="modalidad"][value="directo"]');
        await page.waitForFunction(() => document.getElementById('dispatchFolioSelection').textContent.includes('MAT-NORTE'));
        assert.doesNotMatch(await page.locator('#dispatchFolioSelection').textContent(), /MAT-CENTRAL/);
        await page.locator('#dispatchSubmit').click();
        await page.waitForFunction(() => document.getElementById('officeToasts').textContent.includes('DM-001'));
        const write = calls.find((c) => c.path.endsWith('/despachos/directos') && c.method === 'POST');
        assert.equal(write.body.almacen_origen_id, 'norte'); assert.equal(write.body.retiros[0].folio_id, 'same-folio'); assert.equal(write.body.retiros[0].cantidad, 10);
        assert.deepEqual(errors, []);
        await page.screenshot({ path: resolve(root, 'storage/app/ui/oficina/multibodega-despachos.png'), fullPage: true });
    } finally { await browser.close(); }
});

test('dos saldos del mismo folio abren el despacho del saldo seleccionado', async () => {
    const { browser, page, calls, errors } = await fixture('inventario');
    try {
        const row = page.locator('#materialsInventoryBody tr').filter({ has: page.locator('[data-direct-dispatch="saldo-norte"]') });
        await row.getByRole('button', { name: 'Acciones' }).click();
        await page.getByRole('menuitem', { name: 'Despachar directo', exact: true }).click();
        assert.match(await page.locator('#materialDirectDispatchContext').textContent(), /Bodega Norte/);
        assert.equal(await page.locator('#materialDirectDispatchForm [name="almacen_origen_id"]').inputValue(), 'norte');
        await page.selectOption('#materialDirectDispatchForm [name="destino_material_id"]', 'packing');
        await page.fill('#materialDirectDispatchForm [name="cantidad"]', '5');
        await page.locator('#materialDirectDispatchForm [type="submit"]').click();
        await page.waitForFunction(() => !document.getElementById('materialDirectDispatchDialog').open);
        const write = calls.find((c) => c.path.endsWith('/despachos/directos') && c.method === 'POST');
        assert.equal(write.body.almacen_origen_id, 'norte'); assert.equal(write.body.folio_id, 'same-folio');
        assert.deepEqual(errors, []);
    } finally { await browser.close(); }
});

test('cambiar de bodega descarta una consulta anterior demorada', async () => {
    let release; const deferred = new Promise((resolve) => { release = resolve; });
    const { browser, page, errors } = await fixture('despachos', { intercept: async (route, call) => {
        if (call.path !== '/api/materiales/inventario' || call.query.get('almacen_id') !== 'central') return false;
        await deferred;
        await route.fulfill({ json: { data: [balances[0]], meta: { last_page: 1 } } }); return true;
    } });
    try {
        await page.waitForFunction(() => document.querySelector('#dispatchWarehouse option[value="norte"]'));
        await page.fill('.material-item-search', 'Caja plana');
        await page.locator('[data-select-item="item"]').click();
        await page.fill('#dispatchMaterialLines [name="cantidad"]', '10');
        const oldRequest = page.waitForRequest((r) => r.url().includes('almacen_id=central'));
        await page.check('#dispatchMaterialForm [name="modalidad"][value="directo"]'); await oldRequest;
        await page.selectOption('#dispatchWarehouse', 'norte');
        await page.waitForFunction(() => document.getElementById('dispatchFolioSelection').textContent.includes('MAT-NORTE'));
        const oldResponse = page.waitForResponse((r) => r.url().includes('almacen_id=central')); release(); await oldResponse;
        await page.evaluate(() => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve))));
        assert.doesNotMatch(await page.locator('#dispatchFolioSelection').textContent(), /MAT-CENTRAL/);
        assert.deepEqual(errors, []);
    } finally { release(); await browser.close(); }
});

test('la orden informa cobertura por bodega y conserva el origen en el borrador', async () => {
    const { browser, page, calls, errors } = await fixture('ordenes');
    try {
        await page.waitForFunction(() => document.querySelector('#materialOrderForm [name="almacen_origen_id"] option[value="norte"]'));
        await page.selectOption('#materialOrderForm [name="almacen_origen_id"]', 'norte');
        await page.fill('#materialOrderForm [name="cantidad_planificada_salida"]', '50');
        assert.match(await page.locator('#materialOrderRequirements').textContent(), /40 disponibles[\s\S]*Faltan 10/);
        await page.fill('#materialOrderForm [name="cantidad_planificada_salida"]', '20');
        await page.screenshot({ path: resolve(root, 'storage/app/ui/oficina/multibodega-ordenes.png'), fullPage: true });
        await page.locator('#saveMaterialOrder').click();
        await page.waitForFunction(() => document.getElementById('officeToasts').textContent.includes('creada en borrador'));
        const write = calls.find((c) => c.path.endsWith('/transformaciones/ordenes') && c.method === 'POST');
        assert.equal(write.body.almacen_origen_id, 'norte'); assert.equal(write.body.cantidad_planificada_salida, 20);
        assert.deepEqual(errors, []);
    } finally { await browser.close(); }
});
