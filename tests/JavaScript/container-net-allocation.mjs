import assert from 'node:assert/strict';
import test from 'node:test';
import { calculateNetAllocation, loadContainerCatalog } from '../../resources/js/office-container-catalog.js';

test('la vista previa usa el catálogo y la misma proporción que el servidor', async () => {
    await loadContainerCatalog(async () => ({ data: [{ codigo: 'bins', nombre: 'Bins plástico', contiene_fruta: true }, { codigo: 'caja_3_4', nombre: 'Caja 3/4', contiene_fruta: true }, { codigo: 'esponjas', nombre: 'Esponja de bins', contiene_fruta: false }] }));
    assert.equal(calculateNetAllocation(9000, { bins: 2 }, ['bins'])[0].neto_unitario, 4500);
    const rows = calculateNetAllocation(9000, { bins: 2, caja_3_4: 6 }, ['bins', 'caja_3_4'], { bins: 210, caja_3_4: 10 });
    assert.equal(rows.reduce((sum, row) => sum + row.cantidad * row.neto_unitario, 0), 9000);
    assert.throws(() => calculateNetAllocation(9000, { esponjas: 2 }, ['esponjas']));
    assert.throws(() => calculateNetAllocation(9000, { bins: 2, caja_3_4: 6 }, ['bins', 'caja_3_4'], { bins: 210 }), /Caja 3\/4/);
});
