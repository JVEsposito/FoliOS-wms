import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
// EstructuraOficinaTest exporta la vista Blade después de compilar Vite.
const html = readFileSync(process.env.OFFICE_UI_FIXTURE
    || resolve(root, 'storage/app/ui/oficina/materiales.html'), 'utf8');
const season = { id: 'season-1', codigo: 'TEMP-2627', nombre: 'Temporada 2026–2027', activa: true };
const outputItem = { id: 'item-pt', codigo: 'CAJ-10', nombre: 'Caja 10 kg armada', unidad_medida: 'UN' };
const reservations = [
    { id: 'res-1', secuencia: 1, numero_folio: 'FGE0000101', estado: 'usado', cantidad_teorica: '30.000', cantidad_real: '28.000', folio_id: 'folio-1', lote_id: 'lot-1', impresiones: 1, ultima_impresion_at: null, requiere_reimpresion: true, motivo_anulacion: null, anulado_at: null },
    { id: 'res-2', secuencia: 2, numero_folio: 'FGE0000102', estado: 'reservado', cantidad_teorica: '30.000', cantidad_real: null, folio_id: null, lote_id: null, impresiones: 1, ultima_impresion_at: null, requiere_reimpresion: false, motivo_anulacion: null, anulado_at: null },
    { id: 'res-3', secuencia: 3, numero_folio: 'FGE0000103', estado: 'reservado', cantidad_teorica: '20.000', cantidad_real: null, folio_id: null, lote_id: null, impresiones: 0, ultima_impresion_at: null, requiere_reimpresion: false, motivo_anulacion: null, anulado_at: null },
];
const summary = { reservados: 2, sin_imprimir: 1, por_reimprimir: 1, usados: 1, anulados: 0 };
const order = {
    id: 'a1b2c3d4-0000-4000-8000-000000000001',
    estado: 'en_proceso',
    modo_operacion: 'cuadratura_cierre',
    version: 5,
    cantidad_planificada_salida: '80.000',
    cantidad_real_salida: '28.000',
    unidades_por_folio_salida: '30.000',
    fecha_operacional: '2026-10-14',
    temporada: season,
    cliente: { id: 'client-1', codigo: 'GE', nombre: 'Cliente General' },
    version_receta: { id: 'version-1', numero_version: 1, estado: 'activa', receta: { id: 'recipe-1', nombre: 'Caja 10 kg', item_salida: outputItem } },
    reservas_count: 2,
    lotes_count: 1,
    tiene_salidas: true,
    folios_salida_resumen: summary,
};
const detail = {
    ...order,
    receta_snapshot: { salida: { item_id: outputItem.id, codigo: outputItem.codigo, nombre: outputItem.nombre, unidad_medida: 'UN' }, componentes: [] },
    reservas: [],
    folios_salida: reservations,
    lotes: [{
        id: 'lot-1', numero_lote: 1, estado: 'cerrado', cantidad_planificada_salida: '30.000', cantidad_real_salida: '28.000',
        merma_real: '2.000', cerrado_at: '2026-10-14T10:00:00-03:00', consumos: [],
        salidas: [{ id: 'out-1', folio_id: 'folio-1', numero_folio: 'FGE0000101', item: outputItem, cantidad_producida: '28.000', es_salida_principal: true }],
    }],
    eventos: [],
    cuadratura: [{
        item: { id: 'item-mp', codigo: 'CAJ-PL', nombre: 'Caja plana', unidad_medida: 'UN' },
        es_componente_principal: true, cantidad_retirada: '80.000', cantidad_teorica: '58.000',
        cantidad_merma: '2.000', cantidad_sin_armar: '20.000', cantidad_utilizada: '58.000', desviacion: '0.000',
    }],
};
const reception = {
    id: 'reception-1', estado: 'confirmada', numero_guia_despacho: '697996', confirmado_at: '2026-10-29T12:00:00Z', cliente: { codigo: 'AG', nombre: 'LA AGUADA' },
    detalles: [{ item: { codigo: '020019', nombre: 'FONDO 2,5KG GENERICO' }, unidad_medida: 'UN', bultos: [{ cantidad: '1500.000', folio: { id: 'folio-r1', numero_folio: 'FAG000001' } }] }],
};

