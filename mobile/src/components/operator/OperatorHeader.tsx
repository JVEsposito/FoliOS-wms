import type { ReactNode } from 'react';
import { Image, StyleSheet, Text, View, useWindowDimensions } from 'react-native';

import { areas, type AreaKey } from '../../theme/areas';
import { operatorTheme as o } from '../../theme/operatorTheme';

type Props = {
  area?: AreaKey;
  connected: boolean;
  deviceName: string;
  modeLabel: string;
  navigation?: ReactNode;
  role: string;
  title?: string;
  userName: string;
};

/**
 * Encabezado de terreno FoliOS v2: gajo del área, título angosto, persona y estado de
 * conexión; regla inferior de 3 px con el color del área. Ver
 * docs/diseno/folios-v2/pr-07-movil-tema.md.
 */
export function OperatorHeader({ area = 'frigorifico', connected, deviceName, modeLabel, navigation, role, title, userName }: Props) {
  const { width } = useWindowDimensions();
  const compact = width < o.breakpoint.compact;
  const definition = areas[area];

  return (
    <View style={[styles.header, { borderBottomColor: definition.bright }, compact && styles.headerCompact]}>
      <View style={styles.brandBlock}>
        <Image accessibilityLabel={`FoliOS, ${definition.label}`} accessible resizeMode="contain" source={definition.gajo} style={styles.gajo} />
        <View style={styles.titleBlock}>
          <Text numberOfLines={1} style={styles.title}>{title ?? definition.label}</Text>
          <Text numberOfLines={1} style={styles.subtitle}>{userName}, {humanRole(role).toLowerCase()}</Text>
        </View>
        <View
          accessibilityLabel={`Estado de conexión: ${modeLabel}`}
          accessible
          style={[styles.pill, connected ? styles.pillOnline : styles.pillOffline]}
        >
          <Text style={[styles.pillMark, { color: connected ? o.color.success : o.color.warning }]}>{connected ? '✓' : '◆'}</Text>
          <Text style={[styles.pillText, { color: connected ? o.color.success : o.color.warning }]}>{modeLabel}</Text>
        </View>
      </View>
      {navigation ? <View style={styles.navigation}>{navigation}</View> : null}
      <Text numberOfLines={1} style={[styles.device, compact && styles.deviceCompact]}>{deviceName}</Text>
    </View>
  );
}

function humanRole(role: string) {
  const normalized = role.trim().replaceAll('_', ' ');
  return normalized ? normalized.replace(/^./, (letter) => letter.toUpperCase()) : 'Camarero';
}

const styles = StyleSheet.create({
  header: {
    minHeight: 76,
    paddingHorizontal: o.space[4],
    paddingVertical: o.space[2],
    backgroundColor: o.color.navy,
    borderBottomWidth: 3,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    gap: o.space[4],
    flexWrap: 'wrap',
  },
  headerCompact: {
    alignItems: 'stretch',
    flexDirection: 'column',
    gap: o.space[2],
  },
  brandBlock: {
    minWidth: 0,
    flexDirection: 'row',
    alignItems: 'center',
    gap: o.space[3],
    flexShrink: 1,
  },
  gajo: { width: 38, height: 38 },
  titleBlock: { minWidth: 0, flexShrink: 1 },
  title: {
    color: o.color.text,
    fontFamily: o.font.condensed,
    fontSize: o.type.heading,
    fontWeight: '800',
  },
  subtitle: {
    color: o.color.muted,
    fontSize: o.type.small,
    marginTop: 2,
  },
  pill: {
    minHeight: 36,
    paddingHorizontal: o.space[3],
    borderRadius: o.radius.pill,
    borderWidth: 1,
    flexDirection: 'row',
    alignItems: 'center',
    gap: o.space[2],
  },
  pillOnline: { backgroundColor: o.color.successSurface, borderColor: o.color.successBorder },
  pillOffline: { backgroundColor: o.color.warningSurface, borderColor: o.color.warningBorder },
  pillMark: { fontSize: o.type.caption, fontWeight: '900' },
  pillText: { fontSize: o.type.small, fontWeight: '700' },
  navigation: { flexDirection: 'row', flexWrap: 'wrap', alignItems: 'center', justifyContent: 'center', flexGrow: 1, gap: 8 },
  device: { maxWidth: 220, color: o.color.muted, fontSize: o.type.caption, textAlign: 'right' },
  deviceCompact: { maxWidth: undefined, textAlign: 'left' },
});
