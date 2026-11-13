import { font } from '../theme/typography';
import { FieldHeader, type FieldLeaveTarget } from '../components/field/FieldHeader';
import * as Crypto from 'expo-crypto';
import { useEffect, useMemo, useRef, useState } from 'react';
import {
  ActivityIndicator,
  Alert,
  Modal,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  TextInput,
  View,
} from 'react-native';

import { AuthSession } from '../domain/estiba';
import { useOperationalPolling } from '../hooks/useOperationalPolling';
import {
  PrefrioActionPayload,
  PrefrioFolioCandidate,
  PrefrioMobileCache,
  PrefrioOperationalEventType,
  PrefrioProcess,
  PrefrioProcessFolio,
  PrefrioQueuedCommand,
  PrefrioTunnel,
} from '../domain/prefrio';
import {
  R41_POINTS,
  R41_SECTORS,
  emptyR41Draft,
  parseR41Draft,
  r41FinalReadingRequired,
  r41MissingMessage,
  r41ReadingStatus,
  r41ReadingSummary,
  r41SectorsOf,
  updateR41Draft,
  type R41Draft,
  type R41PointKey,
  type R41SectorKey,
} from '../domain/prefrioR41';
import { ApiError } from '../services/apiError';
import {
  createPrefrioProcess,
  executePrefrioCommand,
  findEligiblePrefrioFolios,
  getPrefrioProcess,
  listEligiblePrefrioFolios,
  listPrefrioProcesses,
  listPrefrioTunnels,
} from '../services/prefrioApi';
import {
  enqueuePrefrioCommand,
  loadPrefrioCache,
  loadPrefrioOutbox,
  markPrefrioCommand,
  removePrefrioCommand,
  removePrefrioProcessCommands,
  savePrefrioCache,
} from '../services/prefrioOfflineStore';
import { colors } from '../theme/colors';

const EMPTY_CACHE: PrefrioMobileCache = {
  tunnels: [],
  processes: [],
  eligible_folios: [],
  revisions: {},
  synced_at: '',
};

const ACTIVE_STATES = new Set([
  'borrador',
  'cargando',
  'listo_para_iniciar',
  'en_proceso',
  'pendiente_verificacion',
]);

const LOADABLE_STATES = new Set(['borrador', 'cargando', 'listo_para_iniciar']);
const UNIQUE_STATE_COMMANDS = new Set<PrefrioQueuedCommand['kind']>([
  'confirmar_armado',
  'iniciar',
  'verificar',
]);

type PrefrioScreenProps = {
  auth: AuthSession;
  baseUrl: string | null;
  onLogout: () => void;
  onBusyChange?: (busy: boolean) => void;
};

type EventDraft = {
  type: PrefrioOperationalEventType;
  title: string;
  requiresTemperature: boolean;
};

type StateActionDraft = {
  kind: PrefrioQueuedCommand['kind'];
  title: string;
  description: string;
  label: string;
  route: string;
  nextState: PrefrioProcess['estado'];
};

type RemovalDraft = {
  assignmentId: string;
  folioLabel: string;
};

type CommandDelivery = 'confirmed' | 'queued' | 'blocked';
type FlushOutcome = 'completed' | 'offline';

function operationalDateTime(value = new Date()): string {
  const day = String(value.getDate()).padStart(2, '0');
  const month = String(value.getMonth() + 1).padStart(2, '0');
  const hours = String(value.getHours()).padStart(2, '0');
  const minutes = String(value.getMinutes()).padStart(2, '0');
  return `${day}-${month}-${value.getFullYear()} ${hours}:${minutes}`;
}

function parseOperationalDateTime(value: string): string | null {
  const match = value.trim().match(/^(\d{2})-(\d{2})-(\d{4})\s+(\d{2}):(\d{2})$/);
  if (!match) return null;
  const [, day, month, year, hour, minute] = match;
  const date = new Date(Number(year), Number(month) - 1, Number(day), Number(hour), Number(minute));
  if (date.getFullYear() !== Number(year) || date.getMonth() !== Number(month) - 1 || date.getDate() !== Number(day)
    || date.getHours() !== Number(hour) || date.getMinutes() !== Number(minute)) return null;
  return date.toISOString();
}

