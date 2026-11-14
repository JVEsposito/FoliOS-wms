import assert from 'node:assert/strict';
import test from 'node:test';
import { buildPatioFinish, buildPatioStart, isPatioCycle, localDateTime } from '../src/domain/hidrocoolerMp.ts';

const now = new Date(2026, 10, 14, 8, 30);
const inicio = localDateTime(new Date(2026, 10, 14, 7, 50));

test('registro de patio: inicio con tiempos, temperaturas y estado del agua, sin cloro ni turno', () => {
  const payload = buildPatioStart({ equipo: ' HIDRO-01 ', inicio_at: inicio, temperatura_inicial_c: '18,5', temperatura_agua_inicial_c: '',
    condicion_visual_agua: 'conforme', observacion_inicio: '', cloro_libre_ppm: '95', turno: 'A' }, 'op-1', now);
  assert.deepEqual(Object.keys(payload).sort(), ['condicion_visual_agua', 'equipo', 'inicio_at', 'modalidad', 'observacion_inicio', 'operacion_id', 'temperatura_agua_inicial_c', 'temperatura_inicial_c']);
  assert.equal(payload.modalidad, 'patio');
  assert.equal(payload.equipo, 'HIDRO-01');
  assert.equal(payload.temperatura_inicial_c, 18.5);
  assert.equal(payload.temperatura_agua_inicial_c, null);
});

test('registro de patio: con agua no conforme o sin temperatura no se inicia', () => {
  const base = { equipo: 'HIDRO-01', inicio_at: inicio, temperatura_inicial_c: '18', condicion_visual_agua: 'conforme' };
  assert.throws(() => buildPatioStart({ ...base, condicion_visual_agua: 'no_conforme' }, 'op', now), /agua no conforme/);
  assert.throws(() => buildPatioStart({ ...base, condicion_visual_agua: '' }, 'op', now), /Estado del agua/);
  assert.throws(() => buildPatioStart({ ...base, temperatura_inicial_c: '' }, 'op', now), /Temperatura de fruta/);
  assert.throws(() => buildPatioStart({ ...base, inicio_at: localDateTime(new Date(2026, 10, 14, 9, 0)) }, 'op', now), /no sea futura/);
});

test('registro de patio: el término no pide cloro, pH ni dosificador y debe ser posterior al inicio', () => {
  const started = new Date(2026, 10, 14, 7, 50).toISOString();
  const payload = buildPatioFinish({ termino_at: localDateTime(now), temperatura_c: '6.2', temperatura_agua_final_c: '2',
    condicion_visual_agua_final: 'no_conforme', destino_salida: 'camara', observacion: ' Agua turbia ', accion_correctiva: '' }, 'op-2', started, now);
  assert.equal(payload.cloro_libre_final_ppm, undefined);
  assert.equal(payload.control_final_conforme, undefined);
  assert.equal(payload.condicion_visual_agua_final, 'no_conforme');
  assert.equal(payload.observacion, 'Agua turbia');
  assert.throws(() => buildPatioFinish({ termino_at: inicio, temperatura_c: '6', condicion_visual_agua_final: 'conforme', destino_salida: 'camara' }, 'op', started, now), /posterior al inicio/);
  assert.equal(isPatioCycle({ modalidad: 'patio' }), true);
  assert.equal(isPatioCycle({ modalidad: 'oficial' }), false);
  assert.equal(isPatioCycle(null), false);
});
