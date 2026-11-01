import * as Crypto from 'expo-crypto';
import { ReactNode, useEffect, useMemo, useState } from 'react';
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
import { cameraDisplayName } from '../domain/cameras';
import { OPERATIONAL_POLL_INTERVAL_MS } from '../config/polling';
import { useOperationalPolling } from '../hooks/useOperationalPolling';
import {
  CreateProcessDelivery,
  ProcessAuthorizer,
  ProcessCatalogs,
  ProcessDelivery,
  ProcessLot,
  ProcessOrder,
  ProcessOrderRow,
  ProcessReturnMovement,
  ProcessSublot,
  ProcessSummary,
} from '../domain/frutaProceso';
import {
  annulPackingReturn,
  annulProcessDelivery,
  createPackingReturn,
  createProcessDelivery,
  getProcessCatalogs,
  getProcessLot,
  getProcessSummary,
  listProcessAuthorizers,
  listProcessLots,
  listProcessOrders,
  locatePackingSublot,
} from '../services/frutaProcesoApi';
import { ApiError } from '../services/apiError';
import { colors } from '../theme/colors';

type Props = { auth: AuthSession; baseUrl: string; onLogout: () => void };
type StatusFilter = 'abiertos' | 'completados';
type Section = 'ordenes' | 'entregas' | 'retornos';
/** orden: lote programado · fuera: lote fuera de orden con PIN de supervisor · libre: sin orden (transición). */
type DeliveryMode = 'orden' | 'fuera' | 'libre';
type Action =
  | { type: 'deliver'; lot: ProcessLot }
  | { type: 'return'; lot: ProcessLot; delivery: ProcessDelivery }
  | { type: 'locate'; lot: ProcessLot; sublot: ProcessSublot }
  | { type: 'annul-delivery'; lot: ProcessLot; delivery: ProcessDelivery }
  | { type: 'annul-return'; lot: ProcessLot; movement: ProcessReturnMovement }
  | null;
type ReturnResultDraft = { id: string; typeId: string; name: string; bins: string; kilos: string };
type ReturnOriginDraft = { deliveryId: string; label: string; detail: string; selected: boolean; closes: boolean; primary: boolean };

const EMPTY_SUMMARY: ProcessSummary = {
  temporada: null,
  revision: null,
  lotes_abiertos: 0,
  lotes_completados: 0,
  bins_disponibles: 0,
  bins_entregados: 0,
  entregas_pendientes_retorno: 0,
  bins_retornados: 0,
  kilos_recuperados: 0,
  sublotes_pendientes_ubicacion: 0,
  retornos_registrados: 0,
  desglose_resultados: [],
};
const EMPTY_CATALOGS: ProcessCatalogs = { tipos_resultado: [], camaras: [] };

