import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { extname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
const html = readFileSync(resolve(root, 'storage/app/ui/oficina/romana.html'), 'utf8');
const season = { id: 'temporada', codigo: 'TEMP-2627', nombre: 'Temporada 2026', activa: true };
const client = { id: 'cliente', nombre: 'Cliente prueba' };
const types = [{ codigo: 'bins', nombre: 'Bins plástico', contiene_fruta: true }];

test('fecha real: la digitación corrige el camión de la noche en hora de planta y reintenta sin duplicar', async () => {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
    try {
        for (const [role, width, allowed] of [['digitador_materia_prima', 1280, true], ['digitador_materia_prima', 390, true], ['consulta', 1280, false]]) {
            const context = await browser.newContext({ viewport: { width, height: 1000 }, timezoneId: 'UTC' });
            const identity = { id: 1, nombre: 'Digitadora', rol: role, puede_consultar_romana: true, puede_operar_romana: false, modulos_acceso: ['materia-prima.romana'] };
            await context.addInitScript((user) => {
                localStorage.setItem('estiba_wms_office_token', 'fixture');
                localStorage.setItem('estiba_wms_office_identity', JSON.stringify(user));
            }, identity);
            const writes = []; const errors = [];
            // Registrada el 17-11 a las 09:15 de planta (12:15 UTC); el camión llegó la noche anterior.
            let reception = { id: 'recepcion', numero_recepcion: 'REC-2611-0042', numero_guia_despacho: '1511', cliente: client, temporada: season,
                estado: 'cerrado', estado_validacion_mp: 'validada', tipo_recepcion: 'fruta_con_envases', tipo_servicio: 'proceso',
                peso_bruto: 28000, peso_tara: 10000, peso_tara_envases: 0, peso_neto: 18000, version: 5,
                cantidad_envases_declarados: 48, envases: [{ tipo_envase: 'bins', cantidad_declarada: 48, cantidad_validada: 48 }],
                patente_camion: 'ABCD12', nombre_conductor: 'Conductor', rut_conductor: '12.345.678-5',
                ingreso_at: '2026-11-17T12:15:00+00:00', registrado_at: '2026-11-17T12:15:00+00:00', salida_at: '2026-11-17T12:40:00+00:00',
                fecha_jornada: '2026-11-17', turno_operativo: 'A', puede_corregir_fecha_real: allowed, eventos: [] };
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
                if (path === '/api/romana/catalogos') return route.fulfill({ json: { temporadas: [season], clientes: [client], especies: [], tipos_envase: types, tipos_servicio: [], tipos_recepcion: [], conceptos_envases: [] } });
                if (path.endsWith('/fecha-real')) {
                    writes.push(request.postDataJSON());
                    if (writes.length === 1) return route.abort('failed');
                    reception = { ...reception, ingreso_at: '2026-11-17T02:30:00+00:00', salida_at: '2026-11-17T04:10:00+00:00', fecha_jornada: '2026-11-16', turno_operativo: 'B', version: 6,
                        eventos: [{ tipo: 'fecha_real_corregida', usuario: { nombre: 'Digitadora' }, ocurrido_at: '2026-11-17T12:30:00Z', estado_anterior: 'cerrado', estado_nuevo: 'cerrado',
                            datos: { motivo: writes[1].motivo, anterior: { ingreso_at: '2026-11-17T12:15:00+00:00', salida_at: '2026-11-17T12:40:00+00:00' },
                                posterior: { ingreso_at: '2026-11-17T02:30:00+00:00', salida_at: '2026-11-17T04:10:00+00:00' } } }] };
                    return route.fulfill({ json: { data: reception } });
                }
                if (path === '/api/romana/recepciones/recepcion') return route.fulfill({ json: { data: reception } });
                if (path === '/api/romana/recepciones') return route.fulfill({ json: { data: [reception], resumen: { en_bascula_ingreso: 0, en_bascula_salida: 0, en_pesaje_envases: 0, cerradas: 1, peso_neto: 18000 }, meta: { total: 1, pagina_actual: 1, ultima_pagina: 1 } } });
                return route.fulfill({ json: {} });
            });
            const page = await context.newPage(); page.on('pageerror', (error) => errors.push(error.message));
            await page.goto('http://localhost/oficina/romana');
            await page.locator('tr.rec-row').click();
            await page.waitForFunction(() => document.getElementById('detailTitle').textContent.includes('REC-2611-0042'));
            const button = page.locator('#realDateButton');
            assert.equal(await button.isVisible(), allowed);
            assert.doesNotMatch(await page.locator('#detailFacts').innerText(), /DIGITADA EN FOLIOS/);
            if (allowed) {
                await button.click();
                const form = page.locator('#realDateForm');
                // Valores en hora de planta, aunque el navegador esté en UTC.
                assert.equal(await form.locator('[name="ingreso_fecha"]').inputValue(), '2026-11-17');
                assert.equal(await form.locator('[name="ingreso_hora"]').inputValue(), '09:15');
                assert.equal(await form.locator('[name="salida_hora"]').inputValue(), '09:40');
                assert.match(await form.innerText(), /Se registró en FoliOS el 17-11-2026 09:15/);
                assert.equal(await page.locator('#realDateDialog').evaluate((dialog) => {
                    const bounds = dialog.getBoundingClientRect();
                    return bounds.left >= 0 && bounds.right <= window.innerWidth && dialog.scrollWidth <= dialog.clientWidth;
                }), true);
                await form.locator('[name="motivo"]').fill('Camión de la noche digitado al día siguiente.');
                await form.locator('[type="submit"]').click();
                assert.match(await page.locator('#realDateError').innerText(), /mismas ya registradas/);
                assert.equal(writes.length, 0);
                await form.locator('[name="ingreso_fecha"]').fill('2026-11-16');
                await form.locator('[name="ingreso_hora"]').fill('23:30');
                await form.locator('[name="salida_hora"]').fill('01:10');
                await form.locator('[type="submit"]').click();
                await page.waitForFunction(() => document.getElementById('realDateError').textContent.length > 0);
                await form.locator('[type="submit"]').click();
                await page.waitForFunction(() => !document.getElementById('realDateDialog').open);
                assert.equal(writes.length, 2); assert.deepEqual(writes[0], writes[1]);
                assert.deepEqual({ ...writes[1], operacion_id: undefined }, { operacion_id: undefined, version_conocida: 5, ingreso_fecha: '2026-11-16', ingreso_hora: '23:30',
                    salida_fecha: '2026-11-17', salida_hora: '01:10', motivo: 'Camión de la noche digitado al día siguiente.' });
                assert.match(await page.locator('#detailTimeline').innerText(), /Ingreso 17-11-2026 09:15 → 16-11-2026 23:30 · Salida 17-11-2026 09:40 → 17-11-2026 01:10/);
                const facts = await page.locator('#detailFacts').innerText();
                assert.match(facts, /DIGITADA EN FOLIOS/);
                assert.match(facts, /16-11-2026 · Turno B/);
            }
            assert.deepEqual(errors, []);
            await context.close();
        }
    } finally { await browser.close(); }
});
