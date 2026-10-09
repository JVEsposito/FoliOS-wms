import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { chromium } from 'playwright';

const source = readFileSync(new URL('../../resources/js/office-material-client-transfers.js', import.meta.url), 'utf8');
const blade = readFileSync(new URL('../../resources/views/office/materials.blade.php', import.meta.url), 'utf8');
const history = blade.match(/<section class="panel materials-panel" id="materialClientTransfers"[\s\S]*?<\/section>/)[0];
const dialog = blade.match(/<dialog class="materials-import" id="materialClientTransferDialog"[\s\S]*?<\/dialog>/)[0];
const options = { folio_origen_id: 'origin', numero_folio: 'FAA0000001', unidad_medida: 'rollos', cantidad_actual: 100, disponible_bodega: 100, reservado_bodega: 0, en_centros_costo: 0, tiene_reservas_activas: false,
    clientes: [{ id: 'B', codigo: 'BB', nombre: 'Cliente B', item_sugerido_id: 'item-B', items: [{ id: 'item-B', codigo: 'FILM', nombre: 'Film B', unidad_medida: 'rollos' }] }, { id: 'C', codigo: 'CC', nombre: 'Cliente sin ítems', item_sugerido_id: null, items: [] }] };
const transfer = { id: 'transfer', modalidad: 'parcial', folio_origen: { id: 'origin', numero_folio: 'FAA0000001' }, folio_destino: { id: 'destination', numero_folio: 'FBB0000001', estado_operacional: 'pendiente_ubicacion' }, cliente_origen: { codigo: 'AA' }, cliente_destino: { codigo: 'BB' } };

test('transferencia de Oficina sugiere ítem, explica posición, conserva UUID al reintentar e imprime solo el destino', async () => {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
    try {
        const context = await browser.newContext(); const page = await context.newPage(); const posts = []; const errors = [];
        page.on('pageerror', (e) => errors.push(e.message));
        await context.route('http://localhost/**', async (route) => {
            const request = route.request(); const path = new URL(request.url()).pathname;
            if (path === '/module.js') return route.fulfill({ body: source, contentType: 'text/javascript' });
            if (path === '/test') return route.fulfill({ body: `<table><tbody id="materialsInventoryBody"><tr><td><button data-transfer-client="origin">Transferir a otro cliente</button></td></tr></tbody></table>${history}${dialog}`, contentType: 'text/html' });
            if (path.endsWith('/opciones')) return route.fulfill({ json: { data: options } });
            if (path.endsWith('/transferir-cliente')) {
                posts.push(request.postDataJSON());
                if (posts.length === 1) return route.fulfill({ status: 503, json: { message: 'Reintenta la transferencia.' } });
                return route.fulfill({ json: { data: transfer } });
            }
            if (path.endsWith('/perfiles-impresion')) return route.fulfill({ json: { data: [{ id: 'profile', nombre: 'Perfil de planta', ancho_mm: 100, alto_mm: 80, predeterminado: true }] } });
            if (path.endsWith('/etiquetas')) {
                posts.push(request.postDataJSON());
                return route.fulfill({ body: '^XA^FDFBB0000001^FS^XZ', contentType: 'application/zpl', headers: { 'Content-Disposition': 'attachment; filename="transferencia.zpl"' } });
            }
            if (path.endsWith('/transferencias-clientes')) return route.fulfill({ json: { data: [], meta: { current_page: 1, last_page: 1 } } });
            return route.fulfill({ status: 404 });
        });
        await page.goto('http://localhost/test');
        await page.evaluate(async () => {
            const { createClientTransfers } = await import('/module.js');
            const api = async (path, init = {}) => { const r = await fetch(path, { ...init, headers: { 'Content-Type': 'application/json', Authorization: 'Bearer private-token' } }); const data = await r.json(); if (!r.ok) throw new Error(data.message); return data; };
            window.refreshes = 0;
            window.transfers = createClientTransfers({ api, getIdentity: () => ({ puede_transferir_folios_materiales_clientes: true, puede_imprimir_etiquetas_materiales: true }), getClients: () => [], getToken: () => 'private-token', uuid: () => crypto.randomUUID(), onRefresh: async () => { window.refreshes++; } });
            await window.transfers.refresh();
        });
        await page.getByRole('button', { name: 'Transferir a otro cliente' }).click();
        await page.locator('#materialClientTransferDialog').waitFor({ state: 'visible' });
        await page.locator('[name="cliente_destino_id"]').selectOption('C');
        assert.match(await page.locator('[data-transfer-error]').textContent(), /no tiene un ítem compatible/);
        assert.equal(await page.getByRole('button', { name: 'Confirmar transferencia' }).isDisabled(), true);
        await page.locator('[name="cliente_destino_id"]').selectOption('B');
        assert.equal(await page.locator('[name="item_destino_id"]').inputValue(), 'item-B');
        assert.match(await page.locator('[data-transfer-mode]').textContent(), /Transferencia total/);
        await page.locator('[name="cantidad"]').fill('40');
        assert.match(await page.locator('[data-transfer-mode]').textContent(), /Transferencia parcial/);
        await page.locator('[name="motivo"]').fill('Préstamo autorizado entre clientes.');
        await page.getByRole('button', { name: 'Confirmar transferencia' }).click();
        await page.waitForFunction(() => document.querySelector('[data-transfer-error]')?.textContent.includes('Reintenta'));
        await page.getByRole('button', { name: 'Confirmar transferencia' }).click();
        await page.getByRole('heading', { name: 'Folio nuevo: FBB0000001' }).waitFor();
        assert.equal(posts[0].operacion_id, posts[1].operacion_id);
        assert.equal(posts[1].item_destino_id, 'item-B');
        assert.equal(posts[1].cantidad, 40);
        await page.locator('[name="formato"]').selectOption('zpl');
        await page.getByRole('button', { name: 'Imprimir etiqueta', exact: true }).click();
        await page.waitForFunction(() => document.querySelector('[data-print-error]')?.textContent.includes('Etiqueta generada'));
        assert.deepEqual(posts[2].folio_ids, ['destination']);
        assert.equal(posts[2].perfil_id, 'profile');
        assert.equal(posts[2].formato, 'zpl');
        assert.equal(await page.evaluate(() => window.refreshes), 1);
        assert.deepEqual(errors, []);
        await context.close();
    } finally { await browser.close(); }
});
