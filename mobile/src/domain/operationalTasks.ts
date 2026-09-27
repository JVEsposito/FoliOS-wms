import { MovementType } from './estiba';

export type OperationalTaskPriority = 'normal' | 'alta' | 'urgente' | 'critica';
export type OperationalTaskState = 'bloqueada' | 'pendiente' | 'asumida' | 'en_proceso' | 'completada' | 'cancelada';
export type ManeuverStepType =
  | 'movimiento_permanente'
  | 'extraccion_temporal'
  | 'retorno_banda'
  | 'entrega_anden';

export type OperationalManeuverStep = {
  id: string;
  secuencia: number;
  estado: OperationalTaskState;
  tipo_movimiento: MovementType;
  tipo_paso: ManeuverStepType | null;
  folio: {
    id: string;
    numero_folio: string;
  } | null;
  origen: OperationalTaskEndpoint;
  destino: OperationalTaskEndpoint;
  destino_logico: {
    tipo: 'anden';
    id: string;
    nombre: string;
    carga_folio_id: string | null;
    presencia_carga_anden_id: string | null;
  } | null;
  instruccion: string | null;
};

export type OperationalTemporaryCustody = {
  id: string;
  estado: 'activa';
  folio: {
    id: string;
    numero_folio: string;
  } | null;
  origen: OperationalTaskEndpoint;
  extraido_at: string | null;
};

export type OperationalManeuverObjective = {
  id: string;
  tipo: string;
  estado: string;
  prioridad: OperationalTaskPriority;
  titulo: string;
  es_principal: boolean;
  beneficio_estimado: number;
};

export type OperationalManeuverArbitration = {
  decision: 'en_ejecucion' | 'seleccionada' | 'alternativa' | 'excluida_conflicto' | 'fuera_frontera' | 'fuera_planificador' | 'fuera_rollout';
  orden: number;
  puntaje: number;
  beneficio_neto: number;
  motivo: string;
  conflictos: Array<{
    recurso: string;
    maniobra_id: string;
  }>;
  snapshot_version: string | null;
};

export type OperationalTaskEndpoint = {
  camara: {
    id: string;
    nombre: string;
  };
  posicion: {
    id: string;
    etiqueta: string | null;
    banda: number;
    posicion: number;
    nivel: number;
  } | null;
} | null;

export type OperationalTaskReservation = {
  id: string;
  estado: string;
  tipo_compromiso: 'claim' | 'fisica';
  destino_reservado: boolean;
  reservada_at: string | null;
  renovada_at: string | null;
  vence_at: string | null;
  segundos_restantes: number | null;
  version: number;
};

export type OperationalTask = {
  id: string;
  plan: {
    id: string;
    tipo: string;
    estado: string;
    prioridad: OperationalTaskPriority;
    titulo: string;
    version: number;
    horizon?: 'batch' | 'rolling';
  };
  secuencia: number;
  maniobra: {
    id: string;
    estado: 'pendiente' | 'en_ejecucion' | 'pausada_discrepancia' | 'completada' | 'cancelada';
    titulo: string;
    secuencia_actual: number;
    pasos_totales: number;
    costo_movimientos: number;
    beneficio_estimado: number;
    riesgo_operacional: number;
    version: number;
    arbitraje?: OperationalManeuverArbitration | null;
    objetivos?: OperationalManeuverObjective[];
    custodia_temporal_activa: boolean;
    pasos: OperationalManeuverStep[];
    custodias_temporales: OperationalTemporaryCustody[];
  } | null;
  secuencia_maniobra: number | null;
  tipo_paso_maniobra: ManeuverStepType | null;
  tipo_movimiento: MovementType;
  estado: OperationalTaskState;
  prioridad: OperationalTaskPriority;
  punto_no_retorno: boolean;
  folio: {
    id: string;
    numero_folio: string;
    tipo_bulto: 'pallet';
    variedad?: string | null;
    calibre?: string | null;
    marca?: string | null;
    exportadora?: string | null;
    fecha_ingreso?: string | null;
  };
  origen: OperationalTaskEndpoint;
  destino: OperationalTaskEndpoint;
  destino_logico?: {
    tipo: 'anden';
    id: string;
    nombre: string;
    carga_folio_id: string;
    presencia_carga_anden_id: string;
  } | null;
  responsable: {
    id: string | number;
    nombre: string;
  } | null;
  dispositivo: {
    id: string;
    codigo: string;
    nombre: string;
  } | null;
  reserva: OperationalTaskReservation | null;
  instruccion: string | null;
  contexto: Record<string, unknown>;
  asumida_at: string | null;
  iniciada_at: string | null;
  completada_at: string | null;
  cancelada_at: string | null;
  version: number;
  created_at: string;
};

