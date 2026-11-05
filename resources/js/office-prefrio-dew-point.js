import { CategoryScale, Chart, Legend, LinearScale, LineController, LineElement, PointElement, Tooltip } from 'chart.js';

import { DEW_POINT_STATES, coldChainPlan, compareSurface, evaluateReading, humidityForDewPoint, worstDewPointState } from './shared/dew-point.js';
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
const state = { data: null, date: null, stage: '', palette: null, note: null, attempt: null };
let chart = null;

const numberFormat = new Intl.NumberFormat('es-CL', { maximumFractionDigits: 1, minimumFractionDigits: 1 });
const formatTemperature = (value) => (value === null || value === undefined ? '—' : `${numberFormat.format(value)} °C`);
const formatPercent = (value) => (value === null || value === undefined ? '—' : `${numberFormat.format(value)} %`);
const zone = () => state.data?.parametros?.zona_horaria || 'America/Santiago';
const formatTime = (value) => new Intl.DateTimeFormat('es-CL', { hour: '2-digit', minute: '2-digit', hourCycle: 'h23', timeZone: zone() }).format(new Date(value));
const formatShortDate = (value) => new Intl.DateTimeFormat('es-CL', { day: '2-digit', month: '2-digit', timeZone: zone() }).format(new Date(value));
const plantDate = (value) => new Intl.DateTimeFormat('en-CA', { timeZone: zone(), year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date(value));
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
    byId('marginsButton').classList.toggle('is-hidden', data.permisos?.supervisar !== true);
    byId('editPlanButton').classList.toggle('is-hidden', data.permisos?.supervisar !== true);
    byId('outOfPlanCount').textContent = String(summary.fuera_de_plan ?? 0);
    byId('minimumMarginLabel').textContent = numberFormat.format(data.parametros.margen_minimo_c);
    byId('readingCount').textContent = String(summary.registros);
    byId('surfaceCount').textContent = `${summary.con_superficie} con T° superficial`;
    byId('maxDewPoint').textContent = formatTemperature(summary.punto_rocio_maximo_c);
    byId('minMargin').textContent = formatTemperature(summary.margen_minimo_c);
    byId('alertCount').textContent = String(summary.alertas);
    byId('pendingCount').textContent = `${summary.alertas_pendientes} sin acción`;
    byId('dropCount').textContent = String(summary.gotas_visibles);
    renderFilters();
    renderChain();
    renderTable();
    renderChart();
    renderHistory();
}

const formatHumidity = (value) => (value === null || value === undefined ? '—' : `${new Intl.NumberFormat('es-CL', { maximumFractionDigits: 0 }).format(Math.floor(Math.round(value * 10) / 10))} %`);

function planFor(stage) {
    const row = (state.data?.plan?.etapas || []).find((item) => item.etapa === stage);
    return row && row.planificada && row.rocio_maximo_c !== null ? row : null;
}

