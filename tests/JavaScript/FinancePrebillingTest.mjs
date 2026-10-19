import assert from 'node:assert/strict';
import test from 'node:test';

import {
    acceptText,
    adjustmentLabel,
    adjustmentNote,
    clientSummary,
    closeSummary,
    closureCaptureText,
    closureText,
    countText,
    decisionText,
    detailText,
    differenceQuantity,
    differenceSide,
    factChangeText,
    factReason,
    formatAmount,
    formatMoment,
    monthLabel,
    pendingCloseWarning,
    pendingText,
    periodText,
    priceChangeText,
    priceOrigin,
    quantityText,
    reopeningText,
    reviewText,
    signedAmount,
    signedQuantity,
    totalsText,
} from '../../resources/js/shared/finance-prebilling-logic.js';

const ZONA = 'America/Santiago';

test('el mes y los montos se leen en español de Chile', () => {
    assert.equal(monthLabel('2026-11'), 'noviembre de 2026');
    assert.equal(formatAmount({ simbolo: '$', monto: '105506' }), '$ 105.506');
    assert.equal(formatAmount({ simbolo: 'UF', monto: '1.50' }), 'UF 1,50', 'Conserva los decimales de su moneda.');
    assert.equal(totalsText([{ simbolo: '$', monto: '38' }, { simbolo: 'UF', monto: '1.50' }]), '$ 38 · UF 1,50');
    assert.equal(totalsText([]), 'Sin montos');
});

test('el período dice si el mes está completo, a la fecha o todavía no empieza', () => {
    const periodo = { mes: '2026-11', inicio: '2026-11-01', fin: '2026-11-30' };
    assert.equal(periodText({ periodo, corte: '2026-11-30', a_la_fecha: false }), 'noviembre de 2026: mes completo, del 01-11-2026 al 30-11-2026.');
    assert.equal(periodText({ periodo, corte: '2026-11-15', a_la_fecha: true }), 'noviembre de 2026: a la fecha, valorizado hasta el 15-11-2026. El mes aún no termina.');
    assert.equal(periodText({ periodo, corte: null, a_la_fecha: false }), 'noviembre de 2026: mes futuro, todavía sin hechos que valorizar.');
    assert.equal(periodText({ periodo, corte: '2026-11-30', a_la_fecha: false, estado: 'cerrado' }), 'noviembre de 2026: mes cerrado, del 01-11-2026 al 30-11-2026.');
});

test('las líneas muestran cantidad, días y el origen del precio', () => {
    assert.equal(quantityText({ cantidad: '600', dias: 6 }), '600 · 6 días');
    assert.equal(quantityText({ cantidad: '36000', dias: 36000 }), '36.000 · 36.000 días', 'Los conteos también llevan separador de miles.');
    assert.equal(countText(4000, 'hecho'), '4.000 hechos');
    assert.equal(countText(1, 'hecho queda pendiente', 'hechos quedan pendientes'), '1 hecho queda pendiente');
    assert.equal(quantityText({ cantidad: '9500.5', dias: 0 }), '9.500,5');
    assert.equal(priceOrigin({ origen: 'propio', vigente_desde: '2026-11-01' }), 'Precio propio · desde el 01-11-2026');
    assert.equal(priceOrigin({ origen: 'general', vigente_desde: '2026-11-16' }), 'Tarifa general · desde el 16-11-2026');
    assert.equal(clientSummary({ lineas: [{}, {}], sin_precio: [{ hechos: 1 }] }), '2 líneas · 1 hecho sin precio');
    assert.equal(clientSummary({ lineas: [{}], sin_precio: [] }), '1 línea');
    assert.equal(clientSummary({ lineas: [], ajustes: [{}], sin_precio: [] }), '0 líneas · 1 ajuste');
    assert.equal(pendingText({ nombre: 'Pallets despachados', hechos: 3 }), 'Pallets despachados: 3 hechos sin precio');
});