export function PrefrioScreen({ auth, baseUrl, onLogout, onBusyChange }: PrefrioScreenProps) {
  const scannerRef = useRef<TextInput>(null);
  const flushing = useRef<Promise<FlushOutcome> | null>(null);
  const synchronizing = useRef<Promise<void> | null>(null);
  const operationInFlight = useRef(false);
  const cacheRef = useRef<PrefrioMobileCache>(EMPTY_CACHE);
  const outboxRef = useRef<PrefrioQueuedCommand[]>([]);
  const [cache, setCache] = useState<PrefrioMobileCache>(EMPTY_CACHE);
  const [outbox, setOutbox] = useState<PrefrioQueuedCommand[]>([]);
  const [selectedProcessId, setSelectedProcessId] = useState<string | null>(null);
  const [selectedTunnelId, setSelectedTunnelId] = useState<string | null>(null);
  const [selectedPositionId, setSelectedPositionId] = useState<string | null>(null);
  const [folioNumber, setFolioNumber] = useState('');
  const [initialTemperature, setInitialTemperature] = useState('');
  const [loadOccurredAt, setLoadOccurredAt] = useState(operationalDateTime());
  const [busy, setBusy] = useState(true);
  const [operationBusy, setOperationBusy] = useState(false);
  const [online, setOnline] = useState(Boolean(baseUrl));
  const [notice, setNotice] = useState('');
  const [error, setError] = useState('');
  const [eventDraft, setEventDraft] = useState<EventDraft | null>(null);
  const [eventTemperature, setEventTemperature] = useState('');
  const [readingDraft, setReadingDraft] = useState<R41Draft>(emptyR41Draft());
  const [clock, setClock] = useState(Date.now());
  const [eventNote, setEventNote] = useState('');
  const [eventOccurredAt, setEventOccurredAt] = useState(operationalDateTime());
  const [actionDraft, setActionDraft] = useState<StateActionDraft | null>(null);
  const [actionOccurredAt, setActionOccurredAt] = useState(operationalDateTime());
  const [actionNote, setActionNote] = useState('');
  const [removalDraft, setRemovalDraft] = useState<RemovalDraft | null>(null);
  const [removalOccurredAt, setRemovalOccurredAt] = useState(operationalDateTime());
  const [removalNote, setRemovalNote] = useState('');
  const [creating, setCreating] = useState(false);
  const [createSetpoint, setCreateSetpoint] = useState('-1.5');
  const [createDuration, setCreateDuration] = useState('720');
  const [createFormat, setCreateFormat] = useState('Granel 5 kg');
  const [createOccurredAt, setCreateOccurredAt] = useState(operationalDateTime());

  const userId = auth.usuario.id;
  const deviceId = auth.dispositivo.id;
  const canOperate = auth.usuario.capacidades.puede_operar_prefrio === true;
  const selectedProcess = useMemo(
    () => cache.processes.find((item) => item.id === selectedProcessId) ?? null,
    [cache.processes, selectedProcessId],
  );
  const selectedTunnel = useMemo(() => {
    const processTunnelId = selectedProcess?.tunel.id;
    return cache.tunnels.find((item) => item.id === (processTunnelId ?? selectedTunnelId)) ?? null;
  }, [cache.tunnels, selectedProcess, selectedTunnelId]);
  const activeAssignments = useMemo(
    () => selectedProcess?.folios.filter((item) => !['retirado', 'cancelado'].includes(item.estado)) ?? [],
    [selectedProcess],
  );
  const assignmentsByPosition = useMemo(() => {
    const grouped = new Map<string, PrefrioProcessFolio[]>();
    activeAssignments.forEach((item) => {
      const positionId = item.posicion?.id;
      if (!positionId) return;
      grouped.set(positionId, [...(grouped.get(positionId) ?? []), item]);
    });
    return grouped;
  }, [activeAssignments]);
  const freePositions = useMemo(
    () => selectedTunnel?.posiciones.filter((item) => item.activa && !assignmentsByPosition.has(item.id)) ?? [],
    [selectedTunnel, assignmentsByPosition],
  );
  const selectedPosition = selectedTunnel?.posiciones.find((item) => item.id === selectedPositionId) ?? null;
  const selectedAssignments = assignmentsByPosition.get(selectedPositionId ?? '') ?? [];
  const occupiedPositionCount = assignmentsByPosition.size;
  const processQueue = outbox.filter((item) => item.process_id === selectedProcessId);
  const processHasBlockingIssue = processQueue.some((item) => item.status !== 'pendiente');
  const processActionsBlocked = operationBusy || processHasBlockingIssue;
  const unresolved = outbox.filter((item) => item.status !== 'pendiente').length;
  const r41Status = useMemo(
    () => (selectedProcess ? r41ReadingStatus(selectedProcess, clock) : null),
    [selectedProcess, clock],
  );
  const finalReadingRequired = r41Status ? r41FinalReadingRequired(r41Status) : false;
  const processInProgress = selectedProcess?.estado === 'en_proceso';

  useEffect(() => {
    if (!processInProgress) return undefined;
    setClock(Date.now());
    const timer = setInterval(() => setClock(Date.now()), 30000);
    return () => clearInterval(timer);
  }, [processInProgress, selectedProcessId]);
  useEffect(() => {
    onBusyChange?.(busy || operationBusy);
    return () => onBusyChange?.(false);
  }, [busy, operationBusy, onBusyChange]);

  useEffect(() => {
    void initialize();
  }, []);

  useOperationalPolling(
    synchronize,
    { intervalMs: 30000, enabled: Boolean(baseUrl) },
  );

  useEffect(() => {
    if (selectedProcess && !ACTIVE_STATES.has(selectedProcess.estado)) {
      setSelectedTunnelId(selectedProcess.tunel.id);
      setSelectedProcessId(null);
      setSelectedPositionId(null);
      setCreating(false);
      return;
    }

    if (selectedProcessId && !selectedProcess) {
      setSelectedProcessId(null);
      setSelectedPositionId(null);
      return;
    }

    if (!selectedProcessId && !selectedTunnelId) {
      const active = cache.processes.find((item) => ACTIVE_STATES.has(item.estado));
      if (active) {
        setSelectedProcessId(active.id);
        setSelectedTunnelId(active.tunel.id);
      }
    }
  }, [cache.processes, selectedProcess, selectedProcessId, selectedTunnelId]);

  useEffect(() => {
    if (selectedProcess && !selectedPositionId) {
      setSelectedPositionId(freePositions[0]?.id ?? null);
    }
  }, [selectedProcessId, freePositions.length]);

  async function initialize() {
    setBusy(true);
    setError('');
    try {
      const [savedCache, savedOutbox] = await Promise.all([
        loadPrefrioCache(userId, deviceId),
        loadPrefrioOutbox(userId, deviceId),
      ]);
      if (savedCache) replaceCache(savedCache, false);
      replaceOutbox(savedOutbox);
      await synchronize();
    } catch (reason) {
      setError(messageFrom(reason));
    } finally {
      setBusy(false);
    }
  }

  async function synchronize() {
    if (!baseUrl) {
      setOnline(false);
      return;
    }

    if (synchronizing.current) {
      await synchronizing.current;
      return;
    }

    const task = (async () => {
      try {
        await flushOutbox();
        const current = cacheRef.current;
        const [tunnels, processes, eligibleFolios] = await Promise.all([
          listPrefrioTunnels(baseUrl, auth.token, current.revisions?.tunnels),
          listPrefrioProcesses(baseUrl, auth.token, current.revisions?.processes),
          listEligiblePrefrioFolios(
            baseUrl,
            auth.token,
            500,
            current.revisions?.eligible_folios,
          ),
        ]);
        const next: PrefrioMobileCache = {
          tunnels: tunnels.data ?? current.tunnels,
          processes: processes.data ?? current.processes,
          eligible_folios: eligibleFolios.data ?? current.eligible_folios,
          revisions: {
            tunnels: tunnels.etag,
            processes: processes.etag,
            eligible_folios: eligibleFolios.etag,
          },
          synced_at: new Date().toISOString(),
        };
        await replaceCache(next);
        setOnline(true);
        setError('');
      } catch (reason) {
        if (reason instanceof ApiError && reason.status === 401) {
          Alert.alert('Sesión vencida', 'Vuelve a iniciar el turno.');
          onLogout();
          return;
        }
        if (reason instanceof ApiError && reason.status === 0) setOnline(false);
        if (!cacheRef.current.synced_at) setError(messageFrom(reason));
      }
    })();
    synchronizing.current = task;

    try {
      await task;
    } finally {
      if (synchronizing.current === task) synchronizing.current = null;
    }
  }

  async function flushOutbox(): Promise<FlushOutcome> {
    if (!baseUrl) return 'offline';
    if (flushing.current) return flushing.current;

    const task = (async (): Promise<FlushOutcome> => {
      let items = await loadPrefrioOutbox(userId, deviceId);
      const blockedProcesses = new Set(
        items.filter((item) => item.status !== 'pendiente').map((item) => item.process_id),
      );

      for (const item of items.filter((candidate) => candidate.status === 'pendiente')) {
        if (blockedProcesses.has(item.process_id)) continue;

        try {
          const process = await executePrefrioCommand(
            baseUrl,
            auth.token,
            item.route,
            item.payload as Record<string, unknown>,
          );
          items = await removePrefrioCommand(userId, deviceId, item.id);
          await upsertServerProcess(process);
          setOnline(true);
        } catch (reason) {
          if (reason instanceof ApiError && reason.status === 0) {
            setOnline(false);
            replaceOutbox(items);
            return 'offline';
          }

          const status = reason instanceof ApiError && reason.status === 409
            ? 'conflicto'
            : 'error';
          const failureMessage = messageFrom(reason);
          items = await markPrefrioCommand(
            userId,
            deviceId,
            item.id,
            status,
            `${failureMessage} Las operaciones posteriores de este proceso fueron descartadas para evitar reintentos contradictorios.`,
          );
          items = await removePrefrioProcessCommands(
            userId,
            deviceId,
            item.process_id,
            item.id,
          );
          blockedProcesses.add(item.process_id);
          setNotice('');
          setError(failureMessage);
          await refreshProcessAfterFailure(item.process_id);
        }
      }
      replaceOutbox(items);
      return 'completed';
    })();
    flushing.current = task;

    try {
      return await task;
    } finally {
      if (flushing.current === task) flushing.current = null;
    }
  }

  async function refreshProcessAfterFailure(processId: string) {
    if (!baseUrl) return;
    try {
      await upsertServerProcess(await getPrefrioProcess(baseUrl, auth.token, processId));
    } catch {
      // Conserva la última versión conocida hasta la próxima sincronización completa.
    }
  }

  async function replaceCache(next: PrefrioMobileCache, persist = true) {
    cacheRef.current = next;
    setCache(next);
    if (persist) await savePrefrioCache(userId, deviceId, next);
  }

  function replaceOutbox(items: PrefrioQueuedCommand[]) {
    outboxRef.current = items;
    setOutbox(items);
  }

  function blockingCommandFor(processId: string) {
    return outboxRef.current.find(
      (item) => item.process_id === processId && item.status !== 'pendiente',
    );
  }

  function beginOperation(processId?: string) {
    if (operationInFlight.current) return false;
    const blocker = processId ? blockingCommandFor(processId) : null;
    if (blocker) {
      setNotice('');
      setError(
        `${blocker.process_code} tiene una operación en conflicto. `
        + 'Descarta las operaciones del proceso y refresca antes de continuar.',
      );
      return false;
    }

    operationInFlight.current = true;
    setOperationBusy(true);
    return true;
  }

  function finishOperation() {
    operationInFlight.current = false;
    setOperationBusy(false);
  }

  function deliveryNotice(label: string, delivery: CommandDelivery) {
    return delivery === 'confirmed'
      ? `${label} fue confirmado por el servidor.`
      : `${label} quedó guardado en la bandeja y se enviará automáticamente.`;
  }

  async function upsertServerProcess(process: PrefrioProcess) {
    const current = cacheRef.current;
    const processIsActive = ACTIVE_STATES.has(process.estado);
    const nextProcesses = processIsActive
      ? [process, ...current.processes.filter((item) => item.id !== process.id)]
      : current.processes.filter((item) => item.id !== process.id);

    await replaceCache({ ...current, processes: nextProcesses });
    setSelectedTunnelId(process.tunel.id);
    setSelectedProcessId(processIsActive ? process.id : null);

    if (!processIsActive) {
      setSelectedPositionId(null);
      setCreating(false);
    }
  }

  async function findCandidate(number: string): Promise<PrefrioFolioCandidate | null> {
    const normalized = number.trim().toUpperCase();
    const cached = cacheRef.current.eligible_folios.find(
      (item) => item.numero_folio.toUpperCase() === normalized,
    );
    if (cached) return cached;
    if (!baseUrl) return null;

    const found = await findEligiblePrefrioFolios(baseUrl, auth.token, normalized);
    const exact = found.find((item) => item.numero_folio.toUpperCase() === normalized) ?? null;
    if (exact) {
      await replaceCache({
        ...cacheRef.current,
        eligible_folios: [
          exact,
          ...cacheRef.current.eligible_folios.filter((item) => item.id !== exact.id),
        ],
      });
    }
    return exact;
  }

  async function addFolio() {
    if (!selectedProcess || !selectedTunnel || !canOperate) return;
    if (!LOADABLE_STATES.has(selectedProcess.estado)) {
      setError('El proceso ya no admite nuevos folios.');
      return;
    }
    if (!beginOperation(selectedProcess.id)) return;

    setBusy(true);
    setError('');
    setNotice('');
    try {
      const folio = await findCandidate(folioNumber);
      if (!folio) {
        setError(online
          ? 'El folio no está habilitado para ingresar a Prefrío.'
          : 'El folio no existe en el catálogo guardado. Sincroniza la PDA para incorporarlo.');
        return;
      }

      const position = selectedPosition ?? freePositions[0];
      if (!position) {
        setError(
          folio.tipo_bulto === 'saldo'
            ? 'Selecciona una posición ocupada por saldos para compartirla.'
            : 'No quedan posiciones libres para un pallet completo.',
        );
        return;
      }

      const occupants = assignmentsByPosition.get(position.id) ?? [];
      if (occupants.length > 0
        && (folio.tipo_bulto !== 'saldo'
          || occupants.some((item) => item.folio?.tipo_bulto !== 'saldo'))) {
        setError('Esta posición es exclusiva. Solo puede compartirse entre folios de tipo saldo.');
        return;
      }

      const temperature = initialTemperature.trim() === ''
        ? undefined
        : Number(initialTemperature.replace(',', '.'));
      if (temperature !== undefined && !Number.isFinite(temperature)) {
        setError('La temperatura inicial no es válida.');
        return;
      }
      const occurredAt = parseOperationalDateTime(loadOccurredAt);
      if (!occurredAt) {
        setError('Ingresa la fecha y hora de carga como DD-MM-AAAA HH:mm.');
        return;
      }

      const operationId = Crypto.randomUUID();
      const payload = {
        operacion_id: operationId,
        version_conocida: selectedProcess.version,
        folio_id: folio.id,
        posicion_tunel_prefrio_id: position.id,
        ...(temperature !== undefined ? { temperatura_inicial: temperature } : {}),
        ocurrido_at: occurredAt,
      };
      const command = commandFor(
        operationId,
        selectedProcess,
        'agregar_folio',
        `Cargar ${folio.numero_folio} en ${position.etiqueta}`,
        `/api/prefrio/procesos/${selectedProcess.id}/folios`,
        payload,
      );
      const optimistic: PrefrioProcess = {
        ...selectedProcess,
        estado: 'cargando',
        version: selectedProcess.version + 1,
        folios: [
          ...selectedProcess.folios,
          {
            id: `local:${operationId}`,
            estado: 'cargado',
            temperatura_inicial: temperature ?? null,
            temperatura_final: null,
            cargado_at: payload.ocurrido_at,
            retirado_at: null,
            motivo_resultado: null,
            observacion: null,
            posicion: position,
            folio: {
              id: folio.id,
              numero_folio: folio.numero_folio,
              tipo_bulto: folio.tipo_bulto,
              estado_operacional: folio.estado_operacional,
              condicion_termica: folio.condicion_termica,
              habilitacion_almacenamiento: folio.habilitacion_almacenamiento,
              variedad: folio.variedad,
              calibre: folio.calibre,
              marca: folio.marca,
              exportadora: folio.exportadora,
              cantidad_cajas: folio.cantidad_cajas,
            },
            cargado_por: { id: auth.usuario.id, nombre: auth.usuario.nombre },
          },
        ],
      };
      const delivery = await enqueueAndApply(command, optimistic, folio.id);
      if (delivery === 'blocked') return;
      setFolioNumber('');
      setInitialTemperature('');
      setLoadOccurredAt(operationalDateTime());
      setSelectedPositionId(
        folio.tipo_bulto === 'saldo'
          ? position.id
          : nextFreePositionId(optimistic, selectedTunnel),
      );
      setNotice(
        deliveryNotice(`${folio.numero_folio} en ${position.etiqueta}`, delivery)
        + (selectedProcess.estado === 'listo_para_iniciar'
          ? ' El proceso volvió a Cargando; confirma nuevamente el armado antes de iniciarlo.'
          : ''),
      );
      setTimeout(() => scannerRef.current?.focus(), 180);
    } catch (reason) {
      setError(messageFrom(reason));
    } finally {
      setBusy(false);
      finishOperation();
    }
  }

  async function enqueueAndApply(
    command: PrefrioQueuedCommand,
    optimistic: PrefrioProcess,
    removeEligibleFolioId?: string,
  ): Promise<CommandDelivery> {
    const blocker = blockingCommandFor(command.process_id);
    if (blocker) {
      setNotice('');
      setError(blocker.message ?? 'El proceso tiene una operación que requiere revisión.');
      return 'blocked';
    }
    if (hasEquivalentPendingCommand(outboxRef.current, command)) {
      setNotice('');
      setError(`${command.label} ya está pendiente de envío. Espera la sincronización antes de repetirla.`);
      return 'blocked';
    }

    const items = await enqueuePrefrioCommand(userId, deviceId, command);
    replaceOutbox(items);
    const current = cacheRef.current;
    await replaceCache({
      ...current,
      processes: [optimistic, ...current.processes.filter((item) => item.id !== optimistic.id)],
      eligible_folios: removeEligibleFolioId
        ? current.eligible_folios.filter((item) => item.id !== removeEligibleFolioId)
        : current.eligible_folios,
    });
    if (!baseUrl) return 'queued';

    let outcome = await flushOutbox();
    let latest = await loadPrefrioOutbox(userId, deviceId);
    let currentCommand = latest.find((item) => item.id === command.id);
    const blockedAfterFirstAttempt = latest.some(
      (item) => item.process_id === command.process_id && item.status !== 'pendiente',
    );

    if (outcome === 'completed' && currentCommand?.status === 'pendiente' && !blockedAfterFirstAttempt) {
      outcome = await flushOutbox();
      latest = await loadPrefrioOutbox(userId, deviceId);
      currentCommand = latest.find((item) => item.id === command.id);
    }
    replaceOutbox(latest);

    const processBlocker = latest.find(
      (item) => item.process_id === command.process_id && item.status !== 'pendiente',
    );
    if (!currentCommand && !processBlocker) return 'confirmed';
    if (currentCommand?.status === 'pendiente' || outcome === 'offline') return 'queued';

    setNotice('');
    setError(currentCommand?.message ?? processBlocker?.message ?? 'La operación requiere revisión.');
    return 'blocked';
  }

  async function queueStateAction(
    kind: PrefrioQueuedCommand['kind'],
    label: string,
    route: string,
    nextState: PrefrioProcess['estado'],
    occurredAt: string,
    note?: string,
    datos?: Record<string, unknown>,
  ): Promise<CommandDelivery> {
    if (!selectedProcess) return 'blocked';
    const operationId = Crypto.randomUUID();
    const payload: PrefrioActionPayload = {
      operacion_id: operationId,
      version_conocida: selectedProcess.version,
      ...(note?.trim() ? { observacion: note.trim() } : {}),
      ...(datos ? { datos } : {}),
      ocurrido_at: occurredAt,
    };
    const command = commandFor(operationId, selectedProcess, kind, label, route, payload);
    const optimistic: PrefrioProcess = {
      ...selectedProcess,
      estado: nextState,
      version: selectedProcess.version + 1,
      ...(nextState === 'en_proceso' ? { iniciado_at: occurredAt } : {}),
      ...(nextState === 'pendiente_verificacion' ? { pendiente_verificacion_at: occurredAt } : {}),
      folios: nextState === 'en_proceso'
        ? selectedProcess.folios.map((item) => item.estado === 'cargado'
          ? { ...item, estado: 'en_proceso' as const }
          : item)
        : selectedProcess.folios,
    };
    return enqueueAndApply(command, optimistic);
  }

  function openStateAction(draft: StateActionDraft) {
    if (!selectedProcess || blockingCommandFor(selectedProcess.id)) {
      setNotice('');
      setError('Resuelve primero la operación en conflicto de este proceso.');
      return;
    }
    setActionDraft(draft);
    setActionOccurredAt(operationalDateTime());
    setActionNote('');
    setReadingDraft(emptyR41Draft());
    setError('');
  }

  async function registerStateAction() {
    if (!actionDraft || !selectedProcess) return;
    const occurredAt = parseOperationalDateTime(actionOccurredAt);
    if (!occurredAt) {
      setError('Ingresa la fecha y hora como DD-MM-AAAA HH:mm.');
      return;
    }
    let datos: Record<string, unknown> | undefined;
    if (actionDraft.kind === 'verificar') {
      // POEMP R-41: el enfriado termina con la lectura de los 3 sectores, interior y exterior.
      const reading = parseR41Draft(readingDraft);
      if (!reading.ok) {
        setError(reading.message);
        return;
      }
      if (reading.filled > 0 && reading.missing > 0) {
        setError(r41MissingMessage(reading.missing));
        return;
      }
      if (reading.filled === 0 && finalReadingRequired) {
        setError('Antes de terminar el enfriado registra la lectura final: los 3 sectores (ventilador, medio y puerta), interior y exterior de ambos lados.');
        return;
      }
      if (reading.filled > 0) datos = { lectura_final: reading.sectores };
    }
    if (!beginOperation(selectedProcess.id)) return;
    try {
      const delivery = await queueStateAction(
        actionDraft.kind,
        actionDraft.label,
        actionDraft.route,
        actionDraft.nextState,
        occurredAt,
        actionNote,
        datos,
      );
      if (delivery === 'blocked') return;
      setNotice(deliveryNotice(actionDraft.label, delivery));
      setActionDraft(null);
    } catch (reason) {
      setNotice('');
      setError(messageFrom(reason));
    } finally {
      finishOperation();
    }
  }

  async function registerEvent() {
    if (!selectedProcess || !eventDraft) return;
    const temperature = eventTemperature.trim() === ''
      ? undefined
      : Number(eventTemperature.replace(',', '.'));
    if (eventDraft.requiresTemperature && (temperature === undefined || !Number.isFinite(temperature))) {
      setError('Ingresa una temperatura válida para la lectura.');
      return;
    }
    let datos: Record<string, unknown> | undefined = temperature !== undefined ? { temperatura: temperature, unidad: '°C' } : undefined;
    if (eventDraft.type === 'lectura') {
      // Lectura horaria POEMP R-41: puede quedar parcial si no se midieron todos los sensores.
      const reading = parseR41Draft(readingDraft);
      if (!reading.ok) {
        setError(reading.message);
        return;
      }
      if (reading.filled === 0) {
        setError('Ingresa al menos una temperatura de la lectura.');
        return;
      }
      datos = { sectores: reading.sectores };
    }
    const occurredAt = parseOperationalDateTime(eventOccurredAt);
    if (!occurredAt) {
      setError('Ingresa la fecha y hora como DD-MM-AAAA HH:mm.');
      return;
    }
    if (!beginOperation(selectedProcess.id)) return;

    try {
      const operationId = Crypto.randomUUID();
      const payload: PrefrioActionPayload = {
        operacion_id: operationId,
        version_conocida: selectedProcess.version,
        ...(eventNote.trim() ? { observacion: eventNote.trim() } : {}),
        ...(datos ? { datos } : {}),
        ocurrido_at: occurredAt,
      };
      const command = commandFor(
        operationId,
        selectedProcess,
        'evento',
        eventDraft.title,
        `/api/prefrio/procesos/${selectedProcess.id}/eventos/${eventDraft.type}`,
        payload,
      );
      const optimistic: PrefrioProcess = {
        ...selectedProcess,
        version: selectedProcess.version + 1,
        eventos: [
          {
            id: `local:${operationId}`,
            operacion_id: operationId,
            tipo: eventDraft.type,
            ocurrido_at: payload.ocurrido_at,
            datos: payload.datos ?? null,
            observacion: payload.observacion ?? null,
            usuario: { id: auth.usuario.id, nombre: auth.usuario.nombre },
            dispositivo: {
              id: auth.dispositivo.id,
              codigo: auth.dispositivo.codigo,
              nombre: auth.dispositivo.nombre,
            },
          },
          ...selectedProcess.eventos,
        ],
      };
      const delivery = await enqueueAndApply(command, optimistic);
      if (delivery === 'blocked') return;
      setEventDraft(null);
      setEventTemperature('');
      setReadingDraft(emptyR41Draft());
      setEventNote('');
      setEventOccurredAt(operationalDateTime());
      setNotice(deliveryNotice(command.label, delivery));
    } catch (reason) {
      setNotice('');
      setError(messageFrom(reason));
    } finally {
      finishOperation();
    }
  }

  async function removeAssignment(assignmentId: string, folioLabel: string) {
    if (!selectedProcess) return;
    if (blockingCommandFor(selectedProcess.id)) {
      setNotice('');
      setError('Resuelve primero la operación en conflicto de este proceso.');
      return;
    }
    if (assignmentId.startsWith('local:')) {
      setError('Esta carga aún no se sincroniza. Espera la confirmación antes de retirarla.');
      return;
    }

    setRemovalDraft({ assignmentId, folioLabel });
    setRemovalOccurredAt(operationalDateTime());
    setRemovalNote('');
    setError('');
  }

  async function registerRemoval() {
    if (!removalDraft || !selectedProcess) return;
    const occurredAt = parseOperationalDateTime(removalOccurredAt);
    if (!occurredAt) {
      setError('Ingresa la fecha y hora de retiro como DD-MM-AAAA HH:mm.');
      return;
    }
    if (!beginOperation(selectedProcess.id)) return;
    try {
      const delivery = await queueRemoval(
        removalDraft.assignmentId,
        removalDraft.folioLabel,
        occurredAt,
        removalNote,
      );
      if (delivery === 'blocked') return;
      setNotice(deliveryNotice(`Retiro de ${removalDraft.folioLabel}`, delivery));
      setRemovalDraft(null);
    } catch (reason) {
      setNotice('');
      setError(messageFrom(reason));
    } finally {
      finishOperation();
    }
  }

  async function queueRemoval(
    assignmentId: string,
    folioLabel: string,
    occurredAt: string,
    note?: string,
  ): Promise<CommandDelivery> {
    if (!selectedProcess) return 'blocked';
    const operationId = Crypto.randomUUID();
    const payload: PrefrioActionPayload = {
      operacion_id: operationId,
      version_conocida: selectedProcess.version,
      ...(note?.trim() ? { observacion: note.trim() } : {}),
      ocurrido_at: occurredAt,
    };
    const command = commandFor(
      operationId,
      selectedProcess,
      'retirar_folio',
      `Retirar ${folioLabel}`,
      `/api/prefrio/procesos/${selectedProcess.id}/folios/${assignmentId}/retirar`,
      payload,
    );
    const folios = selectedProcess.folios.map((item) => item.id === assignmentId
      ? { ...item, estado: 'retirado' as const, retirado_at: payload.ocurrido_at }
      : item);
    const quedan = folios.some((item) => item.estado === 'cargado');
    return enqueueAndApply(command, {
      ...selectedProcess,
      estado: quedan ? 'cargando' : 'borrador',
      version: selectedProcess.version + 1,
      folios,
    });
  }

  async function createProcess() {
    if (!baseUrl || !selectedTunnel || !canOperate) {
      setError('La creación de procesos requiere conexión con el servidor.');
      return;
    }
    const setpoint = Number(createSetpoint.replace(',', '.'));
    const duration = Number(createDuration);
    if (!Number.isFinite(setpoint) || !Number.isInteger(duration) || duration < 1) {
      setError('Revisa el setpoint y la duración objetivo.');
      return;
    }
    const occurredAt = parseOperationalDateTime(createOccurredAt);
    if (!occurredAt) {
      setError('Ingresa la fecha y hora como DD-MM-AAAA HH:mm.');
      return;
    }
    if (!beginOperation()) return;

    setBusy(true);
    setError('');
    try {
      const process = await createPrefrioProcess(baseUrl, auth.token, {
        operacion_id: Crypto.randomUUID(),
        tunel_prefrio_id: selectedTunnel.id,
        setpoint,
        duracion_objetivo_minutos: duration,
        ...(createFormat.trim() ? { formato_referencia: createFormat.trim() } : {}),
        ocurrido_at: occurredAt,
      });
      await upsertServerProcess(process);
      setCreating(false);
      await synchronize();
      setNotice(`${process.codigo} creado en ${selectedTunnel.codigo}.`);
    } catch (reason) {
      setError(messageFrom(reason));
    } finally {
      setBusy(false);
      finishOperation();
    }
  }

  function discardCommand(item: PrefrioQueuedCommand) {
    if (!baseUrl) {
      setError('Conecta la PDA antes de descartar una operación para reconstruir el estado confirmado.');
      return;
    }

    Alert.alert(
      `Recuperar ${item.process_code}`,
      'Se descartará la operación en conflicto y cualquier acción posterior guardada para este proceso. Luego se cargará el estado confirmado por el servidor.',
      [
        { text: 'Cancelar', style: 'cancel' },
        {
          text: 'Descartar y refrescar',
          style: 'destructive',
          onPress: () => void discardProcessCommands(item),
        },
      ],
    );
  }

  async function discardProcessCommands(item: PrefrioQueuedCommand) {
    if (!baseUrl || operationInFlight.current) return;
    operationInFlight.current = true;
    setOperationBusy(true);
    setNotice('');
    setError('');
    try {
      const items = await removePrefrioProcessCommands(
        userId,
        deviceId,
        item.process_id,
      );
      replaceOutbox(items);
      await upsertServerProcess(await getPrefrioProcess(baseUrl, auth.token, item.process_id));
      await synchronize();
      setNotice(`${item.process_code} fue refrescado con el estado confirmado por el servidor.`);
    } catch (reason) {
      setError(messageFrom(reason));
    } finally {
      finishOperation();
    }
  }

  function beforeLeave(proceed: () => void, target: FieldLeaveTarget) {
    const action = target === 'module' ? 'Cambiar módulo' : 'Cerrar sesión';
    if (outbox.some((item) => item.status === 'pendiente')) {
      Alert.alert(
        'Operaciones pendientes',
        `La bandeja permanece guardada en esta PDA. ¿Deseas ${action.toLowerCase()} igualmente?`,
        [
          { text: 'Volver', style: 'cancel' },
          { text: action, style: 'destructive', onPress: proceed },
        ],
      );
      return;
    }
    proceed();
  }

  if (!cache.synced_at && busy) {
    return (
      <View style={styles.boot}>
        <ActivityIndicator color={colors.cyan} size="large" />
        <Text style={styles.muted}>Preparando Prefrío…</Text>
      </View>
    );
  }

  return (
    <View style={styles.screen}>
      <ScrollView contentContainerStyle={styles.page} keyboardShouldPersistTaps="handled">
        <FieldHeader area="frigorifico" auth={auth} title="Prefrío · Túneles" offline={!online} busy={busy || operationBusy} onBeforeLeave={beforeLeave} onLogout={onLogout}><Pressable accessibilityLabel="Sincronizar prefrío" onPress={() => void synchronize()} style={styles.secondaryButton}><Text style={styles.secondaryButtonText}>↻</Text></Pressable></FieldHeader>

        {notice ? <Text style={styles.notice}>{notice}</Text> : null}
        {error ? <Text style={styles.error}>{error}</Text> : null}

        <View style={styles.metrics}>
          <Metric label="TÚNELES" value={String(cache.tunnels.length)} />
          <Metric label="PROCESOS ACTIVOS" value={String(cache.processes.filter((item) => ACTIVE_STATES.has(item.estado)).length)} />
          <Metric label="FOLIOS PENDIENTES" value={String(cache.eligible_folios.length)} />
          <Metric label="BANDEJA" value={String(outbox.length)} warning={unresolved > 0} />
        </View>

        <Text style={styles.sectionTitle}>Túneles</Text>
        <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.tunnelRow}>
          {cache.tunnels.map((tunnel) => {
            const process = tunnel.proceso_activo
              ? cache.processes.find((item) => item.id === tunnel.proceso_activo?.id)
              : null;
            const selected = selectedTunnel?.id === tunnel.id;
            return (
              <Pressable
                key={tunnel.id}
                onPress={() => {
                  setSelectedTunnelId(tunnel.id);
                  setSelectedProcessId(process?.id ?? null);
                  setSelectedPositionId(null);
                  setCreating(false);
                }}
                style={[styles.tunnelCard, selected && styles.tunnelCardSelected]}
              >
                <Text style={styles.tunnelCode}>{tunnel.codigo}</Text>
                <Text style={styles.tunnelName}>{tunnel.nombre}</Text>
                <Text style={styles.tunnelMeta}>{tunnel.capacidad_posiciones} posiciones · {formatTemperature(tunnel.setpoint_habitual)}</Text>
                <Text style={styles.tunnelState}>{process ? stateLabel(process.estado) : tunnel.estado_tecnico === 'operativo' ? 'Disponible' : stateLabel(tunnel.estado_tecnico)}</Text>
                {process ? <Text style={styles.tunnelProcess}>{process.codigo} · v{process.version}</Text> : null}
              </Pressable>
            );
          })}
        </ScrollView>

        {!selectedTunnel ? (
          <View style={styles.emptyPanel}><Text style={styles.muted}>Selecciona un túnel para comenzar.</Text></View>
        ) : !selectedProcess ? (
          <View style={styles.panel}>
            <Text style={styles.panelTitle}>{selectedTunnel.codigo} disponible</Text>
            <Text style={styles.muted}>No existe un proceso activo. Puedes crearlo conectado o esperar que oficina lo programe.</Text>
            {canOperate ? (
              <Pressable onPress={() => { setCreateOccurredAt(operationalDateTime()); setCreating(true); }} style={styles.primaryButton}>
                <Text style={styles.primaryButtonText}>Crear proceso</Text>
              </Pressable>
            ) : null}
          </View>
        ) : (
          <>
            <View style={styles.processHeader}>
              <View>
                <Text style={styles.eyebrow}>{selectedTunnel.codigo} · {selectedTunnel.nombre}</Text>
                <Text style={styles.processCode}>{selectedProcess.codigo}</Text>
                <Text style={styles.muted}>{stateLabel(selectedProcess.estado)} · versión {selectedProcess.version} · setpoint {formatTemperature(selectedProcess.setpoint)}</Text>
              </View>
              <View style={styles.processCounts}>
                <Text style={styles.processCountValue}>{occupiedPositionCount}/{selectedTunnel.capacidad_posiciones}</Text>
                <Text style={styles.processCountLabel}>POSICIONES · {activeAssignments.length} FOLIOS</Text>
              </View>
            </View>

            {processInProgress && r41Status ? (
              <View accessibilityLabel="Control horario R-41" style={[styles.r41Banner, r41Status.overdue && styles.r41BannerOverdue]}>
                <Text style={[styles.r41BannerTitle, r41Status.overdue && styles.r41BannerTitleOverdue]}>
                  {r41Status.overdue
                    ? `Lectura R-41 vencida${r41Status.minutesLate > 0 ? ` hace ${r41Status.minutesLate} min` : ''}`
                    : `Próxima lectura R-41 · ${r41Status.nextReadingAt ? formatDate(r41Status.nextReadingAt) : '—'}`}
                </Text>
                <Text style={styles.muted}>
                  {r41Status.readings
                    ? `${r41Status.readings} lectura${r41Status.readings === 1 ? '' : 's'} · última ${formatDate(r41Status.lastReadingAt ?? '')}${r41Status.lastReadingComplete ? ' completa' : ' parcial'}.`
                    : 'Aún no hay lecturas.'}
                  {` Mide cada ${r41Status.intervalMinutes} min: ventilador, medio y puerta, interior y exterior de ambos lados.`}
                </Text>
              </View>
            ) : null}

            <View style={styles.workGrid}>
              <View style={styles.positionPanel}>
                <Text style={styles.panelTitle}>Plano del túnel</Text>
                <Text style={styles.muted}>Selecciona una posición libre o una compartida por saldos. Los pallets completos son exclusivos.</Text>
                <View style={styles.tunnelDirection}>
                  <Text style={styles.tunnelDirectionBack}>FONDO</Text>
                  <Text style={styles.tunnelDirectionCopy}>Lado A / Lado B</Text>
                </View>
                <View style={styles.positions}>
                  {[...selectedTunnel.posiciones].sort((left, right) => left.numero - right.numero).map((position) => {
                    const assignments = assignmentsByPosition.get(position.id) ?? [];
                    const selected = selectedPositionId === position.id;
                    const side = position.numero % 2 === 1 ? 'A' : 'B';
                    const depth = Math.ceil(position.numero / 2);
                    const boxes = assignments.reduce(
                      (total, item) => total + (item.folio?.cantidad_cajas ?? 0),
                      0,
                    );
                    const positionContent = assignments.length === 0
                      ? 'Libre'
                      : assignments.length === 1
                        ? assignments[0].folio?.numero_folio ?? 'Ocupado'
                        : `${assignments.length} saldos${boxes > 0 ? ` · ${boxes} cajas` : ''}`;
                    return (
                      <Pressable
                        key={position.id}
                        disabled={!position.activa}
                        onPress={() => setSelectedPositionId(position.id)}
                        style={[
                          styles.position,
                          assignments.length > 0 && styles.positionOccupied,
                          selected && styles.positionSelected,
                          !position.activa && styles.positionDisabled,
                        ]}
                      >
                        <Text style={styles.positionLabel}>P{String(position.numero).padStart(2, '0')}</Text>
                        <Text style={styles.positionMeta}>Lado {side} · Prof. {depth}</Text>
                        <Text numberOfLines={2} style={styles.positionFolio}>
                          {positionContent}
                        </Text>
                      </Pressable>
                    );
                  })}
                </View>
                <View style={styles.tunnelDirection}>
                  <Text style={styles.tunnelDirectionCopy}>Recorrido operacional</Text>
                  <Text style={styles.tunnelDirectionEntrance}>ENTRADA</Text>
                </View>
              </View>

              <View style={styles.operationPanel}>
                <Text style={styles.panelTitle}>Escaneo de carga</Text>
                <Text style={styles.selectedPosition}>Posición: {selectedPosition?.etiqueta ?? 'selecciona una posición'}</Text>
                {selectedAssignments.length > 0 ? (
                  <View style={styles.positionContents}>
                    <Text style={styles.positionContentsTitle}>
                      Contenido actual · {selectedAssignments.length} folio{selectedAssignments.length === 1 ? '' : 's'}
                    </Text>
                    {selectedAssignments.map((assignment) => (
                      <View key={assignment.id} style={styles.positionContentRow}>
                        <View style={styles.positionContentCopy}>
                          <Text style={styles.positionContentFolio}>{assignment.folio?.numero_folio ?? 'Folio'}</Text>
                          <Text style={styles.positionContentMeta}>
                            {assignment.folio?.tipo_bulto === 'saldo' ? 'Saldo' : 'Pallet completo'}
                            {assignment.folio?.cantidad_cajas ? ` · ${assignment.folio.cantidad_cajas} cajas` : ''}
                          </Text>
                        </View>
                        <Pressable
                          disabled={processActionsBlocked}
                          onPress={() => void removeAssignment(
                            assignment.id,
                            assignment.folio?.numero_folio ?? 'folio',
                          )}
                          style={[styles.smallDangerButton, processActionsBlocked && styles.buttonDisabled]}
                        >
                          <Text style={styles.smallDangerText}>Retirar</Text>
                        </Pressable>
                      </View>
                    ))}
                  </View>
                ) : null}
                <TextInput
                  ref={scannerRef}
                  autoCapitalize="characters"
                  autoCorrect={false}
                  editable={canOperate && LOADABLE_STATES.has(selectedProcess.estado) && !processActionsBlocked}
                  onChangeText={setFolioNumber}
                  onSubmitEditing={() => void addFolio()}
                  placeholder="Escanea el folio"
                  placeholderTextColor={colors.muted}
                  returnKeyType="done"
                  style={styles.scannerInput}
                  value={folioNumber}
                />
                <TextInput
                  editable={canOperate && LOADABLE_STATES.has(selectedProcess.estado) && !processActionsBlocked}
                  keyboardType="decimal-pad"
                  onChangeText={setInitialTemperature}
                  placeholder="Temperatura inicial opcional"
                  placeholderTextColor={colors.muted}
                  style={styles.input}
                  value={initialTemperature}
                />
                <TextInput
                  editable={canOperate && LOADABLE_STATES.has(selectedProcess.estado) && !processActionsBlocked}
                  onChangeText={setLoadOccurredAt}
                  placeholder="DD-MM-AAAA HH:mm"
                  placeholderTextColor={colors.muted}
                  style={styles.input}
                  value={loadOccurredAt}
                />
                <Text style={styles.muted}>Fecha y hora real de carga del folio.</Text>
                <Pressable
                  disabled={!canOperate || !LOADABLE_STATES.has(selectedProcess.estado) || processActionsBlocked}
                  onPress={() => void addFolio()}
                  style={[styles.primaryButton, (!canOperate || !LOADABLE_STATES.has(selectedProcess.estado) || processActionsBlocked) && styles.buttonDisabled]}
                >
                  <Text style={styles.primaryButtonText}>Agregar al túnel</Text>
                </Pressable>

                <View style={styles.actionDivider} />
                <ProcessActions
                  process={selectedProcess}
                  canOperate={canOperate}
                  disabled={processActionsBlocked}
                  onConfirm={() => openStateAction({ kind: 'confirmar_armado', title: 'Confirmar armado', description: 'Después de confirmar ya no deben agregarse pallets.', label: 'Armado confirmado', route: `/api/prefrio/procesos/${selectedProcess.id}/confirmar-armado`, nextState: 'listo_para_iniciar' })}
                  onStart={() => openStateAction({ kind: 'iniciar', title: 'Iniciar proceso', description: 'Indica la fecha y hora real en que comenzó el ciclo térmico.', label: 'Proceso iniciado', route: `/api/prefrio/procesos/${selectedProcess.id}/iniciar`, nextState: 'en_proceso' })}
                  onVerify={() => openStateAction({ kind: 'verificar', title: 'Finalizar proceso', description: 'Indica cuándo terminó realmente el ciclo y envíalo a verificación.', label: 'Proceso enviado a verificación', route: `/api/prefrio/procesos/${selectedProcess.id}/verificar`, nextState: 'pendiente_verificacion' })}
                  onEvent={(draft) => { setEventDraft(draft); setEventOccurredAt(operationalDateTime()); setEventNote(''); setReadingDraft(emptyR41Draft()); setError(''); }}
                  onLeave={() => {
                    setSelectedTunnelId(selectedTunnel.id);
                    setSelectedProcessId(null);
                    setSelectedPositionId(null);
                  }}
                />
              </View>
            </View>

            <View style={styles.panel}>
              <Text style={styles.panelTitle}>Últimos eventos</Text>
              {selectedProcess.eventos.slice(0, 8).map((event) => (
                <View key={event.id} style={styles.timelineItem}>
                  <Text style={styles.timelineType}>{stateLabel(event.tipo)}</Text>
                  <Text style={styles.timelineMeta}>{formatDate(event.ocurrido_at)} · {event.usuario?.nombre ?? 'PDA'}</Text>
                  {r41ReadingSummary(r41SectorsOf(event)) ? <Text style={styles.timelineNote}>{r41ReadingSummary(r41SectorsOf(event))}</Text> : null}
                  {event.observacion ? <Text style={styles.timelineNote}>{event.observacion}</Text> : null}
                </View>
              ))}
              {!selectedProcess.eventos.length ? <Text style={styles.muted}>Aún no existen eventos.</Text> : null}
            </View>
          </>
        )}

        <View style={styles.panel}>
          <View style={styles.panelHeading}>
            <View><Text style={styles.panelTitle}>Bandeja offline</Text><Text style={styles.muted}>Las operaciones se guardan antes de transmitirse.</Text></View>
            <Text style={styles.queueCount}>{outbox.length}</Text>
          </View>
          {outbox.map((item) => (
            <View key={item.id} style={styles.queueItem}>
              <View style={styles.queueCopy}>
                <Text style={styles.queueLabel}>{item.process_code} · {item.label}</Text>
                <Text style={styles.queueMeta}>{stateLabel(item.status)} · {formatDate(item.created_at)}</Text>
                {item.message ? <Text style={styles.queueError}>{item.message}</Text> : null}
              </View>
              {item.status !== 'pendiente' ? (
                <Pressable onPress={() => void discardCommand(item)} style={styles.smallDangerButton}>
                  <Text style={styles.smallDangerText}>Descartar operaciones y refrescar</Text>
                </Pressable>
              ) : null}
            </View>
          ))}
          {!outbox.length ? <Text style={styles.muted}>No existen operaciones pendientes.</Text> : null}
          {selectedProcessId && processQueue.some((item) => item.status !== 'pendiente') ? (
            <Text style={styles.warning}>Este proceso está bloqueado para evitar duplicados. Descarta sus operaciones y refresca antes de repetir la acción sobre el estado confirmado.</Text>
          ) : null}
        </View>
      </ScrollView>

      {busy ? <View style={styles.loading}><ActivityIndicator color={colors.cyan} size="large" /></View> : null}

      <Modal animationType="fade" onRequestClose={() => setEventDraft(null)} transparent visible={eventDraft !== null}>
        <View style={styles.modalBackdrop}>
          <ScrollView contentContainerStyle={styles.modalScrollContent} keyboardShouldPersistTaps="handled" style={[styles.modalCard, styles.modalScroll]}>
            <Text style={styles.eyebrow}>EVENTO OPERACIONAL</Text>
            <Text style={styles.modalTitle}>{eventDraft?.title}</Text>
            {eventDraft?.type === 'lectura' ? (
              <>
                <Text style={styles.muted}>Lectura horaria POEMP R-41: registra los sensores que midas; puede quedar parcial.</Text>
                <R41ReadingGrid draft={readingDraft} onChange={setReadingDraft} />
              </>
            ) : eventDraft?.requiresTemperature ? (
              <TextInput
                autoFocus
                keyboardType="decimal-pad"
                onChangeText={setEventTemperature}
                placeholder="Temperatura °C"
                placeholderTextColor={colors.muted}
                style={styles.input}
                value={eventTemperature}
              />
            ) : null}
            <TextInput
              onChangeText={setEventOccurredAt}
              placeholder="DD-MM-AAAA HH:mm"
              placeholderTextColor={colors.muted}
              style={styles.input}
              value={eventOccurredAt}
            />
            <Text style={styles.muted}>Fecha y hora real del evento.</Text>
            <TextInput
              multiline
              onChangeText={setEventNote}
              placeholder="Observación opcional"
              placeholderTextColor={colors.muted}
              style={[styles.input, styles.multiline]}
              value={eventNote}
            />
            <View style={styles.modalActions}>
              <Pressable onPress={() => setEventDraft(null)} style={styles.secondaryButton}><Text style={styles.secondaryButtonText}>Cancelar</Text></Pressable>
              <Pressable disabled={operationBusy} onPress={() => void registerEvent()} style={[styles.primaryButton, operationBusy && styles.buttonDisabled]}><Text style={styles.primaryButtonText}>Registrar</Text></Pressable>
            </View>
          </ScrollView>
        </View>
      </Modal>

      <Modal animationType="fade" onRequestClose={() => setActionDraft(null)} transparent visible={actionDraft !== null}>
        <View style={styles.modalBackdrop}>
          <ScrollView contentContainerStyle={styles.modalScrollContent} keyboardShouldPersistTaps="handled" style={[styles.modalCard, styles.modalScroll]}>
            <Text style={styles.eyebrow}>ACCIÓN DE PREFRÍO</Text>
            <Text style={styles.modalTitle}>{actionDraft?.title}</Text>
            <Text style={styles.muted}>{actionDraft?.description}</Text>
            {actionDraft?.kind === 'verificar' ? (
              <>
                <Text style={finalReadingRequired ? styles.r41Required : styles.muted}>
                  {finalReadingRequired
                    ? 'Lectura final obligatoria: completa los 3 sectores, interior y exterior de ambos lados.'
                    : 'La última lectura horaria está completa y servirá de lectura final. Si mides de nuevo, completa los 12 sensores.'}
                </Text>
                <R41ReadingGrid draft={readingDraft} onChange={setReadingDraft} />
              </>
            ) : null}
            <TextInput
              autoFocus={actionDraft?.kind !== 'verificar'}
              onChangeText={setActionOccurredAt}
              placeholder="DD-MM-AAAA HH:mm"
              placeholderTextColor={colors.muted}
              style={styles.input}
              value={actionOccurredAt}
            />
            <TextInput
              multiline
              onChangeText={setActionNote}
              placeholder="Observación opcional"
              placeholderTextColor={colors.muted}
              style={[styles.input, styles.multiline]}
              value={actionNote}
            />
            <View style={styles.modalActions}>
              <Pressable onPress={() => setActionDraft(null)} style={styles.secondaryButton}><Text style={styles.secondaryButtonText}>Cancelar</Text></Pressable>
              <Pressable disabled={operationBusy} onPress={() => void registerStateAction()} style={[styles.primaryButton, operationBusy && styles.buttonDisabled]}><Text style={styles.primaryButtonText}>Registrar</Text></Pressable>
            </View>
          </ScrollView>
        </View>
      </Modal>

      <Modal animationType="fade" onRequestClose={() => setRemovalDraft(null)} transparent visible={removalDraft !== null}>
        <View style={styles.modalBackdrop}>
          <View style={styles.modalCard}>
            <Text style={styles.eyebrow}>RETIRO DE FOLIO</Text>
            <Text style={styles.modalTitle}>{removalDraft?.folioLabel}</Text>
            <Text style={styles.muted}>Indica cuándo se retiró realmente de la carga del túnel.</Text>
            <TextInput
              autoFocus
              onChangeText={setRemovalOccurredAt}
              placeholder="DD-MM-AAAA HH:mm"
              placeholderTextColor={colors.muted}
              style={styles.input}
              value={removalOccurredAt}
            />
            <TextInput
              multiline
              onChangeText={setRemovalNote}
              placeholder="Observación opcional"
              placeholderTextColor={colors.muted}
              style={[styles.input, styles.multiline]}
              value={removalNote}
            />
            <View style={styles.modalActions}>
              <Pressable onPress={() => setRemovalDraft(null)} style={styles.secondaryButton}><Text style={styles.secondaryButtonText}>Cancelar</Text></Pressable>
              <Pressable disabled={operationBusy} onPress={() => void registerRemoval()} style={[styles.smallDangerButton, operationBusy && styles.buttonDisabled]}><Text style={styles.smallDangerText}>Retirar</Text></Pressable>
            </View>
          </View>
        </View>
      </Modal>

      <Modal animationType="fade" onRequestClose={() => setCreating(false)} transparent visible={creating}>
        <View style={styles.modalBackdrop}>
          <View style={styles.modalCard}>
            <Text style={styles.eyebrow}>NUEVO PROCESO</Text>
            <Text style={styles.modalTitle}>{selectedTunnel?.codigo}</Text>
            <TextInput keyboardType="decimal-pad" onChangeText={setCreateSetpoint} placeholder="Setpoint" placeholderTextColor={colors.muted} style={styles.input} value={createSetpoint} />
            <TextInput keyboardType="number-pad" onChangeText={setCreateDuration} placeholder="Duración objetivo en minutos" placeholderTextColor={colors.muted} style={styles.input} value={createDuration} />
            <TextInput onChangeText={setCreateFormat} placeholder="Formato de referencia" placeholderTextColor={colors.muted} style={styles.input} value={createFormat} />
            <TextInput onChangeText={setCreateOccurredAt} placeholder="DD-MM-AAAA HH:mm" placeholderTextColor={colors.muted} style={styles.input} value={createOccurredAt} />
            <View style={styles.modalActions}>
              <Pressable onPress={() => setCreating(false)} style={styles.secondaryButton}><Text style={styles.secondaryButtonText}>Cancelar</Text></Pressable>
              <Pressable disabled={operationBusy} onPress={() => void createProcess()} style={[styles.primaryButton, operationBusy && styles.buttonDisabled]}><Text style={styles.primaryButtonText}>Crear proceso</Text></Pressable>
            </View>
          </View>
        </View>
      </Modal>
    </View>
  );
}