/** Franja de la cadena: objetivo de cada etapa planificada y su medición más reciente del día. */
function renderChain() {
    const stages = (state.data.plan?.etapas || []).filter((row) => row.planificada);
    const freezing = state.data.plan?.temperatura_congelacion_c;
    const today = state.data.fecha === todayInPlant();
    byId('chainNote').classList.toggle('is-hidden', stages.length === 0);
    if (!stages.length) {
        byId('chainStrip').innerHTML = `<li class="dew-chain__empty">Aún no hay plan. ${state.data.permisos?.supervisar ? 'Usa «Editar plan» para definir' : 'Supervisión de frío define'}, por etapa, la T° del aire y la T° más baja de la fruta; FoliOS calcula el rocío y la humedad máximos.</li>`;
        return;
    }
    byId('chainStrip').innerHTML = stages.map((row) => {
        const last = state.data.mediciones.filter((item) => item.etapa === row.etapa && !item.anulada).at(-1);
        const out = last?.plan?.fuera === true;
        const tone = !last?.plan ? 'neutral' : (out ? 'warning' : 'success');
        const lastText = last
            ? `${today ? 'Hoy' : 'Último'} ${formatTime(last.medido_at)} · rocío ${formatTemperature(last.punto_rocio_c)}${last.plan ? (out ? ' · fuera de plan' : ' · dentro del plan') : ''}`
            : (today ? 'Sin medición hoy' : 'Sin medición ese día');
        const humidity = row.humedad_maxima === null ? 'Define la T° del aire' : (row.humedad_maxima >= 100 ? 'Sin límite de HR' : `HR ≤ ${formatHumidity(row.humedad_maxima)}`);
        return `<li class="dew-chain__stage" data-tone="${tone}" data-stage="${e(row.etapa)}">
            <small>${row.orden}. ${e(row.etiqueta)}</small>
            <span>Aire ${e(formatTemperature(row.temperatura_aire_c))} · fruta ${e(formatTemperature(row.temperatura_fruta_c))}</span>
            <strong>${e(humidity)}</strong>
            <span>Rocío ≤ ${e(formatTemperature(row.rocio_maximo_c))}${row.limitante === 'envase' ? ' <em>por el envase</em>' : ''}</span>
            ${row.cerca_congelacion ? `<span class="dew-chain__freeze">Cerca de congelación (${e(formatTemperature(freezing))})</span>` : ''}
            <span class="dew-chain__last">${e(lastText)}</span>
        </li>`;
    }).join('');
}

function visibleReadings() {
    return (state.data?.mediciones || []).filter((reading) => !state.stage || reading.etapa === state.stage);
}

