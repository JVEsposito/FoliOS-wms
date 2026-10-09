import assert from 'node:assert/strict';
import { test } from 'node:test';
import { puedeConfirmarPorFotos } from '../src/domain/materialReception.ts';

test('solo una foto de documento subida habilita la confirmación', () => {
  assert.equal(puedeConfirmarPorFotos([]), false);
  assert.equal(puedeConfirmarPorFotos([{ id: 'r', tipo: 'referencial' }]), false);
  assert.equal(puedeConfirmarPorFotos([{ id: '', tipo: 'documento' }]), false);
  assert.equal(puedeConfirmarPorFotos([{ id: 'd', tipo: 'documento' }]), true);
});

import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import vm from 'node:vm';
import React from 'react';
import { act, create } from 'react-test-renderer';
import ts from 'typescript';
const require = createRequire(import.meta.url);
globalThis.IS_REACT_ACT_ENVIRONMENT = true;

function harness(picker) {
  const path = new URL('../src/components/MaterialReceptionPhotosPanel.tsx', import.meta.url);
  const compiled = ts.transpileModule(readFileSync(path, 'utf8'), { compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.CommonJS } }).outputText;
  const module = { exports: {} };
  vm.runInNewContext(compiled, { module, exports: module.exports, Promise, Error,
    require: (id) => {
      if (id === 'expo-crypto') return { randomUUID: () => 'upload-operation' };
      if (id === 'expo-image-picker') return picker;
      if (id === 'react-native') return { ActivityIndicator: 'ActivityIndicator', Image: 'Image', TextInput: 'TextInput', Pressable: 'Pressable', Text: 'Text', View: 'View', StyleSheet: { create: (s) => s } };
      if (id === './PrivateMaterialThumbnail') return { PrivateMaterialThumbnail: () => null };
      if (id === '../theme/colors') return { colors: {} };
      return require(id);
    },
  });
  return module.exports.MaterialReceptionPhotosPanel;
}
function button(renderer, text) {
  return renderer.root.findAllByType('Pressable').find((p) => p.findAllByType('Text').some((t) => t.props.children === text));
}

test('foto con error no habilita confirmación; reintentar conserva UUID y archivo', async () => {
  const calls = []; const changes = []; const options = [];
  const asset = { uri: 'file:///documento.jpg', mimeType: 'image/jpeg', fileSize: 1000 };
  const Component = harness({ requestCameraPermissionsAsync: async () => ({ granted: true }), launchCameraAsync: async (o) => { options.push(o); return { canceled: false, assets: [asset] }; } });
  const api = { listarFotos: async () => [], subirFoto: async (...args) => { calls.push(args); if (calls.length === 1) throw new Error('Sin red'); return { id: 'saved', tipo: 'documento', orden: 1 }; } };
  let renderer;
  await act(async () => { renderer = create(React.createElement(Component, { receptionId: 'reception', state: 'borrador', api, baseUrl: 'https://example', token: 'token', canManage: true, canAdminister: false, onChange: (fotos) => changes.push(fotos) })); });
  await act(async () => { await button(renderer, 'Tomar foto').props.onPress(); });
  assert.equal(puedeConfirmarPorFotos(changes.at(-1)), false);
  assert.equal(options[0].quality, 0.5); assert.equal(options[0].allowsEditing, false);
  assert.equal(button(renderer, 'Reintentar').props.disabled, false);
  await act(async () => { await button(renderer, 'Reintentar').props.onPress(); });
  assert.equal(calls[0][2], calls[1][2]); assert.equal(calls[0][3].uri, calls[1][3].uri);
  assert.equal(puedeConfirmarPorFotos(changes.at(-1)), true);
  assert.equal(button(renderer, 'Reintentar'), undefined);
  await act(async () => renderer.unmount());
});
