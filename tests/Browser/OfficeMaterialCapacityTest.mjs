import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
const html = readFileSync(resolve(root, 'storage/app/ui/oficina/capacidad.html'), 'utf8').replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, '');
const pallet = { id: 'p1', codigo: 'P-1', nombre: '<Pallet>', largo_m: 1.2, ancho_m: 1, altura_total_m: 1.5, max_niveles: 1, peso_maximo_kg: 500, activo: true, version: 1 };
const zone = { id: 'z1', nombre: 'Zona A', x_m: 0, y_m: 0, largo_m: 6, ancho_m: 4, altura_util_m: 3, max_niveles: 1, permite_giro: true, limite_pallets: null, peso_maximo_kg: 500, tipo_pallet_ids: ['p1'], modo: 'piso' };
const stock = { saldo_id: 's1', saldo_version_actual: 8, id: null, folio: 'FM-1', item: 'Cajas', cantidad_actual: 120, unidad_medida: 'unidades', medidas_vigentes: false };
function simulation() { return { data: { pallets_referencia: 20, aviso: 'Referencia sin descontar ocupación.', zonas: [{ nombre: 'Zona A', modo: 'piso', puestos: 20, niveles: 1, pallets_referencia: 20, superficie_util_m2: 24, volumen_util_m3: 72, estado: 'referencia', motivos: [] }] } }; }
async function fixture({ write = false, intercept, mobile = false } = {}) {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
    const context = await browser.newContext({ viewport: { width: mobile ? 390 : 1440, height: 1000 } });
    const page = await context.newPage(); const calls = []; const errors = [];
    let config = { largo_m: 10, ancho_m: 8, altura_util_m: 4, zonas: [structuredClone(zone)], superficie_util_m2: 24, volumen_util_m3: 72, version: 1 };
    let types = [structuredClone(pallet)]; let currentStock = structuredClone(stock);
    page.on('pageerror', (error) => errors.push(error.message));
    await context.addInitScript(() => localStorage.setItem('estiba_wms_office_token', 'test-token'));
    await context.route('http://localhost/**', async (route) => {
        const request = route.request(); const url = new URL(request.url());
        if (url.pathname === '/test') return route.fulfill({ body: html, contentType: 'text/html' });
        if (url.pathname.startsWith('/build/')) return route.fulfill({ body: readFileSync(resolve(root, 'public' + url.pathname)), contentType: url.pathname.endsWith('.css') ? 'text/css' : 'application/octet-stream' });
        if (url.pathname === '/office-navigation.js') return route.fulfill({ body: '', contentType: 'text/javascript' });
        if (url.pathname === '/office-material-capacity.js') return route.fulfill({ body: readFileSync(resolve(root, 'resources/js/office-material-capacity.js')), contentType: 'text/javascript' });
        const call = { path: url.pathname, method: request.method(), body: request.postDataJSON() }; calls.push(call);
        if (intercept && await intercept(route, url, call)) return;
        if (url.pathname.endsWith('/catalogo')) return route.fulfill({ json: { almacenes: [{ id: 'w1', nombre: 'Central', codigo: 'BC', activo: true, configuracion: config }], tipos: types, permisos: { configurar: write, medir: write }, zona_horaria: 'America/Santiago' } });
        if (url.pathname.endsWith('/almacenes/w1') && request.method() === 'PUT') { config = { ...config, ...call.body, version: 2 }; return route.fulfill({ json: { data: config } }); }
        if (url.pathname.endsWith('/cargas/s1') && request.method() === 'PUT') { currentStock = { ...currentStock, ...call.body, id: 'm1', version: 1, medidas_vigentes: true }; return route.fulfill({ json: { data: currentStock } }); }
        if (url.pathname.endsWith('/cargas')) return route.fulfill({ json: { data: [currentStock], meta: { pagina: 1, paginas: 1, total: 1 } } });
        if (url.pathname.endsWith('/simular')) return route.fulfill({ json: simulation() });
        if (url.pathname.endsWith('/historial')) return route.fulfill({ json: { data: [{ id: 'e1', registro_id: 's1', entidad: 'carga', user_id: 1, usuario: 'Ana Pérez', ocurrido_at: '2026-10-23 12:00:00', anterior: null, resultado: { folio: 'FM-1', largo_m: 1.2, version: 1 } }], meta: { pagina: 1, paginas: 1, total: 1 } } });
        if (url.pathname.endsWith('/tipos-pallet') && request.method() === 'POST') { const item = { id: 'p2', ...call.body, version: 1 }; types = [...types, item]; return route.fulfill({ status: 201, json: { data: item } }); }
        return route.fulfill({ status: 404 });
    });
    await page.goto('http://localhost/test');
    await page.evaluate(async () => { document.querySelector('[data-office-shell]')?.remove(); await import('/office-material-capacity.js'); });
    await page.waitForFunction(() => document.getElementById('capacityStockRows').textContent.includes('FM-1'));
    return { browser, page, calls, errors };
}