export function FrutaProcesoScreen({ auth, baseUrl, onLogout }: Props) {
  const canDeliver = auth.usuario.capacidades.puede_entregar_fruta_proceso === true;
  const [summary, setSummary] = useState(EMPTY_SUMMARY);
  const [catalogs, setCatalogs] = useState(EMPTY_CATALOGS);
  const [lots, setLots] = useState<ProcessLot[]>([]);
  const [section, setSection] = useState<Section>('ordenes');
  const [orders, setOrders] = useState<ProcessOrder[]>([]);
  const [orderLine, setOrderLine] = useState('');
  const [deliveryMode, setDeliveryMode] = useState<DeliveryMode>('orden');
  const [deliveryOrderId, setDeliveryOrderId] = useState('');
  const [deliveryVersionId, setDeliveryVersionId] = useState<string | null>(null);
  const [authorizers, setAuthorizers] = useState<ProcessAuthorizer[]>([]);
  const [authorizerId, setAuthorizerId] = useState<number | null>(null);
  const [authorizerPin, setAuthorizerPin] = useState('');
  const [filter, setFilter] = useState<StatusFilter>('abiertos');
  const [search, setSearch] = useState('');
  const [busy, setBusy] = useState(true);
  const [actionBusy, setActionBusy] = useState(false);
  const [error, setError] = useState('');
  const [message, setMessage] = useState('');
  const [expanded, setExpanded] = useState<string | null>(null);
  const [action, setAction] = useState<Action>(null);
  const [operationId, setOperationId] = useState('');
  const [quantity, setQuantity] = useState('');
  const [sentKilos, setSentKilos] = useState('');
  const [line, setLine] = useState('');
  const [shift, setShift] = useState<'A' | 'B' | ''>('');
  const [orderNumber, setOrderNumber] = useState('');
  const [observation, setObservation] = useState('');
  const [annulReason, setAnnulReason] = useState('');
  const [returnResults, setReturnResults] = useState<ReturnResultDraft[]>([]);
  const [returnOrigins, setReturnOrigins] = useState<ReturnOriginDraft[]>([]);
  const [cameraId, setCameraId] = useState('');

  useEffect(() => {
    void load();
  }, [filter, section, orderLine]);

  useEffect(() => {
    void loadCatalogs();
  }, [auth.token, baseUrl]);

  useOperationalPolling(
    () => load(true),
    { intervalMs: OPERATIONAL_POLL_INTERVAL_MS },
  );

  const visibleLots = useMemo(() => {
    const needle = search.trim().toLocaleLowerCase('es-CL');
    if (!needle) return lots;
    return lots.filter((lot) => [
      lot.numero_lote,
      lot.cliente?.nombre,
      lot.camara?.codigo,
      lot.camara?.nombre,
      lot.producto.csg,
      lot.producto.predio,
      ...lot.entregas.map((delivery) => delivery.numero_orden),
    ].some((value) => String(value ?? '').toLocaleLowerCase('es-CL').includes(needle)));
  }, [lots, search]);

  const returnEntries = useMemo(() => visibleLots.flatMap((lot) => lot.entregas
    .filter((delivery) => !delivery.anulado)
    .map((delivery) => ({ lot, delivery }))), [visibleLots]);

  async function load(silent = false) {
    if (!silent) { setBusy(true); setError(''); setMessage(''); }
    if (section === 'ordenes') {
      try { setOrders(await listProcessOrders(baseUrl, auth.token, orderLine)); }
      catch (reason) { if (!silent) setError(errorMessage(reason)); else throw reason; }
      finally { if (!silent) setBusy(false); }
      return;
    }
    try {
      let nextSummary: ProcessSummary;
      let nextLots: ProcessLot[];
      if (silent) {
        nextSummary = await getProcessSummary(baseUrl, auth.token);
        if (nextSummary.revision && nextSummary.revision === summary.revision) {
          setSummary(nextSummary);
          return;
        }
        nextLots = await listProcessLots(baseUrl, auth.token, '', section === 'retornos' ? '' : filter);
      } else {
        [nextSummary, nextLots] = await Promise.all([
          getProcessSummary(baseUrl, auth.token),
          listProcessLots(baseUrl, auth.token, '', section === 'retornos' ? '' : filter),
        ]);
      }
      setSummary(nextSummary); setLots(nextLots);
    } catch (reason) {
      if (!silent) setError(errorMessage(reason));
      else throw reason;
    }
    finally { if (!silent) setBusy(false); }
  }

  async function loadCatalogs() {
    try { setCatalogs(await getProcessCatalogs(baseUrl, auth.token)); }
    catch (reason) { setError(errorMessage(reason)); }
  }

  function newResult(): ReturnResultDraft {
    return { id: Crypto.randomUUID(), typeId: '', name: '', bins: '', kilos: '' };
  }
  function openDelivery(lot: ProcessLot, orderId?: string) {
    const programmed = lot.ordenes.find((order) => order.id === orderId) ?? lot.ordenes[0];
    setAction({ type: 'deliver', lot }); setOperationId(Crypto.randomUUID());
    setDeliveryMode(programmed ? 'orden' : 'fuera');
    setDeliveryOrderId(programmed?.id ?? ''); setDeliveryVersionId(programmed?.version_id ?? null);
    setAuthorizerId(null); setAuthorizerPin('');
    setQuantity(''); setSentKilos(''); setLine(''); setShift(''); setOrderNumber(''); setObservation(''); setError('');
    if (!programmed) void loadOutOfOrderOptions();
  }
  async function openDeliveryFromOrder(order: ProcessOrder, row: ProcessOrderRow) {
    setError('');
    try {
      const lot = lots.find((item) => item.id === row.lote_materia_prima_id) ?? await getProcessLot(baseUrl, auth.token, row.lote_materia_prima_id);
      openDelivery(lot, order.id);
    } catch (reason) { setError(errorMessage(reason)); }
  }
  async function loadOutOfOrderOptions() {
    try {
      const [openOrders, people] = await Promise.all([
        listProcessOrders(baseUrl, auth.token),
        listProcessAuthorizers(baseUrl, auth.token),
      ]);
      setOrders(openOrders); setAuthorizers(people);
    } catch (reason) { setError(errorMessage(reason)); }
  }
  function chooseDeliveryMode(mode: DeliveryMode) {
    setDeliveryMode(mode); setError('');
    if (mode === 'orden' && action?.type === 'deliver') {
      const first = action.lot.ordenes[0];
      setDeliveryOrderId(first?.id ?? ''); setDeliveryVersionId(first?.version_id ?? null);
    } else {
      setDeliveryOrderId(''); setDeliveryVersionId(null);
    }
    if (mode === 'fuera') void loadOutOfOrderOptions();
  }
  function chooseDeliveryOrder(orderId: string, versionId: string | null) {
    setDeliveryOrderId(orderId); setDeliveryVersionId(versionId);
  }
  function openReturn(lot: ProcessLot, delivery: ProcessDelivery) {
    setAction({ type: 'return', lot, delivery }); setOperationId(Crypto.randomUUID());
    setReturnResults([newResult()]);
    setReturnOrigins(lots.flatMap((originLot) => originLot.entregas
      .filter((origin) => !origin.anulado && origin.retorno.puede_registrar)
      .map((origin) => ({
        deliveryId: origin.id,
        label: `${originLot.numero_lote} · ${origin.numero_orden}`,
        detail: `${origin.linea_proceso} · turno ${origin.turno} · ${origin.cantidad_envases} bins`,
        selected: origin.id === delivery.id,
        closes: false,
        primary: origin.id === delivery.id,
      }))));
    setObservation(''); setError('');
  }
  function openLocate(lot: ProcessLot, sublot: ProcessSublot) {
    setAction({ type: 'locate', lot, sublot }); setOperationId(Crypto.randomUUID());
    setCameraId(''); setObservation(''); setError('');
  }
  function openAnnulDelivery(lot: ProcessLot, delivery: ProcessDelivery) {
    setAction({ type: 'annul-delivery', lot, delivery }); setOperationId(Crypto.randomUUID()); setAnnulReason(''); setError('');
  }
  function openAnnulReturn(lot: ProcessLot, movement: ProcessReturnMovement) {
    setAction({ type: 'annul-return', lot, movement }); setOperationId(Crypto.randomUUID()); setAnnulReason(''); setError('');
  }
  function closeAction() { if (!actionBusy) { setAction(null); setError(''); } }
  function replaceLot(updated: ProcessLot) {
    setLots((current) => current.map((lot) => lot.id === updated.id ? updated : lot));
  }
  function updateResult(id: string, change: Partial<ReturnResultDraft>) {
    setReturnResults((current) => current.map((item) => item.id === id ? { ...item, ...change } : item));
  }
  function toggleReturnOrigin(id: string) {
    setReturnOrigins((current) => current.map((origin) => origin.deliveryId !== id || origin.primary
      ? origin
      : { ...origin, selected: !origin.selected, closes: origin.selected ? false : origin.closes }));
  }
  function toggleReturnOriginClose(id: string) {
    setReturnOrigins((current) => current.map((origin) => origin.deliveryId === id && origin.selected
      ? { ...origin, closes: !origin.closes }
      : origin));
  }

  async function submitAction() {
    if (!action) return;
    setError(''); setMessage('');
    if (action.type === 'deliver') {
      const numericQuantity = Number(quantity);
      const numericKilos = sentKilos.trim() ? Number(sentKilos) : null;
      const programmed = deliveryMode === 'orden' ? action.lot.ordenes.find((order) => order.id === deliveryOrderId) : undefined;
      const maximum = programmed ? Math.min(action.lot.progreso.disponibles, programmed.bins_pendientes) : action.lot.progreso.disponibles;
      if (!Number.isInteger(numericQuantity) || numericQuantity < 1 || numericQuantity > maximum) {
        setError(`Ingresa entre 1 y ${maximum} bins.`); return;
      }
      if (numericKilos !== null && (!Number.isFinite(numericKilos) || numericKilos <= 0)) {
        setError('Los kilos enviados deben ser mayores que cero.'); return;
      }
      if (!shift) { setError('Elige el turno.'); return; }
      const input: CreateProcessDelivery = {
        operacion_id: operationId,
        camara_origen_id: action.lot.camara?.id ?? null,
        cantidad_envases: numericQuantity,
        kilos_enviados: numericKilos,
        turno: shift,
        observacion: observation.trim() || null,
      };
      if (deliveryMode === 'libre') {
        if (!line.trim() || !orderNumber.trim()) { setError('Completa línea y número de orden.'); return; }
        input.linea_proceso = line.trim(); input.numero_orden = orderNumber.trim();
      } else {
        if (!deliveryOrderId) { setError('Elige la orden de proceso.'); return; }
        input.orden_proceso_mp_id = deliveryOrderId; input.version_orden_proceso_mp_id = deliveryVersionId;
        if (deliveryMode === 'fuera') {
          if (authorizerId === null || !/^\d{4}$/.test(authorizerPin)) { setError('Un supervisor debe elegir su nombre e ingresar su PIN de 4 dígitos.'); return; }
          input.autorizacion = { user_id: authorizerId, pin: authorizerPin };
        }
      }
      setActionBusy(true);
      try {
        const updated = await createProcessDelivery(baseUrl, auth.token, action.lot.id, input);
        replaceLot(updated); setAction(null); setMessage(`Viaje registrado: ${numericQuantity} bins.`);
        setSummary(await getProcessSummary(baseUrl, auth.token));
        if (section === 'ordenes') await load(true);
      } catch (reason) {
        setError(errorMessage(reason));
        // La orden cambió de versión: se refresca para entregar contra lo vigente.
        if (reason instanceof ApiError && reason.status === 409) {
          try {
            const fresh = await getProcessLot(baseUrl, auth.token, action.lot.id);
            replaceLot(fresh); setAction({ type: 'deliver', lot: fresh });
            const current = fresh.ordenes.find((order) => order.id === deliveryOrderId);
            if (current) setDeliveryVersionId(current.version_id);
          } catch { /* el mensaje del conflicto ya está visible */ }
        }
      }
      finally { setActionBusy(false); setAuthorizerPin(''); }
      return;
    }
    if (action.type === 'return') {
      const other = catalogs.tipos_resultado.find((type) => type.codigo === 'otro');
      const invalid = returnResults.some((item) => !item.typeId || !Number.isInteger(Number(item.bins)) || Number(item.bins) < 1 || (item.typeId === other?.id && !item.name.trim()));
      if (!returnResults.length || invalid) { setError('Completa tipo, bins y el nombre cuando el resultado sea Otro.'); return; }
      const selectedOrigins = returnOrigins.filter((origin) => origin.selected);
      if (!selectedOrigins.length || !selectedOrigins.some((origin) => origin.deliveryId === action.delivery.id)) { setError('El retorno debe conservar el viaje principal y al menos un origen.'); return; }
      setActionBusy(true);
      try {
        await createPackingReturn(baseUrl, auth.token, action.delivery.id, {
          operacion_id: operationId,
          entregas: selectedOrigins.map((origin) => ({ entrega_fruta_proceso_id: origin.deliveryId, cierra_entrega: origin.closes })),
          observacion: observation.trim() || null,
          resultados: returnResults.map((item) => ({
            tipo_resultado_packing_id: item.typeId,
            nombre_resultado: item.name.trim() || null,
            cantidad_bins: Number(item.bins),
            kilos_netos: item.kilos.trim() ? Number(item.kilos) : null,
          })),
        });
        setAction(null); setMessage('Retorno multiorigen registrado; sublotes pendientes de ubicación.');
        await load();
      } catch (reason) { setError(errorMessage(reason)); }
      finally { setActionBusy(false); }
      return;
    }
    if (action.type === 'locate') {
      if (!cameraId) { setError('Selecciona una cámara de materia prima.'); return; }
      setActionBusy(true);
      try {
        const updated = await locatePackingSublot(baseUrl, auth.token, action.sublot.id, operationId, cameraId, observation.trim() || null);
        replaceLot(updated); setAction(null); setMessage(`${action.sublot.numero_sublote} ubicado en cámara.`);
        setSummary(await getProcessSummary(baseUrl, auth.token));
      } catch (reason) { setError(errorMessage(reason)); }
      finally { setActionBusy(false); }
      return;
    }
    if (annulReason.trim().length < 5) { setError('Ingresa un motivo de al menos 5 caracteres.'); return; }
    setActionBusy(true);
    try {
      const updated = action.type === 'annul-delivery'
        ? await annulProcessDelivery(baseUrl, auth.token, action.delivery.id, operationId, annulReason.trim())
        : await annulPackingReturn(baseUrl, auth.token, action.movement.id, operationId, annulReason.trim());
      replaceLot(updated); setAction(null); setMessage(action.type === 'annul-delivery' ? 'Entrega anulada y saldo restituido.' : 'Retorno anulado con trazabilidad.');
      setSummary(await getProcessSummary(baseUrl, auth.token));
    } catch (reason) { setError(errorMessage(reason)); }
    finally { setActionBusy(false); }
  }

  return (
    <View style={styles.screen}>
      <View style={styles.header}>
        <View><Text style={styles.eyebrow}>MATERIA PRIMA · CÁMARA ↔ PACKING</Text><Text style={styles.title}>Fruta a proceso</Text><Text style={styles.subtitle}>{summary.temporada ? `${summary.temporada.nombre} · circuito trazable` : 'Sin temporada activa'}</Text></View>
        <View style={styles.headerActions}><Pressable disabled={busy} onPress={() => void Promise.all([loadCatalogs(), load()])} style={styles.secondary}><Text style={styles.secondaryText}>↻ Actualizar</Text></Pressable><Pressable onPress={onLogout} style={styles.secondary}><Text style={styles.secondaryText}>Salir</Text></Pressable></View>
      </View>
      <ScrollView contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled">
        <View style={styles.sectionTabs}><FilterButton active={section === 'ordenes'} label="Órdenes" onPress={() => setSection('ordenes')} /><FilterButton active={section === 'entregas'} label="Entregas" onPress={() => setSection('entregas')} /><FilterButton active={section === 'retornos'} label="Retornos" onPress={() => setSection('retornos')} /></View>
        {section === 'ordenes' ? <OrdersPanel busy={busy} canDeliver={canDeliver} lines={catalogs.lineas ?? []} onDeliver={(order, row) => void openDeliveryFromOrder(order, row)} onLine={setOrderLine} orders={orders} selectedLine={orderLine} /> : null}
        {section !== 'ordenes' ? <><View style={styles.metrics}>
          {section === 'entregas' ? <><Metric label="Lotes abiertos" value={summary.lotes_abiertos} /><Metric label="Bins disponibles" value={summary.bins_disponibles} accent /><Metric label="Bins entregados" value={summary.bins_entregados} /><Metric label="Completados" value={summary.lotes_completados} /></> : <><Metric label="Viajes por retornar" value={summary.entregas_pendientes_retorno} /><Metric label="Bins retornados" value={summary.bins_retornados} accent /><Metric label="Kilos recuperados" value={summary.kilos_recuperados} /><Metric label="Por ubicar" value={summary.sublotes_pendientes_ubicacion} /></>}
        </View>
        <View style={styles.filters}>
          {section === 'entregas' ? <View style={styles.tabs}><FilterButton active={filter === 'abiertos'} label="Abiertos" onPress={() => setFilter('abiertos')} /><FilterButton active={filter === 'completados'} label="Completados" onPress={() => setFilter('completados')} /></View> : null}
          <TextInput onChangeText={setSearch} placeholder="Buscar lote, cámara, CSG u orden" placeholderTextColor={colors.muted} style={styles.search} value={search} />
        </View></> : null}
        {message ? <Text style={styles.message}>{message}</Text> : null}
        {error && !action ? <Text style={styles.error}>{error}</Text> : null}
        {busy && section !== 'ordenes' ? <ActivityIndicator color={colors.cyan} size="large" style={styles.loader} /> : null}
        {!busy && section === 'entregas' && !visibleLots.length ? <Text style={styles.empty}>No hay lotes de bins en este estado.</Text> : null}
        {!busy && section === 'retornos' && !returnEntries.length ? <Text style={styles.empty}>No existen viajes entregados a Packing.</Text> : null}
        <View style={styles.lots}>
          {section === 'entregas' ? visibleLots.map((lot) => <LotCard canDeliver={canDeliver} expanded={expanded === lot.id} key={lot.id} lot={lot} onAnnul={(delivery) => openAnnulDelivery(lot, delivery)} onDeliver={() => openDelivery(lot)} onExpand={() => setExpanded(expanded === lot.id ? null : lot.id)} onReturn={(delivery) => openReturn(lot, delivery)} />) : returnEntries.map(({ lot, delivery }) => <ReturnCard delivery={delivery} key={delivery.id} lot={lot} onAnnul={(movement) => openAnnulReturn(lot, movement)} onLocate={(sublot) => openLocate(lot, sublot)} onReturn={() => openReturn(lot, delivery)} />)}
        </View>
      </ScrollView>
      <Modal animationType="slide" onRequestClose={closeAction} transparent visible={action !== null}>
        <View style={styles.modalBackdrop}><View style={styles.modalCard}><ScrollView contentContainerStyle={styles.modalContent} keyboardShouldPersistTaps="handled">
          {action?.type === 'deliver' ? <DeliveryForm authorizerId={authorizerId} authorizerPin={authorizerPin} authorizers={authorizers} available={action.lot.progreso.disponibles} kilos={sentKilos} line={line} lot={action.lot} mode={deliveryMode} observation={observation} onAuthorizer={setAuthorizerId} onAuthorizerPin={setAuthorizerPin} onKilos={setSentKilos} onLine={setLine} onMode={chooseDeliveryMode} onObservation={setObservation} onOrder={setOrderNumber} onOrderChoice={chooseDeliveryOrder} onQuantity={setQuantity} onShift={setShift} openOrders={orders} order={orderNumber} orderId={deliveryOrderId} quantity={quantity} shift={shift} /> : null}
          {action?.type === 'return' ? <ReturnForm catalogs={catalogs} delivery={action.delivery} onAdd={() => setReturnResults((current) => [...current, newResult()])} onObservation={setObservation} onRemove={(id) => setReturnResults((current) => current.length > 1 ? current.filter((item) => item.id !== id) : current)} onToggleOrigin={toggleReturnOrigin} onToggleOriginClose={toggleReturnOriginClose} onUpdate={updateResult} observation={observation} origins={returnOrigins} results={returnResults} /> : null}
          {action?.type === 'locate' ? <LocateForm cameraId={cameraId} catalogs={catalogs} observation={observation} onCamera={setCameraId} onObservation={setObservation} sublot={action.sublot} /> : null}
          {action?.type === 'annul-delivery' || action?.type === 'annul-return' ? <><Text style={styles.eyebrow}>CORRECCIÓN TRAZABLE</Text><Text style={styles.modalTitle}>Anular {action.type === 'annul-delivery' ? 'entrega' : 'retorno'}</Text><Field label="Motivo obligatorio *"><TextInput multiline onChangeText={setAnnulReason} placeholder="Explica la corrección" placeholderTextColor={colors.muted} style={[styles.input, styles.textarea]} value={annulReason} /></Field></> : null}
          {error ? <Text style={styles.error}>{error}</Text> : null}
          <View style={styles.modalActions}><Pressable disabled={actionBusy} onPress={closeAction} style={styles.secondary}><Text style={styles.secondaryText}>Cancelar</Text></Pressable><Pressable disabled={actionBusy} onPress={() => void submitAction()} style={[styles.primary, actionBusy && styles.disabled]}>{actionBusy ? <ActivityIndicator color={colors.accentText} /> : <Text style={styles.primaryText}>{action?.type === 'deliver' ? 'Confirmar viaje' : action?.type === 'return' ? 'Crear sublotes' : action?.type === 'locate' ? 'Confirmar ubicación' : 'Anular'}</Text>}</Pressable></View>
        </ScrollView></View></View>
      </Modal>
    </View>
  );
}

