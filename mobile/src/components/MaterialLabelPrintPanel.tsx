import * as Crypto from 'expo-crypto';
import { useEffect, useMemo, useRef, useState } from 'react';
import {
  ActivityIndicator,
  Pressable,
  StyleSheet,
  Text,
  TextInput,
  View,
} from 'react-native';

import {
  GenerateMaterialLabelsPayload,
  LabelPrintProfile,
  MaterialLabelDesign,
  MaterialPrintJob,
  MaterialPrintOutcomePayload,
  MaterialReception,
} from '../domain/materialReception';
import {
  isDirectPrinterAvailable,
  sendToPrinter,
  testPrinterConnection,
} from '../../modules/estiba-printer-socket';
import { MaterialReceptionApi } from '../services/materialReceptionApi';
import {
  loadPrinterConfiguration,
  PrinterConfiguration,
  savePrinterConfiguration,
  validatePrinterConfiguration,
} from '../services/printerConfiguration';
import { colors } from '../theme/colors';

export type DirectMaterialLabelFolio = {
  id: string;
  number: string;
  item: string;
  quantity: string;
};

export type DirectMaterialLabelApi = {
  printProfiles: () => Promise<LabelPrintProfile[]>;
  printJobs: () => Promise<MaterialPrintJob[]>;
  generateLabels: (payload: GenerateMaterialLabelsPayload) => Promise<{ jobId: string; zpl: string }>;
  reportPrintOutcome: (jobId: string, payload: MaterialPrintOutcomePayload) => Promise<void>;
};

type Props = {
  api: MaterialReceptionApi;
  deviceId: string;
  reception: MaterialReception;
} | {
  deviceId: string;
  sourceApi: DirectMaterialLabelApi;
  sourceFolios: DirectMaterialLabelFolio[];
  sourceId: string;
  sourceLabel: string;
};

type PendingReport = {
  jobId: string;
  payload: MaterialPrintOutcomePayload;
};

const EMPTY_CONFIGURATION: PrinterConfiguration = {
  name: 'Impresora etiquetas',
  host: '',
  port: 9100,
  profileId: '',
};

