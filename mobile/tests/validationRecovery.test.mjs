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
const outboxKey = 'estiba_validation_outbox:1:pda';
const clone = (value) => JSON.parse(JSON.stringify(value));
const payload = () => ({
  operacion_id: '00000000-0000-4000-8000-000000000111', numero_folio: 'PEND-001',
  tipo_bulto: 'pallet', cantidad_cajas: 120, linea_proceso: 2, turno: 'B',
  temporada_id: 'season', catalogo_version: 1, articulo_validacion_id: 'article',
  origen_validacion_id: 'origin-1', categoria_validacion_id: 'category', fecha_embalaje: '2026-10-16',
  composicion: [
    { origen_validacion_id: 'origin-1', cantidad_cajas: 70, lote_materia_prima: 'L-1', proceso_packing: 'P-1' },
    { origen_validacion_id: 'origin-2', cantidad_cajas: 50, lote_materia_prima: 'L-2', proceso_packing: 'P-2' },
  ],
  resultado: 'aprobado', generado_dispositivo_at: '2026-10-17T15:13:00Z',
});
const pending = (overrides = {}) => ({
  id: payload().operacion_id, payload: payload(), status: 'error', attempts: 1,
  created_at: '2026-10-17T15:13:00Z', last_attempt_at: '2026-10-17T15:13:01Z',
  message: 'La combinación de artículo y CSG no se encuentra habilitada.', ...overrides,
});

