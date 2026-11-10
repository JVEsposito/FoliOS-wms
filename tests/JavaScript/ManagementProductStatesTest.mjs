import test from 'node:test';
import assert from 'node:assert/strict';
import { bulkWidths, productStateRows } from '../../resources/js/shared/management-product-states.js';

test('sin folios activos quedan los seis estados en cero', () => {
    const result = productStateRows({ total_activos: 0 });
    assert.equal(result.empty, true);
    assert.equal(result.rows.length, 6);
    assert.ok(result.rows.every(({ total, palletWidth, saldoWidth }) => total === 0 && palletWidth === 0 && saldoWidth === 0));
});

test('cada estado separa pallets y saldos y suma sus cajas', () => {
    const result = productStateRows({
        total_activos: 151,
        estados: [
            { clave: 'disponibles_despacho', pallets: 10, saldos: 3, cajas: 900 },
            { clave: 'pendientes_ubicacion', pallets: 8, saldos: 72, cajas: 2100 },
        ],
    });
    const unlocated = result.rows.find((row) => row.key === 'pendientes_ubicacion');
    assert.deepEqual([unlocated.pallets, unlocated.saldos, unlocated.total, unlocated.cajas], [8, 72, 80, 2100]);
    assert.equal(unlocated.palletWidth, 10);
    assert.equal(unlocated.saldoWidth, 90);
    assert.equal(unlocated.percent, 53);
    assert.deepEqual(result.totals, { pallets: 18, saldos: 75, cajas: 3000 });
});

test('un valor pequeño conserva un ancho visible y el cero no ocupa espacio', () => {
    const result = productStateRows({ total_activos: 1001, estados: [
        { clave: 'disponibles_despacho', pallets: 1000, saldos: 0, cajas: 0 },
        { clave: 'bloqueados', pallets: 1, saldos: 0, cajas: 0 },
    ] });
    const blocked = result.rows.find((row) => row.key === 'bloqueados');
    assert.equal(blocked.palletWidth, 1.5);
    assert.equal(blocked.saldoWidth, 0);
});

test('una respuesta sin desglose muestra el conteo como pallets sin inventar saldos', () => {
    const result = productStateRows({ total_activos: 2, bloqueados: 2 });
    const blocked = result.rows.find((row) => row.key === 'bloqueados');
    assert.deepEqual([blocked.pallets, blocked.saldos], [2, 0]);
});

test('las barras de tramo escalan pallets y saldos contra el mayor', () => {
    assert.deepEqual(bulkWidths(5, 15, 40), { pallet: 12.5, saldo: 37.5 });
    assert.deepEqual(bulkWidths(0, 0, 0), { pallet: 0, saldo: 0 });
});
