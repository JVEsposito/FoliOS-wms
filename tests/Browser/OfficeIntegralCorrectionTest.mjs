import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { extname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
const html = readFileSync(resolve(root, 'storage/app/ui/oficina/romana.html'), 'utf8');
const types = [{ codigo: 'bins', nombre: 'Bins plástico', contiene_fruta: true }, { codigo: 'totes', nombre: 'Tote', contiene_fruta: true },
    { codigo: 'esponjas', nombre: 'Esponja', contiene_fruta: false }];
const estado = {
    recepcion_id: 'recepcion', numero_recepcion: 'REC-2611-0001', version: 7, tipo_recepcion: 'fruta_con_envases', estado: 'cerrado',
    validada: true, cerrada: true, propiedad_editable: true, cantidades_editables: true, tipos_envase: types,
    guias: [{ id: 'g1', numero_guia_despacho: '1511', envases: [{ tipo_envase: 'bins', cantidad: 2 }, { tipo_envase: 'totes', cantidad: 48 }] },
        { id: 'g2', numero_guia_despacho: '1512', envases: [{ tipo_envase: 'bins', cantidad: 2 }, { tipo_envase: 'totes', cantidad: 48 }] }],
    segmentos: [{ id: 's1', secuencia: 1, guia_recepcion_romana_id: 'g1', csg: 'CSG-1', variedad: 'Santina', lotes: ['L-1'], envases: [{ tipo_envase: 'bins', cantidad: 2 }, { tipo_envase: 'totes', cantidad: 48 }] },
        { id: 's2', secuencia: 2, guia_recepcion_romana_id: 'g2', csg: 'CSG-1', variedad: 'Santina', lotes: [], envases: [{ tipo_envase: 'bins', cantidad: 2 }, { tipo_envase: 'totes', cantidad: 49 }] }],
    envases: [{ tipo_envase: 'bins', propiedad: 'cliente', cantidad_declarada: 4, cantidad_validada: 4, tara_unitaria_salida: 40 },
        { tipo_envase: 'totes', propiedad: 'cliente', cantidad_declarada: 96, cantidad_validada: 97, tara_unitaria_salida: 2 }],
    destare: { peso_bruto: 28000, peso_tara: 10000, peso_neto: 18000, modo_salida_envases: 'mismos', numero_guia_salida: 'GS-1',
        salida_envases: [{ tipo_envase: 'bins', cantidad: 4 }, { tipo_envase: 'totes', cantidad: 97 }] },
    lotes: [{ numero_lote: 'L-1', estado: 'disponible_proceso', kilos_netos: 9000, con_entregas: false }],
};

test('Administración corrige guías, conteo, propiedad y destare de una recepción validada', async () => {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
    try {
        for (const width of [390, 1280]) {
            const context = await browser.newContext({ viewport: { width, height: 1000 } });
            const identity = { id: 1, nombre: 'Administración', rol: 'administrador', puede_consultar_romana: true, puede_operar_romana: true, modulos_acceso: ['materia-prima.romana'] };
            await context.addInitScript((user) => {
                localStorage.setItem('estiba_wms_office_token', 'fixture');
                localStorage.setItem('estiba_wms_office_identity', JSON.stringify(user));
            }, identity);
            const season = { id: 'temporada', nombre: 'Temporada 2026', codigo: 'TEMP-2627', activa: true };
            const reception = { id: 'recepcion', numero_recepcion: 'REC-2611-0001', temporada: season, cliente: { id: 'cliente', nombre: 'LA AGUADA' },
                tipo_recepcion: 'fruta_con_envases', estado: 'cerrado', estado_validacion_mp: 'validada', version: 7, numero_guia_despacho: '1511 / 1512',
                guias: estado.guias, envases: estado.envases, eventos: [], salida_envases: [], inspecciones_envases: [], peso_bruto: 28000, peso_tara: 10000, peso_neto: 18000,
                ingreso_at: '2026-11-09T12:00:00Z' };
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
                if (path === '/api/romana/recepciones/recepcion/correccion-integral') {
                    if (request.method() === 'PUT') {
                        writes.push(request.postDataJSON());
                        return writes.length === 1
                            ? route.fulfill({ status: 409, json: { message: 'El lote L-1 ya tiene entregas a proceso.' } })
                            : route.fulfill({ json: { data: { ...reception, version: 8 } } });
                    }
                    return route.fulfill({ json: { data: estado } });
                }
                if (path === '/api/romana/recepciones') return route.fulfill({ json: { data: [reception], resumen: {}, meta: { pagina_actual: 1, ultima_pagina: 1, total: 1 } } });
                if (path === '/api/romana/recepciones/recepcion') return route.fulfill({ json: { data: reception } });
                return route.fulfill({ json: {} });
            });
            const page = await context.newPage(); page.on('pageerror', (error) => errors.push(error.message));
            await page.goto('http://localhost/oficina/romana');
            await page.locator('#officeLoading').waitFor({ state: 'hidden' });
            await page.locator('tr.rec-row[data-reception-id="recepcion"]').click();
            await page.locator('#integralCorrectionButton').waitFor({ state: 'visible' });
            await page.locator('#integralCorrectionButton').click();
            const dialog = page.locator('#integralCorrectionDialog');
            await dialog.locator('#integralForm').waitFor();
            assert.equal(await dialog.locator('[data-numero="g1"]').inputValue(), '1511');
            await dialog.locator('[data-numero="g1"]').fill('1521');
            await dialog.locator('[data-segmento="s1"][data-tipo="totes"]').fill('49');
            // Un tote vacío del segmento 2: entra a la cuenta corriente, no al lote.
            await dialog.locator('[data-vacio="s2"][data-tipo="totes"]').fill('1');
            await dialog.locator('[data-propiedad="bins"]').selectOption('arrendada');
            // Un tipo nuevo aparece en todas las tablas y conserva lo digitado.
            await dialog.locator('#integralAddType').selectOption('esponjas');
            assert.equal(await dialog.locator('[data-segmento="s1"][data-tipo="totes"]').inputValue(), '49');
            assert.equal(await dialog.locator('[data-vacio="s2"][data-tipo="totes"]').inputValue(), '1');
            assert.equal(await dialog.locator('[data-vacio="s2"][data-tipo="esponjas"]').count(), 0, 'Las esponjas no traen fruta: no tienen vacíos.');
            await dialog.locator('[data-segmento="s2"][data-tipo="esponjas"]').fill('4');
            await dialog.locator('[data-tara="esponjas"]').fill('1');
            await dialog.locator('[name="modo_salida_envases"]').selectOption('diferentes');
            await dialog.locator('[data-salida="total"][data-tipo="bins"]').fill('4');
            await dialog.locator('[data-salida="total"][data-tipo="totes"]').fill('99');
            await dialog.locator('[name="peso_tara"]').fill('9900');
            const preview = await dialog.locator('#integralPreview').innerText();
            assert.match(preview, /\+2/);
            // 28.000 − 9.900 − (4 esponjas × 1 kg que quedan en planta) = 18.096
            assert.match(preview, /18\.096/);
            await dialog.locator('[name="motivo_correccion"]').fill('Conteo de totes y guía mal digitados; tara verificada.');
            await dialog.locator('button[type="submit"]').click();
            await dialog.locator('#integralError').filter({ hasText: 'entregas a proceso' }).waitFor();
            await dialog.locator('button[type="submit"]').click();
            await page.waitForFunction(() => !document.getElementById('integralCorrectionDialog').open);
            assert.equal(writes.length, 2);
            // Mismo intento: reutiliza el identificador para no duplicar si la respuesta se perdió.
            assert.equal(writes[0].operacion_id, writes[1].operacion_id);
            const data = writes[1];
            assert.equal(data.version_conocida, 7);
            assert.deepEqual(data.guias.map((g) => g.numero_guia_despacho), ['1521', '1512']);
            assert.deepEqual(data.segmentos[0].envases, [{ tipo_envase: 'bins', cantidad: 2, cantidad_vacia: 0 }, { tipo_envase: 'totes', cantidad: 49, cantidad_vacia: 0 }]);
            assert.deepEqual(data.segmentos[1].envases, [{ tipo_envase: 'bins', cantidad: 2, cantidad_vacia: 0 }, { tipo_envase: 'totes', cantidad: 49, cantidad_vacia: 1 },
                { tipo_envase: 'esponjas', cantidad: 4, cantidad_vacia: 0 }]);
            assert.deepEqual(data.propiedades, [{ tipo_envase: 'bins', propiedad: 'arrendada' }, { tipo_envase: 'totes', propiedad: 'cliente' }, { tipo_envase: 'esponjas', propiedad: 'cliente' }]);
            assert.deepEqual(data.destare, { peso_tara: 9900, modo_salida_envases: 'diferentes', numero_guia_salida: 'GS-1',
                salida_envases: [{ tipo_envase: 'bins', cantidad: 4 }, { tipo_envase: 'totes', cantidad: 99 }],
                taras_envases: [{ tipo_envase: 'bins', tara_unitaria: 40 }, { tipo_envase: 'totes', tara_unitaria: 2 }, { tipo_envase: 'esponjas', tara_unitaria: 1 }] });
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), true);
            assert.deepEqual(errors, []);
            await context.close();
        }
    } finally { await browser.close(); }
});

