import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync(new URL('../../resources/js/office-validation-catalog.js', import.meta.url), 'utf8');

function harness(responses) {
    const nodes = new Map();
    function node(id = '') {
        const classes = new Set(['is-hidden']);
        const fields = new Map();
        return {
            textContent: '', innerHTML: '', value: '', children: [], data: [['nombre', 'Categoría']],
            classList: {
                add: (name) => classes.add(name), remove: (name) => classes.delete(name),
                contains: (name) => classes.has(name),
                toggle: (name, force) => force ? classes.add(name) : classes.delete(name),
            },
            elements: new Proxy({}, { get: (_, key) => {
                if (!fields.has(key)) fields.set(key, { value: '', checked: true });
                return fields.get(key);
            } }),
            addEventListener() {}, setAttribute() {}, reset() {}, remove() {},
            getAttribute: () => id, querySelectorAll: () => [],
            append(child) { this.children.push(child); },
        };
    }
    const byId = (id) => {
        if (!nodes.has(id)) nodes.set(id, node(id));
        return nodes.get(id);
    };
    const requests = [];
    const timeouts = [];
    const context = vm.createContext({
        Headers,
        FormData: class {
            constructor(form) { this.data = form.data; }
            [Symbol.iterator]() { return this.data[Symbol.iterator](); }
        },
        localStorage: { getItem: () => null },
        document: { getElementById: byId, createElement: () => node(), addEventListener() {} },
        window: { setTimeout(callback, delay) {
            timeouts.push(delay);
            if (delay === 600) callback();
        } },
        fetch: async (path, options) => {
            requests.push({ path, method: options.method || 'GET' });
            const response = responses.shift();
            assert.ok(response, `Unexpected request: ${path}`);
            if (response instanceof Error) throw response;
            return { ok: response.status < 400, status: response.status,
                json: async () => response.body || { message: 'Server Error' } };
        },
    });
    vm.runInContext(source.replace('if (verifyAccess()) void load();', 'globalThis.catalog = { state, load, save };'), context);
    return { ...context.catalog, byId, requests, timeouts };
}

const admin = (id = 'A') => ({ status: 200, body: {
    temporadas: [{ id, nombre: id }], temporada: { id, nombre: id }, importaciones: [],
} });
const hierarchy = { status: 200, body: { clientes: [], categorias: [], especies: [], csg: [],
    proyeccion: { articulos: 150, origenes: 200, combinaciones: 30000 } } };

test('Maestros carga el resumen y los contadores sin pedir la proyección completa', async () => {
    const h = harness([admin(), hierarchy]);
    assert.equal(await h.load('A'), true);
    assert.equal(h.requests[0].path, '/api/administracion/validacion?resumen=1&temporada_id=A');
    assert.equal(h.requests[1].path, '/api/administracion/validacion/temporadas/A/catalogo');
    assert.equal(h.byId('projectionCombinationCount').textContent, 30000);
    assert.equal(h.byId('catalogLoadError').classList.contains('is-hidden'), true);
});

test('un 500 se informa de forma persistente y no dispara otra petición costosa', async () => {
    const h = harness([{ status: 500 }]);
    assert.equal(await h.load(), false);
    assert.equal(h.requests.length, 1);
    assert.equal(h.state.loaded, false);
    assert.match(h.byId('catalogLoadError').textContent, /todavía no se han cargado/);
    assert.equal(h.byId('catalogLoadError').classList.contains('is-hidden'), false);
    assert.equal(h.byId('catalogLoading').classList.contains('is-hidden'), true);
    assert.equal(h.timeouts.length, 0);
});

test('conserva la temporada anterior si falla la jerarquía y permite recuperar con Actualizar', async () => {
    const h = harness([admin('A'), hierarchy, admin('B'), { status: 500 }, admin('B'), hierarchy]);
    await h.load('A');
    assert.equal(await h.load('B'), false);
    assert.equal(h.state.season.id, 'A');
    assert.equal(h.byId('catalogSeasonSelector').value, 'A');
    assert.match(h.byId('catalogLoadError').textContent, /últimos datos cargados/);
    assert.equal(await h.load('B'), true);
    assert.equal(h.state.season.id, 'B');
    assert.equal(h.byId('catalogLoadError').classList.contains('is-hidden'), true);
});

test('solo reintenta una desconexión y no modifica la petición de escritura', async () => {
    const h = harness([new Error('offline'), admin(), hierarchy]);
    assert.equal(await h.load(), true);
    assert.equal(h.requests.length, 3);
    assert.deepEqual(h.timeouts, [600]);
});

test('un guardado seguido de un refresco fallido no anuncia catálogo publicado ni repite el POST', async () => {
    const h = harness([admin(), hierarchy, { status: 201, body: {} }, { status: 500 }]);
    await h.load();
    await h.save('category');
    assert.equal(h.requests.filter((r) => r.method === 'POST').length, 1);
    const messages = h.byId('catalogToasts').children.map((n) => n.textContent);
    assert.equal(messages.some((m) => m.includes('publicado')), false);
    assert.match(messages.at(-1), /Cambio guardado.*No fue posible refrescar/);
    assert.equal(h.byId('catalogLoadError').classList.contains('is-hidden'), false);
});
