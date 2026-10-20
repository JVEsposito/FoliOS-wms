import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';
import test from 'node:test';
import React from 'react';
import { act, create } from 'react-test-renderer';
import ts from 'typescript';
import {
  blankGatehouseDraft, gatehousePayload, gatehousePendingKey, parseGatehousePending,
} from '../src/domain/gatehouse.ts';

globalThis.IS_REACT_ACT_ENVIRONMENT = true;
const require = createRequire(import.meta.url);
const sourceRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../src');
const draft = () => ({ ...blankGatehouseDraft(), cliente_id: 'client', patente: 'ab-cd 12',
  tipo_camion: 'Camión', nombre_conductor: 'Conductor', guias: ['000123', '000124'] });

test('captura conserva guías, normaliza patentes y valida carga, unidades y repetidas', () => {
  const p = gatehousePayload(draft(), 'operation');
  assert.equal(p.patente, 'ABCD12');
  assert.deepEqual(p.guias, ['000123', '000124']);
  assert.equal(p.cantidad, null);
  assert.equal(gatehousePayload({ ...draft(), cantidad: '0', unidad: 'bins' }, 'operation').cantidad, 0);
  assert.throws(() => gatehousePayload({ ...draft(), guias: [] }, 'operation'), /al menos una guía/);
  assert.throws(() => gatehousePayload({ ...draft(), guias: ['0001', ' 0001 '] }, 'operation'), /repite/);
  assert.throws(() => gatehousePayload({ ...draft(), cantidad: '20' }, 'operation'), /cantidad y unidad/);
  assert.throws(() => gatehousePayload({ ...draft(), cantidad: '1.5', unidad: 'bins' }, 'operation'), /entero/);
  const service = gatehousePayload({ ...draft(), otro: true, cliente_otro: 'Servicio', motivo: 'servicio', guias: [] }, 'service');
  assert.equal(service.cliente_id, null);
  assert.equal(service.cliente_otro, 'Servicio');
  assert.deepEqual(service.guias, []);
});

test('envío pendiente se separa por servidor, usuario y equipo y no se descarta si es ilegible', () => {
  const p = { payload: gatehousePayload(draft(), 'operation') };
  assert.equal(parseGatehousePending(JSON.stringify(p)).payload.operacion_id, 'operation');
  assert.equal(parseGatehousePending(null), null);
  assert.throws(() => parseGatehousePending('{'), /envío pendiente/);
  assert.throws(() => parseGatehousePending('{"payload":{}}'), /envío pendiente/);
  const keys = [
    gatehousePendingKey('http://server', '7', 'pda'),
    gatehousePendingKey('http://other', '7', 'pda'),
    gatehousePendingKey('http://server', '8', 'pda'),
    gatehousePendingKey('http://server', '7', 'tablet'),
  ];
  assert.equal(new Set(keys).size, 4);
  assert.equal(keys[0], gatehousePendingKey('http://server/', '7', 'pda'));
});

