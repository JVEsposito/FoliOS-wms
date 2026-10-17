import assert from 'node:assert/strict';
import test from 'node:test';

import {
    articlesForOrigins,
    correctionPayload,
    csgOptions,
    draftChanges,
    draftFromValidation,
    draftIssues,
    filterOptions,
    indexCatalog,
    keepCompatibleProduct,
    newLine,
    productChoices,
    totalBoxes,
} from '../../resources/js/shared/validation-correction-logic.js';

const article = (id, variedad, calibre, envase, extra = {}) => ({
    id, especie: 'Cereza', variedad, calibre, envase, codigo_externo: null, variedad_validacion_id: `var-${variedad}`, activo: true, ...extra,
});
const catalog = {
    articulos: [
        article('santina-2j-5', 'Santina', '2J', 'Caja 5 kg', { envase_codigo: 'CJ5KG' }),
        article('santina-2j-10', 'Santina', '2J', 'Cartón 10 kg', { envase_codigo: 'GFLK50M' }),
        article('lapins-2j-5', 'Lapins', '2J', 'Caja 5 kg'),
        article('inactivo', 'Kordia', '3J', 'Caja 5 kg', { activo: false }),
    ],
    origenes: [
        { id: 'o1', cliente: 'EXPORTADORA DEMO', marca: 'MARCA DEMO', csg: '100001', predio: 'Fundo Uno', csg_validacion_id: 'c1', variedad_ids: ['var-Santina', 'var-Lapins'], activo: true },
        { id: 'o2', cliente: 'EXPORTADORA DEMO', marca: 'MARCA DEMO', csg: '100002', predio: 'Fundo Dos', csg_validacion_id: 'c2', variedad_ids: ['var-Santina'], activo: true },
        { id: 'o3', cliente: 'OTRA', marca: 'OTRA', csg: '100003', predio: null, csg_validacion_id: null, variedad_ids: null, activo: true },
    ],
    categorias: [{ id: 'cat1', nombre: 'CAT1', activo: true }, { id: 'cat2', nombre: 'CAT2', activo: true }],
    combinaciones: [
        { articulo_validacion_id: 'santina-2j-5', origen_validacion_id: 'o1' },
        { articulo_validacion_id: 'santina-2j-10', origen_validacion_id: 'o1' },
        { articulo_validacion_id: 'lapins-2j-5', origen_validacion_id: 'o1' },
        { articulo_validacion_id: 'santina-2j-10', origen_validacion_id: 'o2' },
        // Lapins está combinado en o2, pero su CSG no tiene la variedad autorizada.
        { articulo_validacion_id: 'lapins-2j-5', origen_validacion_id: 'o2' },
        { articulo_validacion_id: 'santina-2j-5', origen_validacion_id: 'o3' },
    ],
};
const index = indexCatalog(catalog);
const validation = {
    id: 'v1', numero_folio: '0000000005', tipo_bulto: 'saldo', cantidad_cajas: 40, linea_proceso: 1, turno: 'A',
    articulo_validacion_id: 'santina-2j-5', categoria_validacion_id: 'cat1', categoria: 'CAT1',
    articulo: { especie: 'Cereza', variedad: 'Santina', calibre: '2J', envase: 'Caja 5 kg' },
    origen: { cliente: 'EXPORTADORA DEMO', marca: 'MARCA DEMO', csg: '100001' },
    composicion: [{ origen_validacion_id: 'o1', csg: '100001', cantidad_cajas: 40, lote_materia_prima: null, proceso_packing: null }],
};

test('busca por cada palabra sin tildes ni mayúsculas, también en el detalle', () => {
    const options = [{ value: 'a', label: 'Cartón 10 kg', hint: 'GFLK50M' }, { value: 'b', label: 'Caja 5 kg', hint: 'CJ5KG' }];
    assert.deepEqual(filterOptions(options, 'carton 10').map((option) => option.value), ['a']);
    assert.deepEqual(filterOptions(options, 'gflk').map((option) => option.value), ['a']);
    assert.deepEqual(filterOptions(options, '').map((option) => option.value), ['a', 'b']);
});

test('los productos son los habilitados en todos los CSG y con la variedad autorizada en cada uno', () => {
    assert.deepEqual(articlesForOrigins(index, ['o1']).map((item) => item.id), ['santina-2j-5', 'santina-2j-10', 'lapins-2j-5']);
    assert.deepEqual(articlesForOrigins(index, ['o1', 'o2']).map((item) => item.id), ['santina-2j-10']);
    // Un CSG sin variedades normalizadas solo se filtra por combinaciones.
    assert.deepEqual(articlesForOrigins(index, ['o3']).map((item) => item.id), ['santina-2j-5']);
    assert.deepEqual(articlesForOrigins(index, []), []);
});

test('los CSG se ofrecen solo para el cliente y la marca, con su predio para buscar', () => {
    assert.deepEqual(csgOptions(index, 'EXPORTADORA DEMO', 'MARCA DEMO'), [
        { value: 'o1', label: '100001', hint: 'Fundo Uno', search: '' },
        { value: 'o2', label: '100002', hint: 'Fundo Dos', search: '' },
    ]);
});

