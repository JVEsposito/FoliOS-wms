import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { extname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';

// Romana ve, antes del destare, lo que Validación MP contó distinto de la guía y los envases vacíos.
const root = fileURLToPath(new URL('../../', import.meta.url));
const html = readFileSync(resolve(root, 'storage/app/ui/oficina/romana.html'), 'utf8');
const types = [{ codigo: 'bins', nombre: 'Bins plástico', contiene_fruta: true }, { codigo: 'totes', nombre: 'Tote', contiene_fruta: true }];
const conteo = [
    'Bins plástico: Validación MP contó 36 y la guía declaraba 35 (1 más).',
    'Tote: Validación MP contó 864 y la guía declaraba 840 (24 más).',
    'Tote: 24 de 864 llegaron vacíos. Cuentan para la cuenta corriente y la tara, no para el peso promedio.',
    'La tara de envases, el neto y el peso promedio se calculan con lo contado en Validación MP.',
];

test('el destare muestra el conteo de Validación MP y reparte el neto solo entre los envases con fruta', async () => {
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
            const reception = { id: 'recepcion', numero_recepcion: 'REC-2611-0042', temporada: season, cliente: { id: 'cliente', nombre: 'LA AGUADA' },
                tipo_recepcion: 'fruta_con_envases', tipo_servicio: 'proceso', estado: 'en_bascula_salida', estado_validacion_mp: 'validada', version: 4,
                numero_guia_despacho: '1511', guias: [], eventos: [], salida_envases: [], inspecciones_envases: [], peso_bruto: 20518, peso_tara: null, peso_neto: null,
                puede_cerrar: true, ingreso_at: '2026-11-12T12:00:00Z', patente_camion: 'ABCD12', nombre_conductor: 'Conductor', rut_conductor: '1-9',
                configuracion_reparto: { sugerido: 'totes', referencias: {} }, observacion_conteo: conteo,
                envases: [
                    { tipo_envase: 'bins', propiedad: 'cliente', cantidad_declarada: 35, cantidad_validada: 36, cantidad_vacia: 0, cantidad_con_fruta: 36, diferencia: 1, tara_unitaria_salida: null },
                    { tipo_envase: 'totes', propiedad: 'cliente', cantidad_declarada: 840, cantidad_validada: 864, cantidad_vacia: 24, cantidad_con_fruta: 840, diferencia: 24, tara_unitaria_salida: null },
                ] };
            const errors = [];
            await context.route('http://localhost/**', async (route) => {
                const path = new URL(route.request().url()).pathname;
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
                if (path === '/api/romana/recepciones') return route.fulfill({ json: { data: [reception], resumen: {}, meta: { pagina_actual: 1, ultima_pagina: 1, total: 1 } } });
                if (path === '/api/romana/recepciones/recepcion') return route.fulfill({ json: { data: reception } });
                return route.fulfill({ json: {} });
            });
            const page = await context.newPage(); page.on('pageerror', (error) => errors.push(error.message));
            await page.goto('http://localhost/oficina/romana');
            await page.locator('#officeLoading').waitFor({ state: 'hidden' });
            await page.locator('tr.rec-row[data-reception-id="recepcion"]').click();
            await page.locator('#detailFacts').filter({ hasText: 'CONTEO VALIDACIÓN MP' }).waitFor();
            assert.match(await page.locator('#detailFacts').innerText(), /24 de 864 llegaron vacíos/);

            await page.locator('#closeReceptionButton').click();
            const note = page.locator('#tareCountNote');
            await note.waitFor({ state: 'visible' });
            assert.deepEqual(await note.locator('li').allInnerTexts(), conteo);
            assert.match(await note.innerText(), /hoja de pesaje \(RPR-01\) que firma el chofer/);
            const options = await page.locator('[name="tipo_envase_calculo_neto"] option').allInnerTexts();
            assert.ok(options.some((text) => /840 con fruta \(24 vacíos\)/.test(text)), options.join(' | '));
            // 20.518 − 10.000 = 10.518 kg sobre los 840 totes con fruta (el camión se va con los mismos envases).
            await page.locator('#tareForm [name="peso_tara"]').fill('10000');
            assert.match(await page.locator('#netPerContainerPreview').innerText(), /12,52/);
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), true);
            assert.deepEqual(errors, []);
            await context.close();
        }
    } finally { await browser.close(); }
});
