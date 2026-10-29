import AsyncStorage from '@react-native-async-storage/async-storage';
import { assertValidationCatalog } from '../domain/validationCatalogData';
import { loadValidationCatalogFile, saveValidationCatalogFile, usesNativeCatalogFiles } from './validationCatalogFileStore';

import {
  RegisterValidationPayload,
  ValidationCatalog,
  ValidationWorkContext,
  ValidationOutboxItem,
  ValidationOutboxStatus,
  ValidationSessionSnapshot,
} from '../domain/validation';

const CATALOG_STORAGE_SCHEMA = 2;
const CATALOG_CHUNK_SIZE = 200;
const MAX_CACHED_SESSION_ATTEMPTS = 100;
const CATALOG_COLLECTIONS = [
  'categorias',
  'articulos',
  'origenes',
  'combinaciones',
] as const;

type CatalogCollection = typeof CATALOG_COLLECTIONS[number];
type CatalogMetadata = Omit<ValidationCatalog, CatalogCollection>;
type CatalogManifest = {
  schema_version: typeof CATALOG_STORAGE_SCHEMA;
  generation: string;
  metadata: CatalogMetadata;
  chunks: Record<CatalogCollection, number>;
};

function catalogKey(userId: string, deviceId: string) {
  return `estiba_validation_catalog:${userId}:${deviceId}`;
}

function catalogManifestKey(userId: string, deviceId: string) {
  return `${catalogKey(userId, deviceId)}:manifest:v${CATALOG_STORAGE_SCHEMA}`;
}

function catalogChunkPrefix(userId: string, deviceId: string) {
  return `${catalogKey(userId, deviceId)}:chunk:v${CATALOG_STORAGE_SCHEMA}:`;
}

function catalogChunkKey(
  userId: string,
  deviceId: string,
  generation: string,
  collection: CatalogCollection,
  index: number,
) {
  return `${catalogChunkPrefix(userId, deviceId)}${generation}:${collection}:${index}`;
}

function outboxKey(userId: string, deviceId: string) {
  return `estiba_validation_outbox:${userId}:${deviceId}`;
}

function workContextKey(userId: string, deviceId: string) {
  return `estiba_validation_work_context:${userId}:${deviceId}`;
}

function sessionKey(userId: string, deviceId: string, sessionId: string) {
  return `estiba_validation_session:${userId}:${deviceId}:${sessionId}`;
}

function chunks<T>(items: T[], size: number): T[][] {
  const result: T[][] = [];
  for (let index = 0; index < items.length; index += size) {
    result.push(items.slice(index, index + size));
  }
  return result;
}

function parseCatalogManifest(raw: string): CatalogManifest {
  const parsed = JSON.parse(raw) as Partial<CatalogManifest>;
  const validChunks = parsed.chunks
    && CATALOG_COLLECTIONS.every((collection) => (
      Number.isInteger(parsed.chunks?.[collection])
      && Number(parsed.chunks?.[collection]) >= 0
      && Number(parsed.chunks?.[collection]) <= 10_000
    ));

  if (
    parsed.schema_version !== CATALOG_STORAGE_SCHEMA
    || typeof parsed.generation !== 'string'
    || parsed.generation === ''
    || !parsed.metadata
    || !validChunks
  ) {
    throw new Error('Manifiesto de catálogo inválido.');
  }

  return parsed as CatalogManifest;
}

async function removeCatalogGeneration(
  userId: string,
  deviceId: string,
  generation: string,
) {
  const prefix = `${catalogChunkPrefix(userId, deviceId)}${generation}:`;
  const keys = (await AsyncStorage.getAllKeys()).filter((key) => key.startsWith(prefix));
  if (keys.length > 0) await AsyncStorage.multiRemove(keys);
}

async function removeStaleCatalogChunks(
  userId: string,
  deviceId: string,
  currentGeneration: string,
) {
  const prefix = catalogChunkPrefix(userId, deviceId);
  const currentPrefix = `${prefix}${currentGeneration}:`;
  const staleKeys = (await AsyncStorage.getAllKeys())
    .filter((key) => key.startsWith(prefix) && !key.startsWith(currentPrefix));
  if (staleKeys.length > 0) await AsyncStorage.multiRemove(staleKeys);
}

