import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { extname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
const html = readFileSync(resolve(root, 'storage/app/ui/oficina/portal-clientes.html'), 'utf8');
const clients = [{ id: 'c1', codigo: 'AGU', nombre: 'La Aguada' }, { id: 'c2', codigo: 'LAG', nombre: 'Lagunilla' }];
const identity = { id: 9, nombre: 'Comex', email: 'comex@laaguada.cl', rol: 'cliente', es_cliente: true, debe_cambiar_password: false, clientes: clients, inicio: '/oficina/clientes' };
const today = new Date(); const pad = (n) => String(n).padStart(2, '0');
const day = `${today.getFullYear()}-${pad(today.getMonth() + 1)}-${pad(today.getDate())}`;

async function setup(browser, { width = 1280, session = true, internal = false } = {}) {
    const context = await browser.newContext({ viewport: { width, height: 900 } });
    if (session) {
        await context.addInitScript((user) => {
            localStorage.setItem('estiba_wms_office_token', 'fixture');
            localStorage.setItem('estiba_wms_office_identity', JSON.stringify(user));
        }, internal ? { rol: 'administrador', inicio: '/oficina/administracion' } : identity);
    }
    const calls = []; const writes = []; let requests = [];
    await context.route('http://localhost/**', async (route) => {
        const request = route.request(); const url = new URL(request.url()); const path = url.pathname;
        calls.push(`${request.method()} ${path}${url.search}`);
        if (path === '/oficina/clientes') return route.fulfill({ body: html, contentType: 'text/html' });
        if (path.startsWith('/build/') || path.startsWith('/brand/')) {
            try { return route.fulfill({ body: readFileSync(resolve(root, `public${path}`)), contentType: ({ '.js': 'text/javascript', '.css': 'text/css', '.woff2': 'font/woff2', '.svg': 'image/svg+xml' })[extname(path)] || 'application/octet-stream' }); }
            catch { return route.fulfill({ status: 404, body: '' }); }
        }
        if (path === '/api/acceso-oficina' && request.method() === 'POST') return route.fulfill({ json: { token: 'nuevo', usuario: identity } });
        if (path === '/api/portal/contexto') {
            if (internal) return route.fulfill({ status: 403, json: { message: 'No autorizado' } });
            return route.fulfill({ json: { usuario: identity, planta: 'Prueba', temporada: { nombre: '2026-2027', codigo: 'T27' } } });
        }
        const base = path.match(/^\/api\/portal\/clientes\/(c\d)(\/.*)$/);
        if (base) {
            const [, client, rest] = base;
            if (rest === '/resumen') return route.fulfill({ json: { data: { kilos_temporada: client === 'c1' ? 20000.5 : 777, recepciones_temporada: 2, kilos_ultimos_7_dias: 8000.5, folios_en_planta: 1, cajas_en_planta: 120, embarques_proximos: 1, solicitudes_pendientes: 0, items_materiales: 3 } } });
            if (rest === '/recepciones') return route.fulfill({ json: { data: { totales: { kilos: 20000.5, recepciones: 2, por_especie: [{ especie: 'Cereza', kilos: 20000.5, recepciones: 2 }] },
                recepciones: { data: [{ id: 'r1', numero_recepcion: 'REC-2', ingreso_at: '2026-11-10T09:00:00Z', especie: 'Cereza', kilos_netos: 8000.5, guias: ['1512', '1513'],
                    lotes: [{ numero_lote: 'L-7', variedad: 'Santina', csg: '001234', kilos: 4000, guia: '1512' }] }], pagina_actual: 1, ultima_pagina: 1, total: 1 } } } });
            if (rest === '/embarques') return route.fulfill({ json: { data: [{ codigo: 'LA-0000012', fecha: day, hora: '10:00', estado: 'confirmado', modalidad: 'maritimo', nave_vuelo: 'MSC Ana', puerto_embarque: 'San Antonio', contenedor: 'MSCU123', referencia: 'BK-1',
                instructivos: [{ numero: 'INS-1', booking: 'BK-1', destino: 'Shanghái, China', pallets: 20, cajas: 2400 }], carga: { estado: 'en_preparacion', pallets_asignados: 20, pallets_cargados: 8 } }] } });
            if (rest.startsWith('/horarios')) return route.fulfill({ json: { data: [{ fecha: day, hora: '09:00', fin: '10:00', estado: 'ocupado' }, { fecha: day, hora: '10:00', fin: '11:00', estado: 'libre' }, { fecha: day, hora: '11:00', fin: '12:00', estado: 'propio' }], intervalo_minutos: 60 } });
            if (rest === '/solicitudes-carga' && request.method() === 'POST') {
                const body = request.postDataJSON(); writes.push(body);
                requests = [{ id: 's1', codigo: 'SC-00001', fecha: body.fecha, hora: body.hora, estado: 'solicitada', modalidad: body.modalidad, cantidad_pallets: body.cantidad_pallets, referencia: body.referencia, version: 1 }];
                return route.fulfill({ status: 201, json: { data: requests[0] } });
            }
            if (rest === '/solicitudes-carga') return route.fulfill({ json: { data: requests } });
            if (rest === '/solicitudes-carga/s1/anular') { writes.push(request.postDataJSON()); requests = [{ ...requests[0], estado: 'anulada', version: 2 }]; return route.fulfill({ json: { data: requests[0] } }); }
            if (rest.startsWith('/folios')) return route.fulfill({ json: { data: [{ numero_folio: '6000000785', tipo: 'pallet', estado: 'disponible', especie: 'Cereza', variedad: 'Santina', calibre: '2J', marca: 'FALCO FRUIT', envase: 'Caja 5 kg', categoria: 'Exportación', cajas: 81,
                csg: [{ csg: '164539', cajas: 24 }, { csg: '3115673', cajas: 57 }], fecha_proceso: day, en_planta_desde: '2026-11-09T17:50:00Z' }], meta: { pagina_actual: 1, ultima_pagina: 1, total: 1 } } });
            if (rest === '/materiales') return route.fulfill({ json: { data: [{ codigo: 'CJ-5', nombre: 'Caja 5 kg', categoria: 'Envase', unidad: 'unidad', cantidad: 1200, reservada: 200, disponible: 1000 }] } });
        }
        return route.fulfill({ status: 404, json: { message: 'Fuera del fixture' } });
    });
    const page = await context.newPage(); const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await page.goto('http://localhost/oficina/clientes');
    return { context, page, calls, writes, errors };
}

const launch = () => chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });

