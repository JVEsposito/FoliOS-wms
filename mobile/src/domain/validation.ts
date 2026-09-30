export type ValidationResult = 'aprobado' | 'observado' | 'rechazado';
export type ValidationAttemptState = 'aceptada' | 'conflicto';
export type ValidationLine = 1 | 2 | 3;
export type ValidationShift = 'A' | 'B';

export type ValidationWorkContext = {
  linea_proceso: ValidationLine;
  turno: ValidationShift;
};

export type ValidationSeason = {
  id: string;
  codigo: string;
  nombre: string;
  fecha_inicio: string | null;
  fecha_fin: string | null;
  activa: boolean;
  version_catalogo: number;
};

export type ValidationArticle = {
  id: string;
  temporada_id: string;
  variedad_validacion_id: string | null;
  especie: string;
  variedad: string;
  calibre: string;
  envase: string;
  codigo_externo: string | null;
  activo: boolean;
};

export type ValidationCategory = {
  id: string;
  temporada_id: string;
  nombre: string;
  codigo_externo: string | null;
  activo: boolean;
};

export type ValidationOrigin = {
  id: string;
  temporada_id: string;
  csg_validacion_id: string | null;
  variedad_ids: string[] | null;
  cliente: string;
  marca: string;
  csg: string;
  predio: string | null;
  codigo_externo: string | null;
  activo: boolean;
};

export type ValidationCombination = {
  id: string;
  articulo_validacion_id: string;
  origen_validacion_id: string;
  codigo_externo: string | null;
};

export type ValidationCatalog = {
  temporada: ValidationSeason;
  categorias: ValidationCategory[];
  articulos: ValidationArticle[];
  origenes: ValidationOrigin[];
  combinaciones: ValidationCombination[];
  tipos_bulto: Array<'pallet' | 'saldo'>;
  resultados: ValidationResult[];
  motivos: string[];
  generado_at: string;
};

export type RegisterValidationPayload = {
  operacion_id: string;
  numero_folio: string;
  tipo_bulto: 'pallet' | 'saldo';
  cantidad_cajas: number;
  linea_proceso: ValidationLine;
  turno: ValidationShift;
  temporada_id: string;
  catalogo_version: number;
  articulo_validacion_id: string;
  origen_validacion_id: string;
  fecha_embalaje: string;
  composicion: Array<{
    origen_validacion_id: string;
    cantidad_cajas: number;
    lote_materia_prima?: string;
    proceso_packing?: string;
  }>;
  categoria_validacion_id: string;
  resultado: ValidationResult;
  motivo?: string;
  observacion?: string;
  generado_dispositivo_at: string;
};

export type ValidationAttempt = {
  id: string;
  operacion_id: string;
  numero_folio: string;
  numero_intento: number;
  tipo_bulto: 'pallet' | 'saldo';
  cantidad_cajas: number;
  linea_proceso: ValidationLine | null;
  turno: ValidationShift | null;
  temporada_id: string;
  articulo_validacion_id: string;
  origen_validacion_id: string;
  categoria_validacion_id: string;
  resultado: ValidationResult;
  estado: ValidationAttemptState;
  motivo: string | null;
  observacion: string | null;
  catalogo: {
    version_dispositivo: number;
    version_servidor: number;
    desactualizado: boolean;
    temporada: { codigo: string; nombre: string } | null;
    articulo: { especie: string; variedad: string; calibre: string; envase: string } | null;
    origen: { cliente: string; marca: string; csg: string; predio: string | null } | null;
    fecha_embalaje: string | null;
    composicion: Array<{
      origen_validacion_id: string;
      combinacion_validacion_id: string;
      csg: string;
      predio: string | null;
      fecha_embalaje: string | null;
      cantidad_cajas: number;
      lote_materia_prima?: string | null;
      proceso_packing?: string | null;
    }> | null;
    categoria: { id: string; nombre: string; codigo_externo: string | null } | null;
  };
  folio: { id: string; numero_folio: string; estado_operacional: string } | null;
  usuario: { id: string; nombre: string };
  dispositivo: { id: string; codigo: string; nombre: string };
  conflicto_con: { id: string; numero_folio: string; numero_intento: number; resultado: ValidationResult } | null;
  generado_dispositivo_at: string;
  recibido_servidor_at: string;
};

export type ValidationSessionSummary = {
  folios_trabajados: number;
  registros_realizados: number;
  aprobados: number;
  observados: number;
  rechazados: number;
  conflictos: number;
};

export type ValidationSessionSnapshot = {
  sesion: {
    id: string;
    iniciada_at: string;
    servidor_at: string;
    usuario: { id: string; nombre: string };
    dispositivo: { id: string; codigo: string; nombre: string };
    temporada: { id: string; codigo: string; nombre: string } | null;
  };
  resumen: ValidationSessionSummary;
  data: ValidationAttempt[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
};

export type ValidationOutboxStatus = 'pendiente' | 'conflicto' | 'error';

export type ValidationOutboxItem = {
  id: string;
  payload: RegisterValidationPayload;
  status: ValidationOutboxStatus;
  attempts: number;
  created_at: string;
  last_attempt_at: string | null;
  message: string | null;
};
