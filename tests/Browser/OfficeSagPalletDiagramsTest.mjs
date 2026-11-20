import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve, extname } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
const html = readFileSync(resolve(root, 'storage/app/ui/oficina/sag-diagramas.html'), 'utf8');
const catalogo = { temporada: { id: 't', codigo: 'TEMP-2627', nombre: 'Temporada 2026-2027' }, fecha_planta: '2026-11-20', csp: '3176555', especies: ['Cereza'], exportadoras: ['EXPORTADORA DEMO'], puede_gestionar: true };

async function abrir({ puedeGestionar = true } = {}) {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, acceptDownloads: true });
    await context.addInitScript(() => {
        // También corre en el marco de impresión (un PDF en blob:), que no tiene localStorage.
        if (window.top !== window) return;
        localStorage.setItem('estiba_wms_office_token', 'fixture');
        localStorage.setItem('estiba_wms_office_identity', JSON.stringify({ nombre: 'Contraparte SAG', rol: 'supervisor_frio', puede_consultar_inspeccion_sag: true }));
    });
    const writes = [], pdfs = [], errors = [];
    let registro = null, conflicto = false;
    await context.route('http://localhost/**', async (route) => {
        const request = route.request(), path = new URL(request.url()).pathname;
        if (path === '/oficina/frigorifico/diagramas-sag') return route.fulfill({ body: html, contentType: 'text/html' });
        if (path.startsWith('/build/') || path.startsWith('/brand/')) {
            try { return route.fulfill({ body: readFileSync(resolve(root, 'public' + path)), contentType: ({ '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml', '.woff2': 'font/woff2' })[extname(path)] || 'application/octet-stream' }); }
            catch { return route.fulfill({ status: 404 }); }
        }
        if (path === '/api/sag/diagramas/catalogo') return route.fulfill({ json: { ...catalogo, puede_gestionar: puedeGestionar } });
        if (path === '/api/sag/diagramas/pdf') { pdfs.push(request.postDataJSON()); return route.fulfill({ body: Buffer.from('%PDF-1.4\n%%EOF'), contentType: 'application/pdf' }); }
        if (path.startsWith('/api/sag/diagramas') && ['POST', 'PUT'].includes(request.method())) {
            const data = request.postDataJSON(); writes.push({ method: request.method(), path, data });
            if (conflicto) return route.fulfill({ status: 409, json: { message: 'Otra persona guardó este diagrama. Vuelve a abrirlo antes de guardar.' } });
            registro = { ...data, id: 'diagrama-1', numero: data.numero.toUpperCase(), version: (registro?.version || 0) + 1, total_cajas: 0 };
            return route.fulfill({ json: { registro, historial: [{ version: registro.version, usuario: 'Contraparte SAG', fecha: '2026-11-20 11:00:00' }] } });
        }
        if (path === '/api/sag/diagramas') return route.fulfill({ json: { data: registro ? [{ ...registro, fecha: registro.fecha, total_pallets: registro.pallets.length, total_marcadas: registro.cajas_marcadas.length, updated_at: '2026-11-20T11:00:00Z', actualizado_por: 'Contraparte SAG' }] : [], last_page: 1 } });
        if (path === '/api/sag/diagramas/diagrama-1') return route.fulfill({ json: { registro, historial: [] } });
        return route.fulfill({ json: { data: [] } });
    });
    const page = await context.newPage();
    page.on('pageerror', (e) => errors.push(e.message));
    await page.goto('http://localhost/oficina/frigorifico/diagramas-sag');
    await page.locator('#pdPallets tr').first().waitFor();

    return { browser, page, writes, pdfs, errors, setConflicto: (v) => { conflicto = v; } };
}

const rango = (page, i) => page.locator(`#pdPallets tr[data-index="${i}"] [data-cell="rango"]`).textContent();
const campo = (page, i, field) => page.locator(`#pdPallets tr[data-index="${i}"] [data-field="${field}"]`);

