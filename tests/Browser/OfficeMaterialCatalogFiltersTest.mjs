import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
const html = readFileSync(resolve(root, 'storage/app/ui/oficina/items.html'), 'utf8');
const seasons = [{ id: 'actual', codigo: 'TEMP-2627', activa: true }, { id: 'anterior', codigo: 'TEMP-PR-03', activa: false }, { id: 'vacia', codigo: 'SIN-CATALOGO', activa: false }];
const a = { id: 'master-a', codigo: 'CLI-A', nombre: 'CLIENTE COMÚN', es_maestro: true };
const b = { id: 'master-b', codigo: 'CLI-B', nombre: 'CLIENTE COMÚN', es_maestro: true };
const legacy = { id: 'legacy', codigo: 'HIST', nombre: '<img src=x onerror="window.catalogXss=true">', es_maestro: false, temporada_codigo: 'TEMP-PR-03' };
const row = (id, season, master, client, category = 'CAJAS') => ({ id, codigo: id, nombre: `Caja cartón ${id}`, categoria: category, unidad_medida: 'kg', activo: true, cantidad_fotos: 0,
    cliente: { id: client, nombre: master.nombre, temporada: seasons.find(s => s.id === season) }, masterId: master.id });
const rows = [row('NUEVO', 'actual', a, 'projection-new'), row('ANTERIOR', 'anterior', a, 'projection-old'), row('HOMONIMO', 'actual', b, 'projection-b'), row('LEGADO', 'anterior', legacy, 'legacy', 'FILM')];

async function scenario(width, callback) {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
    try {
        const context = await browser.newContext({ viewport: { width, height: 900 }, colorScheme: width === 390 ? 'dark' : 'light' });
        const page = await context.newPage(); const errors = []; const calls = [];
        page.setDefaultTimeout(10000);
        page.setDefaultNavigationTimeout(10000);
        page.on('pageerror', e => errors.push(e.message));
        await context.addInitScript((theme) => {
            localStorage.setItem('estiba_wms_office_token', 'catalog-filter-fixture');
            localStorage.setItem('estiba_wms_office_theme', theme);
            localStorage.setItem('estiba_wms_office_identity', JSON.stringify({ rol: 'consulta', nombre: 'Consulta', puede_consultar_despachos_materiales: true, modulos_acceso: ['materiales.catalogos'] }));
        }, width === 390 ? 'dark-industrial' : 'light-professional');
        await context.route('http://localhost/**', async route => {
            const request = route.request(); const url = new URL(request.url()); const path = url.pathname; const params = url.searchParams;
            if (path.startsWith('/build/')) return route.fulfill({ body: readFileSync(resolve(root, `public${path}`)), contentType: path.endsWith('.js') ? 'text/javascript' : path.endsWith('.css') ? 'text/css' : 'application/octet-stream' });
            if (/\.(?:svg|png)$/.test(path) && existsSync(resolve(root, `public${path}`))) return route.fulfill({ body: readFileSync(resolve(root, `public${path}`)), contentType: path.endsWith('.svg') ? 'image/svg+xml' : 'image/png' });
            if (path === '/oficina/materiales/items') return route.fulfill({ body: html, contentType: 'text/html' });
            if (path === '/api/oficina/contexto') return route.fulfill({ json: { data: { temporada: seasons[0], planta: 'Agro Rosario', avisos_cierre: [], avisos_materiales: [] } } });
            if (path === '/api/materiales/reposicion/resumen') return route.fulfill({ json: { data: { quiebre: 0, bajo_minimo: 0 } } });
            calls.push(url);
            if (path.startsWith('/api/materiales/items/catalogo/exportar/')) return route.fulfill({ body: 'codigo;nombre\nNUEVO;CAJA CARTÓN\n', contentType: 'text/csv' });
            if (path !== '/api/materiales/items/catalogo') return route.fulfill({ status: 404 });
            const season = params.get('temporada_id') || (params.has('temporada_actual') ? 'actual' : '');
            const clients = season === 'actual' ? [a, b] : season === 'anterior' ? [a, legacy] : season === 'vacia' ? [] : [a, b, legacy];
            let data = rows.filter(i => (!season || i.cliente.temporada.id === season)
                && (!params.has('cliente_global_id') || i.masterId === params.get('cliente_global_id'))
                && (!params.has('cliente_id') || i.cliente.id === params.get('cliente_id')));
            const categories = [...new Set(data.map(i => i.categoria))];
            data = data.filter(i => !params.has('categoria') || i.categoria === params.get('categoria'));
            return route.fulfill({ json: { data, total: data.length, pagina: 1, paginas: 1, temporada_seleccionada_id: season || null,
                catalogos: { temporadas: seasons, clientes: [], clientes_maestros: clients, categorias: categories, tipos_item: [] } } });
        });
        await page.goto('http://localhost/oficina/materiales/items');
        await page.waitForFunction(() => document.getElementById('itemScope').textContent.includes('TEMP-2627'));
        await callback({ page, calls });
        assert.deepEqual(errors, []);
        await context.close();
    } finally { await browser.close(); }
}

