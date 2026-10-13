import { StyleSheet, Text, View } from 'react-native';

import type { OperationalTaskPriority } from '../../domain/operationalTasks';
import { operatorTheme as o, type OperatorTone } from '../../theme/operatorTheme';

const tonePalette: Record<OperatorTone, { background: string; border: string; text: string }> = {
  neutral: { background: o.color.surfaceMuted, border: o.color.border, text: o.color.muted },
  info: { background: o.color.selected, border: o.color.infoBorder, text: o.color.primary },
  success: { background: o.color.successSurface, border: o.color.successBorder, text: o.color.success },
  warning: { background: o.color.warningSurface, border: o.color.warningBorder, text: o.color.warning },
  critical: { background: o.color.criticalSurface, border: o.color.criticalBorder, text: o.color.critical },
};

export function OperatorStatusBadge({ label, tone = 'neutral' }: { label: string; tone?: OperatorTone }) {
  const palette = tonePalette[tone];
  return (
    <View style={[styles.badge, { backgroundColor: palette.background, borderColor: palette.border }]}>
      <View style={[styles.marker, { backgroundColor: palette.text }]} />
      <Text style={[styles.badgeText, { color: palette.text }]}>{label}</Text>
    </View>
  );
}

export function OperatorPriorityBadge({ priority }: { priority: OperationalTaskPriority }) {
  const critical = priority === 'critica' || priority === 'urgente';
  const high = priority === 'alta';
  return (
    <OperatorStatusBadge
      label={priority === 'critica' ? 'CRÍTICA' : priority === 'urgente' ? 'URGENTE' : high ? 'ALTA' : 'NORMAL'}
      tone={critical ? 'critical' : high ? 'warning' : 'info'}
    />
  );
}

export function OperatorEntityCode({ value, prominent = false }: { value: string; prominent?: boolean }) {
  return <Text selectable style={[styles.code, prominent && styles.codeProminent]}>{value}</Text>;
}

export function OperatorRouteLine({ label, strong = false, value }: {
  label: string;
  strong?: boolean;
  value: string;
}) {
  return (
    <View accessible accessibilityLabel={`${label}: ${value}`} style={styles.route}>
      <Text style={styles.routeLabel}>{label}</Text>
      <Text numberOfLines={2} style={[styles.routeValue, strong && styles.routeValueStrong]}>{value}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  badge: {
    minHeight: 32,
    paddingHorizontal: o.space[2],
    paddingVertical: o.space[1],
    borderRadius: o.radius.pill,
    borderWidth: 1,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: o.space[2],
  },
  marker: { width: 8, height: 8, borderRadius: 4 },
  badgeText: { fontSize: o.type.caption, fontWeight: '900', letterSpacing: 0.3 },
  code: {
    color: o.color.text,
    fontFamily: o.font.mono,
    fontSize: o.type.body,
    fontWeight: '800',
    fontVariant: ['tabular-nums'],
  },
  codeProminent: { color: o.color.primary, fontSize: o.type.folio, fontWeight: '800', letterSpacing: -1 },
  route: { minHeight: 36, flexDirection: 'row', alignItems: 'flex-start', gap: o.space[3] },
  routeLabel: { width: 70, color: o.color.muted, fontSize: o.type.small, fontWeight: '700' },
  routeValue: { flex: 1, color: o.color.text, fontSize: o.type.small, lineHeight: 20 },
  routeValueStrong: { color: o.color.primary, fontWeight: '800' },
});