test('el detalle de un hecho muestra su fecha o los días cobrados de la estadía', () => {
    assert.equal(detailText({ desde: '2026-11-03', hasta: '2026-11-03', dias: 0, cantidad: '8000' }), '03-11-2026 · cantidad 8.000');
    assert.equal(detailText({ desde: '2026-11-10', hasta: '2026-11-20', dias: 11, cantidad: '11' }), '10-11-2026 → 20-11-2026 · 11 días · cantidad 11');
    assert.equal(detailText({ desde: '2026-11-11', hasta: '2026-11-11', dias: 1, cantidad: '120' }), '11-11-2026 · 1 día · cantidad 120');
});

test('las diferencias se leen con signo: lo que se cobra y lo que se abona', () => {
    assert.equal(signedQuantity('1000'), '+1.000');
    assert.equal(signedQuantity('-8000'), '−8.000');
    assert.equal(signedQuantity('0'), '0');
    assert.equal(signedAmount({ simbolo: '$', monto: '10000' }), '+$ 10.000');
    assert.equal(signedAmount({ simbolo: '$', monto: '-96000' }), '−$ 96.000');
    assert.equal(signedAmount({ simbolo: 'UF', monto: '-1.50' }), '−UF 1,50');
    assert.equal(signedAmount({ simbolo: '$', monto: '0' }), '$ 0');
    assert.equal(differenceQuantity({ cantidad: '1', dias: 1 }), '+1 · +1 día');
    assert.equal(differenceQuantity({ cantidad: '-120', dias: -2 }), '−120 · −2 días');
    const item = { simbolo: '$', moneda: 'CLP', diferencia: { monto: '-16000' }, cambio_precio: true, antes: { precio: '10' }, ahora: { precio: '12.5' } };
    assert.equal(differenceSide({ cantidad: '8000', dias: 0, monto: '80000' }, item), '8.000 · $ 80.000');
    assert.equal(differenceSide(null, item), 'Sin cobro');
    assert.equal(priceChangeText(item), 'Precio: $ 10 → $ 12,5.');
    assert.equal(priceChangeText({ ...item, cambio_precio: false }), '');
    assert.equal(acceptText(item, '2026-12'), 'Se abonará en diciembre de 2026.');
    assert.equal(acceptText({ diferencia: { monto: '300' } }, '2027-01'), 'Se cobrará en enero de 2027.');
});

test('el estado del cierre dice quién cerró, con qué lectura y por qué no se puede cerrar', () => {
    assert.equal(formatMoment('2026-12-05T15:20:00+00:00', ZONA), '05-12-2026 12:20');
    const closed = { estado: 'cerrado', cierre: { cerrado_at: '2026-12-05T15:20:00+00:00', cerrado_por: 'Administración Demo', version: 2, lectura_at: '2026-12-05T15:00:00+00:00' } };
    assert.equal(closureText(closed, ZONA), 'Cerrado el 05-12-2026 12:20 por Administración Demo · versión 2');
    assert.equal(closureCaptureText(closed.cierre, ZONA), 'Se congeló con la lectura de la operación del 05-12-2026 12:00. Lo posterior se revisa como diferencia.');
    assert.equal(closureText({ estado: 'abierto', para_cerrar: { posible: true } }), 'Mes terminado, sin cerrar.');
    assert.equal(closureText({ estado: 'abierto', para_cerrar: { posible: false, motivo: 'El mes aún no termina: se puede cerrar desde el 01-01-2027.' } }), 'El mes aún no termina: se puede cerrar desde el 01-01-2027.');
    assert.equal(
        reopeningText({ version: 1, reabierto_at: '2026-12-06T13:00:00+00:00', reabierto_por: 'Administración Demo', motivo_reapertura: 'Faltaba la recepción del día 30' }, ZONA),
        'La versión 1 se reabrió el 06-12-2026 10:00 (Administración Demo): Faltaba la recepción del día 30.',
    );
    assert.equal(pendingCloseWarning({ hechos_sin_precio: 1, hechos_sin_cliente: 0 }), 'Queda 1 hecho sin precio: no se cobrará en este cierre.');
    assert.equal(pendingCloseWarning({ hechos_sin_precio: 1, hechos_sin_cliente: 1 }), 'Quedan 1 hecho sin precio y 1 hecho sin cliente vinculado: no se cobrarán en este cierre.');
    assert.equal(pendingCloseWarning({ hechos_sin_precio: 0, hechos_sin_cliente: 0 }), '');
    assert.equal(
        closeSummary({ totales: [{ simbolo: '$', monto: '80300' }], resumen: { clientes: 2, hechos_valorizados: 1500, ajustes: 1 } }),
        'Se congela $ 80.300: 2 clientes y 1.500 hechos valorizados, más 1 ajuste de meses cerrados.',
    );
    assert.equal(reviewText({ mes: '2026-11', diferencias: 2 }), 'noviembre de 2026: 2 diferencias');
});