test('arma 16 pallets con numeración continua, marca por número y por clic, y advierte cambios de estructura', async () => {
    const { browser, page, errors } = await abrir();
    try {
        assert.equal(await page.locator('#pdPallets tr').count(), 16);
        assert.equal(await rango(page, 0), 'Sin configurar');
        assert.equal(await page.locator('#pdForm [name=csp]').inputValue(), '3176555');
        assert.equal(await page.locator('#pdForm [name=fecha]').inputValue(), '2026-11-20');

        await page.locator('#pdQuick [name=base]').fill('8');
        await page.locator('#pdQuick [name=corridas]').fill('13');
        await page.locator('[data-apply="todos"]').click();
        assert.match(await page.locator('#pdTotals').textContent(), /16 pallets · 1\.664 cajas · numeración 1–1\.664/);
        assert.equal(await page.locator('.pd-box').count(), 1664);

        // Pallets 2 y 3 de 5 × 10, como el Excel: los siguientes se recalculan sin saltos.
        for (const i of [1, 2]) { await campo(page, i, 'base').fill('5'); await campo(page, i, 'corridas').fill('10'); }
        assert.deepEqual(await Promise.all([0, 1, 2, 3, 5].map((i) => rango(page, i))), ['1–104', '105–154', '155–204', '205–308', '413–516']);

        await page.locator('#pdMarkInput').fill('5, 82, 145, 206 y 411, 99999, abc');
        await page.locator('#pdMarkForm button.primary-button').click();
        const resultado = await page.locator('#pdMarkResult').textContent();
        assert.match(resultado, /5 cajas marcadas: 5 \(pallet 1\), 82 \(pallet 1\), 145 \(pallet 2\), 206 \(pallet 4\), 411 \(pallet 5\)/);
        assert.match(resultado, /No existen en el diagrama: 99999 \(la numeración va de 1 a 1556\)/);
        assert.match(resultado, /No se reconocieron: abc/);
        assert.deepEqual(await page.locator('.pd-diagram [aria-pressed="true"]').evaluateAll((b) => b.map((x) => x.dataset.box)), ['82', '5', '145', '206', '411']);
        assert.match(await page.locator('#pdSummary').textContent(), /145 \(c9 p1\)/);

        await page.locator('.pd-diagram [data-box="6"]').click();
        assert.equal(await page.locator('.pd-diagram [data-box="6"]').getAttribute('aria-pressed'), 'true');
        await page.locator('.pd-diagram [data-box="6"]').click();
        assert.equal(await page.locator('.pd-diagram [data-box="6"]').getAttribute('aria-pressed'), 'false');

        // Corrida superior incompleta: la fila de arriba del pallet 1 tiene solo 5 cajas.
        await campo(page, 0, 'ultima').fill('5');
        assert.equal(await rango(page, 0), '1–101');
        assert.deepEqual(await page.locator('[data-pallet="0"] .pd-pallet__row').first().locator('.pd-box').evaluateAll((b) => b.map((x) => x.textContent)), ['97', '98', '99', '100', '101']);
        assert.match(await page.locator('#pdWarningText').textContent(), /su posición en el diagrama puede haber cambiado/);
        await page.locator('#pdWarningOk').click();
        assert.equal(await page.locator('#pdWarning').isHidden(), true);

        // Una marca que queda fuera del total se advierte y se puede quitar.
        await page.locator('#pdMarkInput').fill('1553');
        await page.locator('#pdMarkForm button.primary-button').click();
        await campo(page, 15, 'corridas').fill('12');
        assert.match(await page.locator('#pdWarningText').textContent(), /La caja marcada 1553 ya no existe/);
        await page.locator('#pdDropMissing').click();
        assert.match(await page.locator('#pdMarkResult').textContent(), /cajas inexistentes: 1553/);

        await campo(page, 3, 'declaradas').fill('100');
        assert.match(await rango(page, 3), /declaradas \(100\) no coinciden con la configuración \(104\)/);
        await campo(page, 3, 'declaradas').fill('104');

        await page.locator('#pdPallets [data-zoom="1"]').click();
        assert.equal(await page.locator('#pdZoom').evaluate((d) => d.open), true);
        assert.equal(await page.locator('#pdZoomTitle').textContent(), 'Pallet 2 · cajas 102–151');
        await page.locator('#pdZoomBody [data-box="102"]').click();
        await page.locator('#pdZoomNext').click();
        assert.equal(await page.locator('#pdZoomTitle').textContent(), 'Pallet 3 · cajas 152–201');
        await page.locator('#pdZoomClose').click();
        assert.equal(await page.locator('.pd-diagram [data-box="102"]').getAttribute('aria-pressed'), 'true');

        await page.locator('#pdPallets [data-remove="15"]').click();
        assert.equal(await page.locator('#pdPallets tr').count(), 15);
        await page.locator('#pdAddPallet').click();
        assert.equal(await rango(page, 15), 'Sin configurar');

        await page.locator('#pdShowNumbers').uncheck();
        assert.equal(await page.locator('#pdDiagram').evaluate((d) => d.classList.contains('is-plain')), true);
        assert.deepEqual(errors, []);
    } finally { await browser.close(); }
});

