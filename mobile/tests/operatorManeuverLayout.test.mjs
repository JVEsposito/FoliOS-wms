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
const flatten = (style) => Object.assign({}, ...[style].flat(Infinity).filter(Boolean));

function task(overrides = {}) {
  return {
    id: 'task-1', estado: 'asumida', tipo_movimiento: 'ubicacion_inicial',
    plan: { tipo: 'recepcion_tunel', prioridad: 'normal' },
    folio: { numero_folio: '2610045902', tipo_bulto: 'pallet' },
    origen: { camara: { nombre: 'Cámara 5' }, posicion: { etiqueta: 'B01-P11-N1', banda: 1, posicion: 11, nivel: 1 } },
    destino: { camara: { nombre: 'Cámara 3' }, posicion: { etiqueta: 'B01-P04-N1', banda: 1, posicion: 4, nivel: 1 } },
    reserva: { tipo_compromiso: 'fisica' },
    maniobra: { id: 'maneuver-1', titulo: 'Recibir túnel', pasos_totales: 2, secuencia_actual: 1, pasos: [], custodias_temporales: [] },
    ...overrides,
  };
}

function harness(width, initial = {}) {
  const cache = new Map();
  const calls = [];
  const native = {
    Platform: { OS: 'android' }, Pressable: 'Pressable', Text: 'Text', View: 'View', ScrollView: 'ScrollView',
    StyleSheet: { create: (styles) => styles },
    useWindowDimensions: () => ({ width, height: 800, scale: 1, fontScale: 1 }),
  };
  function load(path) {
    if (cache.has(path)) return cache.get(path).exports;
    const module = { exports: {} };
    cache.set(path, module);
    const code = ts.transpileModule(readFileSync(path, 'utf8'), {
      compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
      fileName: path,
    }).outputText;
    const mockRequire = (id) => {
      if (id === 'react-native') return native;
      for (const name of ['OperatorFolioConfirmation', 'OperatorExceptionReport', 'OperatorPhysicalContextPanel']) {
        if (id.endsWith('/' + name)) return { [name]: name };
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
    vm.runInNewContext(code, { require: mockRequire, module, exports: module.exports, console }, { filename: path });
    return module.exports;
  }
  const { OperatorTaskExecution } = load(resolve(sourceRoot, 'components/operator/OperatorTaskExecution.tsx'));
  let renderer;
  let props = {
    task: task(), busy: false, confirmation: null, hasPhysicalDestination: true, leaseExpired: false,
    operatorName: 'Operador', deviceName: 'TABLET-PRUEBAS', secondsRemaining: 120,
    ...Object.fromEntries(['onBack', 'onStart', 'onComplete', 'onCompleteDirect', 'onCompleteTemporary', 'onRelease', 'onRecalculate', 'onCancelConfirmation', 'onConfirmStart', 'onCreatePin', 'onReportException']
      .map((name) => [name, (...args) => calls.push({ name, args })])),
    ...initial,
  };
  const text = (node) => node.findAllByType('Text').map((child) => [child.props.children].flat().filter((v) => typeof v === 'string' || typeof v === 'number').join('')).join(' ');
  async function flush(action) { await act(async () => { await action(); }); }
  return {
    calls,
    async mount() { await flush(() => { renderer = create(React.createElement(OperatorTaskExecution, props)); }); },
    async update(changes) { props = { ...props, ...changes }; await flush(() => renderer.update(React.createElement(OperatorTaskExecution, props))); },
    async press(label) { const button = this.button(label); assert.equal(Boolean(button.props.disabled), false); await flush(() => button.props.onPress()); },
    button(label) { return renderer.root.findAllByType('Pressable').find((button) => button.findAllByType('Text').some((child) => [child.props.children].flat().filter((value) => typeof value === 'string').join('') === label)); },
    find(type) { return renderer.root.findAllByType(type); },
    wide() { return renderer.root.findAll((node) => node.type === 'View' && node.props.testID === 'maneuver-wide-columns'); },
    message() { return text(renderer.root); },
    async unmount() { if (renderer) await flush(() => renderer.unmount()); },
  };
}

test('maniobra: dos columnas desde 1000 dp y misma acción de retiro en los tres anchos', async () => {
  for (const width of [999, 1000, 1280]) {
    const h = harness(width);
    try {
      await h.mount();
      assert.equal(h.wide().length, width >= 1000 ? 1 : 0);
      assert.match(h.message(), /2610045902/);
      if (width >= 1000) {
        assert.equal(flatten(h.wide()[0].props.style).flexDirection, 'row');
        assert.equal(flatten(h.button('RETIRAR PALLET').props.style).minHeight, 76);
      }
      await h.press('RETIRAR PALLET');
      assert.deepEqual(h.calls.map((call) => call.name), ['onStart']);
    } finally { await h.unmount(); }
  }
});

test('maniobra: movimiento, extracción temporal y entrega mantienen sus callbacks y textos', async () => {
  for (const width of [360, 1280]) {
    const h = harness(width);
    try {
      await h.mount();
      await h.update({ task: task({ estado: 'en_proceso' }) });
      await h.press('CONFIRMAR MOVIMIENTO');
      await h.update({ task: task({ estado: 'en_proceso', tipo_movimiento: 'retiro', tipo_paso_maniobra: 'extraccion_temporal' }) });
      await h.press('CONFIRMAR PALLET RETIRADO');
      await h.update({ task: task({ estado: 'en_proceso', tipo_movimiento: 'retiro', tipo_paso_maniobra: 'entrega_directa' }) });
      await h.press('CONFIRMAR ENTREGA EN ANDÉN');
      assert.deepEqual(h.calls.map((call) => call.name), ['onComplete', 'onCompleteTemporary', 'onCompleteDirect']);
    } finally { await h.unmount(); }
  }
});

test('maniobra: busy, reserva vencida y destino pendiente mantienen la acción bloqueada', async () => {
  for (const width of [360, 1280]) {
    const h = harness(width);
    try {
      await h.mount();
      for (const changes of [{ busy: true }, { busy: false, leaseExpired: true }, { leaseExpired: false, hasPhysicalDestination: false }]) {
        await h.update(changes);
        assert.equal(h.button('RETIRAR PALLET').props.disabled, true);
      }
      assert.match(h.message(), /Destino físico pendiente/);
      assert.deepEqual(h.calls, []);
    } finally { await h.unmount(); }
  }
});

test('maniobra: confirmación folio/PIN y pausa siguen sustituyendo la ejecución', async () => {
  const h = harness(1280, { confirmation: { pin: '', digits: '' } });
  try {
    await h.mount();
    assert.equal(h.find('OperatorFolioConfirmation').length, 1);
    assert.equal(h.wide().length, 0);
    await h.update({ confirmation: null, task: task({ maniobra: { id: 'm1', estado: 'pausada_discrepancia', pasos_totales: 2, custodias_temporales: [] } }) });
    assert.match(h.message(), /NO MUEVAS EL PALLET/);
    assert.equal(h.wide().length, 0);
    assert.deepEqual(h.calls, []);
  } finally { await h.unmount(); }
});

test('maniobra: excepciones abren el mismo formulario y liberar conserva su callback', async () => {
  const h = harness(1280);
  try {
    await h.mount();
    await h.press('NO COINCIDE');
    assert.equal(h.find('OperatorExceptionReport')[0].props.kind, 'mismatch');
    await act(async () => h.find('OperatorExceptionReport')[0].props.onCancel());
    await h.press('NO ES POSIBLE');
    assert.equal(h.find('OperatorExceptionReport')[0].props.kind, 'impossible');
    await act(async () => h.find('OperatorExceptionReport')[0].props.onCancel());
    await h.press('Liberar antes de iniciar');
    assert.deepEqual(h.calls.map((call) => call.name), ['onRelease']);
  } finally { await h.unmount(); }
});
