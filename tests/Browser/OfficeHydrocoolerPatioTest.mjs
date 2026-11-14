import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { extname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';

// Patio registra solo tiempos, temperaturas y estado del agua; el registro completo POEMP-R3 sigue disponible.
const root = fileURLToPath(new URL('../../', import.meta.url));
const html = readFileSync(resolve(root, 'storage/app/ui/oficina/hidrocooler.html'), 'utf8');
const season = { id: 'temporada', nombre: 'Temporada 2026', codigo: 'TEMP-2627', activa: true };
const base = { cliente: { nombre: 'FALCO FRUIT' }, recepcion: { numero_recepcion: 'REC-2611-0060' },
    trazabilidad: { csg: '121328', especie: 'Cereza', variedad: 'Santina', cuartel: 'C-1' },
    envases: { primario: 'bins', cantidad_primarios: 34 }, pesos: { kilos_netos_confirmados: 7602.784 }, confirmado_at: '2026-11-14T10:00:00Z' };
const pending = { ...base, id: 'l1', numero_lote: '30005', estado: 'pendiente_hidrocooler', hidrocooler: null };
const running = { ...base, id: 'l2', numero_lote: '30004', estado: 'hidrocooler_en_curso',
    hidrocooler: { codigo: 'HC-1', modalidad: 'patio', equipo: 'HIDRO-01', turno: null, cantidad_bombas_funcionando: null, operador: 'Patio',
        cantidad_envases: 2, kilos_netos: 447.216, inicio_at: '2026-11-14T10:20:00Z', termino_at: null, duracion_minutos: null,
        temperatura_inicial_c: 18.5, temperatura_objetivo_c: null, temperatura_agua_inicial_c: 1.5, cloro_libre_ppm: null, ph_agua: null,
        condicion_visual_agua: 'conforme', dosificador_operativo: null, manejo_agua: null, cloro_libre_final_ppm: null, ph_agua_final: null } };

test('Hidrocooler en Oficina: registro de patio por defecto y completo a elección', async () => {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
    try {
        // La página de Hidrocooler es de escritorio (min-width 1040 px), como Cargas.
        for (const width of [1100, 1440]) {
            const context = await browser.newContext({ viewport: { width, height: 1000 } });
            const identity = { id: 1, nombre: 'Patio', rol: 'operador_hidrocooler', puede_consultar_hidrocooler_materia_prima: true,
                puede_operar_hidrocooler_materia_prima: true, modulos_acceso: ['materia-prima.hidrocooler'] };
            await context.addInitScript((user) => {
                localStorage.setItem('estiba_wms_office_token', 'fixture');
                localStorage.setItem('estiba_wms_office_identity', JSON.stringify(user));
            }, identity);
            const writes = []; const errors = [];
            await context.route('http://localhost/**', async (route) => {
                const request = route.request(); const path = new URL(request.url()).pathname;
                if (path === '/oficina/materia-prima/hidrocooler') return route.fulfill({ body: html, contentType: 'text/html' });
                if (path.startsWith('/build/') || path.startsWith('/brand/')) {
                    try { return route.fulfill({ body: readFileSync(resolve(root, `public${path}`)), contentType: ({ '.js': 'text/javascript', '.css': 'text/css', '.woff2': 'font/woff2', '.svg': 'image/svg+xml' })[extname(path)] || 'application/octet-stream' }); }
                    catch { return route.fulfill({ status: 404, body: '' }); }
                }
                if (path === '/api/acceso-oficina') return route.fulfill({ json: { usuario: identity } });
                if (path === '/api/envases/catalogo') return route.fulfill({ json: { data: [{ codigo: 'bins', nombre: 'Bins plástico', contiene_fruta: true }] } });
                if (path === '/api/materia-prima/hidrocooler/resumen') return route.fulfill({ json: { temporada: season, pendientes: 1, en_curso: 1, retenidos: 0, completados_hoy: 0, kilos_en_curso: 447.216, duracion_promedio_hoy: 0, equipos: ['HIDRO-01', 'HIDRO-02'] } });
                if (path === '/api/materia-prima/hidrocooler/productos') return route.fulfill({ json: { data: [], rango_cloro_ppm: null } });
                if (path === '/api/materia-prima/hidrocooler/lotes') return route.fulfill({ json: { data: [pending, running], meta: { current_page: 1, last_page: 1 } } });
                if (path.endsWith('/hidrocooler/iniciar') || path.endsWith('/hidrocooler/completar')) {
                    writes.push({ path, body: request.postDataJSON() });
                    return route.fulfill({ json: { data: { ...pending, estado: path.endsWith('completar') ? 'pendiente_asignacion' : 'hidrocooler_en_curso' } } });
                }
                return route.fulfill({ json: {} });
            });
            const page = await context.newPage(); page.on('pageerror', (error) => errors.push(error.message));
            await page.goto('http://localhost/oficina/materia-prima/hidrocooler');
            await page.locator('[data-start="l1"]').click();
            const start = page.locator('#startForm');
            await start.locator('#startModality').waitFor();
            assert.equal(await start.locator('[name="modalidad"][value="patio"]').isChecked(), true);
            assert.equal(await start.locator('[name="cloro_libre_ppm"]').isVisible(), false, 'Patio no pide cloro.');
            assert.equal(await start.locator('[name="turno"]').isVisible(), false);
            // El registro completo sigue disponible y vuelve a pedir todo.
            await start.locator('[name="modalidad"][value="oficial"]').check();
            assert.equal(await start.locator('[name="cloro_libre_ppm"]').isVisible(), true);
            assert.equal(await start.locator('[name="cloro_libre_ppm"]').evaluate((el) => el.required), true);
            await start.locator('[name="modalidad"][value="patio"]').check();
            await start.locator('[name="equipo"]').fill('HIDRO-02');
            await start.locator('[name="temperatura_inicial_c"]').fill('19');
            await start.locator('[name="condicion_visual_agua"]').selectOption('conforme');
            await start.locator('button[value="default"]').click();
            await page.waitForFunction(() => !document.getElementById('startDialog').open);
            const inicio = writes.find((w) => w.path.endsWith('iniciar')).body;
            assert.equal(inicio.modalidad, 'patio');
            assert.deepEqual(Object.keys(inicio).sort(), ['condicion_visual_agua', 'equipo', 'inicio_at', 'modalidad', 'observacion_inicio', 'operacion_id', 'temperatura_agua_inicial_c', 'temperatura_inicial_c']);
            assert.equal(inicio.temperatura_inicial_c, 19);

            // Término de un ciclo de patio: sin cloro, pH ni dosificador.
            assert.match(await page.locator('#hydrocoolerList').innerText(), /Registro de patio/);
            await page.locator('[data-finish="l2"]').click();
            const finish = page.locator('#finishForm');
            await finish.locator('[name="temperatura_c"]').waitFor();
            assert.equal(await finish.locator('[name="cloro_libre_final_ppm"]').isVisible(), false);
            assert.match(await page.locator('#finishRule').innerText(), /Registro de patio/);
            await finish.locator('[name="temperatura_c"]').fill('6.5');
            await finish.locator('[name="condicion_visual_agua_final"]').selectOption('conforme');
            await finish.locator('button[value="default"]').click();
            await page.waitForFunction(() => !document.getElementById('finishDialog').open);
            const termino = writes.find((w) => w.path.endsWith('completar')).body;
            assert.equal(termino.cloro_libre_final_ppm, undefined);
            assert.equal(termino.temperatura_c, 6.5);
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), true);
            assert.deepEqual(errors, []);
            await context.close();
        }
    } finally { await browser.close(); }
});
