import { CategoryScale, Chart, Legend, LinearScale, LineController, LineElement, PointElement, Tooltip } from 'chart.js';

import { DEW_POINT_STATES, evaluateReading } from './shared/dew-point.js';
import { readManagementChartPaletteSafely } from './shared/management-chart-theme';
import { byId, escapeHtml as e, message, officeSession } from './shared/packed-fruit-office-session.js';
import { createUuid } from './shared/uuid';

Chart.register(CategoryScale, LinearScale, LineController, LineElement, PointElement, Legend, Tooltip);

/*
 * Prefrío · Punto de rocío: chequeos diarios del aire (T° y humedad) y del margen
 * de la superficie de la fruta frente al punto de rocío. Las mediciones con riesgo
 * piden una acción correctiva a supervisión; no bloquean la operación.
 */
const FALLBACK_PALETTE = { cyan: '#2bb3d9', amber: '#d89b2b', red: '#d9534f', green: '#3c9d5d', blue: '#3f7cc4', muted: '#8aa0b2', grid: 'rgba(138, 160, 178, .25)', tooltipBg: '#102f43', tooltipBorder: '#2a4a60', tooltipText: '#ffffff' };
const DROPS = { si: 'Sí', no: 'No', pendiente: 'Pendiente' };
const state = { data: null, date: null, stage: '', palette: null, note: null };
let chart = null;

const numberFormat = new Intl.NumberFormat('es-CL', { maximumFractionDigits: 1, minimumFractionDigits: 1 });
const formatTemperature = (value) => (value === null || value === undefined ? '—' : `${numberFormat.format(value)} °C`);
const formatPercent = (value) => (value === null || value === undefined ? '—' : `${numberFormat.format(value)} %`);
const zone = () => state.data?.parametros?.zona_horaria || 'America/Santiago';
const formatTime = (value) => new Intl.DateTimeFormat('es-CL', { hour: '2-digit', minute: '2-digit', hourCycle: 'h23', timeZone: zone() }).format(new Date(value));
const formatDay = (value) => new Intl.DateTimeFormat('es-CL', { weekday: 'short', day: 'numeric', timeZone: 'UTC' }).format(new Date(`${value}T12:00:00Z`));
const say = (text, error = false) => message(text, error, 'dewPointMessage');