export function MaterialLabelPrintPanel(props: Props) {
  const reception = 'reception' in props ? props.reception : null;
  const receptionApi = 'reception' in props ? props.api : null;
  const deviceId = props.deviceId;
  const sourceId = reception?.id ?? ('sourceId' in props ? props.sourceId : '');
  const sourceLabel = reception
    ? `recepción ${reception.numero_guia_despacho}`
    : ('sourceLabel' in props ? props.sourceLabel : 'origen');
  const sourceApi: DirectMaterialLabelApi = reception && receptionApi
    ? {
      printProfiles: () => receptionApi.printProfiles(),
      printJobs: () => receptionApi.printJobs(reception.id),
      generateLabels: (payload) => receptionApi.generateLabels(reception.id, payload),
      reportPrintOutcome: (jobId, payload) => receptionApi.reportPrintOutcome(jobId, payload),
    }
    : ('sourceApi' in props ? props.sourceApi : {
      printProfiles: async () => [],
      printJobs: async () => [],
      generateLabels: async () => { throw new Error('Origen de etiquetas no configurado.'); },
      reportPrintOutcome: async () => undefined,
    });
  const folios = useMemo(() => reception
    ? (reception.detalles || []).flatMap((detail) =>
      detail.bultos.filter((itemPackage) => itemPackage.folio).map((itemPackage) => ({
        id: itemPackage.folio!.id,
        number: itemPackage.folio!.numero_folio,
        item: `${detail.item?.codigo ?? '—'} · ${detail.item?.nombre ?? 'Ítem sin datos'}`,
        quantity: `${itemPackage.cantidad} ${detail.unidad_medida}`,
      })))
    : ('sourceFolios' in props ? props.sourceFolios : []), [reception, props]);
  const folioKey = folios.map((folio) => folio.id).sort().join(':');
  const generation = useRef<{ key: string; operationId: string } | null>(null);
  const [profiles, setProfiles] = useState<LabelPrintProfile[]>([]);
  const [history, setHistory] = useState<MaterialPrintJob[]>([]);
  const [configuration, setConfiguration] = useState<PrinterConfiguration>(EMPTY_CONFIGURATION);
  const [selected, setSelected] = useState<Set<string>>(new Set());
  const [copies, setCopies] = useState('1');
  const [reason, setReason] = useState('');
  const [design, setDesign] = useState<MaterialLabelDesign>('actual');
  const [showConfiguration, setShowConfiguration] = useState(false);
  const [busy, setBusy] = useState(true);
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');
  const [pendingReport, setPendingReport] = useState<PendingReport | null>(null);
  const selectedProfile = profiles.find((profile) => profile.id === configuration.profileId);
  const sizes = [Number(selectedProfile?.ancho_mm), Number(selectedProfile?.alto_mm)].sort((a, b) => a - b);
  const classicAvailable = Boolean(reception) && selectedProfile?.disenos_disponibles?.includes('clasica_bodega') === true
    && Math.abs(sizes[0] - 100) < 0.01 && Math.abs(sizes[1] - 200) < 0.01;

  useEffect(() => {
    void load();
  }, [deviceId, sourceId, folioKey]);

  async function load() {
    setBusy(true);
    setError('');
    setDesign('actual');
    try {
      const [loadedProfiles, loadedHistory, saved] = await Promise.all([
        sourceApi.printProfiles(),
        sourceApi.printJobs(),
        loadPrinterConfiguration(deviceId),
      ]);
      setProfiles(loadedProfiles);
      setHistory(loadedHistory);
      const defaultProfile = loadedProfiles.find((profile) => profile.predeterminado)
        ?? loadedProfiles[0];
      setConfiguration({
        ...(saved ?? EMPTY_CONFIGURATION),
        profileId: loadedProfiles.some((profile) => profile.id === saved?.profileId)
          ? saved!.profileId
          : defaultProfile?.id ?? '',
      });
      setSelected(new Set(folios.map((folio) => folio.id)));
    } catch (loadError) {
      setError(errorMessage(loadError));
    } finally {
      setBusy(false);
    }
  }

  async function saveAndTest() {
    setBusy(true);
    setMessage('');
    setError('');
    try {
      const saved = await savePrinterConfiguration(deviceId, configuration);
      setConfiguration(saved);
      const result = await testPrinterConnection(saved.host, saved.port);
      if (result.status !== 'connected') throw new Error(result.message);
      setMessage(`Conexión correcta con ${saved.name} (${saved.host}:${saved.port}).`);
    } catch (testError) {
      setError(errorMessage(testError));
    } finally {
      setBusy(false);
    }
  }

  async function print() {
    if (pendingReport) {
      setError('Primero vuelve a informar el resultado pendiente; no se reenviará la etiqueta automáticamente.');
      return;
    }
    setError('');
    setMessage('');
    if (design === 'clasica_bodega' && !classicAvailable) {
      setError('Clásica bodega requiere una recepción y un perfil de etiqueta de 200 × 100 mm. Revisa la configuración de impresora.');
      return;
    }
    if (!isDirectPrinterAvailable()) {
      setError('Esta instalación no incluye impresión IP. Instala la nueva APK antes de probar.');
      return;
    }

    let printer: PrinterConfiguration;
    try {
      printer = validatePrinterConfiguration(configuration);
      await savePrinterConfiguration(deviceId, printer);
    } catch (configurationError) {
      setError(errorMessage(configurationError));
      setShowConfiguration(true);
      return;
    }
    const folioIds = [...selected].sort();
    if (!folioIds.length) {
      setError('Selecciona al menos un folio para imprimir.');
      return;
    }
    const printCopies = Number(copies);
    if (!Number.isInteger(printCopies) || printCopies < 1 || printCopies > 20) {
      setError('Las copias deben ser un número entre 1 y 20.');
      return;
    }
    const printed = new Set(history.flatMap((job) => job.folios.map((folio) => folio.id)));
    const isReprint = folioIds.some((folioId) => printed.has(folioId));
    if (isReprint && reason.trim().length < 5) {
      setError('Indica el motivo de reimpresión para los folios ya generados.');
      return;
    }

    const operationKey = JSON.stringify({
      sourceId,
      profileId: printer.profileId,
      folioIds,
      copies: printCopies,
      reason: reason.trim() || null,
      design,
    });
    if (generation.current?.key !== operationKey) {
      generation.current = { key: operationKey, operationId: Crypto.randomUUID() };
    }
    const payload: GenerateMaterialLabelsPayload = {
      operacion_id: generation.current.operationId,
      perfil_id: printer.profileId,
      formato: 'zpl',
      canal: 'pda_directa',
      folio_ids: folioIds,
      copias: printCopies,
      motivo_reimpresion: reason.trim() || null,
      ...(design === 'clasica_bodega' ? { diseno: design } : {}),
    };

    setBusy(true);
    try {
      const generated = await sourceApi.generateLabels(payload);
      const result = await sendToPrinter(printer.host, printer.port, generated.zpl);
      const outcome: MaterialPrintOutcomePayload = {
        operacion_id: Crypto.randomUUID(),
        estado: result.status === 'sent'
          ? 'enviado'
          : result.status === 'indeterminate'
            ? 'indeterminado'
            : 'fallido',
        bytes_enviados: result.bytesSent,
        error: result.status === 'sent' ? null : result.message,
        impresora: {
          nombre: printer.name,
          host: printer.host,
          puerto: printer.port,
        },
      };
      const report = { jobId: generated.jobId, payload: outcome };
      setPendingReport(report);
      await sourceApi.reportPrintOutcome(report.jobId, report.payload);
      setPendingReport(null);
      generation.current = null;
      setReason('');
      setHistory(await sourceApi.printJobs());
      if (outcome.estado === 'enviado') {
        setMessage(`${folioIds.length} etiqueta(s) enviadas a ${printer.name}.`);
      } else if (outcome.estado === 'indeterminado') {
        setError('El envío quedó indeterminado. Revisa físicamente la impresora antes de decidir una reimpresión.');
      } else {
        setError(`No se enviaron datos a la impresora: ${result.message}`);
      }
    } catch (printError) {
      setError(errorMessage(printError));
    } finally {
      setBusy(false);
    }
  }

  async function retryPendingReport() {
    if (!pendingReport) return;
    setBusy(true);
    setError('');
    try {
      await sourceApi.reportPrintOutcome(pendingReport.jobId, pendingReport.payload);
      const outcome = pendingReport.payload.estado;
      setPendingReport(null);
      generation.current = null;
      setHistory(await sourceApi.printJobs());
      setMessage(outcome === 'enviado'
        ? 'Resultado confirmado en la trazabilidad.'
        : 'Resultado registrado. Verifica la impresora antes de reimprimir.');
    } catch (reportError) {
      setError(`El resultado sigue pendiente de informar: ${errorMessage(reportError)}`);
    } finally {
      setBusy(false);
    }
  }

  function toggle(folioId: string) {
    setSelected((current) => {
      const next = new Set(current);
      if (next.has(folioId)) next.delete(folioId);
      else next.add(folioId);
      generation.current = null;
      return next;
    });
  }

  if (!folios.length) return null;

  const generatedFolios = new Set(history.flatMap((job) => job.folios.map((folio) => folio.id)));

  return (
    <View style={styles.panel}>
      <View style={styles.between}>
        <View style={styles.copy}>
          <Text style={styles.eyebrow}>IMPRESIÓN DIRECTA</Text>
          <Text style={styles.title}>Etiquetas por IP</Text>
          <Text style={styles.muted}>
            {configuration.host
              ? `${configuration.name} · ${configuration.host}:${configuration.port}`
              : 'Configura la impresora de esta PDA/tablet.'}
          </Text>
        </View>
        <Action label={showConfiguration ? 'Cerrar configuración' : 'Configurar impresora'} onPress={() => setShowConfiguration(!showConfiguration)} secondary />
      </View>

      {showConfiguration ? (
        <View style={styles.configuration}>
          <Input label="Nombre" value={configuration.name} onChange={(name) => setConfiguration({ ...configuration, name })} />
          <Input label="IPv4" value={configuration.host} onChange={(host) => setConfiguration({ ...configuration, host })} placeholder="192.168.1.50" />
          <Input label="Puerto RAW" value={String(configuration.port)} onChange={(port) => setConfiguration({ ...configuration, port: Number(port.replace(/\D/g, '')) || 0 })} placeholder="9100" numeric />
          <View style={styles.profileList}>
            <Text style={styles.label}>PERFIL / TAMAÑO</Text>
            {profiles.map((profile) => (
              <Pressable
                key={profile.id}
                onPress={() => setConfiguration({ ...configuration, profileId: profile.id })}
                style={[styles.profile, configuration.profileId === profile.id && styles.selected]}
              >
                <Text style={styles.profileTitle}>{profile.nombre}</Text>
                <Text style={styles.muted}>{profile.fabricante} · {profile.ancho_mm}×{profile.alto_mm} mm · {profile.dpi} dpi</Text>
              </Pressable>
            ))}
          </View>
          <Action label="Guardar y probar conexión" onPress={() => void saveAndTest()} />
        </View>
      ) : null}

      {reception ? (
        <View style={styles.profileList}>
          <Text style={styles.label}>DISEÑO DE ETIQUETA</Text>
          {(['actual', 'clasica_bodega'] as const).map((option) => (
            <Pressable
              key={option}
              accessibilityRole="radio"
              accessibilityState={{ checked: design === option, disabled: busy || Boolean(pendingReport) || (option === 'clasica_bodega' && !classicAvailable) }}
              disabled={busy || Boolean(pendingReport) || (option === 'clasica_bodega' && !classicAvailable)}
              onPress={() => { setDesign(option); generation.current = null; }}
              style={[styles.profile, design === option && styles.selected, option === 'clasica_bodega' && !classicAvailable && styles.disabled]}
            >
              <Text style={styles.profileTitle}>{option === 'actual' ? 'Actual' : 'Clásica bodega · cantidad destacada'}</Text>
            </Pressable>
          ))}
          <Text style={styles.muted}>{classicAvailable
            ? 'Clásica bodega: cliente dueño, fecha real y cantidad recibida por bulto. Conserva el folio escaneable; se gira según el perfil de impresora.'
            : 'Clásica bodega requiere el servidor actualizado y un perfil de 200 × 100 mm.'}</Text>
        </View>
      ) : null}

      <View style={styles.folios}>
        <View style={styles.between}>
          <Text style={styles.label}>FOLIOS A IMPRIMIR</Text>
          <Action
            label={selected.size === folios.length ? 'Quitar todos' : 'Seleccionar todos'}
            onPress={() => setSelected(selected.size === folios.length
              ? new Set()
              : new Set(folios.map((folio) => folio.id)))}
            secondary
            small
          />
        </View>
        {folios.map((folio) => (
          <Pressable
            key={folio.id}
            onPress={() => toggle(folio.id)}
            style={[styles.folio, selected.has(folio.id) && styles.selected]}
          >
            <Text style={styles.checkbox}>{selected.has(folio.id) ? '✓' : '○'}</Text>
            <View style={styles.copy}>
              <Text style={styles.folioNumber}>{folio.number}{generatedFolios.has(folio.id) ? ' · REIMPRESIÓN' : ''}</Text>
              <Text style={styles.muted}>{folio.item} · {folio.quantity}</Text>
            </View>
          </Pressable>
        ))}
      </View>

      <View style={styles.controls}>
        <Input label="Copias por folio" value={copies} onChange={(value) => {
          setCopies(value.replace(/\D/g, ''));
          generation.current = null;
        }} numeric />
        <Input label="Motivo de reimpresión" value={reason} onChange={(value) => {
          setReason(value);
          generation.current = null;
        }} placeholder="Obligatorio si ya fue impresa" />
      </View>

      {error ? <Text style={styles.error}>{error}</Text> : null}
      {message ? <Text style={styles.success}>{message}</Text> : null}
      {pendingReport ? (
        <View style={styles.pending}>
          <Text style={styles.error}>El servidor aún no recibió el resultado. No se reenviará la etiqueta.</Text>
          <Action label="Reintentar solo el registro" onPress={() => void retryPendingReport()} secondary />
        </View>
      ) : null}
      <View style={styles.between}>
        <Text style={styles.muted}>{history.length} trabajo(s) auditados para {sourceLabel}.</Text>
        <Action label={`Imprimir ${selected.size} folio(s)`} onPress={() => void print()} disabled={busy || Boolean(pendingReport)} />
      </View>
      {busy ? <ActivityIndicator color={colors.cyan} /> : null}
    </View>
  );
}

