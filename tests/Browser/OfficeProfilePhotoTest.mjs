import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { extname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';
import { PNG } from 'pngjs';

const root = fileURLToPath(new URL('../../', import.meta.url));
const image = new PNG({ width: 48, height: 24 });
image.data.fill(160);
const picture = PNG.sync.write(image);
const file = { name: 'mi-foto.png', mimeType: 'image/png', buffer: picture };
const user = { id: 41, nombre: 'Ivan Sanchez', rol: 'guardia_porteria', puede_consultar_porteria: true, modulos_acceso: ['materia-prima.porteria'] };
const launch = () => chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });

async function setup(browser, { initialPhoto = null, holdPhoto = null } = {}) {
    const context = await browser.newContext({ viewport: { width: 1440, height: 950 } });
    await context.addInitScript(identity => {
        localStorage.setItem('estiba_wms_office_token', 'perfil-fixture');
        localStorage.setItem('estiba_wms_office_identity', JSON.stringify(identity));
    }, user);
    const state = { photo: initialPhoto, uploads: 0, failure: false, identity: user, photoStarted: false };
    let notifyPhoto;
    const photoStarted = new Promise(resolve => { notifyPhoto = resolve; });
    await context.route('http://localhost/**', async route => {
        const path = new URL(route.request().url()).pathname;
        if (path === '/oficina/porteria') return route.fulfill({ contentType: 'text/html', body: readFileSync(resolve(root, 'storage/app/ui/oficina/porteria.html'), 'utf8') });
        if (path.startsWith('/build/') || path.startsWith('/brand/')) {
            try { return route.fulfill({ body: readFileSync(resolve(root, `public${path}`)), contentType: ({ '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml', '.woff2': 'font/woff2' })[extname(path)] || 'application/octet-stream' }); }
            catch { return route.fulfill({ status: 404 }); }
        }
        if (path === '/api/acceso-oficina') return route.fulfill({ json: { usuario: state.identity } });
        if (path === '/api/oficina/contexto') return route.fulfill({ json: { data: { temporada: null, planta: 'Pruebas', avisos_cierre: [], avisos_materiales: [] } } });
        if (path === '/api/porteria/catalogos') return route.fulfill({ json: { clientes: [], ahora: '2026-11-12T16:00:00Z' } });
        if (path === '/api/porteria/ingresos') return route.fulfill({ json: { data: [], pagina: 1, ultima_pagina: 1, total: 0, puede_registrar: true } });
        if (path === '/api/usuario/perfil') return route.fulfill({ json: { data: { id: state.identity.id, nombre: state.identity.nombre, foto_perfil_version: state.photo } } });
        if (path === '/api/usuario/foto-perfil') {
            assert.match(route.request().headers().authorization, /^Bearer /);
            assert.equal(new URL(route.request().url()).search, '');
            if (route.request().method() === 'GET') {
                if (!state.photo) return route.fulfill({ status: 404, json: {} });
                state.photoStarted = true;
                notifyPhoto();
                if (holdPhoto) await holdPhoto;
                try { return await route.fulfill({ contentType: 'image/png', body: picture }); } catch { return; }
            }
            if (state.failure) return route.fulfill({ status: 422, json: { errors: { foto: ['No se pudo procesar esta foto.'] } } });
            if (route.request().method() === 'POST') {
                assert.match(route.request().headers()['content-type'], /^multipart\/form-data; boundary=/);
                assert.ok(route.request().postDataBuffer().includes(Buffer.from('mi-foto.png')));
                state.photo = `foto-${++state.uploads}`;
            } else if (route.request().method() === 'DELETE') state.photo = null;
            else throw new Error('Método inesperado');
            return route.fulfill({ json: { data: { id: state.identity.id, foto_perfil_version: state.photo } } });
        }
        return route.fulfill({ status: 404, json: {} });
    });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.goto('http://localhost/oficina/porteria');
    await page.locator('[data-office-profile-open][data-profile-ready]').waitFor();
    return { page, context, state, errors, photoStarted };
}

