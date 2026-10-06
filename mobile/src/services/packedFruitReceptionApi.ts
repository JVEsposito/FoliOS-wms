import type { Reception, ReceptionOptions, ReceptionPayload } from '../domain/packedFruitReception';
import type { ValidationCatalog } from '../domain/validation';
import { recepcionEmbaladaAcciones } from './recepcionEmbaladaAcciones';
import * as Crypto from 'expo-crypto';
import { ApiError } from './apiError';
const root = '/api/recepciones-fruta-embalada';
export function createPackedFruitReceptionApi(baseUrl: string, token: string) {
  let cached: ValidationCatalog | null = null;
  let etag: string | null = null;
  async function request<T>(path: string, init: RequestInit = {}): Promise<T> {
    if (!baseUrl) throw new ApiError('Configura el servidor para capturar una recepción.', 0);
    const headers = new Headers(init.headers);
    headers.set('Accept', 'application/json'); headers.set('Authorization', `Bearer ${token}`);
    if (init.body) headers.set('Content-Type', 'application/json');
    let response: Response;
    try { response = await fetch(`${baseUrl}${root}${path}`, { ...init, headers }); }
    catch { throw new ApiError('No hay conexión con el servidor. Reintenta cuando vuelva la red.', 0); }
    const data = await response.json().catch(() => ({}));
    if (!response.ok) throw new ApiError(Object.values(data.errors ?? {}).flat()[0] as string ?? data.message ?? 'No fue posible completar la recepción.', response.status, data);
    return data as T;
  }
  return {
    actions: recepcionEmbaladaAcciones((path, init) => request(path.slice(root.length), init), () => Crypto.randomUUID()),
    options: () => request<ReceptionOptions>('/opciones'),
    async catalog(): Promise<ValidationCatalog> {
      if (!baseUrl) throw new ApiError('Configura el servidor.', 0);
      let response: Response;
      try { response = await fetch(`${baseUrl}${root}/catalogo-pt`, { headers: { Accept: 'application/json', Authorization: `Bearer ${token}`, ...(etag && cached ? { 'If-None-Match': etag } : {}) } }); }
      catch { throw new ApiError('No hay conexión para actualizar el catálogo.', 0); }
      if (response.status === 304 && cached) return cached;
      const data = await response.json();
      if (!response.ok) throw new ApiError(data.message ?? 'No fue posible cargar el catálogo PT.', response.status, data);
      cached = data as ValidationCatalog; etag = response.headers.get('ETag'); return cached;
    },
    list: (page = 1) => request<{ data: Reception[]; last_page: number; current_page: number }>(`?page=${page}`),
    detail: async (id: string) => (await request<{ data: Reception }>(`/${id}`)).data,
    checkFolio: (folio: string) => request<{ repetido: boolean; mensaje: string | null }>(`/revisar-folio?${new URLSearchParams({ folio_origen: folio })}`),
    reviewGuide: (payload: Pick<ReceptionPayload, 'cliente_id' | 'planta_origen_id' | 'numero_guia'>, id?: string) => request<{ duplicada: boolean; mensaje: string | null }>(`/revisar-guia?${new URLSearchParams({ ...payload, ...(id ? { excluir_id: id } : {}) })}`),
    save: async (payload: ReceptionPayload, id?: string) => (await request<{ data: Reception }>(id ? `/${id}` : '', { method: id ? 'PUT' : 'POST', body: JSON.stringify(payload) })).data,
  };
}
