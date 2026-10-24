import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { stripTypeScriptTypes } from 'node:module';
import test from 'node:test';
const source = stripTypeScriptTypes(await readFile(new URL('../../mobile/src/domain/rollingPlanner.ts', import.meta.url), 'utf8'));
const { bestCandidate } = await import(`data:text/javascript,${encodeURIComponent(source)}`);
const position = (id, band = 2, depth = 1, extra = {}) => ({ id, banda: band, posicion: depth, nivel: 1, estado: 'activa', ocupada: false, reservada: false, ...extra });
const camera = (id, positions = [position(id)]) => ({ id, estado: 'activa', contenido: 'productos', posiciones: positions,
    bandas_operacionales: [1, 2, 3].map((numero) => ({ numero, modo: 'operativa', estado: 'libre', acepta_nuevos_ingresos: true,
        usos_permitidos: ['transito_pt'], capacidad: { disponibles: 10 } })) });
const clearance = { id: 'clear', tipo_movimiento: 'traslado_entre_camaras', destino: null,
    origen: { camara: { id: 'cam5' }, posicion: position('origin', 1, 3) },
    contexto: { tipo_decision: 'despeje_salida_directa' }, folio: { exportadora: 'Cliente', marca: 'Marca', envase: 'Caja 5' } };

test('despeje prefiere cámara 5 con capacidad válida aunque cámara 1 tenga mejor afinidad y puntaje', () => {
    const local = camera('cam5'); const remote = camera('cam1');
    remote.bandas_operacionales[1].afinidad = { pallets_completos: 1, perfiles: [{ cliente: 'Cliente', marca: 'Marca', formato: 'Caja 5', pallets: 1 }] };
    for (const tipo_decision of ['despeje_salida_directa', 'despeje_concentracion']) {
        const result = bestCandidate({ ...clearance, contexto: { tipo_decision } }, [remote, local]);
        assert.equal(result.cameraId, 'cam5'); assert.match(result.reason, /cámara de origen/);
    }
});

test('no vuelve a poner el blocker en la banda de extracción ni en otra banda protegida de la carga', () => {
    const local = camera('cam5', [position('source-band', 1), position('other-load-band', 2), position('safe-band', 3)]);
    const task = { ...clearance, contexto: { ...clearance.contexto, bandas_extraccion_protegidas: [{ camara_id: 'cam5', banda: 2, nivel: 1 }] } };
    assert.equal(bestCandidate(task, [local]).position.id, 'safe-band');
});

test('abre otra cámara cuando la capacidad local está ocupada, reservada, bloqueada o no es accesible', () => {
    for (const reason of ['occupied', 'reserved', 'blocked', 'access', 'support', 'protected']) {
        const local = camera('cam5'); const p = local.posiciones[0];
        if (reason === 'occupied') p.ocupada = true;
        if (reason === 'reserved') p.reservada = true;
        if (reason === 'blocked') local.bandas_operacionales[1].acepta_nuevos_ingresos = false;
        if (reason === 'access') local.posiciones.push(position('front', 2, 2, { ocupada: true }));
        if (reason === 'support') p.nivel = 2;
        if (reason === 'protected') p.banda = 1;
        assert.equal(bestCandidate(clearance, [local, camera('cam1')]).cameraId, 'cam1', reason);
    }
});

test('cuando la cámara de origen no tiene capacidad no inventa un destino', () => {
    assert.equal(bestCandidate(clearance, [camera('cam5', [])]), null);
});

test('al recalcular un despeje sin destino reservado una propuesta remota anterior no excluye la cámara local', () => {
    for (const posicion of [null, position('remote')]) {
        const pending = { ...clearance, estado: 'asumida', punto_no_retorno: false,
            destino: { camara: { id: 'cam1' }, posicion }, reserva: { tipo_compromiso: 'claim' } };
        assert.equal(bestCandidate(pending, [camera('cam1', [position('remote')]), camera('cam5')]).cameraId, 'cam5');
        assert.equal(pending.destino.camara.id, 'cam1');
    }
});

test('el recálculo de despeje conserva destinos físicos, movimientos iniciados y destinos inmutables', () => {
    const fixed = { ...clearance, estado: 'asumida', destino: { camara: { id: 'cam1' }, posicion: position('remote') } };
    for (const commitment of [
        { reserva: { tipo_compromiso: 'fisica' } },
        { reserva: { destino_reservado: true } },
        { maniobra: { custodia_temporal_activa: true } },
        { estado: 'en_proceso' },
        { punto_no_retorno: true },
        { contexto: { ...fixed.contexto, destino_precalculado_inmutable: true } },
    ]) {
        assert.equal(bestCandidate({ ...fixed, ...commitment }, [camera('cam1', [position('remote')]), camera('cam5')]).position.id, 'remote');
    }
});
