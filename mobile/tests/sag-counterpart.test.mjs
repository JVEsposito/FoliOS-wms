import test from 'node:test';
import assert from 'node:assert/strict';
import { blankSagForm, sagPayload, sagStorageKey, sagFormFromDocument } from '../src/domain/sagCounterpart.ts';

const line = { folio_id: 'f1', folio: '000123', especie: 'CEREZA', cantidad: 168, tipo_actividad: 'INSPECCIÓN', numero_actividad: '1513' };
const base = () => ({ ...blankSagForm(), responsable: 'r1', fecha: '2026-10-21' });
test('repa conserva actividad y no exige ni incorpora destinos', () => {
  const data = sagPayload({ ...base(), lineas: [line], fuente: { fecha_actividad: '2026-10-21', repaletizaje_id: 'repa1' } }, 'op1');
  assert.equal(data.repaletizaje_id, 'repa1'); assert.deepEqual(data.actividades.f1, { tipo: 'INSPECCIÓN', numero: '1513' });
  assert.equal('mercados_anteriores' in data, false); assert.equal('destinos' in data, false);
});
test('anulación conserva cantidades parciales y causal', () => {
  const data = sagPayload({ ...base(), tipo: 'anulacion', lineas: [{ ...line, cantidad: 4, causal: 'DECISIÓN COMERCIAL' }] }, 'op1');
  assert.equal(data.folios[0].cantidad, 4); assert.equal(data.folios[0].causal, 'DECISIÓN COMERCIAL');
  assert.equal('condicion' in data.folios[0], false);
});
test('mercado no convierte un peso desconocido en cero', () => {
  const data = sagPayload({ ...base(), tipo: 'cambio_mercado', lineas: [{ ...line, kilos: null, mercados_anteriores: 'CHINA' }], fuente: { fecha_actividad: '2026-10-21', lote_inspeccion_sag_id: 'cm1' } }, 'op1');
  assert.deepEqual(data.kilos, {}); assert.equal(data.mercados_anteriores.f1, 'CHINA');
});
test('despacho mixto selecciona el pallet una vez y conserva los kilos y CSG por línea', () => {
  const data = sagPayload({ ...base(), tipo: 'despacho', instructivo: 'i1', fuente: { fecha_actividad: '2026-10-21', embarque_id: 'e1' }, lineas: [
    { ...line, linea_id: 'f1:1', incluido: true, kilos: 300, csg: '001', csp: '007', provincia: 'Colchagua', comuna: 'Placilla' },
    { ...line, linea_id: 'f1:2', incluido: true, kilos: 540, csg: '002', csp: '007', provincia: 'Cachapoal', comuna: 'Rengo' },
    { ...line, folio_id: 'f2', linea_id: 'f2:1', incluido: false, kilos: 800 },
  ] }, 'op1');
  assert.deepEqual(data.seleccion_folios, ['f1']); assert.deepEqual(data.kilos, { 'f1:1': 300, 'f1:2': 540 });
  assert.equal(data.origenes['f1:2'].csg, '002'); assert.equal('nimf15' in data.encabezado.despacho, false);
});
test('reintentos y capturas se separan por servidor, persona y equipo', () => {
  assert.equal(sagStorageKey('https://planta.test/', 'u1', 'd1'), sagStorageKey('https://planta.test', 'u1', 'd1'));
  assert.notEqual(sagStorageKey('https://planta.test', 'u1', 'd1'), sagStorageKey('https://planta.test', 'u2', 'd1'));
  assert.notEqual(sagStorageKey('https://planta.test', 'u1', 'd1'), sagStorageKey('https://otra.test', 'u1', 'd1'));
});
test('editar un borrador conserva la versión y el instructivo', () => {
  const form = sagFormFromDocument({ id: 'd1', version: 3, tipo: 'despacho', fecha_actividad: '2026-10-21T00:00:00Z', responsable_id: 'r1', encabezado: {}, detalle: { solicitud: 'EMB-1', instructivo_id: 'i1', lineas: [line], transporte: { modo: 'multipuerto' } } });
  assert.deepEqual(form.documento, { id: 'd1', version: 3 }); assert.equal(form.instructivo, 'i1'); assert.equal(form.lineas[0].incluido, true);
});
