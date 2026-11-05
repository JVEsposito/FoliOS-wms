import { font } from '../theme/typography';
import { FieldHeader } from '../components/field/FieldHeader';
import AsyncStorage from '@react-native-async-storage/async-storage';
import * as Crypto from 'expo-crypto';
import { ReactNode, useEffect, useMemo, useRef, useState } from 'react';
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

import { ScanInput, ScanInputHandle } from '../components/ui/ScanInput';
import { isPdaBuild } from '../config/appVariant';
import { AuthSession } from '../domain/estiba';
import { Repalletizing, RepalletizingFolio } from '../domain/repaletizaje';
import {
  createRepalletizing,
  findRepalletizingFolio,
  listRepalletizings,
} from '../services/repaletizajeApi';
import { colors } from '../theme/colors';

type Props = {
  auth: AuthSession;
  baseUrl: string;
  onLogout: () => void;
  /** Módulo propio del tarjador; dentro de Validación se muestra como pestaña. */
  standalone?: boolean;
};

type Shift = 'A' | 'B';

type Source = Omit<RepalletizingFolio, 'composicion'> & {
  composicion: Array<RepalletizingFolio['composicion'][number] & { aporte: string }>;
};
type ResultType = 'pallet' | 'saldo';
type FolioStrategy = 'conservar' | 'nuevo';
type HardField = 'cliente' | 'especie' | 'marca' | 'condicion_termica';
type MixField = 'variedad' | 'calibre' | 'envase' | 'categoria' | 'csg' | 'predio' | 'cuartel';

const HARD_FIELDS: Array<{ key: HardField; label: string }> = [
  { key: 'cliente', label: 'cliente' },
  { key: 'especie', label: 'especie' },
  { key: 'marca', label: 'marca' },
  { key: 'condicion_termica', label: 'estado térmico' },
];

const MIX_FIELDS: Array<{ key: MixField; label: string }> = [
  { key: 'variedad', label: 'variedad' },
  { key: 'calibre', label: 'calibre' },
  { key: 'envase', label: 'envase' },
  { key: 'categoria', label: 'categoría' },
  { key: 'csg', label: 'CSG' },
  { key: 'predio', label: 'predio' },
  { key: 'cuartel', label: 'cuartel' },
];

