import assert from 'node:assert/strict';
import test from 'node:test';

import {
    clientSummary,
    countText,
    detailText,
    formatAmount,
    monthLabel,
    pendingText,
    periodText,
    priceOrigin,
    quantityText,
    totalsText,
} from '../../resources/js/shared/finance-prebilling-logic.js';

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
    assert.equal(pendingText({ nombre: 'Pallets despachados', hechos: 3 }), 'Pallets despachados: 3 hechos sin precio');
});

test('el detalle de un hecho muestra su fecha o los días cobrados de la estadía', () => {
    assert.equal(detailText({ desde: '2026-11-03', hasta: '2026-11-03', dias: 0, cantidad: '8000' }), '03-11-2026 · cantidad 8.000');
    assert.equal(detailText({ desde: '2026-11-10', hasta: '2026-11-20', dias: 11, cantidad: '11' }), '10-11-2026 → 20-11-2026 · 11 días · cantidad 11');
    assert.equal(detailText({ desde: '2026-11-11', hasta: '2026-11-11', dias: 1, cantidad: '120' }), '11-11-2026 · 1 día · cantidad 120');
});
