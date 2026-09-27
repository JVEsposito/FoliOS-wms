export type ApiMode = 'demo' | 'connected' | 'unconfigured';
export type AccessMode = 'disponible' | 'edicion' | 'solo_lectura';
export type MovementType =
  | 'ubicacion_inicial'
  | 'reubicacion'
  | 'traslado_entre_camaras'
  | 'retiro'
  | 'reversion';

export type TabletModule =
  | 'demo_administracion'
  | 'operacion'
  | 'recepcion_materiales'
  | 'validacion'
  | 'validacion_mp'
  | 'repaletizaje'
  | 'fruta_proceso'
  | 'prefrio'
  | 'operacion_materiales';

export type UserIdentity = {
  id: string;
  nombre: string;
  email: string;
  rol: string;
  debe_cambiar_password?: boolean;
  ambito_camaras: 'productos' | 'materiales' | 'materia_prima' | 'ambos' | 'ninguno';
  modulos_tablet?: TabletModule[];
  capacidades: UserCapabilities;
};

export type UserCapabilities = {
  modulos_tablet?: TabletModule[];
  ambito_camaras: 'productos' | 'materiales' | 'materia_prima' | 'ambos' | 'ninguno';
  puede_supervisar: boolean;
  puede_operar_productos: boolean;
  puede_operar_materiales: boolean;
  puede_consultar_control_ambiental?: boolean;
  puede_registrar_control_ambiental?: boolean;
  puede_corregir_control_ambiental?: boolean;
  puede_consultar_cargas: boolean;
  puede_consultar_catalogo_cargas: boolean;
  puede_gestionar_cargas: boolean;
  puede_gestionar_andenes: boolean;
  puede_consultar_despachos_materiales: boolean;
  puede_gestionar_despachos_materiales: boolean;
  puede_retirar_materiales: boolean;
  puede_cancelar_despachos_materiales: boolean;
  puede_consultar_kardex_materiales: boolean;
  puede_gestionar_bloqueos_materiales?: boolean;
  puede_consultar_recepciones_materiales?: boolean;
  puede_gestionar_recepciones_materiales?: boolean;
  puede_anular_recepciones_materiales?: boolean;
  puede_imprimir_etiquetas_materiales?: boolean;
  puede_consultar_transformaciones_materiales?: boolean;
  puede_gestionar_transformaciones_materiales?: boolean;
  puede_operar_transformaciones_materiales?: boolean;
  puede_revertir_transformaciones_materiales?: boolean;
  puede_administrar_recetas_materiales?: boolean;
  puede_validar_pallets: boolean;
  puede_validar_mp?: boolean;
  puede_rechazar_pallets: boolean;
  puede_consultar_validaciones_pallet: boolean;
  puede_registrar_repaletizajes?: boolean;
  puede_anular_repaletizajes?: boolean;
  puede_consultar_repaletizajes?: boolean;
  puede_administrar_catalogos_validacion?: boolean;
  puede_consultar_prefrio?: boolean;
  puede_operar_prefrio?: boolean;
  puede_supervisar_prefrio?: boolean;
  puede_administrar_tuneles_prefrio?: boolean;
  puede_consultar_materia_prima?: boolean;
  puede_gestionar_lotes_materia_prima?: boolean;
  puede_supervisar_lotes_materia_prima?: boolean;
  puede_consultar_fruta_proceso?: boolean;
  puede_entregar_fruta_proceso?: boolean;
  puede_corregir_entregas_fruta_proceso?: boolean;
};

export type DeviceIdentity = {
  id: string;
  codigo: string;
  nombre: string;
};

export type AuthSession = {
  token: string;
  token_type: 'Bearer';
  sesion?: {
    id: string;
    iniciada_at: string;
  };
  usuario: UserIdentity;
  dispositivo: DeviceIdentity;
};

export type LoginPayload = {
  email: string;
  password: string;
  codigo_dispositivo: string;
};

export type SagCondition = {
  id: string;
  codigo: string;
  nombre: string;
};

export type EditSession = {
  id: string;
  es_propia: boolean;
  usuario: { id: string; nombre: string };
  dispositivo: { id: string; nombre: string };
  iniciada_at: string;
  ultima_actividad_at: string;
};

