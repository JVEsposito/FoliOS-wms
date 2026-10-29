import assert from 'node:assert/strict';
import { createHash, randomUUID } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { dirname, resolve } from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';
import { gzipSync } from 'node:zlib';
import ts from 'typescript';

const require = createRequire(import.meta.url);
const root = resolve(dirname(fileURLToPath(import.meta.url)), '../src');
const hash = (raw) => createHash('sha256').update(raw).digest('hex');
const clone = (value) => JSON.parse(JSON.stringify(value));
const columns = {
  categorias: ['id', 'nombre', 'codigo_externo'],
  articulos: ['id', 'variedad_validacion_id', 'especie', 'variedad', 'calibre', 'envase', 'codigo_externo'],
  origenes: ['id', 'cliente_validacion_id', 'csg_validacion_id', 'variedad_ids', 'cliente', 'marca', 'csg', 'predio', 'codigo_externo'],
  combinaciones: ['id', 'articulo_validacion_id', 'origen_validacion_id', 'codigo_externo'],
};
const id = (name) => { const s = hash(name); return `${s.slice(0, 8)}-${s.slice(8, 12)}-${s.slice(12, 16)}-${s.slice(16, 20)}-${s.slice(20, 32)}`; };
function catalog(version = 227, articleCount = 96, combinationCount = 128, originCount = 3) {
  const base = { temporada_id: 'season', activo: true, codigo_externo: null };
  return {
    temporada: { id: 'season', codigo: 'TEMP-2627', nombre: 'Temporada', activa: true, version_catalogo: version },
    categorias: [{ ...base, id: id('category'), nombre: 'Exportación' }],
    articulos: Array.from({ length: articleCount }, (_, n) => ({ ...base, id: id(`article-${n}`), variedad_validacion_id: id(`variety-${n % 80}`), especie: 'Cereza', variedad: `Variedad ${n % 80}`, calibre: `${n % 8}J`, envase: `Envase ${n % 16}` })),
    origenes: Array.from({ length: originCount }, (_, n) => ({ ...base, id: id(`origin-${n}`), cliente_validacion_id: id(`client-${n % 5}`), csg_validacion_id: id(`csg-${n}`), variedad_ids: null, cliente: `Cliente ${n % 5}`, marca: 'Marca', csg: String(10000 + n), predio: 'Huerto' })),
    combinaciones: Array.from({ length: combinationCount }, (_, n) => ({ id: id(`combination-${n}`), articulo_validacion_id: id(`article-${n % articleCount}`), origen_validacion_id: id(`origin-${n % originCount}`), codigo_externo: null })),
    tipos_bulto: ['pallet', 'saldo'], resultados: ['aprobado', 'observado', 'rechazado'], motivos: ['otro'], generado_at: '2026-10-28T12:00:00Z',
  };
}

// Mismo contrato de transporte que el backend: grupos estables, columnas y diccionario local.
function transport(source) {
  const bodies = new Map(); const fragments = [];
  for (const [collection, fields] of Object.entries(columns)) {
    const groups = new Map();
    for (const row of source[collection]) {
      const group = ['categorias', 'origenes'].includes(collection) ? 0 : parseInt(row.id.replaceAll('-', '').slice(0, 2), 16) % 32;
      if (!groups.has(group)) groups.set(group, []);
      groups.get(group).push(row);
    }
    for (const [group, rows] of [...groups].sort(([a], [b]) => a - b)) {
      const strings = []; const indexes = new Map();
      const encode = (v) => {
        if (v === null || v === undefined) return null;
        if (Array.isArray(v)) return [...v].sort().map(encode);
        if (!indexes.has(String(v))) { indexes.set(String(v), strings.length); strings.push(String(v)); }
        return indexes.get(String(v));
      };
      const values = rows.sort((a, b) => a.id.localeCompare(b.id)).map((row) => fields.map((field) => encode(row[field])));
      const raw = JSON.stringify({ formato: 'fragmentos-v1', coleccion: collection, columnas: fields, textos: strings, filas: values });
      const digest = hash(raw); bodies.set(digest, raw);
      fragments.push({ coleccion: collection, grupo: group, hash: digest, bytes: Buffer.byteLength(raw), filas: rows.length });
    }
  }
  const { categorias, articulos, origenes, combinaciones, ...meta } = source;
  return { bodies, manifest: { ...meta, formato: 'fragmentos-v1', cantidades: Object.fromEntries(Object.keys(columns).map((key) => [key, source[key].length])), fragmentos: fragments } };
}

