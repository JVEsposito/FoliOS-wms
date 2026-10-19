import assert from 'node:assert/strict';
import test from 'node:test';

import {
    ATTENTION_SOURCES,
    buildAttention,
    buildOperationTabBadges,
} from '../../resources/js/shared/operation-now-attention.js';

function snapshot(overrides = {}) {
    return {
        camaras: [
            { codigo: 'CAM-10', contenido: 'productos', ocupadas: 40, capacidad_operativa: 100, ocupacion_porcentaje: 40, nivel_ocupacion: 'normal', control_ambiental: { estado: 'vencido', capturado_at: '2026-10-19T06:00:00Z' } },
            { codigo: 'CAM-11', contenido: 'productos', ocupadas: 50, capacidad_operativa: 100, ocupacion_porcentaje: 50, nivel_ocupacion: 'normal', control_ambiental: { estado: 'vencido', capturado_at: '2026-10-19T06:10:00Z' } },
            { codigo: 'CAM-12', contenido: 'productos', ocupadas: 92, capacidad_operativa: 100, ocupacion_porcentaje: 92, nivel_ocupacion: 'critica', control_ambiental: { estado: 'vigente' } },
        ],
        prefrio: {
            resumen: { procesos_activos: 2 },
            tuneles: [{ codigo: 'TUN-03', operable: true, estado_operacional: 'en_ciclo', proceso_activo: { objetivo_excedido: true, minutos_sobre_objetivo: 35 } }],
        },
        sincronizacion: { operaciones_hoy: { conflicto: 0, rechazada: 0 } },
        planificador: {
            despliegue: { mode_global: 'guided' },
            arbitraje: { vigencia: { estado: 'actual' }, ciclo: { resumen: { en_curso: 2 } } },
            salud: { riesgos: { maniobras_completadas_con_custodia: 1, tareas_estancadas: 0, discrepancias_abiertas: 3 } },
        },
        incidencias: {
            abiertas: [
                { id: 'a', origen: 'maniobra', tipo: 'diferencia_ubicacion', prioridad: 'normal', antiguedad_minutos: 17, folio: { numero_folio: '1000000173' }, detalle: 'Folio distinto' },
                { id: 'b', origen: 'maniobra', tipo: 'diferencia_ubicacion', prioridad: 'urgente', antiguedad_minutos: 240, folio: { numero_folio: '1000000174' } },
            ],
        },
        camareros: [{ usuario: { nombre: 'Ana' } }, { usuario: { nombre: 'Luis' } }],
        ...overrides,
    };
}

test('agrupa por causa: una fila de control vencido con las cámaras afectadas', () => {
    const { rows } = buildAttention(snapshot());
    const environment = rows.find((row) => row.condition === 'Control ambiental vencido');

    assert.equal(environment.source, 'Ambiente');
    assert.equal(environment.severity, 'critical');
    assert.equal(environment.detail, 'La última lectura ya no está vigente');
    assert.deepEqual(environment.items.map((item) => item.code), ['CAM-10', 'CAM-11']);
    assert.equal(environment.items[0].note, null, 'la evidencia compartida va una sola vez');
});

test('reúne alertas, riesgos del planificador e incidencias sin duplicar discrepancias', () => {
    const attention = buildAttention(snapshot());
    const sources = new Set(attention.rows.map((row) => row.source));

    assert.ok(sources.has('Ambiente'));
    assert.ok(sources.has('Ocupación'));
    assert.ok(sources.has('Prefrío'));
    assert.ok(sources.has('Planificador'));
    assert.ok(sources.has('Incidencias'));
    assert.ok(!attention.rows.some((row) => /Diferencias reportadas/.test(row.condition)),
        'las discrepancias abiertas ya figuran como incidencias de maniobra');

    const planner = attention.rows.find((row) => row.source === 'Planificador');
    assert.equal(planner.condition, 'Pallets sin devolver');
    assert.match(planner.detail, /^1 pallet · /);
    assert.equal(planner.href, '#maniobras');
});

