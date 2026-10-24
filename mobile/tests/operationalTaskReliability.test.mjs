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
const sourceRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../src');
const clone = (value) => JSON.parse(JSON.stringify(value));
const deferred = () => { let resolve; let reject; const promise = new Promise((yes, no) => { resolve = yes; reject = no; }); return { promise, resolve, reject }; };
const endpoint = (camera, position) => ({ camara: { id: camera, nombre: camera }, posicion: { id: position, etiqueta: position, banda: 1, posicion: 1, nivel: 1 } });
const task = (overrides = {}) => ({
  id: 'task-1', version: 2, estado: 'en_proceso', tipo_movimiento: 'ubicacion_inicial',
  tipo_paso_maniobra: 'ubicacion_inicial', secuencia_maniobra: 1, contexto: {},
  plan: { id: 'plan-1', tipo: 'recepcion_tunel', prioridad: 'normal', horizon: 'rolling', version: 2 },
  folio: { id: 'folio-1', numero_folio: '2610045902', tipo_bulto: 'pallet', envase: 'Caja 5 kg' },
  origen: null, destino: endpoint('camera-b', 'B01-P01-N1'),
  reserva: { tipo_compromiso: 'fisica', vence_at: null }, ...overrides,
});

function harness({ initial = task(), offered = [], verificationSlow = false, storage = new Map(), postFailure = null } = {}) {
  let renderer; let uuid = 0; let polling; let failWrite = false; let failRemove = false; let committed = false;
  let own = initial ? [clone(initial)] : []; let available = clone(offered);
  const calls = { posts: [], takes: [], opens: [], closes: [], plans: [], own: [], lists: [] }; const controls = {};
  const native = { ActivityIndicator: 'ActivityIndicator', Pressable: 'Pressable', Text: 'Text', View: 'View', StyleSheet: { create: (value) => value }, Alert: { alert: () => {} } };
  const asyncStorage = {
    getItem: async (key) => storage.get(key) ?? null,
    setItem: async (key, value) => { if (failWrite) { failWrite = false; throw new Error('Disco no disponible'); } storage.set(key, value); },
    removeItem: async (key) => { if (failRemove) { failRemove = false; throw new Error('No se pudo retirar la confirmación'); } storage.delete(key); },
  };
  const plan = (id) => ({ id, nombre: id, version_plano: 4, revision_reservas: 1, acceso: { modo: 'disponible', sesion: null }, posiciones: [], folios_sin_posicion: [] });
  const post = async (kind, payload) => {
    const captured = JSON.parse([...storage.values()][0]); assert.ok(captured, 'La confirmación se guarda antes del POST');
    if (payload.operacion_id) assert.equal(captured.id, payload.operacion_id);
    calls.posts.push({ kind, payload: clone(payload) });
    if (controls.post) return controls.post(kind, payload);
    if (postFailure === 'before' && calls.posts.length === 1) throw new TypeError('Sin conexión antes del envío');
    committed = true; own = own.filter((entry) => entry.id !== initial.id);
    if (postFailure === 'after' && calls.posts.length === 1) throw new TypeError('Respuesta perdida después de confirmar');
  };
  const taskApi = {
    list: async (token, assignment) => { calls.lists.push(assignment); return controls.list ? controls.list(assignment) : clone(assignment === 'mias' ? own : available); },
    currentVerification: async () => verificationSlow ? new Promise(() => {}) : null,
    physicalFrontierSnapshot: async () => { throw new Error('No se requiere propuesta para un destino materializado'); },
    take: async (token, id) => { calls.takes.push(id); if (controls.take) return controls.take(id); const taken = { ...available.find((entry) => entry.id === id), estado: 'asumida' }; own.push(taken); available = available.filter((entry) => entry.id !== id); return clone(taken); },
    ownState: async (token, id, operation) => { calls.own.push({ id, operation }); if (controls.ownState) return controls.ownState(id, operation); return { confirmada: committed, tarea: { ...initial, estado: committed ? 'completada' : initial.estado }, operacion: null }; },
    start: async () => { own = own.map((entry) => ({ ...entry, estado: 'en_proceso' })); return clone(own[0]); },
    completeTemporaryExtraction: async (token, id, payload) => post('temporary', payload), completeDirectPrefrio: async () => post('prefrio', {}), pinStatus: async () => ({ configurado: true }),
  };
  const api = { mode: 'connected', baseUrl: 'https://server.test',
    getPlan: async (token, id) => { calls.plans.push(id); return controls.getPlan ? controls.getPlan(id) : plan(id); },
    openSession: async (token, id) => { calls.opens.push(id); return { id: 'session-' + id }; }, closeSession: async (token, id) => { calls.closes.push(id); },
    locate: async (token, payload) => post('locate', payload), move: async (token, payload) => post('move', payload), sendLoadFolioToDock: async (token, id, payload) => post('dock', payload),
  };
  const cache = new Map();
  function load(path) {
    if (cache.has(path)) return cache.get(path).exports;
    const module = { exports: {} }; cache.set(path, module);
    const code = ts.transpileModule(readFileSync(path, 'utf8'), { compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 }, fileName: path }).outputText;
    const mockRequire = (id) => {
      if (id === 'react-native') return native;
      if (id === 'expo-crypto') return { randomUUID: () => `00000000-0000-4000-8000-${String(++uuid).padStart(12, '0')}` };
      if (id === '@react-native-async-storage/async-storage') return asyncStorage;
      if (id.endsWith('/hooks/useOperationalPolling')) return { useOperationalPolling: (callback, options) => { polling = { callback, ...options }; } };
      if (id.endsWith('/services/operationalTasksApi')) return { OperationalTasksApi: class { constructor() { return taskApi; } } };
      for (const name of ['OperatorTaskHome', 'OperatorTaskExecution', 'OperatorErrorDialog', 'OperatorLoadStatus', 'TurnVerification']) if (id.endsWith('/' + name)) return { [name]: name };
      if (id.startsWith('.')) { const base = resolve(dirname(path), id); for (const ext of ['.ts', '.tsx']) { try { readFileSync(base + ext); } catch { continue; } return load(base + ext); } }
      return require(id);
    };
    vm.runInNewContext(code, { require: mockRequire, module, exports: module.exports, console, Error, setInterval: () => 0, clearInterval: () => {} }, { filename: path }); return module.exports;
  }
  const { OperationalTaskInbox } = load(resolve(sourceRoot, 'components/OperationalTaskInbox.tsx'));
  const { OperationalMovementStore } = load(resolve(sourceRoot, 'services/operationalMovementStore.ts'));
  const { ApiError } = load(resolve(sourceRoot, 'services/apiError.ts'));
  const auth = { token: 'token', usuario: { id: '1', nombre: 'Camarero', capacidades: {} }, dispositivo: { id: 'tablet', nombre: 'Tablet A' } };
  async function flush(action = () => {}) { await act(async () => { await action(); for (let n = 0; n < 100; n++) await Promise.resolve(); }); }
  const text = (value) => Array.isArray(value) ? value.map(text).join('') : typeof value === 'string' ? value : '';
  const props = (name) => renderer.root.findByType(name).props;
  return { calls, controls, storage, flush, plan, taskApi, props, ApiError, store: (...args) => new OperationalMovementStore(...args), polling: () => polling, journal: () => [...storage.values()].map((value) => JSON.parse(value)),
    failNextWrite: () => { failWrite = true; }, failNextRemove: () => { failRemove = true; },
    async mount() { await flush(() => { renderer = create(React.createElement(OperationalTaskInbox, { api, auth })); }); }, async unmount() { if (renderer) await flush(() => renderer.unmount()); },
    async open() { await flush(() => { const home = props('OperatorTaskHome'); home.onOpen(home.next); }); }, async action(name) { await flush(() => props('OperatorTaskExecution')[name]()); },
    async retry() { await flush(() => renderer.root.findAllByType('Pressable').find((node) => node.findAllByType('Text').some((entry) => text(entry.props.children) === 'Revisar confirmación')).props.onPress()); },
    message() { return renderer.root.findAllByType('Text').map((node) => text(node.props.children)).join('\n'); }, error() { return props('OperatorErrorDialog').message; }, count(name) { return renderer.root.findAllByType(name).length; },
  };
}

