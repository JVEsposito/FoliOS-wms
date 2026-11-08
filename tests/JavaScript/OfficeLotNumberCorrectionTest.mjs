import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync(new URL('../../resources/js/office-raw-material.js', import.meta.url), 'utf8')
    .replace(/^import .*;\n/gm, '').replace(/void boot\(\);\s*$/, '');

function fixture(role = 'administrador') {
    const nodes = new Map(); const writes = [];
    function node(id) {
        if (!nodes.has(id)) nodes.set(id, { textContent: '', innerHTML: '', value: '',
            addEventListener() {}, reset() {}, showModal() {}, querySelector() { return null; },
            classList: { toggle() {}, add() {}, remove() {} } });
        return nodes.get(id);
    }
    const fields = new Proxy({}, { get: (target, key) => target[key] ||= { value: '', addEventListener() {} } });
    node('lotForm').elements = fields;
    node('operationForm').elements = fields;
    const context = vm.createContext({
        document: { getElementById: node }, localStorage: { getItem: () => null },
        Headers, URLSearchParams, Intl, window: { setTimeout() {} },
        crypto: { randomUUID: () => '11111111-1111-4111-8111-111111111111' },
        FormData: class { constructor(form) { this.form = form; } [Symbol.iterator]() { return Object.entries(this.form.elements).map(([k, field]) => [k, field.value])[Symbol.iterator](); } },
        containerLabel: () => null,
        fetch: async (path, options) => { writes.push({ path, payload: JSON.parse(options.body), method: options.method }); return { status: 200, ok: true, json: async () => ({}) }; },
    });
    vm.runInContext(source, context);
    context.role = role;
    vm.runInContext(`state.identity = { rol: role }; state.catalogs = {camaras: []}; state.lots = [
        { id: 'lote-la-aguada', numero_lote: '10056', estado: 'entregado_proceso', version: 7, puede_corregir_numero: true, trazabilidad: {},
          cliente: {nombre: 'LA AGUADA'}, recepcion: {numero_recepcion: 'REC-2611-0018', numero_guia_despacho: '13405'} },
        { id: 'lote-disfruta', numero_lote: '10056', estado: 'asignado_camara', version: 4, puede_corregir_numero: true, trazabilidad: {},
          cliente: {nombre: 'DISFRUTA'}, recepcion: {numero_recepcion: 'REC-2611-0017', numero_guia_despacho: '4443'} }
    ];`, context);
    return { context, nodes, fields, writes };
}

test('la corrección se ofrece al administrador para lotes asignados y entregados, conservando las restricciones de otros roles', () => {
    const admin = fixture();
    assert.match(vm.runInContext('lotActions(state.lots[0])', admin.context), /correct-number/);
    assert.match(vm.runInContext('lotActions(state.lots[1])', admin.context), /correct-number/);
    for (const role of ['digitador_materia_prima', 'supervisor_frio', 'consulta']) {
        const f = fixture(role);
        assert.doesNotMatch(vm.runInContext('lotActions(state.lots[0])', f.context), /correct-number/);
    }
    vm.runInContext(`state.lots[0].estado = 'anulado'`, admin.context);
    assert.doesNotMatch(vm.runInContext('lotActions(state.lots[0])', admin.context), /correct-number/);
});

test('dos filas 10056 se corrigen por su registro: el formulario identifica DISFRUTA, recepción 0017 y guía 4443', async () => {
    const f = fixture();
    vm.runInContext(`openOperation('correct-number', 'lote-disfruta')`, f.context);
    assert.match(f.nodes.get('operationDescription').textContent, /DISFRUTA.*REC-2611-0017.*4443/);
    assert.match(f.nodes.get('operationFields').innerHTML, /name="numero_lote"/);
    f.fields.numero_lote.value = '10057'; f.fields.motivo.value = 'Error de digitación en el documento.';
    await vm.runInContext('submitOperation()', f.context);
    assert.equal(f.writes[0].path, '/api/materia-prima/lotes/lote-disfruta/corregir-numero');
    assert.equal(f.writes[0].method, 'PUT');
    assert.deepEqual(f.writes[0].payload, { operacion_id: '11111111-1111-4111-8111-111111111111',
        version_conocida: 4, numero_lote: '10057', motivo: 'Error de digitación en el documento.' });
    assert.equal(vm.runInContext('state.lots[0].numero_lote', f.context), '10056');
});
