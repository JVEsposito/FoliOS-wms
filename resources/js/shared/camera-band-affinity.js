export function bandAffinityText(band) {
    const affinity = band?.afinidad;
    if (!affinity?.activa) return 'Sin afinidad activa';
    const values = [affinity.cliente, affinity.marca, affinity.formato]
        .map((item) => typeof item === 'string' ? item : item?.valor)
        .filter((value) => typeof value === 'string' && value.trim());
    if (values.length) return values.join(' · ');
    if (affinity.perfiles_diferentes) return `${affinity.perfiles_diferentes} perfiles distintos`;
    return 'Afinidad activa';
}