test('el borrador parte de lo validado y el producto se elige paso a paso', () => {
    const draft = draftFromValidation(validation, index);
    assert.equal(draft.cliente, 'EXPORTADORA DEMO');
    assert.equal(draft.marca, 'MARCA DEMO');
    assert.deepEqual([draft.especie, draft.variedad, draft.calibre, draft.envase, draft.categoriaId], ['Cereza', 'Santina', '2J', 'Caja 5 kg', 'cat1']);
    assert.equal(totalBoxes(draft), 40);

    const choices = productChoices(index, draft);
    assert.deepEqual(choices.varieties.map((option) => option.value), ['Lapins', 'Santina']);
    assert.deepEqual(choices.packages, [
        { value: 'Caja 5 kg', label: 'Caja 5 kg', hint: 'CJ5KG' },
        { value: 'Cartón 10 kg', label: 'Cartón 10 kg', hint: 'GFLK50M' },
    ]);
    assert.equal(choices.article.id, 'santina-2j-5');
});

test('al agregar un CSG, el producto que deja de estar habilitado se vacía desde el paso que no calza', () => {
    const draft = draftFromValidation(validation, index);
    draft.lines = [...draft.lines, newLine({ originId: 'o2', boxes: '20' })];
    const next = keepCompatibleProduct(index, draft);
    assert.deepEqual([next.especie, next.variedad, next.calibre, next.envase], ['Cereza', 'Santina', '2J', '']);
    next.envase = 'Cartón 10 kg';
    assert.equal(productChoices(index, next).article.id, 'santina-2j-10');
});

test('avisa lo que falta antes de guardar, en el orden del formulario', () => {
    const draft = draftFromValidation(validation, index);
    assert.deepEqual(draftIssues(draft, index), ['Explica el motivo de la corrección.']);
    draft.motivo = 'El pallet trae dos CSG.';
    assert.deepEqual(draftIssues(draft, index), []);
    assert.deepEqual(draftIssues(draft, index, { requireLotAndProcess: true }), [
        'Ingresa el lote de materia prima.',
        'Ingresa el proceso de packing.',
    ]);

    const repeated = { ...draft, lines: [newLine({ originId: 'o1', boxes: '20', lot: 'l-1' }), newLine({ originId: 'o1', boxes: '20', lot: 'L-1 ' })] };
    assert.ok(draftIssues(repeated, index).includes('No repitas la misma combinación de CSG, lote y proceso.'));

    const foreign = { ...draft, lines: [newLine({ originId: 'o3', boxes: '0' })] };
    assert.deepEqual(draftIssues(foreign, index).slice(0, 2), ['El CSG no es de EXPORTADORA DEMO · MARCA DEMO.', 'Ingresa las cajas.']);

    const noCommon = keepCompatibleProduct(index, { ...draft, lines: [newLine({ originId: 'o2', boxes: '10' }), newLine({ originId: 'o1', boxes: '10' })], variedad: 'Lapins' });
    assert.equal(noCommon.variedad, '');
});

test('el envío lleva la composición completa y el total es la suma de las líneas', () => {
    const draft = draftFromValidation(validation, index);
    draft.lines = [newLine({ originId: 'o1', boxes: '25', lot: ' L-100 ', process: '' }), newLine({ originId: 'o2', boxes: '15', lot: 'L-200', process: 'P-2' })];
    draft.envase = 'Cartón 10 kg';
    draft.motivo = '  Dos CSG.  ';
    const payload = correctionPayload(draft, productChoices(index, draft).article, 'op-1');
    assert.deepEqual(payload, {
        operacion_id: 'op-1',
        tipo_bulto: 'saldo',
        cantidad_cajas: 40,
        linea_proceso: 1,
        turno: 'A',
        articulo_validacion_id: 'santina-2j-10',
        categoria_validacion_id: 'cat1',
        composicion: [
            { origen_validacion_id: 'o1', cantidad_cajas: 25, lote_materia_prima: 'L-100', proceso_packing: null },
            { origen_validacion_id: 'o2', cantidad_cajas: 15, lote_materia_prima: 'L-200', proceso_packing: 'P-2' },
        ],
        motivo_correccion: 'Dos CSG.',
    });
});

test('la revisión muestra solo lo que cambia frente a lo validado', () => {
    const draft = draftFromValidation(validation, index);
    assert.deepEqual(draftChanges(validation, draft, index), []);
    draft.lines = [newLine({ originId: 'o1', boxes: '25' }), newLine({ originId: 'o2', boxes: '13' })];
    draft.envase = 'Cartón 10 kg';
    draft.categoriaId = 'cat2';
    assert.deepEqual(draftChanges(validation, draft, index), [
        { label: 'Cajas', before: '40', after: '38' },
        { label: 'Composición', before: '100001: 40', after: '100001: 25 · 100002: 13' },
        { label: 'Producto', before: 'Cereza · Santina · 2J · Caja 5 kg', after: 'Cereza · Santina · 2J · Cartón 10 kg' },
        { label: 'Categoría', before: 'CAT1', after: 'CAT2' },
    ]);
});
