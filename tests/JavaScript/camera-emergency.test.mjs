import assert from 'node:assert/strict';
import test from 'node:test';
import {
    canSuperviseEmergency,
    confirmsCameraCode,
    emergencyEndpoint,
    emergencyError,
} from '../../resources/js/shared/camera-emergency.js';
import { buildOperationalAlerts } from '../../resources/js/shared/operation-now-alerts.js';

test('solo el permiso específico muestra las acciones y la declaración exige el código exacto', () => {
    assert.equal(canSuperviseEmergency({ puede_supervisar_camaras_productos: true }), true);
    assert.equal(canSuperviseEmergency({ capacidades: { puede_supervisar: true } }), false);
    assert.equal(confirmsCameraCode('CAM-09', 'CAM-09'), true);
    assert.equal(confirmsCameraCode('cam-09', 'CAM-09'), false);
    assert.equal(confirmsCameraCode('CAM-09 ', 'CAM-09'), false);
});

test('declarar y cancelar usan sus endpoints y muestran el mensaje del backend', () => {
    assert.equal(emergencyEndpoint('cam 09', 'declarar'), '/api/evacuaciones-emergencia/cam%2009');
    assert.equal(emergencyEndpoint('cam 09', 'cancelar'), '/api/evacuaciones-emergencia/cam%2009/cancelar');
    assert.equal(emergencyError({ message: 'El planificador está desactivado' }), 'El planificador está desactivado');
    assert.equal(emergencyError({ message: 'La emergencia dirigida requiere…', errors: { motivo: ['Motivo inválido'] } }), 'La emergencia dirigida requiere…');
});

test('Operación ahora destaca la emergencia activa de la cámara', () => {
    const alerts = buildOperationalAlerts({ emergencias: [{ camara_codigo: 'CAM-09', motivo: 'Fuga de amoníaco' }] });
    assert.equal(alerts[0].severity, 'critical');
    assert.equal(alerts[0].condition, 'Evacuación de emergencia activa en CAM-09');
});
