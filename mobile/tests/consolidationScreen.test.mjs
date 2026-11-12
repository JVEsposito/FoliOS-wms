import { fieldUiMock } from './helpers/fieldUiMock.mjs';
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

globalThis.IS_REACT_ACT_ENVIRONMENT = true;
const require = createRequire(import.meta.url);
const sourceRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../src');

const catalog = {
  codigo: 'POEMP R-24', posicion_maxima: 60,
  encabezado: [
    { clave: 'tipo_camion', etiqueta: 'Tipo de camión', tipo: 'opcion', opciones: ['contenedor', 'thermo'], grupo: 'operacion' },
    { clave: 'exportadora', etiqueta: 'Exportadora', tipo: 'texto', grupo: 'operacion' },
  ],
  inspeccion: [
    { clave: 'puertas', etiqueta: 'Puertas en buen estado', aplica: null },
    { clave: 'horometro', etiqueta: 'Horómetro en buen estado (thermos)', aplica: 'thermo' },
  ],
  cierre: [{ clave: 'cajas_guia', etiqueta: 'Cajas totales guía de despacho', tipo: 'entero' }],
  tipos_foto: [
    { clave: 'patente', nombre: 'Placa patente', obligatoria: true, condicion: null, maximo: 3 },
    { clave: 'general', nombre: 'Otras fotos del despacho', obligatoria: false, condicion: null, maximo: 20 },
  ],
  exige_fotos: false,
};
const pallet = (numero_folio, estado_carga, posicion = null) => ({
  asignacion_id: `a-${numero_folio}`, folio_id: `f-${numero_folio}`, numero_folio, estado_carga, cargado_at: null, especie: 'Cereza', variedad: 'Santina', cajas_folio: 80,
  posicion: posicion === null ? null : { id: `p-${numero_folio}`, posicion, codigo_pe: null, cajas: 80, temperatura: null, registrado_at: null, registrado_por: 'Consolidador' },
});
const controlFixture = (pallets, extra = {}) => ({
  id: 'c1', estado: 'borrador', version: 1,
  carga: { id: 'g1', codigo: 'CAR-0007', estado: 'despachada', embarque: 'EMB-1', anden: 'AND-1', patente: 'ABCD12' },
  encabezado: { tipo_camion: null, exportadora: 'Subsole' }, sugerido: { encabezado: { exportadora: 'Subsole' }, cierre: {} },
  inspeccion: { puertas: null, horometro: null }, cierre: { cajas_guia: 240 }, pallets,
  totales: { pallets: pallets.length, cargados: pallets.filter((p) => p.estado_carga === 'cargado').length, en_anden: pallets.filter((p) => p.estado_carga === 'en_anden').length, en_camara: 0, posicionados: pallets.filter((p) => p.posicion).length, con_temperatura: 0, cajas_pcd: 0, cajas_carga: 240 },
  iniciado_at: null, iniciado_por: 'Consolidador', cerrado_at: null, cerrado_por: null, reaperturas: [], fotos: [],
  faltantes_cierre: ['Indica si es contenedor o thermo.'],
  faltantes_impresion: pallets.some((p) => p.posicion && p.posicion.temperatura === null) ? ['Falta la T° de pulpa de pallets.'] : [],
  fotos_pendientes: [{ tipo: 'patente', nombre: 'Placa patente', requeridas: 1, tomadas: 0 }], ...extra,
});

