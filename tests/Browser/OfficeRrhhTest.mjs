import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { extname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
const launch = () => chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
async function setup(browser, { clock = false, manage = true, payroll = false } = {}) {
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
        if (path === '/api/rrhh/personas') return route.fulfill({ json: { data: { data: [{ id: 'persona-1', codigo: 'OP01', nombre: 'Camarero Prueba', cargo: 'Camarero', area: 'Frigorífico', activa: true, fuente_marcaje: 'folios' }], current_page: 1, last_page: 1 }, clientes: [], fuentes: [], permisos: { gestionar: manage } } });
        if (path === '/api/rrhh/nomina/conceptos') return route.fulfill({ json: { data: { data: [{ id: 'he-1', codigo: 'HE', nombre: 'Horas extraordinarias', tipo: 'haber', calculo: 'horas_extra', remuneracion: true, imponible: true, tributable: true, activo: true }], current_page: 1, last_page: 1 }, parametros: [], permisos: { gestionar: manage } } });
        if (path === '/api/rrhh/nomina/personas/persona-1') return route.fulfill({ json: { data: { persona: { id: 'persona-1', codigo: 'OP01', nombre: 'Camarero Prueba' }, condiciones: { data: [], current_page: 1, last_page: 1 }, pactos: { data: [], current_page: 1, last_page: 1 } } } });
        if (path === '/api/rrhh/nomina/movimientos' && request.method() === 'GET') return route.fulfill({ json: { data: { data: [], current_page: 1, last_page: 1 } } });
        if (path === '/api/rrhh/nomina/simular') return route.fulfill({ json: { data: { total: '6667', base_he: '800000', valor_hora_ordinaria: '4444.444444', valor_hora_extra: '6666.666666', minutos: 60, incidencias: [] } } });
        if (path === '/api/rrhh/nomina/movimientos' && request.method() === 'POST') { writes.push(request.postDataJSON()); return route.fulfill({ status: 201, json: { data: { id: 'novedad-1' } } }); }
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
