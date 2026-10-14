export type MaterialTransformationState =
  | 'borrador'
  | 'planificada'
  | 'en_proceso'
  | 'pendiente_cierre'
  | 'cerrada'
  | 'cancelada';

/**
 * por_lote: cada lote pistolea sus consumos (flujo original).
 * cuadratura_cierre: los componentes se retiran al iniciar, cada folio de salida se
 * confirma pistoleando su etiqueta reservada y la merma se cuadra al cerrar la orden.
 */
export type MaterialTransformationMode = 'por_lote' | 'cuadratura_cierre';

export type MaterialTransformationItem = {
  id: string;
  codigo: string;
  nombre: string;
  unidad_medida: string;
};

export type MaterialTransformationReservation = {
  id: string;
  estado: 'activa' | 'consumida' | 'liberada';
  cantidad: string;
  cantidad_consumida: string;
  cantidad_devuelta?: string;
  cantidad_pendiente: string;
  orden_fifo: number;
  item_material_id: string;
  folio: {
    id: string;
    numero_folio: string;
    cantidad_actual: string;
    cantidad_reservada: string;
    ubicacion: { camara: string; posicion: string } | null;
  } | null;
};

export type MaterialTransformationConsumption = {
  id: string;
  folio_id: string;
  numero_folio: string;
  item: MaterialTransformationItem;
  cantidad_consumida: string;
  cantidad_anterior: string;
  cantidad_resultante: string;
  siguio_fifo: boolean;
  motivo_desviacion_fifo: string | null;
  ocurrido_at: string;
};

export type MaterialTransformationOutput = {
  id: string;
  folio_id: string;
  numero_folio: string;
  item: MaterialTransformationItem;
  cantidad_producida: string;
  es_salida_principal: boolean;
};

export type MaterialTransformationLot = {
  id: string;
  numero_lote: number;
  estado: 'abierto' | 'cerrado' | 'anulado';
  cantidad_planificada_salida: string;
  cantidad_real_salida: string | null;
  salida_teorica: string | null;
  merma_estandar: string | null;
  merma_real: string | null;
  desviacion_merma: string | null;
  iniciado_at: string;
  cerrado_at: string | null;
  reversado_at: string | null;
  motivo_reversa: string | null;
  reversado_por: { id: number; nombre: string } | null;
  consumos: MaterialTransformationConsumption[];
  salidas: MaterialTransformationOutput[];
};

/** Folio de salida reservado al planificar: su etiqueta se imprime antes de producir. */
export type MaterialTransformationOutputFolio = {
  id: string;
  secuencia: number;
  numero_folio: string;
  estado: 'reservado' | 'usado' | 'anulado';
  cantidad_teorica: string;
  cantidad_real: string | null;
  folio_id: string | null;
  lote_id: string | null;
  impresiones: number;
  ultima_impresion_at: string | null;
  requiere_reimpresion: boolean;
  motivo_anulacion: string | null;
  anulado_at: string | null;
};

export type MaterialTransformationOutputFolioSummary = {
  reservados: number;
  sin_imprimir: number;
  por_reimprimir: number;
  usados: number;
  anulados: number;
};

/** Cuadratura de un componente al cerrar una orden en modo cuadratura. */
export type MaterialTransformationReconciliation = {
  item: MaterialTransformationItem;
  es_componente_principal: boolean;
  cantidad_retirada: string;
  cantidad_teorica: string;
  cantidad_merma: string;
  cantidad_sin_armar: string;
  cantidad_utilizada: string;
  desviacion: string;
};

export type MaterialTransformationRecipeSnapshot = {
  salida: {
    item_id: string;
    codigo: string;
    nombre: string;
    cantidad_base: string;
    unidades_por_folio: string | null;
    unidad_medida: string;
  };
  componentes: {
    item_id: string;
    codigo: string;
    nombre: string;
    cantidad_estandar: string;
    unidad_medida: string;
    es_componente_principal: boolean;
    factor_conversion: string;
    merma_estandar_porcentaje: string;
    tolerancia_porcentaje: string;
  }[];
};

export type MaterialTransformationOrder = {
  id: string;
  estado: MaterialTransformationState;
  modo_operacion?: MaterialTransformationMode;
  version: number;
  cantidad_planificada_salida: string;
  cantidad_real_salida: string | null;
  unidades_por_folio_salida: string | null;
  folios_planificados: number | null;
  folios_generados?: number;
  folios_pendientes?: number;
  linea: string | null;
  turno: string | null;
  fecha_operacional: string;
  observacion: string | null;
  receta_snapshot: MaterialTransformationRecipeSnapshot;
  cliente: { id: string; codigo: string; nombre: string };
  version_receta: {
    id: string;
    numero_version: number;
    estado: string;
    receta: {
      id: string;
      nombre: string;
      item_salida: MaterialTransformationItem;
    };
  };
  reservas: MaterialTransformationReservation[];
  lotes: MaterialTransformationLot[];
  folios_salida?: MaterialTransformationOutputFolio[];
  folios_salida_resumen?: MaterialTransformationOutputFolioSummary;
  cuadratura?: MaterialTransformationReconciliation[];
  iniciado_at: string | null;
  cerrado_at: string | null;
  created_at: string;
  updated_at: string;
};

export type MaterialTransformationOrderSummary = Omit<
  MaterialTransformationOrder,
  'receta_snapshot' | 'reservas' | 'lotes' | 'folios_salida' | 'cuadratura'
> & {
  reservas_count: number;
  lotes_count: number;
  tiene_salidas: boolean;
};

export type MaterialTransformationOperationPayload = {
  operacion_id: string;
  version_conocida: number;
};

export type StartMaterialTransformationPayload = MaterialTransformationOperationPayload & {
  modo_operacion?: MaterialTransformationMode;
  /** Modo cuadratura: cada folio reservado de componentes que se retiró a la línea. */
  folios_retirados?: string[];
};

export type ConfirmMaterialTransformationOutputPayload = MaterialTransformationOperationPayload & {
  folio_salida: string;
  cantidad_real_salida: number;
};

export type OpenMaterialTransformationLotPayload = MaterialTransformationOperationPayload & {
  cantidad_planificada_salida: number;
};

export type CloseMaterialTransformationLotPayload = MaterialTransformationOperationPayload & {
  cantidad_real_salida: number;
  consumos: {
    folio_id: string;
    cantidad: number;
    motivo_desviacion_fifo?: string;
  }[];
};

export type CloseMaterialTransformationOrderPayload = MaterialTransformationOperationPayload & {
  motivo_desviacion?: string;
  /** Modo cuadratura: folios creados que vio el camarero y merma/sin armar por componente. */
  folios_creados?: number;
  cuadratura?: {
    item_id: string;
    cantidad_merma: number;
    cantidad_sin_armar: number;
  }[];
};

export type ReverseMaterialTransformationLotPayload = MaterialTransformationOperationPayload & {
  motivo: string;
};
