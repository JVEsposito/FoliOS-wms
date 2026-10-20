import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve, extname } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
const html = readFileSync(resolve(root, 'storage/app/ui/oficina/tarifario.html'), 'utf8');
const base = { clave: 'estadia', nombre: 'Estadía en cámara', fuente: 'estadia', fuente_nombre: 'Estadía en cámara', base: true, por_dia: true, dia_desde: 1, dia_hasta: null, vigente_desde: null, vigente_hasta: null, unidades: [{ clave: 'bultos', nombre: 'por bulto y día' }, { clave: 'cajas', nombre: 'por caja y día' }] };
const launch = () => chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });

async function setup(browser, { manage = true, reject = false, width = 1280, hr = false } = {}) {
    const context = await browser.newContext({ viewport: { width, height: 1000 } });
    const identity = { nombre: 'Finanzas Prueba', rol: manage ? 'administrador' : 'consulta', puede_consultar_finanzas: true, modulos_acceso: ['finanzas.prefactura'] };
    await context.addInitScript((user) => {
        localStorage.setItem('estiba_wms_office_token', 'token_finanzas_fixture');
        localStorage.setItem('estiba_wms_office_identity', JSON.stringify(user));
    }, identity);
    let services = hr ? [base, { ...base, clave: 'svc_rrhh', nombre: 'Reserva de cuadrilla', fuente: 'declarado', base: false, por_dia: false, unidad_libre: 'hora persona', unidades: [{ clave: 'cantidad', nombre: 'por hora persona' }] }] : [base];
    const writes = [];
    await context.route('http://localhost/**', async (route) => {
        const request = route.request();
        const path = new URL(request.url()).pathname;
        if (path === '/oficina/finanzas/tarifario') return route.fulfill({ body: html, contentType: 'text/html' });
        if (path.startsWith('/build/') || path.startsWith('/brand/folios/')) {
            try { return route.fulfill({ body: readFileSync(resolve(root, `public${path}`)), contentType: ({ '.js': 'text/javascript', '.css': 'text/css', '.woff2': 'font/woff2', '.svg': 'image/svg+xml' })[extname(path)] || 'application/octet-stream' }); }
            catch { return route.fulfill({ status: 404, body: '' }); }
        }
        if (path === '/api/finanzas/dotacion') return route.fulfill({ json: { data: { data: [{ id: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', cliente_id: 'cliente', cliente_nombre: 'Cliente dotación', cargo: 'Camarero', area: 'Frigorífico', ceco: 'FRIO', desde_at: '2026-11-02T01:00:00Z', hasta_at: '2026-11-02T09:00:00Z', horas_programadas: '8', jornadas_programadas: '1', horas_asistidas: '0', jornadas_asistidas: '0', asistencia_valida: false, turno_finalizado: true, incidencias: ['sin_marcajes'], estado: 'cancelada', cargo_actual: null }], current_page: 1, last_page: 1 } } });
        if (path === '/api/finanzas/dotacion/cargos' && request.method() === 'POST') { writes.push(request.postDataJSON()); return route.fulfill({ status: 201, json: { data: { id: 'cargo-rrhh' } } }); }
        if (path === '/api/finanzas/cargos') return route.fulfill({ json: { data: { data: [], current_page: 1, last_page: 1, prev_page_url: null, next_page_url: null } } });
        if (path === '/api/acceso-oficina') return route.fulfill({ json: { usuario: identity } });
        if (path === '/api/oficina/contexto') return route.fulfill({ json: { data: { planta: 'Planta Prueba', temporada: { nombre: '2026-2027' } } } });
        if (path === '/api/finanzas/tarifas' && request.method() === 'GET') return route.fulfill({ json: {
            data: [], resumen: {}, hoy: '2026-11-01', servicios: services, puede_gestionar: manage, puede_usar_rrhh: hr, clientes: [],
            procesos: [{ clave: 'estadia', nombre: 'Estadía en cámara', descripcion: 'Custodia del folio.', intervalo: true, medidas: { cajas: { nombre: 'Cajas' } } }, { clave: 'declarado', nombre: 'Cargos acordados y manuales', descripcion: 'Base comercial independiente de ejecución.', intervalo: false, medidas: { cantidad: { nombre: 'Cantidad acordada' } } }],
            monedas: [{ codigo: 'CLP', nombre: 'Peso chileno', simbolo: '$', decimales: 0, activa: true }],
        } });
        if (path === '/api/finanzas/servicios' && request.method() === 'POST') {
            const body = request.postDataJSON(); writes.push(body);
            if (reject) return route.fulfill({ status: 422, json: { errors: { aplicacion: ['Ya hay un concepto que reemplaza al base en esas fechas y días. Ajuste sus rangos.'] } } });
            const service = { ...base, ...body, base: false, clave: 'svc_numeral', ...(body.fuente === 'declarado' ? { por_dia: false, unidades: [{ clave: 'cantidad', nombre: `por ${body.unidad_libre}` }] } : {}) };
            services = [...services, service];
            return route.fulfill({ status: 201, json: { data: service } });
        }
        if (path.startsWith('/api/')) return route.fulfill({ json: {} });
        return route.fulfill({ status: 404, body: '' });
    });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await page.goto('http://localhost/oficina/finanzas/tarifario');
    await page.waitForFunction(() => document.getElementById('serviceRows')?.children.length > 0);
    await page.locator('#finance-tariffs-tab-services').click();
    return { context, page, writes, errors };
}

async function fillConcept(page) {
    await page.locator('#serviceNewButton').click();
    await page.locator('#serviceForm [name="nombre"]').fill('Numeral');
    await page.locator('#serviceForm [name="aplicacion"]').selectOption('reemplaza_base');
    await page.locator('#serviceForm [name="dia_desde"]').fill('16');
}

test('crear un concepto abre su precio con las unidades del proceso y muestra sus reglas', async () => {
    const browser = await launch();
    try {
        for (const width of [390, 1280]) {
            const { context, page, writes, errors } = await setup(browser, { width });
            await fillConcept(page);
            await page.locator('#serviceForm [type="submit"]').click();
            await page.waitForFunction(() => document.getElementById('tariffDialog').open);
            assert.equal(writes.length, 1);
            assert.deepEqual([writes[0].fuente, writes[0].aplicacion, writes[0].dia_desde, writes[0].dia_hasta], ['estadia', 'reemplaza_base', 16, null]);
            assert.equal(await page.locator('#tariffFormService').inputValue(), 'svc_numeral');
            assert.deepEqual(await page.locator('#tariffFormUnit option').allTextContents(), ['por bulto y día', 'por caja y día']);
            assert.match(await page.locator('#tariffFormServiceRules').textContent(), /Reemplaza al concepto base · días 16/);
            assert.equal(await page.locator('#serviceRows tr').count(), 2);
            assert.deepEqual(errors, []);
            await context.close();
        }
    } finally { await browser.close(); }
});

test('un cruce rechazado conserva los datos del formulario y permite corregirlos', async () => {
    const browser = await launch();
    try {
        const { context, page, writes } = await setup(browser, { reject: true });
        await fillConcept(page);
        await page.locator('#serviceForm [type="submit"]').click();
        await page.waitForFunction(() => document.getElementById('serviceFormError').textContent.includes('Ajuste sus rangos'));
        assert.equal(writes.length, 1);
        assert.equal(await page.locator('#serviceDialog').evaluate((node) => node.open), true);
        assert.equal(await page.locator('#serviceForm [name="nombre"]').inputValue(), 'Numeral');
        assert.equal(await page.locator('#serviceForm [type="submit"]').isEnabled(), true);
        await context.close();
    } finally { await browser.close(); }
});

test('el perfil de consulta ve conceptos y no tiene acciones de gestión', async () => {
    const browser = await launch();
    try {
        const { context, page, writes, errors } = await setup(browser, { manage: false });
        assert.equal(await page.locator('#serviceNewButton').isVisible(), false);
        assert.equal(await page.locator('#serviceRows [data-service-price]').count(), 0);
        assert.match(await page.locator('#serviceRows').textContent(), /Estadía en cámara/);
        assert.deepEqual(writes, []);
        assert.deepEqual(errors, []);
        await context.close();
    } finally { await browser.close(); }
});


test('un concepto acordado permite unidad y proceso libres sin exigir ejecución', async () => {
    const browser = await launch();
    try {
        const { context, page, writes, errors } = await setup(browser);
        await page.locator('#serviceNewButton').click();
        await page.locator('#serviceForm [name="nombre"]').fill('Cuadrilla reservada');
        await page.locator('#serviceForm [name="fuente"]').selectOption('declarado');
        await page.locator('#serviceForm [name="unidad_libre"]').fill('hora persona');
        await page.locator('#serviceForm [name="proceso_referencia"]').fill('RRHH');
        await page.locator('#serviceForm [type="submit"]').click();
        await page.waitForFunction(() => document.getElementById('tariffDialog').open);
        assert.equal(writes[0].aplicacion, 'declarado');
        assert.equal(writes[0].unidad_libre, 'hora persona');
        assert.equal(writes[0].proceso_referencia, 'RRHH');
        assert.deepEqual(await page.locator('#tariffFormUnit option').allTextContents(), ['por hora persona']);
        assert.match(await page.locator('#tariffFormServiceRules').textContent(), /acuerdo o declaración/);
        assert.deepEqual(errors, []);
        await context.close();
    } finally { await browser.close(); }
});


test('RRHH permite cobrar programación cancelada por acuerdo y bloquea asistencia incompleta', async () => {
    const browser = await launch();
    try {
        const { context, page, writes, errors } = await setup(browser, { hr: true });
        await page.locator('#finance-tariffs-tab-charges').click();
        await page.locator('#chargeFromRrhh').click();
        await page.waitForFunction(() => document.querySelector('[data-dotacion-choose]'));
        await page.locator('[data-dotacion-choose]').click();
        assert.match(await page.locator('#dotacionChargeQuantity').textContent(), /8 horas persona/);
        await page.locator('#dotacionChargeForm [name=medida]').selectOption('horas_asistidas');
        assert.equal(await page.locator('#dotacionChargeSubmit').isDisabled(), true);
        await page.locator('#dotacionChargeForm [name=medida]').selectOption('horas_programadas');
        assert.equal(await page.locator('#dotacionChargeForm [name=base_cobro] [value=ejecucion]').isDisabled(), true);
        await page.locator('#dotacionChargeForm [name=confirmar_unidad]').check();
        await page.locator('#dotacionChargeForm [name=respaldo]').fill('Reserva de cuadrilla según contrato.');
        await page.locator('#dotacionChargeSubmit').click();
        await page.waitForFunction(() => !document.getElementById('dotacionChargeDialog').open);
        assert.equal(writes.length, 1); assert.equal(writes[0].medida, 'horas_programadas'); assert.equal(writes[0].confirmar_unidad, true);
        assert.equal(writes[0].cantidad, undefined); assert.equal(writes[0].cliente_id, undefined); assert.equal(writes[0].base_cobro, 'acuerdo');
        assert.deepEqual(errors, []); await context.close();
    } finally { await browser.close(); }
});
