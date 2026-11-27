import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
let css = readFileSync(resolve(root, 'resources/temas-oficina/personal-stitch.css'), 'utf8')
    .replace('data:image/webp;base64,__FOLIOS_TEMA_WEBP__', `data:image/png;base64,${readFileSync(resolve(root, 'resources/temas-oficina/personal-stitch.png')).toString('base64')}`)
    .replace('__FOLIOS_TEMA_FONDO__', `url("data:image/png;base64,${readFileSync(resolve(root, 'resources/temas-oficina/personal-stitch-juego.png')).toString('base64')}")`);
for (const pose of ['saludo', 'asomado', 'descanso', 'salto']) {
    css = css.replace(`__FOLIOS_TEMA_STITCH_${pose.toUpperCase()}__`, `url("data:image/png;base64,${readFileSync(resolve(root, `resources/temas-oficina/personal-stitch-${pose}.png`)).toString('base64')}")`);
}

function luminance(rgb) {
    return rgb.match(/\d+(?:\.\d+)?/g).slice(0, 3).map(Number).map(value => {
        const v = value / 255;
        return v <= 0.04045 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4;
    }).reduce((sum, value, index) => sum + value * [0.2126, 0.7152, 0.0722][index], 0);
}

// Las vistas Blade y los CSS compilados son reales. Se omiten las consultas operativas:
// este recorrido comprueba maquetación y contraste; las restricciones se prueban en la API.
for (const [ruta, archivo, titulo] of [
    ['/oficina/operacion-ahora', 'operacion-ahora.html', 'Operación ahora'],
    ['/oficina/romana', 'romana.html', 'Control de Romana'],
]) {
    test(`Stitch conserva la cabecera, los controles y el menú en ${titulo}`, async () => {
        const browser = await chromium.launch({
            executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined,
            args: ['--no-sandbox', '--disable-gpu', ...(process.env.FOLIOS_BROWSER_SINGLE_PROCESS ? ['--single-process'] : [])],
        });
        try {
            const page = await browser.newPage({ viewport: { width: 1440, height: 1080 } });
            await page.addInitScript(() => {
                localStorage.setItem('estiba_wms_office_token', 'fixture');
                localStorage.setItem('estiba_wms_office_identity', JSON.stringify({ nombre: 'Usuario autorizado', rol: 'administrador' }));
            });
            await page.route('http://localhost/**', route => {
                const pathname = new URL(route.request().url()).pathname;
                if (pathname === ruta) return route.fulfill({ path: resolve(root, 'storage/app/ui/oficina', archivo) });
                if (pathname.endsWith('.js')) return route.fulfill({ contentType: 'text/javascript', body: '' });
                if (pathname.startsWith('/build/') || pathname.startsWith('/brand/')) return route.fulfill({ path: resolve(root, `public${pathname}`) });
                return route.fulfill({ json: {} });
            });
            await page.goto(`http://localhost${ruta}`);
            await page.evaluate(() => {
                document.documentElement.dataset.officeTheme = 'dark-industrial';
                document.documentElement.dataset.officePersonalTheme = 'personal-stitch';
                document.querySelector('main.office-app').classList.remove('is-hidden');
                document.querySelector('.estiba-office-shell').dataset.menuReady = '';
                const nombre = document.getElementById('officeUserName');
                if (nombre) nombre.textContent = 'Usuario autorizado';
                const selector = document.querySelector('[data-office-theme-select]');
                selector.hidden = false;
                selector.replaceChildren(new Option('Stitch · Experimento 626', 'personal-stitch'));
            });
            await page.addStyleTag({ content: css });
            await page.evaluate(() => document.fonts.ready);
            const imagenes = await page.evaluate(() => {
                const s = getComputedStyle(document.documentElement);
                return ['arte', 'juego', 'saludo', 'asomado', 'descanso', 'salto'].map(pose => s.getPropertyValue(`--stitch-${pose}`));
            });
            assert.equal(new Set(imagenes).size, 6, 'Las seis poses usan dibujos distintos');
            assert.ok(imagenes.every(imagen => imagen.includes('data:image/png;base64,')));
            const cabecera = page.locator('[data-folios-masthead], .operation-now-command__title').first();
            for (const width of [1440, 1024, 390]) {
                await page.setViewportSize({ width, height: 1080 });
                const heading = cabecera.getByRole('heading', { name: titulo, exact: true });
                assert.equal(await heading.isVisible(), true);
                assert.equal(await page.evaluate(() => document.documentElement.scrollWidth), width);
                const estilo = await cabecera.evaluate(el => {
                    const s = getComputedStyle(el);
                    const h = getComputedStyle(el.querySelector('h1'));
                    return { fondo: getComputedStyle(el.querySelector(".office-theme-mascots__figure")).backgroundImage.slice(0, 40), color: h.color, base: s.backgroundColor, reserva: s.paddingBottom };
                });
                assert.match(estilo.fondo, /data:image\/png;base64,/);
                const ratio = (luminance(estilo.color) + 0.05) / (luminance(estilo.base) + 0.05);
                assert.ok(ratio >= 4.5, `Título legible en ${titulo} a ${width}px: ${ratio}`);
                if (width === 390) assert.ok(parseFloat(estilo.reserva) >= 100, 'Las figuras disponen de una franja propia debajo del texto');
                if (process.env.FOLIOS_UI_CAPTURE_DIR) {
                    await page.screenshot({ path: resolve(process.env.FOLIOS_UI_CAPTURE_DIR, `stitch-${archivo.replace('.html', '')}-${width}.png`) });
                }
            }
            await page.setViewportSize({ width: 1440, height: 1080 });
            await page.locator('.estiba-office-sidebar').evaluate(el => el.scrollTo(0, el.scrollHeight));
            assert.equal(await page.getByRole('button', { name: 'Cerrar sesión', exact: true }).isVisible(), true);
            assert.equal(await page.locator('.estiba-office-theme-art').evaluate(el => getComputedStyle(el).display), 'block');
            assert.equal(await page.locator('.estiba-office-theme-art').evaluate(el => getComputedStyle(el).pointerEvents), 'none');
            // Cambiar al tema base retira también las figuras compartidas.
            await page.evaluate(() => { delete document.documentElement.dataset.officePersonalTheme; });
            assert.equal(await cabecera.locator(".office-theme-mascots").isVisible(), false);
        } finally {
            await browser.close();
        }
    });
}
