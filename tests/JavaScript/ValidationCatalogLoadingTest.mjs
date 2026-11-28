import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import { optionMatcher } from '../../resources/js/shared/validation-correction-logic.js';

const source = readFileSync(new URL('../../resources/js/office-validation-catalog.js', import.meta.url), 'utf8').replace(/^import .*;\n/gm, '');

function harness(responses) {
    const nodes = new Map();
    const listeners = new Map();
    let selected = 'clients';
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
                if (!fields.has(key)) fields.set(key, { value: '', checked: true, options: [] });
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
        optionMatcher,
        FormData: class {
            constructor(form) { this.data = form.data; }
            [Symbol.iterator]() { return this.data[Symbol.iterator](); }
            set(name, value) { this.data = [...this.data, [name, value]]; }
        },
        localStorage: { getItem: () => null },
        document: {
            getElementById: byId, createElement: () => node(),
            querySelector: () => ({ dataset: { officePanelTarget: selected } }),
            addEventListener: (name, handler) => listeners.set(name, handler),
        },
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
    vm.runInContext(source.replace('if (verifyAccess()) void load();', 'globalThis.catalog = { state, load, save, submitImport };'), context);
    return {
        ...context.catalog, byId, requests, timeouts,
        select(panel) {
            selected = panel;
            listeners.get('estiba:office-panel-change')({ detail: { group: 'validation-catalog', panel } });
        },
    };
}

const catalog = (id = 'A', version = 7) => ({ status: 200, body: {
    temporadas: [{ id, nombre: id }], temporada: { id, nombre: id, version_catalogo: version }, importaciones: [],
    clientes: [{ id: 'client', nombre: `Cliente ${id}`, activo: true }],
    categorias: [{ id: 'category', nombre: `Categoría ${id}`, activo: true }],
    especies: [],
    csg: [{ id: 'csg', codigo: `CSG-${id}`, activo: true }],
    proyeccion: { articulos: 150, origenes: 200, combinaciones: 30000 },
} });

test('Maestros obtiene el resumen y los contadores en una sola petición', async () => {
    const h = harness([catalog()]);
    assert.equal(await h.load('A'), true);
    assert.deepEqual(h.requests, [{ path: '/api/administracion/validacion/maestros?temporada_id=A', method: 'GET' }]);
    assert.equal(h.byId('projectionCombinationCount').textContent, 30000);
    assert.equal(h.byId('catalogLoadError').classList.contains('is-hidden'), true);
});

test('las pestañas se renderizan al abrirlas y reflejan la nueva temporada tras cambiarla', async () => {
    const h = harness([catalog(), catalog('B')]);
    await h.load();
    assert.match(h.byId('clientList').innerHTML, /Cliente A/);
    assert.equal(h.byId('csgList').innerHTML, '');
    h.select('csg');
    assert.match(h.byId('csgList').innerHTML, /CSG-A/);
    assert.equal(h.byId('categoryList').innerHTML, '');
    await h.load('B');
    assert.match(h.byId('csgList').innerHTML, /CSG-B/);
    h.select('clients');
    assert.match(h.byId('clientList').innerHTML, /Cliente B/);
    assert.equal(h.requests.length, 2);
});

test('previsualizar una importación actualiza su historial sin volver a descargar el catálogo', async () => {
    const h = harness([catalog(), { status: 201, body: { data: {
        id: 'borrador', nombre_archivo: 'maestros.csv', estado: 'borrador', resumen: { filas_validas: 1 },
    } } }]);
    h.select('imports');
    await h.load();
    await h.submitImport({ preventDefault() {} });
    assert.match(h.byId('importList').innerHTML, /maestros.csv/);
    assert.match(h.byId('importPreview').innerHTML, /Confirmar e importar/);
    assert.deepEqual(h.requests.map((r) => r.method), ['GET', 'POST']);
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

test('conserva la temporada anterior si falla la carga y permite recuperar con Actualizar', async () => {
    const h = harness([catalog(), { status: 500 }, catalog('B')]);
    await h.load('A');
    assert.equal(await h.load('B'), false);
    assert.equal(h.state.season.id, 'A');
    assert.equal(h.byId('catalogSeasonSelector').value, 'A');
    assert.match(h.byId('catalogLoadError').textContent, /últimos datos cargados/);
    assert.equal(await h.load('B'), true);
    assert.equal(h.state.season.id, 'B');
    assert.equal(h.byId('catalogLoadError').classList.contains('is-hidden'), true);
});

test('una respuesta incompleta no reemplaza el último catálogo cargado', async () => {
    const h = harness([catalog(), { status: 200, body: {} }]);
    await h.load();
    assert.equal(await h.load('B'), false);
    assert.equal(h.state.season.id, 'A');
    assert.match(h.byId('catalogLoadError').textContent, /llegó incompleta/);
});

test('solo reintenta una desconexión y no modifica la petición de escritura', async () => {
    const h = harness([new Error('offline'), catalog()]);
    assert.equal(await h.load(), true);
    assert.equal(h.requests.length, 2);
    assert.deepEqual(h.timeouts, [600]);
});

test('un guardado seguido de un refresco fallido no anuncia catálogo publicado ni repite el POST', async () => {
    const h = harness([catalog(), { status: 201, body: {} }, { status: 500 }]);
    await h.load();
    await h.save('category');
    assert.equal(h.requests.filter((r) => r.method === 'POST').length, 1);
    const messages = h.byId('catalogToasts').children.map((n) => n.textContent);
    assert.equal(messages.some((m) => m.includes('publicado')), false);
    assert.match(messages.at(-1), /Cambio guardado.*No fue posible refrescar/);
    assert.equal(h.byId('catalogLoadError').classList.contains('is-hidden'), false);
});

test('un guardado habitual aplica la publicación sin recargar resumen ni jerarquía', async () => {
    const h = harness([catalog(), { status: 201, body: { data: { id: 'cat-1', nombre: 'Categoría nueva', activo: true },
        publicacion: { temporada_id: 'A', desde: 7, version_catalogo: 8, proyeccion: { articulos: 150, origenes: 200, combinaciones: 30000 } } } }]);
    await h.load(); await h.save('category');
    assert.equal(h.requests.length, 2);
    assert.equal(h.requests.at(-1).method, 'POST');
    assert.ok(h.state.categories.some((item) => item.nombre === 'Categoría nueva'));
    assert.equal(h.state.season.version_catalogo, 8);
    assert.match(h.byId('catalogToasts').children.at(-1).textContent, /publicado/);
});

test('una publicación concurrente recupera la jerarquía antes de anunciar actualización completa', async () => {
    const h = harness([catalog(), { status: 201, body: { data: { id: 'cat-1' },
        publicacion: { temporada_id: 'A', desde: 9, version_catalogo: 10, proyeccion: {} } } }, catalog('A', 10)]);
    await h.load(); await h.save('category');
    assert.equal(h.requests.length, 3);
    assert.equal(h.state.season.version_catalogo, 10);
    assert.equal(h.requests.filter((r) => r.method === 'POST').length, 1);
});
