import * as Crypto from 'expo-crypto';

import {
  CameraPlan,
  Dock,
  ExtractionPlan,
  ExtractionRouteItem,
  Folio,
  LoadFolio,
  OperationalNotification,
  OperationalNotificationFeed,
  RefrigeratedLoad,
  RegisterLoadedFolioPayload,
  ReportLoadIncidentPayload,
  SendLoadFolioToDockPayload,
  UndoLoadedFolioPayload,
} from '../domain/estiba';
import { cameraDisplayName } from '../domain/cameras';
import { ApiError } from '../services/apiError';
import {
  DemoDatabaseExecutor,
  openDemoDatabase,
  writeDemoAudit,
} from './demoDatabase';
import {
  demoMovementEnd,
  demoOperator,
  DEMO_OPERATIONAL_STATE_KEY,
  DemoOperationalState,
  syncDemoOccupancy,
} from './demoOperationalSeed';

type DemoLoadStatus = 'draft' | 'published' | 'cancelled';
type DemoPriority = 'normal' | 'alta' | 'urgente';
type DemoAssignmentStatus = 'pending' | 'incident' | 'sent' | 'loaded' | 'cancelled';

type LoadRow = {
  id: string;
  code: string;
  external_order: string | null;
  status: DemoLoadStatus;
  priority: DemoPriority;
  observation: string | null;
  version: number;
  published_at: string | null;
  cancelled_at: string | null;
  created_at: string;
  updated_at: string;
};

type AssignmentRow = {
  id: string;
  load_id: string;
  folio_id: string;
  folio_number: string;
  status: DemoAssignmentStatus;
  dock_id: string | null;
  incident_type: string | null;
  incident_description: string | null;
  assigned_at: string;
  sent_at: string | null;
};

type NotificationRow = {
  id: string;
  type: OperationalNotification['tipo'];
  severity: OperationalNotification['severidad'];
  title: string;
  message: string;
  load_id: string | null;
  folio_id: string | null;
  data_json: string | null;
  read_at: string | null;
  confirmed_at: string | null;
  created_at: string;
  updated_at: string;
  load_code: string | null;
  load_priority: DemoPriority | null;
  load_status: DemoLoadStatus | null;
  folio_number: string | null;
};

type LocatedFolio = {
  plan: CameraPlan;
  position: CameraPlan['posiciones'][number];
  folio: Folio;
};

export type DemoLoadCandidate = {
  folioId: string;
  number: string;
  packageType: Folio['tipo_bulto'];
  cameraId: string;
  cameraCode: string;
  positionId: string;
  positionLabel: string;
  variety: string | null;
};

export type DemoLoadAdminSummary = {
  id: string;
  code: string;
  externalOrder: string | null;
  status: DemoLoadStatus;
  priority: DemoPriority;
  observation: string | null;
  version: number;
  folios: Array<{
    assignmentId: string;
    folioId: string;
    number: string;
    status: DemoAssignmentStatus;
  }>;
  publishedAt: string | null;
  cancelledAt: string | null;
  createdAt: string;
  updatedAt: string;
};

export type DemoLoadAdministration = {
  loads: DemoLoadAdminSummary[];
  candidates: DemoLoadCandidate[];
};

export type CreateDemoLoadInput = {
  operationId: string;
  externalOrder?: string;
  priority: DemoPriority;
  observation?: string;
  folioIds: string[];
};

const docks: Dock[] = [
  { id: 'dock-demo-01', codigo: 'AND-01', nombre: 'Andén Demo 01', activo: true },
  { id: 'dock-demo-02', codigo: 'AND-02', nombre: 'Andén Demo 02', activo: true },
];

function nowIso(): string {
  return new Date().toISOString();
}

function required(value: string, label: string): string {
  const normalized = value.trim();
  if (!normalized) throw new ApiError(`${label} es obligatorio.`, 422);
  return normalized;
}

function clone<T>(value: T): T {
  return JSON.parse(JSON.stringify(value)) as T;
}

function parseOperationalState(serialized: string | undefined): DemoOperationalState {
  try {
    const parsed = JSON.parse(serialized ?? '') as Partial<DemoOperationalState>;
    if (!Array.isArray(parsed.plans) || !Array.isArray(parsed.movements)) throw new Error();
    return {
      schemaVersion: 1,
      plans: parsed.plans,
      movements: parsed.movements,
      operationFingerprints: parsed.operationFingerprints ?? {},
    };
  } catch {
    throw new ApiError(
      'La operación Demo no pudo leerse. Restaura el escenario desde Administración Demo.',
      500,
    );
  }
}

async function operationalState(executor: DemoDatabaseExecutor): Promise<DemoOperationalState> {
  const row = await executor.getFirstAsync<{ state_json: string }>(
    'SELECT state_json FROM demo_operational_state WHERE key = ?',
    DEMO_OPERATIONAL_STATE_KEY,
  );
  return parseOperationalState(row?.state_json);
}

function locatedFolios(state: DemoOperationalState): LocatedFolio[] {
  return state.plans.flatMap((plan) => plan.posiciones.flatMap((position) => (
    position.folio ? [{ plan, position, folio: position.folio }] : []
  )));
}

function fingerprint(value: unknown): string {
  return JSON.stringify(value);
}