export type CameraAccess = {
  modo: AccessMode;
  bloqueada: boolean;
  sesion: EditSession | null;
};

export type Occupancy = {
  ocupadas: number;
  sin_posicion: number;
  total: number;
  porcentaje: number;
};

export type CameraSummary = {
  id: string;
  codigo: string;
  nombre: string;
  tipo: string;
  contenido: 'productos' | 'materiales' | 'materia_prima';
  estado: string;
  version_plano: number;
  sentido_numeracion_bandas: 'izquierda_a_derecha' | 'derecha_a_izquierda';
  ocupacion: Occupancy;
  acceso: CameraAccess;
};

export type Folio = {
  id: string;
  numero_folio: string;
  tipo_bulto: 'pallet' | 'saldo' | 'material';
  estado_operacional: string;
  condicion_termica?:
    | 'pendiente_prefrio'
    | 'en_proceso'
    | 'prefrio_aprobado'
    | 'requiere_reproceso'
    | 'condicion_heredada'
    | 'retenido'
    | null;
  habilitacion_almacenamiento?: 'no_habilitado' | 'habilitado' | 'retenido' | null;
  condicion_sag: SagCondition | null;
  fecha_ingreso: string | null;
  variedad: string | null;
  calibre: string | null;
  marca: string | null;
  exportadora: string | null;
  material: FolioMaterial | null;
  ubicado_at: string | null;
  /** Folio PT de otra temporada que nadie cerró; se regulariza en Cierre de temporada. */
  registro_sin_cerrar?: {
    temporada: { id: string; codigo: string | null; tipo: 'productiva' | 'prueba' | null };
  } | null;
  carga_actual?: {
    id: string;
    codigo: string;
    estado: string;
    prioridad: 'normal' | 'alta' | 'urgente';
    version: number;
  } | null;
};

export type FolioLookup = {
  existe: false;
  numero_folio: string;
} | {
  existe: true;
  id: string;
  numero_folio: string;
  tipo_bulto: 'pallet' | 'saldo' | 'material';
  estado_operacional: string;
  condicion_termica: Folio['condicion_termica'];
  habilitacion_almacenamiento: Folio['habilitacion_almacenamiento'];
  disponible_ubicacion: boolean;
  mensaje_disponibilidad: string;
  origen_sistema: string | null;
  condicion_sag: SagCondition | null;
  variedad: string | null;
  calibre: string | null;
  marca: string | null;
  exportadora: string | null;
  recomendacion_ubicacion?: LocationRecommendation | null;
  ubicacion_actual: {
    camara: { id: string; codigo: string; nombre: string };
    posicion: { id: string; etiqueta: string | null } | null;
  } | null;
  material: {
    item_material_id: string;
    item: { codigo: string; nombre: string };
    cantidad: string;
    lote: string | null;
    proveedor: string | null;
    observacion: string | null;
  } | null;
};

export type AvailableMaterialFolio = {
  numero_folio: string;
  codigo_item: string;
  item: string;
};

export type MaterialSeason = {
  id: string;
  codigo: string;
  nombre: string;
  activa: boolean;
};

export type MaterialClient = {
  id: string;
  temporada: MaterialSeason;
  codigo: string;
  nombre: string;
  activo: boolean;
};

export type MaterialItem = {
  id: string;
  cliente: MaterialClient;
  codigo: string;
  nombre: string;
  categoria: string | null;
  unidad_medida: string;
  activo: boolean;
};

export type MaterialDestination = {
  id: string;
  nombre: string;
  centro_costo: string;
  descripcion: string | null;
  activo: boolean;
};

export type FolioMaterial = {
  item: Omit<MaterialItem, 'unidad_medida' | 'activo'>;
  cantidad_inicial: string;
  cantidad_actual: string;
  cantidad_reservada: string;
  cantidad_disponible: string;
  unidad_medida: string;
  lote: string | null;
  proveedor: string | null;
  observacion: string | null;
};

export type MaterialCatalog = {
  temporada: MaterialSeason | null;
  clientes: MaterialClient[];
  items: MaterialItem[];
  destinos: MaterialDestination[];
};

