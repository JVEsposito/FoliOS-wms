const STATES = [
    { key: 'disponibles_despacho', label: 'Disponibles', tone: 'success' },
    { key: 'comprometidos_carga', label: 'Comprometidos', tone: 'info' },
    { key: 'pendientes_prefrio', label: 'Pendientes prefrío', tone: 'warning' },
    { key: 'pendientes_ubicacion', label: 'Sin ubicación', tone: 'quiet' },
    { key: 'bloqueados', label: 'Bloqueados', tone: 'danger' },
    { key: 'otros', label: 'Otros', tone: 'quiet' },
];

// Reserva espacio visible a los segmentos pequeños sin perder el total de 100 %.
function visibleWidths(values) {
    const sum = values.reduce((total, value) => total + value, 0);
    if (!sum) return values.map(() => 0);

    const minimum = 2;
    const small = values.map((value) => value > 0 && (value / sum) * 100 < minimum);
    const reserved = small.filter(Boolean).length * minimum;
    const largeSum = values.reduce((total, value, index) => total + (small[index] ? 0 : value), 0);
    const widths = values.map((value, index) => {
        if (!value) return 0;
        return small[index] ? minimum : (value / largeSum) * (100 - reserved);
    });
    const last = widths.findLastIndex((width) => width > 0);
    widths[last] += 100 - widths.reduce((total, width) => total + width, 0);

    return widths;
}

export function productStateSegments(products) {
    const total = Math.max(0, Number(products.total_activos) || 0);
    const values = STATES.map(({ key }) => Math.max(0, Number(products[key]) || 0));
    const widths = visibleWidths(values);
    const segments = STATES.map((state, index) => ({
        ...state,
        count: values[index],
        percent: total ? Math.round((values[index] / total) * 1000) / 10 : 0,
        width: widths[index],
    }));

    return {
        empty: total === 0,
        segments,
        ariaLabel: total === 0
            ? 'Sin folios PT activos'
            : `Estados de ${total} folios PT: ${segments.map(({ label, count }) => `${label} ${count}`).join(', ')}`,
    };
}
