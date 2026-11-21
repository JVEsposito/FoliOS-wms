import assert from 'node:assert/strict';
import test from 'node:test';
import { sagLotAlertText, sagLotAlertsHtml } from '../../resources/js/shared/sag-lot-alerts.js';

test('explica observaciones, restricciones y segregación sin insertar HTML del lote', () => {
    assert.equal(sagLotAlertsHtml([]), '');
    assert.match(sagLotAlertText({ lote: '10056', estado: 'con_observaciones', destinos: [] }), /Con observaciones.*Sin restricción de destino/);
    const alert = { folio: '60001', lote: '<img src=x onerror=alert(1)>', estado: 'retenido', todos_destinos: false,
        destinos: [{ nombre: 'Perú' }], bloquea_salida: true, destino_sin_definir: true, instruccion_segregacion: 'Separar en CAM-09' };
    assert.match(sagLotAlertText(alert), /Liberado con restricciones.*Define el destino.*Perú.*CAM-09/);
    assert.doesNotMatch(sagLotAlertsHtml([alert]), /<img/);
    assert.match(sagLotAlertsHtml([alert]), /&lt;img/);
});

test('ubica los bloqueos reales primero y agrupa los folios del mismo lote e instructivo', () => {
    const otrosDestinos = {
        id: 'regional', lote: '10056', folio: 'FOLIO-1', estado: 'retenido', destinos: [{ nombre: 'Perú' }, { nombre: 'Rusia' }],
        bloquea_salida: false, instructivo_id: 'i1', instructivo_numero: 'INST-CN', destino: 'China',
    };
    const general = { ...otrosDestinos, id: 'general', folio: 'FOLIO-2', lote: '10057', bloquea_salida: true, todos_destinos: true, destinos: [] };
    const resumen = sagLotAlertsHtml([otrosDestinos, general, { ...general, folio: 'FOLIO-3' }]);
    assert.match(resumen, /Lote 10057.*INST-CN.*Bloquea la salida a China.*todos los destinos/);
    assert.match(resumen, /Folios \(2\) · FOLIO-2, FOLIO-3/);
    assert.equal(resumen.match(/Lote 10057/g).length, 1);
    assert.doesNotMatch(resumen, /10056|Perú|Rusia/);
    assert.match(sagLotAlertText({ ...otrosDestinos, bloquea_salida: true, destino: 'Perú' }), /Bloquea la salida a Perú/);
    const alertaInstructivo = sagLotAlertText({ ...otrosDestinos, bloquea_salida: true, destino: null, destino_sin_definir: true });
    assert.match(alertaInstructivo, /Define el destino del instructivo.*Perú, Rusia/);
    const segregacion = sagLotAlertsHtml([{ ...otrosDestinos, instruccion_segregacion: 'Llevar a cámara' }]);
    assert.match(segregacion, /Segregación indicada · Llevar a cámara/);
    assert.doesNotMatch(segregacion, /Perú|Rusia|Liberado con restricciones/);

    const compacta = sagLotAlertsHtml([otrosDestinos, general, general], { compact: true });
    assert.match(compacta, /1 folio bloqueado · China/);
    assert.doesNotMatch(compacta, /<ul|FOLIO-/);
    assert.equal(sagLotAlertsHtml([otrosDestinos], { compact: true }), '');
    assert.equal(sagLotAlertsHtml([{ ...general, cajas_lote: 0 }]), '');
});