async function openMaterials(browser, section, theme, search = '') {
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
    const page = await context.newPage();
    const errors = [];
    const labelRequests = [];
    page.on('pageerror', error => errors.push(error.message));
    await context.addInitScript(theme => {
        localStorage.setItem('estiba_wms_office_token', 'test-token');
        localStorage.setItem('estiba_wms_office_identity', JSON.stringify({
            nombre: 'Encargada de Bodega', rol: 'administrador',
            puede_consultar_despachos_materiales: true,
            puede_consultar_transformaciones_materiales: true,
            puede_gestionar_transformaciones_materiales: true,
            puede_imprimir_etiquetas_materiales: true,
            puede_consultar_recepciones_materiales: true,
        }));
        localStorage.setItem('estiba_wms_office_theme', theme);
    }, theme);
    await context.route('http://localhost/**', async route => {
        const request = route.request();
        const { pathname } = new URL(request.url());
        if (pathname.startsWith('/build/')) {
            return route.fulfill({
                body: readFileSync(resolve(root, `public${pathname}`)),
                contentType: pathname.endsWith('.js') ? 'text/javascript' : 'text/css',
            });
        }
        if (pathname === `/api/materiales/transformaciones/ordenes/${order.id}/etiquetas` || pathname === '/api/materiales/recepciones/reception-1/etiquetas') {
            labelRequests.push(JSON.parse(request.postData()));
            return route.fulfill({
                body: 'PDF',
                contentType: 'application/pdf',
                headers: { 'Content-Disposition': 'attachment; filename="etiquetas.pdf"' },
            });
        }
        const responses = {
            '/api/oficina/contexto': { data: { temporada: season, planta: 'Planta de prueba', avisos_cierre: [], avisos_materiales: [] } },
            '/api/materiales/catalogo': { temporada: season, clientes: [], items: [], destinos: [] },
            '/api/materiales/inventario': { data: [], resumen_clientes: [], resumen_items: [], resumen: { folios: 0 }, meta: { total: 0 } },
            '/api/materiales/recepciones': { data: [reception] },
            '/api/materiales/recepciones/reception-1': { data: reception },
            '/api/materiales/recepciones/reception-1/impresiones': { data: [] },
            '/api/materiales/recepciones/perfiles-impresion': { data: [
                { id: 'profile-1', nombre: 'Bixolon 100 × 200', fabricante: 'Bixolon', ancho_mm: 100, alto_mm: 200, predeterminado: true, disenos_disponibles: ['actual', 'clasica_bodega'] },
                { id: 'profile-small', nombre: 'Etiqueta pequeña', fabricante: 'Zebra', ancho_mm: 100, alto_mm: 50 },
            ] },
            '/api/materiales/transformaciones/recetas': { data: [] },
            '/api/materiales/transformaciones/ordenes': { data: [order] },
            [`/api/materiales/transformaciones/ordenes/${order.id}`]: { data: detail },
            [`/api/materiales/transformaciones/ordenes/${order.id}/impresiones`]: { data: [] },
        };
        if (responses[pathname]) return route.fulfill({ json: responses[pathname] });
        if (pathname.startsWith('/oficina/materiales')) {
            return route.fulfill({
                body: html.replace('data-materials-section="resumen"', `data-materials-section="${section}"`),
                contentType: 'text/html',
            });
        }
        return route.fulfill({ status: 404, json: { message: 'No encontrado.' } });
    });
    await page.goto(`http://localhost/oficina/materiales/${section}${search}`);

    return { context, page, errors, labelRequests };
}

