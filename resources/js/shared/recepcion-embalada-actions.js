// Contrato común para la pantalla de Oficina y la captura PDA del PR 1.
export function createReceptionActions(request, uuid) {
    let pending = null;
    async function mutate(id, action, data) {
        const signature = JSON.stringify({ id, action, ...data });
        if (pending?.signature !== signature) pending = { signature, operacion_id: uuid() };
        const result = await request(`/api/recepciones-fruta-embalada/${encodeURIComponent(id)}/${action}`, {
            method: 'POST', body: JSON.stringify({ ...data, operacion_id: pending.operacion_id }),
        });
        pending = null;
        return result;
    }
    return {
        load: (id) => request(`/api/recepciones-fruta-embalada/${encodeURIComponent(id)}/aceptacion`),
        accept: (id, version) => mutate(id, 'aceptar', { version }),
        annul: (id, motivo) => mutate(id, 'anular', { motivo: motivo.trim() }),
    };
}
