import type { OperationalPhysicalFrontierSnapshot } from './operationalTasks';

/** El servidor mantiene el arbitraje de nuevas labores; revalida los claims ya concedidos. */
export function resourceFrontier(snapshot: OperationalPhysicalFrontierSnapshot): OperationalPhysicalFrontierSnapshot {
  if (snapshot.contrato_recursos !== 'recursos_v1') return snapshot;
  return { ...snapshot, tareas: snapshot.tareas.map((task) => ({
    ...task, materializable: task.materializable_por_recursos === true,
  })) };
}

/** Reintenta solamente la propuesta, nunca el retiro ni el movimiento físico. */
export async function recoverPhysicalFrontier<T>(operation: () => Promise<T>, onRetry: () => void,
  current: () => boolean = () => true, wait: (ms: number) => Promise<void> = (ms) => new Promise((resolve) => setTimeout(resolve, ms))): Promise<T> {
  for (let attempt = 0; ; attempt += 1) {
    if (!current()) throw new Error('La sesión cambió durante la actualización del destino.');
    try { return await operation(); }
    catch (error) {
      const status = (error as { status?: number }).status;
      if (attempt >= 2 || ![409, 503].includes(status ?? 0) || !current()) throw error;
      onRetry();
      await wait(250 * (attempt + 1));
    }
  }
}
