import { font } from '../theme/typography';
import { FieldHeader } from '../components/field/FieldHeader';
import * as Crypto from 'expo-crypto';
import { useCallback, useEffect, useState } from 'react';
import {
  ActivityIndicator,
  Alert,
  KeyboardAvoidingView,
  Platform,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  TextInput,
  useWindowDimensions,
  View,
} from 'react-native';

import {
  createDemoClient,
  createDemoFolio,
  createDemoMaster,
  deleteDemoClient,
  deleteDemoFolio,
  deleteDemoMaster,
  DemoDataset,
  loadDemoDataset,
  resetDemoDatabase,
  resetDemoOperationalData,
  setDemoMasterActive,
} from '../demo/demoDatabase';
import {
  DEMO_MASTER_CATEGORIES,
  DEMO_MASTER_CATEGORY_LABELS,
  DemoMasterCategory,
} from '../demo/demoMasterCatalog';
import {
  cancelDemoLoad,
  changeDemoLoadPriority,
  createDemoLoad,
  CreateDemoLoadInput,
  DemoLoadAdministration,
  loadDemoLoadAdministration,
  publishDemoLoad,
} from '../demo/demoLoadEngine';
import { colors } from '../theme/colors';

const emptyDataset: DemoDataset = {
  clients: [],
  folios: [],
  masters: [],
  activeMasters: 0,
  localMasters: 0,
  activeLoads: 0,
  auditEntries: 0,
  operationalMovements: 0,
};

const emptyLoadAdministration: DemoLoadAdministration = {
  loads: [],
  candidates: [],
};

type DemoDataScreenProps = {
  onLogout: () => void;
};

