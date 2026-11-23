import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { extname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
const pallet = (id, numero, tunel = null) => ({ id, numero, folio: numero, cliente_id: 'cliente', cliente: 'LA AGUADA', especie: 'Cereza', variedad: 'Royal Dawn', cantidad_cajas: 120, csg: '89868 · 152223', condicion_termica: tunel ? 'en_proceso' : 'prefrio_aprobado', camara: tunel ? null : 'CAM-09', posicion: tunel ? null : 'B15-P04-N1', tunel, sag: { estado: 'SI', destinos: [] } });
const tunnel = { codigo: 'TUN-03', nombre: 'Túnel 3', posicion: 'P12', proceso: 'PFR-0031', estado: 'en_proceso' };
const pallets = [pallet('p1', '6000002818', tunnel), pallet('p2', '6000002256'), pallet('p3', '6000002736', tunnel)];

async function open(browser, width, consulta = false) {
    const context = await browser.newContext({ viewport: { width, height: 900 } });
    await context.addInitScript(({ readOnly, theme }) => {
        localStorage.setItem('estiba_wms_office_token', 'fixture');
        localStorage.setItem('estiba_wms_office_identity', JSON.stringify({ nombre: 'Supervisor', rol: readOnly ? 'consulta' : 'administrador', modulos_acceso: ['frigorifico.inspeccion-sag'] }));
        localStorage.setItem('estiba_wms_office_theme', theme);
        Object.defineProperty(globalThis.crypto, 'randomUUID', { value: undefined }); // HTTP en la red de planta.
    }, { readOnly: consulta, theme: width === 1366 ? 'dark-industrial' : 'light-professional' });
    let members = ['p1']; let estado = 'preparacion'; const patches = [];
    const destination = { id: 'd1', tipo: 'pais', codigo: 'US', nombre: 'Estados Unidos' };
    // Con un pallet el sector aún no tiene espacio; al agregar otro queda repartido en dos bandas.
    const preparation = () => ({ plan_id: 'plan-1', estado: 'programado', capacidad: members.length === 1
        ? { capacidad_estado: 'pendiente', pallets_objetivo: 1, motivo_capacidad_pendiente: 'No hay espacio libre y accesible en bandas de inspección de nivel 1 de una misma cámara para 1 pallet y 1 espacio de separación.' }
        : { capacidad_estado: 'reservada', pallets_objetivo: members.length, pallets_preparados: 1, sector: { camara_codigo: 'CAM-09', banda: 15, posicion_desde: 1, posicion_hasta: 12, bandas: [{ banda: 15, posicion_desde: 1, posicion_hasta: 12, pallets: 8, separacion: 4 }, { banda: 16, posicion_desde: 1, posicion_hasta: 5, pallets: 3, separacion: 2 }] } } });
    const lot = () => ({ id: 'l1', codigo: 'AG-0001', tipo: 'inspeccion_origen', estado, cantidad_folios: members.length, cantidad_solicitada: members.length, destinos: [destination], preparacion_fisica: preparation(), creado_at: '2026-11-16T16:00:00Z', folios: members.map((id) => ({ id: `a-${id}`, estado: 'pendiente', folio: pallets.find((p) => p.id === id), resultados: [{ id: `r-${id}`, destino: destination, resultado: 'pendiente' }] })) });
    const html = readFileSync(resolve(root, 'storage/app/ui/oficina/inspeccion-sag.html'), 'utf8');
    await context.route('http://localhost/**', (route) => {
        const url = new URL(route.request().url()); const path = url.pathname;
        if (path === '/oficina/frigorifico/inspeccion-sag') return route.fulfill({ body: html, contentType: 'text/html' });
        if (path.startsWith('/build/') || path.startsWith('/brand/')) {
            try { return route.fulfill({ body: readFileSync(resolve(root, `public${path}`)), contentType: ({ '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml', '.woff2': 'font/woff2' })[extname(path)] || 'application/octet-stream' }); } catch { return route.fulfill({ status: 404 }); }
        }
        if (path === '/api/inspeccion-sag/catalogos') return route.fulfill({ json: { tipos_lote: [{ value: 'inspeccion_origen', label: 'Origen', tipo_aprobacion: 'AO' }], tipos_aprobacion: [{ value: 'AO', label: 'Origen' }], bloques: [], paises: [{ id: 'us', codigo: 'US', nombre: 'Estados Unidos' }] } });
        if (path === '/api/inspeccion-sag/folios/opciones') return route.fulfill({ json: { clientes: [], condiciones_termicas: [] } });
        if (path === '/api/inspeccion-sag/resumen') return route.fulfill({ json: { lotes_activos: 1, pallets_en_inspeccion: members.length } });
        if (path === '/api/inspeccion-sag/lotes') return route.fulfill({ json: { data: [lot()] } });
        if (path === '/api/inspeccion-sag/lotes/l1') return route.fulfill({ json: lot() });
        if (path === '/api/inspeccion-sag/folios') return route.fulfill({ json: { data: pallets.filter((p) => p.numero === url.searchParams.get('folio') && !members.includes(p.id)) } });
        if (path === '/api/inspeccion-sag/lotes/l1/folios') {
            const body = route.request().postDataJSON(); patches.push(body);
            assert.equal(route.request().method(), 'PATCH');
            assert.match(body.operacion_id, /^[a-f\d]{8}-[a-f\d]{4}-4[a-f\d]{3}-[89ab][a-f\d]{3}-[a-f\d]{12}$/i);
            members = [...members.filter((id) => !body.quitar.includes(id)), ...body.agregar];
            return route.fulfill({ json: lot() });
        }
        if (path === '/api/inspeccion-sag/lotes/l1/iniciar') { estado = 'en_inspeccion'; return route.fulfill({ json: lot() }); }
        return route.fulfill({ json: { data: {} } });
    });
    const page = await context.newPage(); const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await page.goto('http://localhost/oficina/frigorifico/inspeccion-sag');
    await page.locator('#recentLotsBody .lot-action').waitFor();
    return { context, page, errors, patches };
}

for (const width of [1366, 390]) test(`SAG muestra contexto y permite agregar y quitar pallets en preparación (${width}px)`, async () => {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
    try {
        const { context, page, errors, patches } = await open(browser, width);
        await page.locator('[data-sag-panel="nueva"]').click();
        await page.locator('#singleFolioSearch').fill('6000002736');
        await page.locator('#searchSingleFolioButton').click();
        await page.locator('#eligibleFoliosBody input').waitFor();
        const row = await page.locator('#eligibleFoliosBody').innerText();
        for (const value of ['120', '89868', '152223', 'TUN-03', 'P12']) assert.ok(row.includes(value));
        assert.doesNotMatch(row, /Sin ubicación/);
        await page.locator('[data-sag-panel="resumen"]').click();
        await page.locator('#recentLotsBody .lot-action').selectOption('view');
        await page.locator('#editSagLotFoliosForm').waitFor();
        assert.equal(await page.locator('#sagDetailTitle strong').innerText(), '0001');
        assert.equal(await page.locator('#sagDetailStatus [data-estado="pendiente"]').count(), 1);
        assert.equal(await page.locator('#sagResultList .folios-code strong').innerText(), '2818');
        assert.match(await page.locator('#sagResultList').innerText(), /120 cajas · CSG 89868 · 152223/);
        assert.equal(await page.locator('[data-remove-sag-folio="p1"]').isDisabled(), true);
        const pending = await page.locator('#sagDetailPreparation').innerText();
        assert.match(pending, /Sector SAG pendiente: no se publican traslados/);
        assert.match(pending, /para 1 pallet y 1 espacio de separación/);
        await page.locator('#addSagLotFolio').fill('6000002256');
        await page.locator('#addSagLotFolioButton').click();
        await page.waitForFunction(() => document.querySelectorAll('#sagResultList .sag-result-card').length === 2 && !document.querySelector('[data-remove-sag-folio="p1"]').disabled);
        const reserved = await page.locator('#sagDetailPreparation').innerText();
        assert.match(reserved, /Sector SAG reservado/);
        assert.match(reserved, /CAM-09 · B15 · P01 a P12 \(8 pallets, 4 de separación\) · B16 · P01 a P05 \(3 pallets, 2 de separación\)/);
        assert.match(reserved, /1 de 2 pallets en su posición/);
        assert.equal(await page.locator('#sagDetailPreparation [data-cell="done"]').count(), 1);
        assert.equal(await page.locator('#sagDetailPreparation [data-cell="pending"]').count(), 1);
        if (width === 1366) {
            const theme = page.getByRole('combobox', { name: 'Tema de Oficina' });
            const colors = new Map();
            for (const key of ['light-professional', 'dark-industrial', 'light-professional', 'dark-industrial']) {
                await theme.selectOption(key);
                await page.waitForFunction(expected => document.documentElement.dataset.officeTheme === expected, key);
                const palette = await page.locator('#sagLotDetail').evaluate(detail => [
                    getComputedStyle(detail).backgroundColor,
                    getComputedStyle(detail.querySelector('.folios-estado')).color,
                    getComputedStyle(detail.querySelector('[data-cell="done"]')).backgroundColor,
                ]);
                if (colors.has(key)) assert.deepEqual(palette, colors.get(key));
                colors.set(key, palette);
            }
            assert.notDeepEqual(colors.get('light-professional'), colors.get('dark-industrial'));
        }
        assert.equal(await page.locator('#sagDetailPreparation.sag-preparation--pending').count(), 0);
        if (process.env.FOLIOS_UI_CAPTURE_DIR) await page.locator('#sagLotDetail').screenshot({ path: resolve(process.env.FOLIOS_UI_CAPTURE_DIR, `sag-lote-${width}.png`) });
        await page.locator('[data-remove-sag-folio="p1"]').click();
        await page.waitForFunction(() => document.querySelectorAll('#sagResultList .sag-result-card').length === 1 && document.querySelector('[data-remove-sag-folio="p2"]').disabled);
        assert.match(await page.locator('#sagResultList').innerText(), /CAM-09[\s\S]*B15-P04-N1/);
        assert.deepEqual(patches.map((p) => [p.agregar, p.quitar]), [[['p2'], []], [[], ['p1']]]);
        await page.locator('#detailActionSelect').selectOption('start');
        await page.waitForFunction(() => document.querySelector('#editSagLotFoliosForm').classList.contains('is-hidden'));
        assert.equal(await page.locator('[data-remove-sag-folio]').count(), 0);
        assert.equal(await page.locator('#sagDetailPreparation').isVisible(), false);
        assert.equal(await page.locator('#sagDetailStatus [data-estado="en-curso"]').count(), 1);
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), true, JSON.stringify(await page.evaluate(() => ({ viewport: innerWidth, scroll: document.documentElement.scrollWidth, offset: scrollX, overflows: [...document.querySelectorAll('*')].filter((el) => el.getBoundingClientRect().right > innerWidth).map((el) => [el.tagName, el.id || el.className, Math.round(el.getBoundingClientRect().right)]).slice(-15) }))));
        assert.deepEqual(errors, []);
        await context.close();
    } finally { await browser.close(); }
});

test('Consulta ve el contexto SAG y no recibe controles para editar el lote', async () => {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
    try {
        const { context, page, errors } = await open(browser, 1366, true);
        await page.locator('#recentLotsBody .lot-action').selectOption('view');
        await page.locator('#sagResultList .sag-result-card').waitFor();
        assert.equal(await page.locator('#editSagLotFoliosForm').isVisible(), false);
        assert.equal(await page.locator('[data-remove-sag-folio]').count(), 0);
        assert.match(await page.locator('#sagResultList').innerText(), /TUN-03/);
        assert.deepEqual(errors, []);
        await context.close();
    } finally { await browser.close(); }
});
