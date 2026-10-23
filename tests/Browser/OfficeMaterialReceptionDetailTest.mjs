import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
const html = readFileSync(resolve(root, 'storage/app/ui/oficina/materiales.html'), 'utf8').replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, '');
const image = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aDEsAAAAASUVORK5CYII=', 'base64');
const photo = { id: 'document-1', tipo: 'documento', orden: 1, miniatura_url: '/thumb', url: '/original' };
function reception(id, fotos = []) {
    return {
        id, numero_guia_despacho: 'GUIA-' + id, estado: 'borrador', version: 5, fecha_documento: '2026-10-13',
        cliente: { id: 'client', codigo: 'CLI', nombre: 'Cliente' }, proveedor: { id: 'provider', codigo: 'PRO', nombre: 'Proveedor' },
        fotos, fotos_documento: fotos.length, detalles_count: 1,
        detalles: [{ item: { id: 'item', codigo: 'ENV-1', nombre: 'Envase' }, unidad_medida: 'unidades', cantidad_aceptada: 15, bultos: [{ cantidad: 10 }, { cantidad: 5 }] }],
    };
}
async function fixture(records, options = {}) {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
    const context = await browser.newContext({ viewport: { width: options.width || 1440, height: 1000 } }); const page = await context.newPage();
    const calls = []; const errors = []; page.on('pageerror', (error) => errors.push(error.message));
    await context.addInitScript(identity => {
        localStorage.setItem('estiba_wms_office_token', 'test-token');
        localStorage.setItem('estiba_wms_office_identity', JSON.stringify({ puede_gestionar_recepciones_materiales: true, puede_administrar_recepciones_materiales: true, ...identity }));
    }, options.identity || {});
    await context.route('http://localhost/**', async (route) => {
        const request = route.request(); const url = new URL(request.url());
        if (url.pathname === '/test') return route.fulfill({ body: html, contentType: 'text/html' });
        if (url.pathname === '/oficina/materiales/recepcion') return route.fulfill({ contentType: 'text/html', body: html.replace('</body>', `<script type="module">
            document.querySelector('[data-office-shell]')?.remove();
            const app = document.getElementById('officeApp'); app.classList.remove('is-hidden'); app.dataset.materialsSection = 'recepcion';
            document.querySelectorAll('[data-materials-view]').forEach(node => node.classList.toggle('is-hidden', node.dataset.materialsView !== 'recepcion'));
            await import('/office-material-labels.js');
        </script></body>`) });
        if (url.pathname.startsWith('/build/')) return route.fulfill({ body: readFileSync(resolve(root, 'public' + url.pathname)), contentType: url.pathname.endsWith('.css') ? 'text/css' : 'application/octet-stream' });
        if (['/office-material-receptions.js', '/office-material-reception-registration.js', '/office-material-reception-photos.js', '/office-material-labels.js', '/office-material-reception-transport.js'].includes(url.pathname)) return route.fulfill({ body: readFileSync(resolve(root, 'resources/js' + url.pathname)), contentType: 'text/javascript' });
        if (url.pathname === '/office-material-reception-import.js') return route.fulfill({ body: '', contentType: 'text/javascript' });
        if (url.pathname === '/thumb' || url.pathname === '/original') return route.fulfill({ body: image, contentType: 'image/png' });
        calls.push({ path: url.pathname, method: request.method(), body: request.postData() });
        if (options.route && await options.route(route, url, request)) return;
        if (url.pathname === '/api/materiales/recepciones/catalogos') return route.fulfill({ json: { clientes: [], proveedores: [], items: [] } });
        if (url.pathname === '/api/materiales/recepciones') return route.fulfill({ json: { data: [...records.values()], meta: { current_page: 1, last_page: 1, total: records.size } } });
        const id = url.pathname.match(/^\/api\/materiales\/recepciones\/([^/]+)$/)?.[1];
        if (id && records.has(id)) return route.fulfill({ json: { data: records.get(id) } });
        return route.fulfill({ status: 404 });
    });
    await page.goto('http://localhost/test');
    await page.evaluate(async () => {
        // Esta fixture monta solo Recepciones; la navegación tiene su propia suite y arranque.
        document.querySelector('[data-office-shell]')?.remove();
        const app = document.getElementById('officeApp'); app.classList.remove('is-hidden'); app.dataset.materialsSection = 'recepciones';
        document.querySelectorAll('[data-materials-view]').forEach((node) => node.classList.toggle('is-hidden', node.dataset.materialsView !== 'recepciones'));
        await import('/office-material-receptions.js');
    });
    await page.waitForFunction(() => document.querySelector('[data-action="select"]'));
    return { browser, page, calls, errors };
}

