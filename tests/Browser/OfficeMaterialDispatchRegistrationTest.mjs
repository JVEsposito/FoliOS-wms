import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
const html = readFileSync(resolve(root, 'storage/app/ui/oficina/despachos.html'), 'utf8');
const season = { id: 'season', codigo: 'TEMP-2627', nombre: 'Temporada', activa: true };
const client = { id: 'client', codigo: 'CLI', nombre: 'Cliente', activo: true, temporada: season };
const item = { id: 'item', cliente: client, codigo: 'FILM', nombre: 'Film', unidad_medida: 'kg', activo: true };

async function fixture(browser, { width = 1366, canAuthorize = true } = {}) {
    const context = await browser.newContext({ viewport: { width, height: 950 } });
    const page = await context.newPage(); const errors = []; const writes = []; const queries = [];
    page.on('pageerror', error => errors.push(error.message));
    const delivery = { id: 'delivery', version: 1, provisional: true, retroactivo: true, estado: 'vencida', fecha_despacho: '2026-10-10T13:30:00Z', registrado_at: '2026-10-12T16:00:00Z', fecha_limite: '2026-10-16T02:59:59Z', motivo: 'Respaldo pendiente', autorizado_at: '2026-10-12T16:00:00Z', autorizado_por: { name: 'Jefa de bodega' }, motivo_autorizacion: 'Conteo verificado', registrado_por: { name: 'Supervisión' }, retiros: [{ id: 'withdrawal', numero_folio: 'FABC0000001', cantidad_retirada: '4.000' }] };
    const dispatch = { id: 'dispatch', codigo: 'MAT-DES-000001', estado: 'completado', modalidad: 'directo', destino: { id: 'destination', nombre: 'Packing', centro_costo: 'PACK' }, created_at: '2026-10-12T16:00:00Z', creado_por: { nombre: 'Supervisión' }, entregas: [delivery], items: [{ item, cantidad_solicitada: '4.000', cantidad_despachada: '4.000', cantidad_reservada: '0.000', unidad_medida: 'kg', retiros: [] }] };
    await context.addInitScript(({ canAuthorize, width }) => {
        localStorage.setItem('estiba_wms_office_token', 'test-token');
        localStorage.setItem('estiba_wms_office_theme', width === 390 ? 'dark-industrial' : 'light-professional');
        localStorage.setItem('estiba_wms_office_identity', JSON.stringify({ nombre: 'Supervisión', rol: 'supervisor_materiales', puede_consultar_despachos_materiales: true, puede_gestionar_despachos_materiales: true, puede_retirar_materiales: true, puede_autorizar_despachos_tardios_materiales: canAuthorize, modulos_acceso: ['materiales.despachos'] }));
    }, { canAuthorize, width });
    await context.route('http://localhost/**', async route => {
        const request = route.request(); const url = new URL(request.url()); const path = url.pathname;
        if (path.startsWith('/build/')) return route.fulfill({ body: readFileSync(resolve(root, 'public' + path)), contentType: path.endsWith('.js') ? 'text/javascript' : 'text/css' });
        if (path === '/oficina/materiales/despachos') return route.fulfill({ body: html, contentType: 'text/html' });
        if (path === '/api/oficina/contexto') return route.fulfill({ json: { data: { temporada: season, planta: 'Planta de prueba', avisos_cierre: [], avisos_materiales: [] } } });
        if (path === '/api/materiales/reposicion/resumen') return route.fulfill({ json: { data: { quiebre: 0, bajo_minimo: 0 } } });
        if (path === '/api/materiales/catalogo') return route.fulfill({ json: { temporada: season, clientes: [client], items: [item], destinos: [{ id: 'destination', nombre: 'Packing', centro_costo: 'PACK', activo: true }] } });
        if (path === '/api/materiales/inventario') return route.fulfill({ json: { data: [], resumen_clientes: [], resumen_items: [], resumen: {}, meta: { total: 0 } } });
        if (path === '/api/materiales/despachos/camareros') return route.fulfill({ json: { data: [] } });
        if (path === '/api/materiales/despachos') { queries.push(url.search); return route.fulfill({ json: { data: [dispatch], regularizacion: { pendientes: delivery.estado === 'regularizada' ? 0 : 1, vencidas: delivery.estado === 'vencida' ? 1 : 0 } } }); }
        if (path === '/api/materiales/despachos/dispatch') return route.fulfill({ json: { data: dispatch } });
        if (path.endsWith('/regularizar')) {
            writes.push(request.postDataJSON());
            if (writes.length === 1) return route.fulfill({ status: 503, json: { message: 'Respuesta perdida; reintenta.' } });
            const data = request.postDataJSON();
            Object.assign(delivery, { estado: 'regularizada', version: 2, numero_documento: data.numero_documento, fecha_documento: data.fecha_documento, regularizada_fuera_plazo: true, regularizaciones: [{ id: data.operacion_id, usuario: { name: 'Jefa de bodega' }, motivo: data.motivo, ocurrido_at: new Date().toISOString(), fuera_plazo: true, antes: { numero_documento: null }, despues: { numero_documento: data.numero_documento, fecha_documento: data.fecha_documento } }] });
            return route.fulfill({ json: { data: dispatch } });
        }
        if (request.method() === 'POST') assert.fail(`Escritura física inesperada: ${path}`);
        return route.fulfill({ status: 404 });
    });
    await page.goto('http://localhost/oficina/materiales/despachos');
    await page.locator('[data-trace-dispatch="dispatch"]').waitFor();
    return { context, page, errors, writes, queries };
}

