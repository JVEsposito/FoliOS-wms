import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve, extname } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
const html = readFileSync(resolve(root, 'storage/app/ui/oficina/cuenta-corriente.html'), 'utf8');

async function setup(browser, manage, width = 390, historical = false) {
    const context = await browser.newContext({ viewport: { width, height: 1000 } });
    const identity = { nombre: 'Cuenta Envases', rol: manage ? 'administrador' : 'consulta', puede_consultar_cuenta_envases: true, modulos_acceso: ['materia-prima.cuenta-envases'] };
    await context.addInitScript((user) => {
        Object.defineProperty(Crypto.prototype, 'randomUUID', { value: undefined });
        localStorage.setItem('estiba_wms_office_token', 'fixture_envases');
        localStorage.setItem('estiba_wms_office_identity', JSON.stringify(user));
    }, identity);
    const client = { id: 'cliente', codigo: 'CLI', nombre: 'Cliente de arriendo' };
    const movements = historical ? [{ id: 'historico', cliente: client, numero_documento: '123', movimiento: 'Ingreso en arriendo', tipo_envase: 'bins', cantidad: 138, impacto_cuenta: 138, impacto_cuenta_vigente: 138, impacto_existencia: 138, propiedad: 'arrendada', ocurrido_at: '2026-09-01T13:36:00Z', estado_revision: 'pendiente', puede_corregir_saldo_historico: true }] : [];
    const writes = [];
    let balance = historical ? -306 : 0;
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
        if (path.endsWith('/cuenta-corriente/catalogos')) return route.fulfill({ json: { clientes: [client], temporadas: [{ id: 'temporada', codigo: '2026', nombre: '2026', activa: true }], tipos_envase: [{ codigo: 'bins', nombre: 'Bins' }], hoy: '2026-10-21', puede_ajustar: manage } });
        if (path.endsWith('/cuenta-corriente/ajustes')) {
            const body = request.postDataJSON();
            writes.push(body);
            balance += body.cantidad;
            movements.unshift({ id: 'asiento', cliente: client, numero_documento: body.referencia, movimiento: 'Saldo de apertura', tipo_envase: 'bins', cantidad: Math.abs(body.cantidad), impacto_cuenta: body.cantidad, impacto_existencia: 0, propiedad: body.propiedad, ocurrido_at: '2026-10-05T03:00:00Z', estado_revision: 'pendiente', puede_revertir_ajuste: true, ajuste_cuenta: { motivo: body.motivo }, creado_por: { nombre: identity.nombre } });
            return route.fulfill({ status: 201, json: { data: movements[0] } });
        }
        if (path.endsWith('/corregir-saldo-historico')) {
            const body = request.postDataJSON(); writes.push(body);
            balance += body.saldo_correcto - body.impacto_conocido;
            movements[0].impacto_cuenta_vigente = body.saldo_correcto;
            return route.fulfill({ status: 201, json: { data: { id: 'correccion' } } });
        }
        if (path.endsWith('/revertir-ajuste')) {
            writes.push(request.postDataJSON());
            balance = 0;
            movements[0].puede_revertir_ajuste = false;
            return route.fulfill({ status: 201, json: { data: { id: 'reversa' } } });
        }
        if (path.endsWith('/cuenta-corriente/movimientos')) return route.fulfill({ json: {
            data: movements, pendientes: [], reservas: [], balances: [{ cliente: client, tipo_envase: 'bins', saldo: balance }],
            existencia: { tipos: [], propios_entregados: [] }, resumen: { movimientos_confirmados: movements.length, envases_reservados: 0, lineas_pendientes_validacion: 0, observados: 0, sincronizado_at: '2026-10-21T15:00:00Z' },
        } });
        return route.fulfill({ json: {} });
    });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await page.goto('http://localhost/oficina/envases/cuenta-corriente');
    await page.waitForFunction(() => document.getElementById('balanceList')?.textContent.includes('Cliente de arriendo'));
    return { context, page, writes, errors };
}

