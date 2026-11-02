import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';
import vm from 'node:vm';
import React from 'react';
import { act, create } from 'react-test-renderer';
import ts from 'typescript';

globalThis.IS_REACT_ACT_ENVIRONMENT = true;
const require = createRequire(import.meta.url);
const source = readFileSync(new URL('../src/screens/FrutaProcesoScreen.tsx', import.meta.url), 'utf8');

async function harness({ availableOrders, cached = false }) {
  const line = { id: 'line-1', codigo: 'L1', nombre: 'Línea 1' };
  const orderRef = (id) => ({ id, codigo: id, version_id: `${id}-v1`, numero_version: 1, bins_pendientes: 10, linea: line });
  const row = { lote_materia_prima_id: 'lot-1', numero_lote: 'LOTE-1', secuencia: 1, bins_programados: 10, bins_entregados: 0, bins_pendientes: 10, cliente: { nombre: 'Cliente' }, camara: null };
  const order = { id: 'OP-A', codigo: 'OP-A', fecha: '2026-11-02', turno_inicio: 'A', linea: line,
    estado: 'programada', numero_version_vigente: 1, progreso: { bins_entregados: 0, bins_programados: 10, bins_pendientes: 10 },
    version_vigente: { id: 'OP-A-v1', numero: 1, lotes: [row] } };
  const lot = { id: 'lot-1', numero_lote: 'LOTE-1', estado: 'disponible_proceso', cliente: { nombre: 'Cliente' },
    producto: { especie: 'Cereza', variedad: 'Santina' }, camara: null, entregas: [],
    progreso: { total: 48, disponibles: 48, entregados: 0, porcentaje: 0 }, ordenes: [orderRef('OP-A')] };
  const calls = { reads: 0, deliveries: [] };
  const api = {
    listProcessOrders: async () => [order],
    getProcessCatalogs: async () => ({ tipos_resultado: [], camaras: [], lineas: [line] }),
    listProcessLots: async () => [lot],
    getProcessSummary: async () => ({ temporada: null }),
    getProcessLot: async () => { calls.reads++; return { ...lot, ordenes: availableOrders.map(orderRef) }; },
    createProcessDelivery: async (...args) => { calls.deliveries.push(args); return lot; },
  };
  const native = Object.fromEntries(['ActivityIndicator', 'Modal', 'Pressable', 'ScrollView', 'Text', 'TextInput', 'View'].map((name) => [name, name]));
  native.StyleSheet = { create: (styles) => styles };
  const module = { exports: {} };
  const compiled = ts.transpileModule(source, {
    compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
  }).outputText;
  vm.runInNewContext(compiled, { module, exports: module.exports, console,
    require: (id) => {
      if (id === 'react-native') return native;
      if (id === 'expo-crypto') return { randomUUID: () => 'operation-1' };
      if (id.endsWith('/services/frutaProcesoApi')) return api;
      if (id.endsWith('/services/apiError')) return { ApiError: class extends Error {} };
      if (id.endsWith('/hooks/useOperationalPolling')) return { useOperationalPolling: () => {} };
      if (id.endsWith('/config/polling')) return { OPERATIONAL_POLL_INTERVAL_MS: 10000 };
      if (id.endsWith('/domain/cameras')) return { cameraDisplayName: () => 'Hidrocooler' };
      if (id.endsWith('/theme/colors')) return { colors: new Proxy({}, { get: () => '#000000' }) };
      return require(id);
    },
  });
  let renderer;
  await act(async () => {
    renderer = create(React.createElement(module.exports.FrutaProcesoScreen, {
      auth: { token: 'token', usuario: { capacidades: { puede_entregar_fruta_proceso: true } } },
      baseUrl: 'http://server', onLogout: () => {},
    }));
  });
  const text = () => renderer.root.findAllByType('Text').map((node) => node.children.filter((child) => typeof child === 'string').join('')).join('\n');
  const press = async (label) => {
    const button = renderer.root.findAllByType('Pressable').find((node) => node.findAllByType('Text').some((child) => child.children.join('') === label));
    assert.ok(button, `Botón ausente: ${label}`);
    await act(async () => { button.props.onPress(); });
  };
  if (cached) {
    await press('Entregas');
    await press('Órdenes');
  }
  return { calls, press, text, root: () => renderer.root, close: async () => act(async () => renderer.unmount()) };
}

for (const cached of [false, true]) {
  test(`una orden que perdió el lote exige elegir de nuevo, sin cambiar a otra${cached ? ' aunque exista una copia local' : ''}`, async () => {
    const app = await harness({ availableOrders: ['OP-B'], cached });
    try {
      await app.press('Entregar');
      assert.equal(app.calls.reads, 1);
      assert.equal(app.root().findByType('Modal').props.visible, false);
      assert.match(app.text(), /La orden seleccionada ya no tiene bins pendientes/);
      assert.equal(app.calls.deliveries.length, 0);
    } finally { await app.close(); }
  });
}

test('conserva la orden tocada aunque el servidor devuelva otra primero', async () => {
  const app = await harness({ availableOrders: ['OP-B', 'OP-A'] });
  try {
    await app.press('Entregar');
    const form = app.root().find((node) => typeof node.type === 'function' && node.type.name === 'DeliveryForm');
    assert.equal(form.props.orderId, 'OP-A');
    assert.equal(form.props.mode, 'orden');
    assert.equal(app.root().findByType('Modal').props.visible, true);
  } finally { await app.close(); }
});
