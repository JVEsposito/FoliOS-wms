import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
const source = readFileSync(new URL('../../resources/js/office-hydrocooler.js', import.meta.url), 'utf8').replace(/^import .*;\n/gm, '').replace(/void boot\(\);\s*$/, '');
function fixture() {
    const fields = Object.fromEntries(['lote_id', 'operacion_id', 'operador', 'inicio_at', 'equipo', 'producto_hidrocooler_id', 'producto_dosis', 'producto_unidad_dosis', 'aplicacion_producto', 'cloro_libre_ppm', 'correccion_cloro_ppm'].map((key) => [key, { value: '', focus() {} }]));
    const nodes = new Map(); const listeners = new Map();
    const node = (id) => {
        if (!nodes.has(id)) nodes.set(id, { textContent: '', innerHTML: '', addEventListener: (name, fn) => listeners.set(`${id}:${name}`, fn), classList: { toggle() {}, add() {}, remove() {} }, setAttribute() {} });
        return nodes.get(id);
    };
    Object.assign(node('startForm'), { elements: fields, reset() {}, reportValidity() { return true; } });
    node('startDialog').showModal = () => {};
    const context = vm.createContext({ document: { getElementById: node, querySelectorAll: () => [] }, localStorage: { getItem: () => null }, crypto: { randomUUID: () => 'op' }, window: {}, containerLabel: () => undefined, loadContainerCatalog: async () => [], Headers, URLSearchParams });
    vm.runInContext(source, context);
    vm.runInContext(`state.lots = [{id:'lot',numero_lote:'L-1',trazabilidad:{},envases:{},pesos:{}}]; state.products=[{id:'product',nombre:'Prueba',unidad_dosis:'ml/L'}]; state.chlorineRange={min:90,max:110}; openStart('lot');`, context);
    return { fields, listeners, context, node };
}
test('inicio no asume respuestas y exige producto, dosis y unidad cuando se aplica', () => {
    const f = fixture();
    assert.equal(f.fields.aplicacion_producto.value, '');
    assert.equal(f.fields.producto_dosis.disabled, true);
    f.fields.aplicacion_producto.value = '1';
    vm.runInContext('updateStartControls()', f.context);
    assert.equal(f.fields.producto_dosis.required, true);
    assert.equal(f.fields.producto_hidrocooler_id.required, true);
    f.fields.producto_hidrocooler_id.value = 'product';
    f.listeners.get('startForm:change')({ target: { name: 'producto_hidrocooler_id', value: 'product' } });
    assert.equal(f.fields.producto_unidad_dosis.value, 'ml/L');
    f.fields.aplicacion_producto.value = '0';
    vm.runInContext('updateStartControls()', f.context);
    assert.equal(f.fields.producto_hidrocooler_id.value, '');
    assert.equal(f.fields.producto_unidad_dosis.disabled, true);
});
test('corrección se exige solo con rango configurado y lectura fuera de sus límites', () => {
    const f = fixture();
    f.fields.cloro_libre_ppm.value = '40'; vm.runInContext('updateStartControls()', f.context);
    assert.equal(f.fields.correccion_cloro_ppm.required, true);
    f.fields.cloro_libre_ppm.value = '90'; vm.runInContext('updateStartControls()', f.context);
    assert.equal(f.fields.correccion_cloro_ppm.required, false);
    f.fields.cloro_libre_ppm.value = '111'; vm.runInContext('updateStartControls()', f.context);
    assert.equal(f.fields.correccion_cloro_ppm.required, true);
    vm.runInContext('state.chlorineRange=null; updateStartControls()', f.context);
    assert.equal(f.fields.correccion_cloro_ppm.required, false);
    assert.match(f.node('chlorineRangeHint').textContent, /pendiente/);
});
test('lectura de bandeja conserva catálogo y rango entregados por el servidor', async () => {
    const f = fixture();
    vm.runInContext(`api = async (path) => path.endsWith('/productos') ? {data:[{id:'p2',nombre:'Otro',unidad_dosis:'g/L'}],rango_cloro_ppm:{min:10,max:20}} : path.includes('/lotes?') ? {data:[]} : {}; renderSummary=()=>{}; renderLots=()=>{}; query=()=>'';`, f.context);
    await vm.runInContext('load({silent:true})', f.context);
    assert.equal(vm.runInContext('state.products[0].unidad_dosis', f.context), 'g/L');
    assert.equal(vm.runInContext('state.chlorineRange.min', f.context), 10);
});
