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
  | 'pendiente_prefrio'
  | 'requiere_reproceso'
  | 'retenido'
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
  finalizado_parcial_at?: string | null;
  en_tunel?: boolean;
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
  retirado_por?: { id: string; nombre: string } | null;
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

export const prefrioTimeZone = 'America/Santiago';
const plantClock = new Intl.DateTimeFormat('sv-SE', {
  timeZone: prefrioTimeZone, year: 'numeric', month: '2-digit', day: '2-digit',
  hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23',
});

export function operationalDateTime(date = new Date()): string {
  const parts = Object.fromEntries(plantClock.formatToParts(date).map(({ type, value }) => [type, value]));
  return `${parts.day}-${parts.month}-${parts.year} ${parts.hour}:${parts.minute}:${parts.second}`;
}

export function parseOperationalDateTime(value: string): string | null {
  const match = value.trim().match(/^(\d{2})-(\d{2})-(\d{4})\s+(\d{2}):(\d{2})(?::(\d{2}))?$/);
  if (!match) return null;
  const [, day, month, year, hour, minute, second = '00'] = match;
  const wall = Date.parse(`${year}-${month}-${day}T${hour}:${minute}:${second}Z`);
  if (!Number.isFinite(wall)) return null;
  const expected = `${day}-${month}-${year} ${hour}:${minute}:${second}`;
  // Muestrea ambos lados de un posible cambio de hora y comprueba la vuelta a hora de planta.
  for (const probe of [wall - 86_400_000, wall + 86_400_000]) {
    const local = Date.parse(`${plantClock.format(new Date(probe)).replace(' ', 'T')}Z`);
    const candidate = new Date(wall - (local - probe));
    if (Number.isFinite(candidate.getTime()) && operationalDateTime(candidate) === expected) return candidate.toISOString();
  }
  return null;
}

export const partialThermalResults = {
  prefrio_aprobado: 'Prefrío aprobado',
  pendiente_prefrio: 'Pendiente de prefrío',
  requiere_reproceso: 'Requiere reproceso',
  retenido: 'Retenido',
};

export type PrefrioPartialResult = keyof typeof partialThermalResults;

export type PrefrioPartialOutcome = {
  folio_id: string;
  resultado: PrefrioPartialResult;
  temperatura_final?: number;
  motivo?: string;
};

export type PrefrioPartialSelection = { motivo: string; resultados: PrefrioPartialOutcome[] } | { folios: string[] };

export function prefrioFolioInTunnel(folio: PrefrioProcessFolio): boolean {
  return folio.en_tunel ?? !['retirado', 'cancelado'].includes(folio.estado);
}

export function prefrioPartialWithdrawalPending(folio: PrefrioProcessFolio): boolean {
  return Boolean(folio.finalizado_parcial_at && !folio.retirado_at);
}

export function prefrioPartialResultLabel(folio: PrefrioProcessFolio): string {
  if (folio.estado === 'aprobado') return partialThermalResults.prefrio_aprobado;
  if (folio.estado === 'pendiente_prefrio' || folio.estado === 'requiere_reproceso' || folio.estado === 'retenido') {
    return partialThermalResults[folio.estado];
  }
  return folio.estado;
}

export type PrefrioCommandKind =
  | 'agregar_folio'
  | 'retirar_folio'
  | 'confirmar_armado'
  | 'iniciar'
  | 'evento'
  | 'verificar'
  | 'finalizar_parcial'
  | 'confirmar_retiro_parcial';

export type PrefrioQueuedCommand = {
  id: string;
  process_id: string;
  process_code: string;
  kind: PrefrioCommandKind;
  label: string;
  route: string;
  payload: AddPrefrioFolioPayload | PrefrioActionPayload | (PrefrioActionPayload & PrefrioPartialSelection);
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
