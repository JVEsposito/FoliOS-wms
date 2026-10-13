export function validationOriginLabel(catalog = {}) {
    const codes = [...new Set((catalog.composicion || [])
        .map((line) => String(line.csg ?? '').trim()).filter(Boolean))];
    if (codes.length < 2) return `CSG ${catalog.origen?.csg || codes[0] || '—'}`;
    const labels = codes.map((code) => `CSG ${code}`);
    return `${labels.slice(0, -1).join(', ')} y ${labels.at(-1)}`;
}

export function validationJourneyLabel(item, timeZone = 'America/Santiago') {
    const date = new Date(item.generado_dispositivo_at);
    const valid = Boolean(item.generado_dispositivo_at) && !Number.isNaN(date.getTime());
    if (!item.linea_proceso || !item.turno) {
        const formatted = valid ? new Intl.DateTimeFormat('es-CL', {
            dateStyle: 'short', timeStyle: 'short', hourCycle: 'h23', timeZone,
        }).format(date) : 'Sin fecha';
        return `Sin jornada histórica · ${formatted}`;
    }
    const time = valid ? new Intl.DateTimeFormat('es-CL', {
        hour: '2-digit', minute: '2-digit', hourCycle: 'h23', timeZone,
    }).format(date) : 'Sin fecha';
    return `Línea ${item.linea_proceso}, turno ${item.turno}, ${time}${item.dispositivo?.codigo ? ` · ${item.dispositivo.codigo}` : ''}`;
}

export function validationDayContext(fecha, timeZone = 'America/Santiago', now = new Date()) {
    const parts = Object.fromEntries(new Intl.DateTimeFormat('en', {
        timeZone, year: 'numeric', month: '2-digit', day: '2-digit',
    }).formatToParts(now).map(({ type, value }) => [type, value]));
    const today = `${parts.year}-${parts.month}-${parts.day}`;
    const label = !fecha || fecha === today ? 'hoy' : fecha.split('-').reverse().join('-');
    return { label: label === 'hoy' ? 'hoy' : `el ${label}`, title: `Por línea, ${label}` };
}
