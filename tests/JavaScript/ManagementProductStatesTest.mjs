import test from 'node:test';
import assert from 'node:assert/strict';
import { productStateSegments } from '../../resources/js/shared/management-product-states.js';

test('estado vacío mantiene leyenda con seis conteos a cero', () => {
    const result = productStateSegments({ total_activos: 0 });
    assert.equal(result.empty, true);
    assert.equal(result.segments.length, 6);
    assert.ok(result.segments.every(({ count, width }) => count === 0 && width === 0));
    assert.equal(result.ariaLabel, 'Sin folios PT activos');
});

test('un segmento pequeño conserva ancho visible y la suma visual sigue en 100', () => {
    const result = productStateSegments({ total_activos: 1001, disponibles_despacho: 1000, bloqueados: 1 });
    assert.equal(result.segments[4].count, 1);
    assert.equal(result.segments[4].width, 2);
    assert.ok(Math.abs(result.segments.reduce((sum, segment) => sum + segment.width, 0) - 100) < 0.000001);
    assert.equal(result.segments[4].percent, 0.1);
    assert.match(result.ariaLabel, /Bloqueados 1/);
});

test('porcentajes de la leyenda redondean sin perder los conteos exactos', () => {
    const result = productStateSegments({ total_activos: 3, disponibles_despacho: 1, comprometidos_carga: 2 });
    assert.equal(result.segments[0].percent, 33.3);
    assert.equal(result.segments[1].percent, 66.7);
    assert.equal(result.segments[1].count, 2);
    assert.equal(result.segments[2].width, 0);
});

test('sin ubicación y otros conservan tonos distintos en la barra', () => {
    const { segments } = productStateSegments({ total_activos: 2, pendientes_ubicacion: 1, otros: 1 });
    assert.notEqual(segments[3].tone, segments[5].tone);
});
