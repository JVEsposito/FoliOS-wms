import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve, extname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
const html = readFileSync(resolve(root, 'storage/app/ui/oficina/contraparte-sag.html'), 'utf8');
const folioId = '00000000-0000-4000-8000-000000000001';
const personId = '00000000-0000-4000-8000-000000000002';
const docId = '00000000-0000-4000-8000-000000000003';

async function setup(browser, { consulta = false, width = 390 } = {}) {
    const context = await browser.newContext({ viewport: { width, height: 850 } });
    const identity = { nombre: 'Responsable prueba', rol: consulta ? 'consulta' : 'supervisor_frio', modulos_acceso: ['frigorifico.contraparte-sag'], puede_consultar_contraparte_sag: true, puede_gestionar_contraparte_sag: !consulta };
    await context.addInitScript((user) => { localStorage.setItem('estiba_wms_office_token', 'sag-fixture'); localStorage.setItem('estiba_wms_office_identity', JSON.stringify(user)); }, identity);
    let saved;
    let records = [];
    await context.route('http://localhost/**', async (route) => {
        const path = new URL(route.request().url()).pathname;
        if (path === '/oficina/frigorifico/contraparte-sag') return route.fulfill({ body: html, contentType: 'text/html' });
        if (path.startsWith('/build/') || path.startsWith('/brand/')) {
            try { return route.fulfill({ body: readFileSync(resolve(root, `public${path}`)), contentType: ({ '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml', '.woff2': 'font/woff2' })[extname(path)] || 'application/octet-stream' }); } catch { return route.fulfill({ status: 404 }); }
        }
        if (path === '/api/acceso-oficina') return route.fulfill({ json: { usuario: identity } });
        if (path === '/api/oficina/contexto') return route.fulfill({ json: { data: { planta: 'Planta prueba', temporada: null, avisos_cierre: [], avisos_materiales: [] } } });
        if (path === '/api/contraparte-sag/configuracion') return route.fulfill({ json: { planta: {}, version: 1, puede_gestionar: !consulta, responsables: [{ id: personId, nombre: 'Responsable prueba', cargo: 'tecnica', activo: true }] } });
        if (path === '/api/contraparte-sag/existencias') return route.fulfill({ json: { cajas: 0, unidades: 0, folios: { data: [], current_page: 1, last_page: 1 }, movimientos: { data: [], current_page: 1, last_page: 1 } } });
        if (path === '/api/contraparte-sag/folios/00000123') return route.fulfill({ json: { folio_id: folioId, folio: '00000123', cantidad: 180, especie: 'UVA', tipo_actividad: 'INSPECCIÓN', numero_actividad: '1513', con_condicion: true } });
        if (path === '/api/contraparte-sag/documentos' && route.request().method() === 'POST') {
            saved = route.request().postDataJSON(); const d = { ...saved, id: docId, version: 1, estado: 'borrador', numero: null, detalle: { lineas: [{ folio: '00000123', cantidad: 4, tipo_actividad: 'INSPECCIÓN', numero_actividad: '1513' }] } }; records = [d]; return route.fulfill({ status: 201, json: d });
        }
        if (path === '/api/contraparte-sag/documentos') return route.fulfill({ json: { data: records, current_page: 1, last_page: 1 } });
        if (path === `/api/contraparte-sag/documentos/${docId}`) return route.fulfill({ json: { ...records[0], historial: [], archivos: [] } });
        return route.fulfill({ status: 404, json: { message: 'No disponible en fixture' } });
    });
    const page = await context.newPage(); const ready = page.waitForResponse((r) => new URL(r.url()).pathname === '/api/contraparte-sag/documentos');
    await page.goto('http://localhost/oficina/frigorifico/contraparte-sag'); await ready;
    return { context, page, saved: () => saved };
}

test('contraparte captura anulación parcial desde folio escaneado en celular', async () => {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
    try {
        const { page, context, saved } = await setup(browser);
        await page.locator('[data-panel="documentos"]').click(); await page.locator('#scNewDocument').click();
        await page.locator('#scDocumentForm [name="tipo"]').selectOption('anulacion');
        await page.locator('#scDocumentForm [name="responsable_id"]').selectOption(personId);
        await page.locator('#scAddFolioNumber').fill('00000123'); await page.locator('#scAddFolioNumber').press('Enter');
        await page.locator('[data-field="cantidad"]').fill('4'); await page.locator('[data-field="causal"]').fill('DECISIÓN COMERCIAL');
        await page.locator('#scDocumentForm button.primary-button').click(); await page.waitForSelector('#scDocumentDetail:not(.is-hidden)');
        assert.equal(saved().folios[0].cantidad, 4); assert.equal(saved().folios[0].folio_id, folioId);
        assert.equal(saved().folios[0].numero_actividad, '1513'); assert.ok(saved().operacion_id);
        assert.equal('condicion' in saved().folios[0], false);
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
        await context.close();
    } finally { await browser.close(); }
});

test('consulta no ve controles de escritura ni corte inicial', async () => {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
    try {
        const { page, context } = await setup(browser, { consulta: true, width: 1280 });
        assert.equal(await page.locator('#scInitial').isVisible(), false);
        await page.locator('[data-panel="documentos"]').click(); assert.equal(await page.locator('#scNewDocument').isVisible(), false);
        await context.close();
    } finally { await browser.close(); }
});
