import type { RepackingContext } from './repacking';
export type RepalletizingFolio = {
  existe: boolean;
  reembalaje?: RepackingContext;
  id: string;
  numero_folio: string;
  tipo_bulto: string;
  cantidad_cajas: number;
  activo: boolean;
  estado_operacional: string;
  condicion_termica: string;
  cliente: string | null;
  especie: string | null;
  marca: string | null;
  variedad: string | null;
  calibre: string | null;
  envase: string | null;
  categoria: string | null;
  csg: string | null;
  predio: string | null;
  cuartel: string | null;
  composicion: RepalletizingComposition[];
  desglose_incompleto?: boolean;
  ubicacion?: {
    camara: { id: string; codigo: string; nombre: string } | null;
    posicion: { id: string; etiqueta: string } | null;
  } | null;
};

export type RepalletizingComposition = {
  clave: string;
  origen_validacion_id: string | null;
  csg: string;
  predio: string | null;
  fecha_embalaje: string | null;
  cantidad_cajas: number;
  marca?: string | null;
  variedad?: string | null;
  calibre?: string | null;
  envase?: string | null;
  categoria?: string | null;
  cuartel?: string | null;
  lote_materia_prima?: string | null;
  proceso_packing?: string | null;
};

export type CreateRepalletizing = {
  operacion_id: string;
  huella_reembalaje?: string;
  mermas?: Array<{ clave: string; kilos: string }>;
  motivo_merma?: string | null;
  modalidad: 'consolidacion' | 'cambio_folio' | 'division' | 'reembalaje';
  tipo_resultado?: 'pallet' | 'saldo';
  estrategia_folio?: 'conservar' | 'nuevo';
  numero_folio_resultante?: string;
  folio_conservado_id?: string | null;
  cantidad_objetivo?: number | null;
  origenes: Array<{
    folio_id: string;
    cantidad_aportada: number;
    composicion: Array<{ clave: string; cantidad_aportada: number }>;
  }>;
  resultados?: Array<{
    numero_folio: string;
    envase_validacion_id?: string;
    tipo_resultado: 'pallet' | 'saldo';
    cantidad_objetivo: number | null;
    cantidad_resultante: number;
    composicion: Array<{ clave: string; cantidad_cajas: number }>;
  }>;
  observacion: string | null;
  turno: 'A' | 'B';
  fecha_operacional?: string | null;
};

export type Repalletizing = {
  balance_kilos?: { kilos_entrada: string; kilos_salida: string; kilos_merma: string } | null;
  id: string;
  codigo: string;
  modalidad: 'consolidacion' | 'cambio_folio' | 'division' | 'reembalaje';
  tipo_resultado: 'pallet' | 'saldo' | 'division' | 'reembalaje';
  estrategia_folio: 'conservar' | 'nuevo';
  cantidad_objetivo: number | null;
  cantidad_resultante: number;
  condicion_termica: string;
  estado: string;
  turno?: 'A' | 'B' | null;
  fecha_operacional?: string | null;
  campos_mix: string[];
  advertencias: Array<{ campo: string; mensaje: string }>;
  folio_resultante: {
    id: string;
    numero_folio: string;
    tipo_bulto: string;
    cantidad_cajas: number;
    estado_operacional: string;
    condicion_termica: string;
    composicion: RepalletizingComposition[];
  };
  resultados: Array<{
    id: string;
    orden: number;
    tipo_resultado: 'pallet' | 'saldo';
    cantidad_objetivo: number | null;
    cantidad_resultante: number;
    folio: {
      id: string;
      numero_folio: string;
      tipo_bulto: string;
      cantidad_cajas: number;
      composicion: RepalletizingComposition[];
    };
  }>;
  origenes: Array<{
    id: string;
    orden: number;
    cajas_antes: number;
    cajas_aportadas: number;
    cajas_despues: number;
    folio: { id: string; numero_folio: string };
  }>;
  confirmado_at: string;
};
