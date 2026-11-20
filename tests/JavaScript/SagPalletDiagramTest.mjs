import test from 'node:test';
import assert from 'node:assert/strict';
import {
    cargaDiagrama, clasificarNumeros, corridasPallet, distribuir, erroresPallet, etiquetaTemporada, firmaEstructura, interpretarNumeros, laminaSvg,
    marcasPorPallet, numerar, palletsIniciales, tituloDiagrama, totalPallet, ubicarCaja, verificarContinuidad,
} from '../../resources/js/shared/sag-pallet-diagram.js';

const pallet = (base, corridas, extra = {}) => ({ folio: '', base, corridas, ultima: null, declaradas: null, ...extra });

test('parte con 16 pallets sin configurar y sin cajas', () => {
    const pallets = palletsIniciales();
    assert.equal(pallets.length, 16);
    const n = numerar(pallets);
    assert.equal(n.total, 0);
    assert.equal(n.valido, false);
    assert.deepEqual(n.rangos.map((r) => r.inicio), Array(16).fill(null));
});

test('la numeración sigue de un pallet al siguiente sin reiniciar', () => {
    // Configuración real del «DIAGRAMA USDA Nº 8»: 8×13, dos de 5×10 y el resto 8×13.
    const pallets = [pallet(8, 13), pallet(5, 10), pallet(5, 10), ...Array.from({ length: 13 }, () => pallet(8, 13))];
    const n = numerar(pallets);
    assert.equal(n.valido, true);
    assert.deepEqual(n.rangos.slice(0, 7).map((r) => [r.inicio, r.fin]), [[1, 104], [105, 154], [155, 204], [205, 308], [309, 412], [413, 516], [517, 620]]);
    assert.equal(n.total, 104 * 14 + 100);
    assert.equal(verificarContinuidad(n), true);
});

test('el pallet siguiente parte en el número posterior a la última caja del anterior', () => {
    const n = numerar([pallet(9, 9), pallet(8, 13)]);
    assert.equal(n.rangos[0].fin, 81);
    assert.equal(n.rangos[1].inicio, 82);
});

test('cambiar un pallet recalcula todos los posteriores', () => {
    const pallets = [pallet(8, 13), pallet(8, 13), pallet(8, 13)];
    assert.equal(numerar(pallets).rangos[2].inicio, 209);
    pallets[0] = pallet(8, 12);
    const n = numerar(pallets);
    assert.deepEqual(n.rangos.map((r) => [r.inicio, r.fin]), [[1, 96], [97, 200], [201, 304]]);
});

test('la última corrida incompleta suma solo sus cajas y queda arriba desde la izquierda', () => {
    const p = pallet(8, 13, { ultima: 5 });
    assert.equal(totalPallet(p), 101);
    const n = numerar([p, pallet(8, 2)]);
    assert.deepEqual([n.rangos[0].fin, n.rangos[1].inicio], [101, 102]);
    const corridas = corridasPallet(p, n.rangos[0]);
    assert.equal(corridas.length, 13);
    assert.deepEqual(corridas[0], [97, 98, 99, 100, 101]);
    assert.deepEqual(corridas.at(-1), [1, 2, 3, 4, 5, 6, 7, 8]);
});

test('ubica una caja por corrida desde abajo y posición desde la izquierda', () => {
    const pallets = [pallet(8, 13), pallet(5, 10)];
    const n = numerar(pallets);
    assert.deepEqual(ubicarCaja(1, n, pallets), { pallet: 0, corrida: 1, posicion: 1 });
    assert.deepEqual(ubicarCaja(104, n, pallets), { pallet: 0, corrida: 13, posicion: 8 });
    assert.deepEqual(ubicarCaja(105, n, pallets), { pallet: 1, corrida: 1, posicion: 1 });
    assert.deepEqual(ubicarCaja(145, n, pallets), { pallet: 1, corrida: 9, posicion: 1 });
    assert.equal(ubicarCaja(155, n, pallets), null);
    assert.equal(ubicarCaja(0, n, pallets), null);
});

test('un pallet sin configurar no deja saltos en la numeración', () => {
    const n = numerar([pallet(8, 13), pallet(null, null), pallet(5, 10)]);
    assert.equal(n.valido, false);
    assert.deepEqual(n.rangos.map((r) => [r.inicio, r.fin]), [[1, 104], [null, null], [105, 154]]);
    assert.equal(verificarContinuidad(n), true);
});

