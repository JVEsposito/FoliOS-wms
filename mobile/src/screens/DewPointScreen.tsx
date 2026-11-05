import * as Crypto from 'expo-crypto';
import { useEffect, useMemo, useRef, useState } from 'react';
import {
  ActivityIndicator,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  TextInput,
  View,
} from 'react-native';

import {
  DEW_POINT_STATES,
  DewPointDay,
  DewPointDraft,
  DewPointDrops,
  DewPointPayload,
  DewPointPlace,
  DewPointReading,
  DewPointReference,
  DewPointState,
  EMPTY_DEW_POINT_DRAFT,
  attemptFor,
  draftToReading,
  formatCelsius,
  formatHumidityLimit,
  linkCandidates,
  marginForStage,
  planForStage,
  previewDraft,
} from '../domain/dewPoint';
import { AuthSession } from '../domain/estiba';
import { useOperationalPolling } from '../hooks/useOperationalPolling';
import { ApiError } from '../services/apiError';
import { getDewPointDay, registerDewPointReading } from '../services/prefrioApi';
import { colors } from '../theme/colors';

type DewPointScreenProps = {
  auth: AuthSession;
  baseUrl: string | null;
  onLogout: () => void;
};

const DROPS: { value: DewPointDrops; label: string }[] = [
  { value: 'no', label: 'No' },
  { value: 'si', label: 'Sí' },
  { value: 'pendiente', label: 'Pendiente' },
];

/**
 * Chequeo de condensación en terreno: T° y humedad del aire, T° superficial de la fruta
 * y gotas visibles. Las mediciones con riesgo avisan a supervisión de frío; no bloquean.
 */
