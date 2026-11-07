import * as FileSystem from 'expo-file-system/legacy';

import type { ValidationCatalog } from '../domain/validation';
import { assertValidationCatalog, compactCatalogRows } from '../domain/validationCatalogData';
import { catalogCollections, type CatalogCollection } from '../domain/validationCatalogTransfer';
import { catalogFragmentHash } from './validationCatalogTransferStore';

const ROWS_PER_PART = 500;
const SNAPSHOT = /^(\d+-[a-z0-9]+\.json)(?:\.tmp|\.[a-z]+-\d+\.part)?$/;
export const usesNativeCatalogFiles = Boolean(FileSystem.documentDirectory);
const writes = new Map<string, Promise<void>>();
type Counts = Record<CatalogCollection, number>;
type SavedCatalog =
  | { schema: 1; counts: Counts; catalog: ValidationCatalog }
  | { schema: 2; counts: Counts; metadata: Omit<ValidationCatalog, CatalogCollection>; hashes: Record<CatalogCollection, string[]> };

function directory(server: string, deviceId: string) {
  if (!FileSystem.documentDirectory) throw new Error('El equipo no dispone de almacenamiento privado para el catálogo.');
  return `${FileSystem.documentDirectory}validation-catalogs/${encodeURIComponent(server.trim().replace(/\/+$/, ''))}/${encodeURIComponent(deviceId)}/`;
}

function partPath(path: string, name: string, collection: CatalogCollection, index: number) {
  return `${path}${name}.${collection}-${index}.part`;
}

async function readSnapshot(path: string, name: string, loadCatalog: boolean) {
  const saved = JSON.parse(await FileSystem.readAsStringAsync(path + name)) as SavedCatalog;
  if (!saved?.counts) throw new Error('Copia local de catálogo inválida.');
  if (saved.schema === 1) {
    assertValidationCatalog(saved.catalog);
    if (catalogCollections.some((key) => saved.catalog[key].length !== saved.counts[key])) {
      throw new Error('La copia local del catálogo está incompleta.');
    }
    return { name, season: saved.catalog.temporada, catalog: loadCatalog ? saved.catalog : null };
  }

  if (saved.schema !== 2 || !saved.metadata?.temporada?.id
    || !Number.isInteger(saved.metadata.temporada.version_catalogo) || saved.metadata.temporada.version_catalogo < 1
    || !saved.hashes || catalogCollections.some((key) => !Number.isInteger(saved.counts[key]) || saved.counts[key] < 0
      || !Array.isArray(saved.hashes[key]) || saved.hashes[key].length !== Math.ceil(saved.counts[key] / ROWS_PER_PART))) {
    throw new Error('Copia local de catálogo inválida.');
  }
  const catalog: ValidationCatalog | null = loadCatalog
    ? { ...saved.metadata, categorias: [], articulos: [], origenes: [], combinaciones: [] }
    : null;
  for (const collection of catalogCollections) {
    for (const [index, hash] of saved.hashes[collection].entries()) {
      const raw = await FileSystem.readAsStringAsync(partPath(path, name, collection, index));
      if (await catalogFragmentHash(raw) !== hash) throw new Error('Una parte de la copia local está dañada.');
      if (catalog) {
        const rows = JSON.parse(raw) as unknown;
        const expected = Math.min(ROWS_PER_PART, saved.counts[collection] - index * ROWS_PER_PART);
        if (!Array.isArray(rows) || rows.length !== expected) throw new Error('La copia local del catálogo está incompleta.');
        catalog[collection].push(...rows);
      }
    }
  }
  if (catalog) assertValidationCatalog(catalog);
  return { name, season: saved.metadata.temporada, catalog };
}

async function latest(path: string, loadCatalog: boolean) {
  if (!(await FileSystem.getInfoAsync(path)).exists) return null;
  const names = (await FileSystem.readDirectoryAsync(path)).filter((name) => /^\d+-[a-z0-9]+\.json$/.test(name)).sort().reverse();
  for (const name of names) {
    try { return await readSnapshot(path, name, loadCatalog); }
    catch { /* Una escritura interrumpida o dañada nunca sustituye la última copia completa. */ }
  }
  return null;
}