export function DemoDataScreen({ onLogout }: DemoDataScreenProps) {
  const { width } = useWindowDimensions();
  const wide = width >= 900;
  const [dataset, setDataset] = useState<DemoDataset>(emptyDataset);
  const [loadAdministration, setLoadAdministration] = useState<DemoLoadAdministration>(emptyLoadAdministration);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  const [clientCode, setClientCode] = useState('');
  const [clientName, setClientName] = useState('');
  const [folioPrefix, setFolioPrefix] = useState('');

  const [folioNumber, setFolioNumber] = useState('');
  const [selectedClientId, setSelectedClientId] = useState('');
  const [species, setSpecies] = useState('Cereza');
  const [variety, setVariety] = useState('');
  const [boxes, setBoxes] = useState('');

  const [masterCategory, setMasterCategory] = useState<DemoMasterCategory>('especies');
  const [masterCode, setMasterCode] = useState('');
  const [masterName, setMasterName] = useState('');
  const [masterDetail, setMasterDetail] = useState('');

  const [loadExternalOrder, setLoadExternalOrder] = useState('');
  const [loadObservation, setLoadObservation] = useState('');
  const [loadPriority, setLoadPriority] = useState<CreateDemoLoadInput['priority']>('normal');
  const [selectedLoadFolios, setSelectedLoadFolios] = useState<string[]>([]);

  const reload = useCallback(async () => {
    const [next, nextLoads] = await Promise.all([
      loadDemoDataset(),
      loadDemoLoadAdministration(),
    ]);
    setDataset(next);
    setLoadAdministration(nextLoads);
    setSelectedClientId((current) => (
      next.clients.some((client) => client.id === current)
        ? current
        : next.clients[0]?.id ?? ''
    ));
    setSelectedLoadFolios((current) => current.filter((id) => (
      nextLoads.candidates.some((candidate) => candidate.folioId === id)
    )));
  }, []);

  useEffect(() => {
    void reload()
      .catch((reason) => setError(messageFrom(reason)))
      .finally(() => setLoading(false));
  }, [reload]);

  async function mutate(action: () => Promise<void>, success: string) {
    setBusy(true);
    setError('');
    setNotice('');
    try {
      await action();
      await reload();
      setNotice(success);
    } catch (reason) {
      setError(messageFrom(reason));
    } finally {
      setBusy(false);
    }
  }

  async function addClient() {
    await mutate(async () => {
      await createDemoClient({ code: clientCode, name: clientName, folioPrefix });
      setClientCode('');
      setClientName('');
      setFolioPrefix('');
    }, 'Cliente guardado en la tablet.');
  }

  async function addFolio() {
    await mutate(async () => {
      await createDemoFolio({
        number: folioNumber,
        clientId: selectedClientId,
        species,
        variety,
        boxes: Number(boxes),
      });
      setFolioNumber('');
      setVariety('');
      setBoxes('');
    }, 'Folio guardado en la tablet.');
  }

  async function addMaster() {
    await mutate(async () => {
      await createDemoMaster({
        category: masterCategory,
        code: masterCode,
        name: masterName,
        detail: masterDetail,
      });
      setMasterCode('');
      setMasterName('');
      setMasterDetail('');
    }, `${DEMO_MASTER_CATEGORY_LABELS[masterCategory]}: registro guardado en la tablet.`);
  }

  function toggleLoadFolio(folioId: string) {
    setSelectedLoadFolios((current) => current.includes(folioId)
      ? current.filter((id) => id !== folioId)
      : current.length >= 26 ? current : [...current, folioId]);
  }

  async function addLoad() {
    await mutate(async () => {
      await createDemoLoad({
        operationId: Crypto.randomUUID(),
        externalOrder: loadExternalOrder,
        priority: loadPriority,
        observation: loadObservation,
        folioIds: selectedLoadFolios,
      });
      setLoadExternalOrder('');
      setLoadObservation('');
      setLoadPriority('normal');
      setSelectedLoadFolios([]);
    }, 'Carga creada como borrador. Publícala cuando quieras mostrarla en Operación.');
  }

  function confirmLoadCancellation(id: string, code: string) {
    Alert.alert(
      'Cancelar carga demo',
      `${code} dejará de aparecer en Operación y sus folios quedarán disponibles para otra carga.`,
      [
        { text: 'Volver', style: 'cancel' },
        {
          text: 'Cancelar carga',
          style: 'destructive',
          onPress: () => void mutate(
            () => cancelDemoLoad(id, Crypto.randomUUID()),
            `${code} cancelada y folios liberados.`,
          ),
        },
      ],
    );
  }

  function confirmClientDeletion(id: string, name: string) {
    Alert.alert(
      'Eliminar cliente demo',
      `Se eliminarán ${name} y todos sus folios locales. Esta acción no afecta producción.`,
      [
        { text: 'Cancelar', style: 'cancel' },
        {
          text: 'Eliminar',
          style: 'destructive',
          onPress: () => void mutate(() => deleteDemoClient(id), 'Cliente demo eliminado.'),
        },
      ],
    );
  }

  function confirmFolioDeletion(id: string, number: string) {
    Alert.alert(
      'Eliminar folio demo',
      `¿Eliminar ${number} de la memoria local?`,
      [
        { text: 'Cancelar', style: 'cancel' },
        {
          text: 'Eliminar',
          style: 'destructive',
          onPress: () => void mutate(() => deleteDemoFolio(id), 'Folio demo eliminado.'),
        },
      ],
    );
  }

  function confirmOperationalReset() {
    Alert.alert(
      'Preparar nueva demostración',
      'Se restaurará el escenario ficticio de folios, cámaras y cargas. Tus clientes y maestros locales se conservarán.',
      [
        { text: 'Cancelar', style: 'cancel' },
        {
          text: 'Preparar demo',
          onPress: () => void mutate(
            resetDemoOperationalData,
            'Nueva demostración preparada; la base maestra fue conservada.',
          ),
        },
      ],
    );
  }

  function confirmFullReset() {
    Alert.alert(
      'Restaurar toda la base inicial',
      'También se borrarán los clientes y maestros que agregaste en esta tablet. Los ejemplos precargados volverán a su estado original.',
      [
        { text: 'Cancelar', style: 'cancel' },
        {
          text: 'Restaurar todo',
          style: 'destructive',
          onPress: () => void mutate(resetDemoDatabase, 'Escenario inicial restaurado.'),
        },
      ],
    );
  }

  if (loading) {
    return (
      <View style={styles.loading}>
        <ActivityIndicator color={colors.cyan} size="large" />
        <Text style={styles.loadingText}>Preparando base local…</Text>
      </View>
    );
  }

  return (
    <KeyboardAvoidingView
      behavior={Platform.OS === 'ios' ? 'padding' : undefined}
      style={styles.root}
    >
      <ScrollView contentContainerStyle={styles.page} keyboardShouldPersistTaps="handled">
        <FieldHeader area="administracion" title="Datos de demostración" busy={busy} onLogout={onLogout}><View style={styles.offlineChip}><View style={styles.offlineDot}/><Text style={styles.offlineText}>LOCAL</Text></View></FieldHeader><Text style={styles.intro}>Crea clientes, folios y cargas de ejemplo. Los datos quedan únicamente en esta tablet.</Text>

        <View style={styles.metrics}>
          <Metric label="Datos maestros" value={dataset.masters.length + dataset.clients.length} />
          <Metric label="Maestros agregados" value={dataset.localMasters} />
          <Metric label="Clientes" value={dataset.clients.length} />
          <Metric label="Folios" value={dataset.folios.length} />
          <Metric label="Cargas activas" value={dataset.activeLoads} />
          <Metric label="Movimientos" value={dataset.operationalMovements} />
          <Metric label="Acciones locales" value={dataset.auditEntries} />
        </View>

        {error ? <Message tone="error" text={error} /> : null}
        {notice ? <Message tone="success" text={notice} /> : null}

        <View style={styles.privacyPanel}>
          <View style={styles.privacyIcon}><Text style={styles.privacyIconText}>✓</Text></View>
          <View style={styles.privacyCopy}>
            <Text style={styles.privacyTitle}>Base maestra segura y persistente</Text>
            <Text style={styles.privacyText}>
              La APK incluye catálogos ficticios editables y conserva tus cambios en SQLite. No contiene usuarios,
              contraseñas, folios, inventario, recepciones, procesos, cargas ni movimientos de la operación real.
            </Text>
          </View>
        </View>

        <View style={[styles.masterWorkspace, wide && styles.masterWorkspaceWide]}>
          <View style={styles.masterCreator}>
            <Text style={styles.cardEyebrow}>BASE MAESTRA DEMO</Text>
            <Text style={styles.cardTitle}>Agregar dato maestro</Text>
            <Text style={styles.cardCopy}>
              Los registros que agregues quedan disponibles para los módulos Demo actuales y siguientes.
            </Text>
            <Text style={styles.fieldLabel}>Tipo de maestro</Text>
            <View style={styles.choiceRow}>
              {DEMO_MASTER_CATEGORIES.map((category) => (
                <Pressable
                  key={category}
                  onPress={() => setMasterCategory(category)}
                  style={[styles.choice, masterCategory === category && styles.choiceSelected]}
                >
                  <Text style={[styles.choiceText, masterCategory === category && styles.choiceTextSelected]}>
                    {DEMO_MASTER_CATEGORY_LABELS[category]}
                  </Text>
                </Pressable>
              ))}
            </View>
            <DemoField label="Código" onChangeText={setMasterCode} placeholder="COD-DEMO" value={masterCode} />
            <DemoField label="Nombre" onChangeText={setMasterName} placeholder="Nombre demostrativo" value={masterName} />
            <DemoField label="Detalle" onChangeText={setMasterDetail} placeholder="Unidad, capacidad u observación" value={masterDetail} />
            <PrimaryButton busy={busy} label="Guardar dato maestro" onPress={() => void addMaster()} />
          </View>

          <View style={styles.masterListPanel}>
            <View style={styles.listHeader}>
              <View>
                <Text style={styles.cardEyebrow}>PRECARGADOS + LOCALES</Text>
                <Text style={styles.listTitle}>{DEMO_MASTER_CATEGORY_LABELS[masterCategory]}</Text>
              </View>
              <Text style={styles.count}>
                {dataset.masters.filter((record) => record.category === masterCategory).length}
              </Text>
            </View>
            {dataset.masters
              .filter((record) => record.category === masterCategory)
              .map((record) => (
                <View key={record.id} style={[styles.listRow, !record.active && styles.inactiveRow]}>
                  <View style={styles.rowCopy}>
                    <Text style={styles.rowTitle}>{record.code} · {record.name}</Text>
                    <Text style={styles.rowMeta}>
                      {record.detail || 'Sin detalle'} · {record.source === 'preloaded' ? 'precargado' : 'creado en tablet'}
                    </Text>
                  </View>
                  <Pressable
                    disabled={busy}
                    onPress={() => void mutate(
                      () => setDemoMasterActive(record.id, !record.active),
                      `${record.code} quedó ${record.active ? 'inactivo' : 'activo'}.`,
                    )}
                    style={styles.toggleButton}
                  >
                    <Text style={styles.toggleButtonText}>{record.active ? 'Desactivar' : 'Activar'}</Text>
                  </Pressable>
                  {record.source === 'local' ? (
                    <Pressable
                      disabled={busy}
                      onPress={() => void mutate(
                        () => deleteDemoMaster(record.id),
                        `${record.code} eliminado de la base local.`,
                      )}
                      style={styles.deleteButton}
                    >
                      <Text style={styles.deleteButtonText}>Eliminar</Text>
                    </Pressable>
                  ) : null}
                </View>
              ))}
          </View>
        </View>

        <View style={[styles.forms, wide && styles.formsWide]}>
          <View style={styles.card}>
            <Text style={styles.cardEyebrow}>MAESTRO DEMO</Text>
            <Text style={styles.cardTitle}>Nuevo cliente</Text>
            <Text style={styles.cardCopy}>Puedes usar nombres ficticios adaptados a cada presentación.</Text>
            <DemoField
              label="Código"
              onChangeText={setClientCode}
              placeholder="CLIENTE-NORTE"
              value={clientCode}
            />
            <DemoField
              label="Nombre"
              onChangeText={setClientName}
              placeholder="Agrícola Cliente Norte"
              value={clientName}
            />
            <DemoField
              label="Prefijo de folio (máx. 6)"
              onChangeText={setFolioPrefix}
              placeholder="CN"
              value={folioPrefix}
            />
            <PrimaryButton busy={busy} label="Guardar cliente" onPress={() => void addClient()} />
          </View>

          <View style={styles.card}>
            <Text style={styles.cardEyebrow}>OPERACIÓN DEMO</Text>
            <Text style={styles.cardTitle}>Nuevo folio</Text>
            <Text style={styles.cardCopy}>El folio queda disponible para ubicarlo en Cámaras y luego asignarlo a una carga local.</Text>
            <DemoField
              label="Número de folio"
              onChangeText={setFolioNumber}
              placeholder="DEMO-000004"
              value={folioNumber}
            />
            <Text style={styles.fieldLabel}>Cliente</Text>
            <View style={styles.clientChoices}>
              {dataset.clients.map((client) => {
                const selected = selectedClientId === client.id;
                return (
                  <Pressable
                    key={client.id}
                    onPress={() => setSelectedClientId(client.id)}
                    style={[styles.clientChoice, selected && styles.clientChoiceSelected]}
                  >
                    <Text style={[styles.clientChoiceText, selected && styles.clientChoiceTextSelected]}>
                      {client.code}
                    </Text>
                  </Pressable>
                );
              })}
            </View>
            <View style={styles.inlineFields}>
              <View style={styles.inlineField}>
                <DemoField label="Especie" onChangeText={setSpecies} placeholder="Cereza" value={species} />
              </View>
              <View style={styles.inlineField}>
                <DemoField label="Variedad" onChangeText={setVariety} placeholder="Regina" value={variety} />
              </View>
              <View style={styles.smallField}>
                <DemoField
                  keyboardType="number-pad"
                  label="Cajas"
                  onChangeText={setBoxes}
                  placeholder="120"
                  value={boxes}
                />
              </View>
            </View>
            <PrimaryButton busy={busy} label="Guardar folio" onPress={() => void addFolio()} />
          </View>
        </View>

        <View style={[styles.loadWorkspace, wide && styles.loadWorkspaceWide]}>
          <View style={styles.loadCreator}>
            <Text style={styles.cardEyebrow}>OFICINA DEMO · CARGAS DE FRÍO</Text>
            <Text style={styles.cardTitle}>Nueva carga CAR</Text>
            <Text style={styles.cardCopy}>
              Selecciona folios ya ubicados. El borrador no aparecerá en Operación hasta que lo publiques.
            </Text>
            <DemoField
              label="Orden externa (opcional)"
              onChangeText={setLoadExternalOrder}
              placeholder="OC-CLIENTE-001"
              value={loadExternalOrder}
            />
            <DemoField
              label="Observación (opcional)"
              onChangeText={setLoadObservation}
              placeholder="Despacho demostración"
              value={loadObservation}
            />
            <Text style={styles.fieldLabel}>Prioridad</Text>
            <View style={styles.choiceRow}>
              {(['normal', 'alta', 'urgente'] as const).map((priority) => (
                <Pressable
                  key={priority}
                  onPress={() => setLoadPriority(priority)}
                  style={[styles.choice, loadPriority === priority && styles.choiceSelected]}
                >
                  <Text style={[styles.choiceText, loadPriority === priority && styles.choiceTextSelected]}>
                    {priority.toUpperCase()}
                  </Text>
                </Pressable>
              ))}
            </View>
            <View style={styles.selectionHeading}>
              <Text style={styles.fieldLabel}>Folios ubicados disponibles</Text>
              <Text style={styles.selectionCount}>{selectedLoadFolios.length}/26</Text>
            </View>
            <ScrollView
              contentContainerStyle={styles.folioChoices}
              nestedScrollEnabled
              style={styles.folioChoicesScroll}
            >
              {loadAdministration.candidates.map((candidate) => {
                const selected = selectedLoadFolios.includes(candidate.folioId);
                return (
                  <Pressable
                    key={candidate.folioId}
                    onPress={() => toggleLoadFolio(candidate.folioId)}
                    style={[styles.folioChoice, selected && styles.folioChoiceSelected]}
                  >
                    <Text style={[styles.folioChoiceTitle, selected && styles.folioChoiceTitleSelected]}>
                      {selected ? '✓ ' : ''}{candidate.number}
                    </Text>
                    <Text style={styles.folioChoiceMeta}>
                      {candidate.cameraCode} · {candidate.positionLabel} · {candidate.variety ?? 'Sin variedad'}
                    </Text>
                  </Pressable>
                );
              })}
              {!loadAdministration.candidates.length ? (
                <Text style={styles.emptyCopy}>
                  No hay folios ubicados libres. Ubica uno en Cámaras o cancela una carga sin despachos.
                </Text>
              ) : null}
            </ScrollView>
            <PrimaryButton busy={busy} label="Crear borrador CAR" onPress={() => void addLoad()} />
          </View>

          <View style={styles.loadListPanel}>
            <View style={styles.listHeader}>
              <View>
                <Text style={styles.cardEyebrow}>TRAZABILIDAD LOCAL</Text>
                <Text style={styles.listTitle}>Cargas Demo</Text>
              </View>
              <Text style={styles.count}>{loadAdministration.loads.length}</Text>
            </View>
            {loadAdministration.loads.map((load) => (
              <View key={load.id} style={styles.loadRow}>
                <View style={styles.loadRowTop}>
                  <View style={styles.rowCopy}>
                    <Text style={styles.rowTitle}>{load.code} · {load.priority.toUpperCase()}</Text>
                    <Text style={styles.rowMeta}>
                      {load.externalOrder ?? 'Sin orden externa'} · {load.folios.length} folios · v{load.version}
                    </Text>
                  </View>
                  <View style={[
                    styles.statusChip,
                    load.status === 'published' && styles.statusPublished,
                    load.status === 'cancelled' && styles.statusCancelled,
                  ]}>
                    <Text style={styles.statusText}>{loadStatusLabel(load.status)}</Text>
                  </View>
                </View>
                <Text style={styles.loadFolios}>
                  {load.folios.map((folio) => folio.number).join(' · ')}
                </Text>
                {load.observation ? <Text style={styles.loadObservation}>{load.observation}</Text> : null}
                {load.status !== 'cancelled' ? (
                  <View style={styles.loadActions}>
                    {load.status === 'draft' ? (
                      <Pressable
                        disabled={busy}
                        onPress={() => void mutate(
                          () => publishDemoLoad(load.id, Crypto.randomUUID()),
                          `${load.code} publicada; la tablet mostrará una nueva alerta.`,
                        )}
                        style={styles.publishButton}
                      >
                        <Text style={styles.publishButtonText}>Publicar</Text>
                      </Pressable>
                    ) : null}
                    {(['normal', 'alta', 'urgente'] as const).map((priority) => (
                      <Pressable
                        disabled={busy || load.priority === priority}
                        key={priority}
                        onPress={() => void mutate(
                          () => changeDemoLoadPriority(load.id, priority, Crypto.randomUUID()),
                          `${load.code} ahora tiene prioridad ${priority}.`,
                        )}
                        style={[styles.miniAction, load.priority === priority && styles.miniActionActive]}
                      >
                        <Text style={styles.miniActionText}>{priority}</Text>
                      </Pressable>
                    ))}
                    <Pressable
                      disabled={busy}
                      onPress={() => confirmLoadCancellation(load.id, load.code)}
                      style={styles.cancelLoadButton}
                    >
                      <Text style={styles.cancelLoadButtonText}>Cancelar</Text>
                    </Pressable>
                  </View>
                ) : null}
              </View>
            ))}
          </View>
        </View>

        <View style={[styles.tables, wide && styles.tablesWide]}>
          <View style={styles.listCard}>
            <View style={styles.listHeader}>
              <View>
                <Text style={styles.cardEyebrow}>DATOS EDITABLES</Text>
                <Text style={styles.listTitle}>Clientes locales</Text>
              </View>
              <Text style={styles.count}>{dataset.clients.length}</Text>
            </View>
            {dataset.clients.map((client) => (
              <View key={client.id} style={styles.listRow}>
                <View style={styles.rowCopy}>
                  <Text style={styles.rowTitle}>{client.code} · {client.name}</Text>
                  <Text style={styles.rowMeta}>Prefijo {client.folioPrefix} · activo</Text>
                </View>
                <Pressable
                  disabled={busy}
                  onPress={() => confirmClientDeletion(client.id, client.name)}
                  style={styles.deleteButton}
                >
                  <Text style={styles.deleteButtonText}>Eliminar</Text>
                </Pressable>
              </View>
            ))}
          </View>

          <View style={styles.listCard}>
            <View style={styles.listHeader}>
              <View>
                <Text style={styles.cardEyebrow}>TRAZABILIDAD INICIAL</Text>
                <Text style={styles.listTitle}>Folios locales</Text>
              </View>
              <Text style={styles.count}>{dataset.folios.length}</Text>
            </View>
            {dataset.folios.map((folio) => (
              <View key={folio.id} style={styles.listRow}>
                <View style={styles.rowCopy}>
                  <Text style={styles.rowTitle}>{folio.number} · {folio.species} {folio.variety}</Text>
                  <Text style={styles.rowMeta}>
                    {folio.clientCode} · {folio.boxes} cajas · {folio.status}
                  </Text>
                </View>
                <Pressable
                  disabled={busy}
                  onPress={() => confirmFolioDeletion(folio.id, folio.number)}
                  style={styles.deleteButton}
                >
                  <Text style={styles.deleteButtonText}>Eliminar</Text>
                </Pressable>
              </View>
            ))}
          </View>
        </View>

        <View style={styles.resetPanel}>
          <View style={styles.resetCopy}>
            <Text style={styles.resetTitle}>¿Terminaste una demostración?</Text>
            <Text style={styles.resetText}>
              Prepara nuevamente folios, cámaras y cargas ficticias sin borrar clientes ni maestros.
            </Text>
          </View>
          <View style={styles.resetActions}>
            <Pressable disabled={busy} onPress={confirmOperationalReset} style={styles.resetButton}>
              <Text style={styles.resetButtonText}>Preparar nueva demo</Text>
            </Pressable>
            <Pressable disabled={busy} onPress={confirmFullReset} style={styles.fullResetButton}>
              <Text style={styles.fullResetButtonText}>Restaurar todo</Text>
            </Pressable>
          </View>
        </View>
      </ScrollView>
    </KeyboardAvoidingView>
  );
}

