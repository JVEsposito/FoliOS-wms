import assert from 'node:assert/strict';
import { mkdirSync, readFileSync } from 'node:fs';
import { extname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';

import { coldChainPlan } from '../../resources/js/shared/dew-point.js';

const root = fileURLToPath(new URL('../../', import.meta.url));
const html = readFileSync(resolve(root, 'storage/app/ui/oficina/punto-rocio.html'), 'utf8');
const NOW = new Date('2026-11-04T15:30:00Z'); // 12:30 en Santiago.
const ETAPAS = [['packing', 'Packing'], ['sellado', 'Sellado'], ['paletizado', 'Paletizado'], ['ingreso_prefrio', 'Ingreso prefrío'], ['salida_prefrio', 'Salida prefrío'], ['traslado', 'Traslado'], ['almacenamiento', 'Almacenamiento'], ['despacho', 'Despacho / andén'], ['carga_contenedor', 'Carga de contenedor'], ['otro', 'Otro']];
const label = (etapa) => ETAPAS.find(([value]) => value === etapa)[1];

function reading(overrides) {
    return {
        id: 'm-1', etapa: 'sellado', etapa_etiqueta: label(overrides.etapa || 'sellado'), punto_tipo: 'sala', punto_id: 'sala-sellado', punto: 'Sala de sellado', referencia: null,
        temperatura_ambiente_c: 15, humedad_relativa: 62.9, temperatura_superficie_c: 7.1, punto_rocio_c: 7.99, margen_c: -0.89, vapor_g_m3: 8.05, margen_minimo_c: 2,
        estado: 'condensacion_posible', estado_etiqueta: 'Condensación posible', gotas_visibles: 'pendiente', requiere_accion: true, alerta_pendiente: true, observacion: null,
        medido_at: '2026-11-04T11:10:00Z', fecha: '2026-11-04', registrado_por: 'Calidad turno día', dispositivo: 'Tablet prefrío',
        accion_correctiva: null, accion_registrada_por: null, accion_registrada_at: null, anulada: false, anulada_at: null, anulada_por: null, motivo_anulacion: null,
        envase: null, origen: null, plan: null,
        ...overrides,
        etapa_etiqueta: overrides.etapa_etiqueta || label(overrides.etapa || 'sellado'),
    };
}

const MARGINS = () => ETAPAS.map(([etapa, etiqueta]) => (etapa === 'almacenamiento'
    ? { etapa, etiqueta, margen_minimo_c: 0.5, version: 1, ajustado: true }
    : { etapa, etiqueta, margen_minimo_c: 2, version: 0, ajustado: false }));

function fixture({ linked = false } = {}) {
    const extra = linked ? [
        reading({ id: 'm-4', etapa: 'salida_prefrio', punto: 'Salida túneles', temperatura_ambiente_c: 2, humedad_relativa: 80, temperatura_superficie_c: 1, punto_rocio_c: -1.09, margen_c: 2.09, estado: 'margen_favorable', estado_etiqueta: 'Margen favorable', gotas_visibles: 'no', requiere_accion: false, alerta_pendiente: false, medido_at: '2026-11-04T14:30:00Z' }),
        reading({
            id: 'm-5', etapa: 'paletizado', punto: 'Sala de paletizado', temperatura_ambiente_c: 10, humedad_relativa: 50, temperatura_superficie_c: 3, punto_rocio_c: 0.05, margen_c: 2.95,
            estado: 'margen_favorable', estado_etiqueta: 'Margen favorable', gotas_visibles: 'no', medido_at: '2026-11-04T14:45:00Z', condicion: 'Condensación posible en el envase', critica: true,
            envase: { referencia_id: 'm-1', punto: 'Sala de sellado', medido_at: '2026-11-04T11:10:00Z', punto_rocio_c: 7.99, margen_c: -4.99, estado: 'condensacion_posible', estado_etiqueta: 'Condensación posible', referencia_anulada: false },
        }),
    ] : [];
    return {
        margins: MARGINS(),
        rooms: [{ id: 'sala-sellado', nombre: 'Sala de sellado', activa: true, version: 1 }],
        planRows: { sellado: { temperatura_aire_c: 12, temperatura_fruta_c: 4, version: 1 }, almacenamiento: { temperatura_aire_c: -1, temperatura_fruta_c: -0.5, version: 1 } },
        readings: [
            reading({ plan: { rocio_maximo_c: -1, humedad_maxima: 33.4, fuera: true } }),
            reading({ id: 'm-2', etapa: 'ingreso_prefrio', etapa_etiqueta: 'Ingreso prefrío', punto_tipo: 'tunel', punto_id: 'tunel-1', punto: 'TP-01 · Túnel 1', temperatura_ambiente_c: 4, humedad_relativa: 88, temperatura_superficie_c: 6.5, punto_rocio_c: 2.13, margen_c: 4.37, estado: 'margen_favorable', estado_etiqueta: 'Margen favorable', gotas_visibles: 'no', requiere_accion: false, alerta_pendiente: false, medido_at: '2026-11-04T13:30:00Z' }),
            reading({ id: 'm-3', etapa: 'paletizado', etapa_etiqueta: 'Paletizado', punto: 'Sala de sellado', temperatura_superficie_c: null, margen_c: null, estado: 'sin_superficie', estado_etiqueta: 'Medir superficie', requiere_accion: false, alerta_pendiente: false, medido_at: '2026-11-04T14:00:00Z', anulada: true, anulada_por: 'Supervisión frío', motivo_anulacion: 'Termohigrómetro sin estabilizar.' }),
            ...extra,
        ],
    };
}

function summarize(readings) {
    const live = readings.filter((item) => !item.anulada);
    const alerts = live.filter((item) => item.requiere_accion);
    const margins = live.map((item) => item.margen_c).filter((value) => value !== null);
    return {
        registros: live.length,
        con_superficie: live.filter((item) => item.temperatura_superficie_c !== null).length,
        punto_rocio_maximo_c: live.length ? Math.max(...live.map((item) => item.punto_rocio_c)) : null,
        margen_minimo_c: margins.length ? Math.min(...margins) : null,
        alertas: alerts.length,
        condensacion_posible: live.filter((item) => item.estado === 'condensacion_posible').length,
        margen_bajo: live.filter((item) => item.estado === 'margen_bajo').length,
        gotas_visibles: live.filter((item) => item.gotas_visibles === 'si').length,
        alertas_pendientes: alerts.filter((item) => !item.accion_correctiva).length,
        fuera_de_plan: live.filter((item) => item.plan?.fuera).length,
    };
}

function response(state, date, permissions) {
    const readings = state.readings.filter((item) => item.fecha === date);
    const live = readings.filter((item) => !item.anulada);
    const history = Array.from({ length: 14 }, (_, index) => {
        const day = new Date(`${date}T12:00:00Z`);
        day.setUTCDate(day.getUTCDate() - 13 + index);
        const fecha = day.toISOString().slice(0, 10);
        return { fecha, ...summarize(state.readings.filter((item) => item.fecha === fecha)) };
    });
    return {
        fecha: date,
        resumen: summarize(readings),
        por_etapa: ETAPAS.map(([etapa, etiqueta]) => {
            const items = live.filter((item) => item.etapa === etapa);
            return { etapa, etiqueta, registros: items.length, alertas: items.filter((item) => item.requiere_accion).length };
        }).filter((row) => row.registros > 0),
        mediciones: readings,
        historial: history,
        puntos: { salas: state.rooms, tuneles: [{ id: 'tunel-1', nombre: 'TP-01 · Túnel 1' }], camaras: [{ id: 'camara-9', nombre: 'CAM-09 · Cámara 9' }] },
        etapas: ETAPAS.map(([valor, etiqueta]) => ({ valor, etiqueta })),
        referencias: state.readings
            .filter((item) => !item.anulada && (item.etapa === 'sellado' || item.temperatura_superficie_c !== null))
            .sort((a, b) => b.medido_at.localeCompare(a.medido_at))
            .map(({ id, etapa, etapa_etiqueta, punto, referencia, medido_at, temperatura_ambiente_c, humedad_relativa, temperatura_superficie_c, punto_rocio_c }) => ({ id, etapa, etapa_etiqueta, punto, referencia, medido_at, temperatura_ambiente_c, humedad_relativa, temperatura_superficie_c, punto_rocio_c })),
        parametros: { margen_minimo_c: 2, margenes_por_etapa: state.margins, horas_referencia: 72, dias_registro_retroactivo: 7, zona_horaria: 'America/Santiago' },
        plan: {
            temperatura_congelacion_c: -1.7,
            etapas: coldChainPlan(
                ETAPAS.filter(([etapa]) => etapa !== 'otro').map(([etapa]) => ({ etapa, temperatura_aire_c: state.planRows[etapa]?.temperatura_aire_c ?? null, temperatura_fruta_c: state.planRows[etapa]?.temperatura_fruta_c ?? null })),
                (stage) => state.margins.find((row) => row.etapa === stage)?.margen_minimo_c ?? 2,
            ).map((row, index) => ({ ...row, etiqueta: ETAPAS[index][1], orden: index + 1, version: state.planRows[row.etapa]?.version ?? 0 })),
        },
        permisos: permissions,
    };
}

async function openPage(browser, width, { supervisor = true, linked = false } = {}) {
    const context = await browser.newContext({ viewport: { width, height: 1000 }, timezoneId: 'America/Santiago' });
    await context.clock.setFixedTime(NOW);
    const identity = { id: 1, nombre: supervisor ? 'Supervisión frío' : 'Consulta', rol: supervisor ? 'supervisor_frio' : 'consulta', puede_consultar_prefrio: true };
    await context.addInitScript((user) => {
        localStorage.setItem('estiba_wms_office_token', 'fixture');
        localStorage.setItem('estiba_wms_office_identity', JSON.stringify(user));
        // La oficina se abre por HTTP en la red de la planta: ahí el navegador no ofrece randomUUID.
        delete Crypto.prototype.randomUUID;
    }, identity);
    const state = { ...fixture({ linked }), requests: [], posts: [] };
    const permissions = { registrar: supervisor, supervisar: supervisor };
    await context.route('http://localhost/**', async (route) => {
        const request = route.request(); const url = new URL(request.url()); const path = url.pathname;
        if (path === '/oficina/prefrio/punto-rocio') return route.fulfill({ body: html, contentType: 'text/html' });
        if (path.startsWith('/build/') || path.startsWith('/brand/')) {
            try { return route.fulfill({ body: readFileSync(resolve(root, `public${path}`)), contentType: ({ '.js': 'text/javascript', '.css': 'text/css', '.woff2': 'font/woff2', '.svg': 'image/svg+xml' })[extname(path)] || 'application/octet-stream' }); }
            catch { return route.fulfill({ status: 404, body: '' }); }
        }
        if (path === '/api/prefrio/punto-rocio' && request.method() === 'GET') {
            state.requests.push(url.searchParams.get('fecha'));
            return route.fulfill({ json: response(state, url.searchParams.get('fecha') || '2026-11-04', permissions) });
        }
        if (path === '/api/prefrio/punto-rocio' && request.method() === 'POST') {
            const body = request.postDataJSON();
            state.posts.push({ path, body });
            if (state.failNextReading) { state.failNextReading = false; return route.abort('connectionreset'); }
            const created = reading({
                id: 'm-new', etapa: body.etapa, etapa_etiqueta: label(body.etapa), punto_tipo: body.punto_tipo, punto_id: body.punto_id, punto: 'Sala de sellado',
                temperatura_ambiente_c: body.temperatura_ambiente_c, humedad_relativa: body.humedad_relativa, temperatura_superficie_c: body.temperatura_superficie_c,
                punto_rocio_c: 7.99, margen_c: 0.81, estado: 'margen_bajo', estado_etiqueta: 'Margen bajo', gotas_visibles: body.gotas_visibles, medido_at: body.medido_at,
                registrado_por: 'Supervisión frío', dispositivo: null, condicion: 'Margen bajo',
                ...(body.sellado_referencia_id ? {
                    punto: 'CAM-09 · Cámara 9', punto_rocio_c: -1.14, margen_c: 1.34, estado: 'margen_favorable', estado_etiqueta: 'Margen favorable', condicion: 'Condensación posible en el envase',
                    envase: { referencia_id: body.sellado_referencia_id, punto: 'Sala de sellado', medido_at: '2026-11-04T11:10:00Z', punto_rocio_c: 7.99, margen_c: -7.79, estado: 'condensacion_posible', referencia_anulada: false },
                    origen: { referencia_id: body.origen_referencia_id, etapa_etiqueta: 'Salida prefrío', punto: 'Salida túneles', medido_at: '2026-11-04T14:30:00Z', temperatura_superficie_c: 1, margen_c: 2.14, estado: 'margen_favorable', referencia_anulada: false },
                } : {}),
            });
            state.readings.push(created);
            return route.fulfill({ status: 201, json: { data: created } });
        }
        const action = path.match(/^\/api\/prefrio\/punto-rocio\/([^/]+)\/(accion|anular)$/);
        if (action && action[1] !== 'salas') {
            const body = request.postDataJSON();
            state.posts.push({ path, body });
            const item = state.readings.find((candidate) => candidate.id === action[1]);
            if (action[2] === 'accion') Object.assign(item, { accion_correctiva: body.texto, accion_registrada_por: 'Supervisión frío', alerta_pendiente: false });
            else Object.assign(item, { anulada: true, anulada_por: 'Supervisión frío', motivo_anulacion: body.texto, alerta_pendiente: false });
            return route.fulfill({ json: { data: item } });
        }
        if (path === '/api/prefrio/punto-rocio/plan' && request.method() === 'PUT') {
            const body = request.postDataJSON();
            state.posts.push({ path, body });
            for (const change of body.etapas) state.planRows[change.etapa] = { temperatura_aire_c: change.temperatura_aire_c, temperatura_fruta_c: change.temperatura_fruta_c, version: change.version_conocida + 1 };
            return route.fulfill({ json: { data: {} } });
        }
        if (path === '/api/prefrio/punto-rocio/margenes' && request.method() === 'PUT') {
            const body = request.postDataJSON();
            state.posts.push({ path, body });
            for (const change of body.margenes) Object.assign(state.margins.find((row) => row.etapa === change.etapa), { margen_minimo_c: change.margen_minimo_c, version: change.version_conocida + 1, ajustado: true });
            return route.fulfill({ json: { data: state.margins } });
        }
        if (path === '/api/prefrio/punto-rocio/salas' && request.method() === 'POST') {
            const body = request.postDataJSON();
            state.posts.push({ path, body });
            const room = { id: `sala-${state.rooms.length + 1}`, nombre: body.nombre, activa: true, version: 1 };
            state.rooms.push(room);
            return route.fulfill({ status: 201, json: { data: room } });
        }
        return route.fulfill({ json: {} });
    });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await page.goto('http://localhost/oficina/prefrio/punto-rocio');
    await page.locator('#officeApp:not(.is-hidden)').waitFor();
    await page.locator('#readingsBody tr').first().waitFor();
    return { context, page, state, errors };
}

async function assertNoPageOverflow(page) {
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    assert.ok(overflow <= 1, `La página se desborda horizontalmente ${overflow}px`);
}

test('supervisión revisa el día, registra una medición y atiende la alerta de condensación', async () => {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
    try {
        for (const width of [390, 1280]) {
            const { context, page, state, errors } = await openPage(browser, width);
            assert.equal(state.requests[0], null, 'Sin ?fecha pide el día operacional actual');
            assert.equal(await page.locator('#dewDateInput').inputValue(), '2026-11-04');
            assert.ok(await page.locator('#nextDayButton').isDisabled(), 'No se navega a días futuros');
            assert.equal(await page.locator('#readingCount').textContent(), '2', 'La medición anulada no cuenta');
            assert.equal(await page.locator('#alertCount').textContent(), '1');
            assert.equal(await page.locator('#pendingCount').textContent(), '1 sin acción');
            assert.match(await page.locator('#minMargin').textContent(), /-0,9 °C/);
            assert.equal(await page.locator('#readingsBody tr').count(), 3);
            assert.match(await page.locator('#readingsBody tr.is-alert').textContent(), /Condensación posible.*Alerta sin acción correctiva/s);
            assert.match(await page.locator('#readingsBody tr.is-voided').textContent(), /Termohigrómetro sin estabilizar/);
            assert.ok(await page.locator('#chartEmpty').evaluate((node) => node.classList.contains('is-hidden')));
            assert.equal(await page.locator('#historyStrip .dew-day-bar').count(), 14);
            assert.equal(await page.locator('#historyStrip [aria-pressed="true"]').getAttribute('data-date'), '2026-11-04');
            assert.equal(await page.locator('#historyStrip [aria-pressed="true"]').getAttribute('data-tone'), 'danger');
            await assertNoPageOverflow(page);
            if (process.env.DEW_POINT_SCREENSHOT_DIR) {
                mkdirSync(process.env.DEW_POINT_SCREENSHOT_DIR, { recursive: true });
                await page.screenshot({ path: resolve(process.env.DEW_POINT_SCREENSHOT_DIR, `dia-${width}.png`), fullPage: true });
            }

            // Filtro por etapa: tabla y gráfico se limitan a la etapa elegida.
            await page.locator('#stageFilters [data-stage="sellado"]').click();
            assert.equal(await page.locator('#readingsBody tr').count(), 1);
            assert.equal(await page.locator('#chartSummary').textContent(), '1 medición');
            await page.locator('#stageFilters [data-stage=""]').click();

            // Nueva medición con cálculo en vivo, igual que la calculadora de cerezas.
            await page.locator('#newReadingButton').click();
            const form = page.locator('#readingForm');
            assert.equal(await form.locator('[name="medido_at"]').inputValue(), '2026-11-04T12:30');
            await form.locator('[name="etapa"]').selectOption('sellado');
            await form.locator('[name="temperatura_ambiente_c"]').fill('15');
            await form.locator('[name="humedad_relativa"]').fill('62.9');
            assert.match(await page.locator('#readingResult').textContent(), /8,0 °C.*Medir superficie/s);
            await form.locator('[name="temperatura_superficie_c"]').fill('7.1');
            assert.match(await page.locator('#readingResult').textContent(), /8,0 °C.*-0,9 °C.*8,1 g\/m³.*Condensación posible/s);
            assert.equal(await page.locator('#readingResult').getAttribute('data-tone'), 'danger');
            await form.locator('[name="temperatura_superficie_c"]').fill('8.8');
            assert.match(await page.locator('#readingResult').textContent(), /Margen bajo/);
            await page.locator('#saveReadingButton').click();
            assert.match(await page.locator('#readingError').textContent(), /Selecciona dónde/);
            assert.equal(state.posts.length, 0);
            assert.equal(await form.locator('[name="punto"] optgroup').count(), 3);
            await form.locator('[name="punto"]').selectOption('sala:sala-sellado');
            await form.locator('[name="observacion"]').fill('Antes de sellar la línea 2.');
            if (process.env.DEW_POINT_SCREENSHOT_DIR) await page.screenshot({ path: resolve(process.env.DEW_POINT_SCREENSHOT_DIR, `registro-${width}.png`) });
            // La primera respuesta se pierde: el reintento debe reutilizar la misma operación.
            state.failNextReading = true;
            await page.locator('#saveReadingButton').click();
            await page.locator('#readingError', { hasText: 'No hay conexión' }).waitFor();
            await page.locator('#saveReadingButton').click();
            await page.locator('#officeToasts .toast').waitFor();
            assert.equal(state.posts.length, 2);
            assert.equal(state.posts[0].body.operacion_id, state.posts[1].body.operacion_id);
            const post = state.posts.pop();
            state.posts.length = 0;
            assert.equal(post.path, '/api/prefrio/punto-rocio');
            assert.match(post.body.operacion_id, /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
            assert.deepEqual(
                { etapa: post.body.etapa, punto_tipo: post.body.punto_tipo, punto_id: post.body.punto_id, temperatura_ambiente_c: post.body.temperatura_ambiente_c, humedad_relativa: post.body.humedad_relativa, temperatura_superficie_c: post.body.temperatura_superficie_c, gotas_visibles: post.body.gotas_visibles, observacion: post.body.observacion, medido_at: post.body.medido_at },
                { etapa: 'sellado', punto_tipo: 'sala', punto_id: 'sala-sellado', temperatura_ambiente_c: 15, humedad_relativa: 62.9, temperatura_superficie_c: 8.8, gotas_visibles: 'pendiente', observacion: 'Antes de sellar la línea 2.', medido_at: '2026-11-04T15:30:00.000Z' },
            );
            assert.match(await page.locator('#officeToasts').textContent(), /Supervisión de frío fue avisada/);
            assert.equal(await page.locator('#readingsBody tr').count(), 4);
            assert.equal(await page.locator('#alertCount').textContent(), '2');

            // Acción correctiva sobre la alerta de condensación.
            await page.locator('#readingsBody [data-action="accion"][data-id="m-1"]').click();
            await page.locator('#noteDialog[open]').waitFor();
            assert.match(await page.locator('#noteDescription').textContent(), /Sellado · Sala de sellado: rocío 8,0 °C, margen -0,9 °C/);
            await page.locator('#noteForm [name="texto"]').fill('ok');
            await page.locator('#saveNoteButton').click();
            assert.match(await page.locator('#noteError').textContent(), /al menos 5/);
            await page.locator('#noteForm [name="texto"]').fill('Se bajó la humedad de la sala y se esperó 20 minutos antes de sellar.');
            await page.locator('#saveNoteButton').click();
            await page.locator('#noteDialog').waitFor({ state: 'hidden' });
            await page.locator('#officeToasts .toast', { hasText: 'Acción correctiva registrada.' }).waitFor();
            assert.deepEqual(state.posts.at(-1), { path: '/api/prefrio/punto-rocio/m-1/accion', body: { texto: 'Se bajó la humedad de la sala y se esperó 20 minutos antes de sellar.' } });
            assert.match(await page.locator('#readingsBody').textContent(), /Acción de Supervisión frío: Se bajó la humedad/);
            assert.equal(await page.locator('#readingsBody [data-action="accion"][data-id="m-1"]').count(), 0);

            // Salas configuradas una vez por supervisión.
            await page.locator('#roomsButton').click();
            await page.locator('#roomsDialog[open]').waitFor();
            await page.locator('#roomForm [name="nombre"]').fill('Sala de paletizado');
            await page.locator('#addRoomButton').click();
            await page.locator('#roomList [data-room-name="sala-2"]').waitFor();
            assert.deepEqual(state.posts.at(-1), { path: '/api/prefrio/punto-rocio/salas', body: { nombre: 'Sala de paletizado' } });
            await page.keyboard.press('Escape');

            // El historial lleva a otro día y conserva la fecha en la URL.
            await page.locator('#historyStrip [data-date="2026-11-03"]').click();
            await page.waitForFunction(() => document.querySelector('#dewDateInput').value === '2026-11-03');
            assert.equal(state.requests.at(-1), '2026-11-03');
            assert.equal(new URL(page.url()).searchParams.get('fecha'), '2026-11-03');
            assert.match(await page.locator('#readingsBody').textContent(), /Aún no hay mediciones este día/);
            assert.ok(await page.locator('#chartEmpty').evaluate((node) => !node.classList.contains('is-hidden')));
            assert.equal(await page.locator('#nextDayButton').isDisabled(), false);
            await assertNoPageOverflow(page);
            assert.deepEqual(errors, []);
            await context.close();
        }
    } finally {
        await browser.close();
    }
});

test('una medición se vincula al sellado y a la fruta que llega, con el margen de su etapa', async () => {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
    try {
        for (const width of [390, 1280]) {
            const { context, page, state, errors } = await openPage(browser, width, { linked: true });
            // La tabla muestra la comparación guardada con el sellado.
            const palletRow = page.locator('#readingsBody tr', { hasText: 'Sala de paletizado' });
            assert.match(await palletRow.textContent(), /Envase\s*Condensación posible\s*margen -5,0 °C · rocío del sellado 8,0 °C \(08:10, Sala de sellado\)/);

            await page.locator('#newReadingButton').click();
            const form = page.locator('#readingForm');
            await form.locator('[name="etapa"]').selectOption('almacenamiento');
            await form.locator('[name="punto"]').selectOption('camara:camara-9');
            await form.locator('[name="temperatura_ambiente_c"]').fill('0');
            await form.locator('[name="humedad_relativa"]').fill('92');
            await form.locator('[name="temperatura_superficie_c"]').fill('0.2');
            const result = page.locator('#readingResult');
            // Cámara a 0 °C y 92 %: rocío −1,1 °C; con el margen de mantención (0,5 °C) la fruta a 0,2 °C está bien.
            assert.match(await result.textContent(), /-1,1 °C.*1,3 °C.*RIESGO EN ESTE PUNTO\s*Margen favorable.*Margen mínimo de la etapa: 0,5 °C/s);

            const sealedOptions = await form.locator('[name="sellado_referencia_id"] option').allTextContents();
            assert.deepEqual(sealedOptions, ['Sin vincular', '08:10 · Sala de sellado · rocío 8,0 °C (15,0 °C / 62,9 %)']);
            const originOptions = await form.locator('[name="origen_referencia_id"] option').evaluateAll((nodes) => nodes.map((node) => node.value));
            assert.deepEqual(originOptions, ['', 'm-5', 'm-4', 'm-2', 'm-1'], 'Solo mediciones anteriores con T° superficial, de la más reciente a la más antigua');

            await form.locator('[name="sellado_referencia_id"]').selectOption('m-1');
            assert.match(await result.textContent(), /Dentro del envase.*rocío del sellado 8,0 °C vs superficie 0,2 °C · margen -7,8 °C\s*Condensación posible/s);
            assert.equal(await result.getAttribute('data-tone'), 'danger', 'El riesgo vinculado más grave define el tono');
            await form.locator('[name="origen_referencia_id"]').selectOption('m-4');
            assert.match(await result.textContent(), /Al llegar.*fruta a 1,0 °C desde Salida prefrío vs rocío de este punto -1,1 °C · margen 2,1 °C\s*Margen favorable/s);
            if (process.env.DEW_POINT_SCREENSHOT_DIR) {
                mkdirSync(process.env.DEW_POINT_SCREENSHOT_DIR, { recursive: true });
                await page.locator('#readingDialog').screenshot({ path: resolve(process.env.DEW_POINT_SCREENSHOT_DIR, `vinculos-${width}.png`) });
            }

            // Un sellado evalúa su propio aire; una hora anterior deja fuera lo que aún no ocurría.
            await form.locator('[name="etapa"]').selectOption('sellado');
            assert.ok(await form.locator('[name="sellado_referencia_id"]').isDisabled());
            assert.doesNotMatch(await result.textContent(), /Dentro del envase/);
            await form.locator('[name="etapa"]').selectOption('almacenamiento');
            assert.equal(await form.locator('[name="sellado_referencia_id"]').inputValue(), '');
            await form.locator('[name="sellado_referencia_id"]').selectOption('m-1');
            await form.locator('[name="medido_at"]').fill('2026-11-04T11:15');
            await form.locator('[name="medido_at"]').dispatchEvent('change');
            assert.equal(await form.locator('[name="origen_referencia_id"]').inputValue(), '', 'La salida de las 11:30 aún no ocurría');
            assert.equal(await form.locator('[name="sellado_referencia_id"]').inputValue(), 'm-1');
            await form.locator('[name="medido_at"]').fill('2026-11-04T12:30');
            await form.locator('[name="medido_at"]').dispatchEvent('change');
            await form.locator('[name="origen_referencia_id"]').selectOption('m-4');

            await page.locator('#saveReadingButton').click();
            await page.locator('#officeToasts .toast').waitFor();
            const [post] = state.posts;
            assert.equal(post.body.sellado_referencia_id, 'm-1');
            assert.equal(post.body.origen_referencia_id, 'm-4');
            assert.equal(post.body.etapa, 'almacenamiento');
            assert.match(await page.locator('#officeToasts').textContent(), /Medición guardada: condensación posible en el envase\. Supervisión de frío fue avisada\./);
            const cameraRow = page.locator('#readingsBody tr', { hasText: 'CAM-09' });
            assert.match(await cameraRow.textContent(), /Al llegar\s*Margen favorable\s*margen 2,1 °C · fruta a 1,0 °C desde Salida prefrío \(11:30, Salida túneles\)/);

            // Supervisión ajusta el margen por etapa.
            await page.locator('#marginsButton').click();
            await page.locator('#marginsDialog[open]').waitFor();
            const margins = page.locator('#marginsForm');
            assert.equal(await margins.locator('[name="almacenamiento"]').inputValue(), '0.5');
            assert.match(await margins.locator('label', { hasText: 'Almacenamiento' }).textContent(), /Ajustado por supervisión/);
            await margins.locator('[name="ingreso_prefrio"]').fill('1.5');
            await margins.locator('[name="otro"]').fill('12');
            await page.locator('#saveMarginsButton').click();
            assert.match(await page.locator('#marginError').textContent(), /Otro debe estar entre 0 y 10 °C/);
            await margins.locator('[name="otro"]').fill('2');
            if (process.env.DEW_POINT_SCREENSHOT_DIR) await page.locator('#marginsDialog').screenshot({ path: resolve(process.env.DEW_POINT_SCREENSHOT_DIR, `margenes-${width}.png`) });
            await page.locator('#saveMarginsButton').click();
            await page.locator('#officeToasts .toast', { hasText: 'Márgenes guardados' }).waitFor();
            assert.deepEqual(state.posts.at(-1), { path: '/api/prefrio/punto-rocio/margenes', body: { margenes: [{ etapa: 'ingreso_prefrio', margen_minimo_c: 1.5, version_conocida: 0 }] } });
            await assertNoPageOverflow(page);
            assert.deepEqual(errors, []);
            await context.close();
        }
    } finally {
        await browser.close();
    }
});

test('el plan de cadena de frío muestra el objetivo de cada etapa, se compara al medir y se edita', async () => {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
    try {
        for (const width of [390, 1280]) {
            const { context, page, state, errors } = await openPage(browser, width);
            // Sellado a 12 °C: el aire que queda en la bolsa debe aguantar la cámara (fruta −0,5 °C, margen 0,5).
            const sealing = page.locator('#chainStrip [data-stage="sellado"]');
            assert.match(await sealing.textContent(), /2\. Sellado.*Aire 12,0 °C · fruta 4,0 °C.*HR ≤ 40 %.*Rocío ≤ -1,0 °C\s*por el envase.*Hoy 08:10 · rocío 8,0 °C · fuera de plan/s);
            assert.equal(await sealing.getAttribute('data-tone'), 'warning');
            const storage = page.locator('#chainStrip [data-stage="almacenamiento"]');
            assert.match(await storage.textContent(), /7\. Almacenamiento.*Sin límite de HR.*Sin medición hoy/s);
            assert.equal(await page.locator('#chainStrip .dew-chain__stage').count(), 2);
            assert.equal(await page.locator('#outOfPlanCount').textContent(), '1');
            assert.match(await page.locator('#readingsBody tr.is-alert').textContent(), /Plan\s*Fuera de plan\s*rocío ≤ -1,0 °C · HR ≤ 33 % a 15,0 °C/);
            if (process.env.DEW_POINT_SCREENSHOT_DIR) {
                mkdirSync(process.env.DEW_POINT_SCREENSHOT_DIR, { recursive: true });
                await page.locator('.dew-plan-panel').screenshot({ path: resolve(process.env.DEW_POINT_SCREENSHOT_DIR, `plan-${width}.png`) });
            }

            // Al medir, la cámara se compara contra su plan: a 0 °C la HR debe quedar bajo 93 %.
            await page.locator('#newReadingButton').click();
            const form = page.locator('#readingForm');
            await form.locator('[name="etapa"]').selectOption('almacenamiento');
            await form.locator('[name="temperatura_ambiente_c"]').fill('0');
            await form.locator('[name="humedad_relativa"]').fill('95');
            const result = page.locator('#readingResult');
            assert.match(await result.textContent(), /Plan de la etapa\s*rocío ≤ -1,0 °C · a 0,0 °C la HR debe ser ≤ 93 %\s*Fuera del plan/);
            assert.equal(await result.getAttribute('data-tone'), 'warning');
            await form.locator('[name="humedad_relativa"]').fill('90');
            assert.match(await result.textContent(), /Dentro del plan/);
            await page.keyboard.press('Escape');

            // Supervisión edita el plan con el cálculo en vivo.
            await page.locator('#editPlanButton').click();
            await page.locator('#planDialog[open]').waitFor();
            assert.equal(await page.locator('#planRows tr').count(), 9);
            const sealingRow = page.locator('#planRows tr[data-stage="sellado"]');
            assert.match(await sealingRow.textContent(), /-1,0 °C\s*por el envase\s*40 %/);
            await page.locator('#suggestPlanButton').click();
            assert.equal(await page.locator('#planForm [name="fruta_salida_prefrio"]').inputValue(), '-1');
            assert.equal(await page.locator('#planForm [name="aire_almacenamiento"]').inputValue(), '-1', 'La propuesta no pisa lo ya definido');
            // Con margen de 2 °C en salida y traslado, el envase queda limitado a −3 °C.
            assert.match(await sealingRow.textContent(), /-3,0 °C\s*por el envase\s*35 %/);
            await page.locator('#planForm [name="aire_packing"]').fill('12');
            await page.locator('#planForm [name="fruta_packing"]').fill('5');
            await page.locator('#planForm [name="fruta_traslado"]').fill('70');
            await page.locator('#savePlanButton').click();
            assert.match(await page.locator('#planError').textContent(), /fruta de Traslado debe estar entre -30 y 60 °C/);
            await page.locator('#planForm [name="fruta_traslado"]').fill('-1');
            if (process.env.DEW_POINT_SCREENSHOT_DIR) await page.locator('#planDialog').screenshot({ path: resolve(process.env.DEW_POINT_SCREENSHOT_DIR, `plan-editor-${width}.png`) });
            await page.locator('#savePlanButton').click();
            await page.locator('#officeToasts .toast', { hasText: 'Plan guardado' }).waitFor();
            const put = state.posts.at(-1);
            assert.equal(put.path, '/api/prefrio/punto-rocio/plan');
            assert.deepEqual(put.body.etapas.map((row) => row.etapa), ['packing', 'salida_prefrio', 'traslado', 'despacho', 'carga_contenedor']);
            assert.deepEqual(put.body.etapas[0], { etapa: 'packing', temperatura_aire_c: 12, temperatura_fruta_c: 5, version_conocida: 0 });
            assert.equal(await page.locator('#chainStrip .dew-chain__stage').count(), 7);
            await assertNoPageOverflow(page);
            assert.deepEqual(errors, []);
            await context.close();
        }
    } finally {
        await browser.close();
    }
});

test('consulta ve el registro del día sin botones de registro ni de supervisión', async () => {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
    try {
        const { context, page, errors } = await openPage(browser, 1280, { supervisor: false });
        assert.ok(await page.locator('#newReadingButton').evaluate((node) => node.classList.contains('is-hidden')));
        assert.ok(await page.locator('#roomsButton').evaluate((node) => node.classList.contains('is-hidden')));
        assert.ok(await page.locator('#marginsButton').evaluate((node) => node.classList.contains('is-hidden')));
        assert.ok(await page.locator('#editPlanButton').evaluate((node) => node.classList.contains('is-hidden')));
        assert.equal(await page.locator('#readingsBody [data-action]').count(), 0);
        assert.match(await page.locator('#readingsBody').textContent(), /Alerta sin acción correctiva/);
        assert.deepEqual(errors, []);
        await context.close();
    } finally {
        await browser.close();
    }
});

test('un enlace con ?fecha abre directamente ese día', async () => {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
    try {
        const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
        await context.clock.setFixedTime(NOW);
        await context.addInitScript(() => {
            localStorage.setItem('estiba_wms_office_token', 'fixture');
            localStorage.setItem('estiba_wms_office_identity', JSON.stringify({ id: 1, nombre: 'Supervisión', rol: 'supervisor_frio', puede_consultar_prefrio: true }));
        });
        const state = { ...fixture(), requests: [] };
        await context.route('http://localhost/**', async (route) => {
            const url = new URL(route.request().url()); const path = url.pathname;
            if (path === '/oficina/prefrio/punto-rocio') return route.fulfill({ body: html, contentType: 'text/html' });
            if (path.startsWith('/build/')) return route.fulfill({ body: readFileSync(resolve(root, `public${path}`)), contentType: extname(path) === '.css' ? 'text/css' : 'text/javascript' });
            if (path === '/api/prefrio/punto-rocio') { state.requests.push(url.searchParams.get('fecha')); return route.fulfill({ json: response(state, url.searchParams.get('fecha'), { registrar: true, supervisar: true }) }); }
            return route.fulfill({ json: {} });
        });
        const page = await context.newPage();
        await page.goto('http://localhost/oficina/prefrio/punto-rocio?fecha=2026-11-01');
        await page.waitForFunction(() => document.querySelector('#dewDateInput').value === '2026-11-01');
        assert.equal(state.requests[0], '2026-11-01');
        await context.close();
    } finally {
        await browser.close();
    }
});
