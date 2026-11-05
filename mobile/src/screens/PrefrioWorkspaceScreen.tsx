import { font } from '../theme/typography';
import { FieldHeader } from '../components/field/FieldHeader';
import * as Crypto from 'expo-crypto';
import { useEffect, useMemo, useRef, useState } from 'react';
import {
  ActivityIndicator,
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
  PrefrioFolioCandidate,
  PrefrioMobileCache,
  PrefrioProcess,
  PrefrioTunnelPosition,
} from '../domain/prefrio';
import { ApiError } from '../services/apiError';
import {
  executePrefrioCommand,
  listEligiblePrefrioFolios,
  listPrefrioProcesses,
  listPrefrioTunnels,
} from '../services/prefrioApi';
import {
  loadPrefrioCache,
  savePrefrioCache,
} from '../services/prefrioOfflineStore';
import { colors } from '../theme/colors';
import { DewPointScreen } from './DewPointScreen';
import { PrefrioScreen } from './PrefrioScreen';

type PrefrioWorkspaceProps = {
  auth: AuthSession;
  baseUrl: string | null;
  onLogout: () => void;
};

type WorkspaceView = 'pendientes' | 'tuneles' | 'rocio';

type CandidateGroup = {
  key: PrefrioFolioCandidate['condicion_termica'];
  label: string;
  items: PrefrioFolioCandidate[];
};

const LOADABLE_PROCESS_STATES = new Set(['borrador', 'cargando', 'listo_para_iniciar']);

const EMPTY_CACHE: PrefrioMobileCache = {
  tunnels: [],
  processes: [],
  eligible_folios: [],
  revisions: {},
  synced_at: '',
};

