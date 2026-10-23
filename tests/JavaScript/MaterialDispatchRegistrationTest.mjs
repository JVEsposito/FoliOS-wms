import assert from 'node:assert/strict';
import test from 'node:test';
import { readDispatchRegistration, dispatchRegistrationDetail } from '../../resources/js/office-material-dispatch-registration.js';

test('el formulario anterior no agrega campos al payload ni altera el UUID original', () => {
    assert.deepEqual(readDispatchRegistration({ elements: {} }), {});
    assert.deepEqual(readDispatchRegistration({ elements: { registro_fecha_despacho: { value: '' }, registro_provisional: { checked: false } } }), {});
});

test('una entrega conserva la fecha declarada de planta sin interpretarla con la zona del navegador', () => {
    const payload = readDispatchRegistration({ elements: {
        registro_fecha_despacho: { value: '2026-10-20T10:30' }, registro_provisional: { checked: true },
        registro_motivo: { value: '  Respaldo pendiente  ' }, registro_motivo_autorizacion: { value: 'Conteo físico comprobado' },
    } });
    assert.deepEqual(payload, { registro: { fecha_despacho: '2026-10-20T10:30', motivo: 'Respaldo pendiente', motivo_autorizacion: 'Conteo físico comprobado', provisional: true } });
});

test('el historial escapa motivos y documentos externos y oculta la acción a lectores', () => {
    const delivery = { id: 'delivery', version: 1, estado: 'vencida', provisional: true, motivo: '<img src=x onerror=alert(1)>', numero_documento: '"><script>bad()</script>', retiros: [{ numero_folio: 'FABC0000001', cantidad_retirada: '4.000' }] };
    const readOnly = dispatchRegistrationDetail(delivery, false);
    assert.doesNotMatch(readOnly, /<img|<script|data-regularize-dispatch/);
    assert.match(readOnly, /FABC0000001/);
    assert.match(dispatchRegistrationDetail(delivery, true), /name="aceptar_fuera_plazo" type="checkbox" required/);
});
