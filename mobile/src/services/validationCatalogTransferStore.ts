import * as Crypto from 'expo-crypto';
import * as FileSystem from 'expo-file-system/legacy';

export const supportsCatalogTransfer = Boolean(FileSystem.documentDirectory);

function directory(server: string, deviceId: string) {
  return `${FileSystem.documentDirectory}validation-transfers-v1/${encodeURIComponent(server.trim().replace(/\/+$/, ''))}/${encodeURIComponent(deviceId)}/`;
}

export async function verifyCatalogFragment(raw: string, hash: string) {
  if (await catalogFragmentHash(raw) !== hash) {
    throw new Error('Una parte del catálogo llegó dañada. Se conservaron las partes válidas; reintenta la sincronización.');
  }
}

export function catalogFragmentHash(raw: string) {
  return Crypto.digestStringAsync(Crypto.CryptoDigestAlgorithm.SHA256, raw);
}

/** Las partes verificadas sobreviven al cierre de sesión y a una interrupción de la app. */
export async function readCatalogFragment(server: string, deviceId: string, hash: string): Promise<string | null> {
  const path = directory(server, deviceId) + hash + '.json';
  try {
    const raw = await FileSystem.readAsStringAsync(path);
    await verifyCatalogFragment(raw, hash);
    return raw;
  } catch { return null; }
}

export async function writeCatalogFragment(server: string, deviceId: string, hash: string, raw: string) {
  await verifyCatalogFragment(raw, hash);
  const path = directory(server, deviceId);
  await FileSystem.makeDirectoryAsync(path, { intermediates: true });
  const temporary = `${path}${hash}-${Crypto.randomUUID()}.tmp`;
  try {
    await FileSystem.writeAsStringAsync(temporary, raw);
    await verifyCatalogFragment(await FileSystem.readAsStringAsync(temporary), hash);
    await FileSystem.deleteAsync(path + hash + '.json', { idempotent: true });
    await FileSystem.moveAsync({ from: temporary, to: path + hash + '.json' });
  } catch (reason) {
    await FileSystem.deleteAsync(temporary, { idempotent: true }).catch(() => {});
    throw reason;
  }
}

export async function pruneCatalogFragments(server: string, deviceId: string, keep: string[]) {
  const path = directory(server, deviceId);
  const names = new Set(keep.map((hash) => hash + '.json'));
  try {
    for (const name of await FileSystem.readDirectoryAsync(path)) {
      const staleTemporary = name.endsWith('.tmp')
        && ((await FileSystem.getInfoAsync(path + name) as { modificationTime?: number }).modificationTime ?? Infinity) < Date.now() / 1000 - 600;
      if (!names.has(name) && (/^[a-f0-9]{64}\.json$/.test(name) || staleTemporary)) {
        await FileSystem.deleteAsync(path + name, { idempotent: true });
      }
    }
  } catch { /* La limpieza no afecta el catálogo completo publicado ni la bandeja. */ }
}
