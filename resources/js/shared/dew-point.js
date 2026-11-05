/*
 * Punto de rocío por la aproximación de Magnus (a = 17,625; b = 243,04 °C), igual
 * que la calculadora de cerezas y el servidor. La condensación sobre la fruta se
 * evalúa con su T° superficial, no con la pulpa.
 */
const A = 17.625;
const B = 243.04;

function saturation(temperature) { return 6.1094 * Math.exp((A * temperature) / (B + temperature)); }
function validHumidity(humidity) { return Number.isFinite(humidity) && humidity > 0 && humidity <= 100; }

export function dewPoint(temperature, humidity) {
    if (!Number.isFinite(temperature) || !validHumidity(humidity)) return null;
    const gamma = Math.log(humidity / 100) + (A * temperature) / (B + temperature);
    return (B * gamma) / (A - gamma);
}

export function waterVapor(temperature, humidity) {
    if (!Number.isFinite(temperature) || !validHumidity(humidity)) return null;
    return (216.7 * ((humidity / 100) * saturation(temperature))) / (273.15 + temperature);
}

export function humidityAt(temperature, humidity, target) {
    if (!Number.isFinite(temperature) || !Number.isFinite(target) || !validHumidity(humidity)) return null;
    return (100 * ((humidity / 100) * saturation(temperature))) / saturation(target);
}

export const DEW_POINT_STATES = {
    sin_superficie: { label: 'Medir superficie', tone: 'neutral', help: 'Se calcula el rocío, pero no se evalúa el riesgo sobre la fruta.' },
    condensacion_posible: { label: 'Condensación posible', tone: 'danger', help: 'La superficie está bajo el punto de rocío: puede condensar agua.' },
    margen_bajo: { label: 'Margen bajo', tone: 'warning', help: 'Margen reducido: vigila cambios de temperatura y de humedad.' },
    margen_favorable: { label: 'Margen favorable', tone: 'success', help: 'Margen preventivo favorable (referencia interna, no norma).' },
};

export function dewPointState(margin, minimumMargin = 2) {
    if (margin === null || margin === undefined || !Number.isFinite(margin)) return 'sin_superficie';
    if (margin < 0) return 'condensacion_posible';
    if (margin < minimumMargin) return 'margen_bajo';
    return 'margen_favorable';
}

/** Evaluación de una lectura tal como la guarda el servidor (redondeo a 2 decimales). */
export function evaluateReading({ ambient, humidity, surface = null, minimumMargin = 2 }) {
    const dew = dewPoint(ambient, humidity);
    if (dew === null) return null;
    const roundedDew = Math.round(dew * 100) / 100;
    const hasSurface = surface !== null && surface !== undefined && surface !== '' && Number.isFinite(Number(surface));
    const margin = hasSurface ? Math.round((Number(surface) - roundedDew) * 100) / 100 : null;
    return {
        dewPoint: roundedDew,
        margin,
        vapor: Math.round(waterVapor(ambient, humidity) * 100) / 100,
        state: dewPointState(margin, minimumMargin),
    };
}

/** Alertas de Operación ahora: chequeos con riesgo que aún no tienen acción. */
export function dewPointAlerts(pending = []) {
    return pending.map((alert) => {
        const drops = alert.gotas_visibles === 'si';
        const critical = typeof alert.critica === 'boolean' ? alert.critica : drops || alert.estado === 'condensacion_posible';
        const condition = alert.condicion || (drops ? 'Gotas visibles' : alert.estado_etiqueta);
        const format = (value) => (value === null || value === undefined ? '—' : new Intl.NumberFormat('es-CL', { maximumFractionDigits: 1 }).format(value));
        // Un riesgo vinculado (envase o fruta que llega) trae su propio detalle desde el servidor.
        const linked = alert.condicion_detalle && condition !== alert.estado_etiqueta && condition !== 'Gotas visibles';
        return {
            area: alert.punto,
            source: 'Prefrío',
            tab: 'prefrio',
            severity: critical ? 'critical' : 'warning',
            condition: `${condition} · ${alert.etapa_etiqueta}`,
            evidence: linked
                ? alert.condicion_detalle.charAt(0).toUpperCase() + alert.condicion_detalle.slice(1)
                : (alert.margen_c === null || alert.margen_c === undefined
                    ? `Rocío ${format(alert.punto_rocio_c)} °C`
                    : `Rocío ${format(alert.punto_rocio_c)} °C · superficie ${format(alert.temperatura_superficie_c)} °C · margen ${format(alert.margen_c)} °C`),
            href: `/oficina/prefrio/punto-rocio?fecha=${alert.fecha}`,
            action: 'Registrar acción correctiva',
        };
    });
}

