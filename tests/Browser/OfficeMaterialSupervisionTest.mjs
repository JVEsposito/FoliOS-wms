import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
const html = readFileSync(resolve(root, 'storage/app/ui/oficina/supervision.html'), 'utf8').replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, '');
const catalogs = { hoy: '2026-10-22', temporadas: [{ id: 'season', nombre: '2026', activa: true }], clientes: [{ id: 'client', nombre: 'Exportadora', temporada_id: 'season' }], almacenes: [{ id: 'warehouse', nombre: 'Central' }], usuarios: [{ id: 1, nombre: 'Ana Pérez' }] };
function result(params, extra = {}) {
    const vista = params.get('vista');
    const row = vista === 'stock' ? { id: 'saldo', folio: 'F-1', item: '<Film>', cliente: 'Exportadora', almacen: 'Central', unidad: 'kg', actual: 100, reservada: 20, bloqueada: 0, disponible: 80 }
        : vista === 'recepciones' ? { id: 'deleted', fuente: 'eliminacion', guia: 'G-22', tipo: 'eliminada', usuario: 'Ana Pérez', fecha: '2026-10-22 13:00:00', motivo: 'Duplicada' }
            : { id: 'movement', folio: 'F-1', tipo: 'entrega', cantidad: 10, unidad: 'kg', usuario: 'Ana Pérez', fecha: '2026-10-22 13:00:00' };
    return { data: [row], filtros: Object.fromEntries(params), resumen: vista === 'stock' ? { folios: 1, unidades: [row] } : vista === 'diferencias' ? { unidades: [{ unidad: 'kg', faltante: 4, sobrante: 3 }] } : { tipos: [{ tipo: row.tipo, registros: 1 }] }, meta: { pagina: Number(params.get('page') || 1), paginas: 2, total: 26 }, consultado_at: '2026-10-22T14:00:00Z', zona_horaria: 'America/Santiago', ...extra };
}
async function fixture({ mobile = false, intercept } = {}) {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
    const context = await browser.newContext({ viewport: { width: mobile ? 390 : 1440, height: 1000 } });
    const page = await context.newPage(); const calls = []; const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await context.addInitScript(() => localStorage.setItem('estiba_wms_office_token', 'test-token'));
    await context.route('http://localhost/**', async (route) => {
        const request = route.request(); const url = new URL(request.url());
        if (url.pathname === '/test') return route.fulfill({ body: html, contentType: 'text/html' });
        if (url.pathname.startsWith('/build/')) return route.fulfill({ body: readFileSync(resolve(root, 'public' + url.pathname)), contentType: url.pathname.endsWith('.css') ? 'text/css' : 'application/octet-stream' });
        if (url.pathname === '/office-navigation.js') return route.fulfill({ body: '', contentType: 'text/javascript' });
        if (url.pathname === '/office-material-supervision.js') return route.fulfill({ body: readFileSync(resolve(root, 'resources/js/office-material-supervision.js')), contentType: 'text/javascript' });
        calls.push({ path: url.pathname, method: request.method(), params: url.searchParams });
        if (intercept && await intercept(route, url)) return;
        if (url.pathname.endsWith('/catalogos')) return route.fulfill({ json: catalogs });
        if (url.pathname === '/api/materiales/supervision') return route.fulfill({ json: result(url.searchParams) });
        if (url.pathname.includes('/evidencia/')) return route.fulfill({ json: { data: { estado_anterior: 'anulada', eventos_total: 1, eventos: [{ tipo: 'corregida_administrativamente', fecha: '2026-10-22 12:00:00', usuario: 'Ana Pérez', motivo: '<Corrección>', folios_resultantes: ['F-1'] }] } } });
        return route.fulfill({ status: 404 });
    });
    await page.goto('http://localhost/test');
    await page.evaluate(async () => { document.querySelector('[data-office-shell]')?.remove(); await import('/office-material-supervision.js'); });
    await page.locator('#supervisionResults').waitFor({ state: 'visible' });
    return { browser, page, calls, errors };
}

