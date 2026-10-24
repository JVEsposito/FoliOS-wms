export function loadReceptionDependencies(progress, truckAtDock = false) {
    const dependencies = progress?.dependencias || [];
    if (!dependencies.length) return '';
    const origins = new Map();
    for (const item of dependencies) {
        const origin = item.origen || 'Recepción pendiente';
        origins.set(origin, (origins.get(origin) || 0) + 1);
    }
    const count = dependencies.length;
    const detail = [...origins].map(([origin, amount]) => `${origin} (${amount})`).join(' · ');
    const people = [...new Set(dependencies.map((item) => item.responsable).filter(Boolean))];
    const delivery = truckAtDock ? 'La recepción entrega directamente en andén.' : 'La recepción los entrega directamente al acopio de esta carga.';
    return `${count} ${count === 1 ? 'pallet pendiente' : 'pallets pendientes'} de recepción: ${detail}.${people.length ? ` Reciben: ${people.join(', ')}.` : ''} ${delivery}`;
}
