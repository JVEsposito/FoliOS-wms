import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { extname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
const launch = () => chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
async function setup(browser, { manage = true } = {}) {
    const context = await browser.newContext({ viewport: { width: 390, height: 900 } });
    const identity = { nombre: 'Comercial de prueba', rol: manage ? 'despachador' : 'consulta', puede_consultar_comercial_comex: true, modulos_acceso: ['comex.comercial'] };
    const writes = []; const errors = [];
    await context.addInitScript((u) => { localStorage.setItem('estiba_wms_office_token', 'comercial_fixture'); localStorage.setItem('estiba_wms_office_identity', JSON.stringify(u)); }, identity);
    await context.route('http://localhost/**', async (route) => {
        const request = route.request(); const path = new URL(request.url()).pathname;
        if (path === '/oficina/comex/comercial') return route.fulfill({ contentType: 'text/html', body: readFileSync(resolve(root, 'storage/app/ui/oficina/comercial.html')) });
        if (path.startsWith('/build/') || path.startsWith('/brand/folios/')) {
            try { return route.fulfill({ body: readFileSync(resolve(root, `public${path}`)), contentType: ({ '.js': 'text/javascript', '.css': 'text/css', '.woff2': 'font/woff2', '.svg': 'image/svg+xml' })[extname(path)] || 'application/octet-stream' }); }
            catch { return route.fulfill({ status: 404, body: '' }); }
        }
        if (path === '/api/acceso-oficina') return route.fulfill({ json: { usuario: identity } });
        if (path === '/api/oficina/contexto') return route.fulfill({ json: { data: { planta: 'Prueba', temporada: { nombre: '2026-2027' } } } });
        if (path === '/api/comex/comercial/contexto') return route.fulfill({ json: { roles: ['vendedor', 'exportador', 'comprador', 'recibidor', 'naviera'], tipos_lugar: ['puerto', 'terminal'], paises: [{ iso2: 'CL', nombre: 'Chile' }, { iso2: 'CN', nombre: 'China' }], permisos: { gestionar: manage, aprobar: false } } });
        if (path === '/api/comex/comercial/partes' && request.method() === 'POST') { writes.push(request.postDataJSON()); if (writes.length === 1) return route.fulfill({ status: 503, json: { message: 'Reintente la misma solicitud.' } }); return route.fulfill({ status: 201, json: { data: { id: 'parte-2', version: 1 } } }); }
        if (['/api/comex/comercial/clientes-operacion', '/api/comex/comercial/lugares'].includes(path)) return route.fulfill({ json: { data: { data: [], current_page: 1, last_page: 1 } } });
        if (path === '/api/comex/comercial/partes') return route.fulfill({ json: { data: { data: [{ id: 'parte-1', codigo: 'TEST', pais_iso2: 'CL', tax_id: '76000001-9', razon_social: 'Actor de prueba', roles: ['vendedor', 'exportador'], activo: true, version: 1 }], current_page: 1, last_page: 1 } } });
        return route.fulfill({ json: {} });
    });
    const page = await context.newPage(); page.on('pageerror', (e) => errors.push(e.message)); await page.goto('http://localhost/oficina/comex/comercial');
    await page.waitForFunction(() => document.getElementById('ccRows').textContent.includes('Actor de prueba'));
    return { page, context, writes, errors };
}
test('crea actor con roles expresos y conserva UUID ante una falla de conexión', async () => {
    const browser = await launch();
    try {
        const { page, context, writes, errors } = await setup(browser);
        await page.locator('#ccNew').click(); const form = page.locator('#ccPartyForm');
        for (const [name, value] of Object.entries({ codigo: 'ACTOR_NUEVO', tax_id: '76000002-7', razon_social: 'Nueva identidad comercial', direccion: 'Dirección comercial de prueba 123', ciudad: 'Rancagua', respaldo: 'Datos comerciales contrastados con sus antecedentes' })) await form.locator(`[name=${name}]`).fill(value);
        await form.locator('[name=pais_iso2]').selectOption('CL'); await form.locator('[name=roles][value=vendedor]').check(); await form.locator('[name=roles][value=exportador]').check();
        await form.locator('button[type=submit]').click(); await page.waitForFunction(() => document.querySelector('#ccPartyForm [data-cc-error]').textContent.includes('Reintente'));
        await form.locator('button[type=submit]').click(); await page.waitForFunction(() => !document.getElementById('ccPartyDialog').open);
        assert.equal(writes.length, 2); assert.equal(writes[0].operacion_id, writes[1].operacion_id); assert.deepEqual(writes[0].roles, ['vendedor', 'exportador']); assert.equal(writes[0].cliente_operacion_id, undefined); assert.equal(writes[0].version, 0); assert.deepEqual(errors, []); await context.close();
    } finally { await browser.close(); }
});
test('consulta comercial puede ver el directorio sin botones de edición', async () => {
    const browser = await launch();
    try { const { page, context, errors } = await setup(browser, { manage: false }); assert.equal(await page.locator('#ccNew').isVisible(), false); assert.equal(await page.locator('[data-cc-action=edit]').count(), 0); assert.match(await page.locator('#ccRows').textContent(), /Exportador legal/); assert.deepEqual(errors, []); await context.close(); }
    finally { await browser.close(); }
});
