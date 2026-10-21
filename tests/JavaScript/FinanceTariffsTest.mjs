import assert from 'node:assert/strict';
import test from 'node:test';

import {
    CURRENCY_PRESETS,
    eventLines,
    formatDecimal,
    formatPrice,
    parsePriceInput,
    summaryText,
    unitsFor,
    validityText,
} from '../../resources/js/shared/finance-tariffs-logic.js';

test('los precios se muestran con separadores de Chile y sin perder decimales', () => {
    assert.equal(formatDecimal('1234.5'), '1.234,5');
    assert.equal(formatDecimal('0.0125'), '0,0125');
    assert.equal(formatDecimal('999999999999.999999'), '999.999.999.999,999999');
    assert.equal(formatPrice({ moneda: 'CLP', moneda_simbolo: '$', precio: '1650.5' }), '$ 1.650,5');
    assert.equal(formatPrice({ moneda: 'UF', moneda_simbolo: 'UF', precio: '0.35' }), 'UF 0,35');
    assert.equal(formatPrice({ moneda: 'USD', precio: '12' }), 'USD 12', 'Sin símbolo, usa el código.');
});

test('el precio escrito se lee sin dudas', () => {
    assert.deepEqual(parsePriceInput('0,35'), { ok: true, value: '0.35' });
    assert.deepEqual(parsePriceInput(' 1500,50 '), { ok: true, value: '1500.5' });
    assert.deepEqual(parsePriceInput('1.5'), { ok: true, value: '1.5' });
    assert.deepEqual(parsePriceInput('0.350'), { ok: true, value: '0.35' });
    assert.deepEqual(parsePriceInput('012'), { ok: true, value: '12' });
    assert.deepEqual(parsePriceInput('0'), { ok: true, value: '0' });

    // «1.500» podría ser mil quinientos: se pide escribirlo sin puntos de miles.
    assert.equal(parsePriceInput('1.500').ok, false);
    assert.match(parsePriceInput('12.000').message, /sin puntos de miles/);
    assert.equal(parsePriceInput('1.234,5').ok, false);
    assert.deepEqual(parsePriceInput('-5'), { ok: true, value: '-5' });
    assert.deepEqual(parsePriceInput('-0003,500'), { ok: true, value: '-3.5' });
    assert.deepEqual(parsePriceInput('-0,000'), { ok: true, value: '0' });
    assert.equal(parsePriceInput('-1.500').ok, false);
    assert.equal(parsePriceInput('abc').ok, false);
    assert.equal(parsePriceInput('0,1234567').message, 'El precio admite hasta 6 decimales.');
    assert.equal(parsePriceInput('').message, 'Indique el precio.');
});

test('la vigencia y las unidades de cobro se describen en palabras', () => {
    assert.equal(validityText('2026-11-01', null), '01-11-2026 → sin término');
    assert.equal(validityText('2026-11-01', '2027-03-31'), '01-11-2026 → 31-03-2027');

    const services = [{ clave: 'estadia', unidades: [{ clave: 'cajas', nombre: 'por caja y día' }] }];
    assert.deepEqual(unitsFor(services, 'estadia'), [{ clave: 'cajas', nombre: 'por caja y día' }]);
    assert.deepEqual(unitsFor(services, 'despacho'), []);
});

test('el historial muestra los cambios en un orden fijo, aunque la base los reordene', () => {
    assert.deepEqual(eventLines({
        accion: 'modificada',
        cambios: {
            nota: { antes: null, despues: 'Contrato 2026-2027' },
            precio: { antes: '1500', despues: '1650.5' },
            vigente_hasta: { antes: null, despues: '2027-03-31' },
        },
    }), [
        'Precio: 1.500 → 1.650,5',
        'Vigente hasta: sin término → 31-03-2027',
        'Nota: — → Contrato 2026-2027',
    ]);
    assert.deepEqual(eventLines({
        accion: 'creada',
        cambios: { cliente: 'Tarifa general', servicio: 'Prefrío', unidad: 'por caja', moneda: 'CLP', precio: '30', vigente_desde: '2026-11-01', vigente_hasta: null },
    }), ['Tarifa general · Prefrío por caja · CLP 30 · 01-11-2026 → sin término']);
    assert.deepEqual(eventLines({ accion: 'anulada', cambios: [] }), []);
});

test('el resumen del tarifario va en singular o plural', () => {
    assert.equal(
        summaryText({ vigentes: 1, futuras: 0, clientes_con_precio_propio: 1, servicios_en_tarifa_general: 3 }, 7),
        '1 precio vigente · 1 cliente con precio propio · tarifa general en 3 de 7 servicios',
    );
    assert.equal(
        summaryText({ vigentes: 6, futuras: 2, clientes_con_precio_propio: 0, servicios_en_tarifa_general: 7 }, 7),
        '6 precios vigentes y 2 programados · 0 clientes con precio propio · tarifa general en 7 de 7 servicios',
    );
});

test('las monedas sugeridas son las de la planta y se pueden editar al agregarlas', () => {
    assert.deepEqual(CURRENCY_PRESETS.map((currency) => currency.codigo), ['CLP', 'UF', 'USD']);
    assert.equal(CURRENCY_PRESETS.find((currency) => currency.codigo === 'CLP').decimales, 0);
});
