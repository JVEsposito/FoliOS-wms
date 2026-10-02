import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync(new URL('../../resources/js/office-hydrocooler.js', import.meta.url), 'utf8')
    .replace(/^import .*;\n/gm, '')
    .replace(/void boot\(\);\s*$/, '');

function fixture(primario) {
    const listeners = new Map();
    const direct = { disabled: false, closest: () => ({ classList: { toggle: () => {} } }) };
    const fields = Object.fromEntries(['lote_id', 'operacion_id', 'termino_at'].map((name) => [name, { value: '', focus: () => {} }]));
    fields.destino_salida = { value: '' };
    let opened = false;
    const nodes = new Map();
    function node(id) {
        if (!nodes.has(id)) nodes.set(id, {
            textContent: '', innerHTML: '', dataset: {},
            addEventListener: (name, listener) => listeners.set(`${id}:${name}`, listener),
        });
        return nodes.get(id);
    }
    Object.assign(node('finishForm'), {
        elements: fields,
        reset: () => {},
        querySelector: (selector) => {
            assert.equal(selector, 'input[name="destino_salida"][value="proceso"]');
            return direct;
        },
    });
    node('finishDialog').showModal = () => { opened = true; };
    const context = vm.createContext({
        document: { getElementById: node, querySelectorAll: () => [] },
        localStorage: { getItem: () => null },
        crypto: { randomUUID: () => 'operation-id' },
        window: {}, containerLabel: () => undefined, loadContainerCatalog: async () => [],
    });
    vm.runInContext(source, context);
    vm.runInContext(`state.lots = [${JSON.stringify({
        id: 'lot-1', numero_lote: 'TES001', hidrocooler: {
            codigo: 'HID-1', equipo: 'Hidrocooler 1', inicio_at: '2026-09-29T15:00:00Z',
        },
        trazabilidad: { especie: 'Uva', variedad: 'A' },
        envases: { primario, cantidad_primarios: 104 },
        pesos: { kilos_netos_confirmados: 1200 },
    })}];`, context);
    listeners.get('hydrocoolerList:click')({ target: { closest: (selector) => selector === '[data-finish]'
        ? { dataset: { finish: 'lot-1' } } : null } });
    return { opened, fields, direct, title: node('finishTitle').textContent };
}

test('Finalizar ciclo abre el diálogo para un lote en curso con bins', () => {
    const result = fixture('bins');
    assert.equal(result.opened, true);
    assert.equal(result.title, 'Finalizar TES001');
    assert.equal(result.fields.lote_id.value, 'lot-1');
    assert.equal(result.direct.disabled, false);
});

test('Finalizar ciclo abre el diálogo para totes y deshabilita salida directa a proceso', () => {
    const result = fixture('totes');
    assert.equal(result.opened, true);
    assert.equal(result.direct.disabled, true);
});
