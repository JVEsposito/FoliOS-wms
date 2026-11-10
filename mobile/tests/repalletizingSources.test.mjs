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

globalThis.IS_REACT_ACT_ENVIRONMENT = true;
const require = createRequire(import.meta.url);
const sourceRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../src');
const clone = (value) => JSON.parse(JSON.stringify(value));
const folio = (number, type, boxes, overrides = {}) => ({
  existe: true, id: number, numero_folio: number, tipo_bulto: type, cantidad_cajas: boxes, activo: true,
  cliente: 'Cliente', especie: 'Cereza', marca: 'Marca', variedad: 'Santina', calibre: '2J', envase: '5 kg', categoria: 'Exportación',
  condicion_termica: 'pendiente_prefrio', csg: '111', predio: 'Huerto', cuartel: 'A', ubicacion: null,
  composicion: [{ clave: `line-${number}`, csg: '111', fecha_embalaje: '2026-11-06', predio: 'Huerto', cantidad_cajas: boxes }], ...overrides,
});

function harness(entries) {
  let renderer; let sequence = 0;
  const writes = []; const reads = []; const modules = new Map();
  const native = {
    ActivityIndicator: 'ActivityIndicator', Pressable: 'Pressable', ScrollView: 'ScrollView', Text: 'Text', TextInput: 'TextInput', View: 'View',
    StyleSheet: { create: (styles) => styles }, useWindowDimensions: () => ({ width: 390, height: 800 }),
  };
  function load(path) {
    if (modules.has(path)) return modules.get(path).exports;
    const module = { exports: {} }; modules.set(path, module);
    const code = ts.transpileModule(readFileSync(path, 'utf8'), { compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 }, fileName: path }).outputText;
    vm.runInNewContext(code, {
      module, exports: module.exports, console,
      require: (name) => {
        const mock = fieldUiMock(name); if (mock) return mock;
        if (name === 'react-native') return native;
        if (name === 'expo-crypto') return { randomUUID: () => `operation-${++sequence}` };
        if (name === '@react-native-async-storage/async-storage') return { getItem: async () => 'A', setItem: async () => {} };
        if (name.endsWith('/config/appVariant')) return { isPdaBuild: true };
        if (name.endsWith('/components/ui/ScanInput')) return { ScanInput: 'ScanInput' };
        if (name.endsWith('/services/repaletizajeApi')) return {
          listRepalletizings: async () => [],
          findRepalletizingFolio: async (_url, _token, number) => { reads.push(number); return clone(entries.find((entry) => entry.numero_folio === number) ?? { existe: false }); },
          createRepalletizing: async (_url, _token, payload) => { writes.push(clone(payload)); return { codigo: 'REPA-TEST', folio_resultante: { numero_folio: payload.numero_folio_resultante } }; },
        };
        if (name.startsWith('.')) {
          const base = resolve(dirname(path), name);
          for (const extension of ['.ts', '.tsx']) { try { readFileSync(base + extension); } catch { continue; } return load(base + extension); }
        }
        return require(name);
      },
    }, { filename: path });
    return module.exports;
  }
  const { RepalletizingScreen } = load(resolve(sourceRoot, 'screens/RepalletizingScreen.tsx'));
  const flatten = (value) => Array.isArray(value) ? value.map(flatten).join('') : typeof value === 'string' || typeof value === 'number' ? String(value) : value?.props ? flatten(value.props.children) : '';
  async function flush(action = () => {}) { await act(async () => { await action(); for (let n = 0; n < 30; n++) await Promise.resolve(); }); }
  const field = (label) => renderer.root.find((node) => typeof node.type === 'function' && node.props.label === label);
  return {
    writes, reads,
    async scanRepacking(number) { await flush(() => renderer.root.findAllByType('ScanInput').find((n) => n.props.submitLabel === 'Consultar').props.onSubmit(number)); },
    async typeRepacking(label, value) { await flush(() => renderer.root.findAllByType('TextInput').find((n) => n.props.accessibilityLabel === label).props.onChangeText(value)); },
    async mount() { await flush(() => { renderer = create(React.createElement(RepalletizingScreen, { auth: { token: 'token', usuario: { id: '1' }, dispositivo: { id: 'pda' } }, baseUrl: 'http://planta', onLogout: () => {} })); }); },
    async unmount() { await flush(() => renderer.unmount()); },
    async add(number) { await flush(() => renderer.root.findAllByType('ScanInput').find((node) => node.props.submitLabel === 'Agregar').props.onSubmit(number)); },
    async capacity(value) { await flush(() => field('Capacidad del pallet').findByType('TextInput').props.onChangeText(value)); },
    async number(value) { await flush(() => field('Folio resultante').findByType('ScanInput').props.onChangeText(value)); },
    async contribution(index, value) { await flush(() => renderer.root.findAll((node) => typeof node.type === 'function' && node.props.label === 'Aporta')[index].findByType('TextInput').props.onChangeText(value)); },
    async press(label) {
      const button = renderer.root.findAllByType('Pressable').find((node) => node.findAllByType('Text').some((text) => flatten(text.props.children) === label));
      assert.ok(button, `Botón disponible: ${label}`); assert.ok(!button.props.disabled);
      await flush(button.props.onPress);
    },
    message: () => renderer.root.findAllByType('Text').map((node) => flatten(node.props.children)).join('\n'),
    resultLines: () => renderer.root.findAllByType('View').filter((node) => node.props.testID?.startsWith('repa-composition-result-')).map((node) => flatten(node.props.children)),
  };
}

