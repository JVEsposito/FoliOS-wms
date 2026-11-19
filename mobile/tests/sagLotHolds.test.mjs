import assert from 'node:assert/strict';
import test from 'node:test';
import { sagLotAlertText } from '../src/domain/sagLotHolds.ts';

test('el camarero recibe el lote, sus cajas y destinos sin exigir segregación implícita', () => {
  const text = sagLotAlertText({ id: 'hold', lote: '10056', cajas_lote: 1, estado: 'retenido', observacion: 'Detección en análisis', todos_destinos: false, destinos: [{ tipo: 'pais', id: 'us', nombre: 'Estados Unidos' }] });
  assert.match(text, /Lote 10056.*1 cajas.*Retenido/);
  assert.match(text, /Despacho restringido: Estados Unidos/);
  assert.doesNotMatch(text, /Segregación indicada/);
});

test('distingue una observación sin restricción y una instrucción física expresa', () => {
  const alert = { id: 'hold', lote: '10056', cajas_lote: 120, estado: 'con_observaciones', observacion: 'En seguimiento', todos_destinos: false, destinos: [] };
  assert.match(sagLotAlertText(alert), /Con observaciones.*\nSeguimiento SAG sin restricción de destino/);
  assert.match(sagLotAlertText({ ...alert, todos_destinos: true, instruccion_segregacion: 'Separar en CAM-09' }), /todos los destinos.*\nEn seguimiento.*\nSegregación indicada: Separar en CAM-09/);
});

test('la carga informa la restricción que aplica al país del instructivo', () => {
  const alert = { id: 'hold', lote: '10056', cajas_lote: 10, estado: 'retenido', observacion: 'En seguimiento', todos_destinos: false,
    destinos: [{ tipo: 'pais', id: 'pe', nombre: 'Perú' }], instructivo_numero: 'INST-CN', destino: 'China', bloquea_salida: false };
  assert.match(sagLotAlertText(alert), /Instructivo INST-CN.*Destino China.*Restricción en otros destinos \(Perú\)/);
  assert.match(sagLotAlertText({ ...alert, bloquea_salida: true, todos_destinos: true }), /Bloquea la salida a China.*todos los destinos/);
  assert.match(sagLotAlertText({ ...alert, bloquea_salida: true, destino: null, destino_sin_definir: true }), /Define el destino del instructivo/);
});
