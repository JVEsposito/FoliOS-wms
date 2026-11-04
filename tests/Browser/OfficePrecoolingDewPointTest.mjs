import assert from 'node:assert/strict';
import { mkdirSync, readFileSync } from 'node:fs';
import { extname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
const html = readFileSync(resolve(root, 'storage/app/ui/oficina/punto-rocio.html'), 'utf8');
const NOW = new Date('2026-11-04T15:30:00Z'); // 12:30 en Santiago.
const ETAPAS = [['sellado', 'Sellado'], ['paletizado', 'Paletizado'], ['ingreso_prefrio', 'Ingreso prefrío'], ['salida_prefrio', 'Salida prefrío'], ['almacenamiento', 'Almacenamiento'], ['otro', 'Otro']];
const label = (etapa) => ETAPAS.find(([value]) => value === etapa)[1];

function reading(overrides) {
    return {
        id: 'm-1', etapa: 'sellado', etapa_etiqueta: label(overrides.etapa || 'sellado'), punto_tipo: 'sala', punto_id: 'sala-sellado', punto: 'Sala de sellado', referencia: null,
        temperatura_ambiente_c: 15, humedad_relativa: 62.9, temperatura_superficie_c: 7.1, punto_rocio_c: 7.99, margen_c: -0.89, vapor_g_m3: 8.05, margen_minimo_c: 2,
        estado: 'condensacion_posible', estado_etiqueta: 'Condensación posible', gotas_visibles: 'pendiente', requiere_accion: true, alerta_pendiente: true, observacion: null,
        medido_at: '2026-11-04T11:10:00Z', fecha: '2026-11-04', registrado_por: 'Calidad turno día', dispositivo: 'Tablet prefrío',
        accion_correctiva: null, accion_registrada_por: null, accion_registrada_at: null, anulada: false, anulada_at: null, anulada_por: null, motivo_anulacion: null,
        ...overrides,
    };
}

function fixture() {
    return {
        rooms: [{ id: 'sala-sellado', nombre: 'Sala de sellado', activa: true, version: 1 }],
        readings: [
            reading({}),
            reading({ id: 'm-2', etapa: 'ingreso_prefrio', etapa_etiqueta: 'Ingreso prefrío', punto_tipo: 'tunel', punto_id: 'tunel-1', punto: 'TP-01 · Túnel 1', temperatura_ambiente_c: 4, humedad_relativa: 88, temperatura_superficie_c: 6.5, punto_rocio_c: 2.13, margen_c: 4.37, estado: 'margen_favorable', estado_etiqueta: 'Margen favorable', gotas_visibles: 'no', requiere_accion: false, alerta_pendiente: false, medido_at: '2026-11-04T13:30:00Z' }),
            reading({ id: 'm-3', etapa: 'paletizado', etapa_etiqueta: 'Paletizado', punto: 'Sala de sellado', temperatura_superficie_c: null, margen_c: null, estado: 'sin_superficie', estado_etiqueta: 'Medir superficie', requiere_accion: false, alerta_pendiente: false, medido_at: '2026-11-04T14:00:00Z', anulada: true, anulada_por: 'Supervisión frío', motivo_anulacion: 'Termohigrómetro sin estabilizar.' }),
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
        parametros: { margen_minimo_c: 2, dias_registro_retroactivo: 7, zona_horaria: 'America/Santiago' },
        permisos: permissions,
    };
}

async function openPage(browser, width, { supervisor = true } = {}) {
    const context = await browser.newContext({ viewport: { width, height: 1000 }, timezoneId: 'America/Santiago' });
    await context.clock.setFixedTime(NOW);
    const identity = { id: 1, nombre: supervisor ? 'Supervisión frío' : 'Consulta', rol: supervisor ? 'supervisor_frio' : 'consulta', puede_consultar_prefrio: true };
    await context.addInitScript((user) => {
        localStorage.setItem('estiba_wms_office_token', 'fixture');
        localStorage.setItem('estiba_wms_office_identity', JSON.stringify(user));
        // La oficina se abre por HTTP en la red de la planta: ahí el navegador no ofrece randomUUID.
        delete Crypto.prototype.randomUUID;
    }, identity);
    const state = { ...fixture(), requests: [], posts: [] };
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
            const created = reading({
                id: 'm-new', etapa: body.etapa, etapa_etiqueta: label(body.etapa), punto_tipo: body.punto_tipo, punto_id: body.punto_id, punto: 'Sala de sellado',
                temperatura_ambiente_c: body.temperatura_ambiente_c, humedad_relativa: body.humedad_relativa, temperatura_superficie_c: body.temperatura_superficie_c,
                punto_rocio_c: 7.99, margen_c: 0.81, estado: 'margen_bajo', estado_etiqueta: 'Margen bajo', gotas_visibles: body.gotas_visibles, medido_at: body.medido_at,
                registrado_por: 'Supervisión frío', dispositivo: null,
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
            await page.locator('#saveReadingButton').click();
            await page.locator('#readingDialog').waitFor({ state: 'hidden' });
            const [post] = state.posts;
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

test('consulta ve el registro del día sin botones de registro ni de supervisión', async () => {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
    try {
        const { context, page, errors } = await openPage(browser, 1280, { supervisor: false });
        assert.ok(await page.locator('#newReadingButton').evaluate((node) => node.classList.contains('is-hidden')));
        assert.ok(await page.locator('#roomsButton').evaluate((node) => node.classList.contains('is-hidden')));
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
