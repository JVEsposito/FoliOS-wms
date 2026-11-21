import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
const html = readFileSync(resolve(root, 'storage/app/ui/oficina/estandares-consumo.html'), 'utf8').replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, '');
const line = (o, codigo, nombre, cantidad, extra = {}) => ({ id: `l${o}`, orden: o, item_material_id: `i-${codigo}`, codigo_insumo: codigo, descripcion_insumo: nombre, unidad_medida: 'UN', dueno: 'Falco <Fruit>', cantidad_por_caja: cantidad, redondeo: 'entero', variacion_tipo: null, variacion_nombre: null, variacion_valor: null, ...extra });
const planta = { id: 'e1', origen: 'planta', origen_nombre: 'Planta', codigo: 'FC50PM', descripcion: 'FALCO CARTON 5KG POUCH', codigo_articulo: 'FC50PM', activo: true, version: 1, version_vigente: 1, version_futura: false, insumos_sin_item: 1,
    temporada: { id: 's1', codigo: 'T2627', nombre: '2026-2027', activa: true }, cliente: { id: 'c1', codigo: 'FCO', nombre: 'Falco <Fruit>' }, envase: { id: 'v1', nombre: 'FALCO CARTON 5KG', codigo_externo: 'FC50PM', especie: 'Cereza' }, estandar_base: null,
    versiones: [{ id: 'ver1', numero: 1, vigente_desde: '2026-11-06', vigente: true, origen: 'planta', motivo: 'Lectura de planta', creado_por: 'Ana', lineas: [line(1, '031026', 'TAPA CAJA BOLSITAS 5 KG', 1), line(2, '031000', 'PALLET 1000X1200 TACO', 0.0062), { ...line(3, '031099', 'SIN ÍTEM', 0.025), item_material_id: null, dueno: null }] }] };

const plantData = { configuracion: { conexion: { id: 'x1', nombre: 'Planta' }, conexion_id: 'x1', tabla: 'odbc_estandar_materiales', temporada_origen: 'T2627', empresas: [], habilitada: true, version: 1 }, conexiones: [],
    ultima_lectura: { id: 'r1', estado: 'correcta', leida_at: '2026-11-16T15:00:00Z', temporada_origen: 'T2627', filas: 1333, estandares: 97, filas_duplicadas: 3, cabeceras_descartadas: 14, mensaje: null, usuario: 'Ana' },
    estandares: [{ id: 'o1', cod_empresa: 'FCO', cod_articulo: 'FC50PM', codigo_estandar: 'FC50PM', descripcion_articulo: 'FALCO CARTON 5KG', fecha_creacion: '2026-11-06 09:59', fecha_edicion: null, cabeceras: 2, cliente: { id: 'c1', nombre: 'Falco <Fruit>' }, envase: { id: 'v1', nombre: 'FALCO CARTON 5KG' }, estado: 'nuevo', insumos: 12, insumos_sin_item: 1, insumos_repetidos: ['AR5048'], lineas: [] },
        { id: 'o2', cod_empresa: 'OLM', cod_articulo: 'CC50GM', codigo_estandar: 'CC50GM', descripcion_articulo: 'CAJA CARTON GRANEL', fecha_creacion: '2025-12-01 10:00', fecha_edicion: null, cabeceras: 1, cliente: null, envase: null, estado: 'falta_cliente', insumos: 12, insumos_sin_item: 12, insumos_repetidos: [], lineas: [] }],
    insumos_pendientes: [{ cod_empresa: 'FCO', cliente: 'Falco <Fruit>', cod_insumo: 'AR5048', descripcion: 'ETIQUETA 100X200', estandares: ['FC50PM'], candidatos: [{ id: 'i-a', codigo: 'AR5048', nombre: 'ETIQUETA', dueno: 'Agrorosario' }, { id: 'i-b', codigo: 'AR5048', nombre: 'ETIQUETA', dueno: 'General' }] }],
    resumen: { nuevo: 1, falta_cliente: 1 }, puede_configurar: false, puede_administrar: true };

