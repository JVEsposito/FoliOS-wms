import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { extname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
const identity = (extra = {}) => ({ nombre: 'Supervisión Frío', rol: 'supervisor_frio', puede_consultar_control_despacho: true, puede_reabrir_control_despacho: true, modulos_acceso: ['frigorifico.control-despacho'], ...extra });
const catalogo = {
    codigo: 'POEMP R-24', posicion_maxima: 60,
    encabezado: [
        { clave: 'tipo_camion', etiqueta: 'Tipo de camión', tipo: 'opcion', grupo: 'operacion' },
        { clave: 'exportadora', etiqueta: 'Exportadora', tipo: 'texto', grupo: 'operacion' },
        { clave: 'fecha_carga', etiqueta: 'Fecha de carga (real)', tipo: 'fecha', grupo: 'operacion' },
        { clave: 'contenedor', etiqueta: 'N° contenedor', tipo: 'texto', grupo: 'unidad' },
        { clave: 'conductor', etiqueta: 'Nombre conductor', tipo: 'texto', grupo: 'transporte' },
    ],
    inspeccion: [
        { clave: 'puertas', etiqueta: 'Puertas en buen estado', aplica: null },
        { clave: 'danos_visuales', etiqueta: 'Unidad presenta daños aparentes visuales', aplica: null },
        { clave: 'horometro', etiqueta: 'Horómetro en buen estado (thermos)', aplica: 'thermo' },
        { clave: 'trinca', etiqueta: 'Trinca instalada', aplica: null },
    ],
    cierre: [
        { clave: 'cajas_guia', etiqueta: 'Cajas totales guía de despacho', tipo: 'entero' },
        { clave: 'responsable', etiqueta: 'Responsable', tipo: 'texto' },
    ],
    tipos_foto: [
        { clave: 'patente', nombre: 'Placa patente', obligatoria: true, condicion: null, maximo: 3 },
        { clave: 'general', nombre: 'Otras fotos del despacho', obligatoria: false, condicion: null, maximo: 20 },
    ],
    exige_fotos: false,
};
const pallet = (folio, posicion, temperatura, estado = 'cargado') => ({
    asignacion_id: `a-${folio}`, folio_id: `f-${folio}`, numero_folio: folio, estado_carga: estado, especie: 'Cereza', variedad: 'Santina', cajas_folio: 80,
    posicion: posicion ? { id: `p-${folio}`, posicion, codigo_pe: 'P', cajas: 80, temperatura, registrado_at: '2026-11-11T17:10:00Z', registrado_por: 'Consolidador' } : null,
});
const control = (extra = {}) => ({
    id: 'c1', estado: 'cerrado', version: 4,
    carga: { id: 'g1', codigo: 'CAR-0042', estado: 'cerrada', embarque: 'EMB-0099', anden: 'AND-2', patente: 'ABCD12' },
    encabezado: { tipo_camion: 'contenedor', exportadora: 'Subsole', fecha_carga: '2026-11-11', contenedor: 'MSCU1234567', conductor: null },
    sugerido: { encabezado: {}, cierre: {} },
    inspeccion: { puertas: 'si', danos_visuales: 'si', horometro: 'na', trinca: null },
    cierre: { cajas_guia: 160, responsable: 'Consolidador' },
    pallets: [pallet('A000002', 2, -0.4), pallet('A000001', 1, 0.5), pallet('A000003', null, null, 'en_anden')],
    totales: { pallets: 3, cargados: 2, en_anden: 1, en_camara: 0, posicionados: 2, con_temperatura: 2, cajas_pcd: 160, cajas_carga: 240 },
    iniciado_at: '2026-11-11T16:50:00Z', iniciado_por: 'Consolidador', cerrado_at: '2026-11-11T17:40:00Z', cerrado_por: 'Consolidador',
    reaperturas: [], fotos: [{ id: 'ph1', tipo: 'patente', orden: 1, url: '/api/control-despacho/controles/c1/fotos/ph1/original', miniatura_url: '/api/control-despacho/controles/c1/fotos/ph1/miniatura', subida_at: '2026-11-11T17:20:00Z' }],
    faltantes_cierre: [], faltantes_impresion: [], fotos_pendientes: [], ...extra,
});
const pixel = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');

async function open(browser, { width = 1280, user = identity(), detail = control(), serverIdentity = user } = {}) {
    const context = await browser.newContext({ viewport: { width, height: 900 }, acceptDownloads: true });
    await context.addInitScript((value) => {
        localStorage.setItem('estiba_wms_office_token', 'fixture');
        localStorage.setItem('estiba_wms_office_identity', JSON.stringify(value));
    }, user);
    const html = readFileSync(resolve(root, 'storage/app/ui/oficina/control-despacho.html'), 'utf8');
    const requests = []; let current = detail;
    await context.route('http://localhost/**', async (route) => {
        const request = route.request(); const url = new URL(request.url()); const path = url.pathname;
        if (path === '/oficina/frigorifico/control-despacho') return route.fulfill({ body: html, contentType: 'text/html' });
        if (path.startsWith('/build/') || path.startsWith('/brand/')) {
            try { return route.fulfill({ body: readFileSync(resolve(root, `public${path}`)), contentType: ({ '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml', '.woff2': 'font/woff2' })[extname(path)] || 'application/octet-stream' }); } catch { return route.fulfill({ status: 404 }); }
        }
        requests.push({ path, search: url.search, method: request.method(), body: request.postData() ? request.postDataJSON() : null, auth: request.headers().authorization });
        if (path === '/api/acceso-oficina' && request.method() === 'GET') return route.fulfill({ json: { usuario: serverIdentity } });
        if (path === '/api/control-despacho/catalogo') return route.fulfill({ json: { data: catalogo } });
        if (path === '/api/control-despacho/controles') {
            const rows = url.searchParams.get('estado') === 'borrador' ? [] : [{ id: 'c1', estado: current.estado, carga: { id: 'g1', codigo: 'CAR-0042', estado: 'cerrada' }, embarque: 'EMB-0099', exportadora: 'Subsole', contenedor: 'MSCU1234567', tipo_camion: 'contenedor', pallets: 2, iniciado_at: current.iniciado_at, iniciado_por: 'Consolidador', cerrado_at: current.cerrado_at, cerrado_por: current.cerrado_por }];
            return route.fulfill({ json: { data: rows, meta: { current_page: 1, last_page: 1, total: rows.length } } });
        }
        if (path === '/api/control-despacho/controles/c1') return route.fulfill({ json: { data: current } });
        if (path.endsWith('/miniatura') || path.endsWith('/original')) return route.fulfill({ body: pixel, contentType: 'image/png' });
        if (path === '/api/control-despacho/controles/c1/fotos.pdf') return route.fulfill({ body: Buffer.from('%PDF-1.4 fixture'), contentType: 'application/pdf' });
        if (path === '/api/control-despacho/controles/c1/planilla.xlsx') return route.fulfill({ body: Buffer.from('PK\u0003\u0004fixture'), contentType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });
        if (path === '/api/control-despacho/controles/c1/temperaturas') {
            const values = Object.fromEntries(request.postDataJSON().temperaturas.map((t) => [t.posicion_id, t.temperatura]));
            current = { ...current, faltantes_impresion: [], pallets: current.pallets.map((p) => (p.posicion && values[p.posicion.id] !== undefined ? { ...p, posicion: { ...p.posicion, temperatura: values[p.posicion.id] } } : p)) };
            return route.fulfill({ json: { data: current } });
        }
        if (path === '/api/control-despacho/controles/c1/reabrir') {
            current = control({ estado: 'borrador', version: 5, cerrado_at: null, cerrado_por: null, faltantes_cierre: ['Responde la inspección visual: faltan 1 puntos.'],
                reaperturas: [{ motivo: request.postDataJSON().motivo, nombre: 'Supervisión Frío', reabierto_at: '2026-11-11T18:00:00Z' }] });
            return route.fulfill({ json: { data: current } });
        }
        return route.fulfill({ json: { data: {} } });
    });
    const page = await context.newPage(); const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await page.goto('http://localhost/oficina/frigorifico/control-despacho');
    return { context, page, requests, errors };
}

const launch = () => chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
const capture = async (page, name) => {
    if (process.env.FOLIOS_UI_CAPTURE_DIR) await page.screenshot({ path: resolve(process.env.FOLIOS_UI_CAPTURE_DIR, `${name}.png`), fullPage: true });
};

for (const width of [1280, 390]) test(`lista las planillas y abre el detalle con inspección, pallets y fotos (${width}px)`, async () => {
    const browser = await launch();
    try {
        const { context, page, requests, errors } = await open(browser, { width });
        await page.locator('#dcxRows tr[data-id="c1"]').waitFor();
        assert.match(await page.locator('#dcxCount').innerText(), /1 planilla/);
        await page.locator('#dcxRows tr[data-id="c1"]').click();
        await page.locator('#dcxDetailTitle', { hasText: 'CAR-0042' }).waitFor();
        assert.match(await page.locator('#dcxDetailState').innerText(), /CERRADA/);
        assert.match(await page.locator('#dcxHeader').innerText(), /Tipo de camión\s*Contenedor/);
        assert.match(await page.locator('#dcxHeader').innerText(), /11-11-2026/);
        assert.doesNotMatch(await page.locator('#dcxHeader').innerText(), /Nombre conductor/);
        // «Daños visuales: sí» es un hallazgo, no una conformidad.
        assert.equal(await page.locator('#dcxInspection li', { hasText: 'daños aparentes' }).getAttribute('data-tone'), 'alerta');
        assert.equal(await page.locator('#dcxInspection li', { hasText: 'Puertas' }).getAttribute('data-tone'), 'ok');
        assert.match(await page.locator('#dcxInspection li', { hasText: 'Trinca' }).innerText(), /Sin respuesta/);
        const folios = await page.locator('#dcxPallets tr td:nth-child(3)').allInnerTexts();
        assert.deepEqual(folios, ['A000001', 'A000002', 'A000003']);
        assert.match(await page.locator('#dcxPallets tr').first().innerText(), /0,5 °C/);
        assert.match(await page.locator('#dcxPallets tr').first().innerText(), /Punta/);
        await page.waitForFunction(() => document.querySelector('#dcxPhotos img')?.src.startsWith('blob:'));
        assert.match(await page.locator('#dcxPhotos').innerText(), /Placa patente/);
        assert.ok(requests.filter((r) => r.path.endsWith('/miniatura')).every((r) => r.auth === 'Bearer fixture'));
        assert.equal(await page.locator('#dcxReopen').isVisible(), true);
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), true, `desborde a ${width}px`);
        await capture(page, `control-despacho-${width}`);

        const download = page.waitForEvent('download');
        await page.locator('#dcxDownload').click();
        assert.equal((await download).suggestedFilename(), 'R-24_CAR-0042.xlsx');
        assert.deepEqual(errors, []);
        await context.close();
    } finally { await browser.close(); }
});

