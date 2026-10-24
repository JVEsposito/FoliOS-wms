import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import { stripTypeScriptTypes } from 'node:module';

const tasksUrl = `data:text/javascript,${encodeURIComponent(stripTypeScriptTypes(await readFile(new URL('../../mobile/src/domain/operationalTasks.ts', import.meta.url), 'utf8')))}`;
const maneuverSource = stripTypeScriptTypes(await readFile(new URL('../../mobile/src/domain/operatorManeuver.ts', import.meta.url), 'utf8')).replace("from './operationalTasks'", `from ${JSON.stringify(tasksUrl)}`);
const { buildOperatorManeuverAction, buildOperatorManeuverSequence } = await import(`data:text/javascript,${encodeURIComponent(maneuverSource)}`);

const component = await readFile(
    new URL('../../mobile/src/components/operator/OperatorTaskExecution.tsx', import.meta.url),
    'utf8',
);
const inbox = await readFile(
    new URL('../../mobile/src/components/OperationalTaskInbox.tsx', import.meta.url),
    'utf8',
);
const resource = await readFile(
    new URL('../../app/Http/Resources/TareaMovimientoResource.php', import.meta.url),
    'utf8',
);

function task(overrides = {}) {
    return {
        id: 'task-1',
        estado: 'asumida',
        tipo_movimiento: 'retiro',
        tipo_paso_maniobra: 'extraccion_temporal',
        secuencia_maniobra: 1,
        folio: { id: 'folio-1', numero_folio: 'PAL-058321', tipo_bulto: 'pallet' },
        origen: {
            camara: { id: 'cam-3', nombre: 'Cámara C3' },
            posicion: { id: 'pos-4', etiqueta: 'B08-P04-N1', banda: 8, posicion: 4, nivel: 1 },
        },
        destino: null,
        destino_logico: null,
        maniobra: {
            id: 'maneuver-4',
            titulo: 'Maniobra 04',
            secuencia_actual: 1,
            pasos_totales: 2,
            pasos: [],
            custodias_temporales: [],
        },
        ...overrides,
    };
}

test('antes del punto de no retorno la acción principal retira el pallet', () => {
    const action = buildOperatorManeuverAction(task());

    assert.equal(action.verb, 'RETIRAR');
    assert.equal(action.primaryLabel, 'RETIRAR PALLET');
    assert.match(action.instruction, /PAL-058321/);
});

test('una extracción confirmada permanece bajo maniobra y no recibe ubicación ficticia', () => {
    const action = buildOperatorManeuverAction(task({ estado: 'en_proceso' }));

    assert.equal(action.destination, 'Bajo maniobra');
    assert.equal(action.primaryLabel, 'CONFIRMAR PALLET RETIRADO');
    assert.doesNotMatch(JSON.stringify(action), /TEMP-01|PISO-TEMP/);
});

test('la secuencia usa exclusivamente pasos reales enviados por el servidor', () => {
    const current = task({
        secuencia_maniobra: 2,
        maniobra: {
            id: 'maneuver-4',
            titulo: 'Maniobra 04',
            secuencia_actual: 2,
            pasos_totales: 2,
            custodias_temporales: [],
            pasos: [
                {
                    id: 'step-1', secuencia: 1, estado: 'completada', tipo_movimiento: 'retiro',
                    tipo_paso: 'extraccion_temporal', folio: { id: 'f1', numero_folio: 'PAL-C' },
                    origen: task().origen, destino: null, destino_logico: null, instruccion: null,
                },
                {
                    id: 'step-2', secuencia: 2, estado: 'asumida', tipo_movimiento: 'ubicacion_inicial',
                    tipo_paso: 'retorno_banda', folio: { id: 'f1', numero_folio: 'PAL-C' },
                    origen: null, destino: task().origen, destino_logico: null, instruccion: 'Retornar blocker',
                },
            ],
        },
    });

    const sequence = buildOperatorManeuverSequence(current);

    assert.deepEqual(sequence.map((item) => item.state), ['complete', 'current']);
    assert.equal(sequence[0].folio, 'PAL-C');
    assert.match(sequence[1].route, /B08-P04-N1/);
});

test('la nueva pantalla separa ejecución, excepciones y custodia visual', () => {
    assert.match(component, /Mi maniobra/);
    assert.match(component, /PASO \{currentStep\} \/ \{totalSteps\}/);
    assert.match(component, /NO COINCIDE/);
    assert.match(component, /NO ES POSIBLE/);
    assert.match(component, /EXTRAÍDO TEMPORALMENTE/);
    assert.match(component, /VOLVER A MANIOBRAS/);
    assert.match(component, /Objetivos/);
    assert.match(inbox, /<OperatorTaskExecution/);
    assert.doesNotMatch(inbox, /function TaskCard/);
    assert.match(resource, /'pasos' =>/);
    assert.match(resource, /'custodias_temporales' =>/);
    assert.match(resource, /'objetivos' =>/);
});
