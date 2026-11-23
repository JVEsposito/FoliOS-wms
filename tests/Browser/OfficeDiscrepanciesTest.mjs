import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { extname, resolve } from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
const html = readFileSync(resolve(root, 'storage/app/ui/oficina/discrepancias.html'), 'utf8');
const identity = { nombre: 'Supervisión', rol: 'supervisor_frio', puede_supervisar: true, modulos_acceso: ['frigorifico.camaras'] };

test('Discrepancias distingue falta de lectura, mantiene la tarja en ambos temas y resuelve con la versión del caso', async () => {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
    try {
        const context = await browser.newContext({ viewport: { width: 1366, height: 900 } });
        await context.addInitScript(user => {
            localStorage.setItem('estiba_wms_office_token', 'fixture');
            localStorage.setItem('estiba_wms_office_identity', JSON.stringify(user));
        }, identity);
        const item = {
            id: 'caso', estado: 'abierta', tipo: 'pallet_no_encontrado', reportada_at: '2026-11-19T10:00:00Z',
            folio: { numero: '6000001234' }, maniobra: { version: 3, titulo: 'Separar carga', plan: { titulo: 'Despacho PT' } },
            tarea: { secuencia: 2, tipo_paso: 'mover', estado: 'pendiente', origen: { codigo: 'CAM-01', posicion: '01-02' } },
            restricciones: { retorno_seguro: 'sin_custodia_temporal' },
        };
        let failed = true;
        let write = null;
        await context.route('http://localhost/**', route => {
            const request = route.request();
            const path = new URL(request.url()).pathname;
            if (path === '/oficina/frigorifico/discrepancias') return route.fulfill({ body: html, contentType: 'text/html' });
            if (path.startsWith('/build/') || path.startsWith('/brand/')) {
                const contentType = { '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml', '.woff2': 'font/woff2' }[extname(path)] || 'application/octet-stream';
                try { return route.fulfill({ body: readFileSync(resolve(root, 'public' + path)), contentType }); }
                catch { return route.fulfill({ status: 404 }); }
            }
            if (path === '/api/acceso-oficina') return route.fulfill({ json: { usuario: identity } });
            if (path === '/api/discrepancias-maniobra/caso/resolver' && request.method() === 'POST') {
                write = request.postDataJSON();
                Object.assign(item, { estado: 'resuelta', accion_resolucion: write.accion, resolucion: write.resolucion, resuelta_at: '2026-11-19T11:00:00Z' });
                return route.fulfill({ json: {} });
            }
            if (path === '/api/discrepancias-maniobra') {
                if (failed) return route.fulfill({ status: 503, json: { message: 'Lectura temporalmente indisponible.' } });
                const view = new URL(request.url()).searchParams.get('estado');
                const data = [view === 'todas' || view === item.estado ? item : null].filter(Boolean);
                return route.fulfill({ json: { data, meta: { total: data.length }, resumen: { abiertas: item.estado === 'abierta' ? 1 : 0, resueltas: item.estado === 'resuelta' ? 1 : 0 } } });
            }
            return route.fulfill({ json: { data: [] } });
        });
        const page = await context.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.goto('http://localhost/oficina/frigorifico/discrepancias');
        await page.getByText('Bandeja sin lectura').waitFor();
        assert.equal(await page.locator('#openDiscrepanciesCount').innerText(), '—');
        failed = false;
        await page.locator('#discrepanciesReload').click();
        const card = page.locator('.discrepancy-card');
        await card.locator('[data-estado="atencion"]').waitFor();
        assert.equal(await card.locator('.folios-code strong').innerText(), '1234');
        assert.equal(await card.getByRole('button', { name: 'Ordenar retorno seguro' }).isDisabled(), true);
        const theme = page.getByRole('combobox', { name: 'Tema de Oficina' });
        const palette = async key => {
            await theme.selectOption(key);
            await page.waitForFunction(expected => document.documentElement.dataset.officeTheme === expected, key);
            return card.evaluate(ticket => [getComputedStyle(ticket).backgroundColor, getComputedStyle(ticket.querySelector('.folios-estado')).color]);
        };
        const light = await palette('light-professional');
        const dark = await palette('dark-industrial');
        assert.notDeepEqual(light, dark);
        assert.deepEqual(await palette('light-professional'), light);
        assert.deepEqual(await palette('dark-industrial'), dark);

        await page.setViewportSize({ width: 390, height: 800 });
        assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
        await card.getByRole('textbox', { name: /Fundamento/ }).fill('Pallet localizado y ruta verificada.');
        await card.getByRole('button', { name: 'Reanudar ruta verificada' }).click();
        await page.getByText('Sin discrepancias para esta selección').waitFor();
        assert.deepEqual(write, { accion: 'reanudar_maniobra', version_maniobra: 3, resolucion: 'Pallet localizado y ruta verificada.' });
        await page.locator('#discrepanciesFilters select[name=estado]').selectOption('resuelta');
        await page.getByRole('button', { name: 'Filtrar' }).click();
        await card.locator('[data-estado="listo"]').waitFor();
        assert.deepEqual(errors, []);
        await context.close();
    } finally { await browser.close(); }
});
