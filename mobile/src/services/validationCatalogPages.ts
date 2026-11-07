import type { ValidationCatalog } from '../domain/validation';
import { assertValidationCatalog } from '../domain/validationCatalogData';
import { catalogCollections, type CatalogCollection, type CatalogProgress } from '../domain/validationCatalogTransfer';
import { ApiError } from './apiError';
import { pruneCatalogFragments, readCatalogFragment, readCatalogPageCheckpoint, verifyCatalogFragment, writeCatalogFragment, writeCatalogPageCheckpoint } from './validationCatalogTransferStore';

type Index = Omit<ValidationCatalog, CatalogCollection> & { formato: 'paginas-v1'; tamano_pagina: number; colecciones: CatalogCollection[] };
type Page = { formato: 'paginas-v1'; temporada_id: string; version: number; coleccion: CatalogCollection; desde: string | null; siguiente: string | null; filas: Record<string, unknown>[] };
type SavedPage = { coleccion: CatalogCollection; desde: string | null; hash: string };
type Checkpoint = { schema: 1; temporada_id: string; version: number; paginas: SavedPage[] };
type Load = (url: string, headers: Headers) => Promise<{ response: Response; raw: string }>;
const uuid = /^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i;

export function assertCatalogPageIndex(data: unknown): asserts data is Index {
  const value = data as Index;
  if (!value || value.formato !== 'paginas-v1' || typeof value.temporada?.id !== 'string' || !value.temporada.id
    || !Number.isSafeInteger(value.temporada.version_catalogo) || value.temporada.version_catalogo < 1
    || !Number.isSafeInteger(value.tamano_pagina) || value.tamano_pagina < 1 || value.tamano_pagina > 1000
    || JSON.stringify(value.colecciones) !== JSON.stringify(catalogCollections)
    || !Array.isArray(value.tipos_bulto) || !Array.isArray(value.resultados) || !Array.isArray(value.motivos)) {
    throw new Error('El índice de descarga paginada está incompleto. Se conserva el catálogo anterior.');
  }
}

function bytes(raw: string): number {
  let total = 0;
  for (const character of raw) {
    const code = character.codePointAt(0)!;
    total += code < 0x80 ? 1 : code < 0x800 ? 2 : code < 0x10000 ? 3 : 4;
  }
  return total;
}

function decode(raw: string, index: Index, collection: CatalogCollection, cursor: string | null): Page {
  const page = JSON.parse(raw) as Page;
  if (!page || page.formato !== 'paginas-v1' || page.temporada_id !== index.temporada.id
    || page.version !== index.temporada.version_catalogo || page.coleccion !== collection || page.desde !== cursor
    || !Array.isArray(page.filas) || page.filas.length > index.tamano_pagina
    || (page.siguiente !== null && (typeof page.siguiente !== 'string' || !uuid.test(page.siguiente)))) {
    throw new Error('La página no corresponde a la versión o colección solicitada. Reintenta la descarga.');
  }
  let previous = cursor ?? '';
  for (const row of page.filas) {
    if (!row || typeof row.id !== 'string' || !uuid.test(row.id) || row.id <= previous
      || (collection !== 'combinaciones' && (row.temporada_id !== index.temporada.id || row.activo !== true))) {
      throw new Error('Una página del catálogo contiene filas inválidas, repetidas o desordenadas.');
    }
    previous = row.id;
  }
  if (page.siguiente !== null && (page.filas.length !== index.tamano_pagina || page.siguiente !== previous)) {
    throw new Error('El avance de la descarga no corresponde a su última fila.');
  }
  return page;
}

