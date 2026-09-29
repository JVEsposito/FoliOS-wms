export type HydroTray = 'pendientes' | 'en_curso' | 'retenidos' | 'historial';
export type HydroDestination = 'camara' | 'proceso';
export type HydroWaterCondition = 'conforme' | 'no_conforme';

export type HydroFilters = {
  buscar: string;
  equipo: string;
  turno: '' | 'A' | 'B';
  destino: '' | HydroDestination;
  desde: string;
  hasta: string;
};

export type HydroSummary = {
  temporada: { id: string; codigo: string; nombre: string } | null;
  pendientes: number;
  en_curso: number;
  retenidos: number;
  completados_hoy: number;
  kilos_en_curso: number;
  duracion_promedio_hoy: number;
  equipos: string[];
};

export type HydroCycle = {
  codigo: string;
  equipo: string;
  turno: 'A' | 'B';
  cantidad_bombas_funcionando: number;
  operador: string;
  cantidad_envases: number;
  kilos_netos: number;
  inicio_at: string;
  termino_at: string | null;
  duracion_minutos: number | null;
  temperatura_inicial_c: number;
  temperatura_objetivo_c: number;
  temperatura_agua_inicial_c: number | null;
  cloro_libre_ppm: number;
  ph_agua: number;
  control_inicial_conforme: boolean;
  condicion_visual_agua: HydroWaterCondition;
  dosificador_operativo: boolean;
  manejo_agua: 'sin_novedad' | 'filtrado' | 'recambio';
  temperatura_c: number | null;
  temperatura_agua_final_c: number | null;
  cloro_libre_final_ppm: number | null;
  ph_agua_final: number | null;
  condicion_visual_agua_final: HydroWaterCondition | null;
  dosificador_operativo_final: boolean | null;
  control_final_conforme: boolean | null;
  motivo_retencion: string | null;
  temperatura_verificacion_c: number | null;
  cloro_libre_verificacion_ppm: number | null;
  ph_agua_verificacion: number | null;
  evaluacion_producto: string | null;
  verificacion_liberacion: string | null;
  liberado_at: string | null;
  liberado_por: string | null;
  destino_salida: HydroDestination | null;
  observacion_inicio: string | null;
  observacion: string | null;
  accion_correctiva: string | null;
};

export type HydroLot = {
  id: string;
  numero_lote: string;
  estado: string;
  cliente: { nombre: string } | null;
  recepcion: { numero_recepcion: string } | null;
  trazabilidad: { csg: string | null; especie: string | null; variedad: string | null; cuartel: string | null };
  envases: { primario: string; cantidad_primarios: number };
  pesos: { kilos_netos_confirmados: number };
  hidrocooler: HydroCycle | null;
  confirmado_at: string | null;
};

export type StartHydroCycle = {
  operacion_id: string;
  equipo: string;
  turno: 'A' | 'B';
  cantidad_bombas_funcionando: number;
  inicio_at: string;
  temperatura_inicial_c: number;
  temperatura_objetivo_c: number;
  temperatura_agua_inicial_c: number | null;
  cloro_libre_ppm: number;
  ph_agua: number;
  control_inicial_conforme: boolean;
  condicion_visual_agua: HydroWaterCondition;
  dosificador_operativo: boolean;
  manejo_agua: 'sin_novedad' | 'filtrado' | 'recambio';
  observacion_inicio: string | null;
};

export type FinishHydroCycle = {
  operacion_id: string;
  termino_at: string;
  temperatura_c: number;
  temperatura_agua_final_c: number | null;
  cloro_libre_final_ppm: number;
  ph_agua_final: number;
  condicion_visual_agua_final: HydroWaterCondition;
  dosificador_operativo_final: boolean;
  control_final_conforme: boolean;
  destino_salida: HydroDestination;
  observacion: string | null;
  accion_correctiva: string | null;
};

export type ReleaseHydroCycle = {
  operacion_id: string;
  temperatura_verificacion_c: number;
  cloro_libre_verificacion_ppm: number;
  ph_agua_verificacion: number;
  control_verificacion_conforme: true;
  evaluacion_producto: string;
  verificacion_liberacion: string;
};
