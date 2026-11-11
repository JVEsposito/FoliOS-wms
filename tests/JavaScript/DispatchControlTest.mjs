import test from 'node:test';
import assert from 'node:assert/strict';
import { gruposEncabezado, orientacion, palletsOrdenados, temperatura, textoValor, tonoInspeccion } from '../../resources/js/shared/dispatch-control.js';

test('la inspección distingue los puntos en que «sí» es un hallazgo', () => {
    assert.equal(tonoInspeccion('puertas', 'si'), 'ok');
    assert.equal(tonoInspeccion('puertas', 'no'), 'alerta');
    assert.equal(tonoInspeccion('danos_visuales', 'si'), 'alerta');
    assert.equal(tonoInspeccion('danos_visuales', 'no'), 'ok');
    assert.equal(tonoInspeccion('horometro', 'na'), 'neutro');
    assert.equal(tonoInspeccion('horometro', null), 'pendiente');
});

test('el encabezado se agrupa como la planilla y omite lo vacío', () => {
    const campos = [
        { clave: 'tipo_camion', etiqueta: 'Tipo de camión', grupo: 'operacion' },
        { clave: 'fecha_carga', etiqueta: 'Fecha de carga', grupo: 'operacion' },
        { clave: 'conductor', etiqueta: 'Conductor', grupo: 'transporte' },
        { clave: 'nave', etiqueta: 'Nave', grupo: 'destino' },
    ];
    const grupos = gruposEncabezado(campos, { tipo_camion: 'thermo', fecha_carga: '2026-11-11', conductor: '', nave: null });
    assert.deepEqual(grupos, [{ clave: 'operacion', titulo: 'Operación', filas: [
        { clave: 'tipo_camion', etiqueta: 'Tipo de camión', valor: 'Thermo' },
        { clave: 'fecha_carga', etiqueta: 'Fecha de carga', valor: '11-11-2026' },
    ] }]);
});

test('los pallets se ordenan por posición y los sin posición van al final', () => {
    const orden = palletsOrdenados([
        { numero_folio: 'C', posicion: null },
        { numero_folio: 'B', posicion: { posicion: 2 } },
        { numero_folio: 'A', posicion: { posicion: 2 } },
        { numero_folio: 'D', posicion: { posicion: 1 } },
    ]).map((pallet) => pallet.numero_folio);
    assert.deepEqual(orden, ['D', 'A', 'B', 'C']);
});

test('los valores se muestran en español', () => {
    assert.equal(textoValor('no_cumple'), 'No cumple');
    assert.equal(textoValor(1680), '1.680');
    assert.equal(textoValor(''), '—');
    assert.equal(temperatura(-0.5), '-0,5 °C');
    assert.equal(temperatura(null), '—');
});

test('P/E se lee como punta o espejo', () => {
    assert.equal(orientacion('P'), 'Punta');
    assert.equal(orientacion('E'), 'Espejo');
    assert.equal(orientacion(null), '—');
});
