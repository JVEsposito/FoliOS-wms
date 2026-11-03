import assert from 'node:assert/strict';
import test from 'node:test';
import { packingReturnContainerPayload, packingReturnUnits, packingReturnSummary } from '../src/domain/packingReturnContainers.ts';

test('las cajas llevan cantidad propia y no crean bins retornados', () => {
  const result = packingReturnContainerPayload('caja_3_4', 24);
  assert.equal(result.tipo_envase, 'caja_3_4');
  assert.equal(result.cantidad_envases, 24);
  assert.equal(result.cantidad_bins, 0);
  assert.equal(packingReturnUnits(result), '24 CAJAS 3/4');
});

test('los bins conservan la cantidad anterior y los registros previos se pueden presentar', () => {
  assert.deepEqual(packingReturnContainerPayload('bins', 3), { tipo_envase: 'bins', cantidad_envases: 3, cantidad_bins: 3 });
  assert.equal(packingReturnUnits({ cantidad_bins: 3 }), '3 BINS');
  assert.equal(packingReturnSummary({ bins_retornados: 3, cajas_3_4_retornadas: 24 }), '3 BINS · 24 CAJAS 3/4');
});

test('el formulario impide cantidades inválidas y envases desconocidos', () => {
  for (const quantity of [0, -1, 1.5, Infinity, NaN, 100001]) assert.throws(() => packingReturnContainerPayload('caja_3_4', quantity));
  assert.throws(() => packingReturnContainerPayload('otro', 2));
});
