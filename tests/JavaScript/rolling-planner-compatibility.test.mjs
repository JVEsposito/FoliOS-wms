import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { stripTypeScriptTypes } from 'node:module';
import test from 'node:test';

const source = await readFile(new URL('../../mobile/src/domain/rollingPlanner.ts', import.meta.url), 'utf8');
const { bestCandidate } = await import(`data:text/javascript,${encodeURIComponent(stripTypeScriptTypes(source))}`);

function camera(id, affinity) {
    return {
        id,
        contenido: 'productos',
        estado: 'activa',
        posiciones: [{
            id: `position-${id}`,
            banda: 1,
            posicion: 1,
            nivel: 1,
            estado: 'activa',
            ocupada: false,
            reservada: false,
        }],
        bandas_operacionales: [{
            numero: 1,
            acepta_nuevos_ingresos: true,
            usos_permitidos: ['transito_pt'],
            modo: 'operativa',
            estado: 'libre',
            capacidad: { disponibles: 2, ocupadas: affinity?.pallets_completos ?? 0 },
            afinidad: affinity,
        }],
    };
}

const task = {
    id: 'pallet-1',
    tipo_movimiento: 'ubicacion_inicial',
    destino: null,
    origen: null,
    contexto: { cliente: 'Cliente Norte', marca: 'Marca Norte', formato: 'Caja 5' },
};

test('una banda compatible en otra cámara gana a la preferente vacía', () => {
    const preferred = camera('despacho', { activa: false, pallets_completos: 0 });
    const compatible = camera('otra', {
        activa: true,
        pallets_completos: 1,
        cliente: { valor: 'Cliente Norte' },
        marca: { valor: 'Marca Norte' },
        formato: { valor: 'Caja 5' },
    });

    assert.equal(bestCandidate(task, [preferred, compatible], new Set(), new Set(), 'despacho')?.cameraId, 'otra');
});

test('la cámara preferente gana entre bandas igualmente compatibles', () => {
    const preferred = camera('despacho', { activa: false, pallets_completos: 0 });
    const other = camera('otra', { activa: false, pallets_completos: 0 });

    assert.equal(bestCandidate(task, [other, preferred], new Set(), new Set(), 'despacho')?.cameraId, 'despacho');
});

test('una marca igual de otro cliente no desplaza una banda vacía en despacho', () => {
    const preferred = camera('despacho', { activa: false, pallets_completos: 0 });
    const mixed = camera('otra', {
        activa: true,
        pallets_completos: 1,
        cliente: { valor: 'Cliente Sur' },
        marca: { valor: 'Marca Norte' },
        formato: { valor: 'Caja 5' },
    });

    assert.equal(bestCandidate(task, [mixed, preferred], new Set(), new Set(), 'despacho')?.cameraId, 'despacho');
});

test('un cliente minoritario presente en otra banda mixta cuenta como afinidad real', () => {
    const preferred = camera('despacho', { activa: false, pallets_completos: 0 });
    const mixed = camera('otra', {
        activa: true,
        pallets_completos: 3,
        cliente: { valor: 'Cliente Sur' },
        marca: { valor: 'Marca Sur' },
        formato: { valor: 'Caja 5' },
        perfiles: [
            { cliente: 'Cliente Sur', marca: 'Marca Sur', formato: 'Caja 5', pallets: 2 },
            { cliente: 'Cliente Norte', marca: 'Marca Norte', formato: 'Caja 5', pallets: 1 },
        ],
    });

    assert.equal(bestCandidate(task, [preferred, mixed], new Set(), new Set(), 'despacho')?.cameraId, 'otra');
});

test('una banda sin mezcla de clientes gana a otra igual de afín en la cámara preferente', () => {
    const mixed = camera('despacho', {
        activa: true,
        pallets_completos: 2,
        perfiles: [
            { cliente: 'Cliente Norte', marca: 'Marca Norte', formato: 'Caja 5', pallets: 1 },
            { cliente: 'Cliente Sur', marca: 'Marca Sur', formato: 'Caja 5', pallets: 1 },
        ],
    });
    const clean = camera('otra', {
        activa: true,
        pallets_completos: 1,
        perfiles: [{ cliente: 'Cliente Norte', marca: 'Marca Norte', formato: 'Caja 5', pallets: 1 }],
    });

    assert.equal(bestCandidate(task, [mixed, clean], new Set(), new Set(), 'despacho')?.cameraId, 'otra');
});
