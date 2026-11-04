/*
 * Chequeos de punto de rocío en Prefrío (tablet). Mismas fórmulas que la calculadora
 * de cerezas, la oficina y el servidor: aproximación de Magnus (a = 17,625; b = 243,04 °C).
 * La condensación sobre la fruta se evalúa con su T° superficial, no con la pulpa.
 */
const A = 17.625;
const B = 243.04;

export type DewPointStage = 'sellado' | 'paletizado' | 'ingreso_prefrio' | 'salida_prefrio' | 'almacenamiento' | 'otro';
export type DewPointState = 'sin_superficie' | 'condensacion_posible' | 'margen_bajo' | 'margen_favorable';
export type DewPointDrops = 'si' | 'no' | 'pendiente';
export type DewPointPointType = 'sala' | 'tunel' | 'camara';

export type DewPointReading = {
  id: string;
  etapa: DewPointStage;
  etapa_etiqueta: string;
  punto_tipo: DewPointPointType;
  punto_id: string;
  punto: string;
  referencia: string | null;
  temperatura_ambiente_c: number;
  humedad_relativa: number;
  temperatura_superficie_c: number | null;
  punto_rocio_c: number;
  margen_c: number | null;
  vapor_g_m3: number;
  estado: DewPointState;
  estado_etiqueta: string;
  gotas_visibles: DewPointDrops;
  requiere_accion: boolean;
  alerta_pendiente: boolean;
  observacion: string | null;
  medido_at: string;
  fecha: string;
  registrado_por: string | null;
  dispositivo: string | null;
  accion_correctiva: string | null;
  anulada: boolean;
};

export type DewPointSummary = {
  registros: number;
  con_superficie: number;
  punto_rocio_maximo_c: number | null;
  margen_minimo_c: number | null;
  alertas: number;
  alertas_pendientes: number;
  gotas_visibles: number;
};

export type DewPointPlace = { id: string; nombre: string; activa?: boolean };

export type DewPointDay = {
  fecha: string;
  resumen: DewPointSummary;
  mediciones: DewPointReading[];
  puntos: { salas: DewPointPlace[]; tuneles: DewPointPlace[]; camaras: DewPointPlace[] };
  etapas: { valor: DewPointStage; etiqueta: string }[];
  parametros: { margen_minimo_c: number; dias_registro_retroactivo: number; zona_horaria: string };
  permisos: { registrar: boolean; supervisar: boolean };
};

export type DewPointPayload = {
  operacion_id: string;
  etapa: DewPointStage;
  punto_tipo: DewPointPointType;
  punto_id: string;
  referencia: string | null;
  temperatura_ambiente_c: number;
  humedad_relativa: number;
  temperatura_superficie_c: number | null;
  gotas_visibles: DewPointDrops;
  observacion: string | null;
  medido_at: string;
};

export type DewPointDraft = {
  stage: DewPointStage | null;
  /** `tipo:id`, por ejemplo `sala:…` o `tunel:…`. */
  point: string | null;
  reference: string;
  ambient: string;
  humidity: string;
  surface: string;
  drops: DewPointDrops;
  note: string;
};

export const EMPTY_DEW_POINT_DRAFT: DewPointDraft = {
  stage: null,
  point: null,
  reference: '',
  ambient: '',
  humidity: '',
  surface: '',
  drops: 'pendiente',
  note: '',
};

export const DEW_POINT_STATES: Record<DewPointState, { label: string; tone: 'neutral' | 'danger' | 'warning' | 'success'; help: string }> = {
  sin_superficie: { label: 'Medir superficie', tone: 'neutral', help: 'Se calcula el rocío, pero no se evalúa el riesgo sobre la fruta.' },
  condensacion_posible: { label: 'Condensación posible', tone: 'danger', help: 'La superficie está bajo el punto de rocío: puede condensar agua.' },
  margen_bajo: { label: 'Margen bajo', tone: 'warning', help: 'Margen reducido: vigila cambios de temperatura y de humedad.' },
  margen_favorable: { label: 'Margen favorable', tone: 'success', help: 'Margen preventivo favorable (referencia interna, no norma).' },
};

function saturation(temperature: number) {
  return 6.1094 * Math.exp((A * temperature) / (B + temperature));
}

function validHumidity(humidity: number) {
  return Number.isFinite(humidity) && humidity > 0 && humidity <= 100;
}

export function dewPoint(temperature: number, humidity: number): number | null {
  if (!Number.isFinite(temperature) || !validHumidity(humidity)) return null;
  const gamma = Math.log(humidity / 100) + (A * temperature) / (B + temperature);
  return (B * gamma) / (A - gamma);
}

