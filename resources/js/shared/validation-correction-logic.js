/**
 * Lógica de la corrección administrativa de una validación, sin DOM.
 * Sigue el mismo orden y las mismas reglas que la PDA: cliente → marca → líneas por CSG
 * (cajas, lote MP y proceso) → producto habilitado en todos esos CSG → categoría.
 */

/** Sin mayúsculas, tildes ni espacios repetidos: «Cartón 10 KG» = «carton 10 kg». */
export function normalizeSearch(value) {
    return String(value ?? '')
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .replace(/\s+/g, ' ')
        .trim()
        .toLowerCase();
}

/** Cada palabra buscada debe aparecer en el texto de la opción. */
export function optionMatches(option, query) {
    const words = normalizeSearch(query).split(' ').filter(Boolean);
    if (!words.length) return true;
    const text = normalizeSearch([option.label, option.hint, option.search].filter(Boolean).join(' '));
    return words.every((word) => text.includes(word));
}

export function filterOptions(options, query) {
    return options.filter((option) => optionMatches(option, query));
}

function sameText(first, second) {
    return normalizeSearch(first) === normalizeSearch(second);
}

function codeKey(value) {
    return String(value ?? '').trim().toUpperCase();
}

const collator = new Intl.Collator('es', { numeric: true, sensitivity: 'base' });

export function uniqueSorted(values) {
    return [...new Set(values.filter((value) => value !== null && value !== undefined && String(value).trim() !== ''))]
        .sort((first, second) => collator.compare(String(first), String(second)));
}

/** Índice del catálogo de la temporada, con la misma forma que usa la PDA. */
export function indexCatalog(catalog) {
    const articles = (catalog?.articulos || []).filter((article) => article.activo !== false && article.activo !== 0);
    const origins = (catalog?.origenes || []).filter((origin) => origin.activo !== false && origin.activo !== 0);
    const articleById = new Map(articles.map((article) => [article.id, article]));
    const originById = new Map(origins.map((origin) => [origin.id, origin]));
    const articlesByOrigin = new Map();
    for (const combination of catalog?.combinaciones || []) {
        if (!originById.has(combination.origen_validacion_id) || !articleById.has(combination.articulo_validacion_id)) continue;
        if (!articlesByOrigin.has(combination.origen_validacion_id)) articlesByOrigin.set(combination.origen_validacion_id, new Set());
        articlesByOrigin.get(combination.origen_validacion_id).add(combination.articulo_validacion_id);
    }
    return {
        articleById,
        originById,
        articlesByOrigin,
        origins,
        categories: (catalog?.categorias || []).filter((category) => category.activo !== false && category.activo !== 0),
    };
}

/** Artículos habilitados en todos los CSG elegidos y con la variedad autorizada en cada uno. */
export function articlesForOrigins(index, originIds) {
    const ids = [...new Set(originIds.filter(Boolean))];
    if (!ids.length || ids.some((id) => !index.originById.has(id))) return [];
    const first = index.articlesByOrigin.get(ids[0]);
    if (!first) return [];
    const result = [];
    for (const articleId of first) {
        if (!ids.every((id) => index.articlesByOrigin.get(id)?.has(articleId))) continue;
        const article = index.articleById.get(articleId);
        const authorized = ids.every((id) => {
            const varieties = index.originById.get(id)?.variedad_ids;
            return varieties === null || varieties === undefined
                || (article.variedad_validacion_id && varieties.includes(article.variedad_validacion_id));
        });
        if (article && authorized) result.push(article);
    }
    return result;
}

export function clientOptions(index) {
    return uniqueSorted(index.origins.map((origin) => origin.cliente)).map((value) => ({ value, label: value }));
}

export function brandOptions(index, client) {
    return uniqueSorted(index.origins.filter((origin) => !client || origin.cliente === client).map((origin) => origin.marca))
        .map((value) => ({ value, label: value }));
}

/** CSG del cliente y la marca, buscables por código o predio. */
export function csgOptions(index, client, brand) {
    return index.origins
        .filter((origin) => origin.cliente === client && origin.marca === brand)
        .sort((first, second) => collator.compare(String(first.csg), String(second.csg)))
        .map((origin) => ({ value: origin.id, label: String(origin.csg), hint: origin.predio || '', search: origin.codigo_externo || '' }));
}

export function categoryOptions(index) {
    return [...index.categories]
        .sort((first, second) => collator.compare(first.nombre, second.nombre))
        .map((category) => ({ value: category.id, label: category.nombre, hint: category.codigo_externo || '' }));
}