function LotCard({ lot, canDeliver, expanded, onDeliver, onExpand, onAnnul, onReturn }: { lot: ProcessLot; canDeliver: boolean; expanded: boolean; onDeliver: () => void; onExpand: () => void; onAnnul: (delivery: ProcessDelivery) => void; onReturn: (delivery: ProcessDelivery) => void }) {
  return <View style={styles.lotCard}><View style={styles.lotHeading}><View><Text style={styles.lotNumber}>{lot.numero_lote}</Text><Text style={styles.lotCopy}>{lot.cliente?.nombre} · {cameraDisplayName(lot.camara)}</Text></View><Text style={styles.badge}>{stateLabel(lot.estado)}</Text></View><Text style={styles.product}>{[lot.producto.especie, lot.producto.variedad, lot.producto.calibre].filter(Boolean).join(' · ')}</Text><Text style={styles.origin}>CSG {lot.producto.csg} · {lot.producto.predio}</Text><View style={styles.progressLabels}><Text style={styles.progressStrong}>{lot.progreso.entregados}/{lot.progreso.total} entregados</Text><Text style={styles.progressCopy}>{lot.progreso.disponibles} disponibles</Text></View><View style={styles.progressTrack}><View style={[styles.progressFill, { width: `${Math.min(100, lot.progreso.porcentaje)}%` }]} /></View>{canDeliver && lot.progreso.disponibles > 0 ? <Pressable onPress={onDeliver} style={styles.primary}><Text style={styles.primaryText}>+ Registrar viaje físico</Text></Pressable> : null}<Pressable onPress={onExpand} style={styles.historyToggle}><Text style={styles.historyToggleText}>{expanded ? 'Ocultar movimientos' : `Ver movimientos (${lot.entregas.length})`}</Text></Pressable>{expanded ? <View style={styles.history}>{lot.entregas.length ? lot.entregas.map((delivery) => <DeliveryRow delivery={delivery} key={delivery.id} onAnnul={() => onAnnul(delivery)} onReturn={() => onReturn(delivery)} />) : <Text style={styles.historyEmpty}>Sin viajes registrados.</Text>}</View> : null}</View>;
}
function ReturnCard({ lot, delivery, onReturn, onLocate, onAnnul }: { lot: ProcessLot; delivery: ProcessDelivery; onReturn: () => void; onLocate: (sublot: ProcessSublot) => void; onAnnul: (movement: ProcessReturnMovement) => void }) {
  return <View style={styles.lotCard}><View style={styles.lotHeading}><View><Text style={styles.lotNumber}>{lot.numero_lote}</Text><Text style={styles.lotCopy}>{delivery.numero_orden} · {delivery.linea_proceso} · turno {delivery.turno}</Text></View><Text style={styles.badge}>{returnLabel(delivery.retorno.estado)}</Text></View><View style={styles.returnMetrics}><Text style={styles.progressStrong}>Enviado: {delivery.cantidad_envases} bins · {formatKilos(delivery.kilos_enviados)}</Text><Text style={styles.progressCopy}>Retornado: {delivery.retorno.bins_retornados} bins · {formatKilos(delivery.retorno.kilos_recuperados)}</Text>{delivery.retorno.merma_kilos !== null ? <Text style={styles.progressCopy}>Merma: {formatKilos(delivery.retorno.merma_kilos)}</Text> : null}</View>{delivery.retorno.puede_registrar ? <Pressable onPress={onReturn} style={styles.primary}><Text style={styles.primaryText}>+ Registrar retorno</Text></Pressable> : null}<View style={styles.history}>{delivery.retorno.movimientos.length ? delivery.retorno.movimientos.map((movement) => <ReturnMovement key={movement.id} movement={movement} onAnnul={() => onAnnul(movement)} onLocate={onLocate} />) : <Text style={styles.historyEmpty}>Packing todavía no registra retornos.</Text>}</View></View>;
}
function DeliveryRow({ delivery, onAnnul, onReturn }: { delivery: ProcessDelivery; onAnnul: () => void; onReturn: () => void }) {
  return <View style={[styles.delivery, delivery.anulado && styles.deliveryVoid]}><View style={styles.deliveryText}><Text style={styles.deliveryQuantity}>{delivery.cantidad_envases} bins · {formatKilos(delivery.kilos_enviados)}</Text><Text style={styles.deliveryDestination}>{delivery.linea_proceso} · turno {delivery.turno} · orden {delivery.numero_orden}{delivery.orden_proceso ? ` · ${delivery.orden_proceso.codigo}${delivery.orden_proceso.fuera_de_orden ? ` · fuera de orden (${delivery.orden_proceso.autorizado_por ?? 'autorizado'})` : ''}` : ''}</Text><Text style={styles.deliveryMeta}>{delivery.entregado_por?.nombre} · {formatDate(delivery.entregado_at)} · {returnLabel(delivery.retorno.estado)}</Text></View><View style={styles.rowActions}>{delivery.retorno.puede_registrar ? <Pressable onPress={onReturn} style={styles.returnButton}><Text style={styles.returnButtonText}>Retorno</Text></Pressable> : null}{delivery.puede_anular ? <Pressable onPress={onAnnul} style={styles.annul}><Text style={styles.annulText}>Anular</Text></Pressable> : null}</View></View>;
}
function ReturnMovement({ movement, onLocate, onAnnul }: { movement: ProcessReturnMovement; onLocate: (sublot: ProcessSublot) => void; onAnnul: () => void }) {
  return <View style={[styles.returnMovement, movement.anulado && styles.deliveryVoid]}><View style={styles.lotHeading}><View><Text style={styles.deliveryQuantity}>{movement.numero} · {movement.cierra_entrega ? 'Cierre del viaje' : 'Retorno parcial'}</Text><Text style={styles.deliveryMeta}>{movement.registrado_por?.nombre} · {formatDate(movement.registrado_at)}</Text></View>{movement.puede_anular ? <Pressable onPress={onAnnul} style={styles.annul}><Text style={styles.annulText}>Anular</Text></Pressable> : null}</View>{movement.origenes?.length ? <Text style={styles.deliveryMeta}>Orígenes: {movement.origenes.map((origin) => `${origin.numero_lote ?? 'Lote'} · ${origin.numero_orden}${origin.cierra_entrega ? ' (cerrado)' : ' (abierto)'}`).join(' · ')}</Text> : null}{movement.resultados.map((result) => <View key={result.id} style={styles.resultRow}><View style={styles.deliveryText}><Text style={styles.deliveryDestination}>{result.numero_sublote} · {result.nombre_resultado}</Text><Text style={styles.deliveryMeta}>{result.cantidad_bins} bins · {formatKilos(result.kilos_netos)} · {result.camara ? cameraDisplayName(result.camara) : stateLabel(result.estado)}</Text></View>{result.puede_ubicar ? <Pressable onPress={() => onLocate(result)} style={styles.returnButton}><Text style={styles.returnButtonText}>Ubicar</Text></Pressable> : null}</View>)}</View>;
}
type DeliveryFormProps = {
  lot: ProcessLot; available: number; mode: DeliveryMode; orderId: string; openOrders: ProcessOrder[];
  authorizers: ProcessAuthorizer[]; authorizerId: number | null; authorizerPin: string;
  quantity: string; kilos: string; line: string; shift: 'A' | 'B' | ''; order: string; observation: string;
  onMode: (mode: DeliveryMode) => void; onOrderChoice: (orderId: string, versionId: string | null) => void;
  onAuthorizer: (id: number) => void; onAuthorizerPin: (value: string) => void;
  onQuantity: (value: string) => void; onKilos: (value: string) => void; onLine: (value: string) => void;
  onShift: (value: 'A' | 'B') => void; onOrder: (value: string) => void; onObservation: (value: string) => void;
};
function DeliveryForm({ lot, available, mode, orderId, openOrders, authorizers, authorizerId, authorizerPin, quantity, kilos, line, shift, order, observation, onMode, onOrderChoice, onAuthorizer, onAuthorizerPin, onQuantity, onKilos, onLine, onShift, onOrder, onObservation }: DeliveryFormProps) {
  const programmed = lot.ordenes.find((item) => item.id === orderId);
  const limit = mode === 'orden' && programmed ? Math.min(available, programmed.bins_pendientes) : available;
  return <><Text style={styles.eyebrow}>VIAJE FÍSICO A PACKING</Text><Text style={styles.modalTitle}>Registrar entrega · {lot.numero_lote}</Text><Text style={styles.modalCopy}>{available} bins en cámara{mode === 'orden' && programmed ? ` · ${programmed.bins_pendientes} programados en ${programmed.codigo}` : ''}.</Text>
    <View style={styles.shiftRow}>{lot.ordenes.length ? <FilterButton active={mode === 'orden'} label="En orden" onPress={() => onMode('orden')} /> : null}<FilterButton active={mode === 'fuera'} label="Fuera de orden" onPress={() => onMode('fuera')} /><FilterButton active={mode === 'libre'} label="Sin orden" onPress={() => onMode('libre')} /></View>
    {mode === 'orden' ? <><Text style={styles.fieldLabel}>Orden de proceso *</Text><View style={styles.cameraOptions}>{lot.ordenes.map((item) => <Pressable key={item.id} onPress={() => onOrderChoice(item.id, item.version_id)} style={[styles.cameraOption, orderId === item.id && styles.cameraOptionActive]}><Text style={styles.deliveryDestination}>{item.codigo} · v{item.numero_version} · {item.linea?.nombre ?? 'Sin línea'}</Text><Text style={styles.deliveryMeta}>{item.fecha} · {item.bins_pendientes} bins pendientes de este lote</Text></Pressable>)}</View></> : null}
    {mode === 'fuera' ? <><Text style={styles.modalCopy}>El lote no está programado. Entra a la orden como nueva versión con el PIN de un supervisor de frío.</Text><Text style={styles.fieldLabel}>Orden de la línea *</Text><View style={styles.cameraOptions}>{openOrders.map((item) => <Pressable key={item.id} onPress={() => onOrderChoice(item.id, item.version_vigente?.id ?? null)} style={[styles.cameraOption, orderId === item.id && styles.cameraOptionActive]}><Text style={styles.deliveryDestination}>{item.codigo} · v{item.numero_version_vigente} · {item.linea?.nombre ?? 'Sin línea'}</Text><Text style={styles.deliveryMeta}>{item.fecha} · turno {item.turno_inicio} · {item.progreso.bins_pendientes} bins pendientes</Text></Pressable>)}{!openOrders.length ? <Text style={styles.historyEmpty}>No hay órdenes abiertas.</Text> : null}</View><Text style={styles.fieldLabel}>Supervisor que autoriza *</Text><View style={styles.shiftRow}>{authorizers.map((person) => <FilterButton active={authorizerId === person.id} key={person.id} label={person.nombre} onPress={() => onAuthorizer(person.id)} />)}</View>{!authorizers.length ? <Text style={styles.historyEmpty}>No hay supervisores con PIN operacional.</Text> : null}<Field label="PIN del supervisor *"><TextInput keyboardType="number-pad" maxLength={4} onChangeText={onAuthorizerPin} placeholder="••••" placeholderTextColor={colors.muted} secureTextEntry style={styles.input} value={authorizerPin} /></Field></> : null}
    {mode === 'libre' ? <><Text style={styles.modalCopy}>Solo mientras la planta no exija orden de proceso.</Text><Field label="Línea de proceso *"><TextInput onChangeText={onLine} placeholder="Ej. Línea 1" placeholderTextColor={colors.muted} style={styles.input} value={line} /></Field><Field label="N° de orden *"><TextInput onChangeText={onOrder} placeholder="Orden de Packing" placeholderTextColor={colors.muted} style={styles.input} value={order} /></Field></> : null}
    <Field label={`Cantidad de bins * (máx. ${limit})`}><TextInput keyboardType="number-pad" onChangeText={onQuantity} placeholder="0" placeholderTextColor={colors.muted} style={styles.input} value={quantity} /></Field><Field label="Kilos enviados"><TextInput keyboardType="decimal-pad" onChangeText={onKilos} placeholder="Opcional" placeholderTextColor={colors.muted} style={styles.input} value={kilos} /></Field><Text style={styles.fieldLabel}>Turno *</Text><View style={styles.shiftRow}><FilterButton active={shift === 'A'} label="A" onPress={() => onShift('A')} /><FilterButton active={shift === 'B'} label="B" onPress={() => onShift('B')} /></View><Field label="Observación"><TextInput multiline onChangeText={onObservation} placeholder="Opcional" placeholderTextColor={colors.muted} style={[styles.input, styles.textarea]} value={observation} /></Field></>;
}
function OrdersPanel({ orders, lines, selectedLine, busy, canDeliver, onLine, onDeliver }: { orders: ProcessOrder[]; lines: { id: string; nombre: string }[]; selectedLine: string; busy: boolean; canDeliver: boolean; onLine: (id: string) => void; onDeliver: (order: ProcessOrder, row: ProcessOrderRow) => void }) {
  return <View style={styles.lots}>
    <ScrollView horizontal showsHorizontalScrollIndicator={false}><View style={styles.shiftRow}><FilterButton active={selectedLine === ''} label="Todas" onPress={() => onLine('')} />{lines.map((line) => <FilterButton active={selectedLine === line.id} key={line.id} label={line.nombre} onPress={() => onLine(line.id)} />)}</View></ScrollView>
    {busy ? <ActivityIndicator color={colors.cyan} size="large" style={styles.loader} /> : null}
    {!busy && !orders.length ? <Text style={styles.empty}>No hay órdenes abiertas para esta línea.</Text> : null}
    {orders.map((order) => {
      const rows = order.version_vigente?.lotes ?? [];
      const next = rows.find((row) => row.bins_pendientes > 0);
      return <View key={order.id} style={styles.lotCard}><View style={styles.lotHeading}><View><Text style={styles.lotNumber}>{order.codigo} · v{order.numero_version_vigente}</Text><Text style={styles.lotCopy}>{order.linea?.nombre} · {order.fecha} · turno {order.turno_inicio}</Text></View><Text style={styles.badge}>{order.estado === 'en_proceso' ? 'En proceso' : 'Programada'}</Text></View><View style={styles.progressLabels}><Text style={styles.progressStrong}>{order.progreso.bins_entregados}/{order.progreso.bins_programados} bins</Text><Text style={styles.progressCopy}>{order.progreso.bins_pendientes} pendientes</Text></View>
        <View style={styles.history}>{rows.map((row) => <View key={row.lote_materia_prima_id} style={[styles.delivery, row === next && styles.nextRow, row.bins_pendientes === 0 && styles.deliveryVoid]}><View style={styles.deliveryText}><Text style={styles.deliveryQuantity}>{row.secuencia}. {row.numero_lote}{row.numero_proceso ? ` · ${row.numero_proceso}` : ''}</Text><Text style={styles.deliveryDestination}>{row.cliente.nombre} · {row.variedad} · {row.camara ? cameraDisplayName(row.camara) : 'sin cámara'}</Text><Text style={styles.deliveryMeta}>{row.bins_entregados}/{row.bins_programados} bins · {row.bins_pendientes} pendientes{row.inicio_estimado ? ` · ${row.inicio_estimado.slice(11, 16)}–${(row.termino_estimado ?? '').slice(11, 16)}` : ''}</Text></View>{canDeliver && row.bins_pendientes > 0 ? <Pressable onPress={() => onDeliver(order, row)} style={styles.returnButton}><Text style={styles.returnButtonText}>{row === next ? 'Entregar' : 'Entregar antes'}</Text></Pressable> : null}</View>)}</View>
      </View>;
    })}
  </View>;
}
function ReturnForm({ delivery, catalogs, results, origins, observation, onAdd, onRemove, onUpdate, onToggleOrigin, onToggleOriginClose, onObservation }: { delivery: ProcessDelivery; catalogs: ProcessCatalogs; results: ReturnResultDraft[]; origins: ReturnOriginDraft[]; observation: string; onAdd: () => void; onRemove: (id: string) => void; onUpdate: (id: string, change: Partial<ReturnResultDraft>) => void; onToggleOrigin: (id: string) => void; onToggleOriginClose: (id: string) => void; onObservation: (value: string) => void }) {
  return <><Text style={styles.eyebrow}>PACKING → CÁMARA MP</Text><Text style={styles.modalTitle}>Registrar retorno</Text><Text style={styles.modalCopy}>{delivery.cantidad_envases} bins enviados · {formatKilos(delivery.kilos_enviados)}.</Text><Text style={styles.fieldLabel}>Viajes de origen *</Text>{origins.map((origin) => <View key={origin.deliveryId} style={styles.resultDraft}><Pressable onPress={() => onToggleOrigin(origin.deliveryId)} style={[styles.checkRow, origin.selected && styles.checkRowActive]}><Text style={styles.checkMark}>{origin.selected ? '✓' : '○'}</Text><View><Text style={styles.deliveryDestination}>{origin.label}{origin.primary ? ' · principal' : ''}</Text><Text style={styles.deliveryMeta}>{origin.detail}</Text></View></Pressable>{origin.selected ? <Pressable onPress={() => onToggleOriginClose(origin.deliveryId)} style={[styles.checkRow, origin.closes && styles.checkRowActive]}><Text style={styles.checkMark}>{origin.closes ? '✓' : '○'}</Text><View><Text style={styles.deliveryDestination}>Cerrar este viaje</Text><Text style={styles.deliveryMeta}>Packing no devolverá más fruta de este origen.</Text></View></Pressable> : null}</View>)}<Text style={styles.fieldLabel}>Resultados de Packing *</Text>{results.map((result, index) => <View key={result.id} style={styles.resultDraft}><View style={styles.lotHeading}><Text style={styles.deliveryQuantity}>Resultado {index + 1}</Text>{results.length > 1 ? <Pressable onPress={() => onRemove(result.id)}><Text style={styles.annulText}>Quitar</Text></Pressable> : null}</View><Text style={styles.fieldLabel}>Clasificación *</Text><ScrollView horizontal showsHorizontalScrollIndicator={false} style={styles.typeScroller}>{catalogs.tipos_resultado.map((type) => <FilterButton active={result.typeId === type.id} key={type.id} label={type.nombre} onPress={() => onUpdate(result.id, { typeId: type.id })} />)}</ScrollView><Field label="Nombre específico"><TextInput onChangeText={(value) => onUpdate(result.id, { name: value })} placeholder="Obligatorio para Otro" placeholderTextColor={colors.muted} style={styles.input} value={result.name} /></Field><Field label="Cantidad de bins *"><TextInput keyboardType="number-pad" onChangeText={(value) => onUpdate(result.id, { bins: value })} placeholder="0" placeholderTextColor={colors.muted} style={styles.input} value={result.bins} /></Field><Field label="Kilos netos"><TextInput keyboardType="decimal-pad" onChangeText={(value) => onUpdate(result.id, { kilos: value })} placeholder="Opcional" placeholderTextColor={colors.muted} style={styles.input} value={result.kilos} /></Field></View>)}<Pressable onPress={onAdd} style={styles.secondary}><Text style={styles.secondaryText}>+ Agregar resultado</Text></Pressable><Field label="Observación"><TextInput multiline onChangeText={onObservation} placeholder="Opcional" placeholderTextColor={colors.muted} style={[styles.input, styles.textarea]} value={observation} /></Field></>;
}

