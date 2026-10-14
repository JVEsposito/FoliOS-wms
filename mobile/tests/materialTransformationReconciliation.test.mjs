import test from 'node:test';
import assert from 'node:assert/strict';
import {
  closeReconciliationLines,
  closeRequiresReason,
  matchOutputScan,
  matchWithdrawalScan,
  parseDeclaredQuantity,
  reconciliationRows,
  returnPlan,
  startsWithReconciliation,
  withdrawalLines,
} from '../src/domain/materialTransformationReconciliation.ts';

const reservation = (id, item, fifo, cantidad, consumida, estado, folio) => ({
  id, item_material_id: item, orden_fifo: fifo, cantidad, cantidad_consumida: consumida,
  cantidad_pendiente: '0', estado, folio,
});
const folio = (id, numero, actual, ubicacion = { camara: 'MAT', posicion: 'B01-P01-N1' }) => ({
  id, numero_folio: numero, cantidad_actual: actual, cantidad_reservada: '0', ubicacion,
});

function order(overrides = {}) {
  return {
    id: 'ot-1',
    estado: 'en_proceso',
    modo_operacion: 'cuadratura_cierre',
    cantidad_planificada_salida: '80.000',
    receta_snapshot: {
      salida: { cantidad_base: '100.000' },
      componentes: [
        { item_id: 'aux', codigo: 'INS', nombre: 'Insumo', cantidad_estandar: '10.000', unidad_medida: 'UN', es_componente_principal: false, tolerancia_porcentaje: '0' },
        { item_id: 'mp', codigo: 'MP', nombre: 'Material MP', cantidad_estandar: '100.000', unidad_medida: 'UN', es_componente_principal: true, tolerancia_porcentaje: '5' },
      ],
    },
    reservas: [
      reservation('r1', 'mp', 1, '50.000', '50.000', 'consumida', folio('f1', 'FGE0000001', '0.000')),
      reservation('r2', 'mp', 2, '30.000', '30.000', 'consumida', folio('f2', 'FGE0000002', '12.000')),
      reservation('r3', 'aux', 1, '8.000', '8.000', 'consumida', folio('f3', 'FGE0000003', '2.000')),
    ],
    lotes: [
      { estado: 'cerrado', cantidad_real_salida: '30.000' },
      { estado: 'cerrado', cantidad_real_salida: '28.000' },
      { estado: 'anulado', cantidad_real_salida: '30.000' },
    ],
    folios_salida: [
      { numero_folio: 'FGE0000101', estado: 'usado' },
      { numero_folio: 'FGE0000102', estado: 'reservado', cantidad_teorica: '30.000' },
      { numero_folio: 'FGE0000103', estado: 'anulado' },
    ],
    ...overrides,
  };
}

test('una orden planificada con folios de salida reservados se inicia en cuadratura', () => {
  assert.equal(startsWithReconciliation(order({ estado: 'planificada' })), true);
  assert.equal(startsWithReconciliation(order({ estado: 'planificada', folios_salida: [] })), false);
  assert.equal(startsWithReconciliation(order()), false);
});

test('el retiro lista lo pendiente de cada reserva y exige que el folio esté ubicado', () => {
  const planned = order({
    estado: 'planificada',
    reservas: [
      reservation('r1', 'mp', 1, '80.000', '0.000', 'activa', folio('f1', 'FGE0000001', '80.000')),
      reservation('r3', 'aux', 1, '8.000', '0.000', 'activa', folio('f3', 'FGE0000003', '10.000', null)),
    ],
  });
  const lines = withdrawalLines(planned);
  assert.deepEqual(lines.map((line) => [line.numeroFolio, line.codigo, line.cantidad]), [
    ['FGE0000001', 'MP', 80],
    ['FGE0000003', 'INS', 8],
  ]);
  assert.equal(matchWithdrawalScan(lines, ' fge0000001 ').line.folioId, 'f1');
  assert.match(matchWithdrawalScan(lines, 'FGE0000003').error, /debe ubicarse/);
  assert.match(matchWithdrawalScan(lines, 'FGE9999999').error, /no está reservado/);
});

