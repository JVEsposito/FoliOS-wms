import { showOfficeToast } from './office-toast.js';

const byId = id => document.getElementById(id);
const form = byId('operationalCalendarForm');
const tab = byId('administration-tab-operational-calendar');
const current = byId('operationalCalendarCurrent');
const history = byId('operationalCalendarHistory');
const error = byId('operationalCalendarError');
const preview = byId('operationalCalendarPreview');
const submit = form.querySelector('button[type="submit"]');
const endpoint = '/api/administracion/jornada-operativa';
let knownVersion = 0;

function identity() {
    try { return JSON.parse(localStorage.getItem('estiba_wms_office_identity') || 'null'); } catch { return null; }
}

async function api(method = 'GET', data = null) {
    const headers = {
        Accept: 'application/json',
        Authorization: `Bearer ${localStorage.getItem('estiba_wms_office_token')}`,
        'Content-Type': 'application/json',
    };
    const response = await fetch(endpoint, { method, headers, body: data ? JSON.stringify(data) : null });
    const result = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(Object.values(result.errors || {}).flat()[0] || result.message || 'No fue posible guardar o consultar el calendario.');
    return result;
}

const dateLabel = value => value.split('-').reverse().join('-');
const shiftsLabel = (start, night) => `Turno A ${start}–${night} · Turno B ${night}–${start}`;
function refreshPreview() {
    const { inicio_jornada, inicio_turno_b } = form.elements;
    preview.textContent = inicio_jornada.value && inicio_turno_b.value
        ? shiftsLabel(inicio_jornada.value, inicio_turno_b.value)
        : '';
}

function render(result) {
    const { data, historial, sugerido } = result;
    const latest = historial[0] || sugerido;
    const pendingChange = data.proxima;
    knownVersion = result.version_conocida;
    if (data.inicio_jornada) {
        current.textContent = `${shiftsLabel(data.inicio_jornada, data.inicio_turno_b)} · ${data.zona_horaria}`;
    } else {
        current.textContent = 'Primera vigencia pendiente de activación';
    }
    form.elements.inicio_jornada.value = latest.inicio_jornada;
    form.elements.inicio_turno_b.value = latest.inicio_turno_b;
    form.elements.vigente_desde.min = latest.vigente_desde || data.fecha_jornada;
    form.elements.vigente_desde.value = pendingChange ? latest.vigente_desde : '';
    history.replaceChildren(...historial.map(item => {
        const row = document.createElement('tr');
        [
            dateLabel(item.vigente_desde),
            `${item.inicio_jornada}–${item.inicio_turno_b}`,
            `${item.inicio_turno_b}–${item.inicio_jornada}`,
            item.version,
        ].forEach(value => { row.insertCell().textContent = value; });
        return row;
    }));
    refreshPreview();
}

function authorize() {
    const allowed = identity()?.puede_administrar_accesos === true;
    tab.classList.toggle('is-hidden', !allowed);
    form.hidden = !allowed;
    if (!allowed) {
        submit.disabled = true;
        current.textContent = '';
        history.replaceChildren();
    }
    return allowed;
}

async function load() {
    if (!authorize()) return;
    submit.disabled = true;
    error.textContent = '';
    try {
        render(await api());
        submit.disabled = false;
    } catch (failure) {
        error.textContent = failure.message;
    }
}

form.addEventListener('input', refreshPreview);
form.addEventListener('submit', async event => {
    event.preventDefault();
    submit.disabled = true;
    error.textContent = '';
    try {
        render(await api('POST', { ...Object.fromEntries(new FormData(form)), version_conocida: knownVersion }));
        showOfficeToast(byId('officeToasts'), 'Horario operativo guardado.');
    } catch (failure) {
        error.textContent = failure.message;
    } finally {
        submit.disabled = false;
    }
});

document.addEventListener('estiba:office-panel-change', event => {
    if (event.detail?.group === 'administration' && event.detail?.panel === 'operational-calendar') void load();
});
window.addEventListener('estiba:office-session', () => {
    if (authorize() && !byId('administration-panel-operational-calendar').hidden) void load();
});
if (authorize() && !byId('administration-panel-operational-calendar').hidden) void load();
