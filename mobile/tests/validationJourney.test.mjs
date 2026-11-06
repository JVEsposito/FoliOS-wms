import assert from 'node:assert/strict';
import test from 'node:test';
import { formatValidationJourneyDate, validationJourneyDate } from '../src/domain/validationJourney.ts';

test('el turno noche conserva el día de inicio hasta las 07:00 de Santiago', () => {
  assert.equal(validationJourneyDate('2026-11-06T22:00:00Z', 'B'), '2026-11-06');
  assert.equal(validationJourneyDate('2026-11-07T03:00:00Z', 'B'), '2026-11-06');
  assert.equal(validationJourneyDate('2026-11-07T09:59:59Z', 'B'), '2026-11-06');
  assert.equal(validationJourneyDate('2026-11-07T10:00:00Z', 'B'), '2026-11-07');
  assert.equal(validationJourneyDate('2026-11-07T05:15:00Z', 'A'), '2026-11-07');
});

test('la fecha se calcula en la zona de planta y conserva el día al cruzar un año o cambio de hora', () => {
  assert.equal(validationJourneyDate('2027-01-01T03:15:00Z', 'B'), '2026-12-31');
  assert.equal(validationJourneyDate('2026-09-06T04:15:00Z', 'B'), '2026-09-05');
  assert.equal(validationJourneyDate('2026-11-07T09:00:00Z', 'B', 'UTC'), '2026-11-07');
  assert.equal(formatValidationJourneyDate('2026-11-06'), '06/11/2026');
});