test('dos toques simultáneos envían un movimiento y mantienen ocupado hasta finalizar', async () => {
  const h = harness(); const response = deferred(); h.controls.post = () => response.promise;
  try {
    await h.mount(); await h.open(); await h.flush(() => { const p = h.props('OperatorTaskExecution'); p.onComplete(); p.onComplete(); });
    assert.equal(h.calls.posts.length, 1); assert.equal(h.journal().length, 1); assert.match(h.message(), /Guardando entrega/);
    await h.flush(() => response.resolve()); assert.equal(h.journal().length, 0);
  } finally { await h.unmount(); }
});

test('verificación de turno lenta no bloquea las labores ni la entrega', async () => {
  const h = harness({ verificationSlow: true });
  try { await h.mount(); await h.open(); assert.equal(h.props('OperatorTaskExecution').busy, false); await h.action('onComplete'); assert.equal(h.calls.posts.length, 1); assert.equal(h.journal().length, 0); }
  finally { await h.unmount(); }
});

test('un refresco anterior al claim no borra la tarea recién tomada', async () => {
  const waiting = task({ estado: 'pendiente' }); const h = harness({ initial: null, offered: [waiting] }); const old = deferred();
  try {
    await h.mount(); let used = false;
    h.controls.list = (assignment) => assignment === 'mias' && !used ? (used = true, old.promise) : Promise.resolve(assignment === 'mias' ? [{ ...waiting, estado: 'asumida' }] : h.calls.takes.length ? [] : [waiting]);
    await h.flush(() => { void h.polling().callback(); }); await h.open(); assert.equal(h.calls.takes.length, 1);
    assert.equal(h.props('OperatorTaskExecution').task.estado, 'asumida'); await h.flush(() => old.resolve([]));
    assert.equal(h.props('OperatorTaskExecution').task.id, waiting.id); assert.equal(h.props('OperatorTaskExecution').busy, false);
  } finally { await h.unmount(); }
});

