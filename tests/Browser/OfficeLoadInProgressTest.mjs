import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve, extname } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';

// CAR-000036: programada por 20, el cliente liberó 14 y la operación partió. Oficina completa la
// orden con la carga en curso; una carga cerrada sigue en sólo lectura.
const root = fileURLToPath(new URL('../../', import.meta.url));
const html = readFileSync(resolve(root, 'storage/app/ui/oficina/cargas.html'), 'utf8');

test('completa una carga en despacho parcial con los pallets que faltaban', async () => {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
    try {
        // La pantalla de cargas es un espacio de trabajo de escritorio (mínimo 1050 px).
        for (const width of [1440, 1100]) {
            const context = await browser.newContext({ viewport: { width, height: 1000 } });
            await context.addInitScript(() => {
                localStorage.setItem('estiba_wms_office_token', 'fixture');
                localStorage.setItem('estiba_wms_office_identity', JSON.stringify({ nombre: 'Despacho', rol: 'despachador', puede_consultar_cargas: true, puede_consultar_catalogo_cargas: true, puede_gestionar_cargas: true }));
            });
            const writes = [], errors = [];
            const group = { id: 'instr-1', orden: 1, numero_externo: 'EFC0000011', booking: null, recibidor: null, pallets_programados: 4, cajas_programadas: null, pallets_asignados: 2, pallets_pendientes: 2, progreso: { en_anden: 1, cargados: 0 } };
            const folio = (numero, estado) => ({ id: numero, numero_folio: numero, estado_carga: estado, tipo_bulto: 'pallet', estado_operacional: 'disponible', instructivo_embarque_id: 'instr-1', asignado_at: '2026-11-13T00:58:00Z' });
            const load = { id: 'load', codigo: 'CAR-000036', estado: 'despacho_parcial', prioridad: 'normal', version: 9, total_folios: 2, total_programado: 4, segregaciones: [group], folios_sin_instructivo: [],
                folios: [folio('6000000666', 'en_anden'), folio('6000001076', 'pendiente')], distribucion: [], progreso: { en_anden: 1, cargados: 0 }, embarque: { codigo: 'EIN0000011', numeros_externos: ['EFC0000011'] } };
            const closed = { ...load, id: 'closed', codigo: 'CAR-000030', estado: 'cerrada', segregaciones: [], folios: [folio('6000000001', 'despachado')], total_folios: 1, total_programado: 1, cierre: { patente: 'AB-CD-12', conductor: 'Conductor', cerrada_at: '2026-11-12T20:00:00Z' } };
            await context.route('http://localhost/**', async route => {
                const request = route.request(), path = new URL(request.url()).pathname;
                if (path === '/oficina/frigorifico/despacho/cargas') return route.fulfill({ body: html, contentType: 'text/html' });
                if (path.startsWith('/build/') || path.startsWith('/brand/')) {
                    try { return route.fulfill({ body: readFileSync(resolve(root, `public${path}`)), contentType: ({ '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml', '.woff2': 'font/woff2' })[extname(path)] || 'application/octet-stream' }); }
                    catch { return route.fulfill({ status: 404 }); }
                }
                if (request.method() === 'POST' && path === '/api/cargas/load/folios') {
                    const data = request.postDataJSON(); writes.push(data);
                    for (const numero of data.folios) load.folios.push(folio(numero, 'pendiente'));
                    group.pallets_asignados = load.folios.length; group.pallets_pendientes = 4 - load.folios.length;
                    load.total_folios = load.folios.length; load.version++;
                    return route.fulfill({ json: { data: load } });
                }
                if (path === '/api/cargas') return route.fulfill({ json: { data: [load, closed] } });
                if (path === '/api/cargas/load') return route.fulfill({ json: { data: load } });
                if (path === '/api/cargas/closed') return route.fulfill({ json: { data: closed } });
                if (path === '/api/oficina/contexto') return route.fulfill({ json: { data: { temporada: null, planta: 'Fixture', avisos_cierre: [], avisos_materiales: [] } } });
                return route.fulfill({ json: { data: [] } });
            });
            const page = await context.newPage();
            page.on('pageerror', error => errors.push(error.message));
            await page.goto('http://localhost/oficina/frigorifico/despacho/cargas');
            await page.locator('[data-load-id="load"]').click();
            await page.locator('#folioAddSection:not(.is-hidden)').waitFor();
            assert.equal(await page.locator('#folioAddTitle').textContent(), 'Completar la orden');
            assert.match(await page.locator('#folioAddHelp').textContent(), /vuelve a despacho parcial/);
            assert.match(await page.locator('#loadCommandDescription').textContent(), /Faltan 2 pallets por asignar/);
            assert.match(await page.locator('#folioTableHint').textContent(), /Puedes agregar los pallets faltantes/);
            assert.equal(await page.locator('[data-segregate-folio]').count(), 0, 'la reclasificación de instructivo sigue sólo en borrador o pendiente');
            assert.equal(await page.locator('[data-change-folio]').count(), 2);
            await page.locator('#folioInput').fill('6000001292\n6000001524');
            await page.locator('#addFoliosButton').click();
            await page.waitForFunction(() => document.querySelector('#loadTotalFolios').textContent === '4 / 4');
            assert.deepEqual(writes[0], { folios: ['6000001292', '6000001524'], instructivo_embarque_id: 'instr-1', version_esperada: 9 });
            assert.match(await page.locator('#loadCommandDescription').textContent(), /Puedes agregar, quitar o cambiar pallets hasta el cierre/);

            await page.locator('[data-load-id="closed"]').click();
            await page.waitForFunction(() => document.querySelector('#loadEditorTitle')?.textContent === 'CAR-000030');
            assert.equal(await page.locator('#folioAddSection').isHidden(), true);
            assert.deepEqual(errors, []);
            await context.close();
        }
    } finally { await browser.close(); }
});
