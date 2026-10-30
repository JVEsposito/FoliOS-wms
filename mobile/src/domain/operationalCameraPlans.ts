import type { CameraPlan } from './estiba';
import type { OperationalPhysicalFrontierSnapshot, OperationalTask } from './operationalTasks';
import { canRecalculateClearance, isClearanceTask, taskWithCurrentLoadTarget } from './rollingPlanner';

export function candidateCameraIds(tasks: OperationalTask[], snapshot: OperationalPhysicalFrontierSnapshot): string[] {
  const allowed = new Set(snapshot.camaras.map((camera) => camera.id));
  const ids = new Set<string>();
  for (const original of tasks) {
    const task = taskWithCurrentLoadTarget(original, snapshot.tareas.find((item) => item.id === original.id));
    if (task.contexto?.acopio_carga && typeof task.contexto.acopio_carga === 'object'
      && 'despacho_directo' in task.contexto.acopio_carga && task.contexto.acopio_carga.despacho_directo) continue;
    const camera = canRecalculateClearance(task) ? null : task.destino?.camara.id
      ?? (task.tipo_movimiento === 'reubicacion' && !isClearanceTask(task) ? task.origen?.camara.id : null);
    if (camera) { if (allowed.has(camera)) ids.add(camera); }
    else if (task.tipo_movimiento !== 'retiro') allowed.forEach((id) => ids.add(id));
  }
  return [...ids];
}

/** Cache only for proposals. Execution sessions always read the server again. */
export class OperationalCameraPlans {
  private entries = new Map<string, { version: string; expires: number; promise: Promise<CameraPlan> }>();
  constructor(private readonly now = Date.now, private readonly ttlMs = 30_000) {}
  clear() { this.entries.clear(); }

  async load(snapshot: OperationalPhysicalFrontierSnapshot, tasks: OperationalTask[], fetchPlan: (id: string) => Promise<CameraPlan>, current = () => true) {
    const ids = candidateCameraIds(tasks, snapshot);
    const plans = new Map<string, CameraPlan>();
    let next = 0;
    const worker = async () => {
      while (next < ids.length && current()) {
        const id = ids[next++];
        const camera = snapshot.camaras.find((item) => item.id === id)!;
        const version = `${camera.version_plano}:${camera.revision_reservas}:${camera.version_protecciones ?? ''}`;
        let cached = this.entries.get(id);
        if (!cached || cached.version !== version || cached.expires <= this.now()) {
          const entry = { version, expires: this.now() + this.ttlMs, promise: Promise.resolve().then(() => fetchPlan(id)) };
          this.entries.set(id, entry);
          void entry.promise.then((plan) => {
            if (plan.version_plano !== camera.version_plano || plan.revision_reservas !== camera.revision_reservas
              || plan.version_protecciones !== camera.version_protecciones) {
              if (this.entries.get(id) === entry) this.entries.delete(id);
            }
          }, () => { if (this.entries.get(id) === entry) this.entries.delete(id); });
          cached = entry;
        }
        plans.set(id, await cached.promise);
      }
    };
    await Promise.all(Array.from({ length: Math.min(2, ids.length) }, worker));
    return ids.flatMap((id) => plans.has(id) ? [plans.get(id)!] : []);
  }
}