test('fallar al leer el plano después de abrir sesión cierra el recurso adquirido', async () => {
  const h = harness({ initial: task({ estado: 'asumida' }) }); h.controls.getPlan = (id) => h.calls.plans.length === 1 ? h.plan(id) : Promise.reject(new TypeError('Lectura perdida'));
  try { await h.mount(); await h.open(); await h.flush(() => h.props('OperatorTaskExecution').onConfirmStart({ folioDigits: '5902', pin: '1234' }));
    assert.deepEqual(h.calls.opens, ['camera-b']); assert.deepEqual(h.calls.closes, ['session-camera-b']); assert.equal(h.calls.posts.length, 0); assert.match(h.error(), /Lectura perdida/);
  } finally { await h.unmount(); }
});

for (const [name, initial, action, kind] of [
  ['ubicación', task(), 'onComplete', 'locate'],
  ['traslado', task({ tipo_movimiento: 'reubicacion', origen: endpoint('camera-a', 'ORIGEN') }), 'onComplete', 'move'],
  ['retiro temporal', task({ tipo_movimiento: 'retiro', tipo_paso_maniobra: 'extraccion_temporal', origen: endpoint('camera-a', 'ORIGEN') }), 'onCompleteTemporary', 'temporary'],
  ['andén', task({ tipo_movimiento: 'retiro', origen: endpoint('camera-a', 'ORIGEN'), destino_logico: { id: 'dock', carga_folio_id: 'assignment', nombre: 'Andén 1' } }), 'onCompleteDirect', 'dock'],
  ['prefrío directo', task({ tipo_movimiento: 'retiro', contexto: { origen_logico: 'tunel_prefrio' }, destino_logico: { id: 'dock', carga_folio_id: 'assignment', nombre: 'Andén 1' } }), 'onCompleteDirect', 'prefrio'],
]) test(`${name}: respuesta perdida y reinicio confirman sin otro POST`, async () => {
  const h = harness({ initial, postFailure: 'after' });
  try {
    await h.mount(); await h.open(); await h.action(action); assert.equal(h.calls.posts.length, 1); assert.equal(h.calls.posts[0].kind, kind);
    assert.equal(h.journal().length, 1); assert.match(h.message(), /Confirmación pendiente/); assert.equal(h.count('OperatorTaskExecution'), 0); assert.equal(h.count('OperatorTaskHome'), 0);
    await h.unmount(); await h.mount(); assert.equal(h.calls.posts.length, 1); assert.equal(h.journal().length, 0); assert.equal(h.calls.own[0].id, initial.id); assert.equal(h.count('OperatorTaskHome'), 1);
  } finally { await h.unmount(); }
});

