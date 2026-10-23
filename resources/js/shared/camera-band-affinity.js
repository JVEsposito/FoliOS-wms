export function bandAffinityText(band) {
    const affinity = band?.afinidad;
    if (!affinity) return 'Sin afinidad activa';
    const parts = [];
    const label = (profile) => [profile.cliente || 'Cliente sin informar', profile.marca || 'Marca sin informar', profile.formato || 'Envase sin informar'].join(' · ');
    if (affinity.perfiles?.length) {
        const profiles = affinity.perfiles.map((profile) => `${label(profile)} (${profile.pallets} ${profile.pallets === 1 ? 'presente' : 'presentes'})`);
        parts.push(`${profiles.length > 1 ? 'MIX: ' : ''}${profiles.join(' / ')}`);
    }
    if (affinity.entrantes?.perfiles?.length) {
        parts.push(`Entradas reservadas: ${affinity.entrantes.perfiles.map((profile) => `${label(profile)} (${profile.pallets})`).join(' / ')}`);
    }
    if (parts.length) return parts.join(' | ');
    if (!affinity.activa) return 'Sin afinidad activa';
    const values = [affinity.cliente, affinity.marca, affinity.formato]
        .map((item) => typeof item === 'string' ? item : item?.valor)
        .filter((value) => typeof value === 'string' && value.trim());
    if (values.length) return values.join(' · ');
    if (affinity.perfiles_diferentes) return `${affinity.perfiles_diferentes} perfiles distintos`;
    return 'Afinidad activa';
}
