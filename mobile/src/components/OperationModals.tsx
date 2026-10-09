import { useEffect, useRef, useState } from 'react';
import {
  ActivityIndicator,
  Modal,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  TextInput,
  useWindowDimensions,
  View,
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { bandNumberingLabel, cameraDisplayName, orderBandsForCamera } from '../domain/cameras';
import {
  AvailableMaterialFolio,
  CameraPlan,
  CameraSummary,
  FolioLookup,
  MaterialDispatch,
  MaterialItem,
  Position,
  SagCondition,
} from '../domain/estiba';
import { PrivateMaterialThumbnail } from './PrivateMaterialThumbnail';
import { colors } from '../theme/colors';

export type LocateFormValue = {
  numero_folio: string;
  tipo_bulto: 'pallet' | 'saldo' | 'material';
  existente: boolean;
  condicion_sag_id?: string;
  variedad?: string;
  calibre?: string;
  marca?: string;
  exportadora?: string;
  item_material_id?: string;
  cantidad?: number;
  lote?: string;
  proveedor?: string;
  observacion_material?: string;
};

type LocateModalProps = {
  busy: boolean;
  conditions: SagCondition[];
  materialItems: MaterialItem[];
  error: string;
  onCancel: () => void;
  onConfirm: (value: LocateFormValue) => Promise<void>;
  onLookup: (folioNumber: string) => Promise<FolioLookup>;
  onSearchMaterialFolios: (prefix: string) => Promise<AvailableMaterialFolio[]>;
  plan: CameraPlan | null;
  position: Position | null;
  visible: boolean;
  cameraOnly?: boolean;
};

export function LocateModal({
  busy,
  conditions,
  materialItems,
  error,
  onCancel,
  onConfirm,
  onLookup,
  onSearchMaterialFolios,
  plan,
  position,
  visible,
  cameraOnly = false,
}: LocateModalProps) {
  const { height, width } = useWindowDimensions();
  const compact = height < 700 || width < 1000;
  const isMaterial = plan?.contenido === 'materiales';
  const [folio, setFolio] = useState('');
  const [type, setType] = useState<'pallet' | 'saldo' | 'material'>('pallet');
  const [conditionId, setConditionId] = useState<string>();
  const [variety, setVariety] = useState('');
  const [caliber, setCaliber] = useState('');
  const [brand, setBrand] = useState('');
  const [exporter, setExporter] = useState('');
  const [materialSearch, setMaterialSearch] = useState('');
  const [materialClientId, setMaterialClientId] = useState<string>();
  const [materialItemId, setMaterialItemId] = useState<string>();
  const [materialQuantity, setMaterialQuantity] = useState('');
  const [materialLot, setMaterialLot] = useState('');
  const [materialSupplier, setMaterialSupplier] = useState('');
  const [materialObservation, setMaterialObservation] = useState('');
  const [lookup, setLookup] = useState<FolioLookup | null>(null);
  const [lookupBusy, setLookupBusy] = useState(false);
  const [lookupMessage, setLookupMessage] = useState('');
  const [lookupTone, setLookupTone] = useState<'success' | 'warning' | 'neutral'>('neutral');
  const [suggestions, setSuggestions] = useState<AvailableMaterialFolio[]>([]);
  const [suggestionsBusy, setSuggestionsBusy] = useState(false);
  const selectedFolio = useRef('');
  const lookupSequence = useRef(0);
  const lookupTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const materialClients = [...new Map(materialItems.map((item) => [item.cliente.id, item.cliente])).values()];
  const materialSeason = materialItems[0]?.cliente.temporada;
  const sharedMaterialClient = position?.folios?.find((folioItem) => folioItem.material)?.material?.item.cliente;
  const filteredMaterialItems = materialItems.filter((item) => (
    item.cliente.id === materialClientId
    && `${item.cliente.codigo} ${item.cliente.nombre} ${item.codigo} ${item.nombre} ${item.categoria ?? ''}`
      .toLowerCase()
      .includes(materialSearch.trim().toLowerCase())
  ));

  useEffect(() => {
    if (!visible) {
      lookupSequence.current += 1;
      if (lookupTimer.current) clearTimeout(lookupTimer.current);
      return;
    }

    setFolio('');
    setType(isMaterial ? 'material' : 'pallet');
    setConditionId(undefined);
    setVariety('');
    setCaliber('');
    setBrand('');
    setExporter('');
    setMaterialSearch('');
    setMaterialClientId(sharedMaterialClient?.id);
    setMaterialItemId(undefined);
    setMaterialQuantity('');
    setMaterialLot('');
    setMaterialSupplier('');
    setMaterialObservation('');
    setLookup(null);
    setLookupBusy(false);
    setLookupMessage('');
    setLookupTone('neutral');
    setSuggestions([]);
    setSuggestionsBusy(false);
    selectedFolio.current = '';
  }, [visible, isMaterial, sharedMaterialClient?.id]);

  useEffect(() => {
    if (!visible) return;
    if (lookupTimer.current) clearTimeout(lookupTimer.current);

    const normalized = folio.trim().toUpperCase();
    if (!normalized) return;

    lookupTimer.current = setTimeout(() => {
      if (isMaterial) {
        if (normalized.length >= 2 && selectedFolio.current !== normalized) {
          void searchMaterialFolios(normalized);
        }
      } else {
        void lookupFolio(normalized);
      }
    }, 300);

    return () => {
      if (lookupTimer.current) clearTimeout(lookupTimer.current);
    };
  }, [folio, visible, isMaterial]);

  async function searchMaterialFolios(prefix: string) {
    const sequence = ++lookupSequence.current;
    setSuggestionsBusy(true);
    try {
      const results = await onSearchMaterialFolios(prefix);
      if (sequence !== lookupSequence.current) return;
      setSuggestions(results);
      if (!results.length) {
        setLookupMessage('No hay folios disponibles que comiencen con ese texto.');
        setLookupTone('neutral');
      }
    } catch (reason) {
      if (sequence !== lookupSequence.current) return;
      setLookupMessage(reason instanceof Error ? reason.message : 'No fue posible buscar folios. Revisa la conexión.');
      setLookupTone('warning');
    } finally {
      if (sequence === lookupSequence.current) setSuggestionsBusy(false);
    }
  }

  function selectSuggestion(number: string) {
    lookupSequence.current += 1;
    selectedFolio.current = number;
    setFolio(number);
    setSuggestions([]);
    setSuggestionsBusy(false);
    void lookupFolio(number);
  }

  function clearDetails() {
    setType(isMaterial ? 'material' : 'pallet');
    setConditionId(undefined);
    setVariety('');
    setCaliber('');
    setBrand('');
    setExporter('');
    setMaterialSearch('');
    setMaterialClientId(sharedMaterialClient?.id);
    setMaterialItemId(undefined);
    setMaterialQuantity('');
    setMaterialLot('');
    setMaterialSupplier('');
    setMaterialObservation('');
  }

  function changeFolio(value: string) {
    lookupSequence.current += 1;
    selectedFolio.current = '';
    setSuggestions([]);
    setSuggestionsBusy(false);
    if (lookup) clearDetails();
    setLookup(null);
    setLookupBusy(false);
    setLookupMessage('');
    setLookupTone('neutral');
    setFolio(value);
  }

  function isCompatible(result: FolioLookup) {
    if (!result.existe) return true;

    return isMaterial
      ? result.tipo_bulto === 'material' && result.material !== null
      : result.tipo_bulto !== 'material';
  }

  function applyLookup(result: FolioLookup) {
    setLookup(result);

    if (!result.existe) {
      setLookupMessage(isMaterial
        ? 'El folio de material no existe. Debe crearse y confirmarse desde Recepción o Transformación antes de ubicarlo.'
        : 'Folio nuevo. Completa sus datos antes de confirmar la ubicación.');
      setLookupTone(isMaterial ? 'warning' : 'neutral');
      return;
    }

    setType(result.tipo_bulto);
    setConditionId(result.condicion_sag?.id);
    setVariety(result.variedad ?? '');
    setCaliber(result.calibre ?? '');
    setBrand(result.marca ?? '');
    setExporter(result.exportadora ?? '');

    if (result.material) {
      const catalogItem = materialItems.find((item) => item.id === result.material?.item_material_id);
      setMaterialClientId(catalogItem?.cliente.id);
      setMaterialItemId(result.material.item_material_id);
      setMaterialQuantity(result.material.cantidad);
      setMaterialLot(result.material.lote ?? '');
      setMaterialSupplier(result.material.proveedor ?? '');
      setMaterialObservation(result.material.observacion ?? '');
    }

    const compatible = isCompatible(result);
    const source = result.origen_sistema === 'validacion'
      ? 'Datos recuperados desde Validación.'
      : 'Datos recuperados del folio existente.';
    const availability = isMaterial
      && result.tipo_bulto === 'material'
      && result.material === null
      ? 'El folio no posee una ficha válida de inventario de Materiales.'
      : compatible
        ? result.mensaje_disponibilidad
        : 'El tipo de folio no corresponde a esta cámara.';
    const recommendation = recommendationMessage(result);
    setLookupMessage(`${source} ${availability}${recommendation ? ` ${recommendation}` : ''}`);
    setLookupTone(result.disponible_ubicacion && compatible ? 'success' : 'warning');
  }

  async function lookupFolio(value: string): Promise<FolioLookup | null> {
    const normalized = value.trim().toUpperCase();
    if (!normalized) return null;
    if (lookupTimer.current) clearTimeout(lookupTimer.current);
    setSuggestions([]);

    const sequence = ++lookupSequence.current;
    setLookupBusy(true);
    setLookupMessage('Consultando información del folio…');
    setLookupTone('neutral');

    try {
      const result = await onLookup(normalized);
      if (sequence !== lookupSequence.current) return null;
      applyLookup(result);
      return result;
    } catch (reason) {
      if (sequence !== lookupSequence.current) return null;
      setLookup(null);
      setLookupMessage(reason instanceof Error
        ? reason.message
        : 'No fue posible consultar el folio. Revisa la conexión.');
      setLookupTone('warning');
      return null;
    } finally {
      if (sequence === lookupSequence.current) setLookupBusy(false);
    }
  }

  async function submit() {
    if (!folio.trim()) return;
    const result = await lookupFolio(folio);
    if (!result
      || (isMaterial && !result.existe)
      || (result.existe && (!result.disponible_ubicacion || !isCompatible(result)))) return;

    if (result.existe) {
      await onConfirm({
        numero_folio: result.numero_folio,
        tipo_bulto: result.tipo_bulto,
        existente: true,
        condicion_sag_id: result.condicion_sag?.id,
        variedad: result.variedad ?? undefined,
        calibre: result.calibre ?? undefined,
        marca: result.marca ?? undefined,
        exportadora: result.exportadora ?? undefined,
        item_material_id: result.material?.item_material_id,
        cantidad: result.material ? Number(result.material.cantidad) : undefined,
        lote: result.material?.lote ?? undefined,
        proveedor: result.material?.proveedor ?? undefined,
        observacion_material: result.material?.observacion ?? undefined,
      });
      return;
    }

    if (isMaterial && (!materialItemId || Number(materialQuantity) <= 0)) return;
    await onConfirm({
      numero_folio: folio.trim().toUpperCase(),
      tipo_bulto: type,
      existente: false,
      condicion_sag_id: conditionId,
      variedad: variety.trim() || undefined,
      calibre: caliber.trim() || undefined,
      marca: brand.trim() || undefined,
      exportadora: exporter.trim() || undefined,
      item_material_id: materialItemId,
      cantidad: isMaterial ? Number(materialQuantity) : undefined,
      lote: materialLot.trim() || undefined,
      proveedor: materialSupplier.trim() || undefined,
      observacion_material: materialObservation.trim() || undefined,
    });
  }

  const existingFolio = lookup?.existe === true;
  const lookupBlocked = existingFolio
    && (!lookup.disponible_ubicacion || !isCompatible(lookup));

  return (
    <Modal animationType="fade" onRequestClose={onCancel} transparent visible={visible}>
      <SafeAreaView edges={['top', 'right', 'bottom', 'left']} style={styles.modalSafeArea}>
        <View style={[styles.backdrop, compact && styles.backdropCompact]}>
        <View style={[styles.dialog, compact && styles.dialogCompact]}>
          <DialogHeading
            compact={compact}
            eyebrow={cameraOnly ? 'ASIGNACIÓN A CÁMARA' : 'UBICACIÓN INICIAL'}
            onClose={onCancel}
            subtitle={cameraOnly
              ? `Destino: ${cameraDisplayName(plan)} · Sin posición`
              : `Destino: ${cameraDisplayName(plan)} · ${position?.etiqueta ?? ''}`}
            title={cameraOnly ? 'Asignar material a cámara' : 'Registrar folio'}
          />
          <ScrollView
            contentContainerStyle={styles.form}
            keyboardShouldPersistTaps="handled"
            nestedScrollEnabled
            style={styles.formScroll}
          >
            <FormField
              autoCapitalize="characters"
              label="Número de folio *"
              onChangeText={changeFolio}
              onSubmitEditing={() => {
                selectedFolio.current = folio.trim().toUpperCase();
                void lookupFolio(folio);
              }}
              placeholder="Escribe o pistolea el folio"
              returnKeyType="done"
              value={folio}
              wide
            />

            {isMaterial && (suggestionsBusy || suggestions.length > 0) ? (
              <View style={styles.suggestionList}>
                <Text style={styles.suggestionTitle}>FOLIOS DISPONIBLES</Text>
                {suggestionsBusy ? <ActivityIndicator color={colors.cyan} size="small" /> : null}
                {suggestions.map((suggestion) => (
                  <Pressable
                    accessibilityRole="button"
                    key={suggestion.numero_folio}
                    onPress={() => selectSuggestion(suggestion.numero_folio)}
                    style={styles.suggestionRow}
                  >
                    <Text style={styles.suggestionNumber}>{suggestion.numero_folio}</Text>
                    <Text style={styles.suggestionItem} numberOfLines={1}>
                      {suggestion.codigo_item} · {suggestion.item}
                    </Text>
                  </Pressable>
                ))}
              </View>
            ) : null}

            {(lookupBusy || lookupMessage) ? (
              <View
                accessibilityLiveRegion="polite"
                style={[
                  styles.lookupStatus,
                  lookupTone === 'success' && styles.lookupStatusSuccess,
                  lookupTone === 'warning' && styles.lookupStatusWarning,
                ]}
              >
                {lookupBusy ? <ActivityIndicator color={colors.cyan} size="small" /> : null}
                <Text style={[
                  styles.lookupStatusText,
                  lookupTone === 'success' && styles.lookupStatusTextSuccess,
                  lookupTone === 'warning' && styles.lookupStatusTextWarning,
                ]}>
                  {lookupMessage}
                </Text>
              </View>
            ) : null}

            {isMaterial && existingFolio ? (
              <>
                <View style={[styles.field, styles.wide]}>
                  <Text style={styles.label}>Temporada activa</Text>
                  <Text style={styles.emptyInline}>{materialSeason ? `${materialSeason.codigo} · ${materialSeason.nombre}` : 'No existe una temporada activa de materiales.'}</Text>
                </View>
                <View style={[styles.field, styles.wide]}>
                  <Text style={styles.label}>Cliente *</Text>
                  {sharedMaterialClient ? <Text style={styles.emptyInline}>Este bulto pertenece a {sharedMaterialClient.codigo} · {sharedMaterialClient.nombre}. Solo admite ítems del mismo cliente.</Text> : null}
                  <ScrollView horizontal showsHorizontalScrollIndicator>
                    <View style={styles.choiceRow}>
                      {materialClients.map((client) => (
                        <Choice
                          active={materialClientId === client.id}
                          disabled={existingFolio || Boolean(sharedMaterialClient)}
                          key={client.id}
                          label={`${client.codigo} · ${client.nombre}`}
                          onPress={() => {
                            setMaterialClientId(client.id);
                            setMaterialItemId(undefined);
                          }}
                        />
                      ))}
                    </View>
                  </ScrollView>
                </View>
                <FormField editable={!existingFolio} label="Buscar ítem *" onChangeText={setMaterialSearch} placeholder="Código o descripción" value={materialSearch} wide />
                <View style={[styles.field, styles.wide]}>
                  <Text style={styles.label}>Ítem contenido *</Text>
                  <ScrollView horizontal showsHorizontalScrollIndicator>
                    <View style={styles.choiceRow}>
                      {filteredMaterialItems.map((item) => (
                        <Choice active={materialItemId === item.id} disabled={existingFolio} key={item.id} label={`${item.cliente.nombre} · ${item.codigo} · ${item.nombre}`} onPress={() => setMaterialItemId(item.id)} />
                      ))}
                    </View>
                  </ScrollView>
                  {filteredMaterialItems.length === 0 && <Text style={styles.emptyInline}>{materialClientId ? 'No hay ítems coincidentes. Solicita su creación en la oficina.' : 'Selecciona primero un cliente.'}</Text>}
                </View>
                <FormField editable={!existingFolio} label={`Cantidad inicial *${materialItems.find((item) => item.id === materialItemId)?.unidad_medida ? ` · ${materialItems.find((item) => item.id === materialItemId)?.unidad_medida}` : ''}`} onChangeText={setMaterialQuantity} placeholder="Ej. 450" value={materialQuantity} />
                <FormField editable={!existingFolio} label="Lote" onChangeText={setMaterialLot} placeholder="Opcional" value={materialLot} />
                <FormField editable={!existingFolio} label="Proveedor" onChangeText={setMaterialSupplier} placeholder="Opcional" value={materialSupplier} />
                <FormField editable={!existingFolio} label="Observación" onChangeText={setMaterialObservation} placeholder="Opcional" value={materialObservation} />
              </>
            ) : isMaterial ? (
              <View style={[styles.field, styles.wide]}>
                <Text style={styles.label}>Origen del folio</Text>
                <Text style={styles.emptyInline}>
                  Escanea un folio FAG generado previamente por Recepción o Transformación de Materiales.
                </Text>
              </View>
            ) : (
              <>
                <View style={styles.field}>
                  <Text style={styles.label}>Tipo de bulto *</Text>
                  <View style={styles.choiceRow}>
                    <Choice active={type === 'pallet'} disabled={existingFolio} label="Pallet completo" onPress={() => setType('pallet')} />
                    <Choice active={type === 'saldo'} disabled={existingFolio} label="Saldo incompleto" onPress={() => setType('saldo')} />
                  </View>
                </View>

                <View style={[styles.field, styles.wide]}>
                  <Text style={styles.label}>Condición SAG</Text>
                  <ScrollView horizontal showsHorizontalScrollIndicator={false}>
                    <View style={styles.choiceRow}>
                      <Choice active={!conditionId} disabled={existingFolio} label="Sin especificar" onPress={() => setConditionId(undefined)} />
                      {conditions.map((condition) => (
                        <Choice active={conditionId === condition.id} disabled={existingFolio} key={condition.id} label={condition.codigo} onPress={() => setConditionId(condition.id)} />
                      ))}
                    </View>
                  </ScrollView>
                </View>

                <FormField editable={!existingFolio} label="Variedad" onChangeText={setVariety} placeholder="Ej. Santina" value={variety} />
                <FormField editable={!existingFolio} label="Calibre" onChangeText={setCaliber} placeholder="Ej. 2J" value={caliber} />
                <FormField editable={!existingFolio} label="Marca" onChangeText={setBrand} placeholder="Opcional" value={brand} />
                <FormField editable={!existingFolio} label="Exportadora" onChangeText={setExporter} placeholder="Opcional" value={exporter} />
              </>
            )}
          </ScrollView>

          <ModalError message={error} />

          <DialogActions
            busy={busy}
            compact={compact}
            confirmDisabled={
              !folio.trim()
              || lookupBusy
              || lookupBlocked
              || (isMaterial && !existingFolio)
            }
            confirmLabel={cameraOnly ? "Asignar a cámara" : "Confirmar ubicación"}
            onCancel={onCancel}
            onConfirm={submit}
          />
        </View>
        </View>
      </SafeAreaView>
    </Modal>
  );
}

function recommendationMessage(result: FolioLookup): string {
  if (!result.existe || !result.recomendacion_ubicacion?.aplica) return '';

  const recommendation = result.recomendacion_ubicacion;
  const best = recommendation.mejor;

  if (!recommendation.disponible || !best) {
    return `Sugerencia WMS: ${recommendation.motivo}`;
  }

  const position = best.posicion.etiqueta
    ?? `B${String(best.banda.numero).padStart(2, '0')} · P${String(best.posicion.posicion).padStart(2, '0')} · N${best.posicion.nivel}`;

  return `Sugerencia WMS: ${best.camara.nombre} · Banda ${String(best.banda.numero).padStart(2, '0')} · ${position}. ${best.afinidad.motivo}`;
}

type MoveModalProps = {
  busy: boolean;
  cameras: CameraSummary[];
  destinationPlan: CameraPlan | null;
  error: string;
  onCancel: () => void;
  onChooseCamera: (cameraId: string) => void;
  onConfirm: () => Promise<void>;
  onSelectPosition: (position: Position) => void;
  originPlan: CameraPlan | null;
  originPosition: Position | null;
  selectedDestination: Position | null;
  visible: boolean;
};

export function MoveModal({
  busy,
  cameras,
  destinationPlan,
  error,
  onCancel,
  onChooseCamera,
  onConfirm,
  onSelectPosition,
  originPlan,
  originPosition,
  selectedDestination,
  visible,
}: MoveModalProps) {
  const { height, width } = useWindowDimensions();
  const compact = height < 700 || width < 1000;
  const originClientId = originPosition?.folio?.material?.item.cliente.id ?? null;
  const freePositions = destinationPlan?.posiciones.filter((position) => (
    position.estado === 'activa'
    && !position.reservada
    && position.id !== originPosition?.id
    && positionAcceptsMaterialClient(position, originClientId)
  )) ?? [];

  return (
    <Modal animationType="fade" onRequestClose={onCancel} transparent visible={visible}>
      <SafeAreaView edges={['top', 'right', 'bottom', 'left']} style={styles.modalSafeArea}>
        <View style={[styles.backdrop, compact && styles.backdropCompact]}>
        <View style={[styles.dialog, styles.moveDialog, compact && styles.dialogCompact]}>
          <DialogHeading
            compact={compact}
            eyebrow="MOVIMIENTO DE FOLIO"
            onClose={onCancel}
            subtitle={'Origen: ' + cameraDisplayName(originPlan) + ' · ' + (originPosition?.etiqueta ?? '')}
            title={'Mover ' + (originPosition?.folio?.numero_folio ?? 'folio')}
          />

          <Text style={styles.label}>Cámara de destino</Text>
          <ScrollView horizontal showsHorizontalScrollIndicator={false}>
            <View style={styles.choiceRow}>
              {cameras.filter((camera) => camera.estado === 'activa').map((camera) => (
                <Choice
                  active={destinationPlan?.id === camera.id}
                  key={camera.id}
                  label={cameraDisplayName(camera)}
                  onPress={() => onChooseCamera(camera.id)}
                />
              ))}
            </View>
          </ScrollView>

          <View style={styles.destinationHeading}>
            <Text style={styles.label}>PLANO DE DESTINO · POSICIONES COMPATIBLES</Text>
            <Text style={styles.destinationCount}>
              {destinationPlan ? freePositions.length + ' disponibles' : 'Cargando plano…'}
            </Text>
          </View>

          {!destinationPlan ? (
            <ActivityIndicator color={colors.cyan} style={styles.loader} />
          ) : destinationPlan.acceso.modo === 'solo_lectura' ? (
            <Text style={styles.empty}>La cámara de destino está siendo modificada por otro operador.</Text>
          ) : (
            <DestinationPlanMap
              compact={compact}
              onSelectPosition={onSelectPosition}
              originClientId={originClientId}
              originPositionId={originPosition?.id ?? null}
              plan={destinationPlan}
              selectedPositionId={selectedDestination?.id ?? null}
            />
          )}

          <ModalError message={error} />

          <DialogActions
            busy={busy}
            compact={compact}
            confirmDisabled={!selectedDestination}
            confirmLabel="Confirmar movimiento"
            onCancel={onCancel}
            onConfirm={onConfirm}
          />
        </View>
        </View>
      </SafeAreaView>
    </Modal>
  );
}

export type MaterialDispatchFormValue = {
  cantidad: number;
  despacho_id: string;
  motivo_excepcion_fifo?: string;
};

export function MaterialDispatchModal({
  busy,
  baseUrl,
  token,
  dispatches,
  error,
  onCancel,
  onConfirm,
  operatorId,
  position,
  visible,
}: {
  busy: boolean;
  baseUrl: string | null;
  token: string;
  dispatches: MaterialDispatch[];
  error: string;
  onCancel: () => void;
  onConfirm: (value: MaterialDispatchFormValue) => Promise<void>;
  operatorId: string;
  position: Position | null;
  visible: boolean;
}) {
  const { height, width } = useWindowDimensions();
  const compact = height < 700 || width < 1000;
  const material = position?.folio?.material;
  const matchingDispatches = dispatches.filter((dispatch) =>
    (!dispatch.asignado_a_user_id || String(dispatch.asignado_a_user_id) === operatorId)
    && dispatch.items.some((detail) => detail.item.id === material?.item.id && Number(detail.cantidad_pendiente) > 0));
  const prioritizedDispatches = [...matchingDispatches].sort((left, right) => (
    reservationForFolio(right, material?.item.id, position?.folio?.id)
      - reservationForFolio(left, material?.item.id, position?.folio?.id)
  ));
  const preferredDispatchId = prioritizedDispatches[0]?.id;
  const [dispatchId, setDispatchId] = useState<string>();
  const [amount, setAmount] = useState('');
  const [fifoReason, setFifoReason] = useState('');

  useEffect(() => {
    if (!visible) return;
    setDispatchId(preferredDispatchId ?? matchingDispatches[0]?.id);
    setAmount('');
    setFifoReason('');
  }, [preferredDispatchId, visible, position?.folio?.id]);

  const selectedDispatch = matchingDispatches.find((dispatch) => dispatch.id === dispatchId);
  const selectedDetail = selectedDispatch?.items.find((detail) => detail.item.id === material?.item.id);
  const reservedForSelectedDispatch = reservationForFolio(
    selectedDispatch,
    material?.item.id,
    position?.folio?.id,
  );
  const currentAmount = Number(material?.cantidad_actual ?? 0);
  const freeAmount = Number(material?.cantidad_disponible ?? 0);
  const pendingAmount = selectedDetail
    ? Number(selectedDetail.cantidad_pendiente)
    : freeAmount;
  const dispatchableForSelectedOrder = Math.min(
    currentAmount,
    freeAmount + reservedForSelectedDispatch,
  );
  const followsFifo = selectedDetail?.sugerencias_fifo[0]?.folio_id === position?.folio?.id
    && Number(amount || 0) <= reservedForSelectedDispatch;
  const maximum = Math.max(
    0,
    Math.min(dispatchableForSelectedOrder, pendingAmount),
  );

  async function submit() {
    const parsed = Number(amount);
    if (parsed <= 0 || parsed > maximum || !dispatchId || (!followsFifo && fifoReason.trim().length < 5)) return;
    await onConfirm({ cantidad: parsed, despacho_id: dispatchId, ...(!followsFifo ? { motivo_excepcion_fifo: fifoReason.trim() } : {}) });
  }

  return (
    <Modal animationType="fade" onRequestClose={onCancel} transparent visible={visible}>
      <SafeAreaView edges={['top', 'right', 'bottom', 'left']} style={styles.modalSafeArea}>
        <View style={[styles.backdrop, compact && styles.backdropCompact]}>
          <View style={[styles.dialog, compact && styles.dialogCompact]}>
            <DialogHeading
              compact={compact}
              eyebrow="DESPACHO DE MATERIALES"
              onClose={onCancel}
              subtitle={`${position?.folio?.numero_folio ?? ''} · ${position?.etiqueta ?? ''}`}
              title={material ? `${material.item.cliente.temporada.codigo} · ${material.item.cliente.codigo} · ${material.item.nombre}` : 'Retirar material'}
            />

            <PrivateMaterialThumbnail photo={selectedDetail?.item.foto_principal} baseUrl={baseUrl} token={token} />
            <View style={styles.materialBalance}>
              <View><Text style={styles.label}>SALDO ACTUAL</Text><Text style={styles.materialBalanceValue}>{material?.cantidad_actual ?? '0'} {material?.unidad_medida}</Text></View>
              <View><Text style={styles.label}>DISPONIBLE LIBRE</Text><Text style={styles.materialBalanceValue}>{material?.cantidad_disponible ?? '0'} {material?.unidad_medida}</Text></View>
              <View><Text style={styles.label}>RESERVA DE ESTA ORDEN</Text><Text style={styles.materialBalanceValue}>{reservedForSelectedDispatch} {material?.unidad_medida}</Text></View>
            </View>

            <Text style={styles.label}>Orden de despacho asignada</Text>
            <ScrollView horizontal showsHorizontalScrollIndicator>
              <View style={styles.choiceRow}>
                {prioritizedDispatches.map((dispatch) => (
                  <Choice
                    active={dispatchId === dispatch.id}
                    key={dispatch.id}
                    label={materialDispatchLabel(dispatch, material?.item.id, position?.folio?.id, material?.unidad_medida)}
                    onPress={() => { setDispatchId(dispatch.id); setAmount(''); setFifoReason(''); }}
                  />
                ))}
              </View>
            </ScrollView>

            {dispatchId && (
              <View style={[styles.fifoNotice, followsFifo === false && styles.fifoNoticeOverride]}>
                <Text style={styles.fifoNoticeTitle}>{followsFifo ? 'Folio sugerido por FIFO' : 'Selección distinta de FIFO'}</Text>
                <Text style={styles.fifoNoticeText}>{selectedDispatch?.destino.nombre} · {selectedDispatch?.destino.centro_costo} · pendiente {selectedDetail?.cantidad_pendiente} {selectedDetail?.unidad_medida}</Text>
                <Text style={styles.fifoReservationText}>Asignado a este folio: {reservedForSelectedDispatch} {selectedDetail?.unidad_medida}</Text>
              </View>
            )}

            <View style={[styles.field, styles.wide, styles.materialField]}>
              <Text style={styles.label}>Cantidad a despachar * · máximo {maximum} {material?.unidad_medida}</Text>
              <TextInput keyboardType="decimal-pad" onChangeText={setAmount} placeholder="0" placeholderTextColor="#627C88" selectionColor={colors.cyan} style={styles.input} value={amount} />
            </View>

            {!followsFifo && dispatchId ? (
              <View style={[styles.field, styles.wide, styles.materialField]}>
                <Text style={styles.label}>Motivo de excepción FIFO * (mínimo 5 caracteres)</Text>
                <TextInput multiline onChangeText={setFifoReason} placeholder="Explica por qué se omite la reserva FIFO" placeholderTextColor="#627C88" selectionColor={colors.cyan} style={styles.input} value={fifoReason} />
              </View>
            ) : null}

            <ModalError message={error} />
            <DialogActions busy={busy} compact={compact} confirmDisabled={Number(amount) <= 0 || Number(amount) > maximum || !dispatchId || (!followsFifo && fifoReason.trim().length < 5)} confirmLabel="Confirmar despacho" onCancel={onCancel} onConfirm={submit} />
          </View>
        </View>
      </SafeAreaView>
    </Modal>
  );
}

function reservationForFolio(
  dispatch: MaterialDispatch | undefined,
  itemId: string | undefined,
  folioId: string | undefined,
): number {
  if (!dispatch || !itemId || !folioId) return 0;
  const detail = dispatch.items.find((item) => item.item.id === itemId);
  const reservation = detail?.sugerencias_fifo.find((suggestion) => suggestion.folio_id === folioId);

  return Number(reservation?.cantidad ?? 0);
}

function materialDispatchLabel(
  dispatch: MaterialDispatch,
  itemId: string | undefined,
  folioId: string | undefined,
  unit: string | undefined,
): string {
  const reservation = reservationForFolio(dispatch, itemId, folioId);
  const fifo = reservation > 0 ? ` · FIFO ${reservation} ${unit ?? ''}` : '';

  return `${dispatch.codigo} · ${dispatch.destino.nombre}${fifo}`;
}

function DestinationPlanMap({
  compact,
  onSelectPosition,
  originClientId,
  originPositionId,
  plan,
  selectedPositionId,
}: {
  compact: boolean;
  onSelectPosition: (position: Position) => void;
  originClientId: string | null;
  originPositionId: string | null;
  plan: CameraPlan;
  selectedPositionId: string | null;
}) {
  const levels = [...new Set(plan.posiciones.map((position) => position.nivel))].sort((a, b) => a - b);
  const bands = orderBandsForCamera(
    plan,
    [...new Set(plan.posiciones.map((position) => position.banda))],
  );
  const maxPosition = Math.max(1, ...plan.posiciones.map((position) => position.posicion));
  const [selectedLevel, setSelectedLevel] = useState(levels[0] ?? 1);

  useEffect(() => {
    setSelectedLevel(levels[0] ?? 1);
  }, [plan.id, plan.version_plano]);

  return (
    <View style={styles.destinationMap}>
      {levels.length > 1 && (
        <View style={styles.destinationLevelPicker}>
          {levels.map((level) => (
            <Pressable
              key={level}
              onPress={() => setSelectedLevel(level)}
              style={[
                styles.destinationLevelButton,
                selectedLevel === level && styles.destinationLevelButtonActive,
              ]}
            >
              <Text style={[
                styles.destinationLevelText,
                selectedLevel === level && styles.destinationLevelTextActive,
              ]}>
                NIVEL {level}
              </Text>
            </Pressable>
          ))}
        </View>
      )}

      <View style={styles.destinationOrientationRow}>
        <Text style={styles.destinationOrientation}>↑ FONDO</Text>
        <Text style={styles.destinationOrientationHint}>
          Bandas verticales · {bandNumberingLabel(plan)} · P01 se ocupa primero
        </Text>
      </View>

      <ScrollView
        nestedScrollEnabled
        style={[styles.destinationScroll, compact && styles.destinationScrollCompact]}
      >
        <ScrollView
          contentContainerStyle={styles.destinationBandRow}
          horizontal
          nestedScrollEnabled
          showsHorizontalScrollIndicator
        >
          {bands.map((band) => (
            <DestinationBand
              band={band}
              key={band}
              level={selectedLevel}
              maxPosition={maxPosition}
              onSelectPosition={onSelectPosition}
              originClientId={originClientId}
              originPositionId={originPositionId}
              plan={plan}
              selectedPositionId={selectedPositionId}
            />
          ))}
        </ScrollView>
      </ScrollView>

      <Text style={styles.destinationEntrance}>↓ ENTRADA</Text>
    </View>
  );
}

function DestinationBand({
  band,
  level,
  maxPosition,
  onSelectPosition,
  originClientId,
  originPositionId,
  plan,
  selectedPositionId,
}: {
  band: number;
  level: number;
  maxPosition: number;
  onSelectPosition: (position: Position) => void;
  originClientId: string | null;
  originPositionId: string | null;
  plan: CameraPlan;
  selectedPositionId: string | null;
}) {
  const positions = Array.from({ length: maxPosition }, (_, index) => index + 1);

  return (
    <View style={styles.destinationBand}>
      <Text style={styles.destinationBandHeading}>BANDA {String(band).padStart(2, '0')}</Text>
      {positions.map((number) => {
        const position = plan.posiciones.find((candidate) => (
          candidate.nivel === level
          && candidate.banda === band
          && candidate.posicion === number
        ));

        if (!position) return <View key={number} style={styles.destinationGap} />;

        const isOrigin = position.id === originPositionId;
        const available = position.estado === 'activa'
          && !isOrigin
          && !position.reservada
          && positionAcceptsMaterialClient(position, originClientId);
        const status = isOrigin
          ? 'ORIGEN'
          : position.ocupada
            ? available
              ? `BULTO COMPATIBLE · ${position.folios?.length ?? 1} ítems`
              : `OCUPADA${position.folio?.numero_folio ? ` · ${position.folio.numero_folio}` : ''}`
            : position.reservada
              ? 'RESERVADA · TAREA EN EJECUCIÓN'
              : position.estado === 'activa' ? 'LIBRE' : 'NO DISPONIBLE';

        return (
          <Pressable
            accessibilityLabel={`${position.etiqueta ?? `Banda ${band}, posición ${number}`}, ${status}`}
            accessibilityRole="button"
            disabled={!available}
            key={position.id}
            onPress={() => onSelectPosition(position)}
            style={({ pressed }) => [
              styles.destination,
              !available && styles.destinationUnavailable,
              selectedPositionId === position.id && styles.destinationSelected,
              pressed && styles.pressed,
            ]}
          >
            <Text style={styles.destinationPositionNumber}>P{String(number).padStart(2, '0')}</Text>
            <Text numberOfLines={1} style={styles.destinationLabel}>{position.etiqueta}</Text>
            <Text numberOfLines={1} style={styles.destinationMeta}>{status}</Text>
          </Pressable>
        );
      })}
    </View>
  );
}

function positionAcceptsMaterialClient(position: Position, clientId: string | null): boolean {
  if (position.reservada) return false;
  if (!position.ocupada) return true;
  if (!clientId) return false;
  const folios = position.folios?.length ? position.folios : (position.folio ? [position.folio] : []);

  return folios.length > 0 && folios.every((folio) => folio.material?.item.cliente.id === clientId);
}

function DialogHeading({
  compact,
  eyebrow,
  onClose,
  subtitle,
  title,
}: {
  compact?: boolean;
  eyebrow: string;
  onClose: () => void;
  subtitle: string;
  title: string;
}) {
  return (
    <View style={[styles.heading, compact && styles.headingCompact]}>
      <View style={styles.headingCopy}>
        <Text style={styles.eyebrow}>{eyebrow}</Text>
        <Text style={[styles.title, compact && styles.titleCompact]}>{title}</Text>
        <Text style={styles.subtitle}>{subtitle}</Text>
      </View>
      <Pressable accessibilityLabel="Cerrar" onPress={onClose} style={styles.close}>
        <Text style={styles.closeText}>×</Text>
      </Pressable>
    </View>
  );
}

function FormField({
  label,
  wide,
  ...props
}: {
  autoCapitalize?: 'characters';
  editable?: boolean;
  label: string;
  onChangeText: (value: string) => void;
  onSubmitEditing?: () => void;
  placeholder: string;
  returnKeyType?: 'done';
  value: string;
  wide?: boolean;
}) {
  return (
    <View style={[styles.field, wide && styles.wide]}>
      <Text style={styles.label}>{label}</Text>
      <TextInput
        {...props}
        placeholderTextColor="#627C88"
        selectionColor={colors.cyan}
        style={[styles.input, props.editable === false && styles.inputLocked]}
      />
    </View>
  );
}

function Choice({
  active,
  disabled = false,
  label,
  onPress,
}: {
  active: boolean;
  disabled?: boolean;
  label: string;
  onPress: () => void;
}) {
  return (
    <Pressable
      disabled={disabled}
      onPress={onPress}
      style={({ pressed }) => [
        styles.choice,
        active && styles.choiceActive,
        disabled && styles.choiceLocked,
        pressed && styles.pressed,
      ]}
    >
      <Text style={[styles.choiceText, active && styles.choiceTextActive]}>{label}</Text>
    </Pressable>
  );
}

function ModalError({ message }: { message: string }) {
  if (!message) return null;

  return (
    <View accessibilityLiveRegion="assertive" style={styles.modalError}>
      <Text style={styles.modalErrorTitle}>No fue posible confirmar la operación</Text>
      <Text style={styles.modalErrorText}>{message}</Text>
    </View>
  );
}

function DialogActions({
  busy,
  compact,
  confirmDisabled,
  confirmLabel,
  onCancel,
  onConfirm,
}: {
  busy: boolean;
  compact?: boolean;
  confirmDisabled: boolean;
  confirmLabel: string;
  onCancel: () => void;
  onConfirm: () => void;
}) {
  return (
    <View style={[styles.actions, compact && styles.actionsCompact]}>
      <Pressable disabled={busy} onPress={onCancel} style={[styles.cancel, compact && styles.buttonCompact]}>
        <Text style={styles.cancelText}>Cancelar</Text>
      </Pressable>
      <Pressable
        disabled={busy || confirmDisabled}
        onPress={onConfirm}
        style={[
          styles.confirm,
          compact && styles.buttonCompact,
          (busy || confirmDisabled) && styles.disabled,
        ]}
      >
        {busy ? <ActivityIndicator color={colors.accentText} /> : <Text style={styles.confirmText}>{confirmLabel}</Text>}
      </Pressable>
    </View>
  );
}

const styles = StyleSheet.create({
  modalSafeArea: { flex: 1, backgroundColor: 'rgba(0,0,0,0.72)' },
  backdrop: {
    flex: 1,
    padding: 24,
    alignItems: 'center',
    justifyContent: 'center',
  },
  backdropCompact: { padding: 8 },
  dialog: {
    width: '92%',
    maxWidth: 820,
    maxHeight: '94%',
    padding: 20,
    borderRadius: 18,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.panel,
  },
  dialogCompact: { width: '100%', maxHeight: '100%', padding: 14, borderRadius: 14 },
  moveDialog: { maxWidth: 900 },
  heading: { marginBottom: 16, flexDirection: 'row', justifyContent: 'space-between', gap: 12 },
  headingCompact: { marginBottom: 10 },
  headingCopy: { flex: 1 },
  eyebrow: { color: colors.cyan, fontSize: 13, fontWeight: '900', letterSpacing: 1.2 },
  title: { marginTop: 4, color: colors.text, fontSize: 23, fontWeight: '900' },
  titleCompact: { fontSize: 19 },
  subtitle: { marginTop: 4, color: colors.muted, fontSize: 14 },
  close: {
    width: 36,
    height: 36,
    borderRadius: 10,
    borderWidth: 1,
    borderColor: colors.border,
    alignItems: 'center',
    justifyContent: 'center',
  },
  closeText: { color: colors.text, fontSize: 24, lineHeight: 25 },
  formScroll: { flexShrink: 1 },
  form: { flexDirection: 'row', flexWrap: 'wrap', gap: 12, paddingBottom: 2 },
  field: { width: '48%', gap: 6 },
  wide: { width: '100%' },
  label: { color: colors.text, fontSize: 13, fontWeight: '800' },
  input: {
    height: 44,
    paddingHorizontal: 12,
    borderRadius: 10,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.background,
    color: colors.text,
    fontSize: 12,
  },
  inputLocked: { backgroundColor: colors.panelStrong, color: colors.muted },
  lookupStatus: {
    width: '100%',
    minHeight: 38,
    paddingHorizontal: 10,
    paddingVertical: 8,
    borderRadius: 9,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.background,
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
  },
  lookupStatusSuccess: { borderColor: colors.green, backgroundColor: '#12372F' },
  lookupStatusWarning: { borderColor: colors.red, backgroundColor: '#421B21' },
  lookupStatusText: { flex: 1, color: colors.muted, fontSize: 13, lineHeight: 17 },
  lookupStatusTextSuccess: { color: '#B8F0CC' },
  lookupStatusTextWarning: { color: '#FFB7B7' },
  suggestionList: { width: '100%', borderWidth: 1, borderColor: colors.border, borderRadius: 10, backgroundColor: colors.panel, padding: 8, gap: 4 },
  suggestionTitle: { color: colors.muted, fontSize: 11, fontWeight: '800', paddingHorizontal: 8, paddingVertical: 3 },
  suggestionRow: { borderRadius: 7, paddingVertical: 8, paddingHorizontal: 10, backgroundColor: colors.panelStrong },
  suggestionNumber: { color: colors.cyan, fontSize: 14, fontWeight: '800' },
  suggestionItem: { color: colors.muted, fontSize: 12, marginTop: 2 },
  choiceRow: { flexDirection: 'row', gap: 8 },
  choice: {
    minHeight: 38,
    paddingHorizontal: 12,
    borderRadius: 9,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.background,
    alignItems: 'center',
    justifyContent: 'center',
  },
  choiceActive: { borderColor: colors.cyan, backgroundColor: colors.selected },
  choiceLocked: { opacity: 0.72 },
  choiceText: { color: colors.muted, fontSize: 13, fontWeight: '800' },
  choiceTextActive: { color: colors.cyan },
  destinationHeading: {
    marginTop: 16,
    marginBottom: 8,
    flexDirection: 'row',
    justifyContent: 'space-between',
  },
  destinationCount: { color: colors.muted, fontSize: 12 },
  destinationMap: {
    minHeight: 100,
    padding: 8,
    borderRadius: 11,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.background,
  },
  destinationLevelPicker: { marginBottom: 8, flexDirection: 'row', gap: 6 },
  destinationLevelButton: {
    paddingHorizontal: 9,
    paddingVertical: 6,
    borderRadius: 7,
    borderWidth: 1,
    borderColor: colors.border,
  },
  destinationLevelButtonActive: { borderColor: colors.cyan, backgroundColor: colors.cyanDark },
  destinationLevelText: { color: colors.muted, fontSize: 12, fontWeight: '900' },
  destinationLevelTextActive: { color: colors.cyan },
  destinationOrientationRow: {
    marginBottom: 6,
    flexDirection: 'row',
    justifyContent: 'space-between',
    gap: 10,
  },
  destinationOrientation: { color: colors.cyan, fontSize: 12, fontWeight: '900' },
  destinationOrientationHint: { color: colors.muted, fontSize: 11 },
  destinationScroll: { minHeight: 80, maxHeight: 270, flexShrink: 1 },
  destinationScrollCompact: { maxHeight: 210 },
  destinationBandRow: { flexDirection: 'row', alignItems: 'flex-start', gap: 7, paddingBottom: 3 },
  destinationBand: {
    width: 122,
    padding: 6,
    borderRadius: 9,
    borderWidth: 1,
    borderColor: colors.borderSoft,
    backgroundColor: colors.panel,
    gap: 5,
  },
  destinationBandHeading: {
    marginHorizontal: -6,
    marginTop: -6,
    paddingVertical: 8,
    borderBottomWidth: 1,
    borderBottomColor: colors.border,
    borderTopLeftRadius: 8,
    borderTopRightRadius: 8,
    backgroundColor: colors.panelStrong,
    color: colors.cyan,
    textAlign: 'center',
    fontSize: 13,
    fontWeight: '900',
  },
  destination: {
    height: 66,
    padding: 7,
    borderRadius: 8,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.background,
    justifyContent: 'space-between',
  },
  destinationSelected: { borderColor: colors.cyan, backgroundColor: colors.selected },
  destinationUnavailable: { opacity: 0.48, backgroundColor: colors.panelStrong },
  destinationGap: { height: 66 },
  destinationPositionNumber: { color: colors.cyan, fontSize: 12, fontWeight: '900' },
  destinationLabel: { color: colors.text, fontSize: 13, fontWeight: '900' },
  destinationMeta: { color: colors.muted, fontSize: 11 },
  destinationEntrance: { marginTop: 6, color: colors.cyan, fontSize: 12, fontWeight: '900' },
  loader: { height: 150 },
  empty: { paddingVertical: 30, color: colors.muted, fontSize: 14, textAlign: 'center' },
  emptyInline: { color: colors.muted, fontSize: 12 },
  materialBalance: { marginBottom: 14, flexDirection: 'row', gap: 10 },
  materialBalanceValue: { marginTop: 4, color: colors.text, fontSize: 17, fontWeight: '900' },
  materialField: { marginTop: 14 },
  fifoNotice: { marginTop: 14, padding: 10, borderRadius: 9, borderWidth: 1, borderColor: colors.green, backgroundColor: '#12372F' },
  fifoNoticeOverride: { borderColor: colors.amber, backgroundColor: colors.amberDark },
  fifoNoticeTitle: { color: colors.text, fontSize: 13, fontWeight: '900' },
  fifoNoticeText: { marginTop: 3, color: colors.muted, fontSize: 12 },
  fifoReservationText: { marginTop: 5, color: colors.text, fontSize: 13, fontWeight: '900' },
  modalError: {
    marginTop: 10,
    padding: 10,
    borderRadius: 9,
    borderWidth: 1,
    borderColor: colors.red,
    backgroundColor: '#421B21',
  },
  modalErrorTitle: { color: '#FFD0D0', fontSize: 13, fontWeight: '900' },
  modalErrorText: { marginTop: 3, color: '#FFB7B7', fontSize: 13, lineHeight: 17 },
  actions: { marginTop: 18, flexDirection: 'row', justifyContent: 'flex-end', gap: 10 },
  actionsCompact: { marginTop: 10 },
  cancel: {
    minWidth: 110,
    height: 44,
    borderRadius: 10,
    borderWidth: 1,
    borderColor: colors.border,
    alignItems: 'center',
    justifyContent: 'center',
  },
  cancelText: { color: colors.text, fontSize: 14, fontWeight: '900' },
  confirm: {
    minWidth: 165,
    height: 44,
    paddingHorizontal: 14,
    borderRadius: 10,
    backgroundColor: colors.cyan,
    alignItems: 'center',
    justifyContent: 'center',
  },
  confirmText: { color: colors.accentText, fontSize: 14, fontWeight: '900' },
  buttonCompact: { height: 40 },
  disabled: { opacity: 0.4 },
  pressed: { opacity: 0.72 },
});