export async function loadValidationCatalogFile(server: string, deviceId: string) {
  return (await latest(directory(server, deviceId), true))?.catalog ?? null;
}

/** Una copia por servidor/equipo. La bandeja y la jornada siguen siendo privadas de cada usuario. */
export async function saveValidationCatalogFile(server: string, deviceId: string, catalog: ValidationCatalog) {
  const path = directory(server, deviceId);
  const previousWrite = writes.get(path) ?? Promise.resolve();
  const write = previousWrite.catch(() => {}).then(async () => {
    assertValidationCatalog(catalog);
    // Verifica el respaldo por partes sin volver a cargarlo completo mientras está en memoria el catálogo nuevo.
    const previous = await latest(path, false);
    if (previous?.season.id === catalog.temporada.id && previous.season.version_catalogo > catalog.temporada.version_catalogo) {
      throw new Error(`No se reemplazó el catálogo v${previous.season.version_catalogo} por una versión anterior.`);
    }
    await FileSystem.makeDirectoryAsync(path, { intermediates: true });
    const timestamp = Math.max(Date.now(), Number(previous?.name.split('-')[0] ?? 0) + 1);
    const name = `${String(timestamp).padStart(16, '0')}-${Math.random().toString(36).slice(2) || '0'}.json`;
    const temporary = path + name + '.tmp';
    const staged: string[] = [];

    async function stage(file: string, raw: string) {
      staged.push(file);
      await FileSystem.writeAsStringAsync(file, raw);
      if (await FileSystem.readAsStringAsync(file) !== raw) throw new Error('La copia local del catálogo está incompleta.');
    }

    async function writeParts<T>(collection: CatalogCollection, rows: T[], compact: (batch: T[]) => T[]) {
      const hashes: string[] = [];
      for (let offset = 0; offset < rows.length; offset += ROWS_PER_PART) {
        const raw = JSON.stringify(compact(rows.slice(offset, offset + ROWS_PER_PART)));
        await stage(partPath(path, name, collection, hashes.length), raw);
        hashes.push(await catalogFragmentHash(raw));
      }
      return hashes;
    }

    const { categorias, articulos, origenes, combinaciones, temporada, tipos_bulto, resultados, motivos, generado_at } = catalog;
    try {
      const hashes = {
        categorias: await writeParts('categorias', categorias, compactCatalogRows.categorias),
        articulos: await writeParts('articulos', articulos, compactCatalogRows.articulos),
        origenes: await writeParts('origenes', origenes, compactCatalogRows.origenes),
        combinaciones: await writeParts('combinaciones', combinaciones, compactCatalogRows.combinaciones),
      };
      const saved: SavedCatalog = { schema: 2, counts: {
        categorias: categorias.length, articulos: articulos.length,
        origenes: origenes.length, combinaciones: combinaciones.length,
      }, metadata: { temporada, tipos_bulto, resultados, motivos, generado_at }, hashes };
      // Solo el manifiesto final hace visible la copia. Un cierre a mitad de escritura deja el respaldo intacto.
      await stage(temporary, JSON.stringify(saved));
      await FileSystem.moveAsync({ from: temporary, to: path + name });
    } catch (reason) {
      for (const file of staged) await FileSystem.deleteAsync(file, { idempotent: true }).catch(() => {});
      throw reason;
    }
    try {
      for (const old of await FileSystem.readDirectoryAsync(path)) {
        const generation = SNAPSHOT.exec(old)?.[1];
        if (generation && generation !== name && generation !== previous?.name) {
          await FileSystem.deleteAsync(path + old, { idempotent: true });
        }
      }
    } catch { /* La copia nueva ya está publicada; la limpieza se puede reintentar. */ }
  });
  writes.set(path, write);
  try { await write; }
  finally { if (writes.get(path) === write) writes.delete(path); }
}
