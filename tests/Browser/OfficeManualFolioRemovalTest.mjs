import assert from 'node:assert/strict';
import { mkdirSync, readFileSync } from 'node:fs';
import { extname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
const html = readFileSync(resolve(root, 'storage/app/ui/oficina/consultas-busqueda.html'), 'utf8');

test('administración elimina desde el expediente un folio creado en cámara sin validación', async () => {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
    try {
        for (const width of [390, 1280]) {
            const context = await browser.newContext({ viewport: { width, height: 1000 } });
            const identity = { id: 1, nombre: 'Administración', rol: 'administrador', puede_consultar_oficina_consultas: true };
            await context.addInitScript((user) => {
                localStorage.setItem('estiba_wms_office_token', 'fixture');
                localStorage.setItem('estiba_wms_office_identity', JSON.stringify(user));
            }, identity);
            const removals = []; const errors = [];
            let removed = false;
            const folio = () => ({
                id: 'folio-manual', numero: removed ? '600000102-ELIM-1' : '600000102', tipo_bulto: 'pallet', estado: removed ? 'anulado' : 'disponible',
                estado_explicado: removed ? 'anulado' : 'disponible', condicion_termica: null, cantidad_cajas: null, temporada: 'TEMP-2627',
                fecha_ingreso: '2026-11-03T07:10:00Z', especificaciones: {}, ubicacion: removed ? null : { camara: 'CAM-09', posicion: 'B25-P01-N1' },
                repaletizaje_agotamiento: null, origen_sistema: 'manual',
            });
            await context.route('http://localhost/**', async (route) => {
                const request = route.request(); const path = new URL(request.url()).pathname;
                if (path === '/oficina/consultas/busqueda') return route.fulfill({ body: html, contentType: 'text/html' });
                if (path.startsWith('/build/') || path.startsWith('/brand/')) {
                    try { return route.fulfill({ body: readFileSync(resolve(root, `public${path}`)), contentType: ({ '.js': 'text/javascript', '.css': 'text/css', '.woff2': 'font/woff2', '.svg': 'image/svg+xml' })[extname(path)] || 'application/octet-stream' }); }
                    catch { return route.fulfill({ status: 404, body: '' }); }
                }
                if (path === '/api/acceso-oficina') return route.fulfill({ json: { usuario: identity } });
                if (path === '/api/consultas/resumen') return route.fulfill({ json: { productores: { total: 0, pendientes_cliente: 0, asociados: 0 }, consultas_sag_hoy: 0 } });
                if (path === '/api/consultas/catalogos') return route.fulfill({ json: { clientes: [] } });
                if (path === '/api/consultas/buscar') return route.fulfill({ json: { termino: '600000102', folios: [{ ...folio(), exportadora: null }], lotes: [], productores: [], recepciones: [] } });
                if (path === '/api/consultas/folios/folio-manual') {
                    return route.fulfill({ json: {
                        folio: folio(), validacion: null, repaletizajes: [], totales: { validaciones: 0, procesos_prefrio: 0, movimientos: 1, repaletizajes: 0 },
                        timeline: [{ tipo: 'movimiento', fecha: '2026-11-03T07:10:00Z', titulo: 'Movimiento', descripcion: 'Sin ubicación → CAM-09 · B25-P01-N1', meta: { Tipo: 'ubicacion_inicial', Operador: 'Camarero noche', Tablet: 'Tablet CAM-09' } }],
                        eliminacion: removed ? { numero_folio: '600000102', motivo: 'Pallet ubicado sin validar en el turno noche.', eliminado_at: '2026-11-03T10:40:00Z', eliminado_por: 'Administración' } : null,
                        acciones: { eliminar_folio_manual: removed ? null : { disponible: true, motivo_no_disponible: null } },
                    } });
                }
                if (path === '/api/folios/folio-manual/eliminar-manual' && request.method() === 'POST') {
                    removals.push(request.postDataJSON()); removed = true;
                    return route.fulfill({ json: { data: { id: 'eliminacion', folio_id: 'folio-manual', numero_folio: '600000102', numero_folio_archivado: '600000102-ELIM-1', ubicacion_liberada: { camara: 'CAM-09', posicion: 'B25-P01-N1' } } } });
                }
                return route.fulfill({ json: {} });
            });
            const page = await context.newPage();
            page.on('pageerror', (error) => errors.push(error.message));
            page.on('dialog', (dialog) => dialog.accept());
            await page.goto('http://localhost/oficina/consultas/busqueda');
            await page.locator('#officeLoading').waitFor({ state: 'hidden' });
            await page.locator('#globalSearchForm [name="q"]').fill('600000102');
            await page.locator('#globalSearchForm').evaluate((form) => form.requestSubmit());
            // La tarjeta agrupa sus botones en el selector de acciones de FoliOS v2.
            await page.locator('.result-card--folio select.office-action-select').selectOption({ label: 'Ver trazabilidad →' });
            await page.locator('#folioDialog[open]').waitFor();
            assert.match(await page.locator('.folio-dossier-hero').textContent(), /Creado a mano en cámara, sin validación/);
            assert.match(await page.locator('.trace-timeline').textContent(), /Camarero noche/);

            await page.locator('[data-remove-manual-folio]').click();
            assert.match(await page.locator('[data-folio-removal-error]').textContent(), /al menos 10 caracteres/);
            assert.equal(removals.length, 0);
            await page.locator('[data-folio-removal-reason]').fill('Pallet ubicado sin validar en el turno noche.');
            assert.equal(await page.locator('[data-folio-removal-error]').textContent(), '');
            if (process.env.REMOVAL_SCREENSHOT_DIR) {
                mkdirSync(process.env.REMOVAL_SCREENSHOT_DIR, { recursive: true });
                await page.locator('.folio-removal').scrollIntoViewIfNeeded();
                await page.screenshot({ path: resolve(process.env.REMOVAL_SCREENSHOT_DIR, `eliminar-${width}.png`) });
            }
            await page.locator('[data-remove-manual-folio]').click();
            await page.locator('.trace-alert--removed').waitFor();
            assert.equal(removals.length, 1);
            assert.equal(removals[0].motivo, 'Pallet ubicado sin validar en el turno noche.');
            assert.match(removals[0].operacion_id, /^[0-9a-f-]{36}$/);
            assert.match(await page.locator('.trace-alert--removed').textContent(), /ELIMINADO POR ADMINISTRACIÓN.*600000102/s);
            assert.equal(await page.locator('.folio-removal').count(), 0);
            assert.equal(await page.locator('.trace-alert--removed').isVisible(), true);
            if (process.env.REMOVAL_SCREENSHOT_DIR) await page.screenshot({ path: resolve(process.env.REMOVAL_SCREENSHOT_DIR, `eliminado-${width}.png`) });
            assert.equal(await page.locator('#folioDialog').evaluate((node) => node.scrollWidth <= node.clientWidth + 1), true);
            assert.deepEqual(errors, []);
            await context.close();
        }
    } finally { await browser.close(); }
});