async function fixture({ width = 1366 } = {}) {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
    const context = await browser.newContext({ viewport: { width, height: 1000 }, acceptDownloads: true });
    const page = await context.newPage(); const calls = []; const errors = [];
    await context.addInitScript(({ width }) => { localStorage.setItem('estiba_wms_office_token', 'token'); localStorage.setItem('estiba_wms_office_theme', width === 390 ? 'dark-industrial' : 'light-professional'); }, { width });
    page.on('pageerror', (e) => errors.push(e.message));
    await context.route('http://localhost/**', async (route) => {
        const url = new URL(route.request().url()); const path = url.pathname; const method = route.request().method();
        if (path === '/test') return route.fulfill({ body: html, contentType: 'text/html' });
        if (path.startsWith('/build/')) return route.fulfill({ body: readFileSync(resolve(root, 'public' + path)), contentType: path.endsWith('.css') ? 'text/css' : 'application/octet-stream' });
        if (path === '/shared/integration-operation') return route.fulfill({ body: readFileSync(resolve(root, 'resources/js/shared/integration-operation.js'), 'utf8'), contentType: 'text/javascript' });
        if (path === '/standards.js') return route.fulfill({ body: readFileSync(resolve(root, 'resources/js/office-material-standards.js'), 'utf8').replace("import './office-navigation';", ''), contentType: 'text/javascript' });
        const body = route.request().postData(); calls.push({ method, path, query: Object.fromEntries(url.searchParams), body: body ? JSON.parse(body) : null });
        if (path.endsWith('/estandares/opciones')) return route.fulfill({ json: { temporadas: [{ id: 's1', codigo: 'T2627', nombre: '2026-2027', activa: true }], temporada_id: 's1', clientes: [{ id: 'c1', codigo: 'FCO', nombre: 'Falco <Fruit>' }],
            variaciones: [{ clave: 'marca', nombre: 'Marca' }, { clave: 'variedad', nombre: 'Variedad' }, { clave: 'calibre', nombre: 'Calibre' }, { clave: 'categoria', nombre: 'Categoría' }],
            envases: url.searchParams.get('cliente_id') ? [{ id: 'v1', nombre: 'FALCO CARTON 5KG', codigo_externo: 'FC50PM', especie: 'Cereza' }] : [], valores: { marca: ['FALCO FRUIT'], variedad: [], calibre: ['XL'], categoria: [] } } });
        if (path.endsWith('/estandares/items')) return route.fulfill({ json: { data: [{ id: 'i-AR5048', codigo: 'AR5048', nombre: 'ETIQUETA 100X200', unidad_medida: 'UN', dueno: 'Agrorosario', del_cliente: false }] } });
        if (path.endsWith('/estandares/excel')) return route.fulfill({ body: 'PK-test', contentType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });
        if (path.endsWith('/estandares/planta')) return route.fulfill({ json: plantData });
        if (path.endsWith('/planta/vinculos/insumos')) return route.fulfill({ json: { message: 'El insumo AR5048 de FCO quedó reconocido.' } });
        if (path.endsWith('/planta/incorporar')) return route.fulfill({ json: { message: 'Estándares de planta: 1 nuevos, 0 actualizados, 0 al día y 1 pendientes de cliente o embalaje.', resumen: { creados: 1 } } });
        if (path.endsWith('/estandares/e1')) return route.fulfill({ json: { data: planta } });
        if (path.endsWith('/estandares') && method === 'POST') {
            const sent = JSON.parse(body);
            return route.fulfill({ status: 201, json: { data: { ...planta, id: 'e2', origen: 'propio', origen_nombre: 'Propio', insumos_sin_item: 0, versiones: [{ ...planta.versiones[0], id: 'ver2', origen: 'propio', motivo: sent.motivo, lineas: sent.lineas.map((l, i) => ({ ...line(i + 1, 'AR5048', 'ETIQUETA 100X200', Number(l.cantidad_por_caja)), variacion_tipo: l.variacion_tipo, variacion_nombre: l.variacion_tipo ? 'Marca' : null, variacion_valor: l.variacion_valor })) }] } } });
        }
        if (path.endsWith('/estandares')) return route.fulfill({ json: { data: [planta], meta: { page: 1, last_page: 1, total: 1 }, temporada_id: 's1', puede_administrar: true } });
        return route.fulfill({ status: 404, json: { message: 'no' } });
    });
    await page.goto('http://localhost/test');
    await page.evaluate(async () => { document.documentElement.dataset.officeTheme = localStorage.getItem('estiba_wms_office_theme'); document.querySelector('[data-office-shell]')?.remove(); await import('/standards.js'); });
    await page.locator('#standardsRows tr td strong').first().waitFor();
    return { browser, page, calls, errors };
}

test('lista y detalle muestran origen, versión vigente e insumos sin homologar escapando texto', async () => {
    const { browser, page, errors } = await fixture();
    try {
        assert.match(await page.locator('#standardsRows').textContent(), /1 sin homologar/);
        await page.getByRole('button', { name: 'Ver', exact: true }).click(); await page.locator('#standardDetail h2').waitFor();
        const detail = await page.locator('#standardDetail').textContent();
        assert.match(detail, /Falco <Fruit>/); assert.match(detail, /0,0062/); assert.match(detail, /Sin homologar/); assert.match(detail, /rige para las cajas embaladas desde el 06-11-2026/);
        assert.equal(await page.locator('#standardDetail fruit').count(), 0);
        assert.equal(await page.getByRole('button', { name: 'Copiar como propio' }).count(), 1);
        assert.equal(await page.getByRole('button', { name: 'Nueva versión' }).count(), 0);
        await page.screenshot({ path: resolve(root, 'storage/app/ui/oficina/estandares-consumo-1366.png'), fullPage: true });
        assert.deepEqual(errors, []);
    } finally { await browser.close(); }
});