test('reabre una planilla cerrada con motivo y filtra por estado', async () => {
    const browser = await launch();
    try {
        const { context, page, requests, errors } = await open(browser);
        await page.locator('#dcxRows tr[data-id="c1"]').click();
        await page.locator('#dcxReopenForm [name="motivo"]').fill('corto');
        await page.locator('#dcxReopenForm button').click();
        assert.match(await page.locator('#dcxReopenError').innerText(), /mínimo 10/);
        await page.locator('#dcxReopenForm [name="motivo"]').fill('Faltó registrar el termógrafo de control.');
        await page.locator('#dcxReopenForm button').click();
        await page.locator('#dcxDetailState', { hasText: 'EN CURSO' }).waitFor();
        const write = requests.find((r) => r.path.endsWith('/reabrir'));
        assert.equal(write.body.version, 4);
        assert.match(write.body.operacion_id, /^[0-9a-f-]{36}$/);
        assert.match(await page.locator('#dcxMissing').innerText(), /faltan 1 puntos/);
        assert.match(await page.locator('#dcxHistoryList').innerText(), /termógrafo de control/);
        assert.equal(await page.locator('#dcxReopen').isVisible(), false);

        await page.locator('#dcxFilters [name="estado"]').selectOption('borrador');
        await page.locator('#dcxFilters button').click();
        await page.locator('#dcxRows', { hasText: 'No hay planillas' }).waitFor();
        assert.ok(requests.some((r) => r.path === '/api/control-despacho/controles' && r.search.includes('estado=borrador')));
        assert.deepEqual(errors, []);
        await context.close();
    } finally { await browser.close(); }
});