export function DewPointScreen({ auth, baseUrl, onLogout }: DewPointScreenProps) {
  const attempt = useRef<{ key: string; payload: DewPointPayload } | null>(null);
  const saving = useRef(false);
  const [day, setDay] = useState<DewPointDay | null>(null);
  const [draft, setDraft] = useState<DewPointDraft>(EMPTY_DEW_POINT_DRAFT);
  const [busy, setBusy] = useState(true);
  const [online, setOnline] = useState(Boolean(baseUrl));
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const canRegister = day ? day.permisos.registrar : auth.usuario.capacidades.puede_operar_prefrio === true;
  const minimumMargin = day?.parametros.margen_minimo_c ?? 2;
  const stageMargin = marginForStage(day, draft.stage);
  const stagePlan = planForStage(day, draft.stage);
  const preview = useMemo(() => previewDraft(draft, stageMargin, day?.referencias ?? [], stagePlan), [draft, stageMargin, day, stagePlan]);
  // La tablet registra lo que se mide ahora: se ofrecen los vínculos más recientes.
  const candidates = useMemo(() => {
    const all = linkCandidates(day);
    return { sealed: all.sealed.slice(0, 6), origins: all.origins.slice(0, 6) };
  }, [day]);
  const readings = useMemo(() => [...(day?.mediciones ?? [])].reverse(), [day]);
  const rooms = useMemo(() => (day?.puntos.salas ?? []).filter((room) => room.activa !== false), [day]);
  const hasPoints = rooms.length + (day?.puntos.tuneles.length ?? 0) + (day?.puntos.camaras.length ?? 0) > 0;

  useEffect(() => {
    void refresh(false).finally(() => setBusy(false));
  }, []);

  useOperationalPolling(() => refresh(false), { intervalMs: 60000, enabled: Boolean(baseUrl) });

  function update(patch: Partial<DewPointDraft>) {
    setDraft((current) => ({ ...current, ...patch }));
    setError('');
  }

  async function refresh(showNotice = true) {
    if (!baseUrl) {
      setOnline(false);
      setError('La tablet está sin conexión. Los chequeos de punto de rocío requieren red.');
      return;
    }
    try {
      const next = await getDewPointDay(baseUrl, auth.token);
      setDay(next);
      setOnline(true);
      if (showNotice) setNotice('Chequeos del día actualizados.');
    } catch (reason) {
      if (reason instanceof ApiError && reason.status === 401) {
        onLogout();
        return;
      }
      if (reason instanceof ApiError && reason.status === 0) setOnline(false);
      setError(messageFrom(reason));
    }
  }

  async function save() {
    if (saving.current) return;
    setNotice('');
    const result = draftToReading(draft);
    if (!result.ok) {
      setError(result.error);
      return;
    }
    if (!baseUrl) {
      setOnline(false);
      setError('Sin conexión: la medición no se guardó. Reintenta cuando vuelva la red.');
      return;
    }
    attempt.current = attemptFor(attempt.current, result.reading, () => Crypto.randomUUID());
    saving.current = true;
    setBusy(true);
    setError('');
    try {
      const saved = await registerDewPointReading(baseUrl, auth.token, attempt.current.payload);
      attempt.current = null;
      setOnline(true);
      // El mismo punto y lote suelen medirse seguido; el sellado de referencia también se conserva.
      setDraft((current) => ({ ...EMPTY_DEW_POINT_DRAFT, stage: current.stage, point: current.point, reference: current.reference, sealed: current.sealed }));
      setNotice(saved.requiere_accion
        ? `Medición guardada: ${(saved.condicion ?? (saved.gotas_visibles === 'si' ? 'Gotas visibles' : saved.estado_etiqueta)).toLowerCase()}. Supervisión de frío fue avisada.`
        : `Medición guardada: rocío ${formatCelsius(saved.punto_rocio_c)}${saved.margen_c === null ? '' : `, margen ${formatCelsius(saved.margen_c)}`}.`);
      await refresh(false);
    } catch (reason) {
      if (reason instanceof ApiError && reason.status === 401) {
        onLogout();
        return;
      }
      if (reason instanceof ApiError && reason.status === 0) {
        setOnline(false);
        setError('Sin conexión: la medición no se guardó. Pulsa Guardar otra vez cuando vuelva la red; no se duplicará.');
      } else {
        setError(messageFrom(reason));
      }
    } finally {
      saving.current = false;
      setBusy(false);
    }
  }

  const summary = day?.resumen;

  return (
    <View style={styles.screen}>
      <ScrollView contentContainerStyle={styles.page} keyboardShouldPersistTaps="handled">
        <View style={styles.header}>
          <View style={styles.headerText}>
            <Text style={styles.eyebrow}>FoliOS · PREFRÍO · CONDENSACIÓN</Text>
            <Text style={styles.title}>Punto de rocío</Text>
            <Text style={styles.subtitle}>Mide T° y humedad del aire y la T° superficial de la fruta (no la pulpa). Si la superficie queda bajo el punto de rocío, puede condensar agua.</Text>
          </View>
          <View style={styles.headerActions}>
            <View style={[styles.connection, online ? styles.online : styles.offline]}>
              <Text style={styles.connectionText}>{online ? 'EN LÍNEA' : 'SIN CONEXIÓN'}</Text>
            </View>
            <Pressable disabled={busy} onPress={() => void refresh()} style={[styles.secondaryButton, busy && styles.disabled]}>
              <Text style={styles.secondaryButtonText}>↻ Actualizar</Text>
            </Pressable>
          </View>
        </View>

        {notice ? <Text style={styles.notice}>{notice}</Text> : null}
        {error ? <Text style={styles.error}>{error}</Text> : null}

        <View style={styles.metrics}>
          <Metric label="REGISTROS HOY" value={String(summary?.registros ?? 0)} />
          <Metric label="ROCÍO MÁXIMO" value={formatCelsius(summary?.punto_rocio_maximo_c)} />
          <Metric label="MARGEN MÍNIMO" value={formatCelsius(summary?.margen_minimo_c)} warning={summary?.margen_minimo_c !== null && summary?.margen_minimo_c !== undefined && summary.margen_minimo_c < minimumMargin} />
          <Metric label="ALERTAS SIN ACCIÓN" value={String(summary?.alertas_pendientes ?? 0)} warning={(summary?.alertas_pendientes ?? 0) > 0} />
        </View>

        <View style={styles.columns}>
          {canRegister ? (
            <View style={[styles.panel, styles.formPanel]}>
              <Text style={styles.panelTitle}>Nueva medición</Text>

              <Text style={styles.inputLabel}>Etapa *</Text>
              <View style={styles.chips}>
                {(day?.etapas ?? []).map((stage) => (
                  <Chip key={stage.valor} label={stage.etiqueta} selected={draft.stage === stage.valor} onPress={() => update({ stage: stage.valor })} />
                ))}
              </View>

              {stagePlan ? (
                <Text style={styles.planLine}>
                  Plan de {stagePlan.etiqueta.toLowerCase()}: rocío ≤ {formatCelsius(stagePlan.rocio_maximo_c)}
                  {stagePlan.limitante === 'envase' ? ' (por el aire que queda en la bolsa)' : ''}
                  {stagePlan.temperatura_aire_c !== null && stagePlan.humedad_maxima !== null
                    ? ` · con aire a ${formatCelsius(stagePlan.temperatura_aire_c)}, ${stagePlan.humedad_maxima >= 100 ? 'sin límite de HR' : `HR ≤ ${formatHumidityLimit(stagePlan.humedad_maxima)}`}`
                    : ''}
                  {stagePlan.cerca_congelacion ? ' · cerca de la congelación de la fruta' : ''}
                </Text>
              ) : null}

              <Text style={styles.inputLabel}>Punto de control *</Text>
              {hasPoints ? (
                <View style={styles.pointGroups}>
                  <PointGroup label="Salas" type="sala" places={rooms} selected={draft.point} onSelect={(point) => update({ point })} />
                  <PointGroup label="Túneles de prefrío" type="tunel" places={day?.puntos.tuneles ?? []} selected={draft.point} onSelect={(point) => update({ point })} />
                  <PointGroup label="Cámaras" type="camara" places={day?.puntos.camaras ?? []} selected={draft.point} onSelect={(point) => update({ point })} />
                </View>
              ) : (
                <Text style={styles.warning}>No hay puntos de control activos. Supervisión puede agregar salas en Oficina → Prefrío → Punto de rocío.</Text>
              )}

              <View style={styles.inputs}>
                <NumberField label="T° ambiente *" unit="°C" value={draft.ambient} onChange={(ambient) => update({ ambient })} signed />
                <NumberField label="Humedad relativa *" unit="%" value={draft.humidity} onChange={(humidity) => update({ humidity })} />
                <NumberField label="T° superficial fruta" unit="°C" value={draft.surface} onChange={(surface) => update({ surface })} signed />
              </View>

              <Text style={styles.inputLabel}>¿Gotas visibles? *</Text>
              <View style={styles.chips}>
                {DROPS.map((option) => (
                  <Chip key={option.value} label={option.label} selected={draft.drops === option.value} danger={option.value === 'si'} onPress={() => update({ drops: option.value })} />
                ))}
              </View>

              <View style={styles.links}>
                <Text style={styles.linksTitle}>VINCULAR CON MEDICIONES ANTERIORES (OPCIONAL)</Text>
                {draft.stage !== 'sellado' ? (
                  <LinkGroup
                    label="Aire del envase · sellado de referencia"
                    help="Compara la T° superficial de esta medición con el rocío del aire que quedó en la bolsa."
                    items={candidates.sealed}
                    kind="sealed"
                    selected={draft.sealed}
                    onSelect={(sealed) => update({ sealed })}
                  />
                ) : null}
                <LinkGroup
                  label="Fruta que llega · medición de origen"
                  help="Compara la T° superficial con que llega la fruta con el rocío del aire de este punto."
                  items={candidates.origins}
                  kind="origin"
                  selected={draft.origin}
                  onSelect={(origin) => update({ origin })}
                />
              </View>

              <View style={styles.inputs}>
                <View style={styles.field}>
                  <Text style={styles.inputLabel}>Lote, pallet o referencia</Text>
                  <TextInput accessibilityLabel="Lote, pallet o referencia" maxLength={80} onChangeText={(reference) => update({ reference })} placeholder="Opcional" placeholderTextColor={colors.muted} style={styles.input} value={draft.reference} />
                </View>
                <View style={[styles.field, styles.fieldWide]}>
                  <Text style={styles.inputLabel}>Observación</Text>
                  <TextInput accessibilityLabel="Observación" maxLength={1000} onChangeText={(note) => update({ note })} placeholder="Opcional" placeholderTextColor={colors.muted} style={styles.input} value={draft.note} />
                </View>
              </View>

              <ResultPanel preview={preview} sealing={draft.stage === 'sellado'} stageMargin={stageMargin} />

              <Pressable disabled={busy} onPress={() => void save()} style={[styles.primaryButton, busy && styles.disabled]}>
                <Text style={styles.primaryButtonText}>{busy ? 'Guardando…' : 'Guardar medición'}</Text>
              </Pressable>
            </View>
          ) : (
            <Text style={[styles.readOnly, styles.formPanel]}>Tu perfil puede consultar los chequeos, pero no registrarlos.</Text>
          )}

          <View style={[styles.panel, styles.listPanel]}>
            <Text style={styles.panelTitle}>Mediciones de hoy</Text>
            {readings.length ? readings.map((reading) => <ReadingCard key={reading.id} reading={reading} />) : (
              <Text style={styles.subtitle}>Aún no hay mediciones hoy.</Text>
            )}
            <Text style={styles.footnote}>El margen mínimo de cada etapa (por defecto {formatCelsius(minimumMargin)}) es un criterio preventivo interno, no una norma. Dentro de bolsas selladas puede condensar aunque la sala cumpla.</Text>
          </View>
        </View>
      </ScrollView>

      {busy && !day ? (
        <View style={styles.busyOverlay}>
          <ActivityIndicator color={colors.cyan} size="large" />
          <Text style={styles.busyText}>Cargando chequeos…</Text>
        </View>
      ) : null}
    </View>
  );
}

