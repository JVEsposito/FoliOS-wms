export type HydroTray = 'pendientes' | 'en_curso' | 'retenidos' | 'historial';
export type HydroDestination = 'camara' | 'proceso';
export type HydroWaterCondition = 'conforme' | 'no_conforme';
/** oficial: registro completo POEMP-R3; patio: tiempos, temperaturas y estado del agua. */
export type HydroModality = 'oficial' | 'patio';

export type HydroFilters = {
  buscar: string;
  equipo: string;
  turno: '' | 'A' | 'B';
  destino: '' | HydroDestination;
  desde: string;
  hasta: string;
};

export type HydroSummary = {
  temporada: { id: string; codigo: string; nombre: string } | null;
  pendientes: number;
  en_curso: number;
  retenidos: number;
  completados_hoy: number;
  kilos_en_curso: number;
  duracion_promedio_hoy: number;
  equipos: string[];
};

export type HydroCycle = {
  codigo: string;
  modalidad?: HydroModality;
  equipo: string;
  turno: 'A' | 'B' | null;
  cantidad_bombas_funcionando: number | null;
  operador: string;
  cantidad_envases: number;
  kilos_netos: number;
  inicio_at: string;
  termino_at: string | null;
  duracion_minutos: number | null;
  temperatura_inicial_c: number;
  temperatura_objetivo_c: number | null;
  temperatura_agua_inicial_c: number | null;
  temperatura_ambiente_c: number | null;
  humedad_relativa_pct: number | null;
  pozo_accutab_mv: number | null;
  recarga_pastilla: boolean | null;
  correccion_cloro_ppm: number | null;
  aplicacion_producto: boolean | null;
  producto_hidrocooler_id: string | null;
  producto_dosis: number | null;
  producto_unidad_dosis: string | null;
  producto_nombre_snapshot: string | null;
  formato_registro_snapshot: { codigo: string; version: string; fecha_vigencia: string; localidad: string } | null;
  cloro_libre_ppm: number | null;
  ph_agua: number | null;
  control_inicial_conforme: boolean | null;
  condicion_visual_agua: HydroWaterCondition;
  dosificador_operativo: boolean | null;
  manejo_agua: 'sin_novedad' | 'filtrado' | 'recambio' | null;
  temperatura_c: number | null;
  temperatura_agua_final_c: number | null;
  cloro_libre_final_ppm: number | null;
  ph_agua_final: number | null;
  condicion_visual_agua_final: HydroWaterCondition | null;
  dosificador_operativo_final: boolean | null;
  control_final_conforme: boolean | null;
  motivo_retencion: string | null;
  temperatura_verificacion_c: number | null;
  cloro_libre_verificacion_ppm: number | null;
  ph_agua_verificacion: number | null;
  evaluacion_producto: string | null;
  verificacion_liberacion: string | null;
  liberado_at: string | null;
  liberado_por: string | null;
  destino_salida: HydroDestination | null;
  observacion_inicio: string | null;
  observacion: string | null;
  accion_correctiva: string | null;
};

export type HydroLot = {
  id: string;
  numero_lote: string;
  estado: string;
  cliente: { nombre: string } | null;
  recepcion: { numero_recepcion: string } | null;
  trazabilidad: { csg: string | null; especie: string | null; variedad: string | null; cuartel: string | null };
  envases: { primario: string; cantidad_primarios: number };
  pesos: { kilos_netos_confirmados: number };
  hidrocooler: HydroCycle | null;
  confirmado_at: string | null;
};

export type StartHydroOfficial = {
  operacion_id: string;
  modalidad: 'oficial';
  equipo: string;
  turno: 'A' | 'B';
  cantidad_bombas_funcionando: number;
  inicio_at: string;
  temperatura_inicial_c: number;
  temperatura_objetivo_c: number;
  temperatura_agua_inicial_c: number | null;
  temperatura_ambiente_c: number;
  humedad_relativa_pct: number;
  pozo_accutab_mv: number;
  recarga_pastilla: boolean;
  correccion_cloro_ppm: number | null;
  aplicacion_producto: boolean;
  producto_hidrocooler_id: string | null;
  producto_dosis: number | null;
  producto_unidad_dosis: string | null;
  cloro_libre_ppm: number;
  ph_agua: number;
  control_inicial_conforme: boolean;
  condicion_visual_agua: HydroWaterCondition;
  dosificador_operativo: boolean;
  manejo_agua: 'sin_novedad' | 'filtrado' | 'recambio';
  observacion_inicio: string | null;
};

/** Registro de patio: solo tiempos, temperaturas y estado del agua. */
export type StartHydroPatio = {
  operacion_id: string;
  modalidad: 'patio';
  equipo: string;
  inicio_at: string;
  temperatura_inicial_c: number;
  temperatura_agua_inicial_c: number | null;
  condicion_visual_agua: HydroWaterCondition;
  observacion_inicio: string | null;
};

export type StartHydroCycle = StartHydroOfficial | StartHydroPatio;

export type FinishHydroCycle = {
  operacion_id: string;
  termino_at: string;
  temperatura_c: number;
  temperatura_agua_final_c: number | null;
  /** Solo en ciclos oficiales. */
  cloro_libre_final_ppm?: number;
  ph_agua_final?: number;
  condicion_visual_agua_final: HydroWaterCondition;
  dosificador_operativo_final?: boolean;
  control_final_conforme?: boolean;
  destino_salida: HydroDestination;
  observacion: string | null;
  accion_correctiva: string | null;
};

