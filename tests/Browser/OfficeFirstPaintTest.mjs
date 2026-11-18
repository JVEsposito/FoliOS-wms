import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
// EstructuraOficinaTest exporta la vista Blade después de compilar Vite.
const html = readFileSync(resolve(root, 'storage/app/ui/oficina/materia-prima.html'), 'utf8');
const token = '41|tokenDePruebaParaElPrimerPintado';
const identity = { nombre: 'Rosa Romana', rol: 'operador_romana' };

/**
 * Por defecto bloquea los módulos de Vite para observar el primer pintado.
 */
async function firstPaint(browser, storage, { modules = false, contextData = {}, personalCss = '', personalGate = () => null } = {}) {
    const context = await browser.newContext({ viewport: { width: 1280, height: 800 } });
    await context.addInitScript(({ local, session }) => {
        if (sessionStorage.getItem('estiba_prueba_preparada')) return;
        sessionStorage.setItem('estiba_prueba_preparada', '1');
        Object.entries(local).forEach(([key, value]) => localStorage.setItem(key, value));
        Object.entries(session).forEach(([key, value]) => sessionStorage.setItem(key, value));
    }, storage);
    const visited = [];
    await context.route('http://localhost/**', (route) => {
        const { pathname, search } = new URL(route.request().url());
        visited.push(pathname + search);
        if (!modules && pathname.endsWith('.js')) return route.abort();
        if (pathname.startsWith('/build/')) {
            return route.fulfill({ path: resolve(root, `public${pathname}`) });
        }
        if (pathname === '/api/oficina/contexto') return route.fulfill({ json: { data: { planta: 'Planta de prueba', temporada: null, ...contextData } } });
        if (pathname === '/api/oficina/tema-personal/personal-prueba' && route.request().headers().authorization === `Bearer ${token}`) {
            return Promise.resolve(personalGate()).then(() => route.fulfill({ body: personalCss, contentType: 'text/css' }));
        }
        if (pathname === '/oficina/materia-prima') return route.fulfill({ body: html, contentType: 'text/html' });
        if (pathname === '/FoliOS') return route.fulfill({ body: '<p id="entrada">FoliOS</p>', contentType: 'text/html' });
        return route.fulfill({ status: 404, body: '' });
    });
    const page = await context.newPage();
    await page.goto('http://localhost/oficina/materia-prima');
    return { context, page, visited };
}

test('el tema personal se descarga con sesión y se retira al actualizar el perfil', async () => {
    const browser = await chromium.launch({
        executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined,
        args: ['--no-sandbox', '--disable-gpu'],
    });
    try {
        const contextData = {
            temas_oficina: ['personal-prueba'],
            temas_oficina_personales: { 'personal-prueba': { nombre: 'Prueba', claro: true, color: '#112233' } },
        };
        const hold = { until: null };
        const { context, page, visited } = await firstPaint(browser, {
            local: {
                estiba_wms_office_token: token,
                estiba_wms_office_identity: JSON.stringify({ ...identity, ...contextData }),
                estiba_wms_office_theme: 'dark-industrial',
            },
            session: {},
        }, {
            modules: true,
            contextData,
            personalCss: ':root[data-office-personal-theme="personal-prueba"] { --corporate-canvas: #102030; }',
            personalGate: () => hold.until,
        });

        const select = page.getByRole('combobox', { name: 'Tema de Oficina' });
        await select.waitFor();
        assert.deepEqual(await select.locator('option').evaluateAll((options) => options.map((option) => [option.value, option.text])),
            [['light-professional', '☀️ Modo claro'], ['dark-industrial', '◐ Modo oscuro'], ['personal-prueba', 'Prueba']]);
        await select.selectOption('personal-prueba');
        await page.waitForFunction(() => document.documentElement.dataset.officePersonalTheme === 'personal-prueba');
        await page.waitForFunction(() => getComputedStyle(document.body).backgroundColor === 'rgb(16, 32, 48)');
        assert.equal(await page.locator('html').getAttribute('data-office-theme'), 'light-professional');
        assert.ok(visited.includes('/api/oficina/tema-personal/personal-prueba'));

        // En la próxima oficina se pinta con la copia de la sesión, sin esperar la hoja: antes se
        // veía un instante el tema base. La copia va detrás de los estilos de Oficina para ganarles.
        let release;
        hold.until = new Promise((resolve) => { release = resolve; });
        await page.reload({ waitUntil: 'domcontentloaded' });
        assert.deepEqual(await page.evaluate(() => {
            const style = document.getElementById('officePersonalTheme');
            return {
                fondo: getComputedStyle(document.body).backgroundColor,
                tema: document.documentElement.dataset.officePersonalTheme,
                color: document.querySelector('meta[name="theme-color"]').content,
                ultima: [...document.querySelectorAll('link[rel="stylesheet"]')]
                    .every((link) => link.compareDocumentPosition(style) & Node.DOCUMENT_POSITION_FOLLOWING),
            };
        }), { fondo: 'rgb(16, 32, 48)', tema: 'personal-prueba', color: '#112233', ultima: true });
        // Al llegar la hoja se revalida sobre la misma etiqueta, sin quitarla.
        await page.evaluate(() => {
            window.cambiosTema = [];
            new MutationObserver((records) => window.cambiosTema.push(...records.map((record) => record.attributeName || 'head')))
                .observe(document.documentElement, { attributes: true, attributeFilter: ['data-office-personal-theme'] });
            new MutationObserver(() => window.cambiosTema.push('head')).observe(document.head, { childList: true });
        });
        const requests = visited.filter((path) => path === '/api/oficina/tema-personal/personal-prueba').length;
        release();
        await page.waitForFunction(() => document.readyState === 'complete');
        await page.waitForTimeout(150);
        assert.ok(visited.filter((path) => path === '/api/oficina/tema-personal/personal-prueba').length >= requests);
        assert.deepEqual(await page.evaluate(() => window.cambiosTema), []);
        assert.equal(await page.locator('style#officePersonalTheme').count(), 1);
        hold.until = null;

        contextData.temas_oficina = [];
        contextData.temas_oficina_personales = {};
        await page.locator('[data-office-context-refresh]').click();
        await page.waitForFunction(() => !document.documentElement.dataset.officePersonalTheme);
        assert.equal(await page.locator('html').getAttribute('data-office-theme'), 'dark-industrial');
        assert.deepEqual(await select.locator('option').evaluateAll((options) => options.map((option) => option.value)),
            ['light-professional', 'dark-industrial']);
        assert.equal(await select.inputValue(), 'dark-industrial');
        await context.close();
    } finally {
        await browser.close();
    }
});

