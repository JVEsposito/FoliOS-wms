import test from 'node:test';
import assert from 'node:assert/strict';
import { emptyContainerInspection, inspectionPayload } from '../src/domain/containerInspection.ts';
test('inspección MP exige respuesta explícita; No es respuesta válida y cantidad no viaja', () => {
  const quantities = [{ tipo_envase: 'bins', cantidad_validada: 2 }, { tipo_envase: 'totes', cantidad_validada: 0 }];
  const draft = emptyContainerInspection(['bins', 'totes']);
  assert.throws(() => inspectionPayload(draft, quantities), /limpieza/);
  draft.items[0] = { tipo_envase: 'bins', limpieza: false, condicion: 'mala', nota: ' rota ' };
  assert.throws(() => inspectionPayload(draft, quantities), /tres controles/);
  draft.coincide_especie_variedad = false; draft.coincide_cantidad_bins = true; draft.bins_bien_etiquetados = false;
  const payload = inspectionPayload(draft, quantities);
  assert.deepEqual(payload.items, [{ tipo_envase: 'bins', limpieza: false, condicion: 'mala', nota: 'rota' }]);
  assert.equal(payload.coincide_especie_variedad, false); assert.equal('cantidad' in payload.items[0], false);
});
