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
  useWindowDimensions,
  View,
} from 'react-native';

import { OPERATIONAL_POLL_INTERVAL_MS } from '../config/polling';
import { cameraDisplayName } from '../domain/cameras';
import { useOperationalPolling } from '../hooks/useOperationalPolling';
import {
  AuthSession,
  CameraPlan,
  CameraReentry,
  Dock,
  ExtractionPlan,
  ExtractionRouteItem,
  LoadFolio,
  Position,
  RefrigeratedLoad,
  ReportLoadIncidentPayload,
  SendLoadFolioToDockPayload,
} from '../domain/estiba';
import { ApiError } from '../services/apiError';
import { EstibaApi } from '../services/estibaApi';
import { colors } from '../theme/colors';
import { PositionMap } from './PositionMap';
import { CameraReentryPanel } from './CameraReentryPanel';
import { TruckLoadingPanel } from './TruckLoadingPanel';
import { font } from '../theme/typography';

type QueueFilter = 'todas' | 'urgentes' | 'incidencias' | 'preparacion';

type Props = {
  api: EstibaApi;
  auth: AuthSession;
  onOpenPosition: (cameraId: string, positionId: string) => void;
  onConnectionFailure: (reason: unknown) => void;
  onSessionsChanged: () => void;
  preferredLoadId?: string | null;
};

const INCIDENT_TYPES: Array<{ value: ReportLoadIncidentPayload['tipo']; label: string }> = [
  { value: 'caja_aplastada', label: 'Caja aplastada' },
  { value: 'zuncho_roto', label: 'Zuncho roto' },
  { value: 'pallet_mojado', label: 'Pallet mojado' },
  { value: 'pallet_inestable', label: 'Pallet inestable' },
  { value: 'folio_ilegible', label: 'Folio ilegible' },
  { value: 'folio_no_encontrado', label: 'Folio no encontrado' },
  { value: 'diferencia_ubicacion', label: 'Ubicación incorrecta' },
  { value: 'sector_inaccesible', label: 'Sector inaccesible' },
  { value: 'retencion_calidad', label: 'Retención de calidad' },
  { value: 'otro', label: 'Otro' },
];

