import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { extname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
const identity = {
    nombre: 'Supervisión', rol: 'administrador', puede_consultar_panel_gerencial: true,
    puede_consultar_cargas: true, puede_consultar_catalogo_cargas: true, puede_cerrar_despacho_frigorifico: true,
};
const progress = (done, total, enAnden = 0) => ({
    acopiados: done, total, porcentaje: total ? Math.round(done / total * 100) : 0,
    umbral_porcentaje: 80, cumple_umbral: done >= total && total > 0, en_anden: enAnden,
});
const loading = (done, total, waiting = 0) => ({ cargados: done, total, porcentaje: total ? Math.floor(done / total * 100) : 0, en_anden_por_cargar: waiting });
const instruction = (id, number, state, loaded) => ({
    id, orden: id === 'i1' ? 1 : 2, numero_externo: number, booking: `BOOK-${id}`, recibidor: 'UPAM FRESH LLC',
    pallets_asignados: 2, pallets_programados: 2, pallets_pendientes_asignar: 0, pendientes_ubicacion: state === 'pendiente' ? 2 : 0,
    estado: state, incidencias_abiertas: 0, separacion: progress(state === 'pendiente' ? 0 : 2, 2, loaded), carguio: loading(loaded, 2),
});

test('Despacho PT conserva el detalle por instructivo, el tema y la confirmación de salida', async () => {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
    try {
        const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
        await context.addInitScript(user => {
            localStorage.setItem('estiba_wms_office_token', 'fixture');
            localStorage.setItem('estiba_wms_office_identity', JSON.stringify(user));
        }, identity);
        const secondNumber = 'F002 <img src=x onerror="window.__dispatchXss=1">';
        const load = {
            id: 'load', codigo: 'CAR-000031', numero_orden_externa: null, embarque: 'EFC000009', clientes: ['FALCO FRUIT'],
            estado: 'despacho_parcial', prioridad: 'normal', incidencias_abiertas: 0, lista_para_cerrar: false,
            anden: 'AND-04', camion_en_anden: { patente: 'KFGT13', ingresada_at: '2026-11-11T17:00:00Z' },
            separacion: progress(2, 4, 2), carguio: loading(2, 4),
            instructivos: [instruction('i1', 'F001', 'cargado_completo', 2), instruction('i2', secondNumber, 'pendiente', 0)],
        };
        // La segunda carga conserva la fila simple si sólo tiene un instructivo, pero muestra sus datos.
        const single = { ...load, id: 'single', codigo: 'CAR-000030', clientes: ['LA AGUADA'], camion_en_anden: null,
            estado: 'pendiente', separacion: progress(1, 2, 1), carguio: loading(0, 2, 1),
            instructivos: [{ ...instruction('i3', 'F003', 'pendiente', 0), destino: 'Estados Unidos' }] };
        const ready = { ...load, id: 'ready', codigo: 'CAR-000032', estado: 'despachada', lista_para_cerrar: true,
            total_folios: 2, folios: [], distribucion: [], embarque: null, separacion: progress(2, 2, 2), carguio: loading(2, 2),
            instructivos: [instruction('i4', 'F004', 'cargado_completo', 2)] };
        const snapshot = {
            generado_at: '2026-11-11T17:00:00Z', temporada: { id: 'temp', codigo: 'TEMP-2627', nombre: 'Prueba' },
            jornada: { fecha: '2026-11-11', zona_horaria: 'America/Santiago', turno: 'A', inicio: '2026-11-11T10:00:00Z', fin: '2026-11-12T10:00:00Z' },
            sincronizacion: { estado: 'sin_actividad', operaciones_hoy: {} },
            camaras: [], camareros: [], prefrio: { resumen: {}, tuneles: [] }, incidencias: { resumen: {}, abiertas: [] },
            planificador: { despliegue: { mode_global: 'off' }, salud: { riesgos: {} }, arbitraje: { vigencia: {} } },
            planta: { catalogo: [], elementos: [], conexiones: [], configurado: false, puede_editar: false },
            despacho: { resumen: { activas: 3, cargando: 2, listas_para_cerrar: 1 }, cargas: [load, single, ready], reingresos: [] },
        };
        const closeWrites = [];
        let allowedToClose = true;
        await context.route('http://localhost/**', async route => {
            const request = route.request();
            const path = new URL(request.url()).pathname;
            if (path === '/oficina/operacion-ahora') return route.fulfill({ contentType: 'text/html', body: readFileSync(resolve(root, 'storage/app/ui/oficina/operacion-ahora.html'), 'utf8') });
            if (path === '/oficina/frigorifico/despacho/cargas') return route.fulfill({ path: resolve(root, 'storage/app/ui/oficina/cargas.html') });
            if (path.startsWith('/build/') || path.startsWith('/brand/')) {
                try {
                    return route.fulfill({ body: readFileSync(resolve(root, `public${path}`)), contentType: ({ '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml', '.woff2': 'font/woff2' })[extname(path)] || 'application/octet-stream' });
                } catch { return route.fulfill({ status: 404 }); }
            }
            if (path === '/api/operacion-ahora') return route.fulfill({ json: { data: { ...snapshot, despacho: { ...snapshot.despacho, puede_cerrar: allowedToClose } } } });
            if (path === '/api/operacion-ahora/camareros') return route.fulfill({ json: { data: { generado_at: snapshot.generado_at, camareros: [] } } });
            if (path === '/api/acceso-oficina') return route.fulfill({ json: { usuario: { ...identity, puede_cerrar_despacho_frigorifico: allowedToClose } } });
            if (path === '/api/cargas/ready') return route.fulfill({ json: { data: ready } });
            if (['/api/cargas', '/api/cargas/incidencias', '/api/camaras', '/api/andenes'].includes(path)) return route.fulfill({ json: { data: [] } });
            if (path.endsWith('/cerrar-despacho')) closeWrites.push(request.method());
            return route.fulfill({ json: { data: { temporada: snapshot.temporada, planta: 'Pruebas', avisos_cierre: [], avisos_materiales: [] } } });
        });
        const page = await context.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.goto('http://localhost/oficina/operacion-ahora');
        await page.locator('[data-instruction-id="i1"]').waitFor({ state: 'attached' });
        await page.locator('#operationTab-despacho').click();
        const rows = page.locator('#operationDispatchRows');
        assert.equal(await rows.locator('[data-dispatch-load]').count(), 3);
        assert.equal(await rows.locator('[data-instruction-id]').count(), 2);
        assert.equal(await rows.locator('[data-dispatch-load="single"] [data-single-instruction="i3"]').innerText(),
            'UPAM FRESH LLC · 2 asignados');
        assert.equal(await rows.locator('[data-dispatch-load="load"] [data-single-instruction]').count(), 0);
        const singleCard = rows.locator('[data-dispatch-load="single"]');
        assert.deepEqual(await singleCard.locator('.operation-now-dispatch__tags li').allTextContents(),
            ['Instructivo F003', 'Estados Unidos', 'Booking BOOK-i3', '2 programados']);
        assert.equal(await singleCard.locator('.operation-now-dispatch__dock').getAttribute('data-truck-present'), 'false');
        assert.equal(await rows.locator('[data-dispatch-load="load"] .folios-patente').innerText(), 'KFGT13');
        assert.equal(await rows.locator('.folios-perforacion').count(), 3);
        await page.locator('#operationReentryEmpty').getByText('Sin reingresos').waitFor();
        assert.equal(await page.locator('#operationReentryTable').isHidden(), true);
        const first = rows.locator('[data-instruction-id="i1"]');
        const second = rows.locator('[data-instruction-id="i2"]');
        assert.match(await first.innerText(), /Instructivo F001/);
        assert.match(await first.innerText(), /Cargado completo/);
        assert.match(await second.innerText(), /Pendiente/);
        assert.doesNotMatch(await second.innerText(), /Cargado completo/);
        assert.match(await second.innerText(), /0\/2/);
        assert.equal(await second.locator('img').count(), 0);
        assert.equal(await page.evaluate(() => window.__dispatchXss), undefined);
        assert.match(await page.locator('#dispatchPanelSummary').innerText(), /^3 activas/);
        assert.equal(await page.locator('#dispatchLoadingCount').innerText(), '2');
        assert.equal(await page.locator('#dispatchReadyCount').innerText(), '1');
        const workday = page.locator('[data-office-shell-header] .estiba-office-workday');
        assert.equal(await workday.locator('#operationWorkday').textContent(), '11-11-2026');
        assert.equal(await workday.locator('#operationShift').textContent(), ' · Turno A');
        assert.equal(await workday.locator('#operationWorkdayHours').textContent(), '07:00–06:59');
        assert.equal(await page.locator('.operation-now-command #operationWorkday').count(), 0);
        const contextRule = await page.locator('[data-office-shell-header]').evaluate(header => {
            const actual = getComputedStyle(header, '::after').backgroundColor;
            const probe = document.createElement('span');
            probe.style.color = 'var(--area-operacion-ink)';
            header.append(probe);
            const expected = getComputedStyle(probe).color;
            probe.remove();
            return { actual, expected };
        });
        assert.equal(contextRule.actual, contextRule.expected);
        const masthead = page.locator('.operation-now-command h1');
        assert.ok(await masthead.evaluate((title) => parseFloat(getComputedStyle(title).fontSize)) >= 48);
        assert.equal(await page.locator('[data-tab-badge="despacho"]').textContent(), '3');
        assert.equal(await rows.locator('[data-dispatch-load="load"] .operation-now-dispatch__dock').count(), 1);
        assert.equal(await first.locator('.operation-now-dispatch__progress').count(), 2);
        const cells = rows.locator('[data-dispatch-load="single"] .operation-now-dispatch__progress').last();
        assert.equal(await cells.locator('[data-cell="dock"]').count(), 1);
        assert.equal(await cells.locator('[data-cell="pending"]').count(), 1);

        load.instructivos[1].incidencias_abiertas = 1;
        await page.locator('#operationRefresh').click();
        await second.getByText('1 incidencia', { exact: true }).waitFor();
        assert.match(await first.innerText(), /Cargado completo/);
        assert.equal(await rows.locator('[data-dispatch-load]').count(), 3);
        const retencion = { id: 'retencion-1', lote: '10056', folio: 'FOLIO-1', estado: 'retenido', destinos: [{ nombre: 'Perú' }] };
        const compatible = { ...retencion, instructivo_id: 'i1', destino: 'China', bloquea_salida: false };
        const bloqueada = { ...retencion, folio: 'FOLIO-2', instructivo_id: 'i2', destino: 'Perú', bloquea_salida: true };
        Object.assign(load.instructivos[0], { destino: 'China', alertas_sag: [compatible], salida_restringida_sag: false });
        Object.assign(load.instructivos[1], { destino: 'Perú', alertas_sag: [bloqueada], salida_restringida_sag: true });
        Object.assign(load, { alertas_sag: [compatible, bloqueada], salida_restringida_sag: true });
        await page.locator('#operationRefresh').click();
        await second.getByText('Salida restringida SAG', { exact: true }).waitFor();
        assert.match(await first.innerText(), /Cargado completo/);
        assert.equal(await first.locator('.sag-lot-alerts__compact').count(), 0);
        assert.match(await second.innerText(), /1 folio bloqueado · Perú/);
        assert.equal(await rows.locator('.sag-lot-alerts ul').count(), 0);
        const closeLink = rows.getByRole('link', { name: 'Cerrar carga CAR-000032' });
        assert.equal(await rows.getByRole('link', { name: /Cerrar carga/ }).count(), 1);
        const themeSelector = page.getByRole('combobox', { name: 'Tema de Oficina' });
        const palettes = new Map();
        for (const theme of ['light-professional', 'dark-industrial', 'light-professional', 'dark-industrial']) {
            await themeSelector.selectOption(theme);
            await page.waitForFunction(value => document.documentElement.dataset.officeTheme === value, theme);
            const palette = await rows.evaluate(root => {
                const card = root.querySelector('[data-dispatch-load="single"]');
                const label = card.querySelector('.operation-now-dispatch__client');
                const cell = card.querySelector('[data-cell="dock"]');
                const button = root.querySelector('.operation-now-dispatch__close');
                const luminance = rgb => rgb.match(/\d+/g).slice(0, 3).map(Number).map(value => {
                    const n = value / 255;
                    return n <= .04045 ? n / 12.92 : ((n + .055) / 1.055) ** 2.4;
                }).reduce((sum, value, i) => sum + value * [.2126, .7152, .0722][i], 0);
                const ratio = (one, two) => {
                    const [dark, bright] = [luminance(one), luminance(two)].sort((a, b) => a - b);
                    return (bright + .05) / (dark + .05);
                };
                const surface = getComputedStyle(card).backgroundColor;
                const buttonStyle = getComputedStyle(button);
                return {
                    surface, stroke: getComputedStyle(cell).borderTopColor, action: buttonStyle.backgroundColor,
                    labelContrast: ratio(surface, getComputedStyle(label).color),
                    actionContrast: ratio(buttonStyle.backgroundColor, buttonStyle.color),
                };
            });
            assert.ok(palette.labelContrast >= 4.5 && palette.actionContrast >= 4.5, theme);
            if (palettes.has(theme)) assert.deepEqual(palette, palettes.get(theme));
            palettes.set(theme, palette);
        }
        const light = palettes.get('light-professional');
        const dark = palettes.get('dark-industrial');
        for (const property of ['surface', 'stroke', 'action']) assert.notEqual(light[property], dark[property]);
        await page.setViewportSize({ width: 390, height: 840 });
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth), 390);
        await page.setViewportSize({ width: 768, height: 1000 });
        assert.equal(await first.isVisible(), true);
        assert.equal(await second.isVisible(), true);
        await page.setViewportSize({ width: 1440, height: 1000 });
        await closeLink.click();
        await page.locator('#closeDispatchDialog[open]').waitFor({ timeout: 8000 });
        assert.match(await page.locator('#closeDispatchContext').textContent(), /CAR-000032/);
        assert.equal(await page.locator('#closeDispatchForm input[name="patente"]').inputValue(), 'KFGT13');
        assert.equal(new URL(page.url()).searchParams.has('accion'), false);
        assert.deepEqual(closeWrites, []);

        ready.salida_restringida_sag = true;
        await page.goto('http://localhost/oficina/frigorifico/despacho/cargas?carga=ready&accion=cerrar');
        await page.getByText('Esta carga no está disponible para cerrar. Revisa su estado.').waitFor();
        assert.equal(await page.locator('#closeDispatchDialog').isHidden(), true);
        ready.salida_restringida_sag = false;

        allowedToClose = false;
        await page.goto('http://localhost/oficina/operacion-ahora#despacho');
        await rows.locator('[data-dispatch-load="ready"]').waitFor();
        assert.equal(await closeLink.count(), 0);
        assert.deepEqual(closeWrites, []);
        assert.deepEqual(errors, []);
        await context.close();
    } finally {
        await browser.close();
    }
});
