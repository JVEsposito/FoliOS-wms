import { useState, type ReactNode } from 'react';
import {
  ActivityIndicator, Platform, Pressable, ScrollView, StyleSheet, Text, TextInput, View,
  type PressableProps, type StyleProp, type TextInputProps, type ViewStyle,
} from 'react-native';

import { estibaTokens, type EstibaTone } from '../../theme/estibaTokens';

// Terreno siempre oscuro (FoliOS v2): colores de colorDark/signalDark sobre los claros.
const t = {
  ...estibaTokens,
  color: { ...estibaTokens.color, ...estibaTokens.colorDark },
  signal: estibaTokens.signalDark,
} as const;

// Adopción explícita: este kit no sustituye el tema de las pantallas existentes.
type SurfaceProps = { children: ReactNode; style?: StyleProp<ViewStyle> };
type ButtonVariant = 'primary' | 'secondary' | 'critical' | 'confirm';

export function EstibaSurface({ children, style }: SurfaceProps) {
  return <View style={[s.surface, style]}>{children}</View>;
}

export function EstibaHeading({ title, eyebrow, description, actions }: {
  title: string; eyebrow?: string; description?: string; actions?: ReactNode;
}) {
  return <View style={s.heading}>
    <View style={s.headingCopy}>
      {eyebrow ? <Text style={s.eyebrow}>{eyebrow}</Text> : null}
      <Text accessibilityRole="header" style={s.title}>{title}</Text>
      {description ? <Text style={s.muted}>{description}</Text> : null}
    </View>
    {actions}
  </View>;
}

export function EstibaPanel({ title, children, actions, style }: SurfaceProps & {
  title: string; actions?: ReactNode;
}) {
  return <View style={[s.panel, style]}>
    <View style={s.panelHeader}>
      <Text accessibilityRole="header" style={s.panelTitle}>{title}</Text>
      {actions}
    </View>
    <View style={s.panelBody}>{children}</View>
  </View>;
}

export function EstibaButton({ label, variant = 'primary', busy = false, disabled = false, style, ...props }: Omit<PressableProps, 'children' | 'style'> & {
  label: string; variant?: ButtonVariant; busy?: boolean; style?: StyleProp<ViewStyle>;
}) {
  const [focused, setFocused] = useState(false);
  const inactive = disabled || busy;
  const palette = {
    primary: { background: t.color.primary, text: t.color.onPrimary, border: t.color.primary },
    secondary: { background: t.color.surface, text: t.color.text, border: t.color.inputBorder },
    critical: { background: t.color.surface, text: t.signal.critical.text, border: t.signal.critical.text },
    confirm: { background: t.signal.success.text, text: t.color.onPrimary, border: t.signal.success.text },
  }[variant];
  const textColor = inactive ? t.color.disabledText : palette.text;

  return <Pressable
    {...props}
    accessibilityRole="button"
    accessibilityState={{ ...props.accessibilityState, disabled: inactive, busy }}
    disabled={inactive}
    onFocus={(event) => { setFocused(true); props.onFocus?.(event); }}
    onBlur={(event) => { setFocused(false); props.onBlur?.(event); }}
    style={({ pressed }) => [s.button, {
      backgroundColor: inactive ? t.color.disabledSurface : palette.background,
      borderColor: inactive ? t.color.border : palette.border,
      opacity: pressed ? 0.8 : 1,
    }, focused && s.focused, style]}
  >
    {busy ? <ActivityIndicator color={textColor} /> : null}
    <Text style={[s.buttonLabel, { color: textColor }]}>{label}</Text>
  </Pressable>;
}

export function EstibaSignal({ label, tone = 'neutral' }: { label: string; tone?: EstibaTone }) {
  const square = tone === 'critical' || tone === 'blocked';
  const diamond = tone === 'reserved' || tone === 'warning' || tone === 'temporary';
  return <View style={s.signal}>
    <View accessible={false} style={[s.marker, {
      borderColor: t.signal[tone].text,
      backgroundColor: tone === 'neutral' ? 'transparent' : t.signal[tone].text,
      borderRadius: square || diamond ? 0 : 4,
      transform: [{ rotate: diamond ? '45deg' : '0deg' }],
    }]} />
    <Text style={[s.signalText, { color: t.signal[tone].text }]}>{label}</Text>
  </View>;
}

export function EntityCode({ value, large = false }: { value: string; large?: boolean }) {
  return <Text selectable style={[s.code, large && s.codeLarge]}>{value}</Text>;
}

export function EstibaField({ label, hint, error, style, ...props }: TextInputProps & {
  label: string; hint?: string; error?: string;
}) {
  const [focused, setFocused] = useState(false);
  return <View style={s.field}>
    <Text style={s.fieldLabel}>{label}</Text>
    <TextInput
      {...props}
      accessibilityLabel={props.accessibilityLabel ?? label}
      accessibilityHint={error ? `Error: ${error}` : hint}
      placeholderTextColor={t.color.muted}
      selectionColor={t.color.primary}
      onFocus={(event) => { setFocused(true); props.onFocus?.(event); }}
      onBlur={(event) => { setFocused(false); props.onBlur?.(event); }}
      style={[s.input, props.multiline && s.multilineInput, error ? { borderColor: t.signal.critical.text } : null, focused && s.focused, style]}
    />
    {hint ? <Text style={s.smallMuted}>{hint}</Text> : null}
    {error ? <Text accessibilityLiveRegion="polite" style={s.fieldError}>Error: {error}</Text> : null}
  </View>;
}

