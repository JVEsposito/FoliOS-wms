import * as Crypto from 'expo-crypto';
import { useEffect, useMemo, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import {
  ActivityIndicator,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  TextInput,
  useWindowDimensions,
  View,
} from 'react-native';

import { OPERATIONAL_POLL_INTERVAL_MS } from '../config/polling';
import { AuthSession } from '../domain/estiba';
import { useOperationalPolling } from '../hooks/useOperationalPolling';
import { MaterialLabelPrintPanel } from './MaterialLabelPrintPanel';
import {
  MaterialTransformationLot,
  MaterialTransformationOrder,
  MaterialTransformationOrderSummary,
  MaterialTransformationReservation,
  MaterialTransformationState,
} from '../domain/materialTransformation';
import { EstibaApi } from '../services/estibaApi';
import { colors } from '../theme/colors';

type QueueFilter = 'activas' | 'historial' | 'todas';

type DraftConsumption = {
  cantidad: string;
  motivo: string;
};

type Props = {
  api: EstibaApi;
  auth: AuthSession;
  onConnectionFailure: (reason: unknown) => void;
};

const ACTIVE_STATES: MaterialTransformationState[] = [
  'planificada',
  'en_proceso',
  'pendiente_cierre',
];

export function MaterialTransformationOperation({
  api,
  auth,
  onConnectionFailure,
}: Props) {
  const { width } = useWindowDimensions();
  const compact = width < 980;
  const canOperate = auth.usuario.capacidades.puede_operar_transformaciones_materiales === true;
  const canReverse = auth.usuario.capacidades.puede_revertir_transformaciones_materiales === true;
  const [orders, setOrders] = useState<MaterialTransformationOrderSummary[]>([]);
  const [selected, setSelected] = useState<MaterialTransformationOrder | null>(null);
  const [selectedId, setSelectedId] = useState<string | null>(null);
  const [filter, setFilter] = useState<QueueFilter>('activas');
  const [plannedQuantity, setPlannedQuantity] = useState('');
  const [actualQuantity, setActualQuantity] = useState('');
  const [scan, setScan] = useState('');
  const [closeReason, setCloseReason] = useState('');
  const [reverseCandidateId, setReverseCandidateId] = useState<string | null>(null);
  const [reverseReason, setReverseReason] = useState('');
  const [draft, setDraft] = useState<Record<string, DraftConsumption>>({});
  const [busy, setBusy] = useState(true);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [lastSync, setLastSync] = useState<string | null>(null);
  const pollInFlight = useRef(false);
  const operationIds = useRef(new Map<string, string>());

  const filtered = useMemo(() => orders.filter((order) => {
    if (filter === 'activas') return ACTIVE_STATES.includes(order.estado);
    if (filter === 'historial') return order.estado === 'cerrada' || order.estado === 'cancelada';
    return true;
  }), [filter, orders]);
  const selectedSummary = useMemo(
    () => filtered.find((order) => order.id === selectedId) ?? filtered[0] ?? null,
    [filtered, selectedId],
  );
  const openLot = selected?.lotes.find((lot) => lot.estado === 'abierto') ?? null;
  const activeReservations = selected?.reservas.filter(
    (reservation) => reservation.estado === 'activa'
      && Number(reservation.cantidad_pendiente) > 0
      && reservation.folio,
  ) ?? [];
  const closedLots = selected?.lotes.filter((lot) => lot.estado === 'cerrado') ?? [];
  const unitsPerOutputFolio = Number(
    selected?.unidades_por_folio_salida
      ?? selected?.receta_snapshot.salida.unidades_por_folio
      ?? 0,
  );
  const plannedInLots = selected?.lotes
    .filter((lot) => lot.estado !== 'anulado')
    .reduce((sum, lot) => sum + Number(lot.cantidad_planificada_salida), 0) ?? 0;
  const remainingPlannedOutput = Math.max(
    0,
    Number(selected?.cantidad_planificada_salida ?? 0) - plannedInLots,
  );
  const suggestedLotQuantity = unitsPerOutputFolio > 0
    ? Math.min(unitsPerOutputFolio, remainingPlannedOutput)
    : 0;
  const lastLot = [...(selected?.lotes ?? [])]
    .sort((a, b) => b.numero_lote - a.numero_lote)[0] ?? null;

  useEffect(() => {
    void refresh(false);
  }, []);

  useOperationalPolling(
    () => refresh(true),
    { intervalMs: OPERATIONAL_POLL_INTERVAL_MS },
  );

  useEffect(() => {
    if (!selectedSummary) {
      setSelected(null);
      return;
    }
    setSelected((current) => current?.id === selectedSummary.id ? current : null);
    let cancelled = false;
    void api.getMaterialTransformation(auth.token, selectedSummary.id)
      .then((order) => {
        if (!cancelled) {
          setSelected(order);
          setError('');
        }
      })
      .catch((reason) => {
        if (!cancelled) fail(reason, 'No fue posible cargar el detalle de la orden.');
      });

    return () => {
      cancelled = true;
    };
  }, [selectedSummary?.id, selectedSummary?.updated_at]);

  useEffect(() => {
    setDraft({});
    setActualQuantity('');
    setScan('');
    setCloseReason('');
    setReverseCandidateId(null);
    setReverseReason('');
    setPlannedQuantity(suggestedLotQuantity > 0 ? formatInputQuantity(suggestedLotQuantity) : '');
    operationIds.current.clear();
  }, [selected?.id, selected?.version, openLot?.id, suggestedLotQuantity]);

  async function refresh(quiet: boolean) {
    if (pollInFlight.current) return;
    pollInFlight.current = true;
    if (!quiet) setBusy(true);
    try {
      const loaded = await api.listMaterialTransformations(auth.token);
      setOrders(loaded);
      setSelectedId((current) => loaded.some((order) => order.id === current)
        ? current
        : loaded.find((order) => ACTIVE_STATES.includes(order.estado))?.id
          ?? loaded[0]?.id
          ?? null);
      setLastSync(new Date().toLocaleTimeString('es-CL', {
        hour: '2-digit',
        minute: '2-digit',
      }));
      setError('');
    } catch (reason) {
      fail(reason, 'No fue posible cargar las órdenes de transformación.');
    } finally {
      pollInFlight.current = false;
      if (!quiet) setBusy(false);
    }
  }

  function operationId(key: string) {
    const current = operationIds.current.get(key);
    if (current) return current;
    const created = Crypto.randomUUID();
    operationIds.current.set(key, created);
    return created;
  }

  function applyOrder(order: MaterialTransformationOrder, message: string) {
    setOrders((current) => {
      const exists = current.some((candidate) => candidate.id === order.id);
      const summary: MaterialTransformationOrderSummary = {
        ...order,
        reservas_count: order.reservas.length,
        lotes_count: order.lotes.length,
        tiene_salidas: order.lotes.some(
          (lot) => lot.estado === 'cerrado' && lot.salidas.length > 0,
        ),
      };
      return exists
        ? current.map((candidate) => candidate.id === order.id ? summary : candidate)
        : [summary, ...current];
    });
    setSelected(order);
    setSelectedId(order.id);
    setNotice(message);
    setError('');
    operationIds.current.clear();
  }

  async function startOrder() {
    if (!selected || !canOperate) return;
    const key = `start:${selected.id}:${selected.version}`;
    setBusy(true);
    try {
      const order = await api.startMaterialTransformation(auth.token, selected.id, {
        operacion_id: operationId(key),
        version_conocida: selected.version,
      });
      applyOrder(order, 'Orden iniciada. Ya puedes abrir el primer lote parcial.');
    } catch (reason) {
      fail(reason, 'No fue posible iniciar la orden.');
    } finally {
      setBusy(false);
    }
  }

  async function openNewLot() {
    if (!selected || !canOperate) return;
    const quantity = parseQuantity(plannedQuantity);
    if (!quantity) {
      setError('Ingresa una cantidad planificada mayor que cero.');
      return;
    }
    const key = `open:${selected.id}:${selected.version}:${quantity}`;
    setBusy(true);
    try {
      const order = await api.openMaterialTransformationLot(auth.token, selected.id, {
        operacion_id: operationId(key),
        version_conocida: selected.version,
        cantidad_planificada_salida: quantity,
      });
      applyOrder(order, 'Lote abierto. Pistolea cada folio que realmente se consumirá.');
    } catch (reason) {
      fail(reason, 'No fue posible abrir el lote.');
    } finally {
      setBusy(false);
    }
  }

  async function addScannedFolio(reservation?: MaterialTransformationReservation) {
    const normalized = reservation?.folio?.numero_folio ?? scan.trim().toUpperCase();
    if (!normalized) return;
    try {
      const lookup = await api.lookupFolio(auth.token, normalized);
      if (lookup.existe && lookup.mensaje_vencimiento) {
        setError(lookup.mensaje_vencimiento);
        return;
      }
    } catch (reason) {
      fail(reason, 'No fue posible verificar el vencimiento del folio.');
      return;
    }
    const matched = reservation ?? activeReservations.find(
      (candidate) => candidate.folio?.numero_folio.toUpperCase() === normalized,
    );

    if (!matched?.folio) {
      setError('El folio no pertenece a una reserva activa de esta orden.');
      return;
    }
    if (!matched.folio.ubicacion) {
      setError('El folio debe ubicarse nuevamente antes de registrarlo como consumo.');
      return;
    }

    const component = selected?.receta_snapshot.componentes.find(
      (candidate) => candidate.item_id === matched.item_material_id,
    );
    const base = Number(selected?.receta_snapshot.salida.cantidad_base ?? 0);
    const planned = Number(openLot?.cantidad_planificada_salida ?? 0);
    const required = component && base > 0
      ? Number(component.cantidad_estandar) * planned / base
      : Number(matched.cantidad_pendiente);
    const alreadySelected = activeReservations
      .filter((candidate) => candidate.item_material_id === matched.item_material_id)
      .reduce((sum, candidate) => sum + Number(draft[candidate.folio?.id ?? '']?.cantidad ?? 0), 0);
    const suggestion = Math.min(
      Number(matched.cantidad_pendiente),
      Math.max(0, required - alreadySelected) || Number(matched.cantidad_pendiente),
    );

    setDraft((current) => ({
      ...current,
      [matched.folio!.id]: current[matched.folio!.id] ?? {
        cantidad: formatInputQuantity(suggestion),
        motivo: '',
      },
    }));
    setScan('');
    setError('');
  }

  function updateDraft(folioId: string, patch: Partial<DraftConsumption>) {
    setDraft((current) => ({
      ...current,
      [folioId]: {
        cantidad: current[folioId]?.cantidad ?? '',
        motivo: current[folioId]?.motivo ?? '',
        ...patch,
      },
    }));
  }

  async function closeLot() {
    if (!selected || !openLot || !canOperate) return;
    const output = parseQuantity(actualQuantity);
    const consumptions = activeReservations.flatMap((reservation) => {
      if (!reservation.folio) return [];
      const line = draft[reservation.folio.id];
      const quantity = parseQuantity(line?.cantidad ?? '');
      if (!quantity) return [];
      return [{
        folio_id: reservation.folio.id,
        cantidad: quantity,
        ...(line.motivo.trim() ? { motivo_desviacion_fifo: line.motivo.trim() } : {}),
      }];
    });

    if (!output) {
      setError('Ingresa la cantidad real producida del lote.');
      return;
    }
    if (!consumptions.length) {
      setError('Pistolea y registra al menos un folio consumido.');
      return;
    }

    const key = `close-lot:${openLot.id}:${selected.version}:${JSON.stringify({
      output,
      consumptions,
    })}`;
    setBusy(true);
    try {
      const order = await api.closeMaterialTransformationLot(auth.token, openLot.id, {
        operacion_id: operationId(key),
        version_conocida: selected.version,
        cantidad_real_salida: output,
        consumos: consumptions,
      });
      const closed = order.lotes.find((lot) => lot.id === openLot.id);
      const outputFolio = closed?.salidas[0]?.numero_folio;
      applyOrder(
        order,
        outputFolio
          ? `Lote cerrado. Folio de salida ${outputFolio} listo para etiquetar y ubicar.`
          : 'Lote cerrado correctamente.',
      );
    } catch (reason) {
      fail(reason, 'No fue posible cerrar el lote.');
    } finally {
      setBusy(false);
    }
  }

  async function closeOrder() {
    if (!selected || !canOperate) return;
    const reason = closeReason.trim();
    const key = `close-order:${selected.id}:${selected.version}:${reason}`;
    setBusy(true);
    try {
      const order = await api.closeMaterialTransformationOrder(auth.token, selected.id, {
        operacion_id: operationId(key),
        version_conocida: selected.version,
        ...(reason ? { motivo_desviacion: reason } : {}),
      });
      applyOrder(order, 'Orden cerrada. Los saldos de reserva no consumidos fueron liberados.');
    } catch (reasonCaught) {
      fail(reasonCaught, 'No fue posible cerrar la orden.');
    } finally {
      setBusy(false);
    }
  }

  async function reverseLot(lot: MaterialTransformationLot) {
    if (!selected || !canReverse || lot.id !== lastLot?.id || lot.estado !== 'cerrado') return;
    const reason = reverseReason.trim();

    if (reason.length < 5) {
      setError('Indica un motivo de al menos 5 caracteres para revertir el lote.');
      return;
    }

    const key = `reverse-lot:${lot.id}:${selected.version}:${reason}`;
    setBusy(true);
    try {
      const order = await api.reverseMaterialTransformationLot(auth.token, lot.id, {
        operacion_id: operationId(key),
        version_conocida: selected.version,
        motivo: reason,
      });
      const pendingLocation = order.reservas.filter(
        (reservation) => reservation.estado === 'activa'
          && Number(reservation.cantidad_pendiente) > 0
          && reservation.folio
          && !reservation.folio.ubicacion,
      );
      applyOrder(
        order,
        pendingLocation.length > 0
          ? `Lote revertido. ${pendingLocation.length} folio(s) de entrada deben ubicarse nuevamente.`
          : 'Lote revertido. Los saldos y reservas de entrada fueron restaurados.',
      );
      setReverseCandidateId(null);
      setReverseReason('');
    } catch (reasonCaught) {
      fail(reasonCaught, 'No fue posible revertir el lote.');
    } finally {
      setBusy(false);
    }
  }

  function fail(reason: unknown, fallback: string) {
    setError(reason instanceof Error ? reason.message : fallback);
    onConnectionFailure(reason);
  }

  return (
    <View style={styles.module}>
      <View style={styles.heading}>
        <View>
          <Text style={styles.eyebrow}>EJECUCIÓN PDA · MATERIALES</Text>
          <Text style={styles.title}>Transformación de materiales</Text>
          <Text style={styles.subtitle}>
            {orders.filter((order) => ACTIVE_STATES.includes(order.estado)).length} órdenes activas
            {lastSync ? ` · sincronizado ${lastSync}` : ''}
          </Text>
        </View>
        <Pressable onPress={() => void refresh(false)} style={styles.secondaryButton}>
          <Text style={styles.secondaryButtonText}>Actualizar</Text>
        </Pressable>
      </View>

      {error ? (
        <Pressable onPress={() => setError('')} style={styles.errorBanner}>
          <Text style={styles.errorText}>{error}</Text>
        </Pressable>
      ) : null}
      {notice ? (
        <Pressable onPress={() => setNotice('')} style={styles.noticeBanner}>
          <Text style={styles.noticeText}>{notice}</Text>
        </Pressable>
      ) : null}

      <View style={[styles.workspace, compact && styles.workspaceCompact]}>
        <View style={[styles.queue, compact && styles.queueCompact]}>
          <View style={styles.filters}>
            {(['activas', 'historial', 'todas'] as QueueFilter[]).map((value) => (
              <Pressable
                key={value}
                onPress={() => setFilter(value)}
                style={[styles.filter, filter === value && styles.filterActive]}
              >
                <Text style={[styles.filterText, filter === value && styles.filterTextActive]}>
                  {value === 'activas' ? 'Activas' : value === 'historial' ? 'Historial' : 'Todas'}
                </Text>
              </Pressable>
            ))}
          </View>
          <ScrollView contentContainerStyle={styles.queueList} nestedScrollEnabled>
            {filtered.map((order) => (
              <Pressable
                key={order.id}
                onPress={() => setSelectedId(order.id)}
                style={[styles.queueItem, selectedSummary?.id === order.id && styles.queueItemSelected]}
              >
                <View style={styles.rowBetween}>
                  <Text style={styles.queueCode}>OT · {order.id.slice(0, 8).toUpperCase()}</Text>
                  <StateBadge state={order.estado} />
                </View>
                <Text numberOfLines={1} style={styles.queueName}>
                  {order.version_receta.receta.nombre}
                </Text>
                <Text style={styles.meta}>
                  {order.cliente.codigo} · {formatQuantity(order.cantidad_real_salida ?? '0')}/
                  {formatQuantity(order.cantidad_planificada_salida)} {order.version_receta.receta.item_salida.unidad_medida}
                </Text>
              </Pressable>
            ))}
            {!filtered.length && !busy ? (
              <Text style={styles.empty}>No hay órdenes en este filtro.</Text>
            ) : null}
          </ScrollView>
        </View>

        <ScrollView contentContainerStyle={styles.detail} nestedScrollEnabled style={styles.detailScroll}>
          {selected ? (
            <>
              <View style={styles.rowBetween}>
                <View style={styles.flex}>
                  <Text style={styles.detailTitle}>{selected.version_receta.receta.nombre}</Text>
                  <Text style={styles.detailSubtitle}>
                    {selected.cliente.codigo} · {selected.cliente.nombre} · versión {selected.version}
                  </Text>
                </View>
                <StateBadge state={selected.estado} />
              </View>

              <View style={styles.metrics}>
                <Metric label="PLANIFICADO" value={`${formatQuantity(selected.cantidad_planificada_salida)} ${selected.receta_snapshot.salida.unidad_medida}`} />
                <Metric label="PRODUCIDO" value={`${formatQuantity(selected.cantidad_real_salida ?? '0')} ${selected.receta_snapshot.salida.unidad_medida}`} />
                <Metric label="FOLIOS GENERADOS" value={selected.folios_planificados
                  ? `${closedLots.length}/${selected.folios_planificados}`
                  : String(closedLots.length)} />
              </View>

              {selected.estado === 'planificada' ? (
                <ActionCard
                  title="Iniciar ejecución"
                  description="Confirma que la orden pasa desde planificación a operación PDA."
                >
                  <PrimaryButton disabled={!canOperate} label="Iniciar orden" onPress={() => void startOrder()} />
                </ActionCard>
              ) : null}

              {(selected.estado === 'en_proceso' || selected.estado === 'pendiente_cierre') && !openLot ? (
                <ActionCard
                  title={unitsPerOutputFolio > 0 ? 'Abrir siguiente folio / pallet' : 'Abrir lote parcial'}
                  description={unitsPerOutputFolio > 0
                    ? `La receta fija ${formatQuantity(String(unitsPerOutputFolio))} ${selected.receta_snapshot.salida.unidad_medida} por folio. El último puede corresponder al remanente de la orden.`
                    : 'Solo puede existir un lote abierto. La suma planificada no puede superar la orden.'}
                >
                  <Field
                    editable={unitsPerOutputFolio <= 0}
                    keyboardType="decimal-pad"
                    label={unitsPerOutputFolio > 0 ? 'Unidades del siguiente folio' : 'Cantidad planificada del lote'}
                    onChangeText={setPlannedQuantity}
                    value={plannedQuantity}
                  />
                  <PrimaryButton
                    disabled={!canOperate || selected.estado === 'pendiente_cierre'}
                    label="Abrir lote"
                    onPress={() => void openNewLot()}
                  />
                </ActionCard>
              ) : null}

              {openLot ? (
                <ActionCard
                  title={`Lote ${openLot.numero_lote} en ejecución`}
                  description={`Meta ${formatQuantity(openLot.cantidad_planificada_salida)} ${selected.receta_snapshot.salida.unidad_medida}. Pistolea los folios y confirma sus cantidades reales.`}
                >
                  <View style={styles.scanRow}>
                    <TextInput
                      autoCapitalize="characters"
                      autoCorrect={false}
                      blurOnSubmit={false}
                      onChangeText={setScan}
                      onSubmitEditing={() => addScannedFolio()}
                      placeholder="Pistolear folio de material"
                      placeholderTextColor={colors.muted}
                      style={[styles.input, styles.flex]}
                      value={scan}
                    />
                    <Pressable onPress={() => addScannedFolio()} style={styles.secondaryButton}>
                      <Text style={styles.secondaryButtonText}>Agregar</Text>
                    </Pressable>
                  </View>

                  <View style={styles.reservationList}>
                    {activeReservations.map((reservation) => {
                      if (!reservation.folio) return null;
                      const component = selected.receta_snapshot.componentes.find(
                        (candidate) => candidate.item_id === reservation.item_material_id,
                      );
                      const line = draft[reservation.folio.id];
                      return (
                        <View key={reservation.id} style={[
                          styles.reservation,
                          line && styles.reservationSelected,
                        ]}>
                          <Pressable
                            onPress={() => addScannedFolio(reservation)}
                            style={styles.reservationHeader}
                          >
                            <View style={styles.flex}>
                              <Text style={styles.folio}>{reservation.folio.numero_folio}</Text>
                              <Text style={styles.meta}>
                                FIFO {reservation.orden_fifo} · {component?.codigo ?? 'Ítem'} ·
                                {' '}{reservation.folio.ubicacion
                                  ? `${reservation.folio.ubicacion.camara} / ${reservation.folio.ubicacion.posicion}`
                                  : 'sin ubicación'}
                              </Text>
                            </View>
                            <Text style={styles.pending}>
                              {reservation.folio.ubicacion
                                ? `${formatQuantity(reservation.cantidad_pendiente)} disponibles`
                                : 'REUBICAR ANTES DE CONSUMIR'}
                            </Text>
                          </Pressable>
                          {line ? (
                            <View style={styles.consumptionFields}>
                              <Field
                                keyboardType="decimal-pad"
                                label="Cantidad consumida"
                                onChangeText={(value) => updateDraft(reservation.folio!.id, { cantidad: value })}
                                value={line.cantidad}
                              />
                              <Field
                                label="Motivo excepción FIFO (solo si corresponde)"
                                onChangeText={(value) => updateDraft(reservation.folio!.id, { motivo: value })}
                                value={line.motivo}
                              />
                            </View>
                          ) : null}
                        </View>
                      );
                    })}
                  </View>

                  <Field
                    keyboardType="decimal-pad"
                    label="Cantidad real producida"
                    onChangeText={setActualQuantity}
                    value={actualQuantity}
                  />
                  <PrimaryButton
                    disabled={!canOperate}
                    label="Cerrar lote y generar folio de salida"
                    onPress={() => void closeLot()}
                  />
                </ActionCard>
              ) : null}

              {(selected.estado === 'en_proceso' || selected.estado === 'pendiente_cierre')
                && !openLot
                && closedLots.length > 0 ? (
                  <ActionCard
                    title="Cerrar orden"
                    description="Libera reservas no consumidas. Si la producción difiere del plan, la justificación es obligatoria."
                  >
                    <Field
                      label="Motivo de desviación (si corresponde)"
                      onChangeText={setCloseReason}
                      value={closeReason}
                    />
                    <PrimaryButton disabled={!canOperate} label="Cerrar orden" onPress={() => void closeOrder()} />
                  </ActionCard>
                ) : null}

              <Text style={styles.sectionTitle}>Genealogía de lotes</Text>
              {selected.lotes.map((lot) => (
                <View key={lot.id} style={styles.lotCard}>
                  <View style={styles.rowBetween}>
                    <Text style={styles.lotTitle}>Lote {lot.numero_lote}</Text>
                    <Text style={styles.lotState}>{lot.estado.toUpperCase()}</Text>
                  </View>
                  <Text style={styles.meta}>
                    Plan {formatQuantity(lot.cantidad_planificada_salida)} ·
                    real {formatQuantity(lot.cantidad_real_salida ?? '0')} ·
                    merma {formatQuantity(lot.merma_real ?? '0')}
                  </Text>
                  {lot.consumos.map((consumption) => (
                    <Text key={consumption.id} style={styles.trace}>
                      − {consumption.numero_folio} · {consumption.item.codigo} ·
                      {' '}{formatQuantity(consumption.cantidad_consumida)} {consumption.item.unidad_medida}
                      {consumption.siguio_fifo ? ' · FIFO' : ' · EXCEPCIÓN FIFO'}
                    </Text>
                  ))}
                  {lot.salidas.map((output) => (
                    <Text key={output.id} style={styles.output}>
                      + {output.numero_folio} · {output.item.codigo} ·
                      {' '}{formatQuantity(output.cantidad_producida)} {output.item.unidad_medida}
                    </Text>
                  ))}
                  {lot.estado === 'anulado' ? (
                    <Text style={styles.reversed}>
                      REVERTIDO{lot.reversado_por ? ` por ${lot.reversado_por.nombre}` : ''}
                      {lot.motivo_reversa ? ` · ${lot.motivo_reversa}` : ''}
                    </Text>
                  ) : null}
                  {canReverse
                    && !openLot
                    && (selected.estado === 'en_proceso' || selected.estado === 'pendiente_cierre')
                    && lastLot?.id === lot.id
                    && lot.estado === 'cerrado' ? (
                      <View style={styles.reversePanel}>
                        {reverseCandidateId === lot.id ? (
                          <>
                            <Text style={styles.reverseWarning}>
                              La salida quedará anulada y los consumos se compensarán. El motivo será permanente.
                            </Text>
                            <Field
                              label="Motivo obligatorio de la reversa"
                              onChangeText={setReverseReason}
                              value={reverseReason}
                            />
                            <View style={styles.reverseActions}>
                              <Pressable
                                disabled={reverseReason.trim().length < 5}
                                onPress={() => void reverseLot(lot)}
                                style={[
                                  styles.dangerButton,
                                  reverseReason.trim().length < 5 && styles.buttonDisabled,
                                ]}
                              >
                                <Text style={styles.dangerButtonText}>Confirmar reversa</Text>
                              </Pressable>
                              <Pressable
                                onPress={() => {
                                  setReverseCandidateId(null);
                                  setReverseReason('');
                                }}
                                style={styles.secondaryButton}
                              >
                                <Text style={styles.secondaryButtonText}>Cancelar</Text>
                              </Pressable>
                            </View>
                          </>
                        ) : (
                          <Pressable
                            onPress={() => setReverseCandidateId(lot.id)}
                            style={styles.secondaryButton}
                          >
                            <Text style={styles.secondaryButtonText}>Revertir último lote</Text>
                          </Pressable>
                        )}
                      </View>
                    ) : null}
                </View>
              ))}
              {!selected.lotes.length ? <Text style={styles.empty}>La orden aún no tiene lotes.</Text> : null}

              {closedLots.some((lot) => lot.salidas.length > 0) ? (
                <MaterialLabelPrintPanel
                  deviceId={auth.dispositivo.id}
                  sourceApi={{
                    printProfiles: () => api.materialLabelProfiles(auth.token),
                    printJobs: () => api.materialTransformationPrintJobs(auth.token, selected.id),
                    generateLabels: (payload) => api.generateMaterialTransformationLabels(
                      auth.token,
                      selected.id,
                      payload,
                    ),
                    reportPrintOutcome: (jobId, payload) =>
                      api.reportMaterialTransformationPrintOutcome(
                        auth.token,
                        jobId,
                        payload,
                      ),
                  }}
                  sourceFolios={closedLots.flatMap((lot) => lot.salidas.map((output) => ({
                    id: output.folio_id,
                    number: output.numero_folio,
                    item: `Lote ${lot.numero_lote} · ${output.item.codigo} · ${output.item.nombre}`,
                    quantity: `${formatQuantity(output.cantidad_producida)} ${output.item.unidad_medida}`,
                  })))}
                  sourceId={selected.id}
                  sourceLabel={`orden OT-${selected.id.slice(0, 8).toUpperCase()}`}
                />
              ) : null}
            </>
          ) : (
            <View style={styles.emptyDetail}>
              <Text style={styles.detailTitle}>Sin órdenes operacionales</Text>
              <Text style={styles.empty}>La oficina debe crear y planificar una orden primero.</Text>
            </View>
          )}
        </ScrollView>
      </View>

      {busy ? (
        <View pointerEvents="none" style={styles.busy}>
          <ActivityIndicator color={colors.cyan} size="large" />
          <Text style={styles.busyText}>Sincronizando transformación…</Text>
        </View>
      ) : null}
    </View>
  );
}

function ActionCard({
  children,
  description,
  title,
}: {
  children: ReactNode;
  description: string;
  title: string;
}) {
  return (
    <View style={styles.actionCard}>
      <Text style={styles.actionTitle}>{title}</Text>
      <Text style={styles.actionDescription}>{description}</Text>
      <View style={styles.actionBody}>{children}</View>
    </View>
  );
}

function Field({
  editable = true,
  keyboardType,
  label,
  onChangeText,
  value,
}: {
  editable?: boolean;
  keyboardType?: 'default' | 'decimal-pad';
  label: string;
  onChangeText: (value: string) => void;
  value: string;
}) {
  return (
    <View style={styles.field}>
      <Text style={styles.fieldLabel}>{label}</Text>
      <TextInput
        editable={editable}
        keyboardType={keyboardType}
        onChangeText={onChangeText}
        placeholderTextColor={colors.muted}
        style={styles.input}
        value={value}
      />
    </View>
  );
}

function PrimaryButton({
  disabled,
  label,
  onPress,
}: {
  disabled: boolean;
  label: string;
  onPress: () => void;
}) {
  return (
    <Pressable
      disabled={disabled}
      onPress={onPress}
      style={[styles.primaryButton, disabled && styles.buttonDisabled]}
    >
      <Text style={styles.primaryButtonText}>{label}</Text>
    </Pressable>
  );
}

function Metric({ label, value }: { label: string; value: string }) {
  return (
    <View style={styles.metric}>
      <Text style={styles.metricLabel}>{label}</Text>
      <Text style={styles.metricValue}>{value}</Text>
    </View>
  );
}

function StateBadge({ state }: { state: MaterialTransformationState }) {
  return (
    <View style={[styles.badge, { borderColor: stateColor(state) }]}>
      <Text style={[styles.badgeText, { color: stateColor(state) }]}>{stateLabel(state)}</Text>
    </View>
  );
}

function stateColor(state: MaterialTransformationState) {
  return {
    borrador: colors.muted,
    planificada: colors.amber,
    en_proceso: colors.blue,
    pendiente_cierre: colors.cyan,
    cerrada: colors.green,
    cancelada: colors.red,
  }[state];
}

function stateLabel(state: MaterialTransformationState) {
  return {
    borrador: 'BORRADOR',
    planificada: 'PLANIFICADA',
    en_proceso: 'EN PROCESO',
    pendiente_cierre: 'POR CERRAR',
    cerrada: 'CERRADA',
    cancelada: 'CANCELADA',
  }[state];
}

function parseQuantity(value: string) {
  const parsed = Number(value.replace(',', '.'));
  return Number.isFinite(parsed) && parsed > 0 ? Math.round(parsed * 1000) / 1000 : 0;
}

function formatInputQuantity(value: number) {
  return String(Math.round(value * 1000) / 1000).replace('.', ',');
}

function formatQuantity(value: string) {
  return Number(value).toLocaleString('es-CL', { maximumFractionDigits: 3 });
}

const styles = StyleSheet.create({
  module: { minHeight: 650, borderRadius: 18, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.panel, overflow: 'hidden', position: 'relative' },
  heading: { padding: 18, borderBottomWidth: 1, borderBottomColor: colors.border, flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 14 },
  eyebrow: { color: colors.cyan, fontSize: 12, fontWeight: '900', letterSpacing: 1.2 },
  title: { marginTop: 4, color: colors.text, fontSize: 22, fontWeight: '900' },
  subtitle: { marginTop: 4, color: colors.muted, fontSize: 13 },
  workspace: { minHeight: 560, flexDirection: 'row' },
  workspaceCompact: { flexDirection: 'column' },
  queue: { width: 320, borderRightWidth: 1, borderRightColor: colors.border, backgroundColor: colors.backgroundDeep },
  queueCompact: { width: '100%', maxHeight: 280, borderRightWidth: 0, borderBottomWidth: 1, borderBottomColor: colors.border },
  filters: { padding: 10, flexDirection: 'row', gap: 6 },
  filter: { flex: 1, paddingVertical: 8, borderRadius: 8, borderWidth: 1, borderColor: colors.border, alignItems: 'center' },
  filterActive: { borderColor: colors.cyan, backgroundColor: colors.selected },
  filterText: { color: colors.muted, fontSize: 11, fontWeight: '900' },
  filterTextActive: { color: colors.cyan },
  queueList: { padding: 10, paddingTop: 0, gap: 7 },
  queueItem: { padding: 11, borderRadius: 10, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.panel },
  queueItemSelected: { borderColor: colors.cyan, borderLeftWidth: 4, backgroundColor: colors.selected },
  queueCode: { color: colors.cyan, fontSize: 14, fontWeight: '900' },
  queueName: { marginTop: 6, color: colors.text, fontSize: 14, fontWeight: '800' },
  detailScroll: { flex: 1, minWidth: 0 },
  detail: { padding: 18, gap: 12 },
  detailTitle: { color: colors.text, fontSize: 22, fontWeight: '900' },
  detailSubtitle: { marginTop: 5, color: colors.cyan, fontSize: 13, fontWeight: '800' },
  rowBetween: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 10 },
  flex: { flex: 1, minWidth: 0 },
  badge: { paddingHorizontal: 8, paddingVertical: 5, borderRadius: 9, borderWidth: 1 },
  badgeText: { fontSize: 11, fontWeight: '900' },
  meta: { marginTop: 3, color: colors.muted, fontSize: 12, lineHeight: 16 },
  metrics: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  metric: { minWidth: 145, flexGrow: 1, padding: 11, borderRadius: 9, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.background },
  metricLabel: { color: colors.muted, fontSize: 11, fontWeight: '900' },
  metricValue: { marginTop: 5, color: colors.text, fontSize: 13, fontWeight: '900' },
  actionCard: { padding: 13, borderRadius: 11, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.background },
  actionTitle: { color: colors.text, fontSize: 13, fontWeight: '900' },
  actionDescription: { marginTop: 4, color: colors.muted, fontSize: 12, lineHeight: 16 },
  actionBody: { marginTop: 12, gap: 9 },
  field: { flex: 1, minWidth: 180 },
  fieldLabel: { marginBottom: 4, color: colors.muted, fontSize: 11, fontWeight: '900' },
  input: { minHeight: 42, paddingHorizontal: 11, borderRadius: 8, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.panel, color: colors.text, fontSize: 14 },
  scanRow: { flexDirection: 'row', alignItems: 'center', gap: 8 },
  primaryButton: { minHeight: 48, paddingHorizontal: 15, borderRadius: 9, backgroundColor: colors.cyan, alignItems: 'center', justifyContent: 'center' },
  primaryButtonText: { color: colors.accentText, fontSize: 13, fontWeight: '900' },
  secondaryButton: { minHeight: 48, paddingHorizontal: 14, borderRadius: 9, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.background, alignItems: 'center', justifyContent: 'center' },
  secondaryButtonText: { color: colors.text, fontSize: 12, fontWeight: '900' },
  buttonDisabled: { opacity: 0.35 },
  reservationList: { gap: 7 },
  reservation: { borderRadius: 9, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.panel, overflow: 'hidden' },
  reservationSelected: { borderColor: colors.cyan },
  reservationHeader: { padding: 10, flexDirection: 'row', alignItems: 'center', gap: 8 },
  folio: { color: colors.text, fontSize: 14, fontWeight: '900' },
  pending: { color: colors.amber, fontSize: 12, fontWeight: '900' },
  consumptionFields: { padding: 10, paddingTop: 0, flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  sectionTitle: { marginTop: 6, color: colors.text, fontSize: 12, fontWeight: '900' },
  lotCard: { padding: 11, borderRadius: 9, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.background },
  lotTitle: { color: colors.text, fontSize: 14, fontWeight: '900' },
  lotState: { color: colors.muted, fontSize: 11, fontWeight: '900' },
  trace: { marginTop: 6, color: colors.muted, fontSize: 12 },
  output: { marginTop: 6, color: colors.green, fontSize: 13, fontWeight: '900' },
  reversed: { marginTop: 8, color: colors.red, fontSize: 12, fontWeight: '900' },
  reversePanel: { marginTop: 10, paddingTop: 10, borderTopWidth: 1, borderTopColor: colors.border, gap: 8 },
  reverseWarning: { color: colors.red, fontSize: 12, lineHeight: 16, fontWeight: '800' },
  reverseActions: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  dangerButton: { minHeight: 48, paddingHorizontal: 14, borderRadius: 9, borderWidth: 1, borderColor: colors.red, backgroundColor: colors.background, alignItems: 'center', justifyContent: 'center' },
  dangerButtonText: { color: colors.red, fontSize: 12, fontWeight: '900' },
  empty: { padding: 18, color: colors.muted, fontSize: 13, textAlign: 'center' },
  emptyDetail: { minHeight: 400, alignItems: 'center', justifyContent: 'center' },
  errorBanner: { margin: 10, marginBottom: 0, padding: 10, borderRadius: 8, borderWidth: 1, borderColor: colors.red, backgroundColor: colors.background },
  errorText: { color: colors.red, fontSize: 13, fontWeight: '800' },
  noticeBanner: { margin: 10, marginBottom: 0, padding: 10, borderRadius: 8, borderWidth: 1, borderColor: colors.green, backgroundColor: colors.background },
  noticeText: { color: colors.green, fontSize: 13, fontWeight: '800' },
  busy: { ...StyleSheet.absoluteFillObject, backgroundColor: 'rgba(5,8,11,0.58)', alignItems: 'center', justifyContent: 'center', gap: 8 },
  busyText: { color: colors.text, fontSize: 13, fontWeight: '900' },
});