function harness({ item = pending(), statusError = false, record = null, finalRecord = null, failPost = null, width = 1200 } = {}) {
  let renderer; let sequence = 0; let failWrite = false;
  const calls = { posts: [], checks: [] };
  const storage = new Map([[outboxKey, JSON.stringify(item ? [item] : [])]]);
  const records = record ? [record] : finalRecord ? [finalRecord] : [];
  const catalog = {
    temporada: { id: 'season', nombre: '2026', version_catalogo: 2 },
    categorias: [{ id: 'category', nombre: 'Exportación', activo: true }],
    articulos: [{ id: 'article', especie: 'Cereza', variedad: 'Santina', calibre: '2J', envase: '5 kg', variedad_validacion_id: 'v1', activo: true }],
    origenes: [1, 2].map((n) => ({ id: `origin-${n}`, cliente: 'Cliente', marca: 'Marca', csg: String(n).repeat(5), variedad_ids: ['v1'], activo: true })),
    combinaciones: [1, 2].map((n) => ({ origen_validacion_id: `origin-${n}`, articulo_validacion_id: 'article' })),
    motivos: ['otro'],
  };
  storage.set('estiba_validation_catalog:1:pda', JSON.stringify(catalog));
  storage.set('estiba_validation_work_context:1:pda', JSON.stringify({ linea_proceso: 1, turno: 'A' }));
  const asyncStorage = {
    getItem: async (key) => storage.get(key) ?? null,
    setItem: async (key, value) => {
      if (key === outboxKey && failWrite) { failWrite = false; throw new Error('No se pudo guardar la corrección.'); }
      storage.set(key, value);
    },
    removeItem: async (key) => storage.delete(key), getAllKeys: async () => [...storage.keys()],
    multiGet: async (keys) => keys.map((key) => [key, storage.get(key) ?? null]),
    multiSet: async (pairs) => { pairs.forEach(([key, value]) => storage.set(key, value)); },
    multiRemove: async (keys) => { keys.forEach((key) => storage.delete(key)); },
  };
  function attempt(p, overrides = {}) {
    return {
      ...clone(p), id: 'attempt-' + p.operacion_id, estado: 'aceptada', numero_intento: records.length + 1,
      usuario: { id: 1, nombre: 'Validador' }, dispositivo: { id: 'pda', codigo: 'PDA' }, recibido_servidor_at: new Date().toISOString(),
      catalogo: { fecha_embalaje: p.fecha_embalaje, composicion: p.composicion, articulo: catalog.articulos[0], origen: catalog.origenes[0] },
      ...overrides,
    };
  }
  const response = (data, status = 200) => new Response(JSON.stringify(data), { status, headers: { 'Content-Type': 'application/json' } });
  const fetch = async (url, options = {}) => {
    assert.equal(options.headers.get('Authorization'), 'Bearer token');
    const parsed = new URL(url);
    if (parsed.pathname.endsWith('/catalogos')) return response(catalog);
    if (parsed.pathname.includes('/operaciones/')) {
      const id = parsed.pathname.split('/').at(-1); calls.checks.push(id);
      if (statusError) return response({ message: 'El servidor no está disponible.' }, 503);
      const found = records.find((r) => r.operacion_id === id);
      return response({ operacion_id: id, estado: found ? 'aplicada' : 'rechazada', mensaje: found ? null : item?.message, validacion: found ?? null });
    }
    if (parsed.pathname.endsWith('/mi-sesion')) return response({
      sesion: { iniciada_at: '2026-10-17T15:28:00Z' },
      resumen: { registros_realizados: records.length, folios_trabajados: records.length, aprobados: records.length, observados: 0, rechazados: 0, conflictos: 0 },
      data: records, meta: { total: records.length, current_page: 1, last_page: 1 },
    });
    if (options.method === 'POST') {
      const p = JSON.parse(options.body); calls.posts.push(clone(p));
      assert.ok(JSON.parse(storage.get(outboxKey)).some((q) => q.id === p.operacion_id && q.status === 'pendiente'), 'Se guarda antes de transmitir');
      if (failPost === 'reject-once' && calls.posts.length === 1) return response({ message: 'Revisa el lote informado.' }, 422);
      let saved = records.find((r) => r.operacion_id === p.operacion_id);
      if (!saved) { saved = attempt(p); records.unshift(saved); }
      if (failPost === 'lost-response' && calls.posts.length === 1) throw new TypeError('Respuesta perdida después de guardar');
      return response({ data: saved });
    }
    if (parsed.pathname.endsWith('/pallets')) return response({ data: records.filter((r) => !parsed.searchParams.has('folio') || r.numero_folio === parsed.searchParams.get('folio')) });
    throw new Error('Petición inesperada: ' + url);
  };
  const native = {
    ActivityIndicator: 'ActivityIndicator', Image: 'Image', Pressable: 'Pressable', ScrollView: 'ScrollView',
    Text: 'Text', TextInput: 'TextInput', View: 'View', StyleSheet: { create: (styles) => styles },
    useWindowDimensions: () => ({ width, height: 800 }), Alert: { alert: () => {} },
    FlatList: ({ data, renderItem, ListEmptyComponent }) => React.createElement('FlatList', null,
      ...data.map((entry) => React.createElement('Row', { key: entry.value }, renderItem({ item: entry }))), !data.length && ListEmptyComponent),
    Modal: ({ visible, children }) => visible ? React.createElement('Modal', null, children) : null,
  };
  const cache = new Map();
  function load(path) {
    if (cache.has(path)) return cache.get(path).exports;
    const module = { exports: {} }; cache.set(path, module);
    const code = ts.transpileModule(readFileSync(path, 'utf8'), {
      compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 }, fileName: path,
    }).outputText;
    const mockRequire = (id) => {
      if (id === 'react-native') return native;
      if (id === 'expo-crypto') return { randomUUID: () => `00000000-0000-4000-8000-${String(++sequence).padStart(12, '0')}` };
      if (id === '@react-native-async-storage/async-storage') return asyncStorage;
      if (id.endsWith('/hooks/useOperationalPolling')) return { useOperationalPolling: () => {} };
      if (id.endsWith('/config/appVariant')) return { isPdaBuild: width < 420 };
      if (id.endsWith('/theme/areas')) return { areas: { frigorifico: { gajo: 1, bright: '#000' } } };
      if (id.endsWith('/components/ui/ScanInput')) return { ScanInput: 'ScanInput' };
      if (id.startsWith('.')) {
        const base = resolve(dirname(path), id);
        for (const ext of ['.ts', '.tsx']) {
          try { readFileSync(base + ext); } catch { continue; }
          return load(base + ext);
        }
      }
      return require(id);
    };
    vm.runInNewContext(code, { require: mockRequire, module, exports: module.exports, console, Error, Headers, URLSearchParams, fetch, setTimeout: () => 0 }, { filename: path });
    return module.exports;
  }
  const { ValidationScreen } = load(resolve(sourceRoot, 'screens/ValidationScreen.tsx'));
  const offline = load(resolve(sourceRoot, 'services/validationOfflineStore.ts'));
  const auth = { token: 'token', usuario: { id: '1', nombre: 'Validador', capacidades: {} }, dispositivo: { id: 'pda', codigo: 'PDA' }, sesion: { id: 'session', iniciada_at: '2026-10-17T15:28:00Z' } };
  const flatten = (value) => Array.isArray(value) ? value.map(flatten).join('') : typeof value === 'string' || typeof value === 'number' ? String(value) : value?.props ? flatten(value.props.children) : '';
  async function flush(action = () => {}) { await act(async () => { await action(); for (let n = 0; n < 80; n++) await Promise.resolve(); }); }
  const button = (label) => renderer.root.findAllByType('Pressable').find((node) => node.findAllByType('Text').some((text) => flatten(text.props.children) === label));
  return {
    calls, records, offline,
    outbox: () => JSON.parse(storage.get(outboxKey)),
    failNextWrite: () => { failWrite = true; },
    async mount() { await flush(() => { renderer = create(React.createElement(ValidationScreen, { auth, baseUrl: 'https://server.test', onLogout: () => {} })); }); },
    async unmount() { await flush(() => renderer.unmount()); },
    async press(label) { const target = button(label); assert.ok(target, 'Botón disponible: ' + label); assert.ok(!target.props.disabled, 'Botón habilitado: ' + label); await flush(target.props.onPress); },
    button,
    message() { return renderer.root.findAllByType('Text').map((node) => flatten(node.props.children)).join('\n'); },
    field(label) { return renderer.root.find((node) => typeof node.type === 'function' && node.props.label === label).props.value; },
    scan() { return renderer.root.findByType('ScanInput').props; },
    date() { return renderer.root.findAllByType('TextInput').find((node) => node.props.placeholder === 'AAAA-MM-DD').props.value; },
    trace() { return renderer.root.findAllByType('TextInput').filter((node) => node.props.placeholder === 'Según etiqueta').map((node) => node.props.value); },
    async lot(value) { await flush(() => renderer.root.findAllByType('TextInput').find((node) => node.props.placeholder === 'Según etiqueta').props.onChangeText(value)); },
  };
}

