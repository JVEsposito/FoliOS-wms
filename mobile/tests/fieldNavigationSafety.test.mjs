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
import { fieldUiMock } from './helpers/fieldUiMock.mjs';
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
  let serverSessions = ['Cámara 3'];
  function Plan(props) {
    React.useEffect(() => { props.onOpenSessionsChange(serverSessions); props.onRegisterLogout(async () => { await props.api.logout(); props.onLogout(); }); return () => props.onRegisterLogout(null); }, [props.onOpenSessionsChange, props.onRegisterLogout]);
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
  await act(async () => { r = create(React.createElement(OperationalWorkspaceScreen, { auth, api: { mode: 'connected', baseUrl: 'https://planta.test', logout: async () => calls.push('api'), listCameras: async () => serverSessions.map(nombre => ({ nombre, acceso: { modo: 'edicion', sesion: { es_propia: true } } })) }, onLogout: () => calls.push('local'), onChangeModule: () => calls.push('module') })); });
  try {
    await press(r, 'Plano y operación'); await press(r, 'Mi jornada');
    await act(async () => r.root.findByType('Menu').props.onLogout());
    assert.equal(alerts.length, 1); assert.match(alerts[0][1], /Cámara 3/); assert.deepEqual(calls, []);
    await press(r, 'Mi jornada');
    await act(async () => r.root.findByType('Menu').props.onChangeModule());
    assert.equal(alerts.length, 2); assert.deepEqual(calls, []);
    serverSessions = [];
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

class AccessError extends Error { constructor(status) { super('Sesión vencida'); this.status = status; } }
function workspaceHarness(readCameras) {
  const calls = []; const alerts = [];
  const { OperationalWorkspaceScreen } = loader({
    '../components/operator/OperatorHeader': { OperatorHeader: props => React.createElement('Header', null, props.navigation, props.trailing) },
    '../components/operator/OperatorMenu': { OperatorMenu: props => React.createElement('Menu', props) },
    '../components/MaterialInventoryCountPanel': { MaterialInventoryCountPanel: 'Count' },
    '../components/MaterialVerificationPanel': { MaterialVerificationPanel: 'Verify' },
    '../components/OperationalTaskInbox': { OperationalTaskInbox: 'Inbox' },
    './EnvironmentalControlScreen': { EnvironmentalControlScreen: 'Ambient' },
    './OperationalScreen': { OperationalScreen: 'Plan' },
    '../hooks/useOperationalPolling': { useOperationalPolling() {} },
    '../services/operatorPresenceApi': { sendOperatorPresence: async () => {} },
    '../services/environmentalControlApi': { getEnvironmentalControlState: async () => ({ camaras: [] }) },
    '../services/apiError': { ApiError: AccessError },
  }, { Alert: { alert: (...args) => alerts.push(args) } })('screens/OperationalWorkspaceScreen.tsx');
  let r;
  return {
    calls, alerts,
    async mount() { await act(async () => { r = create(React.createElement(OperationalWorkspaceScreen, {
      auth, api: { mode: 'connected', baseUrl: 'https://planta.test', listCameras: async () => { calls.push('read'); return readCameras(); }, logout: async () => calls.push('api') },
      onLogout: () => calls.push('local'), onChangeModule: () => calls.push('module'),
    })); }); },
    menu() { return r.root.findByType('Menu').props; },
    tabs() { return r.root.findAllByType('Pressable').filter(node => node.props.accessibilityRole === 'tab'); },
    plan() { return r.root.findByType('Plan'); },
    async busy(value) { await act(async () => r.root.findByType('Inbox').props.onBusyChange(value)); },
    async close() { await act(async () => r.unmount()); },
  };
}

for (const action of ['onLogout', 'onChangeModule']) test(`${action}: verifica las sesiones del servidor sin haber abierto el plano`, async () => {
  const h = workspaceHarness(async () => [
    { codigo: 'CAM-04', nombre: '', acceso: { modo: 'edicion', sesion: { es_propia: true } } },
    { nombre: 'Otra cámara', acceso: { modo: 'edicion', sesion: { es_propia: false } } },
  ]);
  await h.mount();
  try {
    await act(async () => h.menu()[action]());
    assert.deepEqual(h.calls, ['read']);
    assert.match(h.alerts[0][1], /CAM-04/); assert.doesNotMatch(h.alerts[0][1], /Otra cámara/);
    assert.ok(h.plan());
  } finally { await h.close(); }
});

test('una consulta fallida no autoriza salir y permite reintentar tras recuperar conexión', async () => {
  let offline = true;
  const h = workspaceHarness(async () => { if (offline) throw new Error('Sin red'); return []; });
  await h.mount();
  try {
    await act(async () => h.menu().onChangeModule());
    assert.match(h.alerts[0][0], /No se pudo comprobar/); assert.deepEqual(h.calls, ['read']);
    assert.equal(h.menu().disabled, false);
    offline = false;
    await act(async () => h.menu().onChangeModule());
    assert.deepEqual(h.calls, ['read', 'read', 'module']);
  } finally { await h.close(); }
});

test('un acceso vencido vuelve al ingreso sin quedar atrapado en la comprobación de estibas', async () => {
  const h = workspaceHarness(async () => { throw new AccessError(401); });
  await h.mount();
  try {
    await act(async () => h.menu().onChangeModule());
    assert.deepEqual(h.calls, ['read', 'local']); assert.equal(h.alerts.length, 0);
  } finally { await h.close(); }
});

test('una consulta de salida lenta bloquea doble pulsación y navegación hasta terminar', async () => {
  let release; const response = new Promise(resolve => { release = resolve; });
  const h = workspaceHarness(() => response);
  await h.mount();
  try {
    let leave;
    await act(async () => { leave = h.menu().onLogout(); });
    assert.equal(h.menu().disabled, true); assert.ok(h.tabs().every(tab => tab.props.disabled));
    await act(async () => h.menu().onLogout()); assert.deepEqual(h.calls, ['read']);
    await act(async () => { release([]); await leave; });
    assert.deepEqual(h.calls, ['read', 'api', 'local']);
  } finally { await h.close(); }
});

test('una maniobra en escritura bloquea pestañas y menú hasta recibir su resultado', async () => {
  const h = workspaceHarness(async () => []);
  await h.mount();
  try {
    await h.busy(true);
    assert.equal(h.menu().disabled, true); assert.ok(h.tabs().every(tab => tab.props.disabled));
    await act(async () => h.menu().onChangeModule()); assert.deepEqual(h.calls, []);
    await h.busy(false); assert.equal(h.menu().disabled, false);
    await act(async () => h.menu().onChangeModule()); assert.deepEqual(h.calls, ['read', 'module']);
  } finally { await h.close(); }
});

test('si comienza una escritura mientras se verificaba la salida, se conserva la pantalla', async () => {
  let release; const response = new Promise(resolve => { release = resolve; });
  const h = workspaceHarness(() => response);
  await h.mount();
  try {
    await act(async () => { void h.menu().onChangeModule(); });
    await h.busy(true);
    await act(async () => release([]));
    assert.deepEqual(h.calls, ['read']); assert.equal(h.menu().disabled, true);
    await h.busy(false); assert.equal(h.menu().disabled, false);
  } finally { await h.close(); }
});

test('Prefrío bloquea sus pestañas durante una operación del túnel o de punto de rocío', async () => {
  const { PrefrioWorkspaceScreen } = loader({
    '../components/field/FieldHeader': fieldUiMock('../components/field/FieldHeader'),
    'expo-crypto': { randomUUID: () => 'uuid' },
    '../hooks/useOperationalPolling': { useOperationalPolling() {} },
    '../services/prefrioApi': {},
    '../services/prefrioOfflineStore': { loadPrefrioCache: async () => null },
    './DewPointScreen': { DewPointScreen: 'DewPoint' },
    './PrefrioScreen': { PrefrioScreen: 'Tunnels' },
  }, { ActivityIndicator: 'ActivityIndicator', Modal: 'Modal' })('screens/PrefrioWorkspaceScreen.tsx');
  let r; await act(async () => { r = create(React.createElement(PrefrioWorkspaceScreen, { auth, baseUrl: null, onLogout() {} })); });
  try {
    const tabs = () => r.root.findAllByType('Pressable');
    for (const [label, component] of [['Túneles y procesos', 'Tunnels'], ['Punto de rocío', 'DewPoint']]) {
      await press(r, label);
      await act(async () => r.root.findByType(component).props.onBusyChange(true));
      assert.ok(tabs().every(tab => tab.props.disabled));
      await act(async () => r.root.findByType(component).props.onBusyChange(false));
      assert.ok(tabs().every(tab => !tab.props.disabled));
    }
  } finally { await act(async () => r.unmount()); }
});

for (const [width, height] of [[360, 640], [800, 600], [1280, 600], [1280, 800]]) test(`portada ${width}×${height}: una única acción principal, fija cuando falta espacio`, async () => {
  const { OperatorTaskHome } = loader({}, { useWindowDimensions: () => ({ width, height }) })('components/operator/OperatorTaskHome.tsx');
  const item = { source: 'mine', task: { id: 'task', estado: 'asumida', tipo_movimiento: 'traslado', plan: { tipo: 'concentracion_carga', prioridad: 'normal' }, folio: { numero_folio: '2610045902' }, origen: null, destino: null, contexto: {} } };
  let r; let opens = 0;
  await act(async () => { r = create(React.createElement(OperatorTaskHome, { availableCount: 0, busy: false, mineCount: 1, next: item, plannerFresh: true, queue: [], suggestedDestinations: {}, view: 'mine', onRefresh() {}, onViewChange() {}, onOpen: () => opens++ })); });
  try {
    const actions = r.root.findAllByType('Pressable').filter(node => text(node.props.children) === 'INICIAR MANIOBRA →');
    assert.equal(actions.length, 1);
    const inScroll = node => node?.type === 'ScrollView' || (node?.parent ? inScroll(node.parent) : false);
    assert.equal(inScroll(actions[0]), width >= 1000 && height >= 740);
    await act(async () => actions[0].props.onPress()); assert.equal(opens, 1);
  } finally { await act(async () => r.unmount()); }
});

test('Prefrío conserva la bandeja al cancelar la salida o confirmar cambiar de módulo', async () => {
  const alerts = []; const calls = [];
  const queue = [{ id: 'op-1', process_id: 'p1', process_code: 'PF-01', kind: 'iniciar', status: 'pendiente', created_at: '2026-11-05T12:00:00Z', attempts: 0, payload: {} }];
  const { PrefrioScreen } = loader({
    '../components/field/FieldHeader': fieldUiMock('../components/field/FieldHeader'),
    'expo-crypto': { randomUUID: () => 'uuid' },
    '../hooks/useOperationalPolling': { useOperationalPolling() {} },
    '../services/prefrioApi': {},
    '../services/prefrioOfflineStore': {
      loadPrefrioCache: async () => ({ tunnels: [], processes: [], eligible_folios: [], revisions: {}, synced_at: '2026-11-05T12:00:00Z' }),
      loadPrefrioOutbox: async () => queue,
    },
  }, { ActivityIndicator: 'ActivityIndicator', Modal: 'Modal', Alert: { alert: (...args) => alerts.push(args) } })('screens/PrefrioScreen.tsx');
  let r; await act(async () => { r = create(React.createElement(PrefrioScreen, { auth, baseUrl: null, onLogout: () => calls.push('logout') })); });
  try {
    const header = () => r.root.findByType('FieldHeader').props;
    await act(async () => header().onBeforeLeave(() => calls.push('module'), 'module'));
    assert.match(alerts[0][1], /cambiar módulo/); assert.deepEqual(calls, []);
    await act(async () => alerts[0][2].find(button => button.text === 'Cambiar módulo').onPress());
    assert.deepEqual(calls, ['module']); assert.equal(queue.length, 1);
  } finally { await act(async () => r.unmount()); }
});
