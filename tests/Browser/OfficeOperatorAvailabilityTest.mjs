import assert from 'node:assert/strict';
import { mkdirSync, readFileSync } from 'node:fs';
import { extname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
const panel = () => ({ data: {
    generado_at: new Date().toISOString(), jornada: { zona_horaria: 'America/Santiago' }, temporada: { id: 's1', codigo: '2026', nombre: 'Prueba' },
    sincronizacion: { operaciones_hoy: {} }, camaras: [], camareros: [], prefrio: { resumen: {}, tuneles: [] },
    incidencias: { resumen: {}, abiertas: [] }, planificador: { despliegue: { mode_global: 'guided' }, salud: { riesgos: {} }, arbitraje: { vigencia: { estado: 'actual' } } },
    planta: { configurado: false, catalogo: [], elementos: [] },
} });

for (const width of [1280, 390]) test(`Operación ahora anuncia una sola vez al camarero disponible y degrada la lectura sin conexión en ${width}px`, async () => {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
    try {
        const context = await browser.newContext({ viewport: { width, height: 950 } });
        await context.addInitScript(() => {
            localStorage.setItem('estiba_wms_office_token', 'fixture');
            localStorage.setItem('estiba_wms_office_identity', JSON.stringify({ nombre: 'Supervisor', rol: 'administrador' }));
        });
        let available = false; let failed = false; let reads = 0; let fullReads = 0;
        await context.route('http://localhost/**', async (route) => {
            const path = new URL(route.request().url()).pathname;
            if (path === '/oficina/operacion-ahora') return route.fulfill({ body: readFileSync(resolve(root, 'storage/app/ui/oficina/operacion-ahora.html')), contentType: 'text/html' });
            if (path.startsWith('/build/') || path.startsWith('/brand/')) {
                try { return route.fulfill({ body: readFileSync(resolve(root, `public${path}`)), contentType: ({ '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml', '.woff2': 'font/woff2' })[extname(path)] || 'application/octet-stream' }); }
                catch { return route.fulfill({ status: 404 }); }
            }
            if (path === '/api/operacion-ahora') { fullReads++; return route.fulfill({ json: panel() }); }
            if (path === '/api/operacion-ahora/camareros') {
                reads++;
                if (failed) return route.fulfill({ status: 503, json: { message: 'Servidor sin respuesta' } });
                return route.fulfill({ json: { data: { generado_at: new Date().toISOString(), temporada_id: 's1', disponibles: available ? 1 : 0, camareros: [{
                    usuario: { id: 1, nombre: 'Camarero Ana' }, dispositivo: { codigo: 'TABLET-01' }, ubicacion_actual: { tipo: 'sin_ubicacion' }, tarea_actual: null,
                    estado_presencia: available ? 'disponible' : 'trabajando', ultima_actividad_at: new Date().toISOString(), disponible_desde: available ? new Date().toISOString() : null,
                    aviso_disponible_id: available ? 'final-labor-1' : null,
                }] } } });
            }
            return route.fulfill({ json: { data: { planta: 'Prueba', temporada: null, avisos_cierre: [], avisos_materiales: [] } } });
        });
        const page = await context.newPage(); const errors = []; page.on('pageerror', (error) => errors.push(error.message));
        await page.goto('http://localhost/oficina/operacion-ahora#personal');
        await page.locator('#operatorAvailableCount').filter({ hasText: 'Disponibles: 0' }).waitFor();
        assert.equal(await page.locator('.toast').filter({ hasText: 'quedó disponible' }).count(), 0);
        await page.evaluate(() => {
            window.availabilityAlerts = 0;
            new MutationObserver((records) => {
                for (const record of records) for (const node of record.addedNodes) {
                    if (node.textContent?.includes('quedó disponible')) window.availabilityAlerts++;
                }
            }).observe(document.body, { childList: true, subtree: true });
        });
        available = true;
        await page.locator('#operatorAvailableCount').filter({ hasText: 'Disponibles: 1' }).waitFor({ timeout: 15000 });
        await page.locator('.toast').filter({ hasText: 'Camarero Ana terminó sus labores y quedó disponible' }).waitFor();
        assert.match(await page.locator('#operationOperatorList').innerText(), /Disponible desde/);
        assert.equal(await page.locator('[data-tab-badge="personal"]').innerText(), '1 disponible');
        const count = reads;
        await page.waitForResponse((response) => new URL(response.url()).pathname === '/api/operacion-ahora/camareros' && reads > count, { timeout: 15000 });
        assert.equal(await page.evaluate(() => window.availabilityAlerts), 1, 'el refresco no repite el aviso de finalización');
        mkdirSync(resolve(root, 'storage/app/ui/oficina/capturas'), { recursive: true });
        await page.screenshot({ path: resolve(root, `storage/app/ui/oficina/capturas/camareros-disponibles-${width}.png`), fullPage: true });
        failed = true;
        await page.locator('#operatorAvailableCount').filter({ hasText: 'sin confirmar' }).waitFor({ timeout: 15000 });
        assert.match(await page.locator('#operationOperatorList').innerText(), /Presencia sin confirmar/);
        assert.equal(fullReads, 1, 'el feed rápido no vuelve a cargar todo el tablero');
        assert.deepEqual(errors, []);
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), true);
        await context.close();
    } finally { await browser.close(); }
});
