import * as Crypto from 'expo-crypto';
import { useEffect, useMemo, useRef, useState } from 'react';
import {
  ActivityIndicator,
  Alert,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  useWindowDimensions,
  View,
} from 'react-native';

import { ActionPanel } from '../components/ActionPanel';
import { CameraCard } from '../components/CameraCard';
import { MaterialDispatchOperation } from '../components/MaterialDispatchOperation';
import { MaterialTransformationOperation } from '../components/MaterialTransformationOperation';
import {
  LocateFormValue,
  LocateModal,
  MaterialDispatchFormValue,
  MaterialDispatchModal,
  MoveModal,
} from '../components/OperationModals';
import { PositionMap } from '../components/PositionMap';
import { NotificationCenter } from '../components/NotificationCenter';
import { RecentMovements } from '../components/RecentMovements';
import { RefrigeratedLoadOperation } from '../components/RefrigeratedLoadOperation';
import { OPERATIONAL_POLL_INTERVAL_MS } from '../config/polling';
import { useOperationalPolling } from '../hooks/useOperationalPolling';
import { cameraDisplayName } from '../domain/cameras';
import {
  AuthSession,
  CameraPlan,
  CameraSummary,
  LocatePayload,
  MaterialCatalog,
  MaterialDispatch,
  Movement,
  MovePayload,
  Position,
  SagCondition,
} from '../domain/estiba';
import { EstibaApi } from '../services/estibaApi';
import { ApiError } from '../services/apiError';
import { colors } from '../theme/colors';

type OperationalScreenProps = {
  api: EstibaApi;
  auth: AuthSession;
  onLogout: () => void;
};