function Action({ label, onPress, secondary = false, small = false, disabled = false }: {
  label: string;
  onPress: () => void;
  secondary?: boolean;
  small?: boolean;
  disabled?: boolean;
}) {
  return (
    <Pressable
      disabled={disabled}
      onPress={onPress}
      style={[styles.button, secondary && styles.buttonSecondary, small && styles.buttonSmall, disabled && styles.disabled]}
    >
      <Text style={styles.buttonText}>{label}</Text>
    </Pressable>
  );
}

function Input({ label, value, onChange, placeholder, numeric = false }: {
  label: string;
  value: string;
  onChange: (value: string) => void;
  placeholder?: string;
  numeric?: boolean;
}) {
  return (
    <View style={styles.field}>
      <Text style={styles.label}>{label}</Text>
      <TextInput
        keyboardType={numeric ? 'number-pad' : 'default'}
        onChangeText={onChange}
        placeholder={placeholder}
        placeholderTextColor={colors.muted}
        style={styles.input}
        value={value}
      />
    </View>
  );
}

function errorMessage(error: unknown): string {
  return error instanceof Error ? error.message : 'Ocurrió un error inesperado.';
}

const styles = StyleSheet.create({
  panel: { gap: 12, padding: 15, borderRadius: 13, borderWidth: 1, borderColor: colors.cyanDark, backgroundColor: colors.panel },
  between: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 10, flexWrap: 'wrap' },
  copy: { flex: 1 },
  eyebrow: { color: colors.cyan, fontSize: 13, fontWeight: '900', letterSpacing: 1.1 },
  title: { color: colors.text, fontSize: 16, fontWeight: '900', marginTop: 3 },
  muted: { color: colors.muted, fontSize: 14, lineHeight: 18, marginTop: 2 },
  configuration: { gap: 9, padding: 12, borderRadius: 11, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.backgroundDeep },
  controls: { flexDirection: 'row', flexWrap: 'wrap', gap: 9 },
  field: { minWidth: 180, flexGrow: 1, flexBasis: 190, gap: 5 },
  label: { color: colors.muted, fontSize: 13, fontWeight: '900', textTransform: 'uppercase', letterSpacing: 0.4 },
  input: { minHeight: 42, borderRadius: 9, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.backgroundDeep, color: colors.text, paddingHorizontal: 10, fontWeight: '700' },
  profileList: { gap: 6 },
  profile: { padding: 9, borderRadius: 8, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.panel },
  profileTitle: { color: colors.text, fontSize: 14, fontWeight: '900' },
  selected: { borderColor: colors.cyan, backgroundColor: colors.selected },
  folios: { gap: 7 },
  folio: { flexDirection: 'row', alignItems: 'center', gap: 9, padding: 10, borderRadius: 9, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.backgroundDeep },
  checkbox: { width: 20, color: colors.cyan, fontSize: 16, fontWeight: '900' },
  folioNumber: { color: colors.text, fontSize: 14, fontWeight: '900' },
  button: { alignSelf: 'flex-start', paddingHorizontal: 14, paddingVertical: 9, borderRadius: 9, backgroundColor: colors.cyan },
  buttonSecondary: { borderWidth: 1, borderColor: colors.cyanDark, backgroundColor: colors.selected },
  buttonSmall: { paddingHorizontal: 9, paddingVertical: 6 },
  buttonText: { color: colors.accentText, fontSize: 13, fontWeight: '900' },
  disabled: { opacity: 0.45 },
  error: { color: colors.red, fontSize: 14, fontWeight: '800', lineHeight: 18 },
  success: { color: colors.green, fontSize: 14, fontWeight: '800', lineHeight: 18 },
  pending: { gap: 8, padding: 10, borderRadius: 9, borderWidth: 1, borderColor: colors.blockedBorder, backgroundColor: colors.blocked },
});