test('seleccionar pide el expediente y bloquea la confirmación sin documento', async () => {
    const { browser, page, calls, errors } = await fixture(new Map([['A', reception('A')]]));
    try {
        await page.locator('[data-action="select"]').click();
        await page.waitForFunction(() => document.getElementById('materialReceptionDetailTitle').textContent === 'Guía GUIA-A');
        assert.equal(await page.locator('[data-action="select"]').getAttribute('aria-pressed'), 'true');
        assert.equal(await page.locator('#materialReceptionDetailPhotos [data-confirm]').isDisabled(), true);
        assert.equal(await page.locator('#materialReceptionNoPhotoCount').textContent(), '1');
        assert.match(await page.locator('#materialReceptionDetailLines').textContent(), /15 unidades/);
        assert.match(await page.locator('#materialReceptionDetailLines').textContent(), /1 de 10 y 1 de 5/);
        assert.ok(calls.some((call) => call.path === '/api/materiales/recepciones/A' && call.method === 'GET'));
        assert.deepEqual(errors, []);
    } finally { await browser.close(); }
});

test('editar una recepción usa las cantidades originales por bulto y permite elegir la equivalencia vigente', async () => {
    for (const refresh of [false, true]) {
        const record = reception('A');
        record.detalles[0] = { ...record.detalles[0], id: 'detail', cantidad_documental: 200, cantidad_contada: 200, cantidad_aceptada: 200, cantidad_rechazada: 0,
            conversion_unidad: { unidad_origen: 'cajas', unidad_base: 'unidades', factor: '100.000000', version: 1, entrada: { cantidad_documental: 2, cantidad_contada: 2, cantidad_aceptada: 2, cantidad_rechazada: 0 }, bultos_entrada: [1.5, 0.5], bultos_entrada_por_id: { grande: 1.5, chico: 0.5 } },
            bultos: [{ id: 'chico', cantidad: 50 }, { id: 'grande', cantidad: 150 }] };
        const records = new Map([['A', record]]); let sent;
        const { browser, page, errors } = await fixture(records, { route: async (route, url, request) => {
            if (url.pathname === '/api/materiales/recepciones/catalogos') {
                await route.fulfill({ json: { clientes: [record.cliente], proveedores: [{ ...record.proveedor, cliente_ids: ['client'], categorias: [] }], items: [{ ...record.detalles[0].item, unidad_medida: 'unidades', conversiones_unidad: [{ unidad_origen: 'cajas', factor: '120.000000', version: 2 }] }] } }); return true;
            }
            if (url.pathname === '/api/materiales/recepciones/A/administrar') {
                sent = request.postDataJSON(); await route.fulfill({ json: { data: record } }); return true;
            }
            return false;
        } });
        try {
            await page.locator('[data-action="select"]').click();
            await page.locator('#materialReceptionDetailActions [data-action="edit"]').click();
            await page.locator('#materialReceptionDialog [name="unidad_ingreso"]').waitFor();
            assert.equal(await page.locator('#materialReceptionDialog [name="cantidad_aceptada"]').inputValue(), '2');
            assert.equal(await page.locator('#materialReceptionDialog [name="tamano_bulto"]').inputValue(), '1.5');
            if (refresh) await page.locator('[data-refresh-line-conversion]').click();
            assert.match(await page.locator('.material-reception-conversion-hint').textContent(), refresh ? /120/ : /100/);
            await page.locator('#materialReceptionForm [name="motivo_correccion"]').fill('Aclaración de antecedentes');
            const saved = page.waitForResponse((response) => response.url().endsWith('/A/administrar'));
            await page.locator('#saveMaterialReceptionDraft').click();
            await saved;
            assert.equal(sent.detalles[0].detalle_origen_id, 'detail');
            assert.equal(sent.detalles[0].conversion_version, refresh ? 2 : 1);
            assert.equal(sent.detalles[0].cantidad_aceptada, 2);
            assert.deepEqual(sent.detalles[0].bultos.map((b) => b.cantidad), [0.5, 1.5]);
            assert.deepEqual(errors, []);
        } finally { await browser.close(); }
    }
});

