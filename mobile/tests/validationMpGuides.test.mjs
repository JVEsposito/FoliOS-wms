import test from 'node:test';
import assert from 'node:assert/strict';
import { guideSegments, receptionGuideNumbers, segmentGuideProblem } from '../src/domain/validationMpGuides.ts';
const reception = { numero_guia_despacho: '1511 / 1512', guias: [
    { id: 'guide-1', numero_guia_despacho: '1511', envases: [{ tipo_envase: 'bins', cantidad: 2 }] },
    { id: 'guide-2', numero_guia_despacho: '1512', envases: [{ tipo_envase: 'bins', cantidad: 3 }, { tipo_envase: 'totes', cantidad: 72 }] },
] };
test('prepara un segmento por guía con sus cantidades declaradas sin inventar su origen', () => {
    let seq = 0; const segments = guideSegments(reception, () => `${++seq}`);
    assert.deepEqual(segments.map((s) => s.cantidades), [{ bins: '2' }, { bins: '3', totes: '72' }]);
    assert.deepEqual(segments.map((s) => s.guia_recepcion_romana_id), ['guide-1', 'guide-2']);
    assert.ok(segments.every((s) => s.motivos.includes('guia') && s.csg_validacion_id === null && s.variedad_validacion_id === null));
    assert.equal(segmentGuideProblem(reception, segments), null);
    assert.equal(receptionGuideNumbers(reception), '1511 / 1512');
    assert.match(segmentGuideProblem(reception, [segments[0]]), /1512/);
    assert.match(segmentGuideProblem(reception, [{ ...segments[0], guia_recepcion_romana_id: 'foreign' }]), /cada segmento/);
    assert.equal(segmentGuideProblem(reception, [...segments, { ...segments[0], key: '3' }]), null);
});
test('las recepciones anteriores mantienen el flujo de guía única', () => {
    const legacy = { numero_guia_despacho: 'GD-ANTES' };
    assert.deepEqual(guideSegments(legacy, () => 'key'), []);
    assert.equal(receptionGuideNumbers(legacy), 'GD-ANTES');
    assert.equal(segmentGuideProblem(legacy, []), null);
});
