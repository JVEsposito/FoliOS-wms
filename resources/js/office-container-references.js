import { loadContainerCatalog } from './office-container-catalog.js';

const form = document.getElementById('containerReferencesForm');
const byId = (id) => document.getElementById(id);
const escape = (value) => String(value).replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('"', '&quot;');
let species = [];
let containers = [];

async function api(path, options = {}) {
    const response = await fetch(path, { ...options, headers: { Accept: 'application/json', 'Content-Type': 'application/json', Authorization: `Bearer ${localStorage.getItem('estiba_wms_office_token')}` } });
    const data = await response.json();
    if (!response.ok) throw new Error(Object.values(data.errors || {}).flat()[0] || data.message || 'No fue posible guardar las referencias.');
    return data;
}

function render() {
    const selected = species.find((item) => item.id === form.elements.especie_id.value);
    form.elements.sugerido.innerHTML = '<option value="">Sin sugerencia</option>' + containers.map((c) => `<option value="${escape(c.codigo)}">${escape(c.nombre)}</option>`).join('');
    form.elements.sugerido.value = selected?.sugerido || '';
    byId('containerReferenceRows').innerHTML = containers.map((c) => `<label class="field"><span>${escape(c.nombre)} · kg de referencia</span><input type="number" step="0.001" min="0.001" max="100000" data-reference="${escape(c.codigo)}" value="${selected?.referencias[c.codigo] ?? ''}" placeholder="Sin configurar"></label>`).join('');
}

async function load() {
    try {
        const identity = JSON.parse(localStorage.getItem('estiba_wms_office_identity') || 'null');
        form.hidden = identity?.puede_administrar_accesos !== true;
        if (form.hidden) return;
        const results = await Promise.all([api('/api/administracion/reparto-envases'), loadContainerCatalog(api)]);
        species = results[0].data;
        containers = results[1].filter((c) => c.contiene_fruta);
        form.elements.especie_id.innerHTML = species.map((s) => `<option value="${s.id}">${escape(s.nombre)}</option>`).join('');
        form.querySelector('[type="submit"]').disabled = !species.length;
        render();
    } catch (error) { byId('containerReferenceError').textContent = error.message; }
}

form?.elements.especie_id.addEventListener('change', render);
form?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const submit = form.querySelector('[type="submit"]');
    submit.disabled = true;
    byId('containerReferenceError').textContent = '';
    try {
        const references = [...form.querySelectorAll('[data-reference]')].filter((input) => input.value !== '').map((input) => ({ tipo_envase: input.dataset.reference, peso_referencia: Number(input.value) }));
        const result = await api(`/api/administracion/reparto-envases/${form.elements.especie_id.value}`, { method: 'PUT', body: JSON.stringify({ sugerido: form.elements.sugerido.value || null, referencias: references }) });
        Object.assign(species.find((s) => s.id === form.elements.especie_id.value), result.data);
        byId('containerReferenceError').textContent = 'Referencias guardadas. Los destares ya emitidos conservan su reparto.';
    } catch (error) { byId('containerReferenceError').textContent = error.message; }
    finally { submit.disabled = false; }
});
window.addEventListener('estiba:office-session', () => { if (form) void load(); });
if (form) void load();