test('la respuesta demorada de A no reemplaza el expediente seleccionado de B', async () => {
    const records = new Map([['A', reception('A')], ['B', reception('B')]]);
    let releaseA; const deferred = new Promise((resolve) => { releaseA = resolve; });
    const { browser, page, errors } = await fixture(records, { route: async (route, url) => {
        if (url.pathname !== '/api/materiales/recepciones/A') return false;
        await deferred; await route.fulfill({ json: { data: records.get('A') } }); return true;
    } });
    try {
        await page.locator('[data-action="select"][data-id="A"]').click();
        await page.locator('[data-action="select"][data-id="B"]').click();
        await page.waitForFunction(() => document.getElementById('materialReceptionDetailTitle').textContent === 'Guía GUIA-B');
        const received = page.waitForResponse((response) => response.url().endsWith('/api/materiales/recepciones/A'));
        releaseA(); await received;
        await page.evaluate(() => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve))));
        assert.equal(await page.locator('#materialReceptionDetailTitle').textContent(), 'Guía GUIA-B');
        assert.equal(await page.locator('[data-action="select"][data-id="B"]').getAttribute('aria-pressed'), 'true');
        assert.deepEqual(errors, []);
    } finally { releaseA(); await browser.close(); }
});

test('expediente y diálogo reintentan la misma confirmación con el mismo identificador y versión', async () => {
    const records = new Map([['A', reception('A', [photo])]]); const confirmations = [];
    const { browser, page, errors } = await fixture(records, { route: async (route, url, request) => {
        if (url.pathname !== '/api/materiales/recepciones/A/confirmar') return false;
        confirmations.push(JSON.parse(request.postData()));
        if (confirmations.length === 1) await route.fulfill({ status: 503, json: { message: 'Reintenta la confirmación.' } });
        else { records.set('A', { ...records.get('A'), estado: 'confirmada', version: 6, snapshot_confirmacion: { folios: [{ numero_folio: 'FCL0000001' }] } }); await route.fulfill({ json: { data: records.get('A') } }); }
        return true;
    } });
    try {
        await page.locator('[data-action="select"]').click();
        await page.locator('#materialReceptionDetailPhotos [data-confirm]').click();
        await page.waitForFunction(() => document.querySelector('#materialReceptionDetailPhotos [data-photo-error]')?.textContent.includes('Reintenta'));
        await page.locator('#materialReceptionDetailActions [data-action="view"]').click();
        await page.locator('#materialReceptionPhotos [data-confirm]').click();
        await page.waitForFunction(() => document.getElementById('materialReceptionDetailState').textContent === 'Recepción confirmada');
        assert.equal(confirmations.length, 2); assert.equal(confirmations[0].operacion_id, confirmations[1].operacion_id);
        assert.equal(confirmations[0].version_conocida, 5); assert.equal(confirmations[1].version_conocida, 5);
        assert.match(confirmations[0].operacion_id, /^[0-9a-f-]{36}$/);
        assert.equal(await page.locator('#materialReceptionDetailPhotos [data-confirm]').count(), 0);
        assert.equal(await page.locator('#materialReceptionPhotos [data-confirm]').count(), 0);
        assert.deepEqual(errors, []);
    } finally { await browser.close(); }
});

