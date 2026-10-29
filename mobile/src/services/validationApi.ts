import {
  RegisterValidationPayload,
  ValidationAttempt,
  ValidationCatalog,
  ValidationPrefill,
  ValidationSessionSnapshot,
} from '../domain/validation';
import { ApiError } from './apiError';
import { assertValidationCatalog } from '../domain/validationCatalogData';

function validationMessage(data: unknown, fallback: string) {
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
    response = await fetch(`${baseUrl}${path}`, { ...init, headers });
  } catch {
    throw new ApiError('La PDA no puede alcanzar el servidor. La captura quedó pendiente.', 0);
  }

  const data = response.status === 204
    ? null
    : await response.json().catch(() => ({}));

  if (!response.ok) {
    throw new ApiError(
      validationMessage(data, 'No fue posible completar la validación.'),
      response.status,
      data,
    );
  }

  return data as T;
}

export async function getValidationCatalog(
  baseUrl: string,
  token: string,
  knownCatalog: ValidationCatalog | null = null,
): Promise<ValidationCatalog | null> {
  const headers = new Headers({
    Accept: 'application/json',
    Authorization: `Bearer ${token}`,
  });
  if (knownCatalog) {
    headers.set(
      'If-None-Match',
      `"validacion-catalogo-${knownCatalog.temporada.id}-${knownCatalog.temporada.version_catalogo}"`,
    );
  }

  let response: Response;
  try {
    response = await fetch(`${baseUrl}/api/validacion/catalogos`, { headers });
  } catch {
    throw new ApiError(
      'La PDA no puede actualizar el catálogo. Se conservará la última copia disponible.',
      0,
    );
  }

  if (response.status === 304) {
    if (knownCatalog) return null;
    throw new ApiError('El servidor indicó catálogo sin cambios, pero esta PDA no tiene una copia válida. Vuelve a sincronizar.', 502);
  }

  const data = await response.json().catch(() => ({}));
  if (!response.ok) {
    throw new ApiError(
      `No se pudo descargar el catálogo (HTTP ${response.status}). ${validationMessage(data, 'Reintenta la sincronización o informa a Oficina.')}`,
      response.status,
      data,
    );
  }

  assertValidationCatalog(data);
  if (knownCatalog?.temporada.id === data.temporada.id
    && data.temporada.version_catalogo < knownCatalog.temporada.version_catalogo) {
    throw new ApiError(`El servidor devolvió v${data.temporada.version_catalogo}, anterior al catálogo v${knownCatalog.temporada.version_catalogo} de la PDA. Se conservó la copia más reciente.`, 409);
  }
  return data;
}

export async function registerValidation(
  baseUrl: string,
  token: string,
  payload: RegisterValidationPayload,
) {
  const response = await request<{ data: ValidationAttempt }>(
    baseUrl,
    '/api/validacion/pallets',
    token,
    { method: 'POST', body: JSON.stringify(payload) },
  );
  return response.data;
}

export type ValidationOperationStatus = {
  operacion_id: string;
  estado: 'sin_registro' | 'procesando' | 'aplicada' | 'rechazada' | 'fallida';
  mensaje: string | null;
  validacion: ValidationAttempt | null;
};

export function getValidationOperation(baseUrl: string, token: string, operationId: string) {
  return request<ValidationOperationStatus>(
    baseUrl, `/api/validacion/pallets/operaciones/${encodeURIComponent(operationId)}`, token,
  );
}

export function getMyValidationSession(
  baseUrl: string,
  token: string,
  page = 1,
) {
  const query = new URLSearchParams({
    page: String(page),
    per_page: '25',
  });
  return request<ValidationSessionSnapshot>(
    baseUrl,
    `/api/validacion/pallets/mi-sesion?${query.toString()}`,
    token,
  );
}

export async function listRecentValidations(baseUrl: string, token: string) {
  const response = await request<{ data: ValidationAttempt[] }>(
    baseUrl,
    '/api/validacion/pallets?per_page=10',
    token,
  );
  return response.data;
}

export async function listValidationsByFolio(
  baseUrl: string,
  token: string,
  folio: string,
) {
  const query = new URLSearchParams({
    folio: folio.trim().toUpperCase(),
    per_page: '10',
  });
  const response = await request<{ data: ValidationAttempt[] }>(
    baseUrl,
    `/api/validacion/pallets?${query.toString()}`,
    token,
  );
  return response.data;
}

/** Lo que la planta informa del pallet, para partir la validación con esos datos. */
export async function getValidationPrefill(
  baseUrl: string,
  token: string,
  folio: string,
): Promise<ValidationPrefill> {
  const query = new URLSearchParams({ numero_folio: folio });
  return (await request<{ data: ValidationPrefill }>(
    baseUrl,
    `/api/validacion/precarga-origen?${query.toString()}`,
    token,
  )).data;
}