function harness({ closedWithPdf = false } = {}) {
  const cache = new Map();
  const calls = { loads: 0, start: [], position: [], save: [], temperatures: [], sharedPdf: 0 };
  const storage = new Map();
  let sequence = 0;
  let failNextPosition = false;
  let current = controlFixture([pallet('A1', 'cargado', 1), pallet('A2', 'en_anden'), pallet('A3', 'en_anden')]);
  if (closedWithPdf) {
    current = controlFixture([{ ...pallet('A1', 'cargado', 1), posicion: { ...pallet('A1', 'cargado', 1).posicion, temperatura: 0.2 } }], {
      estado: 'cerrado', cerrado_por: 'Consolidador', faltantes_cierre: [], fotos_pendientes: [],
      fotos: [{ id: 'ph1', tipo: 'patente', orden: 1, url: null, miniatura_url: null, subida_at: null, archivada: true, pagina_pdf: 1 },
        { id: 'ph2', tipo: 'general', orden: 1, url: null, miniatura_url: null, subida_at: null, archivada: true, pagina_pdf: 1 }],
      fotos_pdf: { url: '/api/control-despacho/controles/c1/fotos.pdf', bytes: 348160, cantidad: 2, generado_at: null },
    });
  }
  let renderer;
  let ApiError;
  const native = {
    Pressable: 'Pressable', Text: 'Text', View: 'View', TextInput: 'TextInput', ScrollView: 'ScrollView', Image: 'Image', ActivityIndicator: 'ActivityIndicator',
    KeyboardAvoidingView: 'KeyboardAvoidingView', Platform: { OS: 'android' },
    Modal: ({ visible, children }) => (visible ? React.createElement('Modal', null, children) : null),
    StyleSheet: { create: (styles) => styles, absoluteFillObject: {} },
  };
  const api = {
    catalog: async () => catalog,
    loads: async () => { calls.loads += 1; return [{ id: 'g1', codigo: 'CAR-0007', estado: 'despachada', exportadora: 'Subsole', embarque: 'EMB-1', contenedor: 'MSCU1', anden: 'AND-1', patente: 'ABCD12', pallets: 3, en_anden: 2, cargados: 1, control: closedWithPdf ? { id: 'c1', estado: 'cerrado', posicionados: 1 } : null }]; },
    sharePhotosPdf: async () => { calls.sharedPdf += 1; },
    thumbnail: async () => null,
    start: async (loadId, operation) => { calls.start.push({ loadId, operation }); return current; },
    detail: async () => current,
    position: async (_, payload) => {
      calls.position.push(JSON.parse(JSON.stringify(payload)));
      if (failNextPosition) { failNextPosition = false; throw new ApiError('No hay respuesta del servidor.', 0); }
      current = controlFixture(current.pallets.map((p) => (p.numero_folio === payload.numero_folio
        ? { ...p, estado_carga: 'cargado', posicion: { id: `p-${p.numero_folio}`, posicion: payload.posicion, codigo_pe: payload.codigo_pe, cajas: payload.cajas ?? 80, temperatura: payload.temperatura, registrado_at: null, registrado_por: 'Consolidador' } }
        : p)), { encabezado: current.encabezado, inspeccion: current.inspeccion, version: current.version });
      return current;
    },
    completeTemperatures: async (_, operation, temperaturas) => {
      calls.temperatures.push(JSON.parse(JSON.stringify({ operation, temperaturas })));
      const values = Object.fromEntries(temperaturas.map((t) => [t.posicion_id, t.temperatura]));
      current = controlFixture(current.pallets.map((p) => (p.posicion && values[p.posicion.id] !== undefined ? { ...p, posicion: { ...p.posicion, temperatura: values[p.posicion.id] } } : p)),
        { encabezado: current.encabezado, inspeccion: current.inspeccion, version: current.version });
      return current;
    },
    save: async (_, payload) => {
      calls.save.push(JSON.parse(JSON.stringify(payload)));
      current = { ...current, version: current.version + 1, encabezado: { ...current.encabezado, ...(payload.encabezado ?? {}) }, inspeccion: { ...current.inspeccion, ...(payload.inspeccion ?? {}) } };
      return current;
    },
  };
  function load(path) {
    if (cache.has(path)) return cache.get(path).exports;
    const module = { exports: {} }; cache.set(path, module);
    const compiled = ts.transpileModule(readFileSync(path, 'utf8'), {
      compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
      fileName: path,
    }).outputText;
    const mockRequire = (id) => {
      const fieldMock = fieldUiMock(id); if (fieldMock) return fieldMock;
      if (id === 'react-native') return native;
      if (id === 'expo-crypto') return { randomUUID: () => `operation-${++sequence}` };
      if (id === 'expo-image-picker') return {};
      if (id === '@react-native-async-storage/async-storage') return { __esModule: true, default: {
        getItem: async (key) => storage.get(key) ?? null, setItem: async (key, value) => { storage.set(key, value); }, removeItem: async (key) => { storage.delete(key); },
      } };
      if (id.endsWith('/services/consolidationApi')) return { createConsolidationApi: () => api };
      if (id.startsWith('.')) {
        const base = resolve(dirname(path), id);
        for (const ext of ['.ts', '.tsx']) {
          try { readFileSync(base + ext); } catch { continue; }
          return load(base + ext);
        }
      }
      return require(id);
    };
    vm.runInNewContext(compiled, {
      require: mockRequire, module, exports: module.exports, console, Error, Date, Number, Object, JSON, Promise, Map, Set, Intl, Math, String, setTimeout, clearTimeout,
    }, { filename: path });
    return module.exports;
  }
  ApiError = load(resolve(sourceRoot, 'services/apiError.ts')).ApiError;
  const flatten = (v) => Array.isArray(v) ? v.map(flatten).join('') : typeof v === 'string' || typeof v === 'number'
    ? String(v) : v?.props ? flatten(v.props.children) : '';
  async function flush(fn = () => {}) {
    await act(async () => { await fn(); for (let n = 0; n < 40; n++) await Promise.resolve(); });
  }
  const auth = { token: 'token', dispositivo: { id: 'pda', codigo: 'PDA-01', nombre: 'PDA andén' }, usuario: { id: '9', nombre: 'Consolidador', capacidades: { puede_consultar_control_despacho: true, puede_registrar_control_despacho: true } } };
  const pressables = () => renderer.root.findAllByType('Pressable');
  return {
    calls,
    failNextPosition() { failNextPosition = true; },
    async mount() {
      const { ConsolidationScreen } = load(resolve(sourceRoot, 'screens/ConsolidationScreen.tsx'));
      await flush(() => { renderer = create(React.createElement(ConsolidationScreen, { auth, baseUrl: 'http://server', onLogout() {} })); });
    },
    pressable(label) { return pressables().find((p) => flatten(p.props.children) === label); },
    async press(label, { containing = false } = {}) {
      const target = containing ? pressables().find((p) => flatten(p.props.children).includes(label)) : this.pressable(label);
      assert.ok(target, `Botón: ${label}`); assert.ok(!target.props.disabled, `Botón habilitado: ${label}`);
      await flush(target.props.onPress);
    },
    async input(label, value) { await flush(() => renderer.root.findByProps({ accessibilityLabel: label }).props.onChangeText(value)); },
    value(label) { return renderer.root.findByProps({ accessibilityLabel: label }).props.value; },
    text() { return renderer.root.findAllByType('Text').map((node) => flatten(node.props.children)).join('\n'); },
    async unmount() { await flush(() => renderer.unmount()); },
  };
}

