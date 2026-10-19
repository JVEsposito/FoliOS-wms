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

/** Qué cubre la prefactura: el mes cerrado, completo, hasta hoy o nada todavía. */
export function periodText(data) {
    const month = monthLabel(data?.periodo?.mes);
    if (data?.estado === 'cerrado') return `${month}: mes cerrado, del ${formatDay(data.periodo.inicio)} al ${formatDay(data.corte)}.`;
    if (!data?.corte) return `${month}: mes futuro, todavía sin hechos que valorizar.`;
    if (data.a_la_fecha) return `${month}: a la fecha, valorizado hasta el ${formatDay(data.corte)}. El mes aún no termina.`;

    return `${month}: mes completo, del ${formatDay(data.periodo.inicio)} al ${formatDay(data.corte)}.`;
}

/** Resumen de un cliente bajo su nombre. */
export function clientSummary(client) {
    const lines = (client.lineas || []).length;
    const adjustments = (client.ajustes || []).length;
    const pending = (client.sin_precio || []).reduce((sum, item) => sum + Number(item.hechos || 0), 0);

    return plural(lines, 'línea', 'líneas')
        + (adjustments ? ` · ${plural(adjustments, 'ajuste')}` : '')
        + (pending ? ` · ${plural(pending, 'hecho')} sin precio` : '');
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

const MINUS = '−';
const ZERO = /^-?0(\.0+)?$/;

function sentence(text) {
    const value = String(text ?? '').trim();

    return !value || /[.!?…]$/.test(value) ? value : `${value}.`;
}

function isNegative(value) {
    return String(value ?? '').trim().startsWith('-') && !ZERO.test(String(value).trim());
}

/** Cantidad con signo: «+1.000», «−8.000», «0». */
export function signedQuantity(value) {
    const text = String(value ?? '0').trim();
    if (ZERO.test(text)) return '0';

    return isNegative(text) ? `${MINUS}${formatDecimal(text.slice(1))}` : `+${formatDecimal(text)}`;
}

/** Monto con signo y símbolo: «+$ 10.000», «−UF 1,50», «$ 0». */
export function signedAmount(total) {
    const text = String(total?.monto ?? '0').trim();
    const symbol = total?.simbolo || total?.moneda || '';
    if (ZERO.test(text)) return `${symbol} ${formatDecimal(text.replace('-', ''))}`;

    return isNegative(text) ? `${MINUS}${symbol} ${formatDecimal(text.slice(1))}` : `+${symbol} ${formatDecimal(text)}`;
}

/** «+1 día», «−2 días». */
export function signedDays(days) {
    const number = Number(days) || 0;

    return `${number < 0 ? MINUS : '+'}${plural(Math.abs(number), 'día', 'días')}`;
}

/** Fecha y hora «05-12-2026 15:20»; en la zona indicada o en la del navegador. */
export function formatMoment(value, timeZone) {
    const date = new Date(value);
    if (!value || Number.isNaN(date.getTime())) return '—';
    const parts = Object.fromEntries(new Intl.DateTimeFormat('es-CL', {
        timeZone, day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit', hourCycle: 'h23',
    }).formatToParts(date).map((part) => [part.type, part.value]));

    return `${parts.day}-${parts.month}-${parts.year} ${parts.hour}:${parts.minute}`;
}

/** Estado del mes respecto del cierre, para la barra bajo el período. */
export function closureText(data, timeZone) {
    if (data?.estado === 'cerrado' && data.cierre) {
        return `Cerrado el ${formatMoment(data.cierre.cerrado_at, timeZone)} por ${data.cierre.cerrado_por || 'usuario sin nombre'} · versión ${data.cierre.version}`;
    }
    if (data?.para_cerrar?.posible) return 'Mes terminado, sin cerrar.';

    return sentence(data?.para_cerrar?.motivo || 'Mes abierto.');
}

/** Con qué lectura de la operación quedó congelado el mes. */
export function closureCaptureText(closure, timeZone) {
    return closure?.lectura_at
        ? `Se congeló con la lectura de la operación del ${formatMoment(closure.lectura_at, timeZone)}. Lo posterior se revisa como diferencia.`
        : 'Se congeló sin lecturas de la operación registradas. Lo posterior se revisa como diferencia.';
}

/** Una versión anterior del cierre que se reabrió. */
export function reopeningText(reopening, timeZone) {
    return `La versión ${reopening.version} se reabrió el ${formatMoment(reopening.reabierto_at, timeZone)}`
        + ` (${reopening.reabierto_por || 'usuario sin nombre'}): ${sentence(reopening.motivo_reapertura)}`;
}

/** Hechos que quedarían sin cobrar al cerrar: «1 hecho sin precio y 2 hechos sin cliente vinculado». */
export function pendingClosureText(summary) {
    return [
        summary?.hechos_sin_precio ? plural(summary.hechos_sin_precio, 'hecho sin precio', 'hechos sin precio') : null,
        summary?.hechos_sin_cliente ? plural(summary.hechos_sin_cliente, 'hecho sin cliente vinculado', 'hechos sin cliente vinculado') : null,
    ].filter(Boolean).join(' y ');
}

/** Aviso antes de cerrar con pendientes, igual al del servidor. */
export function pendingCloseWarning(summary) {
    const total = Number(summary?.hechos_sin_precio || 0) + Number(summary?.hechos_sin_cliente || 0);
    if (!total) return '';

    return `${total === 1 ? 'Queda' : 'Quedan'} ${pendingClosureText(summary)}: ${total === 1 ? 'no se cobrará' : 'no se cobrarán'} en este cierre.`;
}

/** Lo que se congela al cerrar el mes. */
export function closeSummary(data) {
    const summary = data?.resumen || {};

    return `Se congela ${totalsText(data?.totales)}: ${plural(summary.clientes || 0, 'cliente')} y ${plural(summary.hechos_valorizados || 0, 'hecho valorizado', 'hechos valorizados')}`
        + `${summary.ajustes ? `, más ${plural(summary.ajustes, 'ajuste')} de meses cerrados` : ''}.`;
}

/** «Ajuste de noviembre de 2026». */
export function adjustmentLabel(adjustment) {
    return `Ajuste de ${monthLabel(adjustment.mes_origen)}`;
}

/** Quién aceptó el ajuste. */
export function adjustmentNote(adjustment, timeZone) {
    return `Aceptado por ${adjustment.decidida_por || 'usuario sin nombre'} el ${formatMoment(adjustment.decidida_at, timeZone)}`;
}

/** Cantidad y días de una diferencia, con signo. */
export function differenceQuantity(values) {
    return `${signedQuantity(values.cantidad)}${values.dias ? ` · ${signedDays(values.dias)}` : ''}`;
}

/** Lo cobrado y lo de hoy en una línea con diferencia: «8.000 · $ 80.000». */
export function differenceSide(values, item) {
    if (!values) return 'Sin cobro';

    return `${quantityText(values)} · ${formatAmount({ simbolo: item.simbolo, moneda: item.moneda, monto: values.monto })}`;
}

/** Cambio de precio de la línea, si lo hubo: «Precio: $ 10 → $ 12.». */
export function priceChangeText(item) {
    if (!item?.cambio_precio) return '';
    const symbol = item.simbolo || item.moneda;

    return `Precio: ${symbol} ${formatDecimal(item.antes.precio)} → ${symbol} ${formatDecimal(item.ahora.precio)}.`;
}

/** Qué pasa al aceptar: se cobra o se abona en el primer mes abierto. */
export function acceptText(item, destination) {
    return `${isNegative(item?.diferencia?.monto) ? 'Se abonará' : 'Se cobrará'} en ${monthLabel(destination)}.`;
}

const FACT_KINDS = { nuevo: 'Nuevo', corregido: 'Corregido', retirado: 'Retirado' };

/** Un hecho que explica una diferencia. */
export function factChangeText(fact) {
    const days = fact.dias ? ` · ${signedDays(fact.dias)}` : '';
    const when = fact.desde
        ? ` · ${!fact.hasta || fact.desde === fact.hasta ? formatDay(fact.desde) : `${formatDay(fact.desde)} → ${formatDay(fact.hasta)}`}`
        : '';

    return `${FACT_KINDS[fact.tipo] || fact.tipo} · cantidad ${signedQuantity(fact.cantidad)}${days}${when}`;
}

/** Por qué cambió el hecho. */
export function factReason(fact, timeZone) {
    if (fact.tipo === 'retirado') {
        if (fact.situacion === 'anulado') return fact.motivo_version === 'eliminado' ? 'Ya no existe en la operación.' : 'Se anuló en la operación.';
        if (fact.situacion === 'otro_cliente') return fact.cliente_actual ? `Ahora es de ${fact.cliente_actual}.` : 'Ahora es de otro cliente.';
        if (fact.situacion === 'sin_version') return 'Ya no tiene una versión vigente.';

        return 'Ahora se cobra con otro precio o quedó fuera del mes.';
    }
    if (!fact.posterior_al_cierre) return 'El hecho no cambió: cambió el precio que lo cobra.';
    const when = fact.capturado_at ? ` (${formatMoment(fact.capturado_at, timeZone)})` : '';

    return fact.motivo_version === 'captura' ? `Llegó después del cierre${when}.` : `Se corrigió en la operación después del cierre${when}.`;
}

/** Una decisión sobre una diferencia, con su resultado. */
export function decisionText(decision, timeZone) {
    const who = `${decision.decidida_por || 'usuario sin nombre'} el ${formatMoment(decision.decidida_at, timeZone)}`;
    const base = decision.decision === 'aceptada'
        ? `Aceptada por ${who}: se ${isNegative(decision.monto) ? 'abona' : 'cobra'} en ${monthLabel(decision.mes_destino)}.${decision.motivo ? ` ${sentence(decision.motivo)}` : ''}`
        : `Descartada por ${who}: ${sentence(decision.motivo)}`;
    if (!decision.anulada_at) return base;

    return `${base} Deshecha por ${decision.anulada_por || 'usuario sin nombre'} el ${formatMoment(decision.anulada_at, timeZone)}: ${sentence(decision.motivo_anulacion)}`;
}

/** Meses cerrados con diferencias por revisar: «noviembre de 2026: 2 diferencias». */
export function reviewText(month) {
    return `${monthLabel(month.mes)}: ${plural(month.diferencias, 'diferencia', 'diferencias')}`;
}