async function operationResult(
  executor: DemoDatabaseExecutor,
  operationId: string,
  expectedFingerprint: string,
): Promise<'new' | 'repeat'> {
  const normalized = required(operationId, 'El UUID de operación');
  const previous = await executor.getFirstAsync<{ fingerprint: string }>(
    'SELECT fingerprint FROM demo_load_operations WHERE operation_id = ?',
    normalized,
  );
  if (!previous) return 'new';
  if (previous.fingerprint === expectedFingerprint) return 'repeat';
  throw new ApiError(
    'El UUID de operación ya fue utilizado con datos diferentes.',
    409,
  );
}

async function rememberOperation(
  executor: DemoDatabaseExecutor,
  operationId: string,
  expectedFingerprint: string,
): Promise<void> {
  await executor.runAsync(
    `INSERT INTO demo_load_operations (operation_id, fingerprint, created_at)
     VALUES (?, ?, ?)`,
    operationId,
    expectedFingerprint,
    nowIso(),
  );
}

async function loadRows(
  executor: DemoDatabaseExecutor,
  where = '',
  ...parameters: Array<string | number>
): Promise<LoadRow[]> {
  return executor.getAllAsync<LoadRow>(
    `SELECT * FROM demo_loads ${where} ORDER BY updated_at DESC, code DESC`,
    ...parameters,
  );
}

async function assignmentRows(
  executor: DemoDatabaseExecutor,
  loadIds: string[],
): Promise<AssignmentRow[]> {
  if (!loadIds.length) return [];
  const placeholders = loadIds.map(() => '?').join(', ');
  return executor.getAllAsync<AssignmentRow>(
    `SELECT * FROM demo_load_assignments
     WHERE load_id IN (${placeholders})
     ORDER BY assigned_at ASC, folio_number ASC`,
    ...loadIds,
  );
}

function adminSummary(row: LoadRow, assignments: AssignmentRow[]): DemoLoadAdminSummary {
  return {
    id: row.id,
    code: row.code,
    externalOrder: row.external_order,
    status: row.status,
    priority: row.priority,
    observation: row.observation,
    version: row.version,
    folios: assignments.filter((item) => item.load_id === row.id).map((item) => ({
      assignmentId: item.id,
      folioId: item.folio_id,
      number: item.folio_number,
      status: item.status,
    })),
    publishedAt: row.published_at,
    cancelledAt: row.cancelled_at,
    createdAt: row.created_at,
    updatedAt: row.updated_at,
  };
}

export async function loadDemoLoadAdministration(): Promise<DemoLoadAdministration> {
  const db = await openDemoDatabase();
  const [state, loads] = await Promise.all([
    operationalState(db),
    loadRows(db),
  ]);
  const assignments = await assignmentRows(db, loads.map((load) => load.id));
  const unavailable = new Set(assignments
    .filter((item) => item.status !== 'cancelled'
      && loads.some((load) => load.id === item.load_id && load.status !== 'cancelled'))
    .map((item) => item.folio_id));

  const candidates = locatedFolios(state)
    .filter((item) => item.folio.tipo_bulto !== 'material' && !unavailable.has(item.folio.id))
    .map((item): DemoLoadCandidate => ({
      folioId: item.folio.id,
      number: item.folio.numero_folio,
      packageType: item.folio.tipo_bulto,
      cameraId: item.plan.id,
      cameraCode: item.plan.codigo,
      positionId: item.position.id,
      positionLabel: item.position.etiqueta ?? item.position.id,
      variety: item.folio.variedad,
    }))
    .sort((left, right) => left.cameraCode.localeCompare(right.cameraCode)
      || left.positionLabel.localeCompare(right.positionLabel));

  return {
    loads: loads.map((load) => adminSummary(load, assignments)),
    candidates,
  };
}

export async function createDemoLoad(input: CreateDemoLoadInput): Promise<void> {
  const folioIds = [...new Set(input.folioIds.map((id) => id.trim()).filter(Boolean))];
  if (!folioIds.length) throw new ApiError('Selecciona al menos un folio ubicado.', 422);
  if (folioIds.length > 26) throw new ApiError('Una carga admite como máximo 26 folios.', 422);
  if (!['normal', 'alta', 'urgente'].includes(input.priority)) {
    throw new ApiError('Selecciona una prioridad válida.', 422);
  }

  const expected = fingerprint({
    action: 'create_load',
    externalOrder: input.externalOrder?.trim() || null,
    priority: input.priority,
    observation: input.observation?.trim() || null,
    folioIds: [...folioIds].sort(),
  });
  const db = await openDemoDatabase();
  await db.withExclusiveTransactionAsync(async (transaction) => {
    if (await operationResult(transaction, input.operationId, expected) === 'repeat') return;
    const state = await operationalState(transaction);
    const located = new Map(locatedFolios(state).map((item) => [item.folio.id, item]));
    const missing = folioIds.filter((id) => !located.has(id));
    if (missing.length) {
      throw new ApiError('Uno de los folios ya no se encuentra ubicado en una cámara Demo.', 409);
    }

    const placeholders = folioIds.map(() => '?').join(', ');
    const assigned = await transaction.getFirstAsync<{ folio_number: string; code: string }>(
      `SELECT a.folio_number, l.code
       FROM demo_load_assignments a
       INNER JOIN demo_loads l ON l.id = a.load_id
       WHERE a.folio_id IN (${placeholders})
         AND a.status != 'cancelled'
         AND l.status != 'cancelled'
       LIMIT 1`,
      ...folioIds,
    );
    if (assigned) {
      throw new ApiError(`${assigned.folio_number} ya pertenece a ${assigned.code}.`, 409);
    }

    const sequence = await transaction.getFirstAsync<{ value: string }>(
      `SELECT value FROM demo_meta WHERE key = 'load_sequence'`,
    );
    const nextSequence = Number(sequence?.value ?? 0) + 1;
    const code = `CAR-${String(nextSequence).padStart(6, '0')}`;
    const id = Crypto.randomUUID();
    const now = nowIso();
    await transaction.runAsync(
      `INSERT INTO demo_loads
        (id, code, external_order, status, priority, observation, version,
         published_at, cancelled_at, created_at, updated_at)
       VALUES (?, ?, ?, 'draft', ?, ?, 1, NULL, NULL, ?, ?)`,
      id,
      code,
      input.externalOrder?.trim() || null,
      input.priority,
      input.observation?.trim() || null,
      now,
      now,
    );
    for (const folioId of folioIds) {
      const item = located.get(folioId)!;
      await transaction.runAsync(
        `INSERT INTO demo_load_assignments
          (id, load_id, folio_id, folio_number, status, dock_id,
           incident_type, incident_description, assigned_at, sent_at)
         VALUES (?, ?, ?, ?, 'pending', NULL, NULL, NULL, ?, NULL)`,
        Crypto.randomUUID(),
        id,
        folioId,
        item.folio.numero_folio,
        now,
      );
    }
    await transaction.runAsync(
      `INSERT OR REPLACE INTO demo_meta (key, value) VALUES ('load_sequence', ?)`,
      String(nextSequence),
    );
    await rememberOperation(transaction, input.operationId, expected);
    await writeDemoAudit(
      transaction,
      'crear_carga',
      'carga',
      id,
      `${code} · ${folioIds.length} folios · borrador`,
    );
  });
}

