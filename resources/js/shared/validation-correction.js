import { createSearchSelect } from './search-select.js';
import {
    articlesForOrigins,
    brandOptions,
    categoryOptions,
    clientOptions,
    correctionPayload,
    csgOptions,
    draftChanges,
    draftFromValidation,
    draftIssues,
    indexCatalog,
    keepCompatibleProduct,
    newLine,
    productChoices,
    totalBoxes,
} from './validation-correction-logic.js';

/**
 * Diálogo de corrección administrativa, compartido por Validación y Anulaciones.
 * Necesita el parcial office.partials.validation-correction-dialog en la página.
 */
function escapeHtml(value) {
    return String(value ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&#039;');
}

const STATES = {
    pendiente_prefrio: 'Pendiente de prefrío',
    pendiente_ubicacion: 'Pendiente de ubicación',
    disponible: 'Disponible',
    bloqueado: 'Bloqueado',
    despachado: 'Despachado',
    agotado: 'Agotado',
    retirado_definitivo: 'Retirado',
};

function statusText(value) {
    return String(value || '').replaceAll('_', ' ').replace(/^./, (letter) => letter.toUpperCase());
}

function randomUuid() {
    if (typeof crypto.randomUUID === 'function') return crypto.randomUUID();
    const bytes = crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = [...bytes].map((byte) => byte.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

export function createValidationCorrection({ api, setBusy = () => {}, toast = () => {}, onSaved = () => {} }) {
    const dialog = document.getElementById('validationCorrectionDialog');
    if (!dialog) return { open: async () => {} };
    const form = dialog.querySelector('form');
    const elements = {
        title: document.getElementById('validationCorrectionTitle'),
        state: document.getElementById('validationCorrectionState'),
        lines: document.getElementById('vcLines'),
        total: document.getElementById('vcTotal'),
        addLine: document.getElementById('vcAddLine'),
        productHint: document.getElementById('vcProductHint'),
        review: document.getElementById('vcReview'),
        error: document.getElementById('validationCorrectionError'),
        submit: document.getElementById('vcSubmit'),
    };
    let context = null;
    let index = null;
    let draft = null;
    let operationId = null;
    let notice = '';
    const loadedClients = new Set();
    const linePickers = new Map();
    const pickers = {};

    const picker = (name, label, placeholder, onChange) => {
        pickers[name] = createSearchSelect(dialog.querySelector(`[data-vc-picker="${name}"]`), { label, placeholder, onChange });
    };
    picker('cliente', 'Cliente', 'Buscar cliente', async (value) => {
        draft.cliente = value;
        draft.marca = '';
        clearForeignOrigins();
        await loadClient(value);
        renderLines();
        refresh();
    });
    picker('marca', 'Marca', 'Buscar marca', (value) => {
        draft.marca = value;
        clearForeignOrigins();
        renderLines();
        refresh();
    });
    picker('especie', 'Especie', 'Buscar especie', (value) => { Object.assign(draft, { especie: value, variedad: '', calibre: '', envase: '' }); refresh(); });
    picker('variedad', 'Variedad', 'Buscar variedad', (value) => { Object.assign(draft, { variedad: value, calibre: '', envase: '' }); refresh(); });
    picker('calibre', 'Calibre', 'Buscar calibre', (value) => { Object.assign(draft, { calibre: value, envase: '' }); refresh(); });
    picker('envase', 'Embalaje', 'Buscar por nombre o código', (value) => { draft.envase = value; refresh(); });
    picker('categoria', 'Categoría', 'Buscar categoría', (value) => { draft.categoriaId = value; refresh(); });

    /** Los artículos llegan por cliente: al elegir otro cliente se piden los suyos una vez. */
    async function loadClient(client) {
        if (!client || loadedClients.has(client)) return;
        setBusy(true, `Cargando el catálogo de ${client}…`);
        try {
            const data = await api(`/api/validacion/pallets/${encodeURIComponent(context.validacion.id)}/correccion?cliente=${encodeURIComponent(client)}`);
            const known = new Set(context.catalogo.articulos.map((article) => article.id));
            context.catalogo.articulos = [...context.catalogo.articulos, ...data.catalogo.articulos.filter((article) => !known.has(article.id))];
            context.catalogo.articulos_por_origen = { ...context.catalogo.articulos_por_origen, ...data.catalogo.articulos_por_origen };
            loadedClients.add(client);
            index = indexCatalog(context.catalogo);
        } catch (error) {
            toast(error.message || 'No fue posible cargar el catálogo del cliente.', true);
        } finally {
            setBusy(false);
        }
    }

    function clearForeignOrigins() {
        draft.lines = draft.lines.map((line) => {
            const origin = index.originById.get(line.originId);
            return origin && origin.cliente === draft.cliente && origin.marca === draft.marca ? line : { ...line, originId: '' };
        });
    }

    function renderLines() {
        linePickers.clear();
        elements.lines.innerHTML = '';
        draft.lines.forEach((line, position) => {
            const number = position + 1;
            const row = document.createElement('div');
            row.className = 'vc-line';
            row.dataset.key = line.key;
            row.innerHTML = `<span class="vc-line__number" aria-hidden="true">${number}</span>
                <div class="vc-field vc-line__csg"><span>CSG</span><div data-line-picker></div></div>
                <label class="vc-field vc-line__boxes"><span>Cajas</span><input data-line-field="boxes" type="number" min="1" step="1" inputmode="numeric" value="${escapeHtml(line.boxes)}" aria-label="Cajas de la línea ${number}"></label>
                <label class="vc-field"><span>Lote MP</span><input data-line-field="lot" maxlength="80" autocomplete="off" value="${escapeHtml(line.lot)}" aria-label="Lote de materia prima de la línea ${number}"></label>
                <label class="vc-field"><span>Proceso</span><input data-line-field="process" maxlength="80" autocomplete="off" value="${escapeHtml(line.process)}" aria-label="Proceso de packing de la línea ${number}"></label>
                <button class="vc-line__remove" type="button" data-remove-line aria-label="Quitar la línea ${number}"${draft.lines.length === 1 ? ' hidden' : ''}>×</button>`;
            elements.lines.append(row);
            const csg = createSearchSelect(row.querySelector('[data-line-picker]'), {
                label: `CSG de la línea ${number}`,
                placeholder: 'Buscar CSG o predio',
                emptyText: 'Ningún CSG de este cliente y marca coincide',
                onChange: (value) => {
                    const lines = draft.lines.map((item) => item.key === line.key ? { ...item, originId: value } : item);
                    const ids = lines.map((item) => item.originId).filter(Boolean);
                    // Igual que la PDA: un CSG sin producto en común con los demás no se acepta.
                    if (ids.length > 1 && !articlesForOrigins(index, ids).length) {
                        const csgs = [...new Set(ids.map((id) => index.originById.get(id)?.csg || id))].join(' + ');
                        notice = `Los CSG ${csgs} no tienen un producto habilitado en común. Elige otro CSG o revisa sus variedades en el catálogo.`;
                        refresh();
                        return;
                    }
                    notice = '';
                    draft.lines = lines;
                    refresh();
                },
            });
            linePickers.set(line.key, csg);
        });
    }

    /** Elige solo lo que tiene una única opción, como especie cuando el CSG es de cerezas. */
    function autoSelectProduct() {
        for (let step = 0; step < 4; step += 1) {
            const choices = productChoices(index, draft);
            const next = !draft.especie && choices.species.length === 1 ? ['especie', choices.species]
                : draft.especie && !draft.variedad && choices.varieties.length === 1 ? ['variedad', choices.varieties]
                    : draft.variedad && !draft.calibre && choices.calibers.length === 1 ? ['calibre', choices.calibers]
                        : draft.calibre && !draft.envase && choices.packages.length === 1 ? ['envase', choices.packages] : null;
            if (!next) return;
            draft[next[0]] = next[1][0].value;
        }
    }

    function refresh() {
        draft = keepCompatibleProduct(index, draft);
        const brands = brandOptions(index, draft.cliente);
        if (draft.cliente && !draft.marca && brands.length === 1) {
            draft.marca = brands[0].value;
            clearForeignOrigins();
        }
        autoSelectProduct();

        pickers.cliente.setOptions(clientOptions(index));
        pickers.cliente.setValue(draft.cliente);
        pickers.marca.setOptions(brands);
        pickers.marca.setValue(draft.marca);
        pickers.marca.setDisabled(!draft.cliente);
        const csgs = csgOptions(index, draft.cliente, draft.marca);
        for (const line of draft.lines) {
            const csg = linePickers.get(line.key);
            if (!csg) continue;
            csg.setOptions(csgs);
            csg.setValue(line.originId);
            csg.setDisabled(!draft.marca);
        }
        elements.addLine.disabled = !draft.marca || draft.lines.length >= 20;

        const choices = productChoices(index, draft);
        const withOrigin = draft.lines.filter((line) => index.originById.has(line.originId));
        pickers.especie.setOptions(choices.species);
        pickers.especie.setValue(draft.especie);
        pickers.especie.setDisabled(!choices.eligible.length);
        pickers.variedad.setOptions(choices.varieties);
        pickers.variedad.setValue(draft.variedad);
        pickers.variedad.setDisabled(!draft.especie);
        pickers.calibre.setOptions(choices.calibers);
        pickers.calibre.setValue(draft.calibre);
        pickers.calibre.setDisabled(!draft.variedad);
        pickers.envase.setOptions(choices.packages);
        pickers.envase.setValue(draft.envase);
        pickers.envase.setDisabled(!draft.calibre);
        pickers.categoria.setOptions(categoryOptions(index));
        pickers.categoria.setValue(draft.categoriaId);
        const csgList = [...new Set(withOrigin.map((line) => index.originById.get(line.originId).csg))].join(' + ');
        elements.productHint.textContent = notice || (!withOrigin.length
            ? 'Elige el CSG para ver los productos habilitados.'
            : !choices.eligible.length
                ? `Los CSG ${csgList} no tienen un producto habilitado en común. Revisa el CSG o el catálogo de la temporada.`
                : `Solo aparecen los productos habilitados en ${withOrigin.length > 1 ? 'los CSG' : 'el CSG'} ${csgList}.`);
        elements.productHint.classList.toggle('is-warning', Boolean(notice) || (withOrigin.length > 0 && !choices.eligible.length));

        const total = totalBoxes(draft);
        elements.total.textContent = `${new Intl.NumberFormat('es-CL').format(total)} ${total === 1 ? 'caja' : 'cajas'}`;
        renderReview();
    }

    function renderReview() {
        const changes = draftChanges(context.validacion, draft, index);
        const issues = draftIssues(draft, index, { requireLotAndProcess: context.exige_lote_proceso === true });
        const list = changes.length
            ? `<ul>${changes.map((change) => `<li><b>${escapeHtml(change.label)}</b><span>${escapeHtml(change.before)}</span><span aria-hidden="true">→</span><span class="vc-review__after">${escapeHtml(change.after)}</span></li>`).join('')}</ul>`
            : '<p>Todavía no hay cambios respecto de lo validado.</p>';
        const missing = issues.length && changes.length ? `<p class="vc-review__missing">Para guardar: ${escapeHtml(issues[0])}</p>` : '';
        elements.review.innerHTML = `<h3>Revisión</h3>${list}${missing}`;
        elements.submit.disabled = !changes.length;
    }

    function close() {
        if (dialog.open) dialog.close();
        context = null;
        draft = null;
        operationId = null;
        elements.error.textContent = '';
    }

    async function open(validationId, folioNumber = '') {
        setBusy(true, 'Cargando la validación y su catálogo…');
        try {
            context = await api(`/api/validacion/pallets/${encodeURIComponent(validationId)}/correccion`);
        } catch (error) {
            toast(error.message || 'No fue posible cargar la validación.', true);
            return;
        } finally {
            setBusy(false);
        }
        index = indexCatalog(context.catalogo);
        loadedClients.clear();
        loadedClients.add(context.catalogo.cliente);
        draft = draftFromValidation(context.validacion, index);
        operationId = randomUuid();
        notice = '';
        const validation = context.validacion;
        elements.title.textContent = `Corregir ${validation.numero_folio || folioNumber}`;
        const folio = validation.folio || {};
        const states = [...new Set([folio.estado_operacional, folio.condicion_termica].filter(Boolean).map((value) => STATES[value] || statusText(value)))];
        elements.state.textContent = `${[context.catalogo?.temporada?.nombre, `Folio ${states.join(' · ').toLowerCase()}`, folio.activo === false ? 'inactivo' : null].filter(Boolean).join(' · ')}. `
            + 'Se corrigen la validación y el folio; su estado, cámara y despacho no cambian.';
        form.elements.tipo_bulto.value = draft.tipo_bulto;
        form.elements.linea_proceso.value = draft.linea_proceso;
        form.elements.turno.value = draft.turno;
        form.elements.motivo_correccion.value = '';
        elements.error.textContent = '';
        renderLines();
        refresh();
        dialog.showModal();
    }

    async function submit(event) {
        event.preventDefault();
        if (!draft) return;
        draft.motivo = form.elements.motivo_correccion.value;
        const issues = draftIssues(draft, index, { requireLotAndProcess: context.exige_lote_proceso === true });
        if (issues.length) {
            elements.error.textContent = issues[0];
            return;
        }
        const article = productChoices(index, draft).article;
        elements.error.textContent = '';
        elements.submit.disabled = true;
        setBusy(true, `Corrigiendo ${context.validacion.numero_folio}…`);
        try {
            const response = await api(`/api/validacion/pallets/${encodeURIComponent(context.validacion.id)}/corregir`, {
                method: 'PUT',
                body: JSON.stringify(correctionPayload(draft, article, operationId)),
            });
            close();
            toast(response?.message || 'Validación corregida y auditada.');
            await onSaved();
        } catch (error) {
            elements.error.textContent = error.message || 'No fue posible guardar la corrección.';
            elements.submit.disabled = false;
        } finally {
            setBusy(false);
        }
    }

    form.addEventListener('submit', (event) => { void submit(event); });
    form.addEventListener('change', (event) => {
        if (!draft) return;
        const name = event.target.name;
        if (['tipo_bulto', 'linea_proceso', 'turno'].includes(name)) {
            draft[name] = event.target.value;
            renderReview();
        }
    });
    form.elements.motivo_correccion.addEventListener('input', (event) => {
        if (!draft) return;
        draft.motivo = event.target.value;
        renderReview();
    });
    elements.lines.addEventListener('input', (event) => {
        const field = event.target.dataset.lineField;
        const key = event.target.closest('[data-key]')?.dataset.key;
        if (!field || !key || !draft) return;
        draft.lines = draft.lines.map((line) => line.key === key ? { ...line, [field]: event.target.value } : line);
        const total = totalBoxes(draft);
        elements.total.textContent = `${new Intl.NumberFormat('es-CL').format(total)} ${total === 1 ? 'caja' : 'cajas'}`;
        renderReview();
    });
    elements.lines.addEventListener('click', (event) => {
        const key = event.target.closest('[data-remove-line]') && event.target.closest('[data-key]')?.dataset.key;
        if (!key || draft.lines.length === 1) return;
        draft.lines = draft.lines.filter((line) => line.key !== key);
        notice = '';
        renderLines();
        refresh();
    });
    elements.addLine.addEventListener('click', () => {
        draft.lines = [...draft.lines, newLine()];
        renderLines();
        refresh();
        linePickers.get(draft.lines.at(-1).key)?.focus();
    });
    dialog.querySelectorAll('[data-vc-close]').forEach((button) => button.addEventListener('click', close));
    dialog.addEventListener('cancel', (event) => { event.preventDefault(); close(); });

    return { open };
}