test('consolidación abre la planilla, sube pallets con su posición y reintenta sin duplicar', async () => {
  const h = harness();
  try {
    await h.mount();
    assert.equal(h.calls.loads, 1);
    assert.match(h.text(), /CAR-0007/);
    assert.match(h.text(), /1\/3 cargados · 2 en andén/);
    await h.press('Iniciar planilla R-24', { containing: true });
    assert.equal(h.calls.start.length, 1);
    assert.match(h.text(), /R-24 · carga CAR-0007/);
    // La posición 1 ya está ocupada: se sugiere la siguiente libre.
    assert.equal(h.value('Posición'), '2');

    await h.input('Folio que sube al camión', 'a2');
    await h.input('T° pulpa', '0,4');
    await h.press('Espejo', { containing: true });
    await h.press('Subió · registrar posición');
    assert.deepEqual(
      [h.calls.position[0].numero_folio, h.calls.position[0].posicion, h.calls.position[0].temperatura, h.calls.position[0].codigo_pe, h.calls.position[0].compartir_posicion],
      ['A2', 2, 0.4, 'E', false],
    );
    assert.match(h.text(), /Espejo · 0,4 °C/);
    assert.match(h.text(), /A2 en posición 2: quedó cargado en el camión/);
    assert.equal(h.value('Folio que sube al camión'), '');
    assert.equal(h.value('Posición'), '3');

    // Posición ocupada: se pregunta antes de enviar y se confirma que comparten.
    await h.input('Folio que sube al camión', 'A3');
    await h.input('Posición', '1');
    await h.press('Subió · registrar posición');
    assert.equal(h.calls.position.length, 1);
    assert.match(h.text(), /Posición 1 ocupada/);
    h.failNextPosition();
    await h.press('Comparten');
    assert.equal(h.calls.position[1].compartir_posicion, true);
    // La orientación elegida se mantiene para el pallet siguiente.
    assert.equal(h.calls.position[1].codigo_pe, 'E');
    assert.match(h.text(), /Envío sin confirmar/);
    await h.press('Reintentar envío');
    assert.equal(h.calls.position.length, 3);
    assert.equal(h.calls.position[2].operacion_id, h.calls.position[1].operacion_id, 'El reintento repite el mismo operacion_id');
    assert.doesNotMatch(h.text(), /Envío sin confirmar/);
  } finally { await h.unmount(); }
});