export async function publishDemoLoad(loadId: string, operationId: string): Promise<void> {
  const expected = fingerprint({ action: 'publish_load', loadId });
  const db = await openDemoDatabase();
  await db.withExclusiveTransactionAsync(async (transaction) => {
    if (await operationResult(transaction, operationId, expected) === 'repeat') return;
    const load = await transaction.getFirstAsync<LoadRow>('SELECT * FROM demo_loads WHERE id = ?', loadId);
    if (!load) throw new ApiError('La carga ya no existe.', 404);
    if (load.status !== 'draft') throw new ApiError('Solo se pueden publicar cargas en borrador.', 409);
    const assignments = await assignmentRows(transaction, [loadId]);
    if (!assignments.length || assignments.length > 26) {
      throw new ApiError('La carga debe contener entre 1 y 26 folios.', 422);
    }
    const state = await operationalState(transaction);
    const current = new Set(locatedFolios(state).map((item) => item.folio.id));
    if (assignments.some((item) => !current.has(item.folio_id))) {
      throw new ApiError('Todos los folios deben continuar ubicados antes de publicar.', 409);
    }

    const now = nowIso();
    await transaction.runAsync(
      `UPDATE demo_loads
       SET status = 'published', version = version + 1, published_at = ?, updated_at = ?
       WHERE id = ?`,
      now,
      now,
      loadId,
    );
    await insertNotification(
      transaction,
      'carga_publicada',
      load.priority === 'urgente' ? 'critica' : load.priority === 'alta' ? 'advertencia' : 'informativa',
      'Nueva carga publicada',
      `${load.code} está disponible con ${assignments.length} folios.`,
      loadId,
      null,
      { priority: load.priority, total: assignments.length },
    );
    await rememberOperation(transaction, operationId, expected);
    await writeDemoAudit(
      transaction,
      'publicar_carga',
      'carga',
      loadId,
      `${load.code} · ${assignments.length} folios`,
    );
  });
}

export async function cancelDemoLoad(loadId: string, operationId: string): Promise<void> {
  const expected = fingerprint({ action: 'cancel_load', loadId });
  const db = await openDemoDatabase();
  await db.withExclusiveTransactionAsync(async (transaction) => {
    if (await operationResult(transaction, operationId, expected) === 'repeat') return;
    const load = await transaction.getFirstAsync<LoadRow>('SELECT * FROM demo_loads WHERE id = ?', loadId);
    if (!load) throw new ApiError('La carga ya no existe.', 404);
    if (load.status === 'cancelled') throw new ApiError('La carga ya está cancelada.', 409);
    const sent = await transaction.getFirstAsync<{ id: string }>(
      `SELECT id FROM demo_load_assignments WHERE load_id = ? AND status = 'sent' LIMIT 1`,
      loadId,
    );
    if (sent) throw new ApiError('No puedes cancelar una carga que ya envió folios al andén.', 409);

    const now = nowIso();
    await transaction.runAsync(
      `UPDATE demo_loads
       SET status = 'cancelled', version = version + 1, cancelled_at = ?, updated_at = ?
       WHERE id = ?`,
      now,
      now,
      loadId,
    );
    await transaction.runAsync(
      `UPDATE demo_load_assignments SET status = 'cancelled' WHERE load_id = ?`,
      loadId,
    );
    await rememberOperation(transaction, operationId, expected);
    await writeDemoAudit(
      transaction,
      'cancelar_carga',
      'carga',
      loadId,
      `${load.code} cancelada; folios liberados`,
    );
  });
}

