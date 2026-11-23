import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { extname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
const views = {
    '/oficina/materiales/almacenes': 'inventario-cc',
    '/oficina/materiales/reposicion': 'reposicion',
    '/oficina/materiales/items/niveles': 'niveles',
};
const mime = { '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml', '.woff2': 'font/woff2' };
const identity = { nombre: 'Supervisión', rol: 'administrador' };

async function fixture(t, path, handle) {
    const browser = await chromium.launch({
        executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined,
        args: ['--no-sandbox', '--disable-gpu'],
    });
    t.after(() => browser.close());
    const context = await browser.newContext({ viewport: { width: 1440, height: 950 }, acceptDownloads: true });
    await context.addInitScript((user) => {
        localStorage.setItem('estiba_wms_office_token', 'test-token');
        localStorage.setItem('estiba_wms_office_identity', JSON.stringify(user));
        localStorage.setItem('estiba_wms_office_theme', 'light-professional');
    }, identity);
    const errors = [];
    const page = await context.newPage();
    page.on('pageerror', (error) => errors.push(error.message));
    await context.route('http://localhost/**', (route) => {
        const url = new URL(route.request().url());
        if (url.pathname === path) {
            const body = readFileSync(resolve(root, `storage/app/ui/oficina/${views[path]}.html`), 'utf8');
            return route.fulfill({ body, contentType: 'text/html' });
        }
        if (url.pathname.startsWith('/build/') || url.pathname.startsWith('/brand/')) {
            try {
                return route.fulfill({ body: readFileSync(resolve(root, `public${url.pathname}`)), contentType: mime[extname(url.pathname)] });
            } catch {
                return route.fulfill({ status: 404 });
            }
        }
        if (url.pathname === '/api/acceso-oficina') return route.fulfill({ json: { usuario: identity } });
        return handle(route, url);
    });
    await page.goto(`http://localhost${path}`);
    return { page, errors };
}

async function verifyThemeSwitch(page, statusSelector) {
    const theme = page.getByRole('combobox', { name: 'Tema de Oficina' });
    const palette = async (key) => {
        await theme.selectOption(key);
        await page.waitForFunction((value) => document.documentElement.dataset.officeTheme === value, key);
        return page.locator(statusSelector).evaluate((status) => {
            const card = status.closest('.folios-bandeja');
            const ticket = document.querySelector('.folios-ticket--indicador');
            return [card, ticket, status.closest('td'), status].map((element) => {
                const style = getComputedStyle(element);
                return [style.backgroundColor, style.color];
            });
        });
    };
    const light = await palette('light-professional');
    const dark = await palette('dark-industrial');
    for (const index of [0, 1, 2]) assert.notEqual(light[index][0], dark[index][0]);
    assert.notEqual(light[3][1], dark[3][1]);
    assert.deepEqual(await palette('light-professional'), light);
    assert.deepEqual(await palette('dark-industrial'), dark);
    await page.setViewportSize({ width: 390, height: 800 });
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
}

test('Inventario CC cambia de perspectiva, mantiene el filtro y exporta la selección', async (t) => {
    const cliente = { id: 'cli', codigo: 'CLI', nombre: 'Cliente' };
    const item = { id: 'item', codigo: 'FILM', nombre: 'Film' };
    const central = { id: 'bc', codigo: 'BC', nombre: 'Bodega Central', tipo: 'fisico' };
    const packing = { id: 'cc', codigo: 'CC', nombre: 'Packing', tipo: 'virtual' };
    const bodega = {
        cliente, item, almacen: central, folio_id: 'folio', numero_folio: 'MAT0001234', lote: 'A1',
        cantidad_actual: 6, cantidad_reservada: 1, cantidad_disponible: 5, unidad_medida: 'kg',
        vencimiento: { estado: 'por_vencer', etiqueta: 'Vence en 4 días' },
    };
    let exportParams;
    let fails = true;
    const { page, errors } = await fixture(t, '/oficina/materiales/almacenes', (route, url) => {
        if (url.pathname === '/api/materiales/almacenes') {
            if (fails) return route.fulfill({ status: 503, json: { message: 'Inventario temporalmente indisponible.' } });
            return route.fulfill({ json: {
                resumen: { folios: 1, almacenes: 2, items: 1 },
                almacenes: [central, packing],
                perspectivas: {
                    bodega: [bodega],
                    centros_costo: [{ ...bodega, almacen: packing }],
                    total_empresa: [{ cliente, item, en_bodega: 6, en_centros_costo: 6, total_empresa: 12, folios: 1, unidad_medida: 'kg' }],
                },
            } });
        }
        if (url.pathname === '/api/materiales/almacenes/exportar') {
            exportParams = url.searchParams;
            return route.fulfill({ body: 'excel' });
        }
        return route.fulfill({ json: { data: [] } });
    });
    await page.getByText('Inventario temporalmente indisponible.').waitFor();
    assert.equal(await page.locator('#custodyFolioCount').innerText(), '—');
    assert.equal(await page.locator('#custodyExport').isDisabled(), true);
    fails = false;
    await page.locator('#custodyReload').click();
    const mark = page.locator('#custodyTableBody [data-estado="atencion"]');
    await mark.waitFor();
    assert.match(await mark.innerText(), /◆\s*Vence en 4 días/);
    assert.equal(await page.locator('#custodyTableBody .folios-code strong').innerText(), '1234');
    await verifyThemeSwitch(page, '#custodyTableBody [data-estado="atencion"]');
    await page.getByRole('searchbox', { name: 'Buscar', exact: true }).fill('inexistente');
    await page.getByText('Sin resultados para los filtros seleccionados.').waitFor();
    const total = page.getByRole('button', { name: 'Existencia total empresa' });
    await total.press('Enter');
    assert.equal(await total.getAttribute('aria-pressed'), 'true');
    assert.equal(await page.locator('[name="camara_id"]').isHidden(), true);
    await page.getByRole('searchbox', { name: 'Buscar', exact: true }).fill('Film');
    await page.getByText('12 kg').waitFor();
    const download = page.waitForEvent('download');
    await page.locator('#custodyExport').click();
    assert.equal((await download).suggestedFilename(), 'Inventario_CC_total_empresa.xlsx');
    assert.equal(exportParams.get('perspectiva'), 'total_empresa');
    assert.equal(exportParams.get('q'), 'Film');
    assert.deepEqual(errors, []);
});

const stock = {
    id: 'item', item: 'Film', codigo: 'FILM001234', cliente: 'Exportadora', categoria: 'Embalaje', unidad: 'kg',
    disponible: 3, cantidad_sugerida: 7, dias_cobertura: 2, estado: 'bajo_minimo',
    stock_minimo: 4, punto_reorden: 6, stock_maximo: 10,
};

test('Reposición distingue quiebres de la temporada, filtros y detalle de consumo', async (t) => {
    const { page, errors } = await fixture(t, '/oficina/materiales/reposicion', (route, url) => {
        if (url.pathname === '/api/materiales/reposicion') {
            return route.fulfill({ json: {
                data: url.searchParams.get('estado') === 'normal' ? [] : [stock],
                indicador: { quiebre: 2, bajo_minimo: 3 }, dias: 30, catalogos: { clientes: [], categorias: [] },
            } });
        }
        if (url.pathname === '/api/materiales/reposicion/items/item') {
            const pageNumber = Number(url.searchParams.get('page'));
            return route.fulfill({ json: { data: {
                item: stock, semanas: [], historial_niveles: [],
                movimientos: { current_page: pageNumber, last_page: 2, data: [] },
            } } });
        }
        return route.fulfill({ json: { data: {} } });
    });
    const item = page.getByRole('button', { name: 'Film', exact: true });
    await item.waitFor();
    assert.equal(await page.locator('#replenishmentOut').innerText(), '2');
    assert.match(await page.locator('#summary').innerText(), /1 ítem · Consumo de 30 días/);
    await verifyThemeSwitch(page, '#rows [data-estado="atencion"]');
    await item.press('Enter');
    await page.getByRole('heading', { name: 'FILM001234 · Film' }).waitFor();
    assert.equal(await page.locator('#detail').evaluate((element) => element === document.activeElement), true);
    await page.getByRole('button', { name: 'Siguiente' }).click();
    await page.getByText('Página 2 de 2').waitFor();
    await page.selectOption('[name="estado"]', 'normal');
    await page.getByText('Sin ítems para estos filtros.').waitFor();
    assert.equal(await page.locator('#replenishmentOut').innerText(), '2');
    assert.deepEqual(errors, []);
});

test('Niveles conserva unidad, valores vacíos y confirmación del guardado por ítem', async (t) => {
    const item = { ...stock };
    let saved;
    const { page, errors } = await fixture(t, '/oficina/materiales/items/niveles', (route, url) => {
        if (url.pathname === '/api/materiales/items/niveles') {
            return route.fulfill({ json: { data: [item], indicador: { quiebre: 0, bajo_minimo: 1 }, dias: 30, catalogos: { clientes: [], categorias: [] } } });
        }
        if (url.pathname === '/api/materiales/items/item/niveles') {
            if (route.request().method() === 'PUT') {
                saved = route.request().postDataJSON();
                Object.assign(item, saved);
                return route.fulfill({ json: { data: item } });
            }
            return route.fulfill({ json: { data: { item, semanas: [], historial_niveles: [], movimientos: { current_page: 1, last_page: 1, data: [] } } } });
        }
        return route.fulfill({ json: { data: {} } });
    });
    await page.getByRole('button', { name: 'Film', exact: true }).click();
    await page.locator('#levelsForm').waitFor();
    assert.match(await page.locator('#levelsUnit').innerText(), /kg/);
    await page.locator('[name="stock_minimo"]').fill('5');
    await page.locator('[name="stock_maximo"]').fill('');
    await page.getByRole('button', { name: 'Guardar niveles' }).click();
    await page.waitForFunction(() => !document.getElementById('levelsForm').hidden && document.querySelector('#levelsForm button').disabled === false);
    assert.deepEqual(saved, { stock_minimo: '5', punto_reorden: '6', stock_maximo: null });
    assert.equal(await page.locator('[name="stock_minimo"]').inputValue(), '5');
    assert.equal(await page.locator('[name="stock_maximo"]').inputValue(), '');
    assert.deepEqual(errors, []);
});
