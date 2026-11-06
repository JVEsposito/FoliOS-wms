import assert from 'node:assert/strict';
import test from 'node:test';

import { cameraDisplayName } from '../../resources/js/shared/camera-display.js';

test('muestra el nombre operacional de la cámara sin exponer su código', () => {
    assert.equal(
        cameraDisplayName({ codigo: 'CAM-04', nombre: 'Bodega Principal' }),
        'Bodega Principal',
    );
});

test('conserva el código como respaldo si la cámara no tiene nombre', () => {
    assert.equal(
        cameraDisplayName({ codigo: 'CAM-04', nombre: '   ' }),
        'CAM-04',
    );
});

test('distingue dos cámaras sin nombre y solo usa el genérico sin identificadores', () => {
    assert.notEqual(cameraDisplayName({ codigo: 'CAM-01' }), cameraDisplayName({ codigo: 'CAM-02' }));
    assert.equal(cameraDisplayName({ codigo: '   ', nombre: null }), 'Cámara sin nombre');
    assert.equal(cameraDisplayName(null), 'Cámara sin nombre');
});
