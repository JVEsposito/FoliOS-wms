import type { ReactNode } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';

import { operatorTheme as o } from '../../theme/operatorTheme';

/**
 * Piezas de la Validación PT con el lenguaje de etiqueta de FoliOS v2: el formulario se
 * lee como la etiqueta del pallet (rótulo pequeño arriba, dato grande abajo, celdas
 * separadas por reglas) y la sesión como una tira de talones.
 *
 * El estado nunca depende solo del color: cada tono lleva su forma (círculo aprobado,
 * rombo observado o pendiente, cuadrado rechazo o conflicto) y su texto.
 */
export type LabelTone = 'neutral' | 'success' | 'warning' | 'critical';

const toneText: Record<LabelTone, string> = {
  neutral: o.color.textSecondary,
  success: o.color.success,
  warning: o.color.warning,
  critical: o.color.critical,
};
const toneSurface: Record<LabelTone, string> = {
  neutral: o.color.surfaceMuted,
  success: o.color.successSurface,
  warning: o.color.warningSurface,
  critical: o.color.criticalSurface,
};
const toneBorder: Record<LabelTone, string> = {
  neutral: o.color.borderStrong,
  success: o.color.successBorder,
  warning: o.color.warningBorder,
  critical: o.color.criticalBorder,
};

export function toneColor(tone: LabelTone) {
  return toneText[tone];
}

/** Forma del estado. `neutral` es un círculo hueco. */
export function SignalMark({ tone, size = 10 }: { tone: LabelTone; size?: number }) {
  const color = tone === 'neutral' ? o.color.muted : toneText[tone];
  if (tone === 'warning') {
    const side = Math.round(size * 0.72);
    return (
      <View style={[styles.mark, { width: size, height: size }]}>
        <View style={{ width: side, height: side, backgroundColor: color, transform: [{ rotate: '45deg' }] }} />
      </View>
    );
  }
  if (tone === 'critical') {
    const side = Math.round(size * 0.84);
    return <View style={[styles.mark, { width: size, height: size }]}><View style={{ width: side, height: side, backgroundColor: color }} /></View>;
  }
  return (
    <View style={[
      { width: size, height: size, borderRadius: size / 2 },
      tone === 'neutral' ? { borderWidth: 1.5, borderColor: color } : { backgroundColor: color },
    ]} />
  );
}

/** Píldora de estado: forma, texto y color del tono. */
export function StatusChip({ tone, label, small = false }: { tone: LabelTone; label: string; small?: boolean }) {
  return (
    <View
      accessibilityLabel={label}
      accessible
      style={[styles.chip, small && styles.chipSmall, { backgroundColor: toneSurface[tone], borderColor: toneBorder[tone] }]}
    >
      <SignalMark size={small ? 9 : 11} tone={tone} />
      <Text numberOfLines={1} style={[styles.chipText, small && styles.chipTextSmall, { color: toneText[tone] }]}>{label}</Text>
    </View>
  );
}

/** Bloque numerado de la etiqueta: franja con número y título, y debajo sus celdas. */
export function LabelSection({ index, title, hint, aside, children }: {
  index: number;
  title: string;
  hint?: string;
  aside?: ReactNode;
  children: ReactNode;
}) {
  return (
    <View style={styles.section}>
      <View style={styles.strip}>
        <Text style={styles.stripIndex}>{index}</Text>
        <Text style={styles.stripTitle}>{title}</Text>
        <View style={styles.stripSpacer} />
        {hint ? <Text numberOfLines={1} style={styles.stripHint}>{hint}</Text> : null}
        {aside}
      </View>
      {children}
    </View>
  );
}

/** Fila de celdas. La última fila de un bloque no lleva regla inferior. */
export function LabelRow({ children, last = false }: { children: ReactNode; last?: boolean }) {
  return <View style={[styles.row, last && styles.rowLast]}>{children}</View>;
}

/** Celda con rótulo arriba y el dato debajo. `divider` traza la regla a la derecha. */
export function LabelCell({ label, children, flex = 1, divider = false, hint, width }: {
  label?: string;
  children: ReactNode;
  flex?: number;
  divider?: boolean;
  hint?: string;
  width?: number;
}) {
  return (
    <View style={[styles.cell, width ? { width, flexGrow: 0, flexShrink: 0 } : { flex }, divider && styles.cellDivider]}>
      {label ? <Text numberOfLines={1} style={styles.cellLabel}>{label}</Text> : null}
      {children}
      {hint ? <Text style={styles.cellHint}>{hint}</Text> : null}
    </View>
  );
}

