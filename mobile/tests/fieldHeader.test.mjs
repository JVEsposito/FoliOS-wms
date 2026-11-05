import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { dirname, resolve } from 'node:path';
import test from 'node:test';
import vm from 'node:vm';
import React from 'react';
import { act, create } from 'react-test-renderer';
import ts from 'typescript';

const require = createRequire(import.meta.url);
const root = resolve(dirname(new URL(import.meta.url).pathname), '../src');
globalThis.IS_REACT_ACT_ENVIRONMENT = true;
const flatten = value => Array.isArray(value) ? value.map(flatten).join('') : typeof value === 'string' ? value : value?.props ? flatten(value.props.children) : '';
function harness(width, multiple = true, busy = false, onBeforeLeave) {
  const cache = new Map(); const calls = [];
  function load(path) {
    if (cache.has(path)) return cache.get(path).exports;
    const module = { exports: {} }; cache.set(path, module);
    vm.runInNewContext(ts.transpileModule(readFileSync(path, 'utf8'), {
      compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 }, fileName: path,
    }).outputText, { module, exports: module.exports,
      require: id => {
        if (id === 'react-native') return { Image: 'Image', Modal: 'Modal', Pressable: 'Pressable', Text: 'Text', View: 'View', useWindowDimensions: () => ({ width }), StyleSheet: { create: styles => styles } };
        if (id.endsWith('/theme/areas')) return { areas: { 'materia-prima': { bright: '#55a', label: 'Materia Prima', gajo: 1 } } };
        if (id.startsWith('.')) {
          const base = resolve(dirname(path), id);
          for (const ext of ['.ts', '.tsx']) { try { readFileSync(base + ext); } catch { continue; } return load(base + ext); }
        }
        return require(id);
      },
    }); return module.exports;
  }
  const { FieldHeader } = load(resolve(root, 'components/field/FieldHeader.tsx'));
  const { FieldNavigationProvider } = load(resolve(root, 'components/field/FieldNavigation.tsx'));
  const auth = { usuario: { nombre: 'Operador Uno', rol: 'validador_mp' }, dispositivo: { nombre: 'PDA Uno' } };
  let renderer;
  return {
    calls,
    async mount() { await act(async () => { renderer = create(React.createElement(FieldNavigationProvider, { auth, onChangeModule: multiple ? () => calls.push('module') : undefined },
      React.createElement(FieldHeader, { area: 'materia-prima', title: 'Validación MP', subtitle: 'TEMP-2627', busy, offline: true, onBeforeLeave, onLogout: () => calls.push('guarded-logout') },
        React.createElement('Pressable', { onPress: () => calls.push('refresh') }, React.createElement('Text', null, 'Actualizar'))))); }); },
    text() { return renderer.root.findAllByType('Text').map(node => flatten(node.props.children)).join('\n'); },
    async press(label) { const button = renderer.root.findAllByType('Pressable').find(node => node.props.accessibilityLabel === label || flatten(node.props.children) === label); assert.ok(button, label); assert.ok(!button.props.disabled, label); await act(async () => button.props.onPress()); },
    menuDisabled() { return renderer.root.findAllByType('Pressable').find(node => node.props.accessibilityLabel === 'Menú de Operador Uno').props.disabled; },
    async close() { await act(async () => renderer.unmount()); },
  };
}
for (const width of [360, 800, 1280]) test(`cabecera de terreno a ${width}: acciones e identidad agrupadas sin perder temporada ni salida protegida`, async () => {
  const h = harness(width);
  await h.mount();
  try {
    assert.match(h.text(), /Validación MP/); assert.match(h.text(), /TEMP-2627/); assert.match(h.text(), /Sin conexión/);
    assert.doesNotMatch(h.text(), /PDA Uno/);
    await h.press('Actualizar'); await h.press('Menú de Operador Uno');
    assert.match(h.text(), /PDA Uno/); assert.match(h.text(), /Cambiar módulo/);
    await h.press('Cambiar módulo'); assert.deepEqual(h.calls, ['refresh', 'module']);
    await h.press('Menú de Operador Uno'); await h.press('Cerrar sesión');
    assert.deepEqual(h.calls, ['refresh', 'module', 'guarded-logout']);
  } finally { await h.close(); }
});
test('un único módulo no ofrece cambiar y una escritura en curso deshabilita el menú', async () => {
  const h = harness(360, false); await h.mount();
  try { await h.press('Menú de Operador Uno'); assert.doesNotMatch(h.text(), /Cambiar módulo/); } finally { await h.close(); }
  const busy = harness(360, true, true); await busy.mount();
  try { assert.equal(busy.menuDisabled(), true); } finally { await busy.close(); }
});

test('cambiar de módulo y cerrar sesión esperan la confirmación de la pantalla', async () => {
  const confirmations = [];
  const h = harness(360, true, false, (proceed, target) => confirmations.push({ proceed, target }));
  await h.mount();
  try {
    await h.press('Menú de Operador Uno'); await h.press('Cambiar módulo');
    assert.equal(confirmations[0].target, 'module'); assert.deepEqual(h.calls, []);
    // Volver de la advertencia no ejecuta la salida; se puede abrir el menú de nuevo.
    await h.press('Menú de Operador Uno'); await h.press('Cerrar sesión');
    assert.equal(confirmations[1].target, 'logout'); assert.deepEqual(h.calls, []);
    await act(async () => confirmations[1].proceed());
    assert.deepEqual(h.calls, ['guarded-logout']);
    await h.press('Menú de Operador Uno'); await h.press('Cambiar módulo');
    await act(async () => confirmations[2].proceed());
    assert.deepEqual(h.calls, ['guarded-logout', 'module']);
  } finally { await h.close(); }
});
