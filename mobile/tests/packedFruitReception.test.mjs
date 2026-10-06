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
function harness({ failAfterCommit = false, duplicateGuide = false } = {}) {
  const storage = new Map(); let renderer; let committed = null; let sequence = 0;
  const calls = { saves: [], scans: [], alerts: [] };
  const catalog = {
    articulos: [{ id: 'article', especie: 'UVA', variedad: 'THOMPSON', variedad_validacion_id: 'variety', envase: '8.2 KG', calibre: 'L', activo: true }],
    origenes: [{ id: 'origin', cliente_validacion_id: 'catalog-client', csg: '12345', predio: 'Rengo', marca: 'Marca', variedad_ids: ['variety'], activo: true }, { id: 'other-origin', cliente_validacion_id: 'other-client', csg: '99999', marca: 'Otro', variedad_ids: ['variety'], activo: true }],
    combinaciones: [{ origen_validacion_id: 'origin', articulo_validacion_id: 'article' }],
  };
  const options = { temporada: { id: 'season', nombre: '2026' }, clientes: [{ id: 'client', nombre: 'Cliente', catalogo_validacion_ids: ['catalog-client'] }], plantas_origen: [{ id: 'plant', nombre: 'Planta' }], condiciones_sag: [{ id: 'sag', nombre: 'Aprobado' }], validadores: [{ id: 1, name: 'Validador' }] };
  const cache = new Map();
  const native = {
    ActivityIndicator: 'ActivityIndicator', Pressable: 'Pressable', Text: 'Text', View: 'View', TextInput: 'TextInput',
    StyleSheet: { create: (s) => s },
    FlatList: ({ data, renderItem, ListHeaderComponent, ListFooterComponent, ListEmptyComponent }) => React.createElement('FlatList', null, ListHeaderComponent, ...data.map((item, index) => React.createElement('Row', { key: item.id ?? index }, renderItem({ item, index }))), ListFooterComponent, !data.length && ListEmptyComponent),
    Modal: ({ visible, children }) => visible ? React.createElement('Modal', null, children) : null,
    Alert: { alert: (title, text, buttons) => { calls.alerts.push({ title, text }); buttons.find((b) => b.text === 'Continuar')?.onPress(); } },
  };
  function load(path) {
    if (cache.has(path)) return cache.get(path).exports;
    const module = { exports: {} }; cache.set(path, module);
    const compiled = ts.transpileModule(readFileSync(path, 'utf8'), { compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 }, fileName: path }).outputText;
    const mockRequire = (id) => {
      if (id === 'react-native') return native;
      if (id === 'expo-crypto') return { randomUUID: () => `operation-${++sequence}` };
      if (id === '@react-native-async-storage/async-storage') return { getItem: async (key) => storage.get(key) ?? null, setItem: async (key, value) => storage.set(key, value), removeItem: async (key) => storage.delete(key) };
      if (id.endsWith('/services/packedFruitReceptionApi')) return { createPackedFruitReceptionApi: () => api };
      if (id.startsWith('.')) { const base = resolve(dirname(path), id); for (const ext of ['.ts', '.tsx']) { try { readFileSync(base + ext); } catch { continue; } return load(base + ext); } }
      return require(id);
    };
    vm.runInNewContext(compiled, { require: mockRequire, module, exports: module.exports, console, Error, Date, Number, Object, JSON, Promise, Map, Set }, { filename: path });
    return module.exports;
  }
  const { ApiError } = load(resolve(sourceRoot, 'services/apiError.ts'));
  const api = {
    options: async () => options, catalog: async () => catalog, list: async () => ({ data: committed ? [committed] : [], current_page: 1, last_page: 1 }), detail: async () => committed,
    checkFolio: async (value) => { calls.scans.push(value); return { repetido: value === 'EX-3', mensaje: value === 'EX-3' ? 'Se asignará folio interno al aceptar' : null }; },
    reviewGuide: async () => ({ duplicada: duplicateGuide, mensaje: duplicateGuide ? 'Esta guía ya se recibió del mismo cliente y planta de origen.' : null }),
    save: async (payload) => {
      calls.saves.push(JSON.parse(JSON.stringify(payload)));
      assert.equal(payload.estado, undefined, 'El editor no envía el estado ni referencias de inventario');
      committed = { ...payload, id: 'reception', estado: 'borrador', version: committed ? committed.version + 1 : 1, editable: true, pallets: payload.pallets.map((p, i) => ({ ...p, id: `pallet-${i}`, especie: 'UVA', variedad: 'THOMPSON', embalaje: '8.2 KG', calibre: 'L', csg: '12345', condicion_sag_id: p.condicion_sag_personalizada ? p.condicion_sag_id : payload.condicion_sag_id })) };
      if (failAfterCommit) { failAfterCommit = false; throw new ApiError('La respuesta se perdió tras guardar.', 0); }
      return committed;
    },
  };
  const { PackedFruitReceptionScreen } = load(resolve(sourceRoot, 'screens/PackedFruitReceptionScreen.tsx'));
  const auth = { token: 'token', dispositivo: { id: 'pda' }, usuario: { id: '1', capacidades: { puede_gestionar_recepciones_fruta_embalada: true } } };
  const flatten = (value) => Array.isArray(value) ? value.map(flatten).join('') : typeof value === 'string' || typeof value === 'number' ? String(value) : value?.props ? flatten(value.props.children) : '';
  async function flush(fn = () => {}) { await act(async () => { await fn(); for (let n = 0; n < 40; n++) await Promise.resolve(); }); }
  const pressable = (label) => renderer.root.findAllByType('Pressable').find((p) => p.props.accessibilityLabel === label || flatten(p.props.children) === label);
  return {
    calls, storage,
    async mount() { await flush(() => { renderer = create(React.createElement(PackedFruitReceptionScreen, { auth, baseUrl: 'http://server', onLogout: () => {} })); }); },
    async unmount() { await flush(() => renderer.unmount()); },
    async press(label) { const target = pressable(label); assert.ok(target, `Botón disponible: ${label}`); assert.ok(!target.props.disabled, `Botón habilitado: ${label}`); await flush(target.props.onPress); },
    async input(label, value) { await flush(() => renderer.root.findByProps({ accessibilityLabel: label }).props.onChangeText(value)); },
    async select(label, option) { await this.press(label); await this.press(option); },
    message() { return renderer.root.findAllByType('Text').map((n) => flatten(n.props.children)).join('\n'); },
    async truck() {
      await this.press('Nueva recepción'); await this.select('Cliente *', 'Cliente'); await this.select('Planta de origen *', 'Planta');
      await this.input('N° de guía *', '123'); await this.input('Chofer *', 'Conductor'); await this.input('Patente delantera *', 'ABCD12'); await this.select('Condición SAG para todos', 'Aprobado');
    },
    async pallet(number, sag = null) {
      await this.press('Escanear / agregar pallet'); await this.input('Folio de origen', `EX-${number}`);
      await this.select('CSG *', '12345 · Rengo · Marca'); await this.select('Especie *', 'UVA'); await this.select('Variedad *', 'THOMPSON'); await this.select('Embalaje *', '8.2 KG'); await this.select('Calibre *', 'L'); await this.input('Cajas *', '100'); await this.input('T° de pulpa * (°C)', '1.5');
      if (sag) await this.select('Condición SAG del pallet', sag);
      await this.press('Agregar a la captura');
    },
  };
}