test('el cliente revisa sus datos y solicita un horario de carga sin salir del portal', async () => {
    const browser = await launch();
    try {
        for (const width of [390, 1280]) {
            const { context, page, calls, writes, errors } = await setup(browser, { width });
            await page.locator('#portalKpis .portal-kpi').first().waitFor();
            assert.match(await page.locator('#portalKpis').innerText(), /20\.000,5 kg/);
            await page.locator('#portalClient').selectOption('c2');
            await page.waitForFunction(() => document.querySelector('#portalKpis').innerText.includes('777'));

            await page.locator('[data-tab="recepciones"]').click();
            await page.locator('#portalReceptions .portal-item').waitFor();
            const receptions = await page.locator('#portalReceptions').innerText();
            assert.match(receptions, /Guías 1512, 1513/);
            assert.match(receptions, /Lote L-7/);

            await page.locator('[data-tab="embarques"]').click();
            await page.locator('#portalShipments .portal-item').waitFor();
            assert.match(await page.locator('#portalShipments').innerText(), /8 de 20 pallets cargados/);

            await page.locator('[data-tab="horarios"]').click();
            await page.locator('[data-slot="10:00"]').waitFor();
            assert.equal(await page.locator('[data-slot="09:00"]').isDisabled(), true);
            assert.equal(await page.locator('[data-slot="11:00"]').isDisabled(), true);
            await page.locator('[data-slot="10:00"]').click();
            await page.locator('#portalRequest [name="modalidad"]').selectOption('maritimo');
            await page.locator('#portalRequest [name="cantidad_pallets"]').fill('20');
            await page.locator('#portalRequest [name="referencia"]').fill('BK-889');
            await page.locator('#portalRequest button[type="submit"]').click();
            await page.locator('#portalRequests [data-cancel="s1"]').waitFor();
            assert.deepEqual({ ...writes[0], operacion_id: undefined }, { operacion_id: undefined, fecha: day, hora: '10:00', modalidad: 'maritimo', cantidad_pallets: 20, referencia: 'BK-889', observacion: null });
            assert.match(writes[0].operacion_id, /^[0-9a-f-]{36}$/);
            await page.locator('#portalRequests [data-cancel="s1"]').click();
            await page.waitForFunction(() => document.querySelector('#portalRequests').innerText.includes('Anulada'));
            assert.deepEqual(writes[1], { version: 1 });

            await page.locator('[data-tab="folios"]').click();
            await page.waitForFunction(() => document.querySelector('#portalFolios').innerText.includes('6000000785'));
            const folios = await page.locator('#portalFolios').innerText();
            assert.match(folios, /6000000785/);
            assert.match(folios, /3115673: 57/);
            await page.locator('#portalFolioFilters [name="q"]').fill('6000000785');
            await page.locator('#portalFolioFilters [name="situacion"]').selectOption('todos');
            const searched = page.waitForResponse((r) => r.url().includes('situacion=todos&q=6000000785'));
            await page.locator('#portalFolioFilters button').click();
            await searched;
            await page.locator('[data-tab="materiales"]').click();
            await page.waitForFunction(() => document.querySelector('#portalMaterials').innerText.includes('CJ-5'));
            assert.match(await page.locator('#portalMaterials').innerText(), /CJ-5/);

            // Todo lo consultado es del portal: nunca rutas internas de la planta.
            assert.ok(calls.filter((c) => c.includes('/api/')).every((c) => c.includes('/api/portal/') || c.includes('/api/acceso-oficina')), calls.join('\n'));
            assert.ok(calls.some((c) => c.includes('/api/portal/clientes/c2/folios?page=1&situacion=todos&q=6000000785')));
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), true, `desborde a ${width}px`);
            assert.deepEqual(errors, []);
            await context.close();
        }
    } finally { await browser.close(); }
});

test('sin sesión pide ingreso y una cuenta interna vuelve a su Oficina', async () => {
    const browser = await launch();
    try {
        const { context, page, errors } = await setup(browser, { session: false });
        await page.locator('#portalLogin').waitFor();
        await page.locator('#portalLogin [name="email"]').fill('comex@laaguada.cl');
        await page.locator('#portalLogin [name="password"]').fill('Temporal2026');
        await page.locator('#portalLogin button[type="submit"]').click();
        await page.locator('#portalKpis .portal-kpi').first().waitFor();
        assert.equal(await page.evaluate(() => localStorage.getItem('estiba_wms_office_token')), 'nuevo');
        assert.deepEqual(errors, []);
        await context.close();

        const internal = await setup(browser, { internal: true });
        await internal.page.waitForFunction(() => document.querySelector('#portalStatus').innerText.includes('usuarios de clientes'));
        assert.equal(await internal.page.locator('#portalStatus a').getAttribute('href'), '/oficina/administracion');
        await internal.context.close();
    } finally { await browser.close(); }
});
