import type { PrefrioEvent, PrefrioProcess } from './prefrio';

/**
 * POEMP R-41 «Registro de prefrío»: cada lectura mide 3 sectores del túnel (ventilador,
 * medio y puerta), exterior e interior de cada lado. La lectura horaria puede quedar
 * parcial; la que cierra el enfriado lleva los 12 sensores.
 */
export const R41_SECTORS = [
  { key: 'ventilador', label: 'Ventilador' },
  { key: 'medio', label: 'Medio' },
  { key: 'puerta', label: 'Puerta' },
] as const;

export const R41_POINTS = [
  { key: 'izq_ex', label: 'Izq. ext.' },
  { key: 'izq_in', label: 'Izq. int.' },
  { key: 'der_in', label: 'Der. int.' },
  { key: 'der_ex', label: 'Der. ext.' },
] as const;

export const R41_SENSOR_COUNT = R41_SECTORS.length * R41_POINTS.length;
export const R41_MIN_TEMPERATURE = -10;
export const R41_MAX_TEMPERATURE = 40;
export const R41_DEFAULT_INTERVAL_MINUTES = 60;

export type R41SectorKey = (typeof R41_SECTORS)[number]['key'];
export type R41PointKey = (typeof R41_POINTS)[number]['key'];
export type R41Sectors = Record<R41SectorKey, Record<R41PointKey, number | null>>;
export type R41Draft = Record<R41SectorKey, Record<R41PointKey, string>>;

export type R41ParsedDraft =
  | { ok: true; sectores: R41Sectors; filled: number; missing: number }
  | { ok: false; message: string };

export type R41ServerStatus = {
  intervalo_lectura_minutos?: number;
  exige_lectura_final?: boolean;
};

export type R41ReadingStatus = {
  intervalMinutes: number;
  requiresFinalReading: boolean;
  readings: number;
  lastReadingAt: string | null;
  lastReadingComplete: boolean;
  nextReadingAt: string | null;
  overdue: boolean;
  minutesLate: number;
};

export function emptyR41Draft(): R41Draft {
  return Object.fromEntries(R41_SECTORS.map((sector) => [
    sector.key,
    Object.fromEntries(R41_POINTS.map((point) => [point.key, ''])),
  ])) as R41Draft;
}

export function updateR41Draft(draft: R41Draft, sector: R41SectorKey, point: R41PointKey, value: string): R41Draft {
  return { ...draft, [sector]: { ...draft[sector], [point]: value } };
}

/** Convierte lo digitado en la tablet; acepta coma decimal y deja vacíos como null. */
export function parseR41Draft(draft: R41Draft): R41ParsedDraft {
  const sectores = {} as R41Sectors;
  let filled = 0;
  for (const sector of R41_SECTORS) {
    sectores[sector.key] = {} as Record<R41PointKey, number | null>;
    for (const point of R41_POINTS) {
      const raw = (draft[sector.key]?.[point.key] ?? '').trim().replace(',', '.');
      if (raw === '') {
        sectores[sector.key][point.key] = null;
        continue;
      }
      const value = Number(raw);
      if (!/^-?\d+(\.\d+)?$/.test(raw) || !Number.isFinite(value)
        || value < R41_MIN_TEMPERATURE || value > R41_MAX_TEMPERATURE) {
        return {
          ok: false,
          message: `${sector.label} ${point.label.toLowerCase()}: la temperatura debe estar entre ${R41_MIN_TEMPERATURE} y ${R41_MAX_TEMPERATURE} °C.`,
        };
      }
      sectores[sector.key][point.key] = Math.round(value * 10) / 10;
      filled += 1;
    }
  }
  return { ok: true, sectores, filled, missing: R41_SENSOR_COUNT - filled };
}

export function isCompleteR41Reading(sectores: unknown): boolean {
  if (!sectores || typeof sectores !== 'object') return false;
  const record = sectores as Record<string, Record<string, unknown> | undefined>;
  return R41_SECTORS.every((sector) => R41_POINTS.every((point) => {
    const value = record[sector.key]?.[point.key];
    return typeof value === 'number' && Number.isFinite(value);
  }));
}

/** Sectores de un evento de lectura, o la lectura final guardada al enviar a verificación. */
export function r41SectorsOf(event: PrefrioEvent): unknown {
  const datos = event.datos ?? {};
  if (event.tipo === 'lectura') return (datos as Record<string, unknown>).sectores ?? null;
  const nested = (datos as Record<string, unknown>).datos;
  if (nested && typeof nested === 'object') return (nested as Record<string, unknown>).lectura_final ?? null;
  return null;
}

/**
 * Estado del control horario: la próxima lectura vence una hora después de la última (o del
 * inicio del enfriado). Se calcula con los eventos locales para que funcione sin conexión.
 */
export function r41ReadingStatus(
  process: Pick<PrefrioProcess, 'estado' | 'iniciado_at' | 'eventos'> & { registro_r41?: R41ServerStatus | null },
  now: number = Date.now(),
): R41ReadingStatus {
  const intervalMinutes = Number(process.registro_r41?.intervalo_lectura_minutos) > 0
    ? Number(process.registro_r41?.intervalo_lectura_minutos)
    : R41_DEFAULT_INTERVAL_MINUTES;
  const requiresFinalReading = process.registro_r41?.exige_lectura_final ?? true;
  const readings = process.eventos
    .filter((event) => event.tipo === 'lectura')
    .sort((a, b) => Date.parse(a.ocurrido_at) - Date.parse(b.ocurrido_at));
  const last = readings.at(-1) ?? null;
  const reference = last?.ocurrido_at ?? process.iniciado_at;
  const nextReadingAt = process.estado === 'en_proceso' && reference && Number.isFinite(Date.parse(reference))
    ? new Date(Date.parse(reference) + intervalMinutes * 60_000).toISOString()
    : null;
  const late = nextReadingAt ? Math.floor((now - Date.parse(nextReadingAt)) / 60_000) : -1;
  return {
    intervalMinutes,
    requiresFinalReading,
    readings: readings.length,
    lastReadingAt: last?.ocurrido_at ?? null,
    lastReadingComplete: last ? isCompleteR41Reading(r41SectorsOf(last)) : false,
    nextReadingAt,
    overdue: late >= 0,
    minutesLate: Math.max(0, late),
  };
}

/** La lectura final es obligatoria salvo que la última lectura horaria ya esté completa. */
export function r41FinalReadingRequired(status: R41ReadingStatus): boolean {
  return status.requiresFinalReading && !status.lastReadingComplete;
}

export function r41MissingMessage(missing: number): string {
  return `La lectura final lleva los ${R41_SENSOR_COUNT} sensores: ${missing === 1 ? 'falta 1' : `faltan ${missing}`}.`;
}

/** Resumen corto para la línea de tiempo: sensores medidos y rango de temperaturas. */
export function r41ReadingSummary(sectores: unknown): string | null {
  if (!sectores || typeof sectores !== 'object') return null;
  const record = sectores as Record<string, Record<string, unknown> | undefined>;
  const values = R41_SECTORS.flatMap((sector) => R41_POINTS.map((point) => record[sector.key]?.[point.key]))
    .filter((value): value is number => typeof value === 'number' && Number.isFinite(value));
  if (!values.length) return null;
  const format = (value: number) => value.toFixed(1).replace('.', ',');
  const min = Math.min(...values);
  const max = Math.max(...values);
  const range = min === max ? `${format(min)} °C` : `${format(min)} a ${format(max)} °C`;
  return `${values.length} de ${R41_SENSOR_COUNT} sensores · ${range}`;
}