export async function changeDemoLoadPriority(
  loadId: string,
  priority: DemoPriority,
  operationId: string,
): Promise<void> {
  if (!['normal', 'alta', 'urgente'].includes(priority)) {
    throw new ApiError('Selecciona una prioridad válida.', 422);
  }
  const expected = fingerprint({ action: 'change_priority', loadId, priority });
  const db = await openDemoDatabase();
  await db.withExclusiveTransactionAsync(async (transaction) => {
    if (await operationResult(transaction, operationId, expected) === 'repeat') return;
    const load = await transaction.getFirstAsync<LoadRow>('SELECT * FROM demo_loads WHERE id = ?', loadId);
    if (!load) throw new ApiError('La carga ya no existe.', 404);
    if (load.status === 'cancelled') throw new ApiError('La carga está cancelada.', 409);
    if (load.priority === priority) throw new ApiError(`La carga ya tiene prioridad ${priority}.`, 409);
    const now = nowIso();
    await transaction.runAsync(
      `UPDATE demo_loads SET priority = ?, version = version + 1, updated_at = ? WHERE id = ?`,
      priority,
      now,
      loadId,
    );
    if (load.status === 'published') {
      await insertNotification(
        transaction,
        'prioridad_carga_cambiada',
        priority === 'urgente' ? 'critica' : 'advertencia',
        'Prioridad de carga actualizada',
        `${load.code} ahora tiene prioridad ${priority}.`,
        loadId,
        null,
        { previous: load.priority, priority },
      );
    }
    await rememberOperation(transaction, operationId, expected);
    await writeDemoAudit(
      transaction,
      'cambiar_prioridad',
      'carga',
      loadId,
      `${load.code}: ${load.priority} → ${priority}`,
    );
  });
}

function loadFolio(
  assignment: AssignmentRow,
  location: LocatedFolio | undefined,
): LoadFolio {
  const dock = assignment.dock_id
    ? docks.find((candidate) => candidate.id === assignment.dock_id) ?? docks[0]
    : null;
  return {
    asignacion_id: assignment.id,
    id: assignment.folio_id,
    numero_folio: assignment.folio_number,
    tipo_bulto: location?.folio.tipo_bulto === 'saldo' ? 'saldo' : 'pallet',
    estado_operacional: assignment.status === 'sent' || assignment.status === 'loaded'
      ? 'en_anden'
      : location?.folio.estado_operacional ?? 'sin_ubicacion',
    estado_carga: assignment.status === 'loaded'
      ? 'cargado'
      : assignment.status === 'sent'
        ? 'en_anden'
        : assignment.status === 'incident' ? 'con_incidencia' : 'pendiente',
    anden: dock ? { id: dock.id, codigo: dock.codigo, nombre: dock.nombre } : null,
    asignado_at: assignment.assigned_at,
    ubicacion: location ? {
      camara: { id: location.plan.id, codigo: location.plan.codigo, nombre: location.plan.nombre },
      posicion: {
        id: location.position.id,
        banda: location.position.banda,
        posicion: location.position.posicion,
        nivel: location.position.nivel,
        etiqueta: location.position.etiqueta,
      },
    } : null,
  };
}

function refrigeratedLoad(
  row: LoadRow,
  assignments: AssignmentRow[],
  locations: Map<string, LocatedFolio>,
): RefrigeratedLoad {
  const folios = assignments.map((assignment) => loadFolio(assignment, locations.get(assignment.folio_id)));
  const outOfCamera = (folio: LoadFolio) => folio.estado_carga === 'en_anden' || folio.estado_carga === 'cargado';
  const pendingLocations = folios.filter((folio) => !outOfCamera(folio) && folio.ubicacion);
  const perCamera = new Map<string, { camera: NonNullable<LoadFolio['ubicacion']>['camara']; total: number }>();
  for (const folio of pendingLocations) {
    const camera = folio.ubicacion!.camara;
    const current = perCamera.get(camera.id) ?? { camera, total: 0 };
    current.total += 1;
    perCamera.set(camera.id, current);
  }
  const main = [...perCamera.values()].sort((left, right) => right.total - left.total)[0] ?? null;
  const atDock = folios.filter(outOfCamera).length;
  const loaded = folios.filter((folio) => folio.estado_carga === 'cargado').length;
  const incidents = folios.filter((folio) => folio.estado_carga === 'con_incidencia').length;
  const concentrated = Math.min(folios.length, atDock + (main?.total ?? 0));
  const percentage = folios.length ? Math.round((concentrated / folios.length) * 1000) / 10 : 0;
  const positions = pendingLocations
    .filter((folio) => folio.ubicacion?.camara.id === main?.camera.id)
    .map((folio) => folio.ubicacion!.posicion);
  const allSent = folios.length > 0 && atDock === folios.length;

  return {
    id: row.id,
    codigo: row.code,
    numero_orden_externa: row.external_order,
    estado: allSent
      ? 'separada'
      : atDock > 0 ? 'despacho_parcial' : incidents > 0 ? 'en_preparacion' : 'pendiente',
    prioridad: row.priority,
    version: row.version,
    observacion: row.observation,
    camara_objetivo: main?.camera ?? null,
    anden_previsto: { id: docks[0].id, codigo: docks[0].codigo, nombre: docks[0].nombre },
    total_folios: folios.length,
    folios,
    progreso: {
      porcentaje: percentage,
      umbral_porcentaje: 80,
      cumple_umbral: percentage >= 80,
      concentrados: concentrated,
      faltantes: Math.max(0, folios.length - concentrated),
      total: folios.length,
      en_anden: atDock,
      cargados: loaded,
      con_incidencia: incidents,
      pendientes: folios.length - atDock - incidents,
      grupo_principal: main && positions.length ? {
        camara: main.camera,
        nivel: positions[0].nivel,
        banda_desde: Math.min(...positions.map((position) => position.banda)),
        banda_hasta: Math.max(...positions.map((position) => position.banda)),
        posicion_desde: Math.min(...positions.map((position) => position.posicion)),
        posicion_hasta: Math.max(...positions.map((position) => position.posicion)),
      } : null,
    },
    incidencias_abiertas: incidents,
    publicada_at: row.published_at,
  };
}

