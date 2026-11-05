import test from 'node:test';
import assert from 'node:assert/strict';
import {
    buildPlantIndex,
    crewByCamera,
    plantNodeLabel,
    plantNodeModel,
    plantNodeTitle,
    tunnelStateTone,
} from '../../resources/js/shared/plant-view.js';

test('el plano conserva el código cuando el modelo de cámara no tiene nombre', () => {
    const model = { kind: 'camara', name: '   ', code: 'CAM-04', alerts: [] };
    assert.equal(plantNodeTitle(model), 'CAM-04');
    assert.equal(plantNodeLabel(model), 'CAM-04');
});

const element = (tipo, referencia_id, extra = {}) => ({
    id: `e-${tipo}-${referencia_id}`,
    tipo,
    referencia_id,
    nombre: 'Recinto',
    categoria: null,
    x: 0,
    y: 0,
    ancho: 1000,
    alto: 1000,
    rotacion: 0,
    ...extra,
});

function snapshot(overrides = {}) {
    return {
        camaras: [
            { id: 'c1', codigo: 'CAM-01', nombre: 'Tránsito', ocupacion_porcentaje: 94.4, nivel_ocupacion: 'critica', ocupadas: 113, capacidad_operativa: 120 },
            { id: 'c2', codigo: 'CAM-02', nombre: 'Tránsito 2', ocupacion_porcentaje: 71, nivel_ocupacion: 'advertencia', ocupadas: 85, capacidad_operativa: 120, control_ambiental: { estado: 'vencido', requiere_control: true } },
        ],
        prefrio: {
            tuneles: [
                { id: 't1', codigo: 'TUN-01', estado_operacional: 'en_proceso', operable: true, ocupacion_porcentaje: 80, proceso_activo: { avance_tiempo_objetivo_porcentaje: 45.6, objetivo_excedido: false } },
                { id: 't2', codigo: 'TUN-02', estado_operacional: 'mantenimiento', operable: false, ocupacion_porcentaje: 0, proceso_activo: null },
            ],
        },
        camareros: [
            { usuario: { nombre: 'Ana' }, dispositivo: { codigo: 'GRUA-1' }, ubicacion_actual: { camara: { id: 'c1' } } },
            { usuario: { nombre: 'Luis' }, dispositivo: { codigo: 'GRUA-2' }, ubicacion_actual: { camara: { id: 'c1' } } },
            { usuario: { nombre: 'Ana' }, dispositivo: { codigo: 'PDA-9' }, ubicacion_actual: { camara: { id: 'c1' } } },
        ],
        planta: {
            catalogo: [
                { tipo: 'camara', id: 'c1', codigo: 'CAM-01', nombre: 'Tránsito', estado: 'operativa' },
                { tipo: 'camara', id: 'c2', codigo: 'CAM-02', nombre: 'Tránsito 2', estado: 'operativa' },
                { tipo: 'camara', id: 'c3', codigo: 'CAM-03', nombre: 'Antigua', estado: 'fuera_servicio' },
                { tipo: 'tunel', id: 't1', codigo: 'TUN-01', nombre: 'Túnel 1' },
                { tipo: 'tunel', id: 't2', codigo: 'TUN-02', nombre: 'Túnel 2' },
                { tipo: 'anden', id: 'a1', codigo: 'AND-01', nombre: 'Andén 1', ocupado: true, patente: 'KXTR42', carga_codigo: 'CAR-1' },
                { tipo: 'anden', id: 'a2', codigo: 'AND-02', nombre: 'Andén 2', ocupado: false, patente: null, carga_codigo: null },
                { tipo: 'almacen', id: 'b1', codigo: 'BOD-01', nombre: 'Bodega central' },
            ],
            indicadores: {
                repa: { pallets_pendientes: 8, maximo: 10, umbral_alta: 8, prioridad: 'alta' },
                recepcion_mp: { en_romana: 2, pendientes_validacion: 3, en_validacion: 1 },
            },
        },
        ...overrides,
    };
}

test('la cámara muestra su ocupación redondeada y se rellena solo en nivel crítico', () => {
    const index = buildPlantIndex(snapshot());
    const critical = plantNodeModel(element('camara', 'c1'), index);
    const warning = plantNodeModel(element('camara', 'c2'), index);

    assert.equal(critical.value, '94%');
    assert.equal(critical.tone, 'critical');
    assert.equal(critical.fill, true);
    assert.deepEqual(critical.meter, { value: 94, tone: 'critical' });
    assert.equal(warning.tone, 'warning');
    assert.equal(warning.fill, false);
    assert.deepEqual(warning.alerts, ['Control ambiental vencido']);
});

