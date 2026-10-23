import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

import { buildOperatorTaskHome } from '../../mobile/src/domain/operatorTaskQueue.ts';

const component = await readFile(
    new URL('../../mobile/src/components/operator/OperatorTaskHome.tsx', import.meta.url),
    'utf8',
);
const inbox = await readFile(
    new URL('../../mobile/src/components/OperationalTaskInbox.tsx', import.meta.url),
    'utf8',
);

const task = (id, estado = 'pendiente', maniobraEstado = null) => ({
    id,
    estado,
    maniobra: maniobraEstado ? { estado: maniobraEstado } : null,
});

test('prioriza una tarea propia físicamente iniciada', () => {
    const result = buildOperatorTaskHome(
        [task('propia-primera', 'asumida'), task('en-movimiento', 'en_proceso')],
        [task('disponible')],
    );

    assert.equal(result.next.task.id, 'en-movimiento');
    assert.equal(result.next.source, 'mine');
    assert.deepEqual(result.mine.map((item) => item.task.id), ['propia-primera']);
});

test('mantiene una maniobra pausada bajo propiedad y en primer lugar', () => {
    const result = buildOperatorTaskHome(
        [
            task('pausada-en-movimiento', 'en_proceso', 'pausada_discrepancia'),
            task('siguiente-propia', 'asumida', 'en_ejecucion'),
        ],
        [
            task('pausada-disponible', 'pendiente', 'pausada_discrepancia'),
            task('disponible-real', 'pendiente', 'pendiente'),
        ],
    );

    assert.equal(result.next.task.id, 'pausada-en-movimiento');
    assert.equal(result.next.source, 'mine');
    assert.deepEqual(result.mine.map((item) => item.task.id), ['siguiente-propia']);
    assert.deepEqual(result.available.map((item) => item.task.id), ['disponible-real']);
});

test('usa el orden del servidor y ofrece trabajo cuando no hay tareas propias', () => {
    const result = buildOperatorTaskHome([], [task('urgente'), task('normal')]);

    assert.equal(result.next.task.id, 'urgente');
    assert.equal(result.next.source, 'available');
    assert.deepEqual(result.available.map((item) => item.task.id), ['normal']);
});

test('elimina duplicados y nunca repite la maniobra principal en la cola', () => {
    const result = buildOperatorTaskHome(
        [task('a', 'asumida'), task('a', 'asumida'), task('b', 'asumida')],
        [task('a'), task('c')],
    );

    assert.equal(result.next.task.id, 'a');
    assert.deepEqual(result.mine.map((item) => item.task.id), ['b']);
    assert.deepEqual(result.available.map((item) => item.task.id), ['c']);
});

test('la portada usa acciones existentes y no inventa datos operacionales', () => {
    assert.match(component, /SIGUIENTE MANIOBRA/);
    assert.match(component, /INICIAR MANIOBRA/);
    assert.match(component, /Próximas maniobras en cola/);
    assert.match(component, /OBJETIVOS/);
    assert.match(component, /ALTERNATIVA EN ESPERA/);
    assert.match(component, /isArbitrationAlternative/);
    assert.match(component, /disabled=\{busy \|\| alternative\}/);
    assert.match(component, /operationalTaskOriginLabel/);
    assert.match(component, /operationalTaskDestinationLabel/);
    assert.doesNotMatch(component, /Temperatura objetivo/);
    assert.doesNotMatch(component, /Equipo asignado/);
    assert.doesNotMatch(component, /Producto/);
    assert.match(inbox, /item\.source === 'mine'/);
    assert.match(inbox, /runAction\('Tomando labor…', \(\) => takeTask\(item\.task\)\)/);
    assert.match(inbox, /beginTask\(item\.task\)/);
});
