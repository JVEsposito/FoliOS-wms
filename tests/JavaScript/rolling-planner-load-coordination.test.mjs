import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { stripTypeScriptTypes } from 'node:module';
import test from 'node:test';
import { loadReceptionDependencies } from '../../resources/js/shared/load-reception-dependencies.js';

const source = await readFile(new URL('../../mobile/src/domain/rollingPlanner.ts', import.meta.url), 'utf8');
const { bestCandidate, calculateRollingFrontier } = await import(`data:text/javascript,${encodeURIComponent(stripTypeScriptTypes(source))}`);
const purposeSource = await readFile(new URL('../../mobile/src/domain/operatorTaskPurpose.ts', import.meta.url), 'utf8');
const { operatorTaskPurpose } = await import(`data:text/javascript,${encodeURIComponent(stripTypeScriptTypes(purposeSource))}`);
const point = (posicion) => ({ banda: 1, posicion, nivel: 1 });
const target = (camera = 'acopio', points = [point(1)]) => ({ carga_id: 'load-a', carga_codigo: 'Carga A',
    carga_folio_id: 'assignment-a', camara_objetivo_id: camera, puntos: points, despacho_directo: false });
const task = (id = 't1', acopio = target()) => ({ id, estado: 'asumida', prioridad: 'alta', secuencia: Number(id.slice(1)),
    tipo_movimiento: 'ubicacion_inicial', punto_no_retorno: false, destino: null, origen: null,
    contexto: { acopio_carga: acopio, cliente: 'Cliente', marca: 'Marca', formato: 'Caja A' },
    folio: { id: `folio-${id}`, exportadora: 'Cliente', marca: 'Marca', envase: 'Caja A' },
    plan: { version: 1 }, maniobra: null });
const camera = (id, occupied = []) => ({ id, version_plano: 1, nombre: id, contenido: 'productos', estado: 'activa',
    posiciones: [1, 2, 3, 4, 5].map((posicion) => ({ ...point(posicion), id: `${id}-${posicion}`,
        estado: 'activa', ocupada: occupied.includes(posicion), reservada: false })),
    bandas_operacionales: [{ numero: 1, modo: 'operativa', estado: 'parcial', usos_permitidos: ['transito_pt'],
        acepta_nuevos_ingresos: true, capacidad: { disponibles: 5 - occupied.length, ocupadas: occupied.length } }] });
const snapshot = (tasks) => ({ planner: { frontier_max: 4 }, tareas: tasks.map((item) => ({ id: item.id,
    version: 1, plan_version: 1, materializable: true })) });

test('recepción de túnel entrega junto a su carga aunque otra cámara tenga más afinidad', () => {
    const generic = camera('generica');
    generic.bandas_operacionales[0].afinidad = { pallets_completos: 1,
        perfiles: [{ cliente: 'Cliente', marca: 'Marca', formato: 'Caja A', pallets: 1 }] };
    const result = bestCandidate(task(), [generic, camera('acopio', [1])]);
    assert.equal(result.position.id, 'acopio-2');
    assert.match(result.reason, /directamente al acopio de Carga A/);
});

test('sin espacio conectado espera y no ofrece almacenamiento genérico para volver a moverlo', () => {
    assert.equal(bestCandidate(task(), [camera('generica'), camera('acopio', [1, 2])]), null);
});

test('dos recepciones de la misma carga anticipan un acopio conectado antes de reservar', () => {
    const tasks = [task('t1', target(null, [])), task('t2', target(null, []))];
    tasks[1].folio.envase = 'Caja B';
    const cameras = [camera('acopio'), camera('otra')];
    cameras[1].bandas_operacionales[0].afinidad = { pallets_completos: 1,
        perfiles: [{ cliente: 'Cliente', marca: 'Marca', formato: 'Caja B', pallets: 1 }] };
    const before = structuredClone({ tasks, cameras });
    const result = calculateRollingFrontier(tasks, snapshot(tasks), cameras);
    assert.deepEqual(result.proposals.map((item) => item.posicion_destino_id), ['acopio-1', 'acopio-2']);
    assert.deepEqual({ tasks, cameras }, before);
});