export type PlannerMode = 'off' | 'shadow' | 'guided';
export type PlannerCompute = 'server' | 'tablet';
export type PlannerHorizon = 'batch' | 'rolling';

export type OperationalSnapshotTask = {
  id: string;
  version: number;
  estado: OperationalTaskState;
  tipo_movimiento?: MovementType;
  folio_id: string;
  camara_origen_id: string | null;
  posicion_origen_id: string | null;
  camara_destino_id: string | null;
  posicion_destino_id: string | null;
  destino_reservado?: boolean;
  maniobra_id: string | null;
  secuencia_maniobra: number | null;
  tipo_paso_maniobra?: ManeuverStepType | null;
  plan_id?: string;
  plan_version?: number;
  maniobra_version?: number | null;
  secuencia_actual?: number | null;
  punto_no_retorno?: boolean;
  decision_arbitraje?: OperationalManeuverArbitration['decision'] | null;
  orden_arbitraje?: number | null;
  materializable?: boolean;
};

export type OperationalSnapshot = {
  snapshot_version: string;
  generado_at: string;
  planner: {
    mode: PlannerMode;
    compute: PlannerCompute;
    horizon: PlannerHorizon;
    frontier_max: number;
    camara_preferente_despacho_id?: string | null;
  };
  plan: {
    id: string;
    tipo: string;
    estado: string;
    prioridad: OperationalTaskPriority;
    titulo: string;
    version: number;
  };
  camaras: Array<{
    id: string;
    codigo: string;
    nombre: string;
    version_plano: number;
    revision_reservas: number;
  }>;
  tareas: OperationalSnapshotTask[];
};

export type OperationalPhysicalFrontierSnapshot = {
  snapshot_version: string;
  generado_at: string;
  planner: {
    mode: PlannerMode;
    compute: PlannerCompute;
    horizon: PlannerHorizon;
    frontier_max: number;
    maniobras_simultaneas_max: number;
    rollout_limitado: boolean;
    camaras_dirigidas: string[] | null;
    camara_preferente_despacho_id: string | null;
  };
  arbitraje: {
    ciclo_id: string | null;
    snapshot_version: string | null;
    capacidad_ejecucion: number | null;
    frontera_max: number | null;
    vigencia: {
      estado: 'detenido' | 'pendiente' | 'recalculando' | 'actual' | 'atrasado' | 'error';
      vigente: boolean;
      detalle: string;
      solicitado_at: string | null;
      iniciado_at: string | null;
      evaluado_at: string | null;
      edad_segundos: number | null;
      umbral_atraso_segundos: number;
      version_solicitada: number;
      version_calculada: number;
      calculos_exitosos: number;
      calculos_fallidos: number;
      espera_ultimo_calculo_ms: number | null;
      duracion_ultimo_calculo_ms: number | null;
      ultimo_error_at: string | null;
    };
    en_ejecucion: number;
    seleccionadas: number;
    alternativas: number;
    fuera_rollout: number;
  };
  frontera: {
    reservas_fisicas_activas: number;
    tareas_materializables: number;
    solo_paso_actual: boolean;
  };
  camaras: Array<{
    id: string;
    codigo: string;
    nombre: string;
    version_plano: number;
    revision_reservas: number;
  }>;
  tareas: OperationalSnapshotTask[];
};

