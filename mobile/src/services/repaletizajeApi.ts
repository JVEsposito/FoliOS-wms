import {
  CreateRepalletizing,
  Repalletizing,
  RepalletizingFolio,
} from '../domain/repaletizaje';

async function request<T>(
  baseUrl: string,
  token: string,
  path: string,
  init: RequestInit = {},
): Promise<T> {
  const headers = new Headers(init.headers);
  headers.set('Accept', 'application/json');
  headers.set('Authorization', `Bearer ${token}`);
  if (init.body) {
    headers.set('Content-Type', 'application/json');
  }

  const response = await fetch(`${baseUrl}${path}`, { ...init, headers });
  const data = await response.json().catch(() => ({}));
  if (!response.ok) {
    const detail = data as {
      message?: string;
      errors?: Record<string, string[]>;
    };
    const errors = Object.values(detail.errors ?? {}).flat();
    throw new Error(
      errors[0] ?? detail.message ?? 'No fue posible completar la operación.',
    );
  }

  return data as T;
}

export function findRepalletizingFolio(
  baseUrl: string,
  token: string,
  number: string,
  repacking = false,
): Promise<RepalletizingFolio> {
  return request(
    baseUrl,
    token,
    `/api/validacion/repaletizajes/folios/${encodeURIComponent(number)}${repacking ? "?reembalaje=1" : ""}`,
  );
}

export async function createRepalletizing(
  baseUrl: string,
  token: string,
  payload: CreateRepalletizing,
): Promise<Repalletizing> {
  const response = await request<{ data: Repalletizing }>(
    baseUrl,
    token,
    '/api/validacion/repaletizajes',
    { method: 'POST', body: JSON.stringify(payload) },
  );

  return response.data;
}

export async function listRepalletizings(
  baseUrl: string,
  token: string,
): Promise<Repalletizing[]> {
  const response = await request<{ data: Repalletizing[] }>(
    baseUrl,
    token,
    '/api/validacion/repaletizajes?per_page=20',
  );

  return response.data;
}
