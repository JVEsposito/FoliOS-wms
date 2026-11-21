import type { ReactNode } from 'react';
import { StyleSheet, Text, View, useWindowDimensions } from 'react-native';

import { areas, type AreaKey } from '../../theme/areas';
import { operatorTheme as o } from '../../theme/operatorTheme';
import { font } from '../../theme/typography';
import { FieldCorner, FieldMark } from '../field/FieldMark';

type Props = {
  area?: AreaKey;
  connected: boolean;
  deviceName: string;
  modeLabel: string;
  navigation?: ReactNode;
  role: string;
  title?: string;
  /** Acciones al extremo derecho (p. ej. el menú del operador). Reemplaza el nombre de la tablet. */
  trailing?: ReactNode;
  userName: string;
};

/** Identidad, conexión y navegación del espacio de trabajo en una tarja. */
export function OperatorHeader({ area = 'frigorifico', connected, deviceName, modeLabel, navigation, role, title, trailing, userName }: Props) {
  const { width } = useWindowDimensions();
  const compact = width < o.breakpoint.compact;
  const definition = areas[area];
  const tone = connected ? o.color.success : o.color.warning;

  return (
    <View style={[styles.header, { borderColor: definition.line }]}>
      <FieldCorner area={area} />
      <View style={[styles.heading, compact && styles.headingCompact]}>
        <View style={styles.brandBlock}>
          <FieldMark area={area} />
          <View style={styles.titleBlock}>
            <Text accessibilityRole="header" numberOfLines={1} style={styles.title}>{title ?? definition.label}</Text>
            <Text numberOfLines={1} style={styles.subtitle}>{humanRole(role)} · {userName}</Text>
          </View>
        </View>
        <View style={[styles.end, compact && styles.endCompact]}>
          <View
            accessibilityLabel={`Estado de conexión: ${modeLabel}`}
            accessible
            style={[styles.pill, connected ? styles.pillOnline : styles.pillOffline]}
          >
            <View style={[styles.dot, { backgroundColor: tone }]} />
            <Text numberOfLines={1} style={[styles.pillText, { color: tone }]}>{modeLabel}</Text>
          </View>
          {trailing ?? <Text numberOfLines={1} style={styles.device}>{deviceName}</Text>}
        </View>
      </View>
      {navigation ? <View style={styles.navigation}>{navigation}</View> : null}
    </View>
  );
}

function humanRole(role: string) {
  const normalized = role.trim().replaceAll('_', ' ');
  return normalized ? normalized.replace(/^./, (letter) => letter.toUpperCase()) : 'Camarero';
}

const styles = StyleSheet.create({
  header: {
    marginHorizontal: 10,
    marginVertical: 6,
    backgroundColor: o.color.surface,
    borderWidth: 1,
    borderRadius: o.radius.panel,
    borderTopRightRadius: 3,
    overflow: 'hidden',
  },
  heading: {
    minHeight: 72,
    paddingHorizontal: o.space[4],
    paddingVertical: o.space[2],
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    gap: o.space[4],
    flexWrap: 'wrap',
  },
  headingCompact: {
    paddingHorizontal: o.space[3],
    gap: o.space[2],
  },
  brandBlock: {
    minWidth: 0,
    flexDirection: 'row',
    alignItems: 'center',
    gap: o.space[3],
    flexShrink: 1,
  },
  titleBlock: { minWidth: 0, flexShrink: 1 },
  title: {
    fontFamily: font.heavy,
    color: o.color.text,
    fontSize: o.type.heading,
  },
  subtitle: {
    fontFamily: font.regular,
    color: o.color.muted,
    fontSize: o.type.small,
    marginTop: 2,
  },
  navigation: { paddingHorizontal: o.space[3], borderTopWidth: 1, borderTopColor: o.color.border, backgroundColor: o.color.navy },
  end: { flexDirection: 'row', alignItems: 'center', justifyContent: 'flex-end', gap: o.space[3] },
  endCompact: { justifyContent: 'space-between' },
  pill: {
    minHeight: 36,
    paddingHorizontal: o.space[3],
    borderRadius: o.radius.control,
    borderWidth: 1,
    flexDirection: 'row',
    alignItems: 'center',
    gap: o.space[2],
  },
  pillOnline: { backgroundColor: 'transparent', borderColor: 'transparent' },
  pillOffline: { backgroundColor: o.color.warningSurface, borderColor: o.color.warningBorder },
  dot: { width: 10, height: 10, borderRadius: o.radius.pill },
  pillText: { fontFamily: font.bold, fontSize: o.type.small },
  device: { fontFamily: font.regular, maxWidth: 220, color: o.color.muted, fontSize: o.type.caption, textAlign: 'right' },
});
