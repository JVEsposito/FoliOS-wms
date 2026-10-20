import assert from 'node:assert/strict';
import test from 'node:test';

import {
    addDays,
    alertInfo,
    asksText,
    clientsOf,
    documentDetail,
    documentFormProblem,
    filterRows,
    formatBytes,
    formatDay,
    groupDocuments,
    historyText,
    missingText,
    progressPercent,
    progressText,
    relativeDay,
    requirementNote,
    requirementText,
    scopeText,
    transportLabel,
} from '../../resources/js/shared/comex-logic.js';

const row = (overrides) => ({
    id: 'e1', codigo: 'E-0001', cliente_id: 'c1', cliente_nombre: 'EXPORTADORA DEMO', fecha_programada: '2026-11-05', hora_programada: '09:00',
    contenedor: 'MSKU1234567', nave_vuelo: 'NAVE DEMO', bookings: ['BK-1'], destinos: ['China'], recibidores: ['RECEIVER CO'],
    carga: null, alerta: 'pendiente', resumen: { exigidos: 8, resueltos: 3, por_definir: 0 }, faltantes: [], ...overrides,
});

test('fechas y días relativos del calendario', () => {
    assert.equal(formatDay('2026-11-05'), '05-11-2026');
    assert.equal(formatDay(null), '—');
    assert.equal(addDays('2026-12-30', 3), '2027-01-02');
    assert.equal(addDays('2026-03-01', -1), '2026-02-28');
    assert.equal(relativeDay('2026-11-05', '2026-11-05'), 'Hoy');
    assert.equal(relativeDay('2026-11-06', '2026-11-05'), 'Mañana');
    assert.equal(relativeDay('2026-11-04', '2026-11-05'), 'Ayer');
    assert.equal(relativeDay('2026-11-12', '2026-11-05'), 'En 7 días');
    assert.equal(relativeDay('2026-11-01', '2026-11-05'), 'Hace 4 días');
});

test('avance, faltantes y tamaño de respaldos', () => {
    assert.equal(progressText({ exigidos: 8, resueltos: 3 }), '3 de 8 resueltos');
    assert.equal(progressText({ exigidos: 0 }), 'Sin documentos exigidos');
    assert.equal(progressPercent({ exigidos: 8, resueltos: 2 }), 25);
    assert.equal(progressPercent({ exigidos: 0, resueltos: 0 }), 100);
    assert.equal(missingText(row({
        faltantes: [{ nombre: 'DUS', momento: 'antes_salida' }, { nombre: 'Guía de despacho', momento: 'antes_salida' }, { nombre: 'BL', momento: 'despues_salida' }],
        resumen: { exigidos: 8, resueltos: 5, por_definir: 2 },
    })), 'Antes de la salida: DUS, Guía de despacho · 1 documento para después · 2 documentos por definir');
    assert.equal(missingText(row({ resumen: { exigidos: 2, resueltos: 2 } })), 'Nada pendiente');
    assert.equal(missingText(row({ faltantes: ['A', 'B', 'C', 'D', 'E'].map((nombre) => ({ nombre, momento: 'antes_salida' })) })), 'Antes de la salida: A, B y 3 más');
    assert.equal(formatBytes(2048), '2 KB');
    assert.equal(formatBytes(300), '1 KB');
    assert.equal(formatBytes(2.5 * 1024 * 1024), '2,5 MB');
});

test('el panel ordena por gravedad y oculta cancelados salvo que se pidan', () => {
    const rows = [
        row({ id: 'a', codigo: 'E-3', alerta: 'completo', fecha_programada: '2026-11-01' }),
        row({ id: 'b', codigo: 'E-2', alerta: 'urgente', fecha_programada: '2026-11-07' }),
        row({ id: 'c', codigo: 'E-1', alerta: 'salio_incompleto', fecha_programada: '2026-11-09' }),
        row({ id: 'd', codigo: 'E-4', alerta: 'cancelado' }),
        row({ id: 'e', codigo: 'E-5', alerta: 'sin_seguimiento', cliente_id: 'c2', cliente_nombre: 'OTRO CLIENTE', contenedor: 'TGHU7654321' }),
    ];

    assert.deepEqual(filterRows(rows).map((item) => item.id), ['c', 'b', 'a']);
    assert.deepEqual(filterRows(rows, { all: true }).map((item) => item.id), ['c', 'b', 'a', 'd', 'e']);
    assert.deepEqual(filterRows(rows, { alert: 'cancelado' }).map((item) => item.id), ['d']);
    assert.deepEqual(filterRows(rows, { term: 'tghu', all: true }).map((item) => item.id), ['e']);
    assert.deepEqual(filterRows(rows, { term: 'bk-1' }).map((item) => item.id), ['c', 'b', 'a']);
    assert.deepEqual(filterRows(rows, { client: 'c2', all: true }).map((item) => item.id), ['e']);
    assert.deepEqual(clientsOf(rows), [{ id: 'c1', name: 'EXPORTADORA DEMO' }, { id: 'c2', name: 'OTRO CLIENTE' }]);
    assert.equal(alertInfo('urgente').tone, 'critico');
    assert.equal(alertInfo('desconocida').label, 'desconocida');
});

