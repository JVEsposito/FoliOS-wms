import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { extname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
const identity = { nombre: 'Administración', rol: 'administrador', puede_cambiar_atributos_pt: true, modulos_acceso: ['administracion.atributos-pt'] };
const article = (id, variedad, calibre, envase) => ({ id, especie: 'Cereza', variedad, calibre, envase, codigo_externo: null, variedad_validacion_id: `var-${variedad}`, activo: true });
const catalogo = {
    temporada: { id: 't1', codigo: 'T27', nombre: 'Temporada 2026-2027', activa: true }, cliente: 'LA AGUADA',
    articulos: [article('s-2j-5', 'Santina', '2J', 'Caja 5 kg'), article('s-2j-10', 'Santina', '2J', 'Cartón 10 kg'), article('s-3j-5', 'Santina', '3J', 'Caja 5 kg')],
    origenes: [{ id: 'o1', cliente: 'LA AGUADA', marca: 'FALCO', csg: '164539', predio: 'Fundo Uno', csg_validacion_id: 'c1', variedad_ids: ['var-Santina'], activo: true }],
    categorias: [{ id: 'cat1', nombre: 'Exportación', activo: true }],
    combinaciones: ['s-2j-5', 's-2j-10', 's-3j-5'].map((id) => ({ articulo_validacion_id: id, origen_validacion_id: 'o1' })),
};
const contexto = {
    validacion: { id: 'v1', numero_folio: '6000000785', tipo_bulto: 'pallet', cantidad_cajas: 120, linea_proceso: 1, turno: 'A', articulo_validacion_id: 's-2j-5', categoria_validacion_id: 'cat1',
        fecha_embalaje: '2026-11-09', fecha_hora_validacion: '2026-11-09T17:50', articulo: { especie: 'Cereza', variedad: 'Santina', calibre: '2J', envase: 'Caja 5 kg' },
        origen: { cliente: 'LA AGUADA', marca: 'FALCO', csg: '164539' }, categoria: 'Exportación',
        composicion: [{ origen_validacion_id: 'o1', csg: '164539', cantidad_cajas: 120, lote_materia_prima: null, proceso_packing: null }],
        folio: { estado_operacional: 'disponible', condicion_termica: 'prefrio_aprobado', activo: true } },
    catalogo, puede_cambiar_numero_folio: false, exige_lote_proceso: false,
};
const folio = (cambios = {}) => ({ id: 'f1', numero_folio: '6000000785', tipo_bulto: 'pallet', estado_operacional: 'disponible', condicion_termica: 'prefrio_aprobado', habilitacion_almacenamiento: 'habilitado',
    activo: true, exportadora: 'LA AGUADA', marca: 'FALCO', especie: 'Cereza', variedad: 'Santina', calibre: '2J', envase: 'Caja 5 kg', categoria: 'Exportación', csg: '164539', cantidad_cajas: 120,
    ubicacion: { camara: 'Cámara 9 · Cerezas', posicion: 'B04-P07-N2' }, ...cambios });
const ficha = (cambios = {}, extra = {}) => ({ folio: folio(cambios), carga: { id: 'c1', codigo: 'CAR-0012', estado: 'en_preparacion' }, proceso_prefrio: null, validacion_id: 'v1',
    editable: true, motivo_bloqueo: null, puede_cambiar_producto: true, puede_cambiar_condicion: true,
    condiciones: ['prefrio_aprobado', 'condicion_heredada', 'pendiente_prefrio', 'requiere_reproceso', 'retenido'], historial: [], ...extra });

async function open(browser, { width = 1280, initial = ficha() } = {}) {
    const context = await browser.newContext({ viewport: { width, height: 900 } });
    await context.addInitScript((user) => {
        localStorage.setItem('estiba_wms_office_token', 'fixture');
        localStorage.setItem('estiba_wms_office_identity', JSON.stringify(user));
    }, identity);
    const html = readFileSync(resolve(root, 'storage/app/ui/oficina/atributos-pt.html'), 'utf8');
    const writes = []; let current = initial;
    await context.route('http://localhost/**', async (route) => {
        const request = route.request(); const url = new URL(request.url()); const path = url.pathname;
        if (path === '/oficina/administracion/atributos-pt') return route.fulfill({ body: html, contentType: 'text/html' });
        if (path.startsWith('/build/') || path.startsWith('/brand/')) {
            try { return route.fulfill({ body: readFileSync(resolve(root, `public${path}`)), contentType: ({ '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml', '.woff2': 'font/woff2' })[extname(path)] || 'application/octet-stream' }); } catch { return route.fulfill({ status: 404 }); }
        }
        const base = '/api/administracion/atributos-pt/folios';
        if (path === base) return url.searchParams.get('folio') === '6000000785' ? route.fulfill({ json: { data: current } }) : route.fulfill({ status: 404, json: { message: 'No hay un folio PT con ese número en la temporada activa.' } });
        if (path === `${base}/f1`) return route.fulfill({ json: { data: current } });
        if (path === `${base}/f1/producto` && request.method() === 'GET') return route.fulfill({ json: contexto });
        if (path === `${base}/f1/producto`) {
            const body = request.postDataJSON(); writes.push({ path, body });
            current = ficha({ envase: 'Cartón 10 kg', cantidad_cajas: 118 }, { historial: [{ id: 'h1', tipo: 'producto', antes: folio(), despues: folio({ envase: 'Cartón 10 kg', cantidad_cajas: 118 }), motivo: body.motivo_correccion, carga_codigo: 'CAR-0012', usuario: 'Administración', created_at: '2026-11-11T12:00:00Z' }] });
            return route.fulfill({ json: { data: current, message: 'Producto del folio cambiado y auditado.' } });
        }
        if (path === `${base}/f1/condicion-termica`) {
            const body = request.postDataJSON(); writes.push({ path, body });
            current = ficha({ condicion_termica: 'retenido', estado_operacional: 'bloqueado' }, { historial: [{ id: 'h2', tipo: 'condicion_termica', antes: folio(), despues: folio({ condicion_termica: 'retenido', estado_operacional: 'bloqueado' }), motivo: body.motivo, carga_codigo: 'CAR-0012', usuario: 'Administración', created_at: '2026-11-11T12:05:00Z' }] });
            return route.fulfill({ json: { data: current, message: 'Condición de prefrío cambiada y auditada.' } });
        }
        return route.fulfill({ json: { data: {} } });
    });
    const page = await context.newPage(); const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await page.goto('http://localhost/oficina/administracion/atributos-pt');
    return { context, page, writes, errors };
}

const launch = () => chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
const capture = async (page, name) => {
    if (process.env.FOLIOS_UI_CAPTURE_DIR) await page.screenshot({ path: resolve(process.env.FOLIOS_UI_CAPTURE_DIR, `${name}.png`) });
};

for (const width of [1280, 390]) test(`busca un folio, avisa su carga y cambia el prefrío con motivo (${width}px)`, async () => {
    const browser = await launch();
    try {
        const { context, page, writes, errors } = await open(browser, { width });
        await page.locator('#ptaSearch [name="folio"]').fill('NO-EXISTE');
        await page.locator('#ptaSearch button').click();
        await page.locator('.toast--error').waitFor();
        assert.match(await page.locator('.toast--error').innerText(), /No hay un folio PT/);

        await page.locator('#ptaSearch [name="folio"]').fill('6000000785');
        await page.locator('#ptaSearch button').click();
        await page.locator('#ptaFolioTitle', { hasText: '6000000785' }).waitFor();
        assert.match(await page.locator('#ptaAttributes').innerText(), /Embalaje\s*Caja 5 kg/);
        assert.match(await page.locator('#ptaNotice').innerText(), /carga CAR-0012/);
        assert.equal(await page.locator('#ptaLabelLink').getAttribute('href'), '/oficina/validacion/etiquetas?folio=6000000785');
        // La condición actual no se ofrece como cambio.
        assert.equal(await page.locator('#ptaConditions input[value="prefrio_aprobado"]').count(), 0);

        await page.locator('#ptaConditions input[value="retenido"]').check();
        assert.match(await page.locator('#ptaThermalEffect').innerText(), /se suspende su carga/);
        await page.locator('#ptaThermalForm [name="motivo"]').fill('corto');
        await page.locator('#ptaThermalSubmit').click();
        assert.match(await page.locator('#ptaThermalError').innerText(), /al menos 10/);
        assert.equal(writes.length, 0);
        await page.locator('#ptaThermalForm [name="motivo"]').fill('Fruta con daño por frío detectado en inspección.');
        await page.locator('#ptaThermalSubmit').click();
        await page.locator('.pta-change').first().waitFor();
        assert.equal(writes[0].body.condicion, 'retenido');
        assert.match(writes[0].body.operacion_id, /^[0-9a-f-]{36}$/);
        const history = await page.locator('#ptaHistory').innerText();
        assert.match(history, /Fruta con daño por frío/);
        assert.match(history, /Prefrío aprobado\s*Retenido/);
        assert.match(history, /carga CAR-0012/);
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), true, `desborde a ${width}px`);
        await capture(page, `atributos-pt-${width}`);
        assert.deepEqual(errors, []);
        await context.close();
    } finally { await browser.close(); }
});

