export type ProcessDelivery = {
  id: string;
  cantidad_envases: number;
  kilos_enviados: number | null;
  saldo_anterior: number;
  saldo_posterior: number;
  linea_proceso: string;
  turno: 'A' | 'B';
  numero_orden: string;
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
  linea_proceso: string;
  turno: 'A' | 'B';
  numero_orden: string;
  observacion: string | null;
};

export type ProcessCatalogs = { tipos_resultado: ProcessResultType[]; camaras: ProcessCamera[] };
export type CreatePackingReturn = {
  operacion_id: string;
  entregas: Array<{ entrega_fruta_proceso_id: string; cierra_entrega: boolean }>;
  observacion: string | null;
  resultados: Array<{
    tipo_resultado_packing_id: string;
    nombre_resultado: string | null;
    cantidad_bins: number;
    kilos_netos: number | null;
  }>;
};
