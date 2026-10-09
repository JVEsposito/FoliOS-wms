import * as Crypto from 'expo-crypto';
import { ReactNode, useEffect, useMemo, useRef, useState } from 'react';
import {
  ActivityIndicator,
  FlatList,
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

import { AuthSession } from '../domain/estiba';
import { PrivateMaterialThumbnail, MaterialItemPhoto } from '../components/PrivateMaterialThumbnail';
import { MaterialLabelPrintPanel } from '../components/MaterialLabelPrintPanel';
import {
  CreateMaterialReceptionPayload,
  MaterialReception,
  MaterialReceptionCatalog,
  MaterialReceptionState,
  PendingReceptionFolio,
  ReceptionDraftDetail,
  ReceptionDraftPackage,
} from '../domain/materialReception';
import { createMaterialReceptionApi } from '../services/materialReceptionApi';
import { colors } from '../theme/colors';

type Props = {
  auth: AuthSession;
  baseUrl: string;
  onLogout: () => void;
};

type Tab = 'nueva' | 'historial' | 'pendientes';
type HistoryFilter = MaterialReceptionState | 'todas';
type Form = {
  cliente_id: string;
  proveedor_material_id: string;
  numero_guia_despacho: string;
  fecha_documento: string;
  orden_compra: string;
  patente: string;
  transportista: string;
  observacion: string;
  detalles: ReceptionDraftDetail[];
};
type Option = { id: string; label: string; description?: string; photo?: MaterialItemPhoto | null };
const MAX_PACKAGES_PER_DETAIL = 500;

const EMPTY_CATALOG: MaterialReceptionCatalog = {
  temporada: null,
  clientes: [],
  proveedores: [],
  items: [],
};

export function MaterialReceptionScreen({ auth, baseUrl, onLogout }: Props) {
  const compact = useWindowDimensions().width < 900;
  const capabilities = auth.usuario.capacidades;
  const canManage = capabilities.puede_gestionar_recepciones_materiales === true;
  const canAnnul = capabilities.puede_anular_recepciones_materiales === true;
  const api = useMemo(() => createMaterialReceptionApi(baseUrl, auth.token), [auth.token, baseUrl]);
  const confirmationOperationIds = useRef(new Map<string, string>());
  const annulmentOperationIds = useRef(new Map<string, { operationId: string; reason: string }>());

  const [tab, setTab] = useState<Tab>(canManage ? 'nueva' : 'historial');
  const [catalog, setCatalog] = useState<MaterialReceptionCatalog>(EMPTY_CATALOG);
  const [receptions, setReceptions] = useState<MaterialReception[]>([]);
  const [pending, setPending] = useState<PendingReceptionFolio[]>([]);
  const [selected, setSelected] = useState<MaterialReception | null>(null);
  const [filter, setFilter] = useState<HistoryFilter>('todas');
  const [form, setForm] = useState<Form>(() => emptyForm());
  const [operationId, setOperationId] = useState(() => Crypto.randomUUID());
  const [editingDraft, setEditingDraft] = useState<{ id: string; version: number; guide: string } | null>(null);
  const [annulReason, setAnnulReason] = useState('');
  const [busy, setBusy] = useState(true);
  const [actionBusy, setActionBusy] = useState(false);
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');

  useEffect(() => {
    void loadAll();
  }, [api]);

  function confirmationOperationId(receptionId: string) {
    const existing = confirmationOperationIds.current.get(receptionId);
    if (existing) return existing;

    const operationId = Crypto.randomUUID();
    confirmationOperationIds.current.set(receptionId, operationId);
    return operationId;
  }

  function annulmentOperationId(receptionId: string, reason: string) {
    const existing = annulmentOperationIds.current.get(receptionId);
    if (existing?.reason === reason) return existing.operationId;

    const operationId = Crypto.randomUUID();
    annulmentOperationIds.current.set(receptionId, { operationId, reason });
    return operationId;
  }

  async function loadAll() {
    setBusy(true);
    setError('');
    try {
      const [loadedCatalog, loadedReceptions, loadedPending] = await Promise.all([
        api.catalog(),
        api.list(),
        api.pendingFolios(),
      ]);
      setCatalog(loadedCatalog);
      setReceptions(loadedReceptions);
      setPending(loadedPending);
    } catch (reason) {
      setError(errorMessage(reason));
    } finally {
      setBusy(false);
    }
  }

  async function refresh(nextTab = tab, nextFilter = filter) {
    setBusy(true);
    setError('');
    setMessage('');
    try {
      if (nextTab === 'nueva') setCatalog(await api.catalog());
      if (nextTab === 'historial') {
        setReceptions(await api.list(nextFilter === 'todas' ? undefined : nextFilter));
      }
      if (nextTab === 'pendientes') setPending(await api.pendingFolios());
    } catch (reason) {
      setError(errorMessage(reason));
    } finally {
      setBusy(false);
    }
  }

  function switchTab(next: Tab) {
    if (next === 'nueva') {
      setEditingDraft(null);
      setForm(emptyForm());
      setOperationId(Crypto.randomUUID());
    }
    setTab(next);
    setSelected(null);
    setError('');
    setMessage('');
    void refresh(next);
  }

  function changeClient(clientId: string) {
    setForm((current) => ({
      ...current,
      cliente_id: clientId,
      proveedor_material_id: '',
      detalles: current.detalles.map((detail) => ({ ...detail, item_material_id: '' })),
    }));
  }

  function changeSupplier(supplierId: string) {
    setForm((current) => ({
      ...current,
      proveedor_material_id: supplierId,
      detalles: current.detalles.map((detail) => ({ ...detail, item_material_id: '' })),
    }));
  }

  function updateDetail(id: string, patch: Partial<ReceptionDraftDetail>) {
    setForm((current) => ({
      ...current,
      detalles: current.detalles.map((detail) => detail.local_id === id
        ? { ...detail, ...patch }
        : detail),
    }));
  }

  function updateAllPackages(detailId: string, patch: Partial<ReceptionDraftPackage>) {
    setForm((current) => ({
      ...current,
      detalles: current.detalles.map((detail) => detail.local_id === detailId
        ? {
          ...detail,
          bultos: (detail.bultos.length ? detail.bultos : [emptyPackage()])
            .map((itemPackage) => ({ ...itemPackage, ...patch })),
        }
        : detail),
    }));
  }

  function addDetail() {
    setForm((current) => ({ ...current, detalles: [...current.detalles, emptyDetail()] }));
  }

  function removeDetail(id: string) {
    setForm((current) => ({
      ...current,
      detalles: current.detalles.length === 1
        ? current.detalles
        : current.detalles.filter((detail) => detail.local_id !== id),
    }));
  }

  function calculatePackages(detail: ReceptionDraftDetail, showIncompleteError = true) {
    if (!detail.cantidad_contada.trim() || !detail.cantidad_por_bulto.trim()) {
      if (showIncompleteError) setError('Ingresa la cantidad total recibida y la cantidad por bulto.');
      return;
    }

    try {
      const bultos = distributePackages(detail);
      updateDetail(detail.local_id, { bultos });
      setError('');
    } catch (reason) {
      if (showIncompleteError) setError(errorMessage(reason));
    }
  }

  async function submit(confirmImmediately: boolean, repeatedItemsConfirmed = false) {
    let payload: CreateMaterialReceptionPayload;
    try {
      payload = buildPayload(form, operationId);
      const client = catalog.clientes.find((candidate) => candidate.id === form.cliente_id);
      if (confirmImmediately && !client?.codigo_folio_materiales) {
        throw new Error(
          'El cliente no tiene código corto de folios. Puedes guardar el borrador, pero no confirmarlo.',
        );
      }
    } catch (reason) {
      setError(errorMessage(reason));
      return;
    }

    const repeatedItems = findRepeatedItems(form.detalles);
    if (repeatedItems.length > 0 && !repeatedItemsConfirmed) {
      const summary = repeatedItems.map(({ itemId, lines }) => {
        const item = catalog.items.find((candidate) => candidate.id === itemId);
        const label = item ? `${item.codigo} · ${item.nombre}` : 'Ítem seleccionado';

        return `• ${label}: líneas ${lines.join(', ')}`;
      }).join('\n');

      Alert.alert(
        'Ítem ingresado más de una vez',
        `${summary}\n\nLas líneas se mantendrán separadas y sus bultos generarán folios independientes. ¿Es correcto?`,
        [
          { text: 'Revisar', style: 'cancel' },
          {
            text: 'Sí, continuar',
            onPress: () => void submit(confirmImmediately, true),
          },
        ],
      );
      return;
    }

    setActionBusy(true);
    setError('');
    setMessage('');
    let reception: MaterialReception | null = null;

    try {
      reception = editingDraft
        ? await api.update(editingDraft.id, {
          ...payload,
          version_conocida: editingDraft.version,
        })
        : await api.create(payload);
      if (confirmImmediately && reception.estado === 'borrador') {
        reception = await api.confirm(
          reception.id,
          confirmationOperationId(reception.id),
          reception.version,
        );
        confirmationOperationIds.current.delete(reception.id);
      }

      const [loadedReceptions, loadedPending] = await Promise.all([
        api.list(),
        api.pendingFolios(),
      ]);
      setReceptions(loadedReceptions);
      setPending(loadedPending);
      setSelected(reception);
      setForm(emptyForm());
      setEditingDraft(null);
      setOperationId(Crypto.randomUUID());
      setFilter('todas');
      setTab('historial');
      setMessage(confirmImmediately
        ? 'Recepción confirmada. Los folios quedaron disponibles para ubicación.'
        : editingDraft
          ? 'Borrador actualizado correctamente.'
          : 'Borrador guardado correctamente.');
    } catch (reason) {
      if (reception) {
        const [historyResult, pendingResult, detailResult] = await Promise.allSettled([
          api.list(),
          api.pendingFolios(),
          api.show(reception.id),
        ]);
        if (historyResult.status === 'fulfilled') setReceptions(historyResult.value);
        if (pendingResult.status === 'fulfilled') setPending(pendingResult.value);

        const latest = detailResult.status === 'fulfilled' ? detailResult.value : reception;
        setSelected(latest);
        setForm(emptyForm());
        setEditingDraft(null);
        setOperationId(Crypto.randomUUID());
        setFilter('todas');
        setTab('historial');

        if (confirmImmediately && latest.estado === 'confirmada') {
          confirmationOperationIds.current.delete(latest.id);
          setMessage('Recepción confirmada. Los folios quedaron disponibles para ubicación.');
        } else if (confirmImmediately) {
          setError(`La recepción quedó guardada en estado ${stateLabel(latest.estado)}, pero no fue posible confirmarla: ${errorMessage(reason)}`);
        } else {
          setMessage(editingDraft
            ? 'Borrador actualizado correctamente.'
            : 'Borrador guardado correctamente.');
        }
      } else {
        setError(errorMessage(reason));
      }
    } finally {
      setActionBusy(false);
    }
  }

  async function openReception(id: string) {
    setActionBusy(true);
    setError('');
    try {
      setSelected(await api.show(id));
      setAnnulReason('');
    } catch (reason) {
      setError(errorMessage(reason));
    } finally {
      setActionBusy(false);
    }
  }

  async function editDraft(reception: MaterialReception) {
    setActionBusy(true);
    setError('');
    setMessage('');
    try {
      const [draft, loadedCatalog] = await Promise.all([
        api.show(reception.id),
        api.catalog(),
      ]);
      if (draft.estado !== 'borrador') {
        throw new Error('La recepción ya no se encuentra en borrador.');
      }
      setCatalog(loadedCatalog);
      setForm(formFromReception(draft));
      setEditingDraft({ id: draft.id, version: draft.version, guide: draft.numero_guia_despacho });
      setOperationId(Crypto.randomUUID());
      setSelected(null);
      setTab('nueva');
      setMessage(`Editando borrador de la guía ${draft.numero_guia_despacho}.`);
    } catch (reason) {
      setError(errorMessage(reason));
    } finally {
      setActionBusy(false);
    }
  }

  function requestConfirm(reception: MaterialReception) {
    Alert.alert(
      'Confirmar recepción',
      'Se generará un folio definitivo por cada bulto. ¿Continuar?',
      [
        { text: 'Cancelar', style: 'cancel' },
        { text: 'Confirmar', onPress: () => void confirmReception(reception) },
      ],
    );
  }

  async function confirmReception(reception: MaterialReception) {
    setActionBusy(true);
    setError('');
    try {
      const confirmed = await api.confirm(
        reception.id,
        confirmationOperationId(reception.id),
        reception.version,
      );
      confirmationOperationIds.current.delete(reception.id);
      setSelected(confirmed);
      setReceptions(await api.list(filter === 'todas' ? undefined : filter));
      setPending(await api.pendingFolios());
      setMessage('Recepción confirmada y folios generados.');
    } catch (reason) {
      setError(errorMessage(reason));
    } finally {
      setActionBusy(false);
    }
  }

  function requestAnnul(reception: MaterialReception) {
    if (annulReason.trim().length < 5) {
      setError('El motivo de anulación debe tener al menos 5 caracteres.');
      return;
    }
    Alert.alert(
      'Anular recepción',
      'La API solo permitirá anularla si los folios siguen intactos y sin ubicación.',
      [
        { text: 'Cancelar', style: 'cancel' },
        { text: 'Anular', style: 'destructive', onPress: () => void annulReception(reception) },
      ],
    );
  }

  async function annulReception(reception: MaterialReception) {
    setActionBusy(true);
    setError('');
    try {
      const reason = annulReason.trim();
      const annulled = await api.annul(
        reception.id,
        annulmentOperationId(reception.id, reason),
        reason,
      );
      annulmentOperationIds.current.delete(reception.id);
      setSelected(annulled);
      setReceptions(await api.list(filter === 'todas' ? undefined : filter));
      setPending(await api.pendingFolios());
      setMessage('Recepción anulada y movimientos compensados.');
    } catch (reason) {
      setError(errorMessage(reason));
    } finally {
      setActionBusy(false);
    }
  }

  const supplierOptions: Option[] = catalog.proveedores
    .filter((supplier) => !form.cliente_id || supplier.cliente_ids.includes(form.cliente_id))
    .map((supplier) => ({ id: supplier.id, label: `${supplier.codigo} · ${supplier.nombre}` }));
  const selectedSupplier = catalog.proveedores.find((supplier) => supplier.id === form.proveedor_material_id);
  const enabledCategories = new Set((selectedSupplier?.categorias || [])
    .filter((assignment) => assignment.cliente_id === form.cliente_id)
    .map((assignment) => assignment.categoria.trim().toLocaleLowerCase('es')));
  const itemOptions: Option[] = catalog.items
    .filter((item) => item.cliente_id === form.cliente_id
      && Boolean(item.categoria)
      && enabledCategories.has(item.categoria!.trim().toLocaleLowerCase('es')))
    .map((item) => ({
      id: item.id,
      label: `${item.codigo} · ${item.nombre}`,
      photo: item.foto_principal,
      description: `${item.categoria ?? 'Sin categoría'} · ${item.categoria_operacional_etiqueta} · ${item.unidad_medida}`,
    }));

  return (
    <View style={styles.screen}>
      <View style={[styles.header, compact && styles.headerCompact]}>
        <View style={styles.headerCopy}>
          <Text style={styles.eyebrow}>MATERIALES · RECEPCIÓN</Text>
          <Text style={styles.title}>Ingreso documental y generación de folios</Text>
          <Text style={styles.muted}>
            {auth.usuario.nombre} · {auth.dispositivo.codigo} · {catalog.temporada?.nombre ?? 'Sin temporada activa'}
          </Text>
        </View>
        <View style={styles.row}>
          <Button label="Actualizar" onPress={() => void refresh()} secondary />
          <Button label="Cerrar sesión" onPress={onLogout} danger />
        </View>
      </View>

      <View style={styles.tabs}>
        {canManage ? <TabButton label="Nueva recepción" active={tab === 'nueva'} onPress={() => switchTab('nueva')} /> : null}
        <TabButton label="Recepciones" active={tab === 'historial'} onPress={() => switchTab('historial')} />
        <TabButton label={`Pendientes (${pending.length})`} active={tab === 'pendientes'} onPress={() => switchTab('pendientes')} />
      </View>

      {error ? <Banner text={error} danger /> : null}
      {message ? <Banner text={message} /> : null}

      {busy ? (
        <View style={styles.center}>
          <ActivityIndicator color={colors.cyan} size="large" />
          <Text style={styles.muted}>Consultando la API…</Text>
        </View>
      ) : tab === 'nueva' ? (
        <ScrollView contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled">
          {editingDraft ? (
            <View style={styles.infoCard}>
              <View style={styles.between}>
                <View style={styles.headerCopy}>
                  <Text style={styles.eyebrow}>EDITANDO BORRADOR</Text>
                  <Text style={styles.cardTitle}>Guía {editingDraft.guide} · versión {editingDraft.version}</Text>
                  <Text style={styles.muted}>Los cambios reemplazarán el contenido provisional. Los folios se crearán únicamente al confirmar.</Text>
                </View>
                <Button label="Cancelar edición" onPress={() => switchTab('nueva')} secondary />
              </View>
            </View>
          ) : null}
          {!catalog.temporada ? (
            <Empty title="No existe una temporada activa para materiales" />
          ) : (
            <>
              <Section title="1. Documento y proveedor">
                <View style={[styles.grid, compact && styles.column]}>
                  <Choice
                    label="Cliente"
                    value={form.cliente_id}
                    placeholder="Seleccionar cliente"
                    options={catalog.clientes.map((client) => ({
                      id: client.id,
                      label: `${client.codigo} · ${client.nombre}`,
                      description: client.codigo_folio_materiales
                        ? `Primer folio F${client.codigo_folio_materiales}0000001`
                        : 'Código de folio pendiente',
                    }))}
                    onChange={changeClient}
                  />
                  <Choice
                    label="Proveedor"
                    value={form.proveedor_material_id}
                    placeholder={form.cliente_id ? 'Seleccionar proveedor' : 'Selecciona primero el cliente'}
                    options={supplierOptions}
                    disabled={!form.cliente_id}
                    onChange={changeSupplier}
                  />
                  <Field label="N.º guía despacho" value={form.numero_guia_despacho} onChange={(value) => setForm({ ...form, numero_guia_despacho: value })} placeholder="GD-12345" />
                  <DateField label="Fecha documento" value={form.fecha_documento} onChange={(value) => setForm({ ...form, fecha_documento: value })} />
                  <Field label="Orden de compra" value={form.orden_compra} onChange={(value) => setForm({ ...form, orden_compra: value })} placeholder="Opcional" />
                  <Field label="Patente" value={form.patente} onChange={(value) => setForm({ ...form, patente: value.toUpperCase() })} placeholder="Opcional" />
                  <Field label="Transportista" value={form.transportista} onChange={(value) => setForm({ ...form, transportista: value })} placeholder="Opcional" />
                  <Field label="Observación general" value={form.observacion} onChange={(value) => setForm({ ...form, observacion: value })} placeholder="Opcional" multiline />
                </View>
              </Section>

              <Section title="2. Ítems y bultos físicos">
                <View style={styles.stack}>
                  {form.detalles.map((detail, detailIndex) => {
                    const item = catalog.items.find((candidate) => candidate.id === detail.item_material_id);
                    return (
                      <View key={detail.local_id} style={styles.card}>
                        <View style={styles.between}>
                          <View>
                            <Text style={styles.cardTitle}>Ítem {detailIndex + 1}</Text>
                            <Text style={styles.muted}>{item ? `${item.categoria_operacional_etiqueta} · ${item.unidad_medida}` : 'Selecciona un ítem'}</Text>
                          </View>
                          {form.detalles.length > 1 ? <Button label="Quitar ítem" onPress={() => removeDetail(detail.local_id)} danger small /> : null}
                        </View>
                        <View style={[styles.grid, compact && styles.column]}>
                          <Choice
                            label="Ítem material"
                            value={detail.item_material_id}
                            placeholder={form.proveedor_material_id ? 'Seleccionar ítem' : 'Selecciona primero el proveedor'}
                            options={itemOptions}
                            photoAuth={{ baseUrl, token: auth.token }}
                            disabled={!form.proveedor_material_id}
                            onChange={(value) => updateDetail(detail.local_id, { item_material_id: value })}
                          />
                          <Field label="Cantidad documental" value={detail.cantidad_documental} onChange={(value) => updateDetail(detail.local_id, { cantidad_documental: decimal(value) })} placeholder="0" numeric />
                          <Field
                            label="Cantidad total recibida"
                            value={detail.cantidad_contada}
                            onChange={(value) => updateDetail(detail.local_id, { cantidad_contada: decimal(value) })}
                            onEndEditing={() => calculatePackages(detail, false)}
                            placeholder="Ej. 500"
                            numeric
                          />
                          <Field
                            label={`Cantidad por bulto${item ? ` (${item.unidad_medida})` : ''}`}
                            value={detail.cantidad_por_bulto}
                            onChange={(value) => updateDetail(detail.local_id, { cantidad_por_bulto: decimal(value) })}
                            onEndEditing={() => calculatePackages(detail, false)}
                            placeholder="Ej. 60"
                            numeric
                          />
                          <Readonly label="Bultos calculados" value={packageDistributionLabel(detail)} />
                          <Readonly label="Cantidad aceptada" value={format(sumPackages(detail.bultos))} />
                          <Readonly label="Cantidad rechazada" value={format(rejectedQuantity(detail))} />
                          <Field label="Observación del ítem" value={detail.observacion} onChange={(value) => updateDetail(detail.local_id, { observacion: value })} placeholder="Opcional" multiline />
                        </View>

                        <View style={styles.packageCard}>
                          <View style={styles.between}>
                            <View style={styles.headerCopy}>
                              <Text style={styles.subheading}>Distribución automática</Text>
                              <Text style={styles.cardTitle}>{packageDistributionLabel(detail)}</Text>
                              <Text style={styles.muted}>Cada bulto generará un folio individual al confirmar la recepción.</Text>
                            </View>
                            <Button label="Calcular bultos" onPress={() => calculatePackages(detail)} secondary small />
                          </View>
                          <View style={[styles.grid, compact && styles.column]}>
                            <Field
                              label="Lote proveedor · todos los bultos"
                              value={packageTemplate(detail).lote_proveedor}
                              onChange={(value) => updateAllPackages(detail.local_id, { lote_proveedor: value })}
                              placeholder="Opcional"
                            />
                            <DateField
                              label="Fecha fabricación · todos"
                              value={packageTemplate(detail).fecha_fabricacion}
                              onChange={(value) => updateAllPackages(detail.local_id, { fecha_fabricacion: value })}
                              optional
                            />
                            <DateField
                              label="Fecha vencimiento · todos"
                              value={packageTemplate(detail).fecha_vencimiento}
                              onChange={(value) => updateAllPackages(detail.local_id, { fecha_vencimiento: value })}
                              optional
                            />
                          </View>
                          <Pressable
                            onPress={() => updateAllPackages(detail.local_id, {
                              bloqueado: !packageTemplate(detail).bloqueado,
                              motivo_bloqueo: packageTemplate(detail).bloqueado ? '' : packageTemplate(detail).motivo_bloqueo,
                            })}
                            style={[styles.toggle, packageTemplate(detail).bloqueado && styles.toggleBlocked]}
                          >
                            <Text style={[styles.toggleText, packageTemplate(detail).bloqueado && styles.redText]}>
                              {packageTemplate(detail).bloqueado ? 'Todos los bultos bloqueados' : 'Bultos sin bloqueo'}
                            </Text>
                          </Pressable>
                          {packageTemplate(detail).bloqueado ? (
                            <Field
                              label="Motivo del bloqueo · todos los bultos"
                              value={packageTemplate(detail).motivo_bloqueo}
                              onChange={(value) => updateAllPackages(detail.local_id, { motivo_bloqueo: value })}
                              placeholder="Obligatorio"
                              multiline
                            />
                          ) : null}
                        </View>
                      </View>
                    );
                  })}
                </View>
                <Button label="+ Agregar otro ítem" onPress={addDetail} secondary />
              </Section>

              <View style={styles.submitBar}>
                <View style={styles.headerCopy}>
                  <Text style={styles.cardTitle}>Operación idempotente</Text>
                  <Text style={styles.muted}>Un reintento de red no duplicará la recepción.</Text>
                </View>
                <View style={styles.row}>
                  <Button label={editingDraft ? 'Actualizar borrador' : 'Guardar borrador'} onPress={() => void submit(false)} secondary />
                  <Button label={editingDraft ? 'Actualizar y confirmar' : 'Crear y confirmar'} onPress={() => void submit(true)} />
                </View>
              </View>
            </>
          )}
        </ScrollView>
      ) : tab === 'historial' ? (
        selected ? (
          <ReceptionDetail
            reception={selected}
            canManage={canManage}
            canAnnul={canAnnul}
            canPrint={capabilities.puede_imprimir_etiquetas_materiales === true}
            api={api}
            deviceId={auth.dispositivo.id}
            annulReason={annulReason}
            onAnnulReason={setAnnulReason}
             onBack={() => setSelected(null)}
             onEdit={() => void editDraft(selected)}
             onConfirm={() => requestConfirm(selected)}
            onAnnul={() => requestAnnul(selected)}
          />
        ) : (
          <View style={styles.fill}>
            <View style={styles.filters}>
              {(['todas', 'borrador', 'confirmada', 'anulada'] as const).map((state) => (
                <TabButton
                  key={state}
                  label={stateLabel(state)}
                  active={filter === state}
                  onPress={() => {
                    setFilter(state);
                    void refresh('historial', state);
                  }}
                />
              ))}
            </View>
            <ScrollView contentContainerStyle={styles.content}>
              {receptions.length ? receptions.map((reception) => (
                <Pressable key={reception.id} onPress={() => void openReception(reception.id)} style={styles.listCard}>
                  <View style={styles.headerCopy}>
                    <Text style={styles.linkText}>Guía {reception.numero_guia_despacho}</Text>
                    <Text style={styles.cardTitle}>{reception.cliente?.nombre ?? 'Cliente sin datos'}</Text>
                    <Text style={styles.muted}>{reception.proveedor?.nombre ?? 'Proveedor sin datos'} · {formatDateCL(reception.fecha_documento)}</Text>
                  </View>
                  <Badge state={reception.estado} />
                </Pressable>
              )) : <Empty title="No hay recepciones para este filtro" />}
            </ScrollView>
          </View>
        )
      ) : (
        <ScrollView contentContainerStyle={styles.content}>
          <View style={styles.infoCard}>
            <Text style={styles.cardTitle}>Folios listos para primera ubicación</Text>
            <Text style={styles.muted}>Cambia al módulo Operación frigorífico, abre una cámara de materiales y ubica cada folio.</Text>
          </View>
          {pending.length ? pending.map((folio) => (
            <View key={folio.folio_id} style={styles.listCard}>
              <View style={styles.folioBox}>
                <Text style={styles.folio}>{folio.numero_folio}</Text>
                <Text style={[styles.smallCaps, folio.bloqueado && styles.redText]}>{folio.bloqueado ? 'BLOQUEADO' : 'PENDIENTE'}</Text>
              </View>
              <View style={styles.headerCopy}>
                <Text style={styles.cardTitle}>{folio.item.codigo} · {folio.item.nombre}</Text>
                <Text style={styles.muted}>{folio.cantidad_actual} {folio.unidad_medida} · Lote {folio.lote_proveedor ?? '—'}</Text>
                <Text style={styles.muted}>{folio.cliente?.nombre ?? 'Cliente sin datos'} · Guía {folio.recepcion?.numero_guia_despacho ?? '—'} · {folio.recepcion?.proveedor ?? 'Proveedor sin datos'}</Text>
                {folio.motivo_bloqueo ? <Text style={styles.redText}>{folio.motivo_bloqueo}</Text> : null}
              </View>
            </View>
          )) : <Empty title="No hay folios pendientes de ubicación" />}
        </ScrollView>
      )}

      {actionBusy ? (
        <View style={styles.overlay}>
          <ActivityIndicator color={colors.cyan} size="large" />
          <Text style={styles.cardTitle}>Procesando operación segura…</Text>
        </View>
      ) : null}
    </View>
  );
}

function ReceptionDetail({
  reception,
  canManage,
  canAnnul,
  canPrint,
  api,
  deviceId,
  annulReason,
  onAnnulReason,
  onBack,
  onEdit,
  onConfirm,
  onAnnul,
}: {
  reception: MaterialReception;
  canManage: boolean;
  canAnnul: boolean;
  canPrint: boolean;
  api: ReturnType<typeof createMaterialReceptionApi>;
  deviceId: string;
  annulReason: string;
  onAnnulReason: (value: string) => void;
  onBack: () => void;
  onEdit: () => void;
  onConfirm: () => void;
  onAnnul: () => void;
}) {
  return (
    <ScrollView contentContainerStyle={styles.content}>
      <Button label="← Volver" onPress={onBack} secondary />
      <View style={styles.infoCard}>
        <View style={styles.between}>
          <View>
            <Text style={styles.eyebrow}>RECEPCIÓN</Text>
            <Text style={styles.title}>Guía {reception.numero_guia_despacho}</Text>
          </View>
          <Badge state={reception.estado} />
        </View>
        <View style={styles.summaryGrid}>
          <Summary label="Cliente" value={reception.cliente?.nombre ?? '—'} />
          <Summary label="Proveedor" value={reception.proveedor?.nombre ?? '—'} />
          <Summary label="Fecha" value={formatDateCL(reception.fecha_documento)} />
          <Summary label="Orden de compra" value={reception.orden_compra ?? '—'} />
          <Summary label="Patente" value={reception.patente ?? '—'} />
          <Summary label="Transportista" value={reception.transportista ?? '—'} />
        </View>
      </View>

      {(reception.detalles ?? []).map((detail, index) => (
        <View key={detail.id} style={styles.card}>
          <View style={styles.between}>
            <View>
              <Text style={styles.cardTitle}>{index + 1}. {detail.item?.codigo} · {detail.item?.nombre}</Text>
              <Text style={styles.muted}>{detail.categoria_operacional_etiqueta} · {detail.unidad_medida}</Text>
            </View>
            <Text style={styles.greenText}>{detail.cantidad_aceptada} {detail.unidad_medida} aceptadas</Text>
          </View>
          <View style={styles.summaryGrid}>
            <Summary label="Documental" value={detail.cantidad_documental} />
            <Summary label="Contado" value={detail.cantidad_contada} />
            <Summary label="Aceptado" value={detail.cantidad_aceptada} />
            <Summary label="Rechazado" value={detail.cantidad_rechazada} />
            <Summary label="Bultos" value={String(detail.bultos.length)} />
          </View>
          {detail.bultos.map((itemPackage, packageIndex) => (
            <View key={itemPackage.id} style={styles.packageResult}>
              <View style={styles.headerCopy}>
                <Text style={styles.cardTitle}>Bulto {packageIndex + 1} · {itemPackage.cantidad} {detail.unidad_medida}</Text>
                <Text style={styles.muted}>Lote {itemPackage.lote_proveedor ?? '—'} · Vence {formatDateCL(itemPackage.fecha_vencimiento)}</Text>
                {itemPackage.bloqueado ? <Text style={styles.redText}>BLOQUEADO · {itemPackage.motivo_bloqueo}</Text> : null}
              </View>
              {itemPackage.folio ? (
                <View style={styles.folioBox}>
                  <Text style={styles.folio}>{itemPackage.folio.numero_folio}</Text>
                  <Text style={styles.smallCaps}>{itemPackage.folio.estado_operacional}</Text>
                </View>
              ) : <Text style={styles.muted}>Folio pendiente</Text>}
            </View>
          ))}
        </View>
      ))}

      {reception.estado === 'confirmada' && canPrint ? (
        <MaterialLabelPrintPanel api={api} deviceId={deviceId} reception={reception} />
      ) : null}

      {reception.estado === 'borrador' && canManage ? (
        <View style={styles.row}>
          <Button label="Editar borrador" onPress={onEdit} secondary />
          <Button label="Confirmar y generar folios" onPress={onConfirm} />
        </View>
      ) : null}
      {reception.estado === 'confirmada' && canAnnul ? (
        <View style={styles.dangerCard}>
          <Text style={styles.cardTitle}>Anulación controlada</Text>
          <Text style={styles.muted}>Solo se permitirá si los folios siguen intactos, sin ubicación, reserva ni retiro.</Text>
          <Field label="Motivo" value={annulReason} onChange={onAnnulReason} placeholder="Describe el motivo" multiline />
          <Button label="Anular recepción" onPress={onAnnul} danger />
        </View>
      ) : null}
    </ScrollView>
  );
}

function Choice({ label, value, placeholder, options, onChange, disabled = false, photoAuth }: {
  label: string;
  value: string;
  placeholder: string;
  options: Option[];
  photoAuth?: { baseUrl: string; token: string };
  onChange: (id: string) => void;
  disabled?: boolean;
}) {
  const [visible, setVisible] = useState(false);
  const [query, setQuery] = useState('');
  const selected = options.find((option) => option.id === value);
  const filtered = options.filter((option) => `${option.label} ${option.description ?? ''}`
    .toLocaleLowerCase('es-CL')
    .includes(query.trim().toLocaleLowerCase('es-CL')));

  return (
    <View style={styles.field}>
      <Text style={styles.label}>{label}</Text>
      <Pressable disabled={disabled} onPress={() => setVisible(true)} style={[styles.input, styles.choice, disabled && styles.disabled]}>
        {photoAuth ? <PrivateMaterialThumbnail photo={selected?.photo} {...photoAuth} /> : null}
        <Text numberOfLines={1} style={[selected ? styles.inputText : styles.placeholder, { flex: 1 }]}>{selected?.label ?? placeholder}</Text>
        <Text style={styles.linkText}>⌄</Text>
      </Pressable>
      <Modal visible={visible} transparent animationType="fade" onRequestClose={() => setVisible(false)}>
        <View style={styles.modalBackdrop}>
          <View style={styles.modalCard}>
            <View style={styles.between}>
              <Text style={styles.title}>{label}</Text>
              <Button label="Cerrar" onPress={() => setVisible(false)} danger small />
            </View>
            <TextInput value={query} onChangeText={setQuery} placeholder="Buscar por código o nombre" placeholderTextColor={colors.muted} style={styles.search} autoFocus />
            <FlatList data={filtered} keyExtractor={(option) => option.id} initialNumToRender={10} maxToRenderPerBatch={10} windowSize={5} keyboardShouldPersistTaps="handled" renderItem={({ item: option }) => (
                <Pressable key={option.id} onPress={() => {
                  onChange(option.id);
                  setVisible(false);
                  setQuery('');
                }} style={[styles.option, option.id === value && styles.optionActive]}>
                  <View style={styles.row}>
                    {photoAuth ? <PrivateMaterialThumbnail photo={option.photo} {...photoAuth} /> : null}
                    <View style={{ flex: 1 }}><Text style={styles.cardTitle}>{option.label}</Text>
                    {option.description ? <Text style={styles.muted}>{option.description}</Text> : null}</View>
                  </View>
                </Pressable>
              )} ListEmptyComponent={<Text style={styles.muted}>No se encontraron opciones.</Text>} />
          </View>
        </View>
      </Modal>
    </View>
  );
}

function Field({ label, value, onChange, onEndEditing, placeholder, multiline = false, numeric = false }: {
  label: string;
  value: string;
  onChange: (value: string) => void;
  onEndEditing?: () => void;
  placeholder: string;
  multiline?: boolean;
  numeric?: boolean;
}) {
  return (
    <View style={styles.field}>
      <Text style={styles.label}>{label}</Text>
      <TextInput
        value={value}
        onChangeText={onChange}
        onEndEditing={onEndEditing}
        placeholder={placeholder}
        placeholderTextColor={colors.muted}
        keyboardType={numeric ? 'decimal-pad' : 'default'}
        multiline={multiline}
        style={[styles.input, multiline && styles.multiline]}
      />
    </View>
  );
}

function DateField({ label, value, onChange, optional = false }: {
  label: string;
  value: string;
  onChange: (value: string) => void;
  optional?: boolean;
}) {
  const initialDate = parseIsoDate(value) ?? new Date();
  const [visible, setVisible] = useState(false);
  const [month, setMonth] = useState(() => new Date(initialDate.getFullYear(), initialDate.getMonth(), 1));

  function open() {
    const selected = parseIsoDate(value) ?? new Date();
    setMonth(new Date(selected.getFullYear(), selected.getMonth(), 1));
    setVisible(true);
  }

  return (
    <View style={styles.field}>
      <Text style={styles.label}>{label}</Text>
      <Pressable onPress={open} style={[styles.input, styles.choice]}>
        <Text style={value ? styles.inputText : styles.placeholder}>
          {value ? formatDateCL(value) : 'Seleccionar fecha'}
        </Text>
        <Text style={styles.linkText}>▣</Text>
      </Pressable>
      <Modal visible={visible} transparent animationType="fade" onRequestClose={() => setVisible(false)}>
        <View style={styles.modalBackdrop}>
          <View style={styles.calendarCard}>
            <View style={styles.between}>
              <Button
                label="‹"
                onPress={() => setMonth((current) => new Date(current.getFullYear(), current.getMonth() - 1, 1))}
                secondary
                small
              />
              <Text style={styles.calendarTitle}>
                {month.toLocaleDateString('es-CL', { month: 'long', year: 'numeric' })}
              </Text>
              <Button
                label="›"
                onPress={() => setMonth((current) => new Date(current.getFullYear(), current.getMonth() + 1, 1))}
                secondary
                small
              />
            </View>
            <View style={styles.calendarGrid}>
              {['L', 'M', 'M', 'J', 'V', 'S', 'D'].map((day, index) => (
                <View key={`${day}-${index}`} style={styles.calendarCell}>
                  <Text style={styles.calendarDayName}>{day}</Text>
                </View>
              ))}
              {calendarDays(month).map((date, index) => {
                if (!date) return <View key={`empty-${index}`} style={styles.calendarCell} />;

                const iso = dateToIso(date);
                const selected = iso === value;
                const today = iso === localDate();
                return (
                  <Pressable
                    key={iso}
                    onPress={() => {
                      onChange(iso);
                      setVisible(false);
                    }}
                    style={[
                      styles.calendarCell,
                      styles.calendarDate,
                      today && styles.calendarToday,
                      selected && styles.calendarSelected,
                    ]}
                  >
                    <Text style={[styles.calendarDateText, selected && styles.buttonText]}>{date.getDate()}</Text>
                  </Pressable>
                );
              })}
            </View>
            <View style={styles.between}>
              <View style={styles.row}>
                <Button
                  label="Hoy"
                  onPress={() => {
                    onChange(localDate());
                    setVisible(false);
                  }}
                  secondary
                  small
                />
                {optional ? (
                  <Button
                    label="Limpiar"
                    onPress={() => {
                      onChange('');
                      setVisible(false);
                    }}
                    danger
                    small
                  />
                ) : null}
              </View>
              <Button label="Cerrar" onPress={() => setVisible(false)} secondary small />
            </View>
          </View>
        </View>
      </Modal>
    </View>
  );
}

function Readonly({ label, value }: { label: string; value: string }) {
  return (
    <View style={styles.field}>
      <Text style={styles.label}>{label}</Text>
      <View style={[styles.input, styles.readonly]}><Text style={styles.inputText}>{value}</Text></View>
    </View>
  );
}

function Section({ title, children }: { title: string; children: ReactNode }) {
  return <View style={styles.section}><Text style={styles.sectionTitle}>{title}</Text>{children}</View>;
}

function Button({ label, onPress, secondary = false, danger = false, small = false }: {
  label: string;
  onPress: () => void;
  secondary?: boolean;
  danger?: boolean;
  small?: boolean;
}) {
  return (
    <Pressable onPress={onPress} style={[
      styles.button,
      secondary && styles.buttonSecondary,
      danger && styles.buttonDanger,
      small && styles.buttonSmall,
    ]}>
      <Text style={[styles.buttonText, secondary && styles.linkText, danger && styles.redText]}>{label}</Text>
    </Pressable>
  );
}

function TabButton({ label, active, onPress }: { label: string; active: boolean; onPress: () => void }) {
  return (
    <Pressable onPress={onPress} style={[styles.tab, active && styles.tabActive]}>
      <Text style={[styles.tabText, active && styles.linkText]}>{label}</Text>
    </Pressable>
  );
}

function Banner({ text, danger = false }: { text: string; danger?: boolean }) {
  return <View style={[styles.banner, danger && styles.bannerDanger]}><Text style={danger ? styles.redText : styles.greenText}>{text}</Text></View>;
}

function Badge({ state }: { state: MaterialReceptionState }) {
  return <View style={[styles.badge, state === 'confirmada' && styles.confirmed, state === 'anulada' && styles.annulled]}><Text style={styles.smallCaps}>{stateLabel(state)}</Text></View>;
}

function Summary({ label, value }: { label: string; value: string }) {
  return <View style={styles.summary}><Text style={styles.label}>{label}</Text><Text style={styles.inputText}>{value}</Text></View>;
}

function Empty({ title }: { title: string }) {
  return <View style={styles.empty}><Text style={styles.sectionTitle}>{title}</Text></View>;
}

function localDate() {
  const date = new Date();
  return dateToIso(date);
}

function formFromReception(reception: MaterialReception): Form {
  return {
    cliente_id: reception.cliente?.id ?? '',
    proveedor_material_id: reception.proveedor?.id ?? '',
    numero_guia_despacho: reception.numero_guia_despacho,
    fecha_documento: reception.fecha_documento ?? '',
    orden_compra: reception.orden_compra ?? '',
    patente: reception.patente ?? '',
    transportista: reception.transportista ?? '',
    observacion: reception.observacion ?? '',
    detalles: (reception.detalles ?? []).map((detail) => ({
      local_id: Crypto.randomUUID(),
      item_material_id: detail.item?.id ?? '',
      cantidad_documental: detail.cantidad_documental,
      cantidad_contada: detail.cantidad_contada,
      cantidad_por_bulto: inferPackageCapacity(detail.bultos),
      observacion: detail.observacion ?? '',
      bultos: detail.bultos.map((itemPackage) => ({
        local_id: Crypto.randomUUID(),
        cantidad: itemPackage.cantidad,
        lote_proveedor: itemPackage.lote_proveedor ?? '',
        fecha_fabricacion: itemPackage.fecha_fabricacion ?? '',
        fecha_vencimiento: itemPackage.fecha_vencimiento ?? '',
        bloqueado: itemPackage.bloqueado,
        motivo_bloqueo: itemPackage.motivo_bloqueo ?? '',
      })),
    })),
  };
}

function emptyForm(): Form {
  return {
    cliente_id: '',
    proveedor_material_id: '',
    numero_guia_despacho: '',
    fecha_documento: localDate(),
    orden_compra: '',
    patente: '',
    transportista: '',
    observacion: '',
    detalles: [emptyDetail()],
  };
}

function emptyDetail(): ReceptionDraftDetail {
  return {
    local_id: Crypto.randomUUID(),
    item_material_id: '',
    cantidad_documental: '',
    cantidad_contada: '',
    cantidad_por_bulto: '',
    observacion: '',
    bultos: [emptyPackage()],
  };
}

function emptyPackage(): ReceptionDraftPackage {
  return {
    local_id: Crypto.randomUUID(),
    cantidad: '',
    lote_proveedor: '',
    fecha_fabricacion: '',
    fecha_vencimiento: '',
    bloqueado: false,
    motivo_bloqueo: '',
  };
}

function buildPayload(form: Form, operationId: string): CreateMaterialReceptionPayload {
  if (!form.cliente_id) throw new Error('Selecciona el cliente.');
  if (!form.proveedor_material_id) throw new Error('Selecciona un proveedor autorizado.');
  if (!form.numero_guia_despacho.trim()) throw new Error('Ingresa el número de guía.');
  validateDate(form.fecha_documento, 'fecha del documento');

  const details = form.detalles.map((detail, detailIndex) => {
    if (!detail.item_material_id) throw new Error(`Selecciona el ítem ${detailIndex + 1}.`);

    const documentary = positive(detail.cantidad_documental, `Cantidad documental del ítem ${detailIndex + 1}`);
    const counted = positive(detail.cantidad_contada, `Cantidad contada del ítem ${detailIndex + 1}`);
    const packages = distributePackages(detail).map((itemPackage, packageIndex) => {
      const quantity = positive(itemPackage.cantidad, `Cantidad del bulto ${packageIndex + 1}`);
      validateDate(itemPackage.fecha_fabricacion, 'fecha de fabricación');
      validateDate(itemPackage.fecha_vencimiento, 'fecha de vencimiento');
      if (itemPackage.bloqueado && !itemPackage.motivo_bloqueo.trim()) {
        throw new Error(`Indica el motivo de bloqueo del bulto ${packageIndex + 1}.`);
      }
      return {
        cantidad: quantity,
        lote_proveedor: optional(itemPackage.lote_proveedor),
        fecha_fabricacion: optional(itemPackage.fecha_fabricacion),
        fecha_vencimiento: optional(itemPackage.fecha_vencimiento),
        bloqueado: itemPackage.bloqueado,
        motivo_bloqueo: itemPackage.bloqueado ? optional(itemPackage.motivo_bloqueo) : null,
      };
    });

    const accepted = round(packages.reduce((total, itemPackage) => total + itemPackage.cantidad, 0));
    if (accepted > counted) {
      throw new Error(`La cantidad aceptada del ítem ${detailIndex + 1} no puede superar la cantidad contada.`);
    }
    const rejected = round(counted - accepted);

    return {
      item_material_id: detail.item_material_id,
      cantidad_documental: documentary,
      cantidad_contada: counted,
      cantidad_aceptada: accepted,
      cantidad_recibida: accepted,
      cantidad_rechazada: rejected,
      observacion: optional(detail.observacion),
      bultos: packages,
    };
  });

  return {
    operacion_id: operationId,
    cliente_id: form.cliente_id,
    proveedor_material_id: form.proveedor_material_id,
    numero_guia_despacho: form.numero_guia_despacho.trim(),
    fecha_documento: optional(form.fecha_documento),
    orden_compra: optional(form.orden_compra),
    patente: optional(form.patente),
    transportista: optional(form.transportista),
    observacion: optional(form.observacion),
    detalles: details,
  };
}

function findRepeatedItems(details: ReceptionDraftDetail[]) {
  const linesByItem = new Map<string, number[]>();

  details.forEach((detail, index) => {
    if (!detail.item_material_id) return;

    const lines = linesByItem.get(detail.item_material_id) ?? [];
    lines.push(index + 1);
    linesByItem.set(detail.item_material_id, lines);
  });

  return [...linesByItem.entries()]
    .filter(([, lines]) => lines.length > 1)
    .map(([itemId, lines]) => ({ itemId, lines }));
}

function sumPackages(packages: ReceptionDraftPackage[]) {
  return round(packages.reduce((total, itemPackage) => {
    const value = Number(itemPackage.cantidad.replace(',', '.'));
    return total + (Number.isFinite(value) ? value : 0);
  }, 0));
}

function packageTemplate(detail: ReceptionDraftDetail): ReceptionDraftPackage {
  return detail.bultos[0] ?? emptyPackage();
}

function inferPackageCapacity(packages: Array<{ cantidad: string }>) {
  return packages.find((itemPackage) => Number(itemPackage.cantidad) > 0)?.cantidad ?? '';
}

function distributePackages(detail: ReceptionDraftDetail): ReceptionDraftPackage[] {
  const total = positive(detail.cantidad_contada, 'La cantidad total recibida');
  const capacity = positive(detail.cantidad_por_bulto, 'La cantidad por bulto');
  const totalUnits = Math.round(total * 1000);
  const capacityUnits = Math.round(capacity * 1000);
  const packageCount = Math.ceil(totalUnits / capacityUnits);

  if (packageCount > MAX_PACKAGES_PER_DETAIL) {
    throw new Error(`La distribución genera ${packageCount} bultos. El máximo permitido por ítem es ${MAX_PACKAGES_PER_DETAIL}.`);
  }

  const template = packageTemplate(detail);
  let remaining = totalUnits;

  return Array.from({ length: packageCount }, (_, index) => {
    const units = Math.min(capacityUnits, remaining);
    const previousPackage = detail.bultos[index] ?? template;
    remaining -= units;

    return {
      ...previousPackage,
      local_id: previousPackage.local_id ?? Crypto.randomUUID(),
      cantidad: numberInput(units / 1000),
    };
  });
}

function packageDistributionLabel(detail: ReceptionDraftDetail) {
  const quantities = detail.bultos
    .map((itemPackage) => Number(itemPackage.cantidad.replace(',', '.')))
    .filter((quantity) => Number.isFinite(quantity) && quantity > 0)
    .map(round);

  if (!quantities.length) return 'Pendiente de calcular';

  const groups = new Map<number, number>();
  quantities.forEach((quantity) => groups.set(quantity, (groups.get(quantity) ?? 0) + 1));
  const distribution = [...groups.entries()]
    .map(([quantity, count]) => `${count} × ${format(quantity)}`)
    .join(' + ');

  return `${quantities.length} ${quantities.length === 1 ? 'bulto' : 'bultos'} · ${distribution}`;
}

function rejectedQuantity(detail: ReceptionDraftDetail) {
  const counted = Number(detail.cantidad_contada.replace(',', '.'));
  if (!Number.isFinite(counted)) return 0;

  return Math.max(0, round(counted - sumPackages(detail.bultos)));
}

function positive(value: string, label: string) {
  const parsed = Number(value.replace(',', '.'));
  if (!Number.isFinite(parsed) || parsed <= 0) throw new Error(`${label} debe ser mayor que cero.`);
  return round(parsed);
}

function validateDate(value: string, label: string) {
  if (value.trim() && !parseIsoDate(value.trim())) {
    throw new Error(`La ${label} no es válida.`);
  }
}

function decimal(value: string) {
  return value.replace(/[^0-9.,]/g, '').replace(',', '.');
}

function optional(value: string) {
  return value.trim() || null;
}

function round(value: number) {
  return Math.round(value * 1000) / 1000;
}

function numberInput(value: number) {
  return String(round(value));
}

function format(value: number) {
  return value.toLocaleString('es-CL', { maximumFractionDigits: 3 });
}

function parseIsoDate(value: string | null | undefined) {
  if (!value || !/^\d{4}-\d{2}-\d{2}$/.test(value)) return null;

  const [year, month, day] = value.split('-').map(Number);
  const date = new Date(year, month - 1, day);
  if (date.getFullYear() !== year || date.getMonth() !== month - 1 || date.getDate() !== day) return null;

  return date;
}

function dateToIso(date: Date) {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');
  return `${year}-${month}-${day}`;
}

function formatDateCL(value: string | null | undefined) {
  const date = parseIsoDate(value);
  if (!date) return '—';

  return date.toLocaleDateString('es-CL', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
  });
}

