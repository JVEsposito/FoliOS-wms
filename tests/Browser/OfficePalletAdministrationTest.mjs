import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { extname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
const camera = { id: 'cam-admin', codigo: 'CAM-07', nombre: 'Cámara de tránsito 07', contenido: 'productos', estado: 'activa', tipo: 'transito', version_plano: 3,
    prioridad_operacional: 'general', version_prioridad: 1, sentido_numeracion_bandas: 'izquierda_a_derecha',
    dimensiones: { bandas: 1, posiciones_por_banda: 2, niveles: 1 }, ocupacion: { ocupadas: 1, total: 2, porcentaje: 50 }, capacidad: { total: 2, activas: 2, ocupadas: 1 } };
const plan = { ...camera, folios_sin_posicion: [], bandas_operacionales: [{ id: 'banda-1', numero: 1, modo: 'operativa', estado: 'parcial', usos_permitidos: ['transito_pt'],
    capacidad: { efectiva: 2, ocupadas: 1, reservadas: 0, disponibles: 1, porcentaje: 50 }, afinidad: null }],
    posiciones: [1, 2].map(n => ({ id: `pos-${n}`, etiqueta: `B01-P0${n}-N1`, banda: 1, posicion: n, nivel: 1, estado: 'activa', ocupada: n === 1, reservada: false, folios: [] })) };

async function fixture(browser, role = 'administrador') {
    const context = await browser.newContext({ viewport: { width: 1366, height: 1000 } });
    const user = { id: 17, nombre: 'Ivan Sanchez', rol: role, puede_consultar_camaras: true, puede_administrar_camaras: role === 'administrador' };
    await context.addInitScript(user => {
        localStorage.setItem('estiba_wms_office_token', 'pallet-admin-fixture');
        localStorage.setItem('estiba_wms_office_identity', JSON.stringify(user));
    }, user);
    const state = { camera: { ...camera }, calls: [], priorities: [], failNext: false, hold: null, readStarted: null };
    await context.route('http://localhost/**', async route => {
        const request = route.request(), url = new URL(request.url()), path = url.pathname;
        if (path === '/oficina/frigorifico/camaras') return route.fulfill({ contentType: 'text/html', body: readFileSync(resolve(root, 'storage/app/ui/oficina/camaras.html'), 'utf8') });
        if (path.startsWith('/build/') || path.startsWith('/brand/')) {
            try { return route.fulfill({ body: readFileSync(resolve(root, `public${path}`)), contentType: ({ '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml', '.woff2': 'font/woff2' })[extname(path)] || 'application/octet-stream' }); }
            catch { return route.fulfill({ status: 404 }); }
        }
        if (path === '/api/acceso-oficina') return route.fulfill({ json: { usuario: user } });
        if (path === '/api/camaras' || path === '/api/configuracion/camaras') return route.fulfill({ json: { data: [state.camera] } });
        if (path === '/api/configuracion/camaras/siguiente-codigo') return route.fulfill({ json: { data: { codigo: 'CAM-08' } } });
        if (path === '/api/camaras/cam-admin/plano') return route.fulfill({ json: { data: { ...plan, ...state.camera } } });
        if (path === '/api/control-ambiental/estado') return route.fulfill({ json: { camaras: [] } });
        if (path === '/api/oficina/contexto') return route.fulfill({ json: { data: { planta: 'Planta fixture', temporada: null, avisos_cierre: [], avisos_materiales: [] } } });
        if (path === '/api/intervenciones-planificador/pallets') {
            assert.equal(request.headers().authorization, 'Bearer pallet-admin-fixture');
            assert.equal(url.searchParams.get('folio'), '6000000542');
            state.readStarted?.(); if (state.hold) await state.hold;
            try { return await route.fulfill({ json: { data: { folio_id: 'pallet-1', numero_folio: '6000000542', estado: 'disponible', revision: 'a'.repeat(64),
                ubicacion: { camara: 'Cámara de tránsito 07', posicion: 'B05-P01-N1' }, tareas: [{ id: 'tarea-1', estado: 'pendiente', instruccion: 'Retirar de REPA y ubicar', responsable: null }], historial: [] } } }); }
            catch { return; }
        }
        if (path === '/api/intervenciones-planificador/pallets/pallet-1') {
            const payload = request.postDataJSON(); state.calls.push(payload);
            if (state.failNext) { state.failNext = false; return route.abort('failed'); }
            return route.fulfill({ json: { data: { accion: payload.accion, folio_id: 'pallet-1' } } });
        }
        if (path === '/api/configuracion/camaras/cam-admin/prioridad-operacional') {
            const payload = request.postDataJSON(); state.priorities.push(payload);
            state.camera.prioridad_operacional = payload.prioridad_operacional; state.camera.version_prioridad += 1;
            return route.fulfill({ json: { data: state.camera } });
        }
        return route.fulfill({ json: { data: [] } });
    });
    const page = await context.newPage(), errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.goto('http://localhost/oficina/frigorifico/camaras');
    await page.locator('#cameraOpsName').filter({ hasText: 'Cámara de tránsito 07' }).waitFor();
    return { context, page, state, errors };
}

test('administrador designa destino, reintenta sin duplicados, corrige ubicación verificada y configura prioridad', async () => {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
    try {
        const { context, page, state, errors } = await fixture(browser);
        await page.locator('[data-admin-pallet]:visible').first().click();
        const dialog = page.getByRole('dialog', { name: 'Pallets y prioridades de cámaras', exact: true });
        await dialog.locator('[data-admin-lookup] input').fill('6000000542');
        await dialog.getByRole('button', { name: 'Consultar pallet', exact: true }).click();
        await dialog.locator('[data-admin-pallet-summary] h3').waitFor();
        const form = dialog.locator('[data-admin-pallet-form]');
        await form.locator('[name="camara_id"]').selectOption('cam-admin');
        await form.locator('[name="banda"]').fill('1'); await form.locator('[name="posicion"]').fill('2');
        await form.locator('[name="motivo"]').fill('Liberar la salida de REPA y concentrar en la cámara designada.');
        state.failNext = true;
        await form.getByRole('button', { name: 'Registrar intervención', exact: true }).click();
        await dialog.locator('[data-admin-status]').filter({ hasText: 'Reintenta sin cambiar el formulario' }).waitFor();
        await form.getByRole('button', { name: 'Registrar intervención', exact: true }).click();
        await page.waitForFunction(() => document.querySelector('[data-admin-pallet-form] textarea')?.value === '');
        assert.equal(state.calls[0].operacion_id, state.calls[1].operacion_id);
        assert.equal(state.calls[1].accion, 'designar_destino');
        assert.equal(state.calls[1].version_plano_destino, 3);
        await form.locator('[name="accion"]').selectOption('corregir_ubicacion');
        await form.locator('[name="motivo"]').fill('Ubicación comprobada junto al camarero.');
        await form.getByRole('button', { name: 'Registrar intervención', exact: true }).click();
        assert.equal(state.calls.length, 2);
        await form.locator('[name="confirmacion_fisica"]').check();
        const checkbox = await form.locator('[name="confirmacion_fisica"]').boundingBox();
        assert.ok(checkbox.width <= 24 && checkbox.height <= 24);
        await form.getByRole('button', { name: 'Registrar intervención', exact: true }).click();
        await page.waitForFunction(() => document.querySelector('[data-admin-pallet-form] textarea')?.value === '');
        assert.equal(state.calls.at(-1).accion, 'corregir_ubicacion'); assert.equal(state.calls.at(-1).confirmacion_fisica, true);
        const priority = dialog.locator('[data-admin-camera-form]');
        await priority.locator('[name="camara_id"]').selectOption('cam-admin');
        await priority.locator('[name="prioridad_operacional"]').selectOption('salida_tuneles');
        await priority.locator('[name="motivo"]').fill('Cámara principal para recibir los túneles.');
        await priority.getByRole('button', { name: 'Guardar prioridad de cámara', exact: true }).click();
        await page.waitForFunction(() => document.querySelector('[data-admin-camera-form] textarea')?.value === '');
        assert.equal(state.priorities[0].prioridad_operacional, 'salida_tuneles'); assert.equal(state.priorities[0].version_prioridad, 1);
        await page.waitForFunction(() => document.querySelector('.admin-pallet-dialog')?.getAttribute('aria-busy') !== 'true');
        await page.screenshot({ path: resolve(root, 'storage/app/ui/oficina/admin-pallets.png') });
        await page.setViewportSize({ width: 390, height: 844 });
        assert.ok(await dialog.evaluate(n => n.scrollWidth <= n.clientWidth + 1));
        await page.keyboard.press('Escape'); await dialog.waitFor({ state: 'detached' });
        assert.deepEqual(errors, []); await context.close();
    } finally { await browser.close(); }
});

test('supervisor consulta sin controles administrativos', async () => {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
    try {
        const { context, page, errors } = await fixture(browser, 'supervisor_frio');
        assert.equal(await page.locator('[data-admin-pallet]:visible').count(), 0);
        assert.deepEqual(errors, []); await context.close();
    } finally { await browser.close(); }
});
