import { fieldUiMock } from './helpers/fieldUiMock.mjs';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { dirname, resolve } from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';
import ts from 'typescript';

const require = createRequire(import.meta.url);
const root = resolve(dirname(fileURLToPath(import.meta.url)), '../src');
const clone = (v) => JSON.parse(JSON.stringify(v));
const catalog = (version = 218) => ({
  temporada: { id: 'season', version_catalogo: version },
  categorias: [{ id: 'category', nombre: 'Exportación', activo: true }],
  articulos: [{ id: 'article', especie: 'Cereza', variedad: 'Santina', calibre: '2J', envase: '5 kg', activo: true }],
  origenes: [{ id: 'origin', cliente: 'Cliente', marca: 'Marca', csg: '12345', activo: true }],
  combinaciones: [{ id: 'combination', articulo_validacion_id: 'article', origen_validacion_id: 'origin' }],
  tipos_bulto: ['pallet', 'saldo'], resultados: ['aprobado', 'observado', 'rechazado'], motivos: ['otro'], generado_at: '2026-10-28T12:00:00Z',
});

function harness(maxNativeBytes = Infinity) {
  const files = new Map(); const directories = new Set(); const storage = new Map(); const modules = new Map();
  let failWrite = false; let fetchImpl = async () => new Response('{}');
  const disk = {
    documentDirectory: 'file:///private/',
    getInfoAsync: async (path) => ({ exists: directories.has(path) || files.has(path) }),
    makeDirectoryAsync: async (path) => { directories.add(path); },
    readDirectoryAsync: async (path) => [...files.keys()].filter((key) => key.startsWith(path) && !key.slice(path.length).includes('/')).map((key) => key.slice(path.length)),
    readAsStringAsync: async (path) => { if (!files.has(path)) throw new Error('Archivo ausente.'); return files.get(path); },
    writeAsStringAsync: async (path, value) => {
      if (Buffer.byteLength(value, 'utf8') > maxNativeBytes) throw new Error('La escritura supera el búfer nativo.');
      const failed = typeof failWrite === 'function' ? failWrite(path) : failWrite;
      files.set(path, failed ? '{incompleto' : value);
      if (failed) throw new Error('Almacenamiento lleno.');
    },
    moveAsync: async ({ from, to }) => { assert.ok(files.has(from)); files.set(to, files.get(from)); files.delete(from); },
    deleteAsync: async (path) => { files.delete(path); },
  };
  const asyncStorage = {
    getItem: async (key) => storage.get(key) ?? null,
    setItem: async (key, value) => { storage.set(key, value); },
    getAllKeys: async () => [...storage.keys()], removeItem: async (key) => { storage.delete(key); },
    multiRemove: async (keys) => { keys.forEach((key) => storage.delete(key)); },
    multiGet: async (keys) => keys.map((key) => [key, storage.get(key) ?? null]),
    multiSet: async () => { throw new Error('database or disk is full (quota AsyncStorage)'); },
  };
  function load(path) {
    if (modules.has(path)) return modules.get(path).exports;
    const module = { exports: {} }; modules.set(path, module);
    const source = ts.transpileModule(readFileSync(path, 'utf8'), { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText;
    vm.runInNewContext(source, {
      module, exports: module.exports, Error, Headers, URLSearchParams, AbortController, setTimeout, clearTimeout, console,
      fetch: (...args) => fetchImpl(...args),
      require: (id) => {
      const fieldMock = fieldUiMock(id); if (fieldMock) return fieldMock;
        if (id === 'expo-file-system/legacy') return disk;
        if (id === 'expo-crypto') return { CryptoDigestAlgorithm: { SHA256: 'SHA256' }, digestStringAsync: async (_algorithm, raw) => require('node:crypto').createHash('sha256').update(raw).digest('hex'), randomUUID: require('node:crypto').randomUUID };
        if (id === '@react-native-async-storage/async-storage') return asyncStorage;
        if (id.startsWith('.')) return load(resolve(dirname(path), id) + '.ts');
        return require(id);
      },
    }, { filename: path });
    return module.exports;
  }
  return {
    files, storage, disk, api: load(resolve(root, 'services/validationApi.ts')),
    offline: load(resolve(root, 'services/validationOfflineStore.ts')), compatibility: load(resolve(root, 'domain/validationCatalogIndex.ts')),
    failWrite: (value) => { failWrite = value; }, fetch: (fn) => { fetchImpl = fn; },
  };
}

test('migra v218, publica v227 y otro usuario comparte la copia sin tocar sus capturas ni su jornada', async () => {
  const h = harness();
  h.storage.set('estiba_validation_catalog:1:pda', JSON.stringify(catalog()));
  h.storage.set('estiba_validation_catalog:2:pda', JSON.stringify(catalog()));
  h.storage.set('estiba_validation_outbox:1:pda', '[{"id":"pendiente-1"}]');
  h.storage.set('estiba_validation_outbox:2:pda', '[{"id":"pendiente-2"}]');
  h.storage.set('estiba_validation_work_context:1:pda', '{"linea_proceso":1,"turno":"A"}');
  h.storage.set('estiba_validation_catalog:1:otra-pda', JSON.stringify(catalog()));
  const captures = [...h.storage].filter(([key]) => !key.startsWith('estiba_validation_catalog:'));
  assert.equal((await h.offline.loadCachedValidationCatalog('1', 'pda', 'http://planta')).temporada.version_catalogo, 218);
  await h.offline.saveValidationCatalog('1', 'pda', catalog(227), 'http://planta');
  assert.equal((await h.offline.loadCachedValidationCatalog('2', 'pda', 'http://planta/')).temporada.version_catalogo, 227);
  assert.deepEqual([...h.storage].filter(([key]) => !key.startsWith('estiba_validation_catalog:')), captures);
  assert.ok(h.storage.has('estiba_validation_catalog:1:otra-pda'));
  assert.equal([...h.files.keys()].filter((key) => key.endsWith('.json')).length, 2, 'Copia actual y respaldo, sin duplicar por usuario');
});

test('una actualización interrumpida no sustituye v218 ni elimina la bandeja', async () => {
  const h = harness();
  await h.offline.saveValidationCatalog('1', 'pda', catalog(218), 'http://planta');
  h.storage.set('estiba_validation_outbox:1:pda', '[{"id":"sin-enviar"}]');
  const previousFiles = [...h.files.keys()];
  h.failWrite((file) => file.endsWith('.combinaciones-0.part'));
  await assert.rejects(h.offline.saveValidationCatalog('1', 'pda', catalog(227), 'http://planta'), /Almacenamiento lleno/);
  assert.equal((await h.offline.loadCachedValidationCatalog('1', 'pda', 'http://planta')).temporada.version_catalogo, 218);
  assert.equal(h.storage.get('estiba_validation_outbox:1:pda'), '[{"id":"sin-enviar"}]');
  assert.deepEqual([...h.files.keys()], previousFiles, 'Elimina las partes nuevas aunque falle después de varias escrituras');
});

test('una copia dañada recupera el respaldo y los catálogos de distintos servidores quedan aislados', async () => {
  const h = harness();
  await h.offline.saveValidationCatalog('1', 'pda', catalog(218), 'http://planta');
  await h.offline.saveValidationCatalog('1', 'pda', catalog(227), 'http://planta');
  const newest = [...h.files.keys()].filter((key) => key.endsWith('.json')).sort().at(-1);
  h.files.set(`${newest}.combinaciones-0.part`, '{incompleto');
  assert.equal((await h.offline.loadCachedValidationCatalog('1', 'pda', 'http://planta')).temporada.version_catalogo, 218);
  assert.equal(await h.offline.loadCachedValidationCatalog('1', 'pda', 'http://otra-planta'), null);
});

test('no publica respuestas incompletas ni permite que una escritura atrasada reemplace v227', async () => {
  const h = harness();
  const results = await Promise.allSettled([
    h.offline.saveValidationCatalog('1', 'pda', catalog(227), 'http://planta'),
    h.offline.saveValidationCatalog('2', 'pda', catalog(218), 'http://planta'),
  ]);
  assert.equal(results[0].status, 'fulfilled'); assert.equal(results[1].status, 'rejected');
  const incomplete = catalog(228); incomplete.articulos = [];
  await assert.rejects(h.offline.saveValidationCatalog('1', 'pda', incomplete, 'http://planta'), /sin artículo u origen/);
  assert.equal((await h.offline.loadCachedValidationCatalog('3', 'pda', 'http://planta')).temporada.version_catalogo, 227);
});

test('lee el respaldo nativo anterior y lo conserva cuando instala la copia por partes', async () => {
  const h = harness();
  const directory = 'file:///private/validation-catalogs/http%3A%2F%2Fplanta/pda/';
  await h.disk.makeDirectoryAsync(directory);
  const legacy = catalog(417);
  const counts = { categorias: 1, articulos: 1, origenes: 1, combinaciones: 1 };
  const oldFile = directory + '0000000000000001-anterior.json';
  h.files.set(oldFile, JSON.stringify({ schema: 1, counts, catalog: legacy }));
  assert.equal((await h.offline.loadCachedValidationCatalog('1', 'pda', 'http://planta')).temporada.version_catalogo, 417);

  await h.offline.saveValidationCatalog('1', 'pda', catalog(442), 'http://planta');
  assert.equal((await h.offline.loadCachedValidationCatalog('1', 'pda', 'http://planta')).temporada.version_catalogo, 442);
  assert.ok(h.files.has(oldFile));
});

test('v442: guarda 76420 artículos, 342 orígenes y 523632 vínculos sin superar el búfer nativo', async () => {
  const h = harness(128 * 1024); const large = catalog(442);
  const id = (prefix, n) => `${prefix}0000000-0000-4000-8000-${String(n).padStart(12, '0')}`;
  large.origenes = Array.from({ length: 342 }, (_, n) => ({ ...large.origenes[0], id: id('b', n), csg: String(100000 + n), variedad_ids: null }));
  large.articulos = Array.from({ length: 76420 }, (_, n) => ({ ...large.articulos[0], id: id('a', n), created_at: 'timestamp no operativo', especie_validacion_id: 'FK interna' }));
  large.combinaciones = Array.from({ length: 523632 }, (_, n) => ({
    id: id('c', n),
    articulo_validacion_id: id('a', n % large.articulos.length),
    origen_validacion_id: large.origenes[n % large.origenes.length].id,
    codigo_externo: null,
  }));
  await h.offline.saveValidationCatalog('nuevo', 'pda', large, 'http://planta');
  const saved = await h.offline.loadCachedValidationCatalog('otro-usuario', 'pda', 'http://planta');
  assert.equal(saved.temporada.version_catalogo, 442);
  assert.equal(saved.articulos.length, 76420); assert.equal(saved.origenes.length, 342);
  assert.equal(h.compatibility.catalogCombinationCount(saved), 523632);
  assert.equal(saved.combinaciones.length, 0);
  const index = h.compatibility.indexValidationCatalog(saved);
  assert.ok(large.combinaciones.every((row) => index.articlesByOrigin.get(row.origen_validacion_id).has(row.articulo_validacion_id)));
  assert.equal('created_at' in saved.articulos[0], false);
  assert.equal('especie_validacion_id' in saved.articulos[0], false);
  assert.equal(h.storage.size, 0, 'No almacena otra copia masiva en la base de capturas');
});

test('migra un respaldo schema 2 por lotes sin materializar los objetos de combinaciones', async () => {
  const h = harness(); const source = catalog(442);
  const directory = 'file:///private/validation-catalogs/http%3A%2F%2Fplanta/pda/';
  await h.disk.makeDirectoryAsync(directory);
  const name = '0000000000000001-schema2.json';
  const { categorias, articulos, origenes, combinaciones, ...metadata } = source;
  const hashes = {}; const counts = {};
  for (const [collection, rows] of Object.entries({ categorias, articulos, origenes, combinaciones })) {
    const raw = JSON.stringify(rows); counts[collection] = rows.length;
    hashes[collection] = [require('node:crypto').createHash('sha256').update(raw).digest('hex')];
    h.files.set(`${directory}${name}.${collection}-0.part`, raw);
  }
  h.files.set(directory + name, JSON.stringify({ schema: 2, counts, metadata, hashes }));
  const loaded = await h.offline.loadCachedValidationCatalog('user', 'pda', 'http://planta');
  assert.equal(loaded.combinaciones.length, 0);
  assert.equal(h.compatibility.catalogCombinationCount(loaded), 1);
  assert.equal(h.compatibility.indexValidationCatalog(loaded).articlesByOrigin.get('origin').has('article'), true);
  await h.offline.saveValidationCatalog('user', 'pda', { ...loaded, temporada: { ...loaded.temporada, version_catalogo: 443 } }, 'http://planta');
  assert.ok(h.files.has(directory + name), 'Conserva el respaldo anterior');
});

test('un índice numérico inválido recupera el respaldo incluso con hashes de partes válidos', async () => {
  const h = harness();
  await h.offline.saveValidationCatalog('u', 'pda', catalog(441), 'http://planta');
  await h.offline.saveValidationCatalog('u', 'pda', catalog(442), 'http://planta');
  const newest = [...h.files.keys()].filter((p) => p.endsWith('.json')).sort().at(-1);
  const manifest = JSON.parse(h.files.get(newest));
  const bad = '[999999]'; h.files.set(newest + '.combinaciones-0.part', bad);
  manifest.hashes.combinaciones[0] = require('node:crypto').createHash('sha256').update(bad).digest('hex');
  h.files.set(newest, JSON.stringify(manifest));
  assert.equal((await h.offline.loadCachedValidationCatalog('u', 'pda', 'http://planta')).temporada.version_catalogo, 441);
});

test('ETag envía v218, recibe v227 y un 304 solo reutiliza una copia existente', async () => {
  const h = harness();
  h.fetch(async (_url, options) => {
    assert.equal(options.headers.get('If-None-Match'), '"validacion-catalogo-season-218"');
    return new Response(JSON.stringify(catalog(227)), { status: 200 });
  });
  assert.equal((await h.api.getValidationCatalog('http://planta', 'token', catalog(218))).temporada.version_catalogo, 227);
  h.fetch(async () => new Response(null, { status: 304 }));
  assert.equal(await h.api.getValidationCatalog('http://planta', 'token', catalog(227)), null);
  await assert.rejects(h.api.getValidationCatalog('http://planta', 'token'), /no tiene una copia válida/);
});

test('no acepta JSON inválido, relaciones incompletas ni versiones anteriores a la copia local', async () => {
  const h = harness();
  h.fetch(async () => new Response('{incompleto', { status: 200 }));
  await assert.rejects(h.api.getValidationCatalog('http://planta', 'token', catalog()), /catálogo incompleto/);
  const incomplete = catalog(227); incomplete.origenes = [];
  h.fetch(async () => new Response(JSON.stringify(incomplete)));
  await assert.rejects(h.api.getValidationCatalog('http://planta', 'token', catalog()), /sin artículo u origen/);
  h.fetch(async () => new Response(JSON.stringify(catalog(218))));
  await assert.rejects(h.api.getValidationCatalog('http://planta', 'token', catalog(227)), /anterior al catálogo v227/);
});
