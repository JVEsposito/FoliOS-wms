import type { GatehouseClient, GatehouseDetail, GatehouseEntry, GatehousePage, GatehousePayload } from '../domain/gatehouse';
import { ApiError } from './apiError';
import { fetchWithTimeout } from './httpClient';

export function createGatehouseApi(baseUrl: string, token: string) {
  async function request<T>(path: string, init: RequestInit = {}): Promise<T> {
    if (!baseUrl) throw new ApiError('Configura el servidor para usar Portería.', 0);
    let response: Response;
    try {
      response = await fetchWithTimeout(baseUrl.replace(/\/+$/, '') + '/api/porteria' + path, {
        ...init, headers: { Accept: 'application/json', Authorization: 'Bearer ' + token,
          ...(init.body ? { 'Content-Type': 'application/json' } : {}) },
      });
    } catch {
      throw new ApiError('No hay respuesta del servidor. Si estabas registrando, reintenta el mismo ingreso al recuperar la conexión.', 0);
    }
    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
      const errors = Object.values(data.errors ?? {}).flat().filter((value): value is string => typeof value === 'string');
      throw new ApiError(errors.slice(0, 4).join('\n') || data.message || 'No se pudo completar la operación de Portería.', response.status, data);
    }
    return data as T;
  }
  return {
    catalog: () => request<{ clientes: GatehouseClient[]; ahora: string }>('/catalogos'),
    list: (filters: { fecha?: string; buscar?: string; page?: number; pendientes?: boolean } = {}) => {
      const query = new URLSearchParams({ page: String(filters.page ?? 1) });
      if (filters.fecha) query.set('fecha', filters.fecha);
      if (filters.buscar) query.set('buscar', filters.buscar);
      if (filters.pendientes) query.set('pendientes', '1');
      return request<GatehousePage>('/ingresos?' + query.toString());
    },
    detail: (id: number) => request<GatehouseDetail>('/ingresos/' + encodeURIComponent(String(id))),
    async register(payload: GatehousePayload): Promise<GatehouseEntry> {
      return (await request<{ data: GatehouseEntry }>('/ingresos', { method: 'POST', body: JSON.stringify(payload) })).data;
    },
  };
}