test('panel filtra y pagina con indicadores completos, abre evidencia eliminada y solo consulta', async () => {
    const { browser, page, calls, errors } = await fixture();
    try {
        assert.match(await page.locator('#supervisionSummary').textContent(), /80/);
        assert.match(await page.locator('#supervisionRows').textContent(), /<Film>/);
        assert.equal(await page.locator('#supervisionRows film').count(), 0);
        await page.locator('[data-page="2"]').click();
        await page.waitForFunction(() => document.getElementById('supervisionPages').textContent.includes('Página 2'));
        await page.selectOption('[name="vista"]', 'recepciones');
        assert.equal(await page.locator('[name="almacen_id"]').isDisabled(), true);
        assert.equal(await page.locator('#supervisionResults').isHidden(), true);
        await page.selectOption('[name="user_id"]', '1');
        await page.selectOption('[name="tipo"]', 'eliminada');
        await page.fill('[name="busqueda"]', 'G-22');
        await page.getByRole('button', { name: 'Consultar', exact: true }).click();
        await page.locator('#supervisionResults').waitFor({ state: 'visible' });
        const request = calls.filter((c) => c.path === '/api/materiales/supervision').at(-1);
        assert.equal(request.params.get('user_id'), '1');
        assert.equal(request.params.get('tipo'), 'eliminada');
        assert.equal(request.params.get('almacen_id'), null);
        await page.locator('[data-detail="0"]').click();
        await page.waitForFunction(() => document.getElementById('supervisionEvidence').textContent.includes('Ana Pérez'));
        assert.match(await page.locator('#supervisionEvidence').textContent(), /<Corrección>/);
        assert.match(await page.locator('#supervisionScope').textContent(), /America\/Santiago/);
        assert.ok(calls.every((c) => c.method === 'GET'));
        assert.deepEqual(errors, []);
    } finally { await browser.close(); }
});

test('tablet conserva filtros y detalle; una denegación limpia los resultados previos', async () => {
    let deny = false;
    const { browser, page, errors } = await fixture({ mobile: true, intercept: async (route, url) => {
        if (!deny || url.pathname !== '/api/materiales/supervision') return false;
        await route.fulfill({ status: 403, json: { message: 'Forbidden' } }); return true;
    } });
    try {
        await page.locator('[data-detail="0"]').click();
        assert.match(await page.locator('#supervisionDetailFields').textContent(), /Disponible80/);
        await page.getByRole('button', { name: 'Cerrar detalle' }).click();
        deny = true;
        await page.getByRole('button', { name: 'Actualizar', exact: true }).click();
        await page.waitForFunction(() => document.getElementById('supervisionNotice').textContent.includes('no tiene permiso'));
        assert.equal(await page.locator('#supervisionResults').isHidden(), true);
        assert.equal(await page.locator('#supervisionDetail').isHidden(), true);
        assert.deepEqual(errors, []);
    } finally { await browser.close(); }
});

test('cambiar filtros descarta una respuesta de evidencia demorada', async () => {
    let release; const deferred = new Promise((resolve) => { release = resolve; });
    const { browser, page, errors } = await fixture({ intercept: async (route, url) => {
        if (!url.pathname.includes('/evidencia/')) return false;
        await deferred; await route.fulfill({ json: { data: { evento: { tipo: 'anulada', motivo: 'EVIDENCIA ANTIGUA' } } } }); return true;
    } });
    try {
        await page.selectOption('[name="vista"]', 'recepciones');
        await page.getByRole('button', { name: 'Consultar', exact: true }).click();
        await page.locator('#supervisionResults').waitFor({ state: 'visible' });
        await page.locator('[data-detail="0"]').click();
        await page.waitForFunction(() => document.getElementById('supervisionDetailNotice').textContent.includes('Buscando'));
        await page.selectOption('[name="vista"]', 'stock');
        const response = page.waitForResponse((r) => r.url().includes('/evidencia/')); release(); await response;
        assert.equal(await page.locator('#supervisionDetail').isHidden(), true);
        assert.doesNotMatch(await page.locator('#supervisionEvidence').textContent(), /EVIDENCIA ANTIGUA/);
        assert.deepEqual(errors, []);
    } finally { release(); await browser.close(); }
});
