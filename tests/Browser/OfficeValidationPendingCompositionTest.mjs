import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve, extname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

// Pendientes por validar muestra cuántas cajas aporta cada CSG y, dentro de él, cada lote y
// proceso: es lo que el validador busca en la etiqueta del pallet.
const root = fileURLToPath(new URL('../../', import.meta.url));
const html = readFileSync(resolve(root, 'storage/app/ui/oficina/pendientes.html'), 'utf8');
const article = { especie: 'CEREZAS', variedad: 'SANTINA', calibre: '3JD', envase: 'MASTER FLA V 2X2,5KG', empresa: 'LA AGUADA', marca: 'LA AGUADA',
    categoria: 'C1', categoria_sugerida: 'CAT1', sin_catalogo: [], mezclados: [] };
const pending = { numero: '1000000170', tipo_bulto: 'pallet', tipo_mezclado: false, cajas: 168, ...article,
    csgs: ['119003', '176364', '3126339'], procesos: ['AR01STD10002', 'AR01STD10009', 'AR01STD10001'], lotes: ['11464', '11471', '11463'],
    recibido_origen_at: '2026-10-05T14:59:00Z', fecha_embalaje: '2026-10-07',
    distribucion: [
        { csg: '119003', predio: 'PREDIO NORTE CON UN NOMBRE LARGO DE PRUEBA', cajas: 72, lineas: [
            { lote: '11464', proceso: 'AR01STD10002', cajas: 24 }, { lote: '11471', proceso: 'AR01STD10009', cajas: 48 }] },
        { csg: '176364', predio: 'PREDIO SUR', cajas: 60, lineas: [{ lote: '11463', proceso: 'AR01STD10001', cajas: 60 }] },
        { csg: '3126339', predio: null, cajas: 36, lineas: [{ lote: null, proceso: 'AR01STD10006', cajas: 36 }] },
    ] };
const onlyFolio = { numero: 'SOLO-1', tipo_bulto: 'saldo', estado_etiqueta: 'Pendiente de prefrío', validado_at: '2026-10-26T15:00:00Z', cajas: 30,
    cliente: 'LA AGUADA', especie: 'Cereza', variedad: 'Santina', calibre: '2J', envase: '5 kg', categoria: 'CAT1', csgs: ['100001'],
    distribucion: [{ csg: '100001', predio: 'PREDIO VALIDADO', cajas: 30, lineas: [{ lote: '11462', proceso: 'AR01STD10000', cajas: 30 }] }] };

async function open(browser, width) {
    const context = await browser.newContext({ viewport: { width, height: 850 } });
    const identity = { nombre: 'Oficina prueba', rol: 'supervisor_frio', puede_consultar_validaciones_pallet: true, modulos_acceso: ['frigorifico.validacion'] };
    await context.addInitScript((user) => { localStorage.setItem('estiba_wms_office_token', 'fixture'); localStorage.setItem('estiba_wms_office_identity', JSON.stringify(user)); }, identity);
    await context.route('http://localhost/**', async (route) => {
        const path = new URL(route.request().url()).pathname;
        if (path === '/oficina/validacion/pendientes') return route.fulfill({ body: html, contentType: 'text/html' });
        if (path.startsWith('/build/') || path.startsWith('/brand/')) {
            try { return route.fulfill({ body: readFileSync(resolve(root, `public${path}`)), contentType: ({ '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml', '.woff2': 'font/woff2' })[extname(path)] || 'application/octet-stream' }); }
            catch { return route.fulfill({ status: 404 }); }
        }
        if (path === '/api/acceso-oficina') return route.fulfill({ json: { usuario: identity } });
        if (path === '/api/oficina/contexto') return route.fulfill({ json: { data: { planta: 'Planta prueba', temporada: null, avisos_cierre: [], avisos_materiales: [] } } });
        if (path === '/api/validacion/pendientes-origen') return route.fulfill({ json: {
            fuente: { habilitada: true, conexion: 'Planta', tabla: 'odbc_stock', bodega: 'AR01', temporada_catalogo: 'Prueba' },
            lectura: { ultima: null, ultima_correcta_at: '2026-10-27T15:01:00Z', desactualizada: false, vigencia_minutos: 20 },
            resumen: { en_origen: 2, validados: 1, pendientes: 1, cajas_pendientes: 168, por_resolver: 0, con_diferencias: 0, solo_en_folios: 1, catalogo_pendiente: 0, pallets_sin_catalogo: 0 },
            pendientes: [pending], por_resolver: [], diferencias: [], solo_en_folios: [onlyFolio], catalogo: [], puede_administrar_catalogo: false,
        } });
        if (path === '/api/validacion/pendientes-origen/conciliaciones') return route.fulfill({ json: { casos: { data: [], current_page: 1, last_page: 1, total: 0 }, resumen: {}, fuente: { vigente: true }, puede_gestionar: false } });
        return route.fulfill({ status: 404, json: { message: 'No disponible en fixture' } });
    });
    const page = await context.newPage(); const errors = []; page.on('pageerror', (error) => errors.push(error.message));
    await page.goto('http://localhost/oficina/validacion/pendientes');
    await page.locator('#pendingBody .pending-composition').waitFor();
    return { context, page, errors };
}

for (const width of [1280, 390]) test(`la composición por CSG, lote y proceso se lee completa en ${width}px`, async () => {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
    try {
        const { context, page, errors } = await open(browser, width);
        const groups = page.locator('#pendingBody .pending-composition__csg');
        assert.equal(await groups.count(), 3);
        assert.match(await groups.nth(0).innerText(), /119003\s+PREDIO NORTE[\s\S]*72\s+lote 11464 · AR01STD10002\s+24\s+lote 11471 · AR01STD10009\s+48/);
        assert.match(await groups.nth(1).innerText(), /176364\s+PREDIO SUR\s+60\s+lote 11463 · AR01STD10001\s+60/);
        assert.match(await groups.nth(2).innerText(), /3126339\s+36\s+sin lote · AR01STD10006\s+36/);
        assert.match(await page.locator('#pendingBody').innerText(), /1000000170/);
        assert.equal(await page.locator('#pendingBody tr:first-child td').count(), 5, 'Una sola columna de composición reemplaza CSG y proceso · lote.');

        await page.locator('#pending-validation-tab-only-folios').click();
        assert.match(await page.locator('#onlyFoliosBody .pending-composition').innerText(), /100001\s+PREDIO VALIDADO\s+30\s+lote 11462 · AR01STD10000\s+30/);

        // La tabla se desplaza dentro de su contenedor; la página no se ensancha.
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), true);
        assert.deepEqual(errors, []);
        await context.close();
    } finally {
        await browser.close();
    }
});