function Chip({ label, selected, danger = false, onPress }: { label: string; selected: boolean; danger?: boolean; onPress: () => void }) {
  return (
    <Pressable accessibilityRole="button" accessibilityState={{ selected }} onPress={onPress} style={[styles.chip, selected && (danger ? styles.chipDanger : styles.chipSelected)]}>
      <Text style={[styles.chipText, selected && styles.chipTextSelected]}>{label}</Text>
    </Pressable>
  );
}

function PointGroup({ label, type, places, selected, onSelect }: { label: string; type: string; places: DewPointPlace[]; selected: string | null; onSelect: (point: string) => void }) {
  if (!places.length) return null;
  return (
    <View style={styles.pointGroup}>
      <Text style={styles.groupLabel}>{label}</Text>
      <View style={styles.chips}>
        {places.map((place) => (
          <Chip key={place.id} label={place.nombre} selected={selected === `${type}:${place.id}`} onPress={() => onSelect(`${type}:${place.id}`)} />
        ))}
      </View>
    </View>
  );
}

function NumberField({ label, unit, value, onChange, signed = false }: { label: string; unit: string; value: string; onChange: (value: string) => void; signed?: boolean }) {
  return (
    <View style={styles.field}>
      <Text style={styles.inputLabel}>{label}</Text>
      <View style={styles.numberBox}>
        {/* Las temperaturas de cámara pueden ser negativas: el teclado numérico permite el signo. */}
        <TextInput accessibilityLabel={label} keyboardType={signed ? 'numeric' : 'decimal-pad'} maxLength={6} onChangeText={onChange} placeholder="—" placeholderTextColor={colors.muted} style={styles.numberInput} value={value} />
        <Text style={styles.unit}>{unit}</Text>
      </View>
    </View>
  );
}

