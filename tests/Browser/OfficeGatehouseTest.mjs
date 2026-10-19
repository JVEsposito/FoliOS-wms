import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve, extname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
const html = readFileSync(resolve(root, 'storage/app/ui/oficina/porteria.html'), 'utf8');
const clientId = '00000000-0000-4000-8000-000000000001';
const entry = { id: 1, codigo: 'PORT-0000001', cliente_id: clientId, cliente_nombre: 'Cliente Prueba', cliente_es_otro: false, motivo: 'carga', patente: 'ABCD12', patente_carro: null, tipo_camion: 'termo', nombre_conductor: 'Conductor Prueba', guias: ['000123','000124'], cantidad: null, unidad: null, observacion: null, ingreso_at: '2026-10-19T15:00:00Z', creado_por_nombre: 'Guardia Prueba', revisado_at: null, anulado_at: null, version: 1 };
const launch = () => chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });

async function setup(browser, { romana = false, handler, width = 390 } = {}) {
    const context = await browser.newContext({ viewport: { width, height: 820 }, acceptDownloads: true });
    const identity = { id: 999, nombre: romana ? 'Operador Prueba' : 'Guardia Prueba', rol: romana ? 'operador_romana' : 'guardia_porteria', modulos_acceso: romana ? ['materia-prima.romana'] : ['materia-prima.porteria'], puede_consultar_romana: romana, puede_consultar_porteria: true, capacidades: { puede_consultar_porteria: true } };
    await context.addInitScript((user) => {
        localStorage.setItem('estiba_wms_office_token', 'token_porteria_fixture');
        localStorage.setItem('estiba_wms_office_identity', JSON.stringify(user));
    }, identity);
    let rows = [];
    await context.route('http://localhost/**', async (route) => {
        const url = new URL(route.request().url()); const path = url.pathname;
        if (path === '/oficina/porteria') return route.fulfill({ body: html, contentType: 'text/html' });
        if (path.startsWith('/brand/folios/')) return route.fulfill({ body: readFileSync(resolve(root, `public${path}`)), contentType: 'image/svg+xml' });
        if (path.startsWith('/build/')) {
            try { return route.fulfill({ body: readFileSync(resolve(root, `public${path}`)), contentType: ({ '.js': 'text/javascript', '.css': 'text/css', '.woff2': 'font/woff2' })[extname(path)] || 'application/octet-stream' }); }
            catch { return route.fulfill({ status: 404, body: '' }); }
        }
        if (path === '/api/acceso-oficina') return route.fulfill({ json: { usuario: identity } });
        if (path === '/api/oficina/contexto') return route.fulfill({ json: { data: { planta: 'Planta Prueba', temporada: null, avisos_cierre: [], avisos_materiales: [] } } });
        if (path === '/api/porteria/catalogos') return route.fulfill({ json: { clientes: [{ id: clientId, nombre: 'Cliente Prueba' }], ahora: '2026-10-19T15:00:00Z' } });
        if (path.startsWith('/api/porteria/') || path.startsWith('/api/romana/porteria/')) {
            if (handler) {
                const handled = await handler(route, { rows, setRows: (value) => { rows = value; } });
                if (handled) return;
            }
            if (path === '/api/porteria/ingresos' && route.request().method() === 'GET') return route.fulfill({ json: { data: rows, pagina: 1, ultima_pagina: 1, total: rows.length, fecha: '2026-10-19', puede_registrar: !romana, puede_administrar: false, puede_revisar: romana, puede_descargar_pdf: romana } });
        }
        return route.fulfill({ status: 404, body: '' });
    });
    const page = await context.newPage();
    await page.goto(`http://localhost/oficina/porteria${romana ? '?romana=1' : ''}`);
    await page.waitForFunction(() => document.getElementById('gateCount')?.textContent.includes('ingresos'));
    return { context, page };
}

