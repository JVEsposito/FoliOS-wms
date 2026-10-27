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

function harness(prefills = {}) {
  let renderer; let sequence = 0;
  const queued = []; const registered = []; const prefillRequests = [];
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
      if (id.endsWith('/services/validationApi')) return {
        getValidationCatalog: async () => catalog,
        listRecentValidations: async () => [],
        getMyValidationSession: async () => session,
        listValidationsByFolio: async () => [],
        getValidationPrefill: async (url, token, folio) => { prefillRequests.push(folio); return prefills[folio] ?? { disponible: false, motivo: 'El sistema de planta no tiene este pallet en bodega.' }; },
        registerValidation: async (url, token, payload) => { registered.push(JSON.parse(JSON.stringify(payload))); return attempt(payload); },
      };
      if (id.endsWith('/services/validationOfflineStore')) return {
        loadCachedValidationCatalog: async () => catalog,
        loadCachedValidationSession: async () => null,
        loadValidationOutbox: async () => [],
        loadValidationWorkContext: async () => ({ linea_proceso: 1, turno: 'A' }),
        enqueueValidation: async (user, device, payload) => { queued.push(JSON.parse(JSON.stringify(payload))); return []; },
        removeValidationFromOutbox: async () => [],
        markValidationOutboxItem: async () => [],
        saveValidationCatalog: async () => {},
        saveValidationSession: async () => {},
        saveValidationWorkContext: async () => {},
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
  const session = { sesion: { id: 's', iniciada_at: '2026-10-27T10:00:00Z', servidor_at: '2026-10-27T10:00:00Z', usuario: { id: '1', nombre: 'Validador' }, dispositivo: { id: 'pda', codigo: 'PDA', nombre: 'PDA' }, temporada: null },
    resumen: { folios_trabajados: 0, registros_realizados: 0, aprobados: 0, observados: 0, rechazados: 0, conflictos: 0 }, data: [], meta: { current_page: 1, last_page: 1, per_page: 10, total: 0 } };
  const attempt = (payload) => ({ id: `attempt-${payload.operacion_id}`, operacion_id: payload.operacion_id, numero_folio: payload.numero_folio, numero_intento: 1, tipo_bulto: payload.tipo_bulto,
    cantidad_cajas: payload.cantidad_cajas, linea_proceso: 1, turno: 'A', temporada_id: 'season', articulo_validacion_id: payload.articulo_validacion_id, origen_validacion_id: payload.origen_validacion_id,
    categoria_validacion_id: payload.categoria_validacion_id, resultado: payload.resultado, estado: 'aceptada', motivo: null, observacion: null, catalogo: {}, folio: null,
    usuario: { id: '1', nombre: 'Validador' }, dispositivo: { id: 'pda', codigo: 'PDA', nombre: 'PDA' }, conflicto_con: null, generado_dispositivo_at: payload.generado_dispositivo_at, recibido_servidor_at: payload.generado_dispositivo_at });
  return {
    queued, registered, prefillRequests,
    async scan(folio) { await flush(() => renderer.root.findByType('ScanInput').props.onSubmit(folio)); },
    boxes() { return renderer.root.findAllByType('TextInput').filter((node) => node.props.placeholder === '0'); },
    traceValues() { return renderer.root.findAllByType('TextInput').filter((node) => node.props.placeholder === 'Según etiqueta').map((node) => node.props.value); },
    async type(node, value) { await flush(() => node.props.onChangeText(value)); },
    async mount() { await flush(() => { renderer = create(React.createElement(ValidationScreen, { auth, baseUrl: 'https://server.test', onLogout: () => {} })); }); },
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


const planta = (cambios = {}) => ({
  disponible: true, numero_folio: '2610160001', huella: 'a'.repeat(64), leido_at: '2026-10-27T13:00:00Z', desactualizada: false,
  tipo_bulto: 'saldo', cantidad_cajas: 120, fecha_embalaje: '2026-10-07', articulo_validacion_id: 'santina', categoria_validacion_id: 'category',
  composicion: [
    { csg: '11111', predio: null, origen_validacion_id: 'csg-1', cantidad_cajas: 70, lote_materia_prima: 'L-1', proceso_packing: 'P-1' },
    { csg: '22222', predio: null, origen_validacion_id: 'csg-2', cantidad_cajas: 50, lote_materia_prima: 'L-2', proceso_packing: 'P-2' },
  ],
  origen: { cliente: 'Cliente', marca: 'Marca', especie: 'CEREZAS', variedad: 'SANTINA', calibre: '2J', envase: '5 kg', categoria: 'C1' },
  pendientes: [],
  ...cambios,
});

test('al ingresar el folio la PDA replica los datos de planta y el validador solo confirma', async () => {
  const h = harness({ 2610160001: planta() });
  try {
    await h.mount(); await h.scan('2610160001');
    assert.deepEqual(h.prefillRequests, ['2610160001']);
    for (const [label, value] of [['Cliente', 'Cliente'], ['Marca', 'Marca'], ['CSG / Predio 1', 'csg-1'], ['CSG / Predio 2', 'csg-2'],
      ['Especie', 'Cereza'], ['Variedad', 'Santina'], ['Calibre', '2J'], ['Envase', '5 kg'], ['Categoría', 'category']]) {
      assert.equal(h.field(label).props.value, value, `Precarga ${label}`);
    }
    assert.deepEqual(h.boxes().map((node) => node.props.value), ['120', '70', '50']);
    assert.deepEqual(h.traceValues(), ['L-1', 'P-1', 'L-2', 'P-2']);
    assert.match(h.message(), /Datos de planta cargados para 2610160001/);
    assert.match(h.message(), /120 cajas · saldo/);
    assert.match(h.message(), /2 líneas · CSG 11111, 22222/);
    assert.match(h.message(), /Confirma que el pallet coincide o corrige lo que no/);
    await h.press('Aprobar');
    assert.equal(h.registered.length, 1);
    assert.deepEqual(h.registered[0].precarga_origen, { huella: 'a'.repeat(64), modificados: [] });
    assert.equal(h.registered[0].tipo_bulto, 'saldo');
    assert.equal(h.registered[0].fecha_embalaje, '2026-10-07');
    assert.deepEqual(h.registered[0].composicion, [
      { origen_validacion_id: 'csg-1', cantidad_cajas: 70, lote_materia_prima: 'L-1', proceso_packing: 'P-1' },
      { origen_validacion_id: 'csg-2', cantidad_cajas: 50, lote_materia_prima: 'L-2', proceso_packing: 'P-2' },
    ]);
  } finally { await h.unmount(); }
});

test('lo que el validador corrige queda informado en pantalla y en el registro', async () => {
  const h = harness({ 2610160001: planta() });
  try {
    await h.mount(); await h.scan('2610160001');
    const [total] = h.boxes();
    await h.type(total, '118'); await h.type(h.boxes()[1], '68');
    assert.match(h.message(), /Corregido respecto de planta: cajas, composición\./);
    await h.press('Aprobar');
    assert.deepEqual(h.registered[0].precarga_origen.modificados, ['cantidad_cajas', 'composicion']);
  } finally { await h.unmount(); }
});

test('un CSG que el catálogo no reconoce queda para completar sin contar como corrección', async () => {
  const sinOrigen = planta({
    composicion: [
      { csg: '11111', predio: null, origen_validacion_id: 'csg-1', cantidad_cajas: 70, lote_materia_prima: 'L-1', proceso_packing: 'P-1' },
      { csg: '99999', predio: null, origen_validacion_id: null, cantidad_cajas: 50, lote_materia_prima: 'L-2', proceso_packing: 'P-2' },
    ],
    pendientes: ['CSG sin equivalencia en el catálogo de validación.'],
  });
  const h = harness({ 2610160001: sinOrigen });
  try {
    await h.mount(); await h.scan('2610160001');
    assert.equal(h.field('CSG / Predio 2').props.value, '');
    assert.match(h.message(), /Completar: CSG sin equivalencia en el catálogo de validación\./);
    await h.press('Aprobar');
    assert.equal(h.registered.length, 0, 'Falta elegir el CSG de la segunda línea');
    await h.select('CSG / Predio 2', '22222');
    await h.press('Aprobar');
    assert.deepEqual(h.registered[0].precarga_origen.modificados, []);
  } finally { await h.unmount(); }
});

test('sin datos de planta el folio se valida como siempre y la PDA dice por qué', async () => {
  const h = harness();
  try {
    await h.mount(); await h.scan('2610160009');
    assert.match(h.message(), /2610160009 no posee validaciones anteriores en la temporada activa\. El sistema de planta no tiene este pallet en bodega\./);
    assert.equal(h.field('Cliente').props.value, '');
    assert.doesNotMatch(h.message(), /Datos de planta/);
  } finally { await h.unmount(); }
});
