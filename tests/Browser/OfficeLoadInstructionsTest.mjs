import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve, extname } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
const html = readFileSync(resolve(root, 'storage/app/ui/oficina/cargas.html'), 'utf8');

test('prepara dos instructivos en la misma orden y conserva el grupo en escaneo, inventario y reclasificación', async () => {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
    try {
        const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
        await context.addInitScript(() => {
            localStorage.setItem('estiba_wms_office_token', 'fixture');
            localStorage.setItem('estiba_wms_office_identity', JSON.stringify({ nombre: 'Despacho', rol: 'despachador', puede_consultar_cargas: true, puede_consultar_catalogo_cargas: true, puede_gestionar_cargas: true }));
        });
        const writes = [], errors = [];
        const groups = [1, 2].map(n => ({ id: `instr-${n}`, orden: n, numero_externo: `F00${n}`, booking: `BOOK-${n}`, recibidor: 'UPAM FRESH LLC', pallets_programados: 7, cajas_programadas: 728, pallets_asignados: 0, pallets_pendientes: 7, progreso: {} }));
        const load = { id: 'load', codigo: 'CAR-000001', estado: 'borrador', prioridad: 'normal', version: 1, total_folios: 0, total_programado: 14, segregaciones: groups, folios_sin_instructivo: [], folios: [], distribucion: [], progreso: {}, embarque: { codigo: 'EIN0000001', numeros_externos: ['F001', 'F002'] } };
        await context.route('http://localhost/**', async route => {
            const request = route.request(), path = new URL(request.url()).pathname;
            if (path === '/oficina/frigorifico/despacho/cargas') return route.fulfill({ body: html, contentType: 'text/html' });
            if (path.startsWith('/build/') || path.startsWith('/brand/')) {
                try { return route.fulfill({ body: readFileSync(resolve(root, `public${path}`)), contentType: ({ '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml', '.woff2': 'font/woff2' })[extname(path)] || 'application/octet-stream' }); }
                catch { return route.fulfill({ status: 404 }); }
            }
            if (request.method() === 'POST') {
                assert.ok(['/api/cargas/load/folios', '/api/cargas/load/segregaciones'].includes(path));
                const data = request.postDataJSON(); writes.push(data);
                assert.equal(data.version_esperada, load.version);
                const group = groups.find(g => g.id === data.instructivo_embarque_id); assert.ok(group);
                for (const number of data.folios) {
                    let folio = load.folios.find(f => f.numero_folio === number);
                    if (!folio) { folio = { id: number, numero_folio: number, estado_carga: 'pendiente', tipo_bulto: 'pallet' }; load.folios.push(folio); }
                    folio.instructivo_embarque_id = group.id;
                }
                for (const g of groups) { g.pallets_asignados = load.folios.filter(f => f.instructivo_embarque_id === g.id).length; g.pallets_pendientes = 7 - g.pallets_asignados; }
                load.total_folios = load.folios.length; load.version++;
                return route.fulfill({ json: { data: load } });
            }
            if (path === '/api/cargas') return route.fulfill({ json: { data: [load] } });
            if (path === '/api/cargas/load') return route.fulfill({ json: { data: load } });
            if (path === '/api/cargas/folios-disponibles') return route.fulfill({ json: { data: [{ id: 'PAL-002', numero_folio: 'PAL-002', tipo_bulto: 'pallet' }] } });
            if (path === '/api/oficina/contexto') return route.fulfill({ json: { data: { temporada: null, planta: 'Fixture', avisos_cierre: [], avisos_materiales: [] } } });
            return route.fulfill({ json: { data: [] } });
        });
        const page = await context.newPage();
        page.on('pageerror', error => errors.push(error.message));
        await page.goto('http://localhost/oficina/frigorifico/despacho/cargas');
        await page.locator('[data-load-id="load"]').click();
        await page.locator('#loadInstructions').waitFor({ state: 'visible' });
        assert.equal(await page.locator('#loadTotalFolios').textContent(), '0 / 14');
        assert.equal(await page.locator('.instruction-card').count(), 2);
        await page.locator('#folioInput').fill('PAL-001');
        assert.equal(await page.locator('#addFoliosButton').isDisabled(), true);
        await page.locator('#loadInstructionSelect').selectOption('instr-1');
        assert.equal(await page.locator('#folioInput').inputValue(), '');
        await page.locator('#folioInput').fill('PAL-001');
        await page.locator('#addFoliosButton').click();
        await page.waitForFunction(() => document.querySelector('#loadTotalFolios').textContent === '1 / 14');
        assert.equal(writes[0].instructivo_embarque_id, 'instr-1');
        await page.locator('[data-select-instruction="instr-2"]').click();
        await page.locator('[data-available-folio="PAL-002"]').check();
        await page.locator('#addFoliosButton').click();
        await page.waitForFunction(() => document.querySelector('#loadTotalFolios').textContent === '2 / 14');
        assert.equal(writes[1].instructivo_embarque_id, 'instr-2');
        assert.equal(await page.locator('.instruction-folio-heading').count(), 2);
        await page.locator('[data-segregate-folio="PAL-001"]').selectOption('instr-2');
        await page.waitForFunction(() => document.querySelectorAll('.instruction-folio-heading').length === 1);
        assert.equal(writes[2].instructivo_embarque_id, 'instr-2');
        assert.match(await page.locator('.instruction-folio-heading').textContent(), /F002.*2 pallets.*BOOK-2/);
        assert.deepEqual(errors, []);
        await context.close();
    } finally { await browser.close(); }
});