export async function listDemoRefrigeratedLoads(): Promise<RefrigeratedLoad[]> {
  const db = await openDemoDatabase();
  const [state, loads] = await Promise.all([
    operationalState(db),
    loadRows(db, `WHERE status = 'published'`),
  ]);
  const assignments = await assignmentRows(db, loads.map((load) => load.id));
  const locations = new Map(locatedFolios(state).map((item) => [item.folio.id, item]));
  return loads.map((load) => refrigeratedLoad(
    load,
    assignments.filter((assignment) => assignment.load_id === load.id),
    locations,
  ));
}

function routeBlockers(
  location: LocatedFolio,
  loadFolioIds: Set<string>,
): ExtractionRouteItem['bloqueadores'] {
  return location.plan.posiciones
    .filter((position) => position.folio
      && position.nivel === location.position.nivel
      && position.banda === location.position.banda
      && position.posicion > location.position.posicion
      && !loadFolioIds.has(position.folio.id))
    .map((position) => ({
      folio_id: position.folio!.id,
      numero_folio: position.folio!.numero_folio,
      posicion_id: position.id,
      etiqueta: position.etiqueta,
    }));
}

export async function getDemoExtractionPlan(loadId: string): Promise<ExtractionPlan> {
  const db = await openDemoDatabase();
  const [state, load] = await Promise.all([
    operationalState(db),
    db.getFirstAsync<LoadRow>(
      `SELECT * FROM demo_loads WHERE id = ? AND status = 'published'`,
      loadId,
    ),
  ]);
  if (!load) throw new ApiError('La carga no está publicada o ya fue cancelada.', 404);
  const assignments = await assignmentRows(db, [loadId]);
  const locations = new Map(locatedFolios(state).map((item) => [item.folio.id, item]));
  const activeIds = new Set(assignments
    .filter((assignment) => assignment.status === 'pending')
    .map((assignment) => assignment.folio_id));

  const candidates = assignments
    .filter((assignment) => assignment.status !== 'sent' && assignment.status !== 'cancelled')
    .map((assignment) => ({ assignment, location: locations.get(assignment.folio_id) }))
    .sort((left, right) => {
      const a = left.location;
      const b = right.location;
      if (!a && !b) return left.assignment.folio_number.localeCompare(right.assignment.folio_number);
      if (!a) return 1;
      if (!b) return -1;
      return a.plan.codigo.localeCompare(b.plan.codigo)
        || a.position.nivel - b.position.nivel
        || a.position.banda - b.position.banda
        || b.position.posicion - a.position.posicion;
    });

  let order = 0;
  const items: ExtractionRouteItem[] = candidates.map(({ assignment, location }) => {
    const blockers = location ? routeBlockers(location, activeIds) : [];
    const actionable = assignment.status === 'pending' && Boolean(location) && blockers.length === 0;
    if (actionable) order += 1;
    return {
      orden: actionable ? order : null,
      estado_ruta: assignment.status === 'incident'
        ? 'incidencia'
        : !location ? 'sin_ubicacion' : blockers.length ? 'bloqueado' : 'disponible',
      asignacion_id: assignment.id,
      folio: {
        id: assignment.folio_id,
        numero_folio: assignment.folio_number,
        tipo_bulto: location?.folio.tipo_bulto === 'saldo' ? 'saldo' : 'pallet',
      },
      ubicacion: location ? {
        camara: {
          id: location.plan.id,
          codigo: location.plan.codigo,
          nombre: location.plan.nombre,
          version_plano: location.plan.version_plano,
        },
        posicion: {
          id: location.position.id,
          banda: location.position.banda,
          posicion: location.position.posicion,
          nivel: location.position.nivel,
          etiqueta: location.position.etiqueta,
        },
      } : null,
      bloqueadores: blockers,
    };
  });
  const next = items.find((item) => item.orden !== null) ?? null;
  if (next) next.estado_ruta = 'sugerido';

  return {
    carga_id: load.id,
    carga_codigo: load.code,
    generado_at: nowIso(),
    resumen: {
      pendientes: items.length,
      planificables: items.filter((item) => item.orden !== null).length,
      bloqueados: items.filter((item) => item.estado_ruta === 'bloqueado').length,
      sin_ubicacion: items.filter((item) => item.estado_ruta === 'sin_ubicacion').length,
      con_incidencia: items.filter((item) => item.estado_ruta === 'incidencia').length,
    },
    siguiente: next,
    items,
  };
}

function assertOwnSession(location: LocatedFolio, sessionId: string): void {
  const session = location.plan.acceso.sesion;
  if (location.plan.acceso.modo !== 'edicion' || !session?.es_propia || session.id !== sessionId) {
    throw new ApiError(`No tienes una sesión activa en ${cameraDisplayName(location.plan)}.`, 409);
  }
}

