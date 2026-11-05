import test from 'node:test';
import assert from 'node:assert/strict';
import {
  EMPTY_DEW_POINT_DRAFT,
  attemptFor,
  compareSurface,
  formatHumidityLimit,
  humidityForDewPoint,
  linkCandidates,
  planForStage,
  marginForStage,
  worstState,
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
      sellado_referencia_id: null, origen_referencia_id: null,
    },
  });
  // Un sellado no lleva sellado de referencia aunque haya quedado elegido.
  const sealing = draftToReading({ ...complete, sealed: 's-1', origin: 'o-1' });
  assert.equal(sealing.reading.sellado_referencia_id, null);
  assert.equal(sealing.reading.origen_referencia_id, 'o-1');
  assert.equal(draftToReading({ ...complete, stage: 'paletizado', sealed: 's-1' }).reading.sellado_referencia_id, 's-1');
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

test('las comparaciones vinculadas usan el margen de la etapa y el rocío del otro aire', () => {
  assert.deepEqual(compareSurface(3, 4.47), { margin: -1.47, state: 'condensacion_posible' });
  assert.deepEqual(compareSurface(1, -1.14, 0.5), { margin: 2.14, state: 'margen_favorable' });
  assert.deepEqual(compareSurface(null, 4.47), { margin: null, state: 'sin_superficie' });
  assert.equal(worstState(['margen_favorable', 'condensacion_posible', 'sin_superficie']), 'condensacion_posible');

  const now = new Date('2026-11-04T15:30:00Z');
  const day = {
    parametros: { margen_minimo_c: 2, horas_referencia: 72, margenes_por_etapa: [{ etapa: 'almacenamiento', margen_minimo_c: 0.5 }] },
    referencias: [
      { id: 'futuro', etapa: 'sellado', medido_at: '2026-11-04T16:00:00Z', temperatura_superficie_c: null, punto_rocio_c: 4 },
      { id: 'sellado', etapa: 'sellado', etapa_etiqueta: 'Sellado', medido_at: '2026-11-04T12:30:00Z', temperatura_superficie_c: null, punto_rocio_c: 4.47 },
      { id: 'salida', etapa: 'salida_prefrio', etapa_etiqueta: 'Salida prefrío', medido_at: '2026-11-04T14:00:00Z', temperatura_superficie_c: 1, punto_rocio_c: -1.09 },
      { id: 'viejo', etapa: 'sellado', medido_at: '2026-10-31T12:00:00Z', temperatura_superficie_c: 9, punto_rocio_c: 5 },
    ],
  };
  assert.equal(marginForStage(day, 'almacenamiento'), 0.5);
  assert.equal(marginForStage(day, 'paletizado'), 2);
  const candidates = linkCandidates(day, now);
  assert.deepEqual(candidates.sealed.map((item) => item.id), ['sellado']);
  assert.deepEqual(candidates.origins.map((item) => item.id), ['salida']);

  // Cámara a 0 °C / 92 % con fruta a 0,2 °C: bien con 0,5 °C; el aire sellado a 4,47 °C condensa dentro de la bolsa.
  const preview = previewDraft(
    { ...EMPTY_DEW_POINT_DRAFT, stage: 'almacenamiento', ambient: '0', humidity: '92', surface: '0,2', sealed: 'sellado', origin: 'salida' },
    0.5,
    day.referencias,
  );
  assert.equal(preview.state, 'margen_favorable');
  assert.deepEqual({ margin: preview.envelope.margin, state: preview.envelope.state }, { margin: -4.27, state: 'condensacion_posible' });
  assert.deepEqual({ margin: preview.arrival.margin, state: preview.arrival.state }, { margin: 2.14, state: 'margen_favorable' });
  assert.equal(preview.worst, 'condensacion_posible');
  assert.equal(previewDraft({ ...EMPTY_DEW_POINT_DRAFT, stage: 'sellado', ambient: '12', humidity: '60', sealed: 'sellado' }, 2, day.referencias).envelope, null);
});

test('el plan de la etapa compara el rocío medido y da la HR máxima a la T° medida', () => {
  assert.equal(formatHumidityLimit(humidityForDewPoint(12, -1)), '40 %');
  assert.equal(formatHumidityLimit(humidityForDewPoint(0, -1)), '93 %');
  assert.equal(humidityForDewPoint(-1, -0.5), 100);
  const day = { plan: { etapas: [
    { etapa: 'almacenamiento', planificada: true, rocio_maximo_c: -1 },
    { etapa: 'paletizado', planificada: false, rocio_maximo_c: null },
  ] } };
  assert.equal(planForStage(day, 'paletizado'), null);
  const plan = planForStage(day, 'almacenamiento');
  const out = previewDraft({ ...EMPTY_DEW_POINT_DRAFT, stage: 'almacenamiento', ambient: '0', humidity: '95' }, 0.5, [], plan);
  assert.deepEqual({ limit: out.plan.limit, out: out.plan.out }, { limit: -1, out: true });
  const inside = previewDraft({ ...EMPTY_DEW_POINT_DRAFT, stage: 'almacenamiento', ambient: '0', humidity: '90' }, 0.5, [], plan);
  assert.equal(inside.plan.out, false);
  assert.equal(previewDraft({ ...EMPTY_DEW_POINT_DRAFT, ambient: '0', humidity: '90' }).plan, null);
});
