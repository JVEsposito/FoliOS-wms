import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { dirname, join } from 'node:path';
import test from 'node:test';
import vm from 'node:vm';
import ts from 'typescript';

const require = createRequire(import.meta.url);
const expoRoot = dirname(require.resolve('expo/package.json'));
function compile(path, mocks = {}, globals = {}) {
  const module = { exports: {} };
  vm.runInNewContext(ts.transpileModule(readFileSync(path, 'utf8'), {
    compilerOptions: { module: ts.ModuleKind.CommonJS },
  }).outputText, { module, exports: module.exports, Error, Blob, Uint8Array, TextEncoder,
    require: (id) => {
      if (id in mocks) return mocks[id];
      throw new Error(`Unexpected import: ${id}`);
    }, ...globals });
  return module.exports;
}

// Use the installed SDK's real multipart encoder and FormData patch. A fake fetch
// that merely accepts {uri, name, type} would hide the Android regression.
const { convertFormDataAsync } = compile(join(expoRoot, 'src/winter/fetch/convertFormData.ts'), {
  '../../utils/blobUtils': { blobToArrayBufferAsync: (blob) => blob.arrayBuffer() },
});
const { installFormDataPatch } = compile(join(expoRoot, 'src/winter/FormData.ts'));
class NativeFormData { _parts = []; }
installFormDataPatch(NativeFormData);
const { ApiError } = compile(new URL('../src/services/apiError.ts', import.meta.url));
const png = Uint8Array.from(Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGP4z8DwHwAFAAH/iZk9HQAAAABJRU5ErkJggg==', 'base64'));

function harness(respond, files = new Map()) {
  const calls = [];
  class LocalFile {
    constructor(uri) {
      this.uri = uri;
      this.name = uri.split('/').at(-1);
      this.type = uri.endsWith('.png') ? 'image/png' : 'image/jpeg';
      this.exists = !files.has(uri) || files.get(uri).exists !== false;
      this.size = files.get(uri)?.size ?? png.byteLength;
    }
    async bytes() { return png; }
  }
  const fetch = async (url, init = {}) => {
    let multipart = null;
    if (init.body) {
      const encoded = await convertFormDataAsync(init.body, 'test-mp-boundary');
      multipart = await new Response(encoded.body, {
        headers: { 'Content-Type': `multipart/form-data; boundary=${encoded.boundary}` },
      }).formData();
    }
    calls.push({ url, init, multipart });
    return respond(calls.at(-1));
  };
  const api = compile(new URL('../src/services/receptionDefectsApi.ts', import.meta.url), {
    './apiError': { ApiError },
    'expo-file-system': { File: LocalFile },
    'expo/fetch': { fetch },
  }, { FormData: NativeFormData, fetch });
  return { api, calls };
}

const photo = (name = 'defecto.jpg') => ({ uri: `file:///cache/${name}`, name, type: name.endsWith('.png') ? 'image/png' : 'image/jpeg' });
const draft = () => ({ operacionId: '13b7fb01-3a32-4a3a-8b5b-8b76396ca55c', categoria: 'envase_danado', tipoEnvase: 'bins', cantidadAfectada: '2', descripcion: ' Bins rotos ', fotografias: [photo()], fotografiaGuia: photo('guia.png') });
const saved = { id: 'saved', operacion_id: draft().operacionId, evidencias: [] };

test('las fotos reales del defecto y la guía se serializan con Expo 57 y conservan el UUID', async () => {
  const { api, calls } = harness(() => Response.json({ data: saved }, { status: 201 }));
  const input = draft();
  input.fotografias.push(photo('defecto-2.jpg'), photo('defecto-3.jpg'));
  assert.equal((await api.createReceptionDefect('http://planta:8000', 'secret', 'reception', input)).id, 'saved');
  assert.equal(calls.length, 1);
  assert.equal(calls[0].multipart.get('operacion_id'), input.operacionId);
  assert.equal(calls[0].multipart.get('descripcion'), 'Bins rotos');
  assert.equal(calls[0].init.headers.Authorization, 'Bearer secret');
  assert.equal(calls[0].init.headers['Content-Type'], undefined);
  for (const key of ['fotografias[0]', 'fotografias[1]', 'fotografias[2]', 'fotografia_guia']) {
    assert.deepEqual(new Uint8Array(await calls[0].multipart.get(key).arrayBuffer()), png);
  }
  assert.equal(input.operacionId, draft().operacionId);
});

test('un archivo local ausente o mayor a cinco MiB se rechaza antes de enviar', async () => {
  for (const state of [{ exists: false }, { size: 5 * 1024 * 1024 + 1 }]) {
    const { api, calls } = harness(() => Response.json({ data: saved }), new Map([[photo().uri, state]]));
    await assert.rejects(api.createReceptionDefect('http://planta', 'token', 'reception', draft()), (error) => error instanceof ApiError && error.status === 422);
    assert.equal(calls.length, 0);
  }
});

test('un límite HTTP 413 incluso sin JSON se explica sin simular un corte de red', async () => {
  const { api } = harness(() => new Response('<h1>Request too large</h1>', { status: 413 }));
  await assert.rejects(api.createReceptionDefect('http://planta', 'token', 'reception', draft()),
    (error) => error.status === 413 && /límite.*servidor/i.test(error.message));
});

test('la consulta de una operación pendiente no vuelve a transmitir las fotografías', async () => {
  const { api, calls } = harness(() => Response.json({ data: [saved] }));
  assert.equal((await api.findReceptionDefectOperation('http://planta', 'token', 'reception', draft().operacionId)).id, 'saved');
  assert.equal(calls.length, 1);
  assert.equal(calls[0].multipart, null);
  assert.match(calls[0].url, /operacion_id=13b7fb01/);
});

test('un rechazo 422 conserva el detalle del servidor y una respuesta perdida mantiene estado incierto', async () => {
  const rejected = harness(() => Response.json({ errors: { fotografia_guia: ['No se recibió la guía completa.'] } }, { status: 422 }));
  await assert.rejects(rejected.api.createReceptionDefect('http://planta', 'token', 'reception', draft()),
    (error) => error.status === 422 && error.message === 'No se recibió la guía completa.');
  const lost = harness(() => { throw new Error('Connection reset'); });
  await assert.rejects(lost.api.createReceptionDefect('http://planta', 'token', 'reception', draft()),
    (error) => error.status === 0);
});

test('una respuesta exitosa sin registro no acredita el guardado ni una consulta vacía', async () => {
  const { api } = harness(() => new Response('<html>Proxy</html>', { status: 200 }));
  await assert.rejects(api.createReceptionDefect('http://planta', 'token', 'reception', draft()), (error) => error.status === 0);
  await assert.rejects(api.findReceptionDefectOperation('http://planta', 'token', 'reception', draft().operacionId), (error) => error.status === 0);
});

test('la consulta solo recupera la operación exacta y una ausencia permanece como no confirmada', async () => {
  for (const data of [[], [{ ...saved, operacion_id: 'other-operation' }]]) {
    const { api, calls } = harness(() => Response.json({ data }));
    assert.equal(await api.findReceptionDefectOperation('http://planta', 'token', 'reception', draft().operacionId), null);
    assert.equal(calls.length, 1);
    assert.equal(calls[0].multipart, null);
  }
});