function calendarDays(month: Date): Array<Date | null> {
  const year = month.getFullYear();
  const monthIndex = month.getMonth();
  const firstDay = new Date(year, monthIndex, 1);
  const leadingEmpty = (firstDay.getDay() + 6) % 7;
  const daysInMonth = new Date(year, monthIndex + 1, 0).getDate();
  const days: Array<Date | null> = Array.from({ length: leadingEmpty }, () => null);

  for (let day = 1; day <= daysInMonth; day += 1) {
    days.push(new Date(year, monthIndex, day));
  }
  while (days.length % 7 !== 0) days.push(null);

  return days;
}

function stateLabel(state: HistoryFilter) {
  if (state === 'todas') return 'Todas';
  if (state === 'confirmada') return 'Confirmada';
  if (state === 'anulada') return 'Anulada';
  return 'Borrador';
}

function errorMessage(reason: unknown) {
  return reason instanceof Error ? reason.message : 'Ocurrió un error inesperado.';
}

const styles = StyleSheet.create({
  screen: { flex: 1, backgroundColor: colors.background },
  fill: { flex: 1 },
  header: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 16, padding: 18, borderBottomWidth: 1, borderBottomColor: colors.border, backgroundColor: colors.backgroundDeep },
  headerCompact: { flexDirection: 'column', alignItems: 'stretch' },
  headerCopy: { flex: 1 },
  eyebrow: { color: colors.cyan, fontSize: 14, fontWeight: '900', letterSpacing: 1.2 },
  title: { color: colors.text, fontSize: 21, fontWeight: '900', marginTop: 3 },
  muted: { color: colors.muted, fontSize: 14, lineHeight: 18, marginTop: 3 },
  row: { flexDirection: 'row', alignItems: 'center', gap: 9, flexWrap: 'wrap' },
  between: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 12, flexWrap: 'wrap' },
  tabs: { flexDirection: 'row', flexWrap: 'wrap', gap: 8, padding: 10, paddingHorizontal: 18, borderBottomWidth: 1, borderBottomColor: colors.borderSoft, backgroundColor: colors.panel },
  tab: { paddingHorizontal: 14, paddingVertical: 8, borderRadius: 9, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.backgroundDeep },
  tabActive: { borderColor: colors.cyan, backgroundColor: colors.selected },
  tabText: { color: colors.muted, fontSize: 14, fontWeight: '900' },
  banner: { marginHorizontal: 18, marginTop: 10, padding: 11, borderRadius: 10, borderWidth: 1, borderColor: colors.greenDark, backgroundColor: '#102C20' },
  bannerDanger: { borderColor: colors.blockedBorder, backgroundColor: colors.blocked },
  center: { flex: 1, alignItems: 'center', justifyContent: 'center', gap: 10 },
  content: { padding: 18, gap: 14, paddingBottom: 110 },
  section: { padding: 15, gap: 12, borderRadius: 15, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.panel },
  sectionTitle: { color: colors.text, fontSize: 16, fontWeight: '900' },
  grid: { flexDirection: 'row', flexWrap: 'wrap', gap: 11 },
  column: { flexDirection: 'column' },
  stack: { gap: 13 },
  stackSmall: { gap: 9 },
  card: { padding: 13, gap: 11, borderRadius: 13, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.backgroundDeep },
  packageCard: { padding: 11, gap: 9, borderRadius: 11, borderWidth: 1, borderColor: colors.borderSoft, backgroundColor: colors.panelStrong },
  cardTitle: { color: colors.text, fontSize: 13, fontWeight: '900' },
  subheading: { color: colors.cyan, fontSize: 14, fontWeight: '900', textTransform: 'uppercase', marginTop: 4 },
  field: { flexGrow: 1, flexBasis: 220, gap: 5 },
  label: { color: colors.muted, fontSize: 13, fontWeight: '900', textTransform: 'uppercase', letterSpacing: 0.4 },
  input: { minHeight: 43, borderRadius: 9, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.backgroundDeep, color: colors.text, paddingHorizontal: 11, paddingVertical: 9, fontWeight: '700' },
  multiline: { minHeight: 68, textAlignVertical: 'top' },
  choice: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 8 },
  disabled: { opacity: 0.45 },
  inputText: { color: colors.text, fontSize: 14, fontWeight: '800' },
  placeholder: { color: colors.muted, flex: 1 },
  readonly: { justifyContent: 'center', borderColor: colors.cyanDark, backgroundColor: colors.selected },
  toggle: { alignSelf: 'flex-start', paddingHorizontal: 11, paddingVertical: 7, borderRadius: 8, borderWidth: 1, borderColor: colors.greenDark, backgroundColor: '#102C20' },
  toggleBlocked: { borderColor: colors.blockedBorder, backgroundColor: colors.blocked },
  toggleText: { color: colors.green, fontSize: 14, fontWeight: '900' },
  button: { alignSelf: 'flex-start', paddingHorizontal: 16, paddingVertical: 10, borderRadius: 9, backgroundColor: colors.cyan },
  buttonSecondary: { borderWidth: 1, borderColor: colors.cyanDark, backgroundColor: colors.selected },
  buttonDanger: { borderWidth: 1, borderColor: colors.blockedBorder, backgroundColor: colors.blocked },
  buttonSmall: { paddingHorizontal: 10, paddingVertical: 6 },
  buttonText: { color: colors.accentText, fontSize: 14, fontWeight: '900' },
  linkText: { color: colors.cyan, fontWeight: '900' },
  redText: { color: colors.red, fontSize: 14, fontWeight: '900' },
  greenText: { color: colors.green, fontSize: 14, fontWeight: '900' },
  submitBar: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', gap: 14, padding: 15, borderRadius: 14, borderWidth: 1, borderColor: colors.cyanDark, backgroundColor: colors.panelStrong },
  filters: { flexDirection: 'row', gap: 8, padding: 12, paddingHorizontal: 18, flexWrap: 'wrap' },
  listCard: { flexDirection: 'row', alignItems: 'center', gap: 13, padding: 13, borderRadius: 12, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.panel },
  infoCard: { padding: 15, gap: 8, borderRadius: 13, borderWidth: 1, borderColor: colors.cyanDark, backgroundColor: colors.panel },
  dangerCard: { padding: 15, gap: 10, borderRadius: 13, borderWidth: 1, borderColor: colors.blockedBorder, backgroundColor: colors.panel },
  badge: { paddingHorizontal: 10, paddingVertical: 6, borderRadius: 999, borderWidth: 1, borderColor: colors.amberDark, backgroundColor: '#332710' },
  confirmed: { borderColor: colors.greenDark, backgroundColor: '#102C20' },
  annulled: { borderColor: colors.blockedBorder, backgroundColor: colors.blocked },
  smallCaps: { color: colors.text, fontSize: 12, fontWeight: '900', textTransform: 'uppercase' },
  summaryGrid: { flexDirection: 'row', flexWrap: 'wrap', gap: 9, marginTop: 9 },
  summary: { minWidth: 135, flexGrow: 1, flexBasis: 165, padding: 9, gap: 4, borderRadius: 8, backgroundColor: colors.backgroundDeep },
  packageResult: { flexDirection: 'row', flexWrap: 'wrap', alignItems: 'center', justifyContent: 'space-between', gap: 11, padding: 10, marginTop: 8, borderRadius: 9, borderWidth: 1, borderColor: colors.borderSoft, backgroundColor: colors.panelStrong },
  folioBox: { minWidth: 135, padding: 9, borderRadius: 9, backgroundColor: colors.backgroundDeep },
  folio: { color: colors.cyan, fontSize: 14, fontWeight: '900', letterSpacing: 0.5 },
  empty: { alignItems: 'center', justifyContent: 'center', padding: 34, borderRadius: 13, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.panel },
  modalBackdrop: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: 22, backgroundColor: 'rgba(0,0,0,0.72)' },
  modalCard: { width: '100%', maxWidth: 720, maxHeight: '82%', padding: 15, gap: 10, borderRadius: 15, borderWidth: 1, borderColor: colors.cyanDark, backgroundColor: colors.panel },
  calendarCard: { width: '100%', maxWidth: 420, padding: 15, gap: 14, borderRadius: 15, borderWidth: 1, borderColor: colors.cyanDark, backgroundColor: colors.panel },
  calendarTitle: { color: colors.text, fontSize: 15, fontWeight: '900', textTransform: 'capitalize' },
  calendarGrid: { flexDirection: 'row', flexWrap: 'wrap' },
  calendarCell: { width: '14.2857%', minHeight: 38, alignItems: 'center', justifyContent: 'center' },
  calendarDayName: { color: colors.muted, fontSize: 13, fontWeight: '900' },
  calendarDate: { borderRadius: 8, borderWidth: 1, borderColor: 'transparent' },
  calendarToday: { borderColor: colors.cyanDark },
  calendarSelected: { backgroundColor: colors.cyan, borderColor: colors.cyan },
  calendarDateText: { color: colors.text, fontSize: 12, fontWeight: '800' },
  search: { minHeight: 43, borderRadius: 9, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.backgroundDeep, color: colors.text, paddingHorizontal: 11 },
  option: { padding: 11, marginBottom: 8, borderRadius: 9, borderWidth: 1, borderColor: colors.borderSoft, backgroundColor: colors.backgroundDeep },
  optionActive: { borderColor: colors.cyan, backgroundColor: colors.selected },
  overlay: { ...StyleSheet.absoluteFillObject, alignItems: 'center', justifyContent: 'center', gap: 11, backgroundColor: 'rgba(8,12,16,0.84)' },
});