function ProcessActions({
  process,
  canOperate,
  disabled,
  onConfirm,
  onStart,
  onVerify,
  onEvent,
  onLeave,
}: {
  process: PrefrioProcess;
  canOperate: boolean;
  disabled: boolean;
  onConfirm: () => void;
  onStart: () => void;
  onVerify: () => void;
  onEvent: (draft: EventDraft) => void;
  onLeave: () => void;
}) {
  if (!ACTIVE_STATES.has(process.estado)) {
    return (
      <View>
        <Text style={styles.muted}>El proceso está {stateLabel(process.estado).toLowerCase()} y ya no admite operaciones.</Text>
        <Pressable onPress={onLeave} style={styles.secondaryButton}>
          <Text style={styles.secondaryButtonText}>Volver al túnel</Text>
        </Pressable>
      </View>
    );
  }

  if (!canOperate) return <Text style={styles.muted}>Perfil de consulta: no puedes ejecutar acciones.</Text>;

  if (disabled) {
    return <Text style={styles.warning}>Acciones bloqueadas mientras se envía o recupera la bandeja de este proceso.</Text>;
  }

  if (['borrador', 'cargando'].includes(process.estado)) {
    return <Pressable onPress={onConfirm} style={styles.primaryButton}><Text style={styles.primaryButtonText}>Confirmar armado</Text></Pressable>;
  }

  if (process.estado === 'listo_para_iniciar') {
    return <Pressable onPress={onStart} style={styles.primaryButton}><Text style={styles.primaryButtonText}>Iniciar proceso</Text></Pressable>;
  }

  if (process.estado === 'en_proceso') {
    return (
      <View style={styles.eventGrid}>
        <EventButton label="Inversión" onPress={() => onEvent({ type: 'inversion_registrada', title: 'Registrar inversión', requiresTemperature: false })} />
        <EventButton label="Pausa" onPress={() => onEvent({ type: 'pausa', title: 'Registrar pausa', requiresTemperature: false })} />
        <EventButton label="Reanudar" onPress={() => onEvent({ type: 'reanudacion', title: 'Registrar reanudación', requiresTemperature: false })} />
        <EventButton label="Deshielo" onPress={() => onEvent({ type: 'deshielo', title: 'Registrar deshielo', requiresTemperature: false })} />
        <EventButton label="Lectura R-41" onPress={() => onEvent({ type: 'lectura', title: 'Registrar lectura', requiresTemperature: false })} />
        <Pressable onPress={onVerify} style={styles.primaryButton}><Text style={styles.primaryButtonText}>Enviar a verificación</Text></Pressable>
      </View>
    );
  }

  if (process.estado === 'pendiente_verificacion') {
    return <Text style={styles.muted}>El proceso espera una decisión de supervisión.</Text>;
  }

  return <Text style={styles.muted}>El proceso no posee acciones operacionales disponibles.</Text>;
}

