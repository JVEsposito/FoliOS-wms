const byId = (id) => document.getElementById(id);
const keys = { token: 'estiba_wms_office_token', identity: 'estiba_wms_office_identity' };
const state = {
    token: localStorage.getItem(keys.token),
    identity: readJson(keys.identity),
    seasons: [],
    season: null,
    clients: [],
    categories: [],
    species: [],
    csg: [],
    imports: [],
    preview: null,
    projection: { articulos: 0, origenes: 0, combinaciones: 0 },
    showInactive: false,
};
const elements = {
    user: byId('officeUserName'), initials: byId('officeInitials'), logout: byId('officeLogoutButton'),
    selector: byId('catalogSeasonSelector'), reload: byId('catalogReload'),
    toggleInactive: byId('catalogToggleInactive'),
    importForm: byId('importForm'), importError: byId('importError'), importPreview: byId('importPreview'), importList: byId('importList'),
    loading: byId('catalogLoading'), loadingText: byId('catalogLoadingText'), toasts: byId('catalogToasts'),
};

const entityConfig = {
    brand: { form: 'brandForm', error: 'brandError', path: 'marcas', list: 'brandList', label: 'marca' },
    category: { form: 'categoryForm', error: 'categoryError', path: 'categorias', list: 'categoryList', label: 'categoría' },
    species: { form: 'speciesForm', error: 'speciesError', path: 'especies', list: 'speciesList', label: 'especie' },
    variety: { form: 'varietyForm', error: 'varietyError', path: 'variedades', list: 'varietyList', label: 'variedad' },
    caliber: { form: 'caliberForm', error: 'caliberError', path: 'calibres', list: 'caliberList', label: 'calibre' },
    package: { form: 'packageForm', error: 'packageError', path: 'envases', list: 'packageList', label: 'envase' },
    csg: { form: 'csgForm', error: 'csgError', path: 'csg', list: 'csgList', label: 'CSG' },
};

class ApiError extends Error {
    constructor(message, status) { super(message); this.status = status; }
}
function readJson(key) { try { return JSON.parse(localStorage.getItem(key) || 'null'); } catch { return null; } }
function escapeHtml(value) { return String(value ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&#039;'); }
function statusText(value) { return String(value || '').replaceAll('_', ' ').replace(/^./, (letter) => letter.toUpperCase()); }
function formatDate(value, fallback = 'Sin fecha') { if (!value) return fallback; const date = new Date(value); return Number.isNaN(date.getTime()) ? fallback : new Intl.DateTimeFormat('es-CL', { dateStyle: 'short', timeStyle: 'short' }).format(date); }
function errorMessage(data, fallback) { return Object.values(data?.errors || {}).flat()[0] || data?.message || fallback; }
function setBusy(active, message = 'Procesando…') { elements.loadingText.textContent = message; elements.loading.classList.toggle('is-hidden', !active); elements.loading.setAttribute('aria-hidden', String(!active)); }
function toast(message, error = false) { const node = document.createElement('div'); node.className = `toast${error ? ' toast--error' : ''}`; node.textContent = message; elements.toasts.append(node); window.setTimeout(() => node.remove(), 4500); }
function wait(milliseconds) { return new Promise((resolve) => window.setTimeout(resolve, milliseconds)); }

async function api(path, options = {}) {
    const headers = new Headers(options.headers || {});
    headers.set('Accept', 'application/json');
    headers.set('Authorization', `Bearer ${state.token}`);
    if (options.body && !(options.body instanceof FormData)) headers.set('Content-Type', 'application/json');
    let response;
    try { response = await fetch(path, { ...options, headers }); } catch { throw new ApiError('No fue posible conectar con Laravel.', 0); }
    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
        if (response.status === 401) leave();
        throw new ApiError(errorMessage(data, 'No fue posible completar la operación.'), response.status);
    }
    return data;
}

function leave() {
    localStorage.removeItem(keys.token);
    localStorage.removeItem(keys.identity);
    window.location.assign('/oficina/accesos');
}

function verifyAccess() {
    const canManage = state.identity?.puede_administrar_catalogos_validacion === true;
    if (!state.token || !state.identity || !canManage) {
        window.location.replace('/oficina/accesos');
        return false;
    }
    const name = state.identity.nombre || 'Usuario';
    elements.user.textContent = name;
    elements.initials.textContent = name.split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0]).join('').toUpperCase();
    return true;
}

