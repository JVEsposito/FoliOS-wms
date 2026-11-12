import type { ContainerCode, ContainerOption } from '../services/containerCatalog';
export type ContainerType = ContainerCode;
export type ReceptionType = 'fruta_con_envases' | 'fruta_pesaje_envases' | 'solo_envases';
export type MpValidationState = 'pendiente' | 'en_curso' | 'validada';
export type SegregationReason = 'csg' | 'cuartel' | 'variedad' | 'guia';

export type MpReceptionGuide = {
  id: string;
  numero_guia_despacho: string;
  envases: Array<{ tipo_envase: ContainerType; cantidad: number }>;
  envases_validados?: Array<{ tipo_envase: ContainerType; cantidad: number }> | null;
};

export type MpReception = {
  id: string;
  numero_recepcion: string;
  estado_romana: 'en_bascula_ingreso' | 'en_pesaje_envases' | 'en_bascula_salida' | 'cerrado';
  estado_validacion_mp: MpValidationState;
  tipo_recepcion: ReceptionType;
  especie_validacion_id: string | null;
  version: number;
  concepto_envases: 'compra' | 'arriendo' | null;
  temporada: { id: string; codigo: string; nombre: string };
  cliente: { id: string; codigo: string | null; nombre: string };
  numero_guia_despacho: string;
  guias?: MpReceptionGuide[];
  patente_camion: string;
  conductor: { rut: string; nombre: string };
  ingreso_at: string;
  pesaje_envases: {
    tipo_envase: ContainerType;
    tara_unitaria: number;
    cantidad_declarada: number;
    cantidad_pesada: number;
    peso_neto: number;
    peso_neto_por_envase: number | null;
    /** Uno o más tipos de envase; las respuestas anteriores no lo traen. */
    tipos?: Array<{
      tipo_envase: ContainerType;
      cantidad_declarada: number;
      cantidad_pesada: number;
      cantidad_pendiente: number;
      tara_unitaria: number | null;
      peso_neto: number;
      peso_neto_por_envase: number | null;
    }>;
  } | null;
  envases: Array<{
    tipo_envase: ContainerType;
    cantidad_declarada: number;
    cantidad_validada: number | null;
    /** Contados que llegaron sin fruta. Los servidores anteriores no lo envían. */
    cantidad_vacia?: number;
    diferencia: number | null;
    /** Solo los tipos que traen fruta admiten vacíos; sin el dato, la PDA no los pide. */
    contiene_fruta?: boolean;
  }>;
  tomada_por: { id: string; nombre: string } | null;
  validacion: MpValidation | null;
};

export type MpValidation = {
  id: string;
  estado: MpValidationState;
  numero_recepcion: string;
  temporada: { id: string; codigo: string };
  validador: { id: string; nombre: string };
  dispositivo: { id: string; codigo: string } | null;
  tarjas_verificadas: boolean | null;
  requiere_segregacion: boolean;
  bins_prestados_planta: number;
  tomada_at: string;
  validada_at: string | null;
  observacion: string | null;
  segmentos: Array<{
    id: string;
    secuencia: number;
    guia_recepcion_romana_id?: string | null;
    numero_guia_despacho?: string | null;
    motivos: SegregationReason[];
    csg: string | null;
    cuartel: string | null;
    variedad: string | null;
    estado: 'pendiente_lote';
    bins_prestados_planta: number;
    /** `cantidad` va al lote (con fruta); `cantidad_vacia` llegó vacío con el segmento. */
    envases: Array<{ tipo_envase: ContainerType; cantidad: number; cantidad_vacia?: number }>;
  }>;
};

export type MpHistory = { id: string; fecha: string; numero_recepcion: string; cliente: string; estado: string; segmentos: Array<{ csg: string | null; variedad: string | null; envases: Array<{ tipo_envase: ContainerType; cantidad: number }> }> };

export type MpCatalog = {
  envases: ContainerOption[];
  temporada: { id: string; codigo: string; nombre: string };
  especies: Array<{ id: string; nombre: string }>;
  csg: Array<{ id: string; codigo: string; predio: string | null; variedad_ids: string[] }>;
  variedades: Array<{ id: string; nombre: string; especie: string | null }>;
  motivos: SegregationReason[];
  /** Referencia de la especie para sugerir bins de planta; null si no está configurada. */
  totes_por_bin?: number | null;
};

export type MpSegmentDraft = {
  key: string;
  guia_recepcion_romana_id?: string | null;
  motivos: SegregationReason[];
  csg_validacion_id: string | null;
  cuartel: string;
  variedad_validacion_id: string | null;
  cantidades: Record<ContainerType, string>;
  /** Bins de planta del segmento; null usa la sugerencia. */
  bins_planta: string | null;
  /** Vacíos del segmento por tipo, incluidos en `cantidades`. */
  vacios?: Record<ContainerType, string>;
};

/** Conteo de un tipo: `cantidad_vacia` es la parte de lo contado que llegó sin fruta. */
export type MpContainerCount = { tipo_envase: ContainerType; cantidad_validada: number; cantidad_vacia?: number };

/** Envases con fruta: la base del peso promedio por envase. */
export function containersWithFruit(count: MpContainerCount): number {
  return Math.max(0, count.cantidad_validada - (count.cantidad_vacia ?? 0));
}

export function emptyCountProblem(containers: MpContainerCount[], labelOf: (type: string) => string): string | null {
  const exceeded = containers.find((item) => (item.cantidad_vacia ?? 0) > item.cantidad_validada);
  return exceeded ? `${labelOf(exceeded.tipo_envase)}: los vacíos no pueden superar los contados.` : null;
}

/** Con segmentos, los vacíos de cada tipo se reparten entre ellos y suman el total contado. */
export function segmentEmptiesProblem(containers: MpContainerCount[], segments: MpSegmentDraft[], labelOf: (type: string) => string): string | null {
  for (const container of containers) {
    const total = container.cantidad_vacia ?? 0;
    let sum = 0;
    for (const [index, segment] of segments.entries()) {
      const empties = Number(segment.vacios?.[container.tipo_envase] || 0);
      if (empties > Number(segment.cantidades[container.tipo_envase] || 0)) {
        return `Segmento ${index + 1}: los vacíos de ${labelOf(container.tipo_envase).toLowerCase()} no pueden superar sus contados.`;
      }
      sum += empties;
    }
    if (sum !== total) return `${labelOf(container.tipo_envase)}: reparte los ${total} vacíos entre los segmentos (van ${sum}).`;
  }
  return null;
}
