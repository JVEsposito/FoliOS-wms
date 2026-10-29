import {
  RegisterValidationPayload,
  ValidationAttempt,
  ValidationCatalog,
  ValidationPrefill,
  ValidationSessionSnapshot,
} from '../domain/validation';
import { ApiError } from './apiError';
import { assertValidationCatalog } from '../domain/validationCatalogData';
import { applyCatalogFragmentDelta, assembleCatalog, assertCatalogManifest, catalogCollections, CatalogCollection, catalogFragmentGroup, CatalogManifest, CatalogProgress, decodeCatalogFragment, encodeCatalogFragment } from '../domain/validationCatalogTransfer';
import { catalogFragmentHash, pruneCatalogFragments, readCatalogFragment, supportsCatalogTransfer, verifyCatalogFragment, writeCatalogFragment } from './validationCatalogTransferStore';

const legacyCatalogServers = new Set<string>();
type CatalogDownloadOptions = { deviceId: string; onProgress?: (progress: CatalogProgress) => void };

async function catalogResponse(url: string, headers: Headers) {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), 45000);
  try {
    const response = await fetch(url, { headers, signal: controller.signal });
    const raw = await response.text();
    return { response, raw };
  } catch {
    throw new ApiError(controller.signal.aborted
      ? 'La descarga del catálogo excedió 45 segundos. Reintenta; las partes recibidas y el catálogo anterior se conservan.'
      : 'La PDA no puede actualizar el catálogo. Se conservará la última copia disponible y las partes ya recibidas.', 0);
  } finally { clearTimeout(timer); }
}

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
  let data: unknown;
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), 45000);
  try {
    response = await fetch(`${baseUrl}${path}`, { ...init, headers, signal: controller.signal });
    data = response.status === 204 ? null : await response.json().catch((reason) => {
      if (controller.signal.aborted) throw reason;
      return {};
    });
  } catch {
    throw new ApiError(controller.signal.aborted
      ? 'El servidor no respondió en 45 segundos. La captura se conserva para reintentar el envío.'
      : 'La PDA no puede alcanzar el servidor. La captura quedó pendiente.', 0);
  } finally { clearTimeout(timer); }

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
  options?: CatalogDownloadOptions,
): Promise<ValidationCatalog | null> {
  const fragmented = Boolean(options && supportsCatalogTransfer && !legacyCatalogServers.has(baseUrl));
  const headers = new Headers({
    Accept: 'application/json',
    Authorization: `Bearer ${token}`,
  });
  if (knownCatalog) {
    headers.set(
      'If-None-Match',
      `"${fragmented ? 'validacion-partes-v1' : 'validacion-catalogo'}-${knownCatalog.temporada.id}-${knownCatalog.temporada.version_catalogo}"`,
    );
  }

  const { response, raw } = await catalogResponse(`${baseUrl}/api/validacion/catalogos${fragmented ? '?formato=fragmentos-v1' : ''}`, headers);

  if (response.status === 304) {
    if (knownCatalog) return null;
    throw new ApiError('El servidor indicó catálogo sin cambios, pero esta PDA no tiene una copia válida. Vuelve a sincronizar.', 502);
  }

  let data: unknown;
  try { data = JSON.parse(raw); } catch { data = {}; }
  if (!response.ok) {
    throw new ApiError(
      `No se pudo descargar el catálogo (HTTP ${response.status}). ${validationMessage(data, 'Reintenta la sincronización o informa a Oficina.')}`,
      response.status,
      data,
    );
  }

  if (fragmented && (data as CatalogManifest)?.formato === 'fragmentos-v1') {
    assertCatalogManifest(data);
    assertCatalogVersion(data, knownCatalog);
    data = await downloadCatalogFragments(baseUrl, token, data, options!, knownCatalog);
  } else if (fragmented) {
    // Un backend anterior sigue entregando el contrato completo; conserva su ETag.
    assertValidationCatalog(data);
    legacyCatalogServers.add(baseUrl);
  }
  assertValidationCatalog(data);
  assertCatalogVersion(data, knownCatalog);
  return data;
}

function assertCatalogVersion(data: Pick<ValidationCatalog, 'temporada'>, knownCatalog: ValidationCatalog | null) {
  if (knownCatalog?.temporada.id === data.temporada.id
    && data.temporada.version_catalogo < knownCatalog.temporada.version_catalogo) {
    throw new ApiError(`El servidor devolvió v${data.temporada.version_catalogo}, anterior al catálogo v${knownCatalog.temporada.version_catalogo} de la PDA. Se conservó la copia más reciente.`, 409);
  }
}

