import assert from 'node:assert/strict';
import test from 'node:test';
import { repackingBalance, weightUnits } from '../../resources/js/shared/repacking-balance.js';
const context = { componentes: [{ clave: 'a', kilos_entrada: '5.0000' }, { clave: 'b', kilos_entrada: '5.0000' }],
    formatos: [{ id: '2.5', kilos_netos_por_caja: '2.5000' }, { id: '2', kilos_netos_por_caja: '2.0000' }] };
test('convierte 5 kg a dos cajas de 2,5 por componente sin merma', () => {
    const balance = repackingBalance(context, [{ envase_validacion_id: '2.5', cantidades: { a: '2', b: '2' } }], {});
    assert.equal(balance.balanced, true); assert.equal(balance.input, 100000); assert.equal(balance.output, 100000);
});
test('dos cajas de 2 kg necesitan merma declarada; el global no oculta un CSG desbalanceado', () => {
    assert.equal(repackingBalance(context, [{ envase_validacion_id: '2', cantidades: { a: '2', b: '2' } }], {}).balanced, false);
    assert.equal(repackingBalance(context, [{ envase_validacion_id: '2', cantidades: { a: '2', b: '2' } }], { a: '1', b: '1' }).balanced, true);
    const crossed = repackingBalance(context, [{ envase_validacion_id: '2.5', cantidades: { a: '3', b: '1' } }], {});
    assert.equal(crossed.input, crossed.output); assert.equal(crossed.balanced, false);
});
test('decimales exactos, sin tolerancias ocultas, cajas fraccionadas ni valores negativos', () => {
    assert.equal(weightUnits('0.0001'), 1); assert.equal(weightUnits('2.5'), 25000);
    for (const bad of ['-1', 'NaN', '0.00001', '1e3']) assert.equal(weightUnits(bad), null);
    assert.equal(repackingBalance(context, [{ envase_validacion_id: '2.5', cantidades: { a: '1.5', b: '2.5' } }], {}).balanced, false);
});
