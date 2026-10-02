import { ContainerCode, ContainerOption } from '../services/containerCatalog';
export type ContainerType = ContainerCode;
export type ReceptionType = 'fruta_con_envases' | 'fruta_pesaje_envases' | 'solo_envases';
export type MpValidationState = 'pendiente' | 'en_curso' | 'validada';
export type SegregationReason = 'csg' | 'cuartel' | 'variedad';

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
  } | null;
  envases: Array<{
    tipo_envase: ContainerType;
    cantidad_declarada: number;
    cantidad_validada: number | null;
    diferencia: number | null;
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
  tomada_at: string;
  validada_at: string | null;
  observacion: string | null;
  segmentos: Array<{
    id: string;
    secuencia: number;
    motivos: SegregationReason[];
    csg: string | null;
    cuartel: string | null;
    variedad: string | null;
    estado: 'pendiente_lote';
    envases: Array<{ tipo_envase: ContainerType; cantidad: number }>;
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
};

export type MpSegmentDraft = {
  key: string;
  motivos: SegregationReason[];
  csg_validacion_id: string | null;
  cuartel: string;
  variedad_validacion_id: string | null;
  cantidades: Record<ContainerType, string>;
};
