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
const addLine = '+ Agregar línea (otro CSG, lote o proceso)';

function harness() {
  let renderer; let sequence = 0;
  const queued = [];
  const catalog = {
    temporada: { id: 'season', nombre: '2026', version_catalogo: 1 },
    categorias: [{ id: 'category', nombre: 'Exportación', activo: true }],
    articulos: [
      { id: 'santina', especie: 'Cereza', variedad: 'Santina', calibre: '2J', envase: '5 kg', variedad_validacion_id: 'v1', activo: true },
      { id: 'lapins', especie: 'Cereza', variedad: 'Lapins', calibre: '2J', envase: '5 kg', variedad_validacion_id: 'v2', activo: true },
    ],
    origenes: [
      { id: 'csg-1', cliente: 'Cliente', marca: 'Marca', csg: '11111', variedad_ids: ['v1'], activo: true },
      { id: 'csg-2', cliente: 'Cliente', marca: 'Marca', csg: '22222', variedad_ids: ['v1'], activo: true },
      { id: 'csg-3', cliente: 'Cliente', marca: 'Marca', csg: '33333', variedad_ids: ['v2'], activo: true },
    ],
    combinaciones: [
      { origen_validacion_id: 'csg-1', articulo_validacion_id: 'santina' },
      { origen_validacion_id: 'csg-2', articulo_validacion_id: 'santina' },
      { origen_validacion_id: 'csg-3', articulo_validacion_id: 'lapins' },
    ],
    motivos: [],
  };
  const cache = new Map();
  const native = {
    ActivityIndicator: 'ActivityIndicator', Image: 'Image', Pressable: 'Pressable', ScrollView: 'ScrollView',
    Text: 'Text', TextInput: 'TextInput', View: 'View', StyleSheet: { create: (styles) => styles },
    useWindowDimensions: () => ({ width: 360, height: 720 }),
    Alert: { alert: () => {} },
    FlatList: ({ data, renderItem, ListEmptyComponent }) => React.createElement('FlatList', null,
      ...data.map((item) => React.createElement('Row', { key: item.value }, renderItem({ item }))),
      !data.length && ListEmptyComponent),
    Modal: ({ visible, children }) => visible ? React.createElement('Modal', null, children) : null,
  };
  function load(path) {
    if (cache.has(path)) return cache.get(path).exports;
    const module = { exports: {} }; cache.set(path, module);
    const code = ts.transpileModule(readFileSync(path, 'utf8'), {
      compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 }, fileName: path,
    }).outputText;
    const mockRequire = (id) => {
      if (id === 'react-native') return native;
      if (id === 'expo-crypto') return { randomUUID: () => `operation-${++sequence}` };
      if (id.endsWith('/hooks/useOperationalPolling')) return { useOperationalPolling: () => {} };
      if (id.endsWith('/config/appVariant')) return { isPdaBuild: true };
      if (id.endsWith('/theme/areas')) return { areas: { frigorifico: { gajo: 1, bright: '#000' } } };
      if (id.endsWith('/components/ui/ScanInput')) return { ScanInput: 'ScanInput' };
      if (id.endsWith('/services/validationApi')) return {};
      if (id.endsWith('/services/validationOfflineStore')) return {
        loadCachedValidationCatalog: async () => catalog,
        loadCachedValidationSession: async () => null,
        loadValidationOutbox: async () => [],
        loadValidationWorkContext: async () => ({ linea_proceso: 1, turno: 'A' }),
        enqueueValidation: async (user, device, payload) => { queued.push(JSON.parse(JSON.stringify(payload))); return []; },
      };
      if (id.startsWith('.')) {
        const base = resolve(dirname(path), id);
        for (const ext of ['.ts', '.tsx']) {
          try { readFileSync(base + ext); } catch { continue; }
          return load(base + ext);
        }
      }
      return require(id);
    };
    vm.runInNewContext(code, { require: mockRequire, module, exports: module.exports, console, setTimeout: () => 0 }, { filename: path });
    return module.exports;
  }
  const { ValidationScreen } = load(resolve(sourceRoot, 'screens/ValidationScreen.tsx'));
  const auth = { token: 'token', usuario: { id: 1, nombre: 'Validador', capacidades: {} }, dispositivo: { id: 'pda', codigo: 'PDA' } };
  const flatten = (value) => Array.isArray(value) ? value.map(flatten).join('') : typeof value === 'string' || typeof value === 'number' ? String(value) : value?.props ? flatten(value.props.children) : '';
  async function flush(action) { await act(async () => { await action(); }); }
  const field = (label) => renderer.root.find((node) => typeof node.type === 'function' && node.props.label === label);
  return {
    queued,
    async mount() { await flush(() => { renderer = create(React.createElement(ValidationScreen, { auth, baseUrl: null, onLogout: () => {} })); }); },
    async unmount() { await flush(() => renderer.unmount()); },
    field,
    async press(label) {
      const button = renderer.root.findAllByType('Pressable').find((node) => node.findAllByType('Text').some((text) => flatten(text.props.children) === label));
      assert.ok(button, `Botón disponible: ${label}`);
      assert.ok(!button.props.disabled, `Botón habilitado: ${label}`);
      await flush(button.props.onPress);
    },
    async select(label, option) {
      const button = field(label).findAllByType('Pressable')[0];
      assert.ok(!button.props.disabled, `Selector habilitado: ${label}`);
      await flush(button.props.onPress);
      const modal = renderer.root.findByType('Modal');
      const choice = modal.findAllByType('Pressable').find((node) => node.findAllByType('Text').some((text) => flatten(text.props.children) === option));
      assert.ok(choice, `Opción disponible en ${label}: ${option}`);
      await flush(choice.props.onPress);
    },
    message() { return renderer.root.findAllByType('Text').map((node) => flatten(node.props.children)).join('\n'); },
    captureValues() { return renderer.root.findAllByType('TextInput').filter((node) => ['0', 'Según etiqueta'].includes(node.props.placeholder)).map((node) => node.props.value); },
    async product() {
      await this.select('Cliente', 'Cliente'); await this.select('Marca', 'Marca'); await this.select('CSG / Predio 1', '11111');
      await this.select('Especie', 'Cereza'); await this.select('Variedad', 'Santina'); await this.select('Calibre', '2J');
      await this.select('Envase', '5 kg'); await this.select('Categoría', 'Exportación');
    },
    async capture() {
      await flush(() => renderer.root.findByType('ScanInput').props.onChangeText('2610160001'));
      const boxes = () => renderer.root.findAllByType('TextInput').filter((node) => node.props.placeholder === '0');
      await flush(() => boxes()[0].props.onChangeText('120'));
      await flush(() => boxes()[1].props.onChangeText('70'));
      await flush(() => boxes()[2].props.onChangeText('50'));
      const trace = () => renderer.root.findAllByType('TextInput').filter((node) => node.props.placeholder === 'Según etiqueta');
      for (const [index, value] of ['L-1', 'P-1', 'L-2', 'P-2'].entries()) await flush(() => trace()[index].props.onChangeText(value));
    },
  };
}