test('la PDA admite un pallet completo como donante parcial junto a un saldo y evita agregarlo dos veces', async () => {
  const h = harness([folio('PAL-240', 'pallet', 240), folio('SAL-40', 'saldo', 40)]);
  try {
    await h.mount(); await h.capacity('240'); await h.press('Otro folio'); await h.number('NUEVO-240');
    await h.add('PAL-240');
    await h.add('PAL-240'); assert.match(h.message(), /Ese folio ya fue agregado/);
    assert.equal(h.reads.filter((number) => number === 'PAL-240').length, 1);
    await h.add('SAL-40'); await h.contribution(0, '200');
    await h.press('Confirmar repaletizaje');
    assert.equal(h.writes.length, 1);
    assert.equal(h.writes[0].cantidad_objetivo, 240);
    assert.deepEqual(h.writes[0].origenes.map(({ folio_id, cantidad_aportada }) => ({ folio_id, cantidad_aportada })), [
      { folio_id: 'PAL-240', cantidad_aportada: 200 }, { folio_id: 'SAL-40', cantidad_aportada: 40 },
    ]);
    assert.match(h.message(), /NUEVO-240 confirmado/);
  } finally { await h.unmount(); }
});

test('la PDA muestra calibres diferentes del mismo CSG/fecha y permite elegir solo sus cajas', async () => {
  const mixed = folio('MIX-240', 'pallet', 240, { calibre: 'MIX', composicion: [
    { clave: 'calibre-2j', csg: '111', fecha_embalaje: '2026-11-06', predio: 'Huerto', variedad: 'Royal Dawn', calibre: '2J', envase: '5 kg', cantidad_cajas: 100 },
    { clave: 'calibre-3j', csg: '111', fecha_embalaje: '2026-11-06', predio: 'Huerto', variedad: 'Royal Dawn', calibre: '3J', envase: '5 kg', cantidad_cajas: 140 },
  ] });
  const h = harness([mixed, folio('SAL-40', 'saldo', 40)]);
  try {
    await h.mount(); await h.capacity('240'); await h.press('Saldo consolidado'); await h.press('Otro folio'); await h.number('RESULTADO-MIX');
    await h.add('MIX-240');
    assert.match(h.message(), /Calibre 2J/); assert.match(h.message(), /Calibre 3J/); assert.match(h.message(), /Variedad Royal Dawn/);
    assert.equal(h.resultLines().length, 2);
    await h.add('SAL-40'); await h.contribution(0, '0'); await h.contribution(1, '60');
    assert.equal(h.resultLines().length, 2);
    assert.ok(h.resultLines().some((text) => text.includes('Calibre 3J') && text.includes('60 cajas')));
    await h.press('Confirmar repaletizaje');
    assert.equal(h.writes.length, 1);
    assert.deepEqual(h.writes[0].origenes[0].composicion, [{ clave: 'calibre-3j', cantidad_aportada: 60 }]);
  } finally { await h.unmount(); }
});