function messageFrom(reason: unknown): string {
  return reason instanceof Error ? reason.message : 'No fue posible actualizar la base demo.';
}

function loadStatusLabel(status: 'draft' | 'published' | 'cancelled') {
  return { draft: 'BORRADOR', published: 'PUBLICADA', cancelled: 'CANCELADA' }[status];
}

function Metric({ label, value }: { label: string; value: number }) {
  return (
    <View style={styles.metric}>
      <Text style={styles.metricValue}>{value}</Text>
      <Text style={styles.metricLabel}>{label}</Text>
    </View>
  );
}

function Message({ tone, text }: { tone: 'error' | 'success'; text: string }) {
  return (
    <View style={[styles.message, tone === 'error' ? styles.messageError : styles.messageSuccess]}>
      <Text style={styles.messageText}>{text}</Text>
    </View>
  );
}

type DemoFieldProps = {
  keyboardType?: 'number-pad';
  label: string;
  onChangeText: (value: string) => void;
  placeholder: string;
  value: string;
};

function DemoField({ label, ...props }: DemoFieldProps) {
  return (
    <View style={styles.field}>
      <Text style={styles.fieldLabel}>{label}</Text>
      <TextInput
        {...props}
        autoCapitalize="characters"
        placeholderTextColor="#737D82"
        selectionColor={colors.cyan}
        style={styles.input}
      />
    </View>
  );
}

