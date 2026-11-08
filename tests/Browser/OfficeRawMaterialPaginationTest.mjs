import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { extname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
const html = readFileSync(resolve(root, 'storage/app/ui/oficina/materia-prima.html'), 'utf8');
const season = { id: 'temporada', codigo: 'TEMP-2627', nombre: 'Temporada 2026' };
const lot = (number) => ({ id: number, numero_lote: number, estado: 'asignado_camara',
    recepcion: { numero_recepcion: 'REC-2611-0017', numero_guia_despacho: '4443' }, cliente: { nombre: 'Cliente' },
    trazabilidad: { csg: '123456', variedad: 'Santina', especie: 'Cereza', tipo_producto: 'materia_prima' },
    envases: { detalle: [{ tipo_envase: 'bins', cantidad: 48 }] }, pesos: { kilos_netos_confirmados: 8561 },
    puede_editar: false, puede_confirmar: false, requiere_hidrocooler: false });

test('lotes: búsqueda desde Romana encuentra recepción y paginación conserva los registros antiguos', async () => {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
    try {
        const context = await browser.newContext({ viewport: { width: 1280, height: 1000 } });
        const identity = { id: 1, nombre: 'Administrador', rol: 'administrador', puede_consultar_materia_prima: true,
            puede_gestionar_lotes_materia_prima: true, modulos_acceso: ['materia-prima.lotes'] };
        await context.addInitScript((user) => {
            localStorage.setItem('estiba_wms_office_token', 'fixture');
            localStorage.setItem('estiba_wms_office_identity', JSON.stringify(user));
        }, identity);
        const queries = []; const errors = [];
        await context.route('http://localhost/**', async (route) => {
            const url = new URL(route.request().url()); const path = url.pathname;
            if (path === '/oficina/materia-prima/lotes') return route.fulfill({ body: html, contentType: 'text/html' });
            if (path.startsWith('/build/') || path.startsWith('/brand/')) {
                try { return route.fulfill({ body: readFileSync(resolve(root, `public${path}`)), contentType: ({ '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml', '.woff2': 'font/woff2' })[extname(path)] || 'application/octet-stream' }); }
                catch { return route.fulfill({ status: 404, body: '' }); }
            }
            if (path === '/api/acceso-oficina') return route.fulfill({ json: { usuario: identity } });
            if (path === '/api/oficina/contexto') return route.fulfill({ json: { data: { temporada: season } } });
            if (path === '/api/envases/catalogo') return route.fulfill({ json: { data: [{ codigo: 'bins', nombre: 'Bins plástico', contiene_fruta: true }] } });
            if (path === '/api/materia-prima/resumen') return route.fulfill({ json: { temporada: season, lotes: {} } });
            if (path === '/api/materia-prima/catalogos') return route.fulfill({ json: { temporada: season, csg: [], especies: [], camaras: [] } });
            if (path === '/api/materia-prima/segmentos-pendientes') return route.fulfill({ json: { data: [] } });
            if (path === '/api/materia-prima/lotes') {
                queries.push(Object.fromEntries(url.searchParams));
                const searched = Boolean(url.searchParams.get('buscar')); const page = Number(url.searchParams.get('page') || 1);
                return route.fulfill({ json: { data: [lot(searched || page === 2 ? 'LOTE-ANTIGUO-4443' : 'LOTE-NUEVO')],
                    meta: { current_page: page, last_page: searched ? 1 : 2, total: searched ? 1 : 101 } } });
            }
            return route.fulfill({ json: {} });
        });
        const page = await context.newPage(); page.on('pageerror', (error) => errors.push(error.message));
        await page.goto('http://localhost/oficina/materia-prima/lotes?buscar=REC-2611-0017');
        await page.getByText('LOTE-ANTIGUO-4443', { exact: true }).waitFor();
        assert.equal(await page.locator('#lotFilters [name="buscar"]').inputValue(), 'REC-2611-0017');
        assert.equal(queries[0].buscar, 'REC-2611-0017'); assert.equal(queries[0].estado, undefined);
        await page.locator('#lotFilters [name="buscar"]').fill('');
        await page.locator('#lotFilters [type="submit"]').click();
        await page.getByText('LOTE-NUEVO', { exact: true }).waitFor();
        assert.match(await page.locator('#lotPageStatus').innerText(), /Página 1 de 2 · 101 lotes/);
        assert.equal(await page.locator('#lotPrevious').isDisabled(), true);
        await page.locator('#lotNext').click();
        await page.getByText('LOTE-ANTIGUO-4443', { exact: true }).waitFor();
        assert.equal(queries.at(-1).page, '2');
        assert.equal(await page.locator('#lotNext').isDisabled(), true);
        await page.locator('#lotPrevious').click();
        await page.getByText('LOTE-NUEVO', { exact: true }).waitFor();
        assert.deepEqual(errors, []);
        await context.close();
    } finally { await browser.close(); }
});
