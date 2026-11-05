/*
 * Chequeos de punto de rocío en Prefrío (tablet). Mismas fórmulas que la calculadora
 * de cerezas, la oficina y el servidor: aproximación de Magnus (a = 17,625; b = 243,04 °C).
 * La condensación sobre la fruta se evalúa con su T° superficial, no con la pulpa.
 */
const A = 17.625;
const B = 243.04;

export type DewPointStage =
  | 'packing'
  | 'sellado'
  | 'paletizado'
  | 'ingreso_prefrio'
  | 'salida_prefrio'
  | 'traslado'
  | 'almacenamiento'
  | 'despacho'
  | 'carga_contenedor'
  | 'otro';
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
  /** Riesgo principal (propio o vinculado) que calculó el servidor. */
  condicion?: string | null;
  envase?: DewPointLink | null;
  /** Rocío máximo del plan vigente al registrar y si se superó. */
  plan?: { rocio_maximo_c: number; humedad_maxima: number; fuera: boolean } | null;
  origen?: DewPointLink | null;
};

/** Objetivo de una etapa del plan de cadena de frío, calculado por el servidor. */
export type DewPointPlanStage = {
  etapa: DewPointStage;
  etiqueta: string;
  orden: number;
  planificada: boolean;
  temperatura_aire_c: number | null;
  temperatura_fruta_c: number | null;
  rocio_maximo_c: number | null;
  limitante: 'fruta' | 'envase' | null;
  humedad_maxima: number | null;
  cerca_congelacion: boolean;
};

/** Comparación vinculada guardada con la medición. */
export type DewPointLink = {
  referencia_id: string;
  punto: string | null;
  etapa_etiqueta?: string | null;
  medido_at: string | null;
  punto_rocio_c?: number | null;
  temperatura_superficie_c?: number | null;
  margen_c: number | null;
  estado: DewPointState | null;
  referencia_anulada: boolean;
};

/** Medición anterior que se puede vincular: un sellado o una con T° superficial. */
export type DewPointReference = {
  id: string;
  etapa: DewPointStage;
  etapa_etiqueta: string;
  punto: string;
  referencia: string | null;
  medido_at: string;
  temperatura_ambiente_c: number;
  humedad_relativa: number;
  temperatura_superficie_c: number | null;
  punto_rocio_c: number;
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
  referencias?: DewPointReference[];
  plan?: { etapas: DewPointPlanStage[]; temperatura_congelacion_c: number };
  parametros: {
    margen_minimo_c: number;
    margenes_por_etapa?: { etapa: DewPointStage; margen_minimo_c: number }[];
    horas_referencia?: number;
    dias_registro_retroactivo: number;
    zona_horaria: string;
  };
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
  sellado_referencia_id?: string | null;
  origen_referencia_id?: string | null;
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
  /** Sellado cuyo aire quedó en el envase. */
  sealed: string | null;
  /** Medición de donde viene la fruta. */
  origin: string | null;
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
  sealed: null,
  origin: null,
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

const STATE_RANK: Record<DewPointState, number> = { sin_superficie: 0, margen_favorable: 1, margen_bajo: 2, condensacion_posible: 3 };

/** Una T° superficial frente al punto de rocío de otro aire (envase o punto de llegada). */
export function compareSurface(surface: number | null | undefined, dew: number | null | undefined, minimumMargin = 2) {
  if (surface === null || surface === undefined || !Number.isFinite(surface) || dew === null || dew === undefined || !Number.isFinite(dew)) {
    return { margin: null, state: 'sin_superficie' as DewPointState };
  }
  const margin = round2(surface - dew);
  return { margin, state: dewPointState(margin, minimumMargin) };
}

export function worstState(states: DewPointState[]): DewPointState {
  return states.reduce<DewPointState>((worst, current) => (STATE_RANK[current] > STATE_RANK[worst] ? current : worst), 'sin_superficie');
}

/** Margen mínimo vigente de la etapa: el ajustado por supervisión o el general. */
export function marginForStage(day: DewPointDay | null, stage: DewPointStage | null) {
  const general = day?.parametros.margen_minimo_c ?? 2;
  return day?.parametros.margenes_por_etapa?.find((row) => row.etapa === stage)?.margen_minimo_c ?? general;
}

/** HR máxima del aire a una temperatura para que su rocío no supere el indicado (tope 100 %). */
export function humidityForDewPoint(temperature: number, dewPointMax: number) {
  return Math.min(100, (100 * saturation(dewPointMax)) / saturation(temperature));
}

/** Objetivo del plan para la etapa elegida, si está planificada. */
export function planForStage(day: DewPointDay | null, stage: DewPointStage | null) {
  const row = day?.plan?.etapas.find((item) => item.etapa === stage);
  return row && row.planificada && row.rocio_maximo_c !== null ? row : null;
}

/** HR en número entero sin redondear hacia arriba: es un máximo. */
export function formatHumidityLimit(value: number) {
  return `${Math.floor(Math.round(value * 10) / 10)} %`;
}

/** Mediciones vinculables a una medición tomada ahora, dentro de la ventana permitida. */
export function linkCandidates(day: DewPointDay | null, now: Date = new Date()) {
  const hours = day?.parametros.horas_referencia ?? 72;
  const items = (day?.referencias ?? []).filter((item) => {
    const at = new Date(item.medido_at).getTime();
    return at <= now.getTime() && at >= now.getTime() - hours * 3600000;
  });
  return {
    sealed: items.filter((item) => item.etapa === 'sellado'),
    origins: items.filter((item) => item.temperatura_superficie_c !== null),
  };
}

/** Vista previa del formulario mientras se escribe; null si aún faltan T° o HR válidas. */
export function previewDraft(
  draft: DewPointDraft,
  minimumMargin = 2,
  references: DewPointReference[] = [],
  plan: DewPointPlanStage | null = null,
) {
  const ambient = parseDecimal(draft.ambient);
  const humidity = parseDecimal(draft.humidity);
  const surface = parseDecimal(draft.surface);
  if (ambient === null || humidity === null || Number.isNaN(ambient) || !validHumidity(humidity)) return null;
  const surfaceValue = surface === null || Number.isNaN(surface) ? null : surface;
  const own = evaluateReading(ambient, humidity, surfaceValue, minimumMargin);
  if (!own) return null;
  const sealed = draft.stage !== 'sellado' && draft.sealed ? references.find((item) => item.id === draft.sealed) ?? null : null;
  const origin = draft.origin ? references.find((item) => item.id === draft.origin) ?? null : null;
  const envelope = sealed ? { reference: sealed, ...compareSurface(surfaceValue, sealed.punto_rocio_c, minimumMargin) } : null;
  const arrival = origin ? { reference: origin, ...compareSurface(origin.temperatura_superficie_c, own.dewPoint, minimumMargin) } : null;
  const planCheck = plan && plan.rocio_maximo_c !== null
    ? { limit: plan.rocio_maximo_c, humidityLimit: humidityForDewPoint(ambient, plan.rocio_maximo_c), out: own.dewPoint > plan.rocio_maximo_c }
    : null;
  return {
    ...own,
    envelope,
    arrival,
    plan: planCheck,
    worst: worstState([own.state, envelope?.state ?? 'sin_superficie', arrival?.state ?? 'sin_superficie']),
  };
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
      // Un sellado evalúa su propio aire: no lleva sellado de referencia.
      sellado_referencia_id: draft.stage === 'sellado' ? null : draft.sealed,
      origen_referencia_id: draft.origin,
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
