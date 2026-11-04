import {
  CreatePrefrioProcessPayload,
  PrefrioActionPayload,
  PrefrioFolioCandidate,
  PrefrioProcess,
  PrefrioTunnel,
} from '../domain/prefrio';
import { DewPointDay, DewPointPayload, DewPointReading } from '../domain/dewPoint';
import { ApiError } from './apiError';
import { fetchWithTimeout } from './httpClient';

function responseMessage(data: unknown, fallback: string) {
  if (!data || typeof data !== 'object') return fallback;
  const response = data as { message?: string; errors?: Record<string, string[]> };
  const first = response.errors ? Object.values(response.errors).flat()[0] : null;
  return first ?? response.message ?? fallback;
}

async function request<T>(
  baseUrl: string,
  path: string,
  token: string,
  init: RequestInit = {},
): Promise<T> {
  const headers = new Headers(init.headers);
  headers.set('Accept', 'application/json');
  headers.set('Authorization', `Bearer ${token}`);
  if (init.body) headers.set('Content-Type', 'application/json');

  let response: Response;
  try {
    response = await fetchWithTimeout(`${baseUrl}${path}`, { ...init, headers });
  } catch {
    throw new ApiError('La PDA no puede alcanzar el servidor. La operación permanece en la bandeja.', 0);
  }

  const data = response.status === 204
    ? null
    : await response.json().catch(() => ({}));

  if (!response.ok) {
    throw new ApiError(
      responseMessage(data, 'No fue posible completar la operación de prefrío.'),
      response.status,
      data,
    );
  }

  return data as T;
}

export type ConditionalPrefrioList<T> = {
  data: T[] | null;
  etag: string | null;
};

async function requestConditionalList<T>(
  baseUrl: string,
  path: string,
  token: string,
  etag?: string | null,
): Promise<ConditionalPrefrioList<T>> {
  const headers = new Headers({
    Accept: 'application/json',
    Authorization: `Bearer ${token}`,
  });
  if (etag) headers.set('If-None-Match', etag);

  let response: Response;
  try {
    response = await fetchWithTimeout(`${baseUrl}${path}`, { headers });
  } catch {
    throw new ApiError('La PDA no puede alcanzar el servidor. Se conserva la última bandeja de Prefrío.', 0);
  }

  const nextEtag = response.headers.get('ETag') ?? etag ?? null;
  if (response.status === 304) return { data: null, etag: nextEtag };

  const body = await response.json().catch(() => ({}));
  if (!response.ok) {
    throw new ApiError(
      responseMessage(body, 'No fue posible actualizar la bandeja de Prefrío.'),
      response.status,
      body,
    );
  }

  return {
    data: (body as { data: T[] }).data,
    etag: nextEtag,
  };
}

export async function listPrefrioTunnels(
  baseUrl: string,
  token: string,
  etag?: string | null,
) {
  return requestConditionalList<PrefrioTunnel>(
    baseUrl,
    '/api/prefrio/tuneles',
    token,
    etag,
  );
}

export async function listPrefrioProcesses(
  baseUrl: string,
  token: string,
  etag?: string | null,
) {
  return requestConditionalList<PrefrioProcess>(
    baseUrl,
    '/api/prefrio/procesos?per_page=50&solo_activos=1',
    token,
    etag,
  );
}

export async function getPrefrioProcess(
  baseUrl: string,
  token: string,
  processId: string,
) {
  const response = await request<{ data: PrefrioProcess }>(
    baseUrl,
    `/api/prefrio/procesos/${processId}`,
    token,
  );
  return response.data;
}

export async function listEligiblePrefrioFolios(
  baseUrl: string,
  token: string,
  limit = 500,
  etag?: string | null,
) {
  return requestConditionalList<PrefrioFolioCandidate>(
    baseUrl,
    `/api/prefrio/folios-disponibles?limit=${limit}`,
    token,
    etag,
  );
}

export async function findEligiblePrefrioFolios(
  baseUrl: string,
  token: string,
  folio: string,
) {
  const response = await request<{ data: PrefrioFolioCandidate[] }>(
    baseUrl,
    `/api/prefrio/folios-disponibles?folio=${encodeURIComponent(folio)}&limit=100`,
    token,
  );
  return response.data;
}

export async function createPrefrioProcess(
  baseUrl: string,
  token: string,
  payload: CreatePrefrioProcessPayload,
) {
  const response = await request<{ data: PrefrioProcess }>(
    baseUrl,
    '/api/prefrio/procesos',
    token,
    { method: 'POST', body: JSON.stringify(payload) },
  );
  return response.data;
}

export async function executePrefrioCommand(
  baseUrl: string,
  token: string,
  route: string,
  payload: PrefrioActionPayload | Record<string, unknown>,
) {
  const response = await request<{ data: PrefrioProcess }>(
    baseUrl,
    route,
    token,
    { method: 'POST', body: JSON.stringify(payload) },
  );
  return response.data;
}

/** Chequeos de punto de rocío del día operacional (hoy si no se indica fecha). */
export async function getDewPointDay(baseUrl: string, token: string, date?: string) {
  return request<DewPointDay>(
    baseUrl,
    `/api/prefrio/punto-rocio${date ? `?fecha=${encodeURIComponent(date)}` : ''}`,
    token,
  );
}

export async function registerDewPointReading(baseUrl: string, token: string, payload: DewPointPayload) {
  const response = await request<{ data: DewPointReading }>(
    baseUrl,
    '/api/prefrio/punto-rocio',
    token,
    { method: 'POST', body: JSON.stringify(payload) },
  );
  return response.data;
}
