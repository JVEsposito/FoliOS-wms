import assert from 'node:assert/strict';
import test from 'node:test';

import { buildOperationalAlerts } from '../../resources/js/shared/operation-now-alerts.js';

test('construye alertas solo con evidencia operacional disponible', () => {
    const alerts = buildOperationalAlerts({
        camaras: [
            {
                codigo: 'C3',
                contenido: 'productos',
                ocupadas: 94,
                capacidad_operativa: 100,
                ocupacion_porcentaje: 94,
                nivel_ocupacion: 'critica',
                control_ambiental: { estado: 'pendiente', capturado_at: null },
            },
            {
                codigo: 'MAT-01',
                contenido: 'materiales',
                ocupadas: 95,
                capacidad_operativa: 100,
                ocupacion_porcentaje: 95,
                nivel_ocupacion: 'critica',
                control_ambiental: null,
            },
        ],
        prefrio: {
            tuneles: [{
                codigo: 'T4',
                operable: true,
                estado_operacional: 'en_ciclo',
                proceso_activo: { objetivo_excedido: true, minutos_sobre_objetivo: 35 },
            }],
        },
        sincronizacion: { operaciones_hoy: { conflicto: 2, rechazada: 1 } },
    });

    assert.equal(alerts.length, 5);
    assert.deepEqual(alerts.map((alert) => alert.severity), [
        'critical', 'critical', 'critical', 'warning', 'warning',
    ]);
    assert.ok(alerts.every((alert) => alert.evidence && alert.href && alert.action));
    assert.deepEqual(
        alerts.map((alert) => `${alert.source}/${alert.tab}`).sort(),
        ['Ambiente/camaras', 'Ocupación/camaras', 'Prefrío/prefrio', 'Sincronización/personal', 'Sincronización/personal'],
        'cada alerta declara su fuente y la pestaña donde se ve el detalle',
    );
    assert.ok(alerts.some((alert) => alert.area === 'C3' && alert.condition === 'Ocupación crítica de cámara'));
    assert.ok(!alerts.some((alert) => alert.area === 'MAT-01'));
});

test('no inventa alertas cuando los indicadores están normales', () => {
    const alerts = buildOperationalAlerts({
        camaras: [{
            codigo: 'C1',
            contenido: 'productos',
            ocupadas: 20,
            capacidad_operativa: 100,
            ocupacion_porcentaje: 20,
            nivel_ocupacion: 'normal',
            control_ambiental: { estado: 'vigente', capturado_at: '2026-09-08T12:00:00Z' },
        }],
        prefrio: {
            tuneles: [{
                codigo: 'T1',
                operable: true,
                estado_operacional: 'disponible',
                proceso_activo: null,
            }],
        },
        sincronizacion: { operaciones_hoy: { conflicto: 0, rechazada: 0 } },
    });

    assert.deepEqual(alerts, []);
});

test('expone el estado real de un túnel no operable', () => {
    const [alert] = buildOperationalAlerts({
        prefrio: {
            tuneles: [{
                codigo: 'T6',
                operable: false,
                estado_operacional: 'fuera_servicio',
                proceso_activo: null,
            }],
        },
    });

    assert.equal(alert.condition, 'Túnel no operable');
    assert.equal(alert.evidence, 'fuera servicio');
    assert.equal(alert.action, 'Revisar túnel');
});

test('alerta del proceso anterior que mantiene ocupado un túnel', () => {
    const [alert] = buildOperationalAlerts({
        prefrio: {
            tuneles: [{
                codigo: 'T7',
                operable: true,
                estado_operacional: 'bloqueado_otra_temporada',
                proceso_activo: null,
                proceso_otra_temporada: { codigo: 'PF-2026-31', temporada_codigo: '2025-2026' },
            }],
        },
    });

    assert.equal(alert.condition, 'Túnel bloqueado por proceso anterior');
    assert.equal(alert.severity, 'critical');
    assert.match(alert.evidence, /PF-2026-31 · 2025-2026/);
    assert.equal(alert.href, undefined);
    assert.equal(alert.action, 'Se resuelve en el cierre de temporada');
});
