import type { ValidationCatalog } from '../domain/validation';
import { assertValidationCatalog } from '../domain/validationCatalogData';
import { createCatalogCompatibilityBuilder } from '../domain/validationCatalogIndex';
import { catalogCollections, decodeCatalogRows, type CatalogCollection, type CatalogProgress } from '../domain/validationCatalogTransfer';
import type { CatalogPageIndex } from './validationCatalogPages';
import { catalogPageBytes, requestCatalogPage, type CatalogPageLoad } from './validationCatalogPageRequest';
import { pruneCatalogFragments, readCatalogFragment, readCatalogPageCheckpoint, writeCatalogFragment, writeCatalogPageCheckpoint } from './validationCatalogTransferStore';

type Range = { inicio: string | null; hasta: string | null };
export type FastCatalogOptions = { transporte: 'compacto-v1'; tamanos_pagina: Record<CatalogCollection, number>; rangos_combinaciones: Range[] };
type FastIndex = CatalogPageIndex & FastCatalogOptions;
type SavedPage = { coleccion: CatalogCollection; rango: number; desde: string | null; hash: string };
type Dictionary = { columnas: string[]; textos: string[]; filas: unknown[][] };
type Page = { formato: string; transporte: string; temporada_id: string; version: number; coleccion: CatalogCollection;
  desde: string | null; siguiente: string | null; inicio: string | null; hasta: string | null; ultimo_id: string | null; cantidad: number; datos: Dictionary };
const uuid = /^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i;
const isBound = (v: unknown): v is string | null => v === null || (typeof v === 'string' && uuid.test(v));
const compare = (a: string, b: string) => a.toLowerCase() < b.toLowerCase() ? -1 : a.toLowerCase() > b.toLowerCase() ? 1 : 0;
const wholeRange: Range = { inicio: null, hasta: null };

export function assertFastCatalogOptions(value: Partial<FastCatalogOptions>): asserts value is FastCatalogOptions {
  const ranges = value.rangos_combinaciones;
  if (value.transporte !== 'compacto-v1' || !value.tamanos_pagina || catalogCollections.some((key) => (
    !Number.isSafeInteger(value.tamanos_pagina?.[key]) || value.tamanos_pagina![key] < 1
    || value.tamanos_pagina![key] > (key === 'combinaciones' ? 10000 : 5000)
  )) || !Array.isArray(ranges) || ranges.length < 1 || ranges.length > 4
    || ranges[0]?.inicio !== null || ranges.at(-1)?.hasta !== null
    || ranges.some((range, i) => !range || !isBound(range.inicio) || !isBound(range.hasta)
      || (i > 0 && (range.inicio === null || ranges[i - 1].hasta !== range.inicio))
      || (i < ranges.length - 1 && range.hasta === null)
      || (range.inicio !== null && range.hasta !== null && compare(range.inicio, range.hasta) >= 0))) {
    throw new Error('El índice de descarga rápida está incompleto o contiene intervalos superpuestos.');
  }
}

function decodePage(raw: string, index: FastIndex, collection: CatalogCollection, cursor: string | null, range: Range) {
  const page = JSON.parse(raw) as Page;
  if (!page || page.formato !== 'paginas-v1' || page.transporte !== 'compacto-v1' || page.temporada_id !== index.temporada.id
    || page.version !== index.temporada.version_catalogo || page.coleccion !== collection || page.desde !== cursor
    || page.inicio !== range.inicio || page.hasta !== range.hasta || !isBound(page.ultimo_id) || !isBound(page.siguiente)
    || !Number.isSafeInteger(page.cantidad) || page.cantidad < 0 || page.cantidad > index.tamanos_pagina[collection]
    || !page.datos || !Array.isArray(page.datos.textos) || page.datos.textos.some((s) => typeof s !== 'string')
    || !Array.isArray(page.datos.filas) || page.datos.filas.length !== page.cantidad
    || (page.cantidad === 0 ? page.ultimo_id !== null || page.siguiente !== null : page.ultimo_id === null)
    || (page.ultimo_id !== null && ((cursor !== null && compare(page.ultimo_id, cursor) <= 0)
      || (range.inicio !== null && compare(page.ultimo_id, range.inicio) < 0) || (range.hasta !== null && compare(page.ultimo_id, range.hasta) >= 0)))
    || (page.siguiente !== null && (page.siguiente !== page.ultimo_id || page.cantidad !== index.tamanos_pagina[collection]))) {
    throw new Error('Una página rápida no corresponde al intervalo, versión o cursor solicitado.');
  }
  if (collection === 'combinaciones') {
    if (JSON.stringify(page.datos.columnas) !== JSON.stringify(['articulo_validacion_id', 'origen_validacion_id'])) throw new Error('El diccionario de compatibilidades no corresponde al formato esperado.');
    return { page, rows: null };
  }
  const rows = decodeCatalogRows({ formato: 'fragmentos-v1', coleccion: collection, ...page.datos },
    { coleccion: collection, grupo: 0, hash: '', bytes: 0, filas: page.cantidad }, index.temporada.id);
  let previous = cursor ?? '';
  for (const row of rows) {
    if (typeof row.id !== 'string' || !uuid.test(row.id) || compare(row.id, previous) <= 0) throw new Error('Una página rápida repite o desordena maestros.');
    previous = row.id;
  }
  if (rows.length && previous !== page.ultimo_id) throw new Error('El avance no coincide con la última fila recibida.');
  return { page, rows };
}

