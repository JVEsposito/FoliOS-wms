import type { CameraPlan } from './estiba';
import type {
  OperationalFrontierProposal,
  OperationalFrontierResult,
  OperationalPhysicalFrontierSnapshot,
  OperationalTask,
} from './operationalTasks';
import { calculateRollingFrontier } from './rollingPlanner';

export type FrontierPreview = {
  physicalVersion: string;
  proposals: OperationalFrontierProposal[];
  destinations: Record<string, string>;
};

// El snapshot completo incluye las tareas asumidas por esta tablet: cambia al
// tomar una tarea aunque no cambie ninguna cámara ni decisión física.
export function physicalSnapshotVersion(snapshot: OperationalPhysicalFrontierSnapshot): string {
  return JSON.stringify({
    planner: snapshot.planner,
    arbitraje: [snapshot.arbitraje.ciclo_id, snapshot.arbitraje.snapshot_version, snapshot.arbitraje.vigencia.vigente],
    camaras: [...snapshot.camaras]
      .sort((a, b) => a.id.localeCompare(b.id))
      .map((camera) => [camera.id, camera.version_plano, camera.revision_reservas]),
    reservas: snapshot.frontera.reservas_fisicas_activas,
  });
}

export function previewableTasks(tasks: OperationalTask[]): OperationalTask[] {
  return [...new Map(tasks.map((task) => [task.id, task])).values()]
    .filter((task) => (task.estado === 'pendiente' || task.estado === 'asumida')
      && task.plan.horizon === 'rolling'
      && task.tipo_movimiento !== 'retiro'
      && task.reserva?.tipo_compromiso !== 'fisica'
      && task.maniobra?.estado !== 'pausada_discrepancia'
      && ['seleccionada', 'en_ejecucion', 'fuera_planificador'].includes(task.maniobra?.arbitraje?.decision ?? ''));
}

/** Simula propuestas de la cola completa; ninguna llamada de escritura ocurre aquí. */
export function simulateFrontierPreview(
  tasks: OperationalTask[],
  snapshot: OperationalPhysicalFrontierSnapshot,
  plans: CameraPlan[],
): FrontierPreview {
  const eligible = previewableTasks(tasks).filter((task) => !task.punto_no_retorno
    && (!task.maniobra?.arbitraje?.snapshot_version
      || task.maniobra.arbitraje.snapshot_version === snapshot.arbitraje.snapshot_version));
  const existing = new Set(snapshot.tareas.map((task) => task.id));
  const simulatedSnapshot = {
    ...snapshot,
    tareas: [
      ...snapshot.tareas,
      ...eligible.filter((task) => !existing.has(task.id)).map((task) => ({
        id: task.id,
        version: task.version,
        estado: 'asumida' as const,
        folio_id: task.folio.id,
        camara_origen_id: task.origen?.camara.id ?? null,
        posicion_origen_id: task.origen?.posicion?.id ?? null,
        camara_destino_id: task.destino?.camara.id ?? null,
        posicion_destino_id: task.destino?.posicion?.id ?? null,
        maniobra_id: task.maniobra?.id ?? null,
        secuencia_maniobra: task.secuencia_maniobra,
        plan_version: task.plan.version,
        materializable: true,
      })),
    ],
  };
  const frontier = calculateRollingFrontier(
    eligible.map((task) => ({ ...task, estado: 'asumida' as const })),
    simulatedSnapshot,
    plans,
  );
  const cameraNames = new Map(plans.map((plan) => [plan.id, plan.nombre]));
  return {
    physicalVersion: physicalSnapshotVersion(snapshot),
    proposals: frontier.proposals,
    destinations: Object.fromEntries(frontier.candidates.map((candidate) => [
      candidate.taskId,
      `${cameraNames.get(candidate.cameraId) ?? 'Cámara'} · ${candidate.position.etiqueta ?? `B${candidate.position.banda}-P${candidate.position.posicion}-N${candidate.position.nivel}`}`,
    ])),
  };
}

/** Revalida la parte física y usa las versiones de tarea/plan posteriores al claim. */
export function reusablePreviewProposal(
  preview: FrontierPreview | null,
  current: OperationalPhysicalFrontierSnapshot,
  taken: OperationalTask,
): OperationalFrontierProposal | null {
  if (!preview || !current.arbitraje.vigencia.vigente
    || preview.physicalVersion !== physicalSnapshotVersion(current)) return null;
  const old = preview.proposals.find((proposal) => proposal.tarea_id === taken.id);
  const task = current.tareas.find((item) => item.id === taken.id && item.materializable);
  if (!old || !task || !task.plan_version || taken.reserva?.tipo_compromiso === 'fisica') return null;
  return { ...old, tarea_version: task.version, plan_version: task.plan_version };
}

export function materializationOutcome(result: OperationalFrontierResult, taskId: string) {
  const accepted = result.aceptadas.find((item) => item.tarea.id === taskId)?.tarea ?? null;
  return { accepted, recalculate: !accepted || result.recalcular || result.rechazadas.length > 0 };
}
