import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { stripTypeScriptTypes } from 'node:module';
import test from 'node:test';

const source = async (file) => stripTypeScriptTypes(await readFile(new URL(`../../mobile/src/domain/${file}.ts`, import.meta.url), 'utf8'), { mode: 'transform' });
const url = (value) => `data:text/javascript,${encodeURIComponent(value)}`;
const plannerUrl = url(await source('rollingPlanner'));
const { OperationalCameraPlans, candidateCameraIds } = await import(url((await source('operationalCameraPlans')).replace("from './rollingPlanner'", `from ${JSON.stringify(plannerUrl)}`)));
const { OperationalActionGate, OperationalInboxReads } = await import(url(await source('operationalInboxConcurrency')));
const { operationalTaskOriginLabel, operationalTaskDestinationLabel } = await import(url(await source('operationalTasks')));
const deferred = () => { let resolve; const promise = new Promise((yes) => { resolve = yes; }); return { promise, resolve }; };
const camera = (id, revision = 1) => ({ id, version_plano: 3, revision_reservas: revision });
const snapshot = (...ids) => ({ camaras: ids.map((id) => camera(id)), tareas: [] });
const task = (id = 't') => ({ id, tipo_movimiento: 'ubicacion_inicial', contexto: {}, origen: null, destino: null, plan: { tipo: 'recepcion_tunel' } });

test('lecturas simultáneas comparten petición; una mutación invalida únicamente respuestas anteriores', async () => {
    const reads = new OperationalInboxReads(); const old = deferred(); let count = 0;
    const a = reads.read('mine', () => { count++; return old.promise; });
    const b = reads.read('mine', () => { count++; return old.promise; });
    await Promise.resolve(); assert.equal(count, 1);
    reads.invalidate(); const fresh = await reads.read('mine', async () => ['tomada']);
    old.resolve([]); assert.deepEqual(await a, { current: false, value: [] }); assert.deepEqual(await b, { current: false, value: [] });
    assert.deepEqual(fresh, { current: true, value: ['tomada'] });
    await assert.rejects(reads.read('mine', async () => { throw new Error('Red'); }), /Red/);
    assert.deepEqual((await reads.read('mine', async () => ['recuperada'])).value, ['recuperada']);
});

test('el bloqueo de acciones es inmediato y una finalización ajena no lo libera', () => {
    const gate = new OperationalActionGate(); const owner = gate.begin();
    assert.equal(gate.begin(), null); assert.equal(gate.finish(Symbol()), false); assert.equal(gate.busy(), true);
    assert.equal(gate.finish(owner), true); assert.ok(gate.begin());
});

test('los planos candidatos usan la cámara de carga actual del servidor y respetan el alcance', () => {
    const t = task(); t.destino = { camara: { id: 'old' }, posicion: null };
    const s = snapshot('old', 'new'); s.tareas = [{ id: t.id, acopio_carga: { carga_id: 'load', camara_objetivo_id: 'new', puntos: [], despacho_directo: false } }];
    assert.deepEqual(candidateCameraIds([t], s), ['new']);
    s.tareas[0].acopio_carga.camara_objetivo_id = 'foreign'; assert.deepEqual(candidateCameraIds([t], s), []);
    s.tareas[0].acopio_carga.despacho_directo = true; assert.deepEqual(candidateCameraIds([t], s), []);
});

test('un despeje sin compromiso físico lee también la cámara de origen aunque conserve una propuesta remota', () => {
    const t = { ...task(), tipo_movimiento: 'traslado_entre_camaras', estado: 'asumida', punto_no_retorno: false,
        origen: { camara: { id: 'cam5' } }, destino: { camara: { id: 'cam1' }, posicion: null },
        contexto: { tipo_decision: 'despeje_salida_directa' }, reserva: { tipo_compromiso: 'claim' } };
    assert.deepEqual(candidateCameraIds([t], snapshot('cam1', 'cam5')), ['cam1', 'cam5']);
    t.reserva.tipo_compromiso = 'fisica';
    assert.deepEqual(candidateCameraIds([t], snapshot('cam1', 'cam5')), ['cam1']);
    assert.deepEqual(candidateCameraIds([{ ...t, reserva: null }], snapshot('cam1')), ['cam1']);
});

