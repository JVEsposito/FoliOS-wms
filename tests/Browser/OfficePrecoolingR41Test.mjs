import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { extname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';

// POEMP R-41: lecturas por sector (ventilador, medio, puerta), exterior e interior a cada lado.
const root = fileURLToPath(new URL('../../', import.meta.url));
const html = readFileSync(resolve(root, 'storage/app/ui/oficina/prefrio.html'), 'utf8');
const sectors = ['ventilador', 'medio', 'puerta'];
const points = ['izq_ex', 'izq_in', 'der_in', 'der_ex'];

function processFixture() {
    const partial = Object.fromEntries(sectors.map((sector) => [sector, Object.fromEntries(points.map((point) => [point, null]))]));
    partial.ventilador.izq_ex = -0.5; partial.ventilador.der_ex = 1.3;
    return {
        id: 'p1', codigo: 'PF-2026-000041', estado: 'en_proceso', version: 5, setpoint: -1.5, duracion_objetivo_minutos: 720, formato_referencia: 'Granel 5 kg',
        tunel: { id: 't1', codigo: 'TUN-01', nombre: 'Túnel 1', capacidad_posiciones: 4, setpoint_habitual: -1.5, estado_administrativo: 'activo', estado_tecnico: 'operativo' },
        iniciado_at: '2026-11-12T12:00:00Z', pendiente_verificacion_at: null, finalizado_at: null, observacion: null,
        folios: [{ id: 'a1', estado: 'en_proceso', posicion: { id: 'pos1', numero: 1, etiqueta: 'P01', activa: true }, folio: { id: 'f1', numero_folio: 'R41-0001', cantidad_cajas: 80 } }],
        eventos: [
            { id: 'e2', tipo: 'lectura', ocurrido_at: '2026-11-12T13:00:00Z', datos: { sectores: partial }, usuario: { nombre: 'Rosa' } },
            { id: 'e1', tipo: 'proceso_iniciado', ocurrido_at: '2026-11-12T12:00:00Z', datos: {}, usuario: { nombre: 'Rosa' } },
        ],
        registro_r41: { intervalo_lectura_minutos: 60, exige_lectura_final: true, lecturas: 1, ultima_lectura_at: '2026-11-12T13:00:00Z', ultima_lectura_completa: false,
            proxima_lectura_at: '2026-11-12T14:00:00Z', lectura_vencida: true, datos: { temperatura_objetivo: null, supervisor: null, jefe_frio: null, servicio: null } },
    };
}

test('la oficina registra lecturas por sector, exige la lectura final completa y descarga el R-41', async () => {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
    try {
        for (const width of [390, 1280]) {
            const context = await browser.newContext({ viewport: { width, height: 1000 } });
            const identity = { id: 1, nombre: 'Supervisor', rol: 'supervisor_frio', puede_consultar_prefrio: true, puede_operar_prefrio: true, puede_supervisar_prefrio: true, ambito_camaras: 'todas' };
            await context.addInitScript((user) => {
                localStorage.setItem('estiba_wms_office_token', 'fixture');
                localStorage.setItem('estiba_wms_office_identity', JSON.stringify(user));
            }, identity);
            const process = processFixture();
            const writes = []; const errors = [];
            await context.route('http://localhost/**', async (route) => {
                const request = route.request(); const path = new URL(request.url()).pathname;
                if (path === '/oficina/prefrio') return route.fulfill({ body: html, contentType: 'text/html' });
                if (path.startsWith('/build/') || path.startsWith('/brand/')) {
                    try { return route.fulfill({ body: readFileSync(resolve(root, `public${path}`)), contentType: ({ '.js': 'text/javascript', '.css': 'text/css', '.woff2': 'font/woff2', '.svg': 'image/svg+xml' })[extname(path)] || 'application/octet-stream' }); }
                    catch { return route.fulfill({ status: 404, body: '' }); }
                }
                if (request.method() !== 'GET') { writes.push({ method: request.method(), path, body: request.postDataJSON() }); return route.fulfill({ json: { data: process } }); }
                if (path === '/api/acceso-oficina') return route.fulfill({ json: { usuario: identity } });
                if (path === '/api/prefrio/tuneles') return route.fulfill({ json: { data: [{ ...process.tunel, posiciones: [], proceso_activo: { id: 'p1', codigo: process.codigo, estado: 'en_proceso' } }] } });
                if (path === '/api/prefrio/procesos') return route.fulfill({ json: { data: [process], meta: {} } });
                if (path === '/api/prefrio/resumen') return route.fulfill({ json: { tuneles_activos: 1 } });
                if (path === '/api/prefrio/procesos/p1') return route.fulfill({ json: { data: process } });
                if (path === '/api/prefrio/procesos/p1/registro-r41.xlsx') { writes.push({ method: 'GET', path }); return route.fulfill({ body: 'xlsx', contentType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' }); }
                return route.fulfill({ json: {} });
            });
            const page = await context.newPage(); page.on('pageerror', (error) => errors.push(error.message));
            await page.goto('http://localhost/oficina/prefrio');
            await page.locator('#officeLoading').waitFor({ state: 'hidden' });
            await page.locator('[data-process-id="p1"]').first().click();
            await page.locator('#r41Status').filter({ hasText: 'Lectura vencida' }).waitFor();
            assert.match(await page.locator('#r41Status').innerText(), /Al terminar el enfriado se exige una lectura completa/);
            const readings = await page.locator('#r41Readings').innerText();
            assert.match(readings, /Ventilador\s+-0,5\s+—\s+—\s+1,3/);

            // Lectura horaria parcial.
            await page.locator('#operationalActionSelect').selectOption('lectura');
            assert.equal(await page.locator('#operationalReadingField').isVisible(), true);
            await page.locator('[name="r41_medio_izq_in"]').fill('0.4');
            await page.locator('#operationalActionForm button[type="submit"]').click();
            await page.waitForTimeout(200);
            const lectura = writes.find((item) => item.path.endsWith('/eventos/lectura'));
            assert.equal(lectura.body.datos.sectores.medio.izq_in, 0.4);
            assert.equal(lectura.body.datos.sectores.ventilador.izq_ex, null);

            // Terminar: la última lectura no está completa, así que se exigen los 12 sensores.
            await page.locator('#operationalActionSelect').selectOption('verificar');
            assert.match(await page.locator('#operationalReadingNote').innerText(), /Obligatoria para terminar/);
            await page.locator('[name="r41_puerta_der_ex"]').fill('0.2');
            await page.locator('#operationalActionForm button[type="submit"]').click();
            assert.match(await page.locator('#operationalActionError').innerText(), /faltan 11/);
            for (const sector of sectors) for (const point of points) await page.locator(`[name="r41_${sector}_${point}"]`).fill('-0.3');
            await page.locator('#operationalActionForm button[type="submit"]').click();
            await page.waitForTimeout(200);
            const final = writes.find((item) => item.path.endsWith('/verificar'));
            assert.equal(final.body.datos.lectura_final.puerta.der_ex, -0.3);

            // Datos de la planilla y descarga.
            await page.locator('#r41DataForm [name="temperatura_objetivo"]').fill('0.5');
            await page.locator('#r41DataForm [name="supervisor"]').fill('Juan Pérez');
            await page.locator('#r41DataForm button[type="submit"]').click();
            await page.waitForTimeout(200);
            assert.deepEqual(writes.find((item) => item.method === 'PUT').body, { temperatura_objetivo: 0.5, servicio: null, supervisor: 'Juan Pérez', jefe_frio: null });
            const download = page.waitForEvent('download');
            await page.locator('#r41DownloadButton').click();
            assert.equal((await download).suggestedFilename(), 'R-41_PF-2026-000041.xlsx');
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), true);
            assert.deepEqual(errors, []);
            await context.close();
        }
    } finally { await browser.close(); }
});
