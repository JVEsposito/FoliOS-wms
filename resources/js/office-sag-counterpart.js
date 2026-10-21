import { showOfficeToast } from './office-toast.js';

const $ = (id) => document.getElementById(id);
const tokenKey = 'estiba_wms_office_token';
let token = localStorage.getItem(tokenKey);
let configuration;
let stockRequest = 0;
const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

export async function sagApi(path, options = {}) {
    const response = await fetch(`/api/contraparte-sag${path}`, {
        ...options, headers: { Accept: 'application/json', Authorization: `Bearer ${token}`, ...(options.body ? { 'Content-Type': 'application/json' } : {}), ...options.headers },
    });
    if (response.status === 401) {
        localStorage.removeItem(tokenKey); token = null;
        $('officeApp').classList.add('is-hidden'); $('officeAccess').classList.remove('is-hidden');
    }
    const body = await response.json();
    if (!response.ok) throw new Error(body.message || 'No se pudo completar la operación.');
    return body;
}

export const sagState = () => ({ token, configuration });
export const sagEscape = esc;
export function sagNotice(message, error = false) {
    $('scStatus').textContent = message;
    showOfficeToast($('officeToasts'), message, error);
}

function pages(element, page, go) {
    element.replaceChildren();
    for (const [label, target] of [['Anterior', page.current_page - 1], ['Siguiente', page.current_page + 1]]) {
        const button = document.createElement('button'); button.className = 'secondary-button'; button.textContent = label;
        button.disabled = target < 1 || target > page.last_page; button.onclick = () => go(target); element.append(button);
    }
}

async function stock(page = 1, movementPage = 1) {
    const revision = ++stockRequest;
    const params = new URLSearchParams(new FormData($('scFilters')));
    params.set('page', page); params.set('pagina_movimientos', movementPage);
    const data = await sagApi(`/existencias?${params}`);
    if (revision !== stockRequest) return;
    $('scTotals').textContent = `${data.unidades} pallets/saldos con condición · ${data.cajas} cajas`;
    $('scStock').innerHTML = data.folios.data.map((r) => `<tr>${[r.snapshot.folio, r.snapshot.cliente, r.snapshot.especie, r.snapshot.variedad, r.snapshot.csg, r.cajas, r.snapshot.condicion || 'Aprobado'].map((v) => `<td>${esc(v)}</td>`).join('')}</tr>`).join('') || '<tr><td colspan="7">No hay existencias registradas para estos filtros.</td></tr>';
    $('scMovements').innerHTML = data.movimientos.data.map((r) => `<tr>${[r.created_at, r.actividad, r.referencia, r.snapshot.folio, Math.max(0, r.variacion_cajas), Math.max(0, -r.variacion_cajas), r.saldo_cajas, r.saldo_unidades].map((v) => `<td>${esc(v)}</td>`).join('')}</tr>`).join('') || '<tr><td colspan="8">Sin movimientos.</td></tr>';
    pages($('scStockPages'), data.folios, (p) => run(() => stock(p, movementPage)));
    pages($('scMovementPages'), data.movimientos, (p) => run(() => stock(page, p)));
}

async function config() {
    configuration = await sagApi('/configuracion');
    document.querySelectorAll('[data-manage]').forEach((el) => { el.hidden = !configuration.puede_gestionar; });
    for (const input of $('scPlant').querySelectorAll('input')) input.value = configuration.planta?.[input.name] || '';
    $('scPeople').innerHTML = configuration.responsables.map((r) => `<article><strong>${esc(r.nombre)}</strong> · ${esc(r.cargo)} · ${r.activo ? 'Activo' : 'Inactivo'}<p>${esc(r.referencia_autorizacion)}</p>${configuration.puede_gestionar ? `<button class="secondary-button" data-person="${esc(r.id)}">Editar</button>` : ''}</article>`).join('') || '<p>Registra el primer responsable autorizado.</p>';
    document.dispatchEvent(new CustomEvent('sag:configuration', { detail: configuration }));
}

async function run(action) {
    try { await action(); } catch (error) { sagNotice(error.message, true); }
}

$('officeLoginForm').addEventListener('submit', async (event) => {
    event.preventDefault();
    try {
        const response = await fetch('/api/acceso-oficina', { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json' }, body: JSON.stringify(Object.fromEntries(new FormData(event.target))) });
        const data = await response.json(); if (!response.ok) throw new Error(data.message);
        token = data.token; localStorage.setItem(tokenKey, token); localStorage.setItem('estiba_wms_office_identity', JSON.stringify(data.usuario)); await enter();
    } catch (error) { $('officeLoginError').textContent = error.message; }
});
document.querySelectorAll('[data-panel]').forEach((button) => button.addEventListener('click', () => {
    document.querySelectorAll('[data-section]').forEach((section) => section.classList.toggle('is-hidden', section.dataset.section !== button.dataset.panel));
}));
$('scFilters').addEventListener('submit', (event) => { event.preventDefault(); run(() => stock()); });
$('scInitial').addEventListener('click', () => run(async () => {
    $('scInitial').disabled = true;
    try { const r = await sagApi('/existencias/corte-inicial', { method: 'POST' }); sagNotice(`${r.folios_revisados} folios revisados.`); await stock(); } finally { $('scInitial').disabled = false; }
}));
$('scPlant').addEventListener('submit', (event) => { event.preventDefault(); run(async () => {
    await sagApi('/configuracion', { method: 'PUT', body: JSON.stringify({ ...Object.fromEntries(new FormData(event.target)), version: configuration.version }) }); await config(); sagNotice('Establecimiento guardado.');
}); });
$('scPeople').addEventListener('click', (event) => {
    const button = event.target.closest('[data-person]'); if (!button) return;
    const r = configuration.responsables.find((p) => p.id === button.dataset.person);
    for (const input of $('scPerson').elements) { if (input.name in r) { if (input.type === 'checkbox') input.checked = r[input.name]; else input.value = r[input.name] ?? ''; } }
});
$('scPerson').addEventListener('submit', (event) => { event.preventDefault(); run(async () => {
    const data = Object.fromEntries(new FormData(event.target)); const id = data.id; delete data.id;
    data.activo = event.target.elements.activo.checked; data.version = Number(data.version);
    await sagApi(`/responsables${id ? `/${id}` : ''}`, { method: id ? 'PUT' : 'POST', body: JSON.stringify(data) });
    event.target.reset(); await config(); sagNotice('Responsable guardado.');
}); });
async function enter() {
    await config(); $('officeAccess').classList.add('is-hidden'); $('officeApp').classList.remove('is-hidden'); await stock();
    const documents = await import('./office-sag-documents.js'); await documents.init();
}
if (token) run(enter);
