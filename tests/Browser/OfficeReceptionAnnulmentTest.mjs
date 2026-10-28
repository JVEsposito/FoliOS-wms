import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve, extname } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
const html = readFileSync(resolve(root, 'storage/app/ui/oficina/romana.html'), 'utf8');

test('administrador anula con motivo, conserva el intento al fallar y consulta el historial', async () => {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
    try {
        for (const width of [390, 1280]) {
            const context = await browser.newContext({ viewport: { width, height: 1000 } });
            const identity = { id: 1, nombre: 'Administrador', rol: 'administrador', puede_consultar_romana: true, puede_anular_recepciones_romana: true, modulos_acceso: ['materia-prima.romana'] };
            await context.addInitScript((user) => {
                Object.defineProperty(Crypto.prototype, 'randomUUID', { value: undefined });
                localStorage.setItem('estiba_wms_office_token', 'fixture');
                localStorage.setItem('estiba_wms_office_identity', JSON.stringify(user));
            }, identity);
            const reception = { id: 'recepcion', numero_recepcion: 'REC-2609-0023', numero_guia_despacho: '2081', tipo_recepcion: 'solo_envases', concepto_envases: 'arriendo',
                estado: 'cerrado', estado_validacion_mp: 'pendiente', version: 3, cliente: { id: 'cliente', nombre: 'Agro Rosario' }, temporada: { id: 'temporada', nombre: '2026', codigo: '2026' },
                tipo_envase_declarado: 'tote', cantidad_envases_declarados: 1393, envases: [{ tipo_envase: 'tote', cantidad_declarada: 1393, cantidad_validada: null }],
                patente_camion: 'WDWK38', nombre_conductor: 'Conductor', ingreso_at: '2026-09-22T20:22:00Z', salida_at: '2026-09-22T20:30:00Z',
                puede_anular: true, puede_editar: false, eventos: [], inspecciones_envases: [], salida_envases: [], reparto_neto_envases: null };
            const writes = []; const errors = []; let annulled = false;
            await context.route('http://localhost/**', async (route) => {
                const req = route.request(); const url = new URL(req.url()); const path = url.pathname;
                if (path === '/oficina/romana') return route.fulfill({ body: html, contentType: 'text/html' });
                if (path.startsWith('/build/') || path.startsWith('/brand/')) {
                    try { return route.fulfill({ body: readFileSync(resolve(root, `public${path}`)), contentType: ({ '.js': 'text/javascript', '.css': 'text/css', '.woff2': 'font/woff2', '.svg': 'image/svg+xml' })[extname(path)] || 'application/octet-stream' }); }
                    catch { return route.fulfill({ status: 404, body: '' }); }
                }
                if (path === '/api/acceso-oficina') return route.fulfill({ json: { usuario: identity } });
                if (path === '/api/oficina/contexto') return route.fulfill({ json: { data: { planta: 'Prueba', temporada: reception.temporada } } });
                if (path === '/api/envases/catalogo') return route.fulfill({ json: { data: [{ codigo: 'tote', nombre: 'Tote', contiene_fruta: true }] } });
                if (path === '/api/romana/porteria/resumen') return route.fulfill({ json: { data: { pendientes: 0, recientes: [], consultado_at: '2026-10-28T12:00:00Z' } } });
                if (path.endsWith('/romana/catalogos')) return route.fulfill({ json: { temporadas: [{ ...reception.temporada, activa: true }], clientes: [reception.cliente], especies: [], tipos_envase: [{ codigo: 'tote', nombre: 'Tote' }], tipos_servicio: [], tipos_recepcion: [], conceptos_envases: [] } });
                if (path.endsWith('/anular')) {
                    writes.push(req.postDataJSON());
                    if (writes.length === 1) return route.fulfill({ status: 503, json: { message: 'Intenta nuevamente con el mismo registro.' } });
                    annulled = true; reception.estado = 'anulada'; reception.puede_anular = false;
                    reception.eventos = [{ tipo: 'recepcion_anulada', usuario: { nombre: 'Administrador' }, estado_anterior: 'cerrado', estado_nuevo: 'anulada', ocurrido_at: '2026-10-28T12:00:00Z', datos: { motivo: writes[1].motivo } }];
                    return route.fulfill({ json: { data: reception } });
                }
                if (path === '/api/romana/recepciones/recepcion') return route.fulfill({ json: { data: reception } });
                if (path === '/api/romana/recepciones') {
                    const rows = annulled === (url.searchParams.get('estado') === 'anulada') ? [reception] : [];
                    return route.fulfill({ json: { data: rows, resumen: {}, meta: { pagina_actual: 1, ultima_pagina: 1, por_pagina: 30, total: rows.length } } });
                }
                return route.fulfill({ json: {} });
            });
            const page = await context.newPage(); page.on('pageerror', (e) => errors.push(e.message));
            await page.goto('http://localhost/oficina/romana');
            await page.locator('tr.rec-row[data-reception-id="recepcion"]').click();
            await page.locator('#annulReceptionButton').click();
            await page.locator('#annulReceptionForm [name="motivo"]').fill('Recepción de prueba ingresada por equivocación.');
            await page.locator('#annulReceptionForm [type="submit"]').click();
            await page.waitForFunction(() => document.getElementById('annulReceptionError').textContent.includes('Intenta nuevamente'));
            await page.locator('#annulReceptionForm [type="submit"]').click();
            await page.waitForFunction(() => !document.getElementById('annulReceptionDialog').open);
            assert.deepEqual(writes[0], writes[1]);
            assert.equal(writes[0].version_conocida, 3);
            await page.locator('#receptionFilters [name="estado"]').selectOption('anulada');
            await page.locator('#receptionFilters [type="submit"]').click();
            await page.locator('tr.rec-row[data-reception-id="recepcion"]').click();
            assert.match(await page.locator('#detailTimeline').textContent(), /Recepción de prueba ingresada por equivocación/);
            assert.equal(await page.locator('#annulReceptionButton').isHidden(), true);
            assert.deepEqual(errors, []);
            await context.close();
        }
    } finally { await browser.close(); }
});
