import { useMemo, useState } from 'react';
import { Pressable, ScrollView, StyleSheet, Text, TextInput, View, useWindowDimensions } from 'react-native';

import {
  operationalTaskDestinationLabel,
  operationalTaskPositionLabel,
  type ManeuverDiscrepancyType,
  type OperationalTask,
  type ReportedManeuverDiscrepancy,
} from '../../domain/operationalTasks';
import {
  operatorExceptionOptions,
  isLocationReconciliation,
  operatorExceptionPrompt,
  operatorExceptionTitle,
  validateOperatorException,
  type OperatorExceptionKind,
} from '../../domain/operatorManeuverException';
import { operatorTheme as o } from '../../theme/operatorTheme';
import { OperatorEntityCode, OperatorStatusBadge } from './OperatorPrimitives';

type Props = {
  busy: boolean;
  kind: OperatorExceptionKind;
  onCancel: () => void;
  onClose: () => void;
  onSubmit: (
    type: ManeuverDiscrepancyType,
    detail: string,
  ) => Promise<ReportedManeuverDiscrepancy | null>;
  task: OperationalTask;
};

type Stage = 'form' | 'confirm' | 'submitted';

export function OperatorExceptionReport({
  busy,
  kind,
  onCancel,
  onClose,
  onSubmit,
  task,
}: Props) {
  const { width } = useWindowDimensions();
  const compact = width < o.breakpoint.compact;
  const options = useMemo(() => operatorExceptionOptions(kind), [kind]);
  const [stage, setStage] = useState<Stage>('form');
  const [selectedType, setSelectedType] = useState<ManeuverDiscrepancyType | null>(null);
  const [detail, setDetail] = useState('');
  const [validation, setValidation] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [submitted, setSubmitted] = useState<ReportedManeuverDiscrepancy | null>(null);
  const selected = options.find((option) => option.type === selectedType) ?? null;
  const title = operatorExceptionTitle(kind);

  const review = () => {
    const message = validateOperatorException(selectedType, detail);
    setValidation(message ?? '');
    if (!message) setStage('confirm');
  };

  const send = async () => {
    if (!selectedType || submitting) return;
    setSubmitting(true);
    const result = await onSubmit(selectedType, detail.trim());
    if (!result) {
      setSubmitting(false);
      return;
    }
    setSubmitted(result);
    setStage('submitted');
  };

  if (stage === 'submitted' && submitted) {
    return (
      <ScrollView contentContainerStyle={styles.waitingContent} style={styles.screen}>
        <View style={styles.waitingCard}>
          <View style={styles.waitingIcon}><Text style={styles.waitingIconText}>!</Text></View>
          <OperatorStatusBadge label="EN ESPERA DE SUPERVISIÓN" tone="warning" />
          <Text accessibilityRole="header" style={styles.waitingTitle}>Maniobra pausada</Text>
          <Text style={styles.waitingCopy}>
            El WMS conservó los pasos ya ejecutados y el estado físico informado. No continúes moviendo este pallet hasta recibir una decisión.
          </Text>
          <View style={styles.waitingSummary}>
            <SummaryLine label="Folio" value={task.folio.numero_folio} />
            <SummaryLine label="Reporte" value={selected?.label ?? title} />
            <SummaryLine label="Estado" value="Revisión pendiente" />
          </View>
          <Text style={styles.waitingAudit}>Reporte registrado · {submitted.id}</Text>
          <Pressable onPress={onClose} style={styles.closeButton}>
            <Text style={styles.closeButtonText}>VOLVER A MI JORNADA</Text>
          </Pressable>
        </View>
      </ScrollView>
    );
  }

  if (stage === 'confirm') {
    return (
      <ScrollView contentContainerStyle={styles.content} style={styles.screen}>
        <ExceptionHeader disabled={busy || submitting} onBack={() => setStage('form')} subtitle="Confirma antes de detener la operación" title={title} />
        <View style={styles.confirmWarning}>
          <Text style={styles.confirmWarningTitle}>La maniobra quedará pausada</Text>
          <Text style={styles.confirmWarningCopy}>
            Supervisión recibirá el estado físico actual. Los movimientos ya realizados y cualquier custodia temporal se conservarán.
          </Text>
        </View>
        <View style={styles.confirmCard}>
          <Text style={styles.sectionEyebrow}>RESUMEN DEL REPORTE</Text>
          <SummaryLine label="Folio / pallet" value={task.folio.numero_folio} />
          <SummaryLine label="Paso" value={`${task.secuencia_maniobra ?? task.maniobra?.secuencia_actual ?? 1} de ${task.maniobra?.pasos_totales ?? 1}`} />
          <SummaryLine label="Motivo" value={selected?.label ?? title} />
          <SummaryLine label="Observación" value={detail.trim()} />
          <SummaryLine label="Origen" value={operationalTaskPositionLabel(task.origen)} />
          <SummaryLine label="Destino" value={operationalTaskDestinationLabel(task)} />
        </View>
        <View style={[styles.confirmActions, compact && styles.stackedActions]}>
          <Pressable disabled={busy || submitting} onPress={() => setStage('form')} style={[styles.editButton, (busy || submitting) && styles.disabled]}>
            <Text style={styles.editButtonText}>CORREGIR REPORTE</Text>
          </Pressable>
          <Pressable disabled={busy || submitting} onPress={() => void send()} style={[styles.submitButton, (busy || submitting) && styles.disabled]}>
            <Text style={styles.submitButtonText}>{busy || submitting ? 'ENVIANDO…' : 'CONFIRMAR Y PAUSAR MANIOBRA'}</Text>
          </Pressable>
        </View>
      </ScrollView>
    );
  }

  return (
    <ScrollView contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled" style={styles.screen}>
      <ExceptionHeader onBack={onCancel} subtitle={operatorExceptionPrompt(kind)} title={title} />

      <View style={[styles.taskContext, compact && styles.taskContextCompact]}>
        <View style={[styles.folioContext, compact && styles.folioContextCompact]}>
          <Text style={styles.contextLabel}>FOLIO / PALLET</Text>
          <OperatorEntityCode prominent value={task.folio.numero_folio} />
        </View>
        <View style={styles.routeContext}>
          <SummaryLine label="Ubicación informada" value={operationalTaskPositionLabel(task.origen)} />
          <SummaryLine label="Instrucción de destino" value={operationalTaskDestinationLabel(task)} />
        </View>
      </View>

      <View style={styles.formCard}>
        <Text style={styles.sectionEyebrow}>1 · SELECCIONA EL MOTIVO</Text>
        <View style={styles.optionGrid}>
          {options.map((option) => {
            const active = option.type === selectedType;
            const recommended = option.type === 'pallet_no_encontrado' && isLocationReconciliation(task);
            return (
              <Pressable
                accessibilityRole="radio"
                accessibilityState={{ checked: active }}
                key={option.type}
                onPress={() => {
                  setSelectedType(option.type);
                  setValidation('');
                }}
                style={[styles.option, recommended && styles.optionRecommended, active && styles.optionActive]}
              >
                <View style={[styles.radio, active && styles.radioActive]}>
                  {active ? <View style={styles.radioDot} /> : null}
                </View>
                <View style={styles.optionCopy}>
                  {recommended ? <Text style={styles.optionRecommendation}>RECOMENDADO PARA CONCILIACIÓN</Text> : null}
                  <Text style={[styles.optionTitle, active && styles.optionTitleActive]}>{option.label}</Text>
                  <Text style={styles.optionDescription}>{option.description}</Text>
                </View>
              </Pressable>
            );
          })}
        </View>

        <View style={styles.detailSection}>
          <View style={styles.detailHeading}>
            <Text style={styles.sectionEyebrow}>2 · DESCRIBE LO ENCONTRADO</Text>
            <Text style={styles.counter}>{detail.length}/500</Text>
          </View>
          <TextInput
            accessibilityLabel="Observación de la excepción"
            maxLength={500}
            multiline
            onChangeText={(value) => {
              setDetail(value);
              setValidation('');
            }}
            placeholder="Ej.: En B08-P04 encontré el folio PAL-058300 y no el indicado."
            placeholderTextColor={o.color.disabledText}
            style={styles.input}
            textAlignVertical="top"
            value={detail}
          />
          {validation ? <Text accessibilityLiveRegion="polite" style={styles.validation}>{validation}</Text> : null}
        </View>
      </View>

      <View style={[styles.formActions, compact && styles.stackedActions]}>
        <Pressable disabled={busy} onPress={onCancel} style={styles.cancelButton}>
          <Text style={styles.cancelButtonText}>VOLVER SIN REPORTAR</Text>
        </Pressable>
        <Pressable disabled={busy} onPress={review} style={[styles.reviewButton, busy && styles.disabled]}>
          <Text style={styles.reviewButtonText}>REVISAR REPORTE →</Text>
        </Pressable>
      </View>
    </ScrollView>
  );
}