export type TemporaryExtractionPayload = {
  operacion_id: string;
  sesion_origen_id: string;
  version_origen_conocida: number;
  generado_dispositivo_at: string;
  advertencias_confirmadas?: string[];
};

export type ManeuverDiscrepancyType =
  | 'pallet_no_coincide'
  | 'posicion_no_coincide'
  | 'posicion_vacia'
  | 'obstaculo'
  | 'pallet_no_movible'
  | 'otra';

export type ReportedManeuverDiscrepancy = {
  id: string;
  estado: 'abierta';
  maniobra_id: string;
  tarea_id: string;
};

export type OperationalFrontierProposal = {
  tarea_id: string;
  posicion_destino_id: string;
  tarea_version: number;
  plan_version: number;
  version_camara_conocida: number;
  score?: number;
  motivo?: string;
};

export type OperationalFrontierResult = {
  aceptadas: Array<{
    tarea: OperationalTask;
    score: number | null;
    motivo: string | null;
    planner_version: string;
  }>;
  rechazadas: Array<{
    tarea_id: string;
    posicion_destino_id?: string;
    motivo: string;
  }>;
  recalcular: boolean;
  snapshot: OperationalSnapshot | OperationalPhysicalFrontierSnapshot;
};

export type OperationalTaskAssignment = 'disponibles' | 'mias';

export const OPERATIONAL_TASK_LABELS: Record<string, string> = {
  recepcion_tunel: 'Recibir túnel',
  almacenamiento_pallet: 'Guardar pallet',
  despacho_directo: 'Retirar a andén',
  preparacion_inspeccion: 'Preparar inspección',
  concentracion_carga: 'Concentrar carga',
  segregacion_retenido: 'Segregar retenido',
  movimiento_oportunidad: 'Movimiento de oportunidad',
  reordenamiento_camara: 'Reordenar cámara',
  desocupacion_camara: 'Desocupar cámara',
  evacuacion_emergencia: 'Emergencia',
  correccion_discrepancia: 'Corregir discrepancia',
};

export function operationalTaskLabel(type: string) {
  return OPERATIONAL_TASK_LABELS[type]
    ?? type.replaceAll('_', ' ').replace(/^./, (letter) => letter.toUpperCase());
}

export function operationalTaskPositionLabel(endpoint: OperationalTaskEndpoint) {
  if (!endpoint) return 'Por calcular';
  if (!endpoint.posicion) return `${endpoint.camara.nombre} · posición por calcular`;

  const position = endpoint.posicion;
  const physical = position.etiqueta
    ?? `B${String(position.banda).padStart(2, '0')}-P${String(position.posicion).padStart(2, '0')}-N${position.nivel}`;

  return `${endpoint.camara.nombre} · ${physical}`;
}

export function operationalTaskDestinationLabel(task: OperationalTask) {
  if (task.destino_logico?.tipo === 'anden') return task.destino_logico.nombre;
  return operationalTaskPositionLabel(task.destino);
}

export function operationalTaskReason(task: OperationalTask) {
  if (task.instruccion) return task.instruccion;
  const candidates = ['motivo', 'razon', 'detalle', 'origen'];
  for (const key of candidates) {
    const value = task.contexto?.[key];
    if (typeof value === 'string' && value.trim()) return value;
  }
  return task.plan.titulo;
}

export function positionScanMatches(task: OperationalTask, scannedValue: string) {
  const position = task.destino?.posicion;
  if (!position) return false;

  const normalized = normalizeScan(scannedValue);
  const generated = `B${String(position.banda).padStart(2, '0')}-P${String(position.posicion).padStart(2, '0')}-N${position.nivel}`;
  const candidates = [position.id, position.etiqueta, generated]
    .filter((value): value is string => Boolean(value))
    .map(normalizeScan);

  return candidates.includes(normalized);
}

export function folioScanMatches(task: OperationalTask, scannedValue: string) {
  return normalizeScan(scannedValue) === normalizeScan(task.folio.numero_folio);
}

function normalizeScan(value: string) {
  return value.trim().toUpperCase();
}