/** Grilla POEMP R-41: 3 sectores × exterior e interior de cada lado. */
function R41ReadingGrid({ draft, onChange }: { draft: R41Draft; onChange: (draft: R41Draft) => void }) {
  const change = (sector: R41SectorKey, point: R41PointKey, value: string) => onChange(updateR41Draft(draft, sector, point, value));
  return (
    <View accessibilityLabel="Lectura por sector" style={styles.r41Grid}>
      <View style={styles.r41Row}>
        <Text style={[styles.r41Head, styles.r41SectorCell]}>°C</Text>
        {R41_POINTS.map((point) => <Text key={point.key} style={[styles.r41Head, styles.r41Cell]}>{point.label}</Text>)}
      </View>
      {R41_SECTORS.map((sector) => (
        <View key={sector.key} style={styles.r41Row}>
          <Text style={[styles.r41SectorLabel, styles.r41SectorCell]}>{sector.label}</Text>
          {R41_POINTS.map((point) => (
            <TextInput
              key={point.key}
              accessibilityLabel={`${sector.label} ${point.label}`}
              keyboardType="numbers-and-punctuation"
              onChangeText={(value) => change(sector.key, point.key, value)}
              placeholder="—"
              placeholderTextColor={colors.muted}
              style={[styles.input, styles.r41Input, styles.r41Cell]}
              value={draft[sector.key][point.key]}
            />
          ))}
        </View>
      ))}
    </View>
  );
}

