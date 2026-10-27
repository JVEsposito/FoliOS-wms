import * as Crypto from 'expo-crypto';
import { useEffect, useMemo, useRef, useState } from 'react';
import {
  ActivityIndicator,
  Alert,
  Pressable,
  StyleSheet,
  Text,
  View,
} from 'react-native';

import { OPERATIONAL_POLL_INTERVAL_MS } from '../config/polling';
import {
  AuthSession,
  CameraPlan,
  LocatePayload,
  MovePayload,
  SendLoadFolioToDockPayload,
} from '../domain/estiba';
import {
  ManeuverDiscrepancyType,
  OperationalTask,
  OperationalShiftContext,
  ReportedManeuverDiscrepancy,
  TemporaryExtractionPayload,
  operationalTaskDestinationLabel,
  operationalTaskLabel,
  operationalTaskPositionLabel,
} from '../domain/operationalTasks';
import { buildOperatorTaskHome, type OperatorQueueItem } from '../domain/operatorTaskQueue';
import type { ShiftVerification, VerificationItem } from '../domain/verification';
import { recoverPhysicalFrontier } from '../domain/physicalFrontierRecovery';
import { calculateRollingFrontier } from '../domain/rollingPlanner';
import { OperationalActionGate, OperationalInboxReads } from '../domain/operationalInboxConcurrency';
import { OperationalCameraPlans } from '../domain/operationalCameraPlans';
import { OperationalMovementStore, type PendingOperationalMovement } from '../services/operationalMovementStore';
import {
  previewableTasks,
  materializationOutcome,
  reusablePreviewProposal,
  simulateFrontierPreview,
  type FrontierPreview,
} from '../domain/rollingPlannerPreview';
import { useOperationalPolling } from '../hooks/useOperationalPolling';
import { ApiError } from '../services/apiError';
import { EstibaApi } from '../services/estibaApi';
import { OperationalTasksApi, type StartConfirmation } from '../services/operationalTasksApi';
import type { FolioConfirmationState } from './operator/OperatorFolioConfirmation';
import { cameraSessionConflictMessage } from '../domain/operationalTaskErrors';
import { OperatorErrorDialog } from './operator/OperatorErrorDialog';
import { OperatorTaskExecution } from './operator/OperatorTaskExecution';
import { OperatorTaskHome } from './operator/OperatorTaskHome';
import { OperatorLoadStatus } from './operator/OperatorLoadStatus';
import { TurnVerification } from './operator/TurnVerification';
import { operatorTheme as o } from '../theme/operatorTheme';

type Props = {
  api: EstibaApi;
  auth: AuthSession;
};

type TaskTab = 'mias' | 'disponibles';

type OpenSession = {
  cameraId: string;
  sessionId: string;
  openedByTask: boolean;
  plan: CameraPlan;
};

type MovementWarning = {
  codigo: string;
  titulo: string;
  mensaje: string;
};

