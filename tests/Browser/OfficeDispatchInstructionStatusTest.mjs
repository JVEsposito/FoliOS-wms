import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { extname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
const identity = { nombre: 'Supervisión', rol: 'administrador', puede_consultar_panel_gerencial: true };
const progress = (done, total, enAnden = 0) => ({
    acopiados: done, total, porcentaje: total ? Math.round(done / total * 100) : 0,
    umbral_porcentaje: 80, cumple_umbral: done >= total && total > 0, en_anden: enAnden,
});
const loading = (done, total, waiting = 0) => ({ cargados: done, total, porcentaje: total ? Math.floor(done / total * 100) : 0, en_anden_por_cargar: waiting });
const instruction = (id, number, state, loaded) => ({
    id, orden: id === 'i1' ? 1 : 2, numero_externo: number, booking: `BOOK-${id}`, recibidor: 'UPAM FRESH LLC',
    pallets_asignados: 2, pallets_programados: 2, pallets_pendientes_asignar: 0, pendientes_ubicacion: state === 'pendiente' ? 2 : 0,
    estado: state, incidencias_abiertas: 0, separacion: progress(state === 'pendiente' ? 0 : 2, 2, loaded), carguio: loading(loaded, 2),
});

test('Despacho PT muestra los subestados de cada instructivo sin duplicar el camión', async () => {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
    try {
        const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
        await context.addInitScript(user => {
            localStorage.setItem('estiba_wms_office_token', 'fixture');
            localStorage.setItem('estiba_wms_office_identity', JSON.stringify(user));
        }, identity);
        const secondNumber = 'F002 <img src=x onerror="window.__dispatchXss=1">';
        const load = {
            id: 'load', codigo: 'CAR-000031', numero_orden_externa: null, embarque: 'EFC000009', clientes: ['FALCO FRUIT'],
            estado: 'despacho_parcial', prioridad: 'normal', incidencias_abiertas: 0, lista_para_cerrar: false,
            anden: 'AND-04', camion_en_anden: { patente: 'KFGT13', ingresada_at: '2026-11-11T17:00:00Z' },
            separacion: progress(2, 4, 2), carguio: loading(2, 4),
            instructivos: [instruction('i1', 'F001', 'cargado_completo', 2), instruction('i2', secondNumber, 'pendiente', 0)],
        };
        // La segunda carga conserva la fila simple si sólo tiene un instructivo.
        const single = { ...load, id: 'single', codigo: 'CAR-000030', clientes: ['LA AGUADA'], camion_en_anden: null,
            estado: 'pendiente', separacion: progress(1, 2), carguio: loading(0, 2), instructivos: [instruction('i3', 'F003', 'pendiente', 0)] };
        const snapshot = {
            generado_at: '2026-11-11T17:00:00Z', temporada: { id: 'temp', codigo: 'TEMP-2627', nombre: 'Prueba' },
            jornada: { zona_horaria: 'America/Santiago', turno: 'dia' }, sincronizacion: { estado: 'sin_actividad', operaciones_hoy: {} },
            camaras: [], camareros: [], prefrio: { resumen: {}, tuneles: [] }, incidencias: { resumen: {}, abiertas: [] },
            planificador: { despliegue: { mode_global: 'off' }, salud: { riesgos: {} }, arbitraje: { vigencia: {} } },
            planta: { catalogo: [], elementos: [], conexiones: [], configurado: false, puede_editar: false },
            despacho: { resumen: { activas: 2, cargando: 1, listas_para_cerrar: 0 }, cargas: [load, single], reingresos: [] },
        };
        await context.route('http://localhost/**', async route => {
            const path = new URL(route.request().url()).pathname;
            if (path === '/oficina/operacion-ahora') return route.fulfill({ contentType: 'text/html', body: readFileSync(resolve(root, 'storage/app/ui/oficina/operacion-ahora.html'), 'utf8') });
            if (path.startsWith('/build/') || path.startsWith('/brand/')) {
                try {
                    return route.fulfill({ body: readFileSync(resolve(root, `public${path}`)), contentType: ({ '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml', '.woff2': 'font/woff2' })[extname(path)] || 'application/octet-stream' });
                } catch { return route.fulfill({ status: 404 }); }
            }
            if (path === '/api/operacion-ahora') return route.fulfill({ json: { data: snapshot } });
            if (path === '/api/operacion-ahora/camareros') return route.fulfill({ json: { data: { generado_at: snapshot.generado_at, camareros: [] } } });
            if (path === '/api/acceso-oficina') return route.fulfill({ json: { usuario: identity } });
            return route.fulfill({ json: { data: { temporada: snapshot.temporada, planta: 'Pruebas', avisos_cierre: [], avisos_materiales: [] } } });
        });
        const page = await context.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.goto('http://localhost/oficina/operacion-ahora');
        await page.locator('[data-instruction-id="i1"]').waitFor({ state: 'attached' });
        await page.locator('#operationTab-despacho').click();
        const rows = page.locator('#operationDispatchRows');
        assert.equal(await rows.locator('[data-dispatch-load]').count(), 2);
        assert.equal(await rows.locator('[data-instruction-id]').count(), 2);
        const first = rows.locator('[data-instruction-id="i1"]');
        const second = rows.locator('[data-instruction-id="i2"]');
        assert.match(await first.innerText(), /Instructivo F001/);
        assert.match(await first.innerText(), /Cargado completo/);
        assert.match(await second.innerText(), /Pendiente/);
        assert.doesNotMatch(await second.innerText(), /Cargado completo/);
        assert.match(await second.innerText(), /0\/2/);
        assert.equal(await second.locator('img').count(), 0);
        assert.equal(await page.evaluate(() => window.__dispatchXss), undefined);
        assert.match(await page.locator('#dispatchPanelSummary').innerText(), /^2 activas/);
        assert.equal(await rows.locator('[data-dispatch-load="load"] [rowspan]').getAttribute('rowspan'), '3');
        assert.equal(await first.locator('td').count(), 4);

        load.instructivos[1].incidencias_abiertas = 1;
        await page.locator('#operationRefresh').click();
        await second.getByText('1 incidencia', { exact: true }).waitFor();
        assert.match(await first.innerText(), /Cargado completo/);
        assert.equal(await rows.locator('[data-dispatch-load]').count(), 2);
        await page.setViewportSize({ width: 768, height: 1000 });
        assert.equal(await first.isVisible(), true);
        assert.equal(await second.isVisible(), true);
        assert.deepEqual(errors, []);
        await context.close();
    } finally {
        await browser.close();
    }
});
