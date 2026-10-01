import type { ValidationArticle, ValidationCatalog, ValidationOrigin } from './validation';

export type ValidationCatalogIndex = {
  articleById: Map<string, ValidationArticle>;
  originById: Map<string, ValidationOrigin>;
  articlesByOrigin: Map<string, Set<string>>;
  activeArticles: ValidationArticle[];
};

export function indexValidationCatalog(catalog: ValidationCatalog): ValidationCatalogIndex {
  const activeArticles = catalog.articulos.filter((article) => article.activo);
  const articleById = new Map(activeArticles.map((article) => [article.id, article]));
  const originById = new Map(catalog.origenes.filter((origin) => origin.activo).map((origin) => [origin.id, origin]));
  const articlesByOrigin = new Map<string, Set<string>>();
  for (const combination of catalog.combinaciones) {
    if (!originById.has(combination.origen_validacion_id) || !articleById.has(combination.articulo_validacion_id)) continue;
    let articles = articlesByOrigin.get(combination.origen_validacion_id);
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