test('guarda con un número, reintenta con la misma operación y descarga PDF, PNG e impresión con el papel elegido', async () => {
    const { browser, page, writes, pdfs, errors, setConflicto } = await abrir();
    try {
        await page.locator('#pdQuick [name=base]').fill('8');
        await page.locator('#pdQuick [name=corridas]').fill('13');
        await page.locator('[data-apply="todos"]').click();
        await page.locator('#pdMarkInput').fill('5 82');
        await page.locator('#pdMarkForm button.primary-button').click();

        await page.locator('#pdSave').click();
        assert.match(await page.locator('#pdMessage').textContent(), /Indica el N\.º del diagrama/);
        await page.locator('#pdForm [name=numero]').fill('8');
        assert.equal(await page.locator('#pdTitle').textContent(), 'DIAGRAMA USDA N.º 8');

        setConflicto(true);
        await page.locator('#pdSave').click();
        await page.locator('#pdMessage').filter({ hasText: 'Otra persona guardó' }).waitFor();
        setConflicto(false);
        await page.locator('#pdSave').click();
        await page.locator('#pdVersion').filter({ hasText: 'Guardado · versión 1' }).waitFor();
        assert.equal(writes.length, 2);
        assert.equal(writes[0].data.operacion_id, writes[1].data.operacion_id);
        assert.deepEqual(writes[1].data.cajas_marcadas, [5, 82]);
        assert.deepEqual(writes[1].data.pallets[0], { folio: null, base: 8, corridas: 13, ultima: null, declaradas: null });
        assert.equal(writes[1].data.csp, '3176555');
        assert.equal('version' in writes[1].data, false);

        await page.locator('#pdForm [name=lote]').fill('30013');
        await page.locator('#pdSave').click();
        await page.locator('#pdVersion').filter({ hasText: 'Guardado · versión 2' }).waitFor();
        assert.deepEqual([writes[2].method, writes[2].path, writes[2].data.version], ['PUT', '/api/sag/diagramas/diagrama-1', 1]);
        assert.notEqual(writes[2].data.operacion_id, writes[1].data.operacion_id);

        await page.locator('#pdPaper').selectOption('a3');
        const [pdf] = await Promise.all([page.waitForEvent('download'), page.locator('[data-export="pdf"]').click()]);
        assert.equal(pdf.suggestedFilename(), 'Diagrama-USDA-8.pdf');
        assert.deepEqual([pdfs[0].papel, pdfs[0].mostrar_numeros, pdfs[0].cajas_marcadas], ['a3', true, [5, 82]]);

        const [png] = await Promise.all([page.waitForEvent('download'), page.locator('[data-export="png"]').click()]);
        assert.equal(png.suggestedFilename(), 'Diagrama-USDA-8.png');
        const bytes = readFileSync(await png.path());
        assert.equal(bytes.subarray(1, 4).toString(), 'PNG');
        assert.ok(bytes.readUInt32BE(16) >= 4000, 'PNG de alta resolución');

        await page.locator('[data-export="print"]').click();
        await page.locator('.pd-print-frame').waitFor({ state: 'attached' });
        assert.equal(pdfs.length, 2);
        assert.match(await page.locator('#pdMessage').textContent(), /A3 horizontal/);

        // Un pallet sin configurar impide emitir hasta completarlo o quitarlo.
        await page.locator('#pdAddPallet').click();
        await page.locator('[data-export="pdf"]').click();
        assert.match(await page.locator('#pdMessage').textContent(), /Pallet 17: falta configurar su base y sus corridas, o quítalo/);
        assert.equal(pdfs.length, 2);

        // Abrir otro diagrama con cambios pide un segundo clic.
        await page.locator('#pdOpenSaved').click();
        await page.locator('#pdSavedRows [data-open="diagrama-1"]').click();
        assert.match(await page.locator('#pdMessage').textContent(), /Vuelve a pulsar para descartarlos/);
        await page.locator('#pdSavedRows [data-open="diagrama-1"]').click();
        await page.locator('#pdMessage').filter({ hasText: 'abierto (versión 2)' }).waitFor();
        assert.equal(await page.locator('#pdPallets tr').count(), 16);
        assert.equal(await page.locator('#pdForm [name=lote]').inputValue(), '30013');
        assert.deepEqual(errors, []);
    } finally { await browser.close(); }
});

test('un perfil de consulta exporta pero no guarda', async () => {
    const { browser, page, errors } = await abrir({ puedeGestionar: false });
    try {
        assert.equal(await page.locator('#pdSave').isDisabled(), true);
        assert.match(await page.locator('#pdSave').getAttribute('title'), /no guardarlos/);
        assert.deepEqual(errors, []);
    } finally { await browser.close(); }
});