export type ReleaseHydroCycle = {
  operacion_id: string;
  temperatura_verificacion_c: number;
  /** Solo en ciclos oficiales. */
  cloro_libre_verificacion_ppm?: number;
  ph_agua_verificacion?: number;
  control_verificacion_conforme: true;
  evaluacion_producto: string;
  verificacion_liberacion: string;
};

export type HydroProduct = { id: string; nombre: string; unidad_dosis: string };
export type HydroCatalog = { data: HydroProduct[]; rango_cloro_ppm: { min: number; max: number } | null };

export function isPatioCycle(cycle: Pick<HydroCycle, 'modalidad'> | null | undefined): boolean {
  return cycle?.modalidad === 'patio';
}

// Lectura de los campos de la tablet: números con hasta dos decimales, fechas AAAA-MM-DD HH:mm no futuras.
export function localDateTime(date = new Date()) {
  const offset = date.getTimezoneOffset() * 60_000;
  return new Date(date.getTime() - offset).toISOString().slice(0, 16).replace('T', ' ');
}
export function toNumber(value: string, name: string, min: number, max: number, integer = false) {
  const input = value.trim().replace(',', '.');
  if (!input || !/^-?\d+(\.\d{1,2})?$/.test(input)) throw new Error(`${name}: ingresa un número válido de hasta dos decimales.`);
  const parsed = Number(input);
  if (parsed < min || parsed > max || (integer && !Number.isInteger(parsed))) throw new Error(`${name}: debe estar entre ${min} y ${max}${integer ? ' y ser entero' : ''}.`);
  return parsed;
}
export function optionalNumber(value: string, name: string, min: number, max: number) {
  return value.trim() ? toNumber(value, name, min, max) : null;
}
export function toDate(value: string, name: string, now = new Date()) {
  const input = value.trim();
  if (!/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/.test(input)) throw new Error(`${name}: usa el formato AAAA-MM-DD HH:mm.`);
  const date = new Date(input.replace(' ', 'T'));
  if (Number.isNaN(date.getTime()) || localDateTime(date) !== input || date.getTime() > now.getTime()) {
    throw new Error(`${name}: ingresa una fecha y hora válida que no sea futura.`);
  }
  return date.toISOString();
}
export function required(value: string, name: string, min = 1, max = 2000) {
  const text = value.trim();
  if (text.length < min || text.length > max) throw new Error(`${name}: debe tener entre ${min} y ${max} caracteres.`);
  return text;
}
export function optional(value: string) { return value.trim() || null; }

function water(value: string, name: string): HydroWaterCondition {
  const condition = required(value, name);
  if (condition !== 'conforme' && condition !== 'no_conforme') throw new Error(`${name}: indica conforme o no conforme.`);
  return condition;
}

/** Inicio del registro de patio. Con agua no conforme no se inicia, igual que en el registro completo. */
export function buildPatioStart(form: Record<string, string>, operationId: string, now = new Date()): StartHydroPatio {
  const payload: StartHydroPatio = {
    operacion_id: operationId,
    modalidad: 'patio',
    equipo: required(form.equipo ?? '', 'Equipo', 1, 100),
    inicio_at: toDate(form.inicio_at ?? '', 'Inicio', now),
    temperatura_inicial_c: toNumber(form.temperatura_inicial_c ?? '', 'Temperatura de fruta al entrar', -20, 50),
    temperatura_agua_inicial_c: optionalNumber(form.temperatura_agua_inicial_c ?? '', 'Temperatura del agua', -20, 50),
    condicion_visual_agua: water(form.condicion_visual_agua ?? '', 'Estado del agua'),
    observacion_inicio: optional(form.observacion_inicio ?? ''),
  };
  if (payload.condicion_visual_agua !== 'conforme') throw new Error('No inicies el ciclo con agua no conforme. Corrige el agua y vuelve a revisarla.');
  if ((payload.observacion_inicio?.length ?? 0) > 2000) throw new Error('Observación: máximo 2000 caracteres.');
  return payload;
}

/** Término del registro de patio: sin cloro, pH ni dosificador. */
export function buildPatioFinish(form: Record<string, string>, operationId: string, startedAt: string | null | undefined, now = new Date()): FinishHydroCycle {
  const payload: FinishHydroCycle = {
    operacion_id: operationId,
    termino_at: toDate(form.termino_at ?? '', 'Término', now),
    temperatura_c: toNumber(form.temperatura_c ?? '', 'Temperatura de fruta al salir', -20, 50),
    temperatura_agua_final_c: optionalNumber(form.temperatura_agua_final_c ?? '', 'Temperatura del agua al término', -20, 50),
    condicion_visual_agua_final: water(form.condicion_visual_agua_final ?? '', 'Estado del agua al término'),
    destino_salida: required(form.destino_salida ?? '', 'Destino') as HydroDestination,
    observacion: optional(form.observacion ?? ''),
    accion_correctiva: optional(form.accion_correctiva ?? ''),
  };
  if (startedAt && new Date(payload.termino_at).getTime() <= new Date(startedAt).getTime()) throw new Error('El término debe ser posterior al inicio del ciclo.');
  return payload;
}
