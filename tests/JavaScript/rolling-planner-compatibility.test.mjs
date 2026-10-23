import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { stripTypeScriptTypes } from 'node:module';
import test from 'node:test';

const source = await readFile(new URL('../../mobile/src/domain/rollingPlanner.ts', import.meta.url), 'utf8');
const { bestCandidate, calculateRollingFrontier } = await import(`data:text/javascript,${encodeURIComponent(stripTypeScriptTypes(source))}`);

function camera(id, affinity) {
    return {
        id,
        contenido: 'productos',
        estado: 'activa',
        posiciones: [{
            id: `position-${id}`,
            banda: 1,
            posicion: 1,
            nivel: 1,
            estado: 'activa',
            ocupada: false,
            reservada: false,
        }],
        bandas_operacionales: [{
            numero: 1,
            acepta_nuevos_ingresos: true,
            usos_permitidos: ['transito_pt'],
            modo: 'operativa',
            estado: 'libre',
            capacidad: { disponibles: 2, ocupadas: affinity?.pallets_completos ?? 0 },
            afinidad: affinity,
        }],
    };
}

const task = {
    id: 'pallet-1',
    tipo_movimiento: 'ubicacion_inicial',
    destino: null,
    origen: null,
    contexto: { cliente: 'Cliente Norte', marca: 'Marca Norte', formato: 'Caja 5' },
};

test('una banda compatible en otra cámara gana a la preferente vacía', () => {
    const preferred = camera('despacho', { activa: false, pallets_completos: 0 });
    const compatible = camera('otra', {
        activa: true,
        pallets_completos: 1,
        cliente: { valor: 'Cliente Norte' },
        marca: { valor: 'Marca Norte' },
        formato: { valor: 'Caja 5' },
    });

    assert.equal(bestCandidate(task, [preferred, compatible], new Set(), new Set(), 'despacho')?.cameraId, 'otra');
});

test('la cámara preferente gana entre bandas igualmente compatibles', () => {
    const preferred = camera('despacho', { activa: false, pallets_completos: 0 });
    const other = camera('otra', { activa: false, pallets_completos: 0 });

    assert.equal(bestCandidate(task, [other, preferred], new Set(), new Set(), 'despacho')?.cameraId, 'despacho');
});

test('una marca igual de otro cliente no desplaza una banda vacía en despacho', () => {
    const preferred = camera('despacho', { activa: false, pallets_completos: 0 });
    const mixed = camera('otra', {
        activa: true,
        pallets_completos: 1,
        cliente: { valor: 'Cliente Sur' },
        marca: { valor: 'Marca Norte' },
        formato: { valor: 'Caja 5' },
    });

    assert.equal(bestCandidate(task, [mixed, preferred], new Set(), new Set(), 'despacho')?.cameraId, 'despacho');
});

test('una banda vacía evita ampliar mezclas, y la afinidad minoritaria sigue como alternativa', () => {
    const preferred = camera('despacho', { activa: false, pallets_completos: 0 });
    const mixed = camera('otra', {
        activa: true,
        pallets_completos: 3,
        cliente: { valor: 'Cliente Sur' },
        marca: { valor: 'Marca Sur' },
        formato: { valor: 'Caja 5' },
        perfiles: [
            { cliente: 'Cliente Sur', marca: 'Marca Sur', formato: 'Caja 5', pallets: 2 },
            { cliente: 'Cliente Norte', marca: 'Marca Norte', formato: 'Caja 5', pallets: 1 },
        ],
    });

    assert.equal(bestCandidate(task, [preferred, mixed], new Set(), new Set(), 'despacho')?.cameraId, 'despacho');
    assert.equal(bestCandidate(task, [mixed])?.cameraId, 'otra');
});

test('una banda sin mezcla de clientes gana a otra igual de afín en la cámara preferente', () => {
    const mixed = camera('despacho', {
        activa: true,
        pallets_completos: 2,
        perfiles: [
            { cliente: 'Cliente Norte', marca: 'Marca Norte', formato: 'Caja 5', pallets: 1 },
            { cliente: 'Cliente Sur', marca: 'Marca Sur', formato: 'Caja 5', pallets: 1 },
        ],
    });
    const clean = camera('otra', {
        activa: true,
        pallets_completos: 1,
        perfiles: [{ cliente: 'Cliente Norte', marca: 'Marca Norte', formato: 'Caja 5', pallets: 1 }],
    });

    assert.equal(bestCandidate(task, [mixed, clean], new Set(), new Set(), 'despacho')?.cameraId, 'otra');
});


