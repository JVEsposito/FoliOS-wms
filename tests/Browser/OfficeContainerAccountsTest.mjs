import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve, extname } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
const html = readFileSync(resolve(root, 'storage/app/ui/oficina/cuenta-corriente.html'), 'utf8');

async function setup(browser, manage, width = 390, historical = false, reconciliation = {}) {
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
    const reconciliationCalls = { searches: [], previews: [], applies: [], refreshes: 0 };
    let reconciled = false;
    const pair = { recepcion_id: 'recepcion-1', guia_id: 'guia-1', cliente: client.nombre, recepcion: 'REC-2627-0011', guia_ingreso: '11893', despacho: 'GDE-2627-0001', guia_salida: reconciliation.different ? '2235' : '2236', guia_salida_romana: reconciliation.different ? '2234' : '002.236' };
    const audit = [{ responsable: identity.nombre, motivo: 'Respaldo físico revisado con la guía 2236', conservar: 'despacho', fecha: '2026-11-06T15:00:00Z' }];
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
        if (path.endsWith('/conciliaciones-salida')) {
            const query = Object.fromEntries(new URL(request.url()).searchParams);
            reconciliationCalls.searches.push(query);
            const empty = reconciled || (reconciliation.different && query.coincidencia !== 'manual');
            return route.fulfill({ json: { data: empty ? [] : [pair], meta: { page: Number(query.page || 1), last_page: 2, total: empty ? 0 : 16 } } });
        }
        if (path.endsWith('/conciliaciones-salida/previsualizar')) {
            const body = request.postDataJSON();
            reconciliationCalls.previews.push(body);
            return route.fulfill({ json: { data: { aplicada: reconciled, documentos: { ...pair, despacho: 'GDE-2627-0001 · Revisado' }, hash: reconciled ? null : String(reconciliationCalls.previews.length).repeat(64), compensaciones: reconciled ? 2 : 0, auditoria: reconciled ? audit : [],
                filas: [{ envase: 'Bins plástico', propiedad: 'Arrendada', romana: 42, despacho: 42, diferencia: 0, conservar: 42, compensacion: 42 },
                    { envase: 'Tote', propiedad: 'Arrendada', romana: 1002, despacho: 1009, diferencia: 7, conservar: body.conservar === 'romana' ? 1002 : 1009, compensacion: body.conservar === 'romana' ? 1009 : 1002 },
                    { envase: 'Esponja de bins', propiedad: 'De la planta', romana: 0, despacho: 14, diferencia: 14, conservar: 14, compensacion: 0 }] } } });
        }
        if (path.endsWith('/conciliaciones-salida/aplicar')) {
            reconciliationCalls.applies.push(request.postDataJSON());
            if (reconciliation.stale && reconciliationCalls.applies.length === 1) return route.fulfill({ status: 409, json: { message: 'Los datos cambiaron desde la previsualización.' } });
            reconciled = true;
            if (reconciliation.lostResponse) return route.abort('failed');
            return route.fulfill({ json: { data: { aplicada: true, hash: null, filas: [], compensaciones: 2, auditoria: audit } } });
        }
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
        if (path.endsWith('/cuenta-corriente/movimientos')) {
            reconciliationCalls.refreshes++;
            return route.fulfill({ json: {
            data: movements, pendientes: [], reservas: [], balances: [{ cliente: client, tipo_envase: 'bins', saldo: balance }],
            existencia: { tipos: [], propios_entregados: [] }, resumen: { movimientos_confirmados: movements.length, envases_reservados: 0, lineas_pendientes_validacion: 0, observados: 0, sincronizado_at: '2026-10-21T15:00:00Z' },
            } });
        }
        return route.fulfill({ json: {} });
    });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await page.goto('http://localhost/oficina/envases/cuenta-corriente');
    await page.waitForFunction(() => document.getElementById('balanceList')?.textContent.includes('Cliente de arriendo'));
    return { context, page, writes, errors, reconciliationCalls };
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
        assert.equal(await page.locator('#duplicateReconciliationOpen').isVisible(), false);
        await page.locator('.accounts-heading [data-reconciliation-guide]').click();
        assert.equal(await page.locator('#reconciliationGuideDialog').isVisible(), true);
        assert.match(await page.locator('#reconciliationGuideDialog').textContent(), /1.002 totes.*1.009/s);
        await page.locator('#reconciliationGuideClose').click();
        assert.equal(await page.locator('[data-reverse-adjustment]').count(), 0);
        assert.equal(writes.length, 0);
        assert.deepEqual(errors, []);
        await context.close();
    } finally { await browser.close(); }
});

