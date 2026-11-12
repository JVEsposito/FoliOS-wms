import test from 'node:test';
import assert from 'node:assert/strict';
import { containersWithFruit, emptyCountProblem, segmentEmptiesProblem } from '../src/domain/validationMp.ts';

const label = (type) => ({ bins: 'Bins plástico', totes: 'Tote' })[type] ?? type;

test('los vacíos se descuentan de lo contado para el peso promedio', () => {
  assert.equal(containersWithFruit({ tipo_envase: 'totes', cantidad_validada: 864, cantidad_vacia: 24 }), 840);
  assert.equal(containersWithFruit({ tipo_envase: 'bins', cantidad_validada: 36 }), 36);
  assert.equal(emptyCountProblem([{ tipo_envase: 'totes', cantidad_validada: 20, cantidad_vacia: 21 }], label), 'Tote: los vacíos no pueden superar los contados.');
  assert.equal(emptyCountProblem([{ tipo_envase: 'totes', cantidad_validada: 20, cantidad_vacia: 20 }], label), null);
});

test('con segmentos, los vacíos se reparten y suman el total contado', () => {
  const containers = [{ tipo_envase: 'bins', cantidad_validada: 36 }, { tipo_envase: 'totes', cantidad_validada: 864, cantidad_vacia: 24 }];
  const segment = (totes, vacios) => ({ key: totes, motivos: ['csg'], csg_validacion_id: 'c', variedad_validacion_id: 'v', cuartel: '', bins_planta: null, cantidades: { bins: '18', totes: String(totes) }, vacios: { totes: String(vacios) } });
  assert.equal(segmentEmptiesProblem(containers, [segment(432, 4), segment(432, 20)], label), null);
  assert.equal(segmentEmptiesProblem(containers, [segment(432, 4), segment(432, 0)], label), 'Tote: reparte los 24 vacíos entre los segmentos (van 4).');
  assert.equal(segmentEmptiesProblem(containers, [segment(10, 24), segment(854, 0)], label), 'Segmento 1: los vacíos de tote no pueden superar sus contados.');
  // Sin vacíos no hay nada que repartir, aunque los segmentos no traigan el campo.
  assert.equal(segmentEmptiesProblem([{ tipo_envase: 'bins', cantidad_validada: 2 }], [{ cantidades: { bins: '2' } }], label), null);
});
