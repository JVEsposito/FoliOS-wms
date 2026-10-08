import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { dirname, resolve } from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';
import React from 'react';
import { act, create } from 'react-test-renderer';
import ts from 'typescript';

globalThis.IS_REACT_ACT_ENVIRONMENT = true;
const require = createRequire(import.meta.url);
const root = resolve(dirname(fileURLToPath(import.meta.url)), '../src');
function loadComponent() {
  const file = resolve(root, 'components/operator/TurnVerification.tsx');
  const compiled = ts.transpileModule(readFileSync(file, 'utf8'), {
    compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 }, fileName: file,
  }).outputText;
  const module = { exports: {} };
  vm.runInNewContext(compiled, { module, exports: module.exports, console, Date, Number, Error,
    require: (id) => id === 'react-native' ? { Pressable: 'Pressable', Text: 'Text', TextInput: 'TextInput', View: 'View', StyleSheet: { create: (s) => s } }
      : id.endsWith('/ScanInput') ? { ScanInput: 'ScanInput' }
        : id.endsWith('/operatorTheme') ? { operatorTheme: { color: {}, radius: {}, space: {}, type: {}, touch: { minimum: 48 } } }
          : require(id),
  });
  return module.exports.TurnVerification;
}
const round = { id: 'r1', estado: 'pendiente', contenido: 'materiales', verificar_cantidad: true, completadas: 0, objetivo: 5,
  vence_at: '2026-10-08T21:00:00Z', items: [{ id: 'i1', version: 1, resultado: null, posicion: { camara: 'MAT1', banda: 1, posicion: 2, nivel: 1 } }] };
const text = (node) => node.findAllByType('Text').map((n) => n.children.join('')).join(' ');
const button = (node, label) => node.findAllByType('Pressable').find((p) => p.findAllByType('Text').some((t) => t.children.join('') === label));

test('PDA cuenta tres folios con su unidad y confirma la posición completa una vez', async () => {
  const Component = loadComponent(); const calls = []; let renderer;
  await act(async () => { renderer = create(React.createElement(Component, { round, busy: false, onVerify: () => assert.fail('Flujo de frío'),
    lookupUnit: async () => 'kg', onVerifyMaterials: (item, readings) => calls.push({ item, readings }) })); });
  assert.doesNotMatch(text(renderer.root), /MAT-A|cantidad esperada/i);
  for (const number of ['MAT-A', 'MAT-B', 'MAT-C']) {
    await act(async () => { await renderer.root.findByType('ScanInput').props.onSubmit(number); });
    await act(async () => { renderer.root.findAllByType('TextInput').at(-1).props.onChangeText('10,5'); });
  }
  assert.match(text(renderer.root), /MAT-A · kg/);
  await act(async () => { button(renderer.root, 'Confirmar posición').props.onPress(); });
  assert.equal(calls.length, 1);
  assert.equal(JSON.stringify(calls[0].readings), JSON.stringify(['MAT-A', 'MAT-B', 'MAT-C'].map((numero_folio) => ({ numero_folio, cantidad_contada: 10.5 }))));
  await act(async () => renderer.unmount());
});

test('PDA exige cantidad, evita duplicados y marcar vacía necesita confirmación', async () => {
  const Component = loadComponent(); const calls = []; let renderer;
  await act(async () => { renderer = create(React.createElement(Component, { round, busy: false, onVerify: () => {}, lookupUnit: async () => 'unidad', onVerifyMaterials: (_item, readings) => calls.push(readings) })); });
  await act(async () => { await renderer.root.findByType('ScanInput').props.onSubmit('MAT-A'); });
  await act(async () => { await renderer.root.findByType('ScanInput').props.onSubmit('mat-a'); });
  assert.equal(renderer.root.findAllByType('TextInput').length, 1);
  assert.match(text(renderer.root), /ya está escaneado/);
  await act(async () => button(renderer.root, 'Confirmar posición').props.onPress());
  assert.equal(calls.length, 0);
  await act(async () => button(renderer.root, 'Posición vacía').props.onPress());
  assert.equal(calls.length, 0);
  await act(async () => button(renderer.root, 'Confirmar posición').props.onPress());
  assert.equal(JSON.stringify(calls), '[[]]');
  await act(async () => renderer.unmount());
});

test('frío mantiene la confirmación de un solo folio', async () => {
  const Component = loadComponent(); const calls = []; let renderer;
  await act(async () => { renderer = create(React.createElement(Component, { round: { ...round, contenido: 'productos' }, busy: false, onVerify: (_item, number) => calls.push(number) })); });
  await act(async () => renderer.root.findByType('ScanInput').props.onSubmit('PT-1'));
  assert.deepEqual(calls, ['PT-1']);
  assert.equal(renderer.root.findAllByType('TextInput').length, 0);
  await act(async () => renderer.unmount());
});
