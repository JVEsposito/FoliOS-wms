import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { extname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
test('asignación expresa de una nota a contenedor conserva UUID al reintentar', async () => {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox'] });
    try {
        const context = await browser.newContext(); const writes = []; const errors = [];
        const identity = { nombre: 'Comercial de prueba', rol: 'despachador', puede_consultar_comercial_comex: true, modulos_acceso: ['comex.comercial'] };
        const booking = { id: 'booking', codigo: 'BK-PRUEBA', cliente: { nombre: 'Cliente' }, gestion: 'servicio', estado: 'borrador', version: 1, datos: { nave: 'Nave de prueba', viaje: 'Viaje de prueba', naviera: { razon_social: 'Naviera' }, forwarder: { razon_social: 'Forwarder' }, origen: { nombre: 'Puerto CL' }, destino: { nombre: 'Puerto CN' } } };
        const detail = { booking, contenedores: [{ id: 'container', referencia: 'CONT-1', numero: 'MSKU1234567', tipo: '40RH' }], asignaciones: [], vinculos: [] };
        const p = (data) => ({ data: { data, current_page: 1, last_page: 1 } });
        await context.addInitScript((u) => { localStorage.setItem('estiba_wms_office_token', 'test'); localStorage.setItem('estiba_wms_office_identity', JSON.stringify(u)); }, identity);
        await context.route('http://localhost/**', async (route) => {
            const req = route.request(); const path = new URL(req.url()).pathname;
            if (path === '/oficina/comex/bookings') return route.fulfill({ contentType: 'text/html', body: readFileSync(resolve(root, 'storage/app/ui/oficina/bookings.html')) });
            if (path.startsWith('/build/') || path.startsWith('/brand/folios/')) {
                try { return route.fulfill({ body: readFileSync(resolve(root, `public${path}`)), contentType: ({ '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml' })[extname(path)] || 'application/octet-stream' }); } catch { return route.fulfill({ status: 404, body: '' }); }
            }
            let json = {};
            if (path === '/api/acceso-oficina') json = { usuario: identity };
            if (path.endsWith('/contexto')) json = { permisos: { gestionar: true, aprobar: false }, data: {} };
            if (path === '/api/comex/comercial/bookings') json = p([booking]);
            if (path.endsWith('/bookings/booking')) json = { data: detail };
            if (path.endsWith('/historial')) json = p([]);
            if (path.endsWith('/notas')) json = p([{ id: 'note', folio: 'NV-1', lineas: [{ id: 'line', sku: 'CHERRY', descripcion: 'Santina 5 kg', saldo: '120', unidad: 'caja' }] }]);
            if (path.endsWith('/transiciones')) {
                writes.push(req.postDataJSON());
                return route.fulfill(writes.length === 1 ? { status: 503, json: { message: 'Reintente con la misma solicitud.' } } : { json: { data: detail } });
            }
            return route.fulfill({ json });
        });
        const page = await context.newPage(); page.on('pageerror', (e) => errors.push(e.message));
        await page.goto('http://localhost/oficina/comex/bookings'); await page.locator('[data-booking]').click();
        await page.locator('[data-cb-action=asignar]').click(); const form = page.locator('#cbActionForm');
        await form.locator('[name=contenedor_id]').selectOption('container'); await form.locator('[name=linea_id]').selectOption('line');
        await form.locator('[name=cantidad]').fill('50.125'); await form.locator('[name=motivo]').fill('Reserva comercial confirmada por el cliente');
        await form.locator('[type=submit]').click(); await page.waitForFunction(() => document.querySelector('#cbActionForm [data-cc-error]').textContent.includes('Reintente'));
        await form.locator('[type=submit]').click(); await page.waitForFunction(() => !document.getElementById('cbAction').open);
        assert.equal(writes.length, 2); assert.equal(writes[0].operacion_id, writes[1].operacion_id); assert.equal(writes[0].nota_id, 'note'); assert.equal(writes[0].cantidad, '50.125'); assert.equal(writes[0].contenedor_id, 'container'); assert.deepEqual(errors, []); await context.close();
    } finally { await browser.close(); }
});