const STATE_RANK = { sin_superficie: 0, margen_favorable: 1, margen_bajo: 2, condensacion_posible: 3 };

/**
 * Comparación vinculada: una T° superficial frente al punto de rocío de otro aire
 * (aire del envase al sellar, o aire del punto al que llega la fruta). Redondea
 * como el servidor.
 */
export function compareSurface(surface, dewPointValue, minimumMargin = 2) {
    const surfaceNumber = surface === '' || surface === null || surface === undefined ? Number.NaN : Number(surface);
    const dew = dewPointValue === null || dewPointValue === undefined ? Number.NaN : Number(dewPointValue);
    if (!Number.isFinite(surfaceNumber) || !Number.isFinite(dew)) return { margin: null, state: 'sin_superficie' };
    const margin = Math.round((surfaceNumber - dew) * 100) / 100;
    return { margin, state: dewPointState(margin, minimumMargin) };
}

/** Estado más grave de una lista (condensación > margen bajo > favorable > sin superficie). */
export function worstDewPointState(states) {
    return states.filter(Boolean).reduce((worst, current) => (STATE_RANK[current] > STATE_RANK[worst] ? current : worst), 'sin_superficie');
}

/** HR máxima del aire a una temperatura para que su rocío no supere el indicado (tope 100 %). */
export function humidityForDewPoint(temperature, dewPointMax) {
    if (!Number.isFinite(temperature) || !Number.isFinite(dewPointMax)) return null;
    return Math.min(100, (100 * saturation(dewPointMax)) / saturation(temperature));
}

const round = (value, decimals) => Math.round(value * 10 ** decimals) / 10 ** decimals;

/**
 * Plan de cadena de frío calculado como el servidor. Cada fila trae etapa,
 * temperatura_aire_c y temperatura_fruta_c en el orden de la cadena; marginFor
 * devuelve el margen mínimo de una etapa. El sellado suma el aire del envase: su
 * rocío debe quedar bajo la fruta más fría de las etapas siguientes.
 */
export function coldChainPlan(rows, marginFor, freezing = -1.7) {
    const base = rows.map((row) => {
        const fruit = Number.isFinite(row.temperatura_fruta_c) ? row.temperatura_fruta_c : null;
        const air = Number.isFinite(row.temperatura_aire_c) ? row.temperatura_aire_c : null;
        const margin = marginFor(row.etapa);
        return { ...row, air, fruit, margin, fruitDew: fruit === null ? null : round(fruit - margin, 2) };
    });
    return base.map((row, index) => {
        const later = base.slice(index + 1).map((item) => item.fruitDew).filter((value) => value !== null);
        const envelope = row.etapa === 'sellado' && later.length ? Math.min(...later) : null;
        const limits = [row.fruitDew, envelope].filter((value) => value !== null);
        const maximum = limits.length ? Math.min(...limits) : null;
        const limiting = maximum === null ? null : (envelope !== null && envelope === maximum && (row.fruitDew === null || envelope < row.fruitDew) ? 'envase' : 'fruta');
        const cold = [row.air, row.fruit].filter((value) => value !== null);
        return {
            etapa: row.etapa,
            planificada: row.fruit !== null,
            temperatura_aire_c: row.air,
            temperatura_fruta_c: row.fruit,
            margen_minimo_c: row.margin,
            rocio_fruta_c: row.fruitDew,
            rocio_envase_c: envelope,
            rocio_maximo_c: maximum,
            limitante: limiting,
            humedad_maxima: maximum !== null && row.air !== null ? round(humidityForDewPoint(row.air, maximum), 1) : null,
            cerca_congelacion: cold.length > 0 && Math.min(...cold) <= freezing + 0.5,
        };
    });
}
