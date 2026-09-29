import { ContainerType, MpCatalog, MpHistory, MpReception, MpSegmentDraft, MpValidation } from '../domain/validationMp';
import { ApiError } from './apiError';

async function request<T>(baseUrl: string, path: string, token: string, init: RequestInit = {}): Promise<T> {
  const headers = new Headers(init.headers);
  headers.set('Accept', 'application/json');
  headers.set('Authorization', `Bearer ${token}`);
  if (init.body) headers.set('Content-Type', 'application/json');
  let response: Response;
  try { response = await fetch(`${baseUrl}${path}`, { ...init, headers }); }
  catch { throw new ApiError('No hay conexión con el servidor. La bandeja puede reintentarse.', 0); }
  const data = await response.json().catch(() => ({}));
  if (!response.ok) {
    const detail = data as { message?: string; errors?: Record<string, string[]> };
    throw new ApiError(Object.values(detail.errors ?? {}).flat()[0] ?? detail.message ?? 'No fue posible completar Validación MP.', response.status, data);
  }
  return data as T;
}

export async function listPendingMp(baseUrl: string, token: string) {
  return (await request<{ data: MpReception[] }>(baseUrl, '/api/validacion-mp/pendientes', token)).data;
}

export async function listMpHistory(baseUrl: string, token: string, date: string) {
  return (await request<{ data: MpHistory[] }>(baseUrl, `/api/validacion-mp/historico?fecha=${encodeURIComponent(date)}`, token)).data;
}

export async function findMpReception(baseUrl: string, token: string, number: string) {
  return (await request<{ data: MpReception }>(baseUrl, `/api/validacion-mp/recepciones/buscar/${encodeURIComponent(number)}`, token)).data;
}

export async function getMpCatalog(baseUrl: string, token: string, receptionId: string) {
  return request<MpCatalog>(baseUrl, `/api/validacion-mp/recepciones/${receptionId}/catalogos`, token);
}

export async function takeMpReception(baseUrl: string, token: string, receptionId: string) {
  return (await request<{ data: MpValidation }>(baseUrl, `/api/validacion-mp/recepciones/${receptionId}/tomar`, token, {
    method: 'POST', body: JSON.stringify({ operacion_id: Crypto.randomUUID() }),
  })).data;
}

export async function confirmMpValidation(baseUrl: string, token: string, validationId: string, input: {
  containers: Array<{ tipo_envase: ContainerType; cantidad_validada: number }>;
  tagsChecked: boolean;
  segregation: boolean;
  csgId: string | null;
  varietyId: string | null;
  segments: MpSegmentDraft[];
  observation: string;
}) {
  const segmentos = input.segregation ? input.segments.map((segment) => ({
    motivos: segment.motivos,
    csg_validacion_id: segment.csg_validacion_id,
    cuartel: segment.cuartel || null,
    variedad_validacion_id: segment.variedad_validacion_id,
    envases: input.containers.map((container) => ({
      tipo_envase: container.tipo_envase,
      cantidad: Number(segment.cantidades[container.tipo_envase] || 0),
    })),
  })) : undefined;
  return (await request<{ data: MpValidation }>(baseUrl, `/api/validacion-mp/validaciones/${validationId}/confirmar`, token, {
    method: 'POST',
    body: JSON.stringify({
      operacion_id: Crypto.randomUUID(),
      envases: input.containers,
      tarjas_verificadas: input.tagsChecked,
      requiere_segregacion: input.segregation,
      csg_validacion_id: input.segregation ? null : input.csgId,
      variedad_validacion_id: input.segregation ? null : input.varietyId,
      segmentos,
      observacion: input.observation || null,
    }),
  })).data;
}
import * as Crypto from 'expo-crypto';