function PrimaryButton({ busy, label, onPress }: { busy: boolean; label: string; onPress: () => void }) {
  return (
    <Pressable disabled={busy} onPress={onPress} style={[styles.primaryButton, busy && styles.disabled]}>
      {busy ? <ActivityIndicator color={colors.accentText} /> : <Text style={styles.primaryButtonText}>{label} →</Text>}
    </Pressable>
  );
}

const styles = StyleSheet.create({
  root: { flex: 1, backgroundColor: colors.background },
  page: { padding: 22, gap: 18 },
  loading: { flex: 1, alignItems: 'center', justifyContent: 'center', gap: 12, backgroundColor: colors.background },
  loadingText: { color: colors.muted, fontFamily: font.heavy },
  header: { flexDirection: 'row', flexWrap: 'wrap', alignItems: 'center', justifyContent: 'space-between', gap: 18, padding: 22, borderWidth: 1, borderColor: colors.cyanDark, borderRadius: 18, backgroundColor: colors.panel },
  headerCopy: { flex: 1, minWidth: 280 },
  headerActions: { alignItems: 'flex-end', gap: 10 },
  eyebrow: { color: colors.cyan, fontSize: 12, fontFamily: font.heavy, letterSpacing: 1.4 },
  title: { marginTop: 6, color: colors.text, fontSize: 28, fontFamily: font.heavy },
  intro: { maxWidth: 720, marginTop: 6, color: colors.muted, lineHeight: 20 },
  offlineChip: { flexDirection: 'row', alignItems: 'center', gap: 7, paddingHorizontal: 11, paddingVertical: 7, borderWidth: 1, borderColor: colors.greenDark, borderRadius: 9, backgroundColor: '#10261B' },
  offlineDot: { width: 8, height: 8, borderRadius: 4, backgroundColor: colors.green },
  offlineText: { color: colors.green, fontSize: 12, fontFamily: font.heavy },
  secondaryButton: { minWidth: 130, paddingHorizontal: 13, paddingVertical: 9, borderWidth: 1, borderColor: colors.border, borderRadius: 9, alignItems: 'center' },
  secondaryButtonText: { color: colors.text, fontSize: 12, fontFamily: font.heavy },
  metrics: { flexDirection: 'row', flexWrap: 'wrap', gap: 12 },
  metric: { flexGrow: 1, flexBasis: 150, padding: 16, borderWidth: 1, borderColor: colors.border, borderRadius: 14, backgroundColor: colors.panelStrong },
  metricValue: { color: colors.text, fontSize: 26, fontFamily: font.heavy },
  metricLabel: { marginTop: 2, color: colors.muted, fontSize: 12, fontFamily: font.heavy, textTransform: 'uppercase' },
  message: { padding: 12, borderWidth: 1, borderRadius: 11 },
  messageError: { borderColor: colors.red, backgroundColor: '#421B21' },
  messageSuccess: { borderColor: colors.greenDark, backgroundColor: '#10261B' },
  messageText: { color: colors.text, fontSize: 12, fontFamily: font.heavy },
  privacyPanel: { flexDirection: 'row', alignItems: 'center', gap: 13, padding: 16, borderWidth: 1, borderColor: colors.greenDark, borderRadius: 14, backgroundColor: '#10261B' },
  privacyIcon: { width: 34, height: 34, alignItems: 'center', justifyContent: 'center', borderRadius: 17, backgroundColor: colors.green },
  privacyIconText: { color: colors.accentText, fontSize: 17, fontFamily: font.heavy },
  privacyCopy: { flex: 1 },
  privacyTitle: { color: colors.text, fontSize: 13, fontFamily: font.heavy },
  privacyText: { fontFamily: font.regular, marginTop: 3, color: colors.muted, fontSize: 12, lineHeight: 15 },
  masterWorkspace: { gap: 16 },
  masterWorkspaceWide: { flexDirection: 'row', alignItems: 'flex-start' },
  masterCreator: { flex: 1, padding: 20, borderWidth: 1, borderColor: colors.greenDark, borderRadius: 16, backgroundColor: colors.panel },
  masterListPanel: { flex: 1, overflow: 'hidden', borderWidth: 1, borderColor: colors.border, borderRadius: 16, backgroundColor: colors.panel },
  forms: { gap: 16 },
  formsWide: { flexDirection: 'row' },
  card: { flex: 1, padding: 20, borderWidth: 1, borderColor: colors.border, borderRadius: 16, backgroundColor: colors.panel },
  cardEyebrow: { color: colors.cyan, fontSize: 12, fontFamily: font.heavy, letterSpacing: 1.2 },
  cardTitle: { marginTop: 5, color: colors.text, fontSize: 21, fontFamily: font.heavy },
  cardCopy: { fontFamily: font.regular, marginTop: 5, marginBottom: 5, color: colors.muted, fontSize: 12, lineHeight: 16 },
  field: { marginTop: 12, gap: 6 },
  fieldLabel: { marginTop: 12, color: colors.text, fontSize: 12, fontFamily: font.heavy },
  input: { fontFamily: font.regular, height: 46, paddingHorizontal: 12, borderWidth: 1, borderColor: colors.border, borderRadius: 10, backgroundColor: colors.backgroundDeep, color: colors.text, fontSize: 12 },
  clientChoices: { flexDirection: 'row', flexWrap: 'wrap', gap: 8, marginTop: 7 },
  clientChoice: { paddingHorizontal: 10, paddingVertical: 8, borderWidth: 1, borderColor: colors.border, borderRadius: 9, backgroundColor: colors.backgroundDeep },
  clientChoiceSelected: { borderColor: colors.cyan, backgroundColor: colors.selected },
  clientChoiceText: { color: colors.muted, fontSize: 12, fontFamily: font.heavy },
  clientChoiceTextSelected: { color: colors.cyan },
  inlineFields: { flexDirection: 'row', alignItems: 'flex-end', gap: 10 },
  inlineField: { flex: 1 },
  smallField: { width: 100 },
  primaryButton: { height: 46, marginTop: 18, paddingHorizontal: 14, borderRadius: 10, backgroundColor: colors.cyan, alignItems: 'center', justifyContent: 'center' },
  primaryButtonText: { color: colors.accentText, fontSize: 12, fontFamily: font.heavy },
  disabled: { opacity: 0.5 },
  loadWorkspace: { gap: 16 },
  loadWorkspaceWide: { flexDirection: 'row', alignItems: 'flex-start' },
  loadCreator: { flex: 1, padding: 20, borderWidth: 1, borderColor: colors.cyanDark, borderRadius: 16, backgroundColor: colors.panel },
  loadListPanel: { flex: 1, overflow: 'hidden', borderWidth: 1, borderColor: colors.border, borderRadius: 16, backgroundColor: colors.panel },
  choiceRow: { marginTop: 8, flexDirection: 'row', flexWrap: 'wrap', gap: 7 },
  choice: { paddingHorizontal: 11, paddingVertical: 8, borderWidth: 1, borderColor: colors.border, borderRadius: 8, backgroundColor: colors.backgroundDeep },
  choiceSelected: { borderColor: colors.cyan, backgroundColor: colors.selected },
  choiceText: { color: colors.muted, fontSize: 12, fontFamily: font.heavy },
  choiceTextSelected: { color: colors.cyan },
  selectionHeading: { marginTop: 7, flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
  selectionCount: { color: colors.cyan, fontSize: 12, fontFamily: font.heavy },
  folioChoicesScroll: { maxHeight: 260, marginTop: 8 },
  folioChoices: { gap: 7 },
  folioChoice: { padding: 10, borderWidth: 1, borderColor: colors.border, borderRadius: 9, backgroundColor: colors.backgroundDeep },
  folioChoiceSelected: { borderColor: colors.cyan, backgroundColor: colors.selected },
  folioChoiceTitle: { color: colors.text, fontSize: 12, fontFamily: font.monoBold },
  folioChoiceTitleSelected: { color: colors.cyan },
  folioChoiceMeta: { fontFamily: font.mono, marginTop: 3, color: colors.muted, fontSize: 12 },
  emptyCopy: { fontFamily: font.regular, padding: 11, color: colors.muted, fontSize: 12, lineHeight: 15 },
  loadRow: { padding: 14, gap: 8, borderBottomWidth: 1, borderBottomColor: colors.borderSoft },
  loadRowTop: { flexDirection: 'row', alignItems: 'flex-start', gap: 10 },
  statusChip: { paddingHorizontal: 8, paddingVertical: 5, borderWidth: 1, borderColor: colors.amber, borderRadius: 7, backgroundColor: colors.amberDark },
  statusPublished: { borderColor: colors.green, backgroundColor: colors.greenDark },
  statusCancelled: { borderColor: colors.red, backgroundColor: '#421B21' },
  statusText: { color: colors.text, fontSize: 12, fontFamily: font.heavy },
  loadFolios: { fontFamily: font.mono, color: colors.text, fontSize: 12, lineHeight: 14 },
  loadObservation: { fontFamily: font.regular, color: colors.muted, fontSize: 12, fontStyle: 'italic' },
  loadActions: { flexDirection: 'row', flexWrap: 'wrap', alignItems: 'center', gap: 6 },
  publishButton: { paddingHorizontal: 11, paddingVertical: 8, borderRadius: 8, backgroundColor: colors.cyan },
  publishButtonText: { color: colors.accentText, fontSize: 12, fontFamily: font.heavy },
  miniAction: { paddingHorizontal: 8, paddingVertical: 7, borderWidth: 1, borderColor: colors.border, borderRadius: 7 },
  miniActionActive: { borderColor: colors.cyan, backgroundColor: colors.selected, opacity: 0.7 },
  miniActionText: { color: colors.text, fontSize: 12, fontFamily: font.heavy, textTransform: 'uppercase' },
  cancelLoadButton: { marginLeft: 'auto', paddingHorizontal: 9, paddingVertical: 7, borderWidth: 1, borderColor: colors.red, borderRadius: 7 },
  cancelLoadButtonText: { color: colors.red, fontSize: 12, fontFamily: font.heavy },
  tables: { gap: 16 },
  tablesWide: { flexDirection: 'row', alignItems: 'flex-start' },
  listCard: { flex: 1, overflow: 'hidden', borderWidth: 1, borderColor: colors.border, borderRadius: 16, backgroundColor: colors.panel },
  listHeader: { padding: 17, flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', borderBottomWidth: 1, borderBottomColor: colors.border },
  listTitle: { marginTop: 4, color: colors.text, fontSize: 18, fontFamily: font.heavy },
  count: { color: colors.cyan, fontSize: 25, fontFamily: font.heavy },
  listRow: { minHeight: 64, paddingHorizontal: 16, paddingVertical: 11, flexDirection: 'row', alignItems: 'center', gap: 12, borderBottomWidth: 1, borderBottomColor: colors.borderSoft },
  inactiveRow: { opacity: 0.55 },
  rowCopy: { flex: 1 },
  rowTitle: { color: colors.text, fontSize: 12, fontFamily: font.heavy },
  rowMeta: { fontFamily: font.regular, marginTop: 4, color: colors.muted, fontSize: 12 },
  deleteButton: { paddingHorizontal: 10, paddingVertical: 7, borderWidth: 1, borderColor: colors.red, borderRadius: 8 },
  deleteButtonText: { color: colors.red, fontSize: 12, fontFamily: font.heavy },
  toggleButton: { paddingHorizontal: 10, paddingVertical: 7, borderWidth: 1, borderColor: colors.cyanDark, borderRadius: 8 },
  toggleButtonText: { color: colors.cyan, fontSize: 12, fontFamily: font.heavy },
  resetPanel: { flexDirection: 'row', flexWrap: 'wrap', alignItems: 'center', justifyContent: 'space-between', gap: 16, padding: 18, borderWidth: 1, borderColor: colors.amberDark, borderRadius: 15, backgroundColor: '#241B10' },
  resetCopy: { flex: 1, minWidth: 240 },
  resetTitle: { color: colors.text, fontSize: 15, fontFamily: font.heavy },
  resetText: { fontFamily: font.regular, marginTop: 4, color: colors.muted, fontSize: 12 },
  resetButton: { paddingHorizontal: 15, paddingVertical: 11, borderWidth: 1, borderColor: colors.amber, borderRadius: 9 },
  resetButtonText: { color: colors.amber, fontSize: 12, fontFamily: font.heavy },
  resetActions: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  fullResetButton: { paddingHorizontal: 15, paddingVertical: 11, borderWidth: 1, borderColor: colors.red, borderRadius: 9 },
  fullResetButtonText: { color: colors.red, fontSize: 12, fontFamily: font.heavy },
});