test('previsualiza y confirma duplicados con fuente explícita, sin perder esponjas ni duplicar el envío', async () => {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
    try {
        for (const width of [390, 1280]) {
            const { context, page, reconciliationCalls: calls, errors } = await setup(browser, true, width);
            await page.locator('#filtersForm [name="cliente_id"]').selectOption('cliente');
            await page.locator('#duplicateReconciliationOpen').click();
            await page.locator('#duplicateSearchForm [name="buscar"]').fill('11893');
            assert.equal(await page.locator('#duplicateCandidates [data-duplicate-pair]').count(), 0);
            await page.locator('#duplicateSearchForm [type="submit"]').click();
            await page.locator('#duplicateCandidates [data-duplicate-pair]').click();
            assert.equal(calls.searches[0].cliente_id, 'cliente');
            assert.equal(calls.searches[0].temporada_id, 'temporada');
            assert.equal(calls.searches.at(-1).buscar, '11893');
            assert.equal(calls.previews.length, 0);
            assert.equal(await page.locator('#duplicatePreviewForm [name="conservar"]').inputValue(), '');
            await page.locator('#duplicatePreviewForm [name="conservar"]').selectOption('romana');
            await page.locator('#duplicatePreviewForm [type="submit"]').click();
            await page.locator('#duplicateComparison').waitFor({ state: 'visible' });
            assert.match(await page.locator('#duplicatePairTitle').textContent(), /Revisado/);
            assert.match(await page.locator('#duplicateComparisonBody').textContent(), /1.002/);
            const apply = page.locator('#duplicateApplyForm');
            assert.equal(await apply.locator('[type="submit"]').isDisabled(), true);
            await apply.locator('[name="motivo"]').fill('Respaldo físico revisado con la guía 2236');
            await apply.locator('[name="cantidades_verificadas"]').check();
            assert.equal(await apply.locator('[type="submit"]').isEnabled(), true);
            // Cambiar la fuente invalida la comparación y su confirmación.
            await page.locator('#duplicatePreviewForm [name="conservar"]').selectOption('despacho');
            assert.equal(await page.locator('#duplicateComparison').isVisible(), false);
            assert.equal(await apply.isVisible(), false);
            await page.locator('#duplicatePreviewForm [type="submit"]').click();
            await apply.waitFor({ state: 'visible' });
            assert.equal(await apply.locator('[name="cantidades_verificadas"]').isChecked(), false);
            await apply.locator('[name="cantidades_verificadas"]').check();
            assert.equal(await page.locator('#duplicateReconciliationDialog').evaluate((dialog) => dialog.scrollWidth <= dialog.clientWidth + 1), true);
            if (process.env.WMS_RECONCILIATION_SCREENSHOTS) await page.screenshot({ path: `${process.env.WMS_RECONCILIATION_SCREENSHOTS}/conciliacion-${width}.png` });
            await apply.locator('[type="submit"]').dblclick();
            await page.locator('#duplicateResult').waitFor({ state: 'visible' });
            assert.equal(calls.applies.length, 1);
            assert.equal(calls.applies[0].conservar, 'despacho');
            assert.equal(calls.applies[0].hash, calls.previews.length.toString().repeat(64));
            assert.equal(calls.applies[0].cantidades_verificadas, true);
            assert.match(await page.locator('#duplicateResult').textContent(), /Cuenta Envases/);
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
            assert.deepEqual(errors, []);
            await context.close();
        }
    } finally { await browser.close(); }
});

test('un conflicto conserva el motivo y exige nueva comparación y confirmación', async () => {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
    try {
        const { context, page, reconciliationCalls: calls, errors } = await setup(browser, true, 390, false, { stale: true });
        await page.locator('#duplicateReconciliationOpen').click();
        await page.locator('#duplicateNext').click();
        await page.waitForFunction(() => document.getElementById('duplicatePage').textContent.includes('Página 2'));
        assert.equal(calls.searches.at(-1).page, '2');
        await page.locator('#duplicateCandidates [data-duplicate-pair]').click();
        await page.locator('#duplicatePreviewForm [name="conservar"]').selectOption('romana');
        await page.locator('#duplicatePreviewForm [type="submit"]').click();
        const apply = page.locator('#duplicateApplyForm');
        await apply.waitFor({ state: 'visible' });
        await apply.locator('[name="motivo"]').fill('Respaldo físico revisado con la guía 2236');
        await apply.locator('[name="cantidades_verificadas"]').check();
        await apply.locator('[type="submit"]').click();
        await page.waitForFunction(() => document.getElementById('duplicateError').textContent.includes('Vuelve a previsualizar'));
        assert.equal(await apply.isVisible(), false);
        await page.locator('#duplicatePreviewForm [type="submit"]').click();
        await apply.waitFor({ state: 'visible' });
        assert.equal(await apply.locator('[name="motivo"]').inputValue(), 'Respaldo físico revisado con la guía 2236');
        assert.equal(await apply.locator('[name="cantidades_verificadas"]').isChecked(), false);
        await apply.locator('[name="cantidades_verificadas"]').check();
        await apply.locator('[type="submit"]').click();
        await page.locator('#duplicateResult').waitFor({ state: 'visible' });
        assert.equal(calls.applies.length, 2);
        assert.notEqual(calls.applies[0].hash, calls.applies[1].hash);
        assert.deepEqual(errors, []);
        await context.close();
    } finally { await browser.close(); }
});

