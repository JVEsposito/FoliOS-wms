import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { extname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
const launch = () => chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
async function setup(browser, { clock = false, manage = true, payroll = false, geo = false, discounts = false, contractors = false } = {}) {
    const context = await browser.newContext({ viewport: { width: 390, height: 900 }, timezoneId: 'America/Santiago' });
    const identity = { nombre: 'RRHH Prueba', rol: manage ? 'administrador' : 'consulta', puede_consultar_rrhh: !clock, puede_consultar_remuneraciones_rrhh: payroll, puede_gestionar_remuneraciones_rrhh: payroll && manage, puede_marcar_rrhh: clock, modulos_acceso: [clock ? 'rrhh.marcaje' : 'rrhh.personal'] };
    const writes = []; const errors = [];
    await context.addInitScript((user) => { localStorage.setItem('estiba_wms_office_token', 'rrhh_fixture'); localStorage.setItem('estiba_wms_office_identity', JSON.stringify(user)); }, identity);
    await context.route('http://localhost/**', async (route) => {
        const request = route.request(); const path = new URL(request.url()).pathname;
        if (path === (clock ? '/oficina/rrhh/marcaje' : '/oficina/rrhh')) return route.fulfill({ contentType: 'text/html', body: readFileSync(resolve(root, `storage/app/ui/oficina/${clock ? 'marcaje' : 'rrhh'}.html`)) });
        if (path.startsWith('/build/') || path.startsWith('/brand/folios/')) {
            try { return route.fulfill({ body: readFileSync(resolve(root, `public${path}`)), contentType: ({ '.js': 'text/javascript', '.css': 'text/css', '.woff2': 'font/woff2', '.svg': 'image/svg+xml' })[extname(path)] || 'application/octet-stream' }); }
            catch { return route.fulfill({ status: 404, body: '' }); }
        }
        if (path === '/api/acceso-oficina') return route.fulfill({ json: { usuario: identity } });
        if (path === '/api/oficina/contexto') return route.fulfill({ json: { data: { planta: 'Prueba', temporada: { nombre: '2026-2027' } } } });
        if (path === '/api/rrhh/personas') return route.fulfill({ json: { data: { data: [{ id: 'persona-1', codigo: 'OP01', nombre: 'Camarero Prueba', cargo: 'Camarero', area: 'Frigorífico', activa: true, fuente_marcaje: 'folios' }], current_page: 1, last_page: 1 }, clientes: [], fuentes: geo ? [{ id: 'fuente-geo', codigo: 'geo', nombre: 'GeoVictoria prueba', tipo: 'geovictoria', activa: true, configurada: true }] : [], permisos: { gestionar: manage } } });
        if (path === '/api/rrhh/fuentes/fuente-geo/consultar-geovictoria') { writes.push({ path, ...request.postDataJSON() }); return route.fulfill({ status: 201, json: { data: { id: 'lote-1', estado: 'con_incidencias' } } }); }
        if (path === '/api/rrhh/fuentes/fuente-geo/lotes') return route.fulfill({ json: { data: { data: [{ id: 'lote-1', estado: writes.length > 1 ? 'procesado' : 'con_incidencias', intentos: writes.length, resultado: { aceptadas: 1, repetidas: 0, errores: writes.length > 1 ? [] : [{ fila: 2, errores: { identificador: ['Falta vínculo explícito'] } }] } }], current_page: 1, last_page: 1 } } });
        if (path === '/api/rrhh/lotes/lote-1/reintentar') { writes.push({ path }); return route.fulfill({ json: { data: { estado: 'procesado' } } }); }
        if (path === '/api/rrhh/nomina/conceptos') return route.fulfill({ json: { data: { data: [{ id: 'he-1', codigo: 'HE', nombre: 'Horas extraordinarias', tipo: 'haber', calculo: 'horas_extra', remuneracion: true, imponible: true, tributable: true, activo: true }], current_page: 1, last_page: 1 }, parametros: [], permisos: { gestionar: manage } } });
        if (path === '/api/rrhh/nomina/personas/persona-1') return route.fulfill({ json: { data: { persona: { id: 'persona-1', codigo: 'OP01', nombre: 'Camarero Prueba' }, condiciones: { data: [], current_page: 1, last_page: 1 }, pactos: { data: [], current_page: 1, last_page: 1 } } } });
        if (path === '/api/rrhh/prevision/directorio/institucion' && request.method() === 'GET') return route.fulfill({ json: { data: { data: [{ id: 'isapre-1', codigo: 'ISAPRE_TEST', rut: '76000002-7', razon_social: 'Isapre de prueba', tipo: 'isapre', activo: true, desde: '2026-01-01', hasta: '9999-12-31', version: 1 }], current_page: 1, last_page: 1 }, tipos: ['afp', 'fonasa', 'isapre'] } });
        if (contractors && path === '/api/rrhh/prevision/directorio/empleador') return route.fulfill({ json: { data: { data: [{ id: 'empresa-1', codigo: 'EXTERNO', rut: '76000010-8', razon_social: 'Contratista de prueba', tipo: 'contratista', activo: true, desde: '2020-01-01', hasta: '9999-12-31' }], current_page: 1, last_page: 1 }, tipos: ['propio', 'contratista', 'subcontratista', 'est'] } });
        if (contractors && path === '/api/rrhh/contratistas/expedientes') return route.fulfill({ json: { data: { data: [{ id: 'exp-1', faena: 'PLANTA_1', mes: '2026-09', personas_count: 1, estado: 'pendiente', referencia_contrato: 'Comercial 2026' }], current_page: 1, last_page: 1 }, empleador: { razon_social: 'Contratista de prueba' } } });
        if (contractors && path === '/api/rrhh/contratistas/expedientes/exp-1') return route.fulfill({ json: { data: { id: 'exp-1', faena: 'PLANTA_1', mes: '2026-09', personas_count: 1, estado: 'pendiente', version: 1, personal: [{ id: 'persona-1', codigo: 'EX01', nombre: 'Persona contratista' }] }, faltantes: ['f301: 1 persona(s) sin cobertura conforme'], saldo_retenido: '100000' } });
        if (contractors && path === '/api/rrhh/contratistas/expedientes/exp-1/antecedentes' && request.method() === 'POST') { writes.push({ path, ...request.postDataJSON() }); return route.fulfill({ status: 201, json: { data: { id: 'doc-1', estado: 'pendiente' } } }); }
        if (contractors && path === '/api/rrhh/contratistas/expedientes/exp-1/retenciones' && request.method() === 'POST') { writes.push({ path, ...request.postDataJSON() }); if (writes.length === 1) return route.fulfill({ status: 503, json: { message: 'Reintente la misma anotación.' } }); return route.fulfill({ status: 201, json: { data: { id: 'anotacion-1' } } }); }
        if (contractors && ['/api/rrhh/contratistas/expedientes/exp-1/antecedentes', '/api/rrhh/contratistas/expedientes/exp-1/retenciones'].includes(path)) return route.fulfill({ json: { data: { data: [], current_page: 1, last_page: 1 }, saldo_retenido: '100000' } });
        if (path === '/api/rrhh/prevision/directorio/institucion' && request.method() === 'POST') { writes.push({ path, ...request.postDataJSON() }); return route.fulfill({ status: 201, json: { data: { id: 'isapre-2' } } }); }
        if (['/api/rrhh/prevision/contratos', '/api/rrhh/prevision/afiliaciones'].includes(path) && request.method() === 'GET') return route.fulfill({ json: { data: { data: [], current_page: 1, last_page: 1 } } });
        if (path === '/api/rrhh/prevision/afiliaciones' && request.method() === 'POST') { writes.push({ path, ...request.postDataJSON() }); return route.fulfill({ status: 201, json: { data: { id: 'afiliacion-1' } } }); }
        if (path === '/api/rrhh/nomina/movimientos' && request.method() === 'GET') return route.fulfill({ json: { data: { data: [], current_page: 1, last_page: 1 } } });
        if (path === '/api/rrhh/nomina/legal/indices') return route.fulfill({ json: { data: { data: [{ id: 'indice-1', mes: '2026-10', version: 1, uf: '40000', utm: '72151', fecha_uf: '2026-10-31', respaldo: 'Índices oficiales revisados' }], current_page: 1, last_page: 1 } } });
        if (path === '/api/rrhh/nomina/legal/calculos' && request.method() === 'GET') return route.fulfill({ json: { data: { data: [], current_page: 1, last_page: 1 } } });
        if (path === '/api/rrhh/nomina/legal/simular') return route.fulfill({ json: { data: { regla: 'CL-CALCULOS-2026-10-21-v1', alcance: 'Preparación revisada; no es una liquidación completa.', lineas: [{ codigo: 'GRAT50_ANTICIPO', nombre: 'Anticipo de gratificación artículo 50', tipo: 'haber', monto: '200000', base: '800000', tasa: '25', destino: null }], totales: { haber: '200000' }, detalle: { tope_mensual_referencia: '219114.729166' } } } });
        if (path === '/api/rrhh/nomina/legal/calculos' && request.method() === 'POST') { writes.push({ path, ...request.postDataJSON() }); return route.fulfill({ status: 201, json: { data: { id: 'calculo-1' } } }); }
        if (path === '/api/rrhh/nomina/mandatos') return route.fulfill({ json: { data: { data: discounts ? [{ id: 'mandato-1', concepto_id: 'coop-1', concepto: { nombre: 'Crédito cooperativa', calculo: 'monto' }, institucion: { razon_social: 'Cooperativa de prueba' }, referencia: 'CRED-01', desde: '2026-10-01', hasta: '2027-12-31', cuota: '20000', deuda_total: '45000', saldo_por_descontar: '45000', descontado: '0', cuotas: 3, cuotas_descontadas: 0, respaldo: 'Autorización escrita y deuda verificadas' }] : [], current_page: 1, last_page: 1 } } });
        if (path === '/api/rrhh/nomina/simular') return route.fulfill({ json: { data: request.postDataJSON().mandato_id ? { total: '20000', institucion: { razon_social: 'Cooperativa de prueba' } } : { total: '6667', base_he: '800000', valor_hora_ordinaria: '4444.444444', valor_hora_extra: '6666.666666', minutos: 60, incidencias: [] } } });
        if (path === '/api/rrhh/nomina/movimientos' && request.method() === 'POST') { writes.push(request.postDataJSON()); if (discounts && writes.length === 1) return route.fulfill({ status: 503, json: { message: 'Reintente la misma cuota.' } }); return route.fulfill({ status: 201, json: { data: { id: 'novedad-1' } } }); }
        if (path === '/api/rrhh/asignaciones') return route.fulfill({ json: { data: { data: [{ id: 'asignacion-1', persona_nombre: 'Camarero Prueba', desde_at: '2026-11-02T01:00:00Z', hasta_at: '2026-11-02T09:00:00Z', estado: 'cancelada', horas_programadas: '7.5', horas_asistidas: '0', asistencia_valida: false, incidencias: ['sin_marcajes'], costo_programado: null }], current_page: 1, last_page: 1 } } });
        if (path === '/api/rrhh/marcajes' && request.method() === 'POST') {
            writes.push(request.postDataJSON());
            if (writes.length === 1) return route.fulfill({ status: 503, json: { message: 'Reintente la misma solicitud.' } });
            return route.fulfill({ status: 201, json: { data: { id: '12345678-1234-4123-8123-123456789abc', tipo: 'entrada', instante_at: '2026-11-02T12:00:00Z', anulado: false } } });
        }
        return route.fulfill({ json: {} });
    });
    const page = await context.newPage(); page.on('pageerror', (e) => errors.push(e.message));
    await page.goto(`http://localhost/oficina/rrhh${clock ? '/marcaje' : ''}`);
    return { page, context, writes, errors };
}
test('el puesto reintenta el mismo UUID y borra el PIN al recibir el comprobante', async () => {
    const browser = await launch();
    try {
        const { page, context, writes, errors } = await setup(browser, { clock: true });
        await page.locator('#rhClockForm [name=codigo]').fill('OP01'); await page.locator('#rhClockForm [name=pin]').fill('345678');
        await page.locator('#rhClockForm button').click(); await page.waitForFunction(() => document.getElementById('rhMessage').textContent.includes('Reintente'));
        await page.locator('#rhClockForm button').click(); await page.waitForFunction(() => document.getElementById('rhReceipt').textContent.includes('Entrada registrada'));
        assert.equal(writes.length, 2); assert.equal(writes[0].operacion_id, writes[1].operacion_id); assert.equal(writes[0].instante, undefined);
        assert.equal(await page.locator('#rhClockForm [name=pin]').inputValue(), ''); assert.equal(await page.locator('#rhPeople').count(), 0); assert.deepEqual(errors, []); await context.close();
    } finally { await browser.close(); }
});
test('consulta distingue horas programadas y asistencia de una asignación cancelada sin acciones de escritura', async () => {
    const browser = await launch();
    try {
        const { page, context, errors } = await setup(browser, { manage: false });
        await page.waitForFunction(() => document.getElementById('rhPeople').textContent.includes('Camarero Prueba'));
        assert.equal(await page.locator('#rnTab').isVisible(), false); assert.equal(await page.locator('#rhNewPerson').isVisible(), false); assert.equal(await page.locator('[data-action=editar]').count(), 0);
        await page.locator('[data-rh-tab=dotacion]').click(); await page.waitForFunction(() => document.getElementById('rhAssignments').textContent.includes('7.5'));
        assert.match(await page.locator('#rhAssignments').textContent(), /cancelada/); assert.match(await page.locator('#rhAssignments').textContent(), /Sin marcajes/);
        assert.equal(await page.locator('[data-rh-assignment][data-action=cancelada]').count(), 0); assert.deepEqual(errors, []); await context.close();
    } finally { await browser.close(); }
});

test('remuneraciones calcula minutos verificados antes de guardar un borrador sin crear cobro comercial', async () => {
    const browser = await launch();
    try {
        const { page, context, writes, errors } = await setup(browser, { payroll: true });
        await page.locator('#rnTab').click(); await page.waitForFunction(() => document.getElementById('rnConcepts').textContent.includes('Horas extraordinarias'));
        await page.selectOption('#rnPerson', 'persona-1'); await page.locator('#rnMonth').fill('2026-10'); await page.locator('#rnLoad').click();
        await page.locator('#rnNewMovement').click(); await page.selectOption('#rnMovementForm [name=concepto_id]', 'he-1');
        await page.locator('#rnMovementForm [name=minutos]').fill('60'); await page.locator('#rnMovementForm [name=pacto_desde]').fill('2026-10-01'); await page.locator('#rnMovementForm [name=pacto_hasta]').fill('2026-12-31');
        await page.locator('#rnMovementForm [name=respaldo]').fill('Pacto y asistencia revisados por RRHH');
        await page.locator('#rnSimulate').click(); await page.waitForFunction(() => document.getElementById('rnSimulation').textContent.includes('6.667 CLP'));
        assert.equal(writes.length, 0); assert.equal(await page.locator('#rnMovementForm [name=cantidad]').isEnabled(), false);
        await page.locator('#rnMovementForm button[type=submit]').click(); await page.waitForFunction(() => !document.getElementById('rnMovementDialog').open);
        assert.equal(writes.length, 1); assert.equal(writes[0].minutos, 60); assert.equal(writes[0].valor, undefined); assert.equal(writes[0].estado, undefined); assert.ok(writes[0].operacion_id); assert.deepEqual(errors, []);
        await context.close();
    } finally { await browser.close(); }
});

test('GeoVictoria conserva la consulta y reintenta el lote desde pantalla sin repetir la solicitud al proveedor', async () => {
    const browser = await launch();
    try {
        const { page, context, writes, errors } = await setup(browser, { geo: true });
        await page.locator('[data-rh-tab=fuentes]').click(); await page.locator('[data-rh-source][data-action=geo]').click();
        await page.locator('#rhGeoForm [name=desde]').fill('20261001000000'); await page.locator('#rhGeoForm [name=identificadores]').fill('EXT-1, EXT-2');
        await page.locator('#rhGeoForm button[type=submit]').click(); await page.waitForFunction(() => !document.getElementById('rhGeoDialog').open);
        assert.equal(writes.length, 1); assert.deepEqual(writes[0].identificadores, ['EXT-1','EXT-2']); assert.match(await page.locator('#rhDetail').textContent(), /Falta vínculo/);
        await page.locator('[data-retry-lot=lote-1]').click(); await page.waitForFunction(() => document.getElementById('rhDetail').textContent.includes('procesado'));
        assert.equal(writes.length, 2); assert.equal(writes[1].path, '/api/rrhh/lotes/lote-1/reintentar'); assert.deepEqual(errors, []); await context.close();
    } finally { await browser.close(); }
});

test('crea una institución desde RRHH con RUT y fuente sin asignarla automáticamente a personas', async () => {
    const browser = await launch();
    try {
        const { page, context, writes, errors } = await setup(browser, { payroll: true });
        await page.locator('#rpTab').click(); await page.waitForFunction(() => document.getElementById('rpDirectory').textContent.includes('Isapre de prueba'));
        await page.locator('#rpNew').click(); const f = page.locator('#rpDirectoryForm');
        for (const [name, value] of Object.entries({ codigo: 'ISAPRE_NUEVA', rut: '76000003-5', razon_social: 'Institución nueva', desde: '2026-01-01', verificado_en: '2026-10-21', respaldo: 'Fuente oficial revisada por RRHH' })) await f.locator(`[name=${name}]`).fill(value);
        await f.locator('[name=tipo]').selectOption('isapre'); await f.locator('button[type=submit]').click();
        await page.waitForFunction(() => !document.getElementById('rpDirectoryDialog').open);
        assert.equal(writes.length, 1); assert.equal(writes[0].activo, true); assert.equal(writes[0].tipo, 'isapre'); assert.equal(writes[0].persona_id, undefined); assert.deepEqual(errors, []); await context.close();
    } finally { await browser.close(); }
});

test('afilia a salud con FUN y precio de plan Isapre y mantiene separado al empleador', async () => {
    const browser = await launch();
    try {
        const { page, context, writes, errors } = await setup(browser, { payroll: true });
        await page.locator('#rnTab').click(); await page.waitForFunction(() => document.getElementById('rnPerson').options.length > 1);
        await page.selectOption('#rnPerson', 'persona-1'); await page.locator('#rnLoad').click(); await page.locator('#rpNewAffiliation').click();
        const f = page.locator('#rpAffiliationForm'); await f.locator('[name=regimen]').selectOption('salud');
        await f.locator('[name=institucion_id]').selectOption('isapre-1');
        assert.equal(await page.locator('#rpPlan').isVisible(), true);
        for (const [name, value] of Object.entries({ desde: '2026-10-01', referencia: 'FUN-2026-001', precio_plan: '3.45', respaldo: 'FUN firmado y precio mensual revisado' })) await f.locator(`[name=${name}]`).fill(value);
        await f.locator('button[type=submit]').click(); await page.waitForFunction(() => !document.getElementById('rpAffiliationDialog').open);
        assert.equal(writes.length, 1); assert.equal(writes[0].persona_id, 'persona-1'); assert.equal(writes[0].empleador_id, undefined); assert.equal(writes[0].moneda_plan, 'UF'); assert.equal(writes[0].precio_plan, '3.45'); assert.deepEqual(errors, []); await context.close();
    } finally { await browser.close(); }
});

test('prepara una cuota en borrador y reintenta con el mismo UUID sin aprobar ni modificar el saldo desde la pantalla', async () => {
    const browser = await launch();
    try {
        const { page, context, writes, errors } = await setup(browser, { payroll: true, discounts: true });
        await page.locator('#rnTab').click(); await page.waitForFunction(() => document.getElementById('rnPerson').options.length > 1);
        await page.selectOption('#rnPerson', 'persona-1'); await page.locator('#rnMonth').fill('2026-10'); await page.locator('#rnLoad').click();
        await page.locator('[data-rd-action=quota]').click(); await page.locator('#rdQuotaForm [name=remuneracion_bruta]').fill('1000000');
        await page.locator('#rdSimulateQuota').click(); await page.waitForFunction(() => document.getElementById('rdQuotaPreview').textContent.includes('20.000 CLP'));
        assert.equal(writes.length, 0);
        await page.locator('#rdQuotaForm button[type=submit]').click(); await page.waitForFunction(() => document.querySelector('#rdQuotaForm [data-rh-error]').textContent.includes('Reintente'));
        await page.locator('#rdQuotaForm button[type=submit]').click(); await page.waitForFunction(() => !document.getElementById('rdQuotaDialog').open);
        assert.equal(writes.length, 2); assert.equal(writes[0].operacion_id, writes[1].operacion_id); assert.equal(writes[0].mandato_id, 'mandato-1'); assert.equal(writes[0].estado, undefined); assert.equal(writes[0].valor, undefined); assert.deepEqual(errors, []); await context.close();
    } finally { await browser.close(); }
});

test('calcula un anticipo legal con base y tope visibles y guarda preparación sin generar una novedad', async () => {
    const browser = await launch();
    try {
        const { page, context, writes, errors } = await setup(browser, { payroll: true });
        await page.locator('#rnTab').click(); await page.waitForFunction(() => document.getElementById('rnPerson').options.length > 1);
        await page.selectOption('#rnPerson', 'persona-1'); await page.locator('#rnMonth').fill('2026-10'); await page.locator('#rnLoad').click(); await page.locator('#rlNew').click();
        const f = page.locator('#rlForm'); await f.locator('[name=tipo]').selectOption('anticipo50');
        await f.locator('[name=base_computable]').fill('800000'); await f.locator('[name=imm_aplicable]').fill('553553'); await f.locator('[name=respaldo]').fill('Modalidad, remuneraciones e ingreso mínimo revisados');
        assert.equal(await f.locator('[name=base_imponible]').isEnabled(), false);
        await page.locator('#rlSimulate').click(); await page.waitForFunction(() => document.getElementById('rlPreview').textContent.includes('200.000 CLP'));
        assert.match(await page.locator('#rlPreview').textContent(), /Tope mensual de referencia/); assert.equal(writes.length, 0);
        await f.locator('button[type=submit]').click(); await page.waitForFunction(() => !document.getElementById('rlDialog').open);
        assert.equal(writes.length, 1); assert.equal(writes[0].path, '/api/rrhh/nomina/legal/calculos'); assert.equal(writes[0].tipo, 'anticipo50'); assert.equal(writes[0].datos.base_computable, '800000'); assert.equal(writes[0].datos.base_imponible, undefined); assert.equal(writes[0].parametro_id, undefined); assert.deepEqual(errors, []); await context.close();
    } finally { await browser.close(); }
});

async function openContractorFile(page) {
    await page.locator('#rpTab').click(); await page.locator('#rpSearch [name=directorio]').selectOption('empleador');
    await page.locator('#rpSearch button').click(); await page.locator('[data-rc-company=empresa-1]').click();
    await page.locator('[data-rc-action=detail]').click(); await page.waitForFunction(() => document.getElementById('rcFileDialog').open);
}

test('contratistas registra cobertura explícita pendiente sin declarar conformidad automáticamente', async () => {
    const browser = await launch();
    try {
        const { page, context, writes, errors } = await setup(browser, { payroll: true, contractors: true });
        await openContractorFile(page); assert.match(await page.locator('#rcMissing').textContent(), /F30-1/);
        await page.locator('[data-rc-action=evidence]').click(); const f = page.locator('#rcEvidenceForm');
        await f.locator('[name=fecha_documento]').fill('2026-10-10'); await f.locator('[name=referencia]').fill('F30-1 privado, folio 1234');
        await f.locator('[name=cubre_todos]').selectOption('1'); await f.locator('button[type=submit]').click();
        await page.waitForFunction(() => !document.getElementById('rcEvidenceDialog').open);
        assert.equal(writes.length, 1); assert.equal(writes[0].tipo, 'f301'); assert.equal(writes[0].cubre_todos, true);
        assert.equal(writes[0].version_expediente, 1); assert.equal(writes[0].estado, undefined); assert.equal(writes[0].desde, '2026-09-01'); assert.equal(writes[0].hasta, '2026-09-30');
        assert.match(await page.locator('#rcSummary').textContent(), /Pendiente/); assert.deepEqual(errors, []); await context.close();
    } finally { await browser.close(); }
});

test('contratistas reintenta una anotación con el mismo UUID y mantiene separado el saldo comercial', async () => {
    const browser = await launch();
    try {
        const { page, context, writes, errors } = await setup(browser, { payroll: true, contractors: true });
        await openContractorFile(page); assert.match(await page.locator('#rcBalance').textContent(), /100.000 CLP/);
        await page.locator('[data-rc-action=retention]').click(); const f = page.locator('#rcRetentionForm');
        for (const [name, value] of Object.entries({ monto: '20000', fecha: '2026-10-10', referencia: 'RET-12345', respaldo: 'Retención documentada en comprobante privado' })) await f.locator(`[name=${name}]`).fill(value);
        await f.locator('button[type=submit]').click(); await page.waitForFunction(() => document.querySelector('#rcRetentionForm [data-rh-error]').textContent.includes('Reintente'));
        await f.locator('button[type=submit]').click(); await page.waitForFunction(() => !document.getElementById('rcRetentionDialog').open);
        assert.equal(writes.length, 2); assert.equal(writes[0].operacion_id, writes[1].operacion_id); assert.equal(writes[0].tipo, 'retencion');
        assert.equal(writes[0].persona_id, undefined); assert.equal(writes[0].institucion_id, undefined); assert.deepEqual(errors, []); await context.close();
    } finally { await browser.close(); }
});