function harness({ variant = 'pda', width = 360, failAfterCommit = false, failPersist = false, pages = 1, http = null } = {}) {
  const storage = new Map();
  const records = new Map();
  const cache = new Map();
  const calls = { saves: [], lists: [], http: [], orientations: [], logins: [] };
  let renderer;
  let sequence = 0;
  let reject422 = false;
  let listFails = false;
  let foreground = () => {};
  const auth = { token: 'token', token_type: 'Bearer', dispositivo: { id: 'device', codigo: 'PDA-01', nombre: 'Portería' },
    usuario: { id: '7', nombre: 'Guardia', email: 'guardia@example.com', rol: 'guardia_porteria',
      modulos_tablet: ['porteria'], ambito_camaras: 'ninguno',
      capacidades: { puede_consultar_porteria: true, puede_registrar_porteria: true } } };
  const native = {
    ActivityIndicator: 'ActivityIndicator', Pressable: 'Pressable', Text: 'Text', View: 'View', TextInput: 'TextInput',
    ScrollView: 'ScrollView', KeyboardAvoidingView: 'KeyboardAvoidingView',
    StyleSheet: { create: (styles) => styles }, Platform: { OS: 'android' },
    useWindowDimensions: () => ({ width, height: 900 }),
    AppState: { addEventListener: (_, handler) => { foreground = handler; return { remove() {} }; } },
    Modal: ({ visible, children }) => visible ? React.createElement('Modal', null, children) : null,
    FlatList: ({ data, renderItem, ListEmptyComponent }) => React.createElement('FlatList', null,
      ...data.map((item) => React.createElement('Row', { key: item.id }, renderItem({ item }))),
      !data.length && ListEmptyComponent),
  };
  function load(path) {
    if (cache.has(path)) return cache.get(path).exports;
    const module = { exports: {} }; cache.set(path, module);
    const compiled = ts.transpileModule(readFileSync(path, 'utf8'), {
      compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
      fileName: path,
    }).outputText;
    const mockRequire = (id) => {
      if (id === 'react-native') return native;
      if (id === 'expo-crypto') return { randomUUID: () => 'operation-' + (++sequence) };
      if (id === 'expo-updates') return { channel: variant === 'pda' ? 'pda' : 'production' };
      if (id === 'expo-status-bar') return { StatusBar: 'StatusBar' };
      if (id === 'expo-screen-orientation') return {
        OrientationLock: { PORTRAIT_UP: 'portrait', LANDSCAPE: 'landscape', DEFAULT: 'default' },
        lockAsync: async (value) => { calls.orientations.push(value); },
      };
      if (id === 'react-native-safe-area-context') return { SafeAreaProvider: 'SafeAreaProvider', SafeAreaView: 'SafeAreaView' };
      if (id === '@react-native-async-storage/async-storage') return {
        getItem: async (key) => storage.get(key) ?? null,
        setItem: async (key, value) => { if (failPersist) { failPersist = false; throw new Error('No se pudo guardar en el equipo'); } storage.set(key, value); },
        removeItem: async (key) => storage.delete(key),
      };
      if (id.endsWith('/services/gatehouseApi')) return { createGatehouseApi: () => api };
      if (id.endsWith('/services/apiConfiguration')) return { loadApiBaseUrl: async () => 'http://server', saveApiBaseUrl: async (v) => v };
      if (id.endsWith('/services/appUpdates')) return { applyAvailableUpdate: async () => {} };
      if (id.endsWith('/services/estibaApi')) return { createEstibaApi: (baseUrl) => ({
        baseUrl, mode: 'connected', configurationError: null,
        login: async (payload) => { calls.logins.push(payload); return auth; }, logout: async () => {},
      }) };
      if (id.endsWith('/demo/demoDatabase')) return { initializeDemoDatabase: async () => {} };
      if (id.includes('/screens/') && !id.endsWith('/GatehouseScreen')) {
        const name = id.split('/').at(-1);
        return { [name]: (props) => React.createElement(name, props) };
      }
      if (id.startsWith('.')) {
        const base = resolve(dirname(path), id);
        for (const ext of ['.ts', '.tsx']) {
          try { readFileSync(base + ext); } catch { continue; }
          return load(base + ext);
        }
      }
      return require(id);
    };
    const mockFetch = async (url, init) => {
      calls.http.push({ url, init });
      if (http) return http(url, init);
      throw new Error('Solicitud HTTP inesperada');
    };
    vm.runInNewContext(compiled, {
      require: mockRequire, module, exports: module.exports, console, Error, Date, Number, Object,
      JSON, Promise, Map, Set, Intl, Headers, URLSearchParams, AbortController, setTimeout, clearTimeout,
      fetch: mockFetch, process: { env: { EXPO_PUBLIC_APP_VARIANT: variant } },
    }, { filename: path });
    return module.exports;
  }
  const { ApiError } = load(resolve(sourceRoot, 'services/apiError.ts'));
  const api = {
    catalog: async () => ({ clientes: [{ id: 'client', nombre: 'Cliente de prueba' }], ahora: '2026-10-20T18:00:00Z' }),
    list: async (filters) => {
      calls.lists.push(JSON.parse(JSON.stringify(filters)));
      if (listFails) { listFails = false; throw new ApiError('No hay conexión', 0); }
      return { data: [...records.values()], pagina: filters.page ?? 1, ultima_pagina: pages,
        total: records.size, fecha: filters.fecha || '2026-10-20' };
    },
    detail: async (id) => ({ data: [...records.values()].find((entry) => entry.id === id),
      eventos: [{ accion: 'registrado', user_nombre: 'Guardia', created_at: '2026-10-20T18:00:00Z', datos: {} }] }),
    register: async (payload) => {
      const p = JSON.parse(JSON.stringify(payload)); calls.saves.push(p);
      if (reject422) { reject422 = false; throw new ApiError('La captura no es válida', 422); }
      if (!records.has(p.operacion_id)) records.set(p.operacion_id, {
        ...p, id: records.size + 1, codigo: 'PORT-000001', cliente_nombre: p.cliente_otro || 'Cliente de prueba',
        cliente_es_otro: p.cliente_id === null, ingreso_at: '2026-10-20T18:00:00Z',
        creado_por_nombre: 'Guardia', revisado_at: null, revisado_por_nombre: null,
        anulado_at: null, motivo_anulacion: null, version: 1,
      });
      if (failAfterCommit) { failAfterCommit = false; throw new ApiError('Se perdió la respuesta', 0); }
      return records.get(p.operacion_id);
    },
  };
  const flatten = (v) => Array.isArray(v) ? v.map(flatten).join('') : typeof v === 'string' || typeof v === 'number'
    ? String(v) : v?.props ? flatten(v.props.children) : '';
  async function flush(fn = () => {}) {
    await act(async () => { await fn(); for (let n = 0; n < 60; n++) await Promise.resolve(); });
  }
  return {
    calls, storage, records,
    async mount({ app = false } = {}) {
      const Component = app ? load(resolve(sourceRoot, '../App.tsx')).default
        : load(resolve(sourceRoot, 'screens/GatehouseScreen.tsx')).GatehouseScreen;
      await flush(() => { renderer = create(React.createElement(Component, app ? {} : { auth, baseUrl: 'http://server', onLogout() {} })); });
      if (app) await flush(() => renderer.root.findByType('LoginScreen').props.onLogin({ email: auth.usuario.email, password: 'secret', codigo_dispositivo: 'PDA-01' }));
    },
    async unmount() { await flush(() => renderer.unmount()); },
    async press(label) {
      const target = renderer.root.findAllByType('Pressable').find((p) => p.props.accessibilityLabel === label || flatten(p.props.children) === label);
      assert.ok(target, 'Botón: ' + label); assert.ok(!target.props.disabled, 'Botón habilitado: ' + label);
      await flush(target.props.onPress);
    },
    async input(label, value) { await flush(() => renderer.root.findByProps({ accessibilityLabel: label }).props.onChangeText(value)); },
    editable(label) { return renderer.root.findByProps({ accessibilityLabel: label }).props.editable; },
    message() { return renderer.root.findAllByType('Text').map((n) => flatten(n.props.children)).join('\n'); },
    async truck({ other = false, service = false } = {}) {
      if (other) { await this.press('Otro'); await this.input('Cliente / servicio (Otro) *', 'Servicio externo'); }
      else { await this.press('Seleccionar cliente'); await this.press('Cliente de prueba'); }
      await this.input('Patente *', 'ab-cd 12'); await this.input('Tipo de camión *', 'Camión con carro'); await this.input('Conductor *', 'Conductor');
      if (service) await this.press('Servicio');
      else { await this.input('Guía 1', '000123'); await this.press('Agregar guía'); await this.input('Guía 2', '000124'); }
    },
    rejectValidation() { reject422 = true; },
    async loseListConnection() { listFails = true; await flush(() => foreground('active')); },
    realApi() { return load(resolve(sourceRoot, 'services/gatehouseApi.ts')).createGatehouseApi('http://server/', 'secret-token'); },
  };
}