function todayInPlant() {
    const parts = new Intl.DateTimeFormat('en-CA', { timeZone: zone(), year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(new Date());
    return `${parts.find((part) => part.type === 'year').value}-${parts.find((part) => part.type === 'month').value}-${parts.find((part) => part.type === 'day').value}`;
}
function shiftDate(date, days) {
    const value = new Date(`${date}T12:00:00Z`);
    value.setUTCDate(value.getUTCDate() + days);
    return value.toISOString().slice(0, 10);
}
function localDateTimeValue(date = new Date()) {
    const offset = date.getTimezoneOffset() * 60000;
    return new Date(date.getTime() - offset).toISOString().slice(0, 16);
}
function setBusy(active, text = 'Procesando…') {
    byId('officeLoadingText').textContent = text;
    byId('officeLoading').classList.toggle('is-hidden', !active);
    byId('officeLoading').setAttribute('aria-hidden', String(!active));
}
function toast(text) {
    const node = document.createElement('div');
    node.className = 'toast';
    node.textContent = text;
    byId('officeToasts').append(node);
    window.setTimeout(() => node.remove(), 4500);
}

const session = officeSession('puede_consultar_prefrio', async (isCurrent) => {
    const requested = new URLSearchParams(window.location.search).get('fecha');
    if (!state.date && requested && /^\d{4}-\d{2}-\d{2}$/.test(requested)) state.date = requested;
    await load(isCurrent);
}, () => { state.data = null; state.date = null; state.stage = ''; }, { messageId: 'dewPointMessage', reloadId: 'reloadDewPointButton' });

async function load(isCurrent = () => true) {
    const query = new URLSearchParams();
    if (state.date) query.set('fecha', state.date);
    const data = await session.request(`/api/prefrio/punto-rocio?${query}`);
    if (!isCurrent()) return;
    state.data = data;
    state.date = data.fecha;
    const url = new URL(window.location.href);
    url.searchParams.set('fecha', data.fecha);
    window.history.replaceState(null, '', url);
    render();
}

function render() {
    const { data } = state;
    const summary = data.resumen;
    byId('dewDateInput').value = data.fecha;
    byId('dewDateInput').max = todayInPlant();
    byId('nextDayButton').disabled = data.fecha >= todayInPlant();
    byId('newReadingButton').classList.toggle('is-hidden', data.permisos?.registrar !== true);
    byId('roomsButton').classList.toggle('is-hidden', data.permisos?.supervisar !== true);
    byId('minimumMarginLabel').textContent = numberFormat.format(data.parametros.margen_minimo_c);
    byId('readingCount').textContent = String(summary.registros);
    byId('surfaceCount').textContent = `${summary.con_superficie} con T° superficial`;
    byId('maxDewPoint').textContent = formatTemperature(summary.punto_rocio_maximo_c);
    byId('minMargin').textContent = formatTemperature(summary.margen_minimo_c);
    byId('minMarginHelp').textContent = `Superficie − rocío · mínimo sano ${numberFormat.format(data.parametros.margen_minimo_c)} °C`;
    byId('alertCount').textContent = String(summary.alertas);
    byId('pendingCount').textContent = `${summary.alertas_pendientes} sin acción`;
    byId('dropCount').textContent = String(summary.gotas_visibles);
    renderFilters();
    renderTable();
    renderChart();
    renderHistory();
}

function visibleReadings() {
    return (state.data?.mediciones || []).filter((reading) => !state.stage || reading.etapa === state.stage);
}

function renderFilters() {
    const counts = new Map((state.data.por_etapa || []).map((row) => [row.etapa, row]));
    const all = state.data.mediciones.filter((reading) => !reading.anulada).length;
    byId('stageFilters').innerHTML = [
        `<button type="button" data-stage="" aria-pressed="${state.stage === ''}">Todas <b>${all}</b></button>`,
        ...state.data.etapas.filter((stage) => counts.has(stage.valor)).map((stage) => {
            const row = counts.get(stage.valor);
            return `<button type="button" data-stage="${e(stage.valor)}" aria-pressed="${state.stage === stage.valor}">${e(stage.etiqueta)} <b>${row.registros}</b>${row.alertas ? `<i title="Alertas">${row.alertas}</i>` : ''}</button>`;
        }),
    ].join('');
}

function stateBadge(reading) {
    const meta = DEW_POINT_STATES[reading.estado] || DEW_POINT_STATES.sin_superficie;
    return `<span class="dew-badge" data-tone="${meta.tone}">${e(reading.estado_etiqueta || meta.label)}</span>`;
}

function renderTable() {
    const readings = visibleReadings();
    const supervisor = state.data.permisos?.supervisar === true;
    byId('readingsBody').innerHTML = readings.length ? readings.map((reading) => {
        const actions = [];
        if (supervisor && reading.alerta_pendiente) actions.push(`<button class="primary-button" type="button" data-action="accion" data-id="${e(reading.id)}">Registrar acción</button>`);
        if (supervisor && !reading.anulada) actions.push(`<button class="secondary-button" type="button" data-action="anular" data-id="${e(reading.id)}">Anular</button>`);
        const followUp = reading.anulada
            ? `<small class="dew-followup">Anulada por ${e(reading.anulada_por || '—')}: ${e(reading.motivo_anulacion)}</small>`
            : (reading.accion_correctiva
                ? `<small class="dew-followup">Acción de ${e(reading.accion_registrada_por || '—')}: ${e(reading.accion_correctiva)}</small>`
                : (reading.alerta_pendiente ? '<small class="dew-followup dew-followup--pending">Alerta sin acción correctiva</small>' : ''));
        return `<tr class="${reading.anulada ? 'is-voided' : ''}${reading.alerta_pendiente ? ' is-alert' : ''}">
            <td><strong>${e(formatTime(reading.medido_at))}</strong></td>
            <td><strong>${e(reading.etapa_etiqueta)}</strong><small>${e(reading.punto)}${reading.referencia ? ` · ${e(reading.referencia)}` : ''}</small></td>
            <td class="dew-num">${e(formatTemperature(reading.temperatura_ambiente_c))}<small>HR ${e(formatPercent(reading.humedad_relativa))}</small></td>
            <td class="dew-num">${e(formatTemperature(reading.temperatura_superficie_c))}</td>
            <td class="dew-num"><strong>${e(formatTemperature(reading.punto_rocio_c))}</strong></td>
            <td class="dew-num">${e(formatTemperature(reading.margen_c))}</td>
            <td>${stateBadge(reading)}${followUp}${reading.observacion ? `<small>${e(reading.observacion)}</small>` : ''}</td>
            <td>${reading.gotas_visibles === 'si' ? '<span class="dew-badge" data-tone="danger">Sí</span>' : e(DROPS[reading.gotas_visibles] || '—')}</td>
            <td>${e(reading.registrado_por || '—')}${reading.dispositivo ? `<small>${e(reading.dispositivo)}</small>` : ''}</td>
            <td class="dew-actions-cell">${actions.length ? `<div class="dew-row-actions">${actions.join('')}</div>` : ''}</td>
        </tr>`;
    }).join('') : `<tr><td class="dew-empty-row" colspan="10">${state.stage ? 'Sin mediciones de esta etapa en el día.' : 'Aún no hay mediciones este día.'}</td></tr>`;
}

function renderChart() {
    const readings = visibleReadings().filter((reading) => !reading.anulada);
    byId('chartEmpty').classList.toggle('is-hidden', readings.length > 0);
    byId('chartSummary').textContent = readings.length ? `${readings.length} ${readings.length === 1 ? 'medición' : 'mediciones'}` : 'Sin mediciones';
    state.palette = readManagementChartPaletteSafely(state.palette, FALLBACK_PALETTE);
    const palette = state.palette;
    const series = [
        { label: 'T° ambiente', key: 'temperatura_ambiente_c', color: palette.blue },
        { label: 'Punto de rocío', key: 'punto_rocio_c', color: palette.red },
        { label: 'T° superficial', key: 'temperatura_superficie_c', color: palette.green },
    ];
    const config = {
        type: 'line',
        data: {
            labels: readings.map((reading) => `${formatTime(reading.medido_at)} · ${reading.etapa_etiqueta}`),
            datasets: series.map((serie) => ({
                label: serie.label,
                data: readings.map((reading) => reading[serie.key]),
                borderColor: serie.color,
                backgroundColor: serie.color,
                borderWidth: 2,
                pointRadius: 3,
                spanGaps: true,
                tension: 0.25,
            })),
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: false,
            interaction: { mode: 'index', intersect: false },
            scales: {
                x: { ticks: { color: palette.muted, maxRotation: 0, autoSkip: true, callback(value) { return String(this.getLabelForValue(value)).split(' · ')[0]; } }, grid: { color: palette.grid } },
                y: { title: { display: true, text: '°C', color: palette.muted }, ticks: { color: palette.muted }, grid: { color: palette.grid } },
            },
            plugins: {
                legend: { labels: { color: palette.muted, boxWidth: 12 } },
                tooltip: {
                    backgroundColor: palette.tooltipBg, borderColor: palette.tooltipBorder, borderWidth: 1, titleColor: palette.tooltipText, bodyColor: palette.tooltipText,
                    callbacks: { label: (context) => `${context.dataset.label}: ${formatTemperature(context.parsed.y)}` },
                },
            },
        },
    };
    if (chart) chart.destroy();
    chart = readings.length ? new Chart(byId('dewChart'), config) : null;
}

function renderHistory() {
    const days = state.data.historial || [];
    const maximum = Math.max(1, ...days.map((day) => day.registros));
    byId('historyStrip').innerHTML = days.map((day) => {
        const tone = day.alertas_pendientes ? 'danger' : (day.alertas ? 'warning' : (day.registros ? 'success' : 'neutral'));
        const height = Math.max(6, Math.round((day.registros / maximum) * 100));
        const title = `${day.fecha}: ${day.registros} ${day.registros === 1 ? 'medición' : 'mediciones'}, ${day.alertas} ${day.alertas === 1 ? 'alerta' : 'alertas'}${day.alertas_pendientes ? ` (${day.alertas_pendientes} sin acción)` : ''}`;
        return `<button type="button" class="dew-day-bar" data-date="${e(day.fecha)}" data-tone="${tone}" aria-pressed="${day.fecha === state.data.fecha}" title="${e(title)}" aria-label="${e(title)}">
            <span class="dew-day-bar__value"><i style="height:${height}%"></i></span>
            <strong>${day.registros}</strong><small>${e(formatDay(day.fecha))}</small>
        </button>`;
    }).join('');
}

function pointOptions(selected = '') {
    const points = state.data.puntos;
    const group = (label, type, items) => (items.length
        ? `<optgroup label="${e(label)}">${items.map((item) => `<option value="${e(`${type}:${item.id}`)}"${selected === `${type}:${item.id}` ? ' selected' : ''}>${e(item.nombre)}</option>`).join('')}</optgroup>`
        : '');
    const rooms = points.salas.filter((room) => room.activa);
    const options = group('Salas', 'sala', rooms) + group('Túneles de prefrío', 'tunel', points.tuneles) + group('Cámaras', 'camara', points.camaras);
    return options ? `<option value="">Selecciona…</option>${options}` : '<option value="">No hay puntos activos: agrega una sala</option>';
}

function readNumber(form, name) {
    const raw = String(form.elements[name].value ?? '').trim().replace(',', '.');
    return raw === '' ? null : Number(raw);
}

function updatePreview() {
    const form = byId('readingForm');
    const result = byId('readingResult');
    const ambient = readNumber(form, 'temperatura_ambiente_c');
    const humidity = readNumber(form, 'humedad_relativa');
    const surface = readNumber(form, 'temperatura_superficie_c');
    const evaluation = ambient === null || humidity === null ? null
        : evaluateReading({ ambient, humidity, surface, minimumMargin: state.data?.parametros?.margen_minimo_c ?? 2 });
    if (!evaluation) {
        result.dataset.tone = 'neutral';
        result.innerHTML = humidity !== null && (humidity <= 0 || humidity > 100)
            ? 'La humedad relativa debe estar entre 0 y 100 %.'
            : 'Ingresa T° y humedad para calcular el punto de rocío.';
        return;
    }
    const meta = DEW_POINT_STATES[evaluation.state];
    result.dataset.tone = meta.tone;
    result.innerHTML = `<span><small>PUNTO DE ROCÍO</small><strong>${e(formatTemperature(evaluation.dewPoint))}</strong></span>
        <span><small>MARGEN VS FRUTA</small><strong>${e(formatTemperature(evaluation.margin))}</strong></span>
        <span><small>VAPOR EN EL AIRE</small><strong>${e(numberFormat.format(evaluation.vapor))} g/m³</strong></span>
        <span class="dew-result__state"><small>RIESGO AL SELLAR</small><strong>${e(meta.label)}</strong><em>${e(meta.help)}</em></span>`;
}

function openReading() {
    const form = byId('readingForm');
    form.reset();
    byId('readingError').textContent = '';
    form.elements.etapa.innerHTML = state.data.etapas.map((stage) => `<option value="${e(stage.valor)}">${e(stage.etiqueta)}</option>`).join('');
    if (state.stage) form.elements.etapa.value = state.stage;
    form.elements.punto.innerHTML = pointOptions();
    const today = todayInPlant();
    const oldestAllowed = shiftDate(today, -(state.data.parametros?.dias_registro_retroactivo ?? 7));
    // Un día pasado dentro de la ventana permitida propone el mediodía de ese día; fuera de ella, la hora actual.
    form.elements.medido_at.value = state.data.fecha === today || state.data.fecha < oldestAllowed ? localDateTimeValue() : `${state.data.fecha}T12:00`;
    form.elements.medido_at.max = localDateTimeValue();
    updatePreview();
    byId('readingDialog').showModal();
    form.elements.temperatura_ambiente_c.focus();
}

byId('readingForm').addEventListener('input', updatePreview);
byId('readingForm').addEventListener('submit', async (event) => {
    if (event.submitter?.value === 'cancel') return;
    event.preventDefault();
    const form = event.currentTarget;
    const error = byId('readingError');
    error.textContent = '';
    const [type, id] = String(form.elements.punto.value || '').split(':');
    const ambient = readNumber(form, 'temperatura_ambiente_c');
    const humidity = readNumber(form, 'humedad_relativa');
    const fail = (text, field) => { error.textContent = text; form.elements[field]?.focus(); };
    if (!type || !id) return fail('Selecciona dónde se tomó la medición.', 'punto');
    if (ambient === null || !Number.isFinite(ambient)) return fail('Ingresa la temperatura del ambiente.', 'temperatura_ambiente_c');
    if (humidity === null || !(humidity > 0 && humidity <= 100)) return fail('Ingresa la humedad relativa entre 0 y 100 %.', 'humedad_relativa');
    const measuredAt = new Date(form.elements.medido_at.value);
    if (Number.isNaN(measuredAt.getTime())) return fail('Ingresa la fecha y hora de la medición.', 'medido_at');
    const button = byId('saveReadingButton');
    button.disabled = true;
    setBusy(true, 'Guardando medición…');
    try {
        const surface = readNumber(form, 'temperatura_superficie_c');
        const payload = await session.request('/api/prefrio/punto-rocio', {
            method: 'POST',
            body: JSON.stringify({
                operacion_id: createUuid(),
                etapa: form.elements.etapa.value,
                punto_tipo: type,
                punto_id: id,
                referencia: form.elements.referencia.value.trim() || null,
                temperatura_ambiente_c: ambient,
                humedad_relativa: humidity,
                temperatura_superficie_c: surface,
                gotas_visibles: form.elements.gotas_visibles.value,
                observacion: form.elements.observacion.value.trim() || null,
                medido_at: measuredAt.toISOString(),
            }),
        });
        byId('readingDialog').close();
        state.date = payload.data.fecha;
        await load();
        toast(payload.data.requiere_accion
            ? `Medición guardada: ${payload.data.gotas_visibles === 'si' ? 'gotas visibles' : payload.data.estado_etiqueta.toLowerCase()}. Supervisión de frío fue avisada.`
            : 'Medición guardada.');
    } catch (requestError) {
        error.textContent = requestError.message;
    } finally {
        button.disabled = false;
        setBusy(false);
    }
});

function openNote(reading, mode) {
    state.note = { reading, mode };
    const form = byId('noteForm');
    form.reset();
    byId('noteError').textContent = '';
    const summary = `${formatTime(reading.medido_at)} · ${reading.etapa_etiqueta} · ${reading.punto}: rocío ${formatTemperature(reading.punto_rocio_c)}, margen ${formatTemperature(reading.margen_c)}.`;
    byId('noteEyebrow').textContent = mode === 'accion' ? 'ALERTA' : 'ANULACIÓN';
    byId('noteDialogTitle').textContent = mode === 'accion' ? 'Registrar acción correctiva' : 'Anular medición';
    byId('noteLabel').textContent = mode === 'accion' ? 'Acción correctiva *' : 'Motivo de anulación *';
    byId('noteDescription').textContent = mode === 'accion'
        ? `${summary} Indica qué se hizo (por ejemplo, bajar la humedad de la sala, esperar antes de sellar o secar la fruta).`
        : `${summary} La medición queda en el registro como anulada y no cuenta en el resumen.`;
    byId('noteDialog').showModal();
    form.elements.texto.focus();
}

byId('noteForm').addEventListener('submit', async (event) => {
    if (event.submitter?.value === 'cancel') return;
    event.preventDefault();
    const text = event.currentTarget.elements.texto.value.trim();
    const error = byId('noteError');
    if (text.length < 5) { error.textContent = 'Escribe al menos 5 caracteres.'; return; }
    const { reading, mode } = state.note;
    setBusy(true, mode === 'accion' ? 'Registrando acción…' : 'Anulando medición…');
    try {
        await session.request(`/api/prefrio/punto-rocio/${reading.id}/${mode}`, { method: 'POST', body: JSON.stringify({ texto: text }) });
        byId('noteDialog').close();
        await load();
        toast(mode === 'accion' ? 'Acción correctiva registrada.' : 'Medición anulada.');
    } catch (requestError) {
        error.textContent = requestError.message;
    } finally {
        setBusy(false);
    }
});

function renderRooms() {
    byId('roomList').innerHTML = state.data.puntos.salas.length
        ? state.data.puntos.salas.map((room) => `<div class="dew-room${room.activa ? '' : ' is-inactive'}">
            <input value="${e(room.nombre)}" maxlength="80" aria-label="Nombre de ${e(room.nombre)}" data-room-name="${e(room.id)}">
            <button class="secondary-button" type="button" data-room-save="${e(room.id)}">Guardar</button>
            <button class="secondary-button" type="button" data-room-toggle="${e(room.id)}">${room.activa ? 'Desactivar' : 'Activar'}</button>
        </div>`).join('')
        : '<p class="dew-empty">Aún no hay salas. Agrega las zonas donde se mide (sellado, paletizado…).</p>';
}

async function saveRoom(body, id = null) {
    byId('roomError').textContent = '';
    try {
        await session.request(`/api/prefrio/punto-rocio/salas${id ? `/${id}` : ''}`, { method: id ? 'PUT' : 'POST', body: JSON.stringify(body) });
        await load();
        renderRooms();
        return true;
    } catch (requestError) {
        byId('roomError').textContent = requestError.message;
        return false;
    }
}

byId('roomForm').addEventListener('submit', async (event) => {
    if (event.submitter?.value === 'cancel') return;
    event.preventDefault();
    const input = event.currentTarget.elements.nombre;
    if (input.value.trim().length < 2) { byId('roomError').textContent = 'Escribe el nombre de la sala.'; input.focus(); return; }
    if (await saveRoom({ nombre: input.value.trim() })) { input.value = ''; toast('Sala agregada.'); }
});
byId('roomList').addEventListener('click', async (event) => {
    const saveId = event.target.closest('[data-room-save]')?.dataset.roomSave;
    const toggleId = event.target.closest('[data-room-toggle]')?.dataset.roomToggle;
    const room = state.data.puntos.salas.find((item) => item.id === (saveId || toggleId));
    if (!room) return;
    const name = byId('roomList').querySelector(`[data-room-name="${CSS.escape(room.id)}"]`).value.trim();
    if (await saveRoom({ nombre: saveId ? name : room.nombre, activa: toggleId ? !room.activa : room.activa, version_conocida: room.version }, room.id)) {
        toast(toggleId ? (room.activa ? 'Sala desactivada.' : 'Sala activada.') : 'Sala guardada.');
    }
});

byId('readingsBody').addEventListener('click', (event) => {
    const button = event.target.closest('[data-action]');
    if (!button) return;
    const reading = state.data.mediciones.find((item) => item.id === button.dataset.id);
    if (reading) openNote(reading, button.dataset.action);
});
byId('stageFilters').addEventListener('click', (event) => {
    const button = event.target.closest('[data-stage]');
    if (!button) return;
    state.stage = button.dataset.stage;
    renderFilters();
    renderTable();
    renderChart();
});
byId('historyStrip').addEventListener('click', (event) => {
    const date = event.target.closest('[data-date]')?.dataset.date;
    if (date) changeDate(date);
});

async function changeDate(date) {
    if (!date || date > todayInPlant()) return;
    state.date = date;
    try { await load(); say(''); } catch (requestError) { say(requestError.message, true); }
}

byId('dewDateInput').addEventListener('change', (event) => changeDate(event.target.value));
byId('previousDayButton').addEventListener('click', () => changeDate(shiftDate(state.date, -1)));
byId('nextDayButton').addEventListener('click', () => changeDate(shiftDate(state.date, 1)));
byId('todayButton').addEventListener('click', () => changeDate(todayInPlant()));
byId('newReadingButton').addEventListener('click', openReading);
byId('roomsButton').addEventListener('click', () => {
    renderRooms();
    byId('roomError').textContent = '';
    byId('roomsDialog').showModal();
});

session.start();
