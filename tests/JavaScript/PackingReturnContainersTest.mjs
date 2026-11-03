import assert from 'node:assert/strict';
import test from 'node:test';
import { packingReturnQuantity, packingReturnTotals } from '../../resources/js/shared/packing-return-containers.js';

test('Oficina identifica una agrupación de cajas con cantidad propia', () => {
    assert.equal(packingReturnQuantity({ tipo_envase: 'caja_3_4', cantidad_envases: 24, cantidad_bins: 0 }), '24 CAJAS 3/4');
    assert.equal(packingReturnQuantity({ cantidad_bins: 2 }), '2 BINS');
    assert.equal(packingReturnQuantity({}), '1 BINS');
});

test('los totales conservan ambas unidades sin sumarlas entre sí', () => {
    assert.equal(packingReturnTotals({ bins_retornados: 2, cajas_3_4_retornadas: 24 }), '2 BINS · 24 CAJAS 3/4');
    assert.equal(packingReturnTotals({ bins_retornados: 2 }), '2 BINS');
});
