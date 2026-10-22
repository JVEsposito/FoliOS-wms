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

async function setup(browser, { manage = true, reject = false, width = 1280, hr = false, periodic = false } = {}) {
    const context = await browser.newContext({ viewport: { width, height: 1000 } });
    const identity = { nombre: 'Finanzas Prueba', rol: manage ? 'administrador' : 'consulta', puede_consultar_finanzas: true, modulos_acceso: ['finanzas.prefactura'] };
    await context.addInitScript((user) => {
        localStorage.setItem('estiba_wms_office_token', 'token_finanzas_fixture');
        localStorage.setItem('estiba_wms_office_identity', JSON.stringify(user));
    }, identity);
    let services = hr ? [base, { ...base, clave: 'svc_rrhh', nombre: 'Reserva de cuadrilla', fuente: 'declarado', base: false, por_dia: false, unidad_libre: 'hora persona', unidades: [{ clave: 'cantidad', nombre: 'por hora persona' }] }] : [base];
    if (periodic) services.push({ ...base, clave: 'svc_period', nombre: 'Arriendo de bins', fuente: 'periodico', base: false, unidad_libre: 'bin', unidades: [{ clave: 'cantidad', nombre: 'por bin y día' }] });
    const charges = periodic ? [{ id: 'cargo-periodo', servicio: 'svc_period', cliente_nombre: 'Cliente de prueba', cantidad: '30', fecha: '2026-11-01', fecha_hasta: '2026-12-10', aprobado_at: '2026-11-01', base_cobro: 'acuerdo', estado_ejecucion: 'no_aplica', respaldo: 'Acuerdo de arriendo' }] : [];
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
        if (path === '/api/finanzas/cargos' && request.method() === 'GET') return route.fulfill({ json: { data: { data: charges, current_page: 1, last_page: 1, prev_page_url: null, next_page_url: null } } });
        if (path === '/api/finanzas/cargos' && request.method() === 'POST') { writes.push(request.postDataJSON()); return route.fulfill({ status: 201, json: { data: { id: 'cargo-nuevo' } } }); }
        if (path.endsWith('/finalizar-periodo')) { const body = request.postDataJSON(); writes.push(body); charges[0].fecha_hasta = body.fecha_hasta; return route.fulfill({ json: { data: charges[0] } }); }
        if (path === '/api/acceso-oficina') return route.fulfill({ json: { usuario: identity } });
        if (path === '/api/oficina/contexto') return route.fulfill({ json: { data: { planta: 'Planta Prueba', temporada: { nombre: '2026-2027' } } } });
        if (path === '/api/finanzas/tarifas' && request.method() === 'GET') return route.fulfill({ json: {
            data: [], resumen: {}, hoy: '2026-11-01', servicios: services, puede_gestionar: manage, puede_usar_rrhh: hr, clientes: [{ id: 'cliente', nombre: 'Cliente de prueba', activo: true }],
            procesos: [{ clave: 'estadia', nombre: 'Estadía en cámara', descripcion: 'Custodia del folio.', intervalo: true, medidas: { cajas: { nombre: 'Cajas' } } }, { clave: 'declarado', nombre: 'Cargos acordados y manuales', descripcion: 'Base comercial independiente de ejecución.', intervalo: false, medidas: { cantidad: { nombre: 'Cantidad acordada' } } }, { clave: 'romana_recepcion', nombre: 'Recepciones', area: 'recepcion', intervalo: false, dimensiones: { tipo_servicio: 'Servicio' }, medidas: { kilos_netos: { nombre: 'Kilos netos', unidad: 'kg' } } }, { clave: 'calculado', nombre: 'Cálculo entre medidas operativas', intervalo: false, medidas: { cantidad: { nombre: 'Cantidad calculada', unidad: 'unidad' } } }],
            monedas: [{ codigo: 'CLP', nombre: 'Peso chileno', simbolo: '$', decimales: 0, activa: true }],
        } });
        if (path === '/api/finanzas/servicios' && request.method() === 'POST') {
            const body = request.postDataJSON(); writes.push(body);
            if (reject) return route.fulfill({ status: 422, json: { errors: { aplicacion: ['Ya hay un concepto que reemplaza al base en esas fechas y días. Ajuste sus rangos.'] } } });
            const service = { ...base, ...body, base: false, clave: 'svc_numeral', ...(['declarado', 'calculado'].includes(body.fuente) ? { por_dia: false, unidades: [{ clave: 'cantidad', nombre: `por ${body.unidad_libre}` }] } : {}) };
            services = [...services, service];
            return route.fulfill({ status: 201, json: { data: service } });
        }
        if (path === '/api/finanzas/servicios/simular') return route.fulfill({ json: { data: { unidad: 'kg', grupos: 1, incompletos: 0, aviso: 'Simulación de hechos capturados', muestra: [{ cliente_nombre: 'Cliente prueba', fecha: '2026-11-01', cantidad: '8500', completo: true, dimensiones: { fecha_operacional: '2026-11-01' }, aportes: [{ hecho_id: 1, fuente: 'romana_recepcion', version: 1, valor: '10000', factor: '1', cantidad: '10000' }] }] } } });
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

test('un arriendo por período muestra fechas y permite registrar su término anticipado', async () => {
    const browser = await launch();
    try {
        const { context, page, writes, errors } = await setup(browser, { width: 390, periodic: true });
        await page.locator('#finance-tariffs-tab-charges').click();
        await page.locator('[data-charge-end-period]').click();
        await page.locator('#chargePeriodEndForm [name="fecha_hasta"]').fill('2026-11-28');
        await page.locator('#chargePeriodEndForm [name="motivo"]').fill('Devolución anticipada acordada');
        await page.locator('#chargePeriodEndForm [type="submit"]').click();
        await page.waitForFunction(() => !document.getElementById('chargePeriodEnd').open);
        assert.equal(writes[0].fecha_hasta, '2026-11-28');
        await page.locator('#chargeNew').click();
        await page.locator('#chargeForm [name="cantidad"]').fill('30');
        await page.locator('#chargeForm [name="fecha"]').fill('2026-11-25');
        await page.locator('#chargeForm [name="fecha_hasta"]').fill('2026-12-10');
        await page.locator('#chargeForm [name="respaldo"]').fill('Contrato de arriendo de bins');
        await page.locator('#chargeForm [type="submit"]').click();
        await page.waitForFunction(() => !document.getElementById('chargeDialog').open);
        assert.equal(writes[1].fecha_hasta, '2026-12-10');
        assert.equal(writes[1].cantidad, '30');
        assert.deepEqual(errors, []);
        await context.close();
    } finally { await browser.close(); }
});

test('construye y simula una cantidad compuesta desde la oficina sin escribir código', async () => {
    const browser = await launch();
    try {
        for (const width of [390, 1280]) {
            const { context, page, writes, errors } = await setup(browser, { width });
            await page.locator('#serviceNewButton').click();
            await page.locator('#serviceForm [name="nombre"]').fill('Servicio neto');
            await page.locator('#serviceForm [name="area"]').fill('packing');
            await page.locator('#serviceForm [name="fuente"]').selectOption('calculado');
            await page.locator('#serviceForm [name="unidad_libre"]').fill('kg');
            await page.locator('[data-rule-add]').click();
            await page.locator('[data-rule-group][value="origen_id"]').uncheck();
            await page.locator('[data-rule-group][value="fecha_operacional"]').check();
            const terms = page.locator('[data-rule-term]');
            await terms.nth(0).locator('[data-rule-filter]').selectOption('tipo_servicio');
            await terms.nth(0).locator('[data-rule-filter-value]').fill('proceso');
            await terms.nth(1).locator('[data-rule-factor]').fill('-1');
            await terms.nth(1).locator('[data-rule-filter]').selectOption('tipo_servicio');
            await terms.nth(1).locator('[data-rule-filter-value]').fill('almacenaje');
            await page.locator('#serviceRulePreviewButton').click();
            await page.waitForFunction(() => document.querySelector('[data-rule-preview]')?.textContent.includes('8500'));
            assert.equal(writes.length, 0);
            await page.locator('#serviceForm [type="submit"]').click();
            await page.waitForFunction(() => document.getElementById('tariffDialog').open);
            assert.equal(writes[0].area, 'packing');
            assert.equal(writes[0].regla.terminos[1].factor, '-1');
            assert.deepEqual(writes[0].regla.agrupar, ['fecha_operacional']);
            assert.deepEqual(errors, []);
            await context.close();
        }
    } finally { await browser.close(); }
});

async function fillConcept(page) {
    await page.locator('#serviceNewButton').click();
    await page.locator('#serviceForm [name="nombre"]').fill('Numeral');
    await page.locator('#serviceForm [name="aplicacion"]').selectOption('reemplaza_base');
    await page.locator('#serviceForm [name="dia_desde"]').fill('16');
}

test('los filtros repetidos se explican en el formulario y no se guardan silenciosamente', async () => {
    const browser = await launch();
    try {
        const { context, page, writes, errors } = await setup(browser, { width: 390 });
        await page.locator('#serviceNewButton').click();
        await page.locator('#serviceForm [name="nombre"]').fill('Servicio filtrado');
        await page.locator('#serviceForm [name="fuente"]').selectOption('calculado');
        await page.locator('#serviceForm [name="unidad_libre"]').fill('kg');
        await page.locator('[data-rule-filter-add]').click();
        const filters = page.locator('[data-rule-filter]');
        await filters.nth(0).selectOption('tipo_servicio');
        await filters.nth(1).selectOption('tipo_servicio');
        await page.locator('[data-rule-filter-value]').nth(0).fill('proceso');
        await page.locator('[data-rule-filter-value]').nth(1).fill('almacenaje');
        await page.locator('#serviceForm [type="submit"]').click();
        assert.match(await page.locator('#serviceFormError').textContent(), /una sola vez/);
        assert.equal(writes.length, 0);
        assert.deepEqual(errors, []);
        await context.close();
    } finally { await browser.close(); }
});

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
        assert.equal(await page.locator('#dotacionChargeForm [name=base_cobro] [value=ejecucion]').evaluate((option) => option.disabled), true);
        await page.locator('#dotacionChargeForm [name=confirmar_unidad]').check();
        await page.locator('#dotacionChargeForm [name=respaldo]').fill('Reserva de cuadrilla según contrato.');
        await page.locator('#dotacionChargeSubmit').click();
        await page.waitForFunction(() => !document.getElementById('dotacionChargeDialog').open);
        assert.equal(writes.length, 1); assert.equal(writes[0].medida, 'horas_programadas'); assert.equal(writes[0].confirmar_unidad, true);
        assert.equal(writes[0].cantidad, undefined); assert.equal(writes[0].cliente_id, undefined); assert.equal(writes[0].base_cobro, 'acuerdo');
        assert.deepEqual(errors, []); await context.close();
    } finally { await browser.close(); }
});
