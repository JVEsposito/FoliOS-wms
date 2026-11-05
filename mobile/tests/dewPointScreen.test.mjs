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

const minutesAgo = (minutes) => new Date(Date.now() - minutes * 60000).toISOString();

function dayFixture({ registrar = true, readings = [] } = {}) {
  return {
    fecha: '2026-11-04',
    resumen: { registros: readings.length, con_superficie: 0, punto_rocio_maximo_c: readings[0]?.punto_rocio_c ?? null, margen_minimo_c: readings[0]?.margen_c ?? null, alertas: readings.filter((r) => r.requiere_accion).length, alertas_pendientes: readings.filter((r) => r.alerta_pendiente).length, gotas_visibles: 0 },
    mediciones: readings,
    puntos: { salas: [{ id: 'sala-1', nombre: 'Sala de sellado', activa: true }, { id: 'sala-2', nombre: 'Sala antigua', activa: false }], tuneles: [{ id: 't1', nombre: 'TP-01 · Túnel 1' }], camaras: [] },
    etapas: [{ valor: 'sellado', etiqueta: 'Sellado' }, { valor: 'paletizado', etiqueta: 'Paletizado' }, { valor: 'ingreso_prefrio', etiqueta: 'Ingreso prefrío' }, { valor: 'almacenamiento', etiqueta: 'Almacenamiento' }],
    plan: {
      temperatura_congelacion_c: -1.7,
      etapas: [{ etapa: 'almacenamiento', etiqueta: 'Almacenamiento', orden: 7, planificada: true, temperatura_aire_c: -1, temperatura_fruta_c: -0.5, rocio_maximo_c: -1, limitante: 'fruta', humedad_maxima: 100, cerca_congelacion: false }],
    },
    referencias: [
      { id: 'sellado-1', etapa: 'sellado', etapa_etiqueta: 'Sellado', punto: 'Sala de sellado', referencia: null, medido_at: minutesAgo(90), temperatura_ambiente_c: 12, humedad_relativa: 60, temperatura_superficie_c: null, punto_rocio_c: 4.47 },
      { id: 'salida-1', etapa: 'salida_prefrio', etapa_etiqueta: 'Salida prefrío', punto: 'Salida túneles', referencia: null, medido_at: minutesAgo(30), temperatura_ambiente_c: 2, humedad_relativa: 80, temperatura_superficie_c: 1, punto_rocio_c: -1.09 },
    ],
    parametros: { margen_minimo_c: 2, margenes_por_etapa: [{ etapa: 'ingreso_prefrio', margen_minimo_c: 0.5 }], horas_referencia: 72, dias_registro_retroactivo: 7, zona_horaria: 'America/Santiago' },
    permisos: { registrar, supervisar: false },
  };
}