test('valida base, corridas, última corrida y cajas declaradas', () => {
    assert.deepEqual(erroresPallet(pallet(null, 13)).map((e) => e.campo), ['base']);
    assert.deepEqual(erroresPallet(pallet(0, 13)).map((e) => e.campo), ['base']);
    assert.deepEqual(erroresPallet(pallet(8, 51)).map((e) => e.campo), ['corridas']);
    assert.deepEqual(erroresPallet(pallet('8.5', 13)).map((e) => e.campo), ['base']);
    assert.deepEqual(erroresPallet(pallet(8, 13, { ultima: 8 })).map((e) => e.campo), ['ultima']);
    assert.deepEqual(erroresPallet(pallet(8, 13, { declaradas: 104 })), []);
    assert.match(erroresPallet(pallet(8, 13, { declaradas: 100 }))[0].mensaje, /declaradas \(100\) no coinciden con la configuración \(104\)/);
    // Con «declaradas» distinta el pallet sigue numerado, pero no se puede emitir.
    const n = numerar([pallet(8, 13, { declaradas: 100 }), pallet(8, 1)]);
    assert.deepEqual([n.rangos[1].inicio, n.valido], [105, false]);
});

test('interpreta listas de cajas con comas, espacios, «y» y rangos', () => {
    assert.deepEqual(interpretarNumeros('5, 82, 145, 206 y 411'), { numeros: [5, 82, 145, 206, 411], no_reconocidos: [] });
    assert.deepEqual(interpretarNumeros('10-12; 7\n7 20 a 21').numeros, [10, 11, 12, 7, 20, 21]);
    assert.deepEqual(interpretarNumeros('12 - 10').numeros, [10, 11, 12]);
    assert.deepEqual(interpretarNumeros('3, abc, 4.5').no_reconocidos, ['abc', '4.5']);
});

test('no permite marcar cajas inexistentes y agrupa las marcas por pallet', () => {
    const pallets = [pallet(8, 13), pallet(5, 10)];
    const n = numerar(pallets);
    assert.deepEqual(clasificarNumeros([5, 82, 145, 206, 0], n), { validos: [5, 82, 145], inexistentes: [206, 0] });
    const { porPallet, fuera } = marcasPorPallet([145, 5, 82, 5, 400], n, pallets);
    assert.deepEqual(porPallet[0].map((m) => m.numero), [5, 82]);
    assert.deepEqual(porPallet[1], [{ numero: 145, corrida: 9, posicion: 1 }]);
    assert.deepEqual(fuera, [400]);
});

test('la firma cambia solo cuando cambia la estructura', () => {
    const a = [pallet(8, 13, { folio: 'A' })];
    assert.equal(firmaEstructura(a), firmaEstructura([pallet('8', '13', { folio: 'B' })]));
    assert.notEqual(firmaEstructura(a), firmaEstructura([pallet(8, 13, { ultima: 4 })]));
});

test('arma el título y la carga para la API', () => {
    assert.equal(tituloDiagrama({ tipo: 'usda', numero: ' 8 ' }), 'DIAGRAMA USDA N.º 8');
    assert.equal(tituloDiagrama({ tipo: 'sag', numero: '' }), 'DIAGRAMA SAG');
    assert.equal(etiquetaTemporada('Temporada 2026-2027'), 'Temporada 2026-2027');
    assert.equal(etiquetaTemporada('TEMP-2627'), 'Temporada TEMP-2627');
    const carga = cargaDiagrama({ tipo: 'usda', numero: '8', csp: ' 123 ', folio: '', fecha: '2026-11-20', lote: 'L1', especie: 'Cereza', exportadora: '' },
        [pallet('8', '13', { folio: ' P1 ', ultima: '' })], [82, 5, 82]);
    assert.deepEqual(carga, { tipo: 'usda', numero: '8', csp: '123', folio: null, fecha: '2026-11-20', lote: 'L1', especie: 'Cereza', exportadora: null,
        pallets: [{ folio: 'P1', base: 8, corridas: 13, ultima: null, declaradas: null }], cajas_marcadas: [5, 82] });
});

test('los pallets de una fila se apoyan en el mismo piso y la fila se corta por ancho', () => {
    const pallets = [pallet(8, 13), pallet(5, 10), pallet(8, 13)];
    const filas = distribuir(pallets, numerar(pallets), { ancho: 400, celda: 20, separacion: 10, rotulo: 40 });
    assert.deepEqual(filas.map((f) => f.pallets.map((p) => p.indice)), [[0, 1], [2]]);
    assert.equal(filas[0].alto, 13 * 20 + 40);
});

test('la lámina PNG marca en amarillo, no lleva nombre de planta y advierte que no es una aprobación', () => {
    const { svg, ancho, alto } = laminaSvg({ tipo: 'usda', numero: '8', csp: '3176555' }, [pallet(8, 13)], [5, 82], { temporada: 'Temporada 2026-2027' });
    assert.ok(ancho > 0 && alto > 0);
    assert.equal((svg.match(/fill="#ffd21f"/g) || []).length, 2);
    assert.match(svg, /DIAGRAMA USDA N\.º 8/);
    assert.match(svg, /CSP 3176555 · Temporada 2026-2027/);
    assert.match(svg, /No constituye aprobación del SAG ni del USDA/);
    assert.doesNotMatch(svg, /agro\s*rosario/i);
    assert.equal(laminaSvg({ tipo: 'usda' }, [pallet(8, 13)], [], { mostrarNumeros: false }).svg.includes('>104<'), false);
});
