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
function component() {
  const file = new URL('../src/components/MaterialWarehouseDelivery.tsx', import.meta.url);
  const compiled = ts.transpileModule(readFileSync(file, 'utf8'), { compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText;
  const module = { exports: {} }; let id = 0;
  vm.runInNewContext(compiled, { module, exports: module.exports, Date, Intl, Error, Number, console, require: name => {
    if (name === 'react-native') return { Pressable: 'Pressable', Text: 'Text', TextInput: 'TextInput', View: 'View', StyleSheet: { create: s => s } };
    if (name === 'expo-crypto') return { randomUUID: () => `uuid-${++id}` };
    if (name.endsWith('/colors')) return { colors: {} };
    if (name.endsWith('MaterialDispatchRegistration')) return { MaterialDispatchRegistrationFields: 'Registration' };
    return require(name);
  } });
  return module.exports.MaterialWarehouseDelivery;
}
const order = { id: 'd1', estado: 'pendiente', almacen_origen: { id: 'w2', nombre: 'Bodega Embalajes', requiere_ubicacion_fisica: false } };
const item = { cantidad_pendiente: '5.000', unidad_medida: 'kg', sugerencias_fifo: [{ folio_id: 'f1', numero_folio: 'FMAT000001', cantidad: '4.000', camara: null, posicion: null }] };
const auth = { token: 'token', usuario: { id: 1, capacidades: { puede_retirar_materiales: true } } };
const press = (r, label) => r.findAllByType('Pressable').find(n => n.findAllByType('Text').some(t => t.children.join('').includes(label)));
const field = (r, label) => r.findAllByType('TextInput').find(n => n.props.accessibilityLabel.includes(label));
const text = r => r.findAllByType('Text').map(n => n.children.join('')).join(' ');

test('solo quien puede retirar y tiene la orden asignada puede entregar sin plano', async () => {
  const C = component();
  for (const props of [{ auth: { ...auth, usuario: { ...auth.usuario, capacidades: {} } } }, { dispatch: { ...order, asignado_a_user_id: 2 } }, { dispatch: { ...order, almacen_origen: { ...order.almacen_origen, requiere_ubicacion_fisica: true } } }]) {
    let r; await act(async () => { r = create(React.createElement(C, { dispatch: order, item, auth, api: {}, onChange: () => {}, ...props })); });
    assert.equal(r.toJSON(), null); await act(async () => r.unmount());
  }
});

test('comprueba el folio físico, cantidad máxima y tres decimales antes de entregar', async () => {
  const C = component(); const calls = []; let r;
  await act(async () => { r = create(React.createElement(C, { dispatch: order, item, auth, api: { withdrawMaterial: async (...a) => { calls.push(a); return order; } }, onChange: () => {} })); });
  await act(async () => press(r.root, 'Entregar desde').props.onPress());
  assert.match(text(r.root), /Bodega Embalajes/);
  for (const [folio, cantidad] of [['OTRO', '1'], ['FMAT000001', '5'], ['FMAT000001', '-1'], ['FMAT000001', '0.0001'], ['FMAT000001', 'Infinity']]) {
    await act(async () => { field(r.root, 'Folio').props.onChangeText(folio); field(r.root, 'Cantidad').props.onChangeText(cantidad); });
    assert.equal(press(r.root, 'Confirmar entrega').props.disabled, true);
    await act(async () => press(r.root, 'Confirmar entrega').props.onPress());
  }
  assert.equal(calls.length, 0);
  await act(async () => { field(r.root, 'Folio').props.onChangeText(' fmat000001 '); field(r.root, 'Cantidad').props.onChangeText('1,250'); });
  await act(async () => press(r.root, 'Confirmar entrega').props.onPress());
  assert.equal(calls[0][2].retiros[0].cantidad, 1.25);
  assert.equal(Object.hasOwn(calls[0][2].retiros[0], 'sesion_estiba_id'), false);
  await act(async () => r.unmount());
});

test('doble toque y respuesta perdida no duplican la entrega; cambiar cantidad usa otro UUID', async () => {
  const C = component(); const calls = []; let rejectFirst; let r;
  const api = { withdrawMaterial: async (...a) => { calls.push(JSON.parse(JSON.stringify(a))); if (calls.length === 1) await new Promise((_, reject) => { rejectFirst = reject; }); throw new Error('Respuesta perdida'); } };
  await act(async () => { r = create(React.createElement(C, { dispatch: order, item, auth, api, onChange: () => {} })); });
  await act(async () => press(r.root, 'Entregar desde').props.onPress());
  await act(async () => { field(r.root, 'Folio').props.onChangeText('FMAT000001'); field(r.root, 'Cantidad').props.onChangeText('2'); });
  await act(async () => { const f = press(r.root, 'Confirmar entrega').props.onPress; f(); f(); });
  assert.equal(calls.length, 1);
  await act(async () => rejectFirst(new Error('Respuesta perdida')));
  await act(async () => press(r.root, 'Confirmar entrega').props.onPress());
  assert.deepEqual(calls[0], calls[1]);
  await act(async () => field(r.root, 'Cantidad').props.onChangeText('1'));
  await act(async () => press(r.root, 'Confirmar entrega').props.onPress());
  assert.notEqual(calls[1][2].operacion_id, calls[2][2].operacion_id);
  await act(async () => r.unmount());
});
