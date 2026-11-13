export type PrefrioProcessState =
  | 'borrador'
  | 'cargando'
  | 'listo_para_iniciar'
  | 'en_proceso'
  | 'pendiente_verificacion'
  | 'aprobado'
  | 'requiere_reproceso'
  | 'cancelado';

export type PrefrioAssignmentState =
  | 'cargado'
  | 'en_proceso'
  | 'aprobado'
  | 'requiere_reproceso'
  | 'retirado'
  | 'cancelado';

export type PrefrioOperationalEventType =
  | 'inversion_registrada'
  | 'pausa'
  | 'reanudacion'
  | 'deshielo'
  | 'lectura';

export type PrefrioTunnelPosition = {
  id: string;
  numero: number;
  etiqueta: string;
  activa: boolean;
};

export type PrefrioActiveProcessSummary = {
  id: string;
  codigo: string;
  estado: PrefrioProcessState;
  version: number;
  folios_cargados: number;
  iniciado_at: string | null;
};

export type PrefrioTunnel = {
  id: string;
  codigo: string;
  nombre: string;
  capacidad_posiciones: number;
  setpoint_habitual: number | null;
  estado_administrativo: 'activo' | 'inactivo';
  estado_tecnico: 'operativo' | 'fuera_de_servicio' | 'mantenimiento';
  codigo_externo: string | null;
  observacion: string | null;
  version_configuracion: number;
  posiciones: PrefrioTunnelPosition[];
  proceso_activo: PrefrioActiveProcessSummary | null;
  created_at: string;
  updated_at: string;
};

export type PrefrioFolioCandidate = {
  id: string;
  numero_folio: string;
  tipo_bulto: 'pallet' | 'saldo';
  estado_operacional: string;
  condicion_termica: 'pendiente_prefrio' | 'requiere_reproceso' | 'retenido';
  habilitacion_almacenamiento: 'no_habilitado' | 'retenido';
  especie: string | null;
  variedad: string | null;
  calibre: string | null;
  envase: string | null;
  categoria: string | null;
  marca: string | null;
  exportadora: string | null;
  csg: string | null;
  predio: string | null;
  cantidad_cajas: number | null;
  fecha_ingreso: string | null;
};

export type PrefrioProcessFolio = {
  id: string;
  estado: PrefrioAssignmentState;
  temperatura_inicial: number | null;
  temperatura_final: number | null;
  cargado_at: string | null;
  retirado_at: string | null;
  motivo_resultado: string | null;
  observacion: string | null;
  posicion: PrefrioTunnelPosition | null;
  folio: {
    id: string;
    numero_folio: string;
    tipo_bulto: 'pallet' | 'saldo';
    estado_operacional: string;
    condicion_termica: string | null;
    habilitacion_almacenamiento: string | null;
    variedad: string | null;
    calibre: string | null;
    marca: string | null;
    exportadora: string | null;
    cantidad_cajas: number | null;
  } | null;
  cargado_por: { id: string; nombre: string } | null;
};

export type PrefrioEvent = {
  id: string;
  operacion_id: string;
  tipo: string;
  ocurrido_at: string;
  datos: Record<string, unknown> | null;
  observacion: string | null;
  usuario: { id: string; nombre: string } | null;
  dispositivo: { id: string; codigo: string; nombre: string } | null;
};

export type PrefrioProcess = {
  id: string;
  codigo: string;
  tunel: Omit<PrefrioTunnel, 'posiciones' | 'proceso_activo' | 'codigo_externo' | 'observacion' | 'created_at' | 'updated_at'>;
  estado: PrefrioProcessState;
  setpoint: number;
  duracion_objetivo_minutos: number | null;
  formato_referencia: string | null;
  version: number;
  observacion: string | null;
  iniciado_at: string | null;
  pendiente_verificacion_at: string | null;
  finalizado_at: string | null;
  folios: PrefrioProcessFolio[];
  eventos: PrefrioEvent[];
  /** Control horario POEMP R-41; el servidor lo envía cuando incluye los eventos. */
  registro_r41?: {
    intervalo_lectura_minutos: number;
    exige_lectura_final: boolean;
    lecturas: number;
    ultima_lectura_at: string | null;
    ultima_lectura_completa: boolean;
    proxima_lectura_at: string | null;
    lectura_vencida: boolean;
  } | null;
  creado_por: { id: string; nombre: string };
  iniciado_por: { id: string; nombre: string } | null;
  finalizado_por: { id: string; nombre: string } | null;
  created_at: string;
  updated_at: string;
};

export type CreatePrefrioProcessPayload = {
  operacion_id: string;
  tunel_prefrio_id: string;
  setpoint: number;
  duracion_objetivo_minutos?: number;
  formato_referencia?: string;
  observacion?: string;
  ocurrido_at: string;
};

export type AddPrefrioFolioPayload = {
  operacion_id: string;
  version_conocida: number;
  folio_id: string;
  posicion_tunel_prefrio_id: string;
  temperatura_inicial?: number;
  observacion?: string;
  ocurrido_at: string;
};

export type PrefrioActionPayload = {
  operacion_id: string;
  version_conocida: number;
  observacion?: string;
  datos?: Record<string, unknown>;
  ocurrido_at: string;
};

export type PrefrioCommandKind =
  | 'agregar_folio'
  | 'retirar_folio'
  | 'confirmar_armado'
  | 'iniciar'
  | 'evento'
  | 'verificar';

export type PrefrioQueuedCommand = {
  id: string;
  process_id: string;
  process_code: string;
  kind: PrefrioCommandKind;
  label: string;
  route: string;
  payload: AddPrefrioFolioPayload | PrefrioActionPayload;
  status: 'pendiente' | 'conflicto' | 'error';
  attempts: number;
  created_at: string;
  last_attempt_at: string | null;
  message: string | null;
};

export type PrefrioMobileCache = {
  tunnels: PrefrioTunnel[];
  processes: PrefrioProcess[];
  eligible_folios: PrefrioFolioCandidate[];
  revisions?: {
    tunnels?: string | null;
    processes?: string | null;
    eligible_folios?: string | null;
  };
  synced_at: string;
};
