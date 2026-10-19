import assert from 'node:assert/strict';
import test from 'node:test';

import {
    MIN_CORRIDOR_THICKNESS,
    MIN_ELEMENT_SIZE,
    PLANT_SIZE,
    contactPoint,
    describeConnections,
} from '../../resources/js/shared/plant-layout.js';
import {
    AGROROSARIO_PLANT,
    PLANT_TEMPLATES,
    buildPlantFromTemplate,
    recintoNumber,
    unassignedPlaces,
} from '../../resources/js/shared/plant-templates.js';

function sequentialIds() {
    let next = 0;
    return () => `00000000-0000-4000-8000-${String(next++).padStart(12, '0')}`;
}

const catalog = [
    ...[1, 2, 3, 4, 5, 6, 7].map((n) => ({ tipo: 'camara', id: `c${n}`, codigo: `CAM-0${n}`, nombre: `Cámara ${n}`, contenido: 'productos' })),
    { tipo: 'camara', id: 'm1', codigo: 'MAT-01', nombre: 'Materiales 1', contenido: 'materiales' },
    ...[1, 2, 3, 4, 5].map((n) => ({ tipo: 'tunel', id: `t${n}`, codigo: `T${n}`, nombre: `Túnel ${n}` })),
    ...[1, 2, 3, 4, 5].map((n) => ({ tipo: 'anden', id: `a${n}`, codigo: `AND-0${n}`, nombre: `Andén ${n}` })),
];

test('el plano base cumple las reglas de guardado del servidor', () => {
    const claves = AGROROSARIO_PLANT.elementos.map((element) => element.clave);
    assert.equal(new Set(claves).size, claves.length, 'cada elemento tiene una clave única');
    assert.ok(PLANT_TEMPLATES.includes(AGROROSARIO_PLANT));
    assert.ok(AGROROSARIO_PLANT.proporcion >= 0.5 && AGROROSARIO_PLANT.proporcion <= 2.5);

    for (const element of AGROROSARIO_PLANT.elementos) {
        assert.ok(element.x >= 0 && element.y >= 0, `${element.nombre} dentro del plano`);
        assert.ok(element.x + element.ancho <= PLANT_SIZE && element.y + element.alto <= PLANT_SIZE, `${element.nombre} dentro del plano`);
        const smaller = Math.min(element.ancho, element.alto);
        const larger = Math.max(element.ancho, element.alto);
        if (element.tipo === 'pasillo') {
            assert.ok(smaller >= MIN_CORRIDOR_THICKNESS && larger >= MIN_ELEMENT_SIZE, `${element.nombre}: pasillo visible`);
        } else {
            assert.ok(smaller >= MIN_ELEMENT_SIZE, `${element.nombre}: ${element.ancho}×${element.alto} bajo el mínimo`);
        }
        if (['camara', 'tunel', 'anden'].includes(element.tipo)) assert.ok(Number.isInteger(element.numero), `${element.nombre} declara número`);
    }

    const elements = AGROROSARIO_PLANT.elementos;
    for (let i = 0; i < elements.length; i += 1) {
        for (let j = i + 1; j < elements.length; j += 1) {
            const [a, b] = [elements[i], elements[j]];
            const overlapX = Math.min(a.x + a.ancho, b.x + b.ancho) - Math.max(a.x, b.x);
            const overlapY = Math.min(a.y + a.alto, b.y + b.alto) - Math.max(a.y, b.y);
            assert.ok(overlapX <= 0 || overlapY <= 0, `${a.nombre} y ${b.nombre} no se superponen`);
        }
    }

    const byKey = new Map(elements.map((element) => [element.clave, element]));
    for (const [from, to] of AGROROSARIO_PLANT.conexiones) {
        assert.ok(byKey.has(from) && byKey.has(to), `${from} ↔ ${to} existen`);
        assert.ok(contactPoint(byKey.get(from), byKey.get(to)), `${from} ↔ ${to} comparten borde`);
        assert.ok(![byKey.get(from), byKey.get(to)].some((element) => element.categoria === 'no_operativo'));
    }
});

