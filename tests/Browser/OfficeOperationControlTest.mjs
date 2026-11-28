import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';
import { PNG } from 'pngjs';

const root = fileURLToPath(new URL('../../', import.meta.url));
const resonancia = 'personal-resonancia';
const ultimaNoche = 'personal-majora-link';
const webpMagenta = 'UklGRhwAAABXRUJQVlA4TBAAAAAvB4AAAAcQxS9y/wMR0f8A';
function luminance(rgb) {
    const [red, green, blue] = rgb.match(/\d+/g).slice(0, 3).map((channel) => {
        const value = Number(channel) / 255;
        return value <= 0.04045 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4;
    });
    return 0.2126 * red + 0.7152 * green + 0.0722 * blue;
}

const pngMagenta = 'iVBORw0KGgoAAAANSUhEUgAAAAYAAAAGCAIAAABvrngfAAAAFUlEQVQI12P8z3qNARUwMWAAagoBANKnAeZyRjDUAAAAAElFTkSuQmCC';

test('el centro de control conserva la pestaña y pinta las cabeceras de los temas habilitados', async () => {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
    try {
        const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
        const temas = {
            'personal-omegamon-x': { nombre: 'Omegamon X', color: '#0d111b', claro: false },
            'personal-mugiwara': { nombre: 'Mugiwara', color: '#071c28', claro: false },
            'personal-nerv': { nombre: 'NERV', color: '#0c090a', claro: false },
            [ultimaNoche]: { nombre: 'Majora y Link · Última noche', color: '#15101d', claro: false },
            [resonancia]: { nombre: 'Resonancia', color: '#f1f4f7', claro: true },
        };
        const preferencias = { temas_oficina: Object.keys(temas), temas_oficina_personales: temas };
        const identity = {
            nombre: 'Supervisión', rol: 'consulta', inicio: '/oficina/operacion-ahora',
            puede_consultar_panel_gerencial: true, puede_consultar_prefrio: true,
            modulos_acceso: ['gerencia.panel', 'frigorifico.prefrio'], ...preferencias,
        };
        await context.addInitScript((user) => {
            localStorage.setItem('estiba_wms_office_token', 'fixture');
            localStorage.setItem('estiba_wms_office_identity', JSON.stringify(user));
        }, identity);
        const now = '2026-11-18T14:00:00Z';
        const snapshot = {
            generado_at: now, temporada: { id: 'temp', codigo: 'TEMP-2627', nombre: 'Prueba' },
            jornada: { zona_horaria: 'America/Santiago', turno: 'A' }, sincronizacion: { estado: 'sin_actividad', operaciones_hoy: {} },
            camaras: [{ contenido: 'productos', codigo: 'CAM-01', capacidad_operativa: 80, ocupadas: 20 }], camareros: [], prefrio: { resumen: { procesos_activos: 0 }, tuneles: [] },
            incidencias: { resumen: {}, abiertas: [] },
            planificador: { despliegue: { mode_global: 'off' }, salud: { riesgos: {} }, arbitraje: { vigencia: {} } },
            planta: { catalogo: [], elementos: [], conexiones: [], configurado: false },
            despacho: { resumen: { activas: 2, cargando: 1 }, cargas: [], reingresos: [] },
            retenciones_sag: [{ id: 'lote-prueba', lote: '31012', estado: 'retenido', todos_destinos: true, destinos: [] }],
            recepcion_mp: { en_curso: 1, en_ingreso: 0, en_pesaje: 0, pendientes_destare: 1, cerradas_jornada: 4, kilos_netos_jornada: 12350,
                recepciones: [{ numero: 'REC-2611-0011', cliente: 'Cliente prueba', guias: ['1511', '1512'], patente: 'ABCD12', ingreso_at: now,
                    estado: 'en_bascula_salida', validacion_mp: 'validada' }] },
            validacion_pt: { procesadas: 25, aprobadas: 24, observadas: 1, rechazadas: 0, conflictos: 1,
                recientes: [{ folio: '6000000001', linea: 2, turno: 'A', recibido_at: now, resultado: 'conflicto' }] },
        };
        let attentionRequests = 0;
        let attentionFails = false;
        const summaryAttention = {
            total: 4, parcial: true, areas: {
                frigorifico: { cantidad: 4, parcial: false, fuentes: [
                    { etiqueta: 'Lotes con retención general SAG', href: '/oficina/frigorifico/lotes-retenidos-sag', cantidad: 4 },
                ] },
                gerencia: { cantidad: 0, parcial: false, fuentes: [
                    { etiqueta: 'Focos del Panel gerencial', href: '/oficina/gerencia', cantidad: 0 },
                ] },
                administracion: { cantidad: null, parcial: false, fuentes: [] },
                consultas: { referencia: '/oficina/consultas' },
            },
        };
        await context.route('http://localhost/**', (route) => {
            const { pathname } = new URL(route.request().url());
            if (pathname === '/oficina/operacion-ahora') return route.fulfill({ path: resolve(root, 'storage/app/ui/oficina/operacion-ahora.html') });
            if (pathname === '/FoliOS') return route.fulfill({ path: resolve(root, 'storage/app/ui/oficina/inicio-folios.html') });
            if (pathname === '/api/oficina/atencion') {
                attentionRequests++;
                return attentionFails ? route.fulfill({ status: 503 }) : route.fulfill({ json: { data: summaryAttention } });
            }
            if (pathname.startsWith('/build/') || pathname.startsWith('/brand/')) return route.fulfill({ path: resolve(root, `public${pathname}`) });
            if (pathname === '/api/operacion-ahora') return route.fulfill({ json: { data: snapshot } });
            if (pathname === '/api/operacion-ahora/camareros') return route.fulfill({ json: { data: { generado_at: now, camareros: [] } } });
            if (pathname === '/api/acceso-oficina') return route.fulfill({ json: { usuario: identity } });
            const tema = pathname.slice('/api/oficina/tema-personal/'.length);
            if (pathname.startsWith('/api/oficina/tema-personal/') && Object.hasOwn(temas, tema)) {
                const css = readFileSync(resolve(root, 'resources', 'temas-oficina', `${tema}.css`), 'utf8');
                const webp = [ultimaNoche, resonancia].includes(tema)
                    ? readFileSync(resolve(root, 'resources', 'temas-oficina', `${tema}.webp`)).toString('base64')
                    : webpMagenta;
                const ilustracion = tema === 'personal-nerv' ? `data:image/png;base64,${pngMagenta}` : `data:image/webp;base64,${webp}`;
                let body = css.replace('data:image/webp;base64,__FOLIOS_TEMA_WEBP__', ilustracion).replace('__FOLIOS_TEMA_FONDO__', 'none');
                if (tema === resonancia) {
                    const fuente = readFileSync(resolve(root, 'resources/temas-oficina/bodoni-moda-latin.woff2')).toString('base64');
                    body = body.replace('__FOLIOS_TEMA_WOFF2__', fuente);
                }
                return route.fulfill({ contentType: 'text/css', body });
            }
            return route.fulfill({ json: { data: { planta: 'Pruebas', temporada: snapshot.temporada, ...preferencias } } });
        });

        const page = await context.newPage();
        const errors = [];
        page.on('pageerror', (error) => errors.push(error.message));
        await page.goto('http://localhost/oficina/operacion-ahora');
        const summary = page.getByRole('tabpanel', { name: 'Resumen' });
        await summary.getByText('REC-2611-0011').waitFor();
        assert.equal(await summary.locator('#operationOverviewPt').innerText(), '25');
        assert.equal(await summary.locator('[data-overview-visual="recepcion-mp"] [data-cell="done"]').count(), 1);
        assert.equal(await summary.locator('[data-overview-visual="despacho"] [data-cell="done"]').count(), 2);
        assert.equal(await summary.locator('#operationOverviewCold').innerText(), '25 %');
        assert.equal(await summary.locator('.operation-now-overview__capacity i').evaluate((i) => i.style.inlineSize), '25%');
        assert.equal(await summary.locator('#operationOverviewReceptionRows .folios-patente').innerText(), 'ABCD12');
        assert.equal(await page.locator('#operationSagLotAlerts .sag-lot-alerts__stamp').innerText(), 'RETENIDO');
        await summary.getByText('4 pendientes · Lectura parcial').waitFor();
        assert.equal(await summary.locator('[data-gajo-total]').textContent(), '4');
        const focusArea = summary.locator('[data-attention-area="frigorifico"]');
        assert.equal(await focusArea.locator('[data-attention-label]').innerText(), '4');
        assert.equal(await focusArea.getByRole('link').getAttribute('href'), '/oficina/frigorifico/lotes-retenidos-sag');
        assert.equal(await page.locator('[data-office-attention-count="frigorifico"]').innerText(), '4');
        assert.equal(await summary.locator('[data-attention-area="gerencia"] [data-attention-label]').innerText(), 'Al día');
        assert.equal(await summary.locator('[data-attention-area="administracion"] [data-attention-label]').innerText(), 'Sin lectura');
        assert.equal(await summary.locator('[data-attention-area="consultas"] [data-attention-label]').innerText(), 'Referencia');
        assert.equal(await summary.locator('[data-attention-area="materiales"]').isVisible(), false);

        const selector = page.getByRole('combobox', { name: 'Tema de Oficina' });
        const palette = () => page.locator('#operationPanel-resumen').evaluate((element) => {
            const panel = element.querySelector('[data-folios-atencion]');
            const leaf = element.querySelector('[data-gajo-leaf="frigorifico"]');
            const count = element.querySelector('[data-gajo-count="frigorifico"]');
            const ink = document.createElement('span');
            ink.style.color = 'var(--area-frigorifico-ink)';
            panel.append(ink);
            const areaColor = getComputedStyle(ink).color;
            ink.remove();
            return {
                surface: getComputedStyle(panel).backgroundColor,
                fill: getComputedStyle(leaf).fill,
                color: getComputedStyle(count).fill,
                areaColor,
            };
        });
        const requestsBeforeThemes = attentionRequests;
        const colors = {};
        for (const theme of ['light-professional', 'dark-industrial', 'light-professional', 'dark-industrial']) {
            await selector.selectOption(theme);
            await page.waitForFunction(key => document.documentElement.dataset.officeTheme === key, theme);
            const current = await palette();
            assert.equal(current.fill, current.areaColor);
            const [bright, dark] = [luminance(current.fill), luminance(current.color)].sort((a, b) => b - a);
            assert.ok((bright + 0.05) / (dark + 0.05) >= 4.5, `Contraste de la cifra en ${theme}`);
            if (colors[theme]) assert.deepEqual(current, colors[theme]);
            colors[theme] = current;
        }
        assert.notEqual(colors['light-professional'].surface, colors['dark-industrial'].surface);
        assert.notEqual(colors['light-professional'].fill, colors['dark-industrial'].fill);
        assert.notEqual(colors['light-professional'].color, colors['dark-industrial'].color);
        assert.equal(attentionRequests, requestsBeforeThemes);
        assert.equal(await summary.locator('[data-gajo-total]').textContent(), '4');

        const home = await context.newPage();
        await home.goto('http://localhost/FoliOS?inicio=1');
        await home.getByText('4 pendientes · Lectura parcial').waitFor();
        assert.equal(await home.locator('html').getAttribute('data-office-theme'), 'dark-industrial');
        assert.equal(await home.locator('[data-gajo-total]').textContent(), '4');
        assert.equal(await home.getByRole('link', { name: 'Ir a mi oficina' }).getAttribute('href'), '/oficina/operacion-ahora');
        await home.close();
        await page.bringToFront();

        attentionFails = true;
        await page.locator('#operationRefresh').click();
        await summary.getByText('Sin lectura vigente').waitFor();
        assert.equal(await page.locator('[data-office-attention-count="frigorifico"]').isVisible(), false);
        attentionFails = false;
        await page.locator('#operationRefresh').click();
        await summary.getByText('4 pendientes · Lectura parcial').waitFor();

        await page.getByRole('tab', { name: /Atención/ }).click();
        const attention = page.getByRole('tabpanel', { name: /Atención/ });
        await attention.getByRole('combobox', { name: 'Filtrar condiciones por área' }).selectOption('frigorifico');
        await attention.getByText('Retención de lote', { exact: true }).waitFor();
        await page.getByRole('tab', { name: 'Resumen' }).click();
        await summary.getByRole('link', { name: 'Ver recepción MP' }).click();
        assert.equal(new URL(page.url()).hash, '#recepcion-mp');
        await page.locator('#operationReceptionRows').getByText('1511 · 1512').waitFor();
        assert.match(await page.locator('#operationReceptionRows').innerText(), /Pendiente de destare.*Validada/s);
        assert.equal(await page.locator('#operationOpenRomana').isVisible(), false);

        snapshot.recepcion_mp.en_curso = 0;
        snapshot.recepcion_mp.pendientes_destare = 0;
        snapshot.recepcion_mp.recepciones = [];
        await page.locator('#operationRefresh').click();
        await page.locator('#operationReceptionRows').getByText('Sin camiones con fruta en curso').waitFor();
        assert.equal(new URL(page.url()).hash, '#recepcion-mp');

        await page.setViewportSize({ width: 390, height: 850 });
        await page.getByRole('tab', { name: /Validación PT/ }).click();
        await page.locator('#operationValidationRows').getByText('Conflicto', { exact: true }).waitFor();
        assert.equal(await page.locator('#operationPtApproved').innerText(), '24');
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth), 390);

        const cabecera = page.locator('.operation-now-command__title');
        for (const tema of Object.keys(temas).filter((theme) => ![ultimaNoche, resonancia].includes(theme))) {
            await page.setViewportSize({ width: 1440, height: 900 });
            await selector.selectOption(tema);
            await page.waitForFunction(clave => document.documentElement.dataset.officePersonalTheme === clave, tema);
            for (const width of [1440, 1024, 390]) {
                await page.setViewportSize({ width, height: 900 });
                await page.evaluate(() => window.scrollTo(0, 0));
                const titulo = await cabecera.getByRole('heading', { name: 'Operación ahora' }).boundingBox();
                assert.ok(titulo?.height > 20, `${tema} mantiene el título visible a ${width}px`);
                const imagen = PNG.sync.read(await cabecera.screenshot());
                const x = tema === 'personal-nerv' ? (width <= 599 ? imagen.width - 52 : Math.floor(imagen.width * 0.53)) : imagen.width - 12;
                const posicion = (Math.floor(imagen.height / 2) * imagen.width + x) * 4;
                const [rojo, verde, azul] = imagen.data.subarray(posicion, posicion + 3);
                assert.ok(rojo > 220 && verde < 45 && azul > 160, `${tema} pinta su ilustración en la cabecera a ${width}px`);
                assert.equal(await page.evaluate(() => document.documentElement.scrollWidth), width);
            }
        }
        assert.equal(await page.locator('.estiba-office-search input').evaluate(input => getComputedStyle(input).backgroundColor), 'rgb(17, 13, 14)');
        assert.equal(await page.evaluate(() => getComputedStyle(document.documentElement).getPropertyValue('--primary').trim()), '#f47583');
        const estilosUltimaNoche = () => page.locator('#operationValidationRows td').first().evaluate((cell) => {
            const style = getComputedStyle(cell);
            const header = getComputedStyle(document.querySelector('.operation-now-command__title'));
            const badge = document.querySelector('#operationValidationRows .folios-estado');
            return {
                cell: style.backgroundColor, text: style.color,
                status: badge ? getComputedStyle(badge).color : null,
                art: header.backgroundImage.startsWith('linear-gradient') && header.backgroundImage.includes('data:image/webp;base64,'),
            };
        });

        await selector.selectOption(ultimaNoche);
        await page.waitForFunction((theme) => document.documentElement.dataset.officePersonalTheme === theme, ultimaNoche);
        const noche = await estilosUltimaNoche();
        assert.equal(noche.art, true);
        assert.ok(luminance(noche.cell) < 0.12);
        assert.ok((luminance(noche.text) + 0.05) / (luminance(noche.cell) + 0.05) >= 4.5);
        if (noche.status) assert.ok((luminance(noche.status) + 0.05) / (luminance(noche.cell) + 0.05) >= 4.5);
        assert.equal((await palette()).fill, colors['dark-industrial'].fill);
        if (process.env.FOLIOS_UI_CAPTURE_DIR) {
            await page.locator('#operationToasts .toast').first().waitFor({ state: 'hidden' });
        }
        for (const width of [390, 768, 1440]) {
            await page.setViewportSize({ width, height: 900 });
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth), width);
            if (process.env.FOLIOS_UI_CAPTURE_DIR) {
                await page.screenshot({ path: resolve(process.env.FOLIOS_UI_CAPTURE_DIR, `ultima-noche-${width}.png`) });
            }
        }
        await selector.selectOption('light-professional');
        await page.waitForFunction(() => document.documentElement.dataset.officeTheme === 'light-professional');
        assert.notEqual((await estilosUltimaNoche()).cell, noche.cell);
        assert.equal((await estilosUltimaNoche()).art, false);
        await selector.selectOption(ultimaNoche);
        await page.waitForFunction((theme) => document.documentElement.dataset.officePersonalTheme === theme, ultimaNoche);
        assert.deepEqual(await estilosUltimaNoche(), noche);

        await selector.selectOption(resonancia);
        await page.waitForFunction((theme) => document.documentElement.dataset.officePersonalTheme === theme, resonancia);
        const fuentes = await page.evaluate(async () => (await document.fonts.load('700 32px "Bodoni Moda"', 'Operación ahora')).map(font => font.status));
        assert.deepEqual(fuentes, ['loaded']);
        for (const width of [1440, 1024, 390]) {
            await page.setViewportSize({ width, height: 900 });
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth), width);
            const estilo = await cabecera.evaluate((element, movil) => {
                const titulo = getComputedStyle(element.querySelector('h1'));
                const fondo = getComputedStyle(element);
                const arte = movil ? getComputedStyle(element, '::after') : fondo;
                return { fuente: titulo.fontFamily, color: titulo.color, base: fondo.backgroundColor, arte: arte.backgroundImage.includes('data:image/webp;base64,') };
            }, width <= 760);
            assert.match(estilo.fuente, /Bodoni Moda/);
            assert.equal(estilo.arte, true);
            assert.ok((luminance(estilo.base) + 0.05) / (luminance(estilo.color) + 0.05) >= 4.5);
            await page.locator('#operationRefresh').click({ trial: true });
            if (process.env.FOLIOS_UI_CAPTURE_DIR) {
                await page.evaluate(() => window.scrollTo(0, 0));
                await page.screenshot({ path: resolve(process.env.FOLIOS_UI_CAPTURE_DIR, `resonancia-${width}.png`) });
            }
        }
        await selector.selectOption('dark-industrial');
        assert.equal(await cabecera.evaluate(el => getComputedStyle(el.querySelector('h1')).fontFamily.includes('Bodoni Moda')), false);
        assert.equal(new URL(page.url()).hash, '#validacion-pt');
        assert.deepEqual(errors, []);
        await context.close();
    } finally {
        await browser.close();
    }
});