async function fetchCatalog(seasonId) {
    const suffix = seasonId ? `?temporada_id=${encodeURIComponent(seasonId)}` : '';
    const admin = await api(`/api/administracion/validacion${suffix}`);
    state.seasons = admin.temporadas || [];
    state.season = admin.temporada || null;
    state.imports = admin.importaciones || [];
    if (state.season) {
        const hierarchy = await api(`/api/administracion/validacion/temporadas/${state.season.id}/catalogo`);
        state.clients = hierarchy.clientes || [];
        state.categories = hierarchy.categorias || [];
        state.species = hierarchy.especies || [];
        state.csg = hierarchy.csg || [];
        state.projection = hierarchy.proyeccion || { articulos: 0, origenes: 0, combinaciones: 0 };
        return;
    }
    state.clients = []; state.categories = []; state.species = []; state.csg = [];
    state.projection = { articulos: 0, origenes: 0, combinaciones: 0 };
}

async function load(seasonId = null, { announce = false } = {}) {
    setBusy(true, 'Cargando catálogo…');
    try {
        try {
            await fetchCatalog(seasonId);
        } catch (error) {
            if (error.status !== 0 && error.status < 500) throw error;
            await wait(600);
            await fetchCatalog(seasonId);
        }
        render();
        if (announce) {
            toast(`Catálogo actualizado · ${state.season?.nombre || 'sin temporada activa'}.`);
        }
    } catch (error) {
        toast(error.message, true);
    } finally {
        setBusy(false);
    }
}

function option(value, label) { return `<option value="${escapeHtml(value)}">${escapeHtml(label)}</option>`; }
function active(items) { return items.filter((item) => item.activo); }
function visible(items) { return state.showInactive ? items : active(items); }
function activeClass(item) { return item.activo ? '' : ' is-inactive'; }
function editButton(type, id) {
    if (state.identity?.puede_administrar_catalogos_validacion !== true) return '';
    return `<button data-edit-type="${type}" data-edit-id="${id}" type="button">Editar</button>`;
}
function deleteButton(type, id) {
    if (state.identity?.puede_administrar_catalogos_validacion !== true) return '';
    return `<button class="catalog-delete-button" data-delete-type="${type}" data-delete-id="${id}" type="button">Eliminar</button>`;
}
function row(title, detail, type, item) {
    const status = item.activo ? '' : ' · ELIMINADO';
    const actions = `${editButton(type, item.id)}${item.activo ? deleteButton(type, item.id) : ''}`;
    return `<article class="validation-row${activeClass(item)}"><div><strong>${escapeHtml(title)}<span class="catalog-status-removed">${status}</span></strong><small>${escapeHtml(detail)}</small></div><div class="validation-row__actions">${actions}</div></article>`;
}

