import assert from 'node:assert/strict';
import test from 'node:test';
import { processOrderChanges } from '../../resources/js/shared/process-order-changes.js';

const before = { lote_materia_prima_id: 'lote', numero_lote: 'L-1', bins_programados: 6, bins_entregados: 4, embalaje: 'CAJA-5', kilos_por_caja: 5 };

test('cambiar solo embalaje mantiene seis programados y aplica a dos pendientes', () => {
    const changes = processOrderChanges([before], [{ ...before, embalaje: 'CAJA-2', kilos_por_caja: 2 }]);
    assert.equal(changes.released, 0);
    assert.deepEqual(changes.packaging, [{ lot: 'L-1', delivered: 4, pending: 2 }]);
});

test('poner cuatro en total programado informa que se liberan los dos pendientes', () => {
    const changes = processOrderChanges([before], [{ ...before, bins_programados: '4' }]);
    assert.equal(changes.released, 2);
    assert.deepEqual(changes.reductions[0], { lot: 'L-1', before: 6, after: 4, delivered: 4, pending: 0, released: 2 });
});

test('quitar un lote libera su programación y agregar otro no oculta esa reducción', () => {
    assert.equal(processOrderChanges([{ ...before, bins_entregados: 0 }], [{ lote_materia_prima_id: 'otro', bins_programados: 6 }]).released, 6);
    assert.deepEqual(processOrderChanges([], []), { reductions: [], packaging: [], released: 0 });
});