export function EstibaMetric({ label, value, total, detail, tone = 'info' }: {
  label: string; value: number | null; total: number | null; detail?: string; tone?: EstibaTone;
}) {
  const available = typeof value === 'number' && Number.isFinite(value) && value >= 0
    && typeof total === 'number' && Number.isFinite(total) && total > 0;
  const percent = available ? Math.max(0, Math.min(100, Math.round(value / total * 100))) : 0;
  const count = available ? `${value} / ${total}` : 'Sin registro';
  return <View style={s.metric}>
    <View style={s.metricHeading}><Text style={s.body}>{label}</Text><Text style={s.metricValue}>{count}</Text></View>
    {available ? <View
      accessibilityRole="progressbar"
      accessibilityLabel={label}
      accessibilityValue={{ min: 0, max: 100, now: percent, text: `${value} de ${total}` }}
      style={s.meter}
    ><View style={{ width: `${percent}%`, height: '100%', backgroundColor: t.signal[tone].text }} /></View> : null}
    {detail ? <Text style={s.smallMuted}>{detail}</Text> : null}
  </View>;
}

export function EstibaAlert({ title, detail, tone = 'info', live = false }: {
  title: string; detail?: string; tone?: EstibaTone; live?: boolean;
}) {
  const urgent = tone === 'critical' || tone === 'blocked';
  return <View
    accessibilityLiveRegion={live ? urgent ? 'assertive' : 'polite' : 'none'}
    style={[s.alert, { borderColor: t.signal[tone].border, borderLeftColor: t.signal[tone].text, backgroundColor: t.signal[tone].surface }]}
  >
    <Text style={[s.fieldLabel, { color: t.signal[tone].text }]}>{title}</Text>
    {detail ? <Text style={[s.body, { color: t.signal[tone].text }]}>{detail}</Text> : null}
  </View>;
}

export function EstibaEmpty({ title, description, action }: { title: string; description: string; action?: ReactNode }) {
  return <View style={s.empty}>
    <Text style={s.fieldLabel}>{title}</Text>
    <Text style={s.muted}>{description}</Text>
    {action}
  </View>;
}

export type EstibaProgressStep = {
  key: string; label: string; state: 'pending' | 'current' | 'complete' | 'return' | 'blocked';
};
const stepLabels: Record<EstibaProgressStep['state'], string> = {
  pending: 'Pendiente', current: 'Paso actual', complete: 'Completado', return: 'Retorno pendiente', blocked: 'En pausa',
};

export function EstibaProgress({ steps }: { steps: readonly EstibaProgressStep[] }) {
  return <View style={s.progress}>
    {steps.map((step, index) => <View key={step.key} accessible accessibilityLabel={`${index + 1}. ${step.label}. ${stepLabels[step.state]}`} style={s.progressStep}>
      <View style={[s.progressNumber, step.state === 'current' && s.currentNumber]}>
        <Text style={[s.body, step.state === 'current' && { color: t.color.onPrimary }]}>{index + 1}</Text>
      </View>
      <View style={s.stepCopy}>
        <Text style={[s.body, step.state === 'current' && s.strong]}>{step.label}</Text>
        <Text style={s.smallMuted}>{stepLabels[step.state]}</Text>
      </View>
    </View>)}
  </View>;
}

export type EstibaTableColumn = { key: string; label: string; width: number; numeric?: boolean };
export type EstibaTableRow = { key: string; cells: Readonly<Record<string, string | number>> };

export function EstibaTable({ caption, columns, rows }: {
  caption: string; columns: readonly EstibaTableColumn[]; rows: readonly EstibaTableRow[];
}) {
  return <View style={s.table}>
    <Text style={s.smallMuted}>{caption}</Text>
    <ScrollView horizontal nestedScrollEnabled accessibilityLabel={caption}>
      <View>
        <View style={[s.tableRow, s.tableHead]}>{columns.map((column) => <Text
          key={column.key} accessibilityRole="header"
          style={[s.cell, s.strong, { width: column.width, textAlign: column.numeric ? 'right' : 'left' }]}
        >{column.label}</Text>)}</View>
        {rows.map((row) => <View key={row.key} style={s.tableRow} accessible
          accessibilityLabel={columns.map((column) => `${column.label}: ${row.cells[column.key] ?? 'Sin registro'}`).join('. ')}
        >{columns.map((column) => <Text key={column.key}
          style={[s.cell, { width: column.width, textAlign: column.numeric ? 'right' : 'left' }]}
        >{row.cells[column.key] ?? 'Sin registro'}</Text>)}</View>)}
      </View>
    </ScrollView>
  </View>;
}