export function PrefrioWorkspaceScreen({ auth, baseUrl, onLogout }: PrefrioWorkspaceProps) {
  const loadingFolio = useRef(false);
  const previousView = useRef<WorkspaceView>('pendientes');
  const synchronizing = useRef(false);
  const cacheRef = useRef<PrefrioMobileCache>(EMPTY_CACHE);
  const userId = auth.usuario.id;
  const deviceId = auth.dispositivo.id;
  const canOperate = auth.usuario.capacidades.puede_operar_prefrio === true;
  const [activeView, setActiveView] = useState<WorkspaceView>('pendientes');
  const [folios, setFolios] = useState<PrefrioFolioCandidate[]>([]);
  const [processes, setProcesses] = useState<PrefrioProcess[]>([]);
  const [tunnels, setTunnels] = useState<PrefrioMobileCache['tunnels']>([]);
  const [search, setSearch] = useState('');
  const [selectedFolioId, setSelectedFolioId] = useState<string | null>(null);
  const [selectedProcessId, setSelectedProcessId] = useState<string | null>(null);
  const [selectedPositionId, setSelectedPositionId] = useState<string | null>(null);
  const [temperature, setTemperature] = useState('');
  const [busy, setBusy] = useState(true);
  const [online, setOnline] = useState(Boolean(baseUrl));
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  const selectedFolio = useMemo(
    () => folios.find((item) => item.id === selectedFolioId) ?? null,
    [folios, selectedFolioId],
  );
  const loadableProcesses = useMemo(
    () => processes.filter((item) => LOADABLE_PROCESS_STATES.has(item.estado)),
    [processes],
  );
  const selectedProcess = useMemo(
    () => loadableProcesses.find((item) => item.id === selectedProcessId) ?? null,
    [loadableProcesses, selectedProcessId],
  );
  const selectedTunnel = useMemo(
    () => tunnels.find((item) => item.id === selectedProcess?.tunel.id) ?? null,
    [tunnels, selectedProcess],
  );
  const occupiedPositionIds = useMemo(() => new Set(
    selectedProcess?.folios
      .filter((item) => !['retirado', 'cancelado'].includes(item.estado))
      .map((item) => item.posicion?.id)
      .filter((id): id is string => Boolean(id)) ?? [],
  ), [selectedProcess]);
  const freePositions = useMemo(
    () => selectedTunnel?.posiciones.filter((item) => item.activa && !occupiedPositionIds.has(item.id)) ?? [],
    [selectedTunnel, occupiedPositionIds],
  );
  const filteredFolios = useMemo(() => {
    const query = search.trim().toLowerCase();
    if (!query) return folios;
    return folios.filter((item) => [
      item.numero_folio,
      item.tipo_bulto,
      item.especie,
      item.variedad,
      item.calibre,
      item.envase,
      item.marca,
      item.exportadora,
      item.csg,
      item.predio,
    ].some((value) => String(value ?? '').toLowerCase().includes(query)));
  }, [folios, search]);
  const groups = useMemo<CandidateGroup[]>(() => ([
    {
      key: 'pendiente_prefrio',
      label: 'Pendientes nuevos',
      items: filteredFolios.filter((item) => item.condicion_termica === 'pendiente_prefrio'),
    },
    {
      key: 'requiere_reproceso',
      label: 'Requieren reproceso',
      items: filteredFolios.filter((item) => item.condicion_termica === 'requiere_reproceso'),
    },
    {
      key: 'retenido',
      label: 'Retenidos',
      items: filteredFolios.filter((item) => item.condicion_termica === 'retenido'),
    },
  ]), [filteredFolios]);

  useEffect(() => {
    void initialize();
  }, []);

  useEffect(() => {
    if (!selectedProcessId && loadableProcesses.length) {
      setSelectedProcessId(loadableProcesses[0].id);
    }
  }, [loadableProcesses, selectedProcessId]);

  useEffect(() => {
    setSelectedPositionId(freePositions[0]?.id ?? null);
  }, [selectedProcessId, freePositions.length]);

  useEffect(() => {
    const previous = previousView.current;
    previousView.current = activeView;
    if (previous !== 'pendientes' && activeView === 'pendientes') {
      void synchronize(false);
    }
  }, [activeView]);

  useOperationalPolling(
    () => synchronize(false),
    {
      intervalMs: 30000,
      enabled: activeView === 'pendientes' && Boolean(baseUrl),
    },
  );

  async function initialize() {
    setBusy(true);
    try {
      const cached = await loadPrefrioCache(userId, deviceId);
      if (cached) {
        cacheRef.current = cached;
        setFolios(cached.eligible_folios);
        setProcesses(cached.processes);
        setTunnels(cached.tunnels);
      }
      await synchronize(false);
    } catch (reason) {
      setError(messageFrom(reason));
    } finally {
      setBusy(false);
    }
  }

  async function synchronize(showNotice = true) {
    if (!baseUrl) {
      setOnline(false);
      if (showNotice) setError('La PDA está sin conexión. Se muestra la última bandeja sincronizada.');
      return;
    }
    if (synchronizing.current) return;

    synchronizing.current = true;
    try {
      const current = cacheRef.current;
      const [nextTunnels, nextProcesses, nextFolios] = await Promise.all([
        listPrefrioTunnels(baseUrl, auth.token, current.revisions?.tunnels),
        listPrefrioProcesses(baseUrl, auth.token, current.revisions?.processes),
        listEligiblePrefrioFolios(
          baseUrl,
          auth.token,
          500,
          current.revisions?.eligible_folios,
        ),
      ]);
      const nextCache: PrefrioMobileCache = {
        tunnels: nextTunnels.data ?? current.tunnels,
        processes: nextProcesses.data ?? current.processes,
        eligible_folios: nextFolios.data ?? current.eligible_folios,
        revisions: {
          tunnels: nextTunnels.etag,
          processes: nextProcesses.etag,
          eligible_folios: nextFolios.etag,
        },
        synced_at: new Date().toISOString(),
      };
      cacheRef.current = nextCache;
      setTunnels(nextCache.tunnels);
      setProcesses(nextCache.processes);
      setFolios(nextCache.eligible_folios);
      await savePrefrioCache(userId, deviceId, nextCache);
      setOnline(true);
      setError('');
      if (showNotice) setNotice('Bandeja de Prefrío actualizada.');
    } catch (reason) {
      if (reason instanceof ApiError && reason.status === 401) {
        onLogout();
        return;
      }
      if (reason instanceof ApiError && reason.status === 0) setOnline(false);
      setError(messageFrom(reason));
    } finally {
      synchronizing.current = false;
    }
  }

  function openCandidate(candidate: PrefrioFolioCandidate) {
    setSelectedFolioId(candidate.id);
    setSelectedProcessId(loadableProcesses[0]?.id ?? null);
    setTemperature('');
    setError('');
    setNotice('');
  }

  function closeCandidate() {
    setSelectedFolioId(null);
    setSelectedPositionId(null);
    setTemperature('');
  }

  async function loadSelectedFolio() {
    if (loadingFolio.current || busy) return;
    if (!selectedFolio || !selectedProcess || !selectedPositionId) {
      setError('Selecciona un proceso activo y una posición libre.');
      return;
    }
    if (!baseUrl) {
      setError('La carga rápida desde la bandeja requiere conexión. Usa Túneles para operar con la bandeja offline.');
      return;
    }
    if (!canOperate) {
      setError('Tu perfil solo puede consultar la bandeja de Prefrío.');
      return;
    }

    const parsedTemperature = temperature.trim() === ''
      ? undefined
      : Number(temperature.replace(',', '.'));
    if (parsedTemperature !== undefined && !Number.isFinite(parsedTemperature)) {
      setError('La temperatura inicial no es válida.');
      return;
    }

    const reopensAssembly = selectedProcess.estado === 'listo_para_iniciar';
    loadingFolio.current = true;
    setBusy(true);
    setError('');
    setNotice('');
    try {
      const updatedProcess = await executePrefrioCommand(
        baseUrl,
        auth.token,
        `/api/prefrio/procesos/${selectedProcess.id}/folios`,
        {
          operacion_id: Crypto.randomUUID(),
          version_conocida: selectedProcess.version,
          folio_id: selectedFolio.id,
          posicion_tunel_prefrio_id: selectedPositionId,
          ...(parsedTemperature !== undefined ? { temperatura_inicial: parsedTemperature } : {}),
          ocurrido_at: new Date().toISOString(),
        },
      );
      const nextProcesses = [
        updatedProcess,
        ...processes.filter((item) => item.id !== updatedProcess.id),
      ];
      const nextFolios = folios.filter((item) => item.id !== selectedFolio.id);
      const nextCache: PrefrioMobileCache = {
        ...cacheRef.current,
        tunnels,
        processes: nextProcesses,
        eligible_folios: nextFolios,
        synced_at: new Date().toISOString(),
      };
      cacheRef.current = nextCache;
      setProcesses(nextProcesses);
      setFolios(nextFolios);
      await savePrefrioCache(userId, deviceId, nextCache);
      setOnline(true);
      setNotice(
        `${selectedFolio.numero_folio} cargado en ${positionLabel(selectedPositionId, freePositions)} del proceso ${updatedProcess.codigo}.`
        + (reopensAssembly ? ' El proceso volvió a Cargando; confirma nuevamente el armado antes de iniciarlo.' : ''),
      );
      closeCandidate();
    } catch (reason) {
      if (reason instanceof ApiError && reason.status === 0) setOnline(false);
      setError(messageFrom(reason));
      await synchronize(false);
    } finally {
      loadingFolio.current = false;
      setBusy(false);
    }
  }

  if (activeView === 'rocio') {
    return (
      <View style={styles.screen}>
        <WorkspaceTabs activeView={activeView} pendingCount={folios.length} onChange={setActiveView} />
        <View style={styles.tunnelWorkspace}>
          <DewPointScreen auth={auth} baseUrl={baseUrl} onLogout={onLogout} />
        </View>
      </View>
    );
  }

  if (activeView === 'tuneles') {
    return (
      <View style={styles.screen}>
        <WorkspaceTabs activeView={activeView} pendingCount={folios.length} onChange={setActiveView} />
        <View style={styles.tunnelWorkspace}>
          <PrefrioScreen auth={auth} baseUrl={baseUrl} onLogout={onLogout} />
        </View>
      </View>
    );
  }

  return (
    <View style={styles.screen}>
      <WorkspaceTabs activeView={activeView} pendingCount={folios.length} onChange={setActiveView} />
      <ScrollView contentContainerStyle={styles.page} keyboardShouldPersistTaps="handled">
        <FieldHeader area="frigorifico" auth={auth} title="Prefrío · Pendientes" offline={!online} onLogout={onLogout}><Pressable disabled={busy} onPress={() => void synchronize()} style={styles.secondaryButton}><Text style={styles.secondaryButtonText}>↻</Text></Pressable></FieldHeader>

        {notice ? <Text style={styles.notice}>{notice}</Text> : null}
        {error ? <Text style={styles.error}>{error}</Text> : null}

        <View style={styles.metrics}>
          <Metric label="PENDIENTES NUEVOS" value={String(groups[0].items.length)} />
          <Metric label="REPROCESOS" value={String(groups[1].items.length)} warning={groups[1].items.length > 0} />
          <Metric label="RETENIDOS" value={String(groups[2].items.length)} warning={groups[2].items.length > 0} />
          <Metric label="PROCESOS CARGABLES" value={String(loadableProcesses.length)} />
        </View>

        <TextInput
          autoCapitalize="characters"
          autoCorrect={false}
          onChangeText={setSearch}
          placeholder="Buscar por folio, variedad, calibre, cliente, marca, CSG o predio"
          placeholderTextColor={colors.muted}
          style={styles.searchInput}
          value={search}
        />

        {groups.map((group) => group.items.length ? (
          <View key={group.key} style={styles.group}>
            <View style={styles.groupHeader}>
              <Text style={styles.groupTitle}>{group.label}</Text>
              <Text style={styles.groupCount}>{group.items.length}</Text>
            </View>
            <View style={styles.cards}>
              {group.items.map((candidate) => (
                <CandidateCard key={candidate.id} candidate={candidate} canOperate={canOperate} onOpen={openCandidate} />
              ))}
            </View>
          </View>
        ) : null)}

        {!filteredFolios.length ? (
          <View style={styles.emptyPanel}>
            <Text style={styles.emptyTitle}>{folios.length ? 'Sin coincidencias' : 'No existen folios esperando Prefrío'}</Text>
            <Text style={styles.subtitle}>{folios.length ? 'Cambia el texto de búsqueda para ver otros resultados.' : 'Los pallets aprobados en Validación aparecerán aquí al sincronizar.'}</Text>
          </View>
        ) : null}
      </ScrollView>

      <CandidateModal
        busy={busy}
        canOperate={canOperate}
        folio={selectedFolio}
        freePositions={freePositions}
        loadableProcesses={loadableProcesses}
        selectedPositionId={selectedPositionId}
        selectedProcessId={selectedProcessId}
        temperature={temperature}
        onClose={closeCandidate}
        onConfirm={() => void loadSelectedFolio()}
        onPositionChange={setSelectedPositionId}
        onProcessChange={setSelectedProcessId}
        onTemperatureChange={setTemperature}
        onOpenTunnels={() => {
          closeCandidate();
          setActiveView('tuneles');
        }}
      />

      {busy ? (
        <View style={styles.busyOverlay}>
          <ActivityIndicator color={colors.cyan} size="large" />
          <Text style={styles.busyText}>Actualizando Prefrío…</Text>
        </View>
      ) : null}
    </View>
  );
}