function harness(source = catalog(), shared = {}) {
  const files = shared.files ?? new Map(); const storage = shared.storage ?? new Map(); const modules = new Map();
  const directories = shared.directories ?? new Set();
  const calls = []; const progress = []; const timers = new Map();
  let server = transport(source); const history = new Map(server.bodies);
  let failure = null; let corrupt = null; let fullDisk = false; let never = false; let active = 0; let maximum = 0;
  const disk = {
    documentDirectory: 'file:///private/',
    getInfoAsync: async (path) => ({ exists: directories.has(path) || files.has(path) }),
    makeDirectoryAsync: async (path) => { directories.add(path); },
    readDirectoryAsync: async (path) => [...files.keys()].filter((key) => key.startsWith(path) && !key.slice(path.length).includes('/')).map((key) => key.slice(path.length)),
    readAsStringAsync: async (path) => { if (!files.has(path)) throw new Error('Archivo ausente'); return files.get(path); },
    writeAsStringAsync: async (path, value) => { files.set(path, fullDisk ? '{incompleto' : value); if (fullDisk) throw new Error('Almacenamiento lleno'); },
    moveAsync: async ({ from, to }) => { assert.ok(files.has(from)); files.set(to, files.get(from)); files.delete(from); },
    deleteAsync: async (path) => { files.delete(path); },
  };
  const response = (data, status = 200) => new Response(typeof data === 'string' ? data : JSON.stringify(data), { status });
  async function fetch(url, options) {
    assert.equal(options.headers.get('Authorization'), 'Bearer token'); calls.push(url);
    const parsed = new URL(url);
    if (!parsed.pathname.includes('/fragmentos/')) {
      if (options.headers.get('If-None-Match') === `"validacion-partes-v1-season-${server.manifest.temporada.version_catalogo}"`) return new Response(null, { status: 304 });
      return response(server.manifest);
    }
    const digest = parsed.pathname.split('/').at(-1);
    maximum = Math.max(maximum, ++active);
    try {
      await Promise.resolve();
      if (never) await new Promise((_resolve, reject) => options.signal.addEventListener('abort', () => reject(new Error('Aborted'))));
      if (digest === failure) return response({ message: 'Descarga vencida' }, 409);
      if (digest === corrupt) return response('{incompleto');
      const target = server.bodies.get(digest);
      const baseHash = parsed.searchParams.get('base'); const base = history.get(baseHash);
      if (base) {
        const decode = (raw) => {
          const data = JSON.parse(raw);
          const value = (v) => v === null ? null : data.textos[v];
          return new Map(data.filas.map((row) => {
            const result = Object.fromEntries(data.columnas.map((key, n) => [key, Array.isArray(row[n]) ? row[n].map(value) : value(row[n])]));
            return [result.id, result];
          }));
        };
        const previous = decode(base); const current = decode(target);
        const delta = JSON.stringify({ formato: 'delta-fragmentos-v1', base: baseHash, destino: digest,
          coleccion: JSON.parse(target).coleccion, cambios: [...current].filter(([key, row]) => JSON.stringify(previous.get(key)) !== JSON.stringify(row)).map(([, row]) => row),
          eliminados: [...previous.keys()].filter((key) => !current.has(key)) });
        if (Buffer.byteLength(delta) < Buffer.byteLength(target)) return response(delta);
      }
      return response(target);
    } finally { active--; }
  }
  function load(path) {
    if (modules.has(path)) return modules.get(path).exports;
    const module = { exports: {} }; modules.set(path, module);
    const code = ts.transpileModule(readFileSync(path, 'utf8'), { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText;
    vm.runInNewContext(code, {
      module, exports: module.exports, console, Error, Headers, URLSearchParams, AbortController, fetch,
      setTimeout: (fn) => { const key = randomUUID(); timers.set(key, fn); return key; }, clearTimeout: (key) => timers.delete(key),
      require: (name) => {
        if (name === 'expo-file-system/legacy') return disk;
        if (name === 'expo-crypto') return { randomUUID, CryptoDigestAlgorithm: { SHA256: 'SHA256' }, digestStringAsync: async (_algorithm, raw) => hash(raw) };
        if (name === '@react-native-async-storage/async-storage') return {
          getItem: async (key) => storage.get(key) ?? null, getAllKeys: async () => [...storage.keys()],
          multiRemove: async (keys) => keys.forEach((key) => storage.delete(key)),
        };
        if (name.startsWith('.')) return load(resolve(dirname(path), name) + '.ts');
        return require(name);
      },
    }, { filename: path });
    return module.exports;
  }
  const api = load(resolve(root, 'services/validationApi.ts'));
  return {
    files, storage, calls, progress, server: () => server, maxActive: () => maximum,
    api, codec: load(resolve(root, 'domain/validationCatalogTransfer.ts')), offline: load(resolve(root, 'services/validationOfflineStore.ts')),
    download: (known = null, base = 'http://planta', device = 'pda') => api.getValidationCatalog(base, 'token', known, { deviceId: device, onProgress: (p) => progress.push(clone(p)) }),
    update: (next) => { server = transport(next); for (const [key, raw] of server.bodies) history.set(key, raw); }, fail: (digest) => { failure = digest; }, corrupt: (digest) => { corrupt = digest; }, diskFull: (v) => { fullDisk = v; },
    never: () => { never = true; }, abort: () => { for (const fn of timers.values()) fn(); },
    restart: (next = source) => harness(next, { files, directories, storage }),
  };
}

test('una edición descarga solo partes nuevas, conserva IDs y retira artículos y combinaciones desactivados', async () => {
  const initial = catalog(218); const h = harness(initial);
  const first = await h.download();
  assert.equal(first.articulos.length, initial.articulos.length);
  assert.deepEqual([...first.articulos.map((r) => r.id)].sort(), initial.articulos.map((r) => r.id).sort());
  assert.ok(h.maxActive() <= 2);
  const updated = clone(initial); updated.temporada.version_catalogo = 227; updated.articulos[0].envase = 'Envase corregido';
  h.update(updated); h.calls.length = 0;
  const second = await h.download(first);
  assert.equal(h.calls.filter((url) => url.includes('/fragmentos/')).length, 1);
  assert.equal(second.articulos.find((r) => r.id === initial.articulos[0].id).envase, 'Envase corregido');
  assert.ok(h.progress.at(-1).reusedBytes > h.progress.at(-1).downloadedBytes);
  const retired = clone(updated); retired.temporada.version_catalogo++;
  retired.articulos = retired.articulos.slice(1); retired.combinaciones = retired.combinaciones.filter((r) => r.articulo_validacion_id !== updated.articulos[0].id);
  h.update(retired);
  const third = await h.download(second);
  assert.equal(third.articulos.some((r) => r.id === updated.articulos[0].id), false);
  assert.equal(third.combinaciones.some((r) => r.articulo_validacion_id === updated.articulos[0].id), false);
});

test('una interrupción y reinicio reanudan las partes recibidas sin tocar catálogo vigente, bandeja ni jornada', async () => {
  const old = catalog(218); const current = catalog(227); const h = harness(current);
  await h.offline.saveValidationCatalog('1', 'pda', old, 'http://planta');
  h.storage.set('estiba_validation_outbox:1:pda', '[{"id":"captura-pendiente"}]');
  h.storage.set('estiba_validation_work_context:1:pda', '{"linea_proceso":1,"turno":"A"}');
  const preserved = [...h.storage];
  h.fail(h.server().manifest.fragmentos.at(-1).hash);
  await assert.rejects(h.download(old), /HTTP 409/);
  assert.equal((await h.offline.loadCachedValidationCatalog('1', 'pda', 'http://planta')).temporada.version_catalogo, 218);
  const restarted = h.restart(current);
  const recovered = await restarted.download(old);
  assert.equal(restarted.calls.filter((url) => url.includes('/fragmentos/')).length, 1);
  await restarted.offline.saveValidationCatalog('2', 'pda', recovered, 'http://planta');
  assert.equal((await restarted.offline.loadCachedValidationCatalog('1', 'pda', 'http://planta')).temporada.version_catalogo, 227);
  assert.deepEqual([...h.storage], preserved);
});

test('partes dañadas, faltantes o relaciones incoherentes no se instalan y una caché dañada se descarga de nuevo', async () => {
  const h = harness(); const original = catalog();
  const digest = h.server().manifest.fragmentos[0].hash;
  h.corrupt(digest); await assert.rejects(h.download(), /llegó dañada/);
  assert.equal([...h.files.keys()].some((path) => path.endsWith(digest + '.json')), false);
  h.corrupt(null); const first = await h.download();
  const path = [...h.files.keys()].find((p) => p.endsWith(digest + '.json')); h.files.set(path, '{dañado');
  h.calls.length = 0; await h.download();
  assert.equal(h.calls.filter((url) => url.includes('/fragmentos/')).length, 1);
  const invalid = clone(original); invalid.temporada.version_catalogo++; invalid.combinaciones[0].origen_validacion_id = 'no-existe'; h.update(invalid);
  await assert.rejects(h.download(first), /sin artículo u origen/);
});

test('fallo de disco y tiempo agotado conservan la copia anterior y permiten reintentar', async () => {
  const h = harness(); const old = catalog(218);
  await h.offline.saveValidationCatalog('1', 'pda', old, 'http://planta');
  h.diskFull(true); await assert.rejects(h.download(old), /Almacenamiento lleno/);
  h.diskFull(false);
  assert.equal((await h.offline.loadCachedValidationCatalog('1', 'pda', 'http://planta')).temporada.version_catalogo, 218);
  assert.ok([...h.files.keys()].every((path) => !path.endsWith('.tmp')));
  h.never(); const timed = h.download(old);
  for (let i = 0; i < 40; i++) await Promise.resolve();
  h.abort(); await assert.rejects(timed, /45 segundos/);
  const recovered = await h.restart().download(old);
  assert.equal(recovered.temporada.version_catalogo, 227);
});

test('304 no descarga contenido y las partes de otro servidor o dispositivo no se reutilizan', async () => {
  const h = harness(); const first = await h.download();
  h.calls.length = 0;
  assert.equal(await h.download(first), null);
  assert.equal(h.calls.length, 1);
  await h.download(null, 'http://otra-planta');
  assert.ok(h.calls.filter((url) => url.startsWith('http://otra-planta') && url.includes('/fragmentos/')).length > 1);
  h.calls.length = 0;
  await h.download(null, 'http://planta', 'otra-pda');
  assert.ok(h.calls.filter((url) => url.includes('/fragmentos/')).length > 1);
});

test('29676 artículos y 37184 combinaciones: descarga compacta y una edición evita repetir el catálogo entero', async () => {
  const source = catalog(227, 29676, 37184, 69); const h = harness(source);
  const first = await h.download();
  const raw = [...h.server().bodies.values()];
  const fullBytes = Buffer.byteLength(JSON.stringify(source));
  const fragmentBytes = raw.reduce((sum, value) => sum + Buffer.byteLength(value), 0);
  assert.ok(fragmentBytes < fullBytes * 0.65);
  assert.equal(first.articulos.length, 29676); assert.equal(first.combinaciones.length, 37184);
  const edited = clone(source); edited.temporada.version_catalogo++; edited.articulos[0].envase = 'Envase nuevo'; h.update(edited);
  const second = await h.download(first);
  const delta = h.progress.at(-1);
  assert.equal(second.temporada.version_catalogo, 228);
  assert.equal(delta.completed, delta.total);
  assert.ok(delta.downloadedBytes < fragmentBytes * 0.05);
  console.log(JSON.stringify({ simulacion: true, fullBytes, fragmentBytes, fragmentGzipBytes: raw.reduce((sum, value) => sum + gzipSync(value).length, 0), legacyGzipBytes: gzipSync(JSON.stringify(source)).length, editedDownloadBytes: delta.downloadedBytes, reusedBytes: delta.reusedBytes, fragments: delta.total }));
  // Renombrar un envase en Maestros puede retirar miles de IDs y crear sus reemplazos.
  const renamed = clone(edited); renamed.temporada.version_catalogo++;
  const replacements = new Map();
  for (const row of renamed.articulos.filter((row) => row.envase === 'Envase 1')) {
    const replacement = id(row.id + '-renamed'); replacements.set(row.id, replacement); row.id = replacement; row.envase = 'Envase reemplazado';
  }
  for (const row of renamed.combinaciones) {
    if (replacements.has(row.articulo_validacion_id)) { row.articulo_validacion_id = replacements.get(row.articulo_validacion_id); row.id = id(row.id + '-renamed'); }
  }
  h.update(renamed);
  const third = await h.download(second);
  const packagingDelta = h.progress.at(-1);
  assert.equal(third.articulos.length, 29676); assert.equal(third.combinaciones.length, 37184);
  assert.ok(third.articulos.every((row) => row.envase !== 'Envase 1'));
  assert.ok(packagingDelta.downloadedBytes < fragmentBytes * 0.3);
  console.log(JSON.stringify({ simulacion: true, packagingRenameRows: replacements.size, packagingDownloadBytes: packagingDelta.downloadedBytes, reusedBytes: packagingDelta.reusedBytes }));
});

test('el índice rechaza duplicados y cantidades incorrectas y una delta de otra base no se aplica', () => {
  const h = harness(); const manifest = clone(h.server().manifest);
  manifest.fragmentos.push(clone(manifest.fragmentos[0]));
  assert.throws(() => h.codec.assertCatalogManifest(manifest), /repetidas/);
  manifest.fragmentos.pop(); manifest.cantidades.articulos++;
  assert.throws(() => h.codec.assertCatalogManifest(manifest), /cantidades/);
  const target = h.server().manifest.fragmentos[0];
  assert.throws(() => h.codec.applyCatalogFragmentDelta(JSON.stringify({ formato: 'delta-fragmentos-v1', base: 'otro', destino: target.hash, coleccion: target.coleccion, cambios: [], eliminados: [] }), 'base-vigente', target, []), /no corresponde/);
});

test('el codificador de PDA coincide byte por byte con PHP, incluidos Unicode, ceros iniciales y variedades ordenadas', () => {
  const fixture = JSON.parse(readFileSync(new URL('./fixtures/catalogTransfer-v1.json', import.meta.url), 'utf8'));
  const h = harness();
  const encoded = h.codec.encodeCatalogFragment(fixture.coleccion, fixture.registros);
  assert.equal(encoded, fixture.cuerpo);
  assert.equal(hash(encoded), fixture.sha256);
  const rows = h.codec.decodeCatalogFragment(encoded, { coleccion: fixture.coleccion, grupo: 0, hash: fixture.sha256, bytes: Buffer.byteLength(encoded), filas: fixture.registros.length }, 'season');
  assert.equal(rows[0].csg, '00123');
  assert.deepEqual([...rows[0].variedad_ids], ['variety-a', 'variety-b']);
});
