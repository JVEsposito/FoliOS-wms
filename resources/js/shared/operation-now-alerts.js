const priority = { critical: 0, warning: 1, info: 2 };

function numeric(value) {
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : 0;
}

function formatNumber(value, maximumFractionDigits = 0) {
    return new Intl.NumberFormat('es-CL', { maximumFractionDigits }).format(numeric(value));
}

function cameraAlerts(cameras = []) {
    return cameras.flatMap((camera) => {
        const alerts = [];
        const occupancy = numeric(camera.ocupacion_porcentaje);
        const evidence = `${formatNumber(camera.ocupadas)} de ${formatNumber(camera.capacidad_operativa)} posiciones (${formatNumber(occupancy, 1)} %)`;

        if (camera.nivel_ocupacion === 'critica') {
            alerts.push({
                area: camera.codigo,
                severity: 'critical',
                condition: 'Ocupación crítica de cámara',
                evidence,
                href: '/oficina/frigorifico/camaras',
                action: 'Revisar cámara',
            });
        } else if (camera.nivel_ocupacion === 'advertencia') {
            alerts.push({
                area: camera.codigo,
                severity: 'warning',
                condition: 'Ocupación alta de cámara',
                evidence,
                href: '/oficina/frigorifico/camaras',
                action: 'Evaluar distribución',
            });
        }

        const environment = camera.control_ambiental;
        if (environment?.estado === 'vencido' || environment?.estado === 'pendiente') {
            alerts.push({
                area: camera.codigo,
                severity: environment.estado === 'vencido' ? 'critical' : 'warning',
                condition: environment.estado === 'vencido'
                    ? 'Control ambiental vencido'
                    : 'Control ambiental pendiente',
                evidence: environment.capturado_at
                    ? 'La última lectura ya no está vigente'
                    : 'No existe una lectura registrada',
                href: '/oficina/frigorifico/camaras',
                action: 'Revisar control',
            });
        }

        return alerts;
    });
}

function tunnelAlerts(tunnels = []) {
    return tunnels.flatMap((tunnel) => {
        const alerts = [];
        const process = tunnel.proceso_activo;

        if (!tunnel.operable) {
            alerts.push({
                area: tunnel.codigo,
                severity: 'warning',
                condition: 'Túnel no operable',
                evidence: String(tunnel.estado_operacional || 'Estado no informado').replaceAll('_', ' '),
                href: '/oficina/prefrio',
                action: 'Revisar túnel',
            });
        }

        if (tunnel.estado_operacional === 'bloqueado_otra_temporada') {
            const foreign = tunnel.proceso_otra_temporada;
            alerts.push({
                area: tunnel.codigo,
                severity: 'critical',
                condition: 'Túnel bloqueado por proceso anterior',
                evidence: foreign
                    ? `${foreign.codigo} · ${foreign.temporada_codigo || 'temporada anterior'}`
                    : 'Proceso anterior sin cerrar',
                action: 'Se resuelve en el cierre de temporada',
            });
        }

        if (process?.objetivo_excedido) {
            alerts.push({
                area: tunnel.codigo,
                severity: 'critical',
                condition: 'Proceso fuera de tiempo objetivo',
                evidence: `${formatNumber(process.minutos_sobre_objetivo)} min sobre el objetivo`,
                href: '/oficina/prefrio',
                action: 'Revisar proceso',
            });
        }

        return alerts;
    });
}

function synchronizationAlerts(synchronization = {}) {
    const counts = synchronization.operaciones_hoy || {};
    const conflicts = numeric(counts.conflicto);
    const rejected = numeric(counts.rechazada);
    const alerts = [];

    if (conflicts > 0) {
        alerts.push({
            area: 'Sincronización',
            severity: 'critical',
            condition: 'Operaciones con conflicto',
            evidence: `${formatNumber(conflicts)} durante la jornada`,
            href: '/oficina/administracion/integridad-operacional',
            action: 'Revisar salud',
        });
    }

    if (rejected > 0) {
        alerts.push({
            area: 'Sincronización',
            severity: 'warning',
            condition: 'Operaciones rechazadas',
            evidence: `${formatNumber(rejected)} durante la jornada`,
            href: '/oficina/administracion/integridad-operacional',
            action: 'Revisar salud',
        });
    }

    return alerts;
}

export function buildOperationalAlerts(snapshot = {}) {
    const productCameras = (snapshot.camaras || [])
        .filter((camera) => camera.contenido === 'productos');
    const alerts = [
        ...(snapshot.emergencias || []).map((emergencia) => ({
            area: emergencia.camara_codigo || 'Cámara',
            severity: 'critical',
            condition: `Evacuación de emergencia activa en ${emergencia.camara_codigo || 'cámara'}`,
            evidence: emergencia.motivo || 'Evacuación declarada',
            href: '/oficina/frigorifico/camaras',
            action: 'Revisar plano de la cámara',
        })),
        ...cameraAlerts(productCameras),
        ...tunnelAlerts(snapshot.prefrio?.tuneles || []),
        ...synchronizationAlerts(snapshot.sincronizacion),
    ];

    return alerts.sort((left, right) => (
        (priority[left.severity] ?? 99) - (priority[right.severity] ?? 99)
        || left.area.localeCompare(right.area, 'es')
        || left.condition.localeCompare(right.condition, 'es')
    ));
}