test('subir y borrar un documento refresca lista y expediente con el registro vigente', async () => {
    const records = new Map([['A', reception('A')]]);
    const { browser, page, calls, errors } = await fixture(records, { route: async (route, url, request) => {
        if (url.pathname === '/api/materiales/recepciones/A/fotos' && request.method() === 'POST') {
            records.set('A', { ...records.get('A'), version: 8, fotos: [photo], fotos_documento: 1 });
            await route.fulfill({ status: 201, json: { data: photo } }); return true;
        }
        if (url.pathname === '/api/materiales/recepciones/A/fotos/document-1' && request.method() === 'DELETE') {
            records.set('A', { ...records.get('A'), fotos: [], fotos_documento: 0 }); await route.fulfill({ status: 204 }); return true;
        }
        return false;
    } });
    try {
        await page.locator('[data-action="select"]').click();
        await page.locator('#materialReceptionDetailPhotos [data-upload="documento"]').setInputFiles({ name: 'guia.png', mimeType: 'image/png', buffer: image });
        await page.waitForFunction(() => document.getElementById('materialReceptionNoPhotoCount').textContent === '0');
        await page.waitForFunction(() => document.querySelector('#materialReceptionDetailPhotos [data-delete]'));
        assert.equal(await page.locator('#materialReceptionDetailPhotos [data-confirm]').isEnabled(), true);
        await page.locator('#materialReceptionDetailPhotos [data-delete]').click();
        await page.waitForFunction(() => document.getElementById('materialReceptionNoPhotoCount').textContent === '1');
        await page.waitForFunction(() => document.querySelector('#materialReceptionDetailPhotos [data-confirm]')?.disabled);
        assert.ok(calls.filter((call) => call.path === '/api/materiales/recepciones/A').length >= 3);
        assert.deepEqual(errors, []);
    } finally { await browser.close(); }
});