/** Opciones de cada paso del producto, filtradas por los CSG y por lo ya elegido. */
export function productChoices(index, draft) {
    const eligible = articlesForOrigins(index, draft.lines.map((line) => line.originId));
    const bySpecies = eligible.filter((article) => !draft.especie || article.especie === draft.especie);
    const byVariety = bySpecies.filter((article) => !draft.variedad || article.variedad === draft.variedad);
    const byCaliber = byVariety.filter((article) => !draft.calibre || article.calibre === draft.calibre);
    const article = eligible.find((item) => item.especie === draft.especie && item.variedad === draft.variedad
        && item.calibre === draft.calibre && item.envase === draft.envase) || null;
    const asOptions = (values) => uniqueSorted(values).map((value) => ({ value, label: value }));

    return {
        eligible,
        species: asOptions(eligible.map((item) => item.especie)),
        varieties: asOptions(bySpecies.map((item) => item.variedad)),
        calibers: asOptions(byVariety.map((item) => item.calibre)),
        packages: uniqueSorted(byCaliber.map((item) => item.envase)).map((value) => {
            const codes = uniqueSorted(byCaliber.filter((item) => item.envase === value).map((item) => item.envase_codigo || item.codigo_externo));
            return { value, label: value, hint: codes.join(' · ') };
        }),
        article,
    };
}

/** Si el producto elegido ya no está habilitado en los CSG, se vacía desde el primer paso que no calza. */
export function keepCompatibleProduct(index, draft) {
    const eligible = articlesForOrigins(index, draft.lines.map((line) => line.originId));
    const next = { ...draft };
    const fields = ['especie', 'variedad', 'calibre', 'envase'];
    for (let position = 0; position < fields.length; position += 1) {
        const prefix = fields.slice(0, position + 1);
        if (!next[fields[position]]) continue;
        if (!eligible.some((article) => prefix.every((field) => article[field] === next[field]))) {
            for (const field of fields.slice(position)) next[field] = '';
            break;
        }
    }
    return next;
}

let lineCounter = 0;
export function newLine(values = {}) {
    lineCounter += 1;
    return { key: `linea-${lineCounter}`, originId: '', boxes: '', lot: '', process: '', ...values };
}

/** El borrador parte de lo validado: mismas líneas, mismo producto y categoría. */
export function draftFromValidation(validation, index) {
    const lines = (validation.composicion || []).map((line) => newLine({
        originId: line.origen_validacion_id || '',
        boxes: String(line.cantidad_cajas ?? ''),
        lot: line.lote_materia_prima || '',
        process: line.proceso_packing || '',
    }));
    const firstOrigin = lines.map((line) => index.originById.get(line.originId)).find(Boolean);
    const article = index.articleById.get(validation.articulo_validacion_id) || validation.articulo || {};
    const draft = {
        tipo_bulto: validation.tipo_bulto || 'pallet',
        linea_proceso: String(validation.linea_proceso || 1),
        turno: validation.turno || 'A',
        cliente: firstOrigin?.cliente || validation.origen?.cliente || '',
        marca: firstOrigin?.marca || validation.origen?.marca || '',
        lines: lines.length ? lines : [newLine()],
        especie: article.especie || '',
        variedad: article.variedad || '',
        calibre: article.calibre || '',
        envase: article.envase || '',
        categoriaId: index.categories.some((category) => category.id === validation.categoria_validacion_id) ? validation.categoria_validacion_id : '',
        motivo: '',
    };
    return keepCompatibleProduct(index, draft);
}

export function totalBoxes(draft) {
    return draft.lines.reduce((sum, line) => sum + (Number.isInteger(Number(line.boxes)) && Number(line.boxes) > 0 ? Number(line.boxes) : 0), 0);
}

function lineKey(line) {
    return [line.originId, codeKey(line.lot), codeKey(line.process)].join('|');
}

