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

// Ejecuta el componente real con hooks de React; solo sustituye las APIs,
// superficies nativas y pantallas hijas ajenas al comportamiento probado.
function harness({ cameraBusy = true, openConflict = false, completionError = '', rejectPin = false } = {}) {
  let polling;
  let renderer;
  let listError = '';
  let nextTask = {
    id: 'task-1', estado: 'asumida', tipo_movimiento: 'ubicacion_inicial',
    plan: { id: 'plan-1', tipo: 'recepcion_tunel', titulo: 'Recibir túnel', prioridad: 'normal' },
    folio: { id: 'folio-1', numero_folio: '0000000003', tipo_bulto: 'pallet' },
    destino: { camara: { id: 'cam-5', nombre: 'Cámara 5' }, posicion: { id: 'pos-1', etiqueta: 'B01-P01', banda: 1, posicion: 1, nivel: 1 } },
    origen: null, reserva: { tipo_compromiso: 'fisica', vence_at: null },
  };
  const calls = { list: 0, open: 0, start: 0, locate: 0, close: [] };
  const taskApi = {
    list: async (_token, tab) => {
      calls.list++;
      if (listError) throw new Error(listError);
      return tab === 'mias' ? [nextTask] : [];
    },
    currentVerification: async () => null,
    physicalFrontierSnapshot: async () => { throw new Error('Sin simulación local'); },
    pinStatus: async () => ({ configurado: true, bloqueado_hasta: null }),
    start: async () => {
      calls.start++;
      if (rejectPin) throw new ApiError('El PIN no es correcto.', 422);
      nextTask = { ...nextTask, estado: 'en_proceso' };
      return nextTask;
    },
  };
  const cache = new Map();
  const native = {
    ActivityIndicator: 'ActivityIndicator', Modal: 'Modal', Pressable: 'Pressable',
    ScrollView: 'ScrollView', Text: 'Text', View: 'View',
    StyleSheet: { create: (styles) => styles, absoluteFillObject: {} },
    Alert: { alert: () => { throw new Error('No se espera una alerta nativa'); } },
  };
  function load(path) {
    if (cache.has(path)) return cache.get(path).exports;
    const module = { exports: {} };
    cache.set(path, module);
    const compiled = ts.transpileModule(readFileSync(path, 'utf8'), {
      compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
      fileName: path,
    }).outputText;
    const mockRequire = (id) => {
      if (id === 'react-native') return native;
      if (id === 'expo-crypto') return { randomUUID: () => 'operation-1' };
      if (id.endsWith('/hooks/useOperationalPolling')) return { useOperationalPolling: (task, options) => { polling = { task, options }; } };
      if (id.endsWith('/services/operationalTasksApi')) return { OperationalTasksApi: class { constructor() { return taskApi; } } };
      if (id.endsWith('/domain/operatorTaskQueue')) return { buildOperatorTaskHome: (mine, available) => ({ mine, available, next: null }) };
      for (const name of ['OperatorTaskHome', 'OperatorTaskExecution', 'TurnVerification']) {
        if (id.endsWith(`/${name}`)) return { [name]: name };
      }
      if (id.startsWith('.')) {
        const base = resolve(dirname(path), id);
        for (const extension of ['.ts', '.tsx']) {
          try { readFileSync(base + extension); } catch { continue; }
          return load(base + extension);
        }
      }
      return require(id);
    };
    vm.runInNewContext(compiled, {
      require: mockRequire, module, exports: module.exports,
      setInterval: () => 1, clearInterval: () => {}, console, Error,
    }, { filename: path });
    return module.exports;
  }
  const { ApiError } = load(resolve(sourceRoot, 'services/apiError.ts'));
  const api = {
    mode: 'connected', baseUrl: 'http://server:8001',
    getPlan: async () => ({
      id: 'cam-5', nombre: 'Cámara 5', version_plano: 1,
      acceso: cameraBusy
        ? { modo: 'solo_lectura', sesion: { id: 'other-session', es_propia: false, usuario: { nombre: 'Operador de prueba' }, dispositivo: { nombre: 'TABLET-PREFRIO' } } }
        : { modo: 'disponible', sesion: null },
    }),
    openSession: async () => {
      calls.open++;
      if (openConflict) throw new ApiError('La cámara ya está siendo modificada por otra sesión.', 409);
      return { id: 'own-session' };
    },
    closeSession: async (_token, id) => { calls.close.push(id); },
    locate: async () => {
      calls.locate++;
      if (completionError) throw new ApiError(completionError, 409);
    },
  };
  const { OperationalTaskInbox } = load(resolve(sourceRoot, 'components/OperationalTaskInbox.tsx'));
  async function flush(action = () => {}) {
    await act(async () => {
      await action();
      for (let i = 0; i < 20; i++) await Promise.resolve();
    });
  }
  const view = (name) => renderer.root.findByType(name);
  return {
    calls,
    async mount() {
      await flush(() => { renderer = create(React.createElement(OperationalTaskInbox, { api, auth: { token: 'token', dispositivo: { nombre: 'TABLET-02' }, usuario: { nombre: 'Camarero' } } })); });
    },
    async start() {
      await flush(() => view('OperatorTaskHome').props.onOpen({ source: 'mine', task: nextTask }));
      await this.retry();
    },
    async retry() {
      await flush(() => view('OperatorTaskExecution').props.onStart());
      await flush(() => view('OperatorTaskExecution').props.onConfirmStart({ folioDigits: '003', pin: '1234' }));
    },
    async complete() { await flush(() => view('OperatorTaskExecution').props.onComplete()); },
    async refresh() { await flush(() => polling.task()); },
    async backgroundFailure(message) { listError = message; await this.refresh(); },
    async dismiss() { await flush(() => view('Pressable').props.onPress()); },
    async back() { await flush(() => view('Modal').props.onRequestClose()); },
    modal() { return renderer.root.findAllByType('Modal'); },
    message() {
      return renderer.root.findAllByType('Text').map((node) => node.props.children).filter((item) => typeof item === 'string').join('\n');
    },
    state() { return view('OperatorTaskExecution').props.task.estado; },
    confirmation() { return view('OperatorTaskExecution').props.confirmation; },
    releaseCamera() { cameraBusy = false; },
    async unmount() { if (renderer) await flush(() => renderer.unmount()); },
  };
}

