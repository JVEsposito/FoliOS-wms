import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const inbox = await readFile(
    new URL('../../mobile/src/components/OperationalTaskInbox.tsx', import.meta.url),
    'utf8',
);
const api = await readFile(
    new URL('../../mobile/src/services/operationalTasksApi.ts', import.meta.url),
    'utf8',
);
const planner = await readFile(
    new URL('../../mobile/src/domain/rollingPlanner.ts', import.meta.url),
    'utf8',
);

test('la tablet calcula una sola frontera para tareas de planes distintos', () => {
    assert.match(inbox, /physicalFrontierSnapshot\(auth\.token\)/);
    assert.match(inbox, /anchorTask,[\s\S]*\.\.\.mine/);
    assert.doesNotMatch(inbox, /task\.plan\.id === anchorTask\.plan\.id/);
    assert.match(inbox, /item\.id === task\.id && item\.materializable/);
});

test('la tablet materializa contra el snapshot físico global', () => {
    assert.match(api, /TABLET_PLANNER_VERSION = 'rolling-global-3-envases'/);
    assert.match(api, /'\/api\/frontera-fisica\/snapshot'/);
    assert.match(api, /'\/api\/frontera-fisica\/materializar'/);
    assert.match(inbox, /materializePhysicalFrontier\(/);
    assert.match(inbox, /snapshot\.arbitraje\.vigencia\.vigente/);
    assert.match(inbox, /Espera la próxima actualización antes de mover el pallet/);
});

test('cada propuesta conserva la versión de su propio plan', () => {
    assert.match(planner, /taskSnapshot\.plan_version \?\? planVersion\(snapshot\)/);
    assert.match(planner, /item\.id === task\.id\)\?\.materializable !== false/);
});

test('la tablet limita candidatos a las cámaras del snapshot autoritativo', () => {
    assert.match(inbox, /new Set\(snapshot\.camaras\.map\(\(camera\) => camera\.id\)\)/);
    assert.match(inbox, /directedCameraIds\.has\(camera\.id\)/);
    assert.match(inbox, /No existen cámaras dirigidas disponibles en el rollout vigente/);
});
