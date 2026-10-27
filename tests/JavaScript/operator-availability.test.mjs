import test from 'node:test';
import assert from 'node:assert/strict';
import { createAvailabilityTracker, operatorPresenceLabel } from '../../resources/js/shared/operator-availability.js';

const snapshot = (seconds, operators = [], season = 's1') => ({ generado_at: `2026-10-27T15:00:${String(seconds).padStart(2, '0')}Z`, temporada_id: season, camareros: operators });
const free = (id = 'event-1') => ({ usuario: { id: 1, nombre: 'Camarero' }, estado_presencia: 'disponible', aviso_disponible_id: id });

test('una finalización produce un solo aviso y una nueva labor permite un aviso posterior', () => {
    const tracker = createAvailabilityTracker();
    assert.equal(tracker.receive(snapshot(0)).alerts.length, 0);
    assert.equal(tracker.receive(snapshot(5, [free()])).alerts.length, 1);
    assert.equal(tracker.receive(snapshot(10, [free()])).alerts.length, 0);
    assert.equal(tracker.receive(snapshot(15, [free('event-2')])).alerts.length, 1);
});

test('abrir Oficina y cambiar de temporada no repite alertas antiguas', () => {
    const tracker = createAvailabilityTracker();
    assert.equal(tracker.receive(snapshot(0, [free()])).alerts.length, 0);
    assert.equal(tracker.receive(snapshot(5, [free('event-2')], 's2')).alerts.length, 0);
});

test('una respuesta antigua no reemplaza la lectura actual ni dispara avisos', () => {
    const tracker = createAvailabilityTracker();
    tracker.receive(snapshot(10));
    assert.equal(tracker.receive(snapshot(5, [free()])).applied, false);
    assert.equal(tracker.receive(snapshot(15, [free()])).alerts.length, 1);
});

test('espera, pérdida de conexión y falta de confirmación tienen estados explícitos', () => {
    assert.equal(operatorPresenceLabel({ estado_presencia: 'desconectado' }), 'Sin conexión');
    assert.equal(operatorPresenceLabel({ estado_presencia: 'esperando' }), 'Esperando / requiere atención');
    assert.equal(operatorPresenceLabel({ estado_presencia: 'sin_confirmar' }), 'Presencia sin confirmar');
    assert.equal(operatorPresenceLabel(free()), 'Disponible');
});
