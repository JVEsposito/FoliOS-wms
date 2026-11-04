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
        const critical = drops || alert.estado === 'condensacion_posible';
        const format = (value) => (value === null || value === undefined ? '—' : new Intl.NumberFormat('es-CL', { maximumFractionDigits: 1 }).format(value));
        return {
            area: alert.punto,
            source: 'Prefrío',
            tab: 'prefrio',
            severity: critical ? 'critical' : 'warning',
            condition: `${drops ? 'Gotas visibles' : alert.estado_etiqueta} · ${alert.etapa_etiqueta}`,
            evidence: alert.margen_c === null || alert.margen_c === undefined
                ? `Rocío ${format(alert.punto_rocio_c)} °C`
                : `Rocío ${format(alert.punto_rocio_c)} °C · superficie ${format(alert.temperatura_superficie_c)} °C · margen ${format(alert.margen_c)} °C`,
            href: `/oficina/prefrio/punto-rocio?fecha=${alert.fecha}`,
            action: 'Registrar acción correctiva',
        };
    });
}
