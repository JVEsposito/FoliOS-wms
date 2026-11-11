import test from 'node:test';
import assert from 'node:assert/strict';
import {
  checkNotApplicable, declaredPallets, fieldValue, fillNotApplicable, formatTemperature, nextFreePosition, palletsForList,
  parsePosition, parseTemperature, positionOccupant, sectionChanges,
} from '../src/domain/consolidation.ts';

const pallet = (numero_folio, posicion = null, estado_carga = 'cargado') => ({
  asignacion_id: `a-${numero_folio}`, folio_id: `f-${numero_folio}`, numero_folio, estado_carga, cargado_at: null, especie: 'Cereza', variedad: null, cajas_folio: 80,
  posicion: posicion === null ? null : { id: `p-${numero_folio}`, posicion, codigo_pe: null, cajas: 80, temperatura: null, registrado_at: null, registrado_por: null },
});
const control = (extra = {}) => ({
  id: 'c1', estado: 'borrador', version: 3, carga: { id: 'g1', codigo: 'CAR-1', estado: 'despachada', embarque: null, anden: null, patente: null },
  encabezado: { exportadora: 'Subsole', tipo_camion: null }, sugerido: { encabezado: {}, cierre: {} }, inspeccion: { puertas: 'si', horometro: null },
  cierre: { cajas_guia: 160 }, pallets: [], totales: {}, fotos: [], faltantes_cierre: [], reaperturas: [], ...extra,
});

test('la siguiente posición es la primera libre', () => {
  assert.equal(nextFreePosition([]), 1);
  assert.equal(nextFreePosition([pallet('A', 1), pallet('B', 2), pallet('C', 4), pallet('D')]), 3);
});

test('una posición ocupada por otro folio se informa; el mismo pallet puede corregirse', () => {
  const pallets = [pallet('A0001', 5)];
  assert.equal(positionOccupant(pallets, 5, 'b0002'), 'A0001');
  assert.equal(positionOccupant(pallets, 5, 'a0001'), null);
  assert.equal(positionOccupant(pallets, 6, 'B0002'), null);
});

test('posición y temperatura se validan con mensajes para el operador', () => {
  assert.equal(parsePosition('12'), 12);
  assert.throws(() => parsePosition('0'), /de 1 a 60/);
  assert.throws(() => parsePosition('2.5'), /de 1 a 60/);
  assert.equal(parseTemperature('-0,46'), -0.5);
  assert.equal(parseTemperature(' '), null);
  assert.throws(() => parseTemperature('55'), /-10 a 40/);
  assert.equal(formatTemperature(-0.5), '-0,5 °C');
});

test('la inspección marca «no aplica» solo en los puntos del otro tipo de camión sin respuesta', () => {
  const checks = [
    { clave: 'puertas', etiqueta: 'Puertas', aplica: null },
    { clave: 'horometro', etiqueta: 'Horómetro', aplica: 'thermo' },
    { clave: 'linea_roja', etiqueta: 'Línea roja', aplica: 'contenedor' },
  ];
  assert.equal(checkNotApplicable(checks[1], 'contenedor'), true);
  assert.equal(checkNotApplicable(checks[1], null), false);
  assert.deepEqual(fillNotApplicable(checks, 'contenedor', {}), { horometro: 'na' });
  assert.deepEqual(fillNotApplicable(checks, 'thermo', { linea_roja: 'si' }), {});
});

test('solo se envía lo que cambió y los enteros viajan como número', () => {
  const base = control();
  const draft = { encabezado: { exportadora: 'Subsole', tipo_camion: 'thermo' }, cierre: { cajas_guia: '160', pallets_chep_1000: '2' } };
  assert.deepEqual(sectionChanges(base, draft, 'encabezado', [{ clave: 'exportadora' }, { clave: 'tipo_camion' }]), { tipo_camion: 'thermo' });
  assert.deepEqual(sectionChanges(base, draft, 'cierre', [{ clave: 'cajas_guia', tipo: 'entero' }, { clave: 'pallets_chep_1000', tipo: 'entero' }]), { pallets_chep_1000: 2 });
  assert.throws(() => sectionChanges(base, { cierre: { cajas_guia: 'diez' } }, 'cierre', [{ clave: 'cajas_guia', tipo: 'entero', etiqueta: 'Cajas guía' }]), /Cajas guía/);
  assert.equal(fieldValue(base, draft, 'encabezado', 'tipo_camion'), 'thermo');
  assert.equal(fieldValue(base, {}, 'inspeccion', 'puertas'), 'si');
  assert.equal(fieldValue(base, {}, 'inspeccion', 'horometro'), '');
});

test('la lista muestra primero lo que espera en el andén', () => {
  const ordered = palletsForList([pallet('C', 2), pallet('A', null, 'en_anden'), pallet('B', 1), pallet('D', null, 'pendiente'), pallet('E', null)]);
  assert.deepEqual(ordered.map((p) => p.numero_folio), ['A', 'E', 'B', 'C', 'D']);
});

test('los tipos de pallet declarados se suman', () => {
  const values = { pallets_chep_1000: '18', pallets_taco_800: '2', pallets_yugo_1000: '' };
  assert.equal(declaredPallets((key) => values[key] ?? ''), 20);
});