export type MaterialDispatchItem = {
  detalle_id: string;
  item: Omit<MaterialItem, 'unidad_medida' | 'activo'>;
  cantidad_solicitada: string;
  cantidad_despachada: string;
  cantidad_pendiente: string;
  cantidad_reservada: string;
  unidad_medida: string;
  reservas_fifo?: Array<{ numero_folio: string; cantidad: string; estado: string; orden_fifo: number; reservado_at: string }>;
  sugerencias_fifo: Array<{
    folio_id: string;
    numero_folio: string;
    cantidad: string;
    camara: { id: string; codigo: string; nombre: string } | null;
    posicion: { id: string; etiqueta: string } | null;
  }>;
  retiros: Array<{
    id: string;
    folio: { id: string; numero_folio: string };
    cantidad_anterior: string;
    cantidad_retirada: string;
    cantidad_resultante: string;
    camara: { id: string; codigo: string; nombre: string } | null;
    posicion: { id: string; etiqueta: string } | null;
    usuario: { id: string; nombre: string } | null;
    dispositivo: { id: string; codigo: string; nombre: string } | null;
    siguio_fifo: boolean;
    motivo_excepcion_fifo?: string | null;
    retirado_at: string;
  }>;
};

export type MaterialDispatch = {
  id: string;
  codigo: string;
  origen: 'oficina' | 'tablet';
  modalidad?: 'delegado' | 'directo';
  asignado_a?: { id: number; nombre: string } | null;
  asignado_a_user_id?: number | null;
  estado: 'pendiente' | 'parcial' | 'completado' | 'cancelado';
  destino: { id: string; nombre: string; centro_costo: string };
  observacion: string | null;
  creado_por?: { id: string; nombre: string } | null;
  dispositivo?: { id: string; codigo: string; nombre: string } | null;
  items: MaterialDispatchItem[];
  completado_at?: string | null;
  cancelacion?: {
    motivo: string;
    usuario: { id: string; nombre: string } | null;
    dispositivo: { id: string; codigo: string; nombre: string } | null;
    cancelado_at: string;
  } | null;
  created_at: string;
};

export type MaterialDispatchSummary = Omit<
  MaterialDispatch,
  'cancelacion' | 'creado_por' | 'dispositivo' | 'items'
> & {
  items: Array<Omit<MaterialDispatchItem, 'retiros' | 'sugerencias_fifo'>>;
  cancelado_at?: string | null;
  updated_at: string;
};

export type Dock = {
  id: string;
  codigo: string;
  nombre: string;
  activo: boolean;
};

export type LoadFolioState = 'pendiente' | 'con_incidencia' | 'en_anden';

export type LoadFolio = {
  asignacion_id: string;
  id: string;
  numero_folio: string;
  tipo_bulto: 'pallet' | 'saldo';
  estado_operacional: string;
  estado_carga: LoadFolioState;
  anden: Pick<Dock, 'id' | 'codigo' | 'nombre'> | null;
  asignado_at: string;
  ubicacion: {
    camara: { id: string; codigo: string; nombre: string };
    posicion: {
      id: string;
      banda: number;
      posicion: number;
      nivel: number;
      etiqueta: string | null;
    };
  } | null;
};

export type LoadProgress = {
  porcentaje: number;
  umbral_porcentaje: number;
  cumple_umbral: boolean;
  concentrados: number;
  faltantes: number;
  total: number;
  en_anden: number;
  con_incidencia: number;
  pendientes: number;
  grupo_principal: {
    camara: { id: string; codigo: string; nombre: string };
    nivel: number;
    banda_desde: number;
    banda_hasta: number;
    posicion_desde: number;
    posicion_hasta: number;
  } | null;
};

export type RefrigeratedLoad = {
  id: string;
  codigo: string;
  numero_orden_externa: string | null;
  estado: 'pendiente' | 'en_preparacion' | 'despacho_parcial' | 'en_separacion' | 'separada' | 'separacion_completa';
  prioridad: 'normal' | 'alta' | 'urgente';
  version: number;
  observacion: string | null;
  camara_objetivo: { id: string; codigo: string; nombre: string } | null;
  anden_previsto: Pick<Dock, 'id' | 'codigo' | 'nombre'> | null;
  total_folios: number;
  folios: LoadFolio[];
  progreso: LoadProgress;
  incidencias_abiertas: number;
  publicada_at: string | null;
};

