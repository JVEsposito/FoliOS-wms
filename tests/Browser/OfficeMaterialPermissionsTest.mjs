import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
const exported = readFileSync(resolve(root, 'storage/app/ui/oficina/accesos.html'), 'utf8');
const form = exported.match(/<form\b[^>]*id="accessProfileForm"[^>]*>[\s\S]*?<\/form>/)?.[0];
assert.ok(form, 'El formulario de perfiles debe formar parte de Oficina');
const html = exported.replace(/<script\b[^>]*>[\s\S]*?<\/script>/g, '').replace(/<body\b[^>]*>[\s\S]*<\/body>/,
    `<body><main style="padding:12px;max-width:1100px;margin:auto">${form}<p id="accessProfilesSummary"></p><table><tbody id="accessProfilesTableBody"></tbody></table></main><script type="module" src="/profiles.js"></script></body>`);
const modules = ['materiales.etiquetas', 'materiales.inventario'];
const groups = [
    { clave: 'recepciones', nombre: 'Recepciones', modulos: ['materiales.etiquetas'], preparado: false, acciones: [
        { clave: 'recepciones.gestionar', nombre: 'Crear borradores' }, { clave: 'recepciones.confirmar', nombre: 'Confirmar' }, { clave: 'recepciones.eliminar', nombre: 'Eliminar' },
    ] },
    { clave: 'inventario', nombre: 'Inventario', modulos: ['materiales.inventario'], preparado: false,
        acciones: [{ clave: 'inventario.transferir_cliente', nombre: 'Transferir entre clientes' }] },
];

test('perfiles guarda acciones independientes, quita dependencias y deshabilita escrituras en Solo consulta', async () => {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
    try {
        for (const width of [1366, 390]) {
            const context = await browser.newContext({ viewport: { width, height: 900 } });
            const page = await context.newPage(); const writes = []; const errors = [];
            page.on('pageerror', error => errors.push(error.message));
            let profile = { id: 'profile', codigo: 'BODEGA', nombre: 'Bodega', rol_base: 'supervisor_materiales', rol_base_nombre: 'Supervisor',
                modulos: modules, modulos_tablet: [], permisos_materiales: groups.flatMap(g => g.acciones.map(a => a.clave)),
                activo: true, solo_consulta: false, usuarios_count: 1, protegido: false };
            await context.addInitScript(() => localStorage.setItem('estiba_wms_office_token', 'permission-token'));
            await context.route('http://localhost/**', async route => {
                const request = route.request(), path = new URL(request.url()).pathname;
                if (path.startsWith('/build/')) return route.fulfill({ body: readFileSync(resolve(root, `public${path}`)), contentType: path.endsWith('.css') ? 'text/css' : 'text/javascript' });
                if (path === '/test') return route.fulfill({ contentType: 'text/html', body: html });
                if (path === '/profiles.js') return route.fulfill({ contentType: 'text/javascript', body: readFileSync(resolve(root, 'resources/js/office-access-profiles.js')) });
                if (request.method() === 'PUT') {
                    const data = request.postDataJSON(); writes.push(data); profile = { ...profile, ...data };
                    return route.fulfill({ json: { data: profile, sesiones_revocadas: true } });
                }
                return route.fulfill({ json: { data: [{ ...profile, permisos_materiales_efectivos: profile.permisos_materiales }],
                    catalogo: [{ clave: 'materiales', nombre: 'Materiales', descripcion: '', modulos: modules.map(clave => ({ clave, nombre: clave, descripcion: '' })) }],
                    catalogo_tablet: [], catalogo_permisos_materiales: groups, pantallas_inicio: [],
                    roles_base: ['supervisor_materiales', 'consulta'].map(clave => ({ clave, nombre: clave, modulos_disponibles: modules, modulos_sugeridos: modules, modulos_tablet_sugeridos: [] })),
                } });
            });
            await page.goto('http://localhost/test');
            const edit = page.locator('[data-edit-access-profile]');
            await edit.click();
            const permission = key => page.locator(`[name="permisos_materiales[]"][value="${key}"]`);
            assert.equal(await permission('recepciones.confirmar').isChecked(), true);
            await permission('recepciones.confirmar').uncheck();
            await permission('recepciones.eliminar').uncheck();
            await page.locator('[name="modulos[]"][value="materiales.inventario"]').uncheck();
            assert.equal(await permission('inventario.transferir_cliente').isChecked(), false);
            assert.equal(await permission('inventario.transferir_cliente').isDisabled(), true);
            await page.locator('#accessProfileForm button[type="submit"]').click();
            await page.waitForFunction(() => document.getElementById('accessProfileForm').elements.id.value === '');
            assert.deepEqual(writes[0].permisos_materiales, ['recepciones.gestionar']);
            await edit.click();
            assert.equal(await permission('recepciones.gestionar').isChecked(), true);
            await page.locator('[name="solo_consulta"]').selectOption('1');
            assert.equal(await permission('recepciones.gestionar').isDisabled(), true);
            assert.equal(await permission('recepciones.gestionar').isChecked(), false);
            await page.locator('#accessProfileForm button[type="submit"]').click();
            await page.waitForFunction(() => document.getElementById('accessProfileForm').elements.id.value === '');
            assert.deepEqual(writes[1].permisos_materiales, []);
            assert.equal(writes[1].rol_base, 'consulta');
            assert.deepEqual(errors, []);
            await context.close();
        }
    } finally { await browser.close(); }
});
