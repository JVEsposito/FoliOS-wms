import assert from 'node:assert/strict';
import test from 'node:test';

import { catalogCacheMatches, loadIndependentSections } from '../../resources/js/office-validation-loading.js';

test('el catálogo se reutiliza en la temporada activa aun sin parámetro explícito', () => {
    const season = { id: 'temporada-1', version_catalogo: 4 };
    const cache = { seasonId: season.id, version: 4 };
    assert.equal(catalogCacheMatches(cache, season, null), true);
    assert.equal(catalogCacheMatches(cache, season, season.id), true);
    assert.equal(catalogCacheMatches(cache, { ...season, version_catalogo: 5 }, null), false);
    assert.equal(catalogCacheMatches(cache, { ...season, id: 'temporada-2' }, null), false);
    assert.equal(catalogCacheMatches(cache, season, 'temporada-2'), false);
});

test('una sección fallida no impide mostrar las otras dos', async () => {
    const loaded = [];
    const errors = [];
    const results = await loadIndependentSections([
        async () => { loaded.push('opciones'); },
        async () => { throw new Error('Resumen temporalmente no disponible'); },
        async () => { loaded.push('historial'); },
    ], (error) => errors.push(error.message));
    assert.deepEqual(loaded.sort(), ['historial', 'opciones']);
    assert.deepEqual(results.map((result) => result.status), ['fulfilled', 'rejected', 'fulfilled']);
    assert.deepEqual(errors, ['Resumen temporalmente no disponible']);
});