function assertProduct(h) {
  for (const [label, value] of [['Especie', 'Cereza'], ['Variedad', 'Santina'], ['Calibre', '2J'], ['Envase', '5 kg'], ['Categoría', 'category']]) {
    assert.equal(h.field(label).props.value, value, `Conserva ${label}`);
    assert.ok(h.field(label).props.options.some((option) => option.value === value), `Opciones disponibles: ${label}`);
  }
}

test('PDA conserva producto y categoría con una fila vacía y con dos CSG compatibles', async () => {
  const h = harness();
  try {
    await h.mount(); await h.product(); await h.press(addLine); assertProduct(h);
    await h.select('CSG / Predio 2', '22222'); assertProduct(h);
    await h.capture(); await h.press('APROBAR');
    assert.equal(h.queued.length, 1);
    assert.equal(h.queued[0].articulo_validacion_id, 'santina');
    assert.deepEqual(h.queued[0].composicion, [
      { origen_validacion_id: 'csg-1', cantidad_cajas: 70, lote_materia_prima: 'L-1', proceso_packing: 'P-1' },
      { origen_validacion_id: 'csg-2', cantidad_cajas: 50, lote_materia_prima: 'L-2', proceso_packing: 'P-2' },
    ]);
  } finally { await h.unmount(); }
});

test('PDA avisa al elegir CSG sin producto común y conserva la captura para corregirlo', async () => {
  const h = harness();
  try {
    await h.mount(); await h.product(); await h.press(addLine);
    await h.capture(); const captured = h.captureValues();
    await h.select('CSG / Predio 2', '33333');
    assertProduct(h);
    assert.deepEqual(h.captureValues(), captured);
    assert.equal(h.field('CSG / Predio 2').props.value, '');
    assert.match(h.message(), /11111.*33333.*no tienen un producto habilitado en común/);
    await h.select('CSG / Predio 2', '22222'); assertProduct(h);
    assert.doesNotMatch(h.message(), /no tienen un producto habilitado en común/);
    await h.press('APROBAR');
    assert.equal(h.queued.length, 1);
  } finally { await h.unmount(); }
});

test('PDA admite tres líneas y repetir CSG para otro lote, sin vaciar el producto al cambiar o quitar líneas', async () => {
  const h = harness();
  try {
    await h.mount(); await h.product(); await h.press(addLine); await h.select('CSG / Predio 2', '22222');
    await h.press(addLine); assertProduct(h);
    await h.select('CSG / Predio 3', '11111'); assertProduct(h);
    await h.select('CSG / Predio 3', '33333'); assertProduct(h);
    assert.equal(h.field('CSG / Predio 3').props.value, 'csg-1');
    assert.match(h.message(), /no tienen un producto habilitado en común/);
    await h.press('Quitar'); assertProduct(h);
    assert.equal(h.field('CSG / Predio 1').props.value, 'csg-2');
    assert.equal(h.field('CSG / Predio 2').props.value, 'csg-1');
    assert.doesNotMatch(h.message(), /no tienen un producto habilitado en común/);
    await h.capture(); await h.press('APROBAR');
    assert.equal(h.queued.length, 1);
    assert.deepEqual(h.queued[0].composicion.map((line) => line.origen_validacion_id), ['csg-2', 'csg-1']);
  } finally { await h.unmount(); }
});

test('PDA mantiene opciones aunque se elijan los CSG antes del producto y permite cambiar el origen inicial', async () => {
  const h = harness();
  try {
    await h.mount(); await h.select('Cliente', 'Cliente'); await h.select('Marca', 'Marca');
    await h.select('CSG / Predio 1', '11111'); await h.press(addLine); await h.select('CSG / Predio 2', '33333');
    assert.equal(h.field('CSG / Predio 2').props.value, '');
    assert.deepEqual(Array.from(h.field('Especie').props.options, (option) => option.value), ['Cereza']);
    assert.deepEqual(Array.from(h.field('Variedad').props.options, (option) => option.value), ['Santina']);
    await h.select('CSG / Predio 1', '33333');
    await h.select('CSG / Predio 2', '33333');
    await h.select('Especie', 'Cereza'); await h.select('Variedad', 'Lapins');
    await h.select('Calibre', '2J'); await h.select('Envase', '5 kg');
    assert.equal(h.field('Variedad').props.value, 'Lapins');
    assert.doesNotMatch(h.message(), /no tienen un producto habilitado en común/);
  } finally { await h.unmount(); }
});
