import { File, Paths } from 'expo-file-system';
import * as Sharing from 'expo-sharing';
import type { SagDocument, SagKind, SagLine, SagPayload, SagSource } from '../domain/sagCounterpart';
import { ApiError } from './apiError';
import { fetchWithTimeout } from './httpClient';

export type SagPage = { data: SagDocument[]; current_page: number; last_page: number };
export type SagConfiguration = { puede_gestionar: boolean; responsables: { id: string; nombre: string; activo: boolean }[] };
export function createSagCounterpartApi(baseUrl: string, token: string) {
  const root = baseUrl.replace(/\/+$/, '') + '/api/contraparte-sag';
  async function response(path: string, init: RequestInit = {}) {
    if (!baseUrl) throw new ApiError('Configura el servidor.', 0);
    try { return await fetchWithTimeout(root + path, { ...init, headers: { Accept: 'application/json', Authorization: `Bearer ${token}`, ...(init.body ? { 'Content-Type': 'application/json' } : {}) } }); }
    catch { throw new ApiError('No hay respuesta del servidor. Reintenta el mismo envío cuando vuelva la conexión.', 0); }
  }
  async function request<T>(path: string, init: RequestInit = {}): Promise<T> {
    const r = await response(path, init); const data = await r.json().catch(() => ({}));
    if (!r.ok) throw new ApiError(Object.values(data.errors ?? {}).flat().slice(0, 4).join('\n') || data.message || 'No se pudo completar el registro SAG.', r.status, data);
    return data as T;
  }
  return {
    configuration: () => request<SagConfiguration>('/configuracion'),
    list: (page = 1, tipo: SagKind | '' = '', estado = '') => request<SagPage>(`/documentos?${new URLSearchParams({ page: String(page), tipo, estado })}`),
    detail: (id: string) => request<SagDocument>(`/documentos/${encodeURIComponent(id)}`),
    source: (type: SagKind, ref: string) => request<SagSource>(`/fuentes/${type}/${encodeURIComponent(ref)}`),
    folio: (number: string) => request<SagLine>(`/folios/${encodeURIComponent(number)}`),
    save: (payload: SagPayload, id?: string) => request<SagDocument>(`/documentos${id ? '/' + encodeURIComponent(id) : ''}`, { method: id ? 'PUT' : 'POST', body: JSON.stringify(payload) }),
    act: (id: string, action: string, data: { operacion_id: string; version: number; referencia?: string; resuelto_por?: string }) => request<SagDocument>(`/documentos/${encodeURIComponent(id)}/${action}`, { method: 'POST', body: JSON.stringify(data) }),
    async share(id: string, format: 'pdf' | 'xlsx') {
      if (!await Sharing.isAvailableAsync()) throw new Error('Este equipo no permite compartir archivos. Descarga desde Oficina.');
      const r = await response(`/documentos/${encodeURIComponent(id)}/archivo/${format}`);
      if (!r.ok) { const data = await r.json().catch(() => ({})); throw new ApiError(data.message || 'No se pudo descargar.', r.status, data); }
      const bytes = new Uint8Array(await r.arrayBuffer());
      if (format === 'pdf' ? bytes[0] !== 0x25 || bytes[1] !== 0x50 : bytes[0] !== 0x50 || bytes[1] !== 0x4b) throw new Error('El servidor no devolvió el documento solicitado.');
      const f = new File(Paths.cache, `sag-${id}-${Date.now()}.${format}`); f.write(bytes);
      await Sharing.shareAsync(f.uri, { mimeType: format === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', dialogTitle: 'Documento SAG' });
    },
  };
}