/** Margen mínimo vigente de una etapa (ajustado por supervisión o el de configuración). */
function marginFor(stage) {
    const row = (state.data?.parametros?.margenes_por_etapa || []).find((item) => item.etapa === stage);
    return row ? row.margen_minimo_c : (state.data?.parametros?.margen_minimo_c ?? 2);
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

function smallBadge(stateValue) {
    const meta = DEW_POINT_STATES[stateValue] || DEW_POINT_STATES.sin_superficie;
    return `<span class="dew-badge dew-badge--small" data-tone="${meta.tone}">${e(meta.label)}</span>`;
}

function referenceMoment(value, reading) {
    if (!value) return '—';
    return plantDate(value) === reading.fecha ? formatTime(value) : `${formatShortDate(value)} ${formatTime(value)}`;
}

function planLine(reading) {
    if (!reading.plan) return '';
    return `<small class="dew-link-line"><b>Plan</b> ${reading.plan.fuera ? '<span class="dew-badge dew-badge--small" data-tone="warning">Fuera de plan</span>' : '<span class="dew-badge dew-badge--small" data-tone="success">Dentro del plan</span>'} rocío ≤ ${e(formatTemperature(reading.plan.rocio_maximo_c))} · HR ≤ ${e(formatHumidity(reading.plan.humedad_maxima))} a ${e(formatTemperature(reading.temperatura_ambiente_c))}</small>`;
}

/** Comparaciones vinculadas guardadas con la medición: aire del envase y fruta que llega. */
function linkedLines(reading) {
    const lines = [];
    if (reading.envase) {
        const link = reading.envase;
        lines.push(`<small class="dew-link-line"><b>Envase</b> ${smallBadge(link.estado)} margen ${e(formatTemperature(link.margen_c))} · rocío del sellado ${e(formatTemperature(link.punto_rocio_c))} (${e(referenceMoment(link.medido_at, reading))}, ${e(link.punto || '—')})${link.referencia_anulada ? ' · <em>sellado anulado</em>' : ''}</small>`);
    }
    if (reading.origen) {
        const link = reading.origen;
        lines.push(`<small class="dew-link-line"><b>Al llegar</b> ${smallBadge(link.estado)} margen ${e(formatTemperature(link.margen_c))} · fruta a ${e(formatTemperature(link.temperatura_superficie_c))} desde ${e(link.etapa_etiqueta || '—')} (${e(referenceMoment(link.medido_at, reading))}, ${e(link.punto || '—')})${link.referencia_anulada ? ' · <em>origen anulado</em>' : ''}</small>`);
    }
    return lines.join('');
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
            <td>${stateBadge(reading)}${linkedLines(reading)}${planLine(reading)}${followUp}${reading.observacion ? `<small>${e(reading.observacion)}</small>` : ''}</td>
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

function selectedReference(name) {
    const id = byId('readingForm').elements[name].value;
    return id ? (state.data?.referencias || []).find((item) => item.id === id) || null : null;
}

function referenceLabel(item, kind, measuredDate) {
    const when = measuredDate && plantDate(item.medido_at) !== measuredDate ? `${formatShortDate(item.medido_at)} ${formatTime(item.medido_at)}` : formatTime(item.medido_at);
    const where = `${item.punto}${item.referencia ? ` · ${item.referencia}` : ''}`;
    // En la lista de sellados la etapa sobra: se muestra el aire que quedó en la bolsa.
    return kind === 'sellado'
        ? `${when} · ${where} · rocío ${formatTemperature(item.punto_rocio_c)} (${formatTemperature(item.temperatura_ambiente_c)} / ${formatPercent(item.humedad_relativa)})`
        : `${when} · ${item.etapa_etiqueta} · ${where} · fruta a ${formatTemperature(item.temperatura_superficie_c)}`;
}

/** Opciones de vínculo: mediciones anteriores a la hora elegida, dentro de la ventana permitida. */
function renderReferenceOptions() {
    const form = byId('readingForm');
    const measuredAt = new Date(form.elements.medido_at.value);
    const valid = !Number.isNaN(measuredAt.getTime());
    const hours = state.data?.parametros?.horas_referencia ?? 72;
    const measuredDate = valid ? plantDate(measuredAt) : null;
    const candidates = (state.data?.referencias || []).filter((item) => {
        const at = new Date(item.medido_at).getTime();
        return valid && at <= measuredAt.getTime() && at >= measuredAt.getTime() - hours * 3600000;
    });
    const fill = (name, items, kind, empty) => {
        const select = form.elements[name];
        const current = select.value;
        select.innerHTML = `<option value="">${e(empty)}</option>${items.map((item) => `<option value="${e(item.id)}">${e(referenceLabel(item, kind, measuredDate))}</option>`).join('')}`;
        select.value = items.some((item) => item.id === current) ? current : '';
    };
    const sealing = form.elements.etapa.value === 'sellado';
    fill('sellado_referencia_id', sealing ? [] : candidates.filter((item) => item.etapa === 'sellado'), 'sellado',
        sealing ? 'No aplica: el sellado evalúa su propio aire' : 'Sin vincular');
    form.elements.sellado_referencia_id.disabled = sealing;
    fill('origen_referencia_id', candidates.filter((item) => item.temperatura_superficie_c !== null), 'origen', 'Sin vincular');
}

function linkRow(title, comparison, detail) {
    const meta = DEW_POINT_STATES[comparison.state];
    return `<li data-tone="${meta.tone}"><b>${e(title)}</b><span>${e(detail)} · margen ${e(formatTemperature(comparison.margin))}</span><strong>${e(meta.label)}</strong></li>`;
}

function updatePreview() {
    const form = byId('readingForm');
    const result = byId('readingResult');
    const ambient = readNumber(form, 'temperatura_ambiente_c');
    const humidity = readNumber(form, 'humedad_relativa');
    const surface = readNumber(form, 'temperatura_superficie_c');
    const stage = form.elements.etapa.value;
    const minimum = marginFor(stage);
    const evaluation = ambient === null || humidity === null ? null
        : evaluateReading({ ambient, humidity, surface, minimumMargin: minimum });
    const sealed = selectedReference('sellado_referencia_id');
    const origin = selectedReference('origen_referencia_id');
    if (!evaluation) {
        result.dataset.tone = 'neutral';
        result.innerHTML = humidity !== null && (humidity <= 0 || humidity > 100)
            ? 'La humedad relativa debe estar entre 0 y 100 %.'
            : 'Ingresa T° y humedad para calcular el punto de rocío.';
        return;
    }
    const links = [];
    const states = [evaluation.state];
    if (sealed) {
        const comparison = compareSurface(surface, sealed.punto_rocio_c, minimum);
        states.push(comparison.state);
        links.push(linkRow('Dentro del envase', comparison, `rocío del sellado ${formatTemperature(sealed.punto_rocio_c)} vs superficie ${formatTemperature(surface)}`));
    }
    if (origin) {
        const comparison = compareSurface(origin.temperatura_superficie_c, evaluation.dewPoint, minimum);
        states.push(comparison.state);
        links.push(linkRow('Al llegar', comparison, `fruta a ${formatTemperature(origin.temperatura_superficie_c)} desde ${origin.etapa_etiqueta} vs rocío de este punto ${formatTemperature(evaluation.dewPoint)}`));
    }
    const plan = planFor(stage);
    const outOfPlan = plan ? evaluation.dewPoint > plan.rocio_maximo_c : false;
    if (plan) {
        const humidityLimit = humidityForDewPoint(ambient, plan.rocio_maximo_c);
        links.push(`<li data-tone="${outOfPlan ? 'warning' : 'success'}"><b>Plan de la etapa</b><span>rocío ≤ ${e(formatTemperature(plan.rocio_maximo_c))} · a ${e(formatTemperature(ambient))} la HR debe ser ≤ ${e(formatHumidity(humidityLimit))}</span><strong>${outOfPlan ? 'Fuera del plan' : 'Dentro del plan'}</strong></li>`);
    }
    const meta = DEW_POINT_STATES[evaluation.state];
    const worst = worstDewPointState(states);
    const worstTone = worst === 'sin_superficie' ? 'neutral' : DEW_POINT_STATES[worst].tone;
    // Quedar fuera del plan no es una alerta, pero se marca si nada más grave lo hace.
    result.dataset.tone = outOfPlan && ['neutral', 'success'].includes(worstTone) ? 'warning' : worstTone;
    result.innerHTML = `<span><small>PUNTO DE ROCÍO</small><strong>${e(formatTemperature(evaluation.dewPoint))}</strong></span>
        <span><small>MARGEN VS FRUTA</small><strong>${e(formatTemperature(evaluation.margin))}</strong></span>
        <span><small>VAPOR EN EL AIRE</small><strong>${e(numberFormat.format(evaluation.vapor))} g/m³</strong></span>
        <span class="dew-result__state" data-tone="${meta.tone}"><small>${stage === 'sellado' ? 'RIESGO AL SELLAR' : 'RIESGO EN ESTE PUNTO'}</small><strong>${e(meta.label)}</strong><em>${e(meta.help)} Margen mínimo de la etapa: ${e(formatTemperature(minimum))}.</em></span>
        ${links.length ? `<ul class="dew-result__links">${links.join('')}</ul>` : ''}`;
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
    renderReferenceOptions();
    updatePreview();
    byId('readingDialog').showModal();
    form.elements.temperatura_ambiente_c.focus();
}

byId('readingForm').addEventListener('input', updatePreview);
byId('readingForm').addEventListener('change', (event) => {
    if (!['etapa', 'medido_at'].includes(event.target.name)) return;
    renderReferenceOptions();
    updatePreview();
});
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
        const reading = {
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
            sellado_referencia_id: form.elements.sellado_referencia_id.value || null,
            origen_referencia_id: form.elements.origen_referencia_id.value || null,
        };
        // Reintentar la misma medición (p. ej., tras perder la red) reutiliza su operación y no la duplica.
        const key = JSON.stringify(reading);
        if (state.attempt?.key !== key) state.attempt = { key, id: createUuid() };
        const payload = await session.request('/api/prefrio/punto-rocio', {
            method: 'POST',
            body: JSON.stringify({ operacion_id: state.attempt.id, ...reading }),
        });
        state.attempt = null;
        byId('readingDialog').close();
        state.date = payload.data.fecha;
        await load();
        toast(payload.data.requiere_accion
            ? `Medición guardada: ${(payload.data.condicion || payload.data.estado_etiqueta).toLowerCase()}. Supervisión de frío fue avisada.`
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
    const summary = `${formatTime(reading.medido_at)} · ${reading.etapa_etiqueta} · ${reading.punto}: ${reading.condicion ? `${reading.condicion.toLowerCase()}; ` : ''}rocío ${formatTemperature(reading.punto_rocio_c)}, margen ${formatTemperature(reading.margen_c)}.`;
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

function renderMargins() {
    byId('marginList').innerHTML = (state.data.parametros.margenes_por_etapa || []).map((row) => `<label class="dew-margin">
        <span>${e(row.etiqueta)}<small>${row.ajustado ? 'Ajustado por supervisión' : 'Por defecto'}</small></span>
        <span class="dew-number"><div><input name="${e(row.etapa)}" type="number" step="0.1" min="0" max="10" inputmode="decimal" value="${e(String(row.margen_minimo_c))}" aria-label="Margen mínimo de ${e(row.etiqueta)}"><b>°C</b></div></span>
    </label>`).join('');
}

byId('marginsButton').addEventListener('click', () => {
    renderMargins();
    byId('marginError').textContent = '';
    byId('marginsDialog').showModal();
});
byId('marginsForm').addEventListener('submit', async (event) => {
    if (event.submitter?.value === 'cancel') return;
    event.preventDefault();
    const form = event.currentTarget;
    const error = byId('marginError');
    error.textContent = '';
    const changes = [];
    for (const row of state.data.parametros.margenes_por_etapa || []) {
        const value = readNumber(form, row.etapa);
        if (value === null || !Number.isFinite(value) || value < 0 || value > 10) {
            error.textContent = `El margen de ${row.etiqueta} debe estar entre 0 y 10 °C.`;
            form.elements[row.etapa].focus();
            return;
        }
        if (Math.round(value * 100) !== Math.round(row.margen_minimo_c * 100)) changes.push({ etapa: row.etapa, margen_minimo_c: value, version_conocida: row.version });
    }
    if (!changes.length) { byId('marginsDialog').close(); return; }
    setBusy(true, 'Guardando márgenes…');
    try {
        await session.request('/api/prefrio/punto-rocio/margenes', { method: 'PUT', body: JSON.stringify({ margenes: changes }) });
        byId('marginsDialog').close();
        await load();
        toast('Márgenes guardados. Rigen para las mediciones nuevas.');
    } catch (requestError) {
        error.textContent = requestError.message;
    } finally {
        setBusy(false);
    }
});

const PLAN_PROPOSAL = {
    salida_prefrio: { temperatura_aire_c: -1, temperatura_fruta_c: -1 },
    traslado: { temperatura_aire_c: 4, temperatura_fruta_c: -1 },
    almacenamiento: { temperatura_aire_c: -1, temperatura_fruta_c: -0.5 },
    despacho: { temperatura_aire_c: 4, temperatura_fruta_c: -0.5 },
    carga_contenedor: { temperatura_aire_c: 0, temperatura_fruta_c: -0.5 },
};

function planDraft() {
    const form = byId('planForm');
    return (state.data.plan?.etapas || []).map((row) => ({
        etapa: row.etapa,
        temperatura_aire_c: readNumber(form, `aire_${row.etapa}`),
        temperatura_fruta_c: readNumber(form, `fruta_${row.etapa}`),
    }));
}

function renderPlanRows() {
    byId('planRows').innerHTML = (state.data.plan?.etapas || []).map((row) => `<tr data-stage="${e(row.etapa)}">
        <td><strong>${row.orden}. ${e(row.etiqueta)}</strong></td>
        <td><span class="dew-number"><div><input name="aire_${e(row.etapa)}" type="number" step="0.1" min="-30" max="60" inputmode="decimal" value="${row.temperatura_aire_c ?? ''}" aria-label="T° del aire en ${e(row.etiqueta)}"><b>°C</b></div></span></td>
        <td><span class="dew-number"><div><input name="fruta_${e(row.etapa)}" type="number" step="0.1" min="-30" max="60" inputmode="decimal" value="${row.temperatura_fruta_c ?? ''}" aria-label="T° más baja de la fruta en ${e(row.etiqueta)}"><b>°C</b></div></span></td>
        <td class="dew-num">${e(formatTemperature(marginFor(row.etapa)))}</td>
        <td class="dew-num" data-cell="dew"></td>
        <td class="dew-num" data-cell="humidity"></td>
    </tr>`).join('');
    updatePlanPreview();
}

function updatePlanPreview() {
    const computed = coldChainPlan(planDraft(), marginFor, state.data.plan?.temperatura_congelacion_c ?? -1.7);
    for (const row of computed) {
        const tr = byId('planRows').querySelector(`tr[data-stage="${CSS.escape(row.etapa)}"]`);
        tr.classList.toggle('is-unplanned', !row.planificada);
        tr.querySelector('[data-cell="dew"]').innerHTML = row.rocio_maximo_c === null ? '—'
            : `${e(formatTemperature(row.rocio_maximo_c))}${row.limitante === 'envase' ? '<small>por el envase</small>' : ''}`;
        tr.querySelector('[data-cell="humidity"]').innerHTML = row.humedad_maxima === null ? '—'
            : `${e(row.humedad_maxima >= 100 ? 'Sin límite' : formatHumidity(row.humedad_maxima))}${row.cerca_congelacion ? '<small class="dew-chain__freeze">Cerca de congelación</small>' : ''}`;
    }
}

byId('editPlanButton').addEventListener('click', () => {
    renderPlanRows();
    byId('planError').textContent = '';
    byId('planDialog').showModal();
});
byId('planForm').addEventListener('input', updatePlanPreview);
byId('suggestPlanButton').addEventListener('click', () => {
    const form = byId('planForm');
    for (const [stage, values] of Object.entries(PLAN_PROPOSAL)) {
        for (const [field, value] of Object.entries({ aire: values.temperatura_aire_c, fruta: values.temperatura_fruta_c })) {
            const input = form.elements[`${field}_${stage}`];
            if (input && input.value === '') input.value = String(value);
        }
    }
    updatePlanPreview();
});
byId('planForm').addEventListener('submit', async (event) => {
    if (event.submitter?.value === 'cancel') return;
    event.preventDefault();
    const error = byId('planError');
    error.textContent = '';
    const changes = [];
    for (const row of state.data.plan?.etapas || []) {
        const draft = planDraft().find((item) => item.etapa === row.etapa);
        for (const [field, label] of [['temperatura_aire_c', 'aire'], ['temperatura_fruta_c', 'fruta']]) {
            const value = draft[field];
            if (value !== null && (!Number.isFinite(value) || value < -30 || value > 60)) {
                error.textContent = `La T° de ${label === 'aire' ? 'aire' : 'fruta'} de ${row.etiqueta} debe estar entre -30 y 60 °C.`;
                byId('planForm').elements[`${label}_${row.etapa}`].focus();
                return;
            }
        }
        const same = (a, b) => (a === null && b === null) || (a !== null && b !== null && Math.round(a * 10) === Math.round(b * 10));
        if (!same(draft.temperatura_aire_c, row.temperatura_aire_c) || !same(draft.temperatura_fruta_c, row.temperatura_fruta_c)) {
            changes.push({ ...draft, version_conocida: row.version });
        }
    }
    if (!changes.length) { byId('planDialog').close(); return; }
    setBusy(true, 'Guardando plan…');
    try {
        await session.request('/api/prefrio/punto-rocio/plan', { method: 'PUT', body: JSON.stringify({ etapas: changes }) });
        byId('planDialog').close();
        await load();
        toast('Plan guardado. Rige para las mediciones nuevas.');
    } catch (requestError) {
        error.textContent = requestError.message;
    } finally {
        setBusy(false);
    }
});

session.start();
