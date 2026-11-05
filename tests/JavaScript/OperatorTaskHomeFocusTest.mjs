import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const read = (path) => readFile(new URL(`../../${path}`, import.meta.url), 'utf8');

const home = await read('mobile/src/components/operator/OperatorTaskHome.tsx');
const inbox = await read('mobile/src/components/OperationalTaskInbox.tsx');
const toast = await read('mobile/src/components/operator/OperatorToast.tsx');

test('la portada muestra una maniobra con folio, Desde → Hacia y una sola acción', () => {
    assert.match(home, /<FolioCode compact=\{compact\} value=\{task\.folio\.numero_folio\} \/>/);
    assert.match(home, /value\.length - 4/, 'los últimos cuatro dígitos del folio se destacan');
    assert.match(home, /<RouteBlock label="DESDE" value=\{operationalTaskOriginLabel\(task\)\} \/>/);
    assert.match(home, /label="HACIA"/);
    assert.match(home, /pending=\{destinationPending\}/);
    assert.match(home, /heroAction: \{ minHeight: 84/);
    assert.doesNotMatch(home, /Mi jornada operacional/);
    assert.doesNotMatch(home, /<OperatorTaskPurpose/, 'el propósito completo queda en la maniobra en ejecución');
    assert.doesNotMatch(home, /label: 'DISPONIBLE'/, 'el estado redundante con el botón no se repite');
});

test('cola, último movimiento y cargas viven en la columna lateral', () => {
    assert.match(home, /accessibilityLabel="Próximas maniobras en cola"/);
    assert.match(home, /QUEUE_PREVIEW = 3/);
    assert.match(home, /Último movimiento/);
    assert.match(home, /Cargas del turno/);
    assert.match(home, /refreshControl=\{<RefreshControl/);
    assert.match(inbox, /lastMovement=\{lastMovement\}/);
    assert.match(inbox, /setLastMovement\(\{ at: new Date\(\)\.toISOString\(\)/);
    assert.match(inbox, /onOpenLoads=\{auth\.usuario\.capacidades\?\.puede_consultar_cargas \?/);
    assert.match(inbox, /syncLabel=\{syncLabel\}/);
    assert.doesNotMatch(inbox, /Cargas activas y terminadas del turno/);
});

test('los avisos informativos se cierran solos y no tapan acciones de la maniobra', () => {
    assert.doesNotMatch(inbox, /styles\.noticeBanner/);
    assert.match(inbox, /<OperatorToast\s+inline=\{!onHome\}\s+message=\{notice\}/);
    assert.match(toast, /const MIN_MS = 5_000;/);
    assert.match(toast, /const MAX_MS = 12_000;/);
    assert.match(toast, /Math\.min\(MAX_MS, Math\.max\(MIN_MS, message\.length \* MS_PER_CHARACTER\)\)/);
    assert.match(toast, /\}, \[message\]\);/, 'el plazo no se reinicia con cada consulta de la bandeja');
    assert.match(toast, /onPress=\{onDismiss\}/);
});