test('los documentos se agrupan por momento y los no exigidos aparte', () => {
    const item = (codigo, momento, exigencia) => ({ tipo: { codigo, momento }, exigencia });
    const groups = groupDocuments([
        item('dus', 'antes_salida', 'exigido'),
        item('bl', 'despues_salida', 'exigido'),
        item('termografo', 'antes_salida', 'opcional'),
        item('booking', 'antes_salida', 'por_definir'),
    ]);

    assert.deepEqual(groups.map((group) => [group.key, group.items.map((entry) => entry.tipo.codigo)]), [
        ['antes', ['dus']],
        ['despues', ['bl']],
        ['otros', ['termografo', 'booking']],
    ]);
    assert.deepEqual(groupDocuments([item('bl', 'despues_salida', 'exigido')]).map((group) => group.key), ['despues']);
    assert.equal(requirementNote({ exigencia: 'opcional' }), 'Opcional: se registra si se tiene.');
    assert.equal(requirementNote({ exigencia: 'por_definir', motivo_exigencia: 'Falta el país de destino.' }), 'Falta el país de destino.');
});

test('detalle del documento y validación del formulario', () => {
    assert.equal(documentDetail({ numero: '123', fecha_emision: '2026-11-04', estado: 'listo', faltantes: ['respaldo'] }), 'N° 123 · emitido el 04-11-2026 · falta respaldo');
    assert.equal(documentDetail({ estado: 'no_aplica', motivo_no_aplica: 'Mercado sin inspección.' }), 'Mercado sin inspección.');

    const type = { pide_numero: true, pide_fecha: true, pide_archivo: true };
    assert.equal(documentFormProblem(type, { estado: '' }, false), 'Elija el estado del documento.');
    assert.equal(documentFormProblem(type, { estado: 'en_tramite' }, false), '');
    assert.equal(documentFormProblem(type, { estado: 'no_aplica', motivo: 'no' }, false), 'Indique por qué este documento no aplica al embarque.');
    assert.equal(documentFormProblem(type, { estado: 'listo', numero: ' ' }, true), 'Para marcarlo listo, ingrese el número del documento.');
    assert.equal(documentFormProblem(type, { estado: 'listo', numero: '1' }, true), 'Para marcarlo listo, ingrese la fecha de emisión.');
    assert.equal(documentFormProblem(type, { estado: 'listo', numero: '1', fecha_emision: '2026-11-04' }, false), 'Para marcarlo listo, suba el respaldo (PDF o imagen).');
    assert.equal(documentFormProblem(type, { estado: 'listo', numero: '1', fecha_emision: '2026-11-04' }, true), '');
});

test('el historial se lee como una frase', () => {
    assert.equal(historyText({ documento: 'DUS', tipo: 'registrado', estado_nuevo: 'en_tramite', datos: { cambios: { estado: [null, 'en_tramite'], numero: [null, '123'] } } }),
        'DUS: se registró como en trámite (número «123»)');
    assert.equal(historyText({ documento: 'DUS', tipo: 'estado', datos: { cambios: { estado: ['en_tramite', 'listo'], fecha_emision: [null, '2026-11-04'] } } }),
        'DUS: estado: en trámite → listo; fecha de emisión: vacío → 04-11-2026');
    assert.equal(historyText({ documento: 'BL', tipo: 'archivo_reemplazado', datos: { nombre: 'bl-final.pdf' } }), 'BL: se reemplazó el respaldo por bl-final.pdf');
});

test('textos de la configuración', () => {
    assert.equal(transportLabel('aereo'), 'Aéreo');
    assert.equal(transportLabel('por_confirmar'), 'Por confirmar');
    assert.equal(requirementText({ exigencia: 'siempre' }), 'Siempre');
    assert.equal(requirementText({ exigencia: 'paises', paises: [{ nombre: 'China' }, { nombre: 'Corea del Sur' }] }), 'Solo en China, Corea del Sur');
    assert.equal(requirementText({ exigencia: 'paises', paises: ['A', 'B', 'C', 'D', 'E'].map((nombre) => ({ nombre })) }), 'Solo en A, B, C y 2 países más');
    assert.equal(scopeText({ transportes: [], gestiones: [] }), 'Todo transporte · todo cliente');
    assert.equal(scopeText({ transportes: ['maritimo'], gestiones: ['propia'] }), 'Marítimo · Exportación propia');
    assert.equal(asksText({ pide_numero: true, pide_fecha: true, pide_archivo: true }), 'Número, fecha y respaldo');
    assert.equal(asksText({ pide_numero: true, pide_fecha: false, pide_archivo: false }), 'Número');
    assert.equal(asksText({}), 'Solo el estado');
});
