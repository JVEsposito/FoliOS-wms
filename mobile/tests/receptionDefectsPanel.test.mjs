import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';
import vm from 'node:vm';
import React from 'react';
import { act, create } from 'react-test-renderer';
import ts from 'typescript';

const require = createRequire(import.meta.url);
globalThis.IS_REACT_ACT_ENVIRONMENT = true;
class ApiError extends Error { constructor(message, status) { super(message); this.status = status; } }
const photo = { uri: 'file:///defecto.jpg', mimeType: 'image/jpeg', fileSize: 1000 };
const saved = { id: 'saved', categoria: 'envase_danado', descripcion: 'Bins rotos', registrado_at: '2026-10-28T17:00:00Z', evidencias: [{ tipo: 'defecto' }] };

function harness(api) {
  let uuid = 0;
  const module = { exports: {} };
  const compiled = ts.transpileModule(readFileSync(new URL('../src/components/ReceptionDefectsPanel.tsx', import.meta.url), 'utf8'), {
    compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.CommonJS },
  }).outputText;
  vm.runInNewContext(compiled, { module, exports: module.exports, Error, Date, Promise,
    require: (id) => {
      if (id === 'expo-crypto') return { randomUUID: () => `operation-${++uuid}` };
      if (id === 'expo-image-picker') return { launchImageLibraryAsync: async () => ({ canceled: false, assets: [photo] }) };
      if (id === '../services/containerCatalog') return { getContainerCatalog: async () => [], containerLabel: () => 'Bins' };
      if (id === '../services/receptionDefectsApi') return { listReceptionDefects: async () => [], ...api };
      if (id === '../services/apiError') return { ApiError };
      if (id === '../theme/colors') return { colors: {} };
      if (id === 'react-native') return { ActivityIndicator: 'ActivityIndicator', Image: 'Image', Pressable: 'Pressable', Text: 'Text', TextInput: 'TextInput', View: 'View', StyleSheet: { create: (value) => value } };
      return require(id);
    },
  });
  return module.exports.ReceptionDefectsPanel;
}
function button(renderer, label) {
  return renderer.root.findAllByType('Pressable').find((node) => node.findAllByType('Text').some((text) => [].concat(text.props.children).join('') === label));
}
async function prepare(api) {
  let renderer;
  const Component = harness(api);
  await act(async () => { renderer = create(React.createElement(Component, { baseUrl: 'http://planta:8000', token: 'token', receptionId: 'reception', guideNumber: '123' })); });
  await act(async () => button(renderer, '+ Registrar defecto').props.onPress());
  await act(async () => renderer.root.findAllByType('TextInput').find((input) => input.props.multiline).props.onChangeText('Bins rotos'));
  await act(async () => button(renderer, 'Elegir imagen').props.onPress());
  return renderer;
}

test('una respuesta perdida se recupera por consulta sin reenviar fotos', async () => {
  let posts = 0; let lookups = 0; let operation;
  const renderer = await prepare({
    createReceptionDefect: async (_url, _token, _reception, draft) => { posts++; operation = draft.operacionId; throw new ApiError('Sin respuesta', 0); },
    findReceptionDefectOperation: async (_url, _token, _reception, id) => { lookups++; assert.equal(id, operation); return saved; },
  });
  await act(async () => button(renderer, 'Guardar defecto').props.onPress());
  assert.equal(button(renderer, 'Cancelar').props.disabled, true);
  await act(async () => button(renderer, 'Revisar envío').props.onPress());
  assert.equal(posts, 1); assert.equal(lookups, 1);
  assert.equal(button(renderer, 'Reintentar envío'), undefined);
  assert.ok(button(renderer, '+ Registrar defecto'));
  await act(async () => renderer.unmount());
});

test('un fallo 500 conserva UUID y fotos y el doble toque no duplica solicitudes', async () => {
  const attempts = []; let resolvePost;
  const renderer = await prepare({
    createReceptionDefect: async (_url, _token, _reception, draft) => {
      attempts.push(draft);
      if (attempts.length === 1) return new Promise((_resolve, reject) => { resolvePost = () => reject(new ApiError('Sin respuesta', 500)); });
      return saved;
    },
    findReceptionDefectOperation: async () => null,
  });
  await act(async () => {
    const submit = button(renderer, 'Guardar defecto').props.onPress;
    submit(); submit();
  });
  assert.equal(attempts.length, 1);
  await act(async () => resolvePost());
  assert.equal(button(renderer, 'Cancelar').props.disabled, true);
  await act(async () => button(renderer, 'Reintentar envío').props.onPress());
  assert.equal(attempts.length, 2);
  assert.equal(attempts[0].operacionId, attempts[1].operacionId);
  assert.equal(attempts[0].fotografias[0].uri, attempts[1].fotografias[0].uri);
  await act(async () => renderer.unmount());
});

test('si la consulta sigue sin conexión se conserva el formulario y no se vuelve a enviar', async () => {
  let posts = 0;
  const renderer = await prepare({
    createReceptionDefect: async () => { posts++; throw new ApiError('Sin red', 0); },
    findReceptionDefectOperation: async () => { throw new ApiError('Sin red', 0); },
  });
  await act(async () => button(renderer, 'Guardar defecto').props.onPress());
  await act(async () => button(renderer, 'Reintentar envío').props.onPress());
  assert.equal(posts, 1);
  assert.equal(button(renderer, 'Cancelar').props.disabled, true);
  await act(async () => renderer.unmount());
});

test('un rechazo definitivo por tamaño permite corregir las fotos', async () => {
  const renderer = await prepare({ createReceptionDefect: async () => { throw new ApiError('Límite del servidor', 413); } });
  await act(async () => button(renderer, 'Guardar defecto').props.onPress());
  assert.equal(button(renderer, 'Cancelar').props.disabled, false);
  assert.equal(button(renderer, 'Quitar foto 1').props.disabled, false);
  assert.equal(button(renderer, 'Revisar envío'), undefined);
  await act(async () => renderer.unmount());
});
