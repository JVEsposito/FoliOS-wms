import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve, extname } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
const html = readFileSync(resolve(root, 'storage/app/ui/oficina/ordenes-proceso.html'), 'utf8');

function order() {
    return {
        id: 'orden', codigo: 'OP-000001', fecha: '2026-11-02', turno_inicio: 'A', turno_pre_sif: 'T1',
        linea: { id: 'linea', codigo: 'L1', nombre: 'Línea 1' }, estado: 'en_proceso', tipo_cierre: null,
        numero_version_vigente: 1, version: 1, clientes: ['Cliente prueba'],
        progreso: { lotes: 1, bins_programados: 6, bins_entregados: 4, bins_pendientes: 2 },
        inicio_estimado: '2026-11-02 08:00:00', termino_estimado: '2026-11-02 09:00:00',
        version_vigente: { id: 'v1', hora_inicio: '08:00', rendimiento_kg_hora: 6000, lotes: [{
            id: 'fila', lote_materia_prima_id: 'lote', numero_lote: 'L-001', secuencia: 1,
            cliente: { nombre: 'Cliente prueba' }, variedad: 'Santina', csg: '123',
            bins_programados: 6, bins_entregados: 4, bins_pendientes: 2, kilos_programados: 2250,
            embalaje: 'CAJA-5', kilos_por_caja: 5, mercado_destino: 'China',
        }] },
        versiones: [{ id: 'v1', numero: 1, origen: 'digitador', creada_por: 'Digitadora', creada_at: '2026-11-02T11:00:00Z' }],
        entregas: [{ id: 'entrega', numero_lote: 'L-001', cantidad_envases: 4, entregado_at: '2026-11-02T12:00:00Z',
            entregado_por: 'Camarero', anulado: false, version_embalaje: 1, embalaje: 'CAJA-5', kilos_por_caja: 5, correcciones_embalaje: [] }],
    };
}

