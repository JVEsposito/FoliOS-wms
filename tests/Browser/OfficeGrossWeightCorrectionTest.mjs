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

test('corrección de bruto: solo admin, vista adaptable, selección de tanda y reintento sin duplicar operación', async () => {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
    try {
        for (const [role, width, cumulative] of [['administrador', 1280, false], ['administrador', 390, true], ['operador_romana', 1280, false], ['supervisor_frio', 390, false]]) {
            const context = await browser.newContext({ viewport: { width, height: 1000 } });
            const identity = { id: 1, nombre: 'Usuario', rol: role, puede_consultar_romana: true, puede_operar_romana: true, modulos_acceso: ['materia-prima.romana'] };
            await context.addInitScript((user) => {
                localStorage.setItem('estiba_wms_office_token', 'fixture');
                localStorage.setItem('estiba_wms_office_identity', JSON.stringify(user));
            }, identity);
            const writes = []; const errors = [];
            let reception = { id: 'recepcion', numero_recepcion: 'REC-2611-0011', numero_guia_despacho: '1511', cliente: client, temporada: season,
                estado: 'cerrado', estado_validacion_mp: 'validada', tipo_recepcion: cumulative ? 'fruta_pesaje_envases' : 'fruta_con_envases',
                tipo_servicio: 'prefrio', peso_bruto: 500, peso_tara: 80, peso_tara_envases: 0, peso_neto: 420, version: 3,
                cantidad_envases_declarados: 2, envases: [{ tipo_envase: 'bins', cantidad_declarada: 2, cantidad_validada: 2 }],
                patente_camion: 'ABCD12', nombre_conductor: 'Conductor', rut_conductor: '12.345.678-5',
                ingreso_at: '2026-11-07T10:00:00Z', salida_at: '2026-11-07T11:00:00Z',
                puede_corregir_peso_bruto: true, // Incluso una respuesta incorrecta no habilita el botón a otros roles.
                pesaje_envases: cumulative ? { tipo_envase: 'bins', tara_unitaria: 40, cantidad_declarada: 2, cantidad_pesada: 2, cantidad_pendiente: 0, completo: true } : null,
                lecturas_pesaje_envases: cumulative ? [{ id: 'tanda-1', secuencia: 1, tipo_envase: 'bins', cantidad_envases: 2, peso_bruto: 500, peso_tara: 80, peso_neto: 420, anulado: false }] : [], eventos: [] };
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
                if (path.endsWith('/peso-bruto')) {
                    writes.push(request.postDataJSON());
                    if (writes.length === 1) return route.abort('failed');
                    reception = { ...reception, peso_bruto: 520, peso_neto: 440, version: 4,
                        eventos: [{ tipo: 'peso_bruto_corregido', usuario: { nombre: 'Administrador' }, ocurrido_at: '2026-11-07T12:00:00Z', estado_anterior: 'cerrado', estado_nuevo: 'cerrado',
                            datos: { motivo: writes[1].motivo_correccion, anterior: { peso_bruto: 500 }, posterior: { peso_bruto: 520 } } }] };
                    return route.fulfill({ json: { data: reception } });
                }
                if (path === '/api/romana/recepciones/recepcion') return route.fulfill({ json: { data: reception } });
                if (path === '/api/romana/recepciones') return route.fulfill({ json: { data: [reception], resumen: { en_bascula_ingreso: 0, en_bascula_salida: 0, en_pesaje_envases: 0, cerradas: 1, peso_neto: 420 }, meta: { total: 1, pagina_actual: 1, ultima_pagina: 1 } } });
                return route.fulfill({ json: {} });
            });
            const page = await context.newPage(); page.on('pageerror', (error) => errors.push(error.message));
            await page.goto('http://localhost/oficina/romana');
            await page.locator('tr.rec-row').click();
            const button = page.locator('#correctGrossWeightButton');
            if (role !== 'administrador') {
                assert.equal(await button.isVisible(), false);
            } else {
                await button.click();
                assert.equal(await page.locator('#grossWeightReadingField').isVisible(), cumulative);
                assert.equal(await page.locator('#grossWeightDialog').evaluate((dialog) => {
                    const bounds = dialog.getBoundingClientRect();
                    return bounds.left >= 0 && bounds.right <= window.innerWidth && dialog.scrollWidth <= dialog.clientWidth;
                }), true);
                await page.locator('#grossWeightForm [name="peso_bruto"]').fill('520');
                assert.match(await page.locator('#grossWeightPreview').innerText(), /440,00 kg/);
                await page.locator('#grossWeightForm [name="motivo_correccion"]').fill('Error de digitación en la lectura.');
                await page.locator('#grossWeightForm [type="submit"]').click();
                await page.waitForFunction(() => document.getElementById('grossWeightError').textContent.length > 0);
                await page.locator('#grossWeightForm [type="submit"]').click();
                await page.waitForFunction(() => !document.getElementById('grossWeightDialog').open);
                assert.equal(writes.length, 2); assert.deepEqual(writes[0], writes[1]);
                assert.equal(writes[1].version_conocida, 3); assert.equal('peso_tara' in writes[1], false);
                assert.equal(writes[1].pesaje_id, cumulative ? 'tanda-1' : undefined);
                assert.match(await page.locator('#detailTimeline').innerText(), /500,00 kg → 520,00 kg/);
            }
            assert.deepEqual(errors, []);
            await context.close();
        }
    } finally { await browser.close(); }
});
