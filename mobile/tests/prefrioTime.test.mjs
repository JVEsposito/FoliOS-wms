import assert from 'node:assert/strict';
import test from 'node:test';
import { operationalDateTime, parseOperationalDateTime } from '../src/domain/prefrio.ts';

test('Prefrío interpreta la hora de planta con horario de invierno y verano', () => {
  assert.equal(operationalDateTime(new Date('2026-11-13T15:47:25Z')), '13-11-2026 12:47:25');
  assert.equal(parseOperationalDateTime('13-11-2026 12:47:25'), '2026-11-13T15:47:25.000Z');
  assert.equal(parseOperationalDateTime('13-07-2026 12:47'), '2026-07-13T16:47:00.000Z');
  assert.equal(parseOperationalDateTime('31-11-2026 12:47'), null);
});
