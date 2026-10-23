import AsyncStorage from '@react-native-async-storage/async-storage';
import type { LocatePayload, MovePayload, SendLoadFolioToDockPayload } from '../domain/estiba';
import type { TemporaryExtractionPayload } from '../domain/operationalTasks';

export type PendingOperationalMovement = {
  id: string;
  taskId: string;
  folio: string;
  kind: 'locate' | 'move' | 'dock' | 'temporary' | 'prefrio';
  assignmentId?: string;
  payload: LocatePayload | MovePayload | SendLoadFolioToDockPayload | TemporaryExtractionPayload | Record<string, never>;
  createdAt: string;
};

export class OperationalMovementStore {
  private static writes = new Map<string, Promise<unknown>>();
  private readonly key: string;
  constructor(server: string, user: string, device: string) {
    this.key = `estiba_guided_confirmation:${encodeURIComponent(server)}:${user}:${device}`;
  }
  async read(): Promise<PendingOperationalMovement | null> {
    const raw = await AsyncStorage.getItem(this.key);
    if (!raw) return null;
    let data: PendingOperationalMovement;
    try { data = JSON.parse(raw) as PendingOperationalMovement; }
    catch { throw new Error('No se pudo leer la confirmación guardada. Conserva el pallet y solicita revisión; la captura sigue en esta tablet.'); }
    if (!data?.id || !data.taskId || !data.folio || !data.payload
      || !['locate', 'move', 'dock', 'temporary', 'prefrio'].includes(data.kind)
      || (data.kind === 'dock' && !data.assignmentId)
      || (data.kind !== 'prefrio' && data.payload.operacion_id !== data.id)) {
      throw new Error('No se pudo leer la confirmación guardada. Conserva el pallet y solicita revisión; la captura sigue en esta tablet.');
    }
    return data;
  }
  async save(data: PendingOperationalMovement) {
    return this.write(async () => {
      const previous = await this.read();
      if (previous && (previous.id !== data.id || previous.taskId !== data.taskId || previous.kind !== data.kind)) {
        throw new Error('Hay una entrega pendiente de confirmar. Revísala antes de registrar otra.');
      }
      await AsyncStorage.setItem(this.key, JSON.stringify(data));
    });
  }
  async clear(id: string) {
    return this.write(async () => {
      const current = await this.read();
      if (current?.id === id) await AsyncStorage.removeItem(this.key);
    });
  }
  private async write(action: () => Promise<void>) {
    const previous = OperationalMovementStore.writes.get(this.key) ?? Promise.resolve();
    const pending = previous.catch(() => undefined).then(action);
    OperationalMovementStore.writes.set(this.key, pending);
    try { await pending; }
    finally { if (OperationalMovementStore.writes.get(this.key) === pending) OperationalMovementStore.writes.delete(this.key); }
  }
}
