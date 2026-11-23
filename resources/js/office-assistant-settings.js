// Configuración del asistente (docs/ASISTENTE_OFICINA.md): modo, topes de Haiku, piloto,
// compuerta, rangos de tara y registro de preguntas. Solo para quien administra el asistente.
import { DECISIONES, ESTADOS, escapeHtml, percent, renderGate, usd } from './shared/assistant-settings-ui.js';

const tokenKey = 'estiba_wms_office_token';
const identityKey = 'estiba_wms_office_identity';
const byId = (id) => document.getElementById(id);

const el = {
    app: byId('assistantSettingsApp'),
    error: byId('assistantError'),
    reload: byId('assistantReload'),
    save: byId('assistantSave'),
    modeLabel: byId('assistantModeLabel'),
    modelLabel: byId('assistantModelLabel'),
    spend: byId('assistantSpend'),
    spendDetail: byId('assistantSpendDetail'),
    spendBar: byId('assistantSpendBar'),
    calls: byId('assistantCalls'),
    callsDetail: byId('assistantCallsDetail'),
    gate: byId('assistantGate'),
    gateDetail: byId('assistantGateDetail'),
    modes: byId('assistantModes'),
    noKey: byId('assistantNoKey'),
    budget: byId('assistantBudget'),
    daily: byId('assistantDaily'),
    pilot: byId('assistantPilot'),
    measure: byId('assistantMeasure'),
    gateReport: byId('assistantGateReport'),
    usageUsers: byId('assistantUsageUsers'),
    margin: byId('assistantMargin'),
    ranges: byId('assistantRanges'),
    questions: byId('assistantQuestions'),
    questionsFilter: byId('assistantQuestionsFilter'),
    toasts: byId('assistantToasts'),
};

const state = { config: null, users: [] };

function readJson(key) {
    try {
        return JSON.parse(localStorage.getItem(key) || 'null');
    } catch {
        return null;
    }
}

async function api(path, options = {}) {
    const response = await fetch(path, {
        ...options,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            Authorization: `Bearer ${localStorage.getItem(tokenKey)}`,
            ...(options.headers || {}),
        },
    });
    const data = await response.json().catch(() => ({}));
    if (response.status === 401) {
        window.location.replace('/oficina/accesos');
        throw new Error('Tu sesión terminó.');
    }
    if (!response.ok) {
        const first = data?.errors ? Object.values(data.errors)[0]?.[0] : null;
        throw new Error(first || data?.message || 'No se pudo completar la operación.');
    }
    return data;
}

function toast(message, error = false) {
    const item = document.createElement('div');
    item.className = `toast${error ? ' toast--error' : ''}`;
    item.textContent = message;
    el.toasts.append(item);
    setTimeout(() => item.remove(), 5000);
}

function showError(message) {
    el.error.textContent = message || '';
    el.error.classList.toggle('is-hidden', !message);
}

function renderConfig() {
    const config = state.config;
    const ia = config.ia || {};
    el.modeLabel.textContent = config.modo === 'mixto' ? 'Mixto con Haiku' : 'Sin IA';
    el.modelLabel.textContent = ia.disponible ? ia.modelo : 'Sin clave de API en el servidor';
    el.modes.querySelectorAll('input[name="modo"]').forEach((input) => {
        input.checked = input.value === config.modo;
        input.disabled = input.value === 'mixto' && !ia.disponible && config.modo !== 'mixto';
    });
    el.noKey.classList.toggle('is-hidden', Boolean(ia.disponible));
    el.budget.value = ia.tope_mensual_propio ? ia.tope_mensual_usd : '';
    el.budget.placeholder = String(ia.tope_mensual_usd ?? '');
    el.daily.value = ia.limite_diario_propio ? ia.limite_diario_usuario : '';
    el.daily.placeholder = String(ia.limite_diario_usuario ?? '');
    el.margin.value = config.margen_desviaciones;
    el.ranges.innerHTML = (config.rangos || []).map((rango, index) => `
        <tr data-range="${index}">
            <td>${escapeHtml(rango.tipo_envase)}</td>
            <td>${escapeHtml(rango.especie || 'Todas')}</td>
            <td><input type="number" step="0.1" min="0" value="${escapeHtml(rango.minimo_kg)}" data-campo="minimo_kg" aria-label="Mínimo"></td>
            <td><input type="number" step="0.1" min="0" value="${escapeHtml(rango.maximo_kg)}" data-campo="maximo_kg" aria-label="Máximo"></td>
            <td><input type="checkbox" ${rango.activo ? 'checked' : ''} data-campo="activo" aria-label="Activo"></td>
        </tr>`).join('') || '<tr><td colspan="5">Sin rangos configurados.</td></tr>';
    renderPilot();
}

function renderPilot() {
    const selected = new Set((state.config?.ia?.usuarios_piloto || []).map(Number));
    el.pilot.innerHTML = state.users.map((user) => `
        <label><input type="checkbox" value="${user.id}" ${selected.has(Number(user.id)) ? 'checked' : ''}>
            <span>${escapeHtml(user.nombre)}<small>${escapeHtml(user.rol)}</small></span></label>`).join('')
        || '<p class="assistant-settings__hint">Ningún usuario activo tiene el módulo Asistente.</p>';
}