export async function loadCachedValidationSession(
  userId: string,
  deviceId: string,
  sessionId: string,
): Promise<ValidationSessionSnapshot | null> {
  const raw = await AsyncStorage.getItem(sessionKey(userId, deviceId, sessionId));
  if (!raw) return null;

  try {
    return JSON.parse(raw) as ValidationSessionSnapshot;
  } catch {
    await AsyncStorage.removeItem(sessionKey(userId, deviceId, sessionId));
    return null;
  }
}

export async function saveValidationSession(
  userId: string,
  deviceId: string,
  sessionId: string,
  snapshot: ValidationSessionSnapshot,
) {
  const cachedSnapshot = snapshot.data.length > MAX_CACHED_SESSION_ATTEMPTS
    ? { ...snapshot, data: snapshot.data.slice(0, MAX_CACHED_SESSION_ATTEMPTS) }
    : snapshot;

  await AsyncStorage.setItem(
    sessionKey(userId, deviceId, sessionId),
    JSON.stringify(cachedSnapshot),
  );
}

export async function loadValidationWorkContext(
  userId: string,
  deviceId: string,
): Promise<ValidationWorkContext | null> {
  const raw = await AsyncStorage.getItem(workContextKey(userId, deviceId));
  if (!raw) return null;

  try {
    const parsed = JSON.parse(raw) as ValidationWorkContext;
    if (![1, 2, 3].includes(parsed.linea_proceso) || !['A', 'B'].includes(parsed.turno)) {
      throw new Error('Contexto de jornada inválido.');
    }
    return parsed;
  } catch {
    await AsyncStorage.removeItem(workContextKey(userId, deviceId));
    return null;
  }
}

export async function saveValidationWorkContext(
  userId: string,
  deviceId: string,
  context: ValidationWorkContext,
) {
  await AsyncStorage.setItem(workContextKey(userId, deviceId), JSON.stringify(context));
}

async function loadLegacyValidationCatalog(
  userId: string,
  deviceId: string,
  migrate = true,
): Promise<ValidationCatalog | null> {
  const manifestKey = catalogManifestKey(userId, deviceId);
  const manifestRaw = await AsyncStorage.getItem(manifestKey);

  if (manifestRaw) {
    let manifest: CatalogManifest | null = null;
    try {
      manifest = parseCatalogManifest(manifestRaw);
      const collections: Record<CatalogCollection, unknown[]> = {
        categorias: [],
        articulos: [],
        origenes: [],
        combinaciones: [],
      };
      const keys: string[] = [];

      for (const collection of CATALOG_COLLECTIONS) {
        for (let index = 0; index < manifest.chunks[collection]; index += 1) {
          keys.push(catalogChunkKey(
            userId,
            deviceId,
            manifest.generation,
            collection,
            index,
          ));
        }
      }

      const values = await AsyncStorage.multiGet(keys);
      let offset = 0;
      for (const collection of CATALOG_COLLECTIONS) {
        for (let index = 0; index < manifest.chunks[collection]; index += 1) {
          const raw = values[offset]?.[1];
          offset += 1;
          if (!raw) throw new Error('Fragmento de catálogo ausente.');
          const parsed = JSON.parse(raw) as unknown;
          if (!Array.isArray(parsed)) throw new Error('Fragmento de catálogo inválido.');
          collections[collection].push(...parsed);
        }
      }

      const catalog = {
        ...manifest.metadata,
        categorias: collections.categorias as ValidationCatalog['categorias'],
        articulos: collections.articulos as ValidationCatalog['articulos'],
        origenes: collections.origenes as ValidationCatalog['origenes'],
        combinaciones: collections.combinaciones as ValidationCatalog['combinaciones'],
      };
      assertValidationCatalog(catalog);
      return catalog;
    } catch {
      await AsyncStorage.removeItem(manifestKey);
      if (manifest) {
        try {
          await removeCatalogGeneration(userId, deviceId, manifest.generation);
        } catch {
          // Los fragmentos huérfanos se eliminarán en la próxima sincronización.
        }
      }
    }
  }

  let legacyRaw: string | null = null;
  try {
    legacyRaw = await AsyncStorage.getItem(catalogKey(userId, deviceId));
  } catch {
    await AsyncStorage.removeItem(catalogKey(userId, deviceId));
    return null;
  }
  if (!legacyRaw) return null;

  let legacyCatalog: ValidationCatalog;
  try {
    legacyCatalog = JSON.parse(legacyRaw) as ValidationCatalog;
    assertValidationCatalog(legacyCatalog);
  } catch {
    await AsyncStorage.removeItem(catalogKey(userId, deviceId));
    return null;
  }

  try {
    if (migrate) await saveLegacyValidationCatalog(userId, deviceId, legacyCatalog);
  } catch {
    // La copia antigua sigue disponible durante esta sesión aunque la migración falle.
  }

  return legacyCatalog;
}

