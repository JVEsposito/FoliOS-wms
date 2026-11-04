import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { extname } from 'node:path';
import test from 'node:test';
import { chromium } from 'playwright';

const html = readFileSync(new URL('../../storage/app/ui/oficina/validacion.html', import.meta.url), 'utf8');
const contentTypes = { '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml', '.woff2': 'font/woff2' };
const identity = {
    nombre: 'Oficina prueba', rol: 'administrador',
    puede_consultar_validaciones_pallet: true, modulos_acceso: ['frigorifico.validacion'],
};
const temporada = { id: 'temporada-prueba', codigo: 'TEMP-2627', nombre: 'Temporada de prueba', activa: true };

test('Validación PT exporta el cliente y los filtros aplicados al historial', async () => {
    const browser = await chromium.launch({
        executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined,
        args: ['--no-sandbox', '--disable-gpu'],
    });
    const context = await browser.newContext({ viewport: { width: 1440, height: 940 } });
    await context.addInitScript((user) => {
        localStorage.setItem('estiba_wms_office_token', 'fixture');
        localStorage.setItem('estiba_wms_office_identity', JSON.stringify(user));
    }, identity);
    const exports = [];
    await context.route('http://localhost/**', async (route) => {
        const { pathname, searchParams } = new URL(route.request().url());
        if (pathname === '/oficina/validacion') return route.fulfill({ body: html, contentType: 'text/html' });
        if (pathname.startsWith('/build/')) {
            return route.fulfill({
                body: readFileSync(new URL(`../../public${pathname}`, import.meta.url)),
                contentType: contentTypes[extname(pathname)] || 'application/octet-stream',
            });
        }
        if (pathname === '/api/acceso-oficina') return route.fulfill({ json: { usuario: identity } });
        if (pathname === '/api/oficina/contexto') return route.fulfill({ json: { data: { temporada } } });
        if (pathname === '/api/validacion/registro/opciones') {
            return route.fulfill({ json: { temporada, temporadas: [temporada], clientes: ['DIS', 'FALCO FRUIT'] } });
        }
        if (pathname === '/api/validacion/registro/resumen') return route.fulfill({ json: {} });
        if (pathname === '/api/validacion/pallets') {
            const data = ['DIS', 'FALCO FRUIT']
                .filter((cliente) => !searchParams.has('cliente') || searchParams.get('cliente') === cliente)
                .map((cliente) => ({
                    numero_folio: cliente === 'DIS' ? 'PAL-DIS' : 'PAL-FALCO',
                    resultado: 'aprobado', estado: 'aceptada', cantidad_cajas: 20,
                    catalogo: { origen: { cliente } },
                }));
            return route.fulfill({ json: { data } });
        }
        if (pathname === '/api/validacion/registro/rrpp-01') {
            exports.push(Object.fromEntries(searchParams));
            const pdf = searchParams.get('formato') === 'pdf';
            return route.fulfill({
                headers: {
                    'Content-Type': pdf ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'Content-Disposition': `attachment; filename="validaciones.${pdf ? 'pdf' : 'xlsx'}"`,
                },
                body: 'fixture',
            });
        }
        return route.fulfill({ status: 404, json: {} });
    });
    try {
        const page = await context.newPage();
        await page.goto('http://localhost/oficina/validacion');
        const client = page.getByRole('combobox', { name: 'Cliente', exact: true });
        await client.selectOption('FALCO FRUIT');
        await page.getByLabel('Fecha de validación').fill('2026-11-03');
        await page.locator('#validationFilters [name="estado"]').selectOption('aceptada');
        await page.getByRole('button', { name: 'Filtrar', exact: true }).click();
        await page.getByText('Cliente: FALCO FRUIT', { exact: true }).waitFor();
        assert.equal(await page.getByText('PAL-DIS', { exact: true }).count(), 0);
        assert.equal(await page.getByText('PAL-FALCO', { exact: true }).count(), 1);

        // Cambiar el selector sin aplicar conserva el alcance que muestra la tabla.
        await client.selectOption('DIS');
        const expected = {
            cliente: 'FALCO FRUIT', fecha: '2026-11-03', estado: 'aceptada', temporada_id: temporada.id,
        };
        for (const [name, formato] of [['Descargar PDF', 'pdf'], ['RRPP-01 (Excel)', 'xlsx']]) {
            const download = page.waitForEvent('download');
            await page.getByRole('button', { name, exact: true }).click();
            assert.equal((await download).suggestedFilename(), `validaciones.${formato}`);
            assert.deepEqual(exports.at(-1), { ...expected, formato });
        }
    } finally {
        await browser.close();
    }
});
