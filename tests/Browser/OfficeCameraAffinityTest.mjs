import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve, extname } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
const html = readFileSync(resolve(root, 'storage/app/ui/oficina/camaras.html'), 'utf8');
const profile = (formato, pallets) => ({ cliente: 'Cliente fixture', marca: 'Marca fixture', formato, pallets });
const capacity = (occupied, reserved) => ({ fisica: 15, efectiva: 15, ocupadas: occupied, reservadas: reserved, disponibles: 15 - occupied - reserved, porcentaje: (occupied + reserved) / 15 * 100 });
const affinity = (profiles, incoming) => ({ activa: profiles.length > 0,
    cliente: { valor: 'Cliente fixture', pallets: 12 }, marca: { valor: 'Marca fixture', pallets: 12 }, formato: { valor: 'GFL50M', pallets: 11 },
    pallets_completos: profiles.reduce((n, p) => n + p.pallets, 0), perfiles_diferentes: profiles.length, perfiles: profiles,
    entrantes: { pallets_completos: incoming.reduce((n, p) => n + p.pallets, 0), perfiles: incoming }, fuera_alcance: 0 });
const camera = { id: 'cam-fixture', codigo: 'CAM-FIXTURE', nombre: 'Cámara fixture', contenido: 'productos', estado: 'activa', tipo: 'transito', version_plano: 3,
    dimensiones: { bandas: 2, posiciones_por_banda: 15, niveles: 1 }, ocupacion: { ocupadas: 12, reservadas: 2, efectivas: 30 } };
const bands = [
    { id: 'band-1', numero: 1, modo: 'operativa', estado: 'parcial', usos_permitidos: ['transito_pt'], acepta_nuevos_ingresos: true,
        capacidad: capacity(12, 1), afinidad: affinity([profile('GFL50M', 11), profile('MAFLV225A', 1)], [profile('MAFLV225A', 1)]) },
    { id: 'band-2', numero: 2, modo: 'operativa', estado: 'parcial', usos_permitidos: ['transito_pt'], acepta_nuevos_ingresos: true,
        capacidad: capacity(0, 1), afinidad: affinity([], [profile('MAFLV225A', 1)]) },
];
const plan = { ...camera, bandas_operacionales: bands, folios_sin_posicion: [],
    posiciones: bands.flatMap((band) => Array.from({ length: 15 }, (_, index) => ({ id: `pos-${band.numero}-${index + 1}`,
        etiqueta: `B0${band.numero}-P${index + 1}-N1`, banda: band.numero, posicion: index + 1, nivel: 1, estado: 'activa',
        ocupada: band.numero === 1 && index < 12, reservada: index === 12, folios: [] }))) };

for (const width of [1280, 390]) test(`Oficina muestra todos los envases y distingue reservas en ${width}px`, async () => {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
    try {
        const context = await browser.newContext({ viewport: { width, height: 900 } });
        await context.addInitScript(() => {
            localStorage.setItem('estiba_wms_office_token', 'fixture');
            localStorage.setItem('estiba_wms_office_identity', JSON.stringify({ nombre: 'Consulta fixture', rol: 'consulta', puede_consultar_camaras: true }));
        });
        await context.route('http://localhost/**', async (route) => {
            const path = new URL(route.request().url()).pathname;
            if (path === '/oficina/frigorifico/camaras') return route.fulfill({ body: html, contentType: 'text/html' });
            if (path.startsWith('/build/') || path.startsWith('/brand/')) {
                try { return route.fulfill({ body: readFileSync(resolve(root, `public${path}`)), contentType: ({ '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml', '.woff2': 'font/woff2' })[extname(path)] || 'application/octet-stream' }); }
                catch { return route.fulfill({ status: 404 }); }
            }
            assert.equal(route.request().method(), 'GET');
            if (path === '/api/camaras') return route.fulfill({ json: { data: [camera] } });
            if (path === `/api/camaras/${camera.id}/plano`) return route.fulfill({ json: { data: plan } });
            if (path === '/api/control-ambiental/estado') return route.fulfill({ json: { camaras: [] } });
            if (path === '/api/oficina/contexto') return route.fulfill({ json: { data: { planta: 'Fixture', temporada: null, avisos_cierre: [], avisos_materiales: [] } } });
            return route.fulfill({ json: { data: [] } });
        });
        const page = await context.newPage(); const errors = [];
        page.on('pageerror', (error) => errors.push(error.message));
        await page.goto('http://localhost/oficina/frigorifico/camaras');
        const mixed = page.locator('#cameraBandMap [data-operational-band="1"] .camera-ops__band-foot');
        await mixed.waitFor();
        assert.match(await mixed.innerText(), /GFL50M \(11 presentes\)/);
        assert.match(await mixed.innerText(), /MAFLV225A \(1 presente\)/);
        assert.match(await mixed.innerText(), /Entradas reservadas:.*MAFLV225A \(1\)/);
        const reserved = page.locator('#cameraBandMap [data-operational-band="2"]');
        await reserved.click();
        assert.match(await page.locator('#cameraBandDetail').innerText(), /Entradas reservadas:.*MAFLV225A/);
        assert.doesNotMatch(await reserved.locator('.camera-ops__band-foot').innerText(), /presentes/);
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
        assert.deepEqual(errors, []);
        await context.close();
    } finally { await browser.close(); }
});
