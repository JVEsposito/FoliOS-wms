import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { extname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
const identity = { nombre: 'Gerencia General', rol: 'consulta', modulos_acceso: ['gerencia.panel'], puede_consultar_panel_gerencial: true, ambito_camaras: 'productos' };
const season = { id: 't1', codigo: '2026-2027', nombre: 'Temporada 2026-2027', activa: true, fecha_inicio: '2026-10-01', fecha_fin: null };
const camera = (codigo, nombre, operativas, ocupadas, contenido = 'productos') => ({ id: codigo, codigo, nombre, contenido, operativas, ocupadas, disponibles: operativas - ocupadas, no_operativas: 0, ocupacion_porcentaje: Math.round((ocupadas * 1000) / operativas) / 10 });
const days = ['mié 4', 'jue 5', 'vie 6', 'sáb 7', 'dom 8', 'lun 9', 'mar 10'];

function dashboard() {
    const cameras = [
        camera('CAM-01', 'Cámara 1 · Exportación', 195, 0), camera('CAM-02', 'Cámara 2 · Exportación', 195, 0),
        camera('CAM-05', 'Cámara 5 · Saldos', 60, 2), camera('CAM-09', 'Cámara 9 · Cerezas', 325, 298),
        camera('CAM-13', 'Cámara 13 · Mantención', 460, 27), camera('MP-01', 'Antecámara MP', 80, 61, 'materia_prima'),
        camera('MAT-01', 'Bodega de materiales', 120, 20, 'materiales'),
    ];
    const pt = cameras.filter((c) => c.contenido === 'productos');
    const sum = (list, key) => list.reduce((total, item) => total + item[key], 0);
    const capacity = (list) => ({ camaras: list.length, operativas: sum(list, 'operativas'), ocupadas: sum(list, 'ocupadas'), disponibles: sum(list, 'disponibles'), no_operativas: 0, ocupacion_porcentaje: Math.round((sum(list, 'ocupadas') * 1000) / sum(list, 'operativas')) / 10 });
    const weekly = [0, 8000, 16500, 31000, 18200, 53800, 0];

    return {
        generado_at: '2026-11-10T16:10:00Z', actualizacion_segundos: 30, temporada: season, temporadas: [season],
        camaras: { resumen: capacity(cameras), por_contenido: { productos: capacity(pt), materiales: capacity(cameras.slice(6)), materia_prima: capacity(cameras.slice(5, 6)) }, detalle: cameras },
        productos: {
            total_activos: 151, disponibles_despacho: 13, comprometidos_carga: 15, pendientes_prefrio: 43, bloqueados: 0, pendientes_ubicacion: 80, ingresados_hoy: 43,
            pallets: 37, saldos: 114, cajas: 9124, cajas_pallets: 6240, cajas_saldos: 2884, cargas_comprometidas: 2, otros: 0, disponibilidad_porcentaje: 8.6,
            estados: [
                { clave: 'disponibles_despacho', pallets: 10, saldos: 3, cajas: 1820, total: 13 },
                { clave: 'comprometidos_carga', pallets: 15, saldos: 0, cajas: 2700, total: 15 },
                { clave: 'pendientes_prefrio', pallets: 9, saldos: 34, cajas: 2310, total: 43 },
                { clave: 'pendientes_ubicacion', pallets: 3, saldos: 77, cajas: 2294, total: 80 },
                { clave: 'bloqueados', pallets: 0, saldos: 0, cajas: 0, total: 0 },
                { clave: 'otros', pallets: 0, saldos: 0, cajas: 0, total: 0 },
            ],
            exportadoras: { detalle: [
                { nombre: 'LA AGUADA', pallets: 21, saldos: 60, cajas: 5110 },
                { nombre: 'Lagunilla Export', pallets: 12, saldos: 40, cajas: 3020 },
                { nombre: 'Sin exportadora', pallets: 4, saldos: 14, cajas: 994 },
            ], otras: null },
            antiguedad: { tramos: [
                { desde_dias: 0, hasta_dias: 7, total: 140, pallets: 34, saldos: 106 },
                { desde_dias: 8, hasta_dias: 14, total: 9, pallets: 3, saldos: 6 },
                { desde_dias: 15, hasta_dias: 30, total: 2, pallets: 0, saldos: 2 },
                { desde_dias: 31, hasta_dias: null, total: 0, pallets: 0, saldos: 0 },
            ], umbral_alerta_dias: 30, sobre_umbral: 0, sin_fecha: 0 },
        },
        cargas: { activas: 2, pendientes: 1, en_preparacion: 1, separadas: 0, folios_pendientes: 10, folios_en_anden: 5, folios_cargados: 0, folios_con_incidencia: 0, cerradas_hoy: 1,
            detalle: [{ id: 'l1', codigo: 'CAR-0012', orden_embarque: 'OE-88', estado: 'en_preparacion', prioridad: null, folios_asignados: 15, camara_objetivo: 'Cámara 9 · Cerezas', anden: 'A1', publicada_at: null, antiguedad_minutos: 95 }] },
        validacion: { procesados_hoy: 40, aprobados_hoy: 38, observados_hoy: 2, rechazados_hoy: 0, conflictos_hoy: 0, ultima_validacion: { folio: '6000000785', resultado: 'aprobado', recibido_at: '2026-11-10T15:55:00Z' } },
        verificaciones: { habilitada: true, periodos: { 7: { cumplimiento: { completadas: 5, generadas: 6, porcentaje: 83.3 }, camaras: [{ codigo: 'CAM-09', nombre: 'Cámara 9 · Cerezas', correctas: 48, total: 50, exactitud_porcentaje: 96 }] }, 30: { cumplimiento: { completadas: 20, generadas: 24, porcentaje: 83.3 }, camaras: [] } } },
        materiales: { reposicion: null, vencimientos: {}, verificaciones: { habilitada: false, periodos: {} }, items_con_stock: 12, folios_con_stock: 30, despachos_abiertos: 2, despachos_parciales: 1, recepciones_confirmadas_hoy: 1, recepciones_borrador: 0,
            unidades_medida: [{ unidad_medida: 'unidad', items_con_stock: 12, folios_con_stock: 30, cantidad_actual: 181467, cantidad_reservada: 0, cantidad_disponible: 0, cantidad_bloqueada: 0, cantidad_pendiente_ubicacion: 180900, cantidad_no_disponible: 567,
                items: [{ item_id: 'i1', cliente: { id: 'c1', codigo: 'AGU', nombre: 'La Aguada' }, temporada: { activa: true }, codigo: 'CJ-5', nombre: 'Caja 5 kg', categoria: 'Envase', unidad_medida: 'unidad', folios: 30, cantidad_actual: 181467, cantidad_reservada: 0, cantidad_disponible: 0, cantidad_bloqueada: 0, cantidad_pendiente_ubicacion: 180900, cantidad_no_disponible: 567 }] }] },
        prefrio: { tuneles_operativos: 2, tuneles_totales: 2, procesos_activos: 1, procesos_atrasados: 0, aprobados_hoy: 3, reprocesos_hoy: 0, duracion_promedio_minutos_7d: 210, folios_pendientes: 43, capacidad: 48, ocupadas: 24, disponibles: 24, ocupacion_porcentaje: 50,
            tuneles: [{ id: 'tu1', codigo: 'TUN-01', nombre: 'Túnel 1', operativo: true, capacidad: 24, ocupadas: 24, disponibles: 0, ocupacion_porcentaje: 100, proceso_activo: { codigo: 'PF-0101', estado: 'en_proceso', setpoint: 0.5, transcurridos_minutos: 120, duracion_objetivo_minutos: 240, atrasado: false } },
                { id: 'tu2', codigo: 'TUN-02', nombre: 'Túnel 2', operativo: true, capacidad: 24, ocupadas: 0, disponibles: 24, ocupacion_porcentaje: 0, proceso_activo: null }] },
        romana: {
            en_bascula_ingreso: 1, en_pesaje_envases: 0, pendientes_destare: 2, cerradas_hoy: 0, peso_neto_hoy: 0, envases_hoy: 0, clientes_hoy: 0,
            kilos_temporada: 412530.4, recepciones_temporada: 38, kilos_7_dias: 127500, recepciones_7_dias: 12, clientes_temporada: 3,
            clientes: [
                { id: 'c1', nombre: 'La Aguada', kilos_temporada: 250120.4, recepciones_temporada: 22, kilos_7_dias: 80300, kilos_hoy: 0, participacion_porcentaje: 60.6 },
                { id: 'c2', nombre: 'Lagunilla', kilos_temporada: 140210, recepciones_temporada: 13, kilos_7_dias: 47200, kilos_hoy: 0, participacion_porcentaje: 34 },
                { id: 'c3', nombre: 'Agrícola Santa Rosa', kilos_temporada: 22200, recepciones_temporada: 3, kilos_7_dias: 0, kilos_hoy: 0, participacion_porcentaje: 5.4 },
            ],
            series_clientes: [{ clave: 'c1', nombre: 'La Aguada' }, { clave: 'c2', nombre: 'Lagunilla' }],
            zona_horaria: 'America/Santiago',
            tendencia_diaria: days.map((etiqueta, index) => ({ fecha: `2026-11-${String(4 + index).padStart(2, '0')}`, etiqueta, recepciones: weekly[index] ? 2 : 0, peso_neto: weekly[index],
                por_cliente: weekly[index] ? { c1: Math.round(weekly[index] * 0.63), c2: weekly[index] - Math.round(weekly[index] * 0.63) } : {} })),
        },
        materia_prima: { lotes_activos: 12, borradores: 1, pendientes_hidrocooler: 5, hidrocooler_en_curso: 1, hidrocooler_retenido: 0, pendientes_asignacion: 0, directo_proceso: 0, en_camara: 6, entrega_parcial: 0, entregados_proceso: 20, confirmados_hoy: 0, kilos_confirmados_hoy: 0 },
        envases: { movimientos_hoy: 0, pendientes_revision: 199, observados: 0, tipos: Object.fromEntries(['bins', 'totes', 'esponjas'].map((tipo) => [tipo, { existencia: 120, entradas_hoy: 0, salidas_hoy: 0, tendencia_diaria: days.map((etiqueta) => ({ etiqueta, entradas: 4, salidas: 2 })), saldos_clientes: [{ cliente: 'La Aguada', saldo: 40 }] }])) },
        alertas: [
            { nivel: 'critica', area: 'Cámaras', titulo: 'Cámara 9 · Cerezas con alta ocupación', detalle: '298 de 325 posiciones operativas ocupadas.', metrica: 91.7, unidad: '%', href: '/oficina/frigorifico/camaras' },
            { nivel: 'advertencia', area: 'Materiales', titulo: 'Materiales en unidad sin stock disponible', detalle: 'Hay 181.467 unidad en bodega, pero nada habilitado para nuevos despachos.', metrica: 181467, unidad: 'unidad', href: '/oficina/materiales/inventario' },
            { nivel: 'advertencia', area: 'Materia prima', titulo: 'Lotes pendientes de etapa', detalle: '5 esperan hidrocooler y 0 esperan cámara.', metrica: 5, href: '/oficina/materia-prima' },
            { nivel: 'informativa', area: 'Envases', titulo: 'Movimientos pendientes de revisión', detalle: '199 movimiento(s) requieren chequeo documental.', metrica: 199, href: '/oficina/envases/cuenta-corriente' },
        ],
    };
}

async function open(browser, width, theme = 'dark-industrial') {
    const context = await browser.newContext({ viewport: { width, height: 900 } });
    await context.addInitScript(({ user, officeTheme }) => {
        localStorage.setItem('estiba_wms_office_token', 'fixture');
        localStorage.setItem('estiba_wms_office_identity', JSON.stringify(user));
        localStorage.setItem('estiba_wms_office_theme', officeTheme);
    }, { user: identity, officeTheme: theme });
    const html = readFileSync(resolve(root, 'storage/app/ui/oficina/gerencia.html'), 'utf8');
    await context.route('http://localhost/**', (route) => {
        const path = new URL(route.request().url()).pathname;
        if (path === '/oficina/gerencia') return route.fulfill({ body: html, contentType: 'text/html' });
        if (path.startsWith('/build/') || path.startsWith('/brand/')) {
            try { return route.fulfill({ body: readFileSync(resolve(root, `public${path}`)), contentType: ({ '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml', '.woff2': 'font/woff2', '.png': 'image/png' })[extname(path)] || 'application/octet-stream' }); } catch { return route.fulfill({ status: 404 }); }
        }
        if (path === '/api/gerencia/resumen') return route.fulfill({ json: { data: dashboard() } });
        if (path === '/api/acceso-oficina') return route.fulfill({ json: { usuario: identity } });
        return route.fulfill({ json: { data: { planta: 'Pruebas', temporada: null, avisos_materiales: [], avisos_cierre: [] } } });
    });
    const page = await context.newPage(); const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await page.goto('http://localhost/oficina/gerencia');
    await page.locator('#managementAlerts .management-alert').first().waitFor();
    return { context, page, errors };
}

const launch = () => chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
const capture = async (page, name) => {
    if (!process.env.FOLIOS_UI_CAPTURE_DIR) return;
    await page.waitForTimeout(900); // los gráficos terminan su animación
    await page.screenshot({ path: resolve(process.env.FOLIOS_UI_CAPTURE_DIR, `${name}.png`), fullPage: true });
};

for (const width of [1366, 390]) test(`Panel gerencial: kilos por cliente, folios con saldos aparte y cámaras por nombre en ${width}px`, async () => {
    const browser = await launch();
    try {
        const { context, page, errors } = await open(browser, width);
        const kpis = await page.locator('.management-kpis').innerText();
        assert.match(kpis, /412\.530/);
        assert.match(kpis, /37\s*pallets en planta/);
        assert.match(kpis, /114\s*saldos/);

        const alerts = await page.locator('#managementAlerts').innerText();
        assert.match(alerts, /181\.467\s*unidad/);
        assert.match(alerts, /91,7\s*%/);
        assert.match(await page.locator('#alertSummary').innerText(), /1 crítico/);
        await capture(page, `gerencia-focos-${width}`);

        await page.locator('#management-tab-weighbridge').click();
        await page.locator('#weighbridgeClientRows .management-client-kilos__row').first().waitFor();
        const clients = await page.locator('#weighbridgeClientRows').innerText();
        assert.match(clients, /La Aguada\s*250\.120 kg/);
        assert.match(clients, /60,6 % del total/);
        assert.match(await page.locator('#weighbridgeChartSummary').innerText(), /Lagunilla\s*47\.200 kg/);
        assert.equal(await page.locator('#weighbridgeSeasonMetric').innerText(), '412.530 kg');
        await capture(page, `gerencia-recepcion-${width}`);

        await page.locator('#management-tab-products').click();
        const unlocated = page.locator('#productStateRows [data-state="pendientes_ubicacion"]');
        assert.match(await unlocated.innerText(), /Sin ubicación[\s\S]*\b3\b[\s\S]*\b77\b[\s\S]*2\.294/);
        assert.match(await page.locator('.management-state-row--total').innerText(), /37[\s\S]*114[\s\S]*9\.124/);
        assert.match(await page.locator('#productExporterRows').innerText(), /LA AGUADA[\s\S]*21 pallets · 60 saldos/);
        await capture(page, `gerencia-producto-${width}`);

        await page.locator('#management-tab-cameras').click();
        const occupancy = await page.locator('#cameraOccupancyChart').innerText();
        assert.match(occupancy, /Cámara 9 · Cerezas/);
        assert.doesNotMatch(occupancy, /CAM-09/);
        assert.equal(await page.locator('.management-occupancy__row[data-tone="alert"]').count(), 1);
        assert.match(await page.locator('#cameraDetailRows tr').nth(3).innerText(), /^Cámara 9 · Cerezas/);
        await capture(page, `gerencia-camaras-${width}`);

        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), true, `desborde a ${width}px`);
        assert.deepEqual(errors, []);
        await context.close();
    } finally { await browser.close(); }
});

test('Panel gerencial: el tema claro también separa pallets y saldos', async () => {
    const browser = await launch();
    try {
        const { context, page, errors } = await open(browser, 1366, 'light-professional');
        await page.locator('#management-tab-products').click();
        const [pallet, saldo] = await page.evaluate(() => ['pallet', 'saldo'].map((tipo) => getComputedStyle(document.querySelector(`#productStateRows .management-bulk--${tipo}`)).backgroundImage + getComputedStyle(document.querySelector(`#productStateRows .management-bulk--${tipo}`)).backgroundColor));
        assert.notEqual(pallet, saldo);
        await capture(page, 'gerencia-producto-claro');
        assert.deepEqual(errors, []);
        await context.close();
    } finally { await browser.close(); }
});