function LinkGroup({ label, help, items, kind, selected, onSelect }: {
  label: string;
  help: string;
  items: DewPointReference[];
  kind: 'sealed' | 'origin';
  selected: string | null;
  onSelect: (id: string | null) => void;
}) {
  return (
    <View style={styles.pointGroup}>
      <Text style={styles.groupLabel}>{label}</Text>
      <Text style={styles.linkHelp}>{help}</Text>
      {items.length ? (
        <View style={styles.chips}>
          <Chip label="Sin vincular" selected={selected === null} onPress={() => onSelect(null)} />
          {items.map((item) => (
            <Chip
              key={item.id}
              label={kind === 'sealed'
                ? `${formatTime(item.medido_at)} · ${item.punto} · rocío ${formatCelsius(item.punto_rocio_c)}`
                : `${formatTime(item.medido_at)} · ${item.etapa_etiqueta} · fruta ${formatCelsius(item.temperatura_superficie_c)}`}
              selected={selected === item.id}
              onPress={() => onSelect(selected === item.id ? null : item.id)}
            />
          ))}
        </View>
      ) : (
        <Text style={styles.linkHelp}>{kind === 'sealed' ? 'No hay sellados en las últimas horas.' : 'No hay mediciones recientes con T° superficial.'}</Text>
      )}
    </View>
  );
}