test('Oficina: previsualiza, cancela, guarda, recupera y quita la foto sin cambiar la identidad', async () => {
    const browser = await launch();
    try {
        const { page, context, state, errors } = await setup(browser);
        const open = page.getByRole('button', { name: 'Cambiar mi foto de perfil', exact: true });
        const dialog = page.getByRole('dialog', { name: 'Mi foto de perfil' });
        const choose = () => dialog.locator('[data-profile-file]').setInputFiles(file);
        const ready = () => page.waitForFunction(() => !document.querySelector('[data-profile-save]').disabled);
        await open.click();
        await choose(); await ready();
        assert.equal(await dialog.locator('[data-profile-preview]').isVisible(), true);
        await page.screenshot({ path: resolve(root, 'storage/app/ui/oficina/foto-perfil.png') });
        await dialog.getByRole('button', { name: 'Cancelar', exact: true }).click();
        assert.equal(state.uploads, 0);
        assert.equal(await page.locator('#officeInitials').isVisible(), true);

        await open.click(); await choose(); await ready();
        await dialog.getByRole('button', { name: 'Guardar foto', exact: true }).click();
        await dialog.waitFor({ state: 'hidden' });
        await page.waitForFunction(() => document.querySelector('[data-office-profile-photo]').naturalWidth > 0);
        assert.equal(state.uploads, 1);
        assert.equal(await page.locator('#officeInitials').isVisible(), false);
        assert.deepEqual(await page.evaluate(() => JSON.parse(localStorage.getItem('estiba_wms_office_identity'))), user);
        await page.reload();
        await page.waitForFunction(() => document.querySelector('[data-office-profile-photo]').naturalWidth > 0);

        await open.click();
        await dialog.locator('[data-profile-file]').setInputFiles({ name: 'falsa.svg', mimeType: 'image/svg+xml', buffer: Buffer.from('<svg/>') });
        await dialog.getByRole('alert').filter({ hasText: 'Selecciona una foto JPG, PNG o WebP de hasta 5 MB.' }).waitFor();
        assert.equal(await dialog.locator('[data-profile-save]').isEnabled(), false);
        assert.equal(state.uploads, 1);
        await choose(); await ready();
        state.failure = true;
        await dialog.getByRole('button', { name: 'Guardar foto', exact: true }).click();
        await dialog.getByRole('alert').filter({ hasText: 'No se pudo procesar esta foto.' }).waitFor();
        assert.equal(await dialog.isVisible(), true);
        assert.equal(await page.locator('[data-office-profile-photo]').isVisible(), true);
        state.failure = false;
        await dialog.getByRole('button', { name: 'Guardar foto', exact: true }).click();
        await dialog.waitFor({ state: 'hidden' });
        assert.equal(state.uploads, 2);

        await open.click();
        await dialog.getByRole('button', { name: 'Quitar foto', exact: true }).click();
        await dialog.waitFor({ state: 'hidden' });
        assert.equal(await page.locator('#officeInitials').isVisible(), true);
        assert.equal(await page.locator('[data-office-profile-photo]').isVisible(), false);
        await page.setViewportSize({ width: 390, height: 844 });
        await page.locator('[data-office-menu]').click();
        await open.click();
        assert.equal(await dialog.isVisible(), true);
        assert.equal(await page.evaluate(() => document.getElementById('officeProfileDialog').getBoundingClientRect().width <= innerWidth), true);
        await page.keyboard.press('Escape');
        await dialog.waitFor({ state: 'hidden' });
        assert.deepEqual(errors, []);
        await context.close();
    } finally { await browser.close(); }
});

test('Oficina: una foto recibida tarde no aparece en la sesión de otro usuario', async () => {
    const browser = await launch();
    let release;
    const holdPhoto = new Promise(resolve => { release = resolve; });
    try {
        const { page, context, state, errors, photoStarted } = await setup(browser, { initialPhoto: 'foto-anterior', holdPhoto });
        await photoStarted;
        state.identity = { ...user, id: 42, nombre: 'Otra persona' };
        state.photo = null;
        const changed = page.waitForResponse(response => new URL(response.url()).pathname === '/api/usuario/perfil' && response.request().headers().authorization === 'Bearer otra-sesion');
        await page.evaluate(identity => {
            localStorage.setItem('estiba_wms_office_token', 'otra-sesion');
            localStorage.setItem('estiba_wms_office_identity', JSON.stringify(identity));
            window.dispatchEvent(new CustomEvent('estiba:office-session'));
        }, state.identity);
        release();
        await changed;
        assert.equal(await page.locator('[data-office-profile-photo]').getAttribute('src'), null);
        assert.equal(await page.locator('#officeInitials').isVisible(), true);
        assert.deepEqual(errors, []);
        await context.close();
    } finally { release(); await browser.close(); }
});