function ExceptionHeader({ disabled = false, onBack, subtitle, title }: { disabled?: boolean; onBack: () => void; subtitle: string; title: string }) {
  return (
    <View style={styles.header}>
      <Pressable disabled={disabled} onPress={onBack} style={[styles.headerBack, disabled && styles.disabled]}>
        <Text style={styles.headerBackIcon}>‹</Text>
      </Pressable>
      <View style={styles.headerCopy}>
        <Text style={styles.headerEyebrow}>EXCEPCIÓN FÍSICA</Text>
        <Text accessibilityRole="header" style={styles.headerTitle}>{title}</Text>
        <Text style={styles.headerSubtitle}>{subtitle}</Text>
      </View>
    </View>
  );
}

function SummaryLine({ label, value }: { label: string; value: string }) {
  return (
    <View style={styles.summaryLine}>
      <Text style={styles.summaryLabel}>{label}</Text>
      <Text style={styles.summaryValue}>{value}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  screen: { flex: 1, backgroundColor: o.color.canvas },
  content: { gap: o.space[3], paddingBottom: o.space[8] },
  header: { minHeight: 96, padding: o.space[3], flexDirection: 'row', alignItems: 'center', gap: o.space[3], borderWidth: 1, borderColor: o.color.critical, borderRadius: o.radius.panel, backgroundColor: o.color.criticalSurface },
  headerBack: { width: o.touch.minimum, height: o.touch.minimum, alignItems: 'center', justifyContent: 'center', borderRadius: o.radius.control, backgroundColor: o.color.navy },
  headerBackIcon: { color: o.color.onNavy, fontSize: 40, lineHeight: 42 },
  headerCopy: { flex: 1 },
  headerEyebrow: { color: o.color.critical, fontSize: o.type.caption, fontWeight: '900', letterSpacing: 1.1 },
  headerTitle: { color: o.color.text, fontSize: o.type.title, fontWeight: '900', marginTop: o.space[1] },
  headerSubtitle: { color: o.color.muted, fontSize: o.type.small, marginTop: o.space[1] },
  taskContext: { flexDirection: 'row', borderWidth: 1, borderColor: o.color.border, borderRadius: o.radius.panel, backgroundColor: o.color.surface },
  taskContextCompact: { flexDirection: 'column' },
  folioContext: { minWidth: 260, padding: o.space[4], justifyContent: 'center', borderRightWidth: 1, borderRightColor: o.color.border },
  folioContextCompact: { borderRightWidth: 0, borderBottomWidth: 1, borderBottomColor: o.color.border },
  routeContext: { flex: 1, padding: o.space[3] },
  contextLabel: { color: o.color.muted, fontSize: o.type.caption, fontWeight: '900', marginBottom: o.space[2] },
  formCard: { padding: o.space[4], gap: o.space[4], borderWidth: 1, borderColor: o.color.border, borderRadius: o.radius.panel, backgroundColor: o.color.surface },
  sectionEyebrow: { color: o.color.primaryPressed, fontSize: o.type.caption, fontWeight: '900', letterSpacing: 1 },
  optionGrid: { flexDirection: 'row', flexWrap: 'wrap', gap: o.space[3] },
  option: { minHeight: 86, flexBasis: 260, flexGrow: 1, padding: o.space[3], flexDirection: 'row', alignItems: 'center', gap: o.space[3], borderWidth: 2, borderColor: o.color.border, borderRadius: o.radius.control, backgroundColor: o.color.surfaceMuted },
  optionRecommended: { borderColor: o.color.warning, backgroundColor: o.color.warningSurface },
  optionActive: { borderColor: o.color.primary, backgroundColor: o.color.selected },
  optionRecommendation: { color: o.color.warning, fontSize: o.type.caption, fontWeight: '900' },
  radio: { width: 28, height: 28, alignItems: 'center', justifyContent: 'center', borderWidth: 2, borderColor: o.color.borderStrong, borderRadius: 14, backgroundColor: o.color.surface },
  radioActive: { borderColor: o.color.primary },
  radioDot: { width: 14, height: 14, borderRadius: 7, backgroundColor: o.color.primary },
  optionCopy: { flex: 1 },
  optionTitle: { color: o.color.text, fontSize: o.type.body, fontWeight: '900' },
  optionTitleActive: { color: o.color.primaryPressed },
  optionDescription: { color: o.color.muted, fontSize: o.type.small, lineHeight: 19, marginTop: o.space[1] },
  detailSection: { gap: o.space[2] },
  detailHeading: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: o.space[3] },
  counter: { color: o.color.muted, fontSize: o.type.caption, fontWeight: '800' },
  input: { minHeight: 116, padding: o.space[3], borderWidth: 2, borderColor: o.color.borderStrong, borderRadius: o.radius.control, backgroundColor: o.color.surface, color: o.color.text, fontSize: o.type.body, lineHeight: 22 },
  validation: { color: o.color.critical, fontSize: o.type.small, fontWeight: '800' },
  formActions: { flexDirection: 'row', justifyContent: 'flex-end', gap: o.space[3] },
  stackedActions: { flexDirection: 'column' },
  cancelButton: { minHeight: o.touch.prominent, paddingHorizontal: o.space[6], alignItems: 'center', justifyContent: 'center', borderWidth: 1, borderColor: o.color.borderStrong, borderRadius: o.radius.control, backgroundColor: o.color.surface },
  cancelButtonText: { color: o.color.text, fontSize: o.type.small, fontWeight: '900' },
  reviewButton: { minHeight: o.touch.prominent, paddingHorizontal: o.space[6], alignItems: 'center', justifyContent: 'center', borderRadius: o.radius.control, backgroundColor: o.color.primary },
  reviewButtonText: { color: o.color.onPrimary, fontSize: o.type.body, fontWeight: '900' },
  confirmWarning: { padding: o.space[4], borderWidth: 2, borderColor: o.color.warning, borderRadius: o.radius.panel, backgroundColor: o.color.warningSurface },
  confirmWarningTitle: { color: o.color.warning, fontSize: o.type.heading, fontWeight: '900' },
  confirmWarningCopy: { color: o.color.text, fontSize: o.type.body, lineHeight: 23, marginTop: o.space[2] },
  confirmCard: { padding: o.space[4], gap: o.space[2], borderWidth: 1, borderColor: o.color.border, borderRadius: o.radius.panel, backgroundColor: o.color.surface },
  summaryLine: { minHeight: 48, paddingVertical: o.space[2], flexDirection: 'row', alignItems: 'flex-start', flexWrap: 'wrap', gap: o.space[3], borderBottomWidth: 1, borderBottomColor: o.color.border },
  summaryLabel: { width: 150, color: o.color.muted, fontSize: o.type.small, fontWeight: '800' },
  summaryValue: { flex: 1, minWidth: 150, color: o.color.text, fontSize: o.type.body, lineHeight: 22, fontWeight: '800' },
  confirmActions: { flexDirection: 'row', justifyContent: 'flex-end', gap: o.space[3] },
  editButton: { minHeight: o.touch.prominent, paddingHorizontal: o.space[6], alignItems: 'center', justifyContent: 'center', borderWidth: 1, borderColor: o.color.primary, borderRadius: o.radius.control, backgroundColor: o.color.surface },
  editButtonText: { color: o.color.primaryPressed, fontSize: o.type.small, fontWeight: '900' },
  submitButton: { minHeight: o.touch.prominent, paddingHorizontal: o.space[6], alignItems: 'center', justifyContent: 'center', borderRadius: o.radius.control, backgroundColor: o.color.critical },
  submitButtonText: { color: o.color.onPrimary, fontSize: o.type.body, fontWeight: '900' },
  disabled: { opacity: 0.45 },
  waitingContent: { minHeight: '100%', padding: o.space[4], alignItems: 'center', justifyContent: 'center' },
  waitingCard: { width: '100%', maxWidth: 760, padding: o.space[6], alignItems: 'center', gap: o.space[3], borderWidth: 2, borderColor: o.color.warning, borderRadius: o.radius.panel, backgroundColor: o.color.surface },
  waitingIcon: { width: 72, height: 72, alignItems: 'center', justifyContent: 'center', borderRadius: 36, backgroundColor: o.color.warningSurface },
  waitingIconText: { color: o.color.warning, fontSize: 44, lineHeight: 48, fontWeight: '900' },
  waitingTitle: { color: o.color.text, fontSize: o.type.title, fontWeight: '900', textAlign: 'center' },
  waitingCopy: { maxWidth: 620, color: o.color.muted, fontSize: o.type.body, lineHeight: 24, textAlign: 'center' },
  waitingSummary: { width: '100%', marginTop: o.space[2], borderTopWidth: 1, borderTopColor: o.color.border },
  waitingAudit: { color: o.color.muted, fontSize: o.type.caption, textAlign: 'center' },
  closeButton: { minHeight: o.touch.prominent, alignSelf: 'stretch', alignItems: 'center', justifyContent: 'center', borderRadius: o.radius.control, backgroundColor: o.color.navy },
  closeButtonText: { color: o.color.onNavy, fontSize: o.type.body, fontWeight: '900' },
});
