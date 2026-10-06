import { byId, escapeHtml as e, message, officeSession } from './shared/packed-fruit-office-session.js';
let catalog = { plantas_origen: [], umbrales: [], especies: [] };
let selectedPlant = null;
let selectedThreshold = null;
const session = officeSession('puede_administrar_accesos', async (isCurrent) => {
    const data = await session.request('/api/administracion/fruta-embalada');
    if (!isCurrent()) return;
    catalog = data; render();
}, () => { selectedPlant = selectedThreshold = null; byId('plantForm').reset(); byId('thresholdForm').reset(); });
function table(head, rows) { return `<table class="rfe-table"><thead><tr>${head.map((h) => `<th>${h}</th>`).join('')}<th></th></tr></thead><tbody>${rows.join('')}</tbody></table>`; }
function render() {
    byId('plantList').innerHTML = table(['Código', 'Nombre', 'Activa'], catalog.plantas_origen.map((p) => `<tr><td>${e(p.codigo)}</td><td>${e(p.nombre)}</td><td>${p.activa ? 'Sí' : 'No'}</td><td><button class="secondary-button" data-plant="${e(p.id)}">Editar</button></td></tr>`));
    byId('thresholdList').innerHTML = table(['Especie', 'T° máxima (°C)'], catalog.umbrales.map((u) => `<tr><td>${e(u.especie)}</td><td>${e(u.temperatura_maxima_c)}</td><td><button class="secondary-button" data-threshold="${e(u.id)}">Editar</button></td></tr>`));
    byId('speciesList').innerHTML = catalog.especies.map((s) => `<option value="${e(s)}"></option>`).join('');
}
for (const [formId, kind] of [['plantForm', 'plantas'], ['thresholdForm', 'umbrales']]) {
    byId(formId).addEventListener('submit', async (event) => {
        event.preventDefault(); const button = event.submitter; button.disabled = true;
        const selected = kind === 'plantas' ? selectedPlant : selectedThreshold;
        const datos = Object.fromEntries(new FormData(event.target));
        if (kind === 'plantas') datos.activa = datos.activa === 'true';
        else datos.temperatura_maxima_c = Number(datos.temperatura_maxima_c);
        if (selected) datos.version_conocida = selected.version;
        try {
            await session.request(`/api/administracion/fruta-embalada/${kind}${selected ? `/${selected.id}` : ''}`, { method: selected ? 'PUT' : 'POST', body: JSON.stringify(datos) });
            event.target.reset(); if (kind === 'plantas') selectedPlant = null; else selectedThreshold = null;
            await session.reload(); message('Catálogo guardado.');
        } catch (error) { message(error.message, true); } finally { button.disabled = false; }
    });
}
byId('plantList').addEventListener('click', (event) => {
    const id = event.target.closest('[data-plant]')?.dataset.plant;
    if (!id) return; selectedPlant = catalog.plantas_origen.find((p) => p.id === id);
    for (const key of ['nombre', 'codigo', 'activa']) byId('plantForm').elements[key].value = String(selectedPlant[key]);
});
byId('thresholdList').addEventListener('click', (event) => {
    const id = event.target.closest('[data-threshold]')?.dataset.threshold;
    if (!id) return; selectedThreshold = catalog.umbrales.find((u) => u.id === id);
    for (const key of ['especie', 'temperatura_maxima_c']) byId('thresholdForm').elements[key].value = selectedThreshold[key];
});
byId('clearPlant').onclick = () => { selectedPlant = null; byId('plantForm').reset(); };
byId('clearThreshold').onclick = () => { selectedThreshold = null; byId('thresholdForm').reset(); };
session.start();
