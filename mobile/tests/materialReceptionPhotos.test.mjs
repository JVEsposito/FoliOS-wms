import assert from 'node:assert/strict';
import { test } from 'node:test';
import { puedeConfirmarPorFotos } from '../src/domain/materialReception.ts';

test('solo una foto de documento subida habilita la confirmación', () => {
  assert.equal(puedeConfirmarPorFotos([]), false);
  assert.equal(puedeConfirmarPorFotos([{ id: 'r', tipo: 'referencial' }]), false);
  assert.equal(puedeConfirmarPorFotos([{ id: '', tipo: 'documento' }]), false);
  assert.equal(puedeConfirmarPorFotos([{ id: 'd', tipo: 'documento' }]), true);
});
