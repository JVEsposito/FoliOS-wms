import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { extname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
const html = readFileSync(resolve(root, 'storage/app/ui/oficina/calendario-embarques.html'), 'utf8');
const today = new Date(); const pad = (n) => String(n).padStart(2, '0');
const day = `${today.getFullYear()}-${pad(today.getMonth() + 1)}-${pad(today.getDate())}`;
const request = (id, hour, version = 1) => ({ id, codigo: `SC-0000${id}`, fecha: day, hora: hour, intervalo_minutos: 60, estado: 'solicitada', modalidad: 'maritimo',
    cantidad_pallets: 20, referencia: 'BK-889', observacion: 'Contenedor 40', version, cliente: { id: 'c1', codigo: 'AGU', nombre: 'La Aguada' }, solicitado_por: 'Comex La Aguada' });

test('despacho ve las solicitudes de clientes en el calendario y las acepta o rechaza', async () => {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
    try {
        const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
        const identity = { id: 1, nombre: 'Despacho', rol: 'despachador', puede_consultar_catalogo_cargas: true, modulos_acceso: ['frigorifico.cargas'] };
        await context.addInitScript((user) => {
            localStorage.setItem('estiba_wms_office_token', 'fixture');
            localStorage.setItem('estiba_wms_office_identity', JSON.stringify(user));
        }, identity);
        let pending = [request('1', '10:00'), request('2', '12:00')];
        const writes = []; const errors = [];
        let firstAccept = true;
        await context.route('http://localhost/**', async (route) => {
            const r = route.request(); const path = new URL(r.url()).pathname;
            if (path === '/oficina/frigorifico/despacho/calendario') return route.fulfill({ body: html, contentType: 'text/html' });
            if (path.startsWith('/build/') || path.startsWith('/brand/')) {
                try { return route.fulfill({ body: readFileSync(resolve(root, `public${path}`)), contentType: ({ '.js': 'text/javascript', '.css': 'text/css', '.woff2': 'font/woff2', '.svg': 'image/svg+xml' })[extname(path)] || 'application/octet-stream' }); }
                catch { return route.fulfill({ status: 404, body: '' }); }
            }
            if (path === '/api/acceso-oficina') return route.fulfill({ json: { usuario: identity } });
            if (path === '/api/embarques') {
                const ventanas = ['09:00', '10:00', '11:00', '12:00'].map((hora) => {
                    const own = pending.filter((p) => p.hora === hora);
                    return { fecha: day, hora, inicio: `${day}T${hora}:00Z`, fin: `${day}T${hora}:00Z`, disponible: own.length === 0, ocupada_por: [], embarques: [],
                        solicitudes: own.map((p) => ({ id: p.id, codigo: p.codigo, cliente: p.cliente.nombre, cantidad_pallets: p.cantidad_pallets })) };
                });
                return route.fulfill({ json: { temporada: { codigo: 'T27', intervalo_embarques_minutos: 60 }, ventanas, embarques: [], catalogos: { clientes: [], camaras: [], andenes: [], paises: [], puertos: [] },
                    permisos: { gestionar: true, autorizar_sobrecupo: true } } });
            }
            if (path === '/api/solicitudes-carga-clientes') return route.fulfill({ json: { data: pending } });
            const action = path.match(/^\/api\/solicitudes-carga-clientes\/(\d)\/(aceptar|rechazar)$/);
            if (action) {
                const body = r.postDataJSON(); writes.push({ id: action[1], action: action[2], body });
                if (action[2] === 'aceptar' && firstAccept) {
                    firstAccept = false;
                    return route.fulfill({ status: 409, json: { message: 'La ventana ya está ocupada. Solo un supervisor puede autorizar un sobrecupo.' } });
                }
                pending = pending.filter((p) => p.id !== action[1]);
                return route.fulfill({ json: { data: { ...request(action[1], '10:00'), estado: action[2] === 'aceptar' ? 'aceptada' : 'rechazada' } } });
            }
            return route.fulfill({ json: {} });
        });
        const page = await context.newPage(); page.on('pageerror', (error) => errors.push(error.message));
        const answers = ['Bloque con andén doble disponible', 'Andén en mantención'];
        page.on('dialog', (dialog) => dialog.accept(answers.shift()));
        await page.goto('http://localhost/oficina/frigorifico/despacho/calendario');
        await page.locator('#clientRequests:not(.is-hidden) [data-request-accept="1"]').waitFor();
        assert.match(await page.locator('#clientRequestList').innerText(), /SC-00001 · La Aguada/);
        assert.equal(await page.locator('.shipment-card--requested').count(), 2);

        // Aceptar en un bloque ocupado ofrece autorizar el sobrecupo con su motivo.
        await page.locator('[data-request-accept="1"]').click();
        await page.waitForFunction(() => !document.querySelector('[data-request-accept="1"]'));
        assert.deepEqual(writes[0], { id: '1', action: 'aceptar', body: { version_esperada: 1 } });
        assert.deepEqual(writes[1], { id: '1', action: 'aceptar', body: { version_esperada: 1, autorizar_sobrecupo: true, motivo_sobrecupo: 'Bloque con andén doble disponible' } });

        await page.locator('[data-request-reject="2"]').click();
        await page.waitForFunction(() => document.querySelector('#clientRequests').classList.contains('is-hidden'));
        assert.deepEqual(writes[2], { id: '2', action: 'rechazar', body: { version_esperada: 1, motivo: 'Andén en mantención' } });
        assert.deepEqual(errors, []);
        await context.close();
    } finally { await browser.close(); }
});
