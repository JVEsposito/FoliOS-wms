import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
const html = readFileSync(resolve(root, 'storage/app/ui/oficina/catalogos.html'), 'utf8');

test('catálogo de unidades y equivalencias por ítem funcionan en escritorio y celular', async () => {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
    try {
        for (const width of [1366, 390]) {
            const context = await browser.newContext({ viewport: { width, height: 900 } });
            await context.addInitScript(() => {
                localStorage.setItem('estiba_wms_office_token', 'units-fixture');
                localStorage.setItem('estiba_wms_office_identity', JSON.stringify({ nombre: 'Administrador fixture', rol: 'administrador', puede_administrar_catalogos_materiales: true, puede_consultar_despachos_materiales: true }));
            });
            const units = [{ id: 'unidades', codigo: 'unidades', nombre: 'Unidades', alias: ['unidad', 'un'], version: 1, activa: true }, { id: 'cajas', codigo: 'cajas', nombre: 'Cajas', alias: ['caja'], version: 1, activa: true }];
            const season = { id: 'season', codigo: 'TEMP', nombre: 'Temporada', activa: true };
            const client = { id: 'client', codigo: 'CLI', nombre: 'Cliente', activo: true, temporada: season };
            const item = { id: 'item', codigo: 'CAJA', nombre: 'Caja plana', unidad_medida: 'unidades', categoria_operacional: 'material_mp', activo: true, cliente: client };
            const writes = []; const errors = []; let conversion = null;
            await context.route('http://localhost/**', async (route) => {
                const request = route.request(); const path = new URL(request.url()).pathname;
                if (path === '/oficina/materiales/catalogos') return route.fulfill({ body: html, contentType: 'text/html' });
                if (path.startsWith('/build/')) return route.fulfill({ body: readFileSync(resolve(root, `public${path}`)), contentType: path.endsWith('.css') ? 'text/css' : 'application/octet-stream' });
                if (path === '/api/consultas/resumen') return route.fulfill({ json: { pendientes: { prefrio: [], despacho: [], cargas: [], excepciones: [], inspeccion_sag: [], reordenamiento_rolling: [], reposicion_materiales: [], fuera_de_encuadre: [], avisos_materiales: [] } } });
                if (path === '/api/materiales/reposicion/resumen') return route.fulfill({ json: { data: { quiebre: 0, bajo_minimo: 0 } } });
                if (path.endsWith('/unidades/historial')) return route.fulfill({ json: { data: [] } });
                if (path === '/api/administracion/materiales/unidades') {
                    if (request.method() === 'POST') { const data = request.postDataJSON(); writes.push(data); units.push({ ...data, id: 'resmas', version: 1 }); return route.fulfill({ status: 201, json: { data: units.at(-1) } }); }
                    return route.fulfill({ json: { data: units } });
                }
                if (path === '/api/administracion/materiales/items/item/unidades') {
                    if (request.method() === 'PUT') { const data = request.postDataJSON(); writes.push(data); conversion = { ...data, version: 1, origen: units[1] }; return route.fulfill({ json: { data: conversion } }); }
                    return route.fulfill({ json: { data: conversion ? [conversion] : [], unidad_base: 'unidades' } });
                }
                const catalogs = { temporadas: [season], clientes: [client], items: [item], destinos: [], proveedores: [], importaciones: [] };
                const key = path.replace('/api/administracion/materiales/', '');
                return Object.hasOwn(catalogs, key) ? route.fulfill({ json: { data: catalogs[key] } }) : route.fulfill({ status: 404 });
            });
            const page = await context.newPage(); page.on('pageerror', (e) => errors.push(e.message));
            await page.goto('http://localhost/oficina/materiales/catalogos');
            await page.waitForFunction(() => document.getElementById('itemsSummary').textContent.includes('1'));
            await page.locator('#materials-catalog-tab-units').click();
            await page.locator('[data-edit-unit]').first().waitFor();
            await page.locator('#materialUnitForm [name="codigo"]').fill('resmas');
            await page.locator('#materialUnitForm [name="nombre"]').fill('Resmas');
            await page.locator('#materialUnitForm [name="alias"]').fill('resma');
            await page.locator('#materialUnitForm [name="motivo"]').fill('Nueva presentación de papel');
            await page.locator('#materialUnitForm [type="submit"]').click();
            await page.waitForFunction(() => [...document.querySelectorAll('#itemMaterialForm [name="unidad_medida"] option')].some((o) => o.value === 'resmas'));
            assert.deepEqual(writes[0].alias, ['resma']);
            await page.locator('#materialConversionItem').selectOption('item');
            await page.waitForFunction(() => document.getElementById('materialConversionFactorLabel').textContent.includes('unidades'));
            await page.locator('#materialConversionUnit').selectOption('cajas');
            await page.locator('#materialUnitConversionForm [name="factor"]').fill('100');
            await page.locator('#materialUnitConversionForm [name="motivo"]').fill('Contenido por caja verificado');
            await page.locator('#materialUnitConversionForm [type="submit"]').click();
            await page.waitForFunction(() => document.getElementById('materialConversionsList').textContent.includes('100'));
            assert.equal(writes[1].unidad_origen, 'cajas'); assert.equal(writes[1].factor, '100'); assert.equal(writes[1].version_conocida, 0);
            assert.deepEqual(errors, [], `${width}px`);
            await context.close();
        }
    } finally { await browser.close(); }
});