test('envío no recibido se reintenta con el mismo UUID, sesiones, versiones y fecha', async () => {
  const h = harness({ postFailure: 'before' });
  try {
    await h.mount(); await h.open(); await h.action('onComplete'); const saved = clone(h.calls.posts[0]); assert.equal(h.journal().length, 1);
    await h.unmount(); await h.mount(); await h.retry(); assert.equal(h.calls.posts.length, 2); assert.deepEqual(h.calls.posts[1], saved); assert.equal(h.journal().length, 0);
  } finally { await h.unmount(); }
});

test('fallo de almacenamiento impide enviar y fallo de consulta conserva la captura', async () => {
  const h = harness();
  try {
    await h.mount(); await h.open(); h.failNextWrite(); await h.action('onComplete'); assert.equal(h.calls.posts.length, 0); assert.equal(h.journal().length, 0); assert.match(h.error(), /Disco no disponible/);
    h.controls.post = () => { throw new TypeError('Sin conexión'); }; await h.action('onComplete'); const saved = clone(h.journal());
    h.controls.ownState = () => { throw new TypeError('No se pudo consultar'); }; await h.retry(); assert.equal(h.calls.posts.length, 1); assert.deepEqual(h.journal(), saved); assert.match(h.message(), /No se pudo consultar/);
  } finally { await h.unmount(); }
});

test('fallo al borrar después de confirmar se recupera por consulta al reiniciar', async () => {
  const h = harness();
  try { await h.mount(); await h.open(); h.failNextRemove(); await h.action('onComplete'); assert.equal(h.journal().length, 1); await h.unmount(); await h.mount(); assert.equal(h.calls.posts.length, 1); assert.equal(h.journal().length, 0); }
  finally { await h.unmount(); }
});

test('las cámaras siempre se adquieren en el mismo orden', async () => {
  for (const [source, destination] of [['camera-b', 'camera-a'], ['camera-a', 'camera-b']]) {
    const h = harness({ initial: task({ origen: endpoint(source, 'ORIGEN'), destino: endpoint(destination, 'DESTINO'), tipo_movimiento: 'reubicacion' }) });
    try { await h.mount(); await h.open(); await h.action('onComplete'); assert.deepEqual(h.calls.opens, ['camera-a', 'camera-b']); } finally { await h.unmount(); }
  }
});

test('una captura ilegible se conserva y bloquea nuevas maniobras', async () => {
  const key = 'estiba_guided_confirmation:https%3A%2F%2Fserver.test:1:tablet';
  const h = harness({ storage: new Map([[key, '{captura dañada']]) });
  try {
    await h.mount(); assert.match(h.message(), /No se pudo leer la confirmación guardada/);
    assert.equal(h.count('OperatorTaskHome'), 0); assert.equal(h.count('OperatorTaskExecution'), 0);
    assert.equal(h.storage.get(key), '{captura dañada'); await h.retry(); assert.equal(h.calls.posts.length, 0);
    assert.equal(h.storage.get(key), '{captura dañada');
  } finally { await h.unmount(); }
});

