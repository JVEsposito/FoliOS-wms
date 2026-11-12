import { fieldUiMock } from './helpers/fieldUiMock.mjs';
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

import { plantBinsHint, resolvePlantBins, suggestedPlantBins } from '../src/domain/plantBins.ts';

globalThis.IS_REACT_ACT_ENVIRONMENT = true;
const require = createRequire(import.meta.url);
const sourceRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../src');

test('sugiere los bins de planta que faltan para los totes recibidos', () => {
  assert.equal(suggestedPlantBins(1, 31, 24), 1);
  assert.equal(suggestedPlantBins(1, 24, 24), 0);
  assert.equal(suggestedPlantBins(1, 49, 24), 2);
  assert.equal(suggestedPlantBins(3, 31, 24), 0);
  assert.equal(suggestedPlantBins(0, 30, 24), 2);
  assert.equal(suggestedPlantBins(1, 31, null), 0);
  assert.equal(suggestedPlantBins(1, 0, 24), 0);
  assert.equal(resolvePlantBins(null, 1), 1);
  assert.equal(resolvePlantBins('', 1), 0);
  assert.equal(resolvePlantBins('3', 1), 3);
  assert.match(plantBinsHint(1, 31, 24), /31 totes a 24 por bin necesitan 2 bins; llegaron 1/);
  assert.match(plantBinsHint(1, 31, null), /no tiene referencia/);
});

