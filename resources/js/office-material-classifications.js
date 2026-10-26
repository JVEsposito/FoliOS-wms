import { materialCategoryOptions, materialItemTypeOptions, materialItemTypeId } from './shared/material-classification-options.js';
import { materialCatalogLabel } from './shared/material-catalog-label.js';

export function createMaterialClassifications({ api, getCatalogs, refresh, toast, setBusy }) {
    const byId = id => document.getElementById(id);
    const itemForm = byId('itemMaterialForm');
    const categoryForm = byId('materialCategoryForm');
    const typeForm = byId('materialItemTypeForm');
    const esc = value => String(value ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&#039;');
    let originalItem = null;

    function fillOptions(select, options, placeholder, selected = select.value) {
        select.innerHTML = `<option value="">${placeholder}</option>`
            + options.map(option => `<option value="${esc(option.value)}">${esc(option.label)}</option>`).join('');
        select.value = options.some(option => option.value === selected) ? selected : '';
    }

    function renderItemFields() {
        const { categorias = [], tipos_item = [] } = getCatalogs();
        fillOptions(itemForm.elements.categoria, materialCategoryOptions(categorias, originalItem?.categoria || ''), 'Sin categoría comercial');
        fillOptions(itemForm.elements.tipo_item_material_id, materialItemTypeOptions(tipos_item, materialItemTypeId(originalItem, tipos_item)), 'Selecciona un tipo');
        const filter = byId('itemsMaterialClassificationFilter');
        fillOptions(filter, tipos_item.map(type => ({ value: type.id, label: `${materialCatalogLabel(type.nombre)}${type.activo ? '' : ' · inactivo'}` })), 'Todos los tipos');
        renderTypeHelp();
    }

    function renderTypeHelp() {
        const selected = getCatalogs().tipos_item?.find(type => type.id === itemForm.elements.tipo_item_material_id.value);
        byId('materialItemTypeHelp').textContent = selected
            ? `Uso del material: ${selected.categoria_operacional_etiqueta}.`
            : 'Selecciona un tipo para indicar cómo se usa el material en recepción y recetas.';
    }

    function render() {
        const { categorias = [], tipos_item = [] } = getCatalogs();
        byId('materialCategoriesSummary').textContent = `${categorias.length} registradas`;
        byId('materialItemTypesSummary').textContent = `${tipos_item.length} registrados`;
        byId('materialCategoriesList').innerHTML = categorias.map(category => `<article class="material-row${category.activo ? '' : ' is-inactive'}"><div><strong>${esc(category.nombre)}</strong><small>${category.activo ? 'Activa' : 'Inactiva'}${category.en_uso ? ' · en uso' : ''}</small></div><button type="button" data-edit-material-category="${esc(category.id)}">Editar</button></article>`).join('') || '<p class="empty-state">Crea una categoría comercial para organizar los materiales.</p>';
        byId('materialItemTypesList').innerHTML = tipos_item.map(type => `<article class="material-row${type.activo ? '' : ' is-inactive'}"><div><strong>${esc(type.nombre)}</strong><small>${esc(type.categoria_operacional_etiqueta)} · ${type.activo ? 'Activo' : 'Inactivo'}${type.en_uso ? ' · en uso' : ''}</small></div><button type="button" data-edit-material-type="${esc(type.id)}">Editar</button></article>`).join('') || '<p class="empty-state">No hay tipos de ítem registrados.</p>';
        renderItemFields();
    }

    function resetForm(form, cancelId, errorId) {
        form.reset(); form.elements.id.value = ''; form.elements.activo.checked = true;
        form.elements.nombre.readOnly = false;
        if (form.elements.categoria_operacional) form.elements.categoria_operacional.disabled = false;
        byId(cancelId).classList.add('is-hidden'); byId(errorId).textContent = '';
    }

    for (const [form, path, cancelId, errorId, message] of [
        [categoryForm, 'categorias', 'cancelMaterialCategoryEdit', 'materialCategoryError', 'Categoría comercial guardada.'],
        [typeForm, 'tipos-items', 'cancelMaterialItemTypeEdit', 'materialItemTypeError', 'Tipo de ítem guardado.'],
    ]) {
        form.addEventListener('submit', async event => {
            event.preventDefault(); byId(errorId).textContent = '';
            const data = Object.fromEntries(new FormData(form)); const id = data.id; delete data.id;
            data.activo = form.elements.activo.checked;
            if (form.elements.categoria_operacional) data.categoria_operacional = form.elements.categoria_operacional.value;
            setBusy(true, 'Guardando catálogo…');
            try {
                await api(`/api/administracion/materiales/${path}${id ? `/${id}` : ''}`, { method: id ? 'PUT' : 'POST', body: JSON.stringify(data) });
                resetForm(form, cancelId, errorId); await refresh(); toast(message);
            } catch (error) { byId(errorId).textContent = error.message; }
            finally { setBusy(false); }
        });
        byId(cancelId).addEventListener('click', () => resetForm(form, cancelId, errorId));
    }

    byId('materialCategoriesList').addEventListener('click', event => {
        const id = event.target.closest('[data-edit-material-category]')?.dataset.editMaterialCategory;
        const category = getCatalogs().categorias?.find(candidate => candidate.id === id);
        if (!category) return;
        categoryForm.elements.id.value = id; categoryForm.elements.nombre.value = category.nombre;
        categoryForm.elements.nombre.readOnly = category.en_uso;
        categoryForm.elements.activo.checked = category.activo;
        byId('cancelMaterialCategoryEdit').classList.remove('is-hidden'); byId('materialCategoryError').textContent = '';
        categoryForm.scrollIntoView({ block: 'center', behavior: 'smooth' });
    });
    byId('materialItemTypesList').addEventListener('click', event => {
        const id = event.target.closest('[data-edit-material-type]')?.dataset.editMaterialType;
        const type = getCatalogs().tipos_item?.find(candidate => candidate.id === id);
        if (!type) return;
        typeForm.elements.id.value = id; typeForm.elements.nombre.value = type.nombre;
        typeForm.elements.activo.checked = type.activo;
        typeForm.elements.categoria_operacional.value = type.categoria_operacional;
        typeForm.elements.categoria_operacional.disabled = Boolean(type.en_uso || type.codigo_base);
        byId('cancelMaterialItemTypeEdit').classList.remove('is-hidden'); byId('materialItemTypeError').textContent = '';
        typeForm.scrollIntoView({ block: 'center', behavior: 'smooth' });
    });
    byId('manageMaterialClassifications').addEventListener('click', () => byId('materials-catalog-tab-classifications')?.click());
    itemForm.elements.tipo_item_material_id.addEventListener('change', renderTypeHelp);

    return {
        render,
        resetSelection() { originalItem = null; renderItemFields(); },
        selectItem(item) {
            originalItem = item;
            renderItemFields();
            itemForm.elements.categoria.value = item.categoria || '';
            itemForm.elements.tipo_item_material_id.value = materialItemTypeId(item, getCatalogs().tipos_item || []);
            renderTypeHelp();
        },
    };
}
