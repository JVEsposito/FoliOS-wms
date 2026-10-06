/** Cliente de acciones del PR 2, para integrar con la captura de recepción del PR 1. */
export type EstadoRecepcionEmbalada = {
    estado: 'borrador' | 'aceptada' | 'anulada';
    revision?: { version: string };
    advertencias?: { mensaje: string; pallet_id: string }[];
    incidencias?: { temperatura_pulpa: string; umbral_prefrio: string }[];
    folios: { folio_id: string; numero_folio: string; folio_interno: boolean; folio_origen: string; estado_operacional: string; etiqueta_pendiente?: boolean; etiqueta_impresa?: boolean; version_etiqueta?: string; etiqueta?: { envases_sin_kilos: { nombre: string }[] } }[];
};

type Request = (path: string, options?: { method: string; body: string }) => Promise<{ data: EstadoRecepcionEmbalada }>;

export function recepcionEmbaladaAcciones(request: Request, uuid: () => string) {
    let pending: { signature: string; operation: string } | null = null;
    async function send(id: string, action: string, data: Record<string, string>) {
        const signature = JSON.stringify({ id, action, ...data });
        if (pending?.signature !== signature) pending = { signature, operation: uuid() };
        const result = await request(`/api/recepciones-fruta-embalada/${encodeURIComponent(id)}/${action}`, {
            method: 'POST', body: JSON.stringify({ ...data, operacion_id: pending.operation }),
        });
        pending = null;
        return result.data;
    }
    return {
        load: async (id: string) => (await request(`/api/recepciones-fruta-embalada/${encodeURIComponent(id)}/aceptacion`)).data,
        accept: (id: string, version: string) => send(id, 'aceptar', { version }),
        annul: (id: string, motivo: string) => send(id, 'anular', { motivo: motivo.trim() }),
    };
}
