import test from 'node:test';
import assert from 'node:assert/strict';
import {
  EMPTY_DEW_POINT_DRAFT,
  attemptFor,
  dewPoint,
  dewPointState,
  draftToReading,
  evaluateReading,
  formatCelsius,
  parseDecimal,
  previewDraft,
  waterVapor,
} from '../src/domain/dewPoint.ts';

test('el punto de rocío y el vapor coinciden con la calculadora de cerezas', () => {
  assert.ok(Math.abs(dewPoint(15, 62.9) - 7.989156882502499) < 1e-9);
  assert.ok(Math.abs(waterVapor(15, 62.9) - 8.050930979414808) < 1e-9);
  assert.ok(Math.abs(dewPoint(12, 45) - 0.4251002191988776) < 1e-9);
  assert.equal(dewPoint(15, 0), null);
  assert.equal(dewPoint(15, 101), null);
});

test('la evaluación redondea como el servidor y clasifica el margen', () => {
  assert.deepEqual(evaluateReading(15, 62.9, 7.1), { dewPoint: 7.99, margin: -0.89, vapor: 8.05, state: 'condensacion_posible' });
  assert.equal(evaluateReading(15, 62.9, 8.8).state, 'margen_bajo');
  assert.equal(evaluateReading(15, 62.9, 10).state, 'margen_favorable');
  assert.equal(evaluateReading(15, 62.9, null).state, 'sin_superficie');
  assert.equal(dewPointState(1.5, 1), 'margen_favorable', 'Respeta el margen mínimo configurado');
});

test('la tablet acepta coma decimal y distingue vacío de inválido', () => {
  assert.equal(parseDecimal(' 7,1 '), 7.1);
  assert.equal(parseDecimal('-0.5'), -0.5);
  assert.equal(parseDecimal(''), null);
  assert.ok(Number.isNaN(parseDecimal('7,1,2')));
  assert.ok(Number.isNaN(parseDecimal('abc')));
});

test('la vista previa aparece solo con T° y humedad válidas', () => {
  assert.equal(previewDraft({ ...EMPTY_DEW_POINT_DRAFT, ambient: '15' }), null);
  assert.equal(previewDraft({ ...EMPTY_DEW_POINT_DRAFT, ambient: '15', humidity: '0' }), null);
  assert.equal(previewDraft({ ...EMPTY_DEW_POINT_DRAFT, ambient: '15', humidity: '62,9', surface: 'x' }).state, 'sin_superficie');
  assert.equal(previewDraft({ ...EMPTY_DEW_POINT_DRAFT, ambient: '15', humidity: '62,9', surface: '7,1' }).state, 'condensacion_posible');
});

test('el formulario exige etapa, punto, T° y humedad antes de guardar', () => {
  const complete = { ...EMPTY_DEW_POINT_DRAFT, stage: 'sellado', point: 'sala:abc', ambient: '15', humidity: '62,9', surface: '', reference: '  L-12 ', note: ' ' };
  assert.deepEqual(draftToReading({ ...complete, stage: null }), { ok: false, error: 'Selecciona la etapa.' });
  assert.deepEqual(draftToReading({ ...complete, point: null }), { ok: false, error: 'Selecciona dónde se tomó la medición.' });
  assert.match(draftToReading({ ...complete, ambient: '' }).error, /temperatura del ambiente/);
  assert.match(draftToReading({ ...complete, humidity: '120' }).error, /humedad relativa/);
  assert.match(draftToReading({ ...complete, surface: '7,1,2' }).error, /T° superficial/);
  assert.deepEqual(draftToReading(complete), {
    ok: true,
    reading: {
      etapa: 'sellado', punto_tipo: 'sala', punto_id: 'abc', referencia: 'L-12', temperatura_ambiente_c: 15, humedad_relativa: 62.9,
      temperatura_superficie_c: null, gotas_visibles: 'pendiente', observacion: null,
    },
  });
});

test('un reintento sin cambios reutiliza operacion_id y hora; con cambios es otra medición', () => {
  const reading = draftToReading({ ...EMPTY_DEW_POINT_DRAFT, stage: 'sellado', point: 'tunel:t1', ambient: '4', humidity: '88' }).reading;
  let ids = 0;
  const createId = () => `op-${++ids}`;
  const first = attemptFor(null, reading, createId, () => new Date('2026-11-04T15:30:00Z'));
  assert.equal(first.payload.operacion_id, 'op-1');
  assert.equal(first.payload.medido_at, '2026-11-04T15:30:00.000Z');
  const retry = attemptFor(first, { ...reading }, createId, () => new Date('2026-11-04T15:35:00Z'));
  assert.equal(retry, first);
  const changed = attemptFor(first, { ...reading, humedad_relativa: 90 }, createId, () => new Date('2026-11-04T15:36:00Z'));
  assert.equal(changed.payload.operacion_id, 'op-2');
  assert.equal(changed.payload.medido_at, '2026-11-04T15:36:00.000Z');
});

test('las temperaturas se muestran con un decimal y coma', () => {
  assert.equal(formatCelsius(7.99), '8,0 °C');
  assert.equal(formatCelsius(-0.89), '-0,9 °C');
  assert.equal(formatCelsius(null), '—');
});
