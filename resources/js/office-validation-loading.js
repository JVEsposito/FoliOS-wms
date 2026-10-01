export function catalogCacheMatches(cache, currentSeason, requestedSeasonId) {
    return Boolean(currentSeason
        && (!requestedSeasonId || requestedSeasonId === currentSeason.id)
        && cache?.seasonId === currentSeason.id
        && cache?.version === currentSeason.version_catalogo);
}

export async function loadIndependentSections(loaders, onError) {
    const results = await Promise.allSettled(loaders.map((load) => load()));
    for (const result of results) if (result.status === 'rejected') onError(result.reason);
    return results;
}