test('tablet retoma la bandeja anterior, conserva la captura y envía la corrección con UUID nuevo', async () => {
  const h = harness();
  try {
    await h.mount(); assert.match(h.message(), /Pendiente de una sesión anterior/);
    assert.match(h.message(), /La combinación de artículo y CSG/);
    const original = h.outbox();
    await h.press('Retomar');
    assert.equal(h.scan().value, 'PEND-001'); assert.equal(h.scan().disabled, true);
    for (const [label, value] of [['Cliente', 'Cliente'], ['Marca', 'Marca'], ['Especie', 'Cereza'], ['Variedad', 'Santina'], ['Calibre', '2J'], ['Envase', '5 kg'], ['Categoría', 'category'], ['CSG / Predio 2', 'origin-2']]) assert.equal(h.field(label), value);
    assert.deepEqual(h.trace(), ['L-1', 'P-1', 'L-2', 'P-2']);
    assert.deepEqual(h.outbox(), original);
    await h.lot('L-CORREGIDO'); await h.press('Aprobar');
    assert.equal(h.calls.posts.length, 1); assert.notEqual(h.calls.posts[0].operacion_id, original[0].id);
    assert.equal(h.calls.posts[0].composicion[0].lote_materia_prima, 'L-CORREGIDO');
    assert.equal(h.calls.posts[0].fecha_embalaje, '2026-10-16');
    assert.equal(h.calls.posts[0].linea_proceso, 2); assert.equal(h.calls.posts[0].turno, 'B');
    assert.deepEqual(h.calls.posts[0].composicion.map((entry) => entry.cantidad_cajas), [70, 50]);
    assert.equal(h.records.length, 1); assert.deepEqual(h.outbox(), []);
    assert.match(h.message(), /Línea 1 · Turno A/);
    assert.match(h.message(), /PEND-001[\s\S]*Confirmado por el servidor · queda pendiente de prefrío/);
  } finally { await h.unmount(); }
});

test('cancelar o reiniciar durante la corrección conserva intacta la captura original', async () => {
  const h = harness({ width: 360 });
  try {
    await h.mount(); const original = h.outbox(); const originalDate = h.date();
    await h.press('Retomar'); await h.lot('CAMBIO SIN ENVIAR'); await h.press('Cancelar corrección');
    assert.deepEqual(h.outbox(), original);
    assert.match(h.message(), /Línea 1 · Turno A/);
    assert.equal(h.date(), originalDate);
    await h.press('Retomar'); await h.lot('OTRO CAMBIO SIN ENVIAR');
    await h.unmount(); await h.mount();
    assert.deepEqual(h.outbox(), original); await h.press('Retomar');
    assert.deepEqual(h.trace(), ['L-1', 'P-1', 'L-2', 'P-2']);
    assert.equal(h.calls.posts.length, 0);
  } finally { await h.unmount(); }
});

test('si falla comprobar el servidor no se libera ni se borra la captura', async () => {
  const h = harness({ statusError: true });
  try {
    await h.mount(); const original = h.outbox(); await h.press('Retomar');
    assert.match(h.message(), /El servidor no está disponible/);
    assert.deepEqual(h.outbox(), original); assert.equal(h.calls.posts.length, 0);
    assert.equal(h.button('Cancelar corrección'), undefined);
  } finally { await h.unmount(); }
});

