import type { ValidationShift } from './validation';

export const validationShifts = {
  A: { name: 'Día', hours: '07:00–19:00' },
  B: { name: 'Noche', hours: '19:00–07:00' },
} as const;

export function validationJourneyDate(
  instant: Date | string,
  shift: ValidationShift | null,
  timeZone = 'America/Santiago',
): string | null {
  const date = new Date(instant);
  if (!Number.isFinite(date.getTime())) return null;
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', hourCycle: 'h23',
  }).formatToParts(date);
  const { year, month, day, hour } = Object.fromEntries(parts.map(({ type, value }) => [type, value]));
  const localDate = `${year}-${month}-${day}`;
  if (shift !== 'B' || Number(hour) >= 7) return localDate;

  // Restar un día calendario, incluso cuando en Santiago cambió el horario de verano.
  const previousDay = new Date(`${localDate}T00:00:00Z`);
  previousDay.setUTCDate(previousDay.getUTCDate() - 1);
  return previousDay.toISOString().slice(0, 10);
}

export function formatValidationJourneyDate(date: string | null): string {
  return date?.split('-').reverse().join('/') ?? '—';
}
