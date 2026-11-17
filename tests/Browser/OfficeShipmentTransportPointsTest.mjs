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
// El servidor ordena los países por nombre; Chile no viene primero.
const paises = [['ar', 'AR', 'Argentina'], ['cl', 'CL', 'Chile'], ['cn', 'CN', 'China'], ['mn', 'MN', 'Mongolia']].map(([id, iso_alpha2, nombre_es]) => ({ id, iso_alpha2, nombre_es }));
const puertos = [['arbue', 'ar', 'ARBUE', 'Buenos Aires', 'maritimo'], ['cre', 'ar', 'PAS-AR-CRE', 'Cristo Redentor (Mendoza – Los Andes)', 'terrestre'],
    ['scl', 'cl', 'AIR-SCL', 'Arturo Merino Benítez (Santiago)', 'aereo'], ['lli', 'cl', 'PAS-LLI', 'Los Libertadores (Los Andes – Mendoza)', 'terrestre'],
    ['sai', 'cl', 'CLSAI', 'San Antonio', 'maritimo'], ['pvg', 'cn', 'AIR-PVG', 'Pudong (Shanghái)', 'aereo'], ['sha', 'cn', 'CNSHA', 'Shanghái', 'maritimo']]
    .map(([id, pais_id, codigo, nombre, tipo]) => ({ id, pais_id, codigo, nombre, tipo }));
const shipment = { id: 'e1', codigo: 'EMC0000007', estado: 'tentativo', version: 2, modalidad: 'aereo', fecha_programada: day, hora_programada: '11:00', intervalo_minutos: 60,
    cliente: { id: 'c1', codigo: 'MC', nombre: 'Cliente' }, puerto_embarque_id: 'scl', totales: { instructivos: 1 },
    instructivos: [{ id: 'i1', numero_externo: 'INS-9', pais_destino_id: 'cn', puerto_destino_id: 'pvg', cantidad_pallets: 4 }] };