test('todo recinto del plano base queda conectado a la red', () => {
    const connected = new Set(AGROROSARIO_PLANT.conexiones.flat());
    const places = AGROROSARIO_PLANT.elementos.filter((element) => ['camara', 'tunel', 'anden'].includes(element.tipo));
    assert.deepEqual(places.filter((place) => !connected.has(place.clave)).map((place) => place.nombre), []);
    assert.equal(places.filter((place) => place.tipo === 'camara').length, 8);
    assert.equal(places.filter((place) => place.tipo === 'tunel').length, 6);
    assert.equal(places.filter((place) => place.tipo === 'anden').length, 4);
});

test('el número del recinto sale del código o del nombre', () => {
    assert.equal(recintoNumber({ codigo: 'CAM-07' }), 7);
    assert.equal(recintoNumber({ codigo: 'T3' }), 3);
    assert.equal(recintoNumber({ codigo: 'PT', nombre: 'Cámara 12' }), 12);
    assert.equal(recintoNumber({ codigo: 'Norte', nombre: 'Cámara norte' }), null);
});

test('enlaza por número, prefiere cámaras de producto y reporta lo que falta', () => {
    const result = buildPlantFromTemplate(AGROROSARIO_PLANT, catalog, { uuid: sequentialIds() });
    const byName = new Map(result.elementos.map((element) => [element.nombre, element]));

    assert.equal(result.proporcion, AGROROSARIO_PLANT.proporcion);
    assert.equal(result.elementos.length, AGROROSARIO_PLANT.elementos.length);
    assert.equal(result.conexiones.length, AGROROSARIO_PLANT.conexiones.length);
    assert.equal(byName.get('Cámara 1').referencia_id, 'c1', 'MAT-01 no reemplaza a la cámara 1 de producto');
    assert.equal(byName.get('Túnel 3').referencia_id, 't3');
    assert.equal(byName.get('Andén 4').referencia_id, 'a4');
    assert.deepEqual(result.faltantes.map((item) => item.nombre).sort(), ['Cámara 0', 'Túnel 6']);
    assert.deepEqual(result.sinUbicar.map((item) => item.codigo), ['AND-05'], 'la cámara de materiales no se reclama');
    assert.deepEqual(unassignedPlaces(result.elementos).map((item) => item.nombre).sort(), ['Cámara 0', 'Túnel 6']);
    assert.ok(describeConnections(result.elementos, result.conexiones).every((connection) => connection.valid));
    assert.ok(result.elementos.filter((element) => element.tipo === 'zona').every((element) => element.categoria));
    assert.ok(result.elementos.filter((element) => element.tipo !== 'zona').every((element) => element.categoria === null));
});

test('dos recintos con el mismo número quedan por asignar, sin adivinar', () => {
    const duplicated = [...catalog, { tipo: 'camara', id: 'c7b', codigo: 'C7', nombre: 'Cámara 7 antigua', contenido: 'productos' }];
    const result = buildPlantFromTemplate(AGROROSARIO_PLANT, duplicated, { uuid: sequentialIds() });

    assert.equal(result.elementos.find((element) => element.nombre === 'Cámara 7').referencia_id, null);
    assert.ok(result.faltantes.some((item) => item.nombre === 'Cámara 7' && /más de un recinto/.test(item.motivo)));
});

test('las áreas confirmadas llevan su indicador: recepción MP en la zona de resguardo, sin REPA', () => {
    const zones = new Map(AGROROSARIO_PLANT.elementos.map((element) => [element.clave, element]));
    assert.equal(zones.get('resguardo').categoria, 'recepcion_mp');
    assert.notEqual(zones.get('palletizaje').categoria, 'repa', 'palletizaje no es repaletizaje');
    assert.ok(!AGROROSARIO_PLANT.elementos.some((element) => element.categoria === 'repa'));
});
