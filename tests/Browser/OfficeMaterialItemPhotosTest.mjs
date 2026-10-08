import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
const html = readFileSync(resolve(root, 'storage/app/ui/oficina/items.html'), 'utf8');
const image = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aDEsAAAAASUVORK5CYII=', 'base64');
const photo = { id: 'photo-1', principal: true, miniatura_url: '/api/materiales/fotos-items/photo-1/miniatura', url: '/api/materiales/fotos-items/photo-1/archivo' };
const item = { id: 'item-1', codigo: 'FILM', nombre: 'Film stretch', unidad_medida: 'rollos', activo: true, categoria: 'Embalaje', cantidad_fotos: 1, foto_principal: photo,
    cliente: { id: 'client-1', nombre: 'Cliente', temporada: { codigo: '2026' } } };

test('catálogo muestra miniaturas autenticadas y un lector puede ver la foto sin editarla', async () => {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
    try {
        const context = await browser.newContext({ viewport: { width: 1366, height: 900 } });
        const page = await context.newPage(); const calls = []; const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await context.addInitScript(() => {
            localStorage.setItem('estiba_wms_office_token', 'private-token');
            localStorage.setItem('estiba_wms_office_identity', JSON.stringify({ rol: 'camarero_materiales', nombre: 'Camarero', puede_consultar_despachos_materiales: true,
                modulos_acceso: ['materiales.despachos'] }));
        });
        await context.route('http://localhost/**', async route => {
            const request = route.request(); const url = new URL(request.url()); const path = url.pathname;
            if (path.startsWith('/build/')) return route.fulfill({ body: readFileSync(resolve(root, `public${path}`)), contentType: path.endsWith('.js') ? 'text/javascript' : 'text/css' });
            if (path === '/oficina/materiales/items') return route.fulfill({ body: html, contentType: 'text/html' });
            calls.push({ path, auth: request.headers().authorization, url: request.url() });
            if (path === '/api/materiales/items/catalogo') return route.fulfill({ json: { data: [item], pagina: 1, paginas: 1, total: 1,
                catalogos: { temporadas: [{ id: 'season-1', codigo: '2026' }], clientes: [{ id: 'client-1', nombre: 'Cliente', temporada_material_id: 'season-1' }], categorias: ['Embalaje'] } } });
            if (path === '/api/materiales/items/item-1/fotos') return route.fulfill({ json: { data: [photo], version: 1 } });
            if (path.startsWith('/api/materiales/fotos-items/')) return route.fulfill({ body: image, contentType: 'image/png', headers: { 'Cache-Control': 'no-store, private' } });
            if (path === '/api/materiales/items/catalogo/exportar/csv') return route.fulfill({ body: 'codigo;nombre\nFILM;Film stretch\n', contentType: 'text/csv', headers: { 'Content-Disposition': 'attachment; filename=catalogo.csv' } });
            if (path === '/api/oficina/contexto') return route.fulfill({ json: { data: { temporada: { codigo: '2026' }, planta: 'Rengo', avisos_cierre: [], avisos_materiales: [] } } });
            if (path === '/api/materiales/reposicion/resumen') return route.fulfill({ json: { data: { quiebre: 0, bajo_minimo: 0 } } });
            return route.fulfill({ status: 404 });
        });
        await page.goto('http://localhost/oficina/materiales/items');
        await page.waitForFunction(() => document.querySelector('#itemRows img')?.src.startsWith('blob:'));
        assert.equal(await page.locator('select[name="estado"]').inputValue(), 'todos');
        assert.equal(await page.locator('[data-office-key="items"]').isVisible(), true);
        assert.equal(calls.some(c => c.path.endsWith('/archivo')), false, 'El listado descarga solo miniaturas');
        await page.getByRole('button', { name: 'Fotos (1)' }).click();
        await page.waitForFunction(() => document.querySelector('dialog img')?.src.startsWith('blob:'));
        assert.equal(await page.locator('dialog form').isVisible(), false);
        assert.equal(await page.getByRole('button', { name: 'Eliminar', exact: true }).count(), 0);
        await page.locator('[data-large]').click();
        await page.waitForFunction(() => document.querySelector('.item-photo-large img')?.src.startsWith('blob:'));
        assert.equal(calls.some(c => c.path.endsWith('/archivo')), true);
        assert.ok(calls.filter(c => c.path.startsWith('/api/materiales/fotos-items/')).every(c => c.auth === 'Bearer private-token' && !c.url.includes('private-token')));
        await page.evaluate(() => { localStorage.removeItem('estiba_wms_office_token'); window.dispatchEvent(new Event('estiba:office-session')); });
        await page.waitForFunction(() => !document.querySelector('dialog'));
        assert.deepEqual(errors, []);
        await context.close();
    } finally { await browser.close(); }
});
