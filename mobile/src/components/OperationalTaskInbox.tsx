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
  ReportedManeuverDiscrepancy,
  TemporaryExtractionPayload,
  operationalTaskDestinationLabel,
  operationalTaskLabel,
  operationalTaskPositionLabel,
} from '../domain/operationalTasks';
import { buildOperatorTaskHome, type OperatorQueueItem } from '../domain/operatorTaskQueue';
import type { ShiftVerification, VerificationItem } from '../domain/verification';
import { calculateRollingFrontier } from '../domain/rollingPlanner';
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
import { OperatorTaskExecution } from './operator/OperatorTaskExecution';
import { OperatorTaskHome } from './operator/OperatorTaskHome';
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
  const [mine, setMine] = useState<OperationalTask[]>([]);
  const [tab, setTab] = useState<TaskTab>('mias');
  const [activeTask, setActiveTask] = useState<OperationalTask | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [confirmation, setConfirmation] = useState<FolioConfirmationState | null>(null);
  const [clock, setClock] = useState(Date.now());
  const [plannerFresh, setPlannerFresh] = useState<boolean | null>(null);
  const [suggestedDestinations, setSuggestedDestinations] = useState<Record<string, string>>({});
  const [verification, setVerification] = useState<ShiftVerification | null>(null);
  const initialLoad = useRef(true);
  const loadInFlight = useRef(false);
  const preview = useRef<FrontierPreview | null>(null);
  const previewGeneration = useRef(0);
  const executionSessions = useRef<OpenSession[]>([]);

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
    void loadTasks();
    return () => { previewGeneration.current += 1; preview.current = null; };
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
    const interval = setInterval(() => void renewActiveTask(), 4 * 60_000);
    return () => clearInterval(interval);
  }, [taskApi, activeTask?.id, activeTask?.estado, activeTask?.reserva?.id, leaseExpired]);

  useEffect(() => {
    if (!leaseExpired || !activeTask) return;
    setError('El claim venció antes de iniciar el movimiento. Actualiza la bandeja y vuelve a tomar la tarea.');
  }, [leaseExpired, activeTask?.id]);

  useOperationalPolling(
    () => loadTasks({ quiet: true }),
    {
      enabled: Boolean(taskApi) && (
        activeTask === null
        || activeTask.maniobra?.estado === 'pausada_discrepancia'
      ),
      intervalMs: OPERATIONAL_POLL_INTERVAL_MS,
      onError: (reason) => setError(messageFrom(reason)),
      onResume: () => loadTasks({ quiet: true }),
    },
  );

  async function loadTasks({ quiet = false }: { quiet?: boolean } = {}) {
    if (!taskApi || loadInFlight.current) return;
    loadInFlight.current = true;
    if (!quiet) setBusy(true);

    try {
      const [nextMine, nextAvailable, nextVerification] = await Promise.all([
        taskApi.list(auth.token, 'mias'),
        taskApi.list(auth.token, 'disponibles'),
        taskApi.currentVerification(auth.token).catch(() => null),
      ]);
      setMine(nextMine);
      setAvailable(nextAvailable);
      setVerification(nextVerification);
      setError('');
      void precalculate(nextMine, nextAvailable);

      setActiveTask((current) => {
        if (!current) return current;
        return nextMine.find((task) => task.id === current.id) ?? null;
      });

      if (initialLoad.current) {
        initialLoad.current = false;
        setTab(nextMine.length > 0 ? 'mias' : 'disponibles');
      }

      return { mine: nextMine, available: nextAvailable };
    } catch (reason) {
      setError(messageFrom(reason));
    } finally {
      loadInFlight.current = false;
      if (!quiet) setBusy(false);
    }
  }

  async function verifyPosition(item: VerificationItem, number: string | null) {
    if (!taskApi) return;
    setBusy(true);
    try {
      const response = await taskApi.recordVerification(auth.token, item.id, item.version, Crypto.randomUUID(), number);
      setVerification(response.data);
      setNotice(response.resultado === 'no_aplica'
        ? 'La posición cambió por un movimiento legítimo. Se asignó otra para este turno.'
        : response.resultado === 'coincide'
          ? 'Ubicación confirmada.'
          : 'Diferencia registrada para revisión en Oficina.');
    } catch (reason) {
      setError(messageFrom(reason));
      setVerification(await taskApi.currentVerification(auth.token).catch(() => verification));
    } finally {
      setBusy(false);
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
      const directedCameraIds = new Set(snapshot.camaras.map((camera) => camera.id));
      const cameras = (await api.listCameras(auth.token))
        .filter((camera) => camera.contenido === 'productos'
          && camera.estado === 'activa'
          && directedCameraIds.has(camera.id));
      if (generation !== previewGeneration.current || !cameras.length) return;
      const requiredIds = candidateCameraIds(tasks, cameras.map((camera) => camera.id));
      const plans = await Promise.all([...requiredIds].map((id) => api.getPlan(auth.token, id)));
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
    setBusy(true);
    setError('');
    setNotice('');
    try {
      const taken = await taskApi.take(auth.token, task.id);
      setActiveTask(taken);
      setTab('mias');
      setNotice(
        taken.tipo_movimiento === 'retiro'
          ? `Tarea crítica tomada. Destino directo: ${operationalTaskDestinationLabel(taken)}.`
          : taken.reserva?.tipo_compromiso === 'fisica'
          ? `Tarea ${operationalTaskLabel(taken.plan.tipo)} tomada con destino físico reservado.`
          : `Tarea ${operationalTaskLabel(taken.plan.tipo)} tomada. El destino se calculará con el snapshot vigente.`,
      );
      if (taken.tipo_movimiento !== 'retiro'
        && (!taken.destino?.posicion || taken.reserva?.tipo_compromiso !== 'fisica')) {
        await calculateAndMaterializeFrontier(taken);
      }
      await loadTasks({ quiet: true });
    } catch (reason) {
      setError(messageFrom(reason));
      await loadTasks({ quiet: true });
    } finally {
      setBusy(false);
    }
  }

  function beginTask(task: OperationalTask) {
    setActiveTask(task);
    setError('');
    setNotice('');
    if (task.estado === 'en_proceso') {
      setNotice('Movimiento ya iniciado: el destino está fijo hasta completar o registrar una incidencia.');
      return;
    }
    if ((task.reserva?.segundos_restantes ?? 600) < 180) void renewActiveTaskById(task.id);
    if (task.tipo_movimiento !== 'retiro'
      && (!task.destino?.posicion || task.reserva?.tipo_compromiso !== 'fisica')) {
      void calculateAndMaterializeFrontier(task);
    }
  }

  function openHomeTask(item: OperatorQueueItem) {
    if (item.source === 'mine') {
      beginTask(item.task);
      return;
    }
    void takeTask(item.task);
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
      setError(messageFrom(reason));
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
        { text: 'Liberar', style: 'destructive', onPress: () => void releaseTask(task) },
      ],
    );
  }

  async function releaseTask(task: OperationalTask) {
    if (!taskApi || task.estado === 'en_proceso') return;
    setBusy(true);
    setError('');
    try {
      await taskApi.release(auth.token, task.id);
      if (activeTask?.id === task.id) {
        setActiveTask(null);
      }
      setNotice('Tarea liberada y devuelta a la bandeja.');
      await loadTasks({ quiet: true });
    } catch (reason) {
      setError(messageFrom(reason));
      await loadTasks({ quiet: true });
    } finally {
      setBusy(false);
    }
  }

  async function calculateAndMaterializeFrontier(anchorTask: OperationalTask) {
    if (!taskApi) return;
    setBusy(true);
    setError('');

    try {
      let snapshot = await taskApi.physicalFrontierSnapshot(auth.token);
      if (snapshot.planner.horizon !== 'rolling' || snapshot.planner.compute !== 'tablet') {
        throw new Error(
          `El planificador está configurado como ${snapshot.planner.compute}/${snapshot.planner.horizon}; no corresponde cálculo rolling en tablet.`,
        );
      }
      if (!snapshot.arbitraje.vigencia.vigente) {
        throw new Error(
          `El arbitraje está ${snapshot.arbitraje.vigencia.estado}. `
          + `${snapshot.arbitraje.vigencia.detalle} Espera la próxima actualización antes de mover el pallet.`,
        );
      }

      const cachedProposal = reusablePreviewProposal(preview.current, snapshot, anchorTask);
      if (cachedProposal) {
        try {
          const cachedResult = await taskApi.materializePhysicalFrontier(
            auth.token, snapshot.snapshot_version, [cachedProposal],
          );
          const { accepted, recalculate } = materializationOutcome(cachedResult, anchorTask.id);
          if (accepted) {
            replaceMine([accepted]);
            setActiveTask(accepted);
            setNotice(`Destino sugerido confirmado: ${operationalTaskPositionLabel(accepted.destino)}.${recalculate ? ' Otras propuestas requieren actualización.' : ''}`);
            preview.current = null;
            return;
          }
        } catch (reason) {
          if (!(reason instanceof ApiError && reason.status === 409)) throw reason;
        }
        // Un rechazo parcial o una versión obsoleta exige recalcular con estado nuevo.
        snapshot = await taskApi.physicalFrontierSnapshot(auth.token);
      }

      const tasksForFrontier = dedupeTasks([
        anchorTask,
        ...mine,
      ]).filter((task) => task.estado === 'asumida'
        && snapshot.tareas.some((item) => item.id === task.id && item.materializable));
      const directedCameraIds = new Set(snapshot.camaras.map((camera) => camera.id));
      const cameras = (await api.listCameras(auth.token))
        .filter((camera) => camera.contenido === 'productos'
          && camera.estado === 'activa'
          && directedCameraIds.has(camera.id));
      if (!cameras.length) {
        throw new Error('No existen cámaras dirigidas disponibles en el rollout vigente.');
      }
      const requiredIds = candidateCameraIds(tasksForFrontier, cameras.map((camera) => camera.id));
      const plans = await Promise.all([...requiredIds].map((cameraId) => api.getPlan(auth.token, cameraId)));
      const frontier = calculateRollingFrontier(tasksForFrontier, snapshot, plans);

      if (!frontier.proposals.length) {
        throw new Error('El snapshot no contiene un destino libre y compatible para la frontera actual.');
      }

      const result = await taskApi.materializePhysicalFrontier(
        auth.token,
        snapshot.snapshot_version,
        frontier.proposals,
      );
      const acceptedTasks = result.aceptadas.map((item) => item.tarea);
      replaceMine(acceptedTasks);
      const acceptedAnchor = acceptedTasks.find((task) => task.id === anchorTask.id);
      if (acceptedAnchor) {
        setActiveTask(acceptedAnchor);
        setNotice(
          `Frontera validada: ${acceptedTasks.length} tarea(s) con reserva física. `
          + `Destino actual: ${operationalTaskPositionLabel(acceptedAnchor.destino)}.`,
        );
      } else {
        const rejected = result.rechazadas.find((item) => item.tarea_id === anchorTask.id);
        setError(
          rejected?.motivo
            ?? 'La tarea quedó fuera de la frontera inmediata. Actualiza el estado antes de ejecutarla.',
        );
      }

      if (result.recalcular && acceptedAnchor) {
        setNotice(
          `Frontera parcial: ${acceptedTasks.length} aceptada(s), ${result.rechazadas.length} reemplazada(s). `
          + `La tablet recalculará con el nuevo snapshot en el siguiente ciclo.`,
        );
      }
    } catch (reason) {
      setError(messageFrom(reason));
      await loadTasks({ quiet: true });
    } finally {
      setBusy(false);
    }
  }

  function canStartPhysicalTask(task: OperationalTask | null): task is OperationalTask {
    if (!taskApi || !task || task.estado === 'en_proceso') return false;
    if (task.tipo_movimiento !== 'retiro'
      && (!task.destino?.posicion || task.reserva?.tipo_compromiso !== 'fisica')) {
      setError('Primero debe existir un destino físico validado por el servidor.');
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
      setError(messageFrom(reason));
    }
  }

  function openStartConfirmation() {
    if (!canStartPhysicalTask(activeTask)) return;
    setError('');
    setConfirmation({ pinStatus: null, error: '' });
    void refreshPinStatus();
  }

  async function createOperatorPin(pin: string) {
    if (!taskApi) return false;
    setBusy(true);
    try {
      const pinStatus = await taskApi.savePin(auth.token, pin);
      setConfirmation((current) => current ? { pinStatus, error: '' } : current);
      return true;
    } catch (reason) {
      setConfirmation((current) => current ? { ...current, error: messageFrom(reason) } : current);
      return false;
    } finally {
      setBusy(false);
    }
  }

  async function startPhysicalTask(folioConfirmation: StartConfirmation) {
    if (!canStartPhysicalTask(activeTask)) return;

    setBusy(true);
    setError('');
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
      setError(messageFrom(reason));
      await loadTasks({ quiet: true });
    } finally {
      setBusy(false);
    }
  }

  async function completeTask() {
    if (!activeTask || activeTask.estado !== 'en_proceso') return;
    const task = activeTask;
    const destination = task.destino;
    if (!destination?.posicion) {
      setError('La tarea en movimiento no posee una posición física de destino. Registra una incidencia.');
      return;
    }

    setBusy(true);
    setError('');
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
        await executeWithWarnings(payload, (confirmedPayload) => api.move(auth.token, confirmedPayload));
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
        await executeWithWarnings(payload, (confirmedPayload) => api.locate(auth.token, confirmedPayload));
      } else {
        throw new Error('La tarea no posee un origen físico compatible con este movimiento.');
      }

      setNotice(`Movimiento completado: ${task.folio.numero_folio} → ${operationalTaskPositionLabel(task.destino)}.`);
      await continueManeuver(task);
    } catch (reason) {
      setError(messageFrom(reason));
      await loadTasks({ quiet: true });
    } finally {
      await closeTemporarySessions(sessions);
      executionSessions.current = [];
      setBusy(false);
    }
  }

  async function completeDirectWithdrawal() {
    if (!taskApi
      || !activeTask
      || activeTask.estado !== 'en_proceso'
      || activeTask.tipo_movimiento !== 'retiro'
      || !activeTask.destino_logico?.carga_folio_id) return;

    const task = activeTask;
    const directDestination = activeTask.destino_logico;
    const fromPrefrio = task.contexto?.origen_logico === 'tunel_prefrio';
    const sessions = executionSessions.current.length
      ? executionSessions.current
      : [];
    setBusy(true);
    setError('');
    setNotice('');

    try {
      if (fromPrefrio) {
        await taskApi.completeDirectPrefrio(auth.token, task.id);
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
        await executeWithWarnings(
          payload,
          (confirmedPayload) => api.sendLoadFolioToDock(
            auth.token,
            directDestination.carga_folio_id,
            confirmedPayload,
          ).then(() => undefined),
        );
      }

      setNotice(
        `Retiro completado: ${task.folio.numero_folio} entregado en ${directDestination.nombre}.`,
      );
      setActiveTask(null);
      await loadTasks({ quiet: true });
    } catch (reason) {
      setError(messageFrom(reason));
      await loadTasks({ quiet: true });
    } finally {
      await closeTemporarySessions(sessions);
      executionSessions.current = [];
      setBusy(false);
    }
  }

  async function completeTemporaryExtraction() {
    if (!taskApi
      || !activeTask
      || activeTask.estado !== 'en_proceso'
      || activeTask.tipo_paso_maniobra !== 'extraccion_temporal') return;

    const task = activeTask;
    const sessions = executionSessions.current.length ? executionSessions.current : [];
    setBusy(true);
    setError('');
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
      await executeWithWarnings(
        payload,
        (confirmedPayload) => taskApi.completeTemporaryExtraction(
          auth.token,
          task.id,
          confirmedPayload,
        ),
      );
      setNotice(
        `${task.folio.numero_folio} quedó bajo custodia temporal de la maniobra. Continúa con el siguiente paso mostrado.`,
      );
      await continueManeuver(task);
    } catch (reason) {
      setError(messageFrom(reason));
      await loadTasks({ quiet: true });
    } finally {
      await closeTemporarySessions(sessions);
      executionSessions.current = [];
      setBusy(false);
    }
  }

  async function sendDiscrepancy(
    type: ManeuverDiscrepancyType,
    detail: string,
  ): Promise<ReportedManeuverDiscrepancy | null> {
    if (!taskApi || !activeTask?.maniobra) {
      setError('La gestión de incidencias está disponible para maniobras físicas del planificador.');
      return null;
    }
    setBusy(true);
    setError('');
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
      setError(messageFrom(reason));
      return null;
    } finally {
      setBusy(false);
    }
  }

  async function continueManeuver(completedTask: OperationalTask) {
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
    if (next.tipo_movimiento !== 'retiro'
      && (!next.destino?.posicion || next.reserva?.tipo_compromiso !== 'fisica')) {
      await calculateAndMaterializeFrontier(next);
    }
  }

  async function acquireExecutionSessions(task: OperationalTask, sessions: OpenSession[]) {
    if (task.tipo_movimiento === 'retiro') {
      if (task.contexto?.origen_logico === 'tunel_prefrio') return;
      if (!task.origen?.camara) throw new Error('La tarea no posee cámara de origen.');
      await acquireSession(task.origen.camara.id, sessions);
      return;
    }
    if (!task.destino?.camara) throw new Error('La tarea no posee cámara de destino materializada.');
    await acquireSession(task.destino.camara.id, sessions);
    if (task.origen?.camara && task.origen.camara.id !== task.destino.camara.id) {
      await acquireSession(task.origen.camara.id, sessions);
    }
  }

  async function acquireSession(cameraId: string, sessions: OpenSession[]) {
    const existing = sessions.find((session) => session.cameraId === cameraId);
    if (existing) return existing;

    const plan = await api.getPlan(auth.token, cameraId);
    if (plan.acceso.modo === 'solo_lectura') {
      throw new Error(`${plan.nombre} está siendo operada desde otra sesión.`);
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

    const opened = await api.openSession(auth.token, cameraId);
    const openedPlan = await api.getPlan(auth.token, cameraId);
    const session: OpenSession = {
      cameraId,
      sessionId: opened.id,
      openedByTask: true,
      plan: openedPlan,
    };
    sessions.push(session);
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
      if (!accepted) throw new Error('Operación cancelada: no se confirmaron las advertencias físicas.');

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
    setMine((current) => current.map((task) => byId.get(task.id) ?? task));
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
      {error ? (
        <Pressable onPress={() => setError('')} style={styles.errorBanner}>
          <Text style={styles.errorText}>{error}</Text>
          <Text style={styles.bannerClose}>×</Text>
        </Pressable>
      ) : null}
      {notice ? (
        <Pressable onPress={() => setNotice('')} style={styles.noticeBanner}>
          <Text style={styles.noticeText}>{notice}</Text>
          <Text style={styles.bannerClose}>×</Text>
        </Pressable>
      ) : null}

      {activeTask ? (
        <OperatorTaskExecution
          busy={busy}
          confirmation={confirmation}
          deviceName={auth.dispositivo.nombre}
          hasPhysicalDestination={hasPhysicalDestination}
          leaseExpired={leaseExpired}
          onBack={() => setActiveTask(null)}
          onCancelConfirmation={() => setConfirmation(null)}
          onComplete={() => void completeTask()}
          onCompleteDirect={() => void completeDirectWithdrawal()}
          onCompleteTemporary={() => void completeTemporaryExtraction()}
          onReportException={sendDiscrepancy}
          onConfirmStart={(folioConfirmation) => void startPhysicalTask(folioConfirmation)}
          onCreatePin={createOperatorPin}
          onRecalculate={() => void calculateAndMaterializeFrontier(activeTask)}
          onRelease={() => requestRelease(activeTask)}
          onStart={openStartConfirmation}
          operatorName={auth.usuario.nombre}
          secondsRemaining={secondsRemaining}
          task={activeTask}
        />
      ) : (
        <OperatorTaskHome
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
          verification={<TurnVerification round={verification} busy={busy} onVerify={(item, number) => void verifyPosition(item, number)} />}
        />
      )}

      {busy ? (
        <View pointerEvents="none" style={styles.busyOverlay}>
          <ActivityIndicator color={o.color.primary} size="large" />
          <Text style={styles.busyText}>Sincronizando estado operacional…</Text>
        </View>
      ) : null}
    </View>
  );
}

function candidateCameraIds(tasks: OperationalTask[], allProductCameraIds: string[]) {
  const ids = new Set<string>();
  let needsGeneralSearch = false;

  for (const task of tasks) {
    if (task.destino?.camara.id) {
      ids.add(task.destino.camara.id);
      continue;
    }
    if (task.tipo_movimiento === 'reubicacion' && task.origen?.camara.id) {
      ids.add(task.origen.camara.id);
      continue;
    }
    if (task.tipo_movimiento === 'ubicacion_inicial' || task.tipo_movimiento === 'traslado_entre_camaras') {
      needsGeneralSearch = true;
    }
  }

  if (needsGeneralSearch) allProductCameraIds.forEach((id) => ids.add(id));
  return ids;
}

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
  screen: { flex: 1, padding: o.space[4], backgroundColor: o.color.canvas },
  errorBanner: { flexDirection: 'row', justifyContent: 'space-between', gap: o.space[3], padding: o.space[3], borderRadius: o.radius.control, borderWidth: 1, borderLeftWidth: 5, borderColor: o.color.critical, backgroundColor: o.color.criticalSurface, marginBottom: o.space[2] },
  errorText: { color: o.color.critical, flex: 1, fontSize: o.type.small, fontWeight: '800' },
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
