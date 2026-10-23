import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve, extname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
const html = readFileSync(resolve(root, 'storage/app/ui/oficina/pendientes.html'), 'utf8');
const base = '/api/validacion/pendientes-origen/conciliaciones';
const id = '00000000-0000-4000-8000-000000000001';
const comparison = Object.fromEntries([
    ['cajas', 'Cajas', 240, 236], ['marca', 'Marca', 'Marca B', 'Marca A'],
    ['calibre', 'Calibre', '3J', '2J'], ['csg', 'Cajas por CSG', { '001567': 240 }, { '001234': 236 }],
].map(([campo, etiqueta, planta, validado]) => [campo, { campo, etiqueta, planta, validado, planta_homologada: planta, estado: 'diferente', diferencia: campo === 'cajas' ? -4 : null }]));
const fixture = { id, numero_folio: '000123', estado: 'pendiente', contexto_actual: 'vigente', version: 1,
    gestion_habilitada: true, comprobacion_vigente: true, validado_at: '2026-10-23T15:00:00Z', lectura_at: '2026-10-23T15:01:00Z', captura: 'registro_servidor',
    validacion_inicial: { usuario: 'Validador prueba', datos: { cajas: 236, marca: 'Marca A', calibre: '2J', csg: { '001234': 236 } } },
    planta_inicial: { cajas: 240, marca: 'Marca B', calibre: '3J', csg: { '001567': 240 }, leida_at: '2026-10-23T15:01:00Z' }, comparacion: comparison };
const pagination = (data) => ({ data, current_page: 1, last_page: 1, total: data.length });

