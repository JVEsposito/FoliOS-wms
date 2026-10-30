import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { stripTypeScriptTypes } from 'node:module';
import test from 'node:test';

// Acopio de carga en la tablet: el sector que elige el servidor, el llenado desde el fondo y
// las propuestas que no dependen de otro pallet sin reserva (caso CAR-000024, 26-10-2026).
const planner = stripTypeScriptTypes(await readFile(new URL('../../mobile/src/domain/rollingPlanner.ts', import.meta.url), 'utf8'));
const plannerUrl = `data:text/javascript,${encodeURIComponent(planner)}`;
const { bestCandidate, calculateRollingFrontier, isNextSlot } = await import(plannerUrl);
const previewSource = stripTypeScriptTypes(await readFile(new URL('../../mobile/src/domain/rollingPlannerPreview.ts', import.meta.url), 'utf8'))
    .replace("from './rollingPlanner'", `from ${JSON.stringify(plannerUrl)}`);
const { simulateFrontierPreview, reusablePreviewProposal } = await import(`data:text/javascript,${encodeURIComponent(previewSource)}`);

/** Cámara de `bands` bandas por `depth` posiciones; `taken` = ['B-P', ...] ocupadas. */
const camera = (bands, depth, taken = []) => ({
    id: 'cam-07', nombre: 'Camara de tránsito 07', version_plano: 53, revision_reservas: 1, contenido: 'productos', estado: 'activa',
    posiciones: Array.from({ length: bands }, (_, b) => Array.from({ length: depth }, (_, p) => ({
        id: `B${b + 1}-P${p + 1}`, etiqueta: `B${b + 1}-P${p + 1}`, banda: b + 1, posicion: p + 1, nivel: 1,
        estado: 'activa', ocupada: taken.includes(`${b + 1}-${p + 1}`), reservada: false,
    }))).flat(),
    bandas_operacionales: Array.from({ length: bands }, (_, b) => ({
        numero: b + 1, modo: 'operativa', estado: 'parcial', usos_permitidos: ['transito_pt'], acepta_nuevos_ingresos: true,
        capacidad: { disponibles: depth, ocupadas: 0 },
    })),
});
const staging = (puntos, sector = null) => ({ carga_id: 'car-24', carga_codigo: 'CAR-000024', carga_folio_id: 'cf',
    camara_objetivo_id: 'cam-07', puntos, despacho_directo: false, sector });
const task = (id, acopio, priority = 'urgente') => ({ id, estado: 'asumida', prioridad: priority, secuencia: Number(id.slice(1)),
    version: 1, tipo_movimiento: 'ubicacion_inicial', punto_no_retorno: false, destino: null, origen: null, reserva: null,
    contexto: { acopio_carga: acopio }, folio: { id: `folio-${id}`, exportadora: 'LA AGUADA', marca: 'FLA', envase: 'GFL50A' },
    plan: { version: 1, horizon: 'rolling' }, maniobra: { id: `m-${id}`, estado: 'en_ejecucion', arbitraje: { decision: 'seleccionada' },
        beneficio_estimado: 0, costo_movimientos: 0, riesgo_operacional: 0 } });
const snapshot = (tasks, acopio) => ({ snapshot_version: 'v1',
    planner: { horizon: 'rolling', compute: 'tablet', frontier_max: 4, camara_preferente_despacho_id: null },
    arbitraje: { ciclo_id: 'c', snapshot_version: 'a', vigencia: { vigente: true } }, frontera: { reservas_fisicas_activas: 0 },
    camaras: [{ id: 'cam-07', version_plano: 53, revision_reservas: 1 }],
    tareas: tasks.map((item) => ({ id: item.id, version: 1, plan_version: 1, materializable: true, acopio_carga: acopio })) });
const nuevo = { tipo: 'nuevo', nivel: 1, banda_desde: 5, banda_hasta: 8, requeridas: 8, ancla: { banda: 6, posicion: 0, nivel: 1 } };

test('con el ancla de un sector nuevo, el primer pallet va al fondo de la banda más cercana del sector', () => {
    const plan = camera(8, 6, ['1-1', '1-2', '1-3', '1-4', '1-5', '1-6', '2-1', '2-2', '2-3', '2-4', '2-5', '3-1', '3-2', '3-3', '3-4', '3-5']);
    const ancla = [{ banda: 6, posicion: 0, nivel: 1 }];
    // Sin leer el sector (como la app que hoy tienen las tablets), igual cae en B05-P01.
    assert.equal(bestCandidate(task('t1', staging(ancla)), [plan]).position.id, 'B5-P1');
    assert.equal(bestCandidate(task('t1', staging(ancla, nuevo)), [plan]).position.id, 'B5-P1');
});

