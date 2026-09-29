import { File, Paths } from 'expo-file-system';
import * as Sharing from 'expo-sharing';

import { FinishHydroCycle, HydroFilters, HydroLot, HydroSummary, HydroTray, ReleaseHydroCycle, StartHydroCycle } from '../domain/hidrocoolerMp';
import { ApiError } from './apiError';
import { fetchWithTimeout } from './httpClient';

async function request<T>(baseUrl: string, path: string, token: string, init: RequestInit = {}): Promise<T> {
  const headers = new Headers(init.headers);
  headers.set('Accept', 'application/json');
  headers.set('Authorization', `Bearer ${token}`);
  if (init.body) headers.set('Content-Type', 'application/json');
  let response: Response;
  try { response = await fetchWithTimeout(`${baseUrl}${path}`, { ...init, headers }); }
  catch { throw new ApiError('No hay conexión con el servidor. Reintenta cuando vuelva la red.', 0); }
  const data = await response.json().catch(() => ({}));
  if (!response.ok) {
    const detail = data as { message?: string; errors?: Record<string, string[]> };
    throw new ApiError(Object.values(detail.errors ?? {}).flat()[0] ?? detail.message ?? 'No se pudo completar la operación de Hidrocooler.', response.status, data);
  }
  return data as T;
}

function searchParams(filters: HydroFilters, withTray?: HydroTray) {
  const params = new URLSearchParams();
  for (const [key, value] of Object.entries(filters)) {
    if (value.trim()) params.set(key, value.trim());
  }
  if (withTray) params.set('bandeja', withTray);
  return params;
}

export function getHydroSummary(baseUrl: string, token: string) {
  return request<HydroSummary>(baseUrl, '/api/materia-prima/hidrocooler/resumen', token);
}

export async function listHydroLots(baseUrl: string, token: string, tray: HydroTray, filters: HydroFilters, page = 1) {
  const params = searchParams(filters, tray);
  params.set('per_page', '100');
  params.set('page', String(page));
  return request<{ data: HydroLot[]; meta: { current_page: number; last_page: number } }>(baseUrl, `/api/materia-prima/hidrocooler/lotes?${params}`, token);
}

export async function startHydroCycle(baseUrl: string, token: string, lotId: string, input: StartHydroCycle) {
  return (await request<{ data: HydroLot }>(baseUrl, `/api/materia-prima/lotes/${lotId}/hidrocooler/iniciar`, token, {
    method: 'POST', body: JSON.stringify(input),
  })).data;
}

export async function finishHydroCycle(baseUrl: string, token: string, lotId: string, input: FinishHydroCycle) {
  return (await request<{ data: HydroLot }>(baseUrl, `/api/materia-prima/lotes/${lotId}/hidrocooler/completar`, token, {
    method: 'POST', body: JSON.stringify(input),
  })).data;
}

export async function releaseHydroCycle(baseUrl: string, token: string, lotId: string, input: ReleaseHydroCycle) {
  return (await request<{ data: HydroLot }>(baseUrl, `/api/materia-prima/lotes/${lotId}/hidrocooler/liberar`, token, {
    method: 'POST', body: JSON.stringify(input),
  })).data;
}

export async function shareHydroRegister(baseUrl: string, token: string, format: 'pdf' | 'xlsx', blank: boolean, filters: HydroFilters) {
  if (!(await Sharing.isAvailableAsync())) throw new Error('Este dispositivo no permite compartir archivos. Descarga el registro desde Oficina.');
  const params = blank ? new URLSearchParams() : searchParams(filters);
  params.set('formato', format);
  const path = `/api/materia-prima/hidrocooler/registro${blank ? '/en-blanco' : ''}?${params}`;
  let response: Response;
  try { response = await fetchWithTimeout(`${baseUrl}${path}`, {
    headers: { Accept: format === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', Authorization: `Bearer ${token}` },
  }, 60_000); }
  catch { throw new Error('No fue posible descargar el registro. Comprueba la conexión y reintenta.'); }
  if (!response.ok) {
    const detail = await response.json().catch(() => ({})) as { message?: string; errors?: Record<string, string[]> };
    throw new Error(Object.values(detail.errors ?? {}).flat()[0] ?? detail.message ?? 'No fue posible descargar el registro.');
  }
  const bytes = new Uint8Array(await response.arrayBuffer());
  const isPdf = bytes[0] === 0x25 && bytes[1] === 0x50 && bytes[2] === 0x44 && bytes[3] === 0x46;
  const isXlsx = bytes[0] === 0x50 && bytes[1] === 0x4b;
  if (format === 'pdf' ? !isPdf : !isXlsx) {
    throw new Error('El servidor no devolvió el formato solicitado. Revisa tu sesión y reintenta.');
  }
  const expectedType = format === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
  const file = new File(Paths.cache, `hidrocooler-${blank ? 'blanco' : 'registro'}-${Date.now()}.${format}`);
  file.write(bytes);
  await Sharing.shareAsync(file.uri, {
    dialogTitle: 'Guardar registro de Hidrocooler',
    mimeType: expectedType,
  });
}
