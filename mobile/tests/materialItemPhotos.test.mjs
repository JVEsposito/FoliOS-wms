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
function harness(fetch) {
  const path = new URL('../src/components/PrivateMaterialThumbnail.tsx', import.meta.url);
  const compiled = ts.transpileModule(readFileSync(path, 'utf8'), { compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.CommonJS } }).outputText;
  const module = { exports: {} };
  class Reader { readAsDataURL() { this.result = 'data:image/jpeg;base64,YQ=='; this.onload(); } }
  vm.runInNewContext(compiled, { module, exports: module.exports, fetch, AbortController, FileReader: Reader, Promise, String,
    require: (id) => id === 'react-native' ? { Image: 'Image', View: 'View', StyleSheet: { create: (s) => s } } : require(id),
  });
  return module.exports.PrivateMaterialThumbnail;
}
const photo = { id: 'photo', miniatura_url: '/api/materiales/fotos-items/photo/miniatura' };
const props = { photo, baseUrl: 'https://api.example', token: 'private-token' };
const response = { ok: true, blob: async () => ({}) };

test('miniatura de materiales autentica por cabecera y conserva solo una imagen en memoria', async () => {
  const calls = []; const Component = harness(async (...args) => { calls.push(args); return response; }); let renderer;
  await act(async () => { renderer = create(React.createElement(Component, props)); });
  assert.equal(calls[0][0], 'https://api.example' + photo.miniatura_url);
  assert.equal(calls[0][1].headers.Authorization, 'Bearer private-token');
  assert.equal(calls[0][1].cache, 'no-store');
  assert.doesNotMatch(calls[0][0], /private-token/);
  assert.equal(renderer.root.findByType('Image').props.source.uri, 'data:image/jpeg;base64,YQ==');
  await act(async () => renderer.unmount());
  assert.equal(calls[0][1].signal.aborted, true);
});

test('cambiar la sesión oculta la foto anterior y descarta una respuesta atrasada', async () => {
  const resolvers = []; const Component = harness(() => new Promise(resolve => resolvers.push(resolve))); let renderer;
  await act(async () => { renderer = create(React.createElement(Component, props)); });
  await act(async () => { renderer.update(React.createElement(Component, { ...props, token: 'new-token' })); });
  await act(async () => { resolvers[0](response); });
  assert.equal(renderer.root.findAllByType('Image').length, 0);
  await act(async () => { resolvers[1](response); });
  assert.equal(renderer.root.findAllByType('Image').length, 1);
  await act(async () => { renderer.update(React.createElement(Component, { ...props, token: 'third-token' })); });
  assert.equal(renderer.root.findAllByType('Image').length, 0);
  await act(async () => renderer.unmount());
});

test('una foto ausente o una URL externa no dispara descargas autenticadas', async () => {
  const Component = harness(() => assert.fail('No debe descargar')); let renderer;
  await act(async () => { renderer = create(React.createElement(Component, { ...props, photo: null })); });
  assert.equal(renderer.toJSON(), null);
  await act(async () => { renderer.update(React.createElement(Component, { ...props, photo: { ...photo, miniatura_url: 'https://other.example/photo.jpg' } })); });
  assert.equal(renderer.root.findAllByType('Image').length, 0);
  await act(async () => renderer.unmount());
});

test('miniatura de evidencia de recepción usa autenticación privada sin token en URL', async () => {
  const calls = []; const Component = harness(async (...args) => { calls.push(args); return response; }); let renderer;
  const evidence = { id: 'photo', miniatura_url: '/api/materiales/recepciones/reception/fotos/photo/miniatura' };
  await act(async () => { renderer = create(React.createElement(Component, { ...props, photo: evidence })); });
  assert.equal(calls[0][1].headers.Authorization, 'Bearer private-token');
  assert.equal(calls[0][1].cache, 'no-store');
  assert.doesNotMatch(calls[0][0], /private-token/);
  assert.equal(renderer.root.findByType('Image').props.source.uri, 'data:image/jpeg;base64,YQ==');
  await act(async () => renderer.unmount());
});