test('el acopio crece desde el fondo de cada banda y no deja huecos detrás', () => {
    const plan = camera(3, 4, ['2-1', '2-2']);
    const grupo = [{ banda: 2, posicion: 1, nivel: 1 }, { banda: 2, posicion: 2, nivel: 1 }];
    const result = calculateRollingFrontier([task('t1', staging(grupo)), task('t2', staging(grupo))], snapshot([], null), [plan]);
    const elegidas = result.proposals.map((item) => item.posicion_destino_id);
    // B01-P02 y B03-P02 tienen más contactos, pero dejarían P01 libre detrás.
    assert.ok(elegidas.every((id) => ['B1-P1', 'B2-P3', 'B3-P1'].includes(id)), elegidas.join(', '));
    assert.equal(isNextSlot(plan, plan.posiciones.find((item) => item.id === 'B3-P2')), false);
    assert.equal(isNextSlot(plan, plan.posiciones.find((item) => item.id === 'B3-P1')), true);
    assert.equal(isNextSlot(plan, plan.posiciones.find((item) => item.id === 'B3-P2'), new Set(['B3-P1'])), true);
});

test('el pallet del camarero se calcula primero aunque otro de la misma carga tenga más prioridad', () => {
    const plan = camera(2, 4, ['1-1']);
    const grupo = [{ banda: 1, posicion: 1, nivel: 1 }];
    const anchor = task('t2', staging(grupo), 'normal');
    const other = task('t1', staging(grupo), 'urgente');
    const result = calculateRollingFrontier([other, anchor], snapshot([other, anchor], staging(grupo)), [plan], 't2');
    assert.equal(result.proposals[0].tarea_id, 't2');
    const destino = plan.posiciones.find((item) => item.id === result.proposals[0].posicion_destino_id);
    // Su destino es vecino del grupo real, no del destino simulado del otro pallet.
    assert.ok(['B1-P2', 'B2-P1'].includes(destino.id), destino.id);
});

test('una propuesta anticipada que dependía de otro pallet sin reserva no se reutiliza', () => {
    const plan = camera(1, 4, ['1-1']);
    const grupo = staging([{ banda: 1, posicion: 1, nivel: 1 }]);
    const first = { ...task('t1', grupo), estado: 'pendiente' };
    const second = { ...task('t2', grupo, 'normal'), estado: 'pendiente' };
    const before = snapshot([], null);
    const preview = simulateFrontierPreview([first, second], before, [plan]);
    assert.deepEqual(preview.proposals.map((item) => item.posicion_destino_id), ['B1-P2', 'B1-P3']);
    assert.deepEqual(preview.dependent, ['t2']);

    const taken = snapshot([second], grupo);
    assert.equal(reusablePreviewProposal(preview, taken, second), null, 'B1-P3 solo calzaba detrás de la propuesta de t1.');
    const takenFirst = snapshot([first], grupo);
    assert.equal(reusablePreviewProposal(preview, takenFirst, first).posicion_destino_id, 'B1-P2');
});

test('la banda de carga en llenado prevalece sobre contactos laterales', () => {
    const plan = camera(3, 14, ['1-1', '1-2', '1-3', '1-4', '1-5', '1-6', '2-1', '2-2', '2-3']);
    const points = plan.posiciones.filter((p) => p.ocupada).map(({ banda, posicion, nivel }) => ({ banda, posicion, nivel }));
    const acopio = staging(points, { tipo: 'grupo', banda_prioritaria: 1 });
    assert.equal(bestCandidate(task('t1', acopio), [plan]).position.id, 'B1-P7');
    plan.protecciones_bandas = [{ banda: 1, nivel: 1, maniobra_id: 'otra' }];
    assert.equal(bestCandidate(task('t1', acopio), [plan]), null, 'Espera la banda; no dispersa a B02 o B03.');
});

test('termina los niveles utilizables de la misma banda con soporte físico', () => {
    const plan = camera(2, 3, ['1-1', '1-2', '1-3']);
    plan.posiciones.push(...[1, 2, 3].map((p) => ({ id: `arriba-${p}`, banda: 1, posicion: p, nivel: 2,
        estado: 'activa', ocupada: false, reservada: false })));
    const acopio = staging([{ banda: 1, posicion: 1, nivel: 1 }, { banda: 1, posicion: 2, nivel: 1 },
        { banda: 1, posicion: 3, nivel: 1 }], { tipo: 'grupo', banda_prioritaria: 1 });
    assert.equal(bestCandidate(task('t1', acopio), [plan]).position.id, 'arriba-1');
});

test('la banda prioritaria no permite atravesar stock ni apilar sobre una reserva', () => {
    const plan = camera(2, 3, ['1-2']);
    const acopio = staging([{ banda: 1, posicion: 2, nivel: 1 }], { tipo: 'grupo', banda_prioritaria: 1 });
    assert.equal(bestCandidate(task('t1', acopio), [plan]).position.id, 'B1-P3');
    plan.posiciones = [{ id: 'suelo-reservado', banda: 1, posicion: 1, nivel: 1, estado: 'activa', ocupada: false, reservada: true },
        { id: 'arriba', banda: 1, posicion: 1, nivel: 2, estado: 'activa', ocupada: false, reservada: false }];
    assert.equal(bestCandidate(task('t1', acopio), [plan]), null);
});
