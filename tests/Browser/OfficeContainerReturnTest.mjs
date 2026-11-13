import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve, extname } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
const html = readFileSync(resolve(root, 'storage/app/ui/oficina/cuenta-corriente.html'), 'utf8');

// Caso Ribagorza: el reingreso de la guía 2324 quedó como arriendo sin CSG.
async function setup(browser, width) {
    const context = await browser.newContext({ viewport: { width, height: 1000 }, timezoneId: 'America/Santiago' });
    const identity = { nombre: 'Cuenta Envases', rol: 'administrador', puede_consultar_cuenta_envases: true, puede_corregir_propiedad_envases: true, modulos_acceso: ['materia-prima.cuenta-envases'] };
    await context.addInitScript((user) => {
        localStorage.setItem('estiba_wms_office_token', 'fixture_envases');
        localStorage.setItem('estiba_wms_office_identity', JSON.stringify(user));
    }, identity);
    const client = { id: 'falco', codigo: 'FA-001', nombre: 'FALCO FRUIT' };
    const line = (id, tipo, cantidad) => ({ id, cliente: client, numero_documento: '2324', movimiento: 'Ingreso en arriendo', tipo_movimiento: 'recepcion_arriendo', recepcion_romana_id: 'rec-34', tipo_envase: tipo, cantidad, impacto_cuenta: cantidad, impacto_cuenta_vigente: cantidad, impacto_existencia: cantidad, propiedad: 'arrendada', ocurrido_at: '2026-11-10T12:33:22Z', estado_revision: 'pendiente', puede_corregir_propiedad: true, devolucion: null });
    const movements = [line('m-bins', 'bins', 13), line('m-esponjas', 'esponjas', 14), line('m-totes', 'totes', 312)];
    const writes = [];
    const lookups = [];
    await context.route('http://localhost/**', async (route) => {
        const request = route.request();
        const url = new URL(request.url());
        const path = url.pathname;
        if (path === '/oficina/envases/cuenta-corriente') return route.fulfill({ body: html, contentType: 'text/html' });
        if (path.startsWith('/build/') || path.startsWith('/brand/')) {
            try { return route.fulfill({ body: readFileSync(resolve(root, `public${path}`)), contentType: ({ '.js': 'text/javascript', '.css': 'text/css', '.woff2': 'font/woff2', '.svg': 'image/svg+xml' })[extname(path)] || 'application/octet-stream' }); }
            catch { return route.fulfill({ status: 404, body: '' }); }
        }
        if (path === '/api/acceso-oficina') return route.fulfill({ json: { usuario: identity } });
        if (path === '/api/oficina/contexto') return route.fulfill({ json: { data: { planta: 'Prueba', temporada: { nombre: '2026' } } } });
        if (path.endsWith('/cuenta-corriente/catalogos')) return route.fulfill({ json: { clientes: [client], temporadas: [{ id: 'temporada', codigo: '2026', nombre: '2026', activa: true }], tipos_envase: [{ codigo: 'bins', nombre: 'Bins plástico' }, { codigo: 'esponjas', nombre: 'Esponja de bins' }, { codigo: 'totes', nombre: 'Tote' }], hoy: '2026-11-13', puede_ajustar: true } });
        if (path === '/api/envases/devoluciones/productores/falco') return route.fulfill({ json: { data: [{ id: 'csg-90624', codigo: '90624', nombre: 'AGRICOLA RIBAGORZA SPA' }, { id: 'csg-90732', codigo: '90732', nombre: 'SOCIEDAD AGRICOLA SAN ALBERTO LIMITADA' }] } });
        if (path === '/api/envases/devoluciones/salida') {
            lookups.push(Object.fromEntries(url.searchParams));
            return route.fulfill({ json: { data: { numero: '2324', devoluciones: [], salidas: [{ origen: 'destare', documento: 'REC-2611-0031 · guía de ingreso 961', fecha: '2026-11-10 17:36', productor: { id: 'csg-90624', codigo: '90624', nombre: 'AGRICOLA RIBAGORZA SPA' },
                envases: [{ tipo_envase: 'bins', propiedad: 'arrendada', cantidad: 13 }, { tipo_envase: 'esponjas', propiedad: 'propia', cantidad: 14 }, { tipo_envase: 'totes', propiedad: 'arrendada', cantidad: 312 }] }] } } });
        }
        if (path.endsWith('/corregir-propiedad')) {
            const body = request.postDataJSON();
            writes.push({ path, body });
            if (body.ingreso_at < '2026-11-10T17:36') return route.fulfill({ status: 422, json: { message: 'Error', errors: { ingreso_at: ['La devolución no puede ser anterior a la salida de la guía 2324 (10-11-2026 17:36). Indica la fecha y hora reales del reingreso.'] } } });
            movements.forEach((movement) => { movement.puede_corregir_propiedad = false; movement.corregido = true; });
            movements.unshift({ ...movements[0], id: 'dev-bins', movimiento: 'Devolución de envases', tipo_movimiento: 'devolucion_envases', corregido: false, devolucion: { productor: { id: 'csg-90624', codigo: '90624', nombre: 'AGRICOLA RIBAGORZA SPA' }, guia_salida: '2324' } });
            return route.fulfill({ json: { message: 'Ingreso asociado.' } });
        }
        if (path.endsWith('/cuenta-corriente/movimientos')) {
            return route.fulfill({ json: {
                data: movements, pendientes: [], reservas: [], balances: [{ cliente: client, tipo_envase: 'bins', saldo: -45 }],
                existencia: { tipos: [], propios_entregados: [] }, resumen: { movimientos_confirmados: movements.length, envases_reservados: 0, lineas_pendientes_validacion: 0, observados: 0, sincronizado_at: '2026-11-13T18:00:00Z' },
            } });
        }
        return route.fulfill({ json: {} });
    });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await page.goto('http://localhost/oficina/envases/cuenta-corriente');
    await page.waitForFunction(() => document.getElementById('balanceList')?.textContent.includes('FALCO FRUIT'));
    return { context, page, writes, lookups, errors };
}