/** Lo que falta o no calza, en el orden del formulario. Vacío: se puede guardar. */
export function draftIssues(draft, index, { requireLotAndProcess = false } = {}) {
    const issues = [];
    if (!draft.cliente) issues.push('Elige el cliente.');
    else if (!draft.marca) issues.push('Elige la marca.');
    draft.lines.forEach((line, position) => {
        const number = draft.lines.length > 1 ? ` de la línea ${position + 1}` : '';
        const origin = index.originById.get(line.originId);
        if (!line.originId) issues.push(`Elige el CSG${number}.`);
        else if (!origin) issues.push(`El CSG${number} ya no está activo en el catálogo: elige otro.`);
        else if (origin.cliente !== draft.cliente || origin.marca !== draft.marca) issues.push(`El CSG${number} no es de ${draft.cliente} · ${draft.marca}.`);
        if (!Number.isInteger(Number(line.boxes)) || Number(line.boxes) < 1) issues.push(`Ingresa las cajas${number}.`);
        if (requireLotAndProcess && !String(line.lot).trim()) issues.push(`Ingresa el lote de materia prima${number}.`);
        if (requireLotAndProcess && !String(line.process).trim()) issues.push(`Ingresa el proceso de packing${number}.`);
    });
    if (new Set(draft.lines.map(lineKey)).size !== draft.lines.length) issues.push('No repitas la misma combinación de CSG, lote y proceso.');
    const choices = productChoices(index, draft);
    if (draft.lines.every((line) => index.originById.has(line.originId)) && !choices.eligible.length) {
        issues.push('Estos CSG no tienen un producto habilitado en común.');
    } else if (!choices.article) {
        issues.push(!draft.especie ? 'Elige la especie.' : !draft.variedad ? 'Elige la variedad.' : !draft.calibre ? 'Elige el calibre.' : 'Elige el embalaje.');
    }
    if (!draft.categoriaId) issues.push('Elige la categoría.');
    if (String(draft.motivo).trim().length < 5) issues.push('Explica el motivo de la corrección.');
    return issues;
}

export function correctionPayload(draft, article, operationId) {
    return {
        operacion_id: operationId,
        tipo_bulto: draft.tipo_bulto,
        cantidad_cajas: totalBoxes(draft),
        linea_proceso: Number(draft.linea_proceso),
        turno: draft.turno,
        articulo_validacion_id: article?.id || null,
        categoria_validacion_id: draft.categoriaId,
        composicion: draft.lines.map((line) => ({
            origen_validacion_id: line.originId,
            cantidad_cajas: Number(line.boxes),
            lote_materia_prima: String(line.lot).trim() || null,
            proceso_packing: String(line.process).trim() || null,
        })),
        motivo_correccion: String(draft.motivo).trim(),
    };
}

function compositionText(lines, index) {
    return lines.map((line) => {
        const csg = index.originById.get(line.originId)?.csg || line.csg || '—';
        const extra = [line.lot && `lote ${codeKey(line.lot)}`, line.process && `proceso ${codeKey(line.process)}`].filter(Boolean).join(', ');
        return `${csg}: ${line.boxes}${extra ? ` (${extra})` : ''}`;
    }).join(' · ');
}

/** Resumen de lo que cambia frente a lo validado, para revisar antes de guardar. */
export function draftChanges(validation, draft, index) {
    const changes = [];
    const add = (label, before, after) => {
        if (!sameText(before, after)) changes.push({ label, before: String(before || '—'), after: String(after || '—') });
    };
    const bulk = { pallet: 'Pallet completo', saldo: 'Saldo' };
    add('Tipo', bulk[validation.tipo_bulto] || validation.tipo_bulto, bulk[draft.tipo_bulto] || draft.tipo_bulto);
    add('Línea', `Línea ${validation.linea_proceso}`, `Línea ${draft.linea_proceso}`);
    add('Turno', `Turno ${validation.turno}`, `Turno ${draft.turno}`);
    add('Cajas', validation.cantidad_cajas, totalBoxes(draft));
    add('Cliente y marca', [validation.origen?.cliente, validation.origen?.marca].filter(Boolean).join(' · '), [draft.cliente, draft.marca].filter(Boolean).join(' · '));
    const before = (validation.composicion || []).map((line) => ({ originId: line.origen_validacion_id, csg: line.csg, boxes: line.cantidad_cajas, lot: line.lote_materia_prima, process: line.proceso_packing }));
    add('Composición', compositionText(before, index), compositionText(draft.lines, index));
    const article = validation.articulo || index.articleById.get(validation.articulo_validacion_id) || {};
    add('Producto', [article.especie, article.variedad, article.calibre, article.envase].filter(Boolean).join(' · '), [draft.especie, draft.variedad, draft.calibre, draft.envase].filter(Boolean).join(' · '));
    const category = index.categories.find((item) => item.id === draft.categoriaId)?.nombre;
    add('Categoría', validation.categoria, category);
    return changes;
}
