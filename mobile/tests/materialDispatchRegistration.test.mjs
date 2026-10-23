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
function components() {
  const file = new URL('../src/components/MaterialDispatchRegistration.tsx', import.meta.url);
  const compiled = ts.transpileModule(readFileSync(file, 'utf8'), { compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 }, fileName: file.pathname }).outputText;
  const module = { exports: {} };
  let id = 0;
  vm.runInNewContext(compiled, { module, exports: module.exports, Date, Intl, Error, console, require: name => {
    if (name === 'react-native') return { Pressable: 'Pressable', Text: 'Text', TextInput: 'TextInput', View: 'View', StyleSheet: { create: s => s } };
    if (name === 'expo-crypto') return { randomUUID: () => `uuid-${++id}` };
    if (name.endsWith('/colors')) return { colors: {} };
    return require(name);
  } });
  return module.exports;
}
const text = node => node.findAllByType('Text').map(n => n.children.join('')).join(' ');
const press = (node, label) => node.findAllByType('Pressable').find(n => n.findAllByType('Text').some(t => t.children.join('').includes(label)));
const field = (node, label) => node.findAllByType('TextInput').find(n => n.props.accessibilityLabel === label);
const delivery = { id: 'delivery', version: 1, provisional: true, estado: 'pendiente', fecha_limite: new Date(Date.now() + 86400000).toISOString(), numero_documento: null, fecha_documento: null, retiros: [{ id: 'r1', numero_folio: 'FABC0000001', cantidad_retirada: '4.000' }] };

test('el operador sin autorización solo ve fecha y respaldo y no puede marcar una provisional', async () => {
  const { MaterialDispatchRegistrationFields: Component } = components(); let renderer;
  await act(async () => { renderer = create(React.createElement(Component, { value: {}, canAuthorize: false, onChange: () => {} })); });
  await act(async () => press(renderer.root, 'Fecha física').props.onPress());
  assert.equal(renderer.root.findAllByType('Pressable').filter(n => n.props.accessibilityRole === 'checkbox').length, 0);
  assert.match(text(renderer.root), /requieren autorización de supervisión/);
  await act(async () => renderer.unmount());
});

test('regularizar desde PDA conserva el UUID al perder la respuesta y evita doble toque', async () => {
  const { MaterialDeliveryRegistrationPanel: Component } = components(); const calls = []; const changes = []; let renderer; let rejectFirst;
  const api = { regularizeMaterialDelivery: async (...args) => { calls.push(JSON.parse(JSON.stringify(args))); if (calls.length === 1) await new Promise((_, reject) => { rejectFirst = reject; }); return { id: 'dispatch', entregas: [{ ...delivery, estado: 'regularizada' }] }; } };
  await act(async () => { renderer = create(React.createElement(Component, { delivery, dispatchId: 'dispatch', api, token: 'token', canAuthorize: true, onChange: d => changes.push(d) })); });
  await act(async () => press(renderer.root, 'Regularizar respaldo').props.onPress());
  await act(async () => { field(renderer.root, 'Documento para regularizar entrega').props.onChangeText('VALE-1'); field(renderer.root, 'Emisión para regularizar entrega').props.onChangeText('2026-10-20'); field(renderer.root, 'Motivo para regularizar entrega').props.onChangeText('Respaldo cotejado con la entrega'); });
  await act(async () => { const submit = press(renderer.root, 'Guardar regularización').props.onPress; submit(); submit(); });
  assert.equal(calls.length, 1);
  await act(async () => rejectFirst(new Error('Respuesta perdida')));
  assert.match(text(renderer.root), /Respuesta perdida/);
  await act(async () => press(renderer.root, 'Guardar regularización').props.onPress());
  assert.equal(calls.length, 2);
  assert.deepEqual(calls[0], calls[1]);
  assert.equal(calls[0][3].operacion_id, 'uuid-1');
  assert.equal(Object.hasOwn(calls[0][3], 'retiros'), false);
  assert.equal(changes.length, 1);
  await act(async () => renderer.unmount());
});

test('una entrega vencida exige reconocer el atraso antes de regularizar', async () => {
  const { MaterialDeliveryRegistrationPanel: Component } = components(); const calls = []; let renderer;
  await act(async () => { renderer = create(React.createElement(Component, { delivery: { ...delivery, estado: 'vencida', fecha_limite: '2026-10-01T02:59:59Z' }, dispatchId: 'dispatch', api: { regularizeMaterialDelivery: async (...args) => { calls.push(args); return {}; } }, token: 'token', canAuthorize: true, onChange: () => {} })); });
  await act(async () => press(renderer.root, 'Regularizar respaldo').props.onPress());
  await act(async () => { field(renderer.root, 'Documento para regularizar entrega').props.onChangeText('VALE-2'); field(renderer.root, 'Emisión para regularizar entrega').props.onChangeText('2026-10-20'); field(renderer.root, 'Motivo para regularizar entrega').props.onChangeText('Regularización documental tardía'); });
  await act(async () => press(renderer.root, 'Guardar regularización').props.onPress());
  assert.equal(calls.length, 0);
  await act(async () => press(renderer.root, 'Regularizo expresamente').props.onPress());
  await act(async () => press(renderer.root, 'Guardar regularización').props.onPress());
  assert.equal(calls[0][3].aceptar_fuera_plazo, true);
  await act(async () => renderer.unmount());
});
