#!/usr/bin/env node
/*
 * Capturas de referencia de Oficina (regresión visual).
 *
 * Fotografía cada vista estática de Oficina (rutas Route::view) en los dos temas, tal como
 * el navegador la pinta antes de ejecutar los módulos de Vite: sesión simulada, sin red,
 * sin animaciones y sin datos. Es determinista, así que dos capturas del mismo código
 * coinciden píxel a píxel y cualquier diferencia viene del cambio que se está probando.
 *
 * Uso (después de `npm run build`):
 *   node scripts/visual/capturar-oficina.mjs storage/app/ui/capturas/antes
 *   ...aplicar el cambio y volver a compilar...
 *   node scripts/visual/capturar-oficina.mjs storage/app/ui/capturas/despues
 *   node scripts/visual/comparar-capturas.mjs storage/app/ui/capturas/antes storage/app/ui/capturas/despues
 *
 * Variables opcionales:
 *   SOLO=<regex>      limita las rutas (p. ej. SOLO=materiales).
 *   TEMAS=a,b         temas a capturar (por defecto light-professional,dark-industrial).
 *   PUERTO=<n>        puerto para `php artisan serve` (por defecto 8765).
 *   PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH  Chromium alternativo.
 *
 * Alcance: cubre la estructura, el shell, encabezados, formularios y estados iniciales.
 * Las filas, diálogos y estados que dibuja JavaScript con datos del servidor no aparecen;
 * esos se revisan con las pruebas de navegador (tests/Browser) y en el servidor de pruebas.
 */
import { spawn, execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const raiz = fileURLToPath(new URL('../../', import.meta.url));
const salida = path.resolve(process.argv[2] || path.join(raiz, 'storage/app/ui/capturas/actual'));
const puerto = Number(process.env.PUERTO || 8765);
const temas = (process.env.TEMAS || 'light-professional,dark-industrial').split(',');
const solo = process.env.SOLO ? new RegExp(process.env.SOLO) : null;
const origenLocal = `http://127.0.0.1:${puerto}`;
const origenCaptura = 'http://oficina.visual';

if (!fs.existsSync(path.join(raiz, 'public/build/manifest.json'))) {
    console.error('Falta public/build/manifest.json: ejecuta `npm run build` antes de capturar.');
    process.exit(1);
}

const rutas = JSON.parse(execFileSync('php', ['artisan', 'route:list', '--json', '--method=GET'], { cwd: raiz, encoding: 'utf8' }))
    .filter((ruta) => ruta.action === 'Illuminate\\Routing\\ViewController' && !ruta.uri.includes('{'))
    .map((ruta) => `/${ruta.uri.replace(/^\/+/, '')}`)
    .filter((ruta) => !solo || solo.test(ruta))
    .sort();

const servidor = spawn('php', ['artisan', 'serve', `--port=${puerto}`, '--no-reload'], { cwd: raiz, stdio: 'ignore' });
const paginas = {};
try {
    await esperarServidor();
    fs.mkdirSync(path.join(salida, 'html'), { recursive: true });
    for (const ruta of rutas) {
        const respuesta = await fetch(origenLocal + ruta, { redirect: 'manual' });
        if (respuesta.status !== 200) {
            console.warn(`omitida ${ruta}: HTTP ${respuesta.status}`);
            continue;
        }
        paginas[ruta] = (await respuesta.text()).replaceAll(origenLocal, origenCaptura);
        fs.writeFileSync(path.join(salida, 'html', `${nombreArchivo(ruta)}.html`), paginas[ruta]);
    }
} finally {
    servidor.kill();
}

const navegador = await chromium.launch({
    executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined,
    args: ['--no-sandbox', '--disable-gpu', '--font-render-hinting=none', '--disable-lcd-text'],
});
try {
    for (const tema of temas) {
        fs.mkdirSync(path.join(salida, tema), { recursive: true });
        const contexto = await navegador.newContext({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 1, reducedMotion: 'reduce' });
        await contexto.addInitScript((temaInicial) => {
            localStorage.setItem('estiba_wms_office_token', '41|capturaVisual');
            localStorage.setItem('estiba_wms_office_identity', JSON.stringify({ nombre: 'Captura Visual', rol: 'administrador' }));
            localStorage.setItem('estiba_wms_office_theme', temaInicial);
        }, tema);
        await contexto.route('**/*', (solicitud) => responder(solicitud));
        const pagina = await contexto.newPage();
        for (const ruta of Object.keys(paginas)) {
            await pagina.goto(origenCaptura + ruta, { waitUntil: 'load' });
            await pagina.evaluate(() => document.fonts.ready);
            await pagina.addStyleTag({ content: '*,*::before,*::after{animation:none!important;transition:none!important;caret-color:transparent!important}' });
            await pagina.screenshot({ path: path.join(salida, tema, `${nombreArchivo(ruta)}.png`), fullPage: true });
        }
        await contexto.close();
    }
} finally {
    await navegador.close();
}
console.log(`${Object.keys(paginas).length} vistas × ${temas.length} temas en ${path.relative(raiz, salida) || salida}`);

function responder(solicitud) {
    const url = new URL(solicitud.request().url());
    if (url.origin !== origenCaptura) return solicitud.abort();
    // Primer pintado: los módulos de Vite no se ejecutan (pedirían datos al servidor).
    if (url.pathname.endsWith('.js')) return solicitud.abort();
    if (paginas[url.pathname]) return solicitud.fulfill({ body: paginas[url.pathname], contentType: 'text/html' });
    const archivo = path.join(raiz, 'public', decodeURIComponent(url.pathname));
    if (!archivo.startsWith(path.join(raiz, 'public')) || !fs.existsSync(archivo) || !fs.statSync(archivo).isFile()) {
        return solicitud.fulfill({ status: 404, body: '' });
    }
    return solicitud.fulfill({ body: fs.readFileSync(archivo), contentType: tipoContenido(archivo) });
}

function tipoContenido(archivo) {
    const tipos = { '.css': 'text/css', '.woff2': 'font/woff2', '.woff': 'font/woff', '.svg': 'image/svg+xml', '.png': 'image/png', '.jpg': 'image/jpeg', '.ico': 'image/x-icon' };
    return tipos[path.extname(archivo)] || 'application/octet-stream';
}

async function esperarServidor() {
    for (let intento = 0; intento < 60; intento += 1) {
        try {
            await fetch(`${origenLocal}/FoliOS`, { redirect: 'manual' });
            return;
        } catch {
            await new Promise((resolver) => setTimeout(resolver, 250));
        }
    }
    throw new Error(`php artisan serve no respondió en el puerto ${puerto}.`);
}

function nombreArchivo(ruta) {
    return ruta.replace(/^\//, '').replace(/[^a-zA-Z0-9]+/g, '_') || 'inicio';
}
