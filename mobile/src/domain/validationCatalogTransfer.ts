import type { ValidationCatalog } from './validation';
import { assertValidationCatalog } from './validationCatalogData';

export const catalogCollections = ['categorias', 'articulos', 'origenes', 'combinaciones'] as const;
export type CatalogCollection = typeof catalogCollections[number];
export type CatalogFragment = { coleccion: CatalogCollection; grupo: number; hash: string; bytes: number; filas: number };
export type CatalogManifest = Omit<ValidationCatalog, CatalogCollection> & {
  formato: 'fragmentos-v1'; cantidades: Record<CatalogCollection, number>; fragmentos: CatalogFragment[];
};
export type CatalogProgress = { version: number; completed: number; total: number; downloadedBytes: number; reusedBytes: number };
const columns = {
  categorias: ['id', 'nombre', 'codigo_externo'],
  articulos: ['id', 'variedad_validacion_id', 'especie', 'variedad', 'calibre', 'envase', 'codigo_externo'],
  origenes: ['id', 'cliente_validacion_id', 'csg_validacion_id', 'variedad_ids', 'cliente', 'marca', 'csg', 'predio', 'codigo_externo'],
  combinaciones: ['id', 'articulo_validacion_id', 'origen_validacion_id', 'codigo_externo'],
} as const;

export function catalogFragmentGroup(collection: CatalogCollection, id: string): number {
  return collection === 'categorias' || collection === 'origenes' ? 0 : (parseInt(id.replace(/-/g, '').slice(0, 2), 16) || 0) % 32;
}

/** Representación canónica compartida con PHP; permite verificar también una actualización por filas. */
export function encodeCatalogFragment(collection: CatalogCollection, rows: Record<string, unknown>[]): string {
  const texts: string[] = [];
  const indexes = new Map<string, number>();
  function encode(value: unknown): number | null | (number | null)[] {
    if (value === null || value === undefined) return null;
    if (Array.isArray(value)) return [...value].sort().map((item) => encode(item) as number | null);
    const text = String(value);
    if (!indexes.has(text)) { indexes.set(text, texts.length); texts.push(text); }
    return indexes.get(text)!;
  }
  const sorted = [...rows].sort((a, b) => String(a.id) < String(b.id) ? -1 : String(a.id) > String(b.id) ? 1 : 0);
  const values = sorted.map((row) => columns[collection].map((column) => encode(row[column])));
  return JSON.stringify({ formato: 'fragmentos-v1', coleccion: collection, columnas: columns[collection], textos: texts, filas: values });
}

export function applyCatalogFragmentDelta(raw: string, baseHash: string, target: CatalogFragment, baseRows: Record<string, unknown>[]): string {
  const delta = JSON.parse(raw) as { formato: string; base: string; destino: string; coleccion: string; cambios: Record<string, unknown>[]; eliminados: string[] };
  if (delta.formato !== 'delta-fragmentos-v1' || delta.base !== baseHash || delta.destino !== target.hash
    || delta.coleccion !== target.coleccion || !Array.isArray(delta.cambios) || !Array.isArray(delta.eliminados)
    || delta.eliminados.some((id) => typeof id !== 'string') || delta.cambios.some((row) => !row || typeof row.id !== 'string')) {
    throw new Error('La actualización no corresponde a la parte solicitada. Se conserva el catálogo anterior.');
  }
  const rows = new Map(baseRows.map((row) => [row.id, row]));
  for (const id of delta.eliminados) rows.delete(id);
  for (const row of delta.cambios) rows.set(row.id, row);
  return encodeCatalogFragment(target.coleccion, [...rows.values()]);
}