function LocateForm({ sublot, catalogs, cameraId, observation, onCamera, onObservation }: { sublot: ProcessSublot; catalogs: ProcessCatalogs; cameraId: string; observation: string; onCamera: (id: string) => void; onObservation: (value: string) => void }) {
  return <><Text style={styles.eyebrow}>PENDIENTE DE UBICACIÓN</Text><Text style={styles.modalTitle}>Ubicar {sublot.numero_sublote}</Text><Text style={styles.modalCopy}>{sublot.nombre_resultado} · {sublot.cantidad_bins} bins.</Text><Text style={styles.fieldLabel}>Cámara de materia prima *</Text><View style={styles.cameraOptions}>{catalogs.camaras.map((camera) => <Pressable key={camera.id} onPress={() => onCamera(camera.id)} style={[styles.cameraOption, cameraId === camera.id && styles.cameraOptionActive]}><Text style={styles.deliveryDestination}>{cameraDisplayName(camera)}</Text></Pressable>)}</View><Field label="Observación"><TextInput multiline onChangeText={onObservation} placeholder="Opcional" placeholderTextColor={colors.muted} style={[styles.input, styles.textarea]} value={observation} /></Field></>;
}
function Metric({ label, value, accent = false }: { label: string; value: number; accent?: boolean }) { return <View style={styles.metric}><Text style={styles.metricLabel}>{label}</Text><Text style={[styles.metricValue, accent && styles.metricAccent]}>{value}</Text></View>; }
function FilterButton({ active, label, onPress }: { active: boolean; label: string; onPress: () => void }) { return <Pressable onPress={onPress} style={[styles.filterButton, active && styles.filterActive]}><Text style={[styles.filterText, active && styles.filterTextActive]}>{label}</Text></Pressable>; }
function Field({ label, children }: { label: string; children: ReactNode }) { return <View style={styles.field}><Text style={styles.fieldLabel}>{label}</Text>{children}</View>; }
function stateLabel(value: string) { return value === 'asignado_camara' ? 'Disponible' : value === 'entrega_parcial_proceso' ? 'Parcial' : value === 'entregado_proceso' ? 'Completado' : value.replaceAll('_', ' '); }
function returnLabel(value: ProcessDelivery['retorno']['estado']) { return value === 'pendiente' ? 'Pendiente retorno' : value === 'parcial' ? 'Retorno parcial' : 'Retorno cerrado'; }
function formatDate(value: string) { const date = new Date(value); return Number.isNaN(date.getTime()) ? '—' : date.toLocaleString('es-CL', { dateStyle: 'short', timeStyle: 'short' }); }
function formatKilos(value: number | null) { return value === null ? 'sin kilos' : `${new Intl.NumberFormat('es-CL', { maximumFractionDigits: 3 }).format(value)} kg`; }
function errorMessage(reason: unknown) { return reason instanceof Error ? reason.message : 'No fue posible completar la operación.'; }