async function saveLegacyValidationCatalog(
  userId: string,
  deviceId: string,
  catalog: ValidationCatalog,
) {
  assertValidationCatalog(catalog);
  const generation = [
    catalog.temporada.id,
    catalog.temporada.version_catalogo,
    Date.now().toString(36),
    Math.random().toString(36).slice(2, 8),
  ].join('-');
  const {
    categorias,
    articulos,
    origenes,
    combinaciones,
    ...metadata
  } = catalog;
  const collectionValues: Record<CatalogCollection, unknown[]> = {
    categorias,
    articulos,
    origenes,
    combinaciones,
  };
  const manifest: CatalogManifest = {
    schema_version: CATALOG_STORAGE_SCHEMA,
    generation,
    metadata,
    chunks: {
      categorias: 0,
      articulos: 0,
      origenes: 0,
      combinaciones: 0,
    },
  };
  const pairs: Array<[string, string]> = [];

  for (const collection of CATALOG_COLLECTIONS) {
    const collectionChunks = chunks(collectionValues[collection], CATALOG_CHUNK_SIZE);
    manifest.chunks[collection] = collectionChunks.length;
    collectionChunks.forEach((items, index) => {
      pairs.push([
        catalogChunkKey(userId, deviceId, generation, collection, index),
        JSON.stringify(items),
      ]);
    });
  }

  try {
    if (pairs.length > 0) await AsyncStorage.multiSet(pairs);
    await AsyncStorage.setItem(
      catalogManifestKey(userId, deviceId),
      JSON.stringify(manifest),
    );
  } catch (reason) {
    try {
      await removeCatalogGeneration(userId, deviceId, generation);
    } catch {
      // No reemplaza el manifiesto anterior si la nueva escritura queda incompleta.
    }
    throw reason;
  }

  try {
    await AsyncStorage.removeItem(catalogKey(userId, deviceId));
    await removeStaleCatalogChunks(userId, deviceId, generation);
  } catch {
    // El manifiesto nuevo ya es válido; la limpieza puede reintentarse después.
  }
}

export async function loadCachedValidationCatalog(userId: string, deviceId: string, server?: string | null): Promise<ValidationCatalog | null> {
  if (!server || !usesNativeCatalogFiles) return loadLegacyValidationCatalog(userId, deviceId);
  try {
    const shared = await loadValidationCatalogFile(server, deviceId);
    if (shared) return shared;
  } catch { /* La lectura de la copia antigua y la descarga siguen disponibles. */ }
  const legacy = await loadLegacyValidationCatalog(userId, deviceId, false);
  if (legacy) {
    try { await saveValidationCatalog(userId, deviceId, legacy, server); }
    catch { /* Conserva la copia antigua hasta que se publique un archivo completo. */ }
  }
  return legacy;
}

export async function saveValidationCatalog(userId: string, deviceId: string, catalog: ValidationCatalog, server?: string | null) {
  if (!server || !usesNativeCatalogFiles) return saveLegacyValidationCatalog(userId, deviceId, catalog);
  await saveValidationCatalogFile(server, deviceId, catalog);
  try {
    // AsyncStorage tiene una cuota compartida entre usuarios. Retirar solo cachés de
    // catálogo después de publicar el archivo; nunca la bandeja ni sus UUID.
    const device = `:${deviceId}`;
    const legacyKeys = (await AsyncStorage.getAllKeys()).filter((key) => key.startsWith('estiba_validation_catalog:')
      && (key.endsWith(device) || key.includes(`${device}:`)));
    if (legacyKeys.length) await AsyncStorage.multiRemove(legacyKeys);
  } catch { /* Una limpieza fallida no invalida el catálogo ya guardado. */ }
}