export async function reportDemoLoadIncident(
  assignmentId: string,
  payload: ReportLoadIncidentPayload,
): Promise<void> {
  const expected = fingerprint({ action: 'report_incident', assignmentId, payload });
  const db = await openDemoDatabase();
  await db.withExclusiveTransactionAsync(async (transaction) => {
    if (await operationResult(transaction, payload.operacion_id, expected) === 'repeat') return;
    const assignment = await transaction.getFirstAsync<AssignmentRow>(
      'SELECT * FROM demo_load_assignments WHERE id = ?',
      assignmentId,
    );
    if (!assignment || assignment.status !== 'pending') {
      throw new ApiError('El folio ya no admite una nueva incidencia.', 409);
    }
    const state = await operationalState(transaction);
    const location = locatedFolios(state).find((item) => item.folio.id === assignment.folio_id);
    if (!location) throw new ApiError('El folio ya no posee ubicación.', 409);
    assertOwnSession(location, payload.sesion_estiba_id);
    const load = await transaction.getFirstAsync<LoadRow>(
      `SELECT * FROM demo_loads WHERE id = ? AND status = 'published'`,
      assignment.load_id,
    );
    if (!load) throw new ApiError('La carga ya no está activa.', 409);
    const now = nowIso();
    await transaction.runAsync(
      `UPDATE demo_load_assignments
       SET status = 'incident', incident_type = ?, incident_description = ?
       WHERE id = ?`,
      payload.tipo,
      payload.descripcion?.trim() || null,
      assignmentId,
    );
    await transaction.runAsync(
      `UPDATE demo_loads SET version = version + 1, updated_at = ? WHERE id = ?`,
      now,
      load.id,
    );
    await insertNotification(
      transaction,
      'incidencia_carga_reportada',
      'critica',
      'Incidencia en carga',
      `${assignment.folio_number} quedó observado en ${load.code}.`,
      load.id,
      assignment.folio_id,
      { type: payload.tipo, description: payload.descripcion?.trim() || null },
    );
    await rememberOperation(transaction, payload.operacion_id, expected);
    await writeDemoAudit(
      transaction,
      'reportar_incidencia_carga',
      'folio',
      assignment.folio_id,
      `${load.code} · ${assignment.folio_number} · ${payload.tipo}`,
    );
  });
}

export async function sendDemoLoadFolioToDock(
  assignmentId: string,
  payload: SendLoadFolioToDockPayload,
): Promise<RefrigeratedLoad> {
  const expected = fingerprint({ action: 'send_to_dock', assignmentId, payload });
  const db = await openDemoDatabase();
  let loadId = '';
  await db.withExclusiveTransactionAsync(async (transaction) => {
    const repeated = await operationResult(transaction, payload.operacion_id, expected) === 'repeat';
    const assignment = await transaction.getFirstAsync<AssignmentRow>(
      'SELECT * FROM demo_load_assignments WHERE id = ?',
      assignmentId,
    );
    if (!assignment) throw new ApiError('La asignación ya no existe.', 404);
    loadId = assignment.load_id;
    if (repeated) return;
    if (assignment.status !== 'pending') {
      throw new ApiError('El folio ya no está pendiente de despacho.', 409);
    }
    const dock = docks.find((candidate) => candidate.id === payload.anden_id);
    if (!dock) throw new ApiError('Selecciona un andén válido.', 422);
    const state = await operationalState(transaction);
    const location = locatedFolios(state).find((item) => item.folio.id === assignment.folio_id);
    if (!location) throw new ApiError('El folio ya no posee ubicación.', 409);
    assertOwnSession(location, payload.sesion_estiba_id);
    if (location.plan.version_plano !== payload.version_camara_conocida) {
      throw new ApiError(
        `${cameraDisplayName(location.plan)} cambió desde la última lectura. Actualiza la ruta antes de continuar.`,
        409,
      );
    }
    const load = await transaction.getFirstAsync<LoadRow>(
      `SELECT * FROM demo_loads WHERE id = ? AND status = 'published'`,
      assignment.load_id,
    );
    if (!load) throw new ApiError('La carga ya no está activa.', 409);
    const loadAssignments = await assignmentRows(transaction, [load.id]);
    const pendingLoadIds = new Set(loadAssignments
      .filter((item) => item.status === 'pending')
      .map((item) => item.folio_id));
    const blockers = routeBlockers(location, pendingLoadIds);
    if (blockers.length) {
      throw new ApiError(
        `No puedes retirar ${assignment.folio_number}: ${blockers.map((item) => item.numero_folio).join(', ')} bloquea(n) la salida.`,
        409,
      );
    }

    const previousVersion = location.plan.version_plano;
    const folio = location.folio;
    location.position.ocupada = false;
    location.position.folio = null;
    location.plan.version_plano += 1;
    syncDemoOccupancy(location.plan);
    const now = nowIso();
    state.movements.unshift({
      id: Crypto.randomUUID(),
      operacion_id: payload.operacion_id,
      tipo_movimiento: 'retiro',
      folio: { id: folio.id, numero_folio: folio.numero_folio, tipo_bulto: folio.tipo_bulto },
      origen: demoMovementEnd(
        location.plan,
        location.position,
        previousVersion,
        location.plan.version_plano,
      ),
      destino: null,
      usuario: demoOperator,
      generado_dispositivo_at: payload.generado_dispositivo_at,
      recibido_servidor_at: now,
      created_at: now,
    });
    await transaction.runAsync(
      `UPDATE demo_operational_state SET state_json = ?, updated_at = ? WHERE key = ?`,
      JSON.stringify(state),
      now,
      DEMO_OPERATIONAL_STATE_KEY,
    );
    await transaction.runAsync(
      `UPDATE demo_load_assignments
       SET status = 'sent', dock_id = ?, sent_at = ? WHERE id = ?`,
      dock.id,
      now,
      assignmentId,
    );
    await transaction.runAsync(
      `UPDATE demo_loads SET version = version + 1, updated_at = ? WHERE id = ?`,
      now,
      load.id,
    );
    await transaction.runAsync(
      `UPDATE demo_folios SET status = 'en_anden' WHERE id = ?`,
      assignment.folio_id,
    );
    await rememberOperation(transaction, payload.operacion_id, expected);
    await writeDemoAudit(
      transaction,
      'enviar_folio_anden',
      'folio',
      assignment.folio_id,
      `${load.code} · ${assignment.folio_number} → ${dock.codigo}`,
    );
  });
  const loads = await listDemoRefrigeratedLoads();
  const result = loads.find((load) => load.id === loadId);
  if (!result) throw new ApiError('La carga ya no está disponible.', 404);
  return result;
}

