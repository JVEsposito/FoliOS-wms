import type { CameraPlan, Position } from './estiba';
import type {
  OperationalFrontierProposal,
  OperationalPhysicalFrontierSnapshot,
  OperationalSnapshot,
  OperationalTask,
  OperationalTaskPriority,
  OperationalLoadStaging,
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
  anchorTaskId: string | null = null,
): RollingFrontier {
  const limit = Math.max(1, snapshot.planner.frontier_max);
  // El pallet en el que trabaja el camarero se calcula primero: su destino nunca depende de
  // la propuesta de otro pallet que el servidor podría rechazar en la misma frontera.
  const eligible = tasks
    .filter((task) => task.estado === 'asumida' && !task.punto_no_retorno)
    .filter((task) => task.tipo_movimiento !== 'retiro')
    .filter((task) => task.reserva?.tipo_compromiso !== 'fisica')
    .filter((task) => snapshot.tareas.find((item) => item.id === task.id)?.materializable !== false)
    .sort((left, right) => Number(right.id === anchorTaskId) - Number(left.id === anchorTaskId) || compareTasks(left, right));

  // Copia solo los contenedores que cambian en la simulación; el snapshot recibido sigue intacto.
  const virtualPlans = cameraPlans.map((plan) => ({ ...plan,
    bandas_operacionales: plan.bandas_operacionales?.map((band) => ({ ...band, capacidad: { ...band.capacidad },
      afinidad: band.afinidad ? { ...band.afinidad } : band.afinidad })),
  }));
  const usedDestinations = new Set<string>();
  const protectedOrigins = new Set(
    eligible
      .map((task) => task.origen?.posicion?.id)
      .filter((value): value is string => Boolean(value)),
  );
  const proposals: OperationalFrontierProposal[] = [];
  const candidates: RollingCandidate[] = [];
  const unresolvedTaskIds: string[] = [];
  const virtualLoadTargets = new Map<string, OperationalLoadStaging>();

  for (const original of eligible) {
    if (proposals.length >= limit) break;
    const taskSnapshot = snapshot.tareas.find((item) => item.id === original.id);
    let task = taskWithCurrentLoadTarget(original, taskSnapshot);
    const acopio = loadStaging(task);
    const virtualTarget = acopio ? virtualLoadTargets.get(acopio.carga_id) : null;
    if (virtualTarget) task = withLoadTarget(task, virtualTarget);

    const candidate = bestCandidate(
      task,
      virtualPlans,
      usedDestinations,
      protectedOrigins,
      snapshot.planner.camara_preferente_despacho_id,
    );
    const candidatePlan = candidate
      ? cameraPlans.find((plan) => plan.id === candidate.cameraId)
      : null;
    if (!candidate || !taskSnapshot || !candidatePlan) {
      unresolvedTaskIds.push(task.id);
      continue;
    }

    usedDestinations.add(candidate.position.id);
    candidates.push(candidate);
    commitVirtualArrival(task, candidate, virtualPlans);
    const loadTarget = loadStaging(task);
    if (loadTarget) virtualLoadTargets.set(loadTarget.carga_id, {
      ...loadTarget,
      camara_objetivo_id: candidate.cameraId,
      puntos: [...loadTarget.puntos, {
        banda: candidate.position.banda, posicion: candidate.position.posicion, nivel: candidate.position.nivel,
      }],
    });
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

export function loadStaging(task: OperationalTask): OperationalLoadStaging | null {
  const value = task.contexto?.acopio_carga;
  if (!value || typeof value !== 'object' || !('carga_id' in value)) return null;
  return value as OperationalLoadStaging;
}

function withLoadTarget(task: OperationalTask, acopio: OperationalLoadStaging | null): OperationalTask {
  return { ...task,
    contexto: { ...task.contexto, acopio_carga: acopio },
    destino: acopio?.camara_objetivo_id ? {
      camara: { id: acopio.camara_objetivo_id, nombre: task.destino?.camara.nombre ?? 'Acopio de carga' },
      posicion: null,
    } : null,
  };
}

export function taskWithCurrentLoadTarget(task: OperationalTask, snapshotTask?: { acopio_carga?: OperationalLoadStaging | null }) {
  if (!snapshotTask || !Object.prototype.hasOwnProperty.call(snapshotTask, 'acopio_carga')) return task;
  if (!snapshotTask.acopio_carga && !loadStaging(task)) return task;
  return withLoadTarget(task, snapshotTask.acopio_carga ?? null);
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
  // Una propuesta anterior sin reserva física no fija el destino del despeje.
  if (canRecalculateClearance(task)) task = { ...task, destino: null };
  const candidates: Array<RollingCandidate & {
    affinityRank: number;
    mixesClients: boolean;
    preferred: boolean;
    localClearance: boolean;
    outsideSector: boolean;
  }> = [];
  const initialWithoutTarget = task.tipo_movimiento === 'ubicacion_inicial'
    && !task.destino?.camara.id;
  const rankByAffinity = (task.tipo_movimiento === 'ubicacion_inicial' || isClearanceTask(task) || Boolean(loadStaging(task)))
    && !task.destino?.posicion?.id;

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
        affinityRank: rankByAffinity ? affinityRank(task, plan, position) : 0,
        mixesClients: rankByAffinity && mixesClients(task, plan, position),
        preferred: initialWithoutTarget && plan.id === preferredDispatchCameraId,
        localClearance: isClearanceTask(task) && plan.id === task.origen?.camara.id,
        outsideSector: outsideLoadSector(task, position),
      });
    }
  }

  candidates.sort((left, right) => (
    Number(right.localClearance) - Number(left.localClearance)
    || Number(left.outsideSector) - Number(right.outsideSector)
    || left.affinityRank - right.affinityRank
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
  const { affinityRank: _affinityRank, mixesClients: _mixesClients, localClearance, preferred, outsideSector: _outsideSector, ...candidate } = best;
  if (localClearance) candidate.reason += '; despeje dentro de la cámara de origen sin traslado adicional';
  if (preferred) candidate.reason += '; cámara preferente para despacho entre bandas de igual afinidad';
  return candidate;
}

type AffinityProfile = { cliente: string | null; marca: string | null; formato: string | null; pallets: number };

function taskProfile(task: OperationalTask): Omit<AffinityProfile, 'pallets'> {
  return {
    cliente: task.folio?.exportadora ?? taskContext(task, ['cliente', 'cliente_codigo', 'cliente_nombre']),
    marca: task.folio?.marca ?? taskContext(task, ['marca']),
    // Un envase explícitamente ausente no se sustituye por «granel» del proceso antiguo.
    formato: task.folio && Object.prototype.hasOwnProperty.call(task.folio, 'envase') ? task.folio.envase ?? null : taskContext(task, ['formato', 'tipo_formato']),
  };
}

function bandProfiles(plan: CameraPlan, position: Position): AffinityProfile[] {
  const band = plan.bandas_operacionales?.find((item) => item.numero === position.banda);
  const affinity = band?.afinidad;
  const physicalCount = affinity?.pallets_completos ?? band?.capacidad.ocupadas ?? 0;
  const physical = affinity?.perfiles?.length ? affinity.perfiles : physicalCount > 0 ? [{
    cliente: affinity?.cliente?.valor ?? null, marca: affinity?.marca?.valor ?? null,
    formato: affinity?.formato?.valor ?? null, pallets: physicalCount,
  }] : [];
  return [...physical, ...(affinity?.entrantes?.perfiles ?? [])];
}

function matches(profile: AffinityProfile, target: Omit<AffinityProfile, 'pallets'>, fields: Array<'cliente' | 'marca' | 'formato'>) {
  return fields.every((field) => target[field] && sameText(target[field]!, profile[field]));
}

function mixesClients(task: OperationalTask, plan: CameraPlan, position: Position) {
  const target = taskProfile(task);
  return bandProfiles(plan, position).some((profile) => !matches(profile, target, ['cliente']));
}

function affinityRank(task: OperationalTask, plan: CameraPlan, position: Position) {
  const profiles = bandProfiles(plan, position);
  if (!profiles.length) return 1;
  const target = taskProfile(task);
  if (profiles.every((profile) => matches(profile, target, ['cliente', 'marca', 'formato']))) return 0;
  if (profiles.some((profile) => matches(profile, target, ['cliente', 'marca', 'formato']))) return 2;
  if (profiles.some((profile) => matches(profile, target, ['cliente', 'marca']))) return 3;
  return profiles.some((profile) => matches(profile, target, ['cliente'])) ? 4 : 5;
}

function commitVirtualArrival(task: OperationalTask, candidate: RollingCandidate, plans: CameraPlan[]) {
  const band = plans.find((plan) => plan.id === candidate.cameraId)?.bandas_operacionales
    ?.find((item) => item.numero === candidate.position.banda);
  if (!band) return;
  const incoming = band.afinidad?.entrantes;
  band.afinidad = { activa: false, cliente: null, marca: null, formato: null,
    pallets_completos: 0, perfiles_diferentes: 0, perfiles: [], fuera_alcance: 0,
    ...band.afinidad, entrantes: {
      pallets_completos: (incoming?.pallets_completos ?? 0) + 1,
      perfiles: [...(incoming?.perfiles ?? []), { ...taskProfile(task), pallets: 1 }],
    } };
  band.capacidad.disponibles = Math.max(0, band.capacidad.disponibles - 1);
  if (band.capacidad.disponibles === 0) band.acepta_nuevos_ingresos = false;
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

export function isClearanceTask(task: OperationalTask) {
  return ['despeje_salida_directa', 'despeje_concentracion'].includes(String(task.contexto?.tipo_decision ?? ''));
}

export function canRecalculateClearance(task: OperationalTask) {
  return isClearanceTask(task) && !task.punto_no_retorno && task.estado !== 'en_proceso'
    && task.reserva?.tipo_compromiso !== 'fisica'
    && !task.reserva?.destino_reservado && !task.maniobra?.custodia_temporal_activa
    && task.contexto?.destino_precalculado_inmutable !== true
    && !loadStaging(task);
}

function cameraAllowedForTask(task: OperationalTask, cameraId: string) {
  const acopio = loadStaging(task);
  if (acopio?.despacho_directo) return false;
  if (acopio?.camara_objetivo_id && acopio.camara_objetivo_id !== cameraId) return false;
  const returnCamera = taskContext(task, ['camara_retorno_id']);
  if (task.tipo_paso_maniobra === 'retorno_banda') {
    return Boolean(returnCamera && returnCamera === cameraId);
  }
  const explicitDestinationCamera = task.destino?.camara.id;
  if (explicitDestinationCamera && explicitDestinationCamera !== cameraId) return false;

  const originCamera = task.origen?.camara.id;
  if (isClearanceTask(task)) {
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
  if (position.estado !== 'activa' || position.ocupada || position.reservada || position.reservada_sag) return false;
  if (plan.protecciones_bandas?.some((lock) => lock.banda === position.banda
    && lock.nivel === position.nivel && lock.maniobra_id !== task.maniobra?.id)) return false;
  if (usedDestinations.has(position.id)) return false;
  if (protectedOrigins.has(position.id) && position.id !== task.origen?.posicion?.id) return false;
  if (position.id === task.origen?.posicion?.id) return false;
  // Las mismas restricciones físicas rigen recepción, concentración y despeje.
  if (plan.posiciones.some((front) => front.banda === position.banda && front.nivel === position.nivel
    && front.posicion > position.posicion && (front.ocupada || front.reservada || front.reservada_sag || usedDestinations.has(front.id)))) return false;
  if (position.nivel > 1 && !plan.posiciones.some((support) => support.banda === position.banda
    && support.posicion === position.posicion && support.nivel === position.nivel - 1 && support.ocupada)) return false;

  if (isClearanceTask(task)) {
    const origin = task.origen;
    if (origin?.posicion && plan.id === origin.camara.id && position.banda === origin.posicion.banda && position.nivel === origin.posicion.nivel) return false;
    const protectedBands = task.contexto?.bandas_extraccion_protegidas;
    if (Array.isArray(protectedBands) && protectedBands.some((value) => value && typeof value === 'object'
      && value.camara_id === plan.id && value.banda === position.banda && value.nivel === position.nivel)) return false;
    if (plan.posiciones.some((front) => front.banda === position.banda && front.nivel === position.nivel
      && front.posicion > position.posicion && (front.ocupada || front.reservada || usedDestinations.has(front.id)))) return false;
    if (position.nivel > 1 && !plan.posiciones.some((support) => support.banda === position.banda
      && support.posicion === position.posicion && support.nivel === position.nivel - 1 && support.ocupada)) return false;
  }

  const exactDestination = task.destino?.posicion?.id;
  if (exactDestination && exactDestination !== position.id) return false;

  const band = plan.bandas_operacionales?.find((item) => item.numero === position.banda);
  if (!band || !band.acepta_nuevos_ingresos) return false;
  if (!band.usos_permitidos.includes('transito_pt')) return false;
  if (band.modo !== 'operativa' || band.estado === 'bloqueada' || band.estado === 'en_vaciado') return false;
  const acopio = loadStaging(task);
  const priorityBand = acopio?.sector?.banda_prioritaria;
  if (priorityBand !== undefined && position.banda !== priorityBand) return false;
  if (priorityBand === undefined && acopio?.puntos.length && !acopio.puntos.some((point) => areConcentrationNeighbors(point, position))) {
    return false;
  }
  // El acopio se arma desde el fondo de cada banda: no se deja un hueco detrás de un pallet.
  if (task.tipo_paso_maniobra !== 'retorno_banda' && !isNextSlot(plan, position, usedDestinations)) return false;

  if (task.contexto?.tipo_decision === 'concentrar_carga') {
    const targetCamera = taskContext(task, ['camara_objetivo_id']);
    if (targetCamera && targetCamera !== plan.id) return false;

    const points = concentrationPoints(task);
    if (priorityBand === undefined && points.length > 0 && !points.some((point) => areConcentrationNeighbors(point, position))) {
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
  const acopio = loadStaging(task);
  if (acopio) {
    const contacts = acopio.puntos.filter((point) => areConcentrationNeighbors(point, position)).length;
    score += 10_000 * contacts;
    reasons.push(`entrega directamente al acopio de ${acopio.carga_codigo}`);
  }

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

  const profiles = bandProfiles(plan, position);
  const target = taskProfile(task);
  if (profiles.some((profile) => matches(profile, target, ['cliente']))) {
    score += 900;
    reasons.push('coincide cliente');
  }
  if (profiles.some((profile) => matches(profile, target, ['cliente', 'marca']))) {
    score += 450;
    reasons.push('coincide marca');
  }
  if (profiles.some((profile) => matches(profile, target, ['cliente', 'marca', 'formato']))) {
    score += 225;
    reasons.push('coincide envase en pallets presentes o comprometidos');
  }
  if (!profiles.length) {
    score += 120;
    reasons.push('banda libre para nueva afinidad');
  }

  // P01 es el fondo. Preferir la menor profundidad libre vuelve a compactar
  // la banda después de retirar el pallet objetivo y evita huecos interiores.
  score += Math.max(0, 1_000 - position.posicion * 10);
  if (task.tipo_paso_maniobra === 'retorno_banda') {
    reasons.push('compacta nuevamente la banda desde el fondo');
  }
  score += Math.max(0, 10 - position.nivel);
  score += Math.min(100, profiles.reduce((total, profile) => total + profile.pallets, 0));

  if (!reasons.length) reasons.push('posición libre y compatible; prioriza llenado desde el fondo');
  return { score, reason: reasons.join('; ') };
}

/** La posición libre más al fondo, delante del último pallet ocupado o comprometido de su banda. */
export function isNextSlot(plan: CameraPlan, position: Position, usedDestinations: ReadonlySet<string> = new Set()) {
  const lane = plan.posiciones.filter((item) => item.banda === position.banda && item.nivel === position.nivel);
  const taken = (item: Position) => item.ocupada || item.reservada || item.reservada_sag || usedDestinations.has(item.id);
  const front = Math.max(0, ...lane.filter(taken).map((item) => item.posicion));
  if (position.posicion <= front) return false;
  return !lane.some((item) => item.posicion > front && item.posicion < position.posicion
    && item.estado === 'activa' && !taken(item));
}

/** Un sector nuevo del servidor marca las bandas donde cabe la carga completa. */
function outsideLoadSector(task: OperationalTask, position: Position) {
  const sector = loadStaging(task)?.sector;
  if (!sector || sector.tipo !== 'nuevo' || sector.banda_desde === null || sector.banda_hasta === null) return false;
  return position.nivel !== sector.nivel || position.banda < sector.banda_desde || position.banda > sector.banda_hasta;
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

export function areConcentrationNeighbors(point: ConcentrationPoint, position: Pick<Position, 'banda' | 'posicion' | 'nivel'>) {
  if (point.nivel !== position.nivel) return Math.abs(point.nivel - position.nivel) === 1
    && point.banda === position.banda && point.posicion === position.posicion;
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
