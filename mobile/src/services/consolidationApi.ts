import { File, Paths } from 'expo-file-system';
import * as Sharing from 'expo-sharing';
import type { ConsolidationCatalog, ConsolidationControl, ConsolidationLoad, ConsolidationPhoto, ConsolidationValues } from '../domain/consolidation';
import { ApiError } from './apiError';
import { fetchWithTimeout } from './httpClient';

export type PositionPayload = {
  operacion_id: string; numero_folio: string; posicion: number; codigo_pe: string | null; cajas: number | null;
  temperatura: number | null; compartir_posicion: boolean; generado_dispositivo_at: string;
};
export type SavePayload = { operacion_id: string; version: number; encabezado?: ConsolidationValues; inspeccion?: ConsolidationValues; cierre?: ConsolidationValues };

/** Planilla R-24 de consolidación. Cada escritura lleva su operacion_id: se puede reintentar. */
export function createConsolidationApi(baseUrl: string, token: string) {
  const root = baseUrl.replace(/\/+$/, '') + '/api/control-despacho';
  async function response(path: string, init: RequestInit = {}, timeout?: number) {
    if (!baseUrl) throw new ApiError('Configura el servidor.', 0);
    const json = typeof init.body === 'string';
    try {
      return await fetchWithTimeout(root + path, { ...init, headers: { Accept: 'application/json', Authorization: `Bearer ${token}`, ...(json ? { 'Content-Type': 'application/json' } : {}) } }, timeout);
    } catch {
      throw new ApiError('No hay respuesta del servidor. Reintenta el mismo envío cuando vuelva la conexión.', 0);
    }
  }
  async function request<T>(path: string, init: RequestInit = {}, timeout?: number): Promise<T> {
    const r = await response(path, init, timeout);
    if (r.status === 204) return null as T;
    const data = await r.json().catch(() => ({}));
    if (!r.ok) throw new ApiError(Object.values(data.errors ?? {}).flat().slice(0, 4).join('\n') || data.message || 'No se pudo completar el registro de consolidación.', r.status, data);
    return data as T;
  }
  const control = (id: string) => `/controles/${encodeURIComponent(id)}`;

  return {
    catalog: async () => (await request<{ data: ConsolidationCatalog }>('/catalogo')).data,
    loads: async () => (await request<{ data: ConsolidationLoad[] }>('/cargas')).data,
    detail: async (id: string) => (await request<{ data: ConsolidationControl }>(control(id))).data,
    start: async (loadId: string, operacionId: string) =>
      (await request<{ data: ConsolidationControl }>(`/cargas/${encodeURIComponent(loadId)}/control`, { method: 'POST', body: JSON.stringify({ operacion_id: operacionId }) })).data,
    save: async (id: string, payload: SavePayload) =>
      (await request<{ data: ConsolidationControl }>(control(id), { method: 'PUT', body: JSON.stringify(payload) })).data,
    position: async (id: string, payload: PositionPayload) =>
      (await request<{ data: ConsolidationControl }>(`${control(id)}/posiciones`, { method: 'POST', body: JSON.stringify(payload) })).data,
    removePosition: async (id: string, positionId: string, operacionId: string) =>
      (await request<{ data: ConsolidationControl }>(`${control(id)}/posiciones/${encodeURIComponent(positionId)}`, { method: 'DELETE', body: JSON.stringify({ operacion_id: operacionId }) })).data,
    completeTemperatures: async (id: string, operacionId: string, temperaturas: { posicion_id: string; temperatura: number }[]) =>
      (await request<{ data: ConsolidationControl }>(`${control(id)}/temperaturas`, { method: 'POST', body: JSON.stringify({ operacion_id: operacionId, temperaturas }) })).data,
    close: async (id: string, operacionId: string, version: number) =>
      (await request<{ data: ConsolidationControl }>(`${control(id)}/cerrar`, { method: 'POST', body: JSON.stringify({ operacion_id: operacionId, version }) })).data,
    async uploadPhoto(id: string, tipo: string, operacionId: string, archivo: { uri: string; name: string; type: string }): Promise<ConsolidationPhoto> {
      const body = new FormData();
      body.append('tipo', tipo);
      body.append('operacion_id', operacionId);
      body.append('archivo', archivo as unknown as Blob);
      return (await request<{ data: ConsolidationPhoto }>(`${control(id)}/fotos`, { method: 'POST', body }, 60_000)).data;
    },
    removePhoto: (id: string, photoId: string) => request<null>(`${control(id)}/fotos/${encodeURIComponent(photoId)}`, { method: 'DELETE' }),
    /** Miniatura autenticada como data URI: no queda en la caché de imágenes del equipo. */
    async thumbnail(path: string): Promise<string | null> {
      const r = await fetchWithTimeout(baseUrl.replace(/\/+$/, '') + path, { headers: { Accept: 'image/jpeg', Authorization: `Bearer ${token}` }, cache: 'no-store' }).catch(() => null);
      if (!r?.ok) return null;
      const blob = await r.blob();
      return new Promise((resolve) => {
        const reader = new FileReader();
        reader.onload = () => resolve(String(reader.result));
        reader.onerror = () => resolve(null);
        reader.readAsDataURL(blob);
      });
    },
    async shareSheet(id: string, code: string) {
      if (!await Sharing.isAvailableAsync()) throw new Error('Este equipo no permite compartir archivos. Descarga la planilla desde Oficina.');
      const r = await response(`${control(id)}/planilla.xlsx`, {}, 60_000);
      if (!r.ok) { const data = await r.json().catch(() => ({})); throw new ApiError(data.message || 'No se pudo descargar la planilla.', r.status, data); }
      const bytes = new Uint8Array(await r.arrayBuffer());
      if (bytes[0] !== 0x50 || bytes[1] !== 0x4b) throw new Error('El servidor no devolvió la planilla R-24.');
      const file = new File(Paths.cache, `R-24_${code.replace(/[^A-Za-z0-9_-]+/g, '_')}_${Date.now()}.xlsx`);
      file.write(bytes);
      await Sharing.shareAsync(file.uri, { mimeType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', dialogTitle: 'Planilla R-24' });
    },
  };
}

export type ConsolidationApi = ReturnType<typeof createConsolidationApi>;