/** Contadores de la sesión en una sola línea: sin color mientras valen cero. */
export function LabelCounters({ items, compact = false }: { items: { label: string; value: number; tone: LabelTone }[]; compact?: boolean }) {
  return (
    <View style={styles.counters}>
      {items.map((item, index) => {
        const active = item.value > 0 && item.tone !== 'neutral';
        return (
          <View
            accessibilityLabel={`${item.label}: ${item.value}`}
            accessible
            key={item.label}
            style={[styles.counter, index < items.length - 1 && styles.cellDivider]}
          >
            <Text numberOfLines={1} style={[styles.counterLabel, compact && styles.counterLabelCompact]}>{item.label}</Text>
            <View style={styles.counterValueRow}>
              {item.tone !== 'neutral' ? <SignalMark size={9} tone={active ? item.tone : 'neutral'} /> : null}
              <Text style={[styles.counterValue, active && { color: toneText[item.tone] }]}>{item.value}</Text>
            </View>
          </View>
        );
      })}
    </View>
  );
}

/**
 * Talón de un pallet trabajado: la hora en el talón separado por línea punteada, el folio
 * en mono y el estado a la derecha. Si trae una acción propia (Retomar) no es presionable
 * entero, para que el toque llegue al botón correcto.
 */
export function TalonRow({ time, folio, detail, message, aside, onPress, disabled = false, tone }: {
  time: string;
  folio: string;
  detail: string;
  message?: string | null;
  aside?: ReactNode;
  onPress?: () => void;
  disabled?: boolean;
  tone?: LabelTone;
}) {
  const body = (
    <>
      <View style={styles.talonStub}><Text style={styles.talonTime}>{time}</Text></View>
      <View style={styles.talonBody}>
        <Text numberOfLines={1} style={styles.talonFolio}>{folio}</Text>
        <Text numberOfLines={2} style={styles.talonDetail}>{detail}</Text>
        {message ? <Text style={styles.talonMessage}>{message}</Text> : null}
      </View>
      {aside ? <View style={styles.talonAside}>{aside}</View> : null}
    </>
  );
  const style = [styles.talon, tone && tone !== 'neutral' && { backgroundColor: toneSurface[tone] }];
  return onPress
    ? <Pressable accessibilityLabel={`Consultar ${folio}`} disabled={disabled} onPress={onPress} style={style}>{body}</Pressable>
    : <View style={style}>{body}</View>;
}

/**
 * Talón del último pallet registrado: sello con el resultado, folio grande, datos de la
 * etiqueta y, bajo la línea de corte, si quedó confirmado o guardado en el equipo.
 */
