import type {
  OperationalManeuverStep,
  OperationalTask,
  OperationalTaskEndpoint,
} from './operationalTasks';
import { operationalTaskDestinationLabel, operationalTaskOriginLabel } from './operationalTasks';

export type OperatorManeuverAction = {
  verb: 'RETIRAR' | 'UBICAR' | 'ENTREGAR';
  primaryLabel: string;
  instruction: string;
  destination: string;
  tone: 'critical' | 'success' | 'info';
};

export type OperatorManeuverSequenceItem = {
  id: string;
  number: number;
  folio: string;
  route: string;
  action: string;
  state: 'complete' | 'current' | 'pending';
};

export function buildOperatorManeuverAction(task: OperationalTask): OperatorManeuverAction {
  const moving = task.estado === 'en_proceso';
  const destination = taskDestinationLabel(task);

  if (!moving) {
    return {
      verb: 'RETIRAR',
      primaryLabel: 'RETIRAR PALLET',
      instruction: `Retira el pallet ${task.folio.numero_folio} desde la posición indicada.`,
      destination,
      tone: 'critical',
    };
  }

  if (task.tipo_movimiento === 'retiro') {
    if (task.tipo_paso_maniobra === 'extraccion_temporal') {
      return {
        verb: 'RETIRAR',
        primaryLabel: 'CONFIRMAR PALLET RETIRADO',
        instruction: 'Conserva el pallet bajo control de esta maniobra hasta su retorno o destino definitivo.',
        destination: 'Bajo maniobra',
        tone: 'critical',
      };
    }

    return {
      verb: 'ENTREGAR',
      primaryLabel: 'CONFIRMAR ENTREGA EN ANDÉN',
      instruction: `Entrega el pallet en ${destination}.`,
      destination,
      tone: 'success',
    };
  }

  return {
    verb: 'UBICAR',
    primaryLabel: 'CONFIRMAR MOVIMIENTO',
    instruction: `Ubica el pallet en ${destination}.`,
    destination,
    tone: 'success',
  };
}

export function buildOperatorManeuverSequence(task: OperationalTask): OperatorManeuverSequenceItem[] {
  const current = task.secuencia_maniobra ?? task.maniobra?.secuencia_actual ?? 1;
  const steps = task.maniobra?.pasos ?? [];

  if (steps.length) {
    return [...steps]
      .sort((left, right) => left.secuencia - right.secuencia)
      .map((step) => sequenceItem(step, current, task));
  }

  const total = task.maniobra?.pasos_totales ?? 1;
  return Array.from({ length: total }, (_, index) => {
    const number = index + 1;
    return {
      id: `${task.id}-paso-${number}`,
      number,
      folio: number === current ? task.folio.numero_folio : 'Paso protegido',
      route: number === current
        ? `${operationalTaskOriginLabel(task)} → ${taskDestinationLabel(task)}`
        : 'El detalle se habilitará al avanzar',
      action: number === current ? buildOperatorManeuverAction(task).primaryLabel : 'Pendiente',
      state: number < current ? 'complete' : number === current ? 'current' : 'pending',
    };
  });
}

function sequenceItem(step: OperationalManeuverStep, current: number, task: OperationalTask): OperatorManeuverSequenceItem {
  const origin = step.id === task.id ? operationalTaskOriginLabel(task)
    : step.tipo_paso === 'retorno_banda' ? 'Pallet bajo custodia' : positionLabel(step.origen);
  const destination = stepDestinationLabel(step);
  return {
    id: step.id,
    number: step.secuencia,
    folio: step.folio?.numero_folio ?? 'Folio protegido',
    route: `${origin} → ${destination}`,
    action: step.instruccion?.trim() || stepAction(step),
    state: step.estado === 'completada' || step.secuencia < current
      ? 'complete'
      : step.secuencia === current
        ? 'current'
        : 'pending',
  };
}

function positionLabel(endpoint: OperationalTaskEndpoint) {
  if (!endpoint) return 'Origen externo';
  if (!endpoint.posicion) return `${endpoint.camara.nombre} · posición por calcular`;
  const position = endpoint.posicion;
  const physical = position.etiqueta
    ?? `B${String(position.banda).padStart(2, '0')}-P${String(position.posicion).padStart(2, '0')}-N${position.nivel}`;
  return `${endpoint.camara.nombre} · ${physical}`;
}

function taskDestinationLabel(task: OperationalTask) {
  if (task.destino_logico?.tipo === 'anden') return task.destino_logico.nombre;
  if (task.tipo_paso_maniobra === 'extraccion_temporal') return 'Bajo maniobra';
  return operationalTaskDestinationLabel(task);
}

function stepDestinationLabel(step: OperationalManeuverStep) {
  if (step.destino_logico?.tipo === 'anden') return step.destino_logico.nombre;
  if (step.tipo_paso === 'extraccion_temporal') return 'Bajo maniobra';
  return positionLabel(step.destino);
}

function stepAction(step: OperationalManeuverStep) {
  if (step.tipo_paso === 'extraccion_temporal') return 'Retirar temporalmente';
  if (step.tipo_paso === 'retorno_banda') return 'Retornar a banda';
  if (step.tipo_paso === 'entrega_anden') return 'Entregar en andén';
  if (step.tipo_movimiento === 'ubicacion_inicial') return 'Ubicar pallet';
  if (step.tipo_movimiento === 'retiro') return 'Retirar pallet';
  return 'Trasladar pallet';
}