test('con sesión, la oficina se pinta directo, con su tema y el menú del perfil', async () => {
    const browser = await chromium.launch({
        executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined,
        args: ['--no-sandbox', '--disable-gpu'],
    });
    try {
        const { context, page } = await firstPaint(browser, {
            local: {
                estiba_wms_office_token: token,
                estiba_wms_office_identity: JSON.stringify(identity),
                estiba_wms_office_theme: 'dark-industrial',
            },
            session: {
                estiba_wms_office_menu: JSON.stringify({
                    sesion: token.slice(-16),
                    ocultos: ['[data-domain-key="gerencia"]', '[data-domain-key="administracion"]'],
                }),
            },
        });
        const state = await page.evaluate(() => ({
            session: document.documentElement.dataset.officeSession,
            theme: document.documentElement.dataset.officeTheme,
            access: getComputedStyle(document.getElementById('officeAccess')).display,
            app: getComputedStyle(document.getElementById('officeApp')).display,
            sidebar: getComputedStyle(document.getElementById('officeSidebar')).position,
            gerencia: getComputedStyle(document.querySelector('[data-domain-key="gerencia"]')).display,
            materiales: getComputedStyle(document.querySelector('[data-domain-key="materiales"]')).display,
            name: document.getElementById('officeUserName').textContent,
            initials: document.getElementById('officeInitials').textContent,
        }));

        assert.equal(state.session, 'activa');
        assert.equal(state.theme, 'dark-industrial');
        assert.equal(state.access, 'none', 'el acceso propio de la oficina no se pinta');
        assert.equal(state.app, 'block');
        assert.notEqual(state.sidebar, 'static', 'el menú ya tiene sus estilos');
        assert.equal(state.gerencia, 'none', 'el menú recordado oculta las áreas que el perfil no tiene');
        assert.notEqual(state.materiales, 'none');
        assert.equal(state.name, 'Rosa Romana');
        assert.equal(state.initials, 'RR');
        await context.close();
    } finally {
        await browser.close();
    }
});

