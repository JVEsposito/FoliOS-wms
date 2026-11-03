export type ProcessDelivery = {
  id: string;
  cantidad_envases: number;
  kilos_enviados: number | null;
  saldo_anterior: number;
  saldo_posterior: number;
  linea_proceso: string;
  turno: 'A' | 'B';
  numero_orden: string;
  embalaje?: string | null;
  kilos_por_caja?: number | null;
  version_embalaje?: number;
  orden_proceso: { id: string; codigo: string | null; fuera_de_orden: boolean; autorizado_por: string | null } | null;
  observacion: string | null;
  entregado_por: { id: string; nombre: string } | null;
  dispositivo: { id: string; codigo: string; nombre: string } | null;
  entregado_at: string;
  anulado: boolean;
  anulado_por: string | null;
  anulado_at: string | null;
  motivo_anulacion: string | null;
  puede_anular: boolean;
  retorno: ProcessReturnSummary;
};

export type ProcessResultType = { id: string; codigo: string; nombre: string; prefijo_sublote: string };
export type ProcessCamera = { id: string; codigo: string; nombre: string };
export type ProcessSublot = {
  id: string;
  numero_sublote: string;
  tipo: { id: string; codigo: string; nombre: string };
  nombre_resultado: string;
  cantidad_bins: number;
  tipo_envase?: 'bins' | 'caja_3_4';
  cantidad_envases?: number;
  envase?: string;
  kilos_netos: number | null;
  estado: 'pendiente_ubicacion' | 'ubicado_camara' | 'anulado';
  camara: ProcessCamera | null;
  ubicado_por: string | null;
  ubicado_at: string | null;
  observacion_ubicacion: string | null;
  puede_ubicar: boolean;
};
export type ProcessReturnOrigin = {
  entrega_id: string;
  lote_id: string;
  numero_lote: string | null;
  linea_proceso: string;
  turno: 'A' | 'B';
  numero_orden: string;
  cierra_entrega: boolean;
};
export type ProcessReturnMovement = {
  id: string;
  numero: string;
  cierra_entrega: boolean;
  origenes: ProcessReturnOrigin[];
  observacion: string | null;
  registrado_por: { id: string; nombre: string } | null;
  dispositivo: { id: string; codigo: string; nombre: string } | null;
  registrado_at: string;
  anulado: boolean;
  anulado_por: string | null;
  anulado_at: string | null;
  motivo_anulacion: string | null;
  puede_anular: boolean;
  resultados: ProcessSublot[];
};
export type ProcessReturnSummary = {
  estado: 'pendiente' | 'parcial' | 'completado';
  bins_retornados: number;
  cajas_3_4_retornadas?: number;
  kilos_recuperados: number | null;
  merma_kilos: number | null;
  puede_registrar: boolean;
  movimientos: ProcessReturnMovement[];
};

export type ProcessLot = {
  id: string;
  numero_lote: string;
  estado: 'asignado_camara' | 'entrega_parcial_proceso' | 'entregado_proceso';
  version: number;
  cliente: { id: string; codigo: string; nombre: string } | null;
  recepcion: { id: string; numero_recepcion: string; numero_guia_despacho: string } | null;
  producto: {
    especie: string;
    variedad: string;
    calibre: string | null;
    csg: string;
    predio: string;
    cuartel: string | null;
    tipo: string;
  };
  camara: { id: string; codigo: string; nombre: string } | null;
  envase_primario: string;
  progreso: { total: number; entregados: number; disponibles: number; porcentaje: number };
  ordenes: ProcessLotOrder[];
  entregas: ProcessDelivery[];
  ultima_entrega_at: string | null;
};

export type ProcessSummary = {
  temporada: { id: string; codigo: string; nombre: string } | null;
  revision: string | null;
  lotes_abiertos: number;
  lotes_completados: number;
  bins_disponibles: number;
  bins_entregados: number;
  entregas_pendientes_retorno: number;
  bins_retornados: number;
  cajas_3_4_retornadas?: number;
  kilos_recuperados: number;
  sublotes_pendientes_ubicacion: number;
  retornos_registrados: number;
  desglose_resultados: Array<{
    tipo: { id: string | null; codigo: string | null; nombre: string | null };
    sublotes: number;
    bins: number;
    kilos: number;
  }>;
};

export type CreateProcessDelivery = {
  operacion_id: string;
  camara_origen_id?: string | null;
  cantidad_envases: number;
  kilos_enviados: number | null;
  turno: 'A' | 'B';
  observacion: string | null;
  /** Con orden de proceso, línea y número de orden salen de la orden. */
  orden_proceso_mp_id?: string;
  version_orden_proceso_mp_id?: string | null;
  autorizacion?: { user_id: number; pin: string };
  /** Solo sin orden, mientras el servidor no exija orden. */
  linea_proceso?: string;
  numero_orden?: string;
};

export type ProcessLine = { id: string; codigo: string; nombre: string };
export type ProcessShift = { codigo: 'A' | 'B'; etiqueta: string; codigo_pre_sif: string; codigo_instructivo: string };
export type ProcessCatalogs = { tipos_resultado: ProcessResultType[]; camaras: ProcessCamera[]; envases_retorno?: Array<{ codigo: "bins" | "caja_3_4"; nombre: string }>; lineas?: ProcessLine[]; turnos?: ProcessShift[] };

/** Orden abierta que reserva bins de un lote. */
export type ProcessLotOrder = {
  id: string;
  codigo: string;
  fecha: string;
  linea: ProcessLine | null;
  version_id: string;
  numero_version: number;
  bins_pendientes: number;
};

export type ProcessOrderRow = {
  lote_materia_prima_id: string;
  secuencia: number;
  numero_lote: string;
  numero_proceso: string | null;
  cliente: { id: string; nombre: string };
  variedad: string;
  csg: string;
  bins_programados: number;
  bins_entregados: number;
  bins_pendientes: number;
  inicio_estimado: string | null;
  termino_estimado: string | null;
  camara: { id: string; codigo: string; nombre: string } | null;
};

export type ProcessOrder = {
  id: string;
  codigo: string;
  fecha: string;
  turno_inicio: 'A' | 'B';
  linea: ProcessLine | null;
  estado: 'programada' | 'en_proceso' | 'terminada';
  numero_version_vigente: number;
  progreso: { lotes: number; bins_programados: number; bins_entregados: number; bins_pendientes: number };
  version_vigente: { id: string; numero: number; lotes: ProcessOrderRow[] } | null;
};

export type ProcessAuthorizer = { id: number; nombre: string };
export type CreatePackingReturn = {
  operacion_id: string;
  entregas: Array<{ entrega_fruta_proceso_id: string; cierra_entrega: boolean }>;
  observacion: string | null;
  resultados: Array<{
    tipo_resultado_packing_id: string;
    nombre_resultado: string | null;
    tipo_envase?: "bins" | "caja_3_4";
    cantidad_envases?: number;
    cantidad_bins: number;
    kilos_netos: number | null;
  }>;
};
