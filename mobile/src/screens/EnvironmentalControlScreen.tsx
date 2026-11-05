import * as Crypto from 'expo-crypto';
import { useCallback, useEffect, useMemo, useState } from 'react';
import {
  Pressable,
  RefreshControl,
  ScrollView,
  StyleSheet,
  Text,
  TextInput,
  View,
  useWindowDimensions,
} from 'react-native';

import {
  EnvironmentalCameraState,
  EnvironmentalControlDraft,
  EnvironmentalControlPayload,
} from '../domain/environmentalControl';
import { AuthSession } from '../domain/estiba';
import { ApiError } from '../services/apiError';
import {
  getEnvironmentalControlState,
  registerEnvironmentalControl,
} from '../services/environmentalControlApi';
import {
  clearEnvironmentalDraft,
  loadEnvironmentalDraft,
  saveEnvironmentalDraft,
} from '../services/environmentalControlDraftStore';
import { operatorTheme as o } from '../theme/operatorTheme';
import { font } from '../theme/typography';

type Props = { auth: AuthSession; baseUrl: string; onDueChange?: (due: number) => void; onBusyChange?: (busy: boolean) => void };
type FieldErrors = Partial<Record<'start' | 'middle' | 'end', string>>;
type Tone = 'success' | 'warning' | 'critical' | 'info';

const labels = { start: 'Inicio', middle: 'Medio', end: 'Fondo' } as const;

function emptyDraft(): EnvironmentalControlDraft {
  return {
    cameraId: null,
    cameraCode: null,
    start: '',
    middle: '',
    end: '',
    operationId: Crypto.randomUUID(),
    capturedAt: null,
  };
}

function parseTemperature(value: string) {
  const normalized = value.trim().replace(',', '.');
  if (!/^-?\d+(?:\.\d{1,2})?$/.test(normalized)) return null;
  const number = Number(normalized);
  return Number.isFinite(number) && number >= -999.99 && number <= 999.99 ? number : null;
}

function time(value?: string | null) {
  if (!value) return 'Sin registro';
  const date = new Date(value);
  return Number.isNaN(date.getTime())
    ? 'Hora no disponible'
    : date.toLocaleTimeString('es-CL', { hour: '2-digit', minute: '2-digit' });
}

function statusPresentation(camera: EnvironmentalCameraState): { label: string; tone: Tone; detail: string } {
  if (camera.estado === 'vigente') {
    return { label: 'Vigente', tone: 'success', detail: `Hasta ${time(camera.ultimo_registro?.vigente_hasta)}` };
  }
  if (camera.estado === 'vencido') {
    return { label: 'Vencido', tone: 'critical', detail: `Venció ${time(camera.vencido_desde)}` };
  }
  return { label: 'Pendiente', tone: 'warning', detail: 'Sin control registrado' };
}

function errorMessage(reason: unknown) {
  return reason instanceof Error ? reason.message : 'No fue posible completar la operación.';
}