test('el desplegable del Administrador recupera el rosado anterior y guarda los nuevos temas', async () => {
    const browser = await chromium.launch({
        executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined,
        args: ['--no-sandbox', '--disable-gpu'],
    });
    try {
        const { context, page } = await firstPaint(browser, {
            local: {
                estiba_wms_office_token: token,
                estiba_wms_office_identity: JSON.stringify({ ...identity, rol: 'administrador', temas_personales: ['dark-bit', 'dark-bit-neon'] }),
                estiba_wms_office_theme: 'light-professional',
                estiba_wms_office_admin_theme: 'dark-rose',
            },
            session: {},
        }, { modules: true });
        const select = page.getByRole('combobox', { name: 'Tema de Oficina' });
        await select.waitFor();
        assert.deepEqual(await select.locator('option').evaluateAll((options) => options.map((option) => option.value)),
            ['light-professional', 'dark-industrial', 'dark-princess', 'light-garden', 'dark-bit', 'dark-bit-neon']);
        assert.equal(await select.inputValue(), 'dark-princess');
        assert.equal(await page.locator('html').getAttribute('data-office-theme'), 'dark-princess');
        await page.waitForFunction(() => getComputedStyle(document.body).backgroundColor === 'rgb(24, 20, 46)');
        assert.equal(await page.locator('#officeSidebar').evaluate((el) => getComputedStyle(el).backgroundColor), 'rgb(27, 21, 50)');
        const art = page.locator('.estiba-office-theme-art');
        assert.match(await art.evaluate((el) => getComputedStyle(el).backgroundImage), /princesa-estelar/);

        await select.selectOption('light-garden');
        assert.equal(await page.locator('html').getAttribute('data-office-theme'), 'light-garden');
        assert.equal(await page.locator('meta[name="color-scheme"]').getAttribute('content'), 'light');
        await page.waitForFunction(() => getComputedStyle(document.body).backgroundColor === 'rgb(255, 243, 247)');
        assert.equal(await page.locator('#officeSidebar').evaluate((el) => getComputedStyle(el).backgroundColor), 'rgb(251, 227, 236)');
        assert.match(await art.evaluate((el) => getComputedStyle(el).backgroundImage), /jardin-encantado/);
        await page.reload();
        await select.waitFor();
        assert.equal(await select.inputValue(), 'light-garden');
        assert.equal(await page.locator('html').getAttribute('data-office-theme'), 'light-garden');

        await page.evaluate((user) => {
            localStorage.setItem('estiba_wms_office_identity', JSON.stringify(user));
            window.dispatchEvent(new CustomEvent('estiba:office-session'));
        }, { ...identity, puede_consultar_romana: true });
        assert.equal(await page.locator('html').getAttribute('data-office-theme'), 'light-professional');
        assert.deepEqual(await select.locator('option').evaluateAll((options) => options.map((option) => option.value)),
            ['light-professional', 'dark-industrial']);
        assert.equal(await art.isVisible(), false);
        await select.selectOption('dark-industrial');
        assert.equal(await page.locator('html').getAttribute('data-office-theme'), 'dark-industrial');
        await context.close();
    } finally {
        await browser.close();
    }
});

test('los modos bit y neón solo los ofrece la cuenta habilitada y se recuperan al recargar', async () => {
    const browser = await chromium.launch({
        executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined,
        args: ['--no-sandbox', '--disable-gpu'],
    });
    try {
        const { context, page } = await firstPaint(browser, {
            local: {
                estiba_wms_office_token: token,
                estiba_wms_office_identity: JSON.stringify({ ...identity, temas_personales: ['dark-bit', 'dark-bit-neon'] }),
                estiba_wms_office_theme: 'light-professional',
            },
            session: {},
        }, { modules: true });
        const select = page.getByRole('combobox', { name: 'Tema de Oficina' });
        await select.selectOption('dark-bit');
        assert.equal(await page.locator('html').getAttribute('data-office-theme'), 'dark-bit');
        await page.waitForFunction(() => getComputedStyle(document.body).backgroundColor === 'rgb(26, 28, 44)');
        assert.match(await page.locator('.estiba-office-signature strong').evaluate((el) => getComputedStyle(el).fontFamily), /Silkscreen/);
        assert.match(await page.locator('.estiba-office-signature').evaluate((el) => getComputedStyle(el, '::before').backgroundImage), /^url\("data:image\/svg\+xml/);
        await select.selectOption('dark-bit-neon');
        assert.equal(await page.locator('html').getAttribute('data-office-theme'), 'dark-bit-neon');
        await page.waitForFunction(() => getComputedStyle(document.body).backgroundColor === 'rgb(11, 8, 32)');
        await page.reload();
        assert.equal(await page.locator('html').getAttribute('data-office-theme'), 'dark-bit-neon');
        assert.equal(await page.evaluate(() => localStorage.getItem('estiba_wms_office_theme')), 'light-professional');

        await page.evaluate((user) => {
            localStorage.setItem('estiba_wms_office_identity', JSON.stringify(user));
            window.dispatchEvent(new CustomEvent('estiba:office-session'));
        }, { ...identity, rol: 'administrador' });
        assert.equal((await page.locator('html').getAttribute('data-office-theme')).startsWith('dark-bit'), false);
        assert.equal(await page.evaluate(() => window.EstibaOfficeTheme.themes.some((theme) => theme.startsWith('dark-bit'))), false);
        await context.close();
    } finally {
        await browser.close();
    }
});

test('sin sesión, no pinta la oficina y va a /FoliOS con el regreso', async () => {
    const browser = await chromium.launch({
        executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined,
        args: ['--no-sandbox', '--disable-gpu'],
    });
    try {
        const { context, page, visited } = await firstPaint(browser, { local: {}, session: {} });
        await page.waitForURL('**/FoliOS?volver=%2Foficina%2Fmateria-prima');
        assert.equal(await page.locator('#entrada').textContent(), 'FoliOS');
        assert.ok(visited.includes('/FoliOS?volver=%2Foficina%2Fmateria-prima'));
        await context.close();
    } finally {
        await browser.close();
    }
});
