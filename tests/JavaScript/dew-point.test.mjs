import assert from 'node:assert/strict';
import test from 'node:test';

import { dewPoint, dewPointAlerts, dewPointState, evaluateReading, humidityAt, waterVapor } from '../../resources/js/shared/dew-point.js';
import { buildOperationalAlerts } from '../../resources/js/shared/operation-now-alerts.js';

// Valores calculados por la planilla «Calculadora_Punto_Rocio_Cerezas.xlsx» (15 °C, 62,9 % HR, fruta 7,1 °C).
test('reproduce la calculadora de cerezas de Calidad', () => {
    assert.ok(Math.abs(dewPoint(15, 62.9) - 7.989156882502499) < 1e-9);
    assert.ok(Math.abs(waterVapor(15, 62.9) - 8.050930979414808) < 1e-9);
    assert.ok(Math.abs(humidityAt(15, 62.9, 12) - 76.46360987089669) < 1e-9);
    assert.ok(Math.abs(dewPoint(12, 45) - 0.4251002191988776) < 1e-9);
    assert.ok(Math.abs(dewPoint(12, 30) - -5.059249623598542) < 1e-9);
    assert.deepEqual(evaluateReading({ ambient: 15, humidity: 62.9, surface: 7.1 }), {
        dewPoint: 7.99, margin: -0.89, vapor: 8.05, state: 'condensacion_posible',
    });
});

test('clasifica el margen como la guía rápida', () => {
    assert.equal(dewPointState(null), 'sin_superficie');
    assert.equal(dewPointState(-0.01), 'condensacion_posible');
    assert.equal(dewPointState(0), 'margen_bajo');
    assert.equal(dewPointState(1.99), 'margen_bajo');
    assert.equal(dewPointState(2), 'margen_favorable');
    assert.equal(dewPointState(2.5, 3), 'margen_bajo');
    assert.equal(evaluateReading({ ambient: 15, humidity: 0 }), null);
    assert.equal(evaluateReading({ ambient: 15, humidity: 101 }), null);
    assert.equal(evaluateReading({ ambient: 15, humidity: 62.9, surface: '' }).state, 'sin_superficie');
});

test('las alertas sin acción llegan a Operación ahora', () => {
    const pending = [
        { punto: 'Sala de sellado', etapa_etiqueta: 'Sellado', estado: 'margen_bajo', estado_etiqueta: 'Margen bajo', gotas_visibles: 'no', punto_rocio_c: 8, temperatura_superficie_c: 9.5, margen_c: 1.5, fecha: '2026-11-04' },
        { punto: 'TUN-01', etapa_etiqueta: 'Salida prefrío', estado: 'margen_favorable', estado_etiqueta: 'Margen favorable', gotas_visibles: 'si', punto_rocio_c: -3, temperatura_superficie_c: 0.5, margen_c: 3.5, fecha: '2026-11-04' },
    ];
    const alerts = dewPointAlerts(pending);
    assert.equal(alerts[0].severity, 'warning');
    assert.equal(alerts[0].condition, 'Margen bajo · Sellado');
    assert.equal(alerts[0].evidence, 'Rocío 8 °C · superficie 9,5 °C · margen 1,5 °C');
    assert.equal(alerts[0].href, '/oficina/prefrio/punto-rocio?fecha=2026-11-04');
    assert.equal(alerts[1].severity, 'critical');
    assert.equal(alerts[1].condition, 'Gotas visibles · Salida prefrío');

    const all = buildOperationalAlerts({ prefrio: { tuneles: [], condensacion: { alertas_pendientes: pending } } });
    assert.deepEqual(all.map((alert) => alert.area), ['TUN-01', 'Sala de sellado']);
});
