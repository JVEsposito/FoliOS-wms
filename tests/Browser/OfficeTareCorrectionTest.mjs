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
const types = [{ codigo: 'bins', nombre: 'Bins plástico', contiene_fruta: true }, { codigo: 'totes', nombre: 'Totes', contiene_fruta: true }];

function receptionFixture(cumulative) {
    return { id: 'recepcion', numero_recepcion: 'REC-2611-0012', numero_guia_despacho: '1512', cliente: client, temporada: season,
        estado: 'cerrado', estado_validacion_mp: 'validada', tipo_recepcion: cumulative ? 'fruta_pesaje_envases' : 'fruta_con_envases',
        tipo_servicio: 'prefrio', version: 5, patente_camion: 'ABCD12', nombre_conductor: 'Conductor', rut_conductor: '12.345.678-5',
        ingreso_at: '2026-11-07T10:00:00Z', salida_at: '2026-11-07T11:00:00Z', cantidad_envases_declarados: cumulative ? 22 : 48,
        ...(cumulative ? {
            peso_bruto: 705.123, peso_tara: 110, peso_tara_envases: null, peso_neto: 595.123,
            envases: [{ tipo_envase: 'bins', cantidad_declarada: 2, cantidad_validada: 2, tara_unitaria_salida: 40 }, { tipo_envase: 'totes', cantidad_declarada: 20, cantidad_validada: 20, tara_unitaria_salida: 1.5 }],
            pesaje_envases: { tipo_envase: 'bins', tara_unitaria: 40, cantidad_declarada: 22, cantidad_pesada: 22, cantidad_pendiente: 0, completo: true,
                tipos: [{ tipo_envase: 'bins', cantidad_declarada: 2, cantidad_pesada: 2, cantidad_pendiente: 0, tara_unitaria: 40 }, { tipo_envase: 'totes', cantidad_declarada: 20, cantidad_pesada: 20, cantidad_pendiente: 0, tara_unitaria: 1.5 }] },
            lecturas_pesaje_envases: [{ id: 'tanda-1', secuencia: 1, tipo_envase: 'bins', cantidad_envases: 22, peso_bruto: 705.123, peso_tara: 110, peso_neto: 595.123, anulado: false,
                envases: [{ tipo_envase: 'bins', cantidad: 2, tara_unitaria: 40, peso_tara: 80, peso_neto: 0 }, { tipo_envase: 'totes', cantidad: 20, tara_unitaria: 1.5, peso_tara: 30, peso_neto: 595.123 }] }],
            salida_envases: [], modo_salida_envases: null,
        } : {
            peso_bruto: 28540, peso_tara: 10540, peso_tara_envases: 1920, peso_neto: 16080, modo_salida_envases: 'vacio', salida_envases: [],
            envases: [{ tipo_envase: 'bins', cantidad_declarada: 48, cantidad_validada: 48, tara_unitaria_salida: 40 }],
            pesaje_envases: null, lecturas_pesaje_envases: [],
        }),
        lotes_materia_prima: [{ id: 'lote-1', numero_lote: 'L-4444', estado: 'entrega_parcial_proceso', kilos_netos: cumulative ? 595.123 : 16080 }],
        // Incluso una respuesta incorrecta no habilita el botón a otros roles.
        puede_corregir_tara: true, eventos: [] };
}