test('calendario: la modalidad filtra puertos, aeropuertos y pasos; Chile va primero y todo país se puede elegir', async () => {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
    try {
        const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
        const identity = { id: 1, nombre: 'Despacho', rol: 'despachador', puede_consultar_catalogo_cargas: true, modulos_acceso: ['frigorifico.cargas'] };
        await context.addInitScript((user) => {
            localStorage.setItem('estiba_wms_office_token', 'fixture');
            localStorage.setItem('estiba_wms_office_identity', JSON.stringify(user));
        }, identity);
        const errors = [];
        await context.route('http://localhost/**', async (route) => {
            const path = new URL(route.request().url()).pathname;
            if (path === '/oficina/frigorifico/despacho/calendario') return route.fulfill({ body: html, contentType: 'text/html' });
            if (path.startsWith('/build/') || path.startsWith('/brand/')) {
                try { return route.fulfill({ body: readFileSync(resolve(root, `public${path}`)), contentType: ({ '.js': 'text/javascript', '.css': 'text/css', '.woff2': 'font/woff2', '.svg': 'image/svg+xml' })[extname(path)] || 'application/octet-stream' }); }
                catch { return route.fulfill({ status: 404, body: '' }); }
            }
            if (path === '/api/acceso-oficina') return route.fulfill({ json: { usuario: identity } });
            if (path === '/api/embarques') {
                const ventanas = [['09:00', true, []], ['11:00', false, [{ id: 'e1', codigo: shipment.codigo }]]].map(([hora, disponible, embarques]) => ({
                    fecha: day, hora, inicio: `${day}T${hora}:00Z`, fin: `${day}T${hora}:00Z`, disponible, ocupada_por: [], embarques, solicitudes: [] }));
                return route.fulfill({ json: { temporada: { codigo: 'T27', intervalo_embarques_minutos: 60 }, ventanas, embarques: [shipment],
                    catalogos: { clientes: [{ id: 'c1', codigo: 'MC', nombre: 'Cliente', codigo_folio_materiales: 'MC' }], camaras: [], andenes: [], paises, puertos },
                    permisos: { gestionar: true, autorizar_sobrecupo: false } } });
            }
            if (path === '/api/embarques/e1') return route.fulfill({ json: { data: shipment } });
            if (path === '/api/solicitudes-carga-clientes') return route.fulfill({ json: { data: [] } });
            return route.fulfill({ json: {} });
        });
        const page = await context.newPage(); page.on('pageerror', (error) => errors.push(error.message));
        await page.goto('http://localhost/oficina/frigorifico/despacho/calendario');
        await page.locator('[data-open-window][data-time="09:00"]').click();
        await page.locator('#shipmentDialog[open], dialog[open]').first().waitFor();

        const form = page.locator('dialog[open] form');
        const departure = form.locator('[name="puerto_embarque_id"]');
        const country = form.locator('[data-field="pais_destino_id"]').first();
        const destination = form.locator('[data-field="puerto_destino_id"]').first();
        const groups = () => departure.locator('optgroup').evaluateAll((nodes) => nodes.map((group) => [group.label, [...group.querySelectorAll('option')].map((option) => option.textContent)]));
        const labels = (select) => select.locator('option').allTextContents();

        // Marítimo: solo puertos, con Chile primero aunque el servidor ordene por nombre.
        assert.deepEqual(await groups(), [['CL · Chile', ['Puerto · San Antonio (CLSAI)']], ['AR · Argentina', ['Puerto · Buenos Aires (ARBUE)']], ['CN · China', ['Puerto · Shanghái (CNSHA)']]]);
        assert.deepEqual(await labels(country), ['Selecciona país', 'AR · Argentina', 'CL · Chile', 'CN · China', 'MN · Mongolia']);
        await country.selectOption('ar');
        assert.deepEqual(await labels(destination), ['Selecciona punto de destino', 'Puerto · Buenos Aires (ARBUE)']);

        // Terrestre: los pasos de salida chilenos y la contraparte argentina como destino.
        await form.locator('[name="modalidad"]').selectOption('terrestre');
        assert.deepEqual(await groups(), [['CL · Chile', ['Paso · Los Libertadores (Los Andes – Mendoza) (PAS-LLI)']], ['AR · Argentina', ['Paso · Cristo Redentor (Mendoza – Los Andes) (PAS-AR-CRE)']]]);
        assert.deepEqual(await labels(destination), ['Selecciona punto de destino', 'Paso · Cristo Redentor (Mendoza – Los Andes) (PAS-AR-CRE)']);
        // El punto de salida está en «Datos compartidos del transporte».
        await form.locator('.shipment-details summary').click();
        await departure.selectOption('lli'); await destination.selectOption('cre');
        // Por confirmar muestra todos los tipos y conserva lo elegido.
        await form.locator('[name="modalidad"]').selectOption('por_confirmar');
        assert.equal(await departure.inputValue(), 'lli'); assert.equal(await destination.inputValue(), 'cre');
        assert.equal((await labels(destination)).length, 3);
        // Un país sin puntos se puede declarar y lo dice.
        await form.locator('[name="modalidad"]').selectOption('terrestre');
        await country.selectOption('mn');
        assert.deepEqual(await labels(destination), ['Sin pasos registrados para este país']);
        await page.locator('#dismissShipment').click();

        // Un embarque aéreo guardado abre con su aeropuerto de salida y de destino.
        await page.locator('[data-shipment-action="e1"]').selectOption('view');
        await page.waitForFunction(() => document.querySelector('dialog[open] [name="puerto_embarque_id"]')?.value === 'scl');
        assert.equal(await form.locator('[name="modalidad"]').inputValue(), 'aereo');
        assert.equal(await destination.inputValue(), 'pvg');
        assert.deepEqual(await groups(), [['CL · Chile', ['Aeropuerto · Arturo Merino Benítez (Santiago) (AIR-SCL)']], ['CN · China', ['Aeropuerto · Pudong (Shanghái) (AIR-PVG)']]]);
        assert.deepEqual(errors, []);
        await context.close();
    } finally { await browser.close(); }
});