export function RepalletizingScreen({ auth, baseUrl, onLogout, standalone = false }: Props) {
  const { width } = useWindowDimensions();
  const tight = isPdaBuild || width < 420;
  const lookupInput = useRef<ScanInputHandle>(null);
  const shiftKey = `estiba_repa_turno:${auth.usuario.id}:${auth.dispositivo?.id ?? 'sin-dispositivo'}`;
  const [shift, setShift] = useState<Shift | null>(null);
  const [operationalDay, setOperationalDay] = useState<'hoy' | 'ayer'>('hoy');
  const [resultType, setResultType] = useState<ResultType>('pallet');
  const [strategy, setStrategy] = useState<FolioStrategy>('conservar');
  const [capacity, setCapacity] = useState('120');
  const [resultNumber, setResultNumber] = useState('');
  const [keptId, setKeptId] = useState('');
  const [lookup, setLookup] = useState('');
  const [sources, setSources] = useState<Source[]>([]);
  const [history, setHistory] = useState<Repalletizing[]>([]);
  const [busy, setBusy] = useState(false);
  const [loadingHistory, setLoadingHistory] = useState(true);
  const [error, setError] = useState('');
  const [message, setMessage] = useState('');

  useEffect(() => {
    void reloadHistory();
    // El turno se elige una vez por equipo y se recuerda entre repas.
    AsyncStorage.getItem(shiftKey)
      .then((stored) => {
        if (stored === 'A' || stored === 'B') setShift(stored);
      })
      .catch(() => undefined);
  }, [shiftKey]);

  function chooseShift(value: Shift) {
    setShift(value);
    if (value === 'A') setOperationalDay('hoy');
    void AsyncStorage.setItem(shiftKey, value).catch(() => undefined);
  }

  const total = useMemo(
    () => sources.reduce((sum, source) => sum + sourceTotal(source), 0),
    [sources],
  );
  const resultComposition = useMemo(() => aggregateComposition(sources), [sources]);

  const hardMismatches = useMemo(
    () => HARD_FIELDS.filter(({ key }) => distinctCount(sources, key) > 1),
    [sources],
  );

  const mixFields = useMemo(
    () => MIX_FIELDS.filter(({ key }) => distinctCount(sources, key) > 1),
    [sources],
  );

  const actualResultNumber = strategy === 'conservar'
    ? sources.find((source) => source.id === keptId)?.numero_folio ?? ''
    : resultNumber.trim().toUpperCase();

  async function reloadHistory() {
    setLoadingHistory(true);
    try {
      setHistory(await listRepalletizings(baseUrl, auth.token));
    } catch (reason) {
      setError(messageOf(reason));
    } finally {
      setLoadingHistory(false);
    }
  }

  async function addSource(scanned?: string) {
    const number = (scanned ?? lookup).trim().toUpperCase();
    setError('');
    setMessage('');
    if (!number) {
      setError('Escanea o escribe un folio.');
      return;
    }
    if (sources.some((source) => source.numero_folio === number)) {
      setError('Ese folio ya fue agregado.');
      return;
    }

    setBusy(true);
    try {
      const source = await findRepalletizingFolio(baseUrl, auth.token, number);
      if (!source.existe) {
        throw new Error('El folio no existe.');
      }
      if (!source.activo || source.tipo_bulto !== 'saldo' || source.cantidad_cajas < 1) {
        throw new Error('El folio no es un saldo activo con cajas disponibles.');
      }
      if (!['pendiente_prefrio', 'prefrio_aprobado'].includes(source.condicion_termica)) {
        throw new Error('El folio posee un estado térmico transitorio o retenido.');
      }

      const target = numeric(capacity);
      const remaining = Math.max(0, target - total);
      const contribution = resultType === 'pallet'
        ? Math.min(source.cantidad_cajas, remaining || source.cantidad_cajas)
        : source.cantidad_cajas;
      setSources((current) => [
        ...current,
        {
          ...source,
          composicion: allocateComposition(source.composicion, contribution),
        },
      ]);
      if (!keptId) {
        setKeptId(source.id);
      }
      setLookup('');
    } catch (reason) {
      setError(messageOf(reason));
    } finally {
      setBusy(false);
    }
  }

  function updateContribution(id: string, key: string, value: string) {
    setSources((current) => current.map((source) => source.id === id
      ? {
        ...source,
        composicion: source.composicion.map((line) => line.clave === key
          ? { ...line, aporte: value.replace(/[^0-9]/g, '') }
          : line),
      }
      : source));
  }

  function removeSource(id: string) {
    setSources((current) => current.filter((source) => source.id !== id));
    if (keptId === id) {
      setKeptId('');
    }
  }

  function clearForm() {
    setSources([]);
    setLookup('');
    setResultNumber('');
    setKeptId('');
    setError('');
  }

  async function submit() {
    setError('');
    setMessage('');
    const target = numeric(capacity);

    if (!shift) {
      setError('Indica el turno antes de confirmar la repa.');
      return;
    }
    if (sources.length < 2) {
      setError('Agrega al menos dos saldos.');
      return;
    }
    if (hardMismatches.length) {
      setError('Cliente, especie, marca y estado térmico deben coincidir.');
      return;
    }
    if (!actualResultNumber) {
      setError('Define el folio resultante.');
      return;
    }
    if (sources.some((source) => (
      sourceTotal(source) < 1 || source.composicion.some((line) => (
        numeric(line.aporte) > line.cantidad_cajas
      ))
    ))) {
      setError('Revisa las cajas aportadas por cada saldo.');
      return;
    }
    if (resultType === 'pallet' && (!target || total !== target)) {
      setError(`El pallet debe completar exactamente ${target || 'la capacidad indicada'} cajas.`);
      return;
    }
    if (resultType === 'saldo' && target && total >= target) {
      setError('El saldo consolidado debe quedar bajo la capacidad completa.');
      return;
    }

    const kept = sources.find((source) => source.id === keptId);
    if (strategy === 'conservar'
      && (!kept || sourceTotal(kept) !== kept.cantidad_cajas)) {
      setError('El folio conservado debe aportar todas sus cajas.');
      return;
    }

    setBusy(true);
    try {
      const result = await createRepalletizing(baseUrl, auth.token, {
        operacion_id: Crypto.randomUUID(),
        modalidad: 'consolidacion',
        tipo_resultado: resultType,
        estrategia_folio: strategy,
        numero_folio_resultante: actualResultNumber,
        folio_conservado_id: strategy === 'conservar' ? keptId : null,
        cantidad_objetivo: target || null,
        origenes: sources.map((source) => ({
          folio_id: source.id,
          cantidad_aportada: sourceTotal(source),
          composicion: source.composicion
            .filter((line) => numeric(line.aporte) > 0)
            .map((line) => ({
              clave: line.clave,
              cantidad_aportada: numeric(line.aporte),
            })),
        })),
        observacion: null,
        turno: shift,
        fecha_operacional: shift === 'B' ? operationalDay : 'hoy',
      });
      setMessage(`${result.codigo}: ${result.folio_resultante.numero_folio} confirmado.`);
      clearForm();
      lookupInput.current?.focus();
      await reloadHistory();
    } catch (reason) {
      setError(messageOf(reason));
    } finally {
      setBusy(false);
    }
  }

  return (
    <View style={styles.screen}>
      <FieldHeader area="frigorifico" auth={auth} title="Repaletizaje" subtitle="Cliente, especie, marca y estado térmico compatibles" onLogout={onLogout}><Pressable accessibilityLabel="Actualizar repaletizajes" disabled={loadingHistory} onPress={() => void reloadHistory()} style={styles.secondary}><Text style={styles.secondaryText}>↻</Text></Pressable></FieldHeader>

      <ScrollView contentContainerStyle={[styles.content, tight && styles.contentTight]} keyboardShouldPersistTaps="handled">
        <View style={styles.shiftRow}>
          <Text style={styles.fieldLabel}>Turno RRPL-01</Text>
          <View style={styles.shiftOptions}>
            <Toggle active={shift === 'A'} label="A" onPress={() => chooseShift('A')} />
            <Toggle active={shift === 'B'} label="B" onPress={() => chooseShift('B')} />
          </View>
        </View>
        {!shift ? <Text style={styles.shiftHint}>Elige el turno: identifica tu planilla de repaletizaje.</Text> : null}
        {shift === 'B' ? (
          <View style={styles.shiftRow}>
            <Text style={styles.fieldLabel}>Fecha RRPL-01</Text>
            <View style={styles.shiftOptions}>
              <Toggle active={operationalDay === 'hoy'} label="Hoy" onPress={() => setOperationalDay('hoy')} />
              <Toggle active={operationalDay === 'ayer'} label="Ayer" onPress={() => setOperationalDay('ayer')} />
            </View>
          </View>
        ) : null}
        {shift === 'B' && operationalDay === 'ayer' ? (
          <Text style={styles.shiftHint}>Esta repa se anotará en la planilla del día anterior.</Text>
        ) : null}
        <View style={styles.options}>
          <Toggle active={resultType === 'pallet'} label="Pallet completo" onPress={() => setResultType('pallet')} />
          <Toggle active={resultType === 'saldo'} label="Saldo consolidado" onPress={() => setResultType('saldo')} />
        </View>
        <View style={styles.options}>
          <Toggle active={strategy === 'conservar'} label="Conservar folio" onPress={() => setStrategy('conservar')} />
          <Toggle active={strategy === 'nuevo'} label="Otro folio" onPress={() => setStrategy('nuevo')} />
        </View>

        <Field label="Capacidad del pallet">
          <TextInput
            keyboardType="number-pad"
            onChangeText={setCapacity}
            style={styles.input}
            value={capacity}
          />
        </Field>
        {strategy === 'nuevo' ? (
          <Field label="Folio resultante">
            <ScanInput
              onChangeText={setResultNumber}
              onSubmit={(code) => {
                setResultNumber(code);
                lookupInput.current?.focus();
              }}
              placeholder={tight ? 'Pistolea el folio nuevo' : 'Escanear o escribir'}
              submitLabel="OK"
              value={resultNumber}
            />
          </Field>
        ) : null}

        <Field label="Saldo de origen">
          <ScanInput
            autoFocus={isPdaBuild}
            disabled={busy}
            onChangeText={setLookup}
            onSubmit={(code) => void addSource(code)}
            placeholder={tight ? 'Pistolea el saldo' : 'Escanear o escribir saldo'}
            ref={lookupInput}
            submitLabel="Agregar"
            value={lookup}
          />
        </Field>

        {hardMismatches.length ? (
          <Text style={styles.blocked}>
            BLOQUEADO: {hardMismatches.map(({ label }) => label).join(' · ')}
          </Text>
        ) : null}
        {mixFields.length ? (
          <View style={styles.warningRow}>
            {mixFields.map(({ key, label }) => (
              <Text key={key} style={styles.warning}>⚠ MIX {label.toUpperCase()}</Text>
            ))}
          </View>
        ) : null}

        {sources.map((source) => (
          <View key={source.id} style={styles.sourceCard}>
            <Pressable
              disabled={strategy !== 'conservar'}
              onPress={() => setKeptId(source.id)}
              style={[
                styles.keepButton,
                keptId === source.id && strategy === 'conservar' && styles.keepButtonActive,
              ]}
            >
              <Text style={styles.keepText}>
                {keptId === source.id && strategy === 'conservar' ? '✓ RESULTADO' : 'CONSERVAR'}
              </Text>
            </Pressable>
            <View style={styles.sourceCopy}>
              <Text style={styles.sourceNumber}>{source.numero_folio}</Text>
              <Text style={styles.sourceMeta}>
                {source.cantidad_cajas} cajas · {source.cliente} · {source.especie}
              </Text>
              <Text style={styles.sourceMeta}>
                {source.marca} · {source.calibre} · {source.condicion_termica}
              </Text>
              <Text style={styles.sourceLocation}>
                {source.ubicacion?.camara
                  ? `${source.ubicacion.camara.codigo}${source.ubicacion.posicion ? ` · ${source.ubicacion.posicion.etiqueta}` : ''}`
                  : 'Sin ubicación en cámara'}
              </Text>
            </View>
            {source.composicion.map((line) => (
              <View key={line.clave} style={styles.compositionLine}>
                <View style={styles.sourceCopy}>
                  <Text style={styles.sourceMeta}>CSG {line.csg} · {line.fecha_embalaje ?? 'Sin fecha'}</Text>
                  <Text style={styles.sourceMeta}>{line.cantidad_cajas} cajas disponibles</Text>
                </View>
                <Field label="Aporta">
                  <TextInput
                    keyboardType="number-pad"
                    onChangeText={(value) => updateContribution(source.id, line.clave, value)}
                    style={styles.contributionInput}
                    value={line.aporte}
                  />
                </Field>
              </View>
            ))}
            <Text style={styles.remaining}>
              Queda {Math.max(0, source.cantidad_cajas - sourceTotal(source))}
            </Text>
            <Pressable onPress={() => removeSource(source.id)}>
              <Text style={styles.remove}>Quitar</Text>
            </Pressable>
          </View>
        ))}

        <View style={styles.preview}>
          <Text style={styles.previewLabel}>RESULTADO</Text>
          <Text style={styles.previewNumber}>{actualResultNumber || 'Sin definir'}</Text>
          <Text style={styles.previewMeta}>
            {resultType === 'pallet' ? `${total}/${capacity || '—'} cajas` : `${total} cajas`}
            {' · '}{sources[0]?.condicion_termica ?? '—'}
          </Text>
          {resultComposition.map((line) => (
            <View key={`${line.csg}-${line.fecha_embalaje}-${line.predio}`} style={styles.previewCompositionLine}>
              <Text style={styles.previewCompositionText}>CSG {line.csg} · {line.fecha_embalaje ?? 'Sin fecha'}</Text>
              <Text style={styles.previewCompositionQuantity}>{line.cantidad_cajas} cajas</Text>
            </View>
          ))}
        </View>

        {message ? <Text style={styles.message}>{message}</Text> : null}
        {error ? <Text style={styles.error}>{error}</Text> : null}
        <View style={styles.confirmActions}>
          <Pressable disabled={busy} onPress={clearForm} style={styles.secondaryWide}>
            <Text style={styles.secondaryText}>Limpiar</Text>
          </Pressable>
          <Pressable disabled={busy} onPress={() => void submit()} style={styles.confirmButton}>
            {busy
              ? <ActivityIndicator color={colors.accentText} />
              : <Text style={styles.primaryText}>Confirmar repaletizaje</Text>}
          </Pressable>
        </View>

        <Text style={styles.sectionTitle}>Repas recientes</Text>
        {loadingHistory ? <ActivityIndicator color={colors.cyan} /> : null}
        {!loadingHistory && !history.length ? (
          <Text style={styles.empty}>Todavía no existen repaletizajes.</Text>
        ) : null}
        {history.map((repa) => (
          <View key={repa.id} style={styles.historyCard}>
            <Text style={styles.sourceNumber}>
              {repa.codigo} · {repa.folio_resultante.numero_folio}
            </Text>
            {repa.turno ? (
              <Text style={styles.sourceMeta}>Turno {repa.turno}{repa.estado === 'anulado' ? ' · ANULADA' : ''}</Text>
            ) : null}
            <Text style={styles.sourceMeta}>
              {repa.tipo_resultado === 'pallet' ? 'Pallet completo' : (repa.tipo_resultado === 'division' ? 'División' : 'Saldo')} · {repa.cantidad_resultante} cajas · {repa.condicion_termica}
            </Text>
            {repa.campos_mix.length ? (
              <Text style={styles.historyWarning}>MIX: {repa.campos_mix.join(' · ')}</Text>
            ) : null}
          </View>
        ))}
      </ScrollView>
    </View>
  );
}