test('crea un estándar propio eligiendo el ítem desde la búsqueda y con variación por marca', async () => {
    const { browser, page, calls, errors } = await fixture();
    try {
        await page.getByRole('button', { name: 'Nuevo estándar' }).click(); await page.locator('#standardDialog[open]').waitFor();
        await page.locator('#standardForm [name="cliente_id"]').selectOption('c1');
        await page.locator('#standardForm [name="envase_validacion_id"] option[value="v1"]').waitFor({ state: 'attached' });
        await page.locator('#standardForm [name="envase_validacion_id"]').selectOption('v1');
        await page.locator('#standardForm [name="motivo"]').fill('Etiqueta por marca');
        await page.getByRole('button', { name: 'Guardar' }).click();
        assert.match(await page.locator('#standardFormError').textContent(), /Elige el ítem del insumo 1/);
        await page.locator('[data-item-search]').fill('AR50'); await page.locator('[data-pick="i-AR5048"]').click();
        assert.match(await page.locator('[data-item-hint]').textContent(), /Agrorosario/);
        await page.locator('[data-quantity]').fill('0,5'); await page.locator('[data-variation-type]').selectOption('marca'); await page.locator('[data-variation-value]').fill('FALCO FRUIT');
        await page.screenshot({ path: resolve(root, 'storage/app/ui/oficina/estandares-consumo-editor.png') });
        await page.getByRole('button', { name: 'Guardar' }).click(); await page.locator('#standardDialog:not([open])').waitFor({ state: 'attached' });
        const post = calls.find((c) => c.method === 'POST');
        assert.equal(post.body.cliente_id, 'c1'); assert.equal(post.body.envase_validacion_id, 'v1');
        assert.deepEqual(post.body.lineas, [{ item_material_id: 'i-AR5048', cantidad_por_caja: '0.5', redondeo: 'entero', variacion_tipo: 'marca', variacion_valor: 'FALCO FRUIT' }]);
        await page.locator('#standardsNotice').filter({ hasText: 'Estándar FC50PM creado.' }).waitFor();
        assert.match(await page.locator('#standardDetail').textContent(), /Marca: FALCO FRUIT/);
        assert.deepEqual(errors, []);
    } finally { await browser.close(); }
});

test('en celular la página y el editor quedan dentro del ancho', async () => {
    const { browser, page, errors } = await fixture({ width: 390 });
    try {
        await page.getByRole('button', { name: 'Nuevo estándar' }).click(); await page.locator('#standardDialog[open]').waitFor();
        const layout = await page.evaluate(() => ({ width: document.documentElement.scrollWidth, viewport: innerWidth, dialog: document.querySelector('#standardDialog').getBoundingClientRect().width }));
        assert.ok(layout.width <= layout.viewport, JSON.stringify(layout)); assert.ok(layout.dialog <= layout.viewport, JSON.stringify(layout));
        await page.screenshot({ path: resolve(root, 'storage/app/ui/oficina/estandares-consumo-390.png'), fullPage: true });
        assert.deepEqual(errors, []);
    } finally { await browser.close(); }
});

test('estándares de planta: muestra la lectura, vincula un insumo ambiguo e incorpora', async () => {
    const { browser, page, calls, errors } = await fixture();
    try {
        await page.locator('#plantStandards > summary').click(); await page.locator('#plantRows table').waitFor();
        assert.match(await page.locator('#plantStatus').textContent(), /97 estándares de 1333 filas, 3 filas repetidas, 14 cabeceras antiguas descartadas/);
        assert.match(await page.locator('#plantRows').textContent(), /Falta cliente/); assert.match(await page.locator('#plantRows').textContent(), /Sumados: AR5048/);
        assert.equal(await page.locator('#plantConfigure').isHidden(), true);
        await page.locator('[data-pending="0"]').selectOption('i-a'); await page.locator('[data-link-supply="0"]').click();
        await page.locator('#plantNotice').filter({ hasText: 'quedó reconocido' }).waitFor();
        assert.deepEqual(calls.find((c) => c.path.endsWith('/vinculos/insumos')).body, { cod_empresa: 'FCO', cod_insumo: 'AR5048', item_material_id: 'i-a' });
        await page.getByRole('button', { name: 'Incorporar listos' }).click(); await page.locator('#plantNotice').filter({ hasText: '1 nuevos' }).waitFor();
        await page.screenshot({ path: resolve(root, 'storage/app/ui/oficina/estandares-planta-1366.png'), fullPage: true });
        assert.deepEqual(errors, []);
    } finally { await browser.close(); }
});