test('el snapshot sustituye un destino anterior por la demanda vigente de la carga', () => {
    const stale = task();
    stale.destino = { camara: { id: 'anterior', nombre: 'Anterior' }, posicion: null };
    const current = snapshot([stale]);
    current.tareas[0].acopio_carga = target('vigente', [point(2)]);
    const result = calculateRollingFrontier([stale], current, [camera('anterior'), camera('vigente', [2])]);
    assert.equal(result.proposals[0].posicion_destino_id, 'vigente-1');
});

test('una asignación retirada libera la restricción anterior en el siguiente cálculo', () => {
    const old = task();
    old.destino = { camara: { id: 'anterior', nombre: 'Anterior' }, posicion: null };
    const current = snapshot([old]);
    current.tareas[0].acopio_carga = null;
    assert.equal(calculateRollingFrontier([old], current, [camera('generica')]).proposals[0].posicion_destino_id, 'generica-1');
});

test('camión presente requiere despacho directo y no materializa una recepción a cámara', () => {
    const receipt = task(); receipt.contexto.acopio_carga.despacho_directo = true;
    assert.equal(bestCandidate(receipt, [camera('acopio')]), null);
});

test('dentro del acopio conectado prefiere envase compatible antes que más contactos físicos', () => {
    const receipt = task('t1', target('acopio', [point(1), { ...point(1), banda: 2 }]));
    const acopio = camera('acopio');
    acopio.posiciones = [{ ...point(2), id: 'mezcla', banda: 2, estado: 'activa', ocupada: false, reservada: false },
        { ...point(1), id: 'compatible', banda: 3, estado: 'activa', ocupada: false, reservada: false }];
    acopio.bandas_operacionales = [2, 3].map((numero) => ({ ...acopio.bandas_operacionales[0], numero,
        afinidad: { pallets_completos: 1, perfiles: [{ cliente: 'Cliente', marca: 'Marca',
            formato: numero === 2 ? 'Caja B' : 'Caja A', pallets: 1 }] } }));
    assert.equal(bestCandidate(receipt, [acopio]).position.id, 'compatible');
});

test('una tarea sin carga conserva la cámara obligatoria al recibir un snapshot sin acopio', () => {
    const fixed = task('t1', null);
    fixed.destino = { camara: { id: 'obligatoria', nombre: 'Destino obligatorio' }, posicion: null };
    const current = snapshot([fixed]); current.tareas[0].acopio_carga = null;
    const result = calculateRollingFrontier([fixed], current, [camera('generica'), camera('obligatoria')]);
    assert.equal(result.proposals[0].posicion_destino_id, 'obligatoria-1');
});

test('Oficina distingue recepciones pendientes de pallets físicamente concentrados', () => {
    assert.equal(loadReceptionDependencies({ dependencias: [] }), '');
    const text = loadReceptionDependencies({ dependencias: [
        { origen: 'Túnel 1', responsable: 'Camarero A' }, { origen: 'Túnel 1', responsable: 'Camarero A' },
        { origen: 'Packing externo', responsable: 'Camarero B' },
    ] });
    assert.match(text, /3 pallets pendientes de recepción: Túnel 1 \(2\) · Packing externo \(1\)/);
    assert.match(text, /Reciben: Camarero A, Camarero B/);
    const dockText = loadReceptionDependencies({ dependencias: [{ origen: 'Túnel 1' }] }, true);
    assert.match(dockText, /entrega directamente en andén/);
    assert.doesNotMatch(dockText, /al acopio/);
});

test('el camarero conserva la labor de recepción y ve que entrega a la carga correspondiente', () => {
    const receipt = task();
    receipt.plan = { id: 'receipt', tipo: 'recepcion_tunel', titulo: 'Recibir Túnel 1' };
    const purpose = operatorTaskPurpose(receipt);
    assert.equal(purpose.label, 'Recepción desde prefrío');
    assert.equal(purpose.goal, 'Recibir Túnel 1');
    assert.equal(purpose.step, 'Entregar directamente al acopio de Carga A');
});
