import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
const source = await readFile(new URL('../../resources/js/shared/load-cluster-location.js', import.meta.url), 'utf8');
const { loadClusterLocation } = await import(`data:text/javascript,${encodeURIComponent(source)}`);

test('muestra las bandas exactas y todos los niveles, sin atribuir bandas intermedias', () => {
    const cluster = { camara: { codigo: 'CAM-07', nombre: 'Tránsito 07' }, nivel: 1, niveles: [1, 2], bandas: [1, 4], banda_desde: 1, banda_hasta: 4 };
    assert.equal(loadClusterLocation(cluster), 'CAM-07 · niveles 1, 2 · bandas 1, 4');
    assert.equal(loadClusterLocation({ ...cluster, bandas: [1, 2] }, true), 'Tránsito 07 · niveles 1, 2 · bandas 1–2');
    assert.equal(loadClusterLocation({ camara: { codigo: 'CAM-01' }, nivel: 1, banda_desde: 2, banda_hasta: 3 }), 'CAM-01 · nivel 1 · bandas 2–3');
});
