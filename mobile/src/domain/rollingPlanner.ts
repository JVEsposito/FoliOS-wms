import type { CameraPlan, Position } from './estiba';
import type {
  OperationalFrontierProposal,
  OperationalPhysicalFrontierSnapshot,
  OperationalSnapshot,
  OperationalTask,
  OperationalTaskPriority,
} from './operationalTasks';

export type RollingCandidate = {
  taskId: string;
  cameraId: string;
  position: Position;
  score: number;
  reason: string;
};

export type RollingFrontier = {
  proposals: OperationalFrontierProposal[];
  candidates: RollingCandidate[];
  unresolvedTaskIds: string[];
};

type ConcentrationPoint = {
  banda: number;
  posicion: number;
  nivel: number;
};

const PRIORITY_WEIGHT: Record<OperationalTaskPriority, number> = {
  critica: 4,
  urgente: 3,
  alta: 2,
  normal: 1,
};

/**
 * Calcula una frontera corta y determinista sobre un snapshot ya versionado.
 * No reserva ni muta estado: el servidor sigue siendo la autoridad y puede
 * aceptar/rechazar cada propuesta al materializar la frontera.
 */
export function calculateRollingFrontier(
  tasks: OperationalTask[],
  snapshot: OperationalSnapshot | OperationalPhysicalFrontierSnapshot,
  cameraPlans: CameraPlan[],
): RollingFrontier {
  const limit = Math.max(1, snapshot.planner.frontier_max);
  const eligible = tasks
    .filter((task) => task.estado === 'asumida' && !task.punto_no_retorno)
    .filter((task) => task.tipo_movimiento !== 'retiro')
    .filter((task) => task.reserva?.tipo_compromiso !== 'fisica')
    .filter((task) => snapshot.tareas.find((item) => item.id === task.id)?.materializable !== false)
    .sort(compareTasks);

  const usedDestinations = new Set<string>();
  const protectedOrigins = new Set(
    eligible
      .map((task) => task.origen?.posicion?.id)
      .filter((value): value is string => Boolean(value)),
  );
  const proposals: OperationalFrontierProposal[] = [];
  const candidates: RollingCandidate[] = [];
  const unresolvedTaskIds: string[] = [];

  for (const task of eligible) {
    if (proposals.length >= limit) break;

    const candidate = bestCandidate(
      task,
      cameraPlans,
      usedDestinations,
      protectedOrigins,
      snapshot.planner.camara_preferente_despacho_id,
    );
    const taskSnapshot = snapshot.tareas.find((item) => item.id === task.id);
    const candidatePlan = candidate
      ? cameraPlans.find((plan) => plan.id === candidate.cameraId)
      : null;
    if (!candidate || !taskSnapshot || !candidatePlan) {
      unresolvedTaskIds.push(task.id);
      continue;
    }

    usedDestinations.add(candidate.position.id);
    candidates.push(candidate);
    proposals.push({
      tarea_id: task.id,
      posicion_destino_id: candidate.position.id,
      tarea_version: taskSnapshot.version,
      plan_version: taskSnapshot.plan_version ?? planVersion(snapshot),
      version_camara_conocida: candidatePlan.version_plano,
      score: candidate.score,
      motivo: candidate.reason,
    });
  }

  return { proposals, candidates, unresolvedTaskIds };
}

function planVersion(
  snapshot: OperationalSnapshot | OperationalPhysicalFrontierSnapshot,
) {
  return 'plan' in snapshot ? snapshot.plan.version : 0;
}

export function bestCandidate(
  task: OperationalTask,
  cameraPlans: CameraPlan[],
  usedDestinations: ReadonlySet<string> = new Set(),
  protectedOrigins: ReadonlySet<string> = new Set(),
  preferredDispatchCameraId: string | null = null,
): RollingCandidate | null {
  const candidates: Array<RollingCandidate & {
    affinityRank: number;
    mixesClients: boolean;
    preferred: boolean;
  }> = [];
  const initialWithoutTarget = task.tipo_movimiento === 'ubicacion_inicial'
    && !task.destino?.camara.id;

  for (const plan of cameraPlans) {
    if (plan.contenido !== 'productos' || plan.estado !== 'activa') continue;
    if (!cameraAllowedForTask(task, plan.id)) continue;

    for (const position of plan.posiciones) {
      if (!positionAllowed(task, plan, position, usedDestinations, protectedOrigins)) continue;
      const scoring = scoreCandidate(task, plan, position);
      candidates.push({
        taskId: task.id,
        cameraId: plan.id,
        position,
        score: scoring.score,
        reason: scoring.reason,
        affinityRank: initialWithoutTarget ? affinityRank(task, plan, position) : 0,
        mixesClients: initialWithoutTarget && mixesClients(task, plan, position),
        preferred: initialWithoutTarget && plan.id === preferredDispatchCameraId,
      });
    }
  }

  candidates.sort((left, right) => (
    left.affinityRank - right.affinityRank
    || Number(left.mixesClients) - Number(right.mixesClients)
    || Number(right.preferred) - Number(left.preferred)
    || right.score - left.score
    || left.position.posicion - right.position.posicion
    || left.position.banda - right.position.banda
    || left.position.nivel - right.position.nivel
    || left.position.id.localeCompare(right.position.id)
  ));

  const best = candidates[0];
  if (!best) return null;
  const { affinityRank: _affinityRank, mixesClients: _mixesClients, preferred, ...candidate } = best;
  if (preferred) candidate.reason += '; cámara preferente para despacho entre bandas de igual afinidad';
  return candidate;
}

