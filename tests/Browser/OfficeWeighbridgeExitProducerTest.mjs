import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { extname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';

// Camión con dos CSG: en el destare Romana elige a qué productor se entregan los envases que salen.
const root = fileURLToPath(new URL('../../', import.meta.url));
const html = readFileSync(resolve(root, 'storage/app/ui/oficina/romana.html'), 'utf8');
const types = [{ codigo: 'bins', nombre: 'Bins plástico', contiene_fruta: true }, { codigo: 'totes', nombre: 'Tote', contiene_fruta: true }];
const season = { id: 'temporada', nombre: 'Temporada 2026', codigo: 'TEMP-2627', activa: true };
const productores = [{ codigo: '121328', nombre: 'Agrícola Uno' }, { codigo: '154148', nombre: 'Agrícola Dos' }];
const guias = [{ id: 'g1', numero_guia_despacho: '4446', envases: [{ tipo_envase: 'bins', cantidad: 2 }, { tipo_envase: 'totes', cantidad: 48 }] },
    { id: 'g2', numero_guia_despacho: '1050', envases: [{ tipo_envase: 'bins', cantidad: 34 }, { tipo_envase: 'totes', cantidad: 816 }] }];
const envases = [
    { tipo_envase: 'bins', propiedad: 'arrendada', cantidad_declarada: 36, cantidad_validada: 36, cantidad_vacia: 0, cantidad_con_fruta: 36, diferencia: 0, tara_unitaria_salida: 43 },
    { tipo_envase: 'totes', propiedad: 'arrendada', cantidad_declarada: 864, cantidad_validada: 864, cantidad_vacia: 0, cantidad_con_fruta: 864, diferencia: 0, tara_unitaria_salida: 0.89 },
];
const base = { id: 'recepcion', numero_recepcion: 'REC-2611-0060', temporada: season, cliente: { id: 'cliente', nombre: 'FALCO FRUIT' },
    tipo_recepcion: 'fruta_con_envases', tipo_servicio: 'proceso', estado_validacion_mp: 'validada', version: 4, numero_guia_despacho: '4446 / 1050',
    guias, envases, eventos: [], salida_envases: [], inspecciones_envases: [], peso_bruto: 27000, ingreso_at: '2026-11-13T12:00:00Z',
    patente_camion: 'ABCD12', nombre_conductor: 'Conductor', rut_conductor: '1-9', configuracion_reparto: { sugerido: 'bins', referencias: {} },
    observacion_conteo: [], productores_csg: productores, salida_productor: null };

async function abrir(browser, width, identity, reception, extra) {
    const context = await browser.newContext({ viewport: { width, height: 1000 } });
    await context.addInitScript((user) => {
        localStorage.setItem('estiba_wms_office_token', 'fixture');
        localStorage.setItem('estiba_wms_office_identity', JSON.stringify(user));
    }, identity);
    const state = { reception, writes: [], errors: [] };
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
        if (await extra(route, request, path, state) === true) return undefined;
        if (path === '/api/romana/recepciones') return route.fulfill({ json: { data: [state.reception], resumen: {}, meta: { pagina_actual: 1, ultima_pagina: 1, total: 1 } } });
        if (path === '/api/romana/recepciones/recepcion') return route.fulfill({ json: { data: state.reception } });
        return route.fulfill({ json: {} });
    });
    const page = await context.newPage(); page.on('pageerror', (error) => state.errors.push(error.message));
    await page.goto('http://localhost/oficina/romana');
    await page.locator('#officeLoading').waitFor({ state: 'hidden' });
    await page.locator('tr.rec-row[data-reception-id="recepcion"]').click();
    return { context, page, state };
}