// Recepción con el lote ya entregado a proceso: Administración corrige solo la cuenta de envases.
const conCuenta = {
    ...estado,
    lotes: [{ numero_lote: 'L-1', estado: 'entrega_parcial_proceso', kilos_netos: 9000, con_entregas: true }],
    cuenta_envases: {
        disponible: true, motivo_no_disponible: null, cerrada: true, entregados_editables: true, salida_conciliada: false,
        exige_productor: true, productores: [{ codigo: 'CSG-1', nombre: 'Agrícola Uno' }, { codigo: 'CSG-2', nombre: 'Agrícola Dos' }],
        tipos: [
            { tipo_envase: 'bins', nombre: 'Bins plástico', recibidos_cuenta: 4, recibidos_registro: 4, entregados_cuenta: 4, entregados_registro: 4 },
            { tipo_envase: 'totes', nombre: 'Tote', recibidos_cuenta: 97, recibidos_registro: 97, entregados_cuenta: 97, entregados_registro: 97 },
        ],
        ajustes: [{ id: 'a1', tipo_envase: 'bins', nombre: 'Bins plástico', lado: 'recibidos', registro: 4, correcto: 5, diferencia: 1,
            motivo: 'Primer conteo en patio.', usuario: 'Administración', registrado_at: '2026-11-12T15:00:00Z', productor: null, vigente: false }],
    },
};

