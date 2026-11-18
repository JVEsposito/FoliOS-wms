import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { extname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
const html = readFileSync(resolve(root, 'storage/app/ui/oficina/cuenta-corriente.html'), 'utf8');
const client = { id: 'cliente', codigo: 'CLI', nombre: 'Cliente de arriendo' };

async function setup(browser, width) {
    const context = await browser.newContext({ viewport: { width, height: 1000 } });
    const identity = { nombre: 'Cuenta Envases', rol: 'administrador', puede_consultar_cuenta_envases: true, modulos_acceso: ['materia-prima.cuenta-envases'] };
    await context.addInitScript((user) => {
        // La planta abre Oficina por HTTP en su red local: sin crypto.randomUUID.
        Object.defineProperty(Crypto.prototype, 'randomUUID', { value: undefined });
        localStorage.setItem('estiba_wms_office_token', 'fixture_envases');
        localStorage.setItem('estiba_wms_office_identity', JSON.stringify(user));
    }, identity);
    const calls = [];
    const errors = [];
    let saved = false;
    const document = (numero, productores, historial = []) => ({
        clave: `recepcion:rec-1:recibidos:guia:${numero}`, lado: 'recibidos', numero, titulo: `Guía de ingreso N° ${numero}`, movimiento: 'Ingreso con fruta',
        fecha: '2026-11-13 09:30:00', referencia: 'Recepción REC-2611-0026 · Camión ABCD12', chofer: 'Chofer Juan Pérez',
        productores: productores.map(([codigo, nombre, bins, totes]) => ({ codigo, nombre, envases: [
            { tipo_envase: 'bins', nombre: 'Bins plástico', cantidad: bins }, { tipo_envase: 'totes', nombre: 'Tote', cantidad: totes }] })),
        disponible: true, motivo_no_disponible: null, historial,
    });
    const movement = { id: 'ingreso', cliente: client, numero_documento: '1512', movimiento: 'Recepción con fruta', tipo_movimiento: 'recepcion_fruta',
        tipo_envase: 'bins', cantidad: 4, impacto_cuenta: 4, impacto_existencia: 4, propiedad: 'cliente', ocurrido_at: '2026-11-13T12:30:00Z',
        estado_revision: 'pendiente', puede_reasignar: true, puede_reasignar_productor: true, reasignaciones_productor: [] };
    await context.route('http://localhost/**', async (route) => {
        const request = route.request();
        const path = new URL(request.url()).pathname;
        if (path === '/oficina/envases/cuenta-corriente') return route.fulfill({ body: html, contentType: 'text/html' });
        if (path.startsWith('/build/') || path.startsWith('/brand/')) {
            try { return route.fulfill({ body: readFileSync(resolve(root, `public${path}`)), contentType: ({ '.js': 'text/javascript', '.css': 'text/css', '.woff2': 'font/woff2', '.svg': 'image/svg+xml' })[extname(path)] || 'application/octet-stream' }); }
            catch { return route.fulfill({ status: 404, body: '' }); }
        }
        if (path === '/api/acceso-oficina') return route.fulfill({ json: { usuario: identity } });
        if (path === '/api/oficina/contexto') return route.fulfill({ json: { data: { planta: 'Prueba', temporada: { nombre: '2026' } } } });
        if (path.endsWith('/cuenta-corriente/catalogos')) return route.fulfill({ json: { clientes: [client], temporadas: [{ id: 'temporada', codigo: '2026', nombre: '2026', activa: true }], tipos_envase: [{ codigo: 'bins', nombre: 'Bins' }], hoy: '2026-11-17', puede_ajustar: true } });
        if (path.endsWith('/ingreso/reasignacion-productor')) return route.fulfill({ json: { data: [
            document('1511', [['CSG-A', 'Agrícola Uno', 2, 48]]),
            saved ? document('1512', [['CSG-B', 'Agrícola Dos', 1, 40], ['CSG-C', 'Agrícola Tres', 1, 9]], [{ id: 'r1', modalidad: 'parcial',
                origen: { codigo: 'CSG-B', nombre: 'Agrícola Dos' }, destino: { codigo: 'CSG-C', nombre: 'Agrícola Tres' },
                envases: [{ tipo_envase: 'bins', nombre: 'Bins plástico', cantidad: 1 }, { tipo_envase: 'totes', nombre: 'Tote', cantidad: 9 }],
                motivo: 'Parte de la guía es de Agrícola Tres.', usuario: identity.nombre, registrado_at: '2026-11-17T21:00:00Z' }])
                : document('1512', [['CSG-B', 'Agrícola Dos', 2, 49]]),
        ] } });
        if (path === '/api/envases/devoluciones/productores/cliente') return route.fulfill({ json: { data: [
            { id: 'prod-a', codigo: 'CSG-A', nombre: 'Agrícola Uno' }, { id: 'prod-b', codigo: 'CSG-B', nombre: 'Agrícola Dos' }, { id: 'prod-c', codigo: 'CSG-C', nombre: 'Agrícola Tres' }] } });
        if (path.endsWith('/cuenta-corriente/reasignaciones-productor')) {
            calls.push(request.postDataJSON());
            // La primera respuesta se pierde: el reintento debe enviar la misma operación.
            if (calls.length === 1) return route.abort('failed');
            saved = true;
            movement.reasignaciones_productor = [{ id: 'r1', origen: { codigo: 'CSG-B' }, destino: { codigo: 'CSG-C' }, cantidad: 1 }];
            return route.fulfill({ status: 201, json: { data: { id: 'r1' } } });
        }
        if (path.endsWith('/cuenta-corriente/movimientos')) return route.fulfill({ json: {
            data: [movement], pendientes: [], reservas: [], balances: [{ cliente: client, tipo_envase: 'bins', saldo: 4 }],
            existencia: { tipos: [], propios_entregados: [] }, resumen: { movimientos_confirmados: 1, envases_reservados: 0, lineas_pendientes_validacion: 0, observados: 0, sincronizado_at: '2026-11-17T15:00:00Z' },
        } });
        return route.fulfill({ json: {} });
    });
    const page = await context.newPage();
    page.on('pageerror', (error) => errors.push(error.message));
    await page.goto('http://localhost/oficina/envases/cuenta-corriente');
    await page.waitForFunction(() => document.getElementById('balanceList')?.textContent.includes('Cliente de arriendo'));

    return { context, page, calls, errors };
}

test('reasigna parte de un movimiento a otro CSG de la cuenta, con razón social automática, en móvil y escritorio', async () => {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
    try {
        for (const width of [390, 1280]) {
            const { context, page, calls, errors } = await setup(browser, width);
            await page.locator('#container-accounts-tab-movements').click();
            // Es una opción más del menú de la fila; las correcciones existentes siguen ahí.
            const options = await page.locator('#movementsBody .office-action-select option').allTextContents();
            assert.ok(options.includes('Reasignar guía'));
            assert.ok(options.includes('Reasignar movimiento a otro productor / CSG'));
            await page.locator('#movementsBody .office-action-select').selectOption({ label: 'Reasignar movimiento a otro productor / CSG' });
            const dialog = page.locator('#producerReassignDialog');
            await dialog.locator('[name="documento_clave"]').first().waitFor();
            // Parte en la guía del movimiento, con su productor actual y sus cantidades.
            assert.equal(await dialog.locator('[name="documento_clave"]:checked').getAttribute('value'), 'recepcion:rec-1:recibidos:guia:1512');
            assert.match(await page.locator('#producerReassignSummary').textContent(), /Guía de ingreso N° 1512.*Ingreso con fruta/s);
            assert.equal(await dialog.locator('[name="productor_origen"] option:checked').textContent(), 'CSG CSG-B · Agrícola Dos');
            assert.match(await page.locator('#producerReassignLines').textContent(), /2 Bins plástico.*49 Tote/s);
            assert.equal(await page.locator('#producerReassignSubmit').isDisabled(), true);
            // El productor actual no se ofrece como destino; la razón social se completa con el CSG.
            assert.equal(await dialog.locator('[name="productor_csg_id"] option[value="prod-b"]').evaluate((option) => option.disabled), true);
            await dialog.locator('[name="productor_csg_id"]').selectOption('prod-c');
            assert.equal(await dialog.locator('[name="razon_social"]').inputValue(), 'Agrícola Tres');
            // Parcial: no más de lo que tiene el productor actual.
            await dialog.locator('[name="modalidad"][value="parcial"]').check();
            await dialog.locator('[name="motivo"]').fill('Parte de la guía es de Agrícola Tres.');
            await dialog.locator('[data-quantity="bins"]').fill('3');
            assert.equal(await page.locator('#producerReassignSubmit').isDisabled(), true);
            await dialog.locator('[data-quantity="bins"]').fill('1');
            await dialog.locator('[data-quantity="totes"]').fill('9');
            assert.match(await page.locator('#producerReassignPreview').textContent(),
                /Guía de ingreso N° 1512: 1 bins plástico, 9 tote pasan de CSG CSG-B · Agrícola Dos a CSG CSG-C · Agrícola Tres\. El resto sigue en CSG CSG-B · Agrícola Dos\. El saldo de la cuenta no cambia\./);
            assert.equal(await dialog.evaluate((element) => element.scrollWidth <= element.clientWidth + 1), true);
            if (process.env.WMS_REASSIGN_SCREENSHOTS) await page.screenshot({ path: `${process.env.WMS_REASSIGN_SCREENSHOTS}/reasignar-productor-${width}.png` });
            // La primera respuesta se pierde; el reintento usa la misma operación.
            await page.locator('#producerReassignSubmit').click();
            await page.waitForFunction(() => document.getElementById('producerReassignError').textContent.length > 0);
            await page.locator('#producerReassignSubmit').click();
            await page.waitForFunction(() => !document.getElementById('producerReassignDialog').open);
            assert.equal(calls.length, 2);
            assert.deepEqual(calls[0], calls[1]);
            assert.deepEqual({ ...calls[1], operacion_id: undefined }, { operacion_id: undefined, documento_clave: 'recepcion:rec-1:recibidos:guia:1512',
                productor_origen_codigo: 'CSG-B', productor_csg_id: 'prod-c', razon_social: 'Agrícola Tres', modalidad: 'parcial',
                envases: [{ tipo_envase: 'bins', cantidad: 1 }, { tipo_envase: 'totes', cantidad: 9 }], motivo: 'Parte de la guía es de Agrícola Tres.' });
            assert.match(calls[1].operacion_id, /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
            // El kardex interno anota a quién pasó; el diálogo muestra el reparto y su historial.
            // La tabla se recarga después de cerrar el diálogo: se espera la recarga en vez de leerla al instante.
            await page.waitForFunction(() => /1 a CSG CSG-C \(desde CSG CSG-B\)/.test(document.getElementById('movementsBody').textContent));
            await page.locator('#movementsBody .office-action-select').selectOption({ label: 'Reasignar movimiento a otro productor / CSG' });
            await dialog.locator('[name="documento_clave"]').first().waitFor();
            assert.deepEqual(await dialog.locator('[name="productor_origen"] option').allTextContents(), ['CSG CSG-B · Agrícola Dos', 'CSG CSG-C · Agrícola Tres']);
            assert.equal(await page.locator('#producerReassignHistory').isVisible(), true);
            assert.match(await page.locator('#producerReassignHistoryList').textContent(), /Parcial · 1 bins plástico, 9 tote.*CSG CSG-B · Agrícola Dos → CSG CSG-C · Agrícola Tres/s);
            await page.locator('#producerReassignCancel').click();
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
            assert.deepEqual(errors, []);
            await context.close();
        }
    } finally { await browser.close(); }
});