for (const [variant, width] of [['pda', 360], ['tablet', 800], ['tablet', 390]]) {
  test('login de guardia abre Portería y registra guías en ' + variant + ' de ' + width + ' px', async () => {
    const h = harness({ variant, width });
    try {
      await h.mount({ app: true }); await h.truck(); await h.press('Registrar llegada');
      assert.equal(h.calls.logins.length, 1);
      assert.deepEqual(h.calls.saves[0].guias, ['000123', '000124']);
      assert.equal(h.calls.saves[0].patente, 'ABCD12');
      assert.match(h.message(), /Ingreso confirmado: PORT-000001/);
      assert.equal(h.calls.orientations.at(-1), 'portrait');
      assert.equal(h.storage.size, 0);
    } finally { await h.unmount(); }
  });
}

test('Otro y servicio sin guía se registran desde la pantalla nativa', async () => {
  const h = harness();
  try {
    await h.mount(); await h.truck({ other: true, service: true }); await h.press('Registrar llegada');
    assert.equal(h.calls.saves[0].cliente_otro, 'Servicio externo');
    assert.equal(h.calls.saves[0].cliente_id, null);
    assert.deepEqual(h.calls.saves[0].guias, []);
  } finally { await h.unmount(); }
});

test('respuesta perdida conserva captura y UUID al cerrar, abrir y reintentar', async () => {
  const h = harness({ failAfterCommit: true });
  try {
    await h.mount(); await h.truck(); await h.press('Registrar llegada');
    assert.equal(h.storage.size, 1);
    assert.equal(h.editable('Patente *'), false);
    assert.match(h.message(), /pendiente de confirmar/);
    await h.unmount(); await h.mount(); await h.press('Reintentar el mismo ingreso');
    assert.deepEqual(h.calls.saves[0], h.calls.saves[1]);
    assert.equal(h.records.size, 1);
    assert.equal(h.storage.size, 0);
    assert.match(h.message(), /Ingreso confirmado/);
  } finally { await h.unmount(); }
});

