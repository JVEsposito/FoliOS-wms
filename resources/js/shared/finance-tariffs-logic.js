// Tarifario de Finanzas: lectura de precios, textos y validaciones de la oficina.
// Funciones puras, sin DOM, para probarlas con node --test.

export const TARIFF_STATES = {
    vigente: 'Vigente',
    futura: 'Programado',
    vencida: 'Vencido',
    anulada: 'Anulado',
};

export const TARIFF_EVENTS = {
    creada: 'Creado',
    modificada: 'Editado',
    cerrada: 'Cerrado al reemplazarlo',
    anulada: 'Anulado',
};

// Las monedas que más se usan en la planta, para agregarlas con un clic. Se pueden editar.
export const CURRENCY_PRESETS = [
    { codigo: 'CLP', nombre: 'Peso chileno', simbolo: '$', decimales: 0 },
    { codigo: 'UF', nombre: 'Unidad de fomento', simbolo: 'UF', decimales: 2 },
    { codigo: 'USD', nombre: 'Dólar estadounidense', simbolo: 'US$', decimales: 2 },
];

// Orden en que se muestran los cambios de un precio (MySQL reordena las claves JSON).
const CHANGE_FIELDS = [
    ['precio', 'Precio'],
    ['moneda', 'Moneda'],
    ['vigente_desde', 'Vigente desde'],
    ['vigente_hasta', 'Vigente hasta'],
    ['nota', 'Nota'],
];

/** «2026-11-01» → «01-11-2026». */
export function formatDay(value) {
    const [year, month, day] = String(value || '').split('-');

    return day ? `${day}-${month}-${year}` : '—';
}

/** Número decimal exacto («1234.5») con separadores de Chile: «1.234,5». Sin flotantes. */
export function formatDecimal(value) {
    const [integer, decimals = ''] = String(value ?? '0').split('.');
    const grouped = integer.replace(/\B(?=(\d{3})+(?!\d))/g, '.');

    return decimals ? `${grouped},${decimals}` : grouped;
}

/** «$ 1.234,5» · «UF 0,35» · «US$ 0,0125». */
export function formatPrice(tariff) {
    return `${tariff.moneda_simbolo || tariff.moneda} ${formatDecimal(tariff.precio)}`;
}

export function validityText(from, to) {
    return `${formatDay(from)} → ${to ? formatDay(to) : 'sin término'}`;
}

/**
 * Lee el precio que se escribe en la oficina. Acepta coma o punto decimal, pero no separadores
 * de miles: «1.500» podría ser mil quinientos o uno coma cinco, y un precio no admite dudas.
 */
export function parsePriceInput(text) {
    const value = String(text ?? '').replace(/\s+/g, '');
    if (value === '') return { ok: false, message: 'Indique el precio.' };
    if (/^-?[1-9]\d{0,2}\.\d{3}$/.test(value)) {
        return { ok: false, message: 'Escriba el precio sin puntos de miles; use coma para los decimales (por ejemplo, 1500 o 1500,5).' };
    }

    const match = value.match(/^-?(\d+)(?:[.,](\d+))?$/);
    if (!match) return { ok: false, message: 'Use solo dígitos y una coma decimal (por ejemplo, 0,35).' };

    const integer = match[1].replace(/^0+(?=\d)/, '');
    const decimals = (match[2] || '').replace(/0+$/, '');
    if (integer.length > 12) return { ok: false, message: 'El precio no puede tener más de 12 dígitos enteros.' };
    if ((match[2] || '').length > 6) return { ok: false, message: 'El precio admite hasta 6 decimales.' };

    const normalized = decimals ? `${integer}.${decimals}` : integer;
    return { ok: true, value: normalized === '0' ? '0' : `${value.startsWith('-') ? '-' : ''}${normalized}` };
}

/** Unidades de cobro de un servicio, desde el catálogo que entrega la API. */
export function unitsFor(services, serviceKey) {
    return (services || []).find((service) => service.clave === serviceKey)?.unidades || [];
}

function changeValue(field, value) {
    if (value === null || value === undefined || value === '') {
        return field === 'vigente_hasta' ? 'sin término' : '—';
    }
    if (field === 'vigente_desde' || field === 'vigente_hasta') return formatDay(value);
    if (field === 'precio') return formatDecimal(value);

    return String(value);
}

/** Líneas legibles de un evento del historial, en un orden fijo de campos. */
export function eventLines(event) {
    const changes = event?.cambios || {};
    if (event?.accion === 'creada') {
        return [`${changes.cliente} · ${changes.servicio} ${changes.unidad} · ${changes.moneda} ${formatDecimal(changes.precio)} · ${validityText(changes.vigente_desde, changes.vigente_hasta)}`];
    }

    return CHANGE_FIELDS
        .filter(([field]) => Object.hasOwn(changes, field))
        .map(([field, label]) => `${label}: ${changeValue(field, changes[field]?.antes)} → ${changeValue(field, changes[field]?.despues)}`);
}

/** Resumen de una línea para el estado de la oficina. */
export function summaryText(summary, servicesCount) {
    const current = Number(summary?.vigentes || 0);
    const scheduled = Number(summary?.futuras || 0);
    const clients = Number(summary?.clientes_con_precio_propio || 0);
    const general = Number(summary?.servicios_en_tarifa_general || 0);
    const prices = `${current} ${current === 1 ? 'precio vigente' : 'precios vigentes'}`
        + (scheduled ? ` y ${scheduled} ${scheduled === 1 ? 'programado' : 'programados'}` : '');

    return `${prices} · ${clients} ${clients === 1 ? 'cliente' : 'clientes'} con precio propio · tarifa general en ${general} de ${servicesCount} servicios`;
}