export function RefrigeratedLoadOperation({
  api,
  auth,
  onConnectionFailure,
  onOpenPosition,
  onSessionsChanged,
  preferredLoadId = null,
}: Props) {
  const { width } = useWindowDimensions();
  const compact = width < 1080;
  const [loads, setLoads] = useState<RefrigeratedLoad[]>([]);
  const [docks, setDocks] = useState<Dock[]>([]);
  const [reentries, setReentries] = useState<CameraReentry[]>([]);
  const [filter, setFilter] = useState<QueueFilter>('todas');
  const [selectedLoadId, setSelectedLoadId] = useState<string | null>(null);
  const [extractionPlan, setExtractionPlan] = useState<ExtractionPlan | null>(null);
  const [cameraPlan, setCameraPlan] = useState<CameraPlan | null>(null);
  const [selectedRouteId, setSelectedRouteId] = useState<string | null>(null);
  const [selectedDockId, setSelectedDockId] = useState<string | null>(null);
  const [incidentVisible, setIncidentVisible] = useState(false);
  const [incidentType, setIncidentType] = useState<ReportLoadIncidentPayload['tipo']>('caja_aplastada');
  const [incidentDescription, setIncidentDescription] = useState('');
  const [busy, setBusy] = useState(true);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const pollInFlight = useRef(false);

  const selectedLoad = useMemo(
    () => loads.find((load) => load.id === selectedLoadId) ?? null,
    [loads, selectedLoadId],
  );
  const selectedRoute = useMemo(
    () => extractionPlan?.items.find((item) => item.asignacion_id === selectedRouteId)
      ?? extractionPlan?.siguiente
      ?? null,
    [extractionPlan, selectedRouteId],
  );
  const filteredLoads = loads.filter((load) => {
    if (filter === 'urgentes') return load.prioridad === 'urgente';
    if (filter === 'incidencias') return load.incidencias_abiertas > 0;
    if (filter === 'preparacion') return load.estado !== 'pendiente';
    return true;
  });
  const loadFolioIds = selectedLoad?.folios
    .filter((folio) => folio.estado_carga !== 'en_anden' && folio.estado_carga !== 'cargado')
    .map((folio) => folio.id) ?? [];
  const actionableItems = extractionPlan?.items
    .filter((item) => item.orden !== null && item.ubicacion !== null)
    .sort((left, right) => (left.orden ?? 999) - (right.orden ?? 999)) ?? [];

  useEffect(() => {
    void initialize();
  }, []);

  useEffect(() => {
    if (!preferredLoadId || busy || preferredLoadId === selectedLoadId) return;
    const preferred = loads.find((load) => load.id === preferredLoadId);
    if (preferred) void selectLoad(preferred);
  }, [preferredLoadId, loads, busy, selectedLoadId]);

  useOperationalPolling(
    pollLoads,
    {
      intervalMs: OPERATIONAL_POLL_INTERVAL_MS,
      enabled: !busy && !incidentVisible,
    },
  );

  async function pollLoads() {
    if (pollInFlight.current) return;
    pollInFlight.current = true;
    try {
      void refreshReentries();
      const refreshedLoads = await api.refreshRefrigeratedLoads(auth.token);
      const nextLoads = refreshedLoads ?? loads;
      if (refreshedLoads) setLoads(refreshedLoads);
      const nextLoadId = nextLoads.some((load) => load.id === selectedLoadId)
        ? selectedLoadId
        : nextLoads[0]?.id ?? null;
      setSelectedLoadId(nextLoadId);

      if (!nextLoadId) {
        setExtractionPlan(null);
        setCameraPlan(null);
        return;
      }

      let refreshedRoute = await api.refreshExtractionPlan(auth.token, nextLoadId);
      const currentRoute = extractionPlan?.carga_id === nextLoadId ? extractionPlan : null;
      if (!refreshedRoute && !currentRoute) {
        refreshedRoute = await api.getExtractionPlan(auth.token, nextLoadId);
      }
      const route = refreshedRoute ?? currentRoute;
      if (!route) return;
      if (refreshedRoute) setExtractionPlan(refreshedRoute);
      const selected = route.items.find((item) => item.asignacion_id === selectedRouteId)
        ?? route.siguiente
        ?? route.items.find((item) => item.ubicacion !== null)
        ?? null;
      setSelectedRouteId(selected?.asignacion_id ?? null);
      if (selected?.ubicacion) {
        const cameraId = selected.ubicacion.camara.id;
        const loadedPlan = cameraPlan?.id === cameraId
          ? await api.refreshPlan(auth.token, cameraId)
          : await api.getPlan(auth.token, cameraId);
        if (loadedPlan) setCameraPlan(loadedPlan);
      } else {
        setCameraPlan(null);
      }
    } catch (reason) {
      onConnectionFailure(reason);
    } finally {
      pollInFlight.current = false;
    }
  }

  async function refreshReentries() {
    try {
      setReentries(await api.listCameraReentries(auth.token));
    } catch {
      // La bandeja de reingreso es informativa: un fallo no detiene el despacho.
    }
  }

  async function registerReentryTemperature(reentry: CameraReentry, temperature: number): Promise<boolean> {
    setBusy(true);
    setError('');
    try {
      await api.registerReentryTemperature(auth.token, reentry.id, temperature);
      await refreshReentries();
      setNotice(`Temperatura de ${reentry.folio.numero_folio} registrada: ${temperature.toFixed(1)} °C.`);
      return true;
    } catch (reason) {
      fail(reason);
      return false;
    } finally {
      setBusy(false);
    }
  }

  async function initialize() {
    setBusy(true);
    setError('');
    try {
      const [loadedLoads, loadedDocks] = await Promise.all([
        api.listRefrigeratedLoads(auth.token),
        api.listDocks(auth.token),
      ]);
      setLoads(loadedLoads);
      setDocks(loadedDocks);
      void refreshReentries();
      const initial = loadedLoads.find((load) => load.id === preferredLoadId) ?? loadedLoads[0] ?? null;
      setSelectedLoadId(initial?.id ?? null);
      setSelectedDockId(initial?.anden_previsto?.id ?? loadedDocks[0]?.id ?? null);
      if (initial) await loadDetail(initial.id);
    } catch (reason) {
      fail(reason);
    } finally {
      setBusy(false);
    }
  }

  async function refresh(preferredLoadId = selectedLoadId, message?: string) {
    setBusy(true);
    setError('');
    try {
      const loadedLoads = await api.listRefrigeratedLoads(auth.token);
      setLoads(loadedLoads);
      const nextId = loadedLoads.some((load) => load.id === preferredLoadId)
        ? preferredLoadId
        : loadedLoads[0]?.id ?? null;
      setSelectedLoadId(nextId);
      if (nextId) {
        const load = loadedLoads.find((candidate) => candidate.id === nextId)!;
        setSelectedDockId((current) => current ?? load.anden_previsto?.id ?? docks[0]?.id ?? null);
        await loadDetail(nextId);
      } else {
        setExtractionPlan(null);
        setCameraPlan(null);
      }
      if (message) setNotice(message);
    } catch (reason) {
      fail(reason);
    } finally {
      setBusy(false);
    }
  }

  async function selectLoad(load: RefrigeratedLoad) {
    setBusy(true);
    setError('');
    setNotice('');
    setSelectedLoadId(load.id);
    setSelectedDockId(load.anden_previsto?.id ?? docks[0]?.id ?? null);
    try {
      await loadDetail(load.id);
    } catch (reason) {
      fail(reason);
    } finally {
      setBusy(false);
    }
  }

  async function loadDetail(loadId: string) {
    const route = await api.getExtractionPlan(auth.token, loadId);
    setExtractionPlan(route);
    const preferred = route.siguiente
      ?? route.items.find((item) => item.ubicacion !== null)
      ?? null;
    setSelectedRouteId(preferred?.asignacion_id ?? null);
    if (preferred?.ubicacion) {
      setCameraPlan(await api.getPlan(auth.token, preferred.ubicacion.camara.id));
    } else {
      setCameraPlan(null);
    }
  }

  async function selectRoute(item: ExtractionRouteItem) {
    setSelectedRouteId(item.asignacion_id);
    if (!item.ubicacion || item.ubicacion.camara.id === cameraPlan?.id) return;
    setBusy(true);
    try {
      setCameraPlan(await api.getPlan(auth.token, item.ubicacion.camara.id));
    } catch (reason) {
      fail(reason);
    } finally {
      setBusy(false);
    }
  }

  function selectMapPosition(position: Position) {
    const item = extractionPlan?.items.find(
      (candidate) => candidate.ubicacion?.posicion.id === position.id,
    );
    if (item) setSelectedRouteId(item.asignacion_id);
  }

  async function ensureSession(item: ExtractionRouteItem) {
    if (!item.ubicacion) throw new Error('El folio no posee una ubicación operable.');
    let plan = await api.getPlan(auth.token, item.ubicacion.camara.id);
    if (plan.acceso.modo === 'edicion' && plan.acceso.sesion?.es_propia) {
      return { plan, sessionId: plan.acceso.sesion.id };
    }
    if (plan.acceso.modo !== 'disponible') {
      throw new Error(`${cameraDisplayName(plan)} está siendo modificada por otro camarero.`);
    }
    const session = await api.openSession(auth.token, plan.id);
    onSessionsChanged();
    plan = await api.getPlan(auth.token, plan.id);
    return { plan, sessionId: session.id };
  }

  function openIncident() {
    if (!selectedRoute || selectedRoute.estado_ruta === 'incidencia') return;
    setIncidentType('caja_aplastada');
    setIncidentDescription('');
    setError('');
    setIncidentVisible(true);
  }

  async function reportIncident() {
    if (!selectedRoute) return;
    setBusy(true);
    setError('');
    try {
      const { sessionId } = await ensureSession(selectedRoute);
      await api.reportLoadIncident(auth.token, selectedRoute.asignacion_id, {
        operacion_id: Crypto.randomUUID(),
        tipo: incidentType,
        descripcion: incidentDescription.trim() || undefined,
        sesion_estiba_id: sessionId,
      });
      setIncidentVisible(false);
      await refresh(selectedLoadId, `Incidencia registrada en ${selectedRoute.folio.numero_folio}. La ruta fue recalculada.`);
    } catch (reason) {
      fail(reason);
    } finally {
      setBusy(false);
    }
  }

  async function sendOne(item: ExtractionRouteItem): Promise<void> {
    if (!selectedDockId) throw new Error('Selecciona un andén de destino.');
    const { plan, sessionId } = await ensureSession(item);
    const payload: SendLoadFolioToDockPayload = {
      operacion_id: Crypto.randomUUID(),
      anden_id: selectedDockId,
      sesion_estiba_id: sessionId,
      version_camara_conocida: plan.version_plano,
      generado_dispositivo_at: new Date().toISOString(),
    };
    await executeWithWarnings(payload, (confirmed) => api.sendLoadFolioToDock(
      auth.token,
      item.asignacion_id,
      confirmed,
    ));
  }

  async function sendSelected() {
    if (!selectedRoute || selectedRoute.orden === null) return;
    const accepted = await confirmConcentration(1);
    if (!accepted) return;
    setBusy(true);
    setError('');
    try {
      await sendOne(selectedRoute);
      await refresh(selectedLoadId, `${selectedRoute.folio.numero_folio} fue enviado al andén.`);
    } catch (reason) {
      fail(reason);
    } finally {
      setBusy(false);
    }
  }

  async function sendAll() {
    if (!actionableItems.length) return;
    const accepted = await confirmConcentration(actionableItems.length);
    if (!accepted) return;
    setBusy(true);
    setError('');
    let completed = 0;
    try {
      for (const item of actionableItems) {
        await sendOne(item);
        completed++;
      }
      await refresh(selectedLoadId, `${completed} folios fueron enviados al andén en orden de extracción.`);
    } catch (reason) {
      const detail = reason instanceof Error ? reason.message : 'No fue posible continuar.';
      if (completed > 0) await refresh(selectedLoadId);
      setError(`Envío detenido después de ${completed} folios. ${detail}`);
    } finally {
      setBusy(false);
    }
  }

  async function registerLoaded(folioNumber: string): Promise<boolean> {
    if (!selectedLoad) return false;
    setBusy(true);
    setError('');
    try {
      await api.registerLoadedFolio(auth.token, selectedLoad.id, {
        operacion_id: Crypto.randomUUID(),
        numero_folio: folioNumber,
        generado_dispositivo_at: new Date().toISOString(),
      });
      await refresh(selectedLoad.id, `${folioNumber} quedó registrado arriba del camión.`);
      return true;
    } catch (reason) {
      fail(reason);
      return false;
    } finally {
      setBusy(false);
    }
  }

  async function undoLoaded(folio: LoadFolio, reason: string): Promise<boolean> {
    if (!selectedLoad) return false;
    setBusy(true);
    setError('');
    try {
      await api.undoLoadedFolio(auth.token, folio.asignacion_id, {
        operacion_id: Crypto.randomUUID(),
        motivo: reason,
      });
      await refresh(selectedLoad.id, `${folio.numero_folio} volvió al andén.`);
      return true;
    } catch (failure) {
      fail(failure);
      return false;
    } finally {
      setBusy(false);
    }
  }

  function confirmConcentration(quantity: number): Promise<boolean> {
    if (!selectedLoad || selectedLoad.progreso.cumple_umbral) return Promise.resolve(true);
    return confirmAlert(
      'Carga bajo el 80% de concentración',
      `${selectedLoad.codigo} tiene ${selectedLoad.progreso.porcentaje}% de concentración. ¿Enviar ${quantity === 1 ? 'este folio' : `${quantity} folios`} igualmente?`,
      'Enviar igualmente',
    );
  }

  async function executeWithWarnings<T extends { advertencias_confirmadas?: string[] }>(
    payload: T,
    operation: (confirmed: T) => Promise<unknown>,
  ) {
    try {
      await operation(payload);
    } catch (reason) {
      const warnings = physicalWarnings(reason);
      if (!warnings.length) throw reason;
      const accepted = await confirmAlert(
        'Confirmar excepción física',
        warnings.map((warning) => `${warning.titulo}: ${warning.mensaje}`).join('\n\n'),
        'Continuar',
      );
      if (!accepted) throw new Error('Operación cancelada por el camarero.');
      await operation({
        ...payload,
        advertencias_confirmadas: warnings.map((warning) => warning.codigo),
      });
    }
  }

  function fail(reason: unknown) {
    setError(reason instanceof Error ? reason.message : 'No fue posible completar la operación.');
    onConnectionFailure(reason);
  }

  return (
    <View style={styles.root}>
      <View style={styles.moduleHeader}>
        <View>
          <Text style={styles.eyebrow}>DESPACHO FRIGORÍFICO</Text>
          <Text style={styles.moduleTitle}>Cola compartida de cargas</Text>
          <Text style={styles.moduleSubtitle}>Ruta sugerida desde la entrada hacia el fondo · actualización manual</Text>
        </View>
        <Pressable onPress={() => void refresh()} style={styles.secondaryButton}>
          <Text style={styles.secondaryButtonText}>Actualizar cola</Text>
        </Pressable>
      </View>

      {error ? <Text style={styles.error}>{error}</Text> : null}
      {notice ? <Text style={styles.notice}>{notice}</Text> : null}

      <CameraReentryPanel
        disabled={busy}
        onRegisterTemperature={registerReentryTemperature}
        reentries={reentries}
      />

      <View style={styles.filters}>
        {(['todas', 'urgentes', 'preparacion', 'incidencias'] as QueueFilter[]).map((value) => (
          <Pressable
            key={value}
            onPress={() => setFilter(value)}
            style={[styles.filter, filter === value && styles.filterActive]}
          >
            <Text style={[styles.filterText, filter === value && styles.filterTextActive]}>
              {filterLabel(value)}
            </Text>
          </Pressable>
        ))}
      </View>

      <View style={[styles.content, compact && styles.contentCompact]}>
        <View style={[styles.queue, compact && styles.queueCompact]}>
          <ScrollView horizontal={compact} nestedScrollEnabled showsHorizontalScrollIndicator={false}>
            <View style={[styles.queueList, compact && styles.queueListCompact]}>
              {filteredLoads.map((load) => (
                <LoadCard
                  key={load.id}
                  load={load}
                  onPress={() => void selectLoad(load)}
                  selected={load.id === selectedLoadId}
                />
              ))}
              {!filteredLoads.length && (
                <View style={styles.emptyQueue}>
                  <Text style={styles.emptyTitle}>Sin cargas en esta bandeja</Text>
                  <Text style={styles.emptyText}>Publica una carga desde oficina o cambia el filtro.</Text>
                </View>
              )}
            </View>
          </ScrollView>
        </View>

        {selectedLoad && extractionPlan ? (
          <View style={styles.detail}>
            <LoadHeader load={selectedLoad} />
            <TruckLoadingPanel
              disabled={busy}
              load={selectedLoad}
              onRegister={registerLoaded}
              onUndo={undoLoaded}
            />
            <View style={[styles.detailBody, compact && styles.detailBodyCompact]}>
              <View style={styles.routePanel}>
                <Text style={styles.panelEyebrow}>SECUENCIA SUGERIDA</Text>
                <Text style={styles.panelTitle}>Ruta de extracción</Text>
                <Text style={styles.routeHint}>Los folios con incidencia no detienen otras bandas. Los bloqueados muestran qué hay delante.</Text>
                <ScrollView style={styles.routeScroll} nestedScrollEnabled>
                  <View style={styles.routeList}>
                    {extractionPlan.items.map((item) => (
                      <RouteCard
                        item={item}
                        key={item.asignacion_id}
                        onPress={() => void selectRoute(item)}
                        selected={item.asignacion_id === selectedRoute?.asignacion_id}
                      />
                    ))}
                  </View>
                </ScrollView>
              </View>

              <View style={styles.mapArea}>
                {cameraPlan ? (
                  <PositionMap
                    highlightedFolioIds={loadFolioIds}
                    onSelectPosition={selectMapPosition}
                    plan={cameraPlan}
                    selectedPositionId={selectedRoute?.ubicacion?.posicion.id ?? null}
                    suggestedFolioId={extractionPlan.siguiente?.folio.id ?? null}
                  />
                ) : (
                  <View style={styles.emptyMap}>
                    <Text style={styles.emptyTitle}>Sin folios ubicados pendientes</Text>
                  </View>
                )}
              </View>

              <View style={styles.actions}>
                <Text style={styles.panelEyebrow}>OPERACIÓN</Text>
                <Text style={styles.panelTitle}>Folio seleccionado</Text>
                {selectedRoute ? (
                  <>
                    <View style={styles.selectedFolio}>
                      <Text style={styles.selectedFolioNumber}>{selectedRoute.folio.numero_folio}</Text>
                      <Text style={styles.selectedFolioLocation}>
                        {selectedRoute.ubicacion
                          ? `${cameraDisplayName(selectedRoute.ubicacion.camara)} · ${selectedRoute.ubicacion.posicion.etiqueta}`
                          : 'Sin ubicación'}
                      </Text>
                    </View>
                    {selectedRoute.ubicacion && (
                      <Pressable
                        onPress={() => onOpenPosition(
                          selectedRoute.ubicacion!.camara.id,
                          selectedRoute.ubicacion!.posicion.id,
                        )}
                        style={styles.secondaryButton}
                      >
                        <Text style={styles.secondaryButtonText}>Abrir en plano de cámara</Text>
                      </Pressable>
                    )}
                    <Pressable
                      disabled={selectedRoute.estado_ruta === 'incidencia' || !selectedRoute.ubicacion}
                      onPress={openIncident}
                      style={[styles.incidentButton, (selectedRoute.estado_ruta === 'incidencia' || !selectedRoute.ubicacion) && styles.disabled]}
                    >
                      <Text style={styles.incidentButtonText}>Reportar incidencia</Text>
                    </Pressable>
                  </>
                ) : null}

                <Text style={styles.fieldLabel}>Andén de destino</Text>
                <View style={styles.dockList}>
                  {docks.map((dock) => (
                    <Pressable
                      key={dock.id}
                      onPress={() => setSelectedDockId(dock.id)}
                      style={[styles.dock, selectedDockId === dock.id && styles.dockActive]}
                    >
                      <Text style={[styles.dockText, selectedDockId === dock.id && styles.dockTextActive]}>{dock.codigo}</Text>
                    </Pressable>
                  ))}
                </View>

                <Pressable
                  disabled={!selectedRoute || selectedRoute.orden === null || !selectedDockId}
                  onPress={() => void sendSelected()}
                  style={[styles.primaryButton, (!selectedRoute || selectedRoute.orden === null || !selectedDockId) && styles.disabled]}
                >
                  <Text style={styles.primaryButtonText}>Enviar folio al andén</Text>
                </Pressable>
                <Pressable
                  disabled={!actionableItems.length || !selectedDockId}
                  onPress={() => void sendAll()}
                  style={[styles.bulkButton, (!actionableItems.length || !selectedDockId) && styles.disabled]}
                >
                  <Text style={styles.bulkButtonText}>Enviar ruta completa ({actionableItems.length})</Text>
                </Pressable>
                <Text style={styles.operationNote}>El envío múltiple confirma cada folio por separado y se detiene ante un conflicto.</Text>
              </View>
            </View>
          </View>
        ) : (
          <View style={styles.emptyDetail}>
            <Text style={styles.emptyTitle}>Selecciona una carga</Text>
            <Text style={styles.emptyText}>Aquí aparecerán su secuencia, plano vertical y acciones.</Text>
          </View>
        )}
      </View>

      {busy && (
        <View pointerEvents="none" style={styles.busy}>
          <ActivityIndicator color={colors.cyan} size="large" />
          <Text style={styles.busyText}>Sincronizando carga…</Text>
        </View>
      )}

      <Modal animationType="fade" onRequestClose={() => setIncidentVisible(false)} transparent visible={incidentVisible}>
        <View style={styles.modalBackdrop}>
          <View style={styles.modalCard}>
            <Text style={styles.eyebrow}>INCIDENCIA FÍSICA</Text>
            <Text style={styles.modalTitle}>Reportar {selectedRoute?.folio.numero_folio}</Text>
            <Text style={styles.modalText}>El folio saldrá temporalmente de la ruta y oficina recibirá la incidencia.</Text>
            <View style={styles.incidentTypes}>
              {INCIDENT_TYPES.map((type) => (
                <Pressable
                  key={type.value}
                  onPress={() => setIncidentType(type.value)}
                  style={[styles.incidentType, incidentType === type.value && styles.incidentTypeActive]}
                >
                  <Text style={[styles.incidentTypeText, incidentType === type.value && styles.incidentTypeTextActive]}>{type.label}</Text>
                </Pressable>
              ))}
            </View>
            <TextInput
              multiline
              onChangeText={setIncidentDescription}
              placeholder="Detalle opcional para oficina"
              placeholderTextColor={colors.muted}
              style={styles.description}
              value={incidentDescription}
            />
            <View style={styles.modalActions}>
              <Pressable onPress={() => setIncidentVisible(false)} style={styles.secondaryButton}>
                <Text style={styles.secondaryButtonText}>Cancelar</Text>
              </Pressable>
              <Pressable onPress={() => void reportIncident()} style={styles.incidentButton}>
                <Text style={styles.incidentButtonText}>Confirmar incidencia</Text>
              </Pressable>
            </View>
          </View>
        </View>
      </Modal>
    </View>
  );
}

