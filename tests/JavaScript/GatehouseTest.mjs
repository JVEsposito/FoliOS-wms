import test from 'node:test';
import assert from 'node:assert/strict';
import { gatehouseDay, gatehouseGuides, gatehouseStatus, gatehouseOperationId, gatehouseEventLines, escapeGatehouse } from '../../resources/js/shared/gatehouse-logic.js';
test('el día de planta conserva la noche de Chile cuando UTC ya cambió', () => {
    assert.equal(gatehouseDay(new Date('2026-10-20T02:30:00Z')), '2026-10-19');
    assert.equal(gatehouseDay(new Date('2026-10-20T03:30:00Z')), '2026-10-20');
});
test('las guías conservan ceros iniciales y solo se limpian espacios', () => {
    assert.deepEqual(gatehouseGuides([' 000123 ', '', '00124']), ['000123', '00124']);
});
test('el estado da prioridad a la anulación aunque Romana ya haya revisado', () => {
    assert.equal(gatehouseStatus({}), 'Pendiente de Romana');
    assert.equal(gatehouseStatus({ revisado_at: 'fecha' }), 'Revisado por Romana');
    assert.equal(gatehouseStatus({ anulado_at: 'fecha', revisado_at: 'fecha' }), 'Anulado');
});
test('los reintentos tienen UUID incluso en HTTP de la red local', () => {
    const cryptoApi = { getRandomValues(bytes) { bytes.fill(0); return bytes; } };
    assert.match(gatehouseOperationId(cryptoApi), /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
});
test('el historial presenta los campos cambiados con nombres operacionales', () => {
    assert.deepEqual(gatehouseEventLines({ accion: 'corregido', datos: { antes: { patente: 'AAAA11', guias: ['0001'] }, despues: { patente: 'BBBB22', guias: ['0001'] } } }), ['Patente: AAAA11 → BBBB22']);
    assert.equal(escapeGatehouse('<script>"&'), '&lt;script&gt;&quot;&amp;');
});