test('corrección de tara: solo administración, vista adaptable, vista previa del neto y reintento sin duplicar operación', async () => {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
    try {
        for (const [role, width, cumulative] of [['administrador', 1280, false], ['administrador', 390, true], ['operador_romana', 1280, false], ['supervisor_frio', 390, true]]) {
            const context = await browser.newContext({ viewport: { width, height: 1000 } });
            const identity = { id: 1, nombre: 'Usuario', rol: role, puede_consultar_romana: true, puede_operar_romana: true, modulos_acceso: ['materia-prima.romana'] };
            await context.addInitScript((user) => {
                localStorage.setItem('estiba_wms_office_token', 'fixture');
                localStorage.setItem('estiba_wms_office_identity', JSON.stringify(user));
            }, identity);
            const writes = []; const errors = [];
            let reception = receptionFixture(cumulative);
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
                if (path === '/api/romana/catalogos') return route.fulfill({ json: { temporadas: [season], clientes: [client], especies: [], tipos_envase: types, tipos_servicio: [], tipos_recepcion: [], conceptos_envases: [], taras_romana: { bins: 43, totes: 0.89 } } });
                if (path.endsWith('/tara')) {
                    writes.push(request.postDataJSON());
                    if (writes.length === 1) return route.abort('failed');
                    const before = { peso_tara: reception.peso_tara, peso_tara_envases: reception.peso_tara_envases, peso_neto: reception.peso_neto, taras_envases: cumulative ? { bins: 40, totes: 1.5 } : { bins: 40 } };
                    const after = cumulative ? { peso_tara: 103.8, peso_tara_envases: null, peso_neto: 601.323, taras_envases: { bins: 43, totes: 0.89 } }
                        : { peso_tara: 10500, peso_tara_envases: 2064, peso_neto: 15976, taras_envases: { bins: 43 } };
                    reception = { ...reception, ...after, version: 6, lotes_materia_prima: [{ ...reception.lotes_materia_prima[0], kilos_netos: after.peso_neto }],
                        eventos: [{ tipo: 'tara_corregida', usuario: { nombre: 'Administrador' }, ocurrido_at: '2026-11-07T12:00:00Z', estado_anterior: 'cerrado', estado_nuevo: 'cerrado',
                            datos: { motivo: writes[1].motivo_correccion, anterior: before, posterior: after, lotes: [{ id: 'lote-1', numero_lote: 'L-4444' }] } }] };
                    return route.fulfill({ json: { data: reception } });
                }
                if (path === '/api/romana/recepciones/recepcion') return route.fulfill({ json: { data: reception } });
                if (path === '/api/romana/recepciones') return route.fulfill({ json: { data: [reception], resumen: { en_bascula_ingreso: 0, en_bascula_salida: 0, en_pesaje_envases: 0, cerradas: 1, peso_neto: reception.peso_neto }, meta: { total: 1, pagina_actual: 1, ultima_pagina: 1 } } });
                return route.fulfill({ json: {} });
            });
            const page = await context.newPage(); page.on('pageerror', (error) => errors.push(error.message));
            await page.goto('http://localhost/oficina/romana');
            await page.locator('tr.rec-row').click();
            const button = page.locator('#correctTareButton');
            if (role !== 'administrador') {
                assert.equal(await button.isVisible(), false);
            } else {
                await button.click();
                const dialog = page.locator('#tareCorrectionDialog');
                assert.equal(await page.locator('#tareCorrectionTruckField').isVisible(), !cumulative);
                assert.equal(await dialog.evaluate((element) => {
                    const bounds = element.getBoundingClientRect();
                    return bounds.left >= 0 && bounds.right <= window.innerWidth && element.scrollWidth <= element.clientWidth;
                }), true);
                assert.match(await page.locator('#tareCorrectionLots').innerText(), /L-4444/);
                assert.match(await page.locator('#tareCorrectionTypes').innerText(), cumulative ? /22 pesados|2 pesados/ : /48 validados · 0 salieron · 48 descuentan tara/);
                // Sin cambios no se envía nada.
                await page.locator('#tareCorrectionForm [name="motivo_correccion"]').fill('La tara oficial de bins estaba mal.');
                await page.locator('#tareCorrectionForm [type="submit"]').click();
                assert.match(await page.locator('#tareCorrectionError').innerText(), /igual a la registrada/);
                assert.equal(writes.length, 0);
                await page.locator('[data-tare-correction="bins"]').fill('43');
                if (cumulative) {
                    await page.locator('[data-tare-correction="totes"]').fill('0.89');
                    assert.match(await page.locator('#tareCorrectionPreview').innerText(), /110,00 kg → 103,80 kg · Neto: 595,123 kg → 601,323 kg/);
                } else {
                    await page.locator('#tareCorrectionForm [name="peso_tara"]').fill('10500');
                    assert.match(await page.locator('#tareCorrectionPreview').innerText(), /12\.460,00 kg → 12\.564,00 kg · Neto: 16\.080,00 kg → 15\.976,00 kg/);
                }
                await page.locator('#tareCorrectionForm [type="submit"]').click();
                await page.waitForFunction(() => document.getElementById('tareCorrectionError').textContent.length > 0
                    && !document.getElementById('tareCorrectionError').textContent.includes('igual'));
                await page.locator('#tareCorrectionForm [type="submit"]').click();
                await page.waitForFunction(() => !document.getElementById('tareCorrectionDialog').open);
                assert.equal(writes.length, 2); assert.deepEqual(writes[0], writes[1]);
                assert.equal(writes[1].version_conocida, 5);
                if (cumulative) {
                    assert.equal('peso_tara' in writes[1], false);
                    assert.deepEqual(writes[1].taras_envases, [{ tipo_envase: 'bins', tara_unitaria: 43 }, { tipo_envase: 'totes', tara_unitaria: 0.89 }]);
                } else {
                    assert.equal(writes[1].peso_tara, 10500);
                    assert.deepEqual(writes[1].taras_envases, [{ tipo_envase: 'bins', tara_unitaria: 43 }]);
                }
                const timeline = await page.locator('#detailTimeline').innerText();
                assert.match(timeline, /Tara corregida/);
                assert.match(timeline, cumulative ? /Neto: 595,123 kg → 601,323 kg/ : /Tara total: 12\.460,00 kg → 12\.564,00 kg/);
                assert.match(timeline, /1 lote recalculado/);
            }
            assert.deepEqual(errors, []);
            await context.close();
        }
    } finally { await browser.close(); }
});
