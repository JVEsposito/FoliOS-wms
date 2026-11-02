/** La cantidad editada es el total de la orden, no el alcance del cambio de embalaje. */
export function processOrderChanges(originals = [], draft = []) {
    const proposed = new Map(draft.map((row) => [row.lote_materia_prima_id, row]));
    const reductions = [];
    const packaging = [];
    for (const before of originals) {
        const after = proposed.get(before.lote_materia_prima_id);
        const total = after ? Number(after.bins_programados) : 0;
        if (!Number.isInteger(total) || total < 0) continue;
        const delivered = Number(before.bins_entregados || 0);
        const released = Math.max(0, Number(before.bins_programados) - total);
        if (released) reductions.push({
            lot: before.numero_lote, before: Number(before.bins_programados), after: total,
            delivered, pending: Math.max(0, total - delivered), released,
        });
        if (after && (String(before.embalaje || '').trim() !== String(after.embalaje || '').trim()
            || Number(before.kilos_por_caja || 0) !== Number(after.kilos_por_caja || 0))) {
            packaging.push({ lot: before.numero_lote, delivered, pending: Math.max(0, total - delivered) });
        }
    }
    return { reductions, packaging, released: reductions.reduce((sum, row) => sum + row.released, 0) };
}
