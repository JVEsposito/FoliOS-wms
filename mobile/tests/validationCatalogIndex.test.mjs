import assert from 'node:assert/strict';
import { performance } from 'node:perf_hooks';
import test from 'node:test';

import { articlesForOrigins, createOriginArticleSelector, indexValidationCatalog, productCompatibleWithOrigins } from '../src/domain/validationCatalogIndex.ts';
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
  // El catálogo ya se indexó al cargar. Medimos cinco selecciones y usamos la
  // mediana para evitar ruido del runner sin esconder una regresión sistemática.
  articlesForOrigins(index, ['csg-4']);
  const samples = [];
  let varieties;
  for (let attempt = 0; attempt < 5; attempt++) {
    const start = performance.now();
    varieties = new Set(articlesForOrigins(index, ['csg-4']).map((article) => article.variedad));
    samples.push(performance.now() - start);
  }
  const elapsed = samples.sort((a, b) => a - b)[2];
  assert.equal(varieties.size, 50);
  assert.ok(elapsed < 50, `Mediana del filtro de variedades: ${elapsed.toFixed(2)} ms`);
});

test('segundo CSG compatible conserva producto; origen vacío no lo borra; incompatibilidad sí', () => {
  const index = indexValidationCatalog({
    articulos: [
      { id: 'santina', especie: 'Cereza', variedad: 'Santina', calibre: '2J', envase: '5 kg', variedad_validacion_id: 'v1', activo: true },
      { id: 'lapins', especie: 'Cereza', variedad: 'Lapins', calibre: '2J', envase: '5 kg', variedad_validacion_id: 'v2', activo: true },
    ],
    origenes: [
      { id: 'csg-1', activo: true, variedad_ids: ['v1', 'v2'] },
      { id: 'csg-2', activo: true, variedad_ids: ['v1'] },
      { id: 'csg-3', activo: true, variedad_ids: ['v2'] },
    ],
    combinaciones: [
      { origen_validacion_id: 'csg-1', articulo_validacion_id: 'santina' },
      { origen_validacion_id: 'csg-1', articulo_validacion_id: 'lapins' },
      { origen_validacion_id: 'csg-2', articulo_validacion_id: 'santina' },
      { origen_validacion_id: 'csg-3', articulo_validacion_id: 'lapins' },
    ],
  });
  const product = { especie: 'Cereza', variedad: 'Santina', calibre: '2J', envase: '5 kg' };
  assert.equal(productCompatibleWithOrigins(index, ['csg-1'], product), true);
  assert.equal(productCompatibleWithOrigins(index, ['csg-1', ''], product), true);
  assert.equal(productCompatibleWithOrigins(index, ['csg-1', 'csg-2'], product), true);
  assert.equal(productCompatibleWithOrigins(index, ['csg-1', 'csg-3'], product), false);
  assert.equal(productCompatibleWithOrigins(index, ['csg-3'], { ...product, variedad: '' }), true);
  assert.equal(productCompatibleWithOrigins(index, [], product), false);
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