function EventButton({ label, onPress }: { label: string; onPress: () => void }) {
  return <Pressable onPress={onPress} style={styles.eventButton}><Text style={styles.eventButtonText}>{label}</Text></Pressable>;
}

function Metric({ label, value, warning = false }: { label: string; value: string; warning?: boolean }) {
  return <View style={styles.metric}><Text style={styles.metricLabel}>{label}</Text><Text style={[styles.metricValue, warning && styles.metricWarning]}>{value}</Text></View>;
}

function commandFor(
  id: string,
  process: PrefrioProcess,
  kind: PrefrioQueuedCommand['kind'],
  label: string,
  route: string,
  payload: PrefrioQueuedCommand['payload'],
): PrefrioQueuedCommand {
  return {
    id,
    process_id: process.id,
    process_code: process.codigo,
    kind,
    label,
    route,
    payload,
    status: 'pendiente',
    attempts: 0,
    created_at: new Date().toISOString(),
    last_attempt_at: null,
    message: null,
  };
}

function hasEquivalentPendingCommand(
  items: PrefrioQueuedCommand[],
  command: PrefrioQueuedCommand,
) {
  return items.some((item) => {
    if (item.process_id !== command.process_id || item.status !== 'pendiente') return false;
    if (UNIQUE_STATE_COMMANDS.has(command.kind)) return item.kind === command.kind;
    if (item.kind !== command.kind || item.route !== command.route) return false;

    if (command.kind === 'agregar_folio') {
      return 'folio_id' in item.payload
        && 'folio_id' in command.payload
        && item.payload.folio_id === command.payload.folio_id;
    }
    if (command.kind === 'retirar_folio') return true;
    if (command.kind === 'evento') {
      return item.payload.ocurrido_at === command.payload.ocurrido_at;
    }
    return false;
  });
}