test('sin permiso de reapertura no se ofrece reabrir', async () => {
    const browser = await launch();
    try {
        const { context, page, errors } = await open(browser, { user: identity({ puede_reabrir_control_despacho: false }) });
        await page.locator('#dcxRows tr[data-id="c1"]').click();
        await page.locator('#dcxDetailTitle', { hasText: 'CAR-0042' }).waitFor();
        assert.equal(await page.locator('#dcxReopen').isVisible(), false);
        assert.deepEqual(errors, []);
        await context.close();
    } finally { await browser.close(); }
});

test('antes de descargar pide completar la T° de pulpa y luego descarga', async () => {
    const browser = await launch();
    try {
        const incompleta = control({
            pallets: [pallet('A000001', 1, 0.5), pallet('A000002', 2, null)],
            faltantes_impresion: ['Falta la T° de pulpa de 1 pallets: A000002.'],
            fotos_pendientes: [{ tipo: 'patente', nombre: 'Placa patente', requeridas: 1, tomadas: 0 }],
        });
        const { context, page, requests, errors } = await open(browser, { user: identity({ puede_registrar_control_despacho: true }), detail: incompleta });
        await page.locator('#dcxRows tr[data-id="c1"]').click();
        await page.locator('#dcxDetailTitle', { hasText: 'CAR-0042' }).waitFor();
        assert.match(await page.locator('#dcxPhotosPending').innerText(), /Placa patente.*El cierre aún no las exige/);
        await page.locator('#dcxDownload').click();
        await page.locator('#dcxPrint').waitFor();
        assert.match(await page.locator('#dcxPrintText').innerText(), /A000002/);
        assert.equal(requests.some((r) => r.path.endsWith('/planilla.xlsx')), false, 'No descarga con datos faltantes');
        await page.locator('#dcxPrintTemps input').fill('99');
        await page.locator('#dcxPrintForm button').click();
        assert.match(await page.locator('#dcxPrintError').innerText(), /-10 a 40/);
        await page.locator('#dcxPrintTemps input').fill('-0,3');
        const download = page.waitForEvent('download');
        await page.locator('#dcxPrintForm button').click();
        assert.equal((await download).suggestedFilename(), 'R-24_CAR-0042.xlsx');
        const write = requests.find((r) => r.path.endsWith('/temperaturas'));
        assert.deepEqual(write.body.temperaturas, [{ posicion_id: 'p-A000002', temperatura: -0.3 }]);
        assert.equal(await page.locator('#dcxPrint').isVisible(), false);
        assert.deepEqual(errors, []);
        await context.close();
    } finally { await browser.close(); }
});