function mixesClients(task: OperationalTask, plan: CameraPlan, position: Position) {
  const affinity = plan.bandas_operacionales?.find((band) => band.numero === position.banda)?.afinidad;
  if (!affinity?.pallets_completos) return false;
  const client = taskContext(task, ['cliente', 'cliente_codigo', 'cliente_nombre']);
  if (affinity.perfiles) {
    return affinity.perfiles.some((profile) => !client || !sameText(client, profile.cliente));
  }
  return !client || !sameText(client, affinity.cliente?.valor)
    || (affinity.cliente?.pallets ?? 0) < affinity.pallets_completos;
}

function affinityRank(task: OperationalTask, plan: CameraPlan, position: Position) {
  const band = plan.bandas_operacionales?.find((item) => item.numero === position.banda);
  const affinity = band?.afinidad;
  if (!affinity) return band?.capacidad.ocupadas === 0 ? 3 : 4;
  if (affinity.pallets_completos === 0) return 3;

  const client = taskContext(task, ['cliente', 'cliente_codigo', 'cliente_nombre']);
  const brand = taskContext(task, ['marca']);
  const format = taskContext(task, ['formato', 'tipo_formato']);
  if (affinity.perfiles) {
    const byClient = affinity.perfiles.filter((profile) => client && sameText(client, profile.cliente));
    if (byClient.length === 0) return 4;
    const byBrand = byClient.filter((profile) => brand && sameText(brand, profile.marca));
    if (byBrand.length === 0) return 2;
    return byBrand.some((profile) => format && sameText(format, profile.formato)) ? 0 : 1;
  }

  // Snapshot antiguo: el dominante puede no representar una banda mixta.
  // En caso de duda se conserva la clasificación conservadora.
  if (!client || !sameText(client, affinity.cliente?.valor)) return 4;

  if (!brand || !sameText(brand, affinity.marca?.valor)) return 2;

  return format && sameText(format, affinity.formato?.valor) ? 0 : 1;
}

function compareTasks(left: OperationalTask, right: OperationalTask) {
  return PRIORITY_WEIGHT[right.prioridad] - PRIORITY_WEIGHT[left.prioridad]
    || maneuverValue(right) - maneuverValue(left)
    || left.secuencia - right.secuencia
    || left.id.localeCompare(right.id);
}

function maneuverValue(task: OperationalTask) {
  if (!task.maniobra) return 0;
  return task.maniobra.beneficio_estimado
    - task.maniobra.costo_movimientos * 100
    - task.maniobra.riesgo_operacional;
}

function cameraAllowedForTask(task: OperationalTask, cameraId: string) {
  const returnCamera = taskContext(task, ['camara_retorno_id']);
  if (task.tipo_paso_maniobra === 'retorno_banda') {
    return Boolean(returnCamera && returnCamera === cameraId);
  }
  const explicitDestinationCamera = task.destino?.camara.id;
  if (explicitDestinationCamera && explicitDestinationCamera !== cameraId) return false;

  const originCamera = task.origen?.camara.id;
  if (['despeje_salida_directa', 'despeje_concentracion'].includes(
    String(task.contexto?.tipo_decision ?? ''),
  )) {
    return Boolean(originCamera);
  }
  if (task.tipo_movimiento === 'reubicacion') return Boolean(originCamera && originCamera === cameraId);
  if (task.tipo_movimiento === 'traslado_entre_camaras') return Boolean(originCamera && originCamera !== cameraId);
  if (task.tipo_movimiento === 'ubicacion_inicial') return true;
  return false;
}