function ResultPanel({ preview, sealing, stageMargin }: { preview: ReturnType<typeof previewDraft>; sealing: boolean; stageMargin: number }) {
  if (!preview) {
    return <Text style={[styles.result, styles.resultNeutral, styles.resultHint]}>Ingresa T° y humedad para calcular el punto de rocío.</Text>;
  }
  const state = DEW_POINT_STATES[preview.state];
  return (
    <View style={[styles.result, toneStyle(preview.worst)]}>
      <ResultValue label="PUNTO DE ROCÍO" value={formatCelsius(preview.dewPoint)} />
      <ResultValue label="MARGEN VS FRUTA" value={formatCelsius(preview.margin)} />
      <ResultValue label="VAPOR EN EL AIRE" value={`${new Intl.NumberFormat('es-CL', { minimumFractionDigits: 1, maximumFractionDigits: 1 }).format(preview.vapor)} g/m³`} />
      <View style={styles.resultState}>
        <Text style={styles.resultLabel}>{sealing ? 'RIESGO AL SELLAR' : 'RIESGO EN ESTE PUNTO'}</Text>
        <Text style={styles.resultStateText}>{state.label}</Text>
        <Text style={styles.resultHelp}>{state.help} Margen mínimo de la etapa: {formatCelsius(stageMargin)}.</Text>
      </View>
      {preview.envelope ? (
        <LinkResult
          title="Dentro del envase"
          detail={`Rocío del sellado ${formatCelsius(preview.envelope.reference.punto_rocio_c)} · margen ${formatCelsius(preview.envelope.margin)}`}
          state={preview.envelope.state}
        />
      ) : null}
      {preview.arrival ? (
        <LinkResult
          title="Al llegar"
          detail={`Fruta a ${formatCelsius(preview.arrival.reference.temperatura_superficie_c)} desde ${preview.arrival.reference.etapa_etiqueta} · margen ${formatCelsius(preview.arrival.margin)}`}
          state={preview.arrival.state}
        />
      ) : null}
      {preview.plan ? (
        <View style={[styles.linkResult, preview.plan.out ? styles.resultWarning : styles.resultSuccess]}>
          <Text style={styles.linkResultTitle}>Plan de la etapa</Text>
          <Text style={styles.resultHelp}>Rocío ≤ {formatCelsius(preview.plan.limit)} · a esta T° la HR debe ser ≤ {formatHumidityLimit(preview.plan.humidityLimit)}</Text>
          <Text style={styles.linkResultState}>{preview.plan.out ? 'Fuera del plan' : 'Dentro del plan'}</Text>
        </View>
      ) : null}
    </View>
  );
}

function LinkResult({ title, detail, state }: { title: string; detail: string; state: DewPointState }) {
  return (
    <View style={[styles.linkResult, toneStyle(state)]}>
      <Text style={styles.linkResultTitle}>{title}</Text>
      <Text style={styles.resultHelp}>{detail}</Text>
      <Text style={styles.linkResultState}>{DEW_POINT_STATES[state].label}</Text>
    </View>
  );
}

function ResultValue({ label, value }: { label: string; value: string }) {
  return <View style={styles.resultValue}><Text style={styles.resultLabel}>{label}</Text><Text style={styles.resultNumber}>{value}</Text></View>;
}

