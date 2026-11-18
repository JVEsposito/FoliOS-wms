import assert from 'node:assert/strict';
import test from 'node:test';
import { packingReturnResultLabel } from '../../resources/js/shared/packing-return-results.js';

test('distingue los códigos de planta y conserva nombres específicos e interfaces anteriores', () => {
    assert.equal(packingReturnResultLabel({ nombre: 'Desecho', prefijo_sublote: 'CEDES' }), 'CEDES · Desecho');
    assert.equal(packingReturnResultLabel({ nombre: 'Descarte', prefijo_sublote: 'CEDCA' }), 'CEDCA · Descarte');
    assert.equal(packingReturnResultLabel({ nombre: 'Congelado', prefijo_sublote: 'CECONG' }), 'CECONG · Congelado');
    assert.equal(packingReturnResultLabel({ nombre: 'Otro resultado', prefijo_sublote: 'OT' }, 'Resultado informado en packing'), 'OT · Resultado informado en packing');
    assert.equal(packingReturnResultLabel({ nombre: 'Comercial' }), 'Comercial');
    assert.equal(packingReturnResultLabel(null), 'Pendiente');
});
