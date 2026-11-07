import type { ValidationArticle, ValidationCatalog, ValidationCombination, ValidationOrigin } from './validation';

export function catalogCombinationCount(catalog: ValidationCatalog): number {
  return catalog.compatibilidad?.indices.length ?? catalog.combinaciones.length;
}

export function validateCatalogCompatibility(catalog: ValidationCatalog): void {
  const data = catalog.compatibilidad;
  if (!data) return;
  const uint32 = (value: unknown) => ArrayBuffer.isView(value) && Object.prototype.toString.call(value) === '[object Uint32Array]';
  if (data.formato !== 'csr-v1' || catalog.combinaciones.length !== 0 || !uint32(data.offsets) || !uint32(data.indices)
    || data.offsets.length !== catalog.origenes.length + 1 || data.offsets[0] !== 0
    || data.offsets[data.offsets.length - 1] !== data.indices.length) throw new Error('Índice de compatibilidad de catálogo inválido.');
  for (let origin = 0; origin < catalog.origenes.length; origin++) {
    const start = data.offsets[origin]; const end = data.offsets[origin + 1];
    if (end < start || end > data.indices.length) throw new Error('Índice de compatibilidad de catálogo incompleto.');
    let previous = -1;
    for (let i = start; i < end; i++) {
      const value = data.indices[i];
      if (value >= catalog.articulos.length || value < previous) throw new Error('La compatibilidad contiene artículos inválidos o desordenados.');
      previous = value;
    }
  }
}

/** Consume lotes pequeños, conservando solo posiciones numéricas de artículo por origen. */
export function createCatalogCompatibilityBuilder(catalog: Pick<ValidationCatalog, 'articulos' | 'origenes'>) {
  const articles = new Map(catalog.articulos.map((row, index) => [row.id, index]));
  const origins = new Map(catalog.origenes.map((row, index) => [row.id, index]));
  const buckets: number[][] = catalog.origenes.map(() => []);
  let count = 0;
  return {
    append(rows: Pick<ValidationCombination, 'articulo_validacion_id' | 'origen_validacion_id'>[]) {
      for (const row of rows) {
        const article = articles.get(row.articulo_validacion_id); const origin = origins.get(row.origen_validacion_id);
        if (article === undefined || origin === undefined) throw new Error('El catálogo contiene combinaciones sin artículo u origen.');
        buckets[origin].push(article);
        if (++count > 0xffffffff) throw new Error('El catálogo supera la capacidad de su índice de compatibilidad.');
      }
    },
    finish(): NonNullable<ValidationCatalog['compatibilidad']> {
      const offsets = new Uint32Array(buckets.length + 1); const indices = new Uint32Array(count);
      let offset = 0;
      for (let i = 0; i < buckets.length; i++) {
        buckets[i].sort((a, b) => a - b);
        offsets[i] = offset; indices.set(buckets[i], offset); offset += buckets[i].length; buckets[i] = [];
      }
      offsets[buckets.length] = offset;
      return { formato: 'csr-v1', offsets, indices };
    },
  };
}

export function compactCatalogCompatibility(catalog: ValidationCatalog): ValidationCatalog {
  if (catalog.compatibilidad) return catalog;
  const builder = createCatalogCompatibilityBuilder(catalog);
  builder.append(catalog.combinaciones);
  return { ...catalog, combinaciones: [], compatibilidad: builder.finish() };
}

type CompatibleArticles = Iterable<string> & { has(id: string): boolean };

export type ValidationCatalogIndex = {
  articleById: Map<string, ValidationArticle>;
  originById: Map<string, ValidationOrigin>;
  articlesByOrigin: Map<string, CompatibleArticles>;
  activeArticles: ValidationArticle[];
};

export function indexValidationCatalog(catalog: ValidationCatalog): ValidationCatalogIndex {
  const activeArticles = catalog.articulos.filter((article) => article.activo);
  const articleById = new Map(activeArticles.map((article) => [article.id, article]));
  const originById = new Map(catalog.origenes.filter((origin) => origin.activo).map((origin) => [origin.id, origin]));
  const articlesByOrigin = new Map<string, CompatibleArticles>();
  if (catalog.compatibilidad) {
    validateCatalogCompatibility(catalog);
    const positions = new Map(catalog.articulos.map((article, index) => [article.id, index]));
    const { offsets, indices } = catalog.compatibilidad;
    catalog.origenes.forEach((origin, originIndex) => {
      if (!origin.activo) return;
      const start = offsets[originIndex]; const end = offsets[originIndex + 1];
      articlesByOrigin.set(origin.id, {
        has(id) {
          const position = positions.get(id);
          if (position === undefined || !articleById.has(id)) return false;
          let lo = start; let hi = end;
          while (lo < hi) { const mid = lo + ((hi - lo) >>> 1); if (indices[mid] < position) lo = mid + 1; else hi = mid; }
          return lo < end && indices[lo] === position;
        },
        *[Symbol.iterator]() {
          let previous = -1;
          for (let i = start; i < end; i++) {
            const position = indices[i];
            if (position !== previous && catalog.articulos[position].activo) yield catalog.articulos[position].id;
            previous = position;
          }
        },
      });
    });
    return { articleById, originById, articlesByOrigin, activeArticles };
  }
  for (const combination of catalog.combinaciones) {
    if (!originById.has(combination.origen_validacion_id) || !articleById.has(combination.articulo_validacion_id)) continue;
    let articles = articlesByOrigin.get(combination.origen_validacion_id) as Set<string> | undefined;
    if (!articles) {
      articles = new Set<string>();
      articlesByOrigin.set(combination.origen_validacion_id, articles);
    }
    articles.add(combination.articulo_validacion_id);
  }
  return { articleById, originById, articlesByOrigin, activeArticles };
}

export function articlesForOrigins(index: ValidationCatalogIndex, originIds: readonly string[]): ValidationArticle[] {
  if (!originIds.length || originIds.some((id) => !index.originById.has(id))) return [];
  const allowed = index.articlesByOrigin.get(originIds[0]);
  if (!allowed) return [];
  const articles: ValidationArticle[] = [];
  for (const id of allowed) {
    if (!originIds.every((originId) => index.articlesByOrigin.get(originId)?.has(id))) continue;
    const article = index.articleById.get(id);
    if (article && originIds.every((originId) => {
      const varieties = index.originById.get(originId)?.variedad_ids;
      return varieties === null || (article.variedad_validacion_id !== null && varieties?.includes(article.variedad_validacion_id) === true);
    })) articles.push(article);
  }
  return articles;
}

export function productCompatibleWithOrigins(
  index: ValidationCatalogIndex,
  originIds: readonly string[],
  selection: Pick<ValidationArticle, 'especie' | 'variedad' | 'calibre' | 'envase'>,
): boolean {
  const fields = ['especie', 'variedad', 'calibre', 'envase'] as const;
  if (fields.every((field) => !selection[field])) return true;
  return articlesForOrigins(index, originIds.filter(Boolean)).some((article) => fields.every(
    (field) => !selection[field] || article[field] === selection[field],
  ));
}

export function createOriginArticleSelector(
  index: ValidationCatalogIndex,
  calculate = articlesForOrigins,
): (originIds: readonly string[]) => ValidationArticle[] {
  let previousKey: string | null = null;
  let previousArticles: ValidationArticle[] = [];
  return (originIds) => {
    const key = originIds.join('|');
    if (key !== previousKey) {
      previousArticles = calculate(index, originIds);
      previousKey = key;
    }
    return previousArticles;
  };
}