test('confirmar ofrece imprimir, precarga fuera de la lista y reintenta la selección sin duplicar la generación', async () => {
    for (const width of [1440, 390]) {
        const records = new Map([['A', reception('A', [photo])]]);
        const requests = []; const history = [];
        const { browser, page, calls, errors } = await fixture(records, {
            width, identity: { puede_imprimir_etiquetas_materiales: true },
            route: async (route, url, request) => {
                if (url.pathname === '/api/materiales/recepciones' && url.searchParams.get('estado') === 'confirmada') {
                    await route.fulfill({ json: { data: [] } }); return true;
                }
                if (url.pathname.endsWith('/confirmar')) {
                    const record = records.get('A');
                    records.set('A', { ...record, estado: 'confirmada', version: 6, confirmado_at: '2026-10-23T09:00:00-03:00',
                        detalles: [{ ...record.detalles[0], bultos: [1, 2, 3].map(n => ({ cantidad: 5, folio: { id: `folio-${n}`, numero_folio: `FCL000000${n}` } })) }],
                    });
                    await route.fulfill({ json: { data: records.get('A') } }); return true;
                }
                if (url.pathname.endsWith('/perfiles-impresion')) {
                    await route.fulfill({ json: { data: [{ id: 'profile', nombre: 'Bodega', fabricante: 'Zebra', ancho_mm: 100, alto_mm: 60, predeterminado: true }] } }); return true;
                }
                if (url.pathname.endsWith('/impresiones')) {
                    await route.fulfill({ json: { data: history } }); return true;
                }
                if (url.pathname.endsWith('/etiquetas')) {
                    const data = request.postDataJSON(); requests.push(data);
                    if (requests.length === 1) await route.fulfill({ status: 503, json: { message: 'Vuelve a descargar la misma selección.' } });
                    else if (history.length && !data.motivo_reimpresion) await route.fulfill({ status: 422, json: { message: 'Debes indicar el motivo para volver a generar una etiqueta.' } });
                    else {
                        history.unshift({ id: `job-${history.length + 1}`, formato: data.formato, simbologia: data.simbologia, copias: data.copias,
                            folios: data.folio_ids.map(id => ({ id })), solicitado_at: '2026-10-23T09:00:00-03:00', solicitado_por: 'Operadora', perfil: { nombre: 'Bodega' },
                            estado: 'generado', motivo_reimpresion: data.motivo_reimpresion });
                        await route.fulfill({ body: '%PDF-1.4', contentType: 'application/pdf', headers: { 'Content-Disposition': 'attachment; filename="folios.pdf"' } });
                    }
                    return true;
                }
                return false;
            },
        });
        try {
            await page.locator('[data-action="select"]').click();
            assert.equal(await page.locator('#materialReceptionDetailActions a').count(), 0);
            await page.locator('#materialReceptionDetailPhotos [data-confirm]').click();
            const notice = page.locator('#materialReceptionPrintNotice');
            await notice.waitFor({ state: 'visible' });
            assert.match(await notice.textContent(), /Guía GUIA-A confirmada/);
            assert.equal(await page.locator('#materialReceptionDetailActions a', { hasText: 'Imprimir folios' }).getAttribute('href'), '/oficina/materiales/recepcion?origen=recepcion&id=A');
            await page.locator('#materialReceptionDetailActions [data-action="view"]').click();
            await page.locator('#printMaterialReceptionFromForm').waitFor({ state: 'visible' });
            assert.equal(await page.locator('#printMaterialReceptionFromForm').getAttribute('href'), '/oficina/materiales/recepcion?origen=recepcion&id=A');
            await page.locator('#closeMaterialReceptionDialog').click();
            await page.locator('#materialReceptionPrintLink').click();
            await page.waitForFunction(() => document.querySelectorAll('#materialLabelFolios input').length === 3);
            assert.equal(await page.locator('#materialLabelReception').inputValue(), 'A');
            assert.equal(await page.locator('#materialLabelSource option[value="transformacion"]').evaluate(option => option.disabled), true);
            await page.selectOption('#materialLabelForm [name="formato"]', 'pdf');
            await page.locator('#materialLabelForm [name="copias"]').fill('2');
            await page.locator('#materialLabelFolios input[value="folio-1"]').check();
            await page.locator('#materialLabelFolios input[value="folio-3"]').check();
            await page.locator('#materialLabelForm button[type="submit"]').click();
            await page.waitForFunction(() => document.getElementById('materialLabelError').textContent.includes('misma selección'));
            const download = page.waitForEvent('download');
            await page.locator('#materialLabelForm button[type="submit"]').click();
            assert.equal((await download).suggestedFilename(), 'folios.pdf');
            await page.waitForFunction(() => document.getElementById('materialLabelHistory').textContent.includes('2 folios × 2 copias'));
            assert.deepEqual(requests[0].folio_ids, ['folio-1', 'folio-3']);
            assert.equal(requests[0].operacion_id, requests[1].operacion_id);
            assert.equal(history.length, 1);
            await page.locator('#materialLabelFolios input[value="folio-3"]').check();
            await page.locator('#materialLabelForm button[type="submit"]').click();
            await page.waitForFunction(() => document.getElementById('materialLabelError').textContent.includes('motivo'));
            assert.equal(history.length, 1);
            await page.locator('#materialLabelForm [name="motivo_reimpresion"]').fill('Etiqueta dañada al pegarla');
            const reprint = page.waitForEvent('download');
            await page.locator('#materialLabelForm button[type="submit"]').click(); await reprint;
            await page.waitForFunction(() => document.getElementById('materialLabelHistory').textContent.includes('Etiqueta dañada al pegarla'));
            assert.equal(history.length, 2);
            assert.deepEqual(requests.at(-1).folio_ids, ['folio-3']);
            assert.notEqual(requests[1].operacion_id, requests.at(-1).operacion_id);
            assert.ok(!calls.some(call => call.path.includes('transformaciones')));
            assert.equal(calls.filter(call => call.path.endsWith('/confirmar')).length, 1);
            assert.deepEqual(errors, []);
        } finally { await browser.close(); }
    }
});