test('el precálculo reutiliza planos y los descarta por reserva, inventario o vencimiento', async () => {
    let clock = 0; let calls = 0; const cache = new OperationalCameraPlans(() => clock, 30_000); const s = snapshot('a');
    const fetch = async (id) => { calls++; return { ...s.camaras.find((c) => c.id === id) }; };
    await cache.load(s, [task()], fetch); await cache.load(s, [task()], fetch); assert.equal(calls, 1);
    s.camaras[0].revision_reservas++; await cache.load(s, [task()], fetch); assert.equal(calls, 2);
    s.camaras[0].version_plano++; await cache.load(s, [task()], fetch); assert.equal(calls, 3);
    clock = 30_001; await cache.load(s, [task()], fetch); assert.equal(calls, 4);
});

test('dos precálculos comparten el plano pendiente y como máximo descargan dos a la vez', async () => {
    const cache = new OperationalCameraPlans(); const s = snapshot('a', 'b', 'c', 'd'); let running = 0; let maximum = 0; let count = 0;
    const waits = new Map();
    const fetch = async (id) => { count++; running++; maximum = Math.max(maximum, running); const wait = deferred(); waits.set(id, wait); await wait.promise; running--; return camera(id); };
    const a = cache.load(s, [task()], fetch); const b = cache.load(s, [task()], fetch);
    for (let i = 0; i < 10; i++) await Promise.resolve(); assert.equal(count, 2);
    waits.get('a').resolve(); waits.get('b').resolve();
    for (let i = 0; i < 20; i++) await Promise.resolve(); waits.get('c').resolve(); waits.get('d').resolve();
    assert.equal((await a).length, 4); assert.equal((await b).length, 4); assert.equal(count, 4); assert.equal(maximum, 2);
});

test('un plano fallido se puede volver a leer y uno sin revisión no se conserva', async () => {
    const cache = new OperationalCameraPlans(); const s = snapshot('a');
    await assert.rejects(cache.load(s, [task()], async () => { throw new Error('Red'); }), /Red/);
    let calls = 0; const legacy = async () => { calls++; return { id: 'a', version_plano: 3 }; };
    await cache.load(s, [task()], legacy); await cache.load(s, [task()], legacy); assert.equal(calls, 2);
});

test('una respuesta antigua no elimina un plano nuevo guardado mientras se descargaba', async () => {
    const cache = new OperationalCameraPlans(); const old = deferred(); const s = snapshot('a');
    const previous = cache.load(s, [task()], () => old.promise);
    const current = snapshot('a'); current.camaras[0].revision_reservas = 2; let calls = 0;
    const fetch = async () => { calls++; return camera('a', 2); };
    await cache.load(current, [task()], fetch); old.resolve(camera('a', 9)); await previous;
    await cache.load(current, [task()], fetch); assert.equal(calls, 1);
});

test('origen de túnel, packing y custodia son explícitos; destino pendiente conserva el nombre de carga', () => {
    const t = task(); t.contexto = { tunel_nombre: 'Túnel 1', posicion_tunel: 'IZQ-P04' };
    assert.equal(operationalTaskOriginLabel(t), 'Túnel 1 · IZQ-P04');
    t.contexto = { origen_logico: 'recepcion_externa' }; t.plan.tipo = 'almacenamiento_pallet';
    assert.equal(operationalTaskOriginLabel(t), 'Recepción de packing externo');
    t.tipo_paso_maniobra = 'retorno_banda'; assert.match(operationalTaskOriginLabel(t), /custodia/);
    t.contexto.acopio_carga = { carga_codigo: 'Carga B' }; assert.match(operationalTaskDestinationLabel(t), /Carga B · posición pendiente de confirmar/);
});
