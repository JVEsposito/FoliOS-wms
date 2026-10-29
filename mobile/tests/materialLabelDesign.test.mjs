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
const root = resolve(dirname(fileURLToPath(import.meta.url)), '../src');
const flatten = value => Array.isArray(value) ? value.map(flatten).join('') : value?.props ? flatten(value.props.children) : String(value ?? '');

async function harness({ size = 200, transformation = false, failOnce = false, oldServer = false } = {}) {
  const calls = []; let renderer; let sequence = 0;
  const file = resolve(root, 'components/MaterialLabelPrintPanel.tsx');
  const compiled = ts.transpileModule(readFileSync(file, 'utf8'), {
    compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 }, fileName: file,
  }).outputText;
  const module = { exports: {} };
  vm.runInNewContext(compiled, { module, exports: module.exports, console, Date, Number, Error, Set,
    require: id => id === 'react-native' ? { ActivityIndicator: 'ActivityIndicator', Pressable: 'Pressable', Text: 'Text', TextInput: 'TextInput', View: 'View', StyleSheet: { create: s => s } }
      : id === 'expo-crypto' ? { randomUUID: () => `uuid-${++sequence}` }
        : id.endsWith('/estiba-printer-socket') ? { isDirectPrinterAvailable: () => true, sendToPrinter: async () => ({ status: 'sent', bytesSent: 80 }) }
          : id.endsWith('/printerConfiguration') ? { loadPrinterConfiguration: async () => ({ name: 'Bodega', host: '192.168.1.50', port: 9100, profileId: 'printer' }), savePrinterConfiguration: async (_id, c) => c, validatePrinterConfiguration: c => c }
            : id.endsWith('/theme/colors') ? { colors: {} } : require(id),
  });
  const api = { printProfiles: async () => [{ id: 'printer', nombre: 'Bodega', fabricante: 'Bixolon', ancho_mm: 100, alto_mm: size, dpi: 203, ...(oldServer ? {} : { disenos_disponibles: ['actual', 'clasica_bodega'] }) }], printJobs: async () => [],
    generateLabels: async (...args) => { const payload = args.at(-1); calls.push(JSON.parse(JSON.stringify(payload))); if (failOnce && calls.length === 1) throw new Error('Sin conexión'); return { jobId: 'job', zpl: '^XA^XZ' }; },
    reportPrintOutcome: async () => undefined };
  const reception = { id: 'reception', numero_guia_despacho: '697996', detalles: [{ item: { codigo: '020019', nombre: 'FONDO' }, unidad_medida: 'UN', bultos: [{ cantidad: 1500, folio: { id: 'folio', numero_folio: 'FAG000001' } }] }] };
  const props = transformation ? { sourceApi: api, sourceId: 'order', sourceLabel: 'orden', sourceFolios: [{ id: 'folio', number: 'AR000001', item: 'ARMADO', quantity: '100 UN' }] }
    : { api, reception };
  await act(async () => { renderer = create(React.createElement(module.exports.MaterialLabelPrintPanel, { deviceId: 'tablet', ...props })); });
  const button = label => renderer.root.findAllByType('Pressable').find(p => p.findAllByType('Text').some(t => flatten(t.props.children) === label));
  return { calls, button, get text() { return renderer.root.findAllByType('Text').map(t => flatten(t.props.children)).join(' '); },
    async press(label) { const target = button(label); assert.ok(target, label); assert.ok(!target.props.disabled, label); await act(async () => { await target.props.onPress(); }); },
    async close() { await act(async () => renderer.unmount()); } };
}

test('tablet/PDA conserva Actual al abrir y envía Clásica solo al elegirla', async () => {
  const h = await harness();
  try {
    assert.equal(h.button('Actual').props.accessibilityState.checked, true);
    await h.press('Imprimir 1 folio(s)');
    assert.equal(h.calls[0].diseno, undefined);
    await h.press('Clásica bodega · cantidad destacada');
    await h.press('Imprimir 1 folio(s)');
    assert.equal(h.calls[1].diseno, 'clasica_bodega');
    assert.equal(h.calls[1].formato, 'zpl');
    assert.equal(h.calls[1].canal, 'pda_directa');
    assert.deepEqual(h.calls[1].folio_ids, ['folio']);
    assert.notEqual(h.calls[0].operacion_id, h.calls[1].operacion_id);
  } finally { await h.close(); }
});

test('Clásica se deshabilita para otro tamaño y no aparece en armado', async () => {
  const small = await harness({ size: 50 });
  try {
    assert.equal(small.button('Clásica bodega · cantidad destacada').props.disabled, true);
    await small.press('Imprimir 1 folio(s)');
    assert.equal(small.calls[0].diseno, undefined);
  } finally { await small.close(); }
  const order = await harness({ transformation: true });
  try {
    assert.equal(order.button('Clásica bodega · cantidad destacada'), undefined);
    await order.press('Imprimir 1 folio(s)');
    assert.equal(order.calls[0].diseno, undefined);
  } finally { await order.close(); }
});

test('un reintento de generación conserva el UUID y el diseño elegido', async () => {
  const h = await harness({ failOnce: true });
  try {
    await h.press('Clásica bodega · cantidad destacada');
    await h.press('Imprimir 1 folio(s)');
    assert.match(h.text, /Sin conexión/);
    await h.press('Imprimir 1 folio(s)');
    assert.equal(h.calls[0].operacion_id, h.calls[1].operacion_id);
    assert.equal(h.calls[1].diseno, 'clasica_bodega');
  } finally { await h.close(); }
});

test('una OTA nueva conectada a un servidor antiguo no ofrece un diseño que este ignoraría', async () => {
  const h = await harness({ oldServer: true });
  try {
    assert.equal(h.button('Clásica bodega · cantidad destacada').props.disabled, true);
    await h.press('Imprimir 1 folio(s)');
    assert.equal(h.calls[0].diseno, undefined);
  } finally { await h.close(); }
});
