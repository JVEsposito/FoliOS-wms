import assert from 'node:assert/strict';
import { mkdirSync, readFileSync } from 'node:fs';
import { extname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
const html = readFileSync(resolve(root, 'storage/app/ui/oficina/romana.html'), 'utf8');
const types = [{ codigo: 'bins', nombre: 'Bins plástico', contiene_fruta: true }, { codigo: 'totes', nombre: 'Tote', contiene_fruta: true }];

test('el ingreso exige propiedad, conserva la selección al editar y distingue fruta, pesaje y solo envases', async () => {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
    try {
        for (const width of [390, 1280]) {
            const context = await browser.newContext({ viewport: { width, height: 1000 } });
            const identity = { id: 1, nombre: 'Operador', rol: 'operador_romana', puede_consultar_romana: true, puede_operar_romana: true, modulos_acceso: ['materia-prima.romana'] };
            await context.addInitScript((user) => {
                localStorage.setItem('estiba_wms_office_token', 'fixture');
                localStorage.setItem('estiba_wms_office_identity', JSON.stringify(user));
            }, identity);
            const season = { id: 'temporada', nombre: 'Temporada 2026', codigo: 'TEMP-2627', activa: true };
            const client = { id: 'cliente', nombre: 'LA AGUADA' };
            const writes = []; const errors = []; let reception = null;
            await context.route('http://localhost/**', async (route) => {
                const request = route.request(); const path = new URL(request.url()).pathname;
                if (path === '/oficina/romana') return route.fulfill({ body: html, contentType: 'text/html' });
                if (path.startsWith('/build/') || path.startsWith('/brand/')) {
                    try { return route.fulfill({ body: readFileSync(resolve(root, `public${path}`)), contentType: ({ '.js': 'text/javascript', '.css': 'text/css', '.woff2': 'font/woff2', '.svg': 'image/svg+xml' })[extname(path)] || 'application/octet-stream' }); }
                    catch { return route.fulfill({ status: 404, body: '' }); }
                }
                if (path === '/api/acceso-oficina') return route.fulfill({ json: { usuario: identity } });
                if (path === '/api/oficina/contexto') return route.fulfill({ json: { data: { planta: 'Prueba', temporada: season } } });
                if (path === '/api/envases/catalogo') return route.fulfill({ json: { data: types } });
                if (path === '/api/romana/porteria/resumen') return route.fulfill({ json: { data: { pendientes: 0, recientes: [] } } });
                if (path === '/api/romana/catalogos') return route.fulfill({ json: { temporadas: [season], clientes: [client], especies: [{ id: 'especie', nombre: 'Cereza' }], tipos_envase: types,
                    tipos_servicio: [{ codigo: 'almacenaje', nombre: 'Almacenaje' }], tipos_recepcion: [{ codigo: 'fruta_con_envases', nombre: 'Fruta con envases' }, { codigo: 'fruta_pesaje_envases', nombre: 'Fruta con pesaje acumulativo' }, { codigo: 'solo_envases', nombre: 'Solo envases' }], conceptos_envases: [{ codigo: 'compra', nombre: 'Compra' }, { codigo: 'arriendo', nombre: 'Arriendo' }] } });
                if (['POST', 'PUT'].includes(request.method()) && path.startsWith('/api/romana/recepciones')) {
                    const data = request.postDataJSON(); writes.push(data);
                    reception = { ...data, id: 'recepcion', numero_recepcion: 'REC-2610-TEST', temporada: season, cliente: client,
                        estado: 'en_bascula_ingreso', estado_validacion_mp: 'pendiente', puede_editar: true, version: writes.length,
                        cantidad_envases_declarados: data.envases.reduce((sum, item) => sum + item.cantidad, 0),
                        envases: data.envases.map((item) => ({ tipo_envase: item.tipo_envase, cantidad_declarada: item.cantidad, cantidad_validada: null,
                            propiedad: data.tipo_recepcion === 'solo_envases' ? (data.concepto_envases === 'arriendo' ? 'arrendada' : 'propia') : item.propiedad })),
                        pesaje_envases: null, eventos: [], salida_envases: [], inspecciones_envases: [], ingreso_at: '2026-10-29T12:00:00Z' };
                    return route.fulfill({ status: request.method() === 'POST' ? 201 : 200, json: { data: reception } });
                }
                if (path === '/api/romana/recepciones') return route.fulfill({ json: { data: reception ? [reception] : [], resumen: {}, meta: { pagina_actual: 1, ultima_pagina: 1, total: reception ? 1 : 0 } } });
                if (path === '/api/romana/recepciones/recepcion') return route.fulfill({ json: { data: reception } });
                return route.fulfill({ json: {} });
            });
            const page = await context.newPage(); page.on('pageerror', (error) => errors.push(error.message));
            await page.goto('http://localhost/oficina/romana');
            const field = (name) => page.locator(`#receptionForm [name="${name}"]`);
            async function newEntry(type = 'fruta_con_envases') {
                await page.locator('#officeLoading').waitFor({ state: 'hidden' });
                if (await page.locator('#receptionDetail').isVisible()) await page.locator('#closeDetailButton').click();
                await page.locator('#newReceptionButton').click();
                await field('tipo_recepcion').selectOption(type);
                await field('cliente_id').selectOption('cliente');
                if (type !== 'solo_envases') await field('especie_validacion_id').selectOption('especie');
                for (const [name, value] of Object.entries({ numero_guia_despacho: 'GD-123', patente_camion: 'ABCD12', rut_conductor: '12.345.678-5', nombre_conductor: 'Conductor' })) await field(name).fill(value);
                await field('tipo_camion').selectOption('plano');
                if (type === 'fruta_con_envases') await field('peso_bruto').fill('14000');
            }
            await newEntry();
            await field('cantidad_bins').fill('1'); await field('cantidad_totes').fill('31');
            await page.locator('#saveReceptionButton').click();
            assert.match(await page.locator('#receptionFormError').textContent(), /propiedad de Bins plástico/);
            assert.equal(writes.length, 0);
            assert.equal(await field('propiedad_bins').evaluate((node) => node === document.activeElement), true);
            await field('propiedad_bins').selectOption('arrendada'); await field('propiedad_totes').selectOption('cliente');
            assert.equal(await page.locator('#receptionDialog').evaluate((node) => node.scrollWidth <= node.clientWidth + 1), true);
            if (process.env.OWNERSHIP_SCREENSHOT_DIR) {
                mkdirSync(process.env.OWNERSHIP_SCREENSHOT_DIR, { recursive: true });
                await page.screenshot({ path: resolve(process.env.OWNERSHIP_SCREENSHOT_DIR, `ingreso-${width}.png`), fullPage: true });
            }
            await page.locator('#saveReceptionButton').click();
            await page.waitForFunction(() => !document.getElementById('receptionDialog').open);
            assert.deepEqual(writes[0].envases, [{ tipo_envase: 'bins', cantidad: 1, propiedad: 'arrendada' }, { tipo_envase: 'totes', cantidad: 31, propiedad: 'cliente' }]);
            await page.waitForFunction(() => document.getElementById('detailFacts').textContent.includes('Arrendados'));
            assert.match(await page.locator('#detailFacts').textContent(), /Arrendados/);
            await page.locator('#editReceptionButton').click();
            assert.equal(await field('propiedad_bins').inputValue(), 'arrendada');
            assert.equal(await field('propiedad_totes').inputValue(), 'cliente');
            await page.locator('#saveReceptionButton').click();
            await page.waitForFunction(() => !document.getElementById('receptionDialog').open);
            assert.deepEqual(writes[1].envases, writes[0].envases);
            await newEntry('fruta_pesaje_envases');
            assert.equal(await field('propiedad_envase_pesaje').inputValue(), '');
            await field('cantidad_envases_pesaje').fill('3'); await field('tara_unitaria_envase').fill('40');
            await field('propiedad_envase_pesaje').selectOption('arrendada');
            await page.locator('#saveReceptionButton').click();
            await page.waitForFunction(() => !document.getElementById('receptionDialog').open);
            assert.deepEqual(writes[2].envases, [{ tipo_envase: 'bins', cantidad: 3, propiedad: 'arrendada' }]);
            await newEntry('solo_envases'); await field('cantidad_bins').fill('5');
            assert.equal(await field('propiedad_bins').isDisabled(), true);
            await field('concepto_envases').selectOption('arriendo');
            await page.locator('#saveReceptionButton').click();
            await page.waitForFunction(() => !document.getElementById('receptionDialog').open);
            assert.deepEqual(writes[3].envases, [{ tipo_envase: 'bins', cantidad: 5 }]);
            assert.deepEqual(errors, []);
            await context.close();
        }
    } finally { await browser.close(); }
});
