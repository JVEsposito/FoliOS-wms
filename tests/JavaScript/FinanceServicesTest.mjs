import assert from 'node:assert/strict';
import test from 'node:test';
import { serviceEventText, serviceRules, serviceValidity, servicesForPrice } from '../../resources/js/shared/finance-services-logic.js';

const base = { clave: 'estadia', base: true, por_dia: true, dia_desde: 1, dia_hasta: null };
const numeral = { clave: 'svc_numeral', nombre: 'Numeral', base: false, por_dia: true, dia_desde: 16, dia_hasta: null, aplicacion: 'reemplaza_base', vigente_desde: '2026-11-01', vigente_hasta: '2027-03-31' };

test('explica el reemplazo y la base temporal de los días sin confundirlos con el precio', () => {
    assert.equal(serviceRules(numeral), 'Reemplaza al concepto base · días 16 a sin límite de esta estadía del folio');
    assert.equal(serviceRules({ ...numeral, aplicacion: 'adicional', dia_desde: 4, dia_hasta: 15 }), 'Cobro adicional al concepto base · días 4 a 15 de esta estadía del folio');
    assert.equal(serviceRules({ base: true, por_dia: false }), 'Concepto base del proceso');
    assert.equal(serviceValidity(base), 'Sin límite de vigencia');
    assert.equal(serviceValidity(numeral), '01-11-2026 → 31-03-2027');
});

test('los conceptos finalizados conservan sus precios al editarlos y no se ofrecen para altas', () => {
    assert.deepEqual(servicesForPrice([base, numeral], '2027-04-01'), [base]);
    assert.deepEqual(servicesForPrice([base, numeral], '2027-03-31'), [base, numeral]);
    assert.deepEqual(servicesForPrice([base, numeral], '2027-04-01', numeral.clave), [base, numeral]);
});

test('el historial conserva las reglas de creación y el motivo se muestra aparte', () => {
    assert.match(serviceEventText({ accion: 'creado', datos: numeral }), /Numeral · Reemplaza al concepto base/);
    assert.equal(serviceEventText({ accion: 'finalizado', datos: { antes: null, despues: '2027-01-05' } }), 'Término: sin término → 05-01-2027');
});


test('los conceptos libres expresan su base económica sin exigir un proceso realizado', () => {
    assert.equal(serviceRules({ aplicacion: 'declarado', por_dia: false }), 'Cargo por acuerdo o declaración');
});