test('las incidencias del mismo tipo se agrupan con la más antigua primero', () => {
    const incidents = buildAttention(snapshot()).rows.find((row) => row.source === 'Incidencias');

    assert.equal(incidents.condition, 'Diferencia de ubicación');
    assert.equal(incidents.severity, 'critical', 'una urgente eleva el grupo');
    assert.equal(incidents.ageMinutes, 240);
    assert.deepEqual(incidents.items.map((item) => item.code), ['1000000174', '1000000173']);
    assert.equal(incidents.items[0].incident.id, 'b', 'la vista conserva la incidencia para sus acciones');
    assert.match(incidents.detail, /Maniobra · 2 abiertas/);
});

test('ordena por severidad y antigüedad y cuenta cada fuente revisada', () => {
    const attention = buildAttention(snapshot());

    assert.deepEqual(attention.rows.map((row) => row.severity).slice(0, attention.critical), Array(attention.critical).fill('critical'));
    assert.equal(attention.rows[0].source, 'Incidencias', 'la crítica más antigua va primero');
    assert.equal(attention.total, attention.critical + attention.warning);
    assert.deepEqual(attention.sources.map((source) => source.name), [...ATTENTION_SOURCES]);
    assert.deepEqual(attention.sources.find((source) => source.name === 'Emergencias'), { name: 'Emergencias', count: 0, severity: null });
    assert.equal(attention.sources.find((source) => source.name === 'Ambiente').severity, 'critical');
});

test('describeIncident compone la nota física de cada incidencia', () => {
    const { rows } = buildAttention(snapshot(), { describeIncident: (incident) => `nota ${incident.id}` });
    const incidents = rows.find((row) => row.source === 'Incidencias');

    assert.deepEqual(incidents.items.map((item) => item.note), ['nota b', 'nota a']);
});

test('el planificador atrasado solo se informa si está dirigiendo u observando', () => {
    const delayed = (mode) => buildAttention(snapshot({
        planificador: {
            despliegue: { mode_global: mode },
            arbitraje: { vigencia: { estado: 'atrasado', detalle: 'Último cálculo hace 12 min' } },
            salud: { riesgos: {} },
        },
    })).rows.filter((row) => row.source === 'Planificador');

    assert.equal(delayed('guided')[0].condition, 'Cálculo del planificador atrasado');
    assert.equal(delayed('guided')[0].detail, 'Último cálculo hace 12 min');
    assert.deepEqual(delayed('off'), []);
});

test('sin evidencia no hay filas y las fuentes quedan en cero', () => {
    const attention = buildAttention({ camaras: [], prefrio: { tuneles: [] }, incidencias: { abiertas: [] } });

    assert.equal(attention.total, 0);
    assert.ok(attention.sources.every((source) => source.count === 0 && source.severity === null));
});

test('las insignias dan contexto neutro y solo Atención lleva severidad', () => {
    const data = snapshot();
    const attention = buildAttention(data);
    const badges = buildOperationTabBadges(data, attention);

    assert.equal(badges.atencion.tone, 'critical');
    assert.equal(badges.atencion.text, String(attention.total));
    assert.deepEqual(badges.maniobras, { text: '2 en curso', tone: 'neutral' });
    assert.equal(badges.plano, null);
    assert.equal(badges.camaras.text, '61 %');
    assert.equal(badges.camaras.tone, 'neutral');
    assert.deepEqual(badges.prefrio, { text: '2 en proceso', tone: 'neutral' });
    assert.deepEqual(badges.personal, { text: '2 activos', tone: 'neutral' });
});

test('las insignias en cero desaparecen', () => {
    const empty = { camaras: [], prefrio: { resumen: { procesos_activos: 0 }, tuneles: [] }, camareros: [] };
    const badges = buildOperationTabBadges(empty, buildAttention(empty));

    assert.deepEqual(badges, { atencion: null, maniobras: null, plano: null, camaras: null, prefrio: null, personal: null });
});