export async function downloadCatalogPages(baseUrl: string, token: string, index: Index,
  options: { deviceId: string; onProgress?: (progress: CatalogProgress) => void }, load: Load): Promise<ValidationCatalog> {
  const checkpoint: Checkpoint = { schema: 1, temporada_id: index.temporada.id, version: index.temporada.version_catalogo, paginas: [] };
  try {
    const saved = JSON.parse(await readCatalogPageCheckpoint(baseUrl, options.deviceId) ?? 'null') as Checkpoint;
    if (saved?.schema === 1 && saved.temporada_id === checkpoint.temporada_id && saved.version === checkpoint.version
      && Array.isArray(saved.paginas) && saved.paginas.length <= 100000
      && saved.paginas.every((p) => p && catalogCollections.includes(p.coleccion) && /^[a-f0-9]{64}$/.test(p.hash)
        && (p.desde === null || (typeof p.desde === 'string' && uuid.test(p.desde))))
      && new Set(saved.paginas.map((p) => `${p.coleccion}:${p.desde}`)).size === saved.paginas.length) {
      checkpoint.paginas = saved.paginas;
    }
  } catch { /* Una pérdida de avance obliga a releer páginas, nunca a instalar una copia parcial. */ }
  const savedPages = new Map(checkpoint.paginas.map((p) => [`${p.coleccion}:${p.desde}`, p]));
  const rows: Record<CatalogCollection, Record<string, unknown>[]> = { categorias: [], articulos: [], origenes: [], combinaciones: [] };
  const headers = new Headers({ Accept: 'application/json', Authorization: `Bearer ${token}` });
  const progress: CatalogProgress = { version: checkpoint.version, completed: 0, total: 0, rows: 0, downloadedBytes: 0, reusedBytes: 0 };
  options.onProgress?.({ ...progress });
  for (const collection of catalogCollections) {
    let cursor: string | null = null;
    do {
      const key = `${collection}:${cursor}`;
      const saved = savedPages.get(key);
      let raw = saved ? await readCatalogFragment(baseUrl, options.deviceId, saved.hash) : null;
      let page: Page | null = null;
      if (raw !== null) {
        try { page = decode(raw, index, collection, cursor); progress.reusedBytes += bytes(raw); }
        catch { raw = null; }
      }
      if (!page) {
        const query = cursor ? `?desde=${encodeURIComponent(cursor)}` : '';
        const result = await load(`${baseUrl}/api/validacion/catalogos/paginas/${encodeURIComponent(index.temporada.id)}/${checkpoint.version}/${collection}${query}`, headers);
        if (!result.response.ok) {
          let message = 'Reintenta la sincronización; las páginas recibidas se conservan.';
          try { message = (JSON.parse(result.raw) as { message?: string }).message ?? message; } catch { /* Mensaje de red sin JSON. */ }
          throw new ApiError(`No se pudo descargar ${collection} (HTTP ${result.response.status}). ${message}`, result.response.status);
        }
        const hash = result.response.headers.get('X-Catalog-Target');
        if (!hash || !/^[a-f0-9]{64}$/.test(hash)) throw new Error('La página no incluye su verificación de integridad.');
        raw = result.raw;
        await verifyCatalogFragment(raw, hash);
        page = decode(raw, index, collection, cursor);
        await writeCatalogFragment(baseUrl, options.deviceId, hash, raw);
        savedPages.set(key, { coleccion: collection, desde: cursor, hash });
        checkpoint.paginas = [...savedPages.values()];
        await writeCatalogPageCheckpoint(baseUrl, options.deviceId, JSON.stringify(checkpoint));
        progress.downloadedBytes += bytes(raw);
      }
      rows[collection].push(...page.filas);
      progress.completed++;
      progress.rows! += page.filas.length;
      options.onProgress?.({ ...progress });
      cursor = page.siguiente;
    } while (cursor !== null);
  }
  const { formato: _format, tamano_pagina: _size, colecciones: _collections, ...metadata } = index;
  const catalog = { ...metadata, ...rows } as unknown as ValidationCatalog;
  assertValidationCatalog(catalog);
  if (catalogCollections.some((collection) => new Set(rows[collection].map((r) => r.id)).size !== rows[collection].length)) {
    throw new Error('La descarga repite registros. Se conserva el catálogo anterior.');
  }
  options.onProgress?.({ ...progress, total: progress.completed });
  await pruneCatalogFragments(baseUrl, options.deviceId, checkpoint.paginas.map((p) => p.hash));
  return catalog;
}