function distinctCount(sources: Source[], field: HardField | MixField): number {
  return new Set(sources.map((source) => normalize(source[field]))).size;
}

function normalize(value: unknown): string {
  return String(value ?? '').trim().toUpperCase();
}

function numeric(value: string): number {
  const parsed = Number(value);
  return Number.isFinite(parsed) ? parsed : 0;
}

function sourceTotal(source: Source): number {
  return source.composicion.reduce((sum, line) => sum + numeric(line.aporte), 0);
}

function allocateComposition(
  composition: RepalletizingFolio['composicion'],
  requested: number,
): Source['composicion'] {
  let remaining = requested;
  return composition.map((line) => {
    const amount = Math.min(line.cantidad_cajas, remaining);
    remaining -= amount;
    return { ...line, aporte: String(amount) };
  });
}

function aggregateComposition(sources: Source[]): Array<{
  csg: string;
  predio: string | null;
  fecha_embalaje: string | null;
  cantidad_cajas: number;
}> {
  const grouped = new Map<string, {
    csg: string;
    predio: string | null;
    fecha_embalaje: string | null;
    cantidad_cajas: number;
  }>();
  sources.flatMap((source) => source.composicion).forEach((line) => {
    const amount = numeric(line.aporte);
    if (!amount) return;
    const key = `${line.csg}|${line.predio ?? ''}|${line.fecha_embalaje ?? ''}`;
    const current = grouped.get(key) ?? {
      csg: line.csg,
      predio: line.predio,
      fecha_embalaje: line.fecha_embalaje,
      cantidad_cajas: 0,
    };
    current.cantidad_cajas += amount;
    grouped.set(key, current);
  });
  return [...grouped.values()];
}