test('un rechazo que terminó marcado como conflicto se puede corregir con un UUID nuevo', async () => {
  const h = harness({ item: pending({ status: 'conflicto' }) });
  try {
    await h.mount(); await h.press('Retomar'); await h.press('Aprobar');
    assert.equal(h.calls.posts.length, 1); assert.notEqual(h.calls.posts[0].operacion_id, payload().operacion_id);
    assert.equal(h.records.length, 1); assert.deepEqual(h.outbox(), []);
  } finally { await h.unmount(); }
});

test('un envío que ya llegó al servidor se confirma y sale de la bandeja sin volver a enviarse', async () => {
  const h = harness({ record: { ...payload(), id: 'accepted', estado: 'aceptada', numero_intento: 1,
    usuario: { nombre: 'Validador' }, recibido_servidor_at: '2026-10-17T15:13:02Z', catalogo: {} } });
  try {
    await h.mount(); await h.press('Retomar');
    assert.deepEqual(h.outbox(), []); assert.equal(h.calls.posts.length, 0);
    assert.match(h.message(), /el envío ya estaba registrado en el servidor/);
    assert.equal(h.button('Aprobar').props.disabled, true); assert.equal(h.records.length, 1);
  } finally { await h.unmount(); }
});

test('otra validación final del mismo folio bloquea la recaptura y conserva el conflicto', async () => {
  const h = harness({ finalRecord: { ...payload(), operacion_id: 'other-operation', id: 'accepted', estado: 'aceptada', numero_intento: 1, usuario: { nombre: 'Otro' }, catalogo: {} } });
  try {
    await h.mount(); await h.press('Retomar');
    assert.match(h.message(), /ya tiene una decisión final/);
    assert.equal(h.outbox()[0].status, 'conflicto'); assert.equal(h.calls.posts.length, 0);
    assert.equal(h.button('Cancelar corrección'), undefined);
  } finally { await h.unmount(); }
});

test('una corrección rechazada sigue siendo recuperable y una escritura fallida conserva el original', async () => {
  const h = harness({ failPost: 'reject-once' });
  try {
    await h.mount(); await h.press('Retomar'); const original = h.outbox();
    await h.lot('L-CORREGIDO'); h.failNextWrite(); await h.press('Aprobar');
    assert.match(h.message(), /No se pudo guardar la corrección/);
    assert.deepEqual(h.outbox(), original); assert.equal(h.calls.posts.length, 0);
    await h.press('Aprobar'); assert.equal(h.outbox()[0].status, 'error');
    await h.press('Retomar'); assert.equal(h.trace()[0], 'L-CORREGIDO'); await h.press('Aprobar');
    assert.equal(h.calls.posts.length, 2); assert.notEqual(h.calls.posts[0].operacion_id, h.calls.posts[1].operacion_id);
    assert.deepEqual(h.outbox(), []); assert.equal(h.records.length, 1);
  } finally { await h.unmount(); }
});

test('respuesta perdida reenvía el mismo UUID y payload al reiniciar y confirma un único pallet', async () => {
  const h = harness({ failPost: 'lost-response' });
  try {
    await h.mount(); await h.press('Retomar'); await h.lot('L-CORREGIDO'); await h.press('Aprobar');
    assert.equal(h.outbox()[0].status, 'pendiente'); assert.equal(h.records.length, 1);
    assert.match(h.message(), /Guardado en la tablet · se enviará al sincronizar/);
    await h.unmount(); await h.mount();
    assert.equal(h.calls.posts.length, 2); assert.deepEqual(h.calls.posts[0], h.calls.posts[1]);
    assert.equal(h.records.length, 1); assert.deepEqual(h.outbox(), []);
  } finally { await h.unmount(); }
});

test('mutaciones simultáneas de bandeja conservan los otros folios y rechazan reemplazar un pendiente', async () => {
  const h = harness();
  const second = { ...payload(), operacion_id: 'second', numero_folio: 'PEND-002' };
  const corrected = { ...payload(), operacion_id: 'corrected' };
  await Promise.all([
    h.offline.replaceValidationOutboxItem('1', 'pda', payload().operacion_id, corrected),
    h.offline.enqueueValidation('1', 'pda', second),
  ]);
  assert.deepEqual(h.outbox().map((entry) => entry.id), ['corrected', 'second']);
  await assert.rejects(h.offline.replaceValidationOutboxItem('1', 'pda', 'corrected', { ...corrected, operacion_id: 'third' }), /La captura cambió/);
  assert.deepEqual(h.outbox().map((entry) => entry.id), ['corrected', 'second']);
});
