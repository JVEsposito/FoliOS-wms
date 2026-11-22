import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { extname, resolve } from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
const view = '/oficina/administracion/integridad-operacional';
const api = '/api/administracion/integridad-operacional';

test('la purga muestra los folios y solo se envía al confirmar', async () => {
    const browser = await chromium.launch({
        executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined,
        args: ['--no-sandbox', '--disable-gpu'],
    });
    try {
        const page = await browser.newPage();
        await page.addInitScript(() => {
            localStorage.setItem('estiba_wms_office_token', 'integridad-fixture');
            localStorage.setItem('estiba_wms_office_identity', JSON.stringify({ rol: 'administrador' }));
        });
        const revision = 'a'.repeat(64);
        const aplicadas = [];
        await page.route('http://localhost/**', async route => {
            const request = route.request();
            const { pathname } = new URL(request.url());
            if (pathname === view) {
                return route.fulfill({ path: resolve(root, 'storage/app/ui/oficina/integridad-operacional.html') });
            }
            if (pathname.startsWith('/build/')) {
                return route.fulfill({
                    body: readFileSync(resolve(root, `public${pathname}`)),
                    contentType: ({ '.js': 'text/javascript', '.css': 'text/css' })[extname(pathname)],
                });
            }
            if (pathname === api) return route.fulfill({ json: { data: [], meta: { total: 0 } } });
            if (pathname === `${api}/purgar-despachados`) {
                if (request.method() === 'GET') {
                    return route.fulfill({ json: { cantidad: 2, revision, folios: ['6000004168', '6000001823'] } });
                }
                aplicadas.push(request.postDataJSON());
                return route.fulfill({ json: { purgadas: 2 } });
            }
            return route.fulfill({ status: 404 });
        });
        await page.goto(`http://localhost${view}`);
        const boton = page.getByRole('button', { name: 'Purgar pallets despachados' });

        const cancelacion = page.waitForEvent('dialog').then(async dialog => {
            assert.equal(dialog.type(), 'confirm');
            assert.match(dialog.message(), /2 ubicaciones.*6000004168.*6000001823/);
            await dialog.dismiss();
        });
        await boton.click();
        await cancelacion;
        assert.deepEqual(aplicadas, []);

        const confirmacion = page.waitForEvent('dialog').then(dialog => dialog.accept());
        await boton.click();
        await confirmacion;
        await page.getByText('Ubicaciones liberadas: 2', { exact: false }).waitFor();
        assert.deepEqual(aplicadas, [{ confirmacion: true, revision }]);
    } finally {
        await browser.close();
    }
});
