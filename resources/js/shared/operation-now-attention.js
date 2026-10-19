import { buildOperationalAlerts } from './operation-now-alerts.js';

// Pestaña «Atención»: una sola lista de condiciones abiertas que reúne las alertas
// operacionales, los riesgos físicos del planificador y las incidencias. Cada fila
// agrupa lo que comparte causa (tres cámaras con el control vencido son una fila con
// tres cámaras), se ordena por severidad y antigüedad y apunta al lugar donde se
// resuelve. Nada se inventa: sin evidencia en la lectura vigente, no hay fila.

export const ATTENTION_SOURCES = Object.freeze([
    'Emergencias',
    'Ambiente',
    'Ocupación',
    'Prefrío',
    'Planificador',
    'Incidencias',
    'Sincronización',
]);

const SEVERITY_ORDER = { critical: 0, warning: 1 };

// Mismos umbrales que ServicioSaludPlanificador::estadoSalud. Las discrepancias abiertas
// no se repiten aquí: cada una ya figura como incidencia de maniobra.
const PLANNER_RISKS = [
    {
        key: 'maniobras_completadas_con_custodia',
        severity: 'critical',
        condition: 'Pallets sin devolver',
        unit: ['pallet', 'pallets'],
        detail: 'maniobras terminadas con pallets que siguen fuera de su posición',
    },
    {
        key: 'leases_vencidos_activos',
        severity: 'critical',
        condition: 'Reservas de tarea vencidas',
        unit: ['reserva', 'reservas'],
        detail: 'tareas reservadas cuyo plazo venció y siguen activas',
    },
    {
        key: 'tareas_estancadas',
        severity: 'warning',
        condition: 'Tareas detenidas',
        unit: ['tarea', 'tareas'],
        detail: 'en proceso sin avance dentro del plazo esperado',
    },
    {
        key: 'custodias_temporales_activas',
        severity: 'warning',
        condition: 'Pallets fuera de su posición',
        unit: ['pallet', 'pallets'],
        detail: 'en custodia temporal mientras avanza una maniobra',
    },
];

const INCIDENT_TYPE_LABELS = {
    prefrio_origen_fuera_umbral: 'Prefrío de origen fuera de umbral',
    caja_aplastada: 'Caja aplastada',
    zuncho_roto: 'Zuncho roto',
    pallet_mojado: 'Pallet mojado',
    pallet_inestable: 'Pallet inestable',
    folio_ilegible: 'Folio ilegible',
    diferencia_ubicacion: 'Diferencia de ubicación',
    folio_no_encontrado: 'Folio no encontrado',
    retencion_calidad: 'Retención de calidad',
    sector_inaccesible: 'Sector inaccesible',
};

const INCIDENT_ORIGIN_LABELS = {
    verificacion: 'Verificación de ubicaciones',
    maniobra: 'Maniobra',
    carga: 'Carga',
    recepcion_embalada: 'Recepción externa',
};

function numeric(value) {
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : 0;
}

function formatNumber(value) {
    return new Intl.NumberFormat('es-CL', { maximumFractionDigits: 0 }).format(numeric(value));
}

function humanize(value) {
    return String(value || '')
        .replaceAll('_', ' ')
        .replace(/^./, (letter) => letter.toUpperCase());
}

function sentence(value) {
    return String(value ?? '').replace(/^./, (letter) => letter.toUpperCase());
}

function worst(severities) {
    return severities.includes('critical') ? 'critical' : 'warning';
}

function plural(count, [singular, pluralForm]) {
    return `${formatNumber(count)} ${count === 1 ? singular : pluralForm}`;
}

function alertRows(snapshot) {
    const groups = new Map();
    for (const alert of buildOperationalAlerts(snapshot)) {
        const key = `alerta:${alert.source}:${alert.condition}:${alert.severity}`;
        if (!groups.has(key)) groups.set(key, []);
        groups.get(key).push(alert);
    }

    return [...groups.entries()].map(([key, alerts]) => {
        const [first] = alerts;
        const evidences = [...new Set(alerts.map((alert) => alert.evidence))];
        const shared = evidences.length === 1;
        const located = alerts.filter((alert) => alert.area !== alert.source);
        const overdue = alerts.map((alert) => alert.minutes).filter((value) => Number.isFinite(value));

        return {
            key,
            severity: first.severity,
            source: first.source,
            condition: first.condition,
            detail: shared ? sentence(evidences[0]) : null,
            items: located.map((alert) => ({ code: alert.area, note: shared ? null : sentence(alert.evidence) })),
            ageMinutes: null,
            overdueMinutes: overdue.length ? Math.max(...overdue) : null,
            href: first.href || null,
            action: first.action,
            tab: first.tab || null,
        };
    });
}

function plannerRows(planner = {}) {
    const rows = [];
    const mode = planner.despliegue?.mode_global || 'off';
    const freshness = planner.arbitraje?.vigencia || {};

    if (mode !== 'off' && (freshness.estado === 'error' || freshness.estado === 'atrasado')) {
        const failed = freshness.estado === 'error';
        rows.push({
            key: `planificador:calculo:${freshness.estado}`,
            severity: failed ? 'critical' : 'warning',
            source: 'Planificador',
            condition: failed ? 'Cálculo del planificador con error' : 'Cálculo del planificador atrasado',
            detail: freshness.detalle || (failed
                ? 'El último cálculo no terminó; las maniobras ofrecidas no se actualizan.'
                : 'Las maniobras ofrecidas pueden no reflejar la planta actual.'),
            items: [],
            ageMinutes: null,
            overdueMinutes: null,
            href: '#maniobras',
            action: 'Ver maniobras',
            tab: 'maniobras',
        });
    }

    const risks = planner.salud?.riesgos || {};
    for (const risk of PLANNER_RISKS) {
        const count = numeric(risks[risk.key]);
        if (count <= 0) continue;
        rows.push({
            key: `planificador:${risk.key}`,
            severity: risk.severity,
            source: 'Planificador',
            condition: risk.condition,
            detail: `${plural(count, risk.unit)} · ${risk.detail}`,
            items: [],
            ageMinutes: null,
            overdueMinutes: null,
            href: '#maniobras',
            action: 'Ver maniobras',
            tab: 'maniobras',
        });
    }

    return rows;
}