export async function registerDemoLoadedFolio(
  loadId: string,
  payload: RegisterLoadedFolioPayload,
): Promise<RefrigeratedLoad> {
  const expected = fingerprint({ action: 'load_truck', loadId, payload });
  const db = await openDemoDatabase();
  await db.withExclusiveTransactionAsync(async (transaction) => {
    if (await operationResult(transaction, payload.operacion_id, expected) === 'repeat') return;
    const number = payload.numero_folio.trim().toUpperCase();
    const assignments = await assignmentRows(transaction, [loadId]);
    const assignment = assignments.find((item) => item.folio_number.toUpperCase() === number);
    if (!assignment) throw new ApiError(`El folio ${number} no pertenece a esta carga. No lo subas a este camión.`, 422);
    if (assignment.status === 'loaded') throw new ApiError(`El folio ${number} ya está registrado como cargado.`, 422);
    if (assignment.status !== 'sent') {
      throw new ApiError(`El folio ${number} todavía no sale de la cámara: envíalo al andén antes de cargarlo.`, 422);
    }
    await transaction.runAsync(`UPDATE demo_load_assignments SET status = 'loaded' WHERE id = ?`, assignment.id);
    await transaction.runAsync(`UPDATE demo_loads SET version = version + 1, updated_at = ? WHERE id = ?`, nowIso(), loadId);
    await rememberOperation(transaction, payload.operacion_id, expected);
    await writeDemoAudit(transaction, 'cargar_folio_camion', 'folio', assignment.folio_id, number);
  });
  return demoLoadById(loadId);
}

export async function undoDemoLoadedFolio(
  assignmentId: string,
  payload: UndoLoadedFolioPayload,
): Promise<RefrigeratedLoad> {
  const expected = fingerprint({ action: 'unload_truck', assignmentId, payload });
  const db = await openDemoDatabase();
  let loadId = '';
  await db.withExclusiveTransactionAsync(async (transaction) => {
    const assignment = await transaction.getFirstAsync<AssignmentRow>(
      'SELECT * FROM demo_load_assignments WHERE id = ?',
      assignmentId,
    );
    if (!assignment) throw new ApiError('La asignación ya no existe.', 404);
    loadId = assignment.load_id;
    if (await operationResult(transaction, payload.operacion_id, expected) === 'repeat') return;
    if (assignment.status !== 'loaded') throw new ApiError('Solo un pallet registrado como cargado puede bajarse del camión.', 422);
    await transaction.runAsync(`UPDATE demo_load_assignments SET status = 'sent' WHERE id = ?`, assignmentId);
    await transaction.runAsync(`UPDATE demo_loads SET version = version + 1, updated_at = ? WHERE id = ?`, nowIso(), loadId);
    await rememberOperation(transaction, payload.operacion_id, expected);
    await writeDemoAudit(transaction, 'bajar_folio_camion', 'folio', assignment.folio_id, payload.motivo);
  });
  return demoLoadById(loadId);
}

async function demoLoadById(loadId: string): Promise<RefrigeratedLoad> {
  const loads = await listDemoRefrigeratedLoads();
  const result = loads.find((load) => load.id === loadId);
  if (!result) throw new ApiError('La carga ya no está disponible.', 404);
  return result;
}

export function listDemoDocks(): Dock[] {
  return clone(docks);
}

async function insertNotification(
  executor: DemoDatabaseExecutor,
  type: OperationalNotification['tipo'],
  severity: OperationalNotification['severidad'],
  title: string,
  message: string,
  loadId: string,
  folioId: string | null,
  data: Record<string, unknown> | null,
): Promise<void> {
  const now = nowIso();
  await executor.runAsync(
    `INSERT INTO demo_notifications
      (id, type, severity, title, message, load_id, folio_id, data_json,
       read_at, confirmed_at, created_at, updated_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, ?, ?)`,
    Crypto.randomUUID(),
    type,
    severity,
    title,
    message,
    loadId,
    folioId,
    data ? JSON.stringify(data) : null,
    now,
    now,
  );
}

