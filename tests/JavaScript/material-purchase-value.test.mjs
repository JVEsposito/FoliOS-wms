import test from 'node:test';
import assert from 'node:assert/strict';
import { purchasePrice, purchaseTotal, purchaseAmount } from '../../resources/js/shared/material-purchase-value.js';

test('precios chilenos sin miles distinguen pendiente de cero y conservan seis decimales', () => {
    assert.equal(purchasePrice(''), null);
    assert.equal(purchasePrice('0'), '0.000000');
    assert.equal(purchasePrice(' 123,456789 '), '123.456789');
    for (const invalid of ['-1', '1e3', 'NaN', '1.234,50', '1,1234567', '1000000000']) assert.throws(() => purchasePrice(invalid));
});

test('total exacto redondea por moneda y soporta importes mayores al entero seguro', () => {
    assert.equal(purchaseTotal('10.000', '123.456789', 'CLP'), '1235');
    assert.equal(purchaseTotal('10.000', '1.2345', 'USD'), '12.35');
    assert.equal(purchaseTotal('99999999999.999', '999999999.999999', 'EUR'), '99999999999998900000.00');
    assert.equal(purchaseTotal('0.001', '5', 'USD'), '0.01');
    assert.equal(purchaseTotal('10', '0', 'CLP'), '0');
    assert.equal(purchaseTotal('10', null, 'CLP'), null);
    assert.equal(purchaseAmount('12345678901234567.120000'), '12.345.678.901.234.567,12');
});
