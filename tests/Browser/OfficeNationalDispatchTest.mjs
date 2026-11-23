import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { extname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
const html = readFileSync(resolve(root, 'storage/app/ui/oficina/despacho-nacional.html'), 'utf8');
const entry = '/oficina/frigorifico/despacho/nacional';
const apiPath = '/api/despacho-nacional-pt';
const mime = { '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml', '.woff2': 'font/woff2' };

test('Oficina separa un MIX, conserva el identificador ante un error y confirma su salida en móvil y oscuro', async (t) => {
    const browser = await chromium.launch({
        executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined,
        args: ['--no-sandbox', '--disable-gpu'],
    });
    t.after(() => browser.close());
    const context = await browser.newContext({ viewport: { width: 390, height: 950 } });
    const identity = {
        nombre: 'Oficina', rol: 'despachador',
        puede_consultar_catalogo_cargas: true, modulos_acceso: ['frigorifico.cargas'],
    };
    await context.addInitScript((user) => {
        localStorage.setItem('estiba_wms_office_token', 'test-token');
        localStorage.setItem('estiba_wms_office_identity', JSON.stringify(user));
        localStorage.setItem('estiba_wms_office_theme', 'light-professional');
    }, identity);

    let partida;
    const separaciones = [];
    const salidas = [];
    const time = '2026-11-23T15:30:00Z';
    await context.route('http://localhost/**', (route) => {
        const request = route.request();
        const url = new URL(request.url());
        if (url.pathname === entry) return route.fulfill({ body: html, contentType: 'text/html' });
        if (url.pathname.startsWith('/build/') || url.pathname.startsWith('/brand/')) {
            try {
                return route.fulfill({ body: readFileSync(resolve(root, `public${url.pathname}`)), contentType: mime[extname(url.pathname)] });
            } catch {
                return route.fulfill({ status: 404 });
            }
        }
        if (url.pathname === '/api/acceso-oficina') return route.fulfill({ json: { usuario: identity } });
        if (url.pathname === `${apiPath}/folio`) return route.fulfill({ json: { data: {
            id: 'folio', numero_folio: '60123456', cliente: 'Exportadora', cajas: 100, ubicacion: { camara: 'CAM-01', posicion: 'B01-P01-N1' },
            composicion: [{ clave: 'csg-1', csg: '1001', cantidad_cajas: 60 }, { clave: 'csg-2', csg: '1002', cantidad_cajas: 40 }],
        } } });
        if (url.pathname === `${apiPath}/partidas`) {
            const payload = request.postDataJSON();
            separaciones.push(payload);
            partida ??= {
                id: 'partida', cajas: 12, motivo: payload.motivo, usuario: { name: 'Oficina' },
                despacho: null, created_at: time, temporada: { codigo: 'TEMP-2627' },
                snapshot: {
                    folio_nacional: 'MN-09F739AB1A874AA998A6562329C9815F', folio_origen: '60123456', cliente: 'Exportadora',
                    composicion: [{ csg: '1002', cantidad_cajas: 12 }],
                },
            };
            if (separaciones.length === 1) return route.fulfill({ status: 503, json: { message: 'Respuesta perdida. Reintenta.' } });
            return route.fulfill({ json: { data: partida } });
        }
        if (url.pathname === `${apiPath}/despachos`) {
            const payload = request.postDataJSON();
            salidas.push(payload);
            partida.despacho = { ...payload, despachado_at: time, usuario: { name: 'Oficina' } };
            return route.fulfill({ json: { data: partida.despacho } });
        }
        if (url.pathname === apiPath) {
            const pending = partida && !partida.despacho ? 1 : 0;
            const data = partida && (url.searchParams.get('estado') === 'pendiente') === Boolean(pending) ? [partida] : [];
            return route.fulfill({ json: {
                data, meta: { total: data.length, pagina: 1, paginas: 1 },
                resumen: { partidas_pendientes: pending, cajas_pendientes: pending ? 12 : 0 },
                permisos: { separar: true, despachar: true },
            } });
        }
        return route.fulfill({ json: { data: [] } });
    });

    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await page.goto(`http://localhost${entry}`);
    await page.getByRole('searchbox', { name: 'Folio original' }).fill('60123456');
    await page.getByRole('button', { name: 'Consultar folio' }).click();
    await page.getByRole('spinbutton', { name: 'Cajas a separar de 1002' }).fill('12');
    await page.getByText('12 cajas a Nacional · 88 quedan en el folio original').waitFor();
    const form = page.locator('#nationalSeparationForm');
    await form.getByRole('textbox', { name: 'Motivo de la separación' }).fill('Cajas de reclasificación nacional');
    await form.getByRole('checkbox').check();
    await form.getByRole('button', { name: 'Registrar separación' }).click();
    await page.getByText('Respuesta perdida. Reintenta.').waitFor();
    await form.getByRole('button', { name: 'Registrar separación' }).click();
    await page.getByRole('checkbox', { name: /Seleccionar partida/ }).check();
    await page.getByText('Ver composición', { exact: true }).click();
    await page.getByText('1002 · 12 cajas', { exact: true }).waitFor();
    assert.deepEqual(separaciones[0], separaciones[1]);
    assert.deepEqual(separaciones[0].composicion, [{ clave: 'csg-2', cajas: 12 }]);

    const theme = page.getByRole('combobox', { name: 'Tema de Oficina' });
    const list = page.locator('#nationalList');
    const palette = async (name) => {
        await theme.selectOption(name);
        await page.waitForFunction((value) => document.documentElement.dataset.officeTheme === value, name);
        return list.evaluate((panel) => [getComputedStyle(panel).backgroundColor, getComputedStyle(panel.querySelector('td')).backgroundColor]);
    };
    const light = await palette('light-professional');
    const dark = await palette('dark-industrial');
    light.forEach((value, index) => assert.notEqual(value, dark[index]));
    assert.deepEqual(await palette('light-professional'), light);
    assert.deepEqual(await palette('dark-industrial'), dark);
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));

    const dispatch = page.locator('#nationalDispatchForm');
    await dispatch.getByRole('textbox', { name: 'Destinatario' }).fill('Mercado Central');
    await dispatch.getByRole('textbox', { name: 'Número de guía' }).fill('00123');
    await dispatch.getByRole('checkbox').check();
    await dispatch.getByRole('button', { name: 'Confirmar salida' }).click();
    await page.getByText('Sin partidas pendientes de despacho.').waitFor();
    await page.getByRole('combobox', { name: 'Estado', exact: true }).selectOption('despachada');
    await page.getByText('Mercado Central · Guía 00123', { exact: true }).waitFor();
    assert.deepEqual(salidas[0].partidas, ['partida']);
    assert.deepEqual(errors, []);
});