function notification(row: NotificationRow): OperationalNotification {
  let data: Record<string, unknown> | null = null;
  try {
    data = row.data_json ? JSON.parse(row.data_json) as Record<string, unknown> : null;
  } catch {
    data = null;
  }
  return {
    id: row.id,
    tipo: row.type,
    severidad: row.severity,
    titulo: row.title,
    mensaje: row.message,
    carga: row.load_id && row.load_code && row.load_priority && row.load_status ? {
      id: row.load_id,
      codigo: row.load_code,
      prioridad: row.load_priority,
      estado: row.load_status,
    } : null,
    despacho_material: null,
    folio: row.folio_id && row.folio_number
      ? { id: row.folio_id, numero_folio: row.folio_number }
      : null,
    incidencia_id: row.type === 'incidencia_carga_reportada' ? row.id : null,
    datos: data,
    leida_at: row.read_at,
    confirmada_at: row.confirmed_at,
    created_at: row.created_at,
    updated_at: row.updated_at,
  };
}

async function notificationRows(
  executor: DemoDatabaseExecutor,
  where = '',
  ...parameters: string[]
): Promise<NotificationRow[]> {
  return executor.getAllAsync<NotificationRow>(
    `SELECT
       n.*,
       l.code AS load_code,
       l.priority AS load_priority,
       l.status AS load_status,
       a.folio_number AS folio_number
     FROM demo_notifications n
     LEFT JOIN demo_loads l ON l.id = n.load_id
     LEFT JOIN demo_load_assignments a
       ON a.load_id = n.load_id AND a.folio_id = n.folio_id
     ${where}
     ORDER BY n.created_at DESC
     LIMIT 100`,
    ...parameters,
  );
}

export async function listDemoOperationalNotifications(): Promise<OperationalNotificationFeed> {
  const db = await openDemoDatabase();
  const [rows, unreadRow] = await Promise.all([
    notificationRows(db),
    db.getFirstAsync<{ total: number }>(
      `SELECT COUNT(*) AS total FROM demo_notifications WHERE read_at IS NULL`,
    ),
  ]);
  const items = rows.map(notification);
  return {
    items,
    unread: unreadRow?.total ?? 0,
    syncedAt: nowIso(),
  };
}

export async function getDemoOperationalNotificationSummary() {
  const db = await openDemoDatabase();
  const row = await db.getFirstAsync<{ total: number }>(
    `SELECT COUNT(*) AS total FROM demo_notifications WHERE read_at IS NULL`,
  );
  return { unread: row?.total ?? 0, syncedAt: nowIso() };
}

async function updateNotification(
  notificationId: string,
  column: 'read_at' | 'confirmed_at',
): Promise<OperationalNotification> {
  const db = await openDemoDatabase();
  const now = nowIso();
  await db.withExclusiveTransactionAsync(async (transaction) => {
    const current = await transaction.getFirstAsync<{ id: string }>(
      'SELECT id FROM demo_notifications WHERE id = ?',
      notificationId,
    );
    if (!current) throw new ApiError('La notificación ya no existe.', 404);
    if (column === 'confirmed_at') {
      await transaction.runAsync(
        `UPDATE demo_notifications
         SET confirmed_at = COALESCE(confirmed_at, ?),
             read_at = COALESCE(read_at, ?),
             updated_at = ?
         WHERE id = ?`,
        now,
        now,
        now,
        notificationId,
      );
    } else {
      await transaction.runAsync(
        `UPDATE demo_notifications SET read_at = COALESCE(read_at, ?), updated_at = ? WHERE id = ?`,
        now,
        now,
        notificationId,
      );
    }
  });
  const rows = await notificationRows(db, 'WHERE n.id = ?', notificationId);
  if (!rows[0]) throw new ApiError('La notificación ya no existe.', 404);
  return notification(rows[0]);
}

export function readDemoOperationalNotification(notificationId: string) {
  return updateNotification(notificationId, 'read_at');
}

export function confirmDemoOperationalNotification(notificationId: string) {
  return updateNotification(notificationId, 'confirmed_at');
}

export async function decorateDemoPlanWithLoads(plan: CameraPlan): Promise<CameraPlan> {
  const db = await openDemoDatabase();
  const rows = await db.getAllAsync<{
    folio_id: string;
    load_id: string;
    code: string;
    status: DemoLoadStatus;
    priority: DemoPriority;
    version: number;
  }>(
    `SELECT a.folio_id, l.id AS load_id, l.code, l.status, l.priority, l.version
     FROM demo_load_assignments a
     INNER JOIN demo_loads l ON l.id = a.load_id
     WHERE l.status = 'published' AND a.status NOT IN ('sent', 'cancelled')`,
  );
  const references = new Map(rows.map((row) => [row.folio_id, row]));
  const decorated = clone(plan);
  for (const position of decorated.posiciones) {
    const folios = position.folios ?? (position.folio ? [position.folio] : []);
    for (const folio of folios) {
      const reference = references.get(folio.id);
      folio.carga_actual = reference ? {
        id: reference.load_id,
        codigo: reference.code,
        estado: reference.status,
        prioridad: reference.priority,
        version: reference.version,
      } : null;
    }
    if (position.folio) {
      const reference = references.get(position.folio.id);
      position.folio.carga_actual = reference ? {
        id: reference.load_id,
        codigo: reference.code,
        estado: reference.status,
        prioridad: reference.priority,
        version: reference.version,
      } : null;
    }
  }
  for (const folio of decorated.folios_sin_posicion) {
    const reference = references.get(folio.id);
    folio.carga_actual = reference ? {
      id: reference.load_id,
      codigo: reference.code,
      estado: reference.status,
      prioridad: reference.priority,
      version: reference.version,
    } : null;
  }
  return decorated;
}
