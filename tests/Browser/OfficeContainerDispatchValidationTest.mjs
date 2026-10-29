import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
const read = path => readFileSync(resolve(root, path), 'utf8');
const plateError = 'Patente: ingresa entre 5 y 8 letras mayúsculas o números, sin espacios ni guiones. Ejemplo: ABCD12.';
const clientError = 'Cliente destino: selecciona un cliente activo de la lista.';
const stockError = 'No existe disponibilidad suficiente de bins propia; faltan 1 unidades.';
const navigation = '<div id="officeUserName"></div><div id="officeUserRole"></div><div id="officeInitials"></div><button id="officeLogoutButton">Salir</button>';
const html = read('resources/views/office/container-dispatches.blade.php')
    .replace(/@if\([\s\S]*?@endif/, '')
    .replace(/@include\([^\n]+\)/g, '')
    .replace(/<x-office\.navigation[^>]*\/>/, navigation)
    .replace('</head>', `<style>${read('resources/css/office.css')}\n${read('resources/css/office-container-dispatches.css')}</style></head>`)
    .replace('</body>', '<script type="module" src="/resources/js/office-container-dispatches.js"></script></body>');

test('despacho de envases muestra todos los errores, conserva los datos y permite corregir y reservar', async () => {
    const browser = await chromium.launch({
        executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined,
        args: ['--no-sandbox', '--disable-gpu'],
    });
    const page = await browser.newPage({ viewport: { width: 1366, height: 900 } });
    const pageErrors = [];
    const submissions = [];
    page.on('pageerror', error => pageErrors.push(error.message));
    await page.addInitScript(() => {
        localStorage.setItem('estiba_wms_office_token', 'token');
        localStorage.setItem('estiba_wms_office_identity', JSON.stringify({
            nombre: 'Operador Romana', rol: 'operador_romana',
            puede_consultar_cuenta_envases: true, puede_gestionar_despacho_envases: true,
        }));
    });
    await page.route('http://localhost/**', async route => {
        const request = route.request();
        const path = new URL(request.url()).pathname;
        if (path === '/test') return route.fulfill({ contentType: 'text/html', body: html });
        if (path.startsWith('/resources/js/')) {
            return route.fulfill({ contentType: 'text/javascript', body: read(path.slice(1)) });
        }
        if (path === '/api/envases/catalogo') {
            return route.fulfill({ json: { data: [{ codigo: 'bins', nombre: 'Bins plástico' }] } });
        }
        if (path === '/api/envases/guias-despacho/catalogos') {
            return route.fulfill({ json: {
                clientes: [{ id: 'cliente-1', codigo: 'C1', nombre: 'Cliente Uno' }, { id: 'cliente-2', codigo: 'C2', nombre: 'Cliente Dos' }],
                origenes: [], inventario: [{ fisico: 20, reservado: 0, disponible: 20 }],
            } });
        }
        if (path === '/api/envases/guias-despacho' && request.method() === 'POST') {
            submissions.push(request.postDataJSON());
            if (submissions.length === 1) {
                return route.fulfill({ status: 422, json: {
                    message: plateError, errors: { cliente_id: [clientError], patente_camion: [plateError] },
                } });
            }
            if (submissions.length === 2) return route.fulfill({ status: 409, json: { message: stockError } });
            return route.fulfill({ status: 201, json: { data: { numero: 'GDE-2610-0001' } } });
        }
        if (path === '/api/envases/guias-despacho') return route.fulfill({ json: { data: [] } });
        return route.fulfill({ status: 404, body: '' });
    });
    try {
        await page.goto('http://localhost/test');
        const field = name => page.locator(`#dispatchForm [name="${name}"]`);
        await field('cantidad_bins').waitFor();
        assert.match(await page.locator('#dispatchPlateHelp').textContent(), /sin espacios ni guiones/);
        assert.equal(await field('patente_camion').getAttribute('aria-describedby'), 'dispatchPlateHelp');
        await field('cliente_id').selectOption('cliente-1');
        await field('patente_camion').fill('AB-CD-12');
        await field('nombre_conductor').fill('Conductor de prueba');
        await field('observacion').fill('Entrega de envases para producción');
        await field('cantidad_bins').fill('6');
        await page.locator('#saveDraftButton').click();
        await page.waitForFunction(() => document.querySelector('#dispatchError').textContent.includes('Patente:'));
        assert.equal(await page.locator('#dispatchError').textContent(), `${clientError}\n${plateError}`);
        assert.equal(await page.locator('#dispatchError').getAttribute('role'), 'alert');
        assert.equal(await page.locator('#dispatchError').evaluate(element => getComputedStyle(element).whiteSpace), 'pre-line');
        assert.equal(await field('cliente_id').inputValue(), 'cliente-1');
        assert.equal(await field('patente_camion').inputValue(), 'AB-CD-12');
        assert.equal(await field('cantidad_bins').inputValue(), '6');
        assert.equal(await field('nombre_conductor').inputValue(), 'Conductor de prueba');
        assert.equal(await field('observacion').inputValue(), 'Entrega de envases para producción');

        await field('cliente_id').selectOption('cliente-2');
        await field('patente_camion').fill('abcd12');
        await page.locator('#saveDraftButton').click();
        await page.waitForFunction(() => document.querySelector('#dispatchError').textContent.includes('disponibilidad suficiente'));
        assert.equal(await page.locator('#dispatchError').textContent(), stockError);
        assert.equal(await field('cantidad_bins').inputValue(), '6');

        await field('cantidad_bins').fill('5');
        await page.locator('#saveDraftButton').click();
        await page.waitForFunction(() => document.querySelector('#officeToasts').textContent.includes('GDE-2610-0001 creada'));
        assert.equal(submissions.length, 3);
        assert.equal(submissions[2].patente_camion, 'ABCD12');
        assert.equal(submissions[2].cliente_id, 'cliente-2');
        assert.equal(submissions[2].detalles[0].cantidad, 5);
        assert.equal(submissions[2].nombre_conductor, 'Conductor de prueba');
        assert.equal(submissions[2].observacion, 'Entrega de envases para producción');
        assert.equal(await page.locator('#dispatchError').textContent(), '');
        assert.equal(await field('cantidad_bins').inputValue(), '0');
        assert.deepEqual(pageErrors, []);
    } finally {
        await browser.close();
    }
});