test('el destare de un camión con dos CSG pide a qué productor van los envases', async () => {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
    try {
        for (const width of [390, 1280]) {
            const identity = { id: 1, nombre: 'Romana', rol: 'operador_romana', puede_consultar_romana: true, puede_operar_romana: true, modulos_acceso: ['materia-prima.romana'] };
            const reception = { ...base, estado: 'en_bascula_salida', puede_cerrar: true, peso_tara: null, peso_neto: null };
            const { context, page, state } = await abrir(browser, width, identity, reception, async (route, request, path, s) => {
                if (path !== '/api/romana/recepciones/recepcion/cerrar') return null;
                s.writes.push(request.postDataJSON());
                s.reception = { ...reception, estado: 'cerrado', puede_cerrar: false, modo_salida_envases: 'mismos', numero_guia_salida: '2345', peso_tara: 16790, peso_neto: 9000,
                    salida_envases: [{ tipo_envase: 'bins', cantidad: 36, tara_unitaria: 43 }, { tipo_envase: 'totes', cantidad: 864, tara_unitaria: 0.89 }],
                    salida_productor: { codigo: '154148', nombre: 'Agrícola Dos' } };
                await route.fulfill({ json: { data: s.reception } });
                return true;
            });
            await page.locator('#closeReceptionButton').click();
            const form = page.locator('#tareForm');
            const field = page.locator('#outboundProducerField');
            await field.waitFor({ state: 'visible' });
            assert.deepEqual(await form.locator('[name="salida_productor_csg"] option').allInnerTexts(), ['Seleccionar…', 'CSG 121328 · Agrícola Uno', 'CSG 154148 · Agrícola Dos']);
            assert.equal(await form.locator('[name="salida_productor_csg"]').evaluate((el) => el.required), true);
            // Si el camión se va vacío no hay a quién entregar.
            await form.locator('[name="modo_salida_envases"]').selectOption('vacio');
            assert.equal(await field.isVisible(), false);
            await form.locator('[name="modo_salida_envases"]').selectOption('mismos');
            await field.waitFor({ state: 'visible' });
            await form.locator('[name="peso_tara"]').fill('16790');
            await form.locator('[name="numero_guia_salida"]').fill('2345');
            for (const row of await page.locator('#dispatchInspectionItems [data-inspection-type]').all()) {
                await row.locator('[data-clean]').selectOption('si');
                await row.locator('[data-condition]').selectOption('buena');
            }
            await form.locator('[name="salida_productor_csg"]').selectOption('154148');
            await form.locator('button[value="default"]').click();
            await page.locator('#detailFacts').filter({ hasText: 'ENTREGADOS A' }).waitFor();
            assert.equal(state.writes.length, 1);
            assert.equal(state.writes[0].salida_productor_csg, '154148');
            assert.match(await page.locator('#detailFacts').innerText(), /CSG 154148 · Agrícola Dos/);
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), true);
            assert.deepEqual(state.errors, []);
            await context.close();
        }
    } finally { await browser.close(); }
});

test('la corrección administrativa asigna el productor de una salida ya registrada', async () => {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
    try {
        const identity = { id: 1, nombre: 'Administración', rol: 'administrador', puede_consultar_romana: true, puede_operar_romana: true, modulos_acceso: ['materia-prima.romana'] };
        const reception = { ...base, estado: 'cerrado', modo_salida_envases: 'mismos', numero_guia_salida: '2345', peso_tara: 16790, peso_neto: 9000 };
        const estado = { recepcion_id: 'recepcion', numero_recepcion: base.numero_recepcion, version: 4, tipo_recepcion: 'fruta_con_envases', estado: 'cerrado',
            validada: true, cerrada: true, propiedad_editable: true, cantidades_editables: true, tipos_envase: types, guias, productores_csg: productores,
            segmentos: [{ id: 's1', secuencia: 1, guia_recepcion_romana_id: 'g1', csg: '154148', variedad: 'Santina', lotes: ['30004'], envases: guias[0].envases },
                { id: 's2', secuencia: 2, guia_recepcion_romana_id: 'g2', csg: '121328', variedad: 'Santina', lotes: ['30005'], envases: guias[1].envases }],
            envases, lotes: [],
            destare: { peso_bruto: 27000, peso_tara: 16790, peso_neto: 9000, modo_salida_envases: 'mismos', numero_guia_salida: '2345', salida_productor_csg: null,
                salida_envases: [{ tipo_envase: 'bins', cantidad: 36 }, { tipo_envase: 'totes', cantidad: 864 }] } };
        const { context, page, state } = await abrir(browser, 1280, identity, reception, async (route, request, path, s) => {
            if (path !== '/api/romana/recepciones/recepcion/correccion-integral') return null;
            if (request.method() === 'PUT') s.writes.push(request.postDataJSON());
            await route.fulfill({ json: { data: request.method() === 'PUT' ? { ...reception, version: 5, salida_productor: { codigo: '121328', nombre: 'Agrícola Uno' } } : estado } });
            return true;
        });
        await page.locator('#integralCorrectionButton').click();
        const dialog = page.locator('#integralCorrectionDialog');
        const select = dialog.locator('[name="salida_productor_csg"]');
        await select.waitFor();
        assert.equal(await select.evaluate((el) => el.required), true);
        await dialog.locator('[name="motivo_correccion"]').fill('La guía de salida 2345 se emitió a Agrícola Uno.');
        await dialog.locator('button[type="submit"]').click();
        assert.equal(state.writes.length, 0, 'Sin productor el formulario no se envía.');
        await select.selectOption('121328');
        await dialog.locator('button[type="submit"]').click();
        await page.waitForFunction(() => !document.getElementById('integralCorrectionDialog').open);
        assert.equal(state.writes[0].destare.salida_productor_csg, '121328');
        assert.deepEqual(state.errors, []);
        await context.close();
    } finally { await browser.close(); }
});