function nextFreePositionId(process: PrefrioProcess, tunnel: PrefrioTunnel) {
  const occupied = new Set(process.folios
    .filter((item) => !['retirado', 'cancelado'].includes(item.estado))
    .map((item) => item.posicion?.id));
  return tunnel.posiciones.find((item) => item.activa && !occupied.has(item.id))?.id ?? null;
}

function stateLabel(value: string) {
  return value.replaceAll('_', ' ').replace(/^./, (letter) => letter.toUpperCase());
}

function formatTemperature(value: number | null) {
  return value === null ? 'Sin setpoint' : `${value.toFixed(1)} °C`;
}

function formatDate(value: string) {
  return new Date(value).toLocaleString('es-CL', { dateStyle: 'short', timeStyle: 'short' });
}

function messageFrom(reason: unknown) {
  return reason instanceof Error ? reason.message : 'Ocurrió un problema inesperado.';
}

const styles = StyleSheet.create({
  screen: { flex: 1, backgroundColor: colors.background },
  page: { padding: 18, gap: 16 },
  boot: { flex: 1, alignItems: 'center', justifyContent: 'center', gap: 12, backgroundColor: colors.background },
  topbar: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', gap: 16 },
  topbarActions: { flexDirection: 'row', alignItems: 'center', gap: 8 },
  eyebrow: { color: colors.cyan, fontSize: 14, fontFamily: font.heavy, letterSpacing: 1.2 },
  title: { color: colors.text, fontSize: 26, fontFamily: font.heavy, marginTop: 4 },
  muted: { fontFamily: font.regular, color: colors.muted, fontSize: 12, lineHeight: 18 },
  notice: { color: colors.green, backgroundColor: colors.greenDark, padding: 10, borderRadius: 8, fontFamily: font.heavy },
  error: { color: colors.red, backgroundColor: colors.blocked, padding: 10, borderRadius: 8, fontFamily: font.heavy },
  warning: { color: colors.amber, marginTop: 10, fontFamily: font.heavy },
  connection: { paddingHorizontal: 10, paddingVertical: 7, borderRadius: 999 },
  connectionOnline: { backgroundColor: colors.greenDark },
  connectionOffline: { backgroundColor: colors.blocked },
  connectionText: { color: colors.text, fontSize: 13, fontFamily: font.heavy },
  metrics: { flexDirection: 'row', gap: 10 },
  metric: { flex: 1, padding: 13, borderRadius: 12, backgroundColor: colors.panel, borderWidth: 1, borderColor: colors.borderSoft },
  metricLabel: { color: colors.muted, fontSize: 13, fontFamily: font.heavy },
  metricValue: { color: colors.text, fontSize: 24, fontFamily: font.heavy, marginTop: 5 },
  metricWarning: { color: colors.amber },
  sectionTitle: { color: colors.text, fontSize: 16, fontFamily: font.heavy },
  tunnelRow: { gap: 10, paddingBottom: 4 },
  tunnelCard: { width: 230, padding: 14, borderRadius: 14, backgroundColor: colors.panel, borderWidth: 1, borderColor: colors.border },
  tunnelCardSelected: { borderColor: colors.cyan, backgroundColor: colors.selected },
  tunnelCode: { color: colors.cyan, fontFamily: font.monoBold, fontSize: 16 },
  tunnelName: { color: colors.text, fontFamily: font.heavy, marginTop: 4 },
  tunnelMeta: { fontFamily: font.regular, color: colors.muted, fontSize: 14, marginTop: 8 },
  tunnelState: { color: colors.green, fontSize: 14, fontFamily: font.heavy, marginTop: 10 },
  tunnelProcess: { fontFamily: font.regular, color: colors.text, fontSize: 14, marginTop: 3 },
  panel: { padding: 16, borderRadius: 14, backgroundColor: colors.panel, borderWidth: 1, borderColor: colors.borderSoft, gap: 10 },
  emptyPanel: { minHeight: 150, alignItems: 'center', justifyContent: 'center', borderRadius: 14, backgroundColor: colors.panel },
  panelTitle: { color: colors.text, fontSize: 17, fontFamily: font.heavy },
  panelHeading: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' },
  processHeader: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', padding: 16, borderRadius: 14, backgroundColor: colors.panelStrong, borderWidth: 1, borderColor: colors.cyanDark },
  processCode: { color: colors.text, fontSize: 24, fontFamily: font.monoBold, marginTop: 4 },
  processCounts: { alignItems: 'flex-end' },
  processCountValue: { color: colors.cyan, fontSize: 25, fontFamily: font.heavy },
  processCountLabel: { color: colors.muted, fontSize: 13, fontFamily: font.heavy },
  workGrid: { flexDirection: 'row', gap: 14, alignItems: 'flex-start' },
  positionPanel: { flex: 2, padding: 16, borderRadius: 14, backgroundColor: colors.panel, borderWidth: 1, borderColor: colors.borderSoft },
  operationPanel: { flex: 1, padding: 16, borderRadius: 14, backgroundColor: colors.panel, borderWidth: 1, borderColor: colors.borderSoft, gap: 10 },
  tunnelDirection: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginTop: 12 },
  tunnelDirectionBack: { color: colors.amber, fontSize: 14, fontFamily: font.heavy, letterSpacing: 1 },
  tunnelDirectionEntrance: { color: colors.cyan, fontSize: 14, fontFamily: font.heavy, letterSpacing: 1 },
  tunnelDirectionCopy: { color: colors.muted, fontSize: 13, fontFamily: font.heavy },
  positions: { flexDirection: 'row', flexWrap: 'wrap', gap: 8, marginTop: 14 },
  position: { width: '48%', minHeight: 76, justifyContent: 'center', padding: 8, borderRadius: 10, borderWidth: 1, borderColor: colors.freeBorder, backgroundColor: colors.free },
  positionOccupied: { borderColor: colors.palletBorder, backgroundColor: colors.pallet },
  positionSelected: { borderColor: colors.cyan, backgroundColor: colors.selected },
  positionDisabled: { opacity: 0.35 },
  positionLabel: { color: colors.text, fontSize: 15, fontFamily: font.heavy },
  positionMeta: { fontFamily: font.regular, color: colors.muted, fontSize: 13, marginTop: 2 },
  positionFolio: { fontFamily: font.mono, color: colors.text, fontSize: 13, marginTop: 4 },
  selectedPosition: { color: colors.cyan, fontFamily: font.heavy },
  positionContents: { gap: 6, padding: 9, borderRadius: 9, backgroundColor: colors.backgroundDeep, borderWidth: 1, borderColor: colors.borderSoft },
  positionContentsTitle: { color: colors.muted, fontSize: 13, fontFamily: font.heavy },
  positionContentRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 8, paddingTop: 5, borderTopWidth: 1, borderTopColor: colors.borderSoft },
  positionContentCopy: { flex: 1 },
  positionContentFolio: { color: colors.text, fontSize: 14, fontFamily: font.monoBold },
  positionContentMeta: { fontFamily: font.regular, color: colors.muted, fontSize: 13, marginTop: 2 },
  scannerInput: { minHeight: 58, paddingHorizontal: 14, borderRadius: 10, borderWidth: 2, borderColor: colors.cyanDark, backgroundColor: colors.backgroundDeep, color: colors.text, fontSize: 20, fontFamily: font.monoBold },
  input: { minHeight: 52, paddingHorizontal: 12, borderRadius: 9, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.backgroundDeep, color: colors.text },
  multiline: { minHeight: 96, paddingTop: 12, textAlignVertical: 'top' },
  primaryButton: { minHeight: 56, alignItems: 'center', justifyContent: 'center', paddingHorizontal: 14, borderRadius: 9, backgroundColor: colors.cyan },
  primaryButtonText: { color: colors.accentText, fontFamily: font.heavy },
  secondaryButton: { minHeight: 48, alignItems: 'center', justifyContent: 'center', paddingHorizontal: 12, borderRadius: 8, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.panel },
  secondaryButtonText: { color: colors.text, fontSize: 14, fontFamily: font.heavy },
  buttonDisabled: { opacity: 0.4 },
  actionDivider: { height: 1, backgroundColor: colors.borderSoft, marginVertical: 4 },
  eventGrid: { gap: 8 },
  eventButton: { minHeight: 48, alignItems: 'center', justifyContent: 'center', borderRadius: 8, borderWidth: 1, borderColor: colors.cyanDark, backgroundColor: colors.panelStrong },
  eventButtonText: { color: colors.cyan, fontFamily: font.heavy },
  timelineItem: { paddingVertical: 8, borderBottomWidth: 1, borderBottomColor: colors.borderSoft },
  timelineType: { color: colors.text, fontFamily: font.heavy },
  timelineMeta: { fontFamily: font.regular, color: colors.muted, fontSize: 14, marginTop: 2 },
  timelineNote: { fontFamily: font.regular, color: colors.text, fontSize: 14, marginTop: 4 },
  queueCount: { color: colors.cyan, fontSize: 22, fontFamily: font.heavy },
  queueItem: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', gap: 12, paddingVertical: 9, borderBottomWidth: 1, borderBottomColor: colors.borderSoft },
  queueCopy: { flex: 1 },
  queueLabel: { color: colors.text, fontFamily: font.heavy },
  queueMeta: { fontFamily: font.regular, color: colors.muted, fontSize: 14, marginTop: 2 },
  queueError: { fontFamily: font.regular, color: colors.red, fontSize: 14, marginTop: 4 },
  smallDangerButton: { paddingHorizontal: 10, paddingVertical: 8, borderRadius: 8, borderWidth: 1, borderColor: colors.red },
  smallDangerText: { color: colors.red, fontSize: 14, fontFamily: font.heavy },
  loading: { ...StyleSheet.absoluteFillObject, alignItems: 'center', justifyContent: 'center', backgroundColor: 'rgba(8,12,16,0.68)' },
  modalBackdrop: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: 24, backgroundColor: 'rgba(0,0,0,0.72)' },
  modalCard: { width: '100%', maxWidth: 520, padding: 20, gap: 12, borderRadius: 16, backgroundColor: colors.panel, borderWidth: 1, borderColor: colors.cyanDark },
  modalTitle: { color: colors.text, fontSize: 22, fontFamily: font.heavy },
  modalScroll: { flexGrow: 0, maxHeight: '92%' },
  modalScrollContent: { gap: 12 },
  modalActions: { flexDirection: 'row', justifyContent: 'flex-end', gap: 10 },
  r41Banner: { padding: 12, gap: 4, borderRadius: 12, borderWidth: 1, borderColor: colors.cyanDark, backgroundColor: colors.panel },
  r41BannerOverdue: { borderColor: colors.amber },
  r41BannerTitle: { color: colors.cyan, fontSize: 15, fontFamily: font.heavy },
  r41BannerTitleOverdue: { color: colors.amber },
  r41Required: { color: colors.amber, fontSize: 13, fontFamily: font.heavy },
  r41Grid: { gap: 6 },
  r41Row: { flexDirection: 'row', alignItems: 'center', gap: 6 },
  r41SectorCell: { width: 84 },
  r41Cell: { flex: 1 },
  r41Head: { color: colors.muted, fontSize: 12, fontFamily: font.heavy, textAlign: 'center' },
  r41SectorLabel: { color: colors.text, fontSize: 14, fontFamily: font.heavy },
  r41Input: { minHeight: 46, paddingHorizontal: 6, textAlign: 'center', fontFamily: font.monoBold },
});