export function OperationalScreen({ api, auth, onLogout }: OperationalScreenProps) {
  const { height, width } = useWindowDimensions();
  const wideLayout = width >= 1180 && height >= 700;
  const [cameras, setCameras] = useState<CameraSummary[]>([]);
  const [conditions, setConditions] = useState<SagCondition[]>([]);
  const [materialCatalog, setMaterialCatalog] = useState<MaterialCatalog>({ temporada: null, clientes: [], items: [], destinos: [] });
  const [materialDispatches, setMaterialDispatches] = useState<MaterialDispatch[]>([]);
  const [selectedCameraId, setSelectedCameraId] = useState<string | null>(null);
  const [plan, setPlan] = useState<CameraPlan | null>(null);
  const [movements, setMovements] = useState<Movement[]>([]);
  const [selectedPositionId, setSelectedPositionId] = useState<string | null>(null);
  const [selectedFolioId, setSelectedFolioId] = useState<string | null>(null);
  const [destinationPlan, setDestinationPlan] = useState<CameraPlan | null>(null);
  const [selectedDestination, setSelectedDestination] = useState<Position | null>(null);
  const [locateVisible, setLocateVisible] = useState(false);
  const [locateCameraOnly, setLocateCameraOnly] = useState(false);
  const [selectedCameraOnlyFolioId, setSelectedCameraOnlyFolioId] = useState<string | null>(null);
  const [moveVisible, setMoveVisible] = useState(false);
  const [materialDispatchVisible, setMaterialDispatchVisible] = useState(false);
  const [activeModule, setActiveModule] = useState<'camaras' | 'cargas' | 'materiales' | 'transformacion'>('camaras');
  const [preferredLoadId, setPreferredLoadId] = useState<string | null>(null);
  const [unreadNotifications, setUnreadNotifications] = useState(0);
  const [busy, setBusy] = useState(true);
  const [error, setError] = useState('');
  const [modalError, setModalError] = useState('');
  const [notice, setNotice] = useState('');
  const [connectionState, setConnectionState] = useState<'connected' | 'offline'>('connected');
  const [lastSync, setLastSync] = useState<string | null>(null);
  const refreshInFlight = useRef(false);
  const materialDispatchRefreshInFlight = useRef<Promise<MaterialDispatch[]> | null>(null);
  const materialWithdrawOperationId = useRef(Crypto.randomUUID());
  const capabilities = auth.usuario.capacidades;
  const canUseMaterials = capabilities.puede_consultar_despachos_materiales;
  const canUseTransformations = capabilities.puede_consultar_transformaciones_materiales === true;
  const canWithdrawMaterial = capabilities.puede_retirar_materiales;
  const canUseLoads = capabilities.puede_consultar_cargas;

  const selectedPosition = useMemo(
    () => plan?.posiciones.find((position) => position.id === selectedPositionId) ?? null,
    [plan, selectedPositionId],
  );
  const operationalPosition = useMemo(() => {
    if (selectedPosition) {
      const folio = selectedPosition.folios?.find((candidate) => candidate.id === selectedFolioId)
        ?? selectedPosition.folio;

      return { ...selectedPosition, folio };
    }

    const folio = plan?.folios_sin_posicion?.find(
      (candidate) => candidate.id === selectedCameraOnlyFolioId,
    );
    if (!folio) return null;

    return {
      id: `camera-only:${folio.id}`,
      banda: 0,
      posicion: 0,
      nivel: 0,
      etiqueta: 'Sin posición',
      estado: 'activa' as const,
      ocupada: true,
      folio,
      folios: [folio],
    };
  }, [plan?.folios_sin_posicion, selectedCameraOnlyFolioId, selectedFolioId, selectedPosition]);
  const ownSession = plan?.acceso.modo === 'edicion' && plan.acceso.sesion?.es_propia
    ? plan.acceso.sesion
    : null;
  const canOpenSession = plan?.contenido === 'materia_prima'
    ? false
    : plan?.contenido === 'materiales'
      ? capabilities.puede_operar_materiales
      : capabilities.puede_operar_productos;
  const canOperate = Boolean(ownSession && canOpenSession);

  useEffect(() => {
    void initialize();
  }, []);

  useOperationalPolling(
    () => refreshCurrent({ quiet: true }),
    {
      intervalMs: OPERATIONAL_POLL_INTERVAL_MS,
      enabled: activeModule === 'camaras'
        && Boolean(selectedCameraId)
        && !busy
        && !locateVisible
        && !moveVisible
        && !materialDispatchVisible,
    },
  );

  useEffect(() => {
    if (!canUseMaterials || activeModule !== 'camaras') return;

    void refreshMaterialDispatches();
  }, [activeModule, canUseMaterials, auth.token]);

  useOperationalPolling(
    refreshMaterialDispatches,
    {
      intervalMs: OPERATIONAL_POLL_INTERVAL_MS,
      enabled: canUseMaterials && activeModule === 'camaras',
    },
  );

  async function initialize() {
    setBusy(true);
    setError('');
    try {
      const [loadedCameras, loadedConditions, loadedMaterialCatalog] = await Promise.all([
        api.listCameras(auth.token),
        capabilities.puede_operar_productos
          ? optionalModule(() => api.listConditions(auth.token), [])
          : Promise.resolve([]),
        canUseMaterials
          ? optionalModule(
            () => api.getMaterialCatalog(auth.token),
            { temporada: null, clientes: [], items: [], destinos: [] },
          )
          : Promise.resolve({ temporada: null, clientes: [], items: [], destinos: [] }),
      ]);
      setCameras(loadedCameras);
      setConditions(loadedConditions);
      setMaterialCatalog(loadedMaterialCatalog);
      setError('');
      setConnectionState('connected');
      if (loadedCameras[0]) {
        setSelectedCameraId(loadedCameras[0].id);
        await loadCamera(loadedCameras[0].id, false);
      }
    } catch (reason) {
      reportFailure(reason, setError);
    } finally {
      setBusy(false);
    }
  }

  async function loadCamera(cameraId: string, showBusy = true) {
    if (showBusy) setBusy(true);
    setError('');
    setSelectedPositionId(null);
    setSelectedFolioId(null);
    setSelectedCameraOnlyFolioId(null);
    try {
      const [loadedPlan, loadedMovements] = await Promise.all([
        api.getPlan(auth.token, cameraId),
        api.listRecent(auth.token, cameraId),
      ]);
      setPlan(loadedPlan);
      setMovements(loadedMovements);
      setError('');
      setConnectionState('connected');
      setLastSync(new Date().toLocaleTimeString('es-CL', { hour: '2-digit', minute: '2-digit' }));
    } catch (reason) {
      reportFailure(reason, setError);
    } finally {
      if (showBusy) setBusy(false);
    }
  }

  async function selectCamera(cameraId: string) {
    setSelectedCameraId(cameraId);
    setNotice('');
    await loadCamera(cameraId);
  }

  async function openPositionFromLoad(cameraId: string, positionId: string) {
    setActiveModule('camaras');
    setSelectedCameraId(cameraId);
    setNotice('Folio abierto desde la ruta de despacho.');
    await loadCamera(cameraId);
    setSelectedPositionId(positionId);
  }

  async function openPositionFromMaterialDispatch(
    cameraId: string,
    positionId: string | null,
    folioId: string,
  ) {
    setActiveModule('camaras');
    setSelectedCameraId(cameraId);
    setNotice('Folio abierto desde el despacho de materiales. Abre la estiba para registrar el retiro.');
    await loadCamera(cameraId);
    if (positionId) {
      setSelectedPositionId(positionId);
      setSelectedFolioId(folioId);
    } else {
      setSelectedPositionId(null);
      setSelectedCameraOnlyFolioId(folioId);
    }
  }

  async function refreshMaterialDispatches() {
    if (!canUseMaterials) return false;

    const request = materialDispatchRefreshInFlight.current
      ?? api.listMaterialDispatches(auth.token);
    const ownsRequest = materialDispatchRefreshInFlight.current === null;
    if (ownsRequest) materialDispatchRefreshInFlight.current = request;

    try {
      const loaded = await request;
      setMaterialDispatches(loaded);
      setConnectionState('connected');
      return true;
    } catch (reason) {
      reportFailure(reason, setError);
      return false;
    } finally {
      if (ownsRequest && materialDispatchRefreshInFlight.current === request) {
        materialDispatchRefreshInFlight.current = null;
      }
    }
  }

  async function openMaterialDispatch() {
    setModalError('');
    setNotice('');
    setBusy(true);

    const refreshed = await refreshMaterialDispatches();
    setBusy(false);
    if (!refreshed) {
      Alert.alert(
        'No fue posible actualizar los despachos',
        'Revisa la conexión con la API antes de registrar el retiro.',
      );
      return;
    }

    materialWithdrawOperationId.current = Crypto.randomUUID();
    setMaterialDispatchVisible(true);
  }

  async function refreshCurrent({ quiet = false }: { quiet?: boolean } = {}) {
    if (!selectedCameraId || refreshInFlight.current) return;

    refreshInFlight.current = true;
    if (!quiet) setBusy(true);

    try {
      const [loadedCameras, loadedPlan, loadedMovements] = await Promise.all([
        api.refreshCameras(auth.token),
        api.refreshPlan(auth.token, selectedCameraId),
        api.refreshRecent(auth.token, selectedCameraId),
      ]);

      if (loadedCameras) setCameras(loadedCameras);
      if (loadedPlan) setPlan(loadedPlan);
      if (loadedMovements) setMovements(loadedMovements);
      setError('');
      if (loadedPlan) {
        setSelectedPositionId((current) => (
          current && loadedPlan.posiciones.some((position) => position.id === current)
            ? current
            : null
        ));
      }
      setConnectionState('connected');
      setLastSync(new Date().toLocaleTimeString('es-CL', { hour: '2-digit', minute: '2-digit' }));
    } catch (reason) {
      reportFailure(reason, setError);
    } finally {
      refreshInFlight.current = false;
      if (!quiet) setBusy(false);
    }
  }

  function toggleSession() {
    if (!plan) return;
    if (!canOpenSession) {
      Alert.alert('Solo consulta', 'Tu perfil no puede operar cámaras de esta área.');
      return;
    }
    if (plan.acceso.modo === 'solo_lectura') {
      Alert.alert('Cámara en uso', 'Otro operador mantiene la sesión de edición.');
      return;
    }

    if (ownSession) {
      Alert.alert(
        'Cerrar estiba',
        '¿Deseas liberar ' + cameraDisplayName(plan) + '?',
        [
          { text: 'Cancelar', style: 'cancel' },
          { text: 'Cerrar', style: 'destructive', onPress: () => void closeCurrentSession() },
        ],
      );
      return;
    }

    void openCurrentSession();
  }

  async function openCurrentSession() {
    if (!plan) return;
    const succeeded = await runOperation(async () => {
      await api.openSession(auth.token, plan.id);
    });

    if (succeeded) {
      setNotice(`Estiba abierta en ${cameraDisplayName(plan)}. La cámara quedó reservada para esta tablet.`);
      await refreshCurrent();
    }
  }

  async function closeCurrentSession() {
    if (!ownSession) return;
    const cameraName = cameraDisplayName(plan);
    const succeeded = await runOperation(() => api.closeSession(auth.token, ownSession.id));

    if (succeeded) {
      setNotice(`Estiba cerrada en ${cameraName}. La cámara quedó disponible.`);
      await refreshCurrent();
    }
  }

  async function confirmLocate(form: LocateFormValue) {
    if (!plan || !ownSession || (!locateCameraOnly && !selectedPosition)) return;
    setModalError('');
    const data = {
      condicion_sag_id: form.condicion_sag_id,
      variedad: form.variedad,
      calibre: form.calibre,
      marca: form.marca,
      exportadora: form.exportadora,
    };
    const compactData = Object.fromEntries(
      Object.entries(data).filter(([, value]) => Boolean(value)),
    ) as NonNullable<LocatePayload['datos_folio']>;
    const payload: LocatePayload = {
      operacion_id: Crypto.randomUUID(),
      numero_folio: form.numero_folio,
      tipo_bulto: form.tipo_bulto,
      camara_destino_id: plan.id,
      ...(!locateCameraOnly && selectedPosition
        ? { posicion_destino_id: selectedPosition.id }
        : {}),
      sesion_destino_id: ownSession.id,
      version_destino_conocida: plan.version_plano,
      generado_dispositivo_at: new Date().toISOString(),
      ...(!form.existente && Object.keys(compactData).length ? { datos_folio: compactData } : {}),
    };

    const succeeded = await runOperation(
      () => executeWithWarnings(payload, (confirmedPayload) => api.locate(auth.token, confirmedPayload)),
      setModalError,
    );

    if (succeeded) {
      setLocateVisible(false);
      setNotice(locateCameraOnly
        ? `Folio ${payload.numero_folio} asignado a ${cameraDisplayName(plan)} sin posición.`
        : `Folio ${payload.numero_folio} guardado en ${positionLabel(selectedPosition!)}.`);
      setLocateCameraOnly(false);
      await refreshCurrent();
    }
  }

  async function openMove() {
    if (!plan || !operationalPosition?.folio || !ownSession || operationalPosition.id.startsWith('camera-only:')) return;
    setModalError('');
    setDestinationPlan(plan);
    setSelectedDestination(null);
    setMoveVisible(true);
  }

  async function chooseDestinationCamera(cameraId: string) {
    setBusy(true);
    setSelectedDestination(null);
    setModalError('');
    try {
      const loadedPlan = cameraId === plan?.id
        ? plan
        : await api.getPlan(auth.token, cameraId);
      setDestinationPlan(loadedPlan);
      setConnectionState('connected');
    } catch (reason) {
      reportFailure(reason, setModalError);
    } finally {
      setBusy(false);
    }
  }

  async function confirmMove() {
    if (!plan || !operationalPosition?.folio || !ownSession || !destinationPlan || !selectedDestination) return;
    const folio = operationalPosition.folio;
    const destinationLabel = `${cameraDisplayName(destinationPlan)} · ${positionLabel(selectedDestination)}`;
    setModalError('');

    const succeeded = await runOperation(async () => {
      let destinationSessionId: string;
      if (destinationPlan.id === plan.id) {
        destinationSessionId = ownSession.id;
      } else if (destinationPlan.acceso.modo === 'edicion' && destinationPlan.acceso.sesion?.es_propia) {
        destinationSessionId = destinationPlan.acceso.sesion.id;
      } else if (destinationPlan.acceso.modo === 'disponible') {
        destinationSessionId = (await api.openSession(auth.token, destinationPlan.id)).id;
      } else {
        throw new Error('La cámara de destino no está disponible para edición.');
      }

      const payload: MovePayload = {
        operacion_id: Crypto.randomUUID(),
        folio_id: folio.id,
        posicion_destino_id: selectedDestination.id,
        sesion_origen_id: ownSession.id,
        sesion_destino_id: destinationSessionId,
        version_origen_conocida: plan.version_plano,
        version_destino_conocida: destinationPlan.version_plano,
        generado_dispositivo_at: new Date().toISOString(),
      };
      await executeWithWarnings(payload, (confirmedPayload) => api.move(auth.token, confirmedPayload));
    }, setModalError);

    if (succeeded) {
      setMoveVisible(false);
      setDestinationPlan(null);
      setSelectedDestination(null);
      setNotice(`Folio ${folio.numero_folio} movido y guardado en ${destinationLabel}.`);
      await refreshCurrent();
    }
  }

  async function confirmMaterialDispatch(form: MaterialDispatchFormValue) {
    if (!operationalPosition?.folio?.material || !ownSession || !canWithdrawMaterial) return;
    setModalError('');
    const succeeded = await runOperation(async () => {
      await api.withdrawMaterial(auth.token, form.despacho_id, {
        operacion_id: materialWithdrawOperationId.current,
        ...(form.motivo_excepcion_fifo ? { motivo_excepcion_fifo: form.motivo_excepcion_fifo } : {}),
        retiros: [{
          folio_id: operationalPosition.folio!.id,
          cantidad: form.cantidad,
          sesion_estiba_id: ownSession.id,
        }],
      });
    }, setModalError);

    if (succeeded) {
      const folioNumber = operationalPosition.folio.numero_folio;
      setMaterialDispatchVisible(false);
      setNotice(`${form.cantidad} ${operationalPosition.folio.material.unidad_medida} despachadas desde ${folioNumber}.`);
      const [catalog, dispatches] = await Promise.all([
        optionalModule(
          () => api.getMaterialCatalog(auth.token),
          { temporada: null, clientes: [], items: [], destinos: [] },
        ),
        optionalModule(() => api.listMaterialDispatches(auth.token), []),
      ]);
      setMaterialCatalog(catalog);
      setMaterialDispatches(dispatches);
      await refreshCurrent();
    }
  }

  async function runOperation(
    operation: () => Promise<void>,
    errorTarget: (message: string) => void = setError,
  ): Promise<boolean> {
    setBusy(true);
    errorTarget('');
    setNotice('');
    try {
      await operation();
      setConnectionState('connected');
      return true;
    } catch (reason) {
      reportFailure(reason, errorTarget);
      return false;
    } finally {
      setBusy(false);
    }
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

  async function logout() {
    const openCameras = cameras
      .filter((camera) => camera.acceso.modo === 'edicion' && camera.acceso.sesion?.es_propia)
      .map(cameraDisplayName);

    if (openCameras.length > 0) {
      Alert.alert(
        'Estibas todavía abiertas',
        `Cierra las sesiones de ${openCameras.join(', ')} antes de finalizar el turno.`,
      );
      return;
    }

    setBusy(true);
    try {
      await api.logout(auth.token);
    } catch {
      // La sesión local se cierra aunque el servidor no responda.
    } finally {
      onLogout();
    }
  }

  function reportFailure(reason: unknown, target: (message: string) => void) {
    if (reason instanceof ApiError) {
      setConnectionState(reason.status === 0 ? 'offline' : 'connected');

      if (reason.status === 401) {
        Alert.alert('Sesión vencida', 'Vuelve a iniciar el turno para continuar.');
        onLogout();
        return;
      }
    }

    target(messageFrom(reason));
  }

  const cameraCards = cameras.map((camera) => (
    <CameraCard
      camera={camera}
      key={camera.id}
      onPress={() => void selectCamera(camera.id)}
      selected={camera.id === selectedCameraId}
    />
  ));
  const connectionLabel = api.mode === 'demo'
    ? 'Demo local'
    : connectionState === 'offline' ? 'API sin conexión' : 'API conectada';
  const connectionColor = api.mode === 'demo'
    ? colors.amber
    : connectionState === 'offline' ? colors.red : colors.green;

  return (
    <View style={styles.screen}>
      <ScrollView contentContainerStyle={styles.page}>
        <View style={styles.topbar}>
          <View style={styles.moduleNav}>
            <Pressable
              onPress={() => setActiveModule('camaras')}
              style={[styles.moduleButton, activeModule === 'camaras' && styles.moduleButtonActive]}
            >
              <Text style={[styles.moduleButtonText, activeModule === 'camaras' && styles.moduleButtonTextActive]}>Cámaras</Text>
            </Pressable>
            {canUseLoads && (
              <Pressable
                onPress={() => setActiveModule('cargas')}
                style={[styles.moduleButton, activeModule === 'cargas' && styles.moduleButtonActive]}
              >
                <Text style={[styles.moduleButtonText, activeModule === 'cargas' && styles.moduleButtonTextActive]}>Cargas</Text>
              </Pressable>
            )}
            {canUseMaterials && (
              <Pressable
                onPress={() => setActiveModule('materiales')}
                style={[styles.moduleButton, activeModule === 'materiales' && styles.moduleButtonActive]}
              >
                <Text style={[styles.moduleButtonText, activeModule === 'materiales' && styles.moduleButtonTextActive]}>Despachos</Text>
              </Pressable>
            )}
            {canUseTransformations && (
              <Pressable
                onPress={() => setActiveModule('transformacion')}
                style={[styles.moduleButton, activeModule === 'transformacion' && styles.moduleButtonActive]}
              >
                <Text style={[styles.moduleButtonText, activeModule === 'transformacion' && styles.moduleButtonTextActive]}>Transformación</Text>
              </Pressable>
            )}
          </View>
          <View style={styles.statuses}>
            <Status color={connectionColor} label={connectionLabel} />
            <Status color={canOperate ? colors.cyan : colors.muted} label={canOperate ? 'Editando ' + cameraDisplayName(plan) : 'Solo consulta'} />
            {(canUseLoads || canUseMaterials) && (
              <NotificationCenter
                api={api}
                auth={auth}
                onFailure={(reason) => reportFailure(reason, setError)}
                onOpenLoads={canUseLoads ? (loadId) => {
                  setPreferredLoadId(loadId ?? null);
                  setActiveModule('cargas');
                } : undefined}
                onOpenMaterialDispatches={canUseMaterials
                  ? () => setActiveModule('materiales')
                  : undefined}
                onSuccess={() => setConnectionState('connected')}
                onUnreadChanged={setUnreadNotifications}
              />
            )}
          </View>
          <Pressable accessibilityRole="button" accessibilityLabel="Cerrar sesión" onPress={() => void logout()} style={styles.logout}>
            <Text style={styles.logoutText}>Salir</Text>
          </Pressable>
        </View>

        {error ? (
          <Pressable onPress={() => setError('')} style={styles.errorBanner}>
            <Text style={styles.errorText}>{error}</Text>
            <Text style={styles.errorClose}>×</Text>
          </Pressable>
        ) : null}

        {notice ? (
          <Pressable onPress={() => setNotice('')} style={styles.noticeBanner}>
            <Text style={styles.noticeText}>{notice}</Text>
            <Text style={styles.noticeClose}>×</Text>
          </Pressable>
        ) : null}

        {api.mode === 'demo' && canUseLoads && unreadNotifications > 0 && activeModule !== 'cargas' ? (
          <Pressable
            onPress={() => setActiveModule('cargas')}
            style={styles.demoLoadBanner}
          >
            <View>
              <Text style={styles.demoLoadBannerTitle}>NUEVA ACTIVIDAD DE CARGAS</Text>
              <Text style={styles.demoLoadBannerText}>
                Tienes {unreadNotifications} aviso(s) local(es). Abre la bandeja para ver la orden y sus folios.
              </Text>
            </View>
            <Text style={styles.demoLoadBannerAction}>ABRIR CARGAS →</Text>
          </Pressable>
        ) : null}

        {activeModule === 'cargas' && canUseLoads ? (
          <RefrigeratedLoadOperation
            api={api}
            auth={auth}
            onConnectionFailure={(reason) => reportFailure(reason, setError)}
            onOpenPosition={(cameraId, positionId) => void openPositionFromLoad(cameraId, positionId)}
            onSessionsChanged={() => void refreshCurrent({ quiet: true })}
            preferredLoadId={preferredLoadId}
          />
        ) : activeModule === 'materiales' && canUseMaterials ? (
          <MaterialDispatchOperation
            api={api}
            auth={auth}
            onConnectionFailure={(reason) => reportFailure(reason, setError)}
            onOpenPosition={(cameraId, positionId, folioId) => (
              void openPositionFromMaterialDispatch(cameraId, positionId, folioId)
            )}
          />
        ) : activeModule === 'transformacion' && canUseTransformations ? (
          <MaterialTransformationOperation
            api={api}
            auth={auth}
            onConnectionFailure={(reason) => reportFailure(reason, setError)}
          />
        ) : (
          <>
          <View style={[styles.workspace, !wideLayout && styles.workspaceCompact]}>
          {wideLayout ? (
            <View style={styles.cameraPanel}>
              <Text style={styles.sectionEyebrow}>CÁMARAS</Text>
              <Text style={styles.sectionTitle}>Área de trabajo</Text>
              <View style={styles.cameraList}>{cameraCards}</View>
            </View>
          ) : (
            <ScrollView horizontal showsHorizontalScrollIndicator={false}>
              <View style={styles.cameraListHorizontal}>{cameraCards}</View>
            </ScrollView>
          )}

          {plan ? (
            <View style={[styles.operationArea, !wideLayout && styles.operationAreaCompact]}>
              <View style={styles.planColumn}>
              {plan.contenido === 'materiales' && plan.folios_sin_posicion.length > 0 ? (
                <View style={styles.cameraOnlyPanel}>
                  <Text style={styles.sectionEyebrow}>EN CÁMARA · SIN POSICIÓN</Text>
                  <ScrollView horizontal showsHorizontalScrollIndicator>
                    <View style={styles.cameraOnlyList}>
                      {plan.folios_sin_posicion.map((folio) => (
                        <Pressable
                          key={folio.id}
                          onPress={() => {
                            setSelectedPositionId(null);
                            setSelectedFolioId(folio.id);
                            setSelectedCameraOnlyFolioId(folio.id);
                          }}
                          style={[
                            styles.cameraOnlyItem,
                            selectedCameraOnlyFolioId === folio.id && styles.cameraOnlyItemActive,
                          ]}
                        >
                          <Text style={styles.cameraOnlyCode}>{folio.numero_folio}</Text>
                          <Text style={styles.cameraOnlyMeta}>
                            {folio.material?.item.codigo ?? 'Material'} · {folio.material?.cantidad_actual ?? '0'} {folio.material?.unidad_medida ?? ''}
                          </Text>
                        </Pressable>
                      ))}
                    </View>
                  </ScrollView>
                </View>
              ) : null}
              <PositionMap
                onSelectPosition={(position) => {
                  setSelectedCameraOnlyFolioId(null);
                  setSelectedPositionId(position.id);
                  setSelectedFolioId(position.folio?.id ?? null);
                }}
                plan={plan}
                selectedPositionId={selectedPositionId}
              />
              </View>
              <ActionPanel
                busy={busy}
                canDispatchMaterial={canWithdrawMaterial}
                canOpenSession={canOpenSession}
                canOperate={canOperate}
                compact={!wideLayout}
                onLocate={() => {
                  setModalError('');
                  setNotice('');
                  setLocateCameraOnly(false);
                  setLocateVisible(true);
                }}
                onAssignCamera={() => {
                  setModalError('');
                  setNotice('');
                  setLocateCameraOnly(true);
                  setLocateVisible(true);
                }}
                onMove={() => void openMove()}
                onSelectFolio={setSelectedFolioId}
                onDispatchMaterial={() => void openMaterialDispatch()}
                onRefresh={() => void refreshCurrent()}
                onToggleSession={toggleSession}
                plan={plan}
                selectedPosition={operationalPosition}
              />
            </View>
          ) : (
            <View style={styles.emptyPlan}>
              <Text style={styles.emptyIcon}>▦</Text>
              <Text style={styles.emptyTitle}>Sin cámaras disponibles</Text>
            </View>
          )}
          </View>

          <RecentMovements lastSync={lastSync} movements={movements} />
          </>
        )}
      </ScrollView>

      {busy && (
        <View pointerEvents="none" style={styles.busyOverlay}>
          <ActivityIndicator color={colors.cyan} size="large" />
          <Text style={styles.busyText}>Sincronizando…</Text>
        </View>
      )}

      <LocateModal
        busy={busy}
        cameraOnly={locateCameraOnly}
        conditions={conditions}
        materialItems={materialCatalog.items}
        error={modalError}
        onCancel={() => {
          setModalError('');
          setLocateVisible(false);
          setLocateCameraOnly(false);
        }}
        onConfirm={confirmLocate}
        onLookup={(folioNumber) => api.lookupFolio(auth.token, folioNumber, plan?.id)}
        onSearchMaterialFolios={(prefix) => api.searchAvailableMaterialFolios(auth.token, prefix, plan!.id, locateCameraOnly)}
        plan={plan}
        position={selectedPosition}
        visible={locateVisible}
      />
      <MoveModal
        busy={busy}
        cameras={cameras.filter((camera) => camera.contenido === plan?.contenido)}
        destinationPlan={destinationPlan}
        error={modalError}
        onCancel={() => {
          setModalError('');
          setMoveVisible(false);
        }}
        onChooseCamera={(cameraId) => void chooseDestinationCamera(cameraId)}
        onConfirm={confirmMove}
        onSelectPosition={setSelectedDestination}
        originPlan={plan}
        originPosition={operationalPosition}
        selectedDestination={selectedDestination}
        visible={moveVisible}
      />
      <MaterialDispatchModal
        baseUrl={api.baseUrl}
        token={auth.token}
        busy={busy}
        dispatches={materialDispatches}
        error={modalError}
        onCancel={() => {
          setModalError('');
          setMaterialDispatchVisible(false);
        }}
        onConfirm={confirmMaterialDispatch}
        operatorId={String(auth.usuario.id)}
        position={operationalPosition}
        visible={materialDispatchVisible}
      />
    </View>
  );
}

async function optionalModule<T>(operation: () => Promise<T>, fallback: T): Promise<T> {
  try {
    return await operation();
  } catch (reason) {
    if (reason instanceof ApiError && reason.status === 403) return fallback;
    throw reason;
  }
}

type MovementWarning = {
  codigo: string;
  titulo: string;
  mensaje: string;
};

function warningResponse(reason: unknown): MovementWarning[] {
  if (!(reason instanceof ApiError) || !reason.data || typeof reason.data !== 'object') return [];
  const data = reason.data as { codigo?: string; advertencias?: MovementWarning[] };
  return data.codigo === 'confirmacion_requerida' && Array.isArray(data.advertencias)
    ? data.advertencias
    : [];
}

function Status({ color, label }: { color: string; label: string }) {
  return (
    <View style={styles.status}>
      <View style={[styles.statusDot, { backgroundColor: color }]} />
      <Text style={styles.statusText}>{label}</Text>
    </View>
  );
}

function positionLabel(position: Position) {
  return position.etiqueta
    ?? `B${String(position.banda).padStart(2, '0')}-P${String(position.posicion).padStart(2, '0')}-N${position.nivel}`;
}

function messageFrom(reason: unknown) {
  return reason instanceof Error ? reason.message : 'La operación no pudo completarse.';
}

const styles = StyleSheet.create({
  screen: { flex: 1, backgroundColor: colors.background },
  page: { flexGrow: 1, padding: 12, backgroundColor: colors.background },
  topbar: {
    marginBottom: 12,
    paddingVertical: 4,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    gap: 12,
    flexWrap: 'wrap',
  },
  statuses: { flexDirection: 'row', alignItems: 'center', gap: 9 },
  moduleNav: { padding: 3, borderRadius: 10, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.panel, flexDirection: 'row', gap: 3 },
  moduleButton: { paddingHorizontal: 12, paddingVertical: 7, borderRadius: 7 },
  moduleButtonActive: { backgroundColor: colors.cyan },
  moduleButtonText: { color: colors.muted, fontSize: 12, fontWeight: '900' },
  moduleButtonTextActive: { color: colors.accentText },
  status: {
    paddingHorizontal: 10,
    paddingVertical: 7,
    borderRadius: 8,
    borderWidth: 1,
    borderColor: colors.border,
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
  },
  statusDot: { width: 7, height: 7, borderRadius: 4 },
  statusText: { color: colors.text, fontSize: 12, fontWeight: '800' },
  logout: { minHeight: 44, justifyContent: 'center', paddingHorizontal: 14, paddingVertical: 7, borderRadius: 8, borderWidth: 1, borderColor: colors.border },
  logoutText: { color: colors.text, fontSize: 12, fontWeight: '800' },
  errorBanner: {
    marginBottom: 10,
    padding: 10,
    borderRadius: 10,
    borderWidth: 1,
    borderColor: colors.red,
    backgroundColor: '#421B21',
    flexDirection: 'row',
    justifyContent: 'space-between',
    gap: 10,
  },
  errorText: { flex: 1, color: '#FFB7B7', fontSize: 13 },
  errorClose: { color: colors.text, fontSize: 16 },
  noticeBanner: {
    marginBottom: 10,
    padding: 10,
    borderRadius: 10,
    borderWidth: 1,
    borderColor: colors.green,
    backgroundColor: colors.greenDark,
    flexDirection: 'row',
    justifyContent: 'space-between',
    gap: 10,
  },
  noticeText: { flex: 1, color: colors.text, fontSize: 13, fontWeight: '800' },
  noticeClose: { color: colors.text, fontSize: 16 },
  demoLoadBanner: {
    marginBottom: 10,
    paddingHorizontal: 14,
    paddingVertical: 11,
    borderRadius: 11,
    borderWidth: 1,
    borderColor: colors.amber,
    backgroundColor: colors.amberDark,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    gap: 14,
  },
  demoLoadBannerTitle: { color: colors.amber, fontSize: 12, fontWeight: '900', letterSpacing: 1 },
  demoLoadBannerText: { marginTop: 3, color: colors.text, fontSize: 13 },
  demoLoadBannerAction: { color: colors.amber, fontSize: 13, fontWeight: '900' },
  workspace: { flexDirection: 'row', alignItems: 'flex-start', gap: 12 },
  workspaceCompact: { flexDirection: 'column' },
  cameraPanel: {
    width: 268,
    padding: 14,
    borderRadius: 18,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.panel,
  },
  sectionEyebrow: { color: colors.cyan, fontSize: 12, fontWeight: '900', letterSpacing: 1.2 },
  sectionTitle: { marginTop: 3, color: colors.text, fontSize: 17, fontWeight: '900' },
  cameraList: { marginTop: 12, gap: 9 },
  cameraListHorizontal: { paddingBottom: 2, flexDirection: 'row', gap: 10 },
  operationArea: { flex: 1, minWidth: 0, flexDirection: 'row', alignItems: 'flex-start', gap: 12 },
  planColumn: { flex: 1, minWidth: 0, gap: 10 },
  cameraOnlyPanel: {
    padding: 10,
    borderRadius: 12,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.panel,
  },
  cameraOnlyList: { flexDirection: 'row', gap: 8, marginTop: 7 },
  cameraOnlyItem: {
    minWidth: 160,
    padding: 9,
    borderRadius: 9,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.backgroundDeep,
  },
  cameraOnlyItemActive: { borderColor: colors.cyan, backgroundColor: colors.selected },
  cameraOnlyCode: { color: colors.text, fontSize: 13, fontWeight: '900' },
  cameraOnlyMeta: { marginTop: 3, color: colors.muted, fontSize: 12 },
  operationAreaCompact: { width: '100%', flexDirection: 'column' },
  emptyPlan: {
    flex: 1,
    minHeight: 350,
    borderRadius: 18,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.panel,
    alignItems: 'center',
    justifyContent: 'center',
  },
  emptyIcon: { color: colors.cyan, fontSize: 40 },
  emptyTitle: { marginTop: 8, color: colors.text, fontSize: 15, fontWeight: '900' },
  busyOverlay: {
    ...StyleSheet.absoluteFill,
    backgroundColor: 'rgba(5,8,11,0.76)',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 10,
  },
  busyText: { color: colors.text, fontSize: 14, fontWeight: '900' },
});
