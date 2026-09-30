import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync(new URL('../../resources/js/office-raw-material.js', import.meta.url), 'utf8')
    .replace(/^import .*;\n/, '')
    .replace(/void boot\(\);\s*$/, '');

function fixture(responses) {
    const nodes = new Map();
    const requests = [];
    function node(id) {
        if (!nodes.has(id)) nodes.set(id, {
            textContent: '', innerHTML: '', disabled: false,
            addEventListener() {}, setAttribute() {},
            classList: { toggle() {}, add() {}, remove() {} },
            append() {}, querySelector() { return null; },
        });
        return nodes.get(id);
    }
    node('lotForm').elements = new Proxy({}, { get: () => ({ addEventListener() {}, value: '' }) });
    const context = vm.createContext({
        document: { getElementById: node, createElement: () => ({ remove() {} }) },
        localStorage: { getItem: () => null },
        Headers, URLSearchParams, Intl,
        FormData: class { [Symbol.iterator]() { return [][Symbol.iterator](); } },
        window: { setTimeout() {} },
        fetch: async (path, options) => {
            requests.push({ path, options });
            const response = responses[path]?.shift();
            if (!response) throw new Error('Sin respuesta para ' + path);
            if (response instanceof Error) throw response;
            return {
                status: response.status ?? 200,
                ok: (response.status ?? 200) < 300,
                headers: { get: (name) => name === 'ETag' ? response.etag ?? null : null },
                json: async () => response.body ?? {},
            };
        },
    });
    vm.runInContext(source, context);
    return { context, nodes, requests };
}

test('el error de segmentos no impide mostrar el resumen y los lotes', async () => {
    const { context, nodes } = fixture({
        '/api/materia-prima/resumen': [{ body: {
            temporada: { nombre: 'Temporada de prueba', codigo: 'T-1' },
            segmentos_pendientes: 2, lotes: { borradores: 1 },
        } }],
        '/api/materia-prima/catalogos': [{ body: { csg: [], especies: [], camaras: [] } }],
        '/api/materia-prima/segmentos-pendientes': [new Error('falló segmento')],
        '/api/materia-prima/lotes?': [{ body: { data: [] } }],
    });
    await vm.runInContext('loadAll()', context);
    assert.equal(nodes.get('pendingSegmentsCount').textContent, '2');
    assert.match(nodes.get('segmentList').innerHTML, /No se pudieron cargar los segmentos/);
    assert.match(nodes.get('lotTableBody').innerHTML, /No existen lotes/);
});

test('un 304 sin catálogo en memoria repite la solicitud sin ETag', async () => {
    const { context, requests } = fixture({
        '/api/materia-prima/catalogos': [
            { status: 304 },
            { body: { csg: [], especies: [], camaras: [] }, etag: '"catalog-2"' },
        ],
    });
    vm.runInContext(`state.catalogEtag = '"catalog-1"'; state.catalogs = null;`, context);
    await vm.runInContext('loadCatalogs()', context);
    assert.equal(requests.length, 2);
    assert.equal(requests[0].options.headers.get('If-None-Match'), '"catalog-1"');
    assert.equal(requests[1].options.headers.get('If-None-Match'), null);
    assert.equal(requests[1].options.cache, 'no-store');
    assert.equal(vm.runInContext('state.catalogEtag', context), '"catalog-2"');
    assert.equal(vm.runInContext('state.catalogs.csg.length', context), 0);
});

test('el catálogo conserva su ETag previo cuando la descarga falla', async () => {
    const { context } = fixture({
        '/api/materia-prima/catalogos': [new Error('red caída')],
    });
    vm.runInContext(`state.catalogEtag = '"previo"';`, context);
    await assert.rejects(vm.runInContext('loadCatalogs()', context), /conectar/);
    assert.equal(vm.runInContext('state.catalogEtag', context), '"previo"');
});