function WorkspaceTabs({ activeView, pendingCount, onChange }: { activeView: WorkspaceView; pendingCount: number; onChange: (view: WorkspaceView) => void }) {
  return (
    <View style={styles.tabs}>
      <Pressable onPress={() => onChange('pendientes')} style={[styles.tab, activeView === 'pendientes' && styles.tabActive]}>
        <Text style={[styles.tabText, activeView === 'pendientes' && styles.tabTextActive]}>Pendientes ({pendingCount})</Text>
      </Pressable>
      <Pressable onPress={() => onChange('tuneles')} style={[styles.tab, activeView === 'tuneles' && styles.tabActive]}>
        <Text style={[styles.tabText, activeView === 'tuneles' && styles.tabTextActive]}>Túneles y procesos</Text>
      </Pressable>
      <Pressable onPress={() => onChange('rocio')} style={[styles.tab, activeView === 'rocio' && styles.tabActive]}>
        <Text style={[styles.tabText, activeView === 'rocio' && styles.tabTextActive]}>Punto de rocío</Text>
      </Pressable>
    </View>
  );
}

function CandidateCard({ candidate, canOperate, onOpen }: { candidate: PrefrioFolioCandidate; canOperate: boolean; onOpen: (candidate: PrefrioFolioCandidate) => void }) {
  return (
    <Pressable onPress={() => onOpen(candidate)} style={styles.card}>
      <View style={styles.cardHeader}>
        <View>
          <Text style={styles.folioNumber}>{candidate.numero_folio}</Text>
          <Text style={styles.cardMeta}>{candidate.tipo_bulto === 'pallet' ? 'Pallet completo' : 'Saldo'}{candidate.cantidad_cajas ? ` · ${candidate.cantidad_cajas} cajas` : ''}</Text>
        </View>
        <Text style={[styles.statusBadge, candidate.condicion_termica === 'pendiente_prefrio' ? styles.pendingBadge : candidate.condicion_termica === 'requiere_reproceso' ? styles.reprocessBadge : styles.retainedBadge]}>
          {thermalLabel(candidate.condicion_termica)}
        </Text>
      </View>
      <Text style={styles.article}>{[candidate.especie, candidate.variedad, candidate.calibre, candidate.envase].filter(Boolean).join(' · ') || 'Artículo sin detalle'}</Text>
      <Text style={styles.detail}>Cliente: {candidate.exportadora ?? '—'} · Marca: {candidate.marca ?? '—'}</Text>
      <Text style={styles.detail}>CSG: {candidate.csg ?? '—'} · Predio: {candidate.predio ?? '—'}</Text>
      <View style={styles.cardFooter}>
        <Text style={styles.dateText}>Validado {formatDateTime(candidate.fecha_ingreso)}</Text>
        <Text style={styles.openText}>{canOperate ? 'Revisar y cargar →' : 'Ver detalle →'}</Text>
      </View>
    </Pressable>
  );
}

