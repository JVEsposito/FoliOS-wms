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

function harness() {
  const files = new Map(); const directories = new Set(); const storage = new Map(); const modules = new Map();
  let failWrite = false; let fetchImpl = async () => new Response('{}');
  const disk = {
    documentDirectory: 'file:///private/',
    getInfoAsync: async (path) => ({ exists: directories.has(path) || files.has(path) }),
    makeDirectoryAsync: async (path) => { directories.add(path); },
    readDirectoryAsync: async (path) => [...files.keys()].filter((key) => key.startsWith(path) && !key.slice(path.length).includes('/')).map((key) => key.slice(path.length)),
    readAsStringAsync: async (path) => { if (!files.has(path)) throw new Error('Archivo ausente.'); return files.get(path); },
    writeAsStringAsync: async (path, value) => { files.set(path, failWrite ? '{incompleto' : value); if (failWrite) throw new Error('Almacenamiento lleno.'); },
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
    offline: load(resolve(root, 'services/validationOfflineStore.ts')),
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
  assert.equal(h.files.size, 2, 'Copia actual y respaldo, sin duplicar por usuario');
});

test('una actualización interrumpida no sustituye v218 ni elimina la bandeja', async () => {
  const h = harness();
  await h.offline.saveValidationCatalog('1', 'pda', catalog(218), 'http://planta');
  h.storage.set('estiba_validation_outbox:1:pda', '[{"id":"sin-enviar"}]');
  h.failWrite(true);
  await assert.rejects(h.offline.saveValidationCatalog('1', 'pda', catalog(227), 'http://planta'), /Almacenamiento lleno/);
  assert.equal((await h.offline.loadCachedValidationCatalog('1', 'pda', 'http://planta')).temporada.version_catalogo, 218);
  assert.equal(h.storage.get('estiba_validation_outbox:1:pda'), '[{"id":"sin-enviar"}]');
  assert.ok([...h.files.keys()].every((key) => !key.endsWith('.tmp')));
});

test('una copia dañada recupera el respaldo y los catálogos de distintos servidores quedan aislados', async () => {
  const h = harness();
  await h.offline.saveValidationCatalog('1', 'pda', catalog(218), 'http://planta');
  await h.offline.saveValidationCatalog('1', 'pda', catalog(227), 'http://planta');
  h.files.set([...h.files.keys()].sort().at(-1), '{incompleto');
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

test('el volumen de 29676 artículos y 37184 combinaciones se guarda fuera de la cuota de AsyncStorage', async () => {
  const h = harness(); const large = catalog(227);
  large.articulos = Array.from({ length: 29676 }, (_, n) => ({ ...large.articulos[0], id: `article-${n}`, created_at: 'timestamp no operativo', especie_validacion_id: 'FK interna' }));
  large.combinaciones = Array.from({ length: 37184 }, (_, n) => ({ id: `combination-${n}`, articulo_validacion_id: `article-${n % 29676}`, origen_validacion_id: 'origin' }));
  await h.offline.saveValidationCatalog('nuevo', 'pda', large, 'http://planta');
  const saved = await h.offline.loadCachedValidationCatalog('otro-usuario', 'pda', 'http://planta');
  assert.equal(saved.articulos.length, 29676); assert.equal(saved.combinaciones.length, 37184);
  assert.equal('created_at' in saved.articulos[0], false);
  assert.equal('especie_validacion_id' in saved.articulos[0], false);
  assert.equal(h.storage.size, 0, 'No almacena otra copia masiva en la base de capturas');
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