function incidentSeverity(incident) {
    return ['critica', 'urgente'].includes(incident.prioridad) ? 'critical' : 'warning';
}

function incidentRows(incidents = [], describeIncident = (incident) => incident.detalle || null) {
    const groups = new Map();
    for (const incident of incidents) {
        const key = `incidencia:${incident.origen || 'otro'}:${incident.tipo || 'otro'}`;
        if (!groups.has(key)) groups.set(key, []);
        groups.get(key).push(incident);
    }

    return [...groups.entries()].map(([key, group]) => {
        const [first] = group;
        const ordered = [...group].sort((left, right) => numeric(right.antiguedad_minutos) - numeric(left.antiguedad_minutos));
        const origin = INCIDENT_ORIGIN_LABELS[first.origen] || 'Operación';

        return {
            key,
            severity: worst(group.map(incidentSeverity)),
            source: 'Incidencias',
            condition: INCIDENT_TYPE_LABELS[first.tipo] || humanize(first.tipo || 'incidencia'),
            detail: group.length === 1
                ? `${origin} · ${first.detalle || 'Sin detalle'}`
                : `${origin} · ${formatNumber(group.length)} abiertas`,
            items: ordered.map((incident) => ({
                code: incident.folio?.numero_folio || 'Sin folio',
                note: describeIncident(incident),
                incident,
            })),
            ageMinutes: Math.max(...group.map((incident) => numeric(incident.antiguedad_minutos))),
            overdueMinutes: null,
            href: '/oficina/frigorifico/discrepancias',
            action: 'Revisar incidencias',
            tab: null,
        };
    });
}

/**
 * Condiciones abiertas de la lectura vigente, agrupadas y ordenadas por severidad,
 * antigüedad y fuente. `describeIncident` permite que la vista componga el contexto
 * físico de cada incidencia con sus propias etiquetas de cámara y posición.
 */
export function buildAttention(snapshot = {}, { describeIncident } = {}) {
    const rows = [
        ...alertRows(snapshot),
        ...plannerRows(snapshot.planificador),
        ...incidentRows(snapshot.incidencias?.abiertas || [], describeIncident),
    ].sort((left, right) => (
        (SEVERITY_ORDER[left.severity] ?? 9) - (SEVERITY_ORDER[right.severity] ?? 9)
        || (right.ageMinutes ?? right.overdueMinutes ?? -1) - (left.ageMinutes ?? left.overdueMinutes ?? -1)
        || ATTENTION_SOURCES.indexOf(left.source) - ATTENTION_SOURCES.indexOf(right.source)
        || left.condition.localeCompare(right.condition, 'es')
    ));

    const sources = ATTENTION_SOURCES.map((name) => {
        const own = rows.filter((row) => row.source === name);
        return {
            name,
            count: own.length,
            severity: own.length ? worst(own.map((row) => row.severity)) : null,
        };
    });
    const critical = rows.filter((row) => row.severity === 'critical').length;

    return {
        rows,
        sources,
        total: rows.length,
        critical,
        warning: rows.length - critical,
    };
}

/**
 * Insignias de cada pestaña. Solo «Atención» lleva tono de severidad; las demás dan
 * contexto neutro y desaparecen en cero para no competir con lo que sí exige mirar.
 */
export function buildOperationTabBadges(snapshot = {}, attention = buildAttention(snapshot)) {
    const cycle = snapshot.planificador?.arbitraje?.ciclo;
    const running = numeric(cycle?.resumen?.en_curso);
    const productCameras = (snapshot.camaras || []).filter((camera) => camera.contenido === 'productos');
    const capacity = productCameras.reduce((sum, camera) => sum + numeric(camera.capacidad_operativa), 0);
    const occupied = productCameras.reduce((sum, camera) => sum + numeric(camera.ocupadas), 0);
    const occupancy = capacity ? `${formatNumber((occupied / capacity) * 100)} %` : null;
    const precooling = numeric(snapshot.prefrio?.resumen?.procesos_activos);
    const operators = (snapshot.camareros || []).length;

    return {
        atencion: attention.total
            ? {
                text: formatNumber(attention.total),
                tone: attention.critical ? 'critical' : 'warning',
                label: plural(attention.total, ['condición abierta', 'condiciones abiertas']),
            }
            : null,
        maniobras: cycle && running ? { text: `${formatNumber(running)} en curso`, tone: 'neutral' } : null,
        plano: null,
        camaras: occupancy
            ? { text: occupancy, tone: 'neutral', label: `${occupancy} de ocupación en cámaras PT` }
            : null,
        prefrio: precooling ? { text: `${formatNumber(precooling)} en proceso`, tone: 'neutral' } : null,
        personal: operators ? { text: plural(operators, ['activo', 'activos']), tone: 'neutral' } : null,
    };
}
