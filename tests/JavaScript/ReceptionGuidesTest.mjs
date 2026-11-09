import test from 'node:test';
import assert from 'node:assert/strict';
import { guideProblem, guideTotals } from '../../resources/js/office-weighbridge-guides.js';

const guides = [
    { numero_guia_despacho: '1511', envases: [{ tipo_envase: 'bins', cantidad: 14 }, { tipo_envase: 'totes', cantidad: 336 }] },
    { numero_guia_despacho: '1512', envases: [{ tipo_envase: 'bins', cantidad: 20 }, { tipo_envase: 'totes', cantidad: 480 }] },
];
test('los totales del camión suman todas sus guías por tipo', () => {
    assert.deepEqual(guideTotals(guides), { bins: 34, totes: 816 });
    assert.equal(guideProblem(guides), '');
});
test('no admite guías repetidas, vacías, fracciones, negativos ni sobrepasar el límite por camión', () => {
    assert.match(guideProblem([guides[0], { ...guides[1], numero_guia_despacho: ' 1511 ' }]), /No repitas/);
    assert.match(guideProblem([{ ...guides[0], numero_guia_despacho: '' }]), /número/);
    assert.match(guideProblem([{ ...guides[0], envases: [] }]), /positivas/);
    for (const cantidad of [-1, 0, 0.5, 100001]) assert.match(guideProblem([{ ...guides[0], envases: [{ tipo_envase: 'bins', cantidad }] }]), /positivas/);
    assert.match(guideProblem(guides.map((g) => ({ ...g, envases: [{ tipo_envase: 'bins', cantidad: 60000 }] }))), /total del camión/);
});