export type ExtractionRouteItem = {
  orden: number | null;
  estado_ruta: 'sugerido' | 'disponible' | 'bloqueado' | 'sin_ubicacion' | 'incidencia';
  asignacion_id: string;
  folio: { id: string; numero_folio: string; tipo_bulto: 'pallet' | 'saldo' };
  ubicacion: {
    camara: { id: string; codigo: string; nombre: string; version_plano: number };
    posicion: {
      id: string;
      banda: number;
      posicion: number;
      nivel: number;
      etiqueta: string | null;
    };
  } | null;
  bloqueadores: Array<{
    folio_id: string;
    numero_folio: string;
    posicion_id: string;
    etiqueta: string | null;
  }>;
};

export type ExtractionPlan = {
  carga_id: string;
  carga_codigo: string;
  generado_at: string;
  resumen: {
    pendientes: number;
    planificables: number;
    bloqueados: number;
    sin_ubicacion: number;
    con_incidencia: number;
  };
  siguiente: ExtractionRouteItem | null;
  items: ExtractionRouteItem[];
};

export type OperationalNotification = {
  id: string;
  tipo: 'carga_publicada' | 'despacho_material_creado' | 'prioridad_carga_cambiada' | 'incidencia_carga_reportada' | 'incidencia_carga_resuelta' | 'recepcion_romana_creada' | 'cierre_temporada_pendiente';
  severidad: 'informativa' | 'advertencia' | 'critica' | 'exito';
  titulo: string;
  mensaje: string;
  carga: {
    id: string;
    codigo: string;
    prioridad: 'normal' | 'alta' | 'urgente';
    estado: string;
  } | null;
  despacho_material: {
    id: string;
    codigo: string;
    estado: MaterialDispatch['estado'];
    destino: { nombre: string; centro_costo: string };
  } | null;
  folio: { id: string; numero_folio: string } | null;
  incidencia_id: string | null;
  datos: Record<string, unknown> | null;
  leida_at: string | null;
  confirmada_at: string | null;
  created_at: string;
  updated_at: string;
};

export type OperationalNotificationFeed = {
  items: OperationalNotification[];
  unread: number;
  syncedAt: string;
};

export type OperationalNotificationSummary = {
  unread: number;
  syncedAt: string;
};

export type ReportLoadIncidentPayload = {
  operacion_id: string;
  tipo: 'caja_aplastada' | 'zuncho_roto' | 'pallet_mojado' | 'pallet_inestable'
    | 'folio_ilegible' | 'diferencia_ubicacion' | 'folio_no_encontrado'
    | 'retencion_calidad' | 'sector_inaccesible' | 'otro';
  descripcion?: string;
  sesion_estiba_id: string;
};

export type SendLoadFolioToDockPayload = {
  operacion_id: string;
  tarea_movimiento_id?: string;
  anden_id: string;
  sesion_estiba_id: string;
  version_camara_conocida: number;
  generado_dispositivo_at: string;
  advertencias_confirmadas?: string[];
};

export type Position = {
  id: string;
  banda: number;
  posicion: number;
  nivel: number;
  etiqueta: string | null;
  estado: string;
  ocupada: boolean;
  reservada?: boolean;
  reserva_operacional?: {
    id: string;
    tarea_movimiento_id: string;
    prioridad: 'normal' | 'alta' | 'urgente' | 'critica' | null;
    responsable: { id: number; nombre: string } | null;
    dispositivo: { id: string; nombre: string } | null;
    vence_at: string;
  } | null;
  folio: Folio | null;
  folios?: Folio[];
};

export type OperationalBand = {
  id: string;
  numero: number;
  usos_permitidos: Array<'transito_pt' | 'inspeccion' | 'retenidos'>;
  modo: 'operativa' | 'bloqueada' | 'en_vaciado';
  motivo_estado: string | null;
  estado: 'libre' | 'parcial' | 'completa' | 'bloqueada' | 'en_vaciado';
  acepta_nuevos_ingresos: boolean;
  capacidad: {
    fisica: number;
    efectiva: number;
    ocupadas: number;
    reservadas?: number;
    disponibles: number;
    porcentaje: number;
  };
  afinidad?: {
    activa: boolean;
    cliente: { valor: string; pallets: number } | null;
    marca: { valor: string; pallets: number } | null;
    formato: { valor: string; pallets: number } | null;
    pallets_completos: number;
    perfiles_diferentes: number;
    perfiles?: Array<{
      cliente: string | null;
      marca: string | null;
      formato: string | null;
      pallets: number;
    }>;
    fuera_alcance: number;
  } | null;
  version: number;
  updated_at: string;
};