test('la PDA identifica un MIX sin detalle y evita aportes parciales que no se pueden atribuir', async () => {
  const h = harness([folio('SIN-DETALLE', 'pallet', 240, { calibre: 'MIX', desglose_incompleto: true }), folio('SAL-40', 'saldo', 40)]);
  try {
    await h.mount(); await h.capacity('240'); await h.press('Otro folio'); await h.number('NO-ADIVINAR');
    await h.add('SIN-DETALLE'); await h.add('SAL-40'); await h.contribution(0, '200');
    assert.match(h.message(), /MIX sin cantidades por componente/);
    await h.press('Confirmar repaletizaje');
    assert.equal(h.writes.length, 0); assert.match(h.message(), /Regulariza el desglose/);
  } finally { await h.unmount(); }
});

test('la PDA consolida dos pallets completos conservando uno de sus folios', async () => {
  const h = harness([folio('PAL-A', 'pallet', 120), folio('PAL-B', 'pallet', 120)]);
  try {
    await h.mount(); await h.capacity('240'); await h.add('PAL-A'); await h.add('PAL-B');
    await h.press('Confirmar repaletizaje');
    assert.equal(h.writes.length, 1);
    assert.equal(h.writes[0].estrategia_folio, 'conservar'); assert.equal(h.writes[0].folio_conservado_id, 'PAL-A');
    assert.equal(h.writes[0].origenes.reduce((sum, row) => sum + row.cantidad_aportada, 0), 240);
  } finally { await h.unmount(); }
});

test('admitir pallets completos mantiene los bloqueos de inactivos, cajas agotadas y estado térmico retenido', async () => {
  const h = harness([folio('INACTIVO', 'pallet', 240, { activo: false }), folio('AGOTADO', 'pallet', 0), folio('RETENIDO', 'pallet', 240, { condicion_termica: 'retenido' })]);
  try {
    await h.mount();
    await h.add('INACTIVO'); assert.match(h.message(), /no es un pallet o saldo activo/);
    await h.add('AGOTADO'); assert.match(h.message(), /cajas disponibles/);
    await h.add('RETENIDO'); assert.match(h.message(), /estado térmico transitorio o retenido/);
    await h.press('Confirmar repaletizaje'); assert.match(h.message(), /Agrega al menos dos folios/);
    assert.equal(h.writes.length, 0);
  } finally { await h.unmount(); }
});

for (const format of [{ id: 'half', weight: '2.5000', loss: '0' }, { id: 'two', weight: '2.0000', loss: '1' }]) {
  test(`PDA/tablet reembala con formato ${format.weight}, conserva la guía de composición y exige merma y motivo`, async () => {
    const source = folio('ORIGEN-5', 'saldo', 1, { reembalaje: {
      huella: 'fingerprint', errores: [], kilos_entrada: '5.0000',
      componentes: [{ clave: 'component', csg: '111', cantidad_cajas: 1, kilos_netos_por_caja: '5.0000', kilos_entrada: '5.0000' }],
      formatos: [{ id: format.id, nombre: 'Formato destino', kilos_netos_por_caja: format.weight }],
    } });
    const pda = harness([source]); await pda.mount();
    await pda.press('Reembalaje · convertir formatos'); await pda.scanRepacking('ORIGEN-5');
    await pda.press(`Formato destino · ${format.weight} kg`);
    await pda.typeRepacking('Cajas salida 1 CSG 111', '2');
    if (format.loss !== '0') {
      await pda.press('Confirmar reembalaje'); assert.equal(pda.writes.length, 0);
      await pda.typeRepacking('Merma kg CSG 111', format.loss);
      await pda.press('Confirmar reembalaje'); assert.equal(pda.writes.length, 0);
      await pda.typeRepacking('Motivo de la merma', 'Fruta descartada durante el reembalaje');
      await pda.press('Otro folio'); await pda.typeRepacking('Folio de salida 1', 'NUEVO-2');
    }
    await pda.press('Confirmar reembalaje'); assert.equal(pda.writes.length, 1);
    const payload = pda.writes[0]; assert.equal(payload.modalidad, 'reembalaje');
    assert.equal(payload.resultados[0].cantidad_resultante, 2);
    assert.equal(payload.resultados[0].envase_validacion_id, format.id);
    assert.equal(payload.resultados[0].numero_folio, format.loss === '0' ? 'ORIGEN-5' : 'NUEVO-2');
    assert.deepEqual(payload.resultados[0].composicion, [{ clave: 'component', cantidad_cajas: 2 }]);
    assert.equal(payload.huella_reembalaje, 'fingerprint');
    await pda.unmount();
  });
}