test('cada hecho de una diferencia dice qué cambió y por qué', () => {
    const nuevo = { tipo: 'nuevo', cantidad: '1000', dias: 0, desde: '2026-11-20', hasta: '2026-11-20', posterior_al_cierre: true, motivo_version: 'captura', capturado_at: '2026-12-05T15:20:00+00:00' };
    assert.equal(factChangeText(nuevo), 'Nuevo · cantidad +1.000 · 20-11-2026');
    assert.equal(factReason(nuevo, ZONA), 'Llegó después del cierre (05-12-2026 12:20).');
    const corregido = { ...nuevo, tipo: 'corregido', cantidad: '1', dias: 1, desde: '2026-11-10', hasta: '2026-11-13', motivo_version: 'cambio' };
    assert.equal(factChangeText(corregido), 'Corregido · cantidad +1 · +1 día · 10-11-2026 → 13-11-2026');
    assert.equal(factReason(corregido, ZONA), 'Se corrigió en la operación después del cierre (05-12-2026 12:20).');
    assert.equal(factReason({ ...nuevo, posterior_al_cierre: false }), 'El hecho no cambió: cambió el precio que lo cobra.');
    const retirado = { tipo: 'retirado', cantidad: '-8000', dias: 0, desde: null };
    assert.equal(factChangeText(retirado), 'Retirado · cantidad −8.000');
    assert.equal(factReason({ ...retirado, situacion: 'anulado', motivo_version: 'anulacion' }), 'Se anuló en la operación.');
    assert.equal(factReason({ ...retirado, situacion: 'anulado', motivo_version: 'eliminado' }), 'Ya no existe en la operación.');
    assert.equal(factReason({ ...retirado, situacion: 'otro_cliente', cliente_actual: 'EXPORTADORA NORTE' }), 'Ahora es de EXPORTADORA NORTE.');
    assert.equal(factReason({ ...retirado, situacion: 'otro_precio' }), 'Ahora se cobra con otro precio o quedó fuera del mes.');
});

test('las decisiones y los ajustes cuentan quién decidió y dónde se cobra', () => {
    const accepted = { decision: 'aceptada', monto: '10000', mes_destino: '2026-12', decidida_por: 'Administración Demo', decidida_at: '2026-12-05T15:30:00+00:00', motivo: null };
    assert.equal(decisionText(accepted, ZONA), 'Aceptada por Administración Demo el 05-12-2026 12:30: se cobra en diciembre de 2026.');
    assert.equal(decisionText({ ...accepted, monto: '-300', motivo: 'Corrección de salida' }, ZONA), 'Aceptada por Administración Demo el 05-12-2026 12:30: se abona en diciembre de 2026. Corrección de salida.');
    const discarded = { ...accepted, decision: 'descartada', mes_destino: null, motivo: 'Se cobró con nota de débito.' };
    assert.equal(decisionText(discarded, ZONA), 'Descartada por Administración Demo el 05-12-2026 12:30: Se cobró con nota de débito.');
    assert.equal(
        decisionText({ ...accepted, anulada_at: '2026-12-06T12:00:00+00:00', anulada_por: 'Administración Demo', motivo_anulacion: 'Se aceptó por error' }, ZONA),
        'Aceptada por Administración Demo el 05-12-2026 12:30: se cobra en diciembre de 2026. Deshecha por Administración Demo el 06-12-2026 09:00: Se aceptó por error.',
    );
    assert.equal(adjustmentLabel({ mes_origen: '2026-11' }), 'Ajuste de noviembre de 2026');
    assert.equal(adjustmentNote({ decidida_por: 'Administración Demo', decidida_at: '2026-12-05T15:30:00+00:00' }, ZONA), 'Aceptado por Administración Demo el 05-12-2026 12:30');
});
