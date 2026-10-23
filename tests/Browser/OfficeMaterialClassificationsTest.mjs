import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
const html = readFileSync(resolve(root, 'storage/app/ui/oficina/catalogos.html'), 'utf8');
const season = { id: 'season', codigo: 'TEMP-2627', nombre: 'Temporada', activa: true };
const client = { id: 'material-client', cliente_id: 'client', codigo: 'CLI', nombre: 'Cliente de prueba', activo: true, temporada: season };

test('categorías y tipos alimentan los ítems, muestran errores y conservan valores históricos inactivos', async () => {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
    try {
        for (const [width, theme] of [[1366, 'light-professional'], [390, 'dark-industrial']]) {
            const context = await browser.newContext({ viewport: { width, height: 900 } });
            const page = await context.newPage();
            const errors = [];
            const writes = [];
            const categories = [{ id: 'cat-cajas', nombre: 'Cajas', activo: true, en_uso: false }];
            const types = [
                { id: 'type-insumo', nombre: 'Insumo', activo: true, codigo_base: 'insumo', categoria_operacional: 'insumo', categoria_operacional_etiqueta: 'Insumo', en_uso: false },
                { id: 'type-mp', nombre: 'Material MP', activo: true, codigo_base: 'material_mp', categoria_operacional: 'material_mp', categoria_operacional_etiqueta: 'Material de embalaje sin preparar', en_uso: false },
            ];
            const items = [];
            page.on('pageerror', error => errors.push(error.message));
            await context.addInitScript(theme => {
                localStorage.setItem('estiba_wms_office_token', 'classification-token');
                localStorage.setItem('estiba_wms_office_theme', theme);
                localStorage.setItem('estiba_wms_office_identity', JSON.stringify({
                    nombre: 'Administrador Materiales', rol: 'administrador',
                    puede_administrar_catalogos_materiales: true, puede_consultar_despachos_materiales: true,
                    modulos_acceso: ['materiales.catalogos', 'materiales.resumen'],
                }));
            }, theme);
            await context.route('http://localhost/**', async route => {
                const request = route.request(); const path = new URL(request.url()).pathname;
                if (path.startsWith('/build/')) return route.fulfill({ body: readFileSync(resolve(root, `public${path}`)), contentType: path.endsWith('.js') ? 'text/javascript' : 'text/css' });
                if (path === '/oficina/materiales/catalogos') return route.fulfill({ body: html, contentType: 'text/html' });
                if (path === '/api/oficina/contexto') return route.fulfill({ json: { data: { temporada: season, planta: 'Planta', avisos_cierre: [], avisos_materiales: [] } } });
                if (path === '/api/materiales/reposicion/resumen') return route.fulfill({ json: { data: { quiebre: 0, bajo_minimo: 0 } } });
                if (path === '/api/administracion/materiales/unidades') return route.fulfill({ json: { data: [{ id: 'unit-litros', codigo: 'litros', nombre: 'Litros', alias: ['litro'], activa: true, version: 1 }] } });
                if (path === '/api/administracion/materiales/unidades/historial') return route.fulfill({ json: { data: [] } });
                if (path === '/api/administracion/materiales/categorias') {
                    const data = request.postDataJSON();
                    if (categories.some(category => category.nombre === data.nombre)) return route.fulfill({ status: 422, json: { errors: { nombre: ['El nombre ya está registrado.'] } } });
                    categories.push({ ...data, id: 'cat-adh', en_uso: false });
                    return route.fulfill({ status: 201, json: { data: categories.at(-1) } });
                }
                if (path === '/api/administracion/materiales/tipos-items') {
                    types.push({ ...request.postDataJSON(), id: 'type-adh', codigo_base: null, en_uso: false, categoria_operacional_etiqueta: 'Insumo' });
                    return route.fulfill({ status: 201, json: { data: types.at(-1) } });
                }
                if (path === '/api/administracion/materiales/tipos-items/type-adh') {
                    Object.assign(types.at(-1), request.postDataJSON());
                    return route.fulfill({ json: { data: types.at(-1) } });
                }
                if (path === '/api/administracion/materiales/items') {
                    if (request.method() === 'POST') {
                        const data = request.postDataJSON(); writes.push(data);
                        const type = types.find(type => type.id === data.tipo_item_material_id);
                        items.push({ ...data, id: 'item-adh', cliente: client, tipo_item: type, categoria_operacional: type.categoria_operacional, folios_activos: 0 });
                        type.en_uso = true; categories.at(-1).en_uso = true;
                        return route.fulfill({ status: 201, json: { data: items.at(-1) } });
                    }
                    return route.fulfill({ json: { data: items, clasificaciones: { categorias: categories, tipos_item: types } } });
                }
                const catalogs = { temporadas: [season], clientes: [client], destinos: [], importaciones: [], proveedores: [] };
                const key = path.replace('/api/administracion/materiales/', '');
                if (Object.hasOwn(catalogs, key)) return route.fulfill({ json: { data: catalogs[key] } });
                return route.fulfill({ status: 404 });
            });
            await page.goto('http://localhost/oficina/materiales/catalogos');
            await page.locator('#materials-catalog-tab-classifications').click();
            await page.waitForFunction(() => document.getElementById('materialItemTypesSummary').textContent === '2 registrados');
            assert.equal(await page.locator('input[name="codigo_externo"]').count(), 0);

            await page.locator('#materialCategoryForm [name="nombre"]').fill('Adhesivos');
            await page.locator('#materialCategoryForm button[type="submit"]').click();
            await page.waitForFunction(() => document.getElementById('materialCategoriesSummary').textContent === '2 registradas');
            await page.locator('#materialCategoryForm [name="nombre"]').fill('Adhesivos');
            await page.locator('#materialCategoryForm button[type="submit"]').click();
            await page.waitForFunction(() => document.getElementById('materialCategoryError').textContent.includes('ya está registrado'));

            await page.locator('#materialItemTypeForm [name="nombre"]').fill('Pegamento');
            await page.locator('#materialItemTypeForm [name="categoria_operacional"]').selectOption('insumo');
            await page.locator('#materialItemTypeForm button[type="submit"]').click();
            await page.waitForFunction(() => document.getElementById('materialItemTypesSummary').textContent === '3 registrados');
            await page.locator('#materials-catalog-tab-items').click();
            await page.locator('#itemMaterialEditor > summary').click();
            await page.locator('#itemMaterialForm [name="cliente_material_id"]').selectOption(client.id);
            await page.locator('#itemMaterialForm [name="codigo"]').fill('ADH-01');
            await page.locator('#itemMaterialForm [name="nombre"]').fill('Pegamento transparente');
            await page.locator('#itemMaterialForm [name="categoria"]').selectOption('Adhesivos');
            await page.locator('#itemMaterialForm [name="tipo_item_material_id"]').selectOption('type-adh');
            await page.locator('#itemMaterialForm [name="unidad_medida"]').selectOption('litros');
            assert.match(await page.locator('#materialItemTypeHelp').textContent(), /Insumo/);
            await page.locator('#itemMaterialForm button[type="submit"]').click();
            await page.waitForFunction(() => document.getElementById('itemsSummary').textContent === '1 registrados');
            assert.equal(writes.length, 1);
            assert.equal(writes[0].tipo_item_material_id, 'type-adh');
            assert.equal(writes[0].categoria, 'Adhesivos');
            assert.equal(Object.hasOwn(writes[0], 'codigo_externo'), false);
            assert.equal(Object.hasOwn(writes[0], 'categoria_operacional'), false);
            await page.locator('#itemsMaterialSearch').fill('pegamento');
            await page.locator('#itemsMaterialClassificationFilter').selectOption('type-adh');
            assert.match(await page.locator('#itemsMaterialList').textContent(), /Adhesivos · Pegamento/);

            await page.locator('#materials-catalog-tab-classifications').click();
            const typeRow = page.locator('#materialItemTypesList .material-row').filter({ has: page.locator('[data-edit-material-type="type-adh"]') });
            await typeRow.locator('.office-action-select').selectOption({ label: 'Editar' });
            assert.equal(await page.locator('#materialItemTypeForm [name="categoria_operacional"]').isDisabled(), true);
            await page.locator('#materialItemTypeForm [name="activo"]').uncheck();
            await page.locator('#materialItemTypeForm button[type="submit"]').click();
            await page.waitForFunction(() => document.getElementById('materialItemTypesList').textContent.includes('Inactivo'));
            await page.locator('#materials-catalog-tab-items').click();
            assert.equal(await page.locator('#itemMaterialForm [name="tipo_item_material_id"] option[value="type-adh"]').count(), 0);
            const itemRow = page.locator('#itemsMaterialList .material-row').filter({ has: page.locator('[data-edit-item="item-adh"]') });
            await itemRow.locator('[data-edit-item="item-adh"]').click();
            assert.equal(await page.locator('#itemMaterialForm [name="tipo_item_material_id"]').inputValue(), 'type-adh');
            assert.match(await page.locator('#itemMaterialForm [name="tipo_item_material_id"] option:checked').textContent(), /inactivo/);
            const overflow = await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1);
            assert.equal(overflow, false, `${width}px ${theme}`);
            assert.deepEqual(errors, [], `${width}px ${theme}`);
            await context.close();
        }
    } finally { await browser.close(); }
});