function positionAllowed(
  task: OperationalTask,
  plan: CameraPlan,
  position: Position,
  usedDestinations: ReadonlySet<string>,
  protectedOrigins: ReadonlySet<string>,
) {
  if (position.estado !== 'activa' || position.ocupada || position.reservada) return false;
  if (usedDestinations.has(position.id)) return false;
  if (protectedOrigins.has(position.id) && position.id !== task.origen?.posicion?.id) return false;
  if (position.id === task.origen?.posicion?.id) return false;

  const exactDestination = task.destino?.posicion?.id;
  if (exactDestination && exactDestination !== position.id) return false;

  const band = plan.bandas_operacionales?.find((item) => item.numero === position.banda);
  if (!band || !band.acepta_nuevos_ingresos) return false;
  if (!band.usos_permitidos.includes('transito_pt')) return false;
  if (band.modo !== 'operativa' || band.estado === 'bloqueada' || band.estado === 'en_vaciado') return false;

  if (task.contexto?.tipo_decision === 'concentrar_carga') {
    const targetCamera = taskContext(task, ['camara_objetivo_id']);
    if (targetCamera && targetCamera !== plan.id) return false;

    const points = concentrationPoints(task);
    if (points.length > 0 && !points.some((point) => areConcentrationNeighbors(point, position))) {
      return false;
    }
  }
  if (task.tipo_paso_maniobra === 'retorno_banda') {
    const returnBand = taskContextNumber(task, 'banda_retorno');
    const returnLevel = taskContextNumber(task, 'nivel_retorno');
    const resultingDepth = taskContextNumber(task, 'profundidad_resultante');
    if (returnBand !== null && position.banda !== returnBand) return false;
    if (returnLevel !== null && position.nivel !== returnLevel) return false;
    if (resultingDepth !== null && position.posicion !== resultingDepth) return false;
  }

  return true;
}

function scoreCandidate(task: OperationalTask, plan: CameraPlan, position: Position) {
  const band = plan.bandas_operacionales?.find((item) => item.numero === position.banda);
  let score = 0;
  const reasons: string[] = [];

  if (task.destino?.posicion?.id === position.id) {
    score += 100_000;
    reasons.push('mantiene el destino ya propuesto');
  }
  if (task.destino?.camara.id === plan.id) {
    score += 5_000;
    reasons.push('respeta la cámara objetivo');
  }

  if (task.contexto?.tipo_decision === 'concentrar_carga') {
    const connected = concentrationPoints(task)
      .filter((point) => areConcentrationNeighbors(point, position))
      .length;
    if (connected > 0) {
      score += connected * 10_000;
      reasons.push(`extiende el grupo principal por ${connected} contacto${connected === 1 ? '' : 's'}`);
    } else {
      score += 2_000;
      reasons.push('establece el primer punto en la cámara objetivo');
    }
  }

  const client = taskContext(task, ['cliente', 'cliente_codigo', 'cliente_nombre']);
  const brand = taskContext(task, ['marca']);
  const format = taskContext(task, ['formato', 'tipo_formato']);
  if (band?.afinidad?.activa) {
    const sameClient = Boolean(client && sameText(client, band.afinidad.cliente?.valor));
    const sameBrand = Boolean(sameClient && brand && sameText(brand, band.afinidad.marca?.valor));
    if (sameClient) {
      score += 900;
      reasons.push('coincide cliente');
    }
    if (sameBrand) {
      score += 450;
      reasons.push('coincide marca');
    }
    if (sameBrand && format && sameText(format, band.afinidad.formato?.valor)) {
      score += 225;
      reasons.push('coincide formato');
    }
    if (band.afinidad.perfiles_diferentes === 0 && band.afinidad.pallets_completos === 0) {
      score += 120;
      reasons.push('banda libre para nueva afinidad');
    }
  }

  // P01 es el fondo. Preferir la menor profundidad libre vuelve a compactar
  // la banda después de retirar el pallet objetivo y evita huecos interiores.
  score += Math.max(0, 1_000 - position.posicion * 10);
  if (task.tipo_paso_maniobra === 'retorno_banda') {
    reasons.push('compacta nuevamente la banda desde el fondo');
  }
  score += Math.max(0, 10 - position.nivel);
  score += Math.min(100, band?.capacidad.disponibles ?? 0);

  if (!reasons.length) reasons.push('posición libre y compatible; prioriza llenado desde el fondo');
  return { score, reason: reasons.join('; ') };
}

function concentrationPoints(task: OperationalTask): ConcentrationPoint[] {
  const raw = task.contexto?.concentracion_puntos;
  if (!Array.isArray(raw)) return [];

  return raw.filter((item): item is ConcentrationPoint => (
    typeof item === 'object'
    && item !== null
    && typeof (item as ConcentrationPoint).banda === 'number'
    && typeof (item as ConcentrationPoint).posicion === 'number'
    && typeof (item as ConcentrationPoint).nivel === 'number'
  ));
}

function areConcentrationNeighbors(point: ConcentrationPoint, position: Position) {
  if (point.nivel !== position.nivel) return false;
  const bandDistance = Math.abs(point.banda - position.banda);
  const positionDistance = Math.abs(point.posicion - position.posicion);

  return (bandDistance === 0 && positionDistance === 1)
    || (bandDistance === 1 && positionDistance <= 1);
}

function taskContext(task: OperationalTask, keys: string[]) {
  for (const key of keys) {
    const value = task.contexto?.[key];
    if (typeof value === 'string' && value.trim()) return value.trim();
  }
  return null;
}

function taskContextNumber(task: OperationalTask, key: string) {
  const value = task.contexto?.[key];
  return typeof value === 'number' ? value : null;
}

function sameText(left: string, right?: string | null) {
  if (!right) return false;
  return normalize(left) === normalize(right);
}

function normalize(value: string) {
  return value.trim().toLocaleUpperCase('es-CL');
}
