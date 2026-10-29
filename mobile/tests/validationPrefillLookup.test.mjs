import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';
import test from 'node:test';
import ts from 'typescript';

const require = createRequire(import.meta.url);
const root = resolve(dirname(fileURLToPath(import.meta.url)), '../src');
const opId = '01234567-1234-1234-1234-0123456789ab';
const proposal = { disponible: true, numero_folio: '000021', cantidad_cajas: 40,
  composicion: [], pendientes: [], origen: {} };
const response = (body, status = 200) => new Response(JSON.stringify(body), { status });

function harness(responses) {
  const modules = new Map(); const calls = [];
  const fetch = async (url, options) => {
    calls.push({ url, method: options.method ?? 'GET', body: options.body });
    assert.equal(options.headers.get('Authorization'), 'Bearer token');
    assert.ok(responses.length, 'No debe consultar sin una respuesta esperada');
    const result = responses.shift();
    return typeof result === 'function' ? result(options) : result;
  };
  function load(path) {
    if (modules.has(path)) return modules.get(path).exports;
    const module = { exports: {} }; modules.set(path, module);
    const code = ts.transpileModule(readFileSync(path, 'utf8'), { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText;
    const mockRequire = (name) => {
      if (name === './validationCatalogTransferStore') return { supportsCatalogTransfer: false };
      if (name.startsWith('.')) return load(resolve(dirname(path), name) + '.ts');
      return require(name);
    };
    vm.runInNewContext(code, { require: mockRequire, module, exports: module.exports, fetch,
      Headers, URLSearchParams, AbortController, setTimeout, clearTimeout }, { filename: path });
    return module.exports;
  }
  const api = load(resolve(root, 'services/validationApi.ts'));
  return { calls, lookup: (options = {}) => api.getValidationPrefill('http://test:8001', 'token', '000021',
    { temporadaId: 'season', wait: async () => {}, ...options }) };
}

test('el escaneo usa la copia vigente sin consultas adicionales', async () => {
  const h = harness([response({ data: proposal })]);
  assert.equal((await h.lookup()).numero_folio, '000021');
  assert.equal(h.calls.length, 1);
  assert.equal(h.calls[0].method, 'POST');
  assert.deepEqual(JSON.parse(h.calls[0].body), { numero_folio: '000021', temporada_id: 'season' });
});

test('una consulta puntual en cola solo sondea su estado, sin repetir el POST', async () => {
  const phases = [];
  const h = harness([
    response({ operacion: { id: opId, estado: 'en_cola', fase: 'Esperando' } }, 202),
    response({ operacion: { id: opId, estado: 'en_curso', fase: 'Consultando packing' } }),
    response({ operacion: { id: opId, estado: 'correcta', respuesta: { codigo_http: 200, contenido: { data: proposal } } } }),
  ]);
  assert.equal((await h.lookup({ onProgress: (phase) => phases.push(phase) })).cantidad_cajas, 40);
  assert.deepEqual(phases, ['Esperando', 'Consultando packing']);
  assert.deepEqual(h.calls.map((c) => c.method), ['POST', 'GET', 'GET']);
  assert.ok(h.calls.slice(1).every((c) => c.url === `http://test:8001/api/integraciones/operaciones/${opId}`));
});

test('no encontrado y fallo de conexión conservan resultados distintos', async () => {
  const missing = harness([response({ data: { disponible: false, motivo: 'Packing no tiene este folio' } })]);
  assert.equal((await missing.lookup()).disponible, false);
  const failed = harness([
    response({ operacion: { id: opId, estado: 'en_cola', fase: 'Esperando' } }, 202),
    response({ operacion: { id: opId, estado: 'fallida', respuesta: { codigo_http: 422, contenido: { message: 'No se pudo consultar packing' } } } }),
  ]);
  await assert.rejects(failed.lookup(), /No se pudo consultar packing/);
});

test('cancelar la espera impide aplicar un resultado tardío y no repite operaciones', async () => {
  let valid = true;
  const h = harness([response({ operacion: { id: opId, estado: 'en_cola', fase: 'Esperando' } }, 202)]);
  await assert.rejects(h.lookup({ valid: () => valid, wait: async () => { valid = false; } }), /Consulta cancelada/);
  assert.equal(h.calls.length, 1);
});

test('un servidor anterior conserva la precarga compatible, sin ocultar errores 500', async () => {
  const legacy = harness([response({}, 405), response({ data: proposal })]);
  assert.equal((await legacy.lookup()).numero_folio, '000021');
  assert.equal(legacy.calls[1].method, 'GET');
  const failed = harness([response({ message: 'Servidor con error' }, 500)]);
  await assert.rejects(failed.lookup(), /Servidor con error/);
  assert.equal(failed.calls.length, 1);
});

test('datos incompletos u otro folio nunca se aplican a la captura', async () => {
  for (const data of [{ disponible: true }, { ...proposal, numero_folio: '21' }]) {
    await assert.rejects(harness([response({ data })]).lookup(), /respuesta incompleta o de otro folio/);
  }
});