function harness({ registrar = true } = {}) {
  const cache = new Map();
  const calls = { days: 0, saves: [] };
  let sequence = 0;
  let failNextSave = false;
  let readings = [];
  let renderer;
  const native = {
    ActivityIndicator: 'ActivityIndicator', Pressable: 'Pressable', Text: 'Text', View: 'View', TextInput: 'TextInput', ScrollView: 'ScrollView',
    StyleSheet: { create: (styles) => styles, absoluteFillObject: {} },
  };
  let ApiError;
  const api = {
    getDewPointDay: async () => { calls.days += 1; return dayFixture({ registrar, readings }); },
    registerDewPointReading: async (_, __, payload) => {
      calls.saves.push(JSON.parse(JSON.stringify(payload)));
      if (failNextSave) { failNextSave = false; throw new ApiError('La PDA no puede alcanzar el servidor.', 0); }
      const saved = {
        ...payload, id: `m-${readings.length + 1}`, etapa_etiqueta: 'Sellado', punto: 'Sala de sellado', punto_rocio_c: 7.99, margen_c: -0.89, vapor_g_m3: 8.05,
        estado: 'condensacion_posible', estado_etiqueta: 'Condensación posible', requiere_accion: true, alerta_pendiente: true, fecha: '2026-11-04',
        registrado_por: 'Calidad', dispositivo: 'Tablet prefrío', accion_correctiva: null, anulada: false,
      };
      readings = [...readings, saved];
      return saved;
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
      if (id.endsWith('/services/prefrioApi')) return api;
      if (id.endsWith('/hooks/useOperationalPolling')) return { useOperationalPolling: () => {} };
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
      require: mockRequire, module, exports: module.exports, console, Error, Date, Number, Object, JSON, Promise, Map, Set, Intl, Math, setTimeout, clearTimeout,
    }, { filename: path });
    return module.exports;
  }
  ApiError = load(resolve(sourceRoot, 'services/apiError.ts')).ApiError;
  const flatten = (v) => Array.isArray(v) ? v.map(flatten).join('') : typeof v === 'string' || typeof v === 'number'
    ? String(v) : v?.props ? flatten(v.props.children) : '';
  async function flush(fn = () => {}) {
    await act(async () => { await fn(); for (let n = 0; n < 40; n++) await Promise.resolve(); });
  }
  const auth = { token: 'token', dispositivo: { id: 'tablet', codigo: 'TAB-01', nombre: 'Tablet prefrío' }, usuario: { id: '7', nombre: 'Calidad', capacidades: { puede_operar_prefrio: registrar } } };
  return {
    calls,
    failNextSave() { failNextSave = true; },
    async mount() {
      const { DewPointScreen } = load(resolve(sourceRoot, 'screens/DewPointScreen.tsx'));
      await flush(() => { renderer = create(React.createElement(DewPointScreen, { auth, baseUrl: 'http://server', onLogout() {} })); });
    },
    pressable(label) { return renderer.root.findAllByType('Pressable').find((p) => flatten(p.props.children) === label); },
    async press(label) {
      const target = this.pressable(label);
      assert.ok(target, `Botón: ${label}`); assert.ok(!target.props.disabled, `Botón habilitado: ${label}`);
      await flush(target.props.onPress);
    },
    async input(label, value) { await flush(() => renderer.root.findByProps({ accessibilityLabel: label }).props.onChangeText(value)); },
    value(label) { return renderer.root.findByProps({ accessibilityLabel: label }).props.value; },
    selected(label) { return this.pressable(label)?.props.accessibilityState?.selected === true; },
    labels() { return renderer.root.findAllByType('Pressable').map((p) => flatten(p.props.children)); },
    text() { return renderer.root.findAllByType('Text').map((node) => flatten(node.props.children)).join('\n'); },
    async unmount() { await flush(() => renderer.unmount()); },
  };
}

test('la tablet registra un chequeo, muestra el riesgo en vivo y reintenta sin duplicar', async () => {
  const h = harness();
  try {
    await h.mount();
    assert.equal(h.calls.days, 1);
    assert.match(h.text(), /Punto de rocío/);
    assert.match(h.text(), /Aún no hay mediciones hoy/);
    assert.equal(h.pressable('Sala antigua'), undefined, 'Las salas inactivas no se ofrecen');

    await h.press('Guardar medición');
    assert.match(h.text(), /Selecciona la etapa/);
    assert.equal(h.calls.saves.length, 0);

    await h.press('Sellado');
    await h.press('Sala de sellado');
    await h.input('T° ambiente *', '15');
    await h.input('Humedad relativa *', '62,9');
    assert.match(h.text(), /PUNTO DE ROCÍO\n8,0 °C/);
    assert.match(h.text(), /Medir superficie/);
    await h.input('T° superficial fruta', '7,1');
    assert.match(h.text(), /MARGEN VS FRUTA\n-0,9 °C/);
    assert.match(h.text(), /Condensación posible/);
    await h.press('Sí');
    await h.input('Lote, pallet o referencia', 'Línea 2');

    h.failNextSave();
    await h.press('Guardar medición');
    assert.match(h.text(), /no se guardó.*no se duplicará/s);
    assert.match(h.text(), /sin conexión/i);
    await h.press('Guardar medición');
    assert.equal(h.calls.saves.length, 2);
    assert.equal(h.calls.saves[0].operacion_id, h.calls.saves[1].operacion_id, 'El reintento reutiliza la operación');
    assert.equal(h.calls.saves[0].medido_at, h.calls.saves[1].medido_at);
    assert.deepEqual(
      { ...h.calls.saves[1], operacion_id: undefined, medido_at: undefined },
      { operacion_id: undefined, medido_at: undefined, etapa: 'sellado', punto_tipo: 'sala', punto_id: 'sala-1', referencia: 'Línea 2', temperatura_ambiente_c: 15, humedad_relativa: 62.9, temperatura_superficie_c: 7.1, gotas_visibles: 'si', observacion: null, sellado_referencia_id: null, origen_referencia_id: null },
    );
    assert.match(h.text(), /Medición guardada: gotas visibles\. Supervisión de frío fue avisada\./);
    assert.match(h.text(), /Sellado · Sala de sellado/);
    assert.match(h.text(), /Supervisión de frío debe registrar una acción correctiva/);
    assert.equal(h.calls.days, 2, 'Recarga el día después de guardar');

    // El formulario queda listo para la siguiente medición del mismo punto.
    assert.equal(h.value('T° ambiente *'), '');
    assert.equal(h.value('T° superficial fruta'), '');
    assert.equal(h.value('Lote, pallet o referencia'), 'Línea 2');
    assert.ok(h.selected('Sellado'));
    assert.ok(h.selected('Sala de sellado'));
    assert.ok(h.selected('Pendiente'));

    // Una medición nueva lleva otra operación.
    await h.input('T° ambiente *', '14');
    await h.input('Humedad relativa *', '60');
    await h.press('Guardar medición');
    assert.notEqual(h.calls.saves[2].operacion_id, h.calls.saves[1].operacion_id);
  } finally {
    await h.unmount();
  }
});