test('un fallo al persistir impide enviar; una validación del primer envío permite corregir', async () => {
  const h = harness({ failPersist: true });
  try {
    await h.mount(); await h.truck(); await h.press('Registrar llegada');
    assert.equal(h.calls.saves.length, 0);
    assert.match(h.message(), /No se pudo guardar en el equipo/);
    h.rejectValidation(); await h.press('Registrar llegada');
    assert.equal(h.storage.size, 0);
    assert.equal(h.editable('Patente *'), true);
    await h.input('Patente *', 'EFGH34'); await h.press('Registrar llegada');
    assert.equal(h.records.size, 1);
    assert.notEqual(h.calls.saves[0].operacion_id, h.calls.saves[1].operacion_id);
  } finally { await h.unmount(); }
});

test('una validación después de respuesta perdida no descarta el intento incierto', async () => {
  const h = harness({ failAfterCommit: true });
  try {
    await h.mount(); await h.truck(); await h.press('Registrar llegada');
    h.rejectValidation(); await h.press('Reintentar el mismo ingreso');
    assert.equal(h.storage.size, 1);
    assert.equal(h.editable('Patente *'), false);
    await h.press('Reintentar el mismo ingreso');
    assert.equal(h.records.size, 1);
    assert.equal(h.storage.size, 0);
    assert.deepEqual(h.calls.saves[0], h.calls.saves[2]);
  } finally { await h.unmount(); }
});

test('bitácora filtra, pagina, conserva datos sin conexión y muestra detalle e historial', async () => {
  const h = harness({ pages: 2 });
  try {
    await h.mount(); await h.truck(); await h.press('Registrar llegada'); await h.press('Bitácora');
    await h.input('Fecha en Chile (AAAA-MM-DD)', '2026-10-19');
    await h.input('Buscar patente, cliente, conductor o guía exacta', '000123');
    await h.press('Aplicar filtros'); await h.press('Siguiente');
    assert.equal(h.calls.lists.at(-1).fecha, '2026-10-19');
    assert.equal(h.calls.lists.at(-1).buscar, '000123');
    assert.equal(h.calls.lists.at(-1).page, 2);
    await h.loseListConnection();
    assert.match(h.message(), /Bitácora sin actualizar/);
    assert.match(h.message(), /PORT-000001/);
    await h.press('Ver PORT-000001');
    assert.match(h.message(), /Historial/);
    assert.match(h.message(), /000123, 000124/);
    await h.press('Cerrar detalle');
  } finally { await h.unmount(); }
});

test('cliente HTTP nativo usa token, endpoint y payload originales, y conserva errores de validación', async () => {
  let validation = false;
  const h = harness({ http: async () => validation
    ? new Response(JSON.stringify({ errors: { guias: ['Agrega una guía'] } }), { status: 422 })
    : new Response(JSON.stringify({ data: { id: 1 } }), { status: 201 }) });
  const api = h.realApi();
  const payload = gatehousePayload(draft(), 'operation');
  await api.register(payload);
  assert.equal(h.calls.http[0].url, 'http://server/api/porteria/ingresos');
  assert.equal(h.calls.http[0].init.headers.Authorization, 'Bearer secret-token');
  assert.deepEqual(JSON.parse(h.calls.http[0].init.body), payload);
  validation = true;
  await assert.rejects(api.register(payload), (error) => error.status === 422 && /Agrega una guía/.test(error.message));
});
