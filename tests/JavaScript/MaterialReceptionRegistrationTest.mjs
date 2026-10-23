import test from 'node:test';
import assert from 'node:assert/strict';
import { registrationLabel, registrationDetail } from '../../resources/js/office-material-reception-registration.js';
globalThis.document = { getElementById: () => null };

test('las recepciones anteriores conservan su representación sin inventar una fecha física', () => {
    assert.equal(registrationLabel({ estado: 'confirmada' }), '');
    assert.equal(registrationDetail({ estado: 'confirmada' }), '');
});
test('el vencimiento documental continúa visible cuando el inventario ya está confirmado', () => {
    assert.equal(registrationLabel({ estado: 'confirmada', registro: { provisional: true, retroactivo: true, estado: 'vencida' } }), 'Retroactiva · Provisional · plazo vencido');
    assert.equal(registrationLabel({ registro: { provisional: true, estado: 'regularizada', regularizada_fuera_plazo: true } }), 'Regularizada fuera de plazo');
});
test('la evidencia de autorización se muestra escapada y separa fecha física de registro', () => {
    const detail = registrationDetail({ created_at: '2026-10-23T18:00:00Z', registro: { fecha_recepcion: '2026-10-20T13:30:00Z', motivo: '<img src=x onerror=alert(1)>', autorizado_at: '2026-10-23T18:01:00Z', autorizado_por: { name: 'Jefe <b>' }, motivo_autorizacion: 'Guía pendiente', provisional: true, estado: 'pendiente', fecha_limite: '2026-10-26T02:59:59Z' } });
    assert.ok(detail.includes('Recepción física:'));
    assert.ok(detail.includes('Registro en sistema:'));
    assert.ok(detail.includes('&lt;img'));
    assert.ok(detail.includes('Jefe &lt;b&gt;'));
    assert.ok(!detail.includes('<img'));
});