function LoadCard({ load, onPress, selected }: { load: RefrigeratedLoad; onPress: () => void; selected: boolean }) {
  return (
    <Pressable onPress={onPress} style={[styles.loadCard, selected && styles.loadCardSelected]}>
      <View style={styles.loadTop}>
        <Text style={styles.loadCode}>{load.codigo}</Text>
        <Text style={[styles.priority, load.prioridad === 'urgente' && styles.priorityUrgent]}>{load.prioridad.toUpperCase()}</Text>
      </View>
      <Text style={styles.loadOrder}>{load.numero_orden_externa ?? 'Sin orden externa'}</Text>
      <View style={styles.progressTrack}>
        <View style={[styles.progressFill, { width: `${Math.min(100, load.progreso.porcentaje)}%` }]} />
      </View>
      <View style={styles.loadBottom}>
        <Text style={styles.loadMeta}>{load.progreso.porcentaje}% concentrada</Text>
        <Text style={[styles.loadMeta, load.incidencias_abiertas > 0 && styles.incidentCount]}>
          {load.incidencias_abiertas > 0 ? `${load.incidencias_abiertas} incidencia(s)` : `${load.progreso.faltantes} faltantes`}
        </Text>
      </View>
    </Pressable>
  );
}

function LoadHeader({ load }: { load: RefrigeratedLoad }) {
  return (
    <View style={styles.loadHeader}>
      <View>
        <Text style={styles.eyebrow}>CARGA ACTIVA</Text>
        <Text style={styles.loadHeaderTitle}>{load.codigo} · {load.numero_orden_externa ?? 'Sin orden externa'}</Text>
        <Text style={styles.loadHeaderSubtitle}>
          {load.total_folios} folios · {load.progreso.en_anden} en andén · {load.progreso.faltantes} por concentrar
        </Text>
      </View>
      <View style={[styles.concentration, load.progreso.cumple_umbral && styles.concentrationReady]}>
        <Text style={styles.concentrationValue}>{load.progreso.porcentaje}%</Text>
        <Text style={styles.concentrationLabel}>{load.progreso.cumple_umbral ? 'CONCENTRADA' : 'EN PROCESO'}</Text>
      </View>
    </View>
  );
}