function harness(reception, catalogOverrides = {}) {
  let renderer;
  let sequence = 0;
  const confirmations = [];
  const catalog = {
    envases: [], temporada: { id: 'season', codigo: '2026', nombre: '2026' }, especies: [{ id: 'cereza', nombre: 'Cereza' }],
    csg: [{ id: 'csg-1', codigo: '11111', predio: 'Fundo', variedad_ids: ['santina'] }],
    variedades: [{ id: 'santina', nombre: 'Santina', especie: 'Cereza' }], motivos: ['csg', 'cuartel', 'variedad'],
    totes_por_bin: 24, ...catalogOverrides,
  };
  const native = {
    ActivityIndicator: 'ActivityIndicator', Pressable: 'Pressable', ScrollView: 'ScrollView', Text: 'Text', TextInput: 'TextInput', View: 'View',
    StyleSheet: { create: (styles) => styles }, useWindowDimensions: () => ({ width: 360, height: 720 }), Alert: { alert: () => {} },
  };
  const cache = new Map();
  function load(path) {
    if (cache.has(path)) return cache.get(path).exports;
    const module = { exports: {} }; cache.set(path, module);
    const code = ts.transpileModule(readFileSync(path, 'utf8'), {
      compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 }, fileName: path,
    }).outputText;
    const mockRequire = (id) => {
      const fieldMock = fieldUiMock(id); if (fieldMock) return fieldMock;
      if (id === 'react-native') return native;
      if (id === 'expo-crypto') return { randomUUID: () => `operation-${++sequence}` };
      if (id.endsWith('/config/appVariant')) return { isPdaBuild: true };
      if (id.endsWith('/components/ui/ScanInput')) return { ScanInput: 'ScanInput' };
      if (id.endsWith('/components/ContainerInspectionPanel')) return { ContainerInspectionPanel: 'ContainerInspectionPanel' };
      if (id.endsWith('/components/ReceptionDefectsPanel')) return { ReceptionDefectsPanel: 'ReceptionDefectsPanel' };
      if (id.endsWith('/domain/containerInspection')) return { emptyContainerInspection: () => ({}), inspectionPayload: () => ({ items: [] }) };
      if (id.endsWith('/services/containerCatalog')) return { containerLabel: () => undefined, getContainerCatalog: async () => [] };
      if (id.endsWith('/services/validationMpApi')) return {
        listPendingMp: async () => [], listMpHistory: async () => [], findMpReception: async () => reception,
        getMpCatalog: async () => catalog, takeMpReception: async () => reception.validacion, completeMpSpecies: async () => reception,
        confirmMpValidation: async (url, token, id, input) => {
          confirmations.push(JSON.parse(JSON.stringify(input)));
          return { ...reception.validacion, estado: 'validada', segmentos: [], bins_prestados_planta: input.plantBins };
        },
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
    vm.runInNewContext(code, { require: mockRequire, module, exports: module.exports, console, setTimeout: () => 0, Intl }, { filename: path });
    return module.exports;
  }
  const { ValidationMpScreen } = load(resolve(sourceRoot, 'screens/ValidationMpScreen.tsx'));
  const auth = { token: 'token', usuario: { id: 7, nombre: 'Validador MP', capacidades: {} } };
  const flatten = (value) => Array.isArray(value) ? value.map(flatten).join('') : typeof value === 'string' || typeof value === 'number' ? String(value) : value?.props ? flatten(value.props.children) : '';
  const flush = async (action) => { await act(async () => { await action(); }); };
  const segment = (index) => renderer.root.find((node) => typeof node.type === 'function' && node.type.name === 'SegmentEditor' && node.props.index === index);
  async function press(scope, label) {
    const button = scope.findAllByType('Pressable').find((node) => node.findAllByType('Text').some((text) => flatten(text.props.children) === label));
    assert.ok(button, `Botón disponible: ${label}`);
    await flush(button.props.onPress);
  }
  return {
    confirmations,
    async mount() { await flush(() => { renderer = create(React.createElement(ValidationMpScreen, { auth, baseUrl: 'https://server.test', onLogout: () => {} })); }); },
    async open(code) { await flush(() => renderer.root.findByType('ScanInput').props.onSubmit(code)); },
    input(label) { return renderer.root.findAllByType('TextInput').find((node) => node.props.accessibilityLabel === label); },
    countInputs() { return renderer.root.findAllByType('TextInput').filter((node) => node.props.keyboardType === 'number-pad' && node.props.accessibilityLabel?.endsWith(' contados')); },
    async type(node, value) { await flush(() => node.props.onChangeText(value)); },
    async press(label) { await press(renderer.root, label); },
    async pressSegment(index, label) { await press(segment(index), label); },
    segmentInputs(index) { return segment(index).findAllByType('TextInput'); },
    text() { return renderer.root.findAllByType('Text').map((node) => flatten(node.props.children)).join('\n'); },
    total() { return flatten(renderer.root.findAllByType('Text').find((node) => node.props.accessibilityLabel === 'Total de bins de planta')?.props.children); },
    async unmount() { await flush(() => renderer.unmount()); },
  };
}

function reception(envases) {
  return {
    id: 'rec-1', numero_recepcion: 'REC-2610-0001', estado_romana: 'en_bascula_salida', estado_validacion_mp: 'en_curso', tipo_recepcion: 'fruta_con_envases',
    especie_validacion_id: 'cereza', version: 2, concepto_envases: null, temporada: { id: 'season', codigo: '2026', nombre: '2026' },
    cliente: { id: 'cliente', codigo: 'C1', nombre: 'Exportadora' }, numero_guia_despacho: 'GD-1', patente_camion: 'ABCD12',
    conductor: { rut: '1-9', nombre: 'Conductor' }, ingreso_at: '2026-10-27T12:00:00Z', pesaje_envases: null,
    envases: envases.map(([tipo_envase, cantidad_declarada]) => ({ tipo_envase, cantidad_declarada, cantidad_validada: null, diferencia: null })),
    tomada_por: { id: 7, nombre: 'Validador MP' },
    validacion: { id: 'val-1', estado: 'en_curso', numero_recepcion: 'REC-2610-0001', temporada: { id: 'season', codigo: '2026' }, validador: { id: 7, nombre: 'Validador MP' },
      dispositivo: null, tarjas_verificadas: null, requiere_segregacion: false, bins_prestados_planta: 0, tomada_at: '2026-10-27T12:05:00Z', validada_at: null, observacion: null, segmentos: [] },
  };
}

test('la PDA sugiere el bin de planta, lo deja corregir y lo envía al confirmar', async () => {
  const pda = harness(reception([['bins', 1], ['totes', 31]]));
  await pda.mount();
  await pda.open('REC-2610-0001');

  const plantBins = () => pda.input('Bins de planta agregados');
  assert.equal(plantBins().props.value, '1');
  assert.match(pda.text(), /31 totes a 24 por bin necesitan 2 bins; llegaron 1/);
  // Si el conteo real trae 2 bins, ya no falta ninguno.
  const [bins] = pda.countInputs();
  await pda.type(bins, '2');
  assert.equal(plantBins().props.value, '0');
  await pda.type(bins, '1');
  await pda.type(plantBins(), '');
  assert.equal(plantBins().props.value, '');
  await pda.type(plantBins(), '2');

  await pda.press('Tarjas de campo verificadas visualmente');
  await pda.press('11111');
  await pda.press('Santina');
  await pda.press('Confirmar Validación MP');
  assert.equal(pda.confirmations.length, 1);
  assert.equal(pda.confirmations[0].plantBins, 2);
  await pda.unmount();
});

test('con segregación cada segmento indica sus bins de planta y el total es la suma', async () => {
  const pda = harness(reception([['bins', 2], ['totes', 60]]));
  await pda.mount();
  await pda.open('REC-2610-0001');
  await pda.press('Sí');
  const segmentInput = (index) => pda.input(`Bins de planta del segmento ${index}`);
  assert.ok(segmentInput(1) && segmentInput(2));
  await pda.type(segmentInput(1), '1');
  await pda.type(segmentInput(2), '1');
  assert.match(pda.text(), /Indícalos en cada segmento/);
  assert.equal(pda.total(), '2');
  assert.equal(pda.input('Bins de planta agregados'), undefined);
  await pda.unmount();
});

test('los vacíos se cuentan aparte, no piden bins de planta y viajan en la confirmación', async () => {
  const base = reception([['bins', 1], ['totes', 48]]);
  base.envases = base.envases.map((item) => ({ ...item, contiene_fruta: true }));
  const pda = harness(base);
  await pda.mount();
  await pda.open('REC-2610-0001');
  const empties = () => pda.input('Totes vacíos');
  assert.equal(pda.input('Bins de planta agregados').props.value, '1');
  await pda.type(empties(), '24');
  assert.match(pda.text(), /Con fruta: 24\. Cuentan para la cuenta corriente y la tara, no para el peso promedio\./);
  // 24 totes con fruta caben en el bin que llegó.
  assert.equal(pda.input('Bins de planta agregados').props.value, '0');
  await pda.press('Tarjas de campo verificadas visualmente');
  await pda.press('11111');
  await pda.press('Santina');
  await pda.type(empties(), '49');
  await pda.press('Confirmar Validación MP');
  assert.match(pda.text(), /Totes: los vacíos no pueden superar los contados\./);
  assert.equal(pda.confirmations.length, 0);
  await pda.type(empties(), '24');
  await pda.press('Confirmar Validación MP');
  assert.deepEqual(pda.confirmations[0].containers, [{ tipo_envase: 'bins', cantidad_validada: 1 }, { tipo_envase: 'totes', cantidad_validada: 48, cantidad_vacia: 24 }]);
  await pda.unmount();
});

test('un servidor que no informa si el envase trae fruta no pide vacíos', async () => {
  const pda = harness(reception([['bins', 1], ['totes', 48]]));
  await pda.mount();
  await pda.open('REC-2610-0001');
  assert.equal(pda.input('Totes vacíos'), undefined);
  await pda.unmount();
});

test('sin totes en la recepción no se ofrece el campo', async () => {
  const pda = harness(reception([['bins', 20]]));
  await pda.mount();
  await pda.open('REC-2610-0001');
  assert.equal(pda.input('Bins de planta agregados'), undefined);
  await pda.unmount();
});


test('MP identifica el segmento incompleto antes de enviar y conserva el conteo al corregirlo', async () => {
  const pda = harness(reception([['bins', 2], ['totes', 48]]));
  await pda.mount();
  try {
    await pda.open('REC-2610-0001');
    await pda.press('Tarjas de campo verificadas visualmente');
    await pda.press('Sí');
    for (const index of [0, 1]) {
      await pda.pressSegment(index, '11111');
      await pda.pressSegment(index, 'Santina');
      const inputs = pda.segmentInputs(index).filter((node) => !node.props.accessibilityLabel);
      await pda.type(inputs[0], '1');
      await pda.type(pda.segmentInputs(index).filter((node) => !node.props.accessibilityLabel)[1], '24');
    }
    await pda.press('Confirmar Validación MP');
    assert.match(pda.text(), /Segmento 1: selecciona al menos un motivo de segregación/);
    assert.equal(pda.confirmations.length, 0);
    await pda.pressSegment(0, 'Csg');
    await pda.press('Confirmar Validación MP');
    assert.match(pda.text(), /Segmento 2: selecciona al menos un motivo de segregación/);
    assert.equal(pda.confirmations.length, 0);
    await pda.pressSegment(1, 'Cuartel');
    await pda.press('Confirmar Validación MP');
    assert.match(pda.text(), /Segmento 2: ingresa el cuartel/);
    assert.equal(pda.confirmations.length, 0);
    await pda.type(pda.segmentInputs(1).find((node) => node.props.placeholder === 'Cuartel'), 'B');
    await pda.press('Confirmar Validación MP');
    assert.equal(pda.confirmations.length, 1);
    assert.deepEqual(pda.confirmations[0].segments.map((segment) => ({
      motivos: segment.motivos, csg: segment.csg_validacion_id, variedad: segment.variedad_validacion_id, cantidades: segment.cantidades,
    })), [
      { motivos: ['csg'], csg: 'csg-1', variedad: 'santina', cantidades: { bins: '1', totes: '24' } },
      { motivos: ['cuartel'], csg: 'csg-1', variedad: 'santina', cantidades: { bins: '1', totes: '24' } },
    ]);
    assert.equal(pda.confirmations[0].segments[1].cuartel, 'B');
  } finally { await pda.unmount(); }
});

test('varias guías de Romana abren su desglose automáticamente y conservan guía, origen y cantidades al confirmar', async () => {
  const item = reception([['bins', 4], ['totes', 96]]);
  item.guias = ['1511', '1512'].map((n) => ({ id: `guia-${n}`, numero_guia_despacho: n,
    envases: [{ tipo_envase: 'bins', cantidad: 2 }, { tipo_envase: 'totes', cantidad: 48 }] }));
  const pda = harness(item);
  await pda.mount(); await pda.open(item.numero_recepcion);
  assert.match(pda.text(), /Desglose por guía de Romana/);
  assert.match(pda.text(), /1511 \/ 1512/);
  assert.equal(pda.input('Bins de planta agregados'), undefined);
  for (let index = 0; index < 2; index++) {
    const inputs = pda.segmentInputs(index).filter((n) => !n.props.accessibilityLabel);
    assert.deepEqual(inputs.map((n) => n.props.value), ['2', '48']);
    await pda.pressSegment(index, '11111'); await pda.pressSegment(index, 'Santina');
  }
  await pda.press('Tarjas de campo verificadas visualmente');
  await pda.press('Confirmar Validación MP');
  assert.equal(pda.confirmations.length, 1);
  assert.deepEqual(pda.confirmations[0].segments.map((s) => s.guia_recepcion_romana_id), ['guia-1511', 'guia-1512']);
  assert.equal(pda.confirmations[0].segregation, true);
  await pda.unmount();
});