function ReadingCard({ reading }: { reading: DewPointReading }) {
  const state = DEW_POINT_STATES[reading.estado];
  return (
    <View style={[styles.reading, reading.alerta_pendiente && styles.readingAlert, reading.anulada && styles.readingVoided]}>
      <View style={styles.readingHeader}>
        <Text style={styles.readingTime}>{formatTime(reading.medido_at)}</Text>
        <Text style={[styles.badge, toneStyle(reading.estado)]}>{reading.anulada ? 'Anulada' : state.label}</Text>
      </View>
      <Text style={styles.readingTitle}>{reading.etapa_etiqueta} · {reading.punto}</Text>
      <Text style={styles.readingDetail}>
        Aire {formatCelsius(reading.temperatura_ambiente_c)} · HR {reading.humedad_relativa.toLocaleString('es-CL')} % · Sup. {formatCelsius(reading.temperatura_superficie_c)}
      </Text>
      <Text style={styles.readingDetail}>Rocío {formatCelsius(reading.punto_rocio_c)} · Margen {formatCelsius(reading.margen_c)}{reading.gotas_visibles === 'si' ? ' · Gotas visibles' : ''}</Text>
      {reading.envase ? (
        <Text style={styles.readingDetail}>
          Envase: {reading.envase.estado ? DEW_POINT_STATES[reading.envase.estado].label : '—'} · margen {formatCelsius(reading.envase.margen_c)} · sellado {reading.envase.medido_at ? formatTime(reading.envase.medido_at) : '—'}{reading.envase.referencia_anulada ? ' (anulado)' : ''}
        </Text>
      ) : null}
      {reading.origen ? (
        <Text style={styles.readingDetail}>
          Al llegar: {reading.origen.estado ? DEW_POINT_STATES[reading.origen.estado].label : '—'} · margen {formatCelsius(reading.origen.margen_c)} · desde {reading.origen.etapa_etiqueta ?? '—'}{reading.origen.referencia_anulada ? ' (anulada)' : ''}
        </Text>
      ) : null}
      {reading.plan?.fuera ? <Text style={styles.readingPlan}>Fuera de plan: rocío ≤ {formatCelsius(reading.plan.rocio_maximo_c)} (HR ≤ {formatHumidityLimit(reading.plan.humedad_maxima)} a esa T°)</Text> : null}
      {reading.alerta_pendiente ? <Text style={styles.readingAlertText}>{reading.condicion ? `${reading.condicion}: supervisión de frío debe registrar una acción correctiva.` : 'Supervisión de frío debe registrar una acción correctiva.'}</Text> : null}
      {reading.accion_correctiva ? <Text style={styles.readingAction}>Acción: {reading.accion_correctiva}</Text> : null}
    </View>
  );
}

function Metric({ label, value, warning = false }: { label: string; value: string; warning?: boolean }) {
  return <View style={[styles.metric, warning && styles.metricWarning]}><Text style={styles.metricValue}>{value}</Text><Text style={styles.metricLabel}>{label}</Text></View>;
}

function toneStyle(state: DewPointState) {
  const tone = DEW_POINT_STATES[state].tone;
  if (tone === 'danger') return styles.resultDanger;
  if (tone === 'warning') return styles.resultWarning;
  if (tone === 'success') return styles.resultSuccess;
  return styles.resultNeutral;
}

function formatTime(value: string) {
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return '—';
  return new Intl.DateTimeFormat('es-CL', { hour: '2-digit', minute: '2-digit', hour12: false }).format(date);
}

function messageFrom(reason: unknown) {
  return reason instanceof Error ? reason.message : 'Ocurrió un error inesperado en Punto de rocío.';
}

