import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { extname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
const html = readFileSync(resolve(root, 'storage/app/ui/oficina/materia-prima-lotes.html'), 'utf8');

test('digitación: el selector de predios conserva el titular y envía el CSG elegido', async () => {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
    try {
        const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
        const identity = { id: 1, nombre: 'Digitadora', rol: 'digitador_materia_prima', puede_consultar_materia_prima: true,
            puede_gestionar_lotes_materia_prima: true, modulos_acceso: ['materia-prima.digitacion'] };
        await context.addInitScript((user) => {
            localStorage.setItem('estiba_wms_office_token', 'fixture');
            localStorage.setItem('estiba_wms_office_identity', JSON.stringify(user));
        }, identity);

        const temporada = { id: 't1', codigo: 'TEMP-2627', nombre: 'Temporada 2026' };
        const especie = { id: 'cereza', nombre: 'Cereza', variedades: [{ id: 'santina', nombre: 'Santina' }] };
        const csg = {
            id: 'csg-1', codigo: '151149', predio: 'SANTA SUSANA', razon_social: 'AGRÍCOLA EJEMPLO SPA',
            titular_predios: { rut_hash: 'titular-1', razon_social: 'agrícola ejemplo spa' }, cliente_ids: ['exportadora'],
            variedad_ids: ['santina'],
        };
        const otroPredio = { ...csg, id: 'csg-2', codigo: '151150', predio: 'LAS BRISAS' };
        const catalogo = {
            temporada, csg: [
                csg, otroPredio,
                { ...csg, id: 'csg-otro-cliente', codigo: '151151', predio: 'LOS BOLDOS', cliente_ids: ['otra'] },
                { ...csg, id: 'csg-otra-variedad', codigo: '151152', predio: 'LOS MAITENES', variedad_ids: ['lapins'] },
                { ...csg, id: 'csg-otro-rut', codigo: '151153', predio: 'EL ROBLE',
                    titular_predios: { ...csg.titular_predios, rut_hash: 'otro-rut' } },
            ], especies: [especie], tipos_producto: ['materia_prima'], camaras: [],
        };
        const cliente = { id: 'exportadora', nombre: 'Exportadora' };
        const recepcion = { numero_recepcion: 'REC-2611-0017', numero_guia_despacho: '4443', cliente,
            peso_neto_por_envase: 180, reparto_neto_envases: [{ tipo_envase: 'bins', peso_neto_unitario: 180 }] };
        const segmento = { id: 'segmento-1', estado: 'pendiente_lote', secuencia: 1, csg,
            variedad: { ...especie.variedades[0], especie_id: especie.id }, recepcion,
            envases: [{ tipo_envase: 'bins', cantidad: 48, cantidad_disponible: 48 }], neto_estimado: 8640 };
        const writes = [];
        const errors = [];
        let lote = null;
        await context.route('http://localhost/**', async (route) => {
            const request = route.request();
            const { pathname: path } = new URL(request.url());
            if (path === '/oficina/materia-prima/lotes') return route.fulfill({ body: html, contentType: 'text/html' });
            if (path.startsWith('/build/') || path.startsWith('/brand/')) {
                try {
                    const contentType = ({ '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml', '.woff2': 'font/woff2' })[extname(path)];
                    return route.fulfill({ body: readFileSync(resolve(root, `public${path}`)), contentType });
                } catch {
                    return route.fulfill({ status: 404, body: '' });
                }
            }
            if (path === '/api/acceso-oficina') return route.fulfill({ json: { usuario: identity } });
            if (path === '/api/oficina/contexto') return route.fulfill({ json: { data: { temporada } } });
            if (path === '/api/envases/catalogo') return route.fulfill({ json: { data: [{ codigo: 'bins', nombre: 'Bins plástico', contiene_fruta: true }] } });
            if (path === '/api/materia-prima/resumen') return route.fulfill({ json: { temporada, lotes: {} } });
            if (path === '/api/materia-prima/catalogos') return route.fulfill({ json: catalogo });
            if (path === '/api/materia-prima/segmentos-pendientes') return route.fulfill({ json: { data: [segmento] } });
            if (path === '/api/materia-prima/lotes' && request.method() === 'POST') {
                const payload = request.postDataJSON();
                writes.push(payload);
                lote = { id: 'lote-1', numero_lote: payload.numero_lote, estado: 'borrador', version: 1,
                    segmento, recepcion, cliente, envases: { detalle: [] }, pesos: {},
                    trazabilidad: { ...payload, csg_id: otroPredio.id, csg: otroPredio.codigo, predio: otroPredio.predio,
                        razon_social: otroPredio.razon_social, especie: 'Cereza', variedad: 'Santina',
                        especie_id: payload.especie_validacion_id, variedad_id: payload.variedad_validacion_id },
                };
                return route.fulfill({ status: 201, json: { data: lote } });
            }
            if (path === '/api/materia-prima/lotes') return route.fulfill({
                json: { data: lote ? [lote] : [], meta: { current_page: 1, last_page: 1, total: Number(Boolean(lote)) } },
            });
            return route.fulfill({ json: {} });
        });
        const page = await context.newPage();
        page.on('pageerror', (error) => errors.push(error.message));
        await page.goto('http://localhost/oficina/materia-prima/lotes');
        await page.getByRole('button', { name: '+ Crear lote' }).click();
        const form = page.locator('#lotForm');
        const codigo = form.getByLabel('CSG *');
        const predio = form.getByLabel('Predio *', { exact: true });
        assert.match(await codigo.locator('option:checked').innerText(), /151149 · AGRÍCOLA EJEMPLO SPA/);
        assert.equal(await codigo.isDisabled(), true);
        assert.equal(await predio.inputValue(), csg.id);
        assert.deepEqual(await predio.locator('option').allTextContents(), ['SANTA SUSANA · CSG 151149', 'LAS BRISAS · CSG 151150']);
        await predio.selectOption(otroPredio.id);
        assert.equal(await codigo.inputValue(), otroPredio.id);
        await form.getByLabel('Número de lote *').fill('LOTE-151150');
        await form.getByLabel('SdP *').fill('00000');
        await form.getByRole('button', { name: 'Guardar borrador' }).click();
        await page.waitForFunction(() => !document.getElementById('lotDialog').open);
        assert.equal(writes.length, 1);
        assert.equal(writes[0].csg_validacion_id, otroPredio.id);
        assert.equal(writes[0].predio_csg_validacion_id, otroPredio.id);
        assert.equal(writes[0].segmento_validacion_mp_id, segmento.id);
        assert.equal(writes[0].predio, undefined);

        const row = page.locator('#lotTableBody tr').filter({ hasText: 'LOTE-151150' });
        await row.getByText('AGRÍCOLA EJEMPLO SPA').waitFor();
        assert.match(await row.innerText(), /LAS BRISAS/);
        await row.locator('select.office-action-select').selectOption({ label: 'Editar' });
        assert.equal(await predio.inputValue(), otroPredio.id);
        assert.equal(await codigo.inputValue(), otroPredio.id);
        assert.deepEqual(errors, []);
    } finally {
        await browser.close();
    }
});
