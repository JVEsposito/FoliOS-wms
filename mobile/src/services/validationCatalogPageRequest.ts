import type { CatalogCollection } from '../domain/validationCatalogTransfer';
import { ApiError } from './apiError';

export type CatalogPageLoad = (url: string, headers: Headers) => Promise<{ response: Response; raw: string }>;

export function catalogPageBytes(raw: string): number {
  let total = 0;
  for (const character of raw) {
    const code = character.codePointAt(0)!;
    total += code < 0x80 ? 1 : code < 0x800 ? 2 : code < 0x10000 ? 3 : 4;
  }
  return total;
}

export async function requestCatalogPage(load: CatalogPageLoad, url: string, headers: Headers, collection: CatalogCollection, onRetry: () => void) {
  for (let retries = 0; ; retries++) {
    try {
      const result = await load(url, headers);
      if (result.response.ok) return result;
      let message = 'Reintenta la sincronización; las páginas recibidas se conservan.';
      try { message = (JSON.parse(result.raw) as { message?: string }).message ?? message; } catch { /* Mensaje de red sin JSON. */ }
      throw new ApiError(`No se pudo descargar ${collection} (HTTP ${result.response.status}). ${message}`, result.response.status);
    } catch (reason) {
      if (retries >= 2 || !(reason instanceof ApiError) || ![0, 500, 502, 503, 504].includes(reason.status)) throw reason;
      onRetry();
      await new Promise<void>((resolve) => setTimeout(resolve, 1000 * (retries + 1)));
    }
  }
}
