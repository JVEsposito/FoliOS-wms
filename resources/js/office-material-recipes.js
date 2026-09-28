import { createOfficeSessionSync } from './shared/office-session-sync';

const recipeTokenKey = 'estiba_wms_office_token';
const recipeIdentityKey = 'estiba_wms_office_identity';

const recipeState = {
    token: null,
    identity: null,
    catalog: { temporada: null, clientes: [], items: [] },
    recipes: [],
    editingRecipeId: null,
    loadedToken: null,
    loading: false,
    session: null,
};

const recipeElements = {};

class RecipeApiError extends Error {
    constructor(message, status = 0) {
        super(message);
        this.status = status;
    }
}

function recipeReadJson(key) {
    try {
        return JSON.parse(localStorage.getItem(key) || 'null');
    } catch {
        return null;
    }
}

function recipeEscape(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function recipeQuantity(value, maximumFractionDigits = 3) {
    return new Intl.NumberFormat('es-CL', { maximumFractionDigits }).format(Number(value || 0));
}

function recipeStatus(value) {
    return String(value || '').replaceAll('_', ' ').replace(/^./, (letter) => letter.toUpperCase());
}

function canConsultRecipes() {
    if (recipeState.identity?.puede_consultar_transformaciones_materiales === true) return true;
    return ['administrador', 'supervisor_materiales', 'camarero_materiales', 'despachador', 'consulta']
        .includes(recipeState.identity?.rol);
}

function canAdminRecipes() {
    if (recipeState.identity?.puede_administrar_recetas_materiales === true) return true;
    return ['administrador', 'supervisor_materiales'].includes(recipeState.identity?.rol);
}

function recipeSectionIsActive() {
    return document.getElementById('officeApp')?.dataset.materialsSection === 'recetas';
}

function recipeErrorMessage(data, fallback) {
    return Object.values(data?.errors || {}).flat()[0] || data?.message || fallback;
}

function recipeRowId() {
    if (globalThis.crypto?.randomUUID) return globalThis.crypto.randomUUID();
    if (globalThis.crypto?.getRandomValues) {
        const bytes = globalThis.crypto.getRandomValues(new Uint8Array(16));
        bytes[6] = (bytes[6] & 0x0f) | 0x40;
        bytes[8] = (bytes[8] & 0x3f) | 0x80;
        return [...bytes].map((value) => value.toString(16).padStart(2, '0')).join('');
    }
    return `${Date.now()}-${Math.random().toString(36).slice(2)}`;
}

async function recipeApi(path, options = {}, token = recipeState.token) {
    const headers = new Headers(options.headers || {});
    headers.set('Accept', 'application/json');
    if (token) headers.set('Authorization', `Bearer ${token}`);
    if (options.body) headers.set('Content-Type', 'application/json');

    let response;
    try {
        response = await fetch(path, { ...options, headers });
    } catch {
        throw new RecipeApiError('No fue posible conectar con Laravel.');
    }

    const data = response.status === 204 ? null : await response.json().catch(() => ({}));
    if (!response.ok) {
        throw new RecipeApiError(
            recipeErrorMessage(data, 'No fue posible completar la operación de recetas.'),
            response.status,
        );
    }

    return data;
}

function injectRecipePanel() {
    if (document.getElementById('materialsRecipesPanel')) return;
    const workspace = document.querySelector('.materials-workspace');
    const operations = document.querySelector('.materials-operation-grid');
    if (!workspace || !operations) return;

    const section = document.createElement('section');
    section.className = 'panel materials-panel materials-recipes-panel is-hidden';
    section.id = 'materialsRecipesPanel';
    section.dataset.materialsView = 'recetas';
    section.innerHTML = `
        <div class="materials-panel__heading">
            <div>
                <p class="eyebrow">TRANSFORMACIÓN INTERNA</p>
                <h2>Recetas de materiales</h2>
                <p class="materials-help">Define qué insumos y materiales sin preparar se consumen para obtener un material preparado para línea, incluso conservando el mismo código.</p>
            </div>
            <div class="materials-panel__tools">
                <span id="materialsRecipesSummary">0 recetas</span>
                <button class="secondary-button" id="reloadMaterialRecipes" type="button">↻ Actualizar recetas</button>
            </div>
        </div>
        <div class="materials-recipes-layout">
            <form class="materials-form materials-recipe-form is-hidden" id="materialRecipeForm" novalidate>
                <input name="recipe_id" type="hidden">
                <div class="materials-recipe-form__heading">
                    <div><p class="eyebrow">CONFIGURACIÓN</p><h3 id="materialRecipeFormTitle">Nueva receta</h3></div>
                    <button class="secondary-button is-hidden" id="cancelMaterialRecipeVersion" type="button">Cancelar versión</button>
                </div>
                <div class="materials-form__grid">
                    <label><span>Cliente *</span><select name="cliente_id" required></select></label>
                    <label><span>Producto de salida *</span><select name="item_salida_id" required></select><small>Un Material MP puede elegirse como salida para transformarlo a PT conservando su código.</small></label>
                    <label class="materials-wide"><span>Nombre de receta *</span><input name="nombre" minlength="3" maxlength="180" placeholder="Caja 10 kg preparada para línea" required></label>
                    <label><span>Cantidad base de salida *</span><input name="cantidad_base_salida" type="number" min="0.001" step="0.001" value="1" required></label>
                    <label><span>Unidades por folio / pallet *</span><input name="unidades_por_folio_salida" type="number" min="0.001" step="0.001" value="1" required></label>
                    <p class="materials-help materials-wide">Cada lote cerrado genera un folio. Esta cantidad define cuántas unidades contiene normalmente cada pallet o bulto de salida.</p>
                </div>
                <div class="materials-panel__heading">
                    <div><p class="eyebrow">COMPONENTES</p><h3>Entradas de la receta</h3></div>
                    <button class="secondary-button" id="addMaterialRecipeComponent" type="button">+ Agregar componente</button>
                </div>
                <p class="materials-help">Debe existir exactamente un componente principal. La merma se calcula sobre ese componente; los demás registran variación de consumo.</p>
                <div class="materials-recipe-components" id="materialRecipeComponents"></div>
                <p class="form-error" id="materialRecipeError" role="alert"></p>
                <div class="materials-actions"><button class="primary-button" type="submit" id="saveMaterialRecipe">Crear receta</button></div>
            </form>
            <div>
                <label class="materials-season-selector"><span>Filtrar por cliente</span><select id="materialRecipesClientFilter"><option value="">Todos los clientes</option></select></label>
                <div class="materials-recipe-list" id="materialRecipesList"></div>
            </div>
        </div>
    `;
    workspace.insertBefore(section, operations);

    Object.assign(recipeElements, {
        panel: section,
        summary: document.getElementById('materialsRecipesSummary'),
        reload: document.getElementById('reloadMaterialRecipes'),
        form: document.getElementById('materialRecipeForm'),
        formTitle: document.getElementById('materialRecipeFormTitle'),
        cancelVersion: document.getElementById('cancelMaterialRecipeVersion'),
        components: document.getElementById('materialRecipeComponents'),
        addComponent: document.getElementById('addMaterialRecipeComponent'),
        error: document.getElementById('materialRecipeError'),
        save: document.getElementById('saveMaterialRecipe'),
        filter: document.getElementById('materialRecipesClientFilter'),
        list: document.getElementById('materialRecipesList'),
    });

    recipeElements.reload.addEventListener('click', () => loadRecipesOffice(true));
    recipeElements.filter.addEventListener('change', renderRecipes);
    recipeElements.form.elements.cliente_id.addEventListener('change', () => {
        if (recipeState.editingRecipeId) return;
        populateRecipeItems();
        resetRecipeComponents();
    });
    recipeElements.form.elements.item_salida_id.addEventListener('change', () => {
        suggestSameItemTransformation();
    });
    recipeElements.addComponent.addEventListener('click', () => addRecipeComponent());
    recipeElements.components.addEventListener('click', (event) => {
        const remove = event.target.closest('[data-remove-recipe-component]');
        if (!remove || recipeElements.components.children.length <= 1) return;
        remove.closest('.materials-recipe-component').remove();
    });
    recipeElements.cancelVersion.addEventListener('click', resetRecipeForm);
    recipeElements.list.addEventListener('click', (event) => {
        const button = event.target.closest('[data-new-recipe-version]');
        if (button) openRecipeVersion(button.dataset.newRecipeVersion);
    });
    recipeElements.form.addEventListener('submit', submitRecipeForm);
}

function activeCatalogClientByGlobalId(globalClientId) {
    return recipeState.catalog.clientes.find((client) => client.cliente_id === globalClientId) || null;
}

function recipeItemsForGlobalClient(globalClientId) {
    const client = activeCatalogClientByGlobalId(globalClientId);
    if (!client) return [];
    return recipeState.catalog.items.filter((item) => item.cliente?.id === client.id && item.activo !== false);
}

function populateRecipeSelectors(selectedClientId = null) {
    const clients = recipeState.catalog.clientes || [];
    const currentClient = selectedClientId || recipeElements.form.elements.cliente_id.value || clients[0]?.cliente_id || '';
    const currentFilter = recipeElements.filter.value;

    recipeElements.form.elements.cliente_id.innerHTML = clients
        .map((client) => `<option value="${recipeEscape(client.cliente_id)}">${recipeEscape(client.codigo)} · ${recipeEscape(client.nombre)}</option>`)
        .join('') || '<option value="">Sin clientes activos</option>';
    recipeElements.form.elements.cliente_id.value = currentClient;

    const filterClients = new Map();
    clients.forEach((client) => filterClients.set(client.cliente_id, { id: client.cliente_id, codigo: client.codigo, nombre: client.nombre }));
    recipeState.recipes.forEach((recipe) => filterClients.set(recipe.cliente?.id, recipe.cliente));
    recipeElements.filter.innerHTML = '<option value="">Todos los clientes</option>' + [...filterClients.values()]
        .filter((client) => client?.id)
        .sort((left, right) => String(left.nombre).localeCompare(String(right.nombre), 'es'))
        .map((client) => `<option value="${recipeEscape(client.id)}">${recipeEscape(client.codigo)} · ${recipeEscape(client.nombre)}</option>`)
        .join('');
    recipeElements.filter.value = currentFilter;
    populateRecipeItems();
}

function populateRecipeItems() {
    const globalClientId = recipeElements.form.elements.cliente_id.value;
    const items = recipeItemsForGlobalClient(globalClientId);
    const currentOutput = recipeElements.form.elements.item_salida_id.value;
    const outputs = items.filter((item) => ['material_mp', 'material_pt'].includes(item.categoria_operacional));

    recipeElements.form.elements.item_salida_id.innerHTML = outputs
        .map((item) => `<option value="${recipeEscape(item.id)}">${recipeEscape(item.codigo)} · ${recipeEscape(item.nombre)} · ${item.categoria_operacional === 'material_mp' ? 'MP → PT mismo código' : 'Material PT'} · ${recipeEscape(item.unidad_medida)}</option>`)
        .join('') || '<option value="">El cliente no tiene ítems Material MP o PT activos</option>';
    if (outputs.some((item) => item.id === currentOutput)) recipeElements.form.elements.item_salida_id.value = currentOutput;

    recipeElements.components.querySelectorAll('[name="item_entrada_id"]').forEach((select) => {
        const previous = select.value;
        select.innerHTML = recipeInputOptions(globalClientId, previous);
    });
}

function suggestSameItemTransformation() {
    const outputId = recipeElements.form.elements.item_salida_id.value;
    const output = recipeState.catalog.items.find((item) => item.id === outputId);
    const rows = [...recipeElements.components.querySelectorAll('.materials-recipe-component')];
    if (output?.categoria_operacional !== 'material_mp' || rows.length !== 1) return;

    const input = rows[0].querySelector('[name="item_entrada_id"]');
    const principal = rows[0].querySelector('[name="recipe_principal"]');
    if ([...input.options].some((option) => option.value === output.id)) input.value = output.id;
    principal.checked = true;
}

function recipeInputOptions(globalClientId, selected = '') {
    const inputs = recipeItemsForGlobalClient(globalClientId)
        .filter((item) => ['insumo', 'material_mp'].includes(item.categoria_operacional));
    return inputs.map((item) => `<option value="${recipeEscape(item.id)}"${item.id === selected ? ' selected' : ''}>${recipeEscape(item.codigo)} · ${recipeEscape(item.nombre)} · ${recipeEscape(item.categoria_operacional_etiqueta || recipeStatus(item.categoria_operacional))}</option>`).join('')
        || '<option value="">Sin insumos o Material MP activos</option>';
}

function addRecipeComponent(component = {}) {
    const globalClientId = recipeElements.form.elements.cliente_id.value;
    const rowId = `recipe-component-${recipeRowId()}`;
    const row = document.createElement('div');
    row.className = 'materials-recipe-component';
    row.dataset.componentRow = rowId;
    row.innerHTML = `
        <label><span>Ítem de entrada *</span><select name="item_entrada_id" required>${recipeInputOptions(globalClientId, component.item?.id || component.item_entrada_id || '')}</select></label>
        <label><span>Cantidad *</span><input name="cantidad_estandar" type="number" min="0.001" step="0.001" value="${recipeEscape(component.cantidad_estandar ?? 1)}" required></label>
        <label><span>Factor</span><input name="factor_conversion" type="number" min="0.000001" step="0.000001" value="${recipeEscape(component.factor_conversion ?? 1)}"></label>
        <label><span>Merma %</span><input name="merma_estandar_porcentaje" type="number" min="0" max="100" step="0.0001" value="${recipeEscape(component.merma_estandar_porcentaje ?? 0)}"></label>
        <label><span>Tolerancia %</span><input name="tolerancia_porcentaje" type="number" min="0" max="100" step="0.0001" value="${recipeEscape(component.tolerancia_porcentaje ?? 0)}"></label>
        <div><label class="recipe-principal"><input name="recipe_principal" type="radio" value="${recipeEscape(rowId)}"${component.es_componente_principal ? ' checked' : ''}><span>Principal</span></label><button class="secondary-button" data-remove-recipe-component type="button" aria-label="Quitar componente">×</button></div>
    `;
    recipeElements.components.append(row);
}

function resetRecipeComponents(components = []) {
    recipeElements.components.innerHTML = '';
    if (components.length) components.forEach((component) => addRecipeComponent(component));
    else addRecipeComponent({ es_componente_principal: true });
}

function resetRecipeForm() {
    recipeState.editingRecipeId = null;
    recipeElements.form.reset();
    recipeElements.form.elements.recipe_id.value = '';
    recipeElements.form.elements.cliente_id.disabled = false;
    recipeElements.form.elements.item_salida_id.disabled = false;
    recipeElements.form.elements.nombre.disabled = false;
    recipeElements.formTitle.textContent = 'Nueva receta';
    recipeElements.save.textContent = 'Crear receta';
    recipeElements.cancelVersion.classList.add('is-hidden');
    recipeElements.error.textContent = '';
    populateRecipeSelectors();
    resetRecipeComponents();
}

function latestRecipeVersion(recipe) {
    return [...(recipe?.versiones || [])]
        .sort((left, right) => Number(right.numero_version) - Number(left.numero_version))[0] || null;
}

function isSameItemTransformation(recipe, version) {
    return recipe?.item_salida?.categoria_operacional === 'material_mp'
        && (version?.componentes || []).some((component) => component.es_componente_principal
            && component.item?.id === recipe.item_salida.id);
}

function openRecipeVersion(recipeId) {
    const recipe = recipeState.recipes.find((candidate) => candidate.id === recipeId);
    const version = latestRecipeVersion(recipe);
    const activeClient = activeCatalogClientByGlobalId(recipe?.cliente?.id);
    if (!recipe || !version || !activeClient || recipe.temporada?.activa !== true) return;

    recipeState.editingRecipeId = recipe.id;
    recipeElements.form.elements.recipe_id.value = recipe.id;
    populateRecipeSelectors(recipe.cliente.id);
    recipeElements.form.elements.item_salida_id.value = recipe.item_salida.id;
    recipeElements.form.elements.nombre.value = recipe.nombre;
    recipeElements.form.elements.cantidad_base_salida.value = version.cantidad_base_salida;
    recipeElements.form.elements.unidades_por_folio_salida.value = version.unidades_por_folio_salida
        || version.cantidad_base_salida;
    recipeElements.form.elements.cliente_id.disabled = true;
    recipeElements.form.elements.item_salida_id.disabled = true;
    recipeElements.form.elements.nombre.disabled = true;
    recipeElements.formTitle.textContent = `${recipe.nombre} · nueva versión`;
    recipeElements.save.textContent = `Crear versión ${Number(version.numero_version) + 1}`;
    recipeElements.cancelVersion.classList.remove('is-hidden');
    recipeElements.error.textContent = '';
    resetRecipeComponents(version.componentes || []);
    recipeElements.form.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function recipeComponentsPayload() {
    const rows = [...recipeElements.components.querySelectorAll('.materials-recipe-component')];
    const principal = recipeElements.components.querySelector('[name="recipe_principal"]:checked')?.value;
    if (!principal) throw new RecipeApiError('Selecciona exactamente un componente principal.', 422);

    const seen = new Set();
    return rows.map((row, index) => {
        const itemId = row.querySelector('[name="item_entrada_id"]').value;
        const amount = Number(row.querySelector('[name="cantidad_estandar"]').value);
        const factor = Number(row.querySelector('[name="factor_conversion"]').value || 1);
        const waste = Number(row.querySelector('[name="merma_estandar_porcentaje"]').value || 0);
        const tolerance = Number(row.querySelector('[name="tolerancia_porcentaje"]').value || 0);

        if (!itemId) throw new RecipeApiError(`Selecciona el ítem de entrada de la línea ${index + 1}.`, 422);
        if (seen.has(itemId)) throw new RecipeApiError('Un mismo ítem no puede repetirse dentro de la receta.', 422);
        if (!Number.isFinite(amount) || amount <= 0) throw new RecipeApiError(`La cantidad de la línea ${index + 1} debe ser mayor que cero.`, 422);
        if (!Number.isFinite(factor) || factor <= 0) throw new RecipeApiError(`El factor de la línea ${index + 1} debe ser mayor que cero.`, 422);
        if (waste < 0 || waste > 100 || tolerance < 0 || tolerance > 100) throw new RecipeApiError('Merma y tolerancia deben estar entre 0 y 100%.', 422);
        seen.add(itemId);

        return {
            item_entrada_id: itemId,
            cantidad_estandar: amount,
            es_componente_principal: row.dataset.componentRow === principal,
            factor_conversion: factor,
            merma_estandar_porcentaje: waste,
            tolerancia_porcentaje: tolerance,
        };
    });
}

async function submitRecipeForm(event) {
    event.preventDefault();
    recipeElements.error.textContent = '';
    const data = Object.fromEntries(new FormData(recipeElements.form));
    const originalButtonText = recipeElements.save.textContent;

    try {
        const amount = Number(data.cantidad_base_salida);
        const unitsPerFolio = Number(data.unidades_por_folio_salida);
        if (!Number.isFinite(amount) || amount <= 0) throw new RecipeApiError('La cantidad base de salida debe ser mayor que cero.', 422);
        if (!Number.isFinite(unitsPerFolio) || unitsPerFolio <= 0) throw new RecipeApiError('Las unidades por folio deben ser mayores que cero.', 422);

        const payload = {
            cantidad_base_salida: amount,
            unidades_por_folio_salida: unitsPerFolio,
            componentes: recipeComponentsPayload(),
        };
        let path = '/api/materiales/transformaciones/recetas';
        if (recipeState.editingRecipeId) {
            path += `/${recipeState.editingRecipeId}/versiones`;
        } else {
            if (!data.cliente_id || !data.item_salida_id || String(data.nombre || '').trim().length < 3) {
                throw new RecipeApiError('Completa cliente, producto de salida y nombre de la receta.', 422);
            }
            payload.cliente_id = data.cliente_id;
            payload.item_salida_id = data.item_salida_id;
            payload.nombre = String(data.nombre).trim();
        }

        recipeElements.save.disabled = true;
        recipeElements.save.textContent = recipeState.editingRecipeId ? 'Creando versión…' : 'Creando receta…';
        await recipeApi(path, { method: 'POST', body: JSON.stringify(payload) });
        resetRecipeForm();
        await loadRecipesOffice(true);
    } catch (error) {
        recipeElements.error.textContent = error.message;
    } finally {
        recipeElements.save.disabled = false;
        recipeElements.save.textContent = recipeState.editingRecipeId ? originalButtonText : 'Crear receta';
    }
}

function renderRecipes() {
    const selectedClient = recipeElements.filter.value;
    const recipes = recipeState.recipes.filter((recipe) => !selectedClient || recipe.cliente?.id === selectedClient);
    recipeElements.summary.textContent = `${recipeState.recipes.length} ${recipeState.recipes.length === 1 ? 'receta' : 'recetas'}`;

    recipeElements.list.innerHTML = recipes.map((recipe) => {
        const version = latestRecipeVersion(recipe);
        const components = version?.componentes || [];
        const principal = components.find((component) => component.es_componente_principal);
        const canVersion = canAdminRecipes()
            && recipe.temporada?.activa === true
            && activeCatalogClientByGlobalId(recipe.cliente?.id) !== null;
        const versionCount = Number(recipe.versiones_count ?? recipe.versiones?.length ?? 0);
        return `
            <article class="materials-recipe-card${recipe.activa ? '' : ' is-inactive'}">
                <div class="materials-recipe-card__header">
                    <div>
                        <h3>${recipeEscape(recipe.nombre)}</h3>
                        <small>${recipeEscape(recipe.temporada?.codigo)} · ${recipeEscape(recipe.cliente?.codigo)} · ${recipeEscape(recipe.cliente?.nombre)} → ${recipeEscape(recipe.item_salida?.codigo)} · ${recipeEscape(recipe.item_salida?.nombre)}${isSameItemTransformation(recipe, version) ? ' · MP → PT mismo código' : ''}</small>
                    </div>
                    ${canVersion ? `<button class="secondary-button" data-new-recipe-version="${recipeEscape(recipe.id)}" type="button">Nueva versión</button>` : ''}
                </div>
                <div class="materials-recipe-card__meta">
                    <span>Versión ${recipeEscape(version?.numero_version || '—')}</span>
                    <span>Historial: ${versionCount} ${versionCount === 1 ? 'versión' : 'versiones'}</span>
                    <span>${recipeEscape(recipeStatus(version?.estado || 'sin_version'))}</span>
                    <span>Salida base: ${recipeQuantity(version?.cantidad_base_salida)} ${recipeEscape(version?.unidad_medida_salida || recipe.item_salida?.unidad_medida || '')}</span>
                    <span>Por folio: ${version?.unidades_por_folio_salida ? `${recipeQuantity(version.unidades_por_folio_salida)} ${recipeEscape(version.unidad_medida_salida || recipe.item_salida?.unidad_medida || '')}` : 'Sin regla'}</span>
                    <span>Principal: ${recipeEscape(principal?.item?.codigo || 'No definido')}</span>
                    <span>Merma estándar: ${recipeQuantity(principal?.merma_estandar_porcentaje, 4)}%</span>
                </div>
                <table class="materials-recipe-table">
                    <thead><tr><th>Componente</th><th>Cantidad</th><th>Factor</th><th>Merma</th><th>Tolerancia</th></tr></thead>
                    <tbody>${components.map((component) => `<tr><td><strong>${recipeEscape(component.item?.codigo)}</strong><br><small>${recipeEscape(component.item?.nombre)}${component.es_componente_principal ? ' · principal' : ''}</small></td><td>${recipeQuantity(component.cantidad_estandar)} ${recipeEscape(component.unidad_medida)}</td><td>${recipeQuantity(component.factor_conversion, 6)}</td><td>${recipeQuantity(component.merma_estandar_porcentaje, 4)}%</td><td>${recipeQuantity(component.tolerancia_porcentaje, 4)}%</td></tr>`).join('')}</tbody>
                </table>
            </article>
        `;
    }).join('') || '<p class="materials-recipe-empty">No existen recetas para el filtro seleccionado.</p>';
}

function clearRecipeSession(token) {
    recipeState.token = token;
    recipeState.identity = token ? recipeReadJson(recipeIdentityKey) : null;
    recipeState.loadedToken = null;
    recipeState.catalog = { temporada: null, clientes: [], items: [] };
    recipeState.recipes = [];
    recipeState.editingRecipeId = null;
    recipeElements.panel?.classList.add('is-hidden');
    recipeElements.form.reset();
    recipeElements.form.classList.add('is-hidden');
    recipeElements.form.elements.cliente_id.disabled = false;
    recipeElements.form.elements.item_salida_id.disabled = false;
    recipeElements.form.elements.nombre.disabled = false;
    recipeElements.form.elements.cliente_id.innerHTML = '';
    recipeElements.form.elements.item_salida_id.innerHTML = '';
    recipeElements.components.innerHTML = '';
    recipeElements.filter.innerHTML = '<option value="">Todos los clientes</option>';
    recipeElements.formTitle.textContent = 'Nueva receta';
    recipeElements.save.textContent = 'Crear receta';
    recipeElements.cancelVersion.classList.add('is-hidden');
    recipeElements.summary.textContent = '0 recetas';
    recipeElements.list.innerHTML = '';
    recipeElements.error.textContent = '';
}

async function loadRecipesForToken(token, isCurrent, showErrors = false) {
    if (!recipeSectionIsActive() || !isCurrent() || !canConsultRecipes()) {
        recipeElements.panel?.classList.add('is-hidden');
        return;
    }

    const editingRecipeId = recipeState.editingRecipeId;
    recipeState.loading = true;
    recipeState.loadedToken = token;
    recipeElements.panel.classList.remove('is-hidden');
    recipeElements.form.classList.toggle('is-hidden', !canAdminRecipes());

    try {
        const [catalog, recipes] = await Promise.all([
            recipeApi('/api/materiales/catalogo', {}, token),
            recipeApi('/api/materiales/transformaciones/recetas?per_page=100', {}, token),
        ]);
        if (!isCurrent()) return;
        recipeState.catalog = catalog;
        recipeState.recipes = recipes.data || [];
        populateRecipeSelectors();
        if (editingRecipeId && canAdminRecipes()) openRecipeVersion(editingRecipeId);
        else if (!recipeState.editingRecipeId) resetRecipeComponents();
        renderRecipes();
    } catch (error) {
        if (!isCurrent()) return;
        if (showErrors || !recipeState.recipes.length) {
            recipeElements.list.innerHTML = `<p class="materials-recipe-empty">${recipeEscape(error.message)}</p>`;
        }
    } finally {
        recipeState.loading = false;
    }
}

function loadRecipesOffice(showErrors = false) {
    return recipeState.session?.reload(showErrors) ?? Promise.resolve(false);
}

function bootMaterialRecipes() {
    injectRecipePanel();
    if (!recipeElements.panel) return;
    if (!recipeSectionIsActive()) return;

    document.getElementById('reloadMaterialsButton')?.addEventListener('click', () => loadRecipesOffice(false));
    recipeState.session = createOfficeSessionSync({
        tokenKey: recipeTokenKey,
        getToken: () => localStorage.getItem(recipeTokenKey),
        onSessionChange: clearRecipeSession,
        load: loadRecipesForToken,
    });
    recipeState.session.start();
}

bootMaterialRecipes();
