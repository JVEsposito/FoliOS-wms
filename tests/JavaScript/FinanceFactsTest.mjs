import assert from 'node:assert/strict';
import test from 'node:test';

import {
    duration,
    factDetail,
    kpiLabel,
    kpiValue,
    measuresText,
    quantity,
    versionChanges,
} from '../../resources/js/shared/finance-facts-logic.js';

test('las cantidades van en singular o plural', () => {
    assert.equal(quantity(1, 'caja'), '1 caja');
    assert.equal(quantity(120, 'caja'), '120 cajas');
    assert.equal(quantity(1200, 'pallet'), '1.200 pallets');
    assert.equal(quantity(0, 'saldo'), '0 saldos');
});

test('las medidas se describen según la fuente', () => {
    assert.equal(measuresText({ fuente: 'romana_recepcion', medidas: { kilos_netos: 8450.5, envases: 24, recepciones: 1 } }), '8.450,5 kg · 24 envases');
    assert.equal(measuresText({ fuente: 'validacion_pt', medidas: { bultos: 1, pallets: 1, saldos: 0, cajas: 120 } }), '120 cajas · 1 pallet');
    assert.equal(measuresText({ fuente: 'recepcion_embalada', medidas: { bultos: 3, pallets: 2, saldos: 1, cajas: 300 } }), '300 cajas · 2 pallets · 1 saldo');
    assert.equal(measuresText({ fuente: 'repaletizaje', medidas: { folios_origen: 2, folios_resultantes: 1, cajas: 120 } }), '2 folios → 1 folio · 120 cajas');
});

test('la duración de una estadía es exacta, en días y horas', () => {
    assert.equal(duration('2026-11-01T13:00:00Z', '2026-11-05T19:00:00Z'), '4 d 6 h');
    assert.equal(duration('2026-11-01T13:00:00Z', '2026-11-03T13:00:00Z'), '2 d');
    assert.equal(duration('2026-11-01T13:00:00Z', '2026-11-01T16:30:00Z'), '3 h');
    assert.equal(duration('2026-11-02T13:00:00Z', '2026-11-01T13:00:00Z'), '0 h');
});

test('el detalle de una estadía dice dónde entró, cuánto duró y cómo salió', () => {
    const closed = {
        fuente: 'estadia', ocurrido_at: '2026-11-01T13:00:00Z', termina_at: '2026-11-05T19:00:00Z',
        datos: { camara_ingreso: 'C1', motivo_salida: 'despacho', carga: 'CG-0001', salida_estimada: false },
    };
    assert.equal(factDetail(closed), 'Ingresó a C1 · 4 d 6 h · despachado en CG-0001');

    const open = { fuente: 'estadia', ocurrido_at: '2026-11-01T13:00:00Z', termina_at: null, datos: { camara_ingreso: 'C2', fuera_de_camara_desde: '2026-11-02T13:00:00Z' } };
    assert.equal(factDetail(open, new Date('2026-11-03T13:00:00Z')), 'Ingresó a C2 · 2 d hasta ahora · en planta · fuera de cámara');
});

test('el detalle de las demás fuentes', () => {
    assert.equal(factDetail({ fuente: 'romana_recepcion', datos: { tipo_servicio: 'almacenaje', especie: 'Cereza', guia: 'G-100' } }), 'Servicio almacenaje · Cereza · Guía G-100');
    assert.equal(factDetail({ fuente: 'prefrio', datos: { proceso: 'PF-0001', tunel: 'T1', minutos_enfriado: 750 } }), 'PF-0001 · Túnel T1 · 12 h de frío');
    assert.equal(factDetail({ fuente: 'despacho', datos: { carga: 'CG-0042', orden_externa: null, patente: 'AB1234' } }), 'Carga CG-0042 · AB1234');
});

test('entre versiones se muestra solo lo que cambió', () => {
    const before = { estado: 'vigente', cliente_nombre: 'EXPORTADORA DEMO', referencia: 'PT-1', ocurrido_at: 'a', termina_at: null, medidas: { cajas: 120, pallets: 1 }, datos: { variedad: 'Santina' } };
    const after = { ...before, termina_at: 'b', medidas: { cajas: 110, pallets: 1 }, datos: { variedad: 'Santina', carga: 'CG-1' } };
    assert.deepEqual(versionChanges(before, after), [
        { label: 'Término', before: '—', after: 'b' },
        { label: 'cajas', before: '120', after: '110' },
        { label: 'carga', before: '—', after: 'CG-1' },
    ]);
    assert.deepEqual(versionChanges(undefined, after), []);
});

test('la tarjeta de cada fuente muestra su medida principal y qué cuenta', () => {
    const romana = { fuente: 'romana_recepcion', hechos: 9, medidas: { kilos_netos: 123456.7 } };
    assert.equal(kpiValue(romana), '123.456,7');
    assert.equal(kpiLabel(romana), 'kg netos · 9 recepciones');
    assert.equal(kpiValue({ fuente: 'estadia', hechos: 1530, medidas: { bultos: 1530 } }), '1.530');
    assert.equal(kpiLabel({ fuente: 'recepcion_embalada', hechos: 1, medidas: { bultos: 3 } }), 'pallets y saldos · 1 recepción');
    assert.equal(kpiValue({ fuente: 'repaletizaje', hechos: 12, medidas: { operaciones: 12 } }), '12');
    assert.equal(kpiLabel({ fuente: 'otra', hechos: 2, medidas: {} }), '2 hechos');
});