function render() {
    elements.toggleInactive.textContent = state.showInactive ? 'Ocultar eliminados' : 'Mostrar eliminados';
    byId('projectionArticleCount').textContent = state.projection.articulos || 0;
    byId('projectionOriginCount').textContent = state.projection.origenes || 0;
    byId('projectionCombinationCount').textContent = state.projection.combinaciones || 0;
    elements.importList.innerHTML = state.imports.map((item) => `<article class="validation-row"><div><strong>${escapeHtml(item.nombre_archivo)}</strong><small>${escapeHtml(statusText(item.estado))} · ${escapeHtml(formatDate(item.created_at))} · ${item.resumen?.filas_validas || 0} filas válidas</small></div>${item.estado === 'borrador' ? `<button data-confirm-import="${item.id}" type="button">Confirmar</button>` : ''}</article>`).join('') || '<p class="empty-validation">Sin importaciones recientes para esta temporada.</p>';

    elements.selector.innerHTML = state.seasons.map((season) => option(season.id, `${season.codigo} · ${season.nombre}${season.activa ? ' (activa)' : ''}`)).join('') || '<option value="">Sin temporadas</option>';
    elements.selector.value = state.season?.id || '';

    const clientOptions = '<option value="">Selecciona un cliente</option>' + active(state.clients).map((item) => option(item.id, `${item.codigo_externo || 'SIN-CÓDIGO'} · ${item.nombre}`)).join('');
    byId('brandForm').elements.cliente_validacion_id.innerHTML = clientOptions;
    byId('packageForm').elements.cliente_validacion_id.innerHTML = clientOptions;

    const speciesOptions = '<option value="">Selecciona una especie</option>' + active(state.species).map((item) => option(item.id, item.nombre)).join('');
    for (const formId of ['varietyForm', 'caliberForm', 'packageForm']) byId(formId).elements.especie_validacion_id.innerHTML = speciesOptions;

    const varieties = active(state.species).flatMap((species) => active(species.variedades || []).map((item) => ({ ...item, species: species.nombre })));
    byId('csgVarietyOptions').innerHTML = varieties.map((item) => `<label><input name="variedad_ids" type="checkbox" value="${item.id}"><span>${escapeHtml(item.species)} · ${escapeHtml(item.nombre)}</span></label>`).join('') || '<p class="empty-validation">Crea variedades antes de registrar un CSG.</p>';

    const clients = visible(state.clients);
    byId('clientCount').textContent = String(clients.length);
    byId('clientList').innerHTML = clients.map((item) => `<article class="validation-row${activeClass(item)}"><div><strong>${escapeHtml(item.codigo_externo || 'SIN-CÓDIGO')} · ${escapeHtml(item.nombre)}</strong><small>Administrado en Accesos</small></div></article>`).join('') || '<p class="empty-validation">Sin clientes. Crea o activa el cliente en Accesos.</p>';

    const brands = clients.flatMap((client) => visible(client.marcas || []).map((item) => ({ ...item, clientId: client.id, client: client.nombre })));
    byId('brandCount').textContent = String(brands.length);
    byId('brandList').innerHTML = brands.map((item) => row(item.nombre, `Cliente: ${item.client}`, 'brand', item)).join('') || '<p class="empty-validation">Sin marcas.</p>';

    const categories = visible(state.categories);
    byId('categoryCount').textContent = String(categories.length);
    byId('categoryList').innerHTML = categories.map((item) => row(item.nombre, item.codigo_externo || 'Disponible para todas las especies y marcas', 'category', item)).join('') || '<p class="empty-validation">Sin categorías.</p>';

    const species = visible(state.species);
    byId('speciesCount').textContent = String(species.length);
    byId('speciesList').innerHTML = species.map((item) => row(item.nombre, `${item.variedades?.length || 0} variedades · ${item.calibres?.length || 0} calibres · ${item.envases?.length || 0} envases`, 'species', item)).join('') || '<p class="empty-validation">Sin especies.</p>';

    renderChildren('variety', 'varietyCount', 'varietyList', 'variedades');
    renderChildren('caliber', 'caliberCount', 'caliberList', 'calibres');
    renderChildren('package', 'packageCount', 'packageList', 'envases');

    const csg = visible(state.csg);
    byId('csgCount').textContent = String(csg.length);
    byId('csgList').innerHTML = csg.map((item) => {
        const clients = item.productor?.clientes?.map((client) => client.nombre).join(', ');
        const scope = item.productor_csg_id
            ? (clients || 'Sin clientes asociados')
            : 'Disponible para todos los clientes';
        return row(
            item.codigo,
            `${item.predio || 'Sin predio'} · ${item.variedades?.length || 0} variedades autorizadas · ${scope}`,
            'csg',
            item,
        );
    }).join('') || '<p class="empty-validation">Sin CSG.</p>';
}

