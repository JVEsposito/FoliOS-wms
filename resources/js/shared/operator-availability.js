/** Un aviso por transición del servidor; la primera lectura establece la línea de base. */
export function createAvailabilityTracker() {
    let season = null;
    let initialized = false;
    let lastRead = 0;
    const seen = new Set();
    return {
        receive(snapshot) {
            const stamp = Date.parse(snapshot.generado_at);
            if (!Number.isFinite(stamp) || (season === snapshot.temporada_id && stamp < lastRead)) {
                return { applied: false, alerts: [] };
            }
            if (season !== snapshot.temporada_id) {
                season = snapshot.temporada_id; initialized = false; seen.clear();
            }
            const alerts = [];
            for (const operator of snapshot.camareros || []) {
                const id = operator.aviso_disponible_id;
                if (!id) continue;
                if (initialized && !seen.has(id) && operator.estado_presencia === 'disponible') alerts.push(operator);
                seen.add(id);
            }
            initialized = true; lastRead = stamp;
            while (seen.size > 500) seen.delete(seen.values().next().value);
            return { applied: true, alerts };
        },
    };
}

export function operatorPresenceLabel(operator) {
    return ({ disponible: 'Disponible', trabajando: 'Trabajando', esperando: 'Esperando / requiere atención',
        desconectado: 'Sin conexión', sin_confirmar: 'Presencia sin confirmar' })[operator.estado_presencia]
        || (operator.tarea_actual ? 'Con tarea' : 'Sesión reciente');
}