test('cambia embalaje y cajas con el diálogo de corrección y llama al módulo de administración', async () => {
    const browser = await launch();
    try {
        const { context, page, writes, errors } = await open(browser);
        await page.locator('#ptaSearch [name="folio"]').fill('6000000785');
        await page.locator('#ptaSearch button').click();
        await page.locator('#ptaProductButton:not([disabled])').click();
        const dialog = page.locator('#validationCorrectionDialog');
        await dialog.waitFor();
        assert.match(await page.locator('#validationCorrectionState').innerText(), /Cambio de administración/);
        const envase = dialog.locator('[data-vc-picker="envase"] input');
        await envase.click();
        await envase.fill('Cartón');
        await dialog.locator('[data-vc-picker="envase"] .search-select__option', { hasText: 'Cartón 10 kg' }).click();
        await dialog.locator('[data-line-field="boxes"]').fill('118');
        await dialog.locator('[name="motivo_correccion"]').fill('Se embaló en cartón de 10 kg; conteo físico 118 cajas.');
        await page.locator('#vcSubmit').click();
        await page.locator('.pta-change').first().waitFor();
        const write = writes.find((entry) => entry.path.endsWith('/producto'));
        assert.equal(write.body.articulo_validacion_id, 's-2j-10');
        assert.equal(write.body.cantidad_cajas, 118);
        assert.match(await page.locator('#ptaAttributes').innerText(), /Embalaje\s*Cartón 10 kg/);
        assert.match(await page.locator('#ptaHistory').innerText(), /Cajas\s*120\s*118/);
        await capture(page, 'atributos-pt-producto');
        assert.deepEqual(errors, []);
        await context.close();
    } finally { await browser.close(); }
});

test('un folio despachado se muestra sin opciones de cambio', async () => {
    const browser = await launch();
    try {
        const despachado = ficha({ estado_operacional: 'despachado' }, { carga: null, editable: false, motivo_bloqueo: 'El folio está despachado y ya no admite cambios de atributos.', puede_cambiar_producto: false, puede_cambiar_condicion: false });
        const { context, page, writes, errors } = await open(browser, { initial: despachado });
        await page.goto('http://localhost/oficina/administracion/atributos-pt?folio=6000000785');
        await page.locator('#ptaFolioTitle', { hasText: '6000000785' }).waitFor();
        assert.equal(await page.locator('#ptaProductButton').isDisabled(), true);
        assert.equal(await page.locator('#ptaThermalForm').isHidden(), true);
        assert.match(await page.locator('#ptaNotice').innerText(), /despachado y ya no admite cambios/);
        assert.equal(writes.length, 0);
        assert.deepEqual(errors, []);
        await context.close();
    } finally { await browser.close(); }
});
