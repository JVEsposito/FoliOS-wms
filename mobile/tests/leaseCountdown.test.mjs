import test from 'node:test';
import assert from 'node:assert/strict';
import { isLeaseExpired, leaseSecondsRemaining, msUntilLeaseExpires } from '../src/domain/leaseCountdown.ts';

const vence = '2026-11-02T12:05:00.000Z';
const t = Date.parse(vence);

test('los segundos restantes truncan hacia abajo y nunca bajan de cero', () => {
  assert.equal(leaseSecondsRemaining(vence, t - 125_400), 125);
  assert.equal(leaseSecondsRemaining(vence, t - 1_000), 1);
  assert.equal(leaseSecondsRemaining(vence, t - 999), 0);
  assert.equal(leaseSecondsRemaining(vence, t + 60_000), 0);
});

test('sin vencimiento o con fecha inválida no hay cuenta regresiva ni vencimiento', () => {
  for (const valor of [null, undefined, '', 'no-es-fecha']) {
    assert.equal(leaseSecondsRemaining(valor, t), null);
    assert.equal(msUntilLeaseExpires(valor, t), null);
    assert.equal(isLeaseExpired(valor, t), false);
  }
});

test('el aviso único cae justo cuando la cuenta llega a cero, como el tic de cada segundo', () => {
  const ahora = t - 125_400;
  const espera = msUntilLeaseExpires(vence, ahora);
  assert.equal(isLeaseExpired(vence, ahora + espera - 1), false);
  assert.equal(isLeaseExpired(vence, ahora + espera), true);
  assert.equal(msUntilLeaseExpires(vence, t), 0);
  assert.equal(msUntilLeaseExpires(vence, t + 5_000), 0);
});
