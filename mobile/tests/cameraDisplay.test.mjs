import assert from 'node:assert/strict';
import test from 'node:test';
import { cameraDisplayName } from '../src/domain/cameras.ts';

test('la tablet prefiere el nombre y conserva códigos distintos si faltan nombres', () => {
  assert.equal(cameraDisplayName({ nombre: ' Tránsito ', codigo: 'CAM-01' }), 'Tránsito');
  assert.equal(cameraDisplayName({ nombre: ' ', codigo: ' CAM-01 ' }), 'CAM-01');
  assert.notEqual(cameraDisplayName({ codigo: 'CAM-01' }), cameraDisplayName({ codigo: 'CAM-02' }));
  assert.equal(cameraDisplayName(null), 'Cámara sin nombre');
});
