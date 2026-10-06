// Reintentar una descarga interrumpida conserva la misma operación y su auditoría.
export function createLabelOperation(uuid) {
    let pending = null;
    return {
        prepare(data) {
            const selection = data.folios ? 'folios' : 'validaciones';
            const payload = { ...data, [selection]: [...data[selection]].sort((a, b) => a.id.localeCompare(b.id)) };
            const signature = JSON.stringify(payload);
            if (pending?.signature !== signature) pending = { signature, id: uuid() };
            return { ...payload, operacion_id: pending.id };
        },
        clear() { pending = null; },
    };
}
