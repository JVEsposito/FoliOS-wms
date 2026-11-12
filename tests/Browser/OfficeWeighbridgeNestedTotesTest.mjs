import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { extname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';

// Cereza en bins con sus totes: la tanda se indica en bins y los totes se completan solos.
const root = fileURLToPath(new URL('../../', import.meta.url));
const html = readFileSync(resolve(root, 'storage/app/ui/oficina/romana.html'), 'utf8');
const types = [{ codigo: 'bins', nombre: 'Bins plástico', contiene_fruta: true }, { codigo: 'totes', nombre: 'Tote', contiene_fruta: true }];

test('la tanda de bins con totes completa los totes y el balance muestra el neto por tote', async () => {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
    try {
        for (const width of [390, 1280]) {
            const context = await browser.newContext({ viewport: { width, height: 1000 } });
            const identity = { id: 1, nombre: 'Romana', rol: 'operador_romana', puede_consultar_romana: true, puede_operar_romana: true, modulos_acceso: ['materia-prima.romana'] };
            await context.addInitScript((user) => {
                localStorage.setItem('estiba_wms_office_token', 'fixture');
                localStorage.setItem('estiba_wms_office_identity', JSON.stringify(user));
            }, identity);
            const season = { id: 'temporada', nombre: 'Temporada 2026', codigo: 'TEMP-2627', activa: true };
            const reception = { id: 'recepcion', numero_recepcion: 'REC-2611-0041', temporada: season, cliente: { id: 'cliente', nombre: 'FALCO FRUIT' },
                tipo_recepcion: 'fruta_pesaje_envases', tipo_servicio: 'proceso', estado: 'en_pesaje_envases', estado_validacion_mp: 'pendiente', version: 4,
                numero_guia_despacho: '127', guias: [], eventos: [], salida_envases: [], inspecciones_envases: [], lecturas_pesaje_envases: [],
                peso_bruto: 536, peso_tara: 129.92, peso_neto: 406.08, peso_neto_por_envase: 8.46, puede_registrar_pesaje: true,
                ingreso_at: '2026-11-12T12:00:00Z', patente_camion: 'ABCD12', nombre_conductor: 'Conductor', rut_conductor: '1-9',
                configuracion_reparto: { sugerido: 'totes', totes_por_bin: 24, referencias: { bins: 210, totes: 8.75 } },
                envases: [{ tipo_envase: 'bins', cantidad_declarada: 6, cantidad_validada: null }, { tipo_envase: 'totes', cantidad_declarada: 144, cantidad_validada: null }],
                pesaje_envases: { tipo_envase: 'bins', tara_unitaria: 40, cantidad_declarada: 150, cantidad_pesada: 50, cantidad_pendiente: 100, peso_neto: 406.08, peso_neto_por_envase: 8.46,
                    tipos: [
                        { tipo_envase: 'bins', cantidad_declarada: 6, cantidad_pesada: 2, cantidad_pendiente: 4, tara_unitaria: 40, peso_neto: 0, peso_neto_por_envase: 0 },
                        { tipo_envase: 'totes', cantidad_declarada: 144, cantidad_pesada: 48, cantidad_pendiente: 96, tara_unitaria: 1.04, peso_neto: 406.08, peso_neto_por_envase: 8.46 },
                    ] } };
            const writes = []; const errors = [];
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
                if (path === '/api/romana/catalogos') return route.fulfill({ json: { temporadas: [season], clientes: [reception.cliente], especies: [], tipos_envase: types, tipos_servicio: [], tipos_recepcion: [], conceptos_envases: [] } });
                if (path === '/api/romana/recepciones/recepcion/pesajes-envases' && request.method() === 'POST') {
                    writes.push(request.postDataJSON());
                    return route.fulfill({ json: { data: reception } });
                }
                if (path === '/api/romana/recepciones') return route.fulfill({ json: { data: [reception], resumen: {}, meta: { pagina_actual: 1, ultima_pagina: 1, total: 1 } } });
                if (path === '/api/romana/recepciones/recepcion') return route.fulfill({ json: { data: reception } });
                return route.fulfill({ json: {} });
            });
            const page = await context.newPage(); page.on('pageerror', (error) => errors.push(error.message));
            await page.goto('http://localhost/oficina/romana');
            await page.locator('#officeLoading').waitFor({ state: 'hidden' });
            await page.locator('tr.rec-row[data-reception-id="recepcion"]').click();
            const balance = page.locator('#weightBalance');
            await balance.filter({ hasText: 'Contienen los totes' }).waitFor();
            assert.match(await balance.innerText(), /Neto por envase · Tote/);
            assert.doesNotMatch(await balance.innerText(), /Neto por envase · Bins/);

            await page.locator('#addContainerWeighingButton').click();
            const dialog = page.locator('#containerWeighingDialog');
            await dialog.locator('[data-weighing-type="bins"]').waitFor();
            assert.match(await page.locator('#containerWeighingDescription').innerText(), /Los totes van dentro de los bins \(24 por bin\)/);
            const bins = dialog.locator('[data-weighing-type="bins"]');
            const totes = dialog.locator('[data-weighing-type="totes"]');
            await bins.fill('2');
            assert.equal(await totes.inputValue(), '48');
            // Un bin que trae menos totes se corrige a mano y deja de seguir a los bins.
            await totes.fill('46');
            await bins.fill('3');
            assert.equal(await totes.inputValue(), '46');
            await bins.fill('2');
            await dialog.locator('[name="peso_bruto"]').fill('554.5');
            await dialog.locator('button[type="submit"]:not([value="cancel"])').click();
            await page.waitForFunction(() => !document.getElementById('containerWeighingDialog').open);
            assert.deepEqual(writes[0].envases, [{ tipo_envase: 'bins', cantidad: 2 }, { tipo_envase: 'totes', cantidad: 46 }]);
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), true);
            assert.deepEqual(errors, []);
            await context.close();
        }
    } finally { await browser.close(); }
});