test('consulta en tablet permite simular y consultar historia sin habilitar escrituras', async () => {
    const { browser, page, calls, errors } = await fixture({ mobile: true });
    try {
        assert.equal(await page.locator('#capacityNewType').isHidden(), true);
        assert.equal(await page.locator('[data-measure]').count(), 0);
        assert.equal(await page.locator('#capacityWarehouseForm [name="largo_m"]').isDisabled(), true);
        await page.fill('#capacitySimulationForm [name="peso_unitario_kg"]', '350');
        await page.getByRole('button', { name: 'Simular capacidad de referencia' }).click();
        await page.waitForFunction(() => document.getElementById('capacitySimulationResult').textContent.includes('20 pallets'));
        await page.locator('[data-history-kind="carga"]').click();
        await page.waitForFunction(() => document.getElementById('capacityHistoryRows').textContent.includes('Ana Pérez'));
        assert.ok(calls.every((c) => c.method === 'GET'));
        assert.deepEqual(errors, []);
        assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));
        await page.screenshot({ path: resolve(root, 'storage/app/ui/oficina/capacidad-tablet.png'), fullPage: true });
    } finally { await browser.close(); }
});

test('configuración de rack conserva las zonas y envía huecos, permisos y versión', async () => {
    const { browser, page, calls, errors } = await fixture({ write: true });
    try {
        await page.locator('#capacityConfiguration > summary').click();
        await page.selectOption('[data-zone-field="modo"]', 'rack');
        await page.fill('[data-zone-field="limite_pallets"]', '4');
        await page.fill('[data-zone-field="hueco_largo_m"]', '1.2');
        await page.fill('[data-zone-field="hueco_ancho_m"]', '1');
        await page.fill('[data-zone-field="hueco_altura_m"]', '1.5');
        await page.getByRole('button', { name: 'Guardar dimensiones y zonas' }).click();
        await page.waitForFunction(() => document.getElementById('capacityNotice').textContent.includes('Guardado y registrado'));
        const saved = calls.find((c) => c.method === 'PUT');
        assert.equal(saved.body.version, 1); assert.equal(saved.body.zonas[0].id, 'z1');
        assert.equal(saved.body.zonas[0].modo, 'rack'); assert.equal(saved.body.zonas[0].hueco_altura_m, 1.5);
        assert.deepEqual(saved.body.zonas[0].tipo_pallet_ids, ['p1']);
        assert.match(saved.body.operacion_id, /^[0-9a-f-]{36}$/);
        assert.match(await page.locator('#capacityMetrics').textContent(), /Versión 2/);
        assert.deepEqual(errors, []);
        await page.screenshot({ path: resolve(root, 'storage/app/ui/oficina/capacidad-escritorio.png'), fullPage: true });
    } finally { await browser.close(); }
});

test('reintentar un alta conserva el identificador y las medidas usan la versión del saldo', async () => {
    let first = true; const writes = [];
    const { browser, page, calls, errors } = await fixture({ write: true, intercept: async (route, url, call) => {
        if (!url.pathname.endsWith('/tipos-pallet') || call.method !== 'POST') return false;
        writes.push(call.body);
        if (!first) return false;
        first = false; await route.fulfill({ status: 500, json: { message: 'Respuesta interrumpida, reintenta.' } }); return true;
    } });
    try {
        await page.locator('#capacityTypes > summary').click();
        await page.getByRole('button', { name: 'Crear tipo de pallet' }).click();
        for (const [key, value] of Object.entries({ codigo: 'P-2', nombre: 'Pallet nuevo', largo_m: '1.2', ancho_m: '1', altura_total_m: '1.5' })) await page.fill(`#capacityTypeForm [name="${key}"]`, value);
        await page.getByRole('button', { name: 'Guardar tipo de pallet' }).click();
        await page.waitForFunction(() => document.getElementById('capacityNotice').textContent.includes('Respuesta interrumpida'));
        await page.getByRole('button', { name: 'Guardar tipo de pallet' }).click();
        await page.waitForFunction(() => document.getElementById('capacityNotice').textContent.includes('Guardado y registrado'));
        assert.equal(writes.length, 2); assert.deepEqual(writes[0], writes[1]);
        await page.getByRole('button', { name: 'Registrar medidas', exact: true }).click();
        await page.fill('#capacityMeasurementForm [name="bultos_fisicos"]', '2');
        await page.fill('#capacityMeasurementForm [name="altura_total_m"]', '2');
        await page.getByRole('button', { name: 'Guardar medidas reales' }).click();
        await page.waitForFunction(() => document.getElementById('capacityStockRows').textContent.includes('Medidas vigentes'));
        const saved = calls.find((c) => c.path.endsWith('/cargas/s1') && c.method === 'PUT');
        assert.equal(saved.body.saldo_version, 8); assert.equal(saved.body.bultos_fisicos, 2); assert.equal(saved.body.altura_total_m, 2);
        assert.deepEqual(errors, []);
    } finally { await browser.close(); }
});

test('editar la simulación descarta una respuesta anterior demorada', async () => {
    let release; const deferred = new Promise((resolve) => { release = resolve; });
    const { browser, page, errors } = await fixture({ intercept: async (route, url) => {
        if (!url.pathname.endsWith('/simular')) return false;
        await deferred; await route.fulfill({ json: simulation() }); return true;
    } });
    try {
        await page.getByRole('button', { name: 'Simular capacidad de referencia' }).click();
        await page.waitForFunction(() => document.getElementById('capacitySimulationResult').textContent.includes('Simulando'));
        await page.fill('#capacitySimulationForm [name="altura_total_m"]', '2');
        const response = page.waitForResponse((r) => r.url().includes('/simular?')); release(); await response;
        await page.evaluate(() => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve))));
        assert.equal(await page.locator('#capacitySimulationResult').textContent(), '');
        assert.deepEqual(errors, []);
    } finally { release(); await browser.close(); }
});
