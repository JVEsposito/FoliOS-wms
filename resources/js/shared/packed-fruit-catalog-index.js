// Índice comercial de #383 para Oficina. No depende de la configuración de Expo.
export function indexValidationCatalog(catalog) {
    const activeArticles = catalog.articulos.filter((article) => article.activo);
    const articleById = new Map(activeArticles.map((article) => [article.id, article]));
    const originById = new Map(catalog.origenes.filter((origin) => origin.activo).map((origin) => [origin.id, origin]));
    const articlesByOrigin = new Map();
    for (const combination of catalog.combinaciones) {
        if (!originById.has(combination.origen_validacion_id) || !articleById.has(combination.articulo_validacion_id)) continue;
        if (!articlesByOrigin.has(combination.origen_validacion_id)) articlesByOrigin.set(combination.origen_validacion_id, new Set());
        articlesByOrigin.get(combination.origen_validacion_id).add(combination.articulo_validacion_id);
    }
    return { articleById, originById, articlesByOrigin, activeArticles };
}
export function articlesForOrigins(index, originIds) {
    if (!originIds.length || originIds.some((id) => !index.originById.has(id))) return [];
    const allowed = index.articlesByOrigin.get(originIds[0]);
    if (!allowed) return [];
    return [...allowed].flatMap((id) => {
        if (!originIds.every((originId) => index.articlesByOrigin.get(originId)?.has(id))) return [];
        const article = index.articleById.get(id);
        return article && originIds.every((originId) => {
            const varieties = index.originById.get(originId)?.variedad_ids;
            return varieties === null || (article.variedad_validacion_id !== null && varieties?.includes(article.variedad_validacion_id) === true);
        }) ? [article] : [];
    });
}
export function createOriginArticleSelector(index) {
    let previousKey = null, previousArticles = [];
    return (originIds) => {
        const key = originIds.join('|');
        if (key !== previousKey) { previousArticles = articlesForOrigins(index, originIds); previousKey = key; }
        return previousArticles;
    };
}
