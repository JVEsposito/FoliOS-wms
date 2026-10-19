// Prefactura de Finanzas: textos y formatos de la oficina. Funciones puras, sin DOM, para
// probarlas con node --test.
import { formatDay, formatDecimal } from './finance-tariffs-logic.js';

const MONTHS = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

/** «2026-11» → «noviembre de 2026». */
export function monthLabel(month) {
    const [year, number] = String(month || '').split('-');
    const name = MONTHS[Number(number) - 1];

    return name ? `${name} de ${year}` : String(month || '');
}

/** Monto exacto con su símbolo; ya viene con los decimales de su moneda: «$ 105.506», «UF 1,50». */
export function formatAmount(total) {
    return `${total.simbolo || total.moneda} ${formatDecimal(total.monto)}`;
}

/** Totales de varias monedas en una línea: «$ 105.506 · UF 1,50». */
export function totalsText(totals) {
    return (totals || []).map(formatAmount).join(' · ') || 'Sin montos';
}

/** «1 hecho», «4.000 hechos»: con separador de miles, como el resto de las cifras. */
export function countText(count, singular, pluralForm = `${singular}s`) {
    const number = Number(count) || 0;

    return `${formatDecimal(String(number))} ${number === 1 ? singular : pluralForm}`;
}

const plural = countText;

/** Qué cubre la prefactura: el mes completo, hasta hoy o nada todavía. */
export function periodText(data) {
    const month = monthLabel(data?.periodo?.mes);
    if (!data?.corte) return `${month}: mes futuro, todavía sin hechos que valorizar.`;
    if (data.a_la_fecha) return `${month}: a la fecha, valorizado hasta el ${formatDay(data.corte)}. El mes aún no termina.`;

    return `${month}: mes completo, del ${formatDay(data.periodo.inicio)} al ${formatDay(data.corte)}.`;
}

/** Resumen de un cliente bajo su nombre. */
export function clientSummary(client) {
    const lines = (client.lineas || []).length;
    const pending = (client.sin_precio || []).reduce((sum, item) => sum + Number(item.hechos || 0), 0);

    return plural(lines, 'línea', 'líneas') + (pending ? ` · ${plural(pending, 'hecho')} sin precio` : '');
}

/** Cantidad cobrada en una línea; en el almacenaje, también los días de estadía. */
export function quantityText(line) {
    const quantity = formatDecimal(line.cantidad);

    return line.dias ? `${quantity} · ${plural(line.dias, 'día', 'días')}` : quantity;
}

/** Origen y vigencia del precio aplicado. */
export function priceOrigin(line) {
    const origin = line.origen === 'propio' ? 'Precio propio' : 'Tarifa general';

    return `${origin} · desde el ${formatDay(line.vigente_desde)}`;
}

/** Un hecho dentro de una línea: su fecha, o los días cobrados de una estadía. */
export function detailText(item) {
    if (item.dias) {
        const days = item.desde === item.hasta ? formatDay(item.desde) : `${formatDay(item.desde)} → ${formatDay(item.hasta)}`;

        return `${days} · ${plural(item.dias, 'día', 'días')} · cantidad ${formatDecimal(item.cantidad)}`;
    }

    return `${formatDay(item.desde)} · cantidad ${formatDecimal(item.cantidad)}`;
}

/** Pendientes sin precio de un cliente. */
export function pendingText(item) {
    return `${item.nombre}: ${plural(item.hechos, 'hecho')} sin precio`;
}