test('PDA captura seis pallets, avisa el folio repetido y conserva SAG por pallet', async () => {
  const h = harness({ duplicateGuide: true });
  try {
    await h.mount(); await h.truck();
    for (let n = 1; n <= 6; n++) await h.pallet(n, n === 2 ? 'Sin condición SAG' : null);
    assert.match(h.message(), /Se asignará folio interno al aceptar/);
    await h.press('Guardar borrador');
    assert.equal(h.calls.saves.length, 1); const p = h.calls.saves[0];
    assert.equal(p.pallets.length, 6); assert.equal(p.condicion_sag_id, 'sag'); assert.equal(p.pallets[1].condicion_sag_id, null); assert.equal(p.pallets[1].condicion_sag_personalizada, true);
    assert.equal(p.confirmar_guia_duplicada, true); assert.equal(h.calls.alerts[0].title, 'Guía ya recibida');
    assert.match(h.message(), /Borrador guardado/);
    // Editar una recepción existente conserva la captura y no envía el estado del recurso.
    await h.input('Observación', 'Segunda captura'); await h.press('Guardar borrador'); assert.equal(h.calls.saves[1].version_conocida, 1);
  } finally { await h.unmount(); }
});

test('respuesta perdida conserva operación y payload incluso al reiniciar la pantalla', async () => {
  const h = harness({ failAfterCommit: true });
  try {
    await h.mount(); await h.truck(); await h.pallet(1); await h.press('Guardar borrador');
    assert.equal(h.storage.size, 1); assert.match(h.message(), /respuesta se perdió/);
    await h.unmount(); await h.mount(); await h.press('Reintentar el mismo guardado');
    assert.deepEqual(h.calls.saves[0], h.calls.saves[1]); assert.equal(h.storage.size, 0);
  } finally { await h.unmount(); }
});