function RouteCard({ item, onPress, selected }: { item: ExtractionRouteItem; onPress: () => void; selected: boolean }) {
  const status = routeLabel(item);
  return (
    <Pressable onPress={onPress} style={[styles.routeCard, selected && styles.routeCardSelected]}>
      <View style={[styles.routeOrder, item.estado_ruta === 'sugerido' && styles.routeOrderNext]}>
        <Text style={styles.routeOrderText}>{item.orden ?? '—'}</Text>
      </View>
      <View style={styles.routeCopy}>
        <Text style={styles.routeFolio}>{item.folio.numero_folio}</Text>
        <Text style={styles.routeLocation}>
          {item.ubicacion ? `${cameraDisplayName(item.ubicacion.camara)} · ${item.ubicacion.posicion.etiqueta}` : 'Sin ubicación'}
        </Text>
        {item.bloqueadores.length > 0 && (
          <Text style={styles.blockers}>Delante: {item.bloqueadores.map((blocker) => blocker.numero_folio).join(', ')}</Text>
        )}
      </View>
      <Text style={[styles.routeStatus, item.estado_ruta === 'incidencia' && styles.routeStatusIncident]}>{status}</Text>
    </Pressable>
  );
}

function routeLabel(item: ExtractionRouteItem) {
  if (item.estado_ruta === 'sugerido') return 'SIGUIENTE';
  if (item.estado_ruta === 'disponible') return 'EN RUTA';
  if (item.estado_ruta === 'bloqueado') return 'BLOQUEADO';
  if (item.estado_ruta === 'incidencia') return 'INCIDENCIA';
  return 'SIN UBICAR';
}

