import { fieldUiMock } from './helpers/fieldUiMock.mjs';
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
      const fieldMock = fieldUiMock(id); if (fieldMock) return fieldMock;
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
  const api = compile(new URL('../src/services/consolidationApi.ts', import.meta.url), {
    './apiError': { ApiError },
    './httpClient': { fetchWithTimeout: fetch },
    'expo-sharing': {},
    'expo-file-system': { File: LocalFile },
    'expo/fetch': { fetch },
  }, { FormData: NativeFormData, fetch, AbortController, setTimeout, clearTimeout });
  return { api: api.createConsolidationApi('http://planta:8000', 'secret'), calls };
}

const photo = { uri: 'file:///cache/camion.png', name: 'camion.png', type: 'image/png' };
const operation = '13b7fb01-3a32-4a3a-8b5b-8b76396ca55c';
const saved = { id: 'photo-1', tipo: 'camion_patentes', orden: 1 };

test('consolidación envía los bytes con el encoder real de Expo 57 y conserva el UUID al reintentar', async () => {
  let attempt = 0;
  const { api, calls } = harness(() => { if (++attempt === 1) throw new Error('lost response'); return Response.json({ data: saved }); });
  await assert.rejects(api.uploadPhoto('control', 'camion_patentes', operation, photo), (error) => error.status === 0);
  assert.equal((await api.uploadPhoto('control', 'camion_patentes', operation, photo)).id, saved.id);
  for (const call of calls) {
    assert.equal(call.multipart.get('operacion_id'), operation);
    assert.equal(call.multipart.get('tipo'), 'camion_patentes');
    assert.equal(call.init.headers.Authorization, 'Bearer secret');
    assert.equal(call.init.headers['Content-Type'], undefined);
    assert.deepEqual(new Uint8Array(await call.multipart.get('archivo').arrayBuffer()), png);
  }
});

test('la foto ausente, vacía o mayor a ocho MiB se rechaza antes del envío', async () => {
  for (const state of [{ exists: false }, { size: 0 }, { size: 8 * 1024 * 1024 + 1 }]) {
    const { api, calls } = harness(() => Response.json({ data: saved }), new Map([[photo.uri, state]]));
    await assert.rejects(api.uploadPhoto('control', 'camion_patentes', operation, photo), (error) => error.status === 422);
    assert.equal(calls.length, 0);
  }
});

test('consolidación distingue el límite HTTP 413 incluso si devuelve HTML', async () => {
  const { api } = harness(() => new Response('<h1>Too large</h1>', { status: 413 }));
  await assert.rejects(api.uploadPhoto('control', 'camion_patentes', operation, photo),
    (error) => error.status === 413 && /límite.*servidor/.test(error.message));
});

test('consolidación conserva los errores de validación y rechaza una confirmación incompleta', async () => {
  const rejected = harness(() => Response.json({ errors: { archivo: ['El archivo debe ser una imagen.'] } }, { status: 422 }));
  await assert.rejects(rejected.api.uploadPhoto('control', 'camion_patentes', operation, photo),
    (error) => error.status === 422 && /debe ser una imagen/.test(error.message));
  const uncertain = harness(() => new Response('<h1>Proxy</h1>'));
  await assert.rejects(uncertain.api.uploadPhoto('control', 'camion_patentes', operation, photo),
    (error) => error.status === 0 && /sin duplicarla/.test(error.message));
});
