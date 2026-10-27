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
const compile = (path) => ts.transpileModule(readFileSync(new URL(path, import.meta.url), 'utf8'), {
  compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
}).outputText;

test('el espacio operacional mantiene presencia entre pestañas y usa la identidad vigente', async () => {
  const calls = []; const polls = [];
  const module = { exports: {} };
  vm.runInNewContext(compile('../src/screens/OperationalWorkspaceScreen.tsx'), {
    module, exports: module.exports,
    require: (id) => {
      if (id === 'react-native') return { Pressable: 'Pressable', ScrollView: 'ScrollView', Text: 'Text', View: 'View', StyleSheet: { create: (s) => s } };
      if (id === 'react' || id === 'react/jsx-runtime') return require(id);
      if (id.endsWith('/hooks/useOperationalPolling')) return { useOperationalPolling: (task, options) => polls.push({ task, options }) };
      if (id.endsWith('/services/operatorPresenceApi')) return { sendOperatorPresence: async (...args) => { calls.push(args); } };
      if (id.endsWith('/services/environmentalControlApi')) return { getEnvironmentalControlState: async () => ({ camaras: [] }) };
      if (id.endsWith('/theme/operatorTheme')) return { operatorTheme: { color: {}, space: {}, type: {}, radius: {}, touch: {} } };
      if (id.endsWith('/OperatorHeader')) return { OperatorHeader: (props) => React.createElement('View', null, props.navigation) };
      const name = id.split('/').at(-1);
      return { [name]: name };
    },
  });
  const Screen = module.exports.OperationalWorkspaceScreen;
  const props = (token, connected = true, products = true) => ({ api: { mode: connected ? 'connected' : 'demo', baseUrl: 'https://wms.example' },
    auth: { token, usuario: { nombre: 'Camarero', rol: 'camarero_frio', capacidades: { puede_operar_productos: products } }, dispositivo: { nombre: 'Tablet' } }, onLogout() {} });
  let renderer;
  try {
    await act(async () => { renderer = create(React.createElement(Screen, props('actor-a'))); });
    assert.deepEqual(calls, [['https://wms.example', 'actor-a']]);
    assert.equal(polls.at(-1).options.enabled, true);
    assert.equal(polls.at(-1).options.intervalMs, 15000);
    const plano = renderer.root.findAllByType('Pressable').find((node) => node.findAllByType('Text').some((text) => text.props.children === 'Plano y operación'));
    await act(async () => { plano.props.onPress(); });
    assert.equal(calls.length, 1, 'cambiar de pestaña no duplica el alta');
    await act(async () => { renderer.update(React.createElement(Screen, props('actor-b'))); });
    await polls.at(-1).task();
    assert.ok(calls.slice(1).every(([, token]) => token === 'actor-b'));
    await act(async () => { renderer.update(React.createElement(Screen, props('demo', false))); });
    assert.equal(polls.at(-1).options.enabled, false);
    await act(async () => { renderer.update(React.createElement(Screen, props('materiales', true, false))); });
    assert.equal(polls.at(-1).options.enabled, false);
    assert.equal(calls.length, 3);
  } finally { if (renderer) await act(async () => renderer.unmount()); }
});

test('el latido autentica su token sin permitir indicar otra persona y propaga la falta de comunicación', async () => {
  const calls = []; let ok = true; const module = { exports: {} };
  vm.runInNewContext(compile('../src/services/operatorPresenceApi.ts'), { module, exports: module.exports,
    require: () => ({ fetchWithTimeout: async (...args) => { calls.push(args); return { ok }; } }), Error,
  });
  await module.exports.sendOperatorPresence('https://wms.example', 'vigente');
  assert.equal(calls[0][0], 'https://wms.example/api/operacion/camareros/presencia');
  assert.equal(calls[0][1].method, 'POST');
  assert.equal(calls[0][1].headers.Authorization, 'Bearer vigente');
  assert.equal(calls[0][1].body, undefined);
  ok = false;
  await assert.rejects(module.exports.sendOperatorPresence('https://wms.example', 'vigente'), /presencia/);
});
