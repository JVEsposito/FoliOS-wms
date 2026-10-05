import test from 'node:test';
import assert from 'node:assert/strict';
import { dispatchInspectionPayload, outgoingInspectionRows } from '../../resources/js/office-container-inspection.js';
test('inspección usa solo los tipos con salida positiva y exige limpieza y condición', () => {
    assert.deepEqual(outgoingInspectionRows([{ tipo_envase: 'bins', cantidad: 2 }, { tipo_envase: 'totes', cantidad: 0 }]), ['bins']);
    assert.throws(() => dispatchInspectionPayload(['bins'], {}), /Completa limpieza/);
    const result = dispatchInspectionPayload(['bins'], { bins: { limpieza: 'no', condicion: 'regular', nota: ' rota ' } }, ' Revisado ');
    assert.deepEqual(result, { items: [{ tipo_envase: 'bins', limpieza: false, condicion: 'regular', nota: 'rota' }], observacion: 'Revisado' });
    assert.equal('cantidad' in result.items[0], false);
});
