import assert from 'node:assert/strict';
import { performance } from 'node:perf_hooks';
import test from 'node:test';

import { articlesForOrigins, createOriginArticleSelector, indexValidationCatalog } from '../src/domain/validationCatalogIndex.ts';
import { resolveAppVariant } from '../src/config/resolveAppVariant.ts';

test('pda y pda-pruebas conservan la variante PDA del binario', () => {
  assert.equal(resolveAppVariant('pda', 'tablet'), 'pda');
  assert.equal(resolveAppVariant('pda-pruebas', 'tablet'), 'pda');
  assert.equal(resolveAppVariant('production', 'pda'), 'tablet');
  assert.equal(resolveAppVariant(null, 'pda'), 'pda');
});

test('variedades de CSG con 2.000 artículos y 20.000 combinaciones en menos de 50 ms', () => {
  const articles = Array.from({ length: 2000 }, (_, n) => ({
    id: `art-${n}`, especie: 'Cereza', variedad: `Variedad-${n % 50}`,
    variedad_validacion_id: `var-${n % 50}`, activo: true,
  }));
  const origins = Array.from({ length: 10 }, (_, n) => ({ id: `csg-${n}`, activo: true, variedad_ids: null }));
  const combinations = origins.flatMap((origin) => articles.map((article) => ({
    origen_validacion_id: origin.id, articulo_validacion_id: article.id,
  })));
  const index = indexValidationCatalog({ articulos: articles, origenes: origins, combinaciones: combinations });
  const start = performance.now();
  const varieties = new Set(articlesForOrigins(index, ['csg-4']).map((article) => article.variedad));
  const elapsed = performance.now() - start;
  assert.equal(varieties.size, 50);
  assert.ok(elapsed < 50, `Filtrar variedades demoró ${elapsed.toFixed(2)} ms`);
});

test('cajas, lote y proceso mantienen los IDs de origen y reutilizan el cálculo', () => {
  const index = indexValidationCatalog({
    articulos: [{ id: 'a', activo: true, variedad_validacion_id: 'v' }],
    origenes: [{ id: 'csg', activo: true, variedad_ids: ['v'] }],
    combinaciones: [{ origen_validacion_id: 'csg', articulo_validacion_id: 'a' }],
  });
  let calculations = 0;
  const select = createOriginArticleSelector(index, (catalog, ids) => {
    calculations++;
    return articlesForOrigins(catalog, ids);
  });
  const first = select(['csg']);
  for (const draft of [
    { originId: 'csg', boxes: '30', lot: '', process: '' },
    { originId: 'csg', boxes: '31', lot: 'LOTE-1', process: '' },
    { originId: 'csg', boxes: '31', lot: 'LOTE-1', process: 'PROC-1' },
  ]) assert.strictEqual(select([draft.originId]), first);
  assert.equal(calculations, 1);
  assert.deepEqual(select(['otro']), []);
  assert.equal(calculations, 2);
});