export async function loadValidationOutbox(
  userId: string,
  deviceId: string,
): Promise<ValidationOutboxItem[]> {
  const raw = await AsyncStorage.getItem(outboxKey(userId, deviceId));
  if (!raw) return [];

  try {
    const parsed = JSON.parse(raw) as ValidationOutboxItem[];
    return Array.isArray(parsed) ? parsed : [];
  } catch {
    await AsyncStorage.removeItem(outboxKey(userId, deviceId));
    return [];
  }
}

async function saveOutbox(
  userId: string,
  deviceId: string,
  items: ValidationOutboxItem[],
) {
  await AsyncStorage.setItem(outboxKey(userId, deviceId), JSON.stringify(items));
}

const outboxWrites = new Map<string, Promise<void>>();

async function updateOutbox(
  userId: string,
  deviceId: string,
  update: (items: ValidationOutboxItem[]) => ValidationOutboxItem[],
): Promise<ValidationOutboxItem[]> {
  const key = outboxKey(userId, deviceId);
  const write = (outboxWrites.get(key) ?? Promise.resolve()).then(async () => {
    const items = update(await loadValidationOutbox(userId, deviceId));
    await saveOutbox(userId, deviceId, items);
    return items;
  });
  const settled = write.then(() => {}, () => {});
  outboxWrites.set(key, settled);
  try {
    return await write;
  } finally {
    if (outboxWrites.get(key) === settled) outboxWrites.delete(key);
  }
}

export async function enqueueValidation(
  userId: string,
  deviceId: string,
  payload: RegisterValidationPayload,
): Promise<ValidationOutboxItem[]> {
  return updateOutbox(userId, deviceId, (items) => {
    if (items.some((item) => item.id === payload.operacion_id)) return items;
    return [...items, pendingValidation(payload)];
  });
}

function pendingValidation(payload: RegisterValidationPayload): ValidationOutboxItem {
  return {
    id: payload.operacion_id, payload, status: 'pendiente', attempts: 0,
    created_at: new Date().toISOString(), last_attempt_at: null, message: null,
  };
}

// La captura original se reemplaza en una sola escritura, después de comprobar
// en el servidor que no creó una validación. No se retira al abrir el editor.
export async function replaceValidationOutboxItem(
  userId: string,
  deviceId: string,
  operationId: string,
  payload: RegisterValidationPayload,
): Promise<ValidationOutboxItem[]> {
  return updateOutbox(userId, deviceId, (items) => {
    const original = items.find((item) => item.id === operationId);
    if (!original || original.status === 'pendiente') {
      throw new Error('La captura cambió mientras la corregías. Vuelve a retomarla desde la bandeja.');
    }
    if (original.payload.numero_folio.trim().toUpperCase() !== payload.numero_folio.trim().toUpperCase()
      || original.payload.temporada_id !== payload.temporada_id
      || items.some((item) => item.id === payload.operacion_id)) {
      throw new Error('La corrección debe conservar el folio y la temporada y utilizar un envío nuevo.');
    }
    return items.map((item) => item.id === operationId ? pendingValidation(payload) : item);
  });
}

export async function removeValidationFromOutbox(
  userId: string,
  deviceId: string,
  operationId: string,
): Promise<ValidationOutboxItem[]> {
  return updateOutbox(userId, deviceId, (items) => items.filter((item) => item.id !== operationId));
}

export async function markValidationOutboxItem(
  userId: string,
  deviceId: string,
  operationId: string,
  status: ValidationOutboxStatus,
  message: string | null,
): Promise<ValidationOutboxItem[]> {
  return updateOutbox(userId, deviceId, (items) => items.map((item) => item.id === operationId
    ? {
      ...item,
      status,
      attempts: item.attempts + 1,
      last_attempt_at: new Date().toISOString(),
      message,
    }
    : item));
}

export async function retryValidationOutboxItem(
  userId: string,
  deviceId: string,
  operationId: string,
): Promise<ValidationOutboxItem[]> {
  return updateOutbox(userId, deviceId, (items) => items.map((item) => item.id === operationId
    ? { ...item, status: 'pendiente' as const, message: null }
    : item));
}