const styles = StyleSheet.create({
  screen: { flex: 1, backgroundColor: colors.background },
  page: { padding: 18, gap: 16, paddingBottom: 44 },
  header: { flexDirection: 'row', flexWrap: 'wrap', justifyContent: 'space-between', alignItems: 'center', gap: 18, padding: 18, borderRadius: 16, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.panel },
  headerText: { flexShrink: 1, flexBasis: 420, flexGrow: 1 },
  headerActions: { flexDirection: 'row', alignItems: 'center', gap: 9, flexWrap: 'wrap', justifyContent: 'flex-end' },
  eyebrow: { color: colors.cyan, fontSize: 14, fontWeight: '900', letterSpacing: 1.2 },
  title: { color: colors.text, fontSize: 25, fontWeight: '900', marginTop: 4 },
  subtitle: { color: colors.muted, marginTop: 6, lineHeight: 19 },
  connection: { paddingHorizontal: 11, paddingVertical: 8, borderRadius: 999, borderWidth: 1 },
  online: { borderColor: colors.green, backgroundColor: colors.greenDark },
  offline: { borderColor: colors.red, backgroundColor: colors.blocked },
  connectionText: { color: colors.text, fontSize: 14, fontWeight: '900' },
  secondaryButton: { paddingHorizontal: 13, paddingVertical: 10, borderRadius: 10, borderWidth: 1, borderColor: colors.cyanDark, alignItems: 'center' },
  secondaryButtonText: { color: colors.cyan, fontSize: 14, fontWeight: '900' },
  primaryButton: { marginTop: 6, paddingHorizontal: 18, paddingVertical: 15, borderRadius: 10, borderWidth: 1, borderColor: colors.green, backgroundColor: colors.greenDark, alignItems: 'center' },
  primaryButtonText: { color: colors.text, fontSize: 15, fontWeight: '900', textTransform: 'uppercase' },
  disabled: { opacity: .4 },
  notice: { color: colors.text, padding: 12, borderRadius: 10, borderWidth: 1, borderColor: colors.green, backgroundColor: colors.greenDark, fontWeight: '800' },
  error: { color: colors.text, padding: 12, borderRadius: 10, borderWidth: 1, borderColor: colors.red, backgroundColor: colors.blocked, fontWeight: '800' },
  warning: { color: colors.text, padding: 12, borderRadius: 10, borderWidth: 1, borderColor: colors.amber, backgroundColor: colors.amberDark, fontWeight: '800', lineHeight: 19 },
  metrics: { flexDirection: 'row', gap: 10, flexWrap: 'wrap' },
  metric: { minWidth: 160, flex: 1, padding: 15, borderRadius: 13, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.panel },
  metricWarning: { borderColor: colors.amber },
  metricValue: { color: colors.text, fontSize: 24, fontWeight: '900' },
  metricLabel: { color: colors.muted, fontSize: 14, fontWeight: '900', marginTop: 4 },
  columns: { flexDirection: 'row', flexWrap: 'wrap', gap: 16, alignItems: 'flex-start' },
  panel: { gap: 10, padding: 16, borderRadius: 14, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.panel },
  formPanel: { flexGrow: 3, flexBasis: 520 },
  listPanel: { flexGrow: 2, flexBasis: 340 },
  panelTitle: { color: colors.text, fontSize: 18, fontWeight: '900', marginBottom: 2 },
  inputLabel: { color: colors.muted, fontSize: 13, fontWeight: '900', textTransform: 'uppercase', marginTop: 4 },
  chips: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  chip: { minHeight: 46, justifyContent: 'center', paddingHorizontal: 14, borderRadius: 10, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.backgroundDeep },
  chipSelected: { borderColor: colors.cyan, backgroundColor: colors.selected },
  chipDanger: { borderColor: colors.red, backgroundColor: colors.blocked },
  chipText: { color: colors.textSecondary, fontSize: 15, fontWeight: '800' },
  chipTextSelected: { color: colors.text, fontWeight: '900' },
  pointGroups: { gap: 10 },
  planLine: { color: colors.text, padding: 10, borderRadius: 10, borderWidth: 1, borderColor: colors.cyanDark, backgroundColor: colors.selected, fontSize: 14, fontWeight: '800', lineHeight: 19 },
  readingPlan: { color: colors.amber, fontSize: 14, fontWeight: '900' },
  links: { gap: 10, padding: 12, borderRadius: 12, borderWidth: 1, borderStyle: 'dashed', borderColor: colors.border },
  linksTitle: { color: colors.muted, fontSize: 12, fontWeight: '900', letterSpacing: .6 },
  linkHelp: { color: colors.muted, fontSize: 13, lineHeight: 18 },
  linkResult: { flexBasis: '100%', gap: 2, paddingVertical: 8, paddingHorizontal: 10, borderRadius: 9, borderWidth: 1, borderLeftWidth: 4 },
  linkResultTitle: { color: colors.text, fontSize: 14, fontWeight: '900' },
  linkResultState: { color: colors.text, fontSize: 15, fontWeight: '900' },
  pointGroup: { gap: 6 },
  groupLabel: { color: colors.cyan, fontSize: 13, fontWeight: '900' },
  inputs: { flexDirection: 'row', flexWrap: 'wrap', gap: 10 },
  field: { flexGrow: 1, flexBasis: 160, gap: 5 },
  fieldWide: { flexBasis: 300 },
  input: { minHeight: 50, paddingHorizontal: 13, borderRadius: 10, borderWidth: 1, borderColor: colors.border, color: colors.text, backgroundColor: colors.backgroundDeep, fontWeight: '700' },
  numberBox: { flexDirection: 'row', alignItems: 'center', minHeight: 56, borderRadius: 10, borderWidth: 1, borderColor: colors.cyanDark, backgroundColor: colors.backgroundDeep },
  numberInput: { flex: 1, paddingHorizontal: 13, color: colors.text, fontSize: 22, fontWeight: '900' },
  unit: { paddingHorizontal: 12, color: colors.muted, fontSize: 16, fontWeight: '900' },
  result: { flexDirection: 'row', flexWrap: 'wrap', gap: 12, padding: 14, borderRadius: 12, borderWidth: 1, borderLeftWidth: 5 },
  resultHint: { color: colors.muted, fontWeight: '800' },
  resultNeutral: { borderColor: colors.border, backgroundColor: colors.backgroundDeep },
  resultDanger: { borderColor: colors.red, backgroundColor: colors.redDark },
  resultWarning: { borderColor: colors.amber, backgroundColor: colors.amberDark },
  resultSuccess: { borderColor: colors.green, backgroundColor: colors.greenDark },
  resultValue: { flexBasis: 130, flexGrow: 1, gap: 3 },
  resultLabel: { color: colors.muted, fontSize: 12, fontWeight: '900', letterSpacing: .6 },
  resultNumber: { color: colors.text, fontSize: 22, fontWeight: '900' },
  resultState: { flexBasis: 260, flexGrow: 2, gap: 3 },
  resultStateText: { color: colors.text, fontSize: 20, fontWeight: '900' },
  resultHelp: { color: colors.textSecondary, fontSize: 13, lineHeight: 18 },
  reading: { gap: 4, padding: 12, borderRadius: 11, borderWidth: 1, borderColor: colors.borderSoft, backgroundColor: colors.backgroundDeep },
  readingAlert: { borderColor: colors.red, borderLeftWidth: 5 },
  readingVoided: { opacity: .55 },
  readingHeader: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', gap: 8 },
  readingTime: { color: colors.text, fontSize: 17, fontWeight: '900' },
  readingTitle: { color: colors.cyan, fontWeight: '900' },
  readingDetail: { color: colors.text, fontSize: 14 },
  readingAlertText: { color: colors.red, fontSize: 14, fontWeight: '900' },
  readingAction: { color: colors.green, fontSize: 14, fontWeight: '800' },
  badge: { overflow: 'hidden', paddingHorizontal: 9, paddingVertical: 4, borderRadius: 999, borderWidth: 1, color: colors.text, fontSize: 13, fontWeight: '900' },
  readOnly: { color: colors.text, padding: 13, borderRadius: 10, borderWidth: 1, borderColor: colors.cyanDark, backgroundColor: colors.selected },
  footnote: { color: colors.muted, fontSize: 13, lineHeight: 18, marginTop: 6 },
  busyOverlay: { ...StyleSheet.absoluteFillObject, alignItems: 'center', justifyContent: 'center', gap: 10, backgroundColor: 'rgba(8,12,16,.74)' },
  busyText: { color: colors.text, fontWeight: '900' },
});