function messageOf(reason: unknown): string {
  return reason instanceof Error
    ? reason.message
    : 'No fue posible completar la operación.';
}

function Toggle({ active, label, onPress }: {
  active: boolean;
  label: string;
  onPress: () => void;
}) {
  return (
    <Pressable onPress={onPress} style={[styles.toggle, active && styles.toggleActive]}>
      <Text style={[styles.toggleText, active && styles.toggleTextActive]}>{label}</Text>
    </Pressable>
  );
}

function Field({ label, children }: { label: string; children: ReactNode }) {
  return (
    <View style={styles.field}>
      <Text style={styles.fieldLabel}>{label}</Text>
      {children}
    </View>
  );
}

const styles = StyleSheet.create({
  screen: { flex: 1, backgroundColor: colors.background },
  header: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    gap: 12,
    padding: 18,
    borderBottomWidth: 1,
    borderBottomColor: colors.border,
  },
  headerTight: { paddingHorizontal: 12, paddingVertical: 10 },
  titleTight: { fontSize: 20, marginTop: 0 },
  contentTight: { padding: 12, gap: 10 },
  shiftRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 10 },
  shiftOptions: { flexDirection: 'row', gap: 8, width: 150 },
  shiftHint: { color: colors.amber, fontSize: 13, fontFamily: font.heavy },
  sourceLocation: { color: colors.cyan, fontSize: 13, fontFamily: font.heavy, marginTop: 3 },
  headerCopy: { flex: 1 },
  headerActions: { flexDirection: 'row', gap: 8 },
  eyebrow: { color: colors.cyan, fontSize: 14, fontFamily: font.heavy, letterSpacing: 1.2 },
  title: { color: colors.text, fontSize: 26, fontFamily: font.heavy, marginTop: 4 },
  subtitle: { fontFamily: font.regular, color: colors.muted, fontSize: 14, marginTop: 4 },
  content: { padding: 16, gap: 12 },
  options: { flexDirection: 'row', gap: 8 },
  toggle: {
    flex: 1,
    minHeight: 48,
    alignItems: 'center',
    justifyContent: 'center',
    borderRadius: 10,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.backgroundDeep,
  },
  toggleActive: { borderColor: colors.cyan, backgroundColor: colors.cyanDark },
  toggleText: { color: colors.muted, fontFamily: font.heavy },
  toggleTextActive: { color: colors.text },
  field: { gap: 5 },
  fieldLabel: { color: colors.muted, fontSize: 14, fontFamily: font.heavy },
  input: {
    minHeight: 52,
    borderRadius: 10,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.panel,
    color: colors.text,
    paddingHorizontal: 12,
  },
  primaryText: { color: colors.accentText, fontFamily: font.heavy },
  secondary: {
    minHeight: 44,
    alignItems: 'center',
    justifyContent: 'center',
    borderRadius: 9,
    borderWidth: 1,
    borderColor: colors.border,
    paddingHorizontal: 12,
  },
  secondaryText: { color: colors.cyan, fontFamily: font.heavy },
  blocked: {
    color: colors.red,
    fontFamily: font.heavy,
    borderWidth: 1,
    borderColor: colors.red,
    borderRadius: 9,
    padding: 10,
  },
  warningRow: { flexDirection: 'row', flexWrap: 'wrap', gap: 6 },
  warning: {
    color: colors.amber,
    borderWidth: 1,
    borderColor: colors.amberDark,
    borderRadius: 999,
    paddingHorizontal: 9,
    paddingVertical: 6,
    fontSize: 13,
    fontFamily: font.heavy,
  },
  sourceCard: {
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: 12,
    backgroundColor: colors.panel,
    padding: 10,
    gap: 8,
  },
  sourceCopy: { flex: 1 },
  sourceNumber: { color: colors.text, fontFamily: font.heavy },
  sourceMeta: { fontFamily: font.regular, color: colors.muted, fontSize: 14, marginTop: 2 },
  compositionLine: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 10,
    padding: 9,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: 9,
    backgroundColor: colors.backgroundDeep,
  },
  keepButton: {
    alignSelf: 'flex-start',
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: 8,
    padding: 7,
  },
  keepButtonActive: { borderColor: colors.cyan, backgroundColor: colors.cyanDark },
  keepText: { color: colors.cyan, fontSize: 13, fontFamily: font.heavy },
  contributionInput: {
    width: 100,
    minHeight: 52,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: 8,
    backgroundColor: colors.backgroundDeep,
    color: colors.text,
    textAlign: 'center',
  },
  remaining: { fontFamily: font.regular, color: colors.muted, fontSize: 14 },
  remove: { color: colors.red, fontFamily: font.heavy, fontSize: 14 },
  preview: {
    borderWidth: 1,
    borderColor: colors.cyanDark,
    borderRadius: 12,
    backgroundColor: colors.panel,
    padding: 14,
  },
  previewLabel: { color: colors.muted, fontSize: 13, fontFamily: font.heavy },
  previewNumber: { color: colors.cyan, fontSize: 22, fontFamily: font.heavy, marginTop: 4 },
  previewMeta: { color: colors.muted, marginTop: 3 },
  previewCompositionLine: { flexDirection: 'row', justifyContent: 'space-between', gap: 10, paddingTop: 8, marginTop: 8, borderTopWidth: 1, borderTopColor: colors.border },
  previewCompositionText: { flex: 1, color: colors.text, fontSize: 14, fontFamily: font.heavy },
  previewCompositionQuantity: { color: colors.cyan, fontSize: 14, fontFamily: font.heavy },
  message: { color: colors.green, fontFamily: font.heavy },
  error: { color: colors.red, fontFamily: font.heavy },
  confirmActions: { flexDirection: 'row', gap: 8 },
  secondaryWide: {
    flex: 1,
    minHeight: 48,
    alignItems: 'center',
    justifyContent: 'center',
    borderRadius: 10,
    borderWidth: 1,
    borderColor: colors.border,
  },
  confirmButton: {
    flex: 2,
    minHeight: 56,
    alignItems: 'center',
    justifyContent: 'center',
    borderRadius: 10,
    backgroundColor: colors.cyan,
  },
  sectionTitle: { color: colors.text, fontSize: 18, fontFamily: font.heavy, marginTop: 12 },
  historyCard: {
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: 10,
    backgroundColor: colors.panel,
    padding: 11,
  },
  historyWarning: { color: colors.amber, fontSize: 14, fontFamily: font.heavy, marginTop: 5 },
  empty: { color: colors.muted, textAlign: 'center', padding: 20 },
});