async function downloadCatalogFragments(baseUrl: string, token: string, manifest: CatalogManifest, options: CatalogDownloadOptions, knownCatalog: ValidationCatalog | null) {
  const rows: Record<CatalogCollection, Record<string, unknown>[]> = { categorias: [], articulos: [], origenes: [], combinaciones: [] };
  const progress: CatalogProgress = { version: manifest.temporada.version_catalogo, completed: 0,
    total: manifest.fragmentos.length, downloadedBytes: 0, reusedBytes: 0 };
  const headers = new Headers({ Accept: 'application/json', Authorization: `Bearer ${token}` });
  const bases = new Map<string, Record<string, unknown>[]>();
  if (knownCatalog?.temporada.id === manifest.temporada.id) {
    for (const collection of catalogCollections) {
      for (const row of knownCatalog[collection]) {
        const key = `${collection}:${catalogFragmentGroup(collection, row.id)}`;
        if (!bases.has(key)) bases.set(key, []);
        bases.get(key)!.push(row as unknown as Record<string, unknown>);
      }
    }
  }
  let next = 0;
  let failure: unknown;
  await pruneCatalogFragments(baseUrl, options.deviceId, manifest.fragmentos.map((part) => part.hash));
  options.onProgress?.({ ...progress });
  async function worker() {
    while (!failure && next < manifest.fragmentos.length) {
      const fragment = manifest.fragmentos[next++];
      try {
        let raw = await readCatalogFragment(baseUrl, options.deviceId, fragment.hash);
        if (raw !== null) progress.reusedBytes += fragment.bytes;
        else {
          const baseRows = bases.get(`${fragment.coleccion}:${fragment.grupo}`);
          const baseHash = baseRows ? await catalogFragmentHash(encodeCatalogFragment(fragment.coleccion, baseRows)) : null;
          const url = `${baseUrl}/api/validacion/catalogos/fragmentos/${encodeURIComponent(manifest.temporada.id)}/${manifest.temporada.version_catalogo}/${fragment.hash}${baseHash ? `?base=${baseHash}` : ''}`;
          const downloaded = await catalogResponse(url, headers);
          if (!downloaded.response.ok) {
            let problem: unknown;
            try { problem = JSON.parse(downloaded.raw); } catch { problem = null; }
            throw new ApiError(`No se pudo completar el catálogo (HTTP ${downloaded.response.status}). ${validationMessage(problem, 'Reintenta; las partes recibidas se conservan.')}`, downloaded.response.status, problem);
          }
          raw = downloaded.raw;
          const downloadedBytes = utf8Length(raw);
          let format: string | undefined;
          try { format = (JSON.parse(raw) as { formato?: string } | null)?.formato; } catch { /* El checksum detectará un cuerpo dañado. */ }
          if (baseRows && baseHash && format === 'delta-fragmentos-v1') {
            raw = applyCatalogFragmentDelta(raw, baseHash, fragment, baseRows);
          }
          await verifyCatalogFragment(raw, fragment.hash);
          // Verifica el contrato antes de guardar una parte para futuras descargas.
          decodeCatalogFragment(raw, fragment, manifest.temporada.id);
          await writeCatalogFragment(baseUrl, options.deviceId, fragment.hash, raw);
          progress.downloadedBytes += downloadedBytes;
          progress.reusedBytes += Math.max(0, fragment.bytes - downloadedBytes);
        }
        rows[fragment.coleccion].push(...decodeCatalogFragment(raw, fragment, manifest.temporada.id));
        progress.completed++;
        options.onProgress?.({ ...progress });
      } catch (reason) { failure = reason; }
    }
  }
  // Dos solicitudes como máximo; todas terminan antes de permitir un reintento.
  await Promise.all([worker(), worker()]);
  if (failure) throw failure;
  const catalog = assembleCatalog(manifest, rows);
  await pruneCatalogFragments(baseUrl, options.deviceId, manifest.fragmentos.map((part) => part.hash));
  return catalog;
}

function utf8Length(value: string) {
  let length = 0;
  for (const character of value) {
    const code = character.codePointAt(0)!;
    length += code < 0x80 ? 1 : code < 0x800 ? 2 : code < 0x10000 ? 3 : 4;
  }
  return length;
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
