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
    keepElementAsArea,
    linkElementToPlace,
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
    assert.deepEqual(result.sinUbicar.map((item) => item.codigo), ['MAT-01', 'AND-05'], 'la cámara de materiales queda disponible para ubicarla');
    assert.deepEqual(unassignedPlaces(result.elementos).map((item) => item.nombre).sort(), ['Cámara 0', 'Túnel 6']);
    assert.ok(describeConnections(result.elementos, result.conexiones).every((connection) => connection.valid));
    assert.ok(result.elementos.filter((element) => element.tipo === 'zona').every((element) => element.categoria));
    assert.ok(result.elementos.filter((element) => element.tipo !== 'zona').every((element) => element.categoria === null));
});

test('dos recintos con el mismo número quedan por asignar, sin adivinar', () => {
    const duplicated = [...catalog, { tipo: 'camara', id: 'c7b', codigo: 'C-07', nombre: 'Cámara 7', contenido: 'productos' }];
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

test('con el catálogo real de la planta, el nombre manda sobre el código', () => {
    // Catálogo de Agrorosario: las cámaras físicas se conocen como «Cámara de tránsito NN»
    // y sus códigos no siguen ese número (la de tránsito 04 es CAM-10).
    const real = [
        ['CAM-01', 'Cámara de tránsito 01', 'productos'],
        ['CAM-02', 'Cámara de tránsito 02', 'productos'],
        ['CAM-03', 'Cámara de tránsito 03', 'productos'],
        ['CAM-04', 'Bodega Principal', 'materiales'],
        ['CAM-05', 'BODEGA JAULA', 'materiales'],
        ['CAM-06', 'rack jaula', 'materiales'],
        ['CAM-07', 'Camara de MP 1', 'productos'],
        ['CAM-08', 'Altillo', 'materiales'],
        ['CAM-09', 'Camara de tránsito 07', 'productos'],
        ['CAM-10', 'Camara de tránsito 04', 'productos'],
        ['CAM-11', 'Cámara de tránsito 05', 'productos'],
        ['CAM-12', 'Cámara de tránsito 06', 'productos'],
        ['DES-01', 'Zona de despacho', 'productos'],
    ].map(([codigo, nombre, contenido]) => ({ tipo: 'camara', id: codigo, codigo, nombre, contenido }));
    const tunnels = [1, 2, 3, 4, 5, 6].map((n) => ({ tipo: 'tunel', id: `TUN-0${n}`, codigo: `TUN-0${n}`, nombre: `TUNEL ${n}` }));
    const docks = [1, 2, 3, 4].map((n) => ({ tipo: 'anden', id: `AND-0${n}`, codigo: `AND-0${n}`, nombre: `ANDEN ${n}` }));
    const warehouse = { tipo: 'almacen', id: 'BOD-CENTRAL', codigo: 'BOD-CENTRAL', nombre: 'Bodega Central de Materiales' };
    const result = buildPlantFromTemplate(AGROROSARIO_PLANT, [...real, ...tunnels, ...docks, warehouse], { uuid: sequentialIds() });
    const linked = Object.fromEntries(result.asignados.map((link) => [link.plano, link.recinto.split(' · ')[0]]));

    assert.deepEqual(
        ['Cámara 1', 'Cámara 2', 'Cámara 3', 'Cámara 4', 'Cámara 5', 'Cámara 6', 'Cámara 7'].map((name) => linked[name]),
        ['CAM-01', 'CAM-02', 'CAM-03', 'CAM-10', 'CAM-11', 'CAM-12', 'CAM-09'],
    );
    assert.equal(linked['Túnel 4'], 'TUN-04');
    assert.equal(linked['Andén 2'], 'AND-02');
    assert.equal(linked.Altillo, 'CAM-08', 'el área del dibujo con el mismo nombre pasa a ser el recinto');
    assert.deepEqual(result.faltantes.map((item) => item.nombre), ['Cámara 0']);
    assert.deepEqual(
        result.sinUbicar.map((item) => item.codigo).sort(),
        ['BOD-CENTRAL', 'CAM-04', 'CAM-05', 'CAM-06', 'CAM-07', 'DES-01'],
    );

    const altillo = result.elementos.find((element) => element.referencia_id === 'CAM-08');
    assert.equal(altillo.tipo, 'camara');
    assert.equal(altillo.categoria, null);
    assert.ok(describeConnections(result.elementos, result.conexiones).every((connection) => connection.valid));
});

test('una cámara nueva queda como área y se enlaza cuando exista, sin perder forma ni puertas', () => {
    const result = buildPlantFromTemplate(AGROROSARIO_PLANT, catalog, { uuid: sequentialIds() });
    const pending = unassignedPlaces(result.elementos).find((element) => element.nombre === 'Cámara 0');
    const area = keepElementAsArea(pending);

    assert.deepEqual(
        { tipo: area.tipo, referencia_id: area.referencia_id, categoria: area.categoria, nombre: area.nombre },
        { tipo: 'zona', referencia_id: null, categoria: 'otro', nombre: 'Cámara 0' },
    );
    assert.deepEqual([area.x, area.y, area.ancho, area.alto], [pending.x, pending.y, pending.ancho, pending.alto]);

    const created = { tipo: 'camara', id: 'c0', codigo: 'CAM-13', nombre: 'Cámara de tránsito 00' };
    const linked = linkElementToPlace(area, created);
    assert.deepEqual(
        { tipo: linked.tipo, referencia_id: linked.referencia_id, categoria: linked.categoria, nombre: linked.nombre, id: linked.id },
        { tipo: 'camara', referencia_id: 'c0', categoria: null, nombre: 'Cámara de tránsito 00', id: pending.id },
    );

    const elements = result.elementos.map((element) => (element.id === linked.id ? linked : element));
    assert.deepEqual(unassignedPlaces(elements).map((element) => element.nombre), ['Túnel 6']);
    assert.ok(describeConnections(elements, result.conexiones).every((connection) => connection.valid), 'las puertas siguen válidas');
});