test('compara 2235 y 2234 manualmente y exige verificar ambos respaldos antes de previsualizar', async () => {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
    try {
        for (const width of [390, 1280]) {
            const { context, page, reconciliationCalls: calls, errors } = await setup(browser, true, width, false, { different: true });
            await page.locator('#duplicateReconciliationOpen').click();
            await page.waitForFunction(() => document.getElementById('duplicateCandidates').textContent.includes('No hay coincidencias'));
            const search = page.locator('#duplicateSearchForm');
            await search.locator('[name="coincidencia"]').selectOption('manual');
            assert.equal(await search.locator('[name="cliente_id"]').getAttribute('required'), '');
            assert.equal(await search.locator('[name="buscar"]').getAttribute('required'), '');
            await search.locator('[name="cliente_id"]').selectOption('cliente');
            await search.locator('[name="buscar"]').fill('2235');
            await search.locator('[type="submit"]').click();
            await page.locator('#duplicateCandidates [data-duplicate-pair]').click();
            assert.equal(calls.searches.at(-1).coincidencia, 'manual');
            assert.equal(calls.searches.at(-1).cliente_id, 'cliente');
            assert.match(await page.locator('#duplicatePairDocuments').textContent(), /Salida Romana 2234 · Salida Despacho 2235/);
            const preview = page.locator('#duplicatePreviewForm');
            await preview.locator('[name="conservar"]').selectOption('romana');
            await preview.locator('[type="submit"]').click();
            assert.equal(calls.previews.length, 0);
            assert.equal(await page.locator('#duplicateDifferentGuides').isVisible(), true);
            await preview.locator('[name="guias_distintas_verificadas"]').check();
            await preview.locator('[type="submit"]').click();
            const apply = page.locator('#duplicateApplyForm');
            await apply.waitFor({ state: 'visible' });
            assert.equal(calls.previews[0].guias_distintas_verificadas, true);
            await apply.locator('[name="motivo"]').fill('Verificados los respaldos 2235 y 2234: misma salida física');
            await apply.locator('[name="cantidades_verificadas"]').check();
            await preview.locator('[name="guias_distintas_verificadas"]').uncheck();
            assert.equal(await apply.isVisible(), false);
            await preview.locator('[name="guias_distintas_verificadas"]').check();
            await preview.locator('[type="submit"]').click();
            await apply.waitFor({ state: 'visible' });
            await apply.locator('[name="cantidades_verificadas"]').check();
            await apply.locator('[type="submit"]').click();
            await page.locator('#duplicateResult').waitFor({ state: 'visible' });
            assert.equal(calls.applies.length, 1);
            assert.equal(calls.applies[0].guias_distintas_verificadas, true);
            assert.equal(await page.locator('#duplicateReconciliationDialog').evaluate((dialog) => dialog.scrollWidth <= dialog.clientWidth + 1), true);
            assert.deepEqual(errors, []);
            await context.close();
        }
    } finally { await browser.close(); }
});

test('una respuesta perdida se recupera mostrando la conciliación aplicada sin otro envío', async () => {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
    try {
        const { context, page, reconciliationCalls: calls, errors } = await setup(browser, true, 390, false, { lostResponse: true });
        await page.locator('#duplicateReconciliationOpen').click();
        await page.locator('#duplicateCandidates [data-duplicate-pair]').click();
        await page.locator('#duplicatePreviewForm [name="conservar"]').selectOption('despacho');
        await page.locator('#duplicatePreviewForm [type="submit"]').click();
        const apply = page.locator('#duplicateApplyForm');
        await apply.waitFor({ state: 'visible' });
        await apply.locator('[name="motivo"]').fill('Respaldo físico revisado con la guía 2236');
        await apply.locator('[name="cantidades_verificadas"]').check();
        await apply.locator('[type="submit"]').click();
        await page.waitForFunction(() => document.getElementById('duplicateError').textContent.includes('Vuelve a previsualizar'));
        await page.locator('#duplicatePreviewForm [type="submit"]').click();
        await page.locator('#duplicateResult').waitFor({ state: 'visible' });
        assert.equal(calls.applies.length, 1);
        await page.waitForFunction(() => document.getElementById('officeLoading').classList.contains('is-hidden'));
        assert.equal(calls.refreshes, 2);
        assert.equal(await apply.isVisible(), false);
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
