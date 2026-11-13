import test from 'node:test';
import assert from 'node:assert/strict';
import {
  R41_SENSOR_COUNT,
  emptyR41Draft,
  isCompleteR41Reading,
  parseR41Draft,
  r41FinalReadingRequired,
  r41MissingMessage,
  r41ReadingStatus,
  r41ReadingSummary,
  r41SectorsOf,
  updateR41Draft,
} from '../src/domain/prefrioR41.ts';

const sectors = ['ventilador', 'medio', 'puerta'];
const points = ['izq_ex', 'izq_in', 'der_in', 'der_ex'];

function fullDraft(value = '0,4') {
  let draft = emptyR41Draft();
  for (const sector of sectors) for (const point of points) draft = updateR41Draft(draft, sector, point, value);
  return draft;
}

function reading(at, sectores) {
  return { id: at, operacion_id: at, tipo: 'lectura', ocurrido_at: at, datos: { sectores }, observacion: null, usuario: null, dispositivo: null };
}

test('la grilla R-41 tiene 3 sectores con exterior e interior de cada lado', () => {
  const draft = emptyR41Draft();
  assert.equal(R41_SENSOR_COUNT, 12);
  assert.deepEqual(Object.keys(draft), sectors);
  assert.deepEqual(Object.keys(draft.medio), points);
  const changed = updateR41Draft(draft, 'medio', 'der_in', '1');
  assert.equal(changed.medio.der_in, '1');
  assert.equal(draft.medio.der_in, '', 'no modifica el borrador original');
});

test('una lectura horaria parcial se envía con los sensores vacíos en null', () => {
  let draft = updateR41Draft(emptyR41Draft(), 'ventilador', 'izq_ex', '-0,46');
  draft = updateR41Draft(draft, 'puerta', 'der_ex', ' 2.0 ');
  const parsed = parseR41Draft(draft);
  assert.equal(parsed.ok, true);
  assert.equal(parsed.filled, 2);
  assert.equal(parsed.missing, 10);
  assert.equal(parsed.sectores.ventilador.izq_ex, -0.5);
  assert.equal(parsed.sectores.puerta.der_ex, 2);
  assert.equal(parsed.sectores.medio.izq_in, null);
  assert.equal(isCompleteR41Reading(parsed.sectores), false);
});

test('rechaza valores fuera de rango o que no son números', () => {
  assert.deepEqual(parseR41Draft(updateR41Draft(emptyR41Draft(), 'medio', 'izq_in', '45')), {
    ok: false, message: 'Medio izq. int.: la temperatura debe estar entre -10 y 40 °C.',
  });
  assert.equal(parseR41Draft(updateR41Draft(emptyR41Draft(), 'puerta', 'der_ex', '1,2,3')).ok, false);
  assert.equal(parseR41Draft(updateR41Draft(emptyR41Draft(), 'puerta', 'der_ex', 'abc')).ok, false);
});

test('la lectura final completa lleva los 12 sensores', () => {
  const parsed = parseR41Draft(fullDraft());
  assert.equal(parsed.missing, 0);
  assert.equal(isCompleteR41Reading(parsed.sectores), true);
  assert.equal(r41MissingMessage(11), 'La lectura final lleva los 12 sensores: faltan 11.');
  assert.equal(r41MissingMessage(1), 'La lectura final lleva los 12 sensores: falta 1.');
});

test('la próxima lectura vence una hora después de la última o del inicio', () => {
  const process = { estado: 'en_proceso', iniciado_at: '2026-11-12T12:00:00.000Z', eventos: [] };
  const sinLecturas = r41ReadingStatus(process, Date.parse('2026-11-12T12:59:00.000Z'));
  assert.equal(sinLecturas.nextReadingAt, '2026-11-12T13:00:00.000Z');
  assert.equal(sinLecturas.overdue, false);
  assert.equal(r41FinalReadingRequired(sinLecturas), true);

  const parcial = parseR41Draft(updateR41Draft(emptyR41Draft(), 'medio', 'izq_in', '1')).sectores;
  const conLectura = { ...process, eventos: [reading('2026-11-12T13:05:00.000Z', parcial)] };
  const vencida = r41ReadingStatus(conLectura, Date.parse('2026-11-12T14:20:00.000Z'));
  assert.equal(vencida.readings, 1);
  assert.equal(vencida.nextReadingAt, '2026-11-12T14:05:00.000Z');
  assert.equal(vencida.overdue, true);
  assert.equal(vencida.minutesLate, 15);
  assert.equal(vencida.lastReadingComplete, false);
});

test('una lectura horaria completa sirve de lectura final y el servidor fija el intervalo', () => {
  const completa = parseR41Draft(fullDraft('-0,8')).sectores;
  const process = {
    estado: 'en_proceso', iniciado_at: '2026-11-12T12:00:00.000Z',
    eventos: [reading('2026-11-12T14:00:00.000Z', completa), reading('2026-11-12T13:00:00.000Z', null)],
    registro_r41: { intervalo_lectura_minutos: 30, exige_lectura_final: true },
  };
  const status = r41ReadingStatus(process, Date.parse('2026-11-12T14:10:00.000Z'));
  assert.equal(status.lastReadingAt, '2026-11-12T14:00:00.000Z');
  assert.equal(status.nextReadingAt, '2026-11-12T14:30:00.000Z');
  assert.equal(status.lastReadingComplete, true);
  assert.equal(r41FinalReadingRequired(status), false);
  assert.equal(r41FinalReadingRequired(r41ReadingStatus({ ...process, eventos: [], registro_r41: { exige_lectura_final: false } })), false);
});

test('fuera del enfriado no hay recordatorio horario', () => {
  const status = r41ReadingStatus({ estado: 'listo_para_iniciar', iniciado_at: null, eventos: [] }, Date.parse('2026-11-12T14:10:00.000Z'));
  assert.equal(status.nextReadingAt, null);
  assert.equal(status.overdue, false);
});

test('la línea de tiempo resume los sensores de la lectura y de la lectura final', () => {
  const parcial = parseR41Draft(updateR41Draft(updateR41Draft(emptyR41Draft(), 'medio', 'izq_in', '-0,5'), 'puerta', 'der_ex', '1,3')).sectores;
  assert.equal(r41ReadingSummary(r41SectorsOf(reading('2026-11-12T13:00:00.000Z', parcial))), '2 de 12 sensores · -0,5 a 1,3 °C');
  const final = { id: 'v', operacion_id: 'v', tipo: 'verificacion_final', ocurrido_at: '2026-11-12T15:00:00.000Z', datos: { datos: { lectura_final: parseR41Draft(fullDraft('0')).sectores } }, observacion: null, usuario: null, dispositivo: null };
  assert.equal(r41ReadingSummary(r41SectorsOf(final)), '12 de 12 sensores · 0,0 °C');
  assert.equal(r41ReadingSummary(r41SectorsOf({ ...final, tipo: 'pausa', datos: {} })), null);
});
