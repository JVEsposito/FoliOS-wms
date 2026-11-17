import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { extname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
const html = readFileSync(resolve(root, 'storage/app/ui/oficina/materia-prima-lotes.html'), 'utf8');
const season = { id: 'temporada', codigo: 'TEMP-2627', nombre: 'Temporada 2026' };
const camera = { id: 'camara-mp', codigo: 'MP-01', nombre: 'Cámara MP 1' };

test('lotes: la asignación digitada después envía la hora real de ingreso a cámara, opcional', async () => {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
    try {
        for (const [width, realTime] of [[1280, '2026-11-17T00:40'], [390, null]]) {
            const context = await browser.newContext({ viewport: { width, height: 1000 }, timezoneId: 'America/Santiago' });
            const identity = { id: 1, nombre: 'Digitadora', rol: 'digitador_materia_prima', puede_consultar_materia_prima: true,
                puede_gestionar_lotes_materia_prima: true, modulos_acceso: ['materia-prima.digitacion'] };
            await context.addInitScript((user) => {
                localStorage.setItem('estiba_wms_office_token', 'fixture');
                localStorage.setItem('estiba_wms_office_identity', JSON.stringify(user));
            }, identity);
            const writes = []; const errors = [];
            const lot = { id: 'lote-1', numero_lote: 'LOTE-NOCHE', estado: 'pendiente_asignacion', version: 3,
                recepcion: { numero_recepcion: 'REC-2611-0042', numero_guia_despacho: '1511', ingreso_at: '2026-11-17T02:30:00+00:00' },
                cliente: { nombre: 'Cliente' }, trazabilidad: { csg: '123456', variedad: 'Santina', especie: 'Cereza', tipo_producto: 'materia_prima', fecha_cosecha: '2026-11-16' },
                envases: { detalle: [{ tipo_envase: 'bins', cantidad: 48 }] }, pesos: { kilos_netos_confirmados: 8561 },
                puede_editar: false, puede_confirmar: false, requiere_hidrocooler: false };
            await context.route('http://localhost/**', async (route) => {
                const request = route.request(); const url = new URL(request.url()); const path = url.pathname;
                if (path === '/oficina/materia-prima/lotes') return route.fulfill({ body: html, contentType: 'text/html' });
                if (path.startsWith('/build/') || path.startsWith('/brand/')) {
                    try { return route.fulfill({ body: readFileSync(resolve(root, `public${path}`)), contentType: ({ '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml', '.woff2': 'font/woff2' })[extname(path)] || 'application/octet-stream' }); }
                    catch { return route.fulfill({ status: 404, body: '' }); }
                }
                if (path === '/api/acceso-oficina') return route.fulfill({ json: { usuario: identity } });
                if (path === '/api/oficina/contexto') return route.fulfill({ json: { data: { temporada: season } } });
                if (path === '/api/envases/catalogo') return route.fulfill({ json: { data: [{ codigo: 'bins', nombre: 'Bins plástico', contiene_fruta: true }] } });
                if (path === '/api/materia-prima/resumen') return route.fulfill({ json: { temporada: season, lotes: {} } });
                if (path === '/api/materia-prima/catalogos') return route.fulfill({ json: { temporada: season, csg: [], especies: [], camaras: [camera] } });
                if (path === '/api/materia-prima/segmentos-pendientes') return route.fulfill({ json: { data: [] } });
                if (path.endsWith('/asignar-camara')) { writes.push(request.postDataJSON()); return route.fulfill({ json: { data: { ...lot, estado: 'asignado_camara' } } }); }
                if (path === '/api/materia-prima/lotes') return route.fulfill({ json: { data: [lot], meta: { current_page: 1, last_page: 1, total: 1 } } });
                return route.fulfill({ json: { data: [] } });
            });
            const page = await context.newPage(); page.on('pageerror', (error) => errors.push(error.message));
            await page.goto('http://localhost/oficina/materia-prima/lotes');
            await page.getByText('LOTE-NOCHE', { exact: true }).first().waitFor();
            // Las acciones de la fila se ofrecen en el selector común de Oficina.
            await page.locator('.lot-actions select.office-action-select').selectOption({ label: 'Asignar cámara' });
            const field = page.locator('#operationFields [name="ingresado_at"]');
            assert.equal(await field.getAttribute('type'), 'datetime-local');
            assert.equal(await field.getAttribute('min'), '2026-11-16T23:30');
            assert.match(await page.locator('#operationFields').innerText(), /el camión llegó el/);
            await page.locator('#operationFields [name="camara_id"]').selectOption('camara-mp');
            if (realTime) await field.fill(realTime);
            await page.locator('#operationForm [type="submit"][value="confirm"], #operationForm button[type="submit"]:not([value="cancel"])').last().click();
            await page.waitForFunction(() => !document.getElementById('operationDialog').open);
            assert.equal(writes.length, 1);
            assert.equal(writes[0].camara_id, 'camara-mp');
            assert.equal(writes[0].ingresado_at, realTime ?? undefined);
            assert.equal(await page.locator('#operationDialog').evaluate((dialog) => document.documentElement.scrollWidth <= window.innerWidth), true);
            assert.deepEqual(errors, []);
            await context.close();
        }
    } finally { await browser.close(); }
});