export type LocationRecommendationCandidate = {
  orden: number;
  camara: {
    id: string;
    codigo: string;
    nombre: string;
    version_plano: number;
  };
  banda: {
    id: string;
    numero: number;
    capacidad_disponible: number;
    version: number;
  };
  posicion: {
    id: string;
    etiqueta: string | null;
    banda: number;
    posicion: number;
    nivel: number;
  };
  afinidad: {
    nivel: 'cliente_marca_formato' | 'cliente_marca' | 'cliente' | 'banda_libre' | 'sin_afinidad';
    puntaje: number;
    coincidencias: {
      cliente_marca_formato: number;
      cliente_marca: number;
      cliente: number;
    };
    mezclaria_clientes: boolean;
    motivo: string;
  };
  en_camara_consultada: boolean;
};

export type LocationRecommendation = {
  aplica: boolean;
  disponible: boolean;
  uso: 'transito_pt' | null;
  motivo: string;
  criterio: {
    jerarquia: string[];
    solo_pallets_completos: boolean;
    afinidad_dinamica: boolean;
    genera_movimiento: boolean;
    reserva_destino: boolean;
    excluye_destinos_reservados?: boolean;
  };
  mejor: LocationRecommendationCandidate | null;
  alternativas: LocationRecommendationCandidate[];
};

export type CameraPlan = CameraSummary & {
  bandas_operacionales?: OperationalBand[];
  folios_sin_posicion: Folio[];
  posiciones: Position[];
};

export type MovementEnd = {
  camara: { id: string; codigo: string; nombre: string | null };
  posicion: {
    id: string;
    banda: number;
    posicion: number;
    nivel: number;
    etiqueta: string | null;
  };
  version_anterior: number;
  version_resultante: number;
};

export type Movement = {
  id: string;
  operacion_id: string;
  tipo_movimiento: MovementType;
  folio: { id: string; numero_folio: string; tipo_bulto: string };
  origen: MovementEnd | null;
  destino: MovementEnd | null;
  usuario: { id: string; nombre: string };
  generado_dispositivo_at: string;
  recibido_servidor_at: string;
  created_at: string;
};

export type OpenedSession = {
  id: string;
  camara_id: string;
  estado: string;
  version_inicial: number;
  version_final: number | null;
  iniciada_at: string;
  ultima_actividad_at: string;
  cerrada_at: string | null;
  motivo_cierre: string | null;
  usuario: { id: string; nombre: string };
  dispositivo: { id: string; nombre: string };
};

export type LocatePayload = {
  operacion_id: string;
  tarea_movimiento_id?: string;
  numero_folio: string;
  tipo_bulto: 'pallet' | 'saldo' | 'material';
  camara_destino_id: string;
  posicion_destino_id?: string;
  sesion_destino_id: string;
  version_destino_conocida: number;
  generado_dispositivo_at: string;
  advertencias_confirmadas?: string[];
  datos_folio?: {
    condicion_sag_id?: string;
    variedad?: string;
    calibre?: string;
    marca?: string;
    exportadora?: string;
  };
};

export type CreateMaterialDispatchPayload = {
  operacion_id: string;
  destino_material_id: string;
  observacion?: string;
  items: Array<{ item_material_id: string; cantidad: number }>;
};

export type WithdrawMaterialPayload = {
  operacion_id: string;
  motivo_excepcion_fifo?: string;
  retiros: Array<{ folio_id: string; cantidad: number; sesion_estiba_id: string }>;
};

export type MovePayload = {
  operacion_id: string;
  tarea_movimiento_id?: string;
  folio_id: string;
  posicion_destino_id: string;
  sesion_origen_id: string;
  sesion_destino_id: string;
  version_origen_conocida: number;
  version_destino_conocida: number;
  generado_dispositivo_at: string;
  advertencias_confirmadas?: string[];
};

export type ApiList<T> = { data: T[] };
export type ApiItem<T> = { data: T };