test('catálogo inicia en temporada activa y un cliente consulta todos sus períodos sin duplicar opciones', { timeout: 90000 }, async () => {
    for (const width of [1366, 390]) await scenario(width, async ({ page, calls }) => {
        assert.equal(await page.locator('[name="temporada_id"]').inputValue(), 'actual');
        assert.equal(await page.locator('[name="cliente_id"] option').count(), 3);
        assert.equal(await page.locator('[name="cliente_id"]').textContent(), 'TodosCLI-A · CLIENTE COMÚNCLI-B · CLIENTE COMÚN');
        await page.locator('[name="cliente_id"]').selectOption('master-a');
        await page.waitForFunction(() => document.getElementById('itemRows').children.length === 1);
        assert.match(await page.locator('#itemRows').textContent(), /CAJA CARTÓN NUEVO.*KG/s);
        await page.locator('[name="temporada_id"]').selectOption('');
        await page.waitForFunction(() => document.getElementById('itemRows').textContent.includes('ANTERIOR'));
        assert.equal(await page.locator('[name="cliente_id"]').inputValue(), 'master-a');
        assert.equal(await page.locator('[name="cliente_id"] option[value="master-a"]').count(), 1);
        assert.match(await page.locator('[name="cliente_id"] option[value="legacy"]').textContent(), /histórico · TEMP-PR-03/);
        assert.equal(await page.locator('#itemRows tr').count(), 2);
        await page.locator('[name="categoria"]').selectOption('CAJAS');
        const exportRequest = page.waitForRequest(r => r.url().includes('/exportar/csv'));
        await page.getByRole('button', { name: 'Descargar CSV', exact: true }).click();
        const query = new URL((await exportRequest).url()).searchParams;
        assert.equal(query.get('cliente_global_id'), 'master-a');
        assert.equal(query.has('cliente_id'), false);
        assert.equal(query.has('temporada_id'), false);
        assert.equal(query.get('categoria'), 'CAJAS');
        assert.ok(calls.find(u => u.pathname === '/api/materiales/items/catalogo').searchParams.has('temporada_actual'));
        assert.equal(await page.evaluate(() => !!window.catalogXss), false);
        assert.equal(await page.locator('[name="cliente_id"] img').count(), 0);
        assert.equal(await page.locator('#itemFilters select').evaluateAll(controls => controls.every(control => {
            const rect = control.getBoundingClientRect(); return rect.left >= 0 && rect.right <= innerWidth;
        })), true, 'Todos los filtros deben caber en la pantalla');
        assert.equal(await page.evaluate(() => document.documentElement.dataset.officeTheme), width === 390 ? 'dark-industrial' : 'light-professional');
        await page.evaluate(() => window.scrollTo({ top: 0, left: 0, behavior: 'instant' }));
        await page.screenshot({ path: resolve(root, `storage/app/ui/oficina/catalogo-clientes-${width}.png`), fullPage: true, timeout: 10000 });
    });
});

test('cambiar a una temporada sin ese cliente limpia filtros y los históricos sin maestro siguen consultables', { timeout: 45000 }, async () => {
    await scenario(1366, async ({ page, calls }) => {
        await page.locator('[name="cliente_id"]').selectOption('master-b');
        await page.waitForFunction(() => document.getElementById('itemRows').textContent.includes('HOMONIMO'));
        await page.locator('[name="temporada_id"]').selectOption('anterior');
        await page.waitForFunction(() => document.getElementById('itemRows').textContent.includes('LEGADO'));
        assert.equal(await page.locator('[name="cliente_id"]').inputValue(), '');
        assert.equal(await page.locator('[name="categoria"]').inputValue(), '');
        await page.locator('[name="cliente_id"]').selectOption('legacy');
        await page.waitForFunction(() => document.getElementById('itemRows').children.length === 1);
        assert.equal(calls.at(-1).searchParams.get('cliente_id'), 'legacy');
        assert.equal(calls.at(-1).searchParams.has('cliente_global_id'), false);
        await page.locator('[name="temporada_id"]').selectOption('vacia');
        await page.waitForFunction(() => document.getElementById('itemRows').textContent.includes('Sin ítems'));
        assert.equal(await page.locator('[name="cliente_id"] option').count(), 1);
    });
});
