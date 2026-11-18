import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));

test('el centro de control muestra MP y PT y conserva la pestaña al refrescar', async () => {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
    try {
        const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
        const identity = { nombre: 'Supervisión', rol: 'consulta', puede_consultar_panel_gerencial: true, modulos_acceso: ['gerencia.panel'] };
        await context.addInitScript((user) => {
            localStorage.setItem('estiba_wms_office_token', 'fixture');
            localStorage.setItem('estiba_wms_office_identity', JSON.stringify(user));
        }, identity);
        const now = '2026-11-18T14:00:00Z';
        const snapshot = {
            generado_at: now, temporada: { id: 'temp', codigo: 'TEMP-2627', nombre: 'Prueba' },
            jornada: { zona_horaria: 'America/Santiago', turno: 'A' }, sincronizacion: { estado: 'sin_actividad', operaciones_hoy: {} },
            camaras: [], camareros: [], prefrio: { resumen: { procesos_activos: 0 }, tuneles: [] },
            incidencias: { resumen: {}, abiertas: [] },
            planificador: { despliegue: { mode_global: 'off' }, salud: { riesgos: {} }, arbitraje: { vigencia: {} } },
            planta: { catalogo: [], elementos: [], conexiones: [], configurado: false },
            despacho: { resumen: { activas: 2, cargando: 1 }, cargas: [], reingresos: [] },
            recepcion_mp: { en_curso: 1, en_ingreso: 0, en_pesaje: 0, pendientes_destare: 1, cerradas_jornada: 4, kilos_netos_jornada: 12350,
                recepciones: [{ numero: 'REC-2611-0011', cliente: 'Cliente prueba', guias: ['1511', '1512'], patente: 'ABCD12', ingreso_at: now,
                    estado: 'en_bascula_salida', validacion_mp: 'validada' }] },
            validacion_pt: { procesadas: 25, aprobadas: 24, observadas: 1, rechazadas: 0, conflictos: 1,
                recientes: [{ folio: '6000000001', linea: 2, turno: 'A', recibido_at: now, resultado: 'conflicto' }] },
        };
        await context.route('http://localhost/**', (route) => {
            const { pathname } = new URL(route.request().url());
            if (pathname === '/oficina/operacion-ahora') return route.fulfill({ path: resolve(root, 'storage/app/ui/oficina/operacion-ahora.html') });
            if (pathname.startsWith('/build/')) return route.fulfill({ path: resolve(root, `public${pathname}`) });
            if (pathname === '/api/operacion-ahora') return route.fulfill({ json: { data: snapshot } });
            if (pathname === '/api/operacion-ahora/camareros') return route.fulfill({ json: { data: { generado_at: now, camareros: [] } } });
            if (pathname === '/api/acceso-oficina') return route.fulfill({ json: { usuario: identity } });
            return route.fulfill({ json: { data: { planta: 'Pruebas', temporada: snapshot.temporada } } });
        });

        const page = await context.newPage();
        const errors = [];
        page.on('pageerror', (error) => errors.push(error.message));
        await page.goto('http://localhost/oficina/operacion-ahora');
        const summary = page.getByRole('tabpanel', { name: 'Resumen' });
        await summary.getByText('REC-2611-0011').waitFor();
        assert.equal(await summary.locator('#operationOverviewPt').innerText(), '25');
        await summary.getByRole('link', { name: 'Ver recepción MP' }).click();
        assert.equal(new URL(page.url()).hash, '#recepcion-mp');
        await page.locator('#operationReceptionRows').getByText('1511 · 1512').waitFor();
        assert.match(await page.locator('#operationReceptionRows').innerText(), /Pendiente de destare.*Validada/s);
        assert.equal(await page.locator('#operationOpenRomana').isVisible(), false);

        snapshot.recepcion_mp.en_curso = 0;
        snapshot.recepcion_mp.pendientes_destare = 0;
        snapshot.recepcion_mp.recepciones = [];
        await page.locator('#operationRefresh').click();
        await page.locator('#operationReceptionRows').getByText('Sin camiones con fruta en curso').waitFor();
        assert.equal(new URL(page.url()).hash, '#recepcion-mp');

        await page.setViewportSize({ width: 390, height: 850 });
        await page.getByRole('tab', { name: /Validación PT/ }).click();
        await page.locator('#operationValidationRows').getByText('Conflicto', { exact: true }).waitFor();
        assert.equal(await page.locator('#operationPtApproved').innerText(), '24');
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth), 390);
        assert.deepEqual(errors, []);
        await context.close();
    } finally {
        await browser.close();
    }
});