/** Hasta cuatro páginas acotadas en vuelo; el catálogo anterior sigue instalado hasta terminar. */
export async function downloadFastCatalogPages(baseUrl: string, token: string, index: FastIndex,
  options: { deviceId: string; onProgress?: (progress: CatalogProgress) => void }, load: CatalogPageLoad): Promise<ValidationCatalog> {
  assertFastCatalogOptions(index);
  const profile = JSON.stringify([index.tamanos_pagina, index.rangos_combinaciones]);
  const saved = new Map<string, SavedPage>();
  const key = (p: Pick<SavedPage, 'coleccion' | 'rango' | 'desde'>) => `${p.coleccion}:${p.rango}:${p.desde}`;
  try {
    const checkpoint = JSON.parse(await readCatalogPageCheckpoint(baseUrl, options.deviceId, 'fast-pages-v1') ?? 'null');
    if (checkpoint?.schema === 1 && checkpoint.temporada_id === index.temporada.id && checkpoint.version === index.temporada.version_catalogo
      && checkpoint.profile === profile && Array.isArray(checkpoint.paginas) && checkpoint.paginas.length <= 100000
      && checkpoint.paginas.every((p: SavedPage) => p && catalogCollections.includes(p.coleccion) && isBound(p.desde)
        && Number.isInteger(p.rango) && p.rango >= 0 && p.rango < (p.coleccion === 'combinaciones' ? index.rangos_combinaciones.length : 1)
        && typeof p.hash === 'string' && /^[a-f0-9]{64}$/.test(p.hash))) {
      for (const page of checkpoint.paginas) saved.set(key(page), page);
    }
  } catch { /* Un avance dañado solo obliga a releer páginas, no a borrar capturas. */ }
  const rows: Record<CatalogCollection, Record<string, unknown>[]> = { categorias: [], articulos: [], origenes: [], combinaciones: [] };
  const headers = new Headers({ Accept: 'application/json', Authorization: `Bearer ${token}` });
  const progress: CatalogProgress = { version: index.temporada.version_catalogo, completed: 0, total: 0, rows: 0, downloadedBytes: 0, reusedBytes: 0 };
  let lastProgress = 0;
  function report(force = false) { if (force || Date.now() - lastProgress >= 100) { lastProgress = Date.now(); options.onProgress?.({ ...progress }); } }
  let writes = Promise.resolve();
  function checkpoint() {
    const pending = writes.then(() => writeCatalogPageCheckpoint(baseUrl, options.deviceId, JSON.stringify({ schema: 1,
      temporada_id: index.temporada.id, version: index.temporada.version_catalogo, profile, paginas: [...saved.values()] }), 'fast-pages-v1'));
    writes = pending.catch(() => {});
    return pending;
  }
  let failure: unknown;
  let compatibility: ReturnType<typeof createCatalogCompatibilityBuilder> | null = null;
  async function download(collection: CatalogCollection, range: Range, rangeIndex: number) {
    let cursor: string | null = null;
    try {
      do {
        if (failure) return;
        const descriptor = { coleccion: collection, rango: rangeIndex, desde: cursor };
        const previous = saved.get(key(descriptor));
        let raw = previous ? await readCatalogFragment(baseUrl, options.deviceId, previous.hash) : null;
        let decoded: ReturnType<typeof decodePage> | null = null;
        if (raw !== null) {
          try { decoded = decodePage(raw, index, collection, cursor, range); progress.reusedBytes += catalogPageBytes(raw); } catch { raw = null; }
        }
        if (!decoded) {
          const query = new URLSearchParams({ transporte: 'compacto-v1' });
          if (cursor) query.set('desde', cursor);
          if (range.inicio) query.set('inicio', range.inicio);
          if (range.hasta) query.set('hasta', range.hasta);
          const url = `${baseUrl}/api/validacion/catalogos/paginas/${encodeURIComponent(index.temporada.id)}/${index.temporada.version_catalogo}/${collection}?${query}`;
          const result = await requestCatalogPage(load, url, headers, collection, () => options.onProgress?.({ ...progress, retryingCollection: collection }));
          const hash = result.response.headers.get('X-Catalog-Target');
          if (!hash || !/^[a-f0-9]{64}$/.test(hash)) throw new Error('La página rápida no incluye su verificación de integridad.');
          raw = result.raw;
          await writeCatalogFragment(baseUrl, options.deviceId, hash, raw);
          decoded = decodePage(raw, index, collection, cursor, range);
          saved.set(key(descriptor), { ...descriptor, hash });
          await checkpoint();
          progress.downloadedBytes += catalogPageBytes(raw);
        }
        if (collection === 'combinaciones') compatibility!.appendPairs(decoded.page.datos.textos, decoded.page.datos.filas);
        else rows[collection].push(...decoded.rows!);
        progress.completed++; progress.rows! += decoded.page.cantidad; report();
        cursor = decoded.page.siguiente;
      } while (cursor !== null);
    } catch (error) { failure ??= error; }
  }
  report(true);
  await Promise.all(catalogCollections.filter((c) => c !== 'combinaciones').map((c) => download(c, wholeRange, 0)));
  if (failure) throw failure;
  compatibility = createCatalogCompatibilityBuilder(rows as unknown as ValidationCatalog);
  await Promise.all(index.rangos_combinaciones.map((range, i) => download('combinaciones', range, i)));
  if (failure) throw failure;
  const { formato: _format, tamano_pagina: _size, colecciones: _collections, transporte: _transport, tamanos_pagina: _sizes, rangos_combinaciones: _ranges, ...metadata } = index;
  const catalog = { ...metadata, ...rows, compatibilidad: compatibility.finish() } as unknown as ValidationCatalog;
  assertValidationCatalog(catalog);
  if (catalogCollections.some((c) => new Set(rows[c].map((r) => r.id)).size !== rows[c].length)) throw new Error('El catálogo rápido repite maestros. Se conserva la copia anterior.');
  progress.total = progress.completed; report(true);
  await pruneCatalogFragments(baseUrl, options.deviceId, [...saved.values()].map((p) => p.hash));
  return catalog;
}