function renderChildren(type, countId, listId, relation) {
    const items = visible(state.species).flatMap((species) => visible(species[relation] || []).map((item) => ({ ...item, speciesId: species.id, species: species.nombre })));
    byId(countId).textContent = String(items.length);
    byId(listId).innerHTML = items.map((item) => {
        const clientLabel = item.cliente
            ? `${item.cliente.codigo_externo || 'SIN-CÓDIGO'} · ${item.cliente.nombre}`
            : 'Sin cliente asignado';
        const client = type === 'package'
            ? ` · Cliente: ${clientLabel}`
            : '';
        const kilos = type === 'package' ? ` · ${item.kilos_netos_por_caja ?? 'Sin configurar'} kg/caja` : '';
        return row(item.nombre, `Especie: ${item.species}${client}${kilos}`, type, item);
    }).join('') || '<p class="empty-validation">Sin registros.</p>';
}

function resetForm(form) {
    form.reset();
    form.elements.id.value = '';
    if (form.elements.activo) form.elements.activo.checked = true;
    form.querySelectorAll('input[name="variedad_ids"]').forEach((input) => { input.checked = false; });
    const formId = form.getAttribute('id') || '';
    const error = byId(formId.replace('Form', 'Error'));
    if (error) error.textContent = '';
}

function itemFor(type, id) {
    if (type === 'brand') return state.clients.flatMap((parent) => (parent.marcas || []).map((item) => ({ ...item, cliente_validacion_id: parent.id }))).find((item) => item.id === id);
    if (type === 'category') return state.categories.find((item) => item.id === id);
    if (type === 'species') return state.species.find((item) => item.id === id);
    if (type === 'csg') return state.csg.find((item) => item.id === id);
    const relation = type === 'variety' ? 'variedades' : type === 'caliber' ? 'calibres' : 'envases';
    return state.species.flatMap((parent) => (parent[relation] || []).map((item) => ({ ...item, especie_validacion_id: parent.id }))).find((item) => item.id === id);
}

