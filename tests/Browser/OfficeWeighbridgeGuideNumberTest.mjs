import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { extname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';

// Administración corrige el número de una guía de ingreso mal digitada, en cualquier etapa.
const root = fileURLToPath(new URL('../../', import.meta.url));
const html = readFileSync(resolve(root, 'storage/app/ui/oficina/romana.html'), 'utf8');
const types = [{ codigo: 'bins', nombre: 'Bins plástico', contiene_fruta: true }];
const season = { id: 'temporada', nombre: 'Temporada 2026', codigo: 'TEMP-2627', activa: true };
const guias = [{ id: 'g1', numero_guia_despacho: '4446', envases: [{ tipo_envase: 'bins', cantidad: 2 }] },
    { id: 'g2', numero_guia_despacho: '1050', envases: [{ tipo_envase: 'bins', cantidad: 34 }] }];
const reception = { id: 'recepcion', numero_recepcion: 'REC-2611-0060', temporada: season, cliente: { id: 'cliente', nombre: 'FALCO FRUIT' },
    tipo_recepcion: 'fruta_con_envases', tipo_servicio: 'proceso', estado: 'cerrado', estado_validacion_mp: 'validada', version: 6,
    numero_guia_despacho: '4446 / 1050', guias, eventos: [], salida_envases: [], inspecciones_envases: [], peso_bruto: 24840, peso_tara: 16790, peso_neto: 8050,
    ingreso_at: '2026-11-13T12:00:00Z', patente_camion: 'ABCD12', nombre_conductor: 'Conductor', rut_conductor: '1-9',
    envases: [{ tipo_envase: 'bins', propiedad: 'arrendada', cantidad_declarada: 36, cantidad_validada: 36, cantidad_vacia: 0, cantidad_con_fruta: 36, diferencia: 0 }] };

async function abrir(browser, width, rol) {
    const context = await browser.newContext({ viewport: { width, height: 900 } });
    const identity = { id: 1, nombre: 'Usuario', rol, puede_consultar_romana: true, puede_operar_romana: true, modulos_acceso: ['materia-prima.romana'] };
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
        if (path === '/api/romana/recepciones/recepcion/numero-guia' && request.method() === 'PUT') {
            state.writes.push(request.postDataJSON());
            state.reception = { ...reception, version: 7, numero_guia_despacho: '4447 / 1050', guias: [{ ...guias[0], numero_guia_despacho: '4447' }, guias[1]],
                eventos: [{ tipo: 'numero_guia_corregido', usuario: { nombre: 'Administración' }, estado_anterior: 'cerrado', estado_nuevo: 'cerrado',
                    ocurrido_at: '2026-11-14T03:00:00Z', datos: { motivo: 'Se digitó 4446; la guía física dice 4447.', resumen: 'Guía 4446 → 4447' } }] };
            return route.fulfill({ json: { data: state.reception } });
        }
        if (path === '/api/romana/recepciones') return route.fulfill({ json: { data: [state.reception], resumen: {}, meta: { pagina_actual: 1, ultima_pagina: 1, total: 1 } } });
        if (path === '/api/romana/recepciones/recepcion') return route.fulfill({ json: { data: state.reception } });
        return route.fulfill({ json: {} });
    });
    const page = await context.newPage(); page.on('pageerror', (error) => state.errors.push(error.message));
    await page.goto('http://localhost/oficina/romana');
    await page.locator('#officeLoading').waitFor({ state: 'hidden' });
    await page.locator('tr.rec-row[data-reception-id="recepcion"]').click();
    await page.locator('#detailTitle').filter({ hasText: 'REC-2611-0060' }).waitFor();
    return { context, page, state };
}

test('Administración corrige el número de una guía de ingreso de una recepción cerrada', async () => {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
    try {
        const operador = await abrir(browser, 1280, 'operador_romana');
        assert.equal(await operador.page.locator('#guideNumberButton').isVisible(), false, 'Solo administración corrige la guía.');
        await operador.context.close();
        for (const width of [390, 1280]) {
            const { context, page, state } = await abrir(browser, width, 'administrador');
            await page.locator('#guideNumberButton').click();
            const dialog = page.locator('#guideNumberDialog');
            await dialog.locator('#guideNumberForm').waitFor();
            assert.equal(await dialog.locator('[data-guide-id="g1"]').inputValue(), '4446');
            assert.equal(await dialog.locator('[data-guide-id="g2"]').inputValue(), '1050');
            await dialog.locator('[name="motivo"]').fill('Se digitó 4446; la guía física dice 4447.');
            await dialog.locator('button[type="submit"]').click();
            await dialog.locator('#guideNumberError').filter({ hasText: 'no presentan cambios' }).waitFor();
            await dialog.locator('[data-guide-id="g1"]').fill('4447');
            await dialog.locator('button[type="submit"]').click();
            await page.waitForFunction(() => !document.getElementById('guideNumberDialog').open);
            assert.equal(state.writes.length, 1);
            assert.deepEqual({ ...state.writes[0], operacion_id: typeof state.writes[0].operacion_id }, {
                operacion_id: 'string', version_conocida: 6, motivo: 'Se digitó 4446; la guía física dice 4447.',
                guias: [{ id: 'g1', numero_guia_despacho: '4447' }, { id: 'g2', numero_guia_despacho: '1050' }] });
            await page.locator('#detailTimeline').filter({ hasText: 'Guía 4446 → 4447' }).waitFor({ state: 'attached' });
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), true);
            assert.deepEqual(state.errors, []);
            await context.close();
        }
    } finally { await browser.close(); }
});
