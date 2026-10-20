export function newOperationId() {
    if (globalThis.crypto.randomUUID) return globalThis.crypto.randomUUID();
    const bytes = globalThis.crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 15) | 64; bytes[8] = (bytes[8] & 63) | 128;
    const hex = [...bytes].map((n) => n.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

export function importRows(value) {
    const rows = Array.isArray(value) ? value : value?.marcajes;
    if (!Array.isArray(rows) || !rows.length || rows.length > 1000 || rows.some((r) => !r || typeof r !== 'object' || Array.isArray(r))) {
        throw new Error('El archivo debe contener de 1 a 1.000 marcajes como objetos JSON.');
    }
    return rows;
}

export function sourceConfiguration(values) {
    return Object.fromEntries(['base_url', 'usuario', 'password', 'api_key', 'api_secret'].filter((k) => values[k]?.trim()).map((k) => [k, values[k]]));
}

export function attendanceIssueText(issues) {
    const labels = { sin_marcajes: 'Sin marcajes', entrada_sin_salida: 'Entrada sin salida', salida_sin_entrada: 'Salida sin entrada', entradas_consecutivas: 'Entradas consecutivas', marcajes_simultaneos: 'Marcajes a la misma hora' };
    return issues.map((key) => labels[key] || 'Incidencia por revisar').join(', ');
}