function edit(type, id) {
    const config = entityConfig[type];
    const form = byId(config.form);
    const item = itemFor(type, id);
    if (!item) return;
    resetForm(form);
    for (const field of ['id', 'nombre', 'codigo_externo', 'kilos_netos_por_caja', 'codigo', 'predio', 'cliente_validacion_id', 'especie_validacion_id']) {
        if (form.elements[field]) form.elements[field].value = item[field] ?? '';
    }
    if (form.elements.activo) form.elements.activo.checked = Boolean(item.activo);
    if (type === 'csg') {
        const allowed = new Set((item.variedades || []).map((entry) => entry.id));
        form.querySelectorAll('input[name="variedad_ids"]').forEach((input) => { input.checked = allowed.has(input.value); });
    }
    form.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

async function save(type) {
    const config = entityConfig[type];
    const form = byId(config.form);
    const errorNode = byId(config.error);
    errorNode.textContent = '';
    if (!state.season) { errorNode.textContent = 'Primero crea o selecciona una temporada.'; return; }

    const data = Object.fromEntries(new FormData(form));
    const id = data.id; delete data.id;
    if (['category', 'species', 'csg'].includes(type)) data.temporada_id = state.season.id;
    data.activo = form.elements.activo.checked;
    if (type === 'package') data.kilos_netos_por_caja = data.kilos_netos_por_caja || null;
    if (type === 'csg') data.variedad_ids = [...form.querySelectorAll('input[name="variedad_ids"]:checked')].map((input) => input.value);

    setBusy(true, 'Actualizando catálogo…');
    try {
        await api(`/api/administracion/validacion/${config.path}${id ? `/${id}` : ''}`, {
            method: id ? 'PUT' : 'POST',
            body: JSON.stringify(data),
        });
        resetForm(form);
        await load(state.season.id);
        toast('Catálogo actualizado y publicado para la PDA.');
    } catch (error) {
        errorNode.textContent = error.message;
    } finally {
        setBusy(false);
    }
}


async function remove(type, id) {
    const config = entityConfig[type];
    const item = itemFor(type, id);
    if (!config || !item || !item.activo) return;

    const name = item.nombre || item.codigo || 'este elemento';
    if (!window.confirm(`¿Eliminar ${config.label} "${name}" del catálogo operativo? Se conservará su historial.`)) return;

    setBusy(true, 'Eliminando del catálogo…');
    try {
        await api(`/api/administracion/validacion/${config.path}/${id}`, { method: 'DELETE' });
        const form = byId(config.form);
        if (form.elements.id.value === id) resetForm(form);
        await load(state.season?.id);
        toast('Elemento retirado del catálogo operativo y de la PDA.');
    } catch (error) {
        toast(error.message, true);
    } finally {
        setBusy(false);
    }
}

function renderImportPreview(item) {
    state.preview = item;
    const summary = item.resumen || {};
    const errors = item.errores || [];
    elements.importPreview.classList.remove('is-hidden');
    elements.importPreview.innerHTML = `<strong>${escapeHtml(item.nombre_archivo)}</strong><div class="import-preview__metrics"><span><strong>${summary.filas_validas || 0}</strong><br>filas válidas</span><span><strong>${summary.combinaciones_detectadas || 0}</strong><br>combinaciones</span><span><strong>${summary.filas_con_error || 0}</strong><br>errores</span></div>${errors.length ? `<div class="import-errors">${errors.map((error) => `<p>Fila ${error.fila}: ${escapeHtml(error.mensaje)}</p>`).join('')}</div>` : '<p>La previsualización no detectó errores bloqueantes.</p>'}${item.estado === 'borrador' ? `<button class="primary-button" id="confirmPreviewImport" type="button">Confirmar e importar</button>` : ''}`;
    byId('confirmPreviewImport')?.addEventListener('click', () => void confirmImport(item.id));
}

async function confirmImport(id) {
    setBusy(true, 'Confirmando importación…');
    elements.importError.textContent = '';
    try {
        await api(`/api/administracion/validacion/importaciones/${id}/confirmar`, { method: 'POST' });
        state.preview = null;
        elements.importPreview.classList.add('is-hidden');
        await load(state.season?.id);
        toast('Datos maestros importados y versión actualizada.');
    } catch (error) {
        elements.importError.textContent = error.message;
    } finally {
        setBusy(false);
    }
}

async function submitImport(event) {
    event.preventDefault();
    elements.importError.textContent = '';
    if (!state.season) {
        elements.importError.textContent = 'No existe una temporada transversal. Debes crearla en Accesos.';
        return;
    }
    const data = new FormData(elements.importForm);
    data.set('temporada_id', state.season.id);
    setBusy(true, 'Leyendo y validando planilla…');
    try {
        const response = await api('/api/administracion/validacion/importaciones/previsualizar', { method: 'POST', body: data });
        renderImportPreview(response.data);
        await fetchCatalog(state.season.id);
        render();
    } catch (error) {
        elements.importError.textContent = error.message;
    } finally {
        setBusy(false);
    }
}

for (const [type, config] of Object.entries(entityConfig)) {
    byId(config.form).addEventListener('submit', (event) => { event.preventDefault(); void save(type); });
}
document.addEventListener('click', (event) => {
    const editTarget = event.target.closest('[data-edit-type]');
    if (editTarget) edit(editTarget.dataset.editType, editTarget.dataset.editId);
    const deleteTarget = event.target.closest('[data-delete-type]');
    if (deleteTarget) void remove(deleteTarget.dataset.deleteType, deleteTarget.dataset.deleteId);
    const resetTarget = event.target.closest('[data-reset-form]');
    if (resetTarget) resetForm(byId(resetTarget.dataset.resetForm));
    const confirmImportTarget = event.target.closest('[data-confirm-import]');
    if (confirmImportTarget) void confirmImport(confirmImportTarget.dataset.confirmImport);
});

elements.selector.addEventListener('change', () => void load(elements.selector.value));
elements.reload.addEventListener('click', () => void load(state.season?.id, { announce: true }));
elements.toggleInactive.addEventListener('click', () => {
    state.showInactive = !state.showInactive;
    render();
});
elements.importForm.addEventListener('submit', (event) => { void submitImport(event); });
elements.logout.addEventListener('click', async () => {
    try { await api('/api/acceso-oficina', { method: 'DELETE' }); } catch { /* limpia igualmente */ }
    leave();
});

if (verifyAccess()) void load();