export function OperationalTaskInbox({ api, auth }: Props) {
  const taskApi = useMemo(
    () => api.mode === 'connected' && api.baseUrl ? new OperationalTasksApi(api.baseUrl) : null,
    [api.baseUrl, api.mode],
  );
  const [available, setAvailable] = useState<OperationalTask[]>([]);
  const [shiftContext, setShiftContext] = useState<OperationalShiftContext | null>(null);
  const [mine, setMine] = useState<OperationalTask[]>([]);
  const [tab, setTab] = useState<TaskTab>('mias');
  const [showLoads, setShowLoads] = useState(false);
  const [activeTask, setActiveTask] = useState<OperationalTask | null>(null);
  const [actionLabel, setActionLabel] = useState('');
  const [loading, setLoading] = useState(false);
  const [journalReady, setJournalReady] = useState(false);
  const [pendingMovement, setPendingMovement] = useState<PendingOperationalMovement | null>(null);
  const [recoveryIssue, setRecoveryIssue] = useState('');
  const [connectionIssue, setConnectionIssue] = useState('');
  const [lastReadAt, setLastReadAt] = useState<string | null>(null);
  const [availableFresh, setAvailableFresh] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [confirmation, setConfirmation] = useState<FolioConfirmationState | null>(null);
  const [clock, setClock] = useState(Date.now());
  const [plannerFresh, setPlannerFresh] = useState<boolean | null>(null);
  const [suggestedDestinations, setSuggestedDestinations] = useState<Record<string, string>>({});
  const [verification, setVerification] = useState<ShiftVerification | null>(null);
  const initialLoad = useRef(true);
  const sessionEpoch = useRef(0);
  const reads = useMemo(() => new OperationalInboxReads(), [taskApi, auth.token]);
  const actionGate = useMemo(() => new OperationalActionGate(), [taskApi, auth.token]);
  const cameraPlans = useMemo(() => new OperationalCameraPlans(), [taskApi, auth.token]);
  const movementStore = useMemo(() => new OperationalMovementStore(api.baseUrl ?? '', auth.usuario.id, auth.dispositivo.id),
    [api.baseUrl, auth.usuario.id, auth.dispositivo.id]);
  const pendingRef = useRef<PendingOperationalMovement | null>(null);
  const preview = useRef<FrontierPreview | null>(null);
  const previewGeneration = useRef(0);
  const executionSessions = useRef<OpenSession[]>([]);
  const busy = Boolean(actionLabel) || loading || !journalReady;

  const inPhysicalMovement = activeTask?.estado === 'en_proceso';
  const secondsRemaining = useMemo(() => {
    if (!activeTask?.reserva
      || activeTask.estado === 'en_proceso'
      || activeTask.maniobra?.custodia_temporal_activa) return null;
    const expiresAt = activeTask.reserva.vence_at;
    if (!expiresAt) return null;
    return Math.max(0, Math.floor((new Date(expiresAt).getTime() - clock) / 1000));
  }, [activeTask?.estado, activeTask?.maniobra?.custodia_temporal_activa, activeTask?.reserva, clock]);
  const leaseExpired = Boolean(activeTask && !inPhysicalMovement && secondsRemaining === 0);
  const hasPhysicalDestination = Boolean(
    activeTask?.destino?.posicion && activeTask?.reserva?.tipo_compromiso === 'fisica',
  );
  const home = useMemo(() => buildOperatorTaskHome(mine, available), [available, mine]);
  const homeView = tab === 'mias' ? 'mine' : 'available';
  const homeQueue = homeView === 'mine' ? home.mine : home.available;

  useEffect(() => {
    setAvailable([]); setMine([]); setActiveTask(null); initialLoad.current = true;
    setJournalReady(false); setRecoveryIssue(''); setAvailableFresh(false);
    pendingRef.current = null; setPendingMovement(null);
    void loadTasks();
    void recoverMovement(false);
    return () => { sessionEpoch.current += 1; reads.invalidate(); previewGeneration.current += 1; preview.current = null; cameraPlans.clear(); };
  }, [taskApi, auth.token]);

  // La confirmación pertenece a una tarea concreta antes de iniciar.
  useEffect(() => {
    setConfirmation(null);
  }, [activeTask?.id, activeTask?.estado]);

  useEffect(() => {
    if (!activeTask?.reserva?.vence_at
      || activeTask.estado === 'en_proceso'
      || activeTask.maniobra?.custodia_temporal_activa) return undefined;
    const interval = setInterval(() => setClock(Date.now()), 1_000);
    return () => clearInterval(interval);
  }, [
    activeTask?.id,
    activeTask?.estado,
    activeTask?.maniobra?.custodia_temporal_activa,
    activeTask?.reserva?.vence_at,
  ]);

  useEffect(() => {
    if (!taskApi || !activeTask?.reserva || activeTask.estado === 'en_proceso' || leaseExpired) {
      return undefined;
    }
    const interval = setInterval(() => void runAction('Renovando reserva…', renewActiveTask), 4 * 60_000);
    return () => clearInterval(interval);
  }, [taskApi, activeTask?.id, activeTask?.estado, activeTask?.reserva?.id, leaseExpired]);

  useEffect(() => {
    if (!leaseExpired || !activeTask) return;
    reportError('La reserva venció antes de iniciar. Actualiza la bandeja y vuelve a tomar la labor.');
  }, [leaseExpired, activeTask?.id]);

  useOperationalPolling(
    () => loadTasks({ quiet: true, propagateError: true }),
    {
      enabled: Boolean(taskApi) && !actionLabel && !pendingMovement && !recoveryIssue && (
        activeTask === null
        || activeTask.maniobra?.estado === 'pausada_discrepancia'
      ),
      intervalMs: OPERATIONAL_POLL_INTERVAL_MS,
      onError: () => setConnectionIssue('No se pudo actualizar. Conservamos la última información recibida.'),
      onResume: () => pendingRef.current ? recoverMovement(false) : loadTasks({ quiet: true, propagateError: true }),
    },
  );

  function reportError(message: string) {
    // Conserva el error que el operador está leyendo, incluso durante el polling.
    setError((current) => current || message);
  }

  async function runAction<T>(label: string, action: () => Promise<T> | T): Promise<T | undefined> {
    if (!journalReady) return undefined;
    const owner = actionGate.begin();
    if (!owner) return undefined;
    reads.invalidate(); previewGeneration.current += 1;
    setActionLabel(label);
    try { return await action(); }
    catch (reason) { reportError(messageFrom(reason)); return undefined; }
    finally { if (actionGate.finish(owner)) setActionLabel(''); }
  }

  function rememberPending(entry: PendingOperationalMovement | null) {
    pendingRef.current = entry; setPendingMovement(entry);
  }

  async function recoverMovement(retry: boolean) {
    if (!taskApi) { setJournalReady(true); return; }
    setRecoveryIssue('');
    let captureRead = false;
    try {
      const entry = await movementStore.read();
      captureRead = true;
      rememberPending(entry);
      if (!entry) return;
      const state = await taskApi.ownState(auth.token, entry.taskId, entry.id);
      if (state.confirmada) {
        await movementStore.clear(entry.id); rememberPending(null);
        setNotice(`Entrega de ${entry.folio} confirmada por el servidor. No repitas el movimiento.`);
        await continueManeuver(state.tarea, retry);
      } else {
        setActiveTask(state.tarea);
        if (!retry) return;
        if (state.tarea.estado !== 'en_proceso') {
          throw new Error('La labor cambió antes de confirmar la entrega. Conserva esta captura y solicita revisión en Oficina.');
        }
        await transmitPending(entry);
        setNotice(`Entrega de ${entry.folio} confirmada por el servidor.`);
        await continueManeuver(state.tarea);
      }
    } catch (reason) {
      if (!captureRead || pendingRef.current) setRecoveryIssue(messageFrom(reason));
      else { setRecoveryIssue(''); reportError(messageFrom(reason)); }
    } finally { setJournalReady(true); }
  }

  async function persistMovement(task: OperationalTask, kind: PendingOperationalMovement['kind'],
    payload: PendingOperationalMovement['payload'], assignmentId?: string) {
    const entry: PendingOperationalMovement = { id: 'operacion_id' in payload ? payload.operacion_id : Crypto.randomUUID(),
      taskId: task.id, folio: task.folio.numero_folio, kind, payload, assignmentId, createdAt: new Date().toISOString() };
    await movementStore.save(entry); rememberPending(entry);
    await transmitPending(entry);
  }

  async function transmitPending(entry: PendingOperationalMovement) {
    if (!taskApi) return;
    const send = async (payload: PendingOperationalMovement['payload']) => {
      const saved = { ...entry, payload };
      await movementStore.save(saved); rememberPending(saved);
      if (entry.kind === 'move') await api.move(auth.token, payload as MovePayload);
      else if (entry.kind === 'locate') await api.locate(auth.token, payload as LocatePayload);
      else if (entry.kind === 'temporary') await taskApi.completeTemporaryExtraction(auth.token, entry.taskId, payload as TemporaryExtractionPayload);
      else if (entry.kind === 'dock') await api.sendLoadFolioToDock(auth.token, entry.assignmentId!, payload as SendLoadFolioToDockPayload);
      else await taskApi.completeDirectPrefrio(auth.token, entry.taskId);
    };
    try {
      await executeWithWarnings(entry.payload, send);
    } catch (reason) {
      if ((reason instanceof ApiError && reason.status >= 400 && reason.status < 500)
        || reason instanceof OperatorWarningCancelled) {
        const state = await taskApi.ownState(auth.token, entry.taskId, entry.id).catch(() => null);
        if (state?.confirmada) {
          await movementStore.clear(entry.id); rememberPending(null); return;
        }
        if (state) {
          await movementStore.clear(entry.id); rememberPending(null); setActiveTask(state.tarea);
          throw reason;
        }
      }
      setRecoveryIssue('No se recibió la confirmación. La entrega está guardada en esta tablet; revísala antes de repetir cualquier movimiento.');
      throw new Error('Confirmación pendiente. No repitas el movimiento físico; usa «Revisar confirmación».');
    }
    await movementStore.clear(entry.id); rememberPending(null); setRecoveryIssue('');
  }

  async function loadTasks({ quiet = false, propagateError = false }: { quiet?: boolean; propagateError?: boolean } = {}) {
    if (!taskApi) return;
    const generation = reads.generation();
    if (!quiet) setLoading(true);
    const own = reads.read('mine', () => taskApi.list(auth.token, 'mias'));
    const offered = reads.read('available', () => taskApi.list(auth.token, 'disponibles'));
    void offered.then((result) => {
      if (!result.current || !reads.isCurrent(generation)) return;
      setAvailable(result.value); setAvailableFresh(true);
    }).catch(() => {
      if (!reads.isCurrent(generation)) return;
      setAvailableFresh(false); setConnectionIssue('No se pudieron actualizar las labores disponibles.');
    });
    // Una verificación lenta no retrasa el movimiento ni la continuación de la maniobra.
    void reads.read('verification', () => taskApi.currentVerification(auth.token)).then((result) => {
      if (result.current && reads.isCurrent(generation)) setVerification(result.value);
    }).catch(() => undefined);
    void Promise.all([own, offered]).then(([self, others]) => {
      if (!self.current || !others.current || !reads.isCurrent(generation)) return;
      setConnectionIssue(''); void precalculate(self.value, others.value);
    }).catch(() => undefined);
    try {
      const result = await own;
      if (!result.current || !reads.isCurrent(generation)) return;
      setShiftContext(result.value.labores ?? null);
      setMine(result.value); setLastReadAt(new Date().toISOString());
      setActiveTask((current) => current ? result.value.find((task) => task.id === current.id)
        ?? (pendingRef.current?.taskId === current.id ? current : null) : current);
      if (initialLoad.current) {
        initialLoad.current = false; setTab(result.value.length ? 'mias' : 'disponibles');
      }
      return { mine: result.value, available };
    } catch (reason) {
      if (reads.isCurrent(generation)) setConnectionIssue('No se pudo actualizar. Conservamos la última información recibida.');
      if (propagateError) throw reason;
      if (!quiet) reportError(messageFrom(reason));
    } finally { if (!quiet) setLoading(false); }
  }

  async function verifyPosition(item: VerificationItem, number: string | null) {
    if (!taskApi) return;
    try {
      const response = await taskApi.recordVerification(auth.token, item.id, item.version, Crypto.randomUUID(), number);
      setVerification(response.data);
      setNotice(response.resultado === 'no_aplica'
        ? 'La posición cambió por un movimiento legítimo. Se asignó otra para este turno.'
        : response.resultado === 'coincide'
          ? 'Ubicación confirmada.'
          : 'Diferencia registrada para revisión en Oficina.');
    } catch (reason) {
      reportError(messageFrom(reason));
      setVerification(await taskApi.currentVerification(auth.token).catch(() => verification));
    }
  }

  async function precalculate(nextMine: OperationalTask[], nextAvailable: OperationalTask[]) {
    if (!taskApi) return;
    const generation = ++previewGeneration.current;
    preview.current = null;
    setSuggestedDestinations({});
    try {
      const snapshot = await taskApi.physicalFrontierSnapshot(auth.token);
      if (generation !== previewGeneration.current) return;
      setPlannerFresh(snapshot.arbitraje.vigencia.vigente);
      if (!snapshot.arbitraje.vigencia.vigente
        || snapshot.planner.horizon !== 'rolling'
        || snapshot.planner.compute !== 'tablet') return;

      const tasks = previewableTasks([...nextMine, ...nextAvailable]);
      if (!tasks.length) return;
      const plans = await cameraPlans.load(snapshot, tasks, (id) => api.getPlan(auth.token, id), () => generation === previewGeneration.current);
      if (generation !== previewGeneration.current) return;
      const result = simulateFrontierPreview(tasks, snapshot, plans);
      preview.current = result;
      setSuggestedDestinations(result.destinations);
    } catch {
      if (generation === previewGeneration.current) {
        preview.current = null;
        setPlannerFresh(null);
        setSuggestedDestinations({});
      }
      // La simulación no bloquea la bandeja: la materialización normal sigue disponible.
    }
  }

  async function takeTask(task: OperationalTask) {
    if (!taskApi) return;
    setNotice('');
    try {
      const taken = await taskApi.take(auth.token, task.id);
      replaceMine([taken]);
      setAvailable((current) => current.filter((item) => item.id !== taken.id));
      setActiveTask(taken);
      setTab('mias');
      setNotice(
        taken.tipo_movimiento === 'retiro'
          ? `Labor tomada. Entrega en ${operationalTaskDestinationLabel(taken)}.`
          : taken.reserva?.tipo_compromiso === 'fisica'
          ? `Tarea ${operationalTaskLabel(taken.plan.tipo)} tomada con destino físico reservado.`
          : `Tarea ${operationalTaskLabel(taken.plan.tipo)} tomada. Confirmaremos la posición antes de retirar el pallet.`,
      );
      if (taken.tipo_movimiento !== 'retiro'
        && (!taken.destino?.posicion || taken.reserva?.tipo_compromiso !== 'fisica')) {
        await calculateAndMaterializeFrontier(taken);
      }
      await loadTasks({ quiet: true });
    } catch (reason) {
      reportError(messageFrom(reason));
      await loadTasks({ quiet: true });
    }
  }

  async function beginTask(task: OperationalTask) {
    setActiveTask(task);
    setNotice('');
    if (task.estado === 'en_proceso') {
      setNotice('Movimiento ya iniciado: el destino está fijo hasta completar o registrar una incidencia.');
      return;
    }
    if ((task.reserva?.segundos_restantes ?? 600) < 180) await renewActiveTaskById(task.id);
    if (task.tipo_movimiento !== 'retiro'
      && (!task.destino?.posicion || task.reserva?.tipo_compromiso !== 'fisica')) {
      await calculateAndMaterializeFrontier(task);
    }
  }

  function openHomeTask(item: OperatorQueueItem) {
    if (pendingRef.current || recoveryIssue) return;
    if (item.source === 'available' && !availableFresh) {
      reportError('Actualiza las labores disponibles antes de tomar una nueva. Tus tareas propias se conservan.'); return;
    }
    const physical = mine.find((task) => task.estado === 'en_proceso' || task.maniobra?.custodia_temporal_activa);
    if (physical && physical.id !== item.task.id && (physical.estado === 'en_proceso'
      || !physical.maniobra?.id || physical.maniobra.id !== item.task.maniobra?.id)) {
      setActiveTask(physical); setNotice('Continúa el pallet o retorno que ya tienes bajo custodia antes de tomar otra labor.'); return;
    }
    if (item.source === 'mine') {
      void runAction('Preparando tu labor…', () => beginTask(item.task));
      return;
    }
    void runAction('Tomando labor…', () => takeTask(item.task));
  }

  async function renewActiveTask() {
    if (!activeTask) return;
    await renewActiveTaskById(activeTask.id);
  }

  async function renewActiveTaskById(taskId: string) {
    if (!taskApi) return;
    try {
      const renewed = await taskApi.renew(auth.token, taskId);
      setActiveTask((current) => current?.id === renewed.id ? renewed : current);
      replaceMine([renewed]);
      setClock(Date.now());
    } catch (reason) {
      reportError(messageFrom(reason));
      await loadTasks({ quiet: true });
    }
  }

  function requestRelease(task: OperationalTask) {
    if (task.estado === 'en_proceso') {
      Alert.alert(
        'Movimiento ya iniciado',
        'El pallet está en punto de no retorno. Completa el destino reservado o registra una incidencia.',
      );
      return;
    }

    Alert.alert(
      'Liberar tarea',
      task.reserva?.tipo_compromiso === 'fisica'
        ? 'La tarea volverá a la bandeja y su destino físico quedará disponible.'
        : 'La tarea volverá a la bandeja. Aún no existe una posición física comprometida.',
      [
        { text: 'Cancelar', style: 'cancel' },
        { text: 'Liberar', style: 'destructive', onPress: () => void runAction('Liberando reserva…', () => releaseTask(task)) },
      ],
    );
  }

  async function releaseTask(task: OperationalTask) {
    if (!taskApi || task.estado === 'en_proceso') return;
    try {
      await taskApi.release(auth.token, task.id);
      if (activeTask?.id === task.id) {
        setActiveTask(null);
      }
      setNotice('Tarea liberada y devuelta a la bandeja.');
      await loadTasks({ quiet: true });
    } catch (reason) {
      reportError(messageFrom(reason));
      await loadTasks({ quiet: true });
    }
  }

  async function calculateAndMaterializeFrontier(anchorTask: OperationalTask) {
    if (!taskApi) return;
    setActionLabel('Confirmando destino…');

    const epoch = sessionEpoch.current;
    const attempt = async () => {
      let snapshot = await taskApi.physicalFrontierSnapshot(auth.token);
      if (epoch !== sessionEpoch.current) return;
      if (snapshot.planner.horizon !== 'rolling' || snapshot.planner.compute !== 'tablet') {
        throw new Error(
          'La configuración actual no permite confirmar el destino. Solicita revisión en Oficina.',
        );
      }
      if (!snapshot.arbitraje.vigencia.vigente && snapshot.contrato_recursos !== 'recursos_v1') {
        throw new ApiError('El plan de trabajo se está actualizando. Mantén el pallet en su origen hasta confirmar el destino.', 503);
      }

      const reserved = snapshot.tareas.find((item) => item.id === anchorTask.id && item.destino_reservado);
      if (reserved) {
        const own = await taskApi.ownState(auth.token, anchorTask.id);
        if (epoch !== sessionEpoch.current) return;
        replaceMine([own.tarea]); setActiveTask(own.tarea);
        setNotice(`Destino reservado: ${operationalTaskPositionLabel(own.tarea.destino)}.`);
        return;
      }
      const cachedProposal = reusablePreviewProposal(preview.current, snapshot, anchorTask);
      if (cachedProposal) {
        try {
          const cachedResult = await taskApi.materializePhysicalFrontier(
            auth.token, snapshot.snapshot_version, [cachedProposal], snapshot.contrato_recursos === 'recursos_v1',
          );
          if (epoch !== sessionEpoch.current) return;
          const { accepted, recalculate } = materializationOutcome(cachedResult, anchorTask.id);
          if (accepted) {
            replaceMine([accepted]);
            setActiveTask(accepted);
            setNotice(`Destino reservado: ${operationalTaskPositionLabel(accepted.destino)}.${recalculate ? ' Otras propuestas requieren actualización.' : ''}`);
            preview.current = null;
            return;
          }
        } catch (reason) {
          if (!(reason instanceof ApiError && reason.status === 409)) throw reason;
        }
        // Un rechazo parcial o una versión obsoleta exige recalcular con estado nuevo.
        if (epoch !== sessionEpoch.current) return;
        snapshot = await taskApi.physicalFrontierSnapshot(auth.token);
        if (epoch !== sessionEpoch.current) return;
      }

      const tasksForFrontier = dedupeTasks([
        anchorTask,
        ...mine,
      ]).filter((task) => task.estado === 'asumida'
        && snapshot.tareas.some((item) => item.id === task.id && item.materializable));
      if (!snapshot.camaras.length) {
        throw new Error('No hay cámaras habilitadas para esta labor. Solicita revisión en Oficina.');
      }
      const plans = await cameraPlans.load(snapshot, tasksForFrontier, (id) => api.getPlan(auth.token, id));
      if (epoch !== sessionEpoch.current) return;
      const frontier = calculateRollingFrontier(tasksForFrontier, snapshot, plans, anchorTask.id);
      if (frontier.unresolvedTaskIds.includes(anchorTask.id)) {
        // Oficina queda informada de que este pallet no tiene destino; el aviso nunca
        // reemplaza el mensaje que lee el camarero ni detiene la acción.
        void taskApi.reportNoDestination?.(auth.token, anchorTask.id, plans.length).catch(() => undefined);
      }

      if (!frontier.proposals.length) {
        throw new Error('No hay una posición libre y compatible para esta labor. Mantén el pallet en su origen y solicita revisión en Oficina.');
      }

      const result = await taskApi.materializePhysicalFrontier(
        auth.token,
        snapshot.snapshot_version,
        frontier.proposals,
        snapshot.contrato_recursos === 'recursos_v1',
      );
      if (epoch !== sessionEpoch.current) return;
      const acceptedTasks = result.aceptadas.map((item) => item.tarea);
      replaceMine(acceptedTasks);
      const acceptedAnchor = acceptedTasks.find((task) => task.id === anchorTask.id);
      if (acceptedAnchor) {
        setActiveTask(acceptedAnchor);
        setNotice(
          `Destino reservado: ${operationalTaskPositionLabel(acceptedAnchor.destino)}.`,
        );
      } else {
        const rejected = result.rechazadas.find((item) => item.tarea_id === anchorTask.id);
        throw new ApiError(rejected?.motivo ?? 'El destino cambió; actualiza las labores antes de retirar el pallet.', 409);
      }

      if (result.recalcular && acceptedAnchor) {
        setNotice(
          'Tu destino está reservado. Hay otras propuestas pendientes de actualización.',
        );
      }
    };
    try {
      await recoverPhysicalFrontier(attempt, () => {
        cameraPlans.clear(); preview.current = null; setActionLabel('Actualizando destino…');
      }, () => epoch === sessionEpoch.current);
    } catch (reason) {
      if (epoch !== sessionEpoch.current) return;
      reportError(messageFrom(reason));
      await loadTasks({ quiet: true });
    }
  }

  function canStartPhysicalTask(task: OperationalTask | null): task is OperationalTask {
    if (!taskApi || !task || task.estado === 'en_proceso') return false;
    if (task.tipo_movimiento !== 'retiro'
      && (!task.destino?.posicion || task.reserva?.tipo_compromiso !== 'fisica')) {
      reportError('Primero debe existir un destino físico validado por el servidor.');
      return false;
    }
    return true;
  }

  async function refreshPinStatus() {
    if (!taskApi) return;
    try {
      const pinStatus = await taskApi.pinStatus(auth.token);
      setConfirmation((current) => current ? { ...current, pinStatus } : current);
    } catch (reason) {
      setConfirmation(null);
      reportError(messageFrom(reason));
    }
  }

  function openStartConfirmation() {
    if (!canStartPhysicalTask(activeTask)) return;
    setConfirmation({ pinStatus: null, error: '' });
    void refreshPinStatus();
  }

  async function createOperatorPin(pin: string) {
    if (!taskApi) return false;
    try {
      const pinStatus = await taskApi.savePin(auth.token, pin);
      setConfirmation((current) => current ? { pinStatus, error: '' } : current);
      return true;
    } catch (reason) {
      setConfirmation((current) => current ? { ...current, error: messageFrom(reason) } : current);
      return false;
    }
  }

  async function startPhysicalTask(folioConfirmation: StartConfirmation) {
    if (!canStartPhysicalTask(activeTask)) return;

    setConfirmation((current) => current ? { ...current, error: '' } : current);
    const sessions: OpenSession[] = [];
    try {
      await acquireExecutionSessions(activeTask, sessions);
      const started = await taskApi!.start(auth.token, activeTask.id, folioConfirmation);
      executionSessions.current = sessions;
      setConfirmation(null);
      setActiveTask(started);
      replaceMine([started]);
      setNotice(
        `PALLET EN MOVIMIENTO · ${started.folio.numero_folio}. `
        + `Desde este punto el destino ${operationalTaskDestinationLabel(started)} no puede recalcularse.`,
      );
    } catch (reason) {
      await closeTemporarySessions(sessions);
      if (reason instanceof ApiError && reason.status === 422) {
        // Folio o PIN rechazados: se corrige dentro de la misma confirmación.
        setConfirmation((current) => current ? { ...current, error: messageFrom(reason) } : current);
        await refreshPinStatus();
        return;
      }
      setConfirmation(null);
      reportError(messageFrom(reason));
      await loadTasks({ quiet: true });
    }
  }

  async function completeTask() {
    if (!activeTask || activeTask.estado !== 'en_proceso') return;
    if (pendingRef.current) { await recoverMovement(true); return; }
    const task = activeTask;
    const destination = task.destino;
    if (!destination?.posicion) {
      reportError('La tarea en movimiento no posee una posición física de destino. Registra una incidencia.');
      return;
    }

    setNotice('');
    const sessions = executionSessions.current.length
      ? executionSessions.current
      : [];

    try {
      if (!sessions.length) await acquireExecutionSessions(task, sessions);
      const destinationSession = sessionForCamera(sessions, destination.camara.id);
      const sourceSession = task.origen?.camara
        ? sessionForCamera(sessions, task.origen.camara.id)
        : null;

      if (task.origen?.posicion && sourceSession) {
        const payload: MovePayload = {
          operacion_id: Crypto.randomUUID(),
          tarea_movimiento_id: task.id,
          folio_id: task.folio.id,
          posicion_destino_id: destination.posicion.id,
          sesion_origen_id: sourceSession.sessionId,
          sesion_destino_id: destinationSession.sessionId,
          version_origen_conocida: sourceSession.plan.version_plano,
          version_destino_conocida: destinationSession.plan.version_plano,
          generado_dispositivo_at: new Date().toISOString(),
        };
        await persistMovement(task, 'move', payload);
      } else if (task.tipo_movimiento === 'ubicacion_inicial') {
        const payload: LocatePayload = {
          operacion_id: Crypto.randomUUID(),
          tarea_movimiento_id: task.id,
          numero_folio: task.folio.numero_folio,
          tipo_bulto: task.folio.tipo_bulto,
          camara_destino_id: destination.camara.id,
          posicion_destino_id: destination.posicion.id,
          sesion_destino_id: destinationSession.sessionId,
          version_destino_conocida: destinationSession.plan.version_plano,
          generado_dispositivo_at: new Date().toISOString(),
        };
        await persistMovement(task, 'locate', payload);
      } else {
        throw new Error('La tarea no posee un origen físico compatible con este movimiento.');
      }

      setNotice(`Movimiento completado: ${task.folio.numero_folio} → ${operationalTaskPositionLabel(task.destino)}.`);
      await continueManeuver(task);
    } catch (reason) {
      reportError(messageFrom(reason));
      await loadTasks({ quiet: true });
    } finally {
      await closeTemporarySessions(sessions);
      executionSessions.current = [];
    }
  }

  async function completeDirectWithdrawal() {
    if (!taskApi
      || !activeTask
      || activeTask.estado !== 'en_proceso'
      || activeTask.tipo_movimiento !== 'retiro'
      || !activeTask.destino_logico?.carga_folio_id) return;
    if (pendingRef.current) { await recoverMovement(true); return; }

    const task = activeTask;
    const directDestination = activeTask.destino_logico;
    const fromPrefrio = task.contexto?.origen_logico === 'tunel_prefrio';
    const sessions = executionSessions.current.length
      ? executionSessions.current
      : [];
    setNotice('');

    try {
      if (fromPrefrio) {
        await persistMovement(task, 'prefrio', {});
      } else {
        if (!sessions.length) await acquireExecutionSessions(task, sessions);
        if (!task.origen?.camara) throw new Error('La tarea no conserva una cámara de origen.');
        const sourceSession = sessionForCamera(sessions, task.origen.camara.id);
        const payload: SendLoadFolioToDockPayload = {
          operacion_id: Crypto.randomUUID(),
          tarea_movimiento_id: task.id,
          anden_id: directDestination.id,
          sesion_estiba_id: sourceSession.sessionId,
          version_camara_conocida: sourceSession.plan.version_plano,
          generado_dispositivo_at: new Date().toISOString(),
        };
        await persistMovement(task, 'dock', payload, directDestination.carga_folio_id);
      }

      setNotice(
        `Retiro completado: ${task.folio.numero_folio} entregado en ${directDestination.nombre}.`,
      );
      await continueManeuver(task);
    } catch (reason) {
      reportError(messageFrom(reason));
      await loadTasks({ quiet: true });
    } finally {
      await closeTemporarySessions(sessions);
      executionSessions.current = [];
    }
  }

  async function completeTemporaryExtraction() {
    if (!taskApi
      || !activeTask
      || activeTask.estado !== 'en_proceso'
      || activeTask.tipo_paso_maniobra !== 'extraccion_temporal') return;
    if (pendingRef.current) { await recoverMovement(true); return; }

    const task = activeTask;
    const sessions = executionSessions.current.length ? executionSessions.current : [];
    try {
      if (!sessions.length) await acquireExecutionSessions(task, sessions);
      if (!task.origen?.camara) throw new Error('La extracción no conserva su cámara de origen.');
      const sourceSession = sessionForCamera(sessions, task.origen.camara.id);
      const payload: TemporaryExtractionPayload = {
        operacion_id: Crypto.randomUUID(),
        sesion_origen_id: sourceSession.sessionId,
        version_origen_conocida: sourceSession.plan.version_plano,
        generado_dispositivo_at: new Date().toISOString(),
      };
      await persistMovement(task, 'temporary', payload);
      setNotice(
        `${task.folio.numero_folio} quedó bajo custodia temporal de la maniobra. Continúa con el siguiente paso mostrado.`,
      );
      await continueManeuver(task);
    } catch (reason) {
      reportError(messageFrom(reason));
      await loadTasks({ quiet: true });
    } finally {
      await closeTemporarySessions(sessions);
      executionSessions.current = [];
    }
  }

  async function sendDiscrepancy(
    type: ManeuverDiscrepancyType,
    detail: string,
  ): Promise<ReportedManeuverDiscrepancy | null> {
    if (!taskApi || !activeTask?.maniobra) {
      reportError('La gestión de incidencias está disponible para maniobras físicas del planificador.');
      return null;
    }
    try {
      const reported = await taskApi.reportDiscrepancy(auth.token, activeTask.id, type, detail);
      const refreshed = await loadTasks({ quiet: true });
      const paused = refreshed?.mine.find((task) => task.id === activeTask.id) ?? null;
      setActiveTask(paused);
      setNotice(
        'Maniobra pausada y visible en tu jornada. La misma tablet recibirá la decisión de supervisión.',
      );
      return reported;
    } catch (reason) {
      reportError(messageFrom(reason));
      return null;
    }
  }

  async function continueManeuver(completedTask: OperationalTask, prepare = true) {
    reads.invalidate();
    setActiveTask(null);
    const refreshed = await loadTasks({ quiet: true });
    if (!completedTask.maniobra || !refreshed) return;

    const next = refreshed.mine.find((task) => (
      task.maniobra?.id === completedTask.maniobra?.id
      && (task.secuencia_maniobra ?? 0) > (completedTask.secuencia_maniobra ?? 0)
    ));
    if (!next) return;

    setActiveTask(next);
    setTab('mias');
    setNotice(
      `Continúa la misma maniobra: paso ${next.secuencia_maniobra ?? next.maniobra?.secuencia_actual} `
      + `de ${next.maniobra?.pasos_totales} · ${next.folio.numero_folio}.`,
    );
    if (prepare && next.tipo_movimiento !== 'retiro'
      && (!next.destino?.posicion || next.reserva?.tipo_compromiso !== 'fisica')) {
      await calculateAndMaterializeFrontier(next);
    }
  }

  async function acquireExecutionSessions(task: OperationalTask, sessions: OpenSession[]) {
    if (task.tipo_movimiento === 'retiro') {
      if (task.contexto?.origen_logico === 'tunel_prefrio') return;
      if (!task.origen?.camara) throw new Error('La tarea no posee cámara de origen.');
      await acquireSession(task.origen.camara.id, sessions, 'origen', task);
      return;
    }
    if (!task.destino?.camara) throw new Error('La tarea no posee cámara de destino materializada.');
    const required = new Map<string, 'origen' | 'destino'>([[task.destino.camara.id, 'destino']]);
    if (task.origen?.camara) required.set(task.origen.camara.id, task.origen.camara.id === task.destino.camara.id ? 'destino' : 'origen');
    for (const [id, role] of [...required].sort(([left], [right]) => left.localeCompare(right))) await acquireSession(id, sessions, role, task);
  }

  async function acquireSession(cameraId: string, sessions: OpenSession[], role: 'origen' | 'destino', task?: OperationalTask) {
    const existing = sessions.find((session) => session.cameraId === cameraId);
    if (existing) return existing;

    const plan = await api.getPlan(auth.token, cameraId);
    if (task?.permite_sesion_por_tarea) {
      const opened = await api.openSession(auth.token, cameraId, task.id);
      const scoped: OpenSession = { cameraId, sessionId: opened.id, openedByTask: true, plan };
      sessions.push(scoped);
      scoped.plan = await api.getPlan(auth.token, cameraId);
      return scoped;
    }
    if (plan.acceso.modo === 'solo_lectura') {
      throw new Error(cameraSessionConflictMessage(plan, role));
    }

    if (plan.acceso.modo === 'edicion' && plan.acceso.sesion?.es_propia) {
      const own: OpenSession = {
        cameraId,
        sessionId: plan.acceso.sesion.id,
        openedByTask: false,
        plan,
      };
      sessions.push(own);
      return own;
    }

    if (plan.acceso.modo !== 'disponible') {
      throw new Error(`${plan.nombre} no está disponible para ejecutar la tarea.`);
    }

    let opened;
    try {
      opened = await api.openSession(auth.token, cameraId);
    } catch (reason) {
      // Otro operador puede abrir la cámara entre la lectura del plano y el POST.
      if (reason instanceof ApiError && reason.status === 409
        && /cámara.*(?:modificada|sesión|uso)/i.test(reason.message)) {
        throw new Error(cameraSessionConflictMessage(plan, role));
      }
      throw reason;
    }
    const session: OpenSession = {
      cameraId,
      sessionId: opened.id,
      openedByTask: true,
      plan,
    };
    sessions.push(session);
    // Registrar el recurso antes del siguiente GET permite cerrarlo si falla la red.
    session.plan = await api.getPlan(auth.token, cameraId);
    return session;
  }

  function sessionForCamera(sessions: OpenSession[], cameraId: string) {
    const session = sessions.find((candidate) => candidate.cameraId === cameraId);
    if (!session) throw new Error('La sesión física requerida por la tarea ya no está disponible.');
    return session;
  }

  async function closeTemporarySessions(sessions: OpenSession[]) {
    await Promise.all(sessions
      .filter((session) => session.openedByTask)
      .map((session) => api.closeSession(auth.token, session.sessionId).catch(() => undefined)));
  }

  async function executeWithWarnings<T extends { advertencias_confirmadas?: string[] }>(
    payload: T,
    operation: (confirmedPayload: T) => Promise<void>,
  ) {
    try {
      await operation(payload);
    } catch (reason) {
      const warnings = warningResponse(reason);
      if (!warnings.length) throw reason;

      const accepted = await confirmWarnings(warnings);
      if (!accepted) throw new OperatorWarningCancelled('Operación cancelada: no se confirmaron las advertencias físicas.');

      await operation({
        ...payload,
        advertencias_confirmadas: warnings.map((warning) => warning.codigo),
      });
    }
  }

  function confirmWarnings(warnings: MovementWarning[]): Promise<boolean> {
    return new Promise((resolve) => {
      Alert.alert(
        'Confirmar excepción física',
        warnings.map((warning) => `${warning.titulo}\n${warning.mensaje}`).join('\n\n'),
        [
          { text: 'Cancelar', style: 'cancel', onPress: () => resolve(false) },
          { text: 'Continuar', style: 'destructive', onPress: () => resolve(true) },
        ],
        { cancelable: false },
      );
    });
  }

  function replaceMine(tasks: OperationalTask[]) {
    if (!tasks.length) return;
    const byId = new Map(tasks.map((task) => [task.id, task]));
    setMine((current) => [...current.map((task) => byId.get(task.id) ?? task),
      ...tasks.filter((task) => !current.some((item) => item.id === task.id))]);
  }

  if (!taskApi) {
    return (
      <View style={styles.emptyStandalone}>
        <Text style={styles.emptyIcon}>◎</Text>
        <Text style={styles.emptyTitle}>Labores guiadas disponibles en operación conectada</Text>
        <Text style={styles.emptyCopy}>
          El modo demo conserva el plano y las operaciones actuales sin consultar tareas productivas.
        </Text>
      </View>
    );
  }

  return (
    <View style={styles.screen}>
      <OperatorErrorDialog message={error} onAcknowledge={() => setError('')} />
      <View accessibilityLiveRegion="polite" style={styles.syncStatus}>
        <Text style={[styles.syncText, connectionIssue ? styles.syncWarning : null]}>
          {connectionIssue || (lastReadAt ? `Mis tareas actualizadas a las ${new Date(lastReadAt).toLocaleTimeString('es-CL')}` : 'Consultando tus labores…')}
        </Text>
      </View>
      {notice ? (
        <Pressable onPress={() => setNotice('')} style={styles.noticeBanner}>
          <Text style={styles.noticeText}>{notice}</Text>
          <Text style={styles.bannerClose}>×</Text>
        </Pressable>
      ) : null}

      {auth.usuario.capacidades?.puede_consultar_cargas && !showLoads && !pendingMovement && !recoveryIssue ? (
        <Pressable accessibilityRole="button" disabled={busy} onPress={() => setShowLoads(true)} style={styles.loadsButton}>
          <Text style={styles.loadsButtonText}>Cargas activas y terminadas del turno →</Text>
        </Pressable>
      ) : null}

      {pendingMovement || recoveryIssue ? (
        <View style={styles.recoveryPanel}>
          <Text accessibilityRole="header" style={styles.recoveryTitle}>Confirmación pendiente</Text>
          {pendingMovement ? <Text style={styles.recoveryFolio}>Folio {pendingMovement.folio}</Text> : null}
          <Text style={styles.recoveryCopy}>{recoveryIssue || 'La entrega está guardada en esta tablet. Comprueba el resultado antes de repetir el movimiento físico.'}</Text>
          <Pressable accessibilityRole="button" disabled={busy} onPress={() => void runAction('Revisando confirmación…', () => recoverMovement(true))} style={styles.recoveryButton}>
            <Text style={styles.recoveryButtonText}>Revisar confirmación</Text>
          </Pressable>
        </View>
      ) : showLoads ? (
        <OperatorLoadStatus api={taskApi} token={auth.token} onBack={() => setShowLoads(false)} />
      ) : activeTask ? (
        <OperatorTaskExecution
          busy={busy}
          confirmation={confirmation}
          deviceName={auth.dispositivo.nombre}
          hasPhysicalDestination={hasPhysicalDestination}
          leaseExpired={leaseExpired}
          onBack={() => setActiveTask(null)}
          onCancelConfirmation={() => setConfirmation(null)}
          onComplete={() => void runAction('Guardando entrega…', completeTask)}
          onCompleteDirect={() => void runAction('Confirmando entrega en andén…', completeDirectWithdrawal)}
          onCompleteTemporary={() => void runAction('Confirmando retiro temporal…', completeTemporaryExtraction)}
          onReportException={async (type, detail) => (await runAction('Registrando incidencia…', () => sendDiscrepancy(type, detail))) ?? null}
          onConfirmStart={(folioConfirmation) => void runAction('Validando retiro…', () => startPhysicalTask(folioConfirmation))}
          onCreatePin={async (pin) => Boolean(await runAction('Guardando PIN…', () => createOperatorPin(pin)))}
          onRecalculate={() => void runAction('Confirmando destino…', () => calculateAndMaterializeFrontier(activeTask))}
          onRelease={() => requestRelease(activeTask)}
          onStart={openStartConfirmation}
          operatorName={auth.usuario.nombre}
          secondsRemaining={secondsRemaining}
          task={activeTask}
        />
      ) : (
        <OperatorTaskHome
          shiftContext={shiftContext}
          availableCount={available.length}
          busy={busy}
          mineCount={mine.length}
          next={home.next}
          plannerFresh={plannerFresh}
          onOpen={openHomeTask}
          onRefresh={() => void loadTasks()}
          onViewChange={(source) => setTab(source === 'mine' ? 'mias' : 'disponibles')}
          queue={homeQueue}
          suggestedDestinations={suggestedDestinations}
          view={homeView}
          verification={<TurnVerification round={verification} busy={busy} onVerify={(item, number) => void runAction('Guardando verificación…', () => verifyPosition(item, number))} />}
        />
      )}

      {busy ? (
        <View pointerEvents="auto" style={styles.busyOverlay}>
          <ActivityIndicator color={o.color.primary} size="large" />
          <Text style={styles.busyText}>{actionLabel || (!journalReady ? 'Revisando confirmaciones guardadas…' : 'Actualizando tus labores…')}</Text>
        </View>
      ) : null}
    </View>
  );
}

