import test from 'node:test';
import assert from 'node:assert/strict';
import { plannerProcessCause, plannerProcessWarnings } from '../../resources/js/shared/planner-process-health.js';

test('explica la causa del cálculo atrasado y ambos avisos operacionales', () => {
    const processes = {
        worker: { estado: 'detenido', ultimo_latido_at: '2026-09-28T11:52:00Z' },
        scheduler: { estado: 'detenido', ultimo_latido_at: null },
    };
    assert.equal(plannerProcessCause(processes), 'cola detenida y programador detenido');
    assert.deepEqual(plannerProcessWarnings(processes, () => '11:52'), [
        'Procesador de cola detenido. Último latido 11:52: el planificador no puede recalcular.',
        'Programador de tareas detenido. Sin latido registrado: no se ejecutan las tareas programadas.',
    ]);
    assert.deepEqual(plannerProcessWarnings({ worker: { estado: 'activo' }, scheduler: { estado: 'activo' } }), []);
});
