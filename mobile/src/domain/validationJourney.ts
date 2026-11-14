import type { ValidationShift } from './validation';

export const validationShifts = {
  A: { name: 'Día', hours: '07:00–19:00' },
  B: { name: 'Noche', hours: '19:00–07:00' },
} as const;

export type OperationalCalendarRule = { vigente_desde: string; vigente_desde_at?: string; inicio_jornada: string; inicio_turno_b: string };

export function operationalValidationJourney(instant: Date | string, timeZone: string | undefined, rules: OperationalCalendarRule[] = []) {
  const date = new Date(instant);
  if (!Number.isFinite(date.getTime()) || rules.length === 0) return null;
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone: timeZone || 'America/Santiago', year: 'numeric', month: '2-digit', day: '2-digit',
    hour: '2-digit', minute: '2-digit', hourCycle: 'h23',
  }).formatToParts(date);
  const { year, month, day, hour, minute } = Object.fromEntries(parts.map(part => [part.type, part.value]));
  const realDate = `${year}-${month}-${day}`;
  const time = `${hour}:${minute}`;
  const todayRule = rules.find(rule => rule.vigente_desde <= realDate);
  const jornada = todayRule && time < todayRule.inicio_jornada ? previousDate(realDate) : realDate;
  const rule = rules.find(candidate => candidate.vigente_desde <= jornada);
  if (!rule || (rule.vigente_desde_at && date.getTime() < Date.parse(rule.vigente_desde_at))) return null;
  const startBIsNextDay = rule.inicio_turno_b < rule.inicio_jornada;
  const isB = startBIsNextDay ? realDate !== jornada && time >= rule.inicio_turno_b : realDate !== jornada || time >= rule.inicio_turno_b;
  return { fecha: jornada, turno: (isB ? 'B' : 'A') as ValidationShift, rule };
}

function previousDate(date: string) {
  const previousDay = new Date(`${date}T00:00:00Z`);
  previousDay.setUTCDate(previousDay.getUTCDate() - 1);
  return previousDay.toISOString().slice(0, 10);
}

export function validationJourneyDate(
  instant: Date | string,
  shift: ValidationShift | null,
  timeZone = 'America/Santiago',
  rules?: OperationalCalendarRule[],
): string | null {
  const operational = operationalValidationJourney(instant, timeZone, rules);
  if (operational) return operational.fecha;
  const date = new Date(instant);
  if (!Number.isFinite(date.getTime())) return null;
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', hourCycle: 'h23',
  }).formatToParts(date);
  const { year, month, day, hour } = Object.fromEntries(parts.map(({ type, value }) => [type, value]));
  const localDate = `${year}-${month}-${day}`;
  if (shift !== 'B' || Number(hour) >= 7) return localDate;

  // Restar un día calendario, incluso cuando en Santiago cambió el horario de verano.
  return previousDate(localDate);
}

export function formatValidationJourneyDate(date: string | null): string {
  return date?.split('-').reverse().join('/') ?? '—';
}