test('registra una apertura negativa y su reversa conservando el contexto de cuenta en móvil y escritorio', async () => {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
    try {
        for (const width of [390, 1280]) {
            const { context, page, writes, errors } = await setup(browser, true, width);
            await page.locator('#accountAdjustmentNew').click();
            const form = page.locator('#accountAdjustmentForm');
            await form.locator('[name="tipo_movimiento"]').selectOption('apertura_cuenta');
            await form.locator('[name="cliente_id"]').selectOption('cliente');
            await form.locator('[name="cantidad"]').fill('-30');
            await form.locator('[name="fecha"]').fill('2026-10-05');
            await form.locator('[name="referencia"]').fill('SALDO-CONVENIDO');
            await form.locator('[name="motivo"]').fill('Conciliación del saldo anterior de arriendo');
            assert.match(await page.locator('#accountAdjustmentPreview').textContent(), /0 → -30/);
            await form.locator('[type="submit"]').click();
            await page.waitForFunction(() => document.getElementById('balanceList').textContent.includes('El cliente debe envases'));
            assert.equal(writes[0].cantidad, -30);
            assert.equal(writes[0].tipo_movimiento, 'apertura_cuenta');
            assert.equal(writes[0].temporada_id, 'temporada');
            await page.locator('#container-accounts-tab-movements').click();
            await page.locator('#movementsBody .office-action-select').selectOption({ label: 'Revertir asiento' });
            await form.locator('[name="motivo"]').fill('Reversa por conciliación equivocada');
            await form.locator('[type="submit"]').click();
            await page.waitForFunction(() => !document.getElementById('accountAdjustmentDialog').open);
            assert.equal(writes.length, 2);
            assert.equal(writes[1].fecha, '2026-10-21');
            assert.notEqual(writes[0].operacion_id, writes[1].operacion_id);
            assert.deepEqual(errors, []);
            await context.close();
        }
    } finally { await browser.close(); }
});

test('el perfil de consulta no ofrece acciones para modificar la cuenta', async () => {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
    try {
        const { context, page, writes, errors } = await setup(browser, false);
        assert.equal(await page.locator('#accountAdjustmentNew').isVisible(), false);
        assert.equal(await page.locator('[data-reverse-adjustment]').count(), 0);
        assert.equal(writes.length, 0);
        assert.deepEqual(errors, []);
        await context.close();
    } finally { await browser.close(); }
});


test('corrige +138 a -138 con vista previa de -276 y conserva la existencia en móvil y escritorio', async () => {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
    try {
        for (const width of [390, 1280]) {
            const { context, page, writes, errors } = await setup(browser, true, width, true);
            await page.locator('#container-accounts-tab-movements').click();
            await page.locator('#movementsBody .office-action-select').selectOption({ label: 'Corregir saldo anterior' });
            const form = page.locator('#historicalBalanceForm');
            await form.locator('[name="sentido"]').selectOption('-1');
            assert.match(await page.locator('#historicalBalancePreview').textContent(), /138 → -138/);
            assert.match(await page.locator('#historicalBalancePreview').textContent(), /-276/);
            assert.match(await page.locator('#historicalBalancePreview').textContent(), /-306 → -582/);
            await form.locator('[name="es_saldo_historico"]').check();
            await form.locator('[name="motivo"]').fill('Saldo anterior adeudado por el cliente con signo equivocado');
            await form.locator('[type="submit"]').click();
            await page.waitForFunction(() => !document.getElementById('historicalBalanceDialog').open);
            assert.equal(writes.length, 1);
            assert.equal(writes[0].impacto_conocido, 138);
            assert.equal(writes[0].saldo_correcto, -138);
            assert.equal(writes[0].es_saldo_historico, true);
            assert.match(await page.locator('#movementsBody').textContent(), /138/);
            assert.deepEqual(errors, []);
            await context.close();
        }
    } finally { await browser.close(); }
});
