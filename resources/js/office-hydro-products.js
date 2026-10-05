import { showOfficeToast } from './office-toast.js';
const byId = (id) => document.getElementById(id);
const form = byId('hydroProductForm');
const body = byId('hydroProductsBody');
const error = byId('hydroProductError');
const state = { products: [], busy: false, historyId: null, nextHistory: null };
const escapeHtml = (value) => String(value ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&#039;');
const describeProduct = (product) => product
    ? `${product.nombre} · Dosis en ${product.unidad_dosis} · ${product.activo ? 'Activo' : 'Inactivo'}`
    : 'Sin registro previo';
async function api(path, options = {}) {
    const response = await fetch(path, { ...options, headers: { Accept: 'application/json', Authorization: `Bearer ${localStorage.getItem('estiba_wms_office_token')}`, ...(options.body ? { 'Content-Type': 'application/json' } : {}) } });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(Object.values(data.errors || {}).flat()[0] || data.message || 'No fue posible consultar el catálogo.');
    return data;
}
function reset() { form.reset(); form.elements.id.value = ''; form.elements.version_conocida.value = ''; }
async function load() {
    let identity; try { identity = JSON.parse(localStorage.getItem('estiba_wms_office_identity') || 'null'); } catch { identity = null; }
    const allowed = identity?.puede_administrar_accesos === true && Boolean(localStorage.getItem('estiba_wms_office_token'));
    byId('administration-tab-hydro-products')?.classList.toggle('is-hidden', !allowed);
    form.hidden = !allowed;
    if (!allowed) { state.products = []; body.innerHTML = ''; byId('hydroProductHistory').classList.add('is-hidden'); return; }
    try {
        state.products = (await api('/api/administracion/productos-hidrocooler')).data;
        body.innerHTML = state.products.map((p) => `<tr><td>${escapeHtml(p.nombre)}</td><td>${escapeHtml(p.unidad_dosis)}</td><td>${p.activo ? 'Activo' : 'Inactivo'}</td><td>${escapeHtml(p.actualizado_por?.name)}<small>${escapeHtml(new Date(p.updated_at).toLocaleString('es-CL'))}</small></td><td><button type="button" data-edit-product="${p.id}">Editar</button><button type="button" data-history-product="${p.id}">Historial</button></td></tr>`).join('') || '<tr><td colspan="5">No hay productos registrados.</td></tr>';
    } catch (reason) { error.textContent = reason.message; }
}
async function history(id, next = false) {
    try {
        const result = (await api(next ? state.nextHistory : `/api/administracion/productos-hidrocooler/${id}/eventos`)).data;
        state.historyId = id;
        const url = result.next_page_url ? new URL(result.next_page_url, window.location.origin) : null;
        state.nextHistory = url ? url.pathname + url.search : null;
        byId('hydroProductHistory').classList.remove('is-hidden');
        if (!next) byId('hydroProductHistoryBody').innerHTML = '';
        byId('hydroProductHistoryBody').insertAdjacentHTML('beforeend', result.data.map((e) => `<p>${escapeHtml(new Date(e.created_at).toLocaleString('es-CL'))} · ${escapeHtml(e.usuario?.name)}<br>Anterior: ${escapeHtml(describeProduct(e.antes))}<br>Nuevo: ${escapeHtml(describeProduct(e.despues))}</p>`).join(''));
        byId('hydroProductHistoryMore').classList.toggle('is-hidden', !state.nextHistory);
    } catch (reason) { error.textContent = reason.message; }
}
form?.addEventListener('submit', async (event) => {
    event.preventDefault(); if (state.busy) return;
    error.textContent = ''; state.busy = true;
    const submit = form.querySelector('[type="submit"]'); submit.disabled = true;
    const data = Object.fromEntries(new FormData(form)); const id = data.id; delete data.id;
    data.activo = form.elements.activo.checked;
    if (!id) delete data.version_conocida; else data.version_conocida = Number(data.version_conocida);
    try {
        await api(`/api/administracion/productos-hidrocooler${id ? `/${id}` : ''}`, { method: id ? 'PUT' : 'POST', body: JSON.stringify(data) });
        reset(); await load(); showOfficeToast(byId('officeToasts'), 'Producto guardado.');
    } catch (reason) { error.textContent = reason.message; }
    finally { state.busy = false; submit.disabled = false; }
});
body?.addEventListener('click', (event) => {
    const view = event.target.closest('[data-history-product]'); if (view) { void history(view.dataset.historyProduct); return; }
    const button = event.target.closest('[data-edit-product]'); if (!button) return;
    const p = state.products.find((p) => p.id === button.dataset.editProduct); if (!p) return;
    for (const key of ['id', 'nombre', 'unidad_dosis']) form.elements[key].value = p[key];
    form.elements.version_conocida.value = p.version; form.elements.activo.checked = p.activo; form.elements.nombre.focus();
});
byId('hydroProductNew')?.addEventListener('click', reset);
byId('hydroProductHistoryMore')?.addEventListener('click', () => { if (state.nextHistory) void history(state.historyId, true); });
byId('reloadAccessesButton')?.addEventListener('click', () => void load());
window.addEventListener('estiba:office-session', () => void load());
if (form) void load();