test('cámara destino ocupada: el error sobrevive al refresco y solo se cierra con Entendido', async () => {
  const h = harness();
  try {
    await h.mount();
    await h.start();
    assert.equal(h.modal().length, 1);
    assert.match(h.message(), /destino Cámara 5.*Operador de prueba.*TABLET-PREFRIO/s);
    assert.match(h.message(), /cierre su sesión.*vuelve a intentar/s);
    assert.equal(h.state(), 'asumida');
    assert.equal(h.calls.start, 0);
    assert.equal(h.calls.open, 0);
    assert.equal(h.calls.locate, 0);
    assert.deepEqual(h.calls.close, []);
    const original = h.message();
    await h.refresh();
    assert.equal(h.message(), original);
    await h.backgroundFailure('El servidor no responde');
    assert.equal(h.message(), original);
    assert.equal(h.modal().length, 1);
    await h.dismiss();
    assert.equal(h.modal().length, 0);
  } finally { await h.unmount(); }
});

test('conflicto al abrir la cámara después de leer el plano: conserva la explicación y permite cierre con Atrás', async () => {
  const h = harness({ cameraBusy: false, openConflict: true });
  try {
    await h.mount(); await h.start();
    assert.equal(h.calls.open, 1);
    assert.equal(h.calls.start, 0);
    assert.match(h.message(), /destino Cámara 5 está en uso por otra sesión/);
    await h.refresh();
    assert.equal(h.modal().length, 1);
    await h.back();
    assert.equal(h.modal().length, 0);
  } finally { await h.unmount(); }
});

test('error al ubicar: conserva el pallet en movimiento y el texto exacto después de actualizar', async () => {
  const h = harness({ cameraBusy: false, completionError: 'La posición de destino ya está ocupada.' });
  try {
    await h.mount(); await h.start(); await h.complete();
    assert.equal(h.calls.start, 1);
    assert.equal(h.calls.locate, 1);
    assert.equal(h.state(), 'en_proceso');
    assert.match(h.message(), /La posición de destino ya está ocupada\./);
    assert.deepEqual(h.calls.close, ['own-session']);
    await h.refresh();
    assert.equal(h.modal().length, 1);
  } finally { await h.unmount(); }
});

test('una operación exitosa no abre el diálogo de error', async () => {
  const h = harness({ cameraBusy: false });
  try {
    await h.mount(); await h.start();
    assert.equal(h.calls.start, 1);
    assert.equal(h.state(), 'en_proceso');
    assert.equal(h.modal().length, 0);
    await h.complete();
    assert.equal(h.calls.locate, 1);
    assert.equal(h.modal().length, 0);
  } finally { await h.unmount(); }
});

test('después de leer el aviso y liberar la cámara, el operador puede volver a intentar', async () => {
  const h = harness();
  try {
    await h.mount(); await h.start(); await h.dismiss();
    h.releaseCamera();
    await h.retry();
    assert.equal(h.modal().length, 0);
    assert.equal(h.calls.open, 1);
    assert.equal(h.calls.start, 1);
    assert.equal(h.state(), 'en_proceso');
  } finally { await h.unmount(); }
});

test('PIN rechazado permanece en su confirmación y conserva el flujo de corrección', async () => {
  const h = harness({ cameraBusy: false, rejectPin: true });
  try {
    await h.mount(); await h.start();
    assert.equal(h.modal().length, 0);
    assert.equal(h.state(), 'asumida');
    assert.equal(h.confirmation().error, 'El PIN no es correcto.');
    assert.deepEqual(h.calls.close, ['own-session']);
  } finally { await h.unmount(); }
});

test('el diálogo conserva completo un error largo y ofrece desplazamiento para leerlo', async () => {
  const message = 'La operación fue rechazada. '.repeat(100);
  const h = harness({ cameraBusy: false, completionError: message });
  try {
    await h.mount(); await h.start(); await h.complete();
    const texts = h.modal()[0].findAllByType('Text');
    const errorText = texts.find((node) => node.props.children === message);
    assert.ok(errorText);
    assert.equal(errorText.props.numberOfLines, undefined);
    assert.equal(h.modal()[0].findAllByType('ScrollView').length, 1);
  } finally { await h.unmount(); }
});