test('las órdenes avisan e imprimen los folios de salida reservados antes de producir', async () => {
    const browser = await chromium.launch({
        executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined,
        args: ['--no-sandbox', '--disable-gpu'],
    });
    try {
        for (const theme of ['light-professional', 'dark-industrial']) {
            const orders = await openMaterials(browser, 'ordenes', theme);
            const card = orders.page.locator('.materials-order-card').first();
            await card.waitFor();
            const text = await card.textContent();
            assert.match(text, /1 de 3 folios de salida producidos/);
            assert.match(text, /Cuadratura al cierre/);
            assert.match(text, /1 folio reservado sin imprimir: imprime las etiquetas antes de producir\./);
            assert.match(text, /1 folio cambió de cantidad al producirse: reimprime su etiqueta\./);
            await card.locator('summary').click();
            const table = card.locator('.materials-order-table', { hasText: 'Teórica / real' });
            await table.waitFor();
            const rows = await table.locator('tbody tr').allTextContents();
            assert.equal(rows.length, 3);
            assert.match(rows[0], /FGE0000101[\s\S]*Producido[\s\S]*30 \/ 28 UN[\s\S]*Reimprimir: cambió la cantidad/);
            assert.match(rows[2], /FGE0000103[\s\S]*Reservado[\s\S]*Sin imprimir/);
            const reconciliation = card.locator('.materials-order-table', { hasText: 'Sin armar' });
            assert.match(await reconciliation.locator('tbody tr').first().textContent(), /CAJ-PL[\s\S]*80[\s\S]*58[\s\S]*2[\s\S]*20/);
            if (process.env.ESTIBA_CAPTURAS) {
                await card.screenshot({ path: `${process.env.ESTIBA_CAPTURAS}/orden-${theme}.png` });
            }
            assert.deepEqual(orders.errors, []);
            await orders.context.close();

            const labels = await openMaterials(browser, 'recepcion', theme, `?origen=transformacion&id=${order.id}`);
            const folios = labels.page.locator('#materialLabelFolios .material-label-folio');
            assert.equal(await labels.page.locator('#materialLabelDesign option[value="clasica_bodega"]').evaluate(option => option.disabled), true);
            await labels.page.waitForFunction(() => document.querySelectorAll('#materialLabelFolios .material-label-folio').length === 3);
            const entries = await folios.allTextContents();
            assert.match(entries[0], /FGE0000102 · reservado · reimpresión[\s\S]*30\.000 UN teórica/);
            assert.match(entries[1], /FGE0000103 · reservado[\s\S]*20\.000 UN teórica · lote 3/);
            assert.match(entries[2], /FGE0000101 · reimprimir: cambió la cantidad[\s\S]*28\.000 UN/);
            assert.match(await labels.page.locator('#materialLabelSummary').textContent(), /2 reservados con cantidad teórica/);
            if (process.env.ESTIBA_CAPTURAS) {
                await labels.page.locator('#materialLabelWorkspace').screenshot({ path: `${process.env.ESTIBA_CAPTURAS}/etiquetas-${theme}.png` });
            }

            await folios.nth(1).locator('input').check();
            await folios.nth(2).locator('input').check();
            await labels.page.selectOption('#materialLabelForm [name="formato"]', 'pdf');
            await labels.page.locator('#materialLabelForm button[type="submit"]').click();
            await labels.page.waitForFunction(() => document.querySelector('#materialLabelError').textContent === '');
            await labels.page.waitForTimeout(100);
            assert.equal(labels.labelRequests.length, 1);
            assert.deepEqual(labels.labelRequests[0].reserva_ids, ['res-3']);
            assert.deepEqual(labels.labelRequests[0].folio_ids, ['folio-1']);
            assert.deepEqual(labels.errors, []);
            await labels.context.close();
        }
    } finally {
        await browser.close();
    }
});

test('Clásica es optativa por recepción y perfil, y la selección viaja al servidor', async () => {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
    try {
        for (const theme of ['light-professional', 'dark-industrial']) {
            const h = await openMaterials(browser, 'recepcion', theme, '?origen=recepcion&id=reception-1');
            try {
                await h.page.waitForFunction(() => document.querySelectorAll('#materialLabelFolios .material-label-folio').length === 1);
                assert.equal(await h.page.locator('#materialLabelDesign').inputValue(), 'actual');
                await h.page.selectOption('#materialLabelDesign', 'clasica_bodega');
                assert.equal(await h.page.locator('#materialLabelForm [name="simbologia"]').inputValue(), 'code128');
                assert.equal(await h.page.locator('#materialLabelForm select[name="simbologia"] option[value="qr"]').evaluate(option => option.disabled), true, JSON.stringify({ errors: h.errors, design: await h.page.locator('#materialLabelDesign').inputValue(), help: await h.page.locator('#materialLabelDesignHelp').textContent() }));
                assert.match(await h.page.locator('#materialLabelDesignHelp').textContent(), /cantidad recibida por bulto/);
                await h.page.locator('#materialLabelFolios input[type="checkbox"]').check();
                await h.page.locator('#materialLabelForm button[type="submit"]').click();
                await h.page.waitForFunction(() => document.querySelector('#materialLabelStatus').textContent.includes('generado'));
                assert.equal(h.labelRequests.length, 1);
                assert.equal(h.labelRequests[0].diseno, 'clasica_bodega');
                assert.deepEqual(h.labelRequests[0].folio_ids, ['folio-r1']);
                await h.page.selectOption('#materialLabelProfile', 'profile-small');
                assert.equal(await h.page.locator('#materialLabelDesign').inputValue(), 'actual');
                assert.equal(await h.page.locator('#materialLabelDesign option[value="clasica_bodega"]').evaluate(option => option.disabled), true);
                assert.equal(await h.page.locator('#materialLabelForm select[name="simbologia"] option[value="qr"]').evaluate(option => option.disabled), false);
                assert.deepEqual(h.errors, []);
            } finally { await h.context.close(); }
        }
    } finally { await browser.close(); }
});
