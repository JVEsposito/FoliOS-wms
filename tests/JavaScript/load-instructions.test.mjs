import test from 'node:test';
import assert from 'node:assert/strict';
import { availableInstructionSlots, groupedLoadFolios, selectedInstruction } from '../../resources/js/shared/load-instructions.js';

const load = { total_folios: 9, segregaciones: [
    { id: 'F001', pallets_programados: 7, pallets_asignados: 7 },
    { id: 'F002', pallets_programados: 7, pallets_asignados: 2 },
], folios: [{ id: 1, instructivo_embarque_id: 'F002' }, { id: 2, instructivo_embarque_id: 'F001' }, { id: 3, instructivo_embarque_id: null }] };
test('exige selección cuando hay varios instructivos, respeta el cupo por grupo y el máximo de la orden', () => {
    assert.equal(selectedInstruction(load, null), null);
    assert.equal(availableInstructionSlots(load, null), 0);
    assert.equal(availableInstructionSlots(load, 'F001'), 0);
    assert.equal(availableInstructionSlots(load, 'F002'), 5);
    assert.equal(availableInstructionSlots({ ...load, total_folios: 25 }, 'F002'), 1);
    assert.equal(availableInstructionSlots({ total_folios: 10 }, null), 16);
    assert.equal(selectedInstruction({ segregaciones: [load.segregaciones[0]] }, null).id, 'F001');
});
test('separa por identidad de instructivo y conserva visibles los pallets sin clasificación', () => {
    const grouped = groupedLoadFolios(load);
    assert.deepEqual(grouped.map((group) => group.folios.map((folio) => folio.id)), [[2], [1], [3]]);
    assert.equal(grouped[2].instruction, null);
    assert.equal(load.folios[0].id, 1);
});
