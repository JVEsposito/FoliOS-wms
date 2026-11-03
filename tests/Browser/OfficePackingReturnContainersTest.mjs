import assert from 'node:assert/strict';
import { readFileSync, mkdirSync } from 'node:fs';
import { resolve, extname } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
const html = readFileSync(resolve(root, 'storage/app/ui/oficina/retornos-packing.html'), 'utf8');
const widths = process.env.FOLIOS_TEST_VIEWPORT ? [Number(process.env.FOLIOS_TEST_VIEWPORT)] : [390, 1280];

test('Oficina registra cajas, corrige cantidades con motivo y conserva el envase al regularizar', async () => {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
    try {
        for (const width of widths) {
            const context = await browser.newContext({ viewport: { width, height: 1000 } });
            const identity = { id: 1, nombre: 'Digitadora', rol: 'administrador', puede_consultar_fruta_proceso: true,
                puede_entregar_fruta_proceso: true, puede_corregir_entregas_fruta_proceso: true, modulos_acceso: ['materia-prima.fruta-proceso'] };
            await context.addInitScript((user) => {
                localStorage.setItem('estiba_wms_office_token', 'fixture');
                localStorage.setItem('estiba_wms_office_identity', JSON.stringify(user));
            }, identity);
            const records = []; const writes = []; const errors = [];
            await context.route('http://localhost/**', async (route) => {
                const req = route.request(); const path = new URL(req.url()).pathname;
                const prefix = '/api/materia-prima/fruta-proceso/retornos-bin';
                if (path === '/oficina/materia-prima/retornos-packing') return route.fulfill({ body: html, contentType: 'text/html' });
                if (path.startsWith('/build/') || path.startsWith('/brand/')) {
                    try { return route.fulfill({ body: readFileSync(resolve(root, `public${path}`)), contentType: ({ '.js': 'text/javascript', '.css': 'text/css', '.woff2': 'font/woff2', '.svg': 'image/svg+xml' })[extname(path)] || 'application/octet-stream' }); }
                    catch { return route.fulfill({ status: 404, body: '' }); }
                }
                if (path === '/api/acceso-oficina') return route.fulfill({ json: { usuario: identity } });
                if (path === '/api/oficina/contexto') return route.fulfill({ json: { data: { planta: 'Prueba', temporada: { codigo: 'TEMP-PRUEBA' } } } });
                if (path === `${prefix}/catalogos`) return route.fulfill({ json: { envases_retorno: [{ codigo: 'bins', nombre: 'BINS' }, { codigo: 'caja_3_4', nombre: 'CAJAS 3/4' }], tipos_resultado: [{ id: 'tipo', codigo: 'comercial', nombre: 'Comercial' }] } });
                if (path === `${prefix}/procesos`) return route.fulfill({ json: { data: [{ clave: 'proc', lote_materia_prima_id: 'lote', numero_lote: 'L-001', numero_orden: 'OP-001', linea_proceso: 'Línea 1', turno: 'A', viajes: 1, bins_enviados: 4, kilos_enviados: 1000 }] } });
                if (path === `${prefix}/legacy`) return route.fulfill({ json: { data: [] } });
                if (path === `${prefix}/resumen`) return route.fulfill({ json: { bins_registrados: 0, cajas_3_4_registradas: records[0]?.cantidad_envases || 0, kilos_registrados: records[0]?.kilos_totales || 0, pendientes_regularizacion: records.filter((r) => r.estado === 'pendiente_regularizacion').length, retornos_anteriores_pendientes: 0 } });
                if (path === `${prefix}/bins` && req.method() === 'GET') return route.fulfill({ json: { data: records } });
                if (path === `${prefix}/bins` && req.method() === 'POST') {
                    const payload = req.postDataJSON(); writes.push({ action: 'registrar', payload });
                    assert.equal(payload.tipo_envase, 'caja_3_4'); assert.equal(payload.cantidad_envases, 24);
                    records.push({ ...payload, id: 'retorno', folio_provisional: 'PR-000001', folio_definitivo: null, estado: 'pendiente_regularizacion',
                        kilos_totales_verdes: payload.kilos_totales, kilos_totales_definitivos: null, registrado_at: '2026-11-02T12:00:00Z', registrado_por: 'Digitadora',
                        regularizado_at: null, anulado_at: null, tipo_resultado: null,
                        origenes: payload.origenes.map((o) => ({ ...o, id: 'origen', clave: 'proc', numero_lote: 'L-001', kilos_aportados_definitivos: null })) });
                    return route.fulfill({ status: 201, json: { data: records[0] } });
                }
                if (path === `${prefix}/bins/retorno` && req.method() === 'PUT') {
                    const payload = req.postDataJSON(); writes.push({ action: 'modificar', payload });
                    assert.equal(payload.tipo_envase, 'caja_3_4'); assert.equal(payload.cantidad_envases, 12); assert.ok(payload.motivo.length >= 5);
                    Object.assign(records[0], { cantidad_envases: 12, modificado_at: '2026-11-02T12:05:00Z', modificado_por: 'Digitadora', motivo_ultima_modificacion: payload.motivo });
                    return route.fulfill({ json: { data: records[0] } });
                }
                if (path === `${prefix}/bins/retorno/regularizar`) {
                    const payload = req.postDataJSON(); writes.push({ action: 'regularizar', payload });
                    Object.assign(records[0], { estado: 'regularizado', folio_definitivo: payload.folio_definitivo, kilos_totales_definitivos: payload.kilos_totales_definitivos,
                        regularizado_at: '2026-11-02T12:10:00Z', tipo_resultado: { id: 'tipo', nombre: 'Comercial' }, nombre_resultado: 'Comercial' });
                    records[0].origenes[0].kilos_aportados_definitivos = payload.kilos_totales_definitivos;
                    return route.fulfill({ json: { data: records[0] } });
                }
                assert.ok(!['POST', 'PUT', 'DELETE'].includes(req.method()), `Escritura inesperada: ${path}`);
                return route.fulfill({ json: {} });
            });
            const page = await context.newPage(); page.on('pageerror', (e) => errors.push(e.message));
            await page.goto('http://localhost/oficina/materia-prima/retornos-packing');
            await page.waitForFunction(() => document.querySelector('#processSelect option[value="proc"]'), null, { timeout: 10000 })
                .catch(async (error) => {
                    throw new Error([error.message, ...errors, page.url(), await page.evaluate(() => document.getElementById('officeToasts')?.textContent)].filter(Boolean).join(' | '));
                });
            assert.equal(await page.locator('#returnContainerQuantity').inputValue(), '1');
            assert.equal(await page.locator('#returnContainerQuantity').evaluate((n) => n.readOnly), true);
            await page.locator('#returnContainerType').selectOption('caja_3_4');
            assert.equal(await page.locator('#returnContainerQuantity').evaluate((n) => n.readOnly), false);
            await page.locator('#returnContainerQuantity').fill('0');
            await page.locator('#binReturnForm button[type="submit"]').click();
            assert.match(await page.locator('#binReturnError').textContent(), /cantidad entera de cajas/); assert.equal(writes.length, 0);
            await page.locator('#returnContainerQuantity').fill('24');
            await page.locator('#binTotalKilos').fill('240');
            await page.locator('#processSelect').selectOption('proc'); await page.locator('#addOriginButton').click();
            await page.locator('[data-origin-kilos]').fill('240');
            if (process.env.FOLIOS_TEST_SCREENSHOT_DIR) {
                mkdirSync(process.env.FOLIOS_TEST_SCREENSHOT_DIR, { recursive: true });
                await page.locator('#binReturnForm').screenshot({ path: resolve(process.env.FOLIOS_TEST_SCREENSHOT_DIR, `retorno-cajas-${width}.png`) });
            }
            await page.locator('#binReturnForm button[type="submit"]').click();
            await page.waitForFunction(() => document.querySelector('[data-edit-bin="retorno"]'));
            assert.match(await page.locator('#recentBins').textContent(), /24 CAJAS 3\/4/);
            assert.equal(await page.locator('#registeredBoxes').textContent(), '24');
            await page.locator('#recentBins .office-action-select').selectOption({ label: 'Modificar' });
            assert.equal(await page.locator('#editReturnContainerType').inputValue(), 'caja_3_4');
            await page.locator('#editReturnContainerQuantity').fill('12');
            await page.locator('#editBinForm [name="motivo"]').fill('Se contaron doce cajas al revisar el retorno.');
            await page.locator('#editBinForm button[value="default"]').click();
            await page.waitForFunction(() => !document.getElementById('editBinDialog').open);
            await page.locator('#recentBins').getByText('12 CAJAS 3/4', { exact: true }).waitFor();
            await page.locator('[data-return-section="pendientes"]').click();
            await page.locator('#pendingBinList .office-action-select').selectOption({ label: 'Regularizar folio y kilos' });
            await page.locator('#regularizeForm [name="folio_definitivo"]').fill('FAG-000012');
            await page.locator('#regularizeForm [name="tipo_resultado_packing_id"]').selectOption('tipo');
            await page.locator('#regularizeForm [name="kilos_totales_definitivos"]').fill('235');
            await page.locator('[data-regularize-kilos]').fill('235');
            await page.locator('#regularizeForm button[value="default"]').click();
            await page.waitForFunction(() => !document.getElementById('regularizeDialog').open);
            assert.equal(records[0].tipo_envase, 'caja_3_4'); assert.equal(records[0].cantidad_envases, 12);
            assert.deepEqual(writes.map((r) => r.action), ['registrar', 'modificar', 'regularizar']);
            assert.deepEqual(errors, []);
            await context.close();
        }
    } finally { await browser.close(); }
});