class OperatorWarningCancelled extends Error {}

function dedupeTasks(tasks: OperationalTask[]) {
  return [...new Map(tasks.map((task) => [task.id, task])).values()];
}

function warningResponse(reason: unknown): MovementWarning[] {
  if (!(reason instanceof ApiError) || !reason.data || typeof reason.data !== 'object') return [];
  const data = reason.data as { codigo?: string; advertencias?: MovementWarning[] };
  return data.codigo === 'confirmacion_requerida' && Array.isArray(data.advertencias)
    ? data.advertencias
    : [];
}

function formatDuration(seconds: number) {
  const minutes = Math.floor(seconds / 60);
  const remainder = seconds % 60;
  return `${String(minutes).padStart(2, '0')}:${String(remainder).padStart(2, '0')}`;
}

function messageFrom(reason: unknown) {
  return reason instanceof Error ? reason.message : 'La operación no pudo completarse.';
}

const styles = StyleSheet.create({
  syncStatus: { paddingBottom: o.space[2] },
  syncText: { color: o.color.muted, fontSize: o.type.small },
  syncWarning: { color: o.color.warning },
  recoveryPanel: { padding: o.space[4], gap: o.space[3], borderWidth: 1, borderColor: o.color.warning, borderRadius: o.radius.panel, backgroundColor: o.color.surface },
  recoveryTitle: { color: o.color.warning, fontSize: o.type.heading, fontWeight: '800' },
  recoveryFolio: { color: o.color.text, fontSize: o.type.heading, fontWeight: '900' },
  recoveryCopy: { color: o.color.textSecondary, fontSize: o.type.body },
  recoveryButton: { minHeight: o.touch.minimum, padding: o.space[3], justifyContent: 'center', backgroundColor: o.color.primary, borderRadius: o.radius.control },
  recoveryButtonText: { color: o.color.onPrimary, fontSize: o.type.body, fontWeight: '800' },
  loadsButton: { minHeight: o.touch.minimum, justifyContent: 'center', padding: 14, marginBottom: 12, borderWidth: 1, borderColor: o.color.primary, borderRadius: o.radius.control, backgroundColor: o.color.surface },
  loadsButtonText: { color: o.color.primaryPressed, fontSize: o.type.body, fontWeight: '800' },
  screen: { flex: 1, padding: o.space[4], backgroundColor: o.color.canvas },
  noticeBanner: { flexDirection: 'row', justifyContent: 'space-between', gap: o.space[3], padding: o.space[3], borderRadius: o.radius.control, borderWidth: 1, borderLeftWidth: 5, borderColor: o.color.success, backgroundColor: o.color.successSurface, marginBottom: o.space[2] },
  noticeText: { color: o.color.success, flex: 1, fontSize: o.type.small, fontWeight: '800' },
  bannerClose: { color: o.color.muted, fontSize: 20, fontWeight: '900' },
  emptyStandalone: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: o.space[8], backgroundColor: o.color.canvas },
  emptyIcon: { color: o.color.primary, fontSize: 34, fontWeight: '900' },
  emptyTitle: { color: o.color.text, fontSize: o.type.body, fontWeight: '900', marginTop: o.space[2], textAlign: 'center' },
  emptyCopy: { color: o.color.muted, fontSize: o.type.small, lineHeight: 20, marginTop: o.space[1], textAlign: 'center', maxWidth: 520 },
  busyOverlay: { ...StyleSheet.absoluteFillObject, backgroundColor: 'rgba(18,51,66,0.82)', alignItems: 'center', justifyContent: 'center', gap: o.space[3] },
  busyText: { color: o.color.onNavy, fontSize: o.type.small, fontWeight: '900' },
});
