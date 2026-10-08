import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
// EstructuraOficinaTest exporta la vista Blade después de compilar Vite.
const html = readFileSync(process.env.OFFICE_UI_FIXTURE
    || resolve(root, 'storage/app/ui/oficina/materiales.html'), 'utf8');
const season = { id: 'season-1', codigo: 'TEMP-2627', nombre: 'Temporada 2026–2027', activa: true };

async function settleLayout(page) {
    await page.evaluate(() => new Promise(resolve => {
        requestAnimationFrame(() => requestAnimationFrame(() => requestAnimationFrame(resolve)));
    }));
}

test('Materiales conserva una cabecera legible y sin errores al mostrar reposición y cambiar de ancho', async () => {
    const browser = await chromium.launch({
        executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined,
        args: ['--no-sandbox', '--disable-gpu'],
    });
    try {
        for (const theme of ['light-professional', 'dark-industrial']) {
            const context = await browser.newContext({ viewport: { width: 1920, height: 1080 } });
            const page = await context.newPage();
            const errors = [];
            page.on('pageerror', error => errors.push(error.message));
            let stock = { quiebre: 0, bajo_minimo: 0 };
            await context.addInitScript(theme => {
                localStorage.setItem('estiba_wms_office_token', 'test-token');
                localStorage.setItem('estiba_wms_office_identity', JSON.stringify({
                    nombre: 'Administrador de Prueba', rol: 'administrador',
                    puede_consultar_despachos_materiales: true,
                    puede_administrar_catalogos_materiales: true,
                    puede_gestionar_despachos_materiales: true,
                }));
                localStorage.setItem('estiba_wms_office_theme', theme);
                window.layoutErrors = [];
                window.addEventListener('error', event => window.layoutErrors.push(event.message));
                window.addEventListener('unhandledrejection', event => window.layoutErrors.push(String(event.reason)));
            }, theme);
            await context.route('http://localhost/**', async route => {
                const { pathname } = new URL(route.request().url());
                if (pathname.startsWith('/build/')) {
                    return route.fulfill({
                        body: readFileSync(resolve(root, `public${pathname}`)),
                        contentType: pathname.endsWith('.js') ? 'text/javascript' : 'text/css',
                    });
                }
                const responses = {
                    '/api/oficina/contexto': { data: { temporada: season, planta: 'Planta de prueba', avisos_cierre: [], avisos_materiales: [] } },
                    '/api/materiales/catalogo': { temporada: season, clientes: [], items: [], destinos: [] },
                    '/api/materiales/despachos': { data: [] },
                    '/api/materiales/inventario': { data: [], resumen_clientes: [], resumen_items: [], resumen: { folios: 9 }, meta: { total: 9 } },
                };
                if (pathname === '/api/materiales/reposicion/resumen') {
                    return route.fulfill(stock ? { json: { data: stock } } : { status: 403, json: { message: 'Sin permiso.' } });
                }
                if (responses[pathname]) return route.fulfill({ json: responses[pathname] });
                if (pathname === '/oficina/materiales') return route.fulfill({ body: html, contentType: 'text/html' });
                return route.fulfill({ status: 404 });
            });
            await page.goto('http://localhost/oficina/materiales');
            await page.waitForFunction(() => document.querySelector('[data-material-replenishment-indicator]')?.textContent.includes('0 en quiebre'));
            await page.waitForFunction(() => document.getElementById('materialsFolioCount').textContent === '9');

            for (const width of [1920, 1440, 1366, 1200, 1024, 768, 390, 1920]) {
                await page.setViewportSize({ width, height: 1080 });
                await settleLayout(page);
                const layout = await page.evaluate(() => {
                    const header = document.querySelector('[data-office-shell-header]').getBoundingClientRect();
                    const user = document.getElementById('officeUserName').getBoundingClientRect();
                    const badge = document.querySelector('[data-material-replenishment-indicator]').getBoundingClientRect();
                    const identity = document.querySelector('.estiba-office-identity').getBoundingClientRect();
                    return {
                        headerHeight: header.height, userWidth: user.width, userHeight: user.height,
                        badgeTop: badge.top, identityBottom: identity.bottom,
                        headerWidth: header.width,
                        measuredHeight: parseFloat(document.getElementById('officeApp').style.getPropertyValue('--office-header-height')),
                        errors: window.layoutErrors,
                        toasts: document.getElementById('officeToasts').textContent,
                    };
                });
                assert.ok(layout.headerHeight < (width >= 600 ? 140 : 220), `${theme} ${width}px: altura ${layout.headerHeight}`);
                assert.ok(Math.abs(layout.measuredHeight - layout.headerHeight) < 1, `${theme} ${width}px: medición desactualizada`);
                assert.ok(layout.headerWidth <= width, `${theme} ${width}px: cabecera fuera de pantalla`);
                assert.ok(layout.badgeTop >= layout.identityBottom - 1, `${theme} ${width}px: reposición desplaza al usuario`);
                if (width >= 1400) {
                    assert.ok(layout.userWidth > 100, `${theme} ${width}px: nombre comprimido`);
                    assert.ok(layout.userHeight < 60, `${theme} ${width}px: nombre en una columna vertical`);
                }
                assert.deepEqual(layout.errors, [], `${theme} ${width}px`);
                assert.equal(layout.toasts, '', `${theme} ${width}px`);
            }
            const withBadge = await page.locator('[data-office-shell-header]').boundingBox();
            stock = { quiebre: 12500, bajo_minimo: 54321 };
            await page.evaluate(() => window.dispatchEvent(new Event('materiales:reposicion-actualizada')));
            await page.waitForFunction(() => document.querySelector('[data-material-replenishment-indicator]').textContent.includes('12500'));
            await settleLayout(page);
            stock = null;
            await page.evaluate(() => window.dispatchEvent(new Event('materiales:reposicion-actualizada')));
            await page.waitForFunction(() => document.querySelector('[data-material-replenishment-indicator]').hidden);
            await settleLayout(page);
            const withoutBadge = await page.locator('[data-office-shell-header]').boundingBox();
            assert.ok(withoutBadge.height < withBadge.height, 'La cabecera recupera su altura al ocultar reposición');
            assert.deepEqual(await page.evaluate(() => window.layoutErrors), []);
            assert.deepEqual(errors, []);
            await context.close();
        }
    } finally {
        await browser.close();
    }
});
