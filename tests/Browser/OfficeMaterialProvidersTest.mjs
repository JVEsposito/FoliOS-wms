import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
const html = readFileSync(resolve(root, 'storage/app/ui/oficina/catalogos.html'), 'utf8');
const season = { id: 'season', codigo: 'TEMP-2627', nombre: 'Temporada', activa: true };
const clients = [
    { id: 'material-aguada', cliente_id: 'aguada', codigo: 'LAG', nombre: 'La Aguada', activo: true, temporada: season },
    { id: 'material-falco', cliente_id: 'falco', codigo: 'FAL', nombre: 'Falco', activo: true, temporada: season },
];

test('proveedores permite buscar, combinar filtros y guardar sin escribir el correlativo', async () => {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
    try {
        for (const [width, theme] of [[1366, 'light-professional'], [390, 'dark-industrial']]) {
            const context = await browser.newContext({ viewport: { width, height: 900 } });
            const page = await context.newPage();
            const errors = [];
            const writes = [];
            const providers = [
                { id: 'provider-1', codigo_interno: 'PRV-001', codigo: 'CSF-01', nombre: 'Cartón San Fernando', activo: true,
                    clientes: [{ id: 'aguada', codigo: 'LAG', nombre: 'La Aguada' }], categorias: [{ cliente_id: 'aguada', categoria: 'Cajas' }] },
                { id: 'provider-2', codigo_interno: 'PRV-002', codigo: 'ADH', nombre: 'Adhesivos del Sur', activo: false,
                    clientes: [{ id: 'falco', codigo: 'FAL', nombre: 'Falco' }], categorias: [] },
                { id: 'provider-3', codigo_interno: 'PRV-003', codigo: 'FILM', nombre: 'Embalajes del Sur', activo: true,
                    clientes: [{ id: 'aguada', codigo: 'LAG', nombre: 'La Aguada' }, { id: 'falco', codigo: 'FAL', nombre: 'Falco' }], categorias: [] },
            ];
            page.on('pageerror', error => errors.push(error.message));
            await context.addInitScript(theme => {
                localStorage.setItem('estiba_wms_office_token', 'provider-test-token');
                localStorage.setItem('estiba_wms_office_theme', theme);
                localStorage.setItem('estiba_wms_office_identity', JSON.stringify({
                    nombre: 'Administrador Materiales', rol: 'administrador',
                    puede_administrar_catalogos_materiales: true, puede_consultar_despachos_materiales: true,
                    modulos_acceso: ['materiales.resumen', 'materiales.catalogos'],
                }));
            }, theme);
            await context.route('http://localhost/**', async route => {
                const request = route.request();
                const path = new URL(request.url()).pathname;
                if (path.startsWith('/build/')) return route.fulfill({ body: readFileSync(resolve(root, `public${path}`)), contentType: path.endsWith('.js') ? 'text/javascript' : 'text/css' });
                if (path === '/oficina/materiales/catalogos') return route.fulfill({ body: html, contentType: 'text/html' });
                if (path === '/api/oficina/contexto') return route.fulfill({ json: { data: { temporada: season, planta: 'Planta', avisos_cierre: [], avisos_materiales: [] } } });
                if (path === '/api/materiales/reposicion/resumen') return route.fulfill({ json: { data: { quiebre: 0, bajo_minimo: 0 } } });
                if (path === '/api/administracion/materiales/proveedores') {
                    if (request.method() === 'POST') {
                        const data = request.postDataJSON(); writes.push(data);
                        providers.push({ ...data, id: 'provider-4', codigo: 'PRV-004', codigo_interno: 'PRV-004', clientes: [{ id: 'aguada', codigo: 'LAG', nombre: 'La Aguada' }] });
                        return route.fulfill({ status: 201, json: { data: providers.at(-1) } });
                    }
                    return route.fulfill({ json: { data: providers } });
                }
                const catalogs = {
                    temporadas: [season], clientes: clients, destinos: [], importaciones: [],
                    items: [{ id: 'item', codigo: 'CAJA', nombre: 'Caja', activo: true, categoria: 'Cajas', categoria_operacional: 'material_mp', unidad_medida: 'unidades', cliente: clients[0] }],
                };
                const key = path.replace('/api/administracion/materiales/', '');
                if (Object.hasOwn(catalogs, key)) return route.fulfill({ json: { data: catalogs[key] } });
                return route.fulfill({ status: 404 });
            });
            await page.goto('http://localhost/oficina/materiales/catalogos');
            await page.locator('#materials-catalog-tab-providers').click();
            await page.waitForFunction(() => document.getElementById('providersSummary').textContent === '3 registrados');
            assert.equal(await page.locator('#providerInternalCode').getAttribute('readonly'), '');
            assert.equal(await page.locator('#providerMaterialForm [name="codigo"]').getAttribute('required'), null);

            await page.locator('#providerMaterialForm [name="nombre"]').fill('Proveedor nuevo');
            await page.locator('#providersMaterialSearch').fill('carton aguada');
            await page.waitForFunction(() => document.getElementById('providersSummary').textContent === '1 de 3 registrados');
            assert.match(await page.locator('#providersMaterialList').textContent(), /PRV-001.*Cartón San Fernando/s);
            await page.locator('#providersMaterialSearch').fill('csf01');
            assert.equal(await page.locator('#providersMaterialList [data-edit-provider]').count(), 1);
            assert.equal(await page.locator('#providerMaterialForm [name="nombre"]').inputValue(), 'Proveedor nuevo');

            await page.locator('#providersMaterialSearch').fill('');
            await page.locator('#providersMaterialClientFilter').selectOption('falco');
            await page.locator('#providersMaterialStatusFilter').selectOption('activo');
            assert.equal(await page.locator('#providersMaterialList [data-edit-provider]').count(), 1);
            assert.match(await page.locator('#providersMaterialList').textContent(), /PRV-003/);
            await page.locator('#providersMaterialStatusFilter').selectOption('inactivo');
            assert.match(await page.locator('#providersMaterialList').textContent(), /PRV-002/);
            await page.locator('#clearProviderFilters').click();
            assert.equal(await page.locator('#providersMaterialList [data-edit-provider]').count(), 3);

            await page.locator('#providerClientOptions input[value="aguada"]').check();
            await page.locator('#providerCategoryOptions input[data-category="Cajas"]').check();
            await page.locator('#providerMaterialForm button[type="submit"]').click();
            await page.waitForFunction(() => document.getElementById('providersSummary').textContent === '4 registrados');
            assert.equal(writes.length, 1);
            assert.equal(writes[0].codigo, '');
            assert.equal(Object.hasOwn(writes[0], 'codigo_interno'), false);
            assert.deepEqual(writes[0].cliente_ids, ['aguada']);
            assert.deepEqual(writes[0].categorias, [{ cliente_id: 'aguada', categoria: 'Cajas' }]);
            assert.match(await page.locator('#officeToasts').textContent(), /PRV-004/);

            const firstProvider = page.locator('#providersMaterialList .material-row')
                .filter({ has: page.locator('[data-edit-provider="provider-1"]') });
            await firstProvider.locator('.office-action-select').selectOption({ label: 'Editar' });
            assert.equal(await page.locator('#providerInternalCode').inputValue(), 'PRV-001');
            assert.equal(await page.locator('#providerMaterialForm [name="codigo"]').inputValue(), 'CSF-01');
            assert.deepEqual(errors, [], `${width}px ${theme}`);
            await context.close();
        }
    } finally {
        await browser.close();
    }
});
