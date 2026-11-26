import test from 'node:test';
import assert from 'node:assert/strict';
import { aplicarPresample, folioDeRangos } from '../../resources/js/shared/sag-presample.js';

const lectura = (cambios = {}) => ({
    fuente: 'claude', lote: '15', muestra: 3, partida: 3432, pallets: 20,
    rangos: [{ desde: '12358039', hasta: '12358058' }],
    cajas: [{ numero: 12, estado: 'confirmada' }, { numero: 743, estado: 'revisar' }, { numero: 3415, estado: 'confirmada' }],
    problemas: [], avisos: [], ...cambios,
});

test('completa N.º, lote y folio vacíos y devuelve las cajas a marcar', () => {
    const r = aplicarPresample({ numero: '', lote: '', folio: '' }, lectura());

    assert.deepEqual(r.campos, { numero: '15', lote: '15', folio: '12358039 a 12358058' });
    assert.deepEqual(r.marcas, [12, 743, 3415]);
    assert.deepEqual(r.avisos, []);
});

test('no reemplaza un N.º distinto y lo avisa; un valor igual no avisa', () => {
    const r = aplicarPresample({ numero: '14', lote: '15', folio: '' }, lectura());

    assert.deepEqual(r.campos, { numero: '14', lote: '15', folio: '12358039 a 12358058' });
    assert.deepEqual(r.avisos, ['El N.º del diagrama es 14 y el presample dice 15: no se cambió.']);
});

test('con problemas no aplica nada', () => {
    const r = aplicarPresample({ numero: '', lote: '', folio: '' }, lectura({ problemas: ['Se leyeron 3 cajas y el presample indica una muestra de 4.'] }));

    assert.deepEqual(r.campos, { numero: '', lote: '', folio: '' });
    assert.deepEqual(r.marcas, []);
});

test('sin lote (solo Tesseract) no toca los campos pero entrega las cajas', () => {
    const r = aplicarPresample({ numero: '', lote: '', folio: '' }, lectura({ fuente: 'tesseract', lote: null, rangos: [] }));

    assert.deepEqual(r.campos, { numero: '', lote: '', folio: '' });
    assert.deepEqual(r.marcas, [12, 743, 3415]);
});

test('dos rangos se unen con «y»', () => {
    assert.equal(folioDeRangos([{ desde: '100', hasta: '110' }, { desde: '120', hasta: '125' }]), '100 a 110 y 120 a 125');
    assert.equal(folioDeRangos([{ desde: '100', hasta: '100' }]), '100');
});
