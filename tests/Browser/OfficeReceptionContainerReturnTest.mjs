import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { extname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
const html = readFileSync(resolve(root, 'storage/app/ui/oficina/romana.html'), 'utf8');
const types = [{ codigo: 'bins', nombre: 'Bins plástico', contiene_fruta: true }, { codigo: 'esponjas', nombre: 'Esponja de bins', contiene_fruta: false }, { codigo: 'totes', nombre: 'Tote', contiene_fruta: true }];

test('Romana registra la devolución de un productor con CSG, propiedad por envase y hora real del reingreso', async () => {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
    try {
        for (const width of [390, 1280]) {
            const context = await browser.newContext({ viewport: { width, height: 1000 }, timezoneId: 'America/Santiago' });
            const identity = { id: 1, nombre: 'Operador', rol: 'operador_romana', puede_consultar_romana: true, puede_operar_romana: true, modulos_acceso: ['materia-prima.romana'] };
            await context.addInitScript((user) => {
                localStorage.setItem('estiba_wms_office_token', 'fixture');
                localStorage.setItem('estiba_wms_office_identity', JSON.stringify(user));
            }, identity);
            const season = { id: 'temporada', nombre: 'Temporada 2026', codigo: 'TEMP-2627', activa: true };
            const client = { id: 'falco', nombre: 'FALCO FRUIT' };
            const producer = { id: 'csg-90624', codigo: '90624', nombre: 'AGRICOLA RIBAGORZA SPA' };
            const writes = []; const lookups = []; const errors = []; let reception = null;
            await context.route('http://localhost/**', async (route) => {
                const request = route.request(); const url = new URL(request.url()); const path = url.pathname;
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
                    tipos_servicio: [{ codigo: 'almacenaje', nombre: 'Almacenaje' }], tipos_recepcion: [{ codigo: 'fruta_con_envases', nombre: 'Fruta con envases' }, { codigo: 'solo_envases', nombre: 'Solo envases' }],
                    conceptos_envases: [{ codigo: 'compra', nombre: 'Compra de envases propios' }, { codigo: 'arriendo', nombre: 'Arriendo de envases' }, { codigo: 'devolucion', nombre: 'Devolución de envases de productor (CSG)' }] } });
                if (path === '/api/envases/devoluciones/productores/falco') return route.fulfill({ json: { data: [producer] } });
                if (path === '/api/envases/devoluciones/salida') {
                    lookups.push(Object.fromEntries(url.searchParams));
                    return route.fulfill({ json: { data: { numero: '2324', devoluciones: [], salidas: [{ origen: 'destare', documento: 'REC-2611-0031 · guía de ingreso 961', fecha: '2026-11-10 17:36', productor: producer,
                        envases: [{ tipo_envase: 'bins', propiedad: 'arrendada', cantidad: 13 }, { tipo_envase: 'esponjas', propiedad: 'propia', cantidad: 14 }, { tipo_envase: 'totes', propiedad: 'arrendada', cantidad: 312 }] }] } } });
                }
                if (request.method() === 'POST' && path === '/api/romana/recepciones') {
                    const data = request.postDataJSON(); writes.push(data);
                    reception = { ...data, id: 'recepcion', numero_recepcion: 'REC-2611-0055', temporada: season, cliente: client, estado: 'en_bascula_ingreso', estado_validacion_mp: 'pendiente', puede_editar: true, version: 1,
                        cantidad_envases_declarados: data.envases.reduce((sum, item) => sum + item.cantidad, 0),
                        envases: data.envases.map((item) => ({ tipo_envase: item.tipo_envase, cantidad_declarada: item.cantidad, cantidad_validada: null, propiedad: item.propiedad || 'arrendada' })),
                        devolucion: data.concepto_envases === 'devolucion' ? { productor: producer, guia_salida: data.devolucion_guia_salida } : null,
                        eventos: [], salida_envases: [], inspecciones_envases: [], ingreso_at: '2026-11-10T20:50:00Z' };
                    return route.fulfill({ status: 201, json: { data: reception } });
                }
                if (path === '/api/romana/recepciones') return route.fulfill({ json: { data: reception ? [reception] : [], resumen: {}, meta: { pagina_actual: 1, ultima_pagina: 1, total: reception ? 1 : 0 } } });
                if (path === '/api/romana/recepciones/recepcion') return route.fulfill({ json: { data: reception } });
                return route.fulfill({ json: {} });
            });
            const page = await context.newPage(); page.on('pageerror', (error) => errors.push(error.message));
            await page.goto('http://localhost/oficina/romana');
            const field = (name) => page.locator(`#receptionForm [name="${name}"]`);
            async function newEntry(concept) {
                await page.locator('#officeLoading').waitFor({ state: 'hidden' });
                if (await page.locator('#receptionDetail').isVisible()) await page.locator('#closeDetailButton').click();
                await page.locator('#newReceptionButton').click();
                await field('tipo_recepcion').selectOption('solo_envases');
                await field('cliente_id').selectOption('falco');
                await field('concepto_envases').selectOption(concept);
                for (const [name, value] of Object.entries({ numero_guia_despacho: 'DEV-2324', patente_camion: 'JJLY15', rut_conductor: '12.345.678-5', nombre_conductor: 'Luis Duarte' })) await field(name).fill(value);
                await field('tipo_camion').selectOption('termo');
            }

            await newEntry('devolucion');
            assert.equal(await page.locator('#containerReturnFields').isVisible(), true);
            await page.waitForFunction(() => document.querySelector('#receptionForm [name="devolucion_productor_csg_id"]').options.length === 2);
            await field('devolucion_guia_salida').fill('2324');
            await page.locator('#containerReturnLookup').click();
            await page.waitForFunction(() => document.querySelector('#receptionForm [name="cantidad_totes"]').value === '312');
            assert.deepEqual(lookups[0], { cliente_id: 'falco', numero: '2324' });
            assert.match(await page.locator('#containerReturnExit').textContent(), /CSG 90624 · AGRICOLA RIBAGORZA SPA/);
            assert.equal(await field('devolucion_productor_csg_id').inputValue(), 'csg-90624');
            assert.equal(await field('cantidad_bins').inputValue(), '13');
            assert.equal(await field('propiedad_esponjas').isVisible(), true);
            assert.equal(await field('propiedad_esponjas').inputValue(), 'propia');
            await field('fecha_ingreso').fill('2026-11-10');
            await field('hora_ingreso').fill('17:50');
            assert.equal(await page.locator('#receptionDialog').evaluate((node) => node.scrollWidth <= node.clientWidth + 1), true);
            await page.locator('#saveReceptionButton').click();
            await page.waitForFunction(() => !document.getElementById('receptionDialog').open);
            assert.equal(writes[0].concepto_envases, 'devolucion');
            assert.equal(writes[0].devolucion_productor_csg_id, 'csg-90624');
            assert.equal(writes[0].devolucion_guia_salida, '2324');
            assert.equal(writes[0].fecha_ingreso, '2026-11-10');
            assert.equal(writes[0].hora_ingreso, '17:50');
            assert.deepEqual(writes[0].envases, [
                { tipo_envase: 'bins', cantidad: 13, propiedad: 'arrendada' },
                { tipo_envase: 'esponjas', cantidad: 14, propiedad: 'propia' },
                { tipo_envase: 'totes', cantidad: 312, propiedad: 'arrendada' },
            ]);
            await page.waitForFunction(() => document.getElementById('detailFacts').textContent.includes('DEVOLUCIÓN DE PRODUCTOR'));
            assert.match(await page.locator('#detailFacts').textContent(), /CSG 90624 · AGRICOLA RIBAGORZA SPA · devuelve guía 2324/);

            // En arriendo no viajan productor, guía devuelta ni propiedad por envase.
            await newEntry('arriendo');
            assert.equal(await page.locator('#containerReturnFields').isVisible(), false);
            await field('cantidad_bins').fill('5');
            await page.locator('#saveReceptionButton').click();
            await page.waitForFunction(() => !document.getElementById('receptionDialog').open);
            assert.equal(writes[1].devolucion_productor_csg_id, undefined);
            assert.equal(writes[1].hora_ingreso, undefined);
            assert.deepEqual(writes[1].envases, [{ tipo_envase: 'bins', cantidad: 5 }]);
            assert.deepEqual(errors, []);
            await context.close();
        }
    } finally { await browser.close(); }
});
