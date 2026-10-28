import * as FileSystem from 'expo-file-system/legacy';

import type { ValidationCatalog } from '../domain/validation';
import { assertValidationCatalog, compactValidationCatalog } from '../domain/validationCatalogData';

const collections = ['categorias', 'articulos', 'origenes', 'combinaciones'] as const;
export const usesNativeCatalogFiles = Boolean(FileSystem.documentDirectory);
const writes = new Map<string, Promise<void>>();
type SavedCatalog = { schema: 1; counts: Record<typeof collections[number], number>; catalog: ValidationCatalog };

function directory(server: string, deviceId: string) {
  if (!FileSystem.documentDirectory) throw new Error('El equipo no dispone de almacenamiento privado para el catálogo.');
  return `${FileSystem.documentDirectory}validation-catalogs/${encodeURIComponent(server.trim().replace(/\/+$/, ''))}/${encodeURIComponent(deviceId)}/`;
}

function decode(raw: string): ValidationCatalog {
  const saved = JSON.parse(raw) as SavedCatalog;
  if (saved.schema !== 1 || !saved.counts) throw new Error('Copia local de catálogo inválida.');
  assertValidationCatalog(saved.catalog);
  if (collections.some((key) => saved.catalog[key].length !== saved.counts[key])) {
    throw new Error('La copia local del catálogo está incompleta.');
  }
  return saved.catalog;
}

async function latest(path: string): Promise<{ name: string; catalog: ValidationCatalog } | null> {
  if (!(await FileSystem.getInfoAsync(path)).exists) return null;
  const names = (await FileSystem.readDirectoryAsync(path)).filter((name) => /^\d+-[a-z0-9]+\.json$/.test(name)).sort().reverse();
  for (const name of names) {
    try { return { name, catalog: decode(await FileSystem.readAsStringAsync(path + name)) }; }
    catch { /* Una escritura interrumpida o dañada nunca sustituye la última copia completa. */ }
  }
  return null;
}

export async function loadValidationCatalogFile(server: string, deviceId: string) {
  return (await latest(directory(server, deviceId)))?.catalog ?? null;
}

/** Una copia por servidor/equipo. La bandeja y la jornada siguen siendo privadas de cada usuario. */
export async function saveValidationCatalogFile(server: string, deviceId: string, catalog: ValidationCatalog) {
  const path = directory(server, deviceId);
  const previousWrite = writes.get(path) ?? Promise.resolve();
  const write = previousWrite.catch(() => {}).then(async () => {
    const compact = compactValidationCatalog(catalog);
    const previous = await latest(path);
    if (previous?.catalog.temporada.id === compact.temporada.id
      && previous.catalog.temporada.version_catalogo > compact.temporada.version_catalogo) {
      throw new Error(`No se reemplazó el catálogo v${previous.catalog.temporada.version_catalogo} por una versión anterior.`);
    }
    await FileSystem.makeDirectoryAsync(path, { intermediates: true });
    const timestamp = Math.max(Date.now(), Number(previous?.name.split('-')[0] ?? 0) + 1);
    const name = `${String(timestamp).padStart(16, '0')}-${Math.random().toString(36).slice(2) || '0'}.json`;
    const temporary = path + name + '.tmp';
    const saved: SavedCatalog = { schema: 1, counts: {
      categorias: compact.categorias.length, articulos: compact.articulos.length,
      origenes: compact.origenes.length, combinaciones: compact.combinaciones.length,
    }, catalog: compact };
    try {
      await FileSystem.writeAsStringAsync(temporary, JSON.stringify(saved));
      decode(await FileSystem.readAsStringAsync(temporary));
      await FileSystem.moveAsync({ from: temporary, to: path + name });
    } catch (reason) {
      await FileSystem.deleteAsync(temporary, { idempotent: true }).catch(() => {});
      throw reason;
    }
    // Conserva una copia anterior completa. Nunca elimina datos de capturas o de sesiones.
    try {
      for (const old of await FileSystem.readDirectoryAsync(path)) {
        if (old !== name && old !== previous?.name && (/^\d+-[a-z0-9]+\.json$/.test(old) || old.endsWith('.tmp'))) {
          await FileSystem.deleteAsync(path + old, { idempotent: true });
        }
      }
    } catch { /* La copia nueva ya está publicada; la limpieza se puede reintentar. */ }
  });
  writes.set(path, write);
  try { await write; }
  finally { if (writes.get(path) === write) writes.delete(path); }
}