test('Oficina regulariza una entrega vencida, conserva el UUID tras un error y muestra la auditoría en PC y PDA', async () => {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
    try {
        for (const width of [1366, 390]) {
            const { context, page, errors, writes, queries } = await fixture(browser, { width });
            await page.locator('#dispatchRegularizationFilter').selectOption('vencida');
            await page.waitForFunction(() => document.querySelector('#dispatchRegularizationAlerts').textContent.includes('1 vencidas'));
            await page.locator('[data-trace-dispatch="dispatch"]').click();
            const form = page.locator('[data-regularize-dispatch="delivery"]');
            await form.locator('[name="numero_documento"]').fill('VALE-901');
            await form.locator('[name="fecha_documento"]').fill('2026-10-10');
            await form.locator('[name="motivo"]').fill('Respaldo recibido y cotejado con la entrega');
            await form.locator('[name="aceptar_fuera_plazo"]').check();
            await form.locator('[type="submit"]').click();
            await form.locator('[data-regularization-error]').filter({ hasText: 'Respuesta perdida' }).waitFor();
            await form.locator('[type="submit"]').click();
            await page.waitForFunction(() => document.querySelector('#dispatchTraceBody').textContent.includes('Documento resultante: VALE-901'));
            assert.equal(writes.length, 2);
            assert.deepEqual(writes[0], writes[1]);
            assert.equal(writes[0].aceptar_fuera_plazo, true);
            assert.equal(Object.hasOwn(writes[0], 'retiros'), false);
            assert.ok(queries.some(query => query.includes('regularizacion=vencida')));
            assert.match(await page.locator('#dispatchTraceBody').textContent(), /Jefa de bodega/);
            assert.match(await page.locator('#dispatchTraceBody').textContent(), /FABC0000001/);
            assert.equal(await page.locator('[data-regularize-dispatch]').count(), 0);
            await page.screenshot({ path: resolve(root, `storage/app/ui/oficina/pr9-despacho-regularizado-${width}.png`), fullPage: true });
            assert.deepEqual(errors, []);
            await context.close();
        }
    } finally { await browser.close(); }
});

test('el lector consulta las fechas y la autorización sin recibir controles de regularización', async () => {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
    try {
        const { context, page, errors } = await fixture(browser, { canAuthorize: false });
        await page.locator('[data-trace-dispatch="dispatch"]').click();
        await page.waitForFunction(() => document.querySelector('#dispatchTraceBody').textContent.includes('Entrega física'));
        assert.equal(await page.locator('[data-regularize-dispatch]').count(), 0);
        assert.equal(await page.locator('[data-dispatch-authorization]').first().isVisible(), false);
        assert.match(await page.locator('#dispatchTraceBody').textContent(), /Conteo verificado/);
        assert.deepEqual(errors, []);
        await context.close();
    } finally { await browser.close(); }
});