test('sin permiso de registro solo informa qué falta para imprimir', async () => {
    const browser = await launch();
    try {
        const incompleta = control({ pallets: [pallet('A000002', 2, null)], faltantes_impresion: ['Falta la T° de pulpa de 1 pallets: A000002.'] });
        const { context, page, errors } = await open(browser, { user: identity({ puede_registrar_control_despacho: false }), detail: incompleta });
        await page.locator('#dcxRows tr[data-id="c1"]').click();
        await page.locator('#dcxDownload').click();
        await page.locator('#dcxPrint').waitFor();
        assert.match(await page.locator('#dcxPrintText').innerText(), /Pide a consolidación/);
        assert.equal(await page.locator('#dcxPrintForm').isVisible(), false);
        assert.deepEqual(errors, []);
        await context.close();
    } finally { await browser.close(); }
});

test('una planilla cerrada muestra el set fotográfico archivado en PDF', async () => {
    const browser = await launch();
    try {
        const cerrada = control({
            fotos: [{ id: 'ph1', tipo: 'patente', orden: 1, url: null, miniatura_url: null, subida_at: '2026-11-11T17:20:00Z', archivada: true, pagina_pdf: 1 }],
            fotos_pdf: { url: '/api/control-despacho/controles/c1/fotos.pdf', bytes: 2411724, cantidad: 9, generado_at: '2026-11-11T17:41:00Z' },
        });
        const { context, page, requests, errors } = await open(browser, { detail: cerrada });
        await page.locator('#dcxRows tr[data-id="c1"]').click();
        await page.locator('#dcxPhotosPdf').waitFor();
        assert.match(await page.locator('#dcxPhotosPdfText').innerText(), /9 fotos · 2,3 MB/);
        assert.equal(await page.locator('#dcxPhotos img').count(), 0, 'Las fotos archivadas no tienen miniatura');
        const popup = context.waitForEvent('page');
        await page.locator('#dcxPhotosPdfButton').click();
        await popup;
        assert.ok(requests.some((r) => r.path.endsWith('/fotos.pdf') && r.auth === 'Bearer fixture'));
        assert.deepEqual(errors, []);
        await context.close();
    } finally { await browser.close(); }
});

test('una sesión guardada antes del módulo se actualiza sin cerrar la sesión de FoliOS', async () => {
    const browser = await launch();
    try {
        const anterior = identity({ puede_consultar_control_despacho: undefined, puede_reabrir_control_despacho: undefined });
        const { context, page, requests, errors } = await open(browser, { user: anterior, serverIdentity: identity() });
        await page.locator('#dcxRows tr[data-id="c1"]').waitFor();
        assert.ok(requests.some((r) => r.path === '/api/acceso-oficina' && r.method === 'GET'));
        assert.equal(await page.evaluate(() => localStorage.getItem('estiba_wms_office_token')), 'fixture');
        assert.equal(await page.evaluate(() => JSON.parse(localStorage.getItem('estiba_wms_office_identity')).puede_consultar_control_despacho), true);
        assert.deepEqual(errors, []);
        await context.close();
    } finally { await browser.close(); }
});

test('sin el módulo lo informa y no cierra la sesión', async () => {
    const browser = await launch();
    try {
        const sinModulo = identity({ puede_consultar_control_despacho: false });
        const { context, page, errors } = await open(browser, { user: sinModulo, serverIdentity: sinModulo });
        await page.locator('#dcxRows', { hasText: 'no tiene el módulo' }).waitFor();
        assert.equal(await page.evaluate(() => localStorage.getItem('estiba_wms_office_token')), 'fixture');
        assert.equal(page.url(), 'http://localhost/oficina/frigorifico/control-despacho');
        assert.deepEqual(errors, []);
        await context.close();
    } finally { await browser.close(); }
});
