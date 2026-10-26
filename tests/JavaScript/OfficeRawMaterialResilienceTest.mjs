import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync(new URL('../../resources/js/office-raw-material.js', import.meta.url), 'utf8')
    .replace(/^import .*;\n/gm, '')
    .replace(/void boot\(\);\s*$/, '');

function fixture(responses) {
    const nodes = new Map();
    const requests = [];
    function node(id) {
        if (!nodes.has(id)) nodes.set(id, {
            textContent: '', innerHTML: '', disabled: false,
            addEventListener() {}, setAttribute() {},
            reset() {},
            classList: { toggle() {}, add() {}, remove() {} },
            append() {}, querySelector() { return null; },
        });
        return nodes.get(id);
    }
    node('lotForm').elements = new Proxy({}, { get: () => ({ addEventListener() {}, value: '' }) });
    node('movementFilters').elements = { camara_id: node('movementCamera') };
    node('movementCamera').value = '';
    const context = vm.createContext({
        document: { getElementById: node, createElement: () => ({ remove() {} }) },
        localStorage: { getItem: () => null, removeItem() {}, setItem() {} },
        Headers, URLSearchParams, Intl,
        loadContainerCatalog: async () => [], containerLabel: () => undefined,
        FormData: class { [Symbol.iterator]() { return [][Symbol.iterator](); } },
        window: { setTimeout() {} },
        fetch: async (path, options) => {
            requests.push({ path, options });
            const response = await responses[path]?.shift();
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

test('un historial lento no sobrescribe la página más reciente y escapa el texto del lote', async () => {
    let resolveOld;
    const old = new Promise((resolve) => { resolveOld = resolve; });
    const page = (number, lot) => ({ body: {
        current_page: number, last_page: 2, total: 51, prev_page_url: number > 1 ? 'prev' : null,
        next_page_url: number < 2 ? 'next' : null,
        data: [{ numero_lote: lot, movimiento: 'Traslado entre cámaras', cantidad_envases: 36, tipo_envase: 'bins',
            origen: 'MP-A', destino: 'MP-B', usuario: 'Operador', ocurrido_at: '2026-10-26T12:00:00-03:00' }],
    } });
    const { context, nodes } = fixture({
        '/api/materia-prima/movimientos?page=1': [old],
        '/api/materia-prima/movimientos?page=2': [page(2, '<script>lote</script>')],
    });
    const first = vm.runInContext('loadMovements(1)', context);
    await vm.runInContext('loadMovements(2)', context);
    resolveOld(page(1, 'LOTE-VIEJO'));
    await first;
    assert.match(nodes.get('movementStatus').textContent, /página 2 de 2/);
    assert.match(nodes.get('movementRows').innerHTML, /&lt;script&gt;lote&lt;\/script&gt;/);
    assert.doesNotMatch(nodes.get('movementRows').innerHTML, /LOTE-VIEJO|<script>/);
    assert.equal(nodes.get('movementPrevious').disabled, false);
    assert.equal(nodes.get('movementNext').disabled, true);
});

test('un error del registro retira las filas viejas y permite reintentar la consulta', async () => {
    const { context, nodes } = fixture({ '/api/materia-prima/movimientos?page=1': [new Error('sin red')] });
    nodes.get('movementRows').innerHTML = '<tr>Movimiento anterior</tr>';
    await vm.runInContext('loadMovements()', context);
    assert.equal(nodes.get('movementRows').innerHTML, '');
    assert.match(nodes.get('movementStatus').textContent, /No se pudieron cargar/);
    assert.equal(nodes.get('movementPrevious').disabled, true);
    assert.equal(nodes.get('movementNext').disabled, true);
});

test('cerrar sesión descarta una respuesta tardía con movimientos del usuario anterior', async () => {
    let resolveOld;
    const pending = new Promise((resolve) => { resolveOld = resolve; });
    const { context, nodes } = fixture({ '/api/materia-prima/movimientos?page=1': [pending] });
    const reading = vm.runInContext('loadMovements()', context);
    vm.runInContext('clearSession()', context);
    resolveOld({ body: { current_page: 1, last_page: 1, total: 1, data: [{ numero_lote: 'PRIVADO' }] } });
    await reading;
    assert.equal(nodes.get('movementRows').innerHTML, '');
    assert.equal(nodes.get('movementStatus').textContent, '');
});
