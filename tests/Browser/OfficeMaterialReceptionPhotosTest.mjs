import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { chromium } from 'playwright';

const module = readFileSync(new URL('../../resources/js/office-material-reception-photos.js', import.meta.url), 'utf8');
const image = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aDEsAAAAASUVORK5CYII=', 'base64');

test('recepción exige documento aceptado, sube multipart autenticado y confirma sin cambiar versión', async () => {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
    try {
        const context = await browser.newContext(); const page = await context.newPage(); const calls = []; const errors = [];
        page.on('pageerror', (error) => errors.push(error.message));
        await context.route('http://localhost/**', async (route) => {
            const request = route.request(); const url = new URL(request.url());
            if (url.pathname === '/photos.js') return route.fulfill({ body: module, contentType: 'text/javascript' });
            if (url.pathname === '/test') return route.fulfill({ body: '<section id="photos"></section>', contentType: 'text/html' });
            calls.push({ path: url.pathname, headers: request.headers(), body: request.postData() });
            if (request.method() === 'POST') return route.fulfill({ status: 201, json: { data: { id: 'photo', tipo: 'documento', orden: 1, miniatura_url: '/api/materiales/recepciones/reception/fotos/photo/miniatura', url: '/api/materiales/recepciones/reception/fotos/photo/original' } } });
            if (url.pathname.endsWith('/miniatura')) return route.fulfill({ body: image, contentType: 'image/png' });
            return route.fulfill({ status: 404 });
        });
        await page.goto('http://localhost/test');
        await page.evaluate(async () => {
            const { createReceptionPhotosPanel } = await import('/photos.js');
            window.reception = { id: 'reception', version: 1, estado: 'borrador', fotos: [] };
            window.confirmed = false;
            window.photosPanel = createReceptionPhotosPanel(document.getElementById('photos'), {
                getReception: () => window.reception, token: () => 'private-token', uuid: () => '00000000-0000-4000-8000-000000000001', canManage: () => true, canAdminister: () => false,
                onChange: (fotos) => { window.reception.fotos = fotos; }, onConfirm: async () => { window.confirmed = true; },
            });
            await window.photosPanel.render();
        });
        assert.equal(await page.getByRole('button', { name: 'Confirmar recepción' }).isDisabled(), true);
        await page.locator('[data-upload="documento"]').setInputFiles({ name: 'guia.png', mimeType: 'image/png', buffer: image });
        await page.waitForFunction(() => document.querySelector('[data-thumbnail]')?.src.startsWith('blob:'));
        assert.equal(await page.getByRole('button', { name: 'Confirmar recepción' }).isEnabled(), true);
        assert.equal(await page.evaluate(() => window.reception.version), 1);
        const upload = calls.find((c) => c.body);
        assert.match(upload.headers['content-type'], /^multipart\/form-data; boundary=/);
        assert.match(upload.body, /name="operacion_id"/);
        assert.match(upload.body, /00000000-0000-4000-8000-000000000001/);
        assert.match(upload.body, /name="archivo"/);
        assert.ok(calls.every((c) => c.headers.authorization === 'Bearer private-token' && !c.path.includes('private-token')));
        await page.getByRole('button', { name: 'Confirmar recepción' }).click();
        assert.equal(await page.evaluate(() => window.confirmed), true);
        await page.evaluate(() => window.photosPanel.reset());
        assert.equal(await page.locator('#photos img').count(), 0);
        assert.deepEqual(errors, []);
        await context.close();
    } finally { await browser.close(); }
});

for (const tipo of ['documento', 'referencial']) {
    test(`fotos ${tipo}: solo sube hasta cinco y avisa las omitidas sin crear reintentos imposibles`, async () => {
        const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
        try {
            const context = await browser.newContext(); const page = await context.newPage(); const uploads = []; const errors = [];
            page.on('pageerror', (error) => errors.push(error.message));
            await context.route('http://localhost/**', async (route) => {
                const request = route.request(); const url = new URL(request.url());
                if (url.pathname === '/photos.js') return route.fulfill({ body: module, contentType: 'text/javascript' });
                if (url.pathname === '/test') return route.fulfill({ body: '<section id="photos"></section>', contentType: 'text/html' });
                if (request.method() === 'POST') {
                    uploads.push(request.postData());
                    const orden = 3 + uploads.length;
                    return route.fulfill({ status: 201, json: { data: { id: `photo-${orden}`, tipo, orden, miniatura_url: `/miniatura/${orden}/miniatura` } } });
                }
                if (url.pathname.endsWith('/miniatura')) return route.fulfill({ body: image, contentType: 'image/png' });
                return route.fulfill({ status: 404 });
            });
            await page.goto('http://localhost/test');
            await page.evaluate(async (tipo) => {
                const { createReceptionPhotosPanel } = await import('/photos.js');
                window.reception = { id: 'reception', version: 1, estado: 'borrador', fotos: [1, 2, 3].map((orden) => ({ id: `photo-${orden}`, tipo, orden, miniatura_url: `/miniatura/${orden}/miniatura` })) };
                window.photosPanel = createReceptionPhotosPanel(document.getElementById('photos'), {
                    getReception: () => window.reception, token: () => 'private-token', uuid: () => crypto.randomUUID(), canManage: () => true, canAdminister: () => false,
                    onChange: (fotos) => { window.reception.fotos = fotos; }, onConfirm: async () => {},
                });
                await window.photosPanel.render();
            }, tipo);
            await page.locator(`[data-upload="${tipo}"]`).setInputFiles([1, 2, 3, 4].map((n) => ({ name: `foto-${n}.png`, mimeType: 'image/png', buffer: image })));
            await page.waitForFunction(() => document.querySelector('[data-photo-error]')?.textContent === 'Se omitieron 2 fotos: el máximo es 5 por tipo');
            assert.equal(uploads.length, 2);
            assert.match(uploads[0], /filename="foto-1.png"/);
            assert.match(uploads[1], /filename="foto-2.png"/);
            assert.equal(await page.evaluate(() => window.reception.fotos.length), 5);
            assert.equal(await page.evaluate(() => window.reception.version), 1);
            assert.equal(await page.locator('[data-retry]').count(), 0);
            assert.equal(await page.locator(`[data-upload="${tipo}"]`).count(), 0);
            assert.deepEqual(errors, []);
            await context.close();
        } finally { await browser.close(); }
    });
}
