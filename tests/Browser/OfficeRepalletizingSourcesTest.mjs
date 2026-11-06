import assert from 'node:assert/strict';
import { mkdirSync, readFileSync } from 'node:fs';
import { extname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
const html = readFileSync(resolve(root, 'storage/app/ui/oficina/repaletizajes.html'), 'utf8');
const source = (number, type, boxes, active = true) => ({
    existe: true, id: number, numero_folio: number, tipo_bulto: type, activo: active, cantidad_cajas: boxes,
    cliente: 'Cliente', especie: 'Cereza', marca: 'Marca', variedad: 'Santina', calibre: '2J', envase: '5 kg', categoria: 'Exportación',
    condicion_termica: 'pendiente_prefrio', csg: '111', predio: 'Huerto', cuartel: 'A',
    composicion: [{ clave: `line-${number}`, csg: '111', predio: 'Huerto', fecha_embalaje: '2026-11-06', calibre: '2J', variedad: 'Santina', envase: '5 kg', categoria: 'Exportación', cantidad_cajas: boxes }],
});

for (const width of [1280, 390]) test(`Oficina admite pallets completos en consolidación, cambio y división en ${width}px`, async () => {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
    try {
        const context = await browser.newContext({ viewport: { width, height: 1000 } });
        await context.addInitScript(() => {
            localStorage.setItem('estiba_wms_office_token', 'fixture');
            localStorage.setItem('estiba_wms_office_identity', JSON.stringify({ id: 1, nombre: 'Tarjador', rol: 'tarjador_pt',
                puede_consultar_repaletizajes: true, puede_registrar_repaletizajes: true, modulos_acceso: ['frigorifico.repaletizajes'] }));
        });
        const entries = [source('600000209', 'pallet', 240), source('SAL-40', 'saldo', 40), source('INACTIVO', 'pallet', 240, false)];
        entries.push({ ...source('MIX-240', 'pallet', 240), calibre: 'MIX', composicion: [
            { clave: 'mix-2j', csg: '111', predio: 'Huerto', fecha_embalaje: '2026-11-06', calibre: '2J', variedad: 'Royal Dawn', envase: '5 kg', cantidad_cajas: 100 },
            { clave: 'mix-3j', csg: '111', predio: 'Huerto', fecha_embalaje: '2026-11-06', calibre: '3J', variedad: 'Royal Dawn', envase: '5 kg', cantidad_cajas: 140 },
        ] });
        const writes = []; const errors = [];
        await context.route('http://localhost/**', async (route) => {
            const request = route.request(); const path = new URL(request.url()).pathname;
            if (path === '/oficina/validacion/repaletizajes') return route.fulfill({ body: html, contentType: 'text/html' });
            if (path.startsWith('/build/') || path.startsWith('/brand/')) {
                try { return route.fulfill({ body: readFileSync(resolve(root, `public${path}`)), contentType: ({ '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml', '.woff2': 'font/woff2' })[extname(path)] || 'application/octet-stream' }); }
                catch { return route.fulfill({ status: 404, body: '' }); }
            }
            if (path === '/api/acceso-oficina') return route.fulfill({ json: { usuario: { puede_consultar_repaletizajes: true, puede_registrar_repaletizajes: true } } });
            if (path === '/api/oficina/contexto') return route.fulfill({ json: { data: { planta: 'Prueba', temporada: { codigo: 'TEMP-2627' } } } });
            if (path.includes('/repaletizajes/folios/')) return route.fulfill({ json: entries.find((entry) => entry.numero_folio === decodeURIComponent(path.split('/').at(-1))) ?? { existe: false } });
            if (path === '/api/validacion/repaletizajes' && request.method() === 'POST') {
                const payload = request.postDataJSON(); writes.push(payload);
                return route.fulfill({ json: { data: { codigo: 'REPA-TEST',
                    folio_resultante: { numero_folio: payload.numero_folio_resultante || payload.resultados[0].numero_folio },
                    resultados: (payload.resultados || []).map((result) => ({ ...result, folio: { numero_folio: result.numero_folio } })),
                } } });
            }
            return route.fulfill({ json: { data: [], meta: {} } });
        });
        const page = await context.newPage(); page.on('pageerror', (error) => errors.push(error.message));
        page.on('dialog', (dialog) => dialog.accept());
        await page.goto('http://localhost/oficina/validacion/repaletizajes');
        const field = (name) => page.locator(`#repaForm [name="${name}"]`);
        async function add(number) {
            await page.locator('#sourceFolioInput').fill(number); await page.locator('#addSourceButton').click();
            await page.locator('#officeLoading').waitFor({ state: 'hidden' });
        }
        async function submit() {
            await page.locator('#repaForm button[type="submit"]').click();
            await page.waitForFunction(() => document.getElementById('sourceCount').textContent === '0 folios'
                || document.getElementById('repaError').textContent.trim());
            assert.equal(await page.locator('#repaError').textContent(), '');
            await page.locator('#officeLoading').waitFor({ state: 'hidden' });
        }
        await field('cantidad_objetivo').fill('240'); await field('estrategia_folio').selectOption('nuevo');
        await field('numero_folio_resultante').fill('NUEVO-240');
        await add('INACTIVO'); assert.match(await page.locator('#sourceError').textContent(), /no es un pallet o saldo activo/);
        assert.equal(await page.locator('.source-card').count(), 0);
        await add('600000209'); await add('600000209');
        assert.equal(await page.locator('.source-card').count(), 1);
        await add('SAL-40');
        assert.match(await page.locator('.source-card').first().innerText(), /Pallet completo/);
        await page.locator('[data-composition-source="600000209"]').fill('200');
        if (process.env.REPA_SCREENSHOT_DIR) {
            mkdirSync(process.env.REPA_SCREENSHOT_DIR, { recursive: true });
            await page.screenshot({ path: resolve(process.env.REPA_SCREENSHOT_DIR, `repa-${width}.png`), fullPage: true });
        }
        await submit();
        assert.deepEqual(writes[0].origenes.map(({ folio_id, cantidad_aportada }) => ({ folio_id, cantidad_aportada })), [
            { folio_id: '600000209', cantidad_aportada: 200 }, { folio_id: 'SAL-40', cantidad_aportada: 40 },
        ]);
        await field('modalidad').selectOption('cambio_folio'); await add('600000209');
        await field('resultado_1_numero').fill('PAL-RENOMBRADO'); await submit();
        assert.equal(writes[1].modalidad, 'cambio_folio'); assert.equal(writes[1].origenes[0].cantidad_aportada, 240);
        await field('modalidad').selectOption('division'); await add('600000209');
        await field('resultado_1_numero').fill('PAL-BAJO'); await field('resultado_2_numero').fill('SAL-RESTO');
        await field('resultado_1_objetivo').fill('180'); await field('resultado_2_objetivo').fill('240');
        await page.locator('[data-division-output="1"]').fill('180');
        assert.equal(await page.locator('[data-division-output="2"]').inputValue(), '60');
        assert.equal(await page.locator('[data-division-total="2"]').textContent(), '60 cajas');
        await submit();
        assert.equal(writes[2].modalidad, 'division');
        assert.deepEqual(writes[2].resultados.map((row) => row.cantidad_resultante), [180, 60]);
        await field('modalidad').selectOption('consolidacion'); await field('tipo_resultado').selectOption('saldo');
        await field('cantidad_objetivo').fill('240'); await field('estrategia_folio').selectOption('nuevo');
        await field('numero_folio_resultante').fill('RESULTADO-SELECTIVO');
        await add('MIX-240');
        assert.equal(await page.locator('.source-card [data-composition-key]').count(), 2);
        assert.match(await page.locator('.composition-lines').innerText(), /Calibre 2J.*Variedad Royal Dawn/s);
        assert.match(await page.locator('.composition-lines').innerText(), /Calibre 3J/);
        await add('SAL-40');
        await page.locator('[data-composition-key="mix-2j"]').fill('0');
        await page.locator('[data-composition-key="mix-3j"]').fill('60');
        assert.equal(await page.locator('.composition-preview p').count(), 2);
        assert.match(await page.locator('.composition-preview').innerText(), /Calibre 3J/);
        if (process.env.REPA_SCREENSHOT_DIR) await page.screenshot({ path: resolve(process.env.REPA_SCREENSHOT_DIR, `repa-mix-${width}.png`), fullPage: true });
        await submit();
        assert.deepEqual(writes[3].origenes[0].composicion, [{ clave: 'mix-3j', cantidad_aportada: 60 }]);
        await field('modalidad').selectOption('division'); await add('MIX-240');
        await field('resultado_1_numero').fill('PURO-2J'); await field('resultado_2_numero').fill('PURO-3J');
        await field('resultado_1_tipo').selectOption('pallet'); await field('resultado_2_tipo').selectOption('pallet');
        await field('resultado_1_objetivo').fill('100'); await field('resultado_2_objetivo').fill('140');
        await page.locator('[data-division-output="1"][data-composition-key="mix-3j"]').fill('0');
        assert.match(await page.locator('#divisionEditor').innerText(), /Calibre 2J/);
        assert.match(await page.locator('#divisionEditor').innerText(), /Calibre 3J/);
        await submit();
        assert.deepEqual(writes[4].resultados.map((row) => row.composicion), [
            [{ clave: 'mix-2j', cantidad_cajas: 100 }], [{ clave: 'mix-3j', cantidad_cajas: 140 }],
        ]);
        assert.deepEqual(errors, []);
        await context.close();
    } finally { await browser.close(); }
});