test('la tablet vincula el sellado y la fruta que llega con el margen de la etapa', async () => {
  const h = harness();
  try {
    await h.mount();
    // En sellado no se ofrece otro sellado: evalúa su propio aire.
    await h.press('Sellado');
    assert.doesNotMatch(h.text(), /Aire del envase/);
    await h.press('Ingreso prefrío');
    assert.match(h.text(), /Aire del envase · sellado de referencia/);
    await h.press('Sala de sellado');
    await h.input('T° ambiente *', '4');
    await h.input('Humedad relativa *', '88');
    await h.input('T° superficial fruta', '3');
    assert.match(h.text(), /Margen mínimo de la etapa: 0,5 °C/);
    const sealedChip = h.labels().find((label) => /· Sala de sellado · rocío 4,5 °C$/.test(label));
    const originChip = h.labels().find((label) => /· Salida prefrío · fruta 1,0 °C$/.test(label));
    assert.ok(sealedChip, 'Chip del sellado reciente');
    assert.ok(originChip, 'Chip de la salida reciente');
    await h.press(sealedChip);
    assert.match(h.text(), /Dentro del envase\nRocío del sellado 4,5 °C · margen -1,5 °C\nCondensación posible/);
    await h.press(originChip);
    // Aire a 4 °C / 88 %: rocío 2,2 °C; la fruta llega a 1,0 °C → condensa al llegar.
    assert.match(h.text(), /Al llegar\nFruta a 1,0 °C desde Salida prefrío · margen -1,2 °C\nCondensación posible/);
    await h.press('Guardar medición');
    const [payload] = h.calls.saves;
    assert.equal(payload.etapa, 'ingreso_prefrio');
    assert.equal(payload.sellado_referencia_id, 'sellado-1');
    assert.equal(payload.origen_referencia_id, 'salida-1');
    // Después de guardar se conserva el sellado de referencia y se limpia el origen.
    assert.ok(h.selected(sealedChip));
    assert.ok(h.selected('Sin vincular') || !h.selected(originChip));
    assert.ok(!h.selected(originChip));
    // Tocar de nuevo un chip lo desvincula.
    await h.press(sealedChip);
    assert.ok(!h.selected(sealedChip));
  } finally {
    await h.unmount();
  }
});

test('la tablet muestra el plan de la etapa y si la medición queda dentro', async () => {
  const h = harness();
  try {
    await h.mount();
    await h.press('Sellado');
    assert.doesNotMatch(h.text(), /Plan de sellado/, 'Una etapa sin plan no muestra objetivo');
    await h.press('Almacenamiento');
    assert.match(h.text(), /Plan de almacenamiento: rocío ≤ -1,0 °C · con aire a -1,0 °C, sin límite de HR/);
    await h.input('T° ambiente *', '0');
    await h.input('Humedad relativa *', '95');
    assert.match(h.text(), /Plan de la etapa\nRocío ≤ -1,0 °C · a esta T° la HR debe ser ≤ 93 %\nFuera del plan/);
    await h.input('Humedad relativa *', '90');
    assert.match(h.text(), /Plan de la etapa\nRocío ≤ -1,0 °C · a esta T° la HR debe ser ≤ 93 %\nDentro del plan/);
  } finally {
    await h.unmount();
  }
});

test('un perfil de consulta ve los chequeos del día pero no el formulario', async () => {
  const h = harness({ registrar: false });
  try {
    await h.mount();
    assert.match(h.text(), /Tu perfil puede consultar los chequeos, pero no registrarlos/);
    assert.equal(h.pressable('Guardar medición'), undefined);
  } finally {
    await h.unmount();
  }
});
