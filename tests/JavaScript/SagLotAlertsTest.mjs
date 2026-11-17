import assert from 'node:assert/strict';
import test from 'node:test';
import { sagLotAlertText, sagLotAlertsHtml } from '../../resources/js/shared/sag-lot-alerts.js';

test('explica observaciones, restricciones y segregación sin insertar HTML del lote', () => {
    assert.equal(sagLotAlertsHtml([]), '');
    assert.match(sagLotAlertText({ lote: '10056', estado: 'con_observaciones', destinos: [] }), /Con observaciones.*Sin restricción de destino/);
    const alert = { folio: '60001', lote: '<img src=x onerror=alert(1)>', estado: 'retenido', todos_destinos: true, destinos: [], destino_sin_definir: true, instruccion_segregacion: 'Separar en CAM-09' };
    assert.match(sagLotAlertText(alert), /todos los destinos.*Define el destino.*CAM-09/);
    assert.doesNotMatch(sagLotAlertsHtml([alert]), /<img/);
    assert.match(sagLotAlertsHtml([alert]), /&lt;img/);
});