export function assertCatalogManifest(value: unknown): asserts value is CatalogManifest {
  const manifest = value as CatalogManifest;
  if (!manifest || manifest.formato !== 'fragmentos-v1' || !manifest.temporada?.id
    || !Number.isInteger(manifest.temporada.version_catalogo) || manifest.temporada.version_catalogo < 1
    || !manifest.cantidades || !Array.isArray(manifest.fragmentos) || manifest.fragmentos.length > 128) {
    throw new Error('El servidor entregó un índice de catálogo incompleto. Reintenta la descarga.');
  }
  const groups = new Set<string>();
  for (const fragment of manifest.fragmentos) {
    if (!fragment || typeof fragment !== 'object') throw new Error('El índice contiene una parte inválida. Reintenta la descarga.');
    const group = `${fragment.coleccion}:${fragment.grupo}`;
    if (!catalogCollections.includes(fragment.coleccion) || groups.has(group)
      || !Number.isInteger(fragment.grupo) || fragment.grupo < 0 || fragment.grupo >= 32
      || !/^[a-f0-9]{64}$/.test(fragment.hash) || !Number.isInteger(fragment.bytes) || fragment.bytes <= 0
      || !Number.isInteger(fragment.filas) || fragment.filas <= 0) {
      throw new Error('El índice contiene partes inválidas o repetidas. Se conserva el catálogo anterior.');
    }
    groups.add(group);
  }
  for (const collection of catalogCollections) {
    if (!Number.isInteger(manifest.cantidades[collection]) || manifest.cantidades[collection] < 0
      || manifest.fragmentos.filter((fragment) => fragment.coleccion === collection).reduce((sum, fragment) => sum + fragment.filas, 0) !== manifest.cantidades[collection]) {
      throw new Error('Las cantidades del catálogo no coinciden con sus partes. Reintenta la descarga.');
    }
  }
}

export function decodeCatalogFragment(raw: string, fragment: CatalogFragment, seasonId: string): Record<string, unknown>[] {
  const data = JSON.parse(raw) as { formato: string; coleccion: string; columnas: string[]; textos: string[]; filas: unknown[][] };
  const expected = columns[fragment.coleccion];
  if (!data || data.formato !== 'fragmentos-v1' || data.coleccion !== fragment.coleccion
    || JSON.stringify(data.columnas) !== JSON.stringify(expected) || !Array.isArray(data.textos)
    || data.textos.some((text) => typeof text !== 'string') || !Array.isArray(data.filas) || data.filas.length !== fragment.filas) {
    throw new Error('Una parte del catálogo está incompleta o pertenece a otra colección.');
  }
  const decode = (value: unknown): string | null | (string | null)[] => {
    if (value === null) return null;
    if (Array.isArray(value)) return value.map((item) => {
      if (!Number.isInteger(item) || item < 0 || item >= data.textos.length) throw new Error('Referencia de catálogo inválida.');
      return data.textos[item];
    });
    if (typeof value !== 'number' || !Number.isInteger(value) || value < 0 || value >= data.textos.length) throw new Error('Referencia de catálogo inválida.');
    return data.textos[value];
  };
  return data.filas.map((row) => {
    if (!Array.isArray(row) || row.length !== expected.length) throw new Error('Una fila del catálogo está incompleta.');
    const decoded = Object.fromEntries(expected.map((column, index) => {
      const value = decode(row[index]);
      if (column === 'variedad_ids' ? value !== null && !Array.isArray(value) : Array.isArray(value)) throw new Error('Tipo de dato de catálogo inválido.');
      if (['id', 'nombre', 'especie', 'variedad', 'calibre', 'envase', 'cliente', 'marca', 'csg', 'articulo_validacion_id', 'origen_validacion_id'].includes(column)
        && (typeof value !== 'string' || !value)) throw new Error('Falta un dato obligatorio en una parte del catálogo.');
      return [column, value];
    }));
    if (fragment.coleccion !== 'combinaciones') Object.assign(decoded, { temporada_id: seasonId, activo: true });
    return decoded;
  });
}

/** Reemplaza colecciones completas: también retira IDs desactivados o eliminados en Oficina. */
export function assembleCatalog(manifest: CatalogManifest, rows: Record<CatalogCollection, Record<string, unknown>[]>): ValidationCatalog {
  for (const collection of catalogCollections) {
    if (rows[collection].length !== manifest.cantidades[collection]
      || rows[collection].some((row) => typeof row.id !== 'string' || !row.id)
      || new Set(rows[collection].map((row) => row.id)).size !== rows[collection].length) {
      throw new Error('El catálogo recibido está incompleto o repite registros. Se conserva la copia anterior.');
    }
  }
  const { formato: _format, fragmentos: _fragments, cantidades: _counts, ...metadata } = manifest;
  const result = { ...metadata, ...rows } as ValidationCatalog;
  assertValidationCatalog(result);
  return result;
}