test('Administración ajusta solo la cuenta de envases cuando el lote ya tiene entregas', async () => {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
    try {
        for (const width of [390, 1280]) {
            const context = await browser.newContext({ viewport: { width, height: 1000 } });
            const identity = { id: 1, nombre: 'Administración', rol: 'administrador', puede_consultar_romana: true, puede_operar_romana: true, modulos_acceso: ['materia-prima.romana'] };
            await context.addInitScript((user) => {
                localStorage.setItem('estiba_wms_office_token', 'fixture');
                localStorage.setItem('estiba_wms_office_identity', JSON.stringify(user));
            }, identity);
            const season = { id: 'temporada', nombre: 'Temporada 2026', codigo: 'TEMP-2627', activa: true };
            const reception = { id: 'recepcion', numero_recepcion: 'REC-2611-0001', temporada: season, cliente: { id: 'cliente', nombre: 'LA AGUADA' },
                tipo_recepcion: 'fruta_con_envases', estado: 'cerrado', estado_validacion_mp: 'validada', version: 7, numero_guia_despacho: '1511 / 1512',
                guias: estado.guias, envases: estado.envases, eventos: [], salida_envases: [], inspecciones_envases: [], peso_bruto: 28000, peso_tara: 10000, peso_neto: 18000,
                ingreso_at: '2026-11-09T12:00:00Z' };
            const saved = { ...reception, version: 8, eventos: [{ tipo: 'ajuste_cuenta_envases', usuario: { nombre: 'Administración' }, estado_anterior: 'cerrado', estado_nuevo: 'cerrado',
                ocurrido_at: '2026-11-13T20:00:00Z', datos: { motivo: 'Conteo real en patio: 3 bins; el camión salió sin totes.', resumen: 'Bins plástico recibidos 4 → 3; Tote entregados 97 → 0' } }] };
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
                if (path === '/api/romana/recepciones/recepcion/correccion-integral') return route.fulfill({ json: { data: conCuenta } });
                if (path === '/api/romana/recepciones/recepcion/ajuste-cuenta-envases' && request.method() === 'PUT') {
                    writes.push(request.postDataJSON());
                    return writes.length === 1
                        ? route.fulfill({ status: 422, json: { message: 'La recepción tiene varios CSG: indica a cuál productor corresponde la diferencia de recibidos.' } })
                        : route.fulfill({ json: { data: saved } });
                }
                if (path === '/api/romana/recepciones') return route.fulfill({ json: { data: [reception], resumen: {}, meta: { pagina_actual: 1, ultima_pagina: 1, total: 1 } } });
                if (path === '/api/romana/recepciones/recepcion') return route.fulfill({ json: { data: writes.length > 1 ? saved : reception } });
                return route.fulfill({ json: {} });
            });
            const page = await context.newPage(); page.on('pageerror', (error) => errors.push(error.message));
            await page.goto('http://localhost/oficina/romana');
            await page.locator('#officeLoading').waitFor({ state: 'hidden' });
            await page.locator('tr.rec-row[data-reception-id="recepcion"]').click();
            await page.locator('#integralCorrectionButton').click();
            const dialog = page.locator('#integralCorrectionDialog');
            await dialog.locator('#integralForm').waitFor();
            assert.match(await dialog.locator('.integral-lots').innerText(), /usa «Solo cuenta de envases»/);
            // Lo digitado en la corrección completa no se pierde al cambiar de modo.
            await dialog.locator('[data-numero="g1"]').fill('1599');
            await dialog.locator('[name="integral_modo"][value="cuenta"]').check();
            await dialog.locator('[data-cuenta-recibidos="bins"]').waitFor();
            assert.equal(await dialog.locator('[data-numero="g1"]').count(), 0, 'El modo de cuenta no muestra guías ni lotes.');
            assert.match(await dialog.locator('.integral-shell').innerText(), /Primer conteo en patio\./);
            await dialog.locator('[data-cuenta-recibidos="bins"]').fill('3');
            await dialog.locator('[name="integral_modo"][value="completa"]').check();
            assert.equal(await dialog.locator('[data-numero="g1"]').inputValue(), '1599');
            await dialog.locator('[name="integral_modo"][value="cuenta"]').check();
            assert.equal(await dialog.locator('[data-cuenta-recibidos="bins"]').inputValue(), '3');
            await dialog.locator('[data-cuenta-entregados="totes"]').fill('0');
            assert.equal((await dialog.locator('[data-ajuste-recibidos="bins"]').innerText()).trim(), '-1');
            assert.equal((await dialog.locator('[data-ajuste-entregados="totes"]').innerText()).trim(), '-97');
            const preview = await dialog.locator('#integralPreview').innerText();
            assert.match(preview, /Efecto en el saldo del cliente: Bins plástico -1 · Tote \+97/);
            assert.match(preview, /Sin efecto en guías, Validación MP, lotes, destare, kilos ni existencia física/);
            await dialog.locator('[name="motivo_correccion"]').fill('Conteo real en patio: 3 bins; el camión salió sin totes.');
            await dialog.locator('button[type="submit"]').click();
            await dialog.locator('#integralError').filter({ hasText: 'varios CSG' }).waitFor();
            await dialog.locator('[name="productor_csg"]').selectOption('CSG-2');
            await dialog.locator('button[type="submit"]').click();
            await page.waitForFunction(() => !document.getElementById('integralCorrectionDialog').open);
            assert.equal(writes.length, 2);
            assert.notEqual(writes[0].operacion_id, writes[1].operacion_id, 'Con otro productor es otra operación.');
            assert.deepEqual({ ...writes[1], operacion_id: undefined }, {
                operacion_id: undefined, version_conocida: 7, motivo: 'Conteo real en patio: 3 bins; el camión salió sin totes.', productor_csg: 'CSG-2',
                envases: [{ tipo_envase: 'bins', recibidos: 3, entregados: 4 }, { tipo_envase: 'totes', recibidos: 97, entregados: 0 }],
            });
            // El historial queda plegado en el expediente: basta con que traiga el resumen del ajuste.
            await page.locator('#detailTimeline').filter({ hasText: 'Bins plástico recibidos 4 → 3' }).waitFor({ state: 'attached' });
            assert.match(await page.locator('#detailTimeline').textContent(), /Ajuste de cuenta de envases \(administración\)/);
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), true);
            assert.deepEqual(errors, []);
            await context.close();
        }
    } finally { await browser.close(); }
});