function CandidateModal({
  busy,
  canOperate,
  folio,
  freePositions,
  loadableProcesses,
  selectedPositionId,
  selectedProcessId,
  temperature,
  onClose,
  onConfirm,
  onPositionChange,
  onProcessChange,
  onTemperatureChange,
  onOpenTunnels,
}: {
  busy: boolean;
  canOperate: boolean;
  folio: PrefrioFolioCandidate | null;
  freePositions: PrefrioTunnelPosition[];
  loadableProcesses: PrefrioProcess[];
  selectedPositionId: string | null;
  selectedProcessId: string | null;
  temperature: string;
  onClose: () => void;
  onConfirm: () => void;
  onPositionChange: (id: string) => void;
  onProcessChange: (id: string) => void;
  onTemperatureChange: (value: string) => void;
  onOpenTunnels: () => void;
}) {
  if (!folio) return null;
  const selectedProcess = loadableProcesses.find((process) => process.id === selectedProcessId) ?? null;

  return (
    <Modal animationType="slide" transparent visible onRequestClose={() => { if (!busy) onClose(); }}>
      <View style={styles.modalBackdrop}>
        <View style={styles.modalCard}>
          <ScrollView contentContainerStyle={styles.modalContent} keyboardShouldPersistTaps="handled">
            <View style={styles.modalHeader}>
              <View>
                <Text style={styles.eyebrow}>{thermalLabel(folio.condicion_termica)}</Text>
                <Text style={styles.modalTitle}>{folio.numero_folio}</Text>
              </View>
              <Pressable disabled={busy} onPress={onClose}><Text style={styles.modalClose}>×</Text></Pressable>
            </View>

            <View style={styles.detailGrid}>
              <Detail label="Bulto" value={`${folio.tipo_bulto === 'pallet' ? 'Pallet completo' : 'Saldo'}${folio.cantidad_cajas ? ` · ${folio.cantidad_cajas} cajas` : ''}`} />
              <Detail label="Artículo" value={[folio.especie, folio.variedad, folio.calibre, folio.envase].filter(Boolean).join(' · ') || 'Sin detalle'} />
              <Detail label="Cliente / marca" value={`${folio.exportadora ?? '—'} · ${folio.marca ?? '—'}`} />
              <Detail label="Origen" value={`CSG ${folio.csg ?? '—'} · ${folio.predio ?? '—'}`} />
              <Detail label="Categoría" value={folio.categoria ?? '—'} />
              <Detail label="Validado" value={formatDateTime(folio.fecha_ingreso)} />
            </View>

            {canOperate ? (
              <>
                <Text style={styles.modalSectionTitle}>Proceso de destino</Text>
                {loadableProcesses.length ? (
                  <View style={styles.optionGrid}>
                    {loadableProcesses.map((process) => (
                      <Pressable disabled={busy} key={process.id} onPress={() => onProcessChange(process.id)} style={[styles.optionCard, process.id === selectedProcessId && styles.optionCardSelected, busy && styles.disabled]}>
                        <Text style={styles.optionTitle}>{process.codigo}</Text>
                        <Text style={styles.optionDetail}>{process.tunel.codigo} · {process.folios.filter((item) => !['retirado', 'cancelado'].includes(item.estado)).length}/{process.tunel.capacidad_posiciones}</Text>
                        <Text style={styles.optionState}>{processStateLabel(process.estado)}</Text>
                      </Pressable>
                    ))}
                  </View>
                ) : (
                  <View style={styles.emptyProcess}>
                    <Text style={styles.emptyTitle}>No existe un proceso cargable</Text>
                    <Text style={styles.subtitle}>Crea o abre un proceso en la vista de Túneles antes de asignar este folio.</Text>
                    <Pressable disabled={busy} onPress={onOpenTunnels} style={[styles.secondaryButton, busy && styles.disabled]}><Text style={styles.secondaryButtonText}>Abrir Túneles</Text></Pressable>
                  </View>
                )}

                {loadableProcesses.length ? (
                  <>
                    {selectedProcess?.estado === 'listo_para_iniciar' ? (
                      <Text style={styles.reopenNotice}>
                        Al cargar este folio, el proceso volverá a Cargando y deberás confirmar nuevamente el armado antes de iniciarlo.
                      </Text>
                    ) : null}
                    <Text style={styles.modalSectionTitle}>Posición libre</Text>
                    <View style={styles.positionGrid}>
                      {freePositions.map((position) => (
                        <Pressable disabled={busy} key={position.id} onPress={() => onPositionChange(position.id)} style={[styles.position, position.id === selectedPositionId && styles.positionSelected, busy && styles.disabled]}>
                          <Text style={styles.positionText}>{position.etiqueta}</Text>
                        </Pressable>
                      ))}
                      {!freePositions.length ? <Text style={styles.error}>El proceso seleccionado no posee posiciones libres.</Text> : null}
                    </View>
                    <Text style={styles.inputLabel}>Temperatura inicial opcional</Text>
                    <TextInput
                      editable={!busy}
                      keyboardType="decimal-pad"
                      onChangeText={onTemperatureChange}
                      placeholder="Ej.: 8,5"
                      placeholderTextColor={colors.muted}
                      style={styles.temperatureInput}
                      value={temperature}
                    />
                  </>
                ) : null}
              </>
            ) : (
              <Text style={styles.readOnlyNotice}>Tu perfil puede consultar los folios, pero no cargarlos a un túnel.</Text>
            )}

            <View style={styles.modalActions}>
              <Pressable disabled={busy} onPress={onClose} style={[styles.secondaryButton, busy && styles.disabled]}><Text style={styles.secondaryButtonText}>Cerrar</Text></Pressable>
              {canOperate && loadableProcesses.length ? (
                <Pressable disabled={busy || !selectedPositionId} onPress={onConfirm} style={[styles.primaryButton, (busy || !selectedPositionId) && styles.disabled]}>
                  <Text style={styles.primaryButtonText}>{busy ? 'Cargando…' : 'Cargar al túnel'}</Text>
                </Pressable>
              ) : null}
            </View>
          </ScrollView>
        </View>
      </View>
    </Modal>
  );
}

