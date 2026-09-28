import assert from 'node:assert/strict';
import test from 'node:test';
import { bandAffinityText } from '../../resources/js/shared/camera-band-affinity.js';
import { cameraReferenceLabel } from '../../resources/js/shared/camera-display.js';
import { maneuverSituation, plannerCapacityText, plannerEmptyDetail } from '../../resources/js/shared/operation-planner-presentation.js';

test('separa pausada, tomada y en ejecución según el retiro físico', () => {
    assert.equal(maneuverSituation({ estado: 'pausada_discrepancia', paso_actual: { estado: 'en_proceso' } }).label,
        'Pausada · en espera de supervisión');
    assert.equal(maneuverSituation({ estado: 'en_ejecucion', paso_actual: { estado: 'asumida' } }).label,
        'Tomada · destino reservado');
    assert.equal(maneuverSituation({ estado: 'en_ejecucion', paso_actual: { estado: 'en_proceso' } }).label,
        'En ejecución');
});

test('presenta cupos ocupados y evita declarar al día un ciclo atrasado', () => {
    assert.equal(plannerCapacityText({ cupos_ocupados: 1, capacidad_ejecucion: 3, frontera_max: 4 }), '1 de 3 ocupados');
    assert.match(plannerEmptyDetail({ estado: 'atrasado', detalle: 'El último cálculo venció.' }), /venció/);
    assert.doesNotMatch(plannerEmptyDetail({ estado: 'atrasado' }), /al día/);
});

test('afinidad y cámara se leen como valores, nunca como objetos implícitos', () => {
    assert.equal(bandAffinityText({ afinidad: {
        activa: true, cliente: { valor: 'Cliente A', pallets: 2 }, marca: { valor: 'Marca B', pallets: 2 }, formato: null,
    } }), 'Cliente A · Marca B');
    assert.equal(cameraReferenceLabel({ codigo: 'CAM-09', nombre: 'Cámara de tránsito 07' }), 'CAM-09 · Cámara de tránsito 07');
});