test('personal y equipos se agrupan por cámara sin duplicar personas', () => {
    const crews = crewByCamera(snapshot().camareros);
    assert.deepEqual(crews.get('c1'), { personas: ['Ana', 'Luis'], equipos: ['GRUA-1', 'GRUA-2', 'PDA-9'] });

    const model = plantNodeModel(element('camara', 'c1'), buildPlantIndex(snapshot()));
    assert.match(plantNodeLabel(model), /2 personas/);
    assert.match(plantNodeLabel(model), /3 equipos móviles/);
});

test('una cámara inactiva y un túnel en mantención se muestran fuera de servicio', () => {
    const index = buildPlantIndex(snapshot());
    const camera = plantNodeModel(element('camara', 'c3'), index);
    const tunnel = plantNodeModel(element('tunel', 't2'), index);

    for (const model of [camera, tunnel]) {
        assert.equal(model.state, 'mantencion');
        assert.equal(model.glyph, 'wrench');
        assert.equal(model.value, null);
    }
    assert.equal(tunnel.detail, 'Mantención');
});

test('el túnel usa el mismo tono que la tabla de prefrío y declara el avance del ciclo', () => {
    const index = buildPlantIndex(snapshot());
    const model = plantNodeModel(element('tunel', 't1'), index);

    assert.equal(model.value, '80%');
    assert.equal(model.tone, tunnelStateTone(snapshot().prefrio.tuneles[0]));
    assert.equal(model.detail, 'En ciclo · ciclo 46%');
    assert.deepEqual(model.meter, { value: 80, tone: model.tone });
});

test('el andén muestra el camión presente o queda libre', () => {
    const index = buildPlantIndex(snapshot());
    const busy = plantNodeModel(element('anden', 'a1'), index);
    const free = plantNodeModel(element('anden', 'a2'), index);

    assert.equal(busy.glyph, 'truck');
    assert.equal(busy.value, 'KXTR42');
    assert.equal(free.glyph, 'dock');
    assert.equal(free.value, 'Libre');
});

test('las áreas REPA y Recepción MP usan los indicadores vivos del servidor', () => {
    const index = buildPlantIndex(snapshot());
    const repa = plantNodeModel(element('zona', null, { nombre: 'REPA', categoria: 'repa' }), index);
    const raw = plantNodeModel(element('zona', null, { nombre: 'Recepción', categoria: 'recepcion_mp' }), index);
    const patio = plantNodeModel(element('zona', null, { nombre: 'Patio', categoria: 'patio' }), index);

    assert.equal(repa.value, '8 / 10');
    assert.equal(repa.tone, 'warning');
    assert.deepEqual(repa.meter, { value: 80, tone: 'warning' });
    assert.equal(raw.value, '4');
    assert.equal(raw.detail, '2 en Romana · 1 en validación');
    assert.equal(patio.value, null);
    assert.equal(patio.tag, 'Patio');
});

test('una referencia que ya no existe queda marcada en vez de desaparecer', () => {
    const model = plantNodeModel(element('camara', 'no-existe'), buildPlantIndex(snapshot()));
    assert.equal(model.state, 'sin_referencia');
    assert.equal(model.detail, 'Referencia no disponible');
});

test('sin indicadores las áreas vivas se dibujan sin valores inventados', () => {
    const index = buildPlantIndex(snapshot({ planta: { catalogo: [], indicadores: { repa: null, recepcion_mp: null } } }));
    const repa = plantNodeModel(element('zona', null, { nombre: 'REPA', categoria: 'repa' }), index);
    assert.equal(repa.value, null);
    assert.equal(repa.meter, null);
});

test('un túnel ocupado por un proceso de otra temporada se marca como crítico y explica la causa', () => {
    const index = buildPlantIndex(snapshot({
        prefrio: {
            tuneles: [
                {
                    id: 't1',
                    codigo: 'TUN-01',
                    estado_operacional: 'bloqueado_otra_temporada',
                    operable: true,
                    ocupacion_porcentaje: 0,
                    proceso_activo: null,
                    proceso_otra_temporada: { id: 'p-old', codigo: 'PF-2026-000031', temporada_codigo: '2025-2026' },
                },
            ],
        },
    }));
    const model = plantNodeModel(element('tunel', 't1'), index);

    assert.equal(model.tone, 'critical');
    assert.equal(model.value, 'Bloqueado');
    assert.equal(model.meter, null);
    assert.equal(model.detail, 'Temporada anterior');
    assert.deepEqual(model.alerts, ['Proceso PF-2026-000031 de la temporada 2025-2026 sin cerrar']);
});