function Detail({ label, value }: { label: string; value: string }) {
  return <View style={styles.detailBox}><Text style={styles.inputLabel}>{label}</Text><Text style={styles.detailValue}>{value}</Text></View>;
}

function Metric({ label, value, warning = false }: { label: string; value: string; warning?: boolean }) {
  return <View style={[styles.metric, warning && styles.metricWarning]}><Text style={styles.metricValue}>{value}</Text><Text style={styles.metricLabel}>{label}</Text></View>;
}

function thermalLabel(value: PrefrioFolioCandidate['condicion_termica']) {
  return value === 'pendiente_prefrio' ? 'Pendiente de Prefrío' : value === 'requiere_reproceso' ? 'Reproceso' : 'Retenido';
}

function processStateLabel(value: PrefrioProcess['estado']) {
  if (value === 'borrador') return 'Borrador';
  if (value === 'cargando') return 'Cargando';
  if (value === 'listo_para_iniciar') return 'Listo para iniciar';
  return value;
}

function formatDateTime(value: string | null) {
  if (!value) return 'sin fecha';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return 'sin fecha';
  return new Intl.DateTimeFormat('es-CL', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }).format(date);
}

function positionLabel(positionId: string, positions: PrefrioTunnelPosition[]) {
  return positions.find((item) => item.id === positionId)?.etiqueta ?? 'la posición seleccionada';
}