async function setup(browser, { consulta = false, lost = false, conflict = false, width = 1280, background = false } = {}) {
    const context = await browser.newContext({ viewport: { width, height: 850 } });
    const identity = { nombre: 'Oficina prueba', rol: consulta ? 'consulta' : 'supervisor_frio', puede_consultar_validaciones_pallet: true, modulos_acceso: ['frigorifico.validacion'] };
    await context.addInitScript((user) => { localStorage.setItem('estiba_wms_office_token', 'fixture'); localStorage.setItem('estiba_wms_office_identity', JSON.stringify(user)); }, identity);
    const requests = []; const writes = []; const integration = { posts: 0, polls: 0, done: false, reads: 0 }; let current = structuredClone(fixture); let events = [];
    await context.route('http://localhost/**', async (route) => {
        const request = route.request(); const url = new URL(request.url()); const path = url.pathname;
        if (path === '/oficina/validacion/pendientes') return route.fulfill({ body: html, contentType: 'text/html' });
        if (path.startsWith('/build/') || path.startsWith('/brand/')) {
            try { return route.fulfill({ body: readFileSync(resolve(root, `public${path}`)), contentType: ({ '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml', '.woff2': 'font/woff2' })[extname(path)] || 'application/octet-stream' }); }
            catch { return route.fulfill({ status: 404 }); }
        }
        if (path === '/api/acceso-oficina') return route.fulfill({ json: { usuario: identity } });
        if (path === '/api/oficina/contexto') return route.fulfill({ json: { data: { planta: 'Planta prueba', temporada: null, avisos_cierre: [], avisos_materiales: [] } } });
        if (background && path === '/api/validacion/pendientes-origen/leer') {
            integration.posts++;
            assert.equal(request.headers().prefer, 'respond-async');
            return route.fulfill({ status: 202, json: { operacion: { id, estado: 'en_cola', fase: 'Esperando integración' } } });
        }
        if (background && path === `/api/integraciones/operaciones/${id}`) {
            integration.polls++;
            assert.equal(request.method(), 'GET');
            return route.fulfill({ json: { operacion: integration.done
                ? { id, estado: 'correcta', fase: 'Finalizada', respuesta: { codigo_http: 200, contenido: { message: 'Stock actualizado fixture' } } }
                : { id, estado: 'en_curso', fase: 'Consultando planta fixture' } } });
        }
        if (path === '/api/validacion/pendientes-origen') { integration.reads++; return route.fulfill({ json: {
            fuente: { habilitada: true, conexion: 'Planta', tabla: 'odbc_stock', bodega: 'AR01', temporada_catalogo: 'Prueba' },
            lectura: { ultima: null, ultima_correcta_at: '2026-10-23T15:01:00Z', desactualizada: false, vigencia_minutos: 20 },
            resumen: { en_origen: 0, validados: 0, pendientes: 0, cajas_pendientes: 0, por_resolver: 0, con_diferencias: 0, solo_en_folios: 0, catalogo_pendiente: 0, pallets_sin_catalogo: 0 },
            pendientes: [], por_resolver: [], diferencias: [], solo_en_folios: [], catalogo: [], puede_administrar_catalogo: false,
        } }); }
        if (path === base) { requests.push(url.searchParams); return route.fulfill({ json: { casos: pagination([current]), resumen: { pendiente: 1 }, fuente: { vigente: true }, puede_gestionar: !consulta } }); }
        if (path === `${base}/excel`) { requests.push(url.searchParams); return route.fulfill({ body: 'fixture', contentType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' }); }
        if (path === `${base}/${id}`) return route.fulfill({ json: { ...current, historial: pagination(events) } });
        if (path === `${base}/${id}/gestiones`) {
            const body = request.postDataJSON(); writes.push(body);
            if (conflict) return route.fulfill({ status: 409, json: { message: 'La conciliación cambió. Recarga antes de registrar la gestión.' } });
            if (writes.length === 1) {
                current = { ...current, estado: 'en_revision', version: 2 };
                events = [{ tipo: 'gestionada', usuario_nombre: 'Oficina prueba', created_at: '2026-10-23T15:03:00Z', comentario: body.comentario, referencia: body.referencia, datos: {} }];
                if (lost) return route.abort('connectionreset');
            }
            return route.fulfill({ json: current });
        }
        return route.fulfill({ status: 404, json: { message: 'No disponible en fixture' } });
    });
    const page = await context.newPage(); const errors = []; page.on('pageerror', (error) => errors.push(error.message));
    await page.goto('http://localhost/oficina/validacion/pendientes');
    await page.locator('#reconciliationBody [data-reconciliation]').waitFor({ state: 'attached' });
    await page.locator('#pending-validation-tab-differences').click();
    return { context, page, requests, writes, errors, integration };
}

async function launch() {
    return chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
}

test('cuadraturas muestra cuatro diferencias, filtra y conserva el mismo envío tras perder respuesta', async () => {
    const browser = await launch();
    try {
        const { page, context, requests, writes, errors } = await setup(browser, { lost: true });
        assert.equal(await page.locator('#reconciliationBody .pending-differences li').count(), 4);
        assert.match(await page.locator('#reconciliationBody').innerText(), /-4 cajas/);
        await page.locator('#reconciliationFilters [name="folio"]').fill('000123');
        await page.locator('#reconciliationFilters [name="campo"]').selectOption('csg');
        const filtered = page.waitForResponse((r) => new URL(r.url()).pathname === base && r.url().includes('campo=csg'));
        await page.locator('#reconciliationFilters button').click(); await filtered;
        assert.equal(requests.at(-1).get('folio'), '000123');
        await page.locator('[data-reconciliation]').click(); await page.locator('#reconciliationDialog[open]').waitFor();
        await page.locator('#reconciliationDetail summary').click();
        assert.match(await page.locator('#reconciliationDetail').innerText(), /001234/);
        await page.locator('#reconciliationManagement [name="accion"]').selectOption('gestionada');
        await page.locator('#reconciliationManagement [name="comentario"]').fill('Corrección realizada en sistema planta');
        await page.locator('#reconciliationManagement [name="referencia"]').fill('Ticket 000123');
        await page.locator('#reconciliationManagement button').click();
        await page.locator('#reconciliationRetry:not([disabled])').waitFor();
        assert.equal(await page.locator('#reconciliationManagement textarea').isDisabled(), true);
        await page.locator('#reconciliationClose').click(); assert.equal(await page.locator('#reconciliationDialog').evaluate((e) => e.open), true);
        await page.locator('#reconciliationRetry').click();
        await page.waitForFunction(() => document.querySelector('#reconciliationTitle').textContent.includes('En revisión'));
        assert.equal(writes.length, 2); assert.deepEqual(writes[0], writes[1]); assert.ok(writes[0].operacion_id);
        assert.equal(await page.locator('#reconciliationHistory article').count(), 1);
        assert.equal(await page.locator('#reconciliationPending').isVisible(), false);
        assert.deepEqual(errors, []);
        await page.locator('#reconciliationClose').click();
        const exported = page.waitForResponse((r) => new URL(r.url()).pathname === `${base}/excel`);
        await page.locator('#reconciliationExcel').click(); await exported;
        assert.equal(requests.at(-1).get('campo'), 'csg'); assert.equal(requests.at(-1).get('folio'), '000123');
        await context.close();
    } finally { await browser.close(); }
});

test('consulta revisa el respaldo en móvil sin acciones de gestión ni desbordamiento', async () => {
    const browser = await launch();
    try {
        const { page, context, writes, errors } = await setup(browser, { consulta: true, width: 390 });
        assert.equal(await page.locator('#reconciliationCheck').isVisible(), false);
        await page.locator('[data-reconciliation]').click(); await page.locator('#reconciliationDialog[open]').waitFor();
        assert.equal(await page.locator('#reconciliationManagement').isVisible(), false);
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
        assert.deepEqual(writes, []); assert.deepEqual(errors, []); await context.close();
    } finally { await browser.close(); }
});

test('una versión obsoleta conserva el comentario y no registra automáticamente otra gestión', async () => {
    const browser = await launch();
    try {
        const { page, context, writes } = await setup(browser, { conflict: true });
        await page.locator('[data-reconciliation]').click(); await page.locator('#reconciliationDialog[open]').waitFor();
        await page.locator('#reconciliationManagement textarea').fill('Revisar cuadratura con nueva lectura');
        await page.locator('#reconciliationManagement button').click();
        await page.waitForFunction(() => document.querySelector('#reconciliationDetailNotice').textContent.includes('La conciliación cambió'));
        assert.equal(await page.locator('#reconciliationManagement textarea').inputValue(), 'Revisar cuadratura con nueva lectura');
        assert.equal(await page.locator('#reconciliationManagement textarea').isDisabled(), false);
        assert.equal(writes.length, 1); assert.equal(await page.locator('#reconciliationPending').isVisible(), false);
        await context.close();
    } finally { await browser.close(); }
});


test('Leer ahora espera en segundo plano y mantiene búsquedas y cuadraturas disponibles', async () => {
    const browser = await launch();
    try {
        const { page, context, integration, errors } = await setup(browser, { background: true });
        await page.locator('#pendingReadButton').click();
        await page.waitForFunction(() => document.getElementById('pendingSyncStatus').textContent.includes('Consultando planta fixture'));
        assert.equal(await page.locator('#officeLoading').isVisible(), false);
        await page.locator('#pendingSearch').fill('000123');
        assert.equal(await page.locator('#pendingSearch').inputValue(), '000123');
        assert.equal(await page.locator('[data-reconciliation]').isEnabled(), true);
        assert.equal(await page.locator('#pendingReadButton').isDisabled(), true);
        integration.done = true;
        await page.waitForFunction(() => document.getElementById('pendingSyncStatus').textContent.includes('Stock actualizado fixture'));
        await page.waitForFunction(() => !document.getElementById('pendingReadButton').disabled);
        assert.equal(integration.posts, 1);
        assert.ok(integration.polls >= 2);
        assert.ok(integration.reads >= 2);
        assert.deepEqual(errors, []);
        await context.close();
    } finally { await browser.close(); }
});