export function EnvironmentalControlScreen({ auth, baseUrl, onDueChange, onBusyChange }: Props) {
  const { width } = useWindowDimensions();
  const compact = width < o.breakpoint.compact;
  const [status, setStatus] = useState<EnvironmentalCameraState[]>([]);
  const [frequency, setFrequency] = useState(60);
  const [generatedAt, setGeneratedAt] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [statusError, setStatusError] = useState('');
  const [draft, setDraft] = useState<EnvironmentalControlDraft>(emptyDraft);
  const [draftReady, setDraftReady] = useState(false);
  const [confirming, setConfirming] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [submitError, setSubmitError] = useState('');
  const [success, setSuccess] = useState('');
  const [fieldErrors, setFieldErrors] = useState<FieldErrors>({});
  const canRegister = auth.usuario.capacidades.puede_registrar_control_ambiental === true;
  useEffect(() => {
    onBusyChange?.(submitting);
    return () => onBusyChange?.(false);
  }, [submitting, onBusyChange]);

  const loadStatus = useCallback(async (manual = false) => {
    manual ? setRefreshing(true) : setLoading(true);
    try {
      const response = await getEnvironmentalControlState(baseUrl, auth.token);
      setStatus(response.camaras);
      onDueChange?.(response.camaras.filter((camera) => camera.requiere_control).length);
      setFrequency(response.frecuencia_minutos);
      setGeneratedAt(response.generado_at);
      setStatusError('');
    } catch (reason) {
      setStatusError(errorMessage(reason));
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  }, [auth.token, baseUrl, onDueChange]);

  useEffect(() => {
    void loadStatus();
    const timer = setInterval(() => void loadStatus(), 60_000);
    return () => clearInterval(timer);
  }, [loadStatus]);

  useEffect(() => {
    let active = true;
    void loadEnvironmentalDraft(auth.usuario.id, auth.dispositivo.id).catch(() => null).then((saved) => {
      if (!active) return;
      if (saved) setDraft(saved);
      setDraftReady(true);
    });
    return () => { active = false; };
  }, [auth.dispositivo.id, auth.usuario.id]);

  const selectedCamera = status.find((item) => item.camara.id === draft.cameraId) ?? null;
  const hasSelection = selectedCamera !== null || draft.cameraId !== null;
  const due = status.filter((item) => item.requiere_control).length;
  const current = status.length - due;
  const lockedAttempt = draft.capturedAt !== null;

  const remember = useCallback((next: EnvironmentalControlDraft) => {
    setDraft(next);
    void saveEnvironmentalDraft(auth.usuario.id, auth.dispositivo.id, next).catch(() => {
      setSubmitError('No fue posible guardar el borrador en este equipo. Revisa el almacenamiento antes de enviar.');
    });
  }, [auth.dispositivo.id, auth.usuario.id]);

  function selectCamera(camera: EnvironmentalCameraState) {
    if (!canRegister || !camera.requiere_control || lockedAttempt) return;
    remember({ ...emptyDraft(), cameraId: camera.camara.id, cameraCode: camera.camara.codigo });
    setConfirming(false);
    setSubmitError('');
    setSuccess('');
    setFieldErrors({});
  }

  function changeField(field: 'start' | 'middle' | 'end', value: string) {
    if (lockedAttempt) return;
    remember({ ...draft, [field]: value });
    setFieldErrors((currentErrors) => ({ ...currentErrors, [field]: undefined }));
    setConfirming(false);
    setSubmitError('');
  }

  function values() {
    return {
      start: parseTemperature(draft.start),
      middle: parseTemperature(draft.middle),
      end: parseTemperature(draft.end),
    };
  }

  function review() {
    const parsed = values();
    const errors: FieldErrors = {};
    (Object.keys(parsed) as Array<keyof typeof parsed>).forEach((field) => {
      if (parsed[field] === null) errors[field] = 'Ingresa un número con máximo dos decimales.';
    });
    setFieldErrors(errors);
    if (Object.keys(errors).length > 0) return;
    setConfirming(true);
    setSubmitError('');
    setSuccess('');
  }

  async function submit() {
    if (!draft.cameraId) return;
    const parsed = values();
    if (parsed.start === null || parsed.middle === null || parsed.end === null) {
      review();
      return;
    }
    const exactAttempt = draft.capturedAt
      ? draft
      : { ...draft, capturedAt: new Date().toISOString() };
    if (!draft.capturedAt) {
      try {
        await saveEnvironmentalDraft(auth.usuario.id, auth.dispositivo.id, exactAttempt);
        setDraft(exactAttempt);
      } catch {
        setSubmitError('No fue posible asegurar el intento en este equipo. Libera espacio y vuelve a intentarlo.');
        return;
      }
    }
    const payload: EnvironmentalControlPayload = {
      operacion_id: exactAttempt.operationId,
      temperatura_inicio_c: parsed.start,
      temperatura_medio_c: parsed.middle,
      temperatura_fondo_c: parsed.end,
      capturado_at: exactAttempt.capturedAt!,
    };
    setSubmitting(true);
    setSubmitError('');
    try {
      await registerEnvironmentalControl(baseUrl, auth.token, exactAttempt.cameraId!, payload);
      await clearEnvironmentalDraft(auth.usuario.id, auth.dispositivo.id);
      setDraft(emptyDraft());
      setConfirming(false);
      setSuccess(`Control de ${exactAttempt.cameraCode ?? 'la cámara'} registrado correctamente.`);
      await loadStatus(true);
    } catch (reason) {
      const message = reason instanceof ApiError && reason.status === 409
        ? `${reason.message} Actualiza el estado antes de descartar este intento.`
        : errorMessage(reason);
      setSubmitError(message);
      setConfirming(true);
    } finally {
      setSubmitting(false);
    }
  }

  async function discardAttempt() {
    try {
      await clearEnvironmentalDraft(auth.usuario.id, auth.dispositivo.id);
    } catch {
      setSubmitError('No fue posible eliminar el intento guardado del equipo.');
      return;
    }
    setDraft(emptyDraft());
    setConfirming(false);
    setSubmitError('');
    setFieldErrors({});
    await loadStatus(true);
  }

  const ordered = useMemo(() => [...status].sort((a, b) => {
    const priority = { vencido: 0, pendiente: 1, vigente: 2 };
    return priority[a.estado] - priority[b.estado] || a.camara.codigo.localeCompare(b.camara.codigo);
  }), [status]);
  const pending = ordered.filter((camera) => camera.requiere_control);
  const upToDate = ordered.filter((camera) => !camera.requiere_control);
  const [showUpToDate, setShowUpToDate] = useState(false);

  return (
    <ScrollView
      style={styles.screen}
      contentContainerStyle={styles.content}
      keyboardShouldPersistTaps="handled"
      refreshControl={<RefreshControl colors={[o.color.primary]} refreshing={refreshing} tintColor={o.color.primary} onRefresh={() => void loadStatus(true)} />}
    >
      <View style={styles.heading}>
        <View style={styles.headingCopy}>
          <Text style={styles.eyebrow}>CADENA DE FRÍO · INICIO, MEDIO Y FONDO CADA {frequency} MIN</Text>
          <Text accessibilityRole="header" style={styles.title}>Control ambiental</Text>
          <Text style={styles.subtitle}>
            {statusError ? 'Estado sin verificar' : loading && !generatedAt ? 'Consultando estado…' : !status.length ? 'Sin cámaras disponibles' : due === 0 ? 'Todas las cámaras al día' : `${due} ${due === 1 ? 'cámara requiere' : 'cámaras requieren'} control`}
            {' · '}{current} al día · consultado {time(generatedAt)}
          </Text>
        </View>
        <Pressable accessibilityLabel="Actualizar estado" accessibilityRole="button" disabled={refreshing} onPress={() => void loadStatus(true)} style={[styles.refresh, refreshing && styles.disabled]}>
          <Text style={styles.refreshText}>↻</Text>
        </Pressable>
      </View>

      {statusError ? <Notice live title="No se pudo actualizar el estado" detail={statusError} tone="critical" /> : null}
      {success ? <Notice live title="Registro confirmado" detail={success} tone="success" /> : null}
      {lockedAttempt ? <Notice
        live
        title="Intento pendiente de confirmación"
        detail="Se conservan exactamente la cámara, las lecturas, la hora y el UUID. Reintenta el mismo envío o descártalo después de actualizar el estado."
        tone="warning"
      /> : null}

      <View style={[styles.workspace, compact && styles.workspaceCompact]}>
        <View style={styles.listColumn}>
          {loading && status.length === 0 ? <Text style={styles.muted}>Consultando cámaras…</Text> : null}
          {!loading && ordered.length === 0 ? (
            <View style={styles.empty}>
              <Text style={styles.emptyTitle}>Sin cámaras disponibles</Text>
              <Text style={styles.muted}>No existen cámaras PT activas visibles para este perfil.</Text>
            </View>
          ) : null}

          {pending.length > 0 ? (
            <View style={styles.list}>
              {pending.map((camera, index) => (
                <CameraRow
                  camera={camera}
                  canRegister={canRegister}
                  disabled={lockedAttempt}
                  key={camera.camara.id}
                  last={index === pending.length - 1}
                  onSelect={() => selectCamera(camera)}
                  selected={draft.cameraId === camera.camara.id}
                />
              ))}
            </View>
          ) : null}

          {upToDate.length > 0 ? (
            <>
              <Pressable
                accessibilityRole="button"
                accessibilityState={{ expanded: showUpToDate }}
                onPress={() => setShowUpToDate((value) => !value)}
                style={styles.upToDateToggle}
              >
                <View style={styles.upToDateLabel}>
                  <View style={[styles.dot, { backgroundColor: o.color.success }]} />
                  <Text style={styles.upToDateText}>{upToDate.length} {upToDate.length === 1 ? 'cámara al día' : 'cámaras al día'}</Text>
                </View>
                <Text style={styles.upToDateChevron}>{showUpToDate ? '▴' : '▾'}</Text>
              </Pressable>
              {showUpToDate ? (
                <View style={styles.list}>
                  {upToDate.map((camera, index) => (
                    <CameraRow camera={camera} canRegister={false} disabled key={camera.camara.id} last={index === upToDate.length - 1} onSelect={() => undefined} selected={false} />
                  ))}
                </View>
              ) : null}
            </>
          ) : null}
        </View>

        <View style={[styles.capture, compact && styles.captureCompact]}>
          <Text style={styles.eyebrow}>CAPTURA</Text>
          {!canRegister ? <Notice title="Perfil de consulta" detail="Puedes revisar la vigencia, pero este perfil no registra controles ambientales." tone="info" /> : null}
          {canRegister && !hasSelection ? (
            <View style={styles.captureEmpty}>
              <Text style={styles.captureEmptyTitle}>Elige una cámara pendiente</Text>
              <Text style={styles.muted}>Las vencidas aparecen primero. Una lectura vigente no admite otro control dentro de la misma hora.</Text>
            </View>
          ) : null}
          {canRegister && hasSelection ? <>
            <View style={styles.selectedCamera}>
              <Text numberOfLines={2} style={styles.selectedName}>{selectedCamera?.camara.nombre ?? 'La cámara ya no aparece en el estado actual'}</Text>
              <Text style={styles.code}>{selectedCamera?.camara.codigo ?? draft.cameraCode ?? 'Cámara no disponible'}</Text>
            </View>
            <View style={[styles.fields, compact && styles.fieldsCompact]}>
              {(Object.keys(labels) as Array<keyof typeof labels>).map((field) => (
                <View key={field} style={styles.field}>
                  <Text nativeID={`temp-${field}`} style={styles.fieldLabel}>{labels[field]} °C</Text>
                  <TextInput
                    accessibilityLabel={`${labels[field]} (°C)`}
                    accessibilityLabelledBy={`temp-${field}`}
                    editable={!lockedAttempt && !submitting}
                    keyboardType="numbers-and-punctuation"
                    onChangeText={(value) => changeField(field, value)}
                    placeholder="-0,80"
                    placeholderTextColor={o.color.borderStrong}
                    selectTextOnFocus
                    style={[styles.input, fieldErrors[field] && styles.inputError, (lockedAttempt || submitting) && styles.inputLocked]}
                    value={draft[field]}
                  />
                  {fieldErrors[field] ? <Text style={styles.fieldError}>{fieldErrors[field]}</Text> : null}
                </View>
              ))}
            </View>
            {confirming || lockedAttempt ? (
              <View style={styles.confirmation}>
                <Text style={styles.confirmationTitle}>Confirma las tres lecturas</Text>
                <Text style={styles.confirmationValues}>Inicio {draft.start} · Medio {draft.middle} · Fondo {draft.end} °C</Text>
              </View>
            ) : null}
            {submitError ? <Notice live title="El envío no quedó confirmado" detail={submitError} tone="critical" /> : null}
            <View style={styles.actions}>
              {!confirming && !lockedAttempt ? <ActionButton label="Revisar lecturas" onPress={review} /> : null}
              {confirming || lockedAttempt ? (
                <ActionButton
                  busy={submitting}
                  label={lockedAttempt ? 'Reintentar mismo envío' : 'Confirmar y enviar'}
                  onPress={() => void submit()}
                />
              ) : null}
              {confirming && !lockedAttempt ? <ActionButton label="Volver a editar" onPress={() => setConfirming(false)} variant="secondary" /> : null}
              {lockedAttempt ? <ActionButton disabled={submitting} label="Actualizar y descartar intento" onPress={() => void discardAttempt()} variant="critical" /> : null}
            </View>
            <Text style={styles.footnote}>Queda a nombre de {auth.usuario.nombre} · {auth.dispositivo.codigo}</Text>
          </> : null}
          {!draftReady ? <Text style={styles.muted}>Revisando capturas pendientes del equipo…</Text> : null}
        </View>
      </View>
    </ScrollView>
  );
}

function CameraRow({ camera, canRegister, disabled, last, onSelect, selected }: {
  camera: EnvironmentalCameraState;
  canRegister: boolean;
  disabled: boolean;
  last: boolean;
  onSelect: () => void;
  selected: boolean;
}) {
  const presentation = statusPresentation(camera);
  const readings = camera.ultimo_registro?.temperaturas;
  const color = toneColor(presentation.tone);
  return (
    <View style={[styles.row, last && styles.rowLast, selected && styles.rowSelected]}>
      <View style={[styles.statusMark, { borderColor: color }]}>
        <View style={[styles.statusDot, { backgroundColor: color }]} />
      </View>
      <View style={styles.rowCopy}>
        <View style={styles.rowTitle}>
          <Text numberOfLines={1} style={styles.rowName}>{camera.camara.nombre}</Text>
          <Text style={styles.rowCode}>{camera.camara.codigo}</Text>
        </View>
        <Text style={[styles.rowStatus, { color }]}>{presentation.label} · {presentation.detail}</Text>
        {readings ? (
          <Text style={styles.rowReadings}>
            Última lectura  Inicio <Text style={styles.rowReadingValue}>{celsius(readings.inicio_c)}</Text>
            {'  '}Medio <Text style={styles.rowReadingValue}>{celsius(readings.medio_c)}</Text>
            {'  '}Fondo <Text style={styles.rowReadingValue}>{celsius(readings.fondo_c)}</Text>
          </Text>
        ) : <Text style={styles.rowReadings}>Sin lecturas previas</Text>}
      </View>
      {camera.requiere_control && canRegister ? (
        <Pressable
          accessibilityRole="button"
          accessibilityState={{ disabled: disabled || selected, selected }}
          disabled={disabled || selected}
          onPress={onSelect}
          style={[styles.rowAction, selected && styles.rowActionSelected, disabled && !selected && styles.disabled]}
        >
          <Text style={[styles.rowActionText, selected && styles.rowActionTextSelected]}>{selected ? 'Seleccionada' : 'Registrar'}</Text>
        </Pressable>
      ) : null}
    </View>
  );
}

function Notice({ detail, live = false, title, tone }: { detail: string; live?: boolean; title: string; tone: Tone }) {
  const palette = tonePalette(tone);
  return (
    <View accessibilityLiveRegion={live ? 'polite' : undefined} style={[styles.notice, { backgroundColor: palette.surface, borderColor: palette.border }]}>
      <Text style={[styles.noticeTitle, { color: palette.text }]}>{title}</Text>
      <Text style={styles.noticeDetail}>{detail}</Text>
    </View>
  );
}

function ActionButton({ busy = false, disabled = false, label, onPress, variant = 'primary' }: {
  busy?: boolean;
  disabled?: boolean;
  label: string;
  onPress: () => void;
  variant?: 'primary' | 'secondary' | 'critical';
}) {
  const inactive = busy || disabled;
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityState={{ busy, disabled: inactive }}
      disabled={inactive}
      onPress={onPress}
      style={[styles.button, variant === 'secondary' && styles.buttonSecondary, variant === 'critical' && styles.buttonCritical, inactive && styles.disabled]}
    >
      <Text style={[styles.buttonText, variant !== 'primary' && styles.buttonTextOutline, variant === 'critical' && styles.buttonTextCritical]}>
        {busy ? 'Enviando…' : label}
      </Text>
    </Pressable>
  );
}

