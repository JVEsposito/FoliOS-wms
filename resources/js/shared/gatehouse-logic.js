export const GATEHOUSE_MOTIVES = { carga: 'Con carga', vacio: 'Vacío', servicio: 'Servicio' };

export function escapeGatehouse(value) {
    return String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
}

export function gatehouseDate(value, timeOnly = false) {
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '—';
    return new Intl.DateTimeFormat('es-CL', { timeZone: 'America/Santiago', ...(timeOnly ? {} : { dateStyle: 'short' }), timeStyle: 'short' }).format(date);
}

export function gatehouseDay(date = new Date()) {
    const parts = new Intl.DateTimeFormat('en-CA', { timeZone: 'America/Santiago', year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(date);
    const part = (key) => parts.find((item) => item.type === key)?.value;
    return `${part('year')}-${part('month')}-${part('day')}`;
}

// Disponible también en HTTP de la red local, donde randomUUID puede no existir.
export function gatehouseOperationId(cryptoApi = globalThis.crypto) {
    if (typeof cryptoApi.randomUUID === 'function') return cryptoApi.randomUUID();
    const bytes = cryptoApi.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = [...bytes].map((value) => value.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

export function gatehouseGuides(values) {
    return values.map((value) => String(value).trim()).filter(Boolean);
}

export function gatehouseStatus(entry) {
    if (entry.anulado_at) return 'Anulado';
    return entry.revisado_at ? 'Revisado por Romana' : 'Pendiente de Romana';
}

export function gatehouseEventLines(event) {
    const data = event.datos || {};
    if (event.accion === 'registrado') return [`${data.registro?.patente || ''} · ${data.registro?.cliente_nombre || ''} · Guías: ${(data.registro?.guias || []).join(', ') || 'sin guía'}`];
    const labels = { cliente_nombre: 'Cliente / servicio', motivo: 'Motivo', patente: 'Patente', patente_carro: 'Carro', tipo_camion: 'Tipo de camión', nombre_conductor: 'Conductor', guias: 'Guías', cantidad: 'Cantidad', unidad: 'Unidad', observacion: 'Observación' };
    const text = (value) => Array.isArray(value) ? value.join(', ') : String(value ?? 'Sin informar');
    return Object.entries(labels).filter(([key]) => JSON.stringify(data.antes?.[key]) !== JSON.stringify(data.despues?.[key]))
        .map(([key, label]) => `${label}: ${text(data.antes?.[key])} → ${text(data.despues?.[key])}`);
}
