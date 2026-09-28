import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { stripTypeScriptTypes } from 'node:module';
import test from 'node:test';

const planner = stripTypeScriptTypes(await readFile(new URL('../../mobile/src/domain/rollingPlanner.ts', import.meta.url), 'utf8'));
const plannerUrl = `data:text/javascript,${encodeURIComponent(planner)}`;
const previewSource = stripTypeScriptTypes(await readFile(new URL('../../mobile/src/domain/rollingPlannerPreview.ts', import.meta.url), 'utf8'))
    .replace("from './rollingPlanner'", `from ${JSON.stringify(plannerUrl)}`);
const { simulateFrontierPreview, physicalSnapshotVersion, reusablePreviewProposal, materializationOutcome } =
    await import(`data:text/javascript,${encodeURIComponent(previewSource)}`);

const snapshot = () => ({
    snapshot_version: 'antes-del-claim',
    planner: { horizon: 'rolling', compute: 'tablet', frontier_max: 2, camara_preferente_despacho_id: null },
    arbitraje: { ciclo_id: 'ciclo-1', snapshot_version: 'arbitraje-1', vigencia: { vigente: true } },
    frontera: { reservas_fisicas_activas: 0 },
    camaras: [{ id: 'cam-1', version_plano: 4, revision_reservas: 2 }],
    tareas: [],
});
const plan = {
    id: 'cam-1', nombre: 'Cámara 1', version_plano: 4, contenido: 'productos', estado: 'activa',
    posiciones: [1, 2].map((position) => ({ id: `pos-${position}`, etiqueta: `B01-P0${position}-N1`, banda: 1,
        posicion: position, nivel: 1, estado: 'activa', ocupada: false, reservada: false })),
    bandas_operacionales: [{ numero: 1, acepta_nuevos_ingresos: true, usos_permitidos: ['transito_pt'],
        modo: 'operativa', estado: 'libre', capacidad: { disponibles: 2 } }],
};
const task = (id, priority = 'normal') => ({
    id, estado: 'pendiente', version: 1, secuencia: 1, prioridad: priority, tipo_movimiento: 'ubicacion_inicial',
    punto_no_retorno: false, reserva: null, origen: null, destino: null, contexto: {},
    folio: { id: `folio-${id}` }, plan: { version: 2, horizon: 'rolling' },
    maniobra: { id: `maniobra-${id}`, estado: 'pendiente', arbitraje: { decision: 'seleccionada' },
        beneficio_estimado: 0, costo_movimientos: 0, riesgo_operacional: 0 },
});

test('simula conjuntamente la cola publicada y propone posiciones distintas sin reservar', () => {
    const before = snapshot();
    const camera = structuredClone(plan);
    const result = simulateFrontierPreview([task('b'), task('a', 'alta')], before, [camera]);
    assert.deepEqual(result.proposals.map((item) => item.tarea_id), ['a', 'b']);
    assert.deepEqual(new Set(result.proposals.map((item) => item.posicion_destino_id)), new Set(['pos-1', 'pos-2']));
    assert.match(result.destinations.a, /Cámara 1 · B01-P01-N1/);
    assert.deepEqual(before.tareas, []);
    assert.equal(camera.posiciones[0].reservada, false);
});

test('reutiliza la posición después del claim si la versión física persiste, con versiones nuevas de tarea y plan', () => {
    const before = snapshot();
    const preview = simulateFrontierPreview([task('a')], before, [plan]);
    const after = { ...snapshot(), snapshot_version: 'cambio-por-claim', tareas: [
        { id: 'a', version: 2, plan_version: 3, materializable: true },
    ] };
    assert.equal(physicalSnapshotVersion(before), physicalSnapshotVersion(after));
    assert.deepEqual(reusablePreviewProposal(preview, after, { id: 'a' }), {
        ...preview.proposals[0], tarea_version: 2, plan_version: 3,
    });
});

test('un plano o arbitraje cambiado descarta la propuesta anticipada', () => {
    const preview = simulateFrontierPreview([task('a')], snapshot(), [plan]);
    const after = { ...snapshot(), tareas: [{ id: 'a', version: 2, plan_version: 3, materializable: true }] };
    after.camaras[0].revision_reservas += 1;
    assert.equal(reusablePreviewProposal(preview, after, { id: 'a' }), null);
    after.camaras[0].revision_reservas -= 1;
    after.arbitraje.snapshot_version = 'arbitraje-nuevo';
    assert.equal(reusablePreviewProposal(preview, after, { id: 'a' }), null);
});

test('no sugiere destinos a tareas publicadas por un ciclo de arbitraje anterior', () => {
    const outdated = task('a');
    outdated.maniobra.arbitraje.snapshot_version = 'arbitraje-anterior';
    assert.deepEqual(simulateFrontierPreview([outdated], snapshot(), [plan]).proposals, []);
});

test('un rechazo parcial obliga a recalcular el ancla, incluso si otra propuesta fue aceptada', () => {
    const result = { aceptadas: [{ tarea: { id: 'otra' } }],
        rechazadas: [{ tarea_id: 'a', motivo: 'Posición ocupada' }], recalcular: true };
    assert.deepEqual(materializationOutcome(result, 'a'), { accepted: null, recalculate: true });
    assert.deepEqual(materializationOutcome(result, 'otra'), { accepted: { id: 'otra' }, recalculate: true });
});