export function ResultTalon({ tone, title, time, folio, rows, footer, footerTone, onDismiss }: {
  tone: LabelTone;
  title: string;
  time: string;
  folio: string;
  rows: { label: string; value: string; mono?: boolean }[];
  footer: string;
  footerTone: LabelTone;
  onDismiss?: () => void;
}) {
  return (
    <View accessibilityLabel={`Último resultado: ${folio} ${title.toLowerCase()}`} style={[styles.result, { borderColor: toneBorder[tone] }]}>
      <View style={[styles.resultBand, { backgroundColor: toneSurface[tone], borderBottomColor: toneBorder[tone] }]}>
        <SignalMark size={16} tone={tone} />
        <Text style={[styles.resultTitle, { color: toneText[tone] }]}>{title}</Text>
        <Text numberOfLines={1} style={styles.resultTime}>Registrado · {time}</Text>
        {onDismiss ? (
          <Pressable accessibilityLabel="Cerrar el último resultado" onPress={onDismiss} style={styles.resultClose}>
            <Text style={styles.resultCloseText}>×</Text>
          </Pressable>
        ) : null}
      </View>
      <View style={styles.resultBody}>
        <Text numberOfLines={1} style={styles.resultFolio}>{folio}</Text>
        <View style={styles.resultGrid}>
          {rows.map((row) => (
            <View key={row.label} style={styles.resultItem}>
              <Text style={styles.cellLabel}>{row.label}</Text>
              <Text numberOfLines={2} style={[styles.resultValue, row.mono && styles.resultValueMono]}>{row.value}</Text>
            </View>
          ))}
        </View>
      </View>
      <View style={styles.resultFoot}>
        <SignalMark size={10} tone={footerTone} />
        <Text style={[styles.resultFootText, { color: footerTone === 'neutral' ? o.color.textSecondary : toneText[footerTone] }]}>{footer}</Text>
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  mark: { alignItems: 'center', justifyContent: 'center' },
  chip: {
    minHeight: 30,
    flexDirection: 'row',
    alignItems: 'center',
    alignSelf: 'flex-start',
    gap: 6,
    paddingLeft: 9,
    paddingRight: 11,
    borderRadius: o.radius.pill,
    borderWidth: 1,
  },
  chipSmall: { minHeight: 26, gap: 5, paddingLeft: 8, paddingRight: 10 },
  chipText: { fontSize: 14, fontWeight: '700' },
  chipTextSmall: { fontSize: 12 },
  section: { borderBottomWidth: 2, borderBottomColor: o.color.border },
  strip: {
    minHeight: 30,
    flexDirection: 'row',
    alignItems: 'center',
    gap: 10,
    paddingHorizontal: 16,
    paddingVertical: 5,
    backgroundColor: o.color.surfaceMuted,
    borderBottomWidth: 1,
    borderBottomColor: o.color.disabled,
  },
  stripIndex: { color: o.color.muted, fontFamily: o.font.mono, fontSize: 13, fontWeight: '700' },
  stripTitle: { color: o.color.textSecondary, fontSize: 13, fontWeight: '700' },
  stripSpacer: { flex: 1 },
  stripHint: { flexShrink: 1, color: o.color.muted, fontSize: 13 },
  row: { flexDirection: 'row', alignItems: 'stretch', borderBottomWidth: 1, borderBottomColor: o.color.disabled },
  rowLast: { borderBottomWidth: 0 },
  cell: { minWidth: 0, paddingHorizontal: 16, paddingTop: 10, paddingBottom: 10, gap: 6 },
  cellDivider: { borderRightWidth: 1, borderRightColor: o.color.disabled },
  cellLabel: { color: o.color.muted, fontSize: 13, fontWeight: '600' },
  cellHint: { color: o.color.muted, fontSize: 13 },
  counters: { flexDirection: 'row', alignItems: 'stretch' },
  counter: { flex: 1, minWidth: 0, gap: 4, paddingHorizontal: 8, paddingVertical: 10 },
  counterValueRow: { flexDirection: 'row', alignItems: 'center', gap: 6 },
  counterLabel: { color: o.color.muted, fontSize: 12, fontWeight: '600' },
  counterLabelCompact: { fontSize: 11 },
  counterValue: { color: o.color.textSecondary, fontFamily: o.font.mono, fontSize: 26, fontWeight: '700', letterSpacing: -1 },
  talon: {
    minHeight: 60,
    flexDirection: 'row',
    alignItems: 'stretch',
    borderBottomWidth: 1,
    borderBottomColor: o.color.disabled,
  },
  talonStub: {
    width: 64,
    alignItems: 'center',
    justifyContent: 'center',
    borderRightWidth: 1,
    borderRightColor: o.color.borderStrong,
  },
  talonTime: { color: o.color.muted, fontFamily: o.font.mono, fontSize: 14, fontWeight: '600' },
  talonBody: { flex: 1, minWidth: 0, justifyContent: 'center', gap: 2, paddingHorizontal: 12, paddingVertical: 8 },
  talonFolio: { color: o.color.text, fontFamily: o.font.mono, fontSize: 18, fontWeight: '700', letterSpacing: -0.5 },
  talonDetail: { color: o.color.muted, fontSize: 13 },
  talonMessage: { color: o.color.critical, fontSize: 13, marginTop: 2 },
  talonAside: { alignItems: 'flex-end', justifyContent: 'center', gap: 6, paddingRight: 12, paddingVertical: 8 },
  result: { overflow: 'hidden', borderRadius: 12, borderWidth: 2, backgroundColor: o.color.surface },
  resultBand: { minHeight: 48, flexDirection: 'row', alignItems: 'center', gap: 10, paddingLeft: 14, borderBottomWidth: 1 },
  resultTitle: { fontSize: 19, fontWeight: '800' },
  resultTime: { flex: 1, color: o.color.textSecondary, fontSize: 13, textAlign: 'right' },
  resultClose: { minHeight: 48, minWidth: 48, alignItems: 'center', justifyContent: 'center' },
  resultCloseText: { color: o.color.textSecondary, fontSize: 22, fontWeight: '700' },
  resultBody: { gap: 12, paddingHorizontal: 14, paddingVertical: 12 },
  resultFolio: { color: o.color.text, fontFamily: o.font.mono, fontSize: 30, fontWeight: '700', letterSpacing: -1 },
  resultGrid: { flexDirection: 'row', flexWrap: 'wrap', rowGap: 10 },
  resultItem: { width: '50%', minWidth: 0, gap: 3, paddingRight: 10 },
  resultValue: { color: o.color.text, fontSize: 15, fontWeight: '600' },
  resultValueMono: { fontFamily: o.font.mono, fontWeight: '700', letterSpacing: -0.5 },
  resultFoot: { minHeight: 40, flexDirection: 'row', alignItems: 'center', gap: 8, paddingHorizontal: 14, paddingVertical: 8, borderTopWidth: 1, borderTopColor: o.color.borderStrong },
  resultFootText: { flex: 1, fontSize: 14, fontWeight: '600' },
});
