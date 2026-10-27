import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import ts from 'typescript';

const module = { exports: {} };
const source = readFileSync(new URL('../src/domain/physicalFrontierRecovery.ts', import.meta.url), 'utf8');
vm.runInNewContext(ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS } }).outputText,
  { module, exports: module.exports, setTimeout });
const { recoverPhysicalFrontier, resourceFrontier } = module.exports;

test('un destino que cambió se recalcula y se confirma dentro de la misma acción', async () => {
  let attempts = 0; let cleared = 0; const delays = [];
  const result = await recoverPhysicalFrontier(async () => {
    attempts++;
    if (attempts < 3) throw Object.assign(new Error('Posición reservada por otra tablet'), { status: 409 });
    return 'destino confirmado';
  }, () => cleared++, () => true, async (ms) => { delays.push(ms); });
  assert.equal(result, 'destino confirmado');
  assert.equal(attempts, 3); assert.equal(cleared, 2); assert.deepEqual(delays, [250, 500]);
});

test('conflicto persistente y errores de permisos se conservan sin bucles de reintentos', async () => {
  for (const status of [409, 403, 422]) {
    let attempts = 0;
    await assert.rejects(recoverPhysicalFrontier(async () => {
      attempts++; throw Object.assign(new Error('Conflicto real'), { status });
    }, () => {}, () => true, async () => {}), /Conflicto real/);
    assert.equal(attempts, status === 409 ? 3 : 1);
  }
});

test('cerrar o cambiar sesión detiene los reintentos del usuario anterior', async () => {
  let active = true; let attempts = 0;
  await assert.rejects(recoverPhysicalFrontier(async () => {
    attempts++; throw Object.assign(new Error('Cambio'), { status: 409 });
  }, () => {}, () => active, async () => { active = false; }), /sesión cambió/);
  assert.equal(attempts, 1);
});

test('solo los servidores que anuncian el contrato permiten revalidar claims durante un recálculo', () => {
  const old = { tareas: [{ id: 'a', materializable: false, materializable_por_recursos: true }] };
  assert.equal(resourceFrontier(old), old);
  const fresh = resourceFrontier({ ...old, contrato_recursos: 'recursos_v1' });
  assert.equal(fresh.tareas[0].materializable, true);
  assert.equal(old.tareas[0].materializable, false);
});