test('embalaje pendiente, corrección de viaje y reducción de bins tienen alcances distintos', async () => {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
    try {
        for (const width of [390, 1280]) for (const readOnly of [false, true]) {
            const context = await browser.newContext({ viewport: { width, height: 1000 } });
            const identity = { id: 1, nombre: 'Digitadora', rol: 'digitador_materia_prima', puede_consultar_ordenes_proceso: true,
                puede_gestionar_ordenes_proceso: !readOnly, puede_cancelar_ordenes_proceso: false, modulos_acceso: ['materia-prima.ordenes-proceso'] };
            await context.addInitScript((user) => {
                localStorage.setItem('estiba_wms_office_token', 'fixture');
                localStorage.setItem('estiba_wms_office_identity', JSON.stringify(user));
            }, identity);
            const current = order(); const versions = []; const corrections = []; const errors = [];
            await context.route('http://localhost/**', async (route) => {
                const req = route.request(); const path = new URL(req.url()).pathname;
                if (path === '/oficina/materia-prima/ordenes-proceso') return route.fulfill({ body: html, contentType: 'text/html' });
                if (path.startsWith('/build/') || path.startsWith('/brand/')) {
                    try { return route.fulfill({ body: readFileSync(resolve(root, `public${path}`)), contentType: ({ '.js': 'text/javascript', '.css': 'text/css', '.woff2': 'font/woff2', '.svg': 'image/svg+xml' })[extname(path)] || 'application/octet-stream' }); }
                    catch { return route.fulfill({ status: 404, body: '' }); }
                }
                if (path === '/api/acceso-oficina') return route.fulfill({ json: { usuario: identity } });
                if (path === '/api/oficina/contexto') return route.fulfill({ json: { data: { planta: 'Prueba', temporada: { codigo: 'TEMP-PRUEBA' } } } });
                if (path.endsWith('/ordenes-proceso/catalogos')) return route.fulfill({ json: {
                    lineas: [{ id: 'linea', codigo: 'L1', nombre: 'Línea 1', activa: true, rendimiento_kg_hora: 6000 }],
                    turnos: [{ codigo: 'A', etiqueta: 'Turno A', codigo_pre_sif: 'T1', codigo_instructivo: 'AM' }],
                } });
                if (path.endsWith('/lotes-programables')) return route.fulfill({ json: { data: [] } });
                if (path.endsWith('/ordenes-proceso/orden/versiones')) {
                    const payload = req.postDataJSON(); versions.push(payload);
                    current.version++; current.numero_version_vigente++;
                    const row = current.version_vigente.lotes[0];
                    Object.assign(row, payload.lotes[0], { bins_pendientes: payload.lotes[0].bins_programados - 4 });
                    Object.assign(current.progreso, { bins_programados: row.bins_programados, bins_pendientes: row.bins_pendientes });
                    if (!row.bins_pendientes) current.estado = 'terminada';
                    return route.fulfill({ json: { data: current } });
                }
                if (path.endsWith('/entregas/entrega/embalaje')) {
                    const payload = req.postDataJSON(); corrections.push(payload);
                    if (corrections.length === 1) return route.fulfill({ status: 503, json: { message: 'Reintenta la misma corrección.' } });
                    Object.assign(current.entregas[0], { embalaje: payload.embalaje, kilos_por_caja: payload.kilos_por_caja, version_embalaje: 2,
                        correcciones_embalaje: [{ anterior: { embalaje: 'CAJA-5', kilos_por_caja: 5 }, nuevo: { embalaje: payload.embalaje, kilos_por_caja: payload.kilos_por_caja }, motivo: payload.motivo, usuario: 'Digitadora', created_at: '2026-11-02T12:10:00Z' }] });
                    return route.fulfill({ json: { data: current.entregas[0] } });
                }
                if (path.endsWith('/ordenes-proceso/orden')) return route.fulfill({ json: { data: current } });
                if (path.endsWith('/ordenes-proceso')) return route.fulfill({ json: { data: [current] } });
                assert.notEqual(req.method(), 'POST', `Escritura inesperada: ${path}`);
                return route.fulfill({ json: {} });
            });
            const page = await context.newPage(); page.on('pageerror', (error) => errors.push(error.message));
            await page.goto('http://localhost/oficina/materia-prima/ordenes-proceso');
            await page.waitForFunction(() => document.querySelector('[data-order]') || document.getElementById('ordersNotice').textContent);
            assert.equal(await page.locator('#ordersNotice').textContent(), '', errors.join('\n'));
            await page.locator('[data-order="orden"]').click();
            if (readOnly) {
                assert.equal(await page.locator('[data-correct-packaging]').count(), 0);
                assert.equal(await page.locator('[data-action="version"]').count(), 0);
                await context.close(); continue;
            }
            await page.locator('[data-action="version"]').click();
            await page.locator('#orderRows [data-field="embalaje"]').fill('CAJA-2');
            await page.locator('#orderRows [data-field="kilos_por_caja"]').fill('2');
            assert.match(await page.locator('#orderChangeSummary').textContent(), /2 bins pendientes.*4 entregados/);
            assert.equal(await page.locator('#orderRows [data-field="bins_programados"]').inputValue(), '6');
            await page.locator('#orderEditorForm [name="motivo_cambio"]').fill('Nuevo embalaje para lo pendiente');
            await page.locator('#orderPublish').click();
            await page.waitForFunction(() => document.getElementById('orderEditor').hidden);
            assert.equal(versions[0].lotes[0].bins_programados, 6);
            assert.equal(versions[0].confirmar_reduccion, false);
            assert.equal(current.entregas[0].embalaje, 'CAJA-5');

            await page.locator('[data-correct-packaging="entrega"]').click();
            await page.locator('#deliveryPackagingForm [name="embalaje"]').fill('CAJA-7');
            await page.locator('#deliveryPackagingForm [name="kilos_por_caja"]').fill('7');
            await page.locator('#deliveryPackagingForm [name="motivo"]').fill('Corrección del viaje ya entregado');
            await page.locator('#deliveryPackagingForm [type="submit"]').click();
            await page.waitForFunction(() => document.getElementById('deliveryPackagingError').textContent.includes('Reintenta'));
            await page.locator('#deliveryPackagingForm [type="submit"]').click();
            await page.waitForFunction(() => !document.getElementById('deliveryPackagingDialog').open);
            assert.deepEqual(corrections[0], corrections[1]);
            assert.equal(corrections[1].cantidad_envases, undefined);
            assert.deepEqual(current.progreso, { lotes: 1, bins_programados: 6, bins_entregados: 4, bins_pendientes: 2 });
            await page.waitForFunction(() => document.getElementById('orderDetail').textContent.includes('CAJA-7'));

            await page.locator('[data-action="version"]').click();
            await page.locator('#orderRows [data-field="bins_programados"]').fill('4');
            await page.locator('#orderEditorForm [name="motivo_cambio"]').fill('Packing cancela los dos pendientes');
            await page.locator('#orderPublish').click();
            await page.waitForFunction(() => document.getElementById('orderEditorError').textContent.includes('liberar 2 bins'));
            assert.equal(versions.length, 1, 'no publica una reducción sin confirmación');
            await page.locator('#orderConfirmReduction').check();
            await page.locator('#orderPublish').click();
            await page.waitForFunction(() => document.getElementById('orderEditor').hidden);
            assert.equal(versions[1].confirmar_reduccion, true);
            assert.equal(current.entregas[0].cantidad_envases, 4);
            assert.deepEqual(errors, []);
            await context.close();
        }
    } finally { await browser.close(); }
});
