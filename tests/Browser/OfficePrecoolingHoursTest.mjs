import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { extname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
const html = readFileSync(resolve(root, 'storage/app/ui/oficina/prefrio.html'), 'utf8');
const id = '11111111-1111-4111-8111-111111111111';
const fixture = () => ({
    id, codigo: 'PF-2026-000048', estado: 'aprobado', version: 7, setpoint: -1.5,
    tunel: { id: 't1', codigo: 'TUN-01', nombre: 'Túnel 1', capacidad_posiciones: 20 },
    iniciado_at: '2026-11-17T22:00:00+00:00', finalizado_at: '2026-11-18T05:00:00+00:00',
    folios: [{ id: 'a1', estado: 'aprobado', en_tunel: false, cargado_at: '2026-11-17T21:00:00+00:00', finalizado_parcial_at: '2026-11-18T01:00:00+00:00', retirado_at: '2026-11-18T01:10:00+00:00', folio: { id: 'f1', numero_folio: '6000000941' }, posicion: { id: 'p1', numero: 1, etiqueta: 'P01' } }],
    eventos: [
        { id: 'e1', tipo: 'proceso_iniciado', ocurrido_at: '2026-11-17T22:00:00+00:00', datos: {} },
        { id: 'e2', tipo: 'finalizacion_parcial', ocurrido_at: '2026-11-18T01:00:00+00:00', datos: { resultados: [{ folio_id: 'f1' }] } },
        { id: 'e3', tipo: 'retiro_parcial_confirmado', ocurrido_at: '2026-11-18T01:10:00+00:00', datos: { folios: ['f1'] } },
        { id: 'e4', tipo: 'correccion_administrativa', ocurrido_at: '2026-11-18T12:00:00+00:00', datos: { motivo: 'Registro anterior' } },
    ],
});

async function open(browser, role) {
    const context = await browser.newContext({ viewport: { width: 1366, height: 900 }, timezoneId: 'UTC' });
    await context.addInitScript((rol) => {
        localStorage.setItem('estiba_wms_office_token', 'fixture');
        localStorage.setItem('estiba_wms_office_identity', JSON.stringify({ nombre: 'Prueba', rol, puede_consultar_prefrio: true }));
    }, role);
    const process = fixture(); const posts = []; const errors = [];
    await context.route('http://localhost/**', async (route) => {
        const path = new URL(route.request().url()).pathname;
        if (path === '/oficina/prefrio') return route.fulfill({ contentType: 'text/html', body: html });
        if (path.startsWith('/build/') || path.startsWith('/brand/')) {
            try { return route.fulfill({ body: readFileSync(resolve(root, `public${path}`)), contentType: ({ '.js': 'text/javascript', '.css': 'text/css', '.woff2': 'font/woff2', '.svg': 'image/svg+xml' })[extname(path)] || 'application/octet-stream' }); }
            catch { return route.fulfill({ status: 404, body: '' }); }
        }
        if (path === '/api/prefrio/tuneles') return route.fulfill({ json: { data: [] } });
        if (path === '/api/prefrio/procesos') return route.fulfill({ json: { data: [process] } });
        if (path === `/api/prefrio/procesos/${id}`) return route.fulfill({ json: { data: process } });
        if (path.endsWith('/corregir-horas')) {
            posts.push(route.request().postDataJSON());
            if (posts.length === 1) return route.abort('connectionreset');
            return route.fulfill({ json: { data: { ...process, version: 8 } } });
        }
        return route.fulfill({ json: {} });
    });
    const page = await context.newPage(); page.on('pageerror', (error) => errors.push(error.message));
    await page.goto(`http://localhost/oficina/prefrio?proceso=${id}`);
    await page.locator('#processDetail:not(.is-hidden)').waitFor();
    return { context, page, posts, errors };
}

test('administración corrige solo horas en Chile, incluye parciales y reintenta la misma operación', async () => {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
    try {
        const { context, page, posts, errors } = await open(browser, 'administrador');
        await page.getByRole('button', { name: 'Corregir horas', exact: true }).click();
        assert.equal(await page.locator('#hoursEvents input').count(), 3, 'La auditoría anterior no se reescribe');
        assert.match(await page.locator('[data-hour-event="e1"] input').inputValue(), /^2026-11-17T19:00(?::00)?$/);
        assert.equal(await page.locator('#hoursFolios input[name="retirado_at"]').count(), 0, 'El retiro con evento tiene un único editor');
        await page.locator('[data-hour-event="e2"] input').fill('2026-11-17T21:45');
        await page.locator('#hoursForm textarea[name="motivo"]').fill('Hora real según registro del turno anterior.');
        await page.getByRole('button', { name: 'Guardar horas reales', exact: true }).click();
        await page.locator('#hoursError').filter({ hasText: 'conectar' }).waitFor();
        await page.getByRole('button', { name: 'Guardar horas reales', exact: true }).click();
        await page.locator('#hoursDialog').waitFor({ state: 'hidden' });
        assert.equal(posts.length, 2);
        assert.deepEqual(posts[0], posts[1]);
        assert.deepEqual(posts[1].eventos, [{ id: 'e2', ocurrido_at: '2026-11-17T21:45' }]);
        assert.deepEqual(posts[1].folios, []);
        assert.equal(posts[1].proceso, undefined);
        assert.deepEqual(errors, []);
        await context.close();
        const supervisor = await open(browser, 'supervisor_frio');
        assert.ok(await supervisor.page.locator('#correctHoursButton').isHidden());
        assert.deepEqual(supervisor.errors, []);
        await supervisor.context.close();
    } finally { await browser.close(); }
});
