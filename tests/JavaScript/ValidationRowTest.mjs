import assert from 'node:assert/strict';
import test from 'node:test';
import { validationDayContext, validationJourneyLabel, validationOriginLabel } from '../../resources/js/shared/validation-row.js';

test('el origen conserva ceros iniciales, deduplica CSG y enumera composiciones mixtas', () => {
    assert.equal(validationOriginLabel({ origen: { csg: '0977' } }), 'CSG 0977');
    assert.equal(validationOriginLabel({ composicion: [{ csg: '1802' }, { csg: '0977' }, { csg: '1802' }] }), 'CSG 1802 y CSG 0977');
    assert.equal(validationOriginLabel({ composicion: [{ csg: '1' }, { csg: '2' }, { csg: '3' }] }), 'CSG 1, CSG 2 y CSG 3');
});

test('la jornada muestra la hora de terreno en Santiago y distingue registros históricos', () => {
    const item = { generado_dispositivo_at: '2026-07-30T03:30:00Z', linea_proceso: 2, turno: 'A', dispositivo: { codigo: 'PDA 02' } };
    assert.equal(validationJourneyLabel(item), 'Línea 2, turno A, 23:30 · PDA 02');
    assert.match(validationJourneyLabel({ ...item, linea_proceso: null }), /^Sin jornada histórica · .*23:30$/);
});

test('el indicador usa el día operacional y no cambia una fecha histórica al convertirla a UTC', () => {
    const now = new Date('2026-07-30T03:30:00Z');
    assert.deepEqual(validationDayContext('2026-07-29', 'America/Santiago', now), { label: 'hoy', title: 'Por línea, hoy' });
    assert.deepEqual(validationDayContext('2026-07-28', 'America/Santiago', now), { label: 'el 28-07-2026', title: 'Por línea, 28-07-2026' });
});
