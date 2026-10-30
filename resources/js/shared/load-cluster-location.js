/** Describe las bandas reales; un rango no debe incluir bandas de otras cargas. */
export function loadClusterLocation(cluster, preferName = false) {
    if (!cluster) return 'Sin grupo principal';
    const camera = preferName ? cluster.camara?.nombre || cluster.camara?.codigo : cluster.camara?.codigo || cluster.camara?.nombre;
    const levels = Array.isArray(cluster.niveles) && cluster.niveles.length ? cluster.niveles : [cluster.nivel];
    const bands = Array.isArray(cluster.bandas) && cluster.bandas.length ? cluster.bandas : null;
    const bandLabel = bands
        ? bands.every((b, i) => i === 0 || b === bands[i - 1] + 1) && bands.length > 1
            ? `${bands[0]}–${bands.at(-1)}` : bands.join(', ')
        : `${cluster.banda_desde}–${cluster.banda_hasta}`;
    return `${camera || 'Cámara'} · ${levels.length > 1 ? 'niveles' : 'nivel'} ${levels.join(', ')} · bandas ${bandLabel}`;
}