test('asocia el reingreso de la guía 2324 a la devolución del CSG con la propiedad y hora reales', async () => {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
    try {
        for (const width of [390, 1280]) {
            const { context, page, writes, lookups, errors } = await setup(browser, width);
            await page.locator('#container-accounts-tab-movements').click();
            await page.locator('#movementsBody .office-action-select').first().selectOption({ label: 'Asociar devolución CSG' });
            const form = page.locator('#returnForm');
            await page.waitForFunction(() => document.querySelector('#returnForm [name="devolucion_productor_csg_id"]').value === 'csg-90624');
            assert.deepEqual(lookups[0], { cliente_id: 'falco', numero: '2324' });
            assert.match(await page.locator('#returnSummary').textContent(), /2324 · FALCO FRUIT · 13 Bins plástico \(Arrendados\) · 14 Esponja de bins \(Arrendados\) · 312 Tote \(Arrendados\)/);
            assert.match(await page.locator('#returnExits').textContent(), /CSG 90624 · AGRICOLA RIBAGORZA SPA/);
            // La salida precarga que las esponjas salieron como propias.
            assert.equal(await form.locator('[name="propiedad_esponjas"]').inputValue(), 'propia');
            assert.equal(await form.locator('[name="propiedad_bins"]').inputValue(), 'arrendada');
            assert.equal(await form.locator('[name="ingreso_at"]').inputValue(), '2026-11-10T09:33');
            await form.locator('[name="motivo"]').fill('El camión reingresó con los envases de la guía 2324');
            await form.locator('[type="submit"]').click();
            await page.waitForFunction(() => document.getElementById('returnError').textContent.includes('anterior a la salida'));
            assert.equal(await page.locator('#returnDialog').isVisible(), true);
            await form.locator('[name="ingreso_at"]').fill('2026-11-10T17:50');
            await form.locator('[type="submit"]').click();
            await page.waitForFunction(() => !document.getElementById('returnDialog').open);
            assert.equal(writes.length, 2);
            assert.equal(writes[1].path, '/api/envases/cuenta-corriente/movimientos/m-bins/corregir-propiedad');
            assert.deepEqual(writes[1].body, {
                concepto_envases: 'devolucion', devolucion_productor_csg_id: 'csg-90624', devolucion_guia_salida: '2324',
                propiedades: { bins: 'arrendada', esponjas: 'propia', totes: 'arrendada' }, ingreso_at: '2026-11-10T17:50',
                motivo: 'El camión reingresó con los envases de la guía 2324',
            });
            await page.waitForFunction(() => document.getElementById('movementsBody').textContent.includes('Devolución CSG 90624'));
            assert.match(await page.locator('#movementsBody').textContent(), /Devolución CSG 90624 · AGRICOLA RIBAGORZA SPA · devuelve guía 2324/);
            assert.deepEqual(errors, []);
            await context.close();
        }
    } finally { await browser.close(); }
});
