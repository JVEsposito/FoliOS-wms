import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';
const root = fileURLToPath(new URL('../../', import.meta.url));
const html = readFileSync(resolve(root, 'storage/app/ui/oficina/tomas.html'), 'utf8').replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, '');
const row = { id: 'r1', folio: 'MAT-001', item_id: 'i1', item: 'Film <Norte>', categoria: 'Insumos', unidad: 'kg', cliente: 'Disfruta', camara: 'MAT-01', posicion: 'B1 P1', posicion_id: 'p1', esperado: 10, contado: 8, diferencia_absoluta: 2, diferencia_pct: -20, tipo: 'diferencia_cantidad', accion: null, clasificacion: null, motivo: null };
const makeTake = (old = false) => ({ id: old ? 'old' : 't1', temporada_id: old ? 's0' : 's1', temporada: old ? '2025–2026' : '2026–2027', finalidad: 'cierre_temporada', estado: old ? 'aprobada' : 'en_revision', version: 5, bodega: 'Bodega Norte', camaras: ['MAT-01'], categoria: null, abierta_por: 'Ana Pérez', exactitud_presencia_pct: 100, exactitud_cantidad_pct: 0, posiciones: [{ posicion_id: 'p1', camara: 'MAT-01', posicion: 'B1 P1', camarero: 'Juan', estado: 'contada' }], diferencias: [{ ...row, ...(old ? { accion: 'ajustar', clasificacion: 'merma', motivo: 'Material deteriorado', decidida_por: 'Ana Pérez', decidida_at: '2026-10-24T13:00:00Z' } : {}) }], totales_items: [], totales_categorias: [], resumen_cierre: [{ bodega: 'Bodega Norte', cliente: 'Disfruta', item: 'Film <Norte>', unidad: 'kg', esperado: 10, contado: 8, faltante_observado: 2, sobrante_observado: 0, merma_aplicada: old ? 2 : 0, ajuste_aplicado: old ? -2 : 0 }], historial_decisiones: old ? [{ folio: 'MAT-001', usuario: 'Ana Pérez', fecha: '2026-10-24T13:00:00Z', accion: 'ajustar', clasificacion: 'merma', motivo: '<b>Material deteriorado</b>', vigente: true }] : [] });
async function fixture({ width = 1366, lost = false } = {}) {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
    const context = await browser.newContext({ viewport: { width, height: 1000 } }); const page = await context.newPage(); const writes = []; const errors = []; let current = makeTake(); let attempts = 0;
    await context.addInitScript(({ width }) => { localStorage.setItem('estiba_wms_office_token', 'token'); localStorage.setItem('estiba_wms_office_identity', JSON.stringify({ rol: 'supervisor_materiales' })); localStorage.setItem('estiba_wms_office_theme', width === 390 ? 'dark-industrial' : 'light-professional'); Object.defineProperty(crypto, 'randomUUID', { value: undefined }); }, { width });
    page.on('pageerror', e => errors.push(e.message));
    await context.route('http://localhost/**', async route => {
        const req = route.request(); const url = new URL(req.url()); const path = url.pathname;
        if (path === '/test') return route.fulfill({ body: html, contentType: 'text/html' });
        if (path.startsWith('/build/')) return route.fulfill({ body: readFileSync(resolve(root, 'public' + path)), contentType: path.endsWith('.css') ? 'text/css' : 'application/octet-stream' });
        if (path === '/shared/uuid.js') return route.fulfill({ body: readFileSync(resolve(root, 'resources/js/shared/uuid.js')), contentType: 'text/javascript' });
        if (path === '/counts.js') return route.fulfill({ body: readFileSync(resolve(root, 'resources/js/office-material-inventory-counts.js'), 'utf8').replace("import './office-navigation';", ''), contentType: 'text/javascript' });
        if (req.method() === 'POST') {
            const data = req.postDataJSON(); writes.push({ path, ...data });
            if (path.endsWith('/decidir')) {
                if (lost && ++attempts === 1) return route.fulfill({ status: 503, json: { message: 'Respuesta perdida. Reintenta.' } });
                current = { ...current, version: current.version + 1, diferencias: [{ ...row, ...data, decidida_por: 'Ana Pérez', decidida_at: '2026-10-24T13:00:00Z' }] };
            } else if (path.endsWith('/aprobar')) current = { ...current, estado: 'aprobada', version: current.version + 1 };
            else current = { ...current, ...data, id: 't2', estado: 'borrador' };
            return route.fulfill({ json: { data: current } });
        }
        if (path === '/api/materiales/tomas') return route.fulfill({ json: { data: [{ ...(url.searchParams.get('temporada_id') === 's0' ? makeTake(true) : current), created_at: '2026-10-24T13:00:00Z' }], meta: { page: 1, last_page: 1 }, temporada_activa_id: 's1', catalogos: { temporadas: [{ id: 's1', nombre: '2026–2027' }, { id: 's0', nombre: '2025–2026' }], bodegas: [{ id: 'w1', nombre: 'Bodega Norte' }], camaras: [{ id: 'cam1', codigo: 'MAT-01' }], camareros: [{ id: 7, name: 'Juan' }], categorias: ['Insumos'] } } });
        if (path === '/api/materiales/tomas/old') return route.fulfill({ json: { data: makeTake(true) } });
        if (path.startsWith('/api/materiales/tomas/')) return route.fulfill({ json: { data: current } });
        return route.fulfill({ status: 404 });
    });
    await page.goto('http://localhost/test'); await page.evaluate(async () => { document.querySelector('[data-office-shell]')?.remove(); await import('/counts.js'); });
    await page.locator('#takes option[value="t1"]').waitFor({ state: 'attached' });
    return { browser, page, writes, errors };
}

