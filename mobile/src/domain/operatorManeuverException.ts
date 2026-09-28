import type { ManeuverDiscrepancyType, OperationalTask } from './operationalTasks';

export type OperatorExceptionKind = 'mismatch' | 'impossible';

export type OperatorExceptionOption = {
  type: ManeuverDiscrepancyType;
  label: string;
  description: string;
};

const MISMATCH_OPTIONS: readonly OperatorExceptionOption[] = [
  {
    type: 'pallet_no_encontrado',
    label: 'Pallet no encontrado',
    description: 'No se encontró físicamente el pallet que indica la tarea.',
  },
  {
    type: 'pallet_no_coincide',
    label: 'Pallet distinto',
    description: 'El folio físico no corresponde al indicado por el WMS.',
  },
  {
    type: 'posicion_no_coincide',
    label: 'Posición distinta',
    description: 'La posición física no corresponde al plano informado.',
  },
  {
    type: 'posicion_vacia',
    label: 'Posición vacía',
    description: 'No hay un pallet en la posición indicada.',
  },
  {
    type: 'otra',
    label: 'Otra diferencia',
    description: 'La realidad física difiere por otro motivo.',
  },
];

const IMPOSSIBLE_OPTIONS: readonly OperatorExceptionOption[] = [
  {
    type: 'obstaculo',
    label: 'Obstáculo',
    description: 'El acceso o la ruta física están bloqueados.',
  },
  {
    type: 'pallet_no_movible',
    label: 'Pallet no movible',
    description: 'El estado del pallet impide moverlo de forma segura.',
  },
  {
    type: 'otra',
    label: 'Otro impedimento',
    description: 'Existe una condición física distinta que impide continuar.',
  },
];

export function operatorExceptionOptions(kind: OperatorExceptionKind): readonly OperatorExceptionOption[] {
  return kind === 'mismatch' ? MISMATCH_OPTIONS : IMPOSSIBLE_OPTIONS;
}

export function isLocationReconciliation(task: OperationalTask): boolean {
  return task.contexto?.origen_logico === 'ubicacion_historica_por_verificar';
}

export function operatorExceptionTitle(kind: OperatorExceptionKind) {
  return kind === 'mismatch' ? 'NO COINCIDE' : 'NO ES POSIBLE';
}

export function operatorExceptionPrompt(kind: OperatorExceptionKind) {
  return kind === 'mismatch'
    ? 'Indica qué encontraste físicamente.'
    : 'Indica qué impide continuar la maniobra.';
}

export function validateOperatorException(
  type: ManeuverDiscrepancyType | null,
  detail: string,
): string | null {
  if (!type) return 'Selecciona un motivo antes de continuar.';

  const normalized = detail.trim();
  if (normalized.length < 3) return 'Describe brevemente lo encontrado en terreno.';
  if (normalized.length > 500) return 'La observación no puede superar los 500 caracteres.';

  return null;
}
