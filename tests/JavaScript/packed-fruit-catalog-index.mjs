import assert from 'node:assert/strict';
import test from 'node:test';
import { indexValidationCatalog, createOriginArticleSelector } from '../../resources/js/shared/packed-fruit-catalog-index.js';

test('Oficina respeta variedad del CSG aun con combinación anticuada y reutiliza la selección', () => {
    const index = indexValidationCatalog({
        articulos: [
            { id: 'uva', activo: true, variedad_validacion_id: 'v1' },
            { id: 'otra', activo: true, variedad_validacion_id: 'v2' },
            { id: 'inactiva', activo: false, variedad_validacion_id: 'v1' },
        ],
        origenes: [{ id: 'csg', activo: true, variedad_ids: ['v1'] }, { id: 'inactivo', activo: false, variedad_ids: ['v1'] }],
        combinaciones: ['uva', 'otra', 'inactiva'].map((id) => ({ articulo_validacion_id: id, origen_validacion_id: 'csg' })),
    });
    const select = createOriginArticleSelector(index);
    const first = select(['csg']);
    assert.deepEqual(first.map((a) => a.id), ['uva']);
    assert.strictEqual(select(['csg']), first);
    assert.deepEqual(select(['inactivo']), []);
    assert.deepEqual(select([]), []);
});