const styles = StyleSheet.create({
  screen: { flex: 1, backgroundColor: colors.background }, header: { paddingHorizontal: 16, paddingVertical: 12, borderBottomWidth: 1, borderBottomColor: colors.border, backgroundColor: colors.backgroundDeep, flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 10 },
  eyebrow: { color: colors.amber, fontSize: 13, fontWeight: '900', letterSpacing: 1 }, title: { color: colors.text, fontSize: 25, fontWeight: '900' }, subtitle: { color: colors.muted, fontSize: 13, marginTop: 2 }, headerActions: { flexDirection: 'row', gap: 6 }, content: { padding: 13, paddingBottom: 40 },
  sectionTabs: { flexDirection: 'row', gap: 8, marginBottom: 10 }, metrics: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 }, metric: { minWidth: '47%', flexGrow: 1, borderWidth: 1, borderColor: colors.border, borderRadius: 12, backgroundColor: colors.panel, padding: 12 }, metricLabel: { color: colors.muted, fontSize: 13, fontWeight: '900', textTransform: 'uppercase' }, metricValue: { color: colors.text, fontSize: 24, fontWeight: '900', marginTop: 4 }, metricAccent: { color: colors.amber },
  filters: { marginTop: 11, gap: 8 }, tabs: { flexDirection: 'row', gap: 7 }, filterButton: { minWidth: 70, paddingHorizontal: 14, paddingVertical: 10, borderRadius: 9, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.panel, alignItems: 'center' }, filterActive: { backgroundColor: colors.cyan, borderColor: colors.cyan }, filterText: { color: colors.muted, fontWeight: '900' }, filterTextActive: { color: colors.accentText }, search: { minHeight: 45, borderWidth: 1, borderColor: colors.border, borderRadius: 10, backgroundColor: colors.backgroundDeep, color: colors.text, paddingHorizontal: 12 },
  loader: { marginTop: 35 }, empty: { color: colors.muted, textAlign: 'center', paddingVertical: 45 }, lots: { marginTop: 10, gap: 10 }, lotCard: { borderWidth: 1, borderColor: colors.border, borderRadius: 14, backgroundColor: colors.panel, padding: 13 }, lotHeading: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'flex-start', gap: 8 }, lotNumber: { color: colors.text, fontSize: 20, fontWeight: '900' }, lotCopy: { color: colors.muted, fontSize: 13, marginTop: 3 }, badge: { color: colors.cyan, borderWidth: 1, borderColor: colors.cyanDark, borderRadius: 999, paddingHorizontal: 8, paddingVertical: 5, fontSize: 12, fontWeight: '900', textTransform: 'uppercase' }, product: { color: colors.amber, fontWeight: '900', marginTop: 11 }, origin: { color: colors.muted, fontSize: 13, marginTop: 4 },
  progressLabels: { marginTop: 12, flexDirection: 'row', justifyContent: 'space-between' }, progressStrong: { color: colors.text, fontWeight: '900', fontSize: 14 }, progressCopy: { color: colors.muted, fontSize: 14 }, progressTrack: { height: 9, borderRadius: 999, backgroundColor: colors.backgroundDeep, overflow: 'hidden', marginTop: 7, marginBottom: 11 }, progressFill: { height: '100%', backgroundColor: colors.cyan }, returnMetrics: { borderWidth: 1, borderColor: colors.border, borderRadius: 9, backgroundColor: colors.backgroundDeep, gap: 5, marginVertical: 11, padding: 10 },
  primary: { minHeight: 45, borderRadius: 10, backgroundColor: colors.cyan, alignItems: 'center', justifyContent: 'center', paddingHorizontal: 16 }, primaryText: { color: colors.accentText, fontWeight: '900' }, secondary: { minHeight: 39, borderRadius: 9, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.panel, alignItems: 'center', justifyContent: 'center', paddingHorizontal: 11 }, secondaryText: { color: colors.text, fontWeight: '800', fontSize: 14 }, historyToggle: { alignItems: 'center', paddingTop: 11 }, historyToggleText: { color: colors.muted, fontSize: 14, fontWeight: '800' }, history: { marginTop: 9, paddingTop: 9, borderTopWidth: 1, borderTopColor: colors.border, gap: 7 }, historyEmpty: { color: colors.muted, fontSize: 13 },
  delivery: { flexDirection: 'row', alignItems: 'center', gap: 8, borderWidth: 1, borderColor: colors.border, borderRadius: 9, backgroundColor: colors.backgroundDeep, padding: 9 }, deliveryVoid: { opacity: .5 }, deliveryText: { flex: 1 }, deliveryQuantity: { color: colors.cyan, fontWeight: '900' }, deliveryDestination: { color: colors.text, fontSize: 13, fontWeight: '800', marginTop: 3 }, deliveryMeta: { color: colors.muted, fontSize: 12, marginTop: 3 }, rowActions: { gap: 5 }, annul: { borderWidth: 1, borderColor: colors.red, borderRadius: 7, paddingHorizontal: 8, paddingVertical: 7 }, annulText: { color: colors.red, fontSize: 13, fontWeight: '900' }, returnButton: { borderWidth: 1, borderColor: colors.cyanDark, borderRadius: 7, paddingHorizontal: 8, paddingVertical: 7 }, returnButtonText: { color: colors.cyan, fontSize: 13, fontWeight: '900' },
  nextRow: { borderColor: colors.cyan },
  returnMovement: { borderWidth: 1, borderColor: colors.border, borderRadius: 9, backgroundColor: colors.backgroundDeep, padding: 9, gap: 7 }, resultRow: { flexDirection: 'row', alignItems: 'center', borderTopWidth: 1, borderTopColor: colors.border, paddingTop: 7, gap: 8 },
  modalBackdrop: { flex: 1, justifyContent: 'flex-end', backgroundColor: 'rgba(1,8,12,.82)' }, modalCard: { maxHeight: '94%', borderTopLeftRadius: 20, borderTopRightRadius: 20, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.panel }, modalContent: { padding: 18, paddingBottom: 24 }, modalTitle: { color: colors.text, fontSize: 22, fontWeight: '900', marginTop: 3 }, modalCopy: { color: colors.muted, fontSize: 14, marginTop: 4, marginBottom: 8 }, field: { marginTop: 10, gap: 5 }, fieldLabel: { color: colors.text, fontSize: 14, fontWeight: '800', marginTop: 8 }, input: { minHeight: 44, borderWidth: 1, borderColor: colors.border, borderRadius: 9, backgroundColor: colors.backgroundDeep, color: colors.text, paddingHorizontal: 11 }, textarea: { minHeight: 72, paddingTop: 10, textAlignVertical: 'top' }, shiftRow: { flexDirection: 'row', gap: 8, marginTop: 5 }, modalActions: { flexDirection: 'row', justifyContent: 'flex-end', gap: 8, marginTop: 15, paddingTop: 13, borderTopWidth: 1, borderTopColor: colors.border },
  resultDraft: { borderWidth: 1, borderColor: colors.border, borderRadius: 11, backgroundColor: colors.backgroundDeep, padding: 11, marginTop: 9 }, typeScroller: { marginVertical: 5 }, checkRow: { flexDirection: 'row', alignItems: 'center', gap: 9, borderWidth: 1, borderColor: colors.border, borderRadius: 10, padding: 11, marginTop: 10 }, checkRowActive: { borderColor: colors.cyanDark, backgroundColor: colors.selected }, checkMark: { color: colors.cyan, fontSize: 20, fontWeight: '900' }, cameraOptions: { gap: 7, marginTop: 7 }, cameraOption: { borderWidth: 1, borderColor: colors.border, borderRadius: 9, backgroundColor: colors.backgroundDeep, padding: 11 }, cameraOptionActive: { borderColor: colors.cyan, backgroundColor: colors.selected },
  error: { color: colors.red, marginTop: 10, fontWeight: '800' }, message: { color: colors.cyan, borderWidth: 1, borderColor: colors.cyanDark, borderRadius: 9, backgroundColor: colors.backgroundDeep, padding: 10, marginTop: 10 }, disabled: { opacity: .55 },
});