export function waterVapor(temperature: number, humidity: number): number | null {
  if (!Number.isFinite(temperature) || !validHumidity(humidity)) return null;
  return (216.7 * ((humidity / 100) * saturation(temperature))) / (273.15 + temperature);
}

export function dewPointState(margin: number | null, minimumMargin = 2): DewPointState {
  if (margin === null || !Number.isFinite(margin)) return 'sin_superficie';
  if (margin < 0) return 'condensacion_posible';
  if (margin < minimumMargin) return 'margen_bajo';
  return 'margen_favorable';
}

/** Número escrito en la tablet: acepta coma decimal. Vacío → null; texto inválido → NaN. */
export function parseDecimal(value: string): number | null {
  const text = value.trim().replace(',', '.');
  if (text === '') return null;
  return /^-?\d+(\.\d+)?$/.test(text) ? Number(text) : Number.NaN;
}

const round2 = (value: number) => Math.round(value * 100) / 100;

/** Evaluación de una lectura tal como la guarda el servidor (redondeo a 2 decimales). */
export function evaluateReading(ambient: number, humidity: number, surface: number | null, minimumMargin = 2) {
  const dew = dewPoint(ambient, humidity);
  if (dew === null) return null;
  const roundedDew = round2(dew);
  const margin = surface === null || !Number.isFinite(surface) ? null : round2(surface - roundedDew);
  return {
    dewPoint: roundedDew,
    margin,
    vapor: round2(waterVapor(ambient, humidity) ?? 0),
    state: dewPointState(margin, minimumMargin),
  };
}

/** Vista previa del formulario mientras se escribe; null si aún faltan T° o HR válidas. */
export function previewDraft(draft: DewPointDraft, minimumMargin = 2) {
  const ambient = parseDecimal(draft.ambient);
  const humidity = parseDecimal(draft.humidity);
  const surface = parseDecimal(draft.surface);
  if (ambient === null || humidity === null || Number.isNaN(ambient) || !validHumidity(humidity)) return null;
  return evaluateReading(ambient, humidity, surface === null || Number.isNaN(surface) ? null : surface, minimumMargin);
}

/** Valida el formulario y arma los datos de la medición (sin operacion_id ni hora). */
export function draftToReading(draft: DewPointDraft):
  | { ok: true; reading: Omit<DewPointPayload, 'operacion_id' | 'medido_at'> }
  | { ok: false; error: string } {
  if (!draft.stage) return { ok: false, error: 'Selecciona la etapa.' };
  const [type, id] = (draft.point ?? '').split(':');
  if (!['sala', 'tunel', 'camara'].includes(type) || !id) return { ok: false, error: 'Selecciona dónde se tomó la medición.' };
  const ambient = parseDecimal(draft.ambient);
  if (ambient === null || Number.isNaN(ambient) || ambient < -30 || ambient > 60) {
    return { ok: false, error: 'Ingresa la temperatura del ambiente entre -30 y 60 °C.' };
  }
  const humidity = parseDecimal(draft.humidity);
  if (humidity === null || !validHumidity(humidity)) return { ok: false, error: 'Ingresa la humedad relativa entre 0 y 100 %.' };
  const surface = parseDecimal(draft.surface);
  if (surface !== null && (Number.isNaN(surface) || surface < -30 || surface > 60)) {
    return { ok: false, error: 'La T° superficial debe estar entre -30 y 60 °C, o quedar vacía.' };
  }
  return {
    ok: true,
    reading: {
      etapa: draft.stage,
      punto_tipo: type as DewPointPointType,
      punto_id: id,
      referencia: draft.reference.trim() || null,
      temperatura_ambiente_c: ambient,
      humedad_relativa: humidity,
      temperatura_superficie_c: surface,
      gotas_visibles: draft.drops,
      observacion: draft.note.trim() || null,
    },
  };
}

/**
 * Un reintento de la misma medición (por ejemplo, tras perder la red) reutiliza su
 * operacion_id y su hora para que el servidor no la duplique; si cambió algún dato,
 * es una medición nueva.
 */
export function attemptFor(
  previous: { key: string; payload: DewPointPayload } | null,
  reading: Omit<DewPointPayload, 'operacion_id' | 'medido_at'>,
  createId: () => string,
  now: () => Date = () => new Date(),
) {
  const key = JSON.stringify(reading);
  if (previous && previous.key === key) return previous;
  return { key, payload: { ...reading, operacion_id: createId(), medido_at: now().toISOString() } };
}

export function formatCelsius(value: number | null | undefined) {
  if (value === null || value === undefined || !Number.isFinite(value)) return '—';
  return `${new Intl.NumberFormat('es-CL', { minimumFractionDigits: 1, maximumFractionDigits: 1 }).format(value)} °C`;
}