test('la etiqueta pistoleada debe ser un folio reservado sin usar', () => {
  assert.equal(matchOutputScan(order(), 'fge0000102').folio.numero_folio, 'FGE0000102');
  assert.match(matchOutputScan(order(), 'FGE0000101').error, /ya fue confirmado/);
  assert.match(matchOutputScan(order(), 'FGE0000103').error, /anulado/);
  assert.match(matchOutputScan(order(), 'FZZ0000001').error, /no está reservado/);
});

test('lo sin armar vuelve primero al último folio retirado', () => {
  const plan = returnPlan(order().reservas.filter((r) => r.item_material_id === 'mp'), 35);
  assert.deepEqual(plan, [
    { numeroFolio: 'FGE0000002', cantidad: 30, requiereUbicacion: false },
    { numeroFolio: 'FGE0000001', cantidad: 5, requiereUbicacion: true },
  ]);
});

test('la cuadratura propone la merma que cuadra con la receta', () => {
  const rows = reconciliationRows(order(), { mp: { sinArmar: '20', merma: '', mermaEditada: false } });
  const [mp, aux] = rows;
  assert.equal(mp.principal, true);
  assert.deepEqual([mp.retirado, mp.teorico, mp.sinArmar, mp.merma, mp.utilizado, mp.desviacion], [80, 58, 20, 2, 58, 0]);
  assert.equal(mp.fueraDeTolerancia, false);
  assert.deepEqual(mp.devoluciones.map((line) => [line.numeroFolio, line.cantidad]), [['FGE0000002', 20]]);
  assert.deepEqual([aux.retirado, aux.teorico, aux.merma, aux.utilizado], [8, 5.8, 2.2, 5.8]);
  assert.equal(closeRequiresReason(order(), rows), true, 'se produjo 58 de 80');
  assert.deepEqual(closeReconciliationLines(rows), [
    { item_id: 'mp', cantidad_merma: 2, cantidad_sin_armar: 20 },
    { item_id: 'aux', cantidad_merma: 2.2, cantidad_sin_armar: 0 },
  ]);
});

test('una merma editada fuera de tolerancia exige motivo y no puede superar lo retirado', () => {
  const full = order({
    lotes: [
      { estado: 'cerrado', cantidad_real_salida: '30.000' },
      { estado: 'cerrado', cantidad_real_salida: '30.000' },
      { estado: 'cerrado', cantidad_real_salida: '20.000' },
    ],
  });
  const balanced = reconciliationRows(full, {});
  assert.equal(closeRequiresReason(full, balanced), false);

  const within = reconciliationRows(full, { mp: { sinArmar: '0', merma: '3', mermaEditada: true } });
  assert.equal(within[0].desviacion, -3);
  assert.equal(within[0].fueraDeTolerancia, false, 'tolerancia 5 % de 80 = 4');

  const outside = reconciliationRows(full, { mp: { sinArmar: '0', merma: '5', mermaEditada: true } });
  assert.equal(outside[0].fueraDeTolerancia, true);
  assert.equal(closeRequiresReason(full, outside), true);

  const overflow = reconciliationRows(full, { mp: { sinArmar: '50', merma: '40', mermaEditada: true } });
  assert.match(overflow[0].error, /superan lo retirado \(80 UN\)/);
  assert.deepEqual(overflow[0].devoluciones, []);
  assert.match(reconciliationRows(full, { mp: { sinArmar: '-1', merma: '', mermaEditada: false } })[0].error, /mayores que cero/);
});

test('acepta coma decimal y vacío como cero', () => {
  assert.equal(parseDeclaredQuantity('2,5'), 2.5);
  assert.equal(parseDeclaredQuantity(''), 0);
  assert.equal(parseDeclaredQuantity('abc'), null);
});
