import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import ts from 'typescript';

function compile(path, imports = {}, globals = {}) {
  const module = { exports: {} };
  const code = ts.transpileModule(readFileSync(new URL(path, import.meta.url), 'utf8'), {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
  }).outputText;
  vm.runInNewContext(code, { module, exports: module.exports, Error, Headers,
    require: (id) => {
      if (id in imports) return imports[id];
      throw new Error(`Import inesperado: ${id}`);
    }, ...globals });
  return module.exports;
}
const { ApiError } = compile('../src/services/apiError.ts');
function api(fetch) {
  return compile('../src/services/validationMpApi.ts', {
    './apiError': { ApiError }, './containerCatalog': {}, 'expo-crypto': { randomUUID: () => 'operation-1' },
  }, { fetch });
}

test('MP conserva todos los errores por campo para corregir la validación en la PDA', async () => {
  const response = { message: 'validation.required_with', errors: {
    'segmentos.0.motivos': ['Segmento 1: selecciona al menos un motivo de segregación.'],
    'segmentos.1.csg_validacion_id': ['Segmento 2: selecciona el CSG.'],
    'inspeccion_envases.bins_bien_etiquetados': ['RC-02: responde Sí o No en «Bins bien etiquetados».'],
  } };
  const service = api(async () => new Response(JSON.stringify(response), { status: 422 }));
  await assert.rejects(service.confirmMpValidation('https://planta.test', 'token', 'validation-1', {
    containers: [{ tipo_envase: 'bins', cantidad_validada: 2 }], tagsChecked: true, segregation: true,
    csgId: null, varietyId: null, plantBins: 0, observation: '',
    segments: [
      { motivos: [], csg_validacion_id: 'csg-1', variedad_validacion_id: 'santina', cuartel: '', cantidades: { bins: '1' } },
      { motivos: ['csg'], csg_validacion_id: null, variedad_validacion_id: 'santina', cuartel: '', cantidades: { bins: '1' } },
    ],
    inspection: { items: [], coincide_especie_variedad: true, coincide_cantidad_bins: true, bins_bien_etiquetados: null },
  }), (error) => {
    assert.ok(error instanceof ApiError);
    assert.equal(error.status, 422);
    assert.equal(error.message, Object.values(response.errors).flat().join('\n'));
    assert.deepEqual(JSON.parse(JSON.stringify(error.data)), response);
    return true;
  });
});

test('MP conserva el conflicto del servidor cuando no hay errores por campo', async () => {
  const service = api(async () => new Response(JSON.stringify({ message: 'La recepción ya fue tomada por otro validador MP.' }), { status: 409 }));
  await assert.rejects(service.findMpReception('https://planta.test', 'token', 'REC-2611-0011'), (error) => {
    assert.ok(error instanceof ApiError);
    assert.equal(error.status, 409);
    assert.equal(error.message, 'La recepción ya fue tomada por otro validador MP.');
    return true;
  });
});

test('envía la guía de origen de cada segmento al servidor', async () => {
  let body;
  const service = api(async (_url, options) => {
    body = JSON.parse(options.body);
    return new Response(JSON.stringify({ data: { estado: 'validada' } }));
  });
  await service.confirmMpValidation('https://planta.test', 'token', 'validation-1', {
    containers: [{ tipo_envase: 'bins', cantidad_validada: 2 }], tagsChecked: true, segregation: true,
    csgId: null, varietyId: null, plantBins: 0, observation: '', inspection: { items: [] },
    segments: ['guia-1511', 'guia-1512'].map((id) => ({ guia_recepcion_romana_id: id, motivos: ['guia'],
      csg_validacion_id: 'csg', variedad_validacion_id: 'santina', cuartel: '', cantidades: { bins: '1' }, bins_planta: '0' })),
  });
  assert.deepEqual(body.segmentos.map((s) => s.guia_recepcion_romana_id), ['guia-1511', 'guia-1512']);
});

test('los vacíos viajan aparte: el segmento envía con fruta y vacíos, el conteo total no cambia', async () => {
  let body;
  const service = api(async (_url, options) => {
    body = JSON.parse(options.body);
    return new Response(JSON.stringify({ data: { estado: 'validada' } }));
  });
  await service.confirmMpValidation('https://planta.test', 'token', 'validation-1', {
    containers: [{ tipo_envase: 'bins', cantidad_validada: 36 }, { tipo_envase: 'totes', cantidad_validada: 864, cantidad_vacia: 24 }],
    tagsChecked: true, segregation: true, csgId: null, varietyId: null, plantBins: 0, observation: '', inspection: { items: [] },
    segments: [
      { motivos: ['csg'], csg_validacion_id: 'a', variedad_validacion_id: 'v', cuartel: '', cantidades: { bins: '20', totes: '480' }, vacios: { totes: '4' }, bins_planta: '0' },
      { motivos: ['csg'], csg_validacion_id: 'b', variedad_validacion_id: 'v', cuartel: '', cantidades: { bins: '16', totes: '384' }, vacios: { totes: '20' }, bins_planta: '0' },
    ],
  });
  assert.deepEqual(body.envases, [{ tipo_envase: 'bins', cantidad_validada: 36 }, { tipo_envase: 'totes', cantidad_validada: 864, cantidad_vacia: 24 }]);
  assert.deepEqual(body.segmentos.map((s) => s.envases), [
    [{ tipo_envase: 'bins', cantidad: 20 }, { tipo_envase: 'totes', cantidad: 476, cantidad_vacia: 4 }],
    [{ tipo_envase: 'bins', cantidad: 16 }, { tipo_envase: 'totes', cantidad: 364, cantidad_vacia: 20 }],
  ]);
});