function filterLabel(filter: QueueFilter) {
  return { todas: 'Todas', urgentes: 'Urgentes', preparacion: 'En preparación', incidencias: 'Con incidencias' }[filter];
}

type PhysicalWarning = { codigo: string; titulo: string; mensaje: string };

function physicalWarnings(reason: unknown): PhysicalWarning[] {
  if (!(reason instanceof ApiError) || !reason.data || typeof reason.data !== 'object') return [];
  const data = reason.data as { codigo?: string; advertencias?: PhysicalWarning[] };
  return data.codigo === 'confirmacion_requerida' && Array.isArray(data.advertencias)
    ? data.advertencias
    : [];
}

function confirmAlert(title: string, message: string, confirmText: string): Promise<boolean> {
  return new Promise((resolve) => Alert.alert(
    title,
    message,
    [
      { text: 'Cancelar', style: 'cancel', onPress: () => resolve(false) },
      { text: confirmText, style: 'destructive', onPress: () => resolve(true) },
    ],
    { cancelable: false },
  ));
}

const styles = StyleSheet.create({
  root: { minHeight: 600, position: 'relative' },
  moduleHeader: { marginBottom: 10, padding: 14, borderWidth: 1, borderColor: colors.border, borderRadius: 16, backgroundColor: colors.panel, flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', gap: 12 },
  eyebrow: { fontFamily: font.heavy, color: colors.cyan, fontSize: 12, letterSpacing: 1.3 },
  moduleTitle: { fontFamily: font.heavy, marginTop: 3, color: colors.text, fontSize: 20 },
  moduleSubtitle: { fontFamily: font.regular, marginTop: 3, color: colors.muted, fontSize: 13 },
  error: { fontFamily: font.regular, marginBottom: 9, padding: 10, borderRadius: 9, borderWidth: 1, borderColor: colors.red, backgroundColor: '#421B21', color: '#FFD0D2', fontSize: 14 },
  notice: { fontFamily: font.heavy, marginBottom: 9, padding: 10, borderRadius: 9, borderWidth: 1, borderColor: colors.green, backgroundColor: colors.greenDark, color: colors.text, fontSize: 14 },
  filters: { marginBottom: 10, flexDirection: 'row', flexWrap: 'wrap', gap: 7 },
  filter: { paddingHorizontal: 13, paddingVertical: 8, borderRadius: 9, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.panel },
  filterActive: { borderColor: colors.cyan, backgroundColor: colors.selected },
  filterText: { fontFamily: font.heavy, color: colors.muted, fontSize: 13 },
  filterTextActive: { color: colors.cyan },
  content: { flexDirection: 'row', alignItems: 'flex-start', gap: 10 },
  contentCompact: { flexDirection: 'column' },
  queue: { width: 290, maxHeight: 760, padding: 10, borderWidth: 1, borderColor: colors.border, borderRadius: 16, backgroundColor: colors.panel },
  queueCompact: { width: '100%', maxHeight: 190 },
  queueList: { gap: 8 },
  queueListCompact: { flexDirection: 'row' },
  loadCard: { width: 268, padding: 11, borderRadius: 11, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.background },
  loadCardSelected: { borderColor: colors.cyan, backgroundColor: colors.selected },
  loadTop: { flexDirection: 'row', justifyContent: 'space-between', gap: 8 },
  loadCode: { fontFamily: font.heavy, color: colors.text, fontSize: 13 },
  loadOrder: { fontFamily: font.regular, marginTop: 4, color: colors.muted, fontSize: 13 },
  priority: { fontFamily: font.heavy, color: colors.amber, fontSize: 11 },
  priorityUrgent: { color: colors.red },
  progressTrack: { height: 4, marginTop: 10, borderRadius: 4, backgroundColor: colors.borderSoft, overflow: 'hidden' },
  progressFill: { height: '100%', backgroundColor: colors.cyan },
  loadBottom: { marginTop: 6, flexDirection: 'row', justifyContent: 'space-between', gap: 6 },
  loadMeta: { fontFamily: font.regular, color: colors.muted, fontSize: 11 },
  incidentCount: { fontFamily: font.heavy, color: colors.red },
  emptyQueue: { width: 260, minHeight: 100, padding: 14, justifyContent: 'center' },
  emptyTitle: { fontFamily: font.heavy, color: colors.text, fontSize: 13 },
  emptyText: { fontFamily: font.regular, marginTop: 4, color: colors.muted, fontSize: 13 },
  detail: { flex: 1, minWidth: 0, gap: 10 },
  loadHeader: { padding: 13, borderWidth: 1, borderColor: colors.border, borderRadius: 16, backgroundColor: colors.panel, flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', gap: 12 },
  loadHeaderTitle: { fontFamily: font.heavy, marginTop: 3, color: colors.text, fontSize: 18 },
  loadHeaderSubtitle: { fontFamily: font.regular, marginTop: 3, color: colors.muted, fontSize: 13 },
  concentration: { minWidth: 94, padding: 9, borderRadius: 10, borderWidth: 1, borderColor: colors.amber, backgroundColor: colors.amberDark, alignItems: 'center' },
  concentrationReady: { borderColor: colors.green, backgroundColor: colors.greenDark },
  concentrationValue: { fontFamily: font.heavy, color: colors.text, fontSize: 18 },
  concentrationLabel: { fontFamily: font.heavy, color: colors.text, fontSize: 11 },
  detailBody: { flexDirection: 'row', alignItems: 'flex-start', gap: 10 },
  detailBodyCompact: { flexDirection: 'column' },
  routePanel: { width: 275, maxHeight: 690, padding: 12, borderWidth: 1, borderColor: colors.border, borderRadius: 16, backgroundColor: colors.panel },
  panelEyebrow: { fontFamily: font.heavy, color: colors.cyan, fontSize: 11, letterSpacing: 1.1 },
  panelTitle: { fontFamily: font.heavy, marginTop: 3, color: colors.text, fontSize: 15 },
  routeHint: { fontFamily: font.regular, marginTop: 4, color: colors.muted, fontSize: 12, lineHeight: 16 },
  routeScroll: { marginTop: 9, maxHeight: 590 },
  routeList: { gap: 6 },
  routeCard: { padding: 8, borderRadius: 9, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.background, flexDirection: 'row', alignItems: 'center', gap: 7 },
  routeCardSelected: { borderColor: colors.cyan, backgroundColor: colors.selected },
  routeOrder: { width: 26, height: 26, borderRadius: 8, backgroundColor: colors.borderSoft, alignItems: 'center', justifyContent: 'center' },
  routeOrderNext: { backgroundColor: colors.greenDark, borderWidth: 1, borderColor: colors.green },
  routeOrderText: { fontFamily: font.heavy, color: colors.text, fontSize: 13 },
  routeCopy: { flex: 1, minWidth: 0 },
  routeFolio: { fontFamily: font.heavy, color: colors.text, fontSize: 13 },
  routeLocation: { fontFamily: font.regular, marginTop: 2, color: colors.muted, fontSize: 11 },
  blockers: { fontFamily: font.regular, marginTop: 2, color: colors.red, fontSize: 11 },
  routeStatus: { fontFamily: font.heavy, color: colors.green, fontSize: 11 },
  routeStatusIncident: { color: colors.red },
  mapArea: { flex: 1, minWidth: 390 },
  emptyMap: { minHeight: 360, borderRadius: 16, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.panel, alignItems: 'center', justifyContent: 'center' },
  actions: { width: 240, padding: 12, borderWidth: 1, borderColor: colors.border, borderRadius: 16, backgroundColor: colors.panel, gap: 9 },
  selectedFolio: { padding: 10, borderRadius: 9, backgroundColor: colors.selected },
  selectedFolioNumber: { fontFamily: font.heavy, color: colors.text, fontSize: 13 },
  selectedFolioLocation: { fontFamily: font.regular, marginTop: 3, color: colors.muted, fontSize: 12 },
  fieldLabel: { fontFamily: font.heavy, marginTop: 5, color: colors.muted, fontSize: 12 },
  dockList: { flexDirection: 'row', flexWrap: 'wrap', gap: 5 },
  dock: { paddingHorizontal: 9, paddingVertical: 7, borderRadius: 8, borderWidth: 1, borderColor: colors.border },
  dockActive: { borderColor: colors.cyan, backgroundColor: colors.selected },
  dockText: { fontFamily: font.heavy, color: colors.muted, fontSize: 12 },
  dockTextActive: { color: colors.cyan },
  primaryButton: { padding: 11, borderRadius: 9, backgroundColor: colors.cyan, alignItems: 'center' },
  primaryButtonText: { fontFamily: font.heavy, color: colors.accentText, fontSize: 13 },
  bulkButton: { padding: 11, borderRadius: 9, borderWidth: 1, borderColor: colors.green, backgroundColor: colors.greenDark, alignItems: 'center' },
  bulkButtonText: { fontFamily: font.heavy, color: colors.text, fontSize: 13 },
  incidentButton: { padding: 10, borderRadius: 9, borderWidth: 1, borderColor: colors.red, backgroundColor: '#421B21', alignItems: 'center' },
  incidentButtonText: { fontFamily: font.heavy, color: '#FFD0D2', fontSize: 13 },
  secondaryButton: { paddingHorizontal: 12, paddingVertical: 9, borderRadius: 9, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.background, alignItems: 'center' },
  secondaryButtonText: { fontFamily: font.heavy, color: colors.text, fontSize: 13 },
  operationNote: { fontFamily: font.regular, color: colors.muted, fontSize: 11, lineHeight: 14 },
  disabled: { opacity: 0.35 },
  emptyDetail: { flex: 1, minHeight: 420, borderWidth: 1, borderColor: colors.border, borderRadius: 16, backgroundColor: colors.panel, alignItems: 'center', justifyContent: 'center' },
  busy: { ...StyleSheet.absoluteFill, zIndex: 10, borderRadius: 16, backgroundColor: 'rgba(5,8,11,0.78)', alignItems: 'center', justifyContent: 'center', gap: 9 },
  busyText: { fontFamily: font.heavy, color: colors.text, fontSize: 14 },
  modalBackdrop: { flex: 1, padding: 20, backgroundColor: 'rgba(0,0,0,0.75)', alignItems: 'center', justifyContent: 'center' },
  modalCard: { width: '100%', maxWidth: 720, padding: 20, borderRadius: 18, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.panel },
  modalTitle: { fontFamily: font.heavy, marginTop: 4, color: colors.text, fontSize: 20 },
  modalText: { fontFamily: font.regular, marginTop: 5, color: colors.muted, fontSize: 14 },
  incidentTypes: { marginTop: 15, flexDirection: 'row', flexWrap: 'wrap', gap: 7 },
  incidentType: { paddingHorizontal: 11, paddingVertical: 9, borderRadius: 9, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.background },
  incidentTypeActive: { borderColor: colors.red, backgroundColor: '#421B21' },
  incidentTypeText: { fontFamily: font.heavy, color: colors.muted, fontSize: 13 },
  incidentTypeTextActive: { color: '#FFD0D2' },
  description: { minHeight: 80, marginTop: 14, padding: 11, borderRadius: 9, borderWidth: 1, borderColor: colors.border, color: colors.text, backgroundColor: colors.background, textAlignVertical: 'top' },
  modalActions: { marginTop: 14, flexDirection: 'row', justifyContent: 'flex-end', gap: 8 },
});