test('PDA: varias guías y reintento tras recargar conservan el mismo ingreso', async () => {
    const browser = await launch();
    try {
        const calls = [];
        const { context, page } = await setup(browser, { width: 360, handler: async (route, state) => {
            if (route.request().method() !== 'POST') return false;
            calls.push(route.request().postDataJSON());
            if (calls.length === 1) { await route.abort('failed'); return true; }
            state.setRows([entry]); await route.fulfill({ status: 201, json: { data: entry } }); return true;
        } });
        await page.locator('#gateClient').selectOption(clientId);
        await page.locator('#gateForm [name="patente"]').fill('ab-cd12');
        await page.locator('#gateForm [name="nombre_conductor"]').fill('Conductor Prueba');
        await page.locator('#gateGuideRows input').fill('000123');
        await page.locator('#gateAddGuide').click(); await page.locator('#gateGuideRows input').last().fill('000124');
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), true);
        await page.evaluate(() => { document.activeElement?.blur(); window.scrollTo(0, 0); });
        await page.screenshot({ path: resolve(root, 'storage/app/ui/oficina/porteria-mobile.png'), fullPage: true });
        await page.locator('#gateSubmit').click();
        await page.waitForFunction(() => document.getElementById('gateFormError').textContent.includes('Sin conexión'));
        await page.reload();
        await page.waitForFunction(() => document.getElementById('gateFields').disabled);
        await page.locator('#gateSubmit').click();
        await page.waitForFunction(() => document.getElementById('gateStatus').textContent.includes('guardado correctamente'));
        assert.deepEqual(calls[0], calls[1]);
        assert.deepEqual(calls[1].guias, ['000123', '000124']);
        assert.equal(calls[1].patente, 'ABCD12');
        assert.equal(await page.evaluate(() => sessionStorage.getItem('folios_porteria_intento_999')), null);
        assert.equal(await page.locator('#gatePdf').isHidden(), true);
        await context.close();
    } finally { await browser.close(); }
});

test('celular: Otro y servicio sin guía conservan el texto y permiten registrar', async () => {
    const browser = await launch();
    try {
        let received;
        const { context, page } = await setup(browser, { handler: async (route) => {
            if (route.request().method() !== 'POST') return false;
            received = route.request().postDataJSON();
            await route.fulfill({ status: 201, json: { data: { ...entry, cliente_id: null, cliente_es_otro: true, cliente_nombre: received.cliente_otro, guias: [] } } }); return true;
        } });
        await page.locator('#gateClient').selectOption('otro');
        await page.locator('#gateForm [name="cliente_otro"]').fill('Servicio de mantención');
        await page.locator('#gateForm [name="motivo"]').selectOption('servicio');
        await page.locator('#gateForm [name="patente"]').fill('ZZZZ99');
        await page.locator('#gateForm [name="nombre_conductor"]').fill('Conductor Servicio');
        await page.locator('#gateSubmit').click();
        await page.waitForFunction(() => document.getElementById('gateStatus').textContent.includes('guardado correctamente'));
        assert.equal(received.cliente_id, null); assert.equal(received.cliente_otro, 'Servicio de mantención'); assert.deepEqual(received.guias, []);
        await context.close();
    } finally { await browser.close(); }
});

test('Romana: revisa pendientes y descarga el PDF de la fecha seleccionada', async () => {
    const browser = await launch();
    try {
        let reviewed = false; let pdfRequest = null;
        const { context, page } = await setup(browser, { romana: true, width: 1280, handler: async (route, state) => {
            const path = new URL(route.request().url()).pathname;
            if (path.endsWith('/revisar')) { assert.equal(route.request().postDataJSON().version, 1); reviewed = true; state.setRows([]); await route.fulfill({ json: { data: { ...entry, revisado_at: '2026-10-19T16:00:00Z' } } }); return true; }
            if (path.endsWith('/diario.pdf')) { pdfRequest = route.request(); await route.fulfill({ body: '%PDF-1.4\n%%EOF', contentType: 'application/pdf' }); return true; }
            if (!reviewed) state.setRows([entry]);
            return false;
        } });
        assert.equal(await page.locator('#gateCapture').isHidden(), true);
        assert.equal(await page.locator('#gatePending').isChecked(), true);
        assert.ok((await page.locator('.gate-columns > .gate-panel').last().boundingBox()).width > 800);
        assert.ok((await page.locator('#gatePending').boundingBox()).width <= 32);
        await page.screenshot({ path: resolve(root, 'storage/app/ui/oficina/porteria-romana.png'), fullPage: true });
        await page.locator('[data-review="1"]').click();
        await page.waitForFunction(() => document.getElementById('gateCount').textContent.startsWith('0 '));
        await page.locator('#gateDate').fill('2026-10-18');
        const download = page.waitForEvent('download'); await page.locator('#gatePdf').click();
        assert.equal((await download).suggestedFilename(), 'ingresos-porteria-2026-10-18.pdf');
        assert.equal(new URL(pdfRequest.url()).searchParams.get('fecha'), '2026-10-18');
        assert.equal(pdfRequest.headers().authorization, 'Bearer token_porteria_fixture');
        await context.close();
    } finally { await browser.close(); }
});