test('capturas se separan por servidor, usuario y dispositivo y no se sobrescriben con escrituras paralelas', async () => {
  const h = harness(); const current = h.store('server', 'user', 'device');
  const entry = { id: 'op-a', taskId: 'task-a', folio: 'FOLIO-A', kind: 'locate', payload: { operacion_id: 'op-a' }, createdAt: new Date().toISOString() };
  const other = { ...entry, id: 'op-b', taskId: 'task-b', payload: { operacion_id: 'op-b' } };
  const result = await Promise.allSettled([current.save(entry), h.store('server', 'user', 'device').save(other)]);
  assert.deepEqual(result.map((value) => value.status), ['fulfilled', 'rejected']);
  assert.equal((await current.read()).id, entry.id);
  for (const args of [['other', 'user', 'device'], ['server', 'other', 'device'], ['server', 'user', 'other']]) assert.equal(await h.store(...args).read(), null);
  await current.clear('wrong-id'); assert.equal((await current.read()).id, entry.id);
  await current.clear(entry.id); assert.equal(await current.read(), null);
});

test('un rechazo confirmado por consulta deja corregir la misma tarea sin una captura pendiente falsa', async () => {
  const h = harness({ postFailure: 'before' });
  try {
    await h.mount(); await h.open(); await h.action('onComplete');
    h.controls.post = () => { throw new h.ApiError('La sesión expiró: vuelve a confirmar la entrega.', 409); };
    await h.retry(); assert.equal(h.journal().length, 0); assert.equal(h.count('OperatorTaskExecution'), 1);
    assert.equal(h.count('OperatorTaskHome'), 0); assert.match(h.error() + h.message(), /Confirmación pendiente|sesión expiró/);
    assert.equal(h.props('OperatorTaskExecution').task.estado, 'en_proceso');
  } finally { await h.unmount(); }
});

test('el movimiento iniciado impide tomar otra labor, también en tareas antiguas sin maniobra', async () => {
  const next = task({ id: 'next', estado: 'pendiente' }); const h = harness({ offered: [next] });
  try {
    await h.mount(); await h.flush(() => h.props('OperatorTaskHome').onOpen({ source: 'available', task: next }));
    assert.equal(h.calls.takes.length, 0); assert.equal(h.props('OperatorTaskExecution').task.id, 'task-1');
    assert.match(h.message(), /bajo custodia/);
  } finally { await h.unmount(); }
});


test('Una lectura vieja de turnos no reemplaza la configuración más reciente de la tablet', async () => {
  const next = task({ id: 'new-turn-task', estado: 'pendiente' });
  const h = harness({ initial: null, offered: [next] });
  const older = deferred(); const newer = deferred(); let ownReads = 0;
  h.controls.list = (assignment) => assignment === 'mias' ? (++ownReads === 1 ? older.promise : newer.promise) : Promise.resolve([next]);
  await h.mount();
  await h.flush(() => { h.props('OperatorTaskHome').onOpen({ source: 'available', task: next }); });
  const current = { turno: { nombre: 'Turno actual' }, funciones: null, disponible: true, labor_actual: null, en_espera: [] };
  await h.flush(() => newer.resolve(Object.assign([], { labores: current })));
  assert.equal(h.props('OperatorTaskHome').shiftContext.turno.nombre, 'Turno actual');
  await h.flush(() => older.resolve(Object.assign([], { labores: { ...current, turno: { nombre: 'Turno anterior' } } })));
  assert.equal(h.props('OperatorTaskHome').shiftContext.turno.nombre, 'Turno actual');
  await h.unmount();
});
