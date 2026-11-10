import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
const read = path => readFileSync(resolve(root, path), 'utf8');
const plateError = 'Patente: ingresa entre 5 y 8 letras mayúsculas o números, sin espacios ni guiones. Ejemplo: ABCD12.';
const clientError = 'Cliente destino: selecciona un cliente activo de la lista.';
const stockError = 'No existe disponibilidad suficiente de bins propia; faltan 1 unidades.';
const navigation = '<div id="officeUserName"></div><div id="officeUserRole"></div><div id="officeInitials"></div><button id="officeLogoutButton">Salir</button>';
const html = read('resources/views/office/container-dispatches.blade.php')
    .replace(/@if\([\s\S]*?@endif/, '')
    .replace(/@include\([^\n]+\)/g, '')
    .replace(/<x-office\.navigation[^>]*\/>/, navigation)
    .replace('</head>', `<style>${read('resources/css/office.css')}\n${read('resources/css/office-container-dispatches.css')}</style></head>`)
    .replace('</body>', '<script type="module" src="/resources/js/office-container-dispatches.js"></script></body>');

test('una diferencia de esponjas de cliente no oculta stock sano y un fallo de carga no se muestra como cero', async () => {
    const browser = await chromium.launch({
        executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined,
        args: ['--no-sandbox', '--disable-gpu'],
    });
    const page = await browser.newPage({ viewport: { width: 1366, height: 900 } });
    const errors = [];
    let failCatalog = false;
    let empty = false;
    let historyLoads = 0;
    let submissions = 0;
    page.on('pageerror', error => errors.push(error.message));
    await page.addInitScript(() => {
        localStorage.setItem('estiba_wms_office_token', 'token');
        localStorage.setItem('estiba_wms_office_identity', JSON.stringify({
            nombre: 'Supervisor', rol: 'supervisor',
            puede_consultar_cuenta_envases: true, puede_gestionar_despacho_envases: true,
        }));
    });
    await page.route('http://localhost/**', async route => {
        const request = route.request();
        const path = new URL(request.url()).pathname;
        if (path === '/test') return route.fulfill({ contentType: 'text/html', body: html });
        if (path.startsWith('/resources/js/')) return route.fulfill({ contentType: 'text/javascript', body: read(path.slice(1)) });
        if (path === '/api/envases/catalogo') return route.fulfill({ json: { data: [{ codigo: 'esponjas', nombre: 'Esponja de bins' }] } });
        if (path.includes('/productores/')) return route.fulfill({ json: { data: [] } });
        if (path === '/api/envases/guias-despacho/catalogos') {
            if (failCatalog) return route.fulfill({ status: 500, json: { message: 'Error de prueba de inventario' } });
            return route.fulfill({ json: {
                clientes: [{ id: 'cliente-1', codigo: 'C1', nombre: 'Cliente Uno' }], origenes: [],
                inventario: [{ tipo_envase: 'esponjas', propiedad: 'propia', fisico: empty ? 0 : 1322, reservado: 0, disponible: empty ? 0 : 1322 },
                    { tipo_envase: 'esponjas', propiedad: 'cliente', fisico: empty ? 0 : -68, reservado: 0, disponible: 0 }],
                disponibilidad: [{ tipo_envase: 'esponjas', propiedad: 'propia', fisico: empty ? 0 : 1322, reservado: 0, disponible: empty ? 0 : 1322 }],
                incidencias: empty ? [] : [{ tipo_envase: 'esponjas', propiedad: 'cliente', cliente_id: 'cliente-1', cantidad: 68,
                    mensaje: 'Esponja de bins · Del cliente · Cliente Uno: faltan 68 unidades trazables.' }],
            } });
        }
        if (path === '/api/envases/guias-despacho') {
            if (request.method() === 'POST') {
                submissions++;
                assert.equal(request.postDataJSON().detalles[0].propiedad, 'propia');
                return route.fulfill({ status: 201, json: { data: { numero: 'GDE-SANA' } } });
            }
            historyLoads++;
            return route.fulfill({ json: { data: [], resumen: { borradores: 3 } } });
        }
        return route.fulfill({ status: 404 });
    });
    try {
        await page.goto('http://localhost/test');
        const form = page.locator('#dispatchForm');
        const save = page.locator('#saveDraftButton');
        await page.waitForFunction(() => !document.querySelector('#saveDraftButton').disabled);
        assert.equal(await page.locator('#physicalCount').textContent(), '1254');
        assert.equal(await page.locator('#availableCount').textContent(), '1322');
        assert.match(await page.locator('#inventoryNotice').textContent(), /68 unidades/);
        await form.locator('[name="cliente_id"]').selectOption('cliente-1');
        await page.waitForFunction(() => !document.querySelector('[name="productor_csg_id"]').disabled);
        await form.locator('[name="propiedad_esponjas"]').selectOption('cliente');
        assert.match(await page.locator('[data-stock-status]').textContent(), /68 unidades/);
        await form.locator('[name="cantidad_esponjas"]').fill('1');
        await save.click();
        assert.match(await page.locator('#dispatchError').textContent(), /68 unidades/);
        assert.equal(submissions, 0);
        await form.locator('[name="propiedad_esponjas"]').selectOption('propia');
        assert.match(await page.locator('[data-stock-status]').textContent(), /Disponible: 1322/);
        await save.click();
        await page.waitForFunction(() => document.querySelector('#officeToasts').textContent.includes('GDE-SANA creada'));
        await page.waitForFunction(() => !document.querySelector('#saveDraftButton').disabled);
        assert.equal(submissions, 1);

        const previousLoads = historyLoads;
        failCatalog = true;
        await page.locator('#reloadButton').click();
        await page.waitForFunction(() => document.querySelector('#inventoryNotice').textContent.includes('Error de prueba de inventario'));
        assert.equal(await save.isDisabled(), true);
        assert.equal(await page.locator('#physicalCount').textContent(), '—');
        assert.equal(await page.locator('#availableCount').textContent(), '—');
        assert.equal(await page.locator('#draftCount').textContent(), '3');
        assert.ok(historyLoads > previousLoads);
        assert.match(await page.locator('#guideList').textContent(), /No existen guías/);
        await form.evaluate(element => element.dispatchEvent(new Event('submit', { cancelable: true })));
        assert.match(await page.locator('#dispatchError').textContent(), /no está verificada/);
        assert.equal(submissions, 1);

        failCatalog = false;
        empty = true;
        await page.locator('#reloadButton').click();
        await page.waitForFunction(() => !document.querySelector('#saveDraftButton').disabled);
        assert.equal(await page.locator('#physicalCount').textContent(), '0');
        assert.match(await page.locator('[data-stock-status]').textContent(), /Agotado/);
        assert.equal(await page.locator('#inventoryNotice').isVisible(), false);
        assert.deepEqual(errors, []);
    } finally {
        await browser.close();
    }
});

test('despacho de envases valida, completa CSG y filtra y pagina el historial en el servidor', async () => {
    const browser = await chromium.launch({
        executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined,
        args: ['--no-sandbox', '--disable-gpu'],
    });
    const page = await browser.newPage({ viewport: { width: 1366, height: 900 } });
    const pageErrors = [];
    const submissions = [];
    const corrections = [];
    const searches = [];
    const producer = { id: 'productor-1', codigo: '122104', nombre: 'Productor Uno' };
    const confirmed = {
        id: 'confirmada-1', numero: 'GDE-2610-0002', numero_guia_despacho: '2227', estado: 'confirmada',
        cliente: { id: 'cliente-1', nombre: 'Cliente Uno' }, temporada: { codigo: 'TEMP-TEST' },
        salida_at: '2026-10-21T12:00:00Z', version: 2, productor: null, puede_editar_productor: true,
        resumen: [{ tipo_envase: 'bins', cantidad: 6, propiedad: 'propia' }], detalles: [],
    };
    page.on('pageerror', error => pageErrors.push(error.message));
    await page.addInitScript(() => {
        localStorage.setItem('estiba_wms_office_token', 'token');
        localStorage.setItem('estiba_wms_office_identity', JSON.stringify({
            nombre: 'Operador Romana', rol: 'operador_romana',
            puede_consultar_cuenta_envases: true, puede_gestionar_despacho_envases: true,
        }));
    });
    await page.route('http://localhost/**', async route => {
        const request = route.request();
        const path = new URL(request.url()).pathname;
        if (path === '/test') return route.fulfill({ contentType: 'text/html', body: html });
        if (path.startsWith('/resources/js/')) {
            return route.fulfill({ contentType: 'text/javascript', body: read(path.slice(1)) });
        }
        if (path === '/api/envases/catalogo') {
            return route.fulfill({ json: { data: [{ codigo: 'bins', nombre: 'Bins plástico' }] } });
        }
        if (path === '/api/envases/guias-despacho/catalogos') {
            return route.fulfill({ json: {
                clientes: [{ id: 'cliente-1', codigo: 'C1', nombre: 'Cliente Uno' }, { id: 'cliente-2', codigo: 'C2', nombre: 'Cliente Dos' }],
                origenes: [], inventario: [{ fisico: 20, reservado: 0, disponible: 20 }],
            } });
        }
        if (path.startsWith('/api/envases/guias-despacho/productores/')) {
            return route.fulfill({ json: { data: path.endsWith('cliente-1') ? [producer] : [] } });
        }
        if (path === '/api/envases/guias-despacho/confirmada-1/productor' && request.method() === 'PUT') {
            corrections.push(request.postDataJSON());
            confirmed.productor = producer;
            return route.fulfill({ json: { data: confirmed } });
        }
        if (path === '/api/envases/guias-despacho' && request.method() === 'POST') {
            submissions.push(request.postDataJSON());
            if (submissions.length === 1) {
                return route.fulfill({ status: 422, json: {
                    message: plateError, errors: { cliente_id: [clientError], patente_camion: [plateError] },
                } });
            }
            if (submissions.length === 2) return route.fulfill({ status: 409, json: { message: stockError } });
            return route.fulfill({ status: 201, json: { data: { numero: 'GDE-2610-0001' } } });
        }
        if (path === '/api/envases/guias-despacho') {
            const params = Object.fromEntries(new URL(request.url()).searchParams);
            searches.push(params);
            const current = Number(params.page || 1);
            const size = Number(params.per_page || 25);
            const total = size + 1;
            const guide = current === 1 ? confirmed : { ...confirmed, id: 'confirmada-antigua', numero_guia_despacho: '1000' };
            return route.fulfill({ json: {
                data: [guide], resumen: { borradores: 8 },
                meta: { current_page: current, last_page: 2, per_page: size, total, from: (current - 1) * size + 1, to: current === 1 ? size : total },
            } });
        }
        return route.fulfill({ status: 404, body: '' });
    });
    try {
        await page.goto('http://localhost/test');
        const field = name => page.locator(`#dispatchForm [name="${name}"]`);
        await field('cantidad_bins').waitFor();
        assert.match(await page.locator('#dispatchPlateHelp').textContent(), /sin espacios ni guiones/);
        assert.equal(await field('patente_camion').getAttribute('aria-describedby'), 'dispatchPlateHelp');
        await field('cliente_id').selectOption('cliente-1');
        await field('productor_csg_id').selectOption('productor-1');
        await field('patente_camion').fill('AB-CD-12');
        await field('nombre_conductor').fill('Conductor de prueba');
        await field('observacion').fill('Entrega de envases para producción');
        await field('cantidad_bins').fill('6');
        await page.locator('#saveDraftButton').click();
        await page.waitForFunction(() => document.querySelector('#dispatchError').textContent.includes('Patente:'));
        assert.equal(await page.locator('#dispatchError').textContent(), `${clientError}\n${plateError}`);
        assert.equal(await page.locator('#dispatchError').getAttribute('role'), 'alert');
        assert.equal(await page.locator('#dispatchError').evaluate(element => getComputedStyle(element).whiteSpace), 'pre-line');
        assert.equal(await field('cliente_id').inputValue(), 'cliente-1');
        assert.equal(await field('productor_csg_id').inputValue(), 'productor-1');
        assert.equal(await field('patente_camion').inputValue(), 'AB-CD-12');
        assert.equal(await field('cantidad_bins').inputValue(), '6');
        assert.equal(await field('nombre_conductor').inputValue(), 'Conductor de prueba');
        assert.equal(await field('observacion').inputValue(), 'Entrega de envases para producción');

        await field('cliente_id').selectOption('cliente-2');
        await field('productor_csg_id').locator('option').filter({ hasText: 'Cliente sin productores asociados' }).waitFor({ state: 'attached' });
        await field('patente_camion').fill('abcd12');
        await page.locator('#saveDraftButton').click();
        await page.waitForFunction(() => document.querySelector('#dispatchError').textContent.includes('disponibilidad suficiente'));
        assert.equal(await page.locator('#dispatchError').textContent(), stockError);
        assert.equal(await field('cantidad_bins').inputValue(), '6');

        await field('cantidad_bins').fill('5');
        await page.locator('#saveDraftButton').click();
        await page.waitForFunction(() => document.querySelector('#officeToasts').textContent.includes('GDE-2610-0001 creada'));
        assert.equal(submissions.length, 3);
        assert.equal(submissions[2].patente_camion, 'ABCD12');
        assert.equal(submissions[2].cliente_id, 'cliente-2');
        assert.equal(submissions[0].productor_csg_id, 'productor-1');
        assert.equal(submissions[2].productor_csg_id, null);
        assert.equal(submissions[2].detalles[0].cantidad, 5);
        assert.equal(submissions[2].nombre_conductor, 'Conductor de prueba');
        assert.equal(submissions[2].observacion, 'Entrega de envases para producción');
        assert.equal(await page.locator('#dispatchError').textContent(), '');
        assert.equal(await field('cantidad_bins').inputValue(), '0');
        await page.getByRole('button', { name: 'Agregar CSG', exact: true }).click();
        const dialog = page.getByRole('dialog');
        await dialog.getByLabel('Productor (CSG)').selectOption('productor-1');
        await dialog.getByRole('button', { name: 'Guardar productor' }).click();
        assert.equal(await dialog.getByRole('alert').textContent(), 'Ingresa el motivo de la corrección.');
        await dialog.getByLabel('Motivo de la corrección').fill('Destinatario indicado en la guía.');
        await dialog.getByRole('button', { name: 'Guardar productor' }).click();
        await page.locator('#guideList').getByText('CSG 122104 · Productor Uno', { exact: true }).waitFor();
        assert.deepEqual(corrections, [{
            productor_csg_id: 'productor-1', motivo: 'Destinatario indicado en la guía.', version: 2,
        }]);
        const filters = page.locator('#guideFilters');
        await filters.getByLabel('Buscar guía, cliente, productor, CSG o patente').fill('2246');
        await filters.getByLabel('Cliente', { exact: true }).selectOption('cliente-1');
        await filters.getByLabel('Salida desde').fill('2026-11-01');
        await filters.getByLabel('Salida hasta').fill('2026-11-05');
        await filters.getByLabel('Productor (CSG)', { exact: true }).selectOption('con');
        await filters.getByLabel('Estado', { exact: true }).selectOption('confirmada');
        await filters.getByRole('button', { name: 'Buscar', exact: true }).click();
        await page.waitForFunction(() => document.querySelector('#guideList').getAttribute('aria-busy') === 'false');
        assert.deepEqual(searches.at(-1), {
            buscar: '2246', cliente_id: 'cliente-1', estado: 'confirmada', desde: '2026-11-01', hasta: '2026-11-05', csg: 'con', page: '1', per_page: '25',
        });
        assert.equal(await page.locator('#draftCount').textContent(), '8');
        assert.equal(await page.locator('#guideCount').textContent(), '26 guías');
        await page.getByRole('button', { name: 'Siguiente', exact: true }).click();
        await page.locator('#guideList').getByText('Guía N° 1000', { exact: true }).waitFor();
        assert.equal(searches.at(-1).page, '2');
        assert.equal(searches.at(-1).buscar, '2246');
        assert.match(await page.locator('#guidePageInfo').textContent(), /26–26 de 26.*Página 2 de 2/);
        assert.equal(await page.getByRole('button', { name: 'Siguiente', exact: true }).isDisabled(), true);
        await page.getByLabel('Guías por página').selectOption('50');
        await page.locator('#guideList').getByText('Guía N° 2227', { exact: true }).waitFor();
        assert.equal(searches.at(-1).page, '1');
        assert.equal(searches.at(-1).per_page, '50');
        assert.equal(searches.at(-1).csg, 'con');
        await page.getByRole('button', { name: 'Limpiar filtros', exact: true }).click();
        await page.waitForFunction(() => document.querySelector('#guideList').getAttribute('aria-busy') === 'false');
        assert.deepEqual(searches.at(-1), { page: '1', per_page: '50' });
        assert.equal(await filters.getByLabel('Salida hasta').getAttribute('min'), '');
        await page.setViewportSize({ width: 390, height: 844 });
        if (process.env.DISPATCH_FILTER_SCREENSHOT) {
            await page.locator('section[aria-labelledby="guideHistoryTitle"]').screenshot({ path: process.env.DISPATCH_FILTER_SCREENSHOT });
        }
        const overflow = await page.evaluate(() => [...document.querySelectorAll('body *')]
            .filter(element => { const rect = element.getBoundingClientRect(); return rect.width && rect.right > window.innerWidth + 1; })
            .map(element => ({ tag: element.tagName, id: element.id, className: element.className, right: element.getBoundingClientRect().right })));
        assert.deepEqual(overflow, []);
        assert.deepEqual(pageErrors, []);
    } finally {
        await browser.close();
    }
});
