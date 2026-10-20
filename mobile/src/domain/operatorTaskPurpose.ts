import type { OperationalTask } from './operationalTasks';

const PURPOSES: Record<string, string> = {
  recepcion_tunel: 'Recepción desde prefrío',
  recepcion_repaletizaje: 'Recepción desde repaletizaje',
  almacenamiento_pallet: 'Almacenamiento de producto',
  concentracion_carga: 'Separación y preparación de carga',
  despacho_directo: 'Despacho de carga',
  preparacion_inspeccion: 'Preparación de inspección SAG / USDA',
  segregacion_retenido: 'Separación de producto retenido',
  movimiento_oportunidad: 'Mejora de ubicación',
  reordenamiento_camara: 'Reordenamiento de cámara',
  desocupacion_camara: 'Desocupación de cámara',
  evacuacion_emergencia: 'Evacuación de emergencia',
  correccion_discrepancia: 'Corrección de una incidencia',
};

/** El propósito pertenece al objetivo principal; el paso puede mover otro pallet para despejarlo. */
export function operatorTaskPurpose(task: OperationalTask) {
  const objectives = task.maniobra?.objetivos ?? [];
  const primary = objectives.find((objective) => objective.es_principal) ?? task.plan;
  const label = PURPOSES[primary.tipo] ?? 'Movimiento operacional';
  const goal = primary.titulo?.trim() || task.maniobra?.titulo || label;
  let step: string;
  if (task.tipo_paso_maniobra === 'extraccion_temporal') step = 'Retiro temporal para despejar el acceso';
  else if (task.tipo_paso_maniobra === 'retorno_banda') step = 'Retorno del pallet retirado temporalmente';
  else if (task.tipo_paso_maniobra === 'entrega_anden') step = 'Entrega del pallet en andén';
  else if (task.tipo_movimiento === 'ubicacion_inicial') step = 'Ubicación inicial del pallet';
  else if (task.tipo_movimiento === 'retiro') step = 'Retiro del pallet';
  else step = 'Traslado del pallet a su destino';

  return {
    label,
    goal,
    reason: primary.motivo?.trim() || (primary === task.plan ? task.plan.motivo?.trim() : '') || null,
    step,
    otherGoals: [...new Set(objectives.filter((objective) => objective.id !== primary.id)
      .map((objective) => objective.titulo).filter(Boolean))],
  };
}