function celsius(value: number) {
  return `${value.toFixed(2).replace('.', ',')} °C`;
}

function toneColor(tone: Tone) {
  return tonePalette(tone).text;
}

function tonePalette(tone: Tone) {
  if (tone === 'success') return { text: o.color.success, surface: o.color.successSurface, border: o.color.successBorder };
  if (tone === 'warning') return { text: o.color.warning, surface: o.color.warningSurface, border: o.color.warningBorder };
  if (tone === 'critical') return { text: o.color.critical, surface: o.color.criticalSurface, border: o.color.criticalBorder };
  return { text: o.color.primaryPressed, surface: o.color.surfaceMuted, border: o.color.infoBorder };
}

const styles = StyleSheet.create({
  screen: { flex: 1, backgroundColor: o.color.canvas },
  content: { padding: o.space[6], gap: o.space[4] },
  heading: { flexDirection: 'row', alignItems: 'flex-start', justifyContent: 'space-between', gap: o.space[4] },
  headingCopy: { flex: 1, minWidth: 0, gap: o.space[1] },
  eyebrow: { fontFamily: font.heavy, color: o.color.muted, fontSize: o.type.caption, letterSpacing: 1.2 },
  title: { fontFamily: font.heavy, color: o.color.text, fontSize: o.type.title },
  subtitle: { fontFamily: font.regular, color: o.color.textSecondary, fontSize: o.type.body },
  refresh: { minWidth: o.touch.minimum, minHeight: o.touch.minimum, alignItems: 'center', justifyContent: 'center', borderRadius: o.radius.pill, borderWidth: 1, borderColor: o.color.border },
  refreshText: { fontFamily: font.bold, color: o.color.textSecondary, fontSize: o.type.heading },

  workspace: { flexDirection: 'row', alignItems: 'flex-start', gap: o.space[6] },
  workspaceCompact: { flexDirection: 'column', alignItems: 'stretch' },
  listColumn: { flex: 3, minWidth: 0, gap: o.space[3] },
  list: { borderWidth: 1, borderColor: o.color.border, borderRadius: o.radius.panel, backgroundColor: o.color.surface, overflow: 'hidden' },
  row: { minHeight: 104, paddingHorizontal: o.space[6], paddingVertical: o.space[4], flexDirection: 'row', alignItems: 'center', gap: o.space[4], borderBottomWidth: 1, borderBottomColor: o.color.border },
  rowLast: { borderBottomWidth: 0 },
  rowSelected: { backgroundColor: o.color.selected },
  statusMark: { width: 40, height: 40, borderRadius: o.radius.pill, borderWidth: 2, alignItems: 'center', justifyContent: 'center' },
  statusDot: { width: 14, height: 14, borderRadius: o.radius.pill },
  rowCopy: { flex: 1, minWidth: 0, gap: 4 },
  rowTitle: { flexDirection: 'row', alignItems: 'baseline', flexWrap: 'wrap', columnGap: o.space[3] },
  rowName: { fontFamily: font.bold, color: o.color.text, fontSize: 20, flexShrink: 1 },
  rowCode: { fontFamily: font.mono, color: o.color.muted, fontSize: o.type.small },
  rowStatus: { fontFamily: font.semibold, fontSize: o.type.small },
  rowReadings: { fontFamily: font.regular, color: o.color.muted, fontSize: o.type.small },
  rowReadingValue: { fontFamily: font.monoBold, color: o.color.textSecondary },
  rowAction: { minHeight: 56, minWidth: 148, paddingHorizontal: o.space[4], alignItems: 'center', justifyContent: 'center', borderRadius: o.radius.control, borderWidth: 2, borderColor: o.color.primary },
  rowActionSelected: { borderColor: o.color.primary, backgroundColor: o.color.primary },
  rowActionText: { fontFamily: font.heavy, color: o.color.primaryPressed, fontSize: o.type.body },
  rowActionTextSelected: { color: o.color.onPrimary },
  upToDateToggle: { minHeight: o.touch.prominent, paddingHorizontal: o.space[6], flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', borderRadius: o.radius.panel, borderWidth: 1, borderColor: o.color.border },
  upToDateLabel: { flexDirection: 'row', alignItems: 'center', gap: o.space[3] },
  upToDateText: { fontFamily: font.semibold, color: o.color.text, fontSize: o.type.body },
  upToDateChevron: { fontFamily: font.bold, color: o.color.muted, fontSize: o.type.heading },
  dot: { width: 10, height: 10, borderRadius: o.radius.pill },
  empty: { padding: o.space[6], gap: o.space[2], borderWidth: 1, borderColor: o.color.border, borderRadius: o.radius.panel, backgroundColor: o.color.surface },
  emptyTitle: { fontFamily: font.bold, color: o.color.text, fontSize: o.type.heading },
  muted: { fontFamily: font.regular, color: o.color.muted, fontSize: o.type.small, lineHeight: 22 },

  capture: { flex: 2, minWidth: 0, padding: o.space[6], gap: o.space[4], borderWidth: 1, borderColor: o.color.border, borderRadius: o.radius.panel, backgroundColor: o.color.surface },
  captureCompact: { flex: 0 },
  captureEmpty: { gap: o.space[2] },
  captureEmptyTitle: { fontFamily: font.bold, color: o.color.text, fontSize: o.type.heading },
  selectedCamera: { gap: 2, paddingBottom: o.space[3], borderBottomWidth: 1, borderBottomColor: o.color.border },
  selectedName: { fontFamily: font.heavy, color: o.color.text, fontSize: o.type.heading },
  code: { fontFamily: font.monoBold, color: o.color.primaryPressed, fontSize: o.type.body },
  fields: { flexDirection: 'row', gap: o.space[3] },
  fieldsCompact: { flexDirection: 'column' },
  field: { flex: 1, minWidth: 0, gap: o.space[1] },
  fieldLabel: { fontFamily: font.bold, color: o.color.textSecondary, fontSize: o.type.small },
  input: { fontFamily: font.monoBold, minHeight: 64, paddingHorizontal: o.space[3], borderRadius: o.radius.control, borderWidth: 2, borderColor: o.color.borderStrong, backgroundColor: o.color.canvas, color: o.color.text, fontSize: 26, textAlign: 'center' },
  inputError: { borderColor: o.color.critical },
  inputLocked: { opacity: 0.6 },
  fieldError: { fontFamily: font.semibold, color: o.color.critical, fontSize: o.type.caption },
  confirmation: { padding: o.space[4], gap: o.space[1], borderRadius: o.radius.control, borderWidth: 1, borderColor: o.color.infoBorder, backgroundColor: o.color.surfaceMuted },
  confirmationTitle: { fontFamily: font.bold, color: o.color.text, fontSize: o.type.body },
  confirmationValues: { fontFamily: font.monoBold, color: o.color.text, fontSize: o.type.body },
  actions: { gap: o.space[2] },
  button: { minHeight: o.touch.prominent, paddingHorizontal: o.space[4], alignItems: 'center', justifyContent: 'center', borderRadius: o.radius.control, backgroundColor: o.color.primary },
  buttonSecondary: { borderWidth: 2, borderColor: o.color.borderStrong, backgroundColor: 'transparent' },
  buttonCritical: { borderWidth: 2, borderColor: o.color.criticalBorder, backgroundColor: 'transparent' },
  buttonText: { fontFamily: font.heavy, color: o.color.onPrimary, fontSize: o.type.body },
  buttonTextOutline: { color: o.color.text },
  buttonTextCritical: { color: o.color.critical },
  footnote: { fontFamily: font.regular, color: o.color.muted, fontSize: o.type.caption },
  notice: { padding: o.space[4], gap: 2, borderRadius: o.radius.control, borderWidth: 1 },
  noticeTitle: { fontFamily: font.bold, fontSize: o.type.body },
  noticeDetail: { fontFamily: font.regular, color: o.color.textSecondary, fontSize: o.type.small, lineHeight: 21 },
  disabled: { opacity: 0.45 },
});