test('la inspección marca «no aplica» lo del otro tipo de camión y el cierre espera lo faltante', async () => {
  const h = harness();
  try {
    await h.mount();
    await h.press('Iniciar planilla R-24', { containing: true });
    await h.press('Encabezado');
    await h.press('Contenedor');
    await h.press('Guardar');
    assert.deepEqual(h.calls.save[0].encabezado, { tipo_camion: 'contenedor' });
    assert.equal(h.calls.save[0].version, 1);

    await h.press('Inspección');
    assert.match(h.text(), /Horómetro en buen estado \(thermos\) · no aplica/);
    // Se responde solo «puertas»: el horómetro (thermo) se envía como «no aplica».
    const doors = h.text().split('\n').indexOf('Puertas en buen estado');
    assert.ok(doors >= 0);
    await h.press('Sí');
    await h.press('Guardar');
    assert.deepEqual(h.calls.save[1].inspeccion, { horometro: 'na', puertas: 'si' });
    assert.equal(h.calls.save[1].version, 2);

    await h.press('Cierre');
    assert.match(h.text(), /Para cerrar la planilla falta/);
    assert.equal(h.pressable('Cerrar planilla R-24').props.disabled, true);
  } finally { await h.unmount(); }
});

test('la T° de pulpa no bloquea el cierre pero se completa antes de imprimir', async () => {
  const h = harness();
  try {
    await h.mount();
    await h.press('Iniciar planilla R-24', { containing: true });
    // A1 ya tiene posición y no tiene temperatura.
    assert.match(h.text(), /T° de pulpa pendiente \(1\)/);
    await h.press('Cierre');
    assert.match(h.text(), /Para imprimir falta/);
    await h.press('Completar T° de pulpa para imprimir');
    await h.input('T° de pulpa de A1', '-0,2');
    await h.press('Guardar temperaturas');
    assert.deepEqual(h.calls.temperatures[0].temperaturas, [{ posicion_id: 'p-A1', temperatura: -0.2 }]);
    assert.match(h.text(), /la planilla ya se puede imprimir/);
    assert.doesNotMatch(h.text(), /T° de pulpa pendiente/);
    await h.press('Fotos');
    assert.match(h.text(), /Obligatoria cuando se active el set estándar\. Faltan 1 de 1\./);
  } finally { await h.unmount(); }
});

test('con la planilla cerrada las fotos se ven en el PDF y se comparten desde ahí', async () => {
  const h = harness();
  try {
    await h.mount();
    await h.press('Iniciar planilla R-24', { containing: true });
    await h.press('Fotos');
    assert.doesNotMatch(h.text(), /Set fotográfico en PDF/);
  } finally { await h.unmount(); }
  const closed = harness({ closedWithPdf: true });
  try {
    await closed.mount();
    await closed.press('Abrir planilla', { containing: true });
    await closed.press('Fotos');
    assert.match(closed.text(), /Set fotográfico en PDF/);
    assert.match(closed.text(), /2 fotos · 340 KB/);
    assert.match(closed.text(), /1 foto en el PDF/);
    assert.equal(closed.pressable('Quitar'), undefined, 'Cerrada no se quitan fotos');
    await closed.press('Compartir PDF de fotos');
    assert.equal(closed.calls.sharedPdf, 1);
  } finally { await closed.unmount(); }
});
