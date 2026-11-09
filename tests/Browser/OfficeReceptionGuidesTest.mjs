import assert from 'node:assert/strict';
import { mkdirSync, readFileSync } from 'node:fs';
import { extname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
const html = readFileSync(resolve(root, 'storage/app/ui/oficina/romana.html'), 'utf8');
const types = [{ codigo: 'bins', nombre: 'Bins plástico', contiene_fruta: true }, { codigo: 'totes', nombre: 'Tote', contiene_fruta: true }];

test('Romana registra varias guías, calcula totales, restaura edición y conserva el pesaje acumulativo', async () => {
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
            const writes = []; const weighings = []; const errors = []; let reception = null;
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
                if (request.method() === 'POST' && path.endsWith('/pesajes-envases')) {
                    weighings.push(request.postDataJSON());
                    return route.fulfill({ json: { data: reception } });
                }
                if (['POST', 'PUT'].includes(request.method()) && path.startsWith('/api/romana/recepciones')) {
                    const data = request.postDataJSON(); writes.push(data);
                    const cumulative = data.tipo_recepcion === 'fruta_pesaje_envases';
                    reception = { ...data, id: 'recepcion', numero_recepcion: 'REC-2610-TEST', temporada: season, cliente: client,
                        estado: 'en_bascula_ingreso', estado_validacion_mp: 'pendiente', puede_editar: true, version: writes.length,
                        cantidad_envases_declarados: data.envases.reduce((sum, item) => sum + item.cantidad, 0),
                        envases: data.envases.map((item) => ({ tipo_envase: item.tipo_envase, cantidad_declarada: item.cantidad, cantidad_validada: null,
                            propiedad: data.tipo_recepcion === 'solo_envases' ? (data.concepto_envases === 'arriendo' ? 'arrendada' : 'propia') : item.propiedad })),
                        pesaje_envases: cumulative ? { tipo_envase: data.envases[0].tipo_envase, tara_unitaria: data.envases[0].tara_unitaria,
                            cantidad_declarada: data.envases.reduce((sum, item) => sum + item.cantidad, 0), cantidad_pesada: 0,
                            cantidad_pendiente: data.envases.reduce((sum, item) => sum + item.cantidad, 0), completo: false, cantidad_lecturas_activas: 0,
                            tipos: data.envases.map((item) => ({ tipo_envase: item.tipo_envase, cantidad_declarada: item.cantidad, cantidad_pesada: 0,
                                cantidad_pendiente: item.cantidad, tara_unitaria: item.tara_unitaria, peso_neto: 0, peso_neto_por_envase: null })) } : null,
                        ...(cumulative ? { estado: 'en_pesaje_envases', puede_registrar_pesaje: true, peso_bruto: 0, peso_tara: 0, peso_neto: 0, lecturas_pesaje_envases: [] } : {}),
                        eventos: [], salida_envases: [], inspecciones_envases: [], ingreso_at: '2026-10-29T12:00:00Z' };
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
            await field('cantidad_bins').fill('2'); await field('cantidad_totes').fill('48');
            await field('propiedad_bins').selectOption('arrendada'); await field('propiedad_totes').selectOption('cliente');
            await page.locator('#addReceptionGuide').click();
            const guide = (index, selector) => page.locator(`[data-guide="${index}"] ${selector}`);
            assert.equal(await guide(0, '[data-guide-number]').inputValue(), 'GD-123');
            await guide(1, '[data-guide-number]').fill('1512');
            await guide(1, '[data-guide-container="bins"]').fill('3');
            await guide(1, '[data-guide-container="totes"]').fill('72');
            assert.equal(await field('cantidad_bins').inputValue(), '5');
            assert.equal(await field('cantidad_totes').inputValue(), '120');
            assert.equal(await field('cantidad_bins').evaluate((n) => n.readOnly), true);
            await page.locator('#saveReceptionButton').click();
            await page.waitForFunction(() => !document.getElementById('receptionDialog').open);
            assert.deepEqual(writes[0].guias.map((g) => g.numero_guia_despacho), ['GD-123', '1512']);
            assert.deepEqual(writes[0].envases, [{ tipo_envase: 'bins', cantidad: 5, propiedad: 'arrendada' }, { tipo_envase: 'totes', cantidad: 120, propiedad: 'cliente' }]);
            await page.locator('#editReceptionButton').click();
            assert.equal(await guide(1, '[data-guide-container="bins"]').inputValue(), '3');
            await guide(1, '[data-guide-number]').fill('gd-123');
            await page.locator('#saveReceptionButton').click();
            assert.match(await page.locator('#receptionFormError').textContent(), /No repitas/);
            assert.equal(writes.length, 1);
            await guide(0, '[data-remove-guide]').click();
            assert.equal(await field('numero_guia_despacho').inputValue(), 'GD-123');
            assert.equal(await field('cantidad_bins').inputValue(), '3');
            assert.equal(await field('cantidad_bins').evaluate((n) => n.readOnly), false);
            await page.locator('#saveReceptionButton').click();
            await page.waitForFunction(() => !document.getElementById('receptionDialog').open);
            assert.equal(writes[1].guias, undefined);
            await newEntry('fruta_pesaje_envases');
            const row = (index, name) => page.locator('#containerWeighingLines [data-weighing-line]').nth(index).locator(`[data-line="${name}"]`);
            await row(0, 'cantidad').fill('2'); await row(0, 'tara_unitaria').fill('40'); await row(0, 'propiedad').selectOption('arrendada');
            await page.locator('#addReceptionGuide').click();
            await guide(1, '[data-guide-number]').fill('1512');
            await guide(1, '[data-guide-container="bins"]').fill('3');
            assert.equal(await row(0, 'cantidad').inputValue(), '5');
            assert.equal(await row(0, 'tara_unitaria').inputValue(), '40');
            await page.locator('#saveReceptionButton').click();
            await page.waitForFunction(() => !document.getElementById('receptionDialog').open);
            assert.deepEqual(writes[2].envases, [{ tipo_envase: 'bins', cantidad: 5, tara_unitaria: 40, propiedad: 'arrendada' }]);
            assert.equal(writes[2].guias.length, 2);
            assert.equal(writes[2].peso_bruto, undefined);
            assert.deepEqual(errors, []);
            await context.close();
        }
    } finally { await browser.close(); }
});
