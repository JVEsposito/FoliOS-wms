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

for (const width of [1280, 390]) test(`Oficina reembala con conservación o cambio de folio y controla la merma en ${width}px`, async () => {
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
        entries.push({ ...source('REEMBALAJE-5', 'saldo', 1), reembalaje: { huella: 'fingerprint', errores: [], kilos_entrada: '5.0000',
            componentes: [{ clave: 'component', csg: '111', cantidad_cajas: 1, kilos_netos_por_caja: '5.0000', kilos_entrada: '5.0000' }],
            formatos: [{ id: 'half', nombre: 'Caja 2,5 kg', kilos_netos_por_caja: '2.5000' }, { id: 'two', nombre: 'Caja 2 kg', kilos_netos_por_caja: '2.0000' }],
        } });
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
        await field('modalidad').selectOption('reembalaje');
        const editor = page.locator('#repackingEditor');
        await editor.locator('[data-lookup]').fill('REEMBALAJE-5'); await editor.locator('[data-find]').click();
        await editor.locator('[data-format]').selectOption('half'); await editor.locator('[data-count]').fill('2');
        assert.match(await editor.locator('[data-balance]').textContent(), /Cuadrado/);
        await editor.locator('[data-confirm]').click(); await editor.locator('[data-success]').filter({ hasText: 'confirmado' }).waitFor();
        assert.equal(writes[0].modalidad, 'reembalaje'); assert.equal(writes[0].resultados[0].numero_folio, 'REEMBALAJE-5');
        await editor.locator('[data-lookup]').fill('REEMBALAJE-5'); await editor.locator('[data-find]').click();
        await editor.locator('[data-format]').selectOption('two'); await editor.locator('[data-count]').fill('2');
        await editor.locator('[data-confirm]').click(); assert.match(await editor.locator('[data-repack-error]').textContent(), /cuadrar/);
        await editor.locator('[data-loss]').fill('1'); await editor.locator('[data-confirm]').click();
        assert.match(await editor.locator('[data-repack-error]').textContent(), /motivo/); assert.equal(writes.length, 1);
        await editor.locator('[data-reason]').fill('Fruta descartada durante el reembalaje');
        await editor.locator('[data-keep]').uncheck(); await editor.locator('[data-number]').fill('NUEVO-2');
        await editor.locator('[data-confirm]').click(); await editor.locator('[data-success]').filter({ hasText: 'confirmado' }).waitFor();
        assert.equal(writes[1].resultados[0].numero_folio, 'NUEVO-2'); assert.equal(writes[1].mermas[0].kilos, '1');
        assert.equal(await page.locator('body').evaluate((n) => n.scrollWidth <= innerWidth + 1), true);
        assert.deepEqual(errors, []); await context.close();
    } finally { await browser.close(); }
});
