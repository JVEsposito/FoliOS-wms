import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { extname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
const html = readFileSync(resolve(root, 'storage/app/ui/oficina/calendario-embarques.html'), 'utf8');
const day = new Date().toLocaleDateString('en-CA');

test('el calendario sugiere recibidores por cliente y recupera los nuevos después de guardar y recargar', async () => {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
    try {
        const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
        const identity = { nombre: 'Despacho', rol: 'despachador', puede_consultar_catalogo_cargas: true };
        await context.addInitScript((user) => {
            localStorage.setItem('estiba_wms_office_token', 'fixture');
            localStorage.setItem('estiba_wms_office_identity', JSON.stringify(user));
        }, identity);
        const clients = ['c1', 'c2'].map((id) => ({ id, codigo: id, nombre: `Cliente ${id}`, codigo_folio_materiales: id }));
        const receivers = [
            { cliente_id: 'c1', nombre: 'UPAM FRESH LLC' },
            { cliente_id: 'c1', nombre: '<img src=x onerror="window.receiverXss=1">' },
            { cliente_id: 'c2', nombre: 'Recibidor de otro cliente' },
        ];
        let shipment = null;
        let rejectSave = true;
        const savedPayloads = [];
        await context.route('http://localhost/**', async (route) => {
            const path = new URL(route.request().url()).pathname;
            if (path === '/oficina/frigorifico/despacho/calendario') return route.fulfill({ body: html, contentType: 'text/html' });
            if (path.startsWith('/build/') || path.startsWith('/brand/')) {
                return route.fulfill({ path: resolve(root, `public${path}`), contentType: ({ '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml', '.woff2': 'font/woff2' })[extname(path)] || 'application/octet-stream' });
            }
            if (path === '/api/acceso-oficina') return route.fulfill({ json: { usuario: identity } });
            if (path === '/api/embarques' && route.request().method() === 'POST') {
                const payload = route.request().postDataJSON();
                savedPayloads.push(payload);
                if (rejectSave) return route.fulfill({ status: 422, json: { message: 'Revisa la solicitud antes de guardar.' } });
                shipment = { ...payload, id: 'saved', codigo: 'ERC0000001', version: 1, estado: 'tentativo', intervalo_minutos: 60,
                    cliente: clients.find((client) => client.id === payload.cliente_id),
                    instructivos: payload.instructivos.map((row, index) => ({ ...row, id: `i${index}` })), totales: { instructivos: payload.instructivos.length } };
                for (const row of shipment.instructivos) receivers.push({ cliente_id: shipment.cliente_id, nombre: row.recibidor });
                return route.fulfill({ status: 201, json: { data: shipment } });
            }
            if (path === '/api/embarques') return route.fulfill({ json: {
                temporada: { codigo: 'TEMP-2627', intervalo_embarques_minutos: 60 },
                ventanas: [{ fecha: day, hora: '09:00', disponible: true, ocupada_por: [], embarques: shipment ? [shipment] : [] }],
                embarques: shipment ? [shipment] : [],
                catalogos: { clientes: clients, recibidores: receivers, camaras: [], andenes: [], paises: [], puertos: [] },
                permisos: { gestionar: true, autorizar_sobrecupo: false },
            } });
            if (path === '/api/embarques/saved') return route.fulfill({ json: { data: shipment } });
            if (path === '/api/solicitudes-carga-clientes') return route.fulfill({ json: { data: [] } });
            return route.fulfill({ json: {} });
        });
        const page = await context.newPage();
        const errors = [];
        page.on('pageerror', (error) => errors.push(error.message));
        const url = 'http://localhost/oficina/frigorifico/despacho/calendario';
        await page.goto(url);
        await page.locator('[data-open-window]').first().click();
        const client = page.locator('#shipmentForm [name="cliente_id"]');
        const inputs = page.locator('#instructionList [data-field="recibidor"]');
        const names = () => page.locator('#shipmentReceiverOptions option').evaluateAll((options) => options.map((option) => option.value));
        assert.deepEqual(await names(), []);
        await client.selectOption('c1');
        assert.deepEqual(await names(), receivers.filter((row) => row.cliente_id === 'c1').map((row) => row.nombre));
        assert.equal(await page.locator('#shipmentReceiverOptions img').count(), 0);
        assert.equal(await page.evaluate(() => window.receiverXss), undefined);
        await inputs.first().fill('UPAM FRESH LLC');
        await page.locator('#addInstruction').click();
        assert.equal(await inputs.count(), 2);
        assert.equal(await inputs.nth(1).getAttribute('list'), 'shipmentReceiverOptions');
        await inputs.nth(1).fill('Recibidor nuevo');
        await client.selectOption('c2');
        assert.deepEqual(await names(), ['Recibidor de otro cliente']);
        assert.equal(await inputs.nth(1).inputValue(), 'Recibidor nuevo');
        await client.selectOption('c1');

        await page.locator('#saveShipment').click();
        await page.locator('#shipmentFormError').getByText('Revisa la solicitud antes de guardar.', { exact: true }).waitFor();
        assert.equal((await names()).includes('Recibidor nuevo'), false);
        assert.equal(await inputs.nth(1).inputValue(), 'Recibidor nuevo');
        rejectSave = false;
        await page.locator('#saveShipment').click();
        await page.locator('#shipmentDialog').waitFor({ state: 'hidden' });
        assert.deepEqual(savedPayloads.at(-1).instructivos.map((row) => row.recibidor), ['UPAM FRESH LLC', 'Recibidor nuevo']);
        await page.reload();
        await page.locator('[data-open-window]').first().click();
        await client.selectOption('c1');
        assert.ok((await names()).includes('Recibidor nuevo'));
        await page.locator('#dismissShipment').click();
        await page.locator('[data-shipment-action="saved"]').selectOption('view');
        await page.waitForFunction(() => document.querySelector('#shipmentDialog[open] [name="cliente_id"]')?.value === 'c1');
        assert.equal(await inputs.nth(1).inputValue(), 'Recibidor nuevo');
        assert.ok((await names()).includes('Recibidor nuevo'));
        assert.deepEqual(errors, []);
        await context.close();
    } finally {
        await browser.close();
    }
});