const s = StyleSheet.create({
  surface: { backgroundColor: t.color.canvas },
  body: { color: t.color.text, fontSize: t.fontSize.body, lineHeight: t.fontSize.body * t.lineHeight.body },
  muted: { color: t.color.muted, fontSize: t.fontSize.body, lineHeight: t.fontSize.body * t.lineHeight.body },
  smallMuted: { color: t.color.muted, fontSize: t.fontSize.small, lineHeight: t.fontSize.small * t.lineHeight.body },
  strong: { fontWeight: t.fontWeight.strong },
  heading: { flexDirection: 'row', flexWrap: 'wrap', alignItems: 'flex-start', justifyContent: 'space-between', gap: t.space[4] },
  headingCopy: { flexGrow: 1, flexShrink: 1, gap: t.space[2] },
  eyebrow: { color: t.color.muted, fontSize: t.fontSize.small, fontWeight: t.fontWeight.bold, letterSpacing: 1, textTransform: 'uppercase' },
  title: { color: t.color.text, fontSize: t.fontSize.title, lineHeight: t.fontSize.title * t.lineHeight.heading, fontWeight: t.fontWeight.bold },
  panel: { backgroundColor: t.color.surface, borderColor: t.color.border, borderWidth: 1, borderRadius: t.radius.panel },
  panelHeader: { flexDirection: 'row', flexWrap: 'wrap', alignItems: 'center', justifyContent: 'space-between', gap: t.space[3], borderBottomWidth: 1, borderBottomColor: t.color.border, padding: t.space[4] },
  panelTitle: { color: t.color.text, fontSize: t.fontSize.body, fontWeight: t.fontWeight.strong, flexShrink: 1 },
  panelBody: { padding: t.space[4], gap: t.space[4] },
  button: { minHeight: t.density.touch.control, paddingHorizontal: t.space[4], paddingVertical: t.space[3], borderRadius: t.radius.control, borderWidth: 1, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: t.space[2] },
  buttonLabel: { fontSize: t.fontSize.body, lineHeight: t.fontSize.body * t.lineHeight.body, fontWeight: t.fontWeight.strong, textAlign: 'center', flexShrink: 1 },
  focused: { borderWidth: 3, borderColor: t.color.focus },
  signal: { flexDirection: 'row', alignItems: 'center', gap: t.space[2] },
  marker: { width: 8, height: 8, borderWidth: 1 },
  signalText: { fontSize: t.fontSize.small, lineHeight: t.fontSize.small * t.lineHeight.body, fontWeight: t.fontWeight.strong, flexShrink: 1 },
  code: { color: t.color.text, fontSize: t.fontSize.body, fontWeight: t.fontWeight.strong, fontFamily: Platform.OS === 'ios' ? 'Menlo' : 'monospace', fontVariant: ['tabular-nums'] },
  codeLarge: { fontSize: t.fontSize.title },
  field: { gap: t.space[2] },
  fieldLabel: { color: t.color.text, fontSize: t.fontSize.body, lineHeight: t.fontSize.body * t.lineHeight.body, fontWeight: t.fontWeight.strong },
  input: { minHeight: t.density.touch.control, borderWidth: 1, borderColor: t.color.inputBorder, borderRadius: t.radius.control, backgroundColor: t.color.surface, color: t.color.text, paddingHorizontal: t.space[3], paddingVertical: t.space[2], fontSize: t.fontSize.body },
  multilineInput: { minHeight: 112, textAlignVertical: 'top' },
  fieldError: { color: t.signal.critical.text, fontSize: t.fontSize.small, lineHeight: t.fontSize.small * t.lineHeight.body },
  metric: { gap: t.space[2] },
  metricHeading: { flexDirection: 'row', flexWrap: 'wrap', justifyContent: 'space-between', gap: t.space[2] },
  metricValue: { color: t.color.text, fontSize: t.fontSize.body, fontWeight: t.fontWeight.bold, fontVariant: ['tabular-nums'] },
  meter: { height: 8, backgroundColor: t.color.disabledSurface, overflow: 'hidden' },
  alert: { padding: t.space[4], borderWidth: 1, borderLeftWidth: 4, borderRadius: t.radius.control, gap: t.space[2] },
  empty: { padding: t.space[4], gap: t.space[3] },
  progress: { gap: t.space[4] },
  progressStep: { flexDirection: 'row', alignItems: 'flex-start', gap: t.space[3] },
  progressNumber: { minWidth: 32, minHeight: 32, paddingHorizontal: t.space[1], justifyContent: 'center', alignItems: 'center', borderColor: t.color.inputBorder, borderWidth: 1, borderRadius: t.radius.control },
  currentNumber: { backgroundColor: t.color.primary, borderColor: t.color.primary },
  stepCopy: { flex: 1, gap: t.space[1] },
  table: { gap: t.space[3] },
  tableRow: { flexDirection: 'row', borderBottomWidth: 1, borderBottomColor: t.color.border },
  tableHead: { backgroundColor: t.color.subtle },
  cell: { color: t.color.text, minHeight: t.density.touch.row, padding: t.space[4], fontSize: t.fontSize.body, lineHeight: t.fontSize.body * t.lineHeight.body },
});
