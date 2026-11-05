import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { createRequire } from 'node:module';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import vm from 'node:vm';
import React from 'react';
import { act, create } from 'react-test-renderer';
import ts from 'typescript';
const require = createRequire(import.meta.url);
const root = resolve(dirname(fileURLToPath(import.meta.url)), '../src');
globalThis.IS_REACT_ACT_ENVIRONMENT = true;
const text = value => Array.isArray(value) ? value.map(text).join('') : typeof value === 'string' || typeof value === 'number' ? String(value) : value?.props ? text(value.props.children) : '';
function loader(mocks, extraNative = {}) {
  const cache = new Map();
  const native = { Pressable: 'Pressable', View: 'View', Text: 'Text', ScrollView: 'ScrollView', TextInput: 'TextInput', RefreshControl: 'RefreshControl', StyleSheet: { create: styles => styles }, useWindowDimensions: () => ({ width: 1280 }), ...extraNative };
  function load(path) {
    if (cache.has(path)) return cache.get(path).exports;
    const module = { exports: {} }; cache.set(path, module);
    vm.runInNewContext(ts.transpileModule(readFileSync(path, 'utf8'), { compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 }, fileName: path }).outputText, {
      module, exports: module.exports, Error, Intl, console, setInterval: () => 1, clearInterval() {},
      require: id => {
        if (id in mocks) return mocks[id];
        if (id === 'react-native') return native;
        if (id.startsWith('.')) { const base = resolve(dirname(path), id); for (const ext of ['.ts', '.tsx']) if (existsSync(base + ext)) return load(base + ext); }
        return require(id);
      },
    }); return module.exports;
  }
  return file => load(resolve(root, file));
}
async function press(renderer, label) { const button = renderer.root.findAllByType('Pressable').find(node => text(node.props.children) === label); assert.ok(button, label); await act(async () => button.props.onPress()); }
const auth = { token: 'token', usuario: { id: 'u', nombre: 'Camarero', rol: 'camarero_frio', capacidades: { puede_operar_productos: true } }, dispositivo: { id: 't', nombre: 'Tablet' } };

test('salir y cambiar módulo siguen revisando las estibas abiertas después de cambiar de pestaña', async () => {
  const calls = []; const alerts = [];
  function Plan(props) {
    React.useEffect(() => { props.onOpenSessionsChange(['Cámara 3']); props.onRegisterLogout(async () => { await props.api.logout(); props.onLogout(); }); return () => props.onRegisterLogout(null); }, [props.onOpenSessionsChange, props.onRegisterLogout]);
    return React.createElement('Plan', props);
  }
  const { OperationalWorkspaceScreen } = loader({
    '../components/operator/OperatorHeader': { OperatorHeader: props => React.createElement('Header', null, props.navigation, props.trailing) },
    '../components/operator/OperatorMenu': { OperatorMenu: props => React.createElement('Menu', props) },
    '../components/MaterialInventoryCountPanel': { MaterialInventoryCountPanel: 'Count' },
    '../components/MaterialVerificationPanel': { MaterialVerificationPanel: 'Verify' },
    '../components/OperationalTaskInbox': { OperationalTaskInbox: 'Inbox' },
    './EnvironmentalControlScreen': { EnvironmentalControlScreen: 'Ambient' },
    './OperationalScreen': { OperationalScreen: Plan },
    '../hooks/useOperationalPolling': { useOperationalPolling() {} },
    '../services/operatorPresenceApi': { sendOperatorPresence: async () => {} },
    '../services/environmentalControlApi': { getEnvironmentalControlState: async () => ({ camaras: [] }) },
  }, { Alert: { alert: (...args) => alerts.push(args) } })('screens/OperationalWorkspaceScreen.tsx');
  let r;
  await act(async () => { r = create(React.createElement(OperationalWorkspaceScreen, { auth, api: { mode: 'connected', baseUrl: 'https://planta.test', logout: async () => calls.push('api') }, onLogout: () => calls.push('local'), onChangeModule: () => calls.push('module') })); });
  try {
    await press(r, 'Plano y operación'); await press(r, 'Mi jornada');
    await act(async () => r.root.findByType('Menu').props.onLogout());
    assert.equal(alerts.length, 1); assert.match(alerts[0][1], /Cámara 3/); assert.deepEqual(calls, []);
    await press(r, 'Mi jornada');
    await act(async () => r.root.findByType('Menu').props.onChangeModule());
    assert.equal(alerts.length, 2); assert.deepEqual(calls, []);
    await act(async () => r.root.findByType('Plan').props.onOpenSessionsChange([]));
    await act(async () => r.root.findByType('Menu').props.onLogout());
    assert.deepEqual(calls, ['api', 'local']);
  } finally { await act(async () => r.unmount()); }
});

test('el plano permite cambiar de nivel con una selección y conserva la vista al refrescar', async () => {
  const { PositionMap } = loader({})('components/PositionMap.tsx');
  const position = n => ({ id: 'p' + n, banda: 1, posicion: 1, nivel: n, etiqueta: 'B01-P01-N' + n, estado: 'activa', ocupada: false, reservada: false });
  const plan = { nombre: 'Cámara 3', tipo: 'almacenamiento', contenido: 'productos', version_plano: 1, posiciones: [position(1), position(2)], ocupacion: { porcentaje: 0, ocupadas: 0, total: 2 }, acceso: { modo: 'disponible' } };
  let r; const props = { plan, selectedPositionId: 'p1', onSelectPosition() {} };
  await act(async () => { r = create(React.createElement(PositionMap, props)); });
  try {
    await press(r, 'Nivel 2');
    const selected = () => r.root.findAllByType('Pressable').find(node => node.props.accessibilityRole === 'tab' && node.props.accessibilityState.selected);
    assert.equal(text(selected().props.children), 'Nivel 2');
    await act(async () => r.update(React.createElement(PositionMap, { ...props, plan: { ...plan, version_plano: 2 } })));
    assert.equal(text(selected().props.children), 'Nivel 2');
    await act(async () => r.update(React.createElement(PositionMap, { ...props, selectedPositionId: 'p2' })));
    await act(async () => r.update(React.createElement(PositionMap, props)));
    assert.equal(text(selected().props.children), 'Nivel 1');
  } finally { await act(async () => r.unmount()); }
});

test('Ambiente no declara cámaras al día cuando el servidor no respondió', async () => {
  const { EnvironmentalControlScreen } = loader({
    'expo-crypto': { randomUUID: () => 'operation' },
    '../services/environmentalControlApi': { getEnvironmentalControlState: async () => { throw new Error('Sin conexión'); } },
    '../services/environmentalControlDraftStore': { loadEnvironmentalDraft: async () => null },
  })('screens/EnvironmentalControlScreen.tsx');
  let r; await act(async () => { r = create(React.createElement(EnvironmentalControlScreen, { auth, baseUrl: 'https://planta.test' })); });
  try { const body = r.root.findAllByType('Text').map(node => text(node.props.children)).join('\n'); assert.match(body, /Estado sin verificar/); assert.doesNotMatch(body, /Todas las cámaras al día/); } finally { await act(async () => r.unmount()); }
});