test('una provisional requiere autorización con motivo y reintenta sin generar otra operación', async () => {
    const record = reception('A'); record.registro = { provisional: true, retroactivo: true, requiere_autorizacion: true, estado: 'por_autorizar', fecha_recepcion: '2026-10-20T13:30:00Z', fecha_limite: new Date(Date.now() + 86400000).toISOString(), motivo: 'Guía pendiente' };
    record.numero_guia_despacho = null;
    const records = new Map([['A', record]]); const confirmations = [];
    const { browser, page, errors } = await fixture(records, { width: 390, identity: { puede_confirmar_recepciones_materiales: true, puede_autorizar_recepciones_tardias_materiales: true }, route: async (route, url, request) => {
        if (!url.pathname.endsWith('/confirmar')) return false;
        confirmations.push(request.postDataJSON());
        if (confirmations.length === 1) await route.fulfill({ status: 503, json: { message: 'Reintenta la autorización' } });
        else { records.set('A', { ...record, estado: 'confirmada', version: 6, registro: { ...record.registro, estado: 'pendiente' } }); await route.fulfill({ json: { data: records.get('A') } }); }
        return true;
    } });
    try {
        await page.locator('[data-action="select"]').click();
        await page.locator('#materialReceptionDetailPhotos [data-confirm]').click();
        await page.locator('#materialReceptionAuthorizationForm [name="motivo_autorizacion"]').fill('Conteo supervisado; guía pendiente');
        await page.screenshot({ path: resolve(root, 'storage/app/ui/oficina/pr8-provisional-autorizacion-pda.png'), fullPage: true });
        await page.locator('#materialReceptionAuthorizationForm [type="submit"]').click();
        await page.waitForFunction(() => document.querySelector('[data-photo-error]')?.textContent.includes('Reintenta'));
        await page.locator('#materialReceptionDetailPhotos [data-confirm]').click();
        await page.waitForFunction(() => document.querySelector('#materialReceptionDetailState')?.textContent === 'Recepción confirmada');
        assert.equal(confirmations.length, 2); assert.deepEqual(confirmations[0], confirmations[1]);
        assert.equal(confirmations[0].motivo_autorizacion, 'Conteo supervisado; guía pendiente');
        assert.deepEqual(errors, []);
    } finally { await browser.close(); }
});

test('regularizar en pantalla móvil conserva el UUID tras perder la respuesta y deja evidencia visible', async () => {
    const record = reception('A', [photo]); record.estado = 'confirmada'; record.numero_guia_despacho = null;
    record.detalles[0].id = 'detail-A'; record.detalles[0].cantidad_documental = null;
    record.registro = { provisional: true, requiere_autorizacion: true, estado: 'pendiente', fecha_recepcion: '2026-10-20T13:30:00Z', fecha_limite: new Date(Date.now() + 86400000).toISOString() };
    const records = new Map([['A', record]]); const requests = [];
    const { browser, page, errors } = await fixture(records, { width: 390, identity: { puede_autorizar_recepciones_tardias_materiales: true }, route: async (route, url, request) => {
        if (!url.pathname.endsWith('/regularizar')) return false;
        requests.push(request.postDataJSON());
        if (requests.length === 1) await route.fulfill({ status: 503, json: { message: 'Reintenta la regularización' } });
        else { records.set('A', { ...record, numero_guia_despacho: 'GD-891', version: 6, registro: { ...record.registro, estado: 'regularizada', regularizado_at: new Date().toISOString(), regularizado_por: { name: 'Jefa bodega' }, motivo_regularizacion: 'Guía recibida y verificada' } }); await route.fulfill({ json: { data: records.get('A') } }); }
        return true;
    } });
    try {
        await page.locator('[data-action="select"]').click();
        await page.locator('[data-action="regularize"]').click();
        const form = page.locator('#materialReceptionRegularizationForm');
        await form.locator('[name="numero_guia_despacho"]').fill('GD-891');
        await form.locator('[name="fecha_documento"]').fill('2026-10-20');
        await form.locator('[data-documentary-detail]').fill('15');
        await form.locator('[name="motivo"]').fill('Guía recibida y verificada');
        await form.locator('[type="submit"]').click();
        await page.waitForFunction(() => document.querySelector('#materialReceptionRegularizationError')?.textContent.includes('Reintenta'));
        await form.locator('[type="submit"]').click();
        await page.waitForFunction(() => document.querySelector('#materialReceptionDetailLines')?.textContent.includes('Jefa bodega'));
        await page.screenshot({ path: resolve(root, 'storage/app/ui/oficina/pr8-provisional-regularizada-pda.png'), fullPage: true });
        assert.deepEqual(requests[0], requests[1]); assert.equal(requests.length, 2);
        assert.deepEqual(errors, []);
    } finally { await browser.close(); }
});
