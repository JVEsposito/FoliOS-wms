import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

import {
    operatorExceptionOptions,
    operatorExceptionTitle,
    validateOperatorException,
} from '../../mobile/src/domain/operatorManeuverException.ts';

const component = await readFile(
    new URL('../../mobile/src/components/operator/OperatorExceptionReport.tsx', import.meta.url),
    'utf8',
);
const execution = await readFile(
    new URL('../../mobile/src/components/operator/OperatorTaskExecution.tsx', import.meta.url),
    'utf8',
);
const inbox = await readFile(
    new URL('../../mobile/src/components/OperationalTaskInbox.tsx', import.meta.url),
    'utf8',
);
const api = await readFile(
    new URL('../../mobile/src/services/operationalTasksApi.ts', import.meta.url),
    'utf8',
);

test('no coincide ofrece todas las diferencias físicas aceptadas por el servidor', () => {
    assert.equal(operatorExceptionTitle('mismatch'), 'NO COINCIDE');
    assert.deepEqual(
        operatorExceptionOptions('mismatch').map((option) => option.type),
        ['pallet_no_encontrado', 'pallet_no_coincide', 'posicion_no_coincide', 'posicion_vacia', 'otra'],
    );
});

test('no es posible separa impedimentos operacionales de una diferencia física', () => {
    assert.equal(operatorExceptionTitle('impossible'), 'NO ES POSIBLE');
    assert.deepEqual(
        operatorExceptionOptions('impossible').map((option) => option.type),
        ['obstaculo', 'pallet_no_movible', 'otra'],
    );
});

test('el reporte exige motivo y una observación auditables', () => {
    assert.match(validateOperatorException(null, ''), /Selecciona un motivo/);
    assert.match(validateOperatorException('obstaculo', '  '), /Describe brevemente/);
    assert.equal(validateOperatorException('obstaculo', 'Acceso bloqueado por bins.'), null);
    assert.match(validateOperatorException('otra', 'x'.repeat(501)), /500 caracteres/);
});

test('la PDA revisa, confirma y conserva una espera explícita para supervisión', () => {
    assert.match(component, /REVISAR REPORTE/);
    assert.match(component, /CONFIRMAR Y PAUSAR MANIOBRA/);
    assert.match(component, /EN ESPERA DE SUPERVISIÓN/);
    assert.match(component, /No continúes moviendo este pallet/);
    assert.match(component, /VOLVER A MI JORNADA/);
    assert.match(component, /maxLength=\{500\}/);
    assert.match(execution, /<OperatorExceptionReport/);
    assert.match(execution, /NO MUEVAS EL PALLET/);
    assert.match(execution, /Esta pantalla se actualiza automáticamente/);
    assert.match(inbox, /reportDiscrepancy\(auth\.token, activeTask\.id, type, detail\)/);
    assert.match(inbox, /activeTask\.maniobra\?\.estado === 'pausada_discrepancia'/);
    assert.doesNotMatch(inbox, /Pallet distinto.*sendDiscrepancy/s);
    assert.match(api, /ReportedManeuverDiscrepancy/);
    assert.doesNotMatch(api, /maniobra\?\.estado !== 'pausada_discrepancia'/);
    assert.match(api, /\)\)\.data;/);
});