test('crear cierre incluye bodega y todas las categorías con UUID válido en HTTP', async () => {
    const { browser, page, writes, errors } = await fixture();
    try {
        await page.locator('#create [name="finalidad"]').selectOption('cierre_temporada');
        assert.equal(await page.locator('#create [name="categoria"]').isDisabled(), true);
        await page.locator('#create [name="almacen_id"]').selectOption('w1');
        await page.locator('#create [name="camara_ids"]').selectOption(['cam1']); await page.locator('#create [name="camarero_ids"]').selectOption(['7']);
        await page.getByRole('button', { name: 'Crear borrador' }).click(); await page.locator('#heading').filter({ hasText: 'borrador' }).waitFor();
        assert.equal(writes[0].finalidad, 'cierre_temporada'); assert.equal(writes[0].almacen_id, 'w1'); assert.equal(writes[0].categoria, null); assert.match(writes[0].operacion_id, /^[0-9a-f-]{36}$/);
        assert.deepEqual(errors, []);
    } finally { await browser.close(); }
});

test('decisión clasificada conserva UUID al reintentar y muestra responsable antes de aprobar', async () => {
    const { browser, page, writes, errors } = await fixture({ lost: true });
    try {
        await page.locator('#takes').selectOption('t1'); await page.locator('[data-decision="r1"]').click();
        await page.locator('#decide [name="accion"]').selectOption('ajustar'); await page.locator('#decide [name="clasificacion"]').selectOption('merma'); await page.locator('#decide [name="motivo"]').fill('Material deteriorado');
        await page.getByRole('button', { name: 'Guardar decisión' }).click(); await page.locator('#decisionError').filter({ hasText: 'Respuesta perdida' }).waitFor();
        await page.getByRole('button', { name: 'Guardar decisión' }).click(); await page.locator('#decision').waitFor({ state: 'hidden' });
        assert.deepEqual(writes[0], writes[1]); assert.equal(writes[1].clasificacion, 'merma'); assert.match(await page.locator('#rows').textContent(), /Ana Pérez/);
        await page.screenshot({ path: resolve(root, 'storage/app/ui/oficina/pr13-cierre-1366.png'), fullPage: true });
        page.once('dialog', dialog => dialog.accept()); await page.locator('[data-action="aprobar"]').click(); await page.locator('#heading').filter({ hasText: 'aprobada' }).waitFor();
        assert.equal(writes[2].version, 6); assert.equal(await page.locator('[data-action]').count(), 0); assert.deepEqual(errors, []);
    } finally { await browser.close(); }
});

test('histórico en celular conserva merma e historial, escapa texto y solo ofrece exportar', async () => {
    const { browser, page, writes, errors } = await fixture({ width: 390 });
    try {
        await page.locator('#season').selectOption('s0'); await page.locator('#takes option[value="old"]').waitFor({ state: 'attached' }); await page.locator('#takes').selectOption('old'); await page.locator('#closingSummary').waitFor();
        assert.equal(await page.locator('#creation').isHidden(), true); assert.equal(await page.locator('[data-action], [data-decision]').count(), 0);
        await page.locator('#filters [name="clasificacion"]').selectOption('merma'); assert.equal(await page.locator('#rows tr').count(), 1);
        await page.locator('#closingSummary summary').click(); assert.match(await page.locator('#decisionHistory').textContent(), /Material deteriorado/); assert.equal(await page.locator('#decisionHistory b').count(), 0); assert.equal(await page.locator('#rows norte').count(), 0);
        assert.equal(await page.locator('[data-download]').count(), 2); assert.equal(writes.length, 0); assert.deepEqual(errors, []);
        await page.screenshot({ path: resolve(root, 'storage/app/ui/oficina/pr13-cierre-390.png'), fullPage: true });
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
    } finally { await browser.close(); }
});
