import type { ReactNode } from 'react';
import { Image, StyleSheet, Text, View, useWindowDimensions } from 'react-native';
import type { AuthSession } from '../../domain/estiba';
import { areas, type AreaKey } from '../../theme/areas';
import { operatorTheme as o } from '../../theme/operatorTheme';
import { font } from '../../theme/typography';
import { OperatorMenu } from '../operator/OperatorMenu';
import { useFieldNavigation } from './FieldNavigation';

export type FieldLeaveTarget = 'module' | 'logout';

/** Una cabecera de terreno: módulo y acción contextual; identidad y salida en el menú. */
export function FieldHeader({ area, auth, busy = false, children, offline = false, onBeforeLeave, onLogout, subtitle, subtitleCompact = true, title }: {
  area: AreaKey; auth?: AuthSession; busy?: boolean; children?: ReactNode;
  onBeforeLeave?: (proceed: () => void, target: FieldLeaveTarget) => void;
  offline?: boolean; onLogout: () => void; subtitle?: string; subtitleCompact?: boolean; title: string;
}) {
  const navigation = useFieldNavigation();
  const identity = auth ?? navigation.auth;
  const compact = useWindowDimensions().width < 720;
  const definition = areas[area];
  const changeModule = navigation.onChangeModule;
  function leave(proceed: () => void, target: FieldLeaveTarget) {
    if (busy) return;
    if (onBeforeLeave) onBeforeLeave(proceed, target);
    else proceed();
  }
  return <View style={[styles.header, { borderBottomColor: definition.bright }]}>
    <Image accessible accessibilityLabel={`FoliOS · ${definition.label}`} source={definition.gajo} resizeMode="contain" style={styles.gajo} />
    <View style={styles.copy}>
      <Text accessibilityRole="header" numberOfLines={2} style={styles.title}>{title}</Text>
      {subtitle && (!compact || subtitleCompact) ? <Text numberOfLines={1} style={styles.subtitle}>{subtitle}</Text> : null}
      {offline ? <Text accessibilityLiveRegion="polite" style={styles.offline}>Sin conexión</Text> : null}
    </View>
    <View style={styles.actions}>{children}</View>
    <OperatorMenu disabled={busy} deviceName={identity?.dispositivo.nombre ?? ''}
      onChangeModule={changeModule ? () => leave(changeModule, 'module') : undefined} onLogout={() => leave(onLogout, 'logout')}
      role={identity?.usuario.rol ?? ''} userName={identity?.usuario.nombre ?? 'Operador'} />
  </View>;
}

const styles = StyleSheet.create({
  header: { minHeight: 64, flexDirection: 'row', alignItems: 'center', gap: 10, paddingHorizontal: 12, paddingVertical: 8, borderBottomWidth: 3, backgroundColor: o.color.navy },
  gajo: { width: 30, height: 30 },
  copy: { flex: 1, minWidth: 0 },
  title: { fontFamily: font.heavy, color: o.color.text, fontSize: 20, lineHeight: 24 },
  subtitle: { fontFamily: font.regular, color: o.color.muted, fontSize: 13, marginTop: 2 },
  offline: { fontFamily: font.bold, color: o.color.warning, fontSize: 13, marginTop: 2 },
  actions: { flexDirection: 'row', alignItems: 'center', gap: 8 },
});