function messageFrom(reason: unknown) {
  return reason instanceof Error ? reason.message : 'Ocurrió un error inesperado en Prefrío.';
}

const styles = StyleSheet.create({
  screen: { flex: 1, backgroundColor: colors.background },
  tunnelWorkspace: { flex: 1 },
  tabs: { flexDirection: 'row', gap: 8, paddingHorizontal: 18, paddingVertical: 9, borderBottomWidth: 1, borderBottomColor: colors.border, backgroundColor: colors.backgroundDeep },
  tab: { paddingHorizontal: 16, paddingVertical: 9, borderRadius: 10, borderWidth: 1, borderColor: colors.border },
  tabActive: { borderColor: colors.cyan, backgroundColor: colors.selected },
  tabText: { color: colors.muted, fontFamily: font.heavy, fontSize: 14 },
  tabTextActive: { color: colors.cyan },
  page: { padding: 18, gap: 16, paddingBottom: 44 },
  header: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', gap: 18, padding: 18, borderRadius: 16, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.panel },
  headerActions: { flexDirection: 'row', alignItems: 'center', gap: 9, flexWrap: 'wrap', justifyContent: 'flex-end' },
  eyebrow: { color: colors.cyan, fontSize: 14, fontFamily: font.heavy, letterSpacing: 1.2 },
  title: { color: colors.text, fontSize: 25, fontFamily: font.heavy, marginTop: 4 },
  subtitle: { color: colors.muted, marginTop: 6, lineHeight: 19 },
  connection: { paddingHorizontal: 11, paddingVertical: 8, borderRadius: 999, borderWidth: 1 },
  online: { borderColor: colors.green, backgroundColor: colors.greenDark },
  offline: { borderColor: colors.red, backgroundColor: colors.blocked },
  connectionText: { color: colors.text, fontSize: 14, fontFamily: font.heavy },
  secondaryButton: { paddingHorizontal: 13, paddingVertical: 10, borderRadius: 10, borderWidth: 1, borderColor: colors.cyanDark, alignItems: 'center' },
  secondaryButtonText: { color: colors.cyan, fontSize: 14, fontFamily: font.heavy },
  primaryButton: { paddingHorizontal: 18, paddingVertical: 12, borderRadius: 10, borderWidth: 1, borderColor: colors.green, backgroundColor: colors.greenDark, alignItems: 'center' },
  primaryButtonText: { color: colors.text, fontSize: 14, fontFamily: font.heavy, textTransform: 'uppercase' },
  disabled: { opacity: .4 },
  notice: { color: colors.text, padding: 12, borderRadius: 10, borderWidth: 1, borderColor: colors.green, backgroundColor: colors.greenDark, fontFamily: font.heavy },
  error: { color: colors.text, padding: 12, borderRadius: 10, borderWidth: 1, borderColor: colors.red, backgroundColor: colors.blocked, fontFamily: font.heavy },
  metrics: { flexDirection: 'row', gap: 10, flexWrap: 'wrap' },
  metric: { minWidth: 170, flex: 1, padding: 15, borderRadius: 13, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.panel },
  metricWarning: { borderColor: colors.amber },
  metricValue: { color: colors.text, fontSize: 24, fontFamily: font.heavy },
  metricLabel: { color: colors.muted, fontSize: 14, fontFamily: font.heavy, marginTop: 4 },
  searchInput: { minHeight: 52, paddingHorizontal: 15, borderRadius: 12, borderWidth: 1, borderColor: colors.cyanDark, color: colors.text, backgroundColor: colors.backgroundDeep, fontFamily: font.heavy },
  group: { gap: 10 },
  groupHeader: { flexDirection: 'row', alignItems: 'center', gap: 8 },
  groupTitle: { color: colors.text, fontSize: 18, fontFamily: font.heavy },
  groupCount: { color: colors.cyan, fontSize: 14, fontFamily: font.heavy, paddingHorizontal: 8, paddingVertical: 4, borderRadius: 999, backgroundColor: colors.selected },
  cards: { flexDirection: 'row', flexWrap: 'wrap', gap: 12 },
  card: { flexGrow: 1, flexBasis: 310, maxWidth: 470, padding: 16, borderRadius: 14, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.panel },
  cardHeader: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'flex-start', gap: 10 },
  folioNumber: { color: colors.text, fontSize: 18, fontFamily: font.monoBold },
  cardMeta: { fontFamily: font.regular, color: colors.muted, fontSize: 14, marginTop: 3 },
  statusBadge: { overflow: 'hidden', paddingHorizontal: 9, paddingVertical: 5, borderRadius: 999, color: colors.text, fontSize: 13, fontFamily: font.heavy, textTransform: 'uppercase' },
  pendingBadge: { backgroundColor: colors.free },
  reprocessBadge: { backgroundColor: colors.amberDark },
  retainedBadge: { backgroundColor: colors.blocked },
  article: { color: colors.cyan, fontFamily: font.heavy, marginTop: 13 },
  detail: { fontFamily: font.regular, color: colors.text, fontSize: 14, marginTop: 5 },
  cardFooter: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', gap: 8, marginTop: 15, paddingTop: 10, borderTopWidth: 1, borderTopColor: colors.borderSoft },
  dateText: { fontFamily: font.regular, color: colors.muted, fontSize: 14 },
  openText: { color: colors.cyan, fontSize: 14, fontFamily: font.heavy },
  emptyPanel: { alignItems: 'center', padding: 32, borderRadius: 14, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.panel },
  emptyTitle: { color: colors.text, fontSize: 17, fontFamily: font.heavy },
  modalBackdrop: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: 18, backgroundColor: 'rgba(0,0,0,.76)' },
  modalCard: { width: '100%', maxWidth: 920, maxHeight: '94%', borderRadius: 17, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.panelStrong },
  modalContent: { padding: 20 },
  modalHeader: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', gap: 12, marginBottom: 15 },
  modalTitle: { color: colors.text, fontSize: 25, fontFamily: font.heavy, marginTop: 3 },
  modalClose: { color: colors.text, fontSize: 29, fontFamily: font.heavy, paddingHorizontal: 8 },
  detailGrid: { flexDirection: 'row', flexWrap: 'wrap', gap: 9 },
  detailBox: { flexGrow: 1, flexBasis: 260, padding: 12, borderRadius: 10, borderWidth: 1, borderColor: colors.borderSoft, backgroundColor: colors.backgroundDeep },
  inputLabel: { color: colors.muted, fontSize: 13, fontFamily: font.heavy, textTransform: 'uppercase', marginBottom: 5 },
  detailValue: { color: colors.text, fontFamily: font.heavy },
  modalSectionTitle: { color: colors.text, fontSize: 15, fontFamily: font.heavy, marginTop: 18, marginBottom: 9 },
  optionGrid: { flexDirection: 'row', flexWrap: 'wrap', gap: 9 },
  optionCard: { minWidth: 180, flexGrow: 1, padding: 12, borderRadius: 10, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.backgroundDeep },
  optionCardSelected: { borderColor: colors.cyan, backgroundColor: colors.selected },
  optionTitle: { color: colors.text, fontFamily: font.heavy },
  optionDetail: { fontFamily: font.regular, color: colors.muted, fontSize: 14, marginTop: 4 },
  optionState: { color: colors.cyan, fontSize: 14, fontFamily: font.heavy, marginTop: 5, textTransform: 'uppercase' },
  reopenNotice: { color: colors.text, padding: 11, borderRadius: 10, borderWidth: 1, borderColor: colors.amber, backgroundColor: colors.amberDark, fontFamily: font.heavy, lineHeight: 18 },
  positionGrid: { flexDirection: 'row', flexWrap: 'wrap', gap: 7 },
  position: { paddingHorizontal: 10, paddingVertical: 9, borderRadius: 8, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.backgroundDeep },
  positionSelected: { borderColor: colors.cyan, backgroundColor: colors.selected },
  positionText: { color: colors.text, fontSize: 14, fontFamily: font.heavy },
  temperatureInput: { minHeight: 52, paddingHorizontal: 13, borderRadius: 10, borderWidth: 1, borderColor: colors.border, color: colors.text, backgroundColor: colors.backgroundDeep },
  emptyProcess: { gap: 10, padding: 14, borderRadius: 11, borderWidth: 1, borderColor: colors.amber, backgroundColor: colors.amberDark },
  readOnlyNotice: { color: colors.text, marginTop: 18, padding: 13, borderRadius: 10, borderWidth: 1, borderColor: colors.cyanDark, backgroundColor: colors.selected },
  modalActions: { flexDirection: 'row', justifyContent: 'flex-end', gap: 9, marginTop: 20 },
  busyOverlay: { ...StyleSheet.absoluteFillObject, alignItems: 'center', justifyContent: 'center', gap: 10, backgroundColor: 'rgba(8,12,16,.74)' },
  busyText: { color: colors.text, fontFamily: font.heavy },
});