function renderUsage(usage, config) {
    const tope = Number(config?.ia?.tope_mensual_usd || 0);
    el.spend.textContent = usd(usage.usd, 4);
    el.spendDetail.textContent = `de ${usd(tope)}`;
    el.spendBar.style.inlineSize = `${tope > 0 ? Math.min(100, (usage.usd / tope) * 100) : 0}%`;
    el.calls.textContent = String(usage.llamadas);
    el.callsDetail.textContent = `${usage.errores} con error`;
    el.usageUsers.innerHTML = (usage.por_usuario || []).map((fila) => `
        <tr><td>${escapeHtml(fila.usuario)}</td><td>${fila.llamadas}</td><td>${usd(fila.usd, 4)}</td></tr>`).join('')
        || '<tr><td colspan="3">Sin llamadas este mes.</td></tr>';
}

async function loadQuestions() {
    const estado = el.questionsFilter.value;
    const data = await api(`/api/asistente/preguntas${estado ? `?estado=${encodeURIComponent(estado)}` : ''}`);
    el.questions.innerHTML = (data.data || []).map((p) => `
        <tr>
            <td>${escapeHtml(p.fecha ? new Date(p.fecha).toLocaleString('es-CL', { dateStyle: 'short', timeStyle: 'short' }) : '—')}</td>
            <td>${escapeHtml(p.usuario || '—')}</td>
            <td>${escapeHtml(p.texto)}</td>
            <td>${escapeHtml(p.consulta || '—')}${p.interprete === 'haiku' ? ' <small>Haiku</small>' : ''}</td>
            <td>${escapeHtml(ESTADOS[p.estado] || p.estado)}</td>
        </tr>`).join('') || '<tr><td colspan="5">Sin preguntas registradas.</td></tr>';
}

async function load() {
    showError('');
    try {
        const [config, users, usage] = await Promise.all([api('/api/asistente/configuracion'), api('/api/asistente/usuarios'), api('/api/asistente/consumo')]);
        state.config = config.data;
        state.users = users.data || [];
        renderConfig();
        renderUsage(usage.data, state.config);
        await loadQuestions();
    } catch (error) {
        showError(error.message);
    }
}

function collect() {
    const modo = el.modes.querySelector('input[name="modo"]:checked')?.value || state.config.modo;
    const rangos = (state.config.rangos || []).map((rango, index) => {
        const fila = el.ranges.querySelector(`[data-range="${index}"]`);
        return {
            ...rango,
            minimo_kg: Number(fila?.querySelector('[data-campo="minimo_kg"]')?.value ?? rango.minimo_kg),
            maximo_kg: Number(fila?.querySelector('[data-campo="maximo_kg"]')?.value ?? rango.maximo_kg),
            activo: Boolean(fila?.querySelector('[data-campo="activo"]')?.checked),
        };
    });
    return {
        modo,
        margen_desviaciones: Number(el.margin.value),
        rangos,
        tope_mensual_usd: el.budget.value === '' ? null : Number(el.budget.value),
        limite_diario_usuario: el.daily.value === '' ? null : Number(el.daily.value),
        usuarios_piloto: [...el.pilot.querySelectorAll('input:checked')].map((input) => Number(input.value)),
    };
}

el.save.addEventListener('click', async () => {
    el.save.disabled = true;
    try {
        const data = await api('/api/asistente/configuracion', { method: 'PUT', body: JSON.stringify(collect()) });
        state.config = data.data;
        renderConfig();
        toast('Configuración guardada.');
    } catch (error) {
        toast(error.message, true);
    } finally {
        el.save.disabled = false;
    }
});

el.measure.addEventListener('click', async () => {
    el.measure.disabled = true;
    el.gateReport.innerHTML = '<p class="assistant-settings__hint">Midiendo el registro y el banco…</p>';
    try {
        const data = await api('/api/asistente/compuerta?dias=7');
        el.gateReport.innerHTML = renderGate(data.data);
        el.gate.textContent = DECISIONES[data.data.decision] || data.data.decision;
        el.gateDetail.textContent = `${percent(data.data.reinterpretado.proporcion_sugerencias)} sin consulta · umbral 15 %`;
    } catch (error) {
        el.gateReport.innerHTML = `<p class="assistant-settings__hint">${escapeHtml(error.message)}</p>`;
    } finally {
        el.measure.disabled = false;
    }
});

el.reload.addEventListener('click', () => void load());
el.questionsFilter.addEventListener('change', () => void loadQuestions().catch((error) => showError(error.message)));

function boot() {
    const identity = readJson(identityKey);
    if (!localStorage.getItem(tokenKey) || !identity || identity?.capacidades?.puede_administrar_asistente !== true) {
        window.location.replace('/oficina/accesos');
        return;
    }
    el.app.classList.remove('is-hidden');
    void load();
}

if (el.app) boot();