test('usa el envase del folio aunque la tarea antigua diga granel', () => {
    const profile = (format) => ({ activa: true, pallets_completos: 1, perfiles: [{ cliente: 'Cliente Norte', marca: 'Marca Norte', formato: format, pallets: 1 }] });
    const other = camera('incorrecta', profile('GFL50M'));
    const correct = camera('correcta', profile('MAFLV225A'));
    const actual = { ...task, folio: { exportadora: 'Cliente Norte', marca: 'Marca Norte', envase: 'MAFLV225A' }, contexto: { ...task.contexto, formato: 'granel' } };
    assert.equal(bestCandidate(actual, [other, correct])?.cameraId, 'correcta');
    const empty = camera('vacia', { activa: false, pallets_completos: 0, perfiles: [] });
    assert.equal(bestCandidate({ ...actual, folio: { ...actual.folio, envase: null } }, [other, empty])?.cameraId, 'vacia');
});

test('prefiere abrir banda antes de mezclar envases del mismo cliente y marca', () => {
    const different = camera('otro-envase', { activa: true, pallets_completos: 1, perfiles: [{ cliente: 'Cliente Norte', marca: 'Marca Norte', formato: 'Otra caja', pallets: 1 }] });
    const empty = camera('libre', { activa: false, pallets_completos: 0, perfiles: [] });
    assert.equal(bestCandidate(task, [different, empty])?.cameraId, 'libre');
    assert.equal(bestCandidate(task, [different])?.cameraId, 'otro-envase');
});

test('la entrada reservada por otra tablet crea afinidad sin inventar pallets presentes', () => {
    const arrivals = camera('reservada', { activa: false, pallets_completos: 0, perfiles: [], entrantes: {
        pallets_completos: 1, perfiles: [{ cliente: 'Cliente Norte', marca: 'Marca Norte', formato: 'Caja 5', pallets: 1 }],
    } });
    arrivals.posiciones[0].posicion = 12;
    const empty = camera('libre', { activa: false, pallets_completos: 0, perfiles: [] });
    assert.equal(bestCandidate(task, [empty, arrivals])?.cameraId, 'reservada');
    arrivals.bandas_operacionales[0].afinidad.entrantes.perfiles[0].formato = 'Otra caja';
    assert.equal(bestCandidate(task, [arrivals, empty])?.cameraId, 'libre');
    assert.equal(arrivals.bandas_operacionales[0].afinidad.pallets_completos, 0);
});

test('planifica juntos los pallets iguales y separa los distintos sin alterar el snapshot recibido', () => {
    const empty = { activa: false, pallets_completos: 0, perfiles: [] };
    const a = camera('cam-a', empty); const b = camera('cam-b', empty);
    for (const cam of [a, b]) {
        cam.posiciones = [1, 2].map((depth) => ({ ...cam.posiciones[0], id: `${cam.id}-${depth}`, posicion: depth }));
    }
    const plans = [a, b]; const before = JSON.stringify(plans);
    const tasks = ['a', 'b'].map((id) => ({ ...task, id, estado: 'asumida', secuencia: 1, prioridad: 'alta' }));
    const snapshot = { planner: { frontier_max: 4 }, tareas: tasks.map((t) => ({ id: t.id, version: 1, plan_version: 1, materializable: true })) };
    const grouped = calculateRollingFrontier(tasks, snapshot, plans);
    assert.deepEqual(grouped.candidates.map((item) => item.position.id), ['cam-a-1', 'cam-a-2']);
    tasks[1].contexto = { ...task.contexto, formato: 'Otra caja' };
    const separated = calculateRollingFrontier(tasks, snapshot, plans);
    assert.deepEqual(separated.candidates.map((item) => item.position.id), ['cam-a-1', 'cam-b-1']);
    assert.equal(JSON.stringify(plans), before);
});
