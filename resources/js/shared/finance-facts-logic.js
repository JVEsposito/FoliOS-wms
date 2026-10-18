/**
 * Presentación de los hechos valorizables de Finanzas: medidas, detalle por fuente,
 * duración de una estadía y diferencias entre versiones. Sin DOM, para poder probarlo.
 */

const integer = new Intl.NumberFormat('es-CL', { maximumFractionDigits: 0 });
const decimal = new Intl.NumberFormat('es-CL', { maximumFractionDigits: 1 });

export const VERSION_REASONS = Object.freeze({
    captura: 'Captura',
    cambio: 'Cambió en la operación',
    anulacion: 'Anulado en la operación',
    eliminado: 'Ya no existe en la operación',
});

export const READ_ORIGINS = Object.freeze({ programada: 'Programada', manual: 'A pedido', consola: 'Consola' });

export const READ_STATES = Object.freeze({
    en_curso: 'En curso',
    completada: 'Completa',
    con_errores: 'Con errores',
    fallida: 'Fallida',
});

const EXIT_REASONS = Object.freeze({
    despacho: 'despachado',
    repaletizaje: 'repaletizado',
    anulacion: 'anulado',
    retiro_definitivo: 'retirado',
    inactivo: 'dado de baja',
});

/** Medida principal de cada fuente, la que se muestra en su tarjeta, y cómo se lee. */
export const KPI = Object.freeze({
    romana_recepcion: { measure: 'kilos_netos', label: (summary) => `kg netos · ${quantity(summary.hechos, 'recepción', 'recepciones')}` },
    validacion_pt: { measure: 'bultos', label: () => 'pallets y saldos' },
    recepcion_embalada: { measure: 'bultos', label: (summary) => `pallets y saldos · ${quantity(summary.hechos, 'recepción', 'recepciones')}` },
    prefrio: { measure: 'bultos', label: () => 'pallets y saldos' },
    estadia: { measure: 'bultos', label: () => 'pallets y saldos en el mes' },
    repaletizaje: { measure: 'operaciones', label: () => 'repaletizajes' },
    despacho: { measure: 'bultos', label: () => 'pallets y saldos' },
});

export function quantity(value, unit, plural = `${unit}s`) {
    const number = Number(value || 0);
    const text = Number.isInteger(number) ? integer.format(number) : decimal.format(number);

    return `${text} ${Math.abs(number) === 1 ? unit : plural}`;
}

function packages(measures) {
    const parts = [];
    if (measures.pallets) parts.push(quantity(measures.pallets, 'pallet'));
    if (measures.saldos) parts.push(quantity(measures.saldos, 'saldo'));

    return parts;
}

/** Medidas de un hecho en palabras: «120 cajas · 1 pallet», «8.450,5 kg · 24 envases». */
export function measuresText(fact) {
    const measures = fact.medidas || {};
    switch (fact.fuente) {
        case 'romana_recepcion':
            return [`${decimal.format(Number(measures.kilos_netos || 0))} kg`, quantity(measures.envases, 'envase')].join(' · ');
        case 'repaletizaje':
            return [
                `${quantity(measures.folios_origen, 'folio')} → ${quantity(measures.folios_resultantes, 'folio')}`,
                quantity(measures.cajas, 'caja'),
            ].join(' · ');
        default:
            return [quantity(measures.cajas, 'caja'), ...packages(measures)].join(' · ');
    }
}

/** Duración exacta entre dos instantes, en días y horas: «4 d 6 h», «3 h». */
export function duration(fromIso, toIso) {
    const hours = Math.max(0, Math.floor((new Date(toIso) - new Date(fromIso)) / 3_600_000));
    const days = Math.floor(hours / 24);
    const rest = hours % 24;
    if (days === 0) return `${rest} h`;

    return rest === 0 ? `${days} d` : `${days} d ${rest} h`;
}

function join(parts) {
    return parts.filter((part) => part !== null && part !== undefined && part !== '').join(' · ');
}

/** Lo que identifica al hecho en la operación, según su fuente. */
export function factDetail(fact, now = new Date()) {
    const data = fact.datos || {};
    switch (fact.fuente) {
        case 'romana_recepcion':
            return join([data.tipo_servicio && `Servicio ${data.tipo_servicio}`, data.especie, data.guia && `Guía ${data.guia}`]);
        case 'validacion_pt':
            return join([data.variedad, data.calibre, data.envase, data.csg && `CSG ${data.csg}`]);
        case 'recepcion_embalada':
            return join([data.planta_origen, data.servicio && `Servicio ${data.servicio}`, data.llega_con_prefrio ? 'Llega con prefrío' : null]);
        case 'prefrio':
            return join([data.proceso, data.tunel && `Túnel ${data.tunel}`, data.minutos_enfriado ? `${duration(0, data.minutos_enfriado * 60_000)} de frío` : null]);
        case 'estadia': {
            const until = fact.termina_at || now.toISOString();
            const exit = data.motivo_salida ? EXIT_REASONS[data.motivo_salida] || data.motivo_salida : 'en planta';
            return join([
                data.camara_ingreso && `Ingresó a ${data.camara_ingreso}`,
                `${duration(fact.ocurrido_at, until)}${fact.termina_at ? '' : ' hasta ahora'}`,
                data.carga ? `${exit} en ${data.carga}` : exit,
                data.salida_estimada ? 'salida estimada' : null,
                data.fuera_de_camara_desde ? 'fuera de cámara' : null,
            ]);
        }
        case 'repaletizaje':
            return join([data.modalidad, data.folio_resultante && `Resultó ${data.folio_resultante}`]);
        case 'despacho':
            return join([data.carga && `Carga ${data.carga}`, data.orden_externa && `Orden ${data.orden_externa}`, data.patente]);
        default:
            return '';
    }
}

const FIELD_LABELS = Object.freeze({
    estado: 'Estado',
    cliente_nombre: 'Cliente',
    referencia: 'Referencia',
    ocurrido_at: 'Inicio',
    termina_at: 'Término',
});

function shown(value) {
    if (value === null || value === undefined || value === '') return '—';
    if (typeof value === 'boolean') return value ? 'sí' : 'no';

    return String(value);
}

/** Qué cambió de una versión a la siguiente, campo por campo. */
export function versionChanges(previous, next) {
    if (!previous) return [];
    const changes = [];
    for (const [field, label] of Object.entries(FIELD_LABELS)) {
        if (shown(previous[field]) !== shown(next[field])) changes.push({ label, before: shown(previous[field]), after: shown(next[field]) });
    }
    for (const group of ['medidas', 'datos']) {
        const keys = new Set([...Object.keys(previous[group] || {}), ...Object.keys(next[group] || {})]);
        for (const key of [...keys].sort()) {
            const before = shown(previous[group]?.[key]);
            const after = shown(next[group]?.[key]);
            if (before !== after) changes.push({ label: key.replaceAll('_', ' '), before, after });
        }
    }

    return changes;
}

/** Valor de la tarjeta de una fuente: su medida principal sumada en el mes. */
export function kpiValue(summary) {
    const value = Number(summary.medidas?.[KPI[summary.fuente]?.measure] ?? summary.hechos ?? 0);

    return Number.isInteger(value) ? integer.format(value) : decimal.format(value);
}

/** Qué cuenta la tarjeta de una fuente. */
export function kpiLabel(summary) {
    return KPI[summary.fuente]?.label(summary) ?? quantity(summary.hechos, 'hecho');
}
