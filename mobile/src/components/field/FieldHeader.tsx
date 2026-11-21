import type { ReactNode } from 'react';
import { StyleSheet, Text, View, useWindowDimensions } from 'react-native';
import type { AuthSession } from '../../domain/estiba';
import { areas, type AreaKey } from '../../theme/areas';
import { operatorTheme as o } from '../../theme/operatorTheme';
import { font } from '../../theme/typography';
import { OperatorMenu } from '../operator/OperatorMenu';
import { FieldCorner, FieldMark } from './FieldMark';
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
  return <View style={[styles.header, { borderColor: definition.line }]}>
    <FieldCorner area={area} />
    <View style={styles.heading}>
      <FieldMark area={area} />
      <View style={styles.copy}>
        <Text accessibilityRole="header" numberOfLines={2} style={[styles.title, compact && styles.titleCompact]}>{title}</Text>
        {subtitle && !compact ? <Text numberOfLines={1} style={styles.subtitle}>{subtitle}</Text> : null}
      </View>
      {children ? <View style={styles.actions}>{children}</View> : null}
      <OperatorMenu disabled={busy} deviceName={identity?.dispositivo.nombre ?? ''}
        onChangeModule={changeModule ? () => leave(changeModule, 'module') : undefined} onLogout={() => leave(onLogout, 'logout')}
        role={identity?.usuario.rol ?? ''} userName={identity?.usuario.nombre ?? 'Operador'} />
    </View>
    {(compact && subtitleCompact && subtitle) || offline ? <View style={styles.context}>
      {compact && subtitleCompact && subtitle ? <Text numberOfLines={2} style={styles.subtitle}>{subtitle}</Text> : null}
      {offline ? <Text accessibilityLiveRegion="polite" style={styles.offline}>Sin conexión</Text> : null}
    </View> : null}
  </View>;
}

const styles = StyleSheet.create({
  header: { marginHorizontal: 10, marginVertical: 6, borderWidth: 1, borderRadius: o.radius.panel, borderTopRightRadius: 3, backgroundColor: o.color.surface, overflow: 'hidden' },
  heading: { minHeight: 64, flexDirection: 'row', alignItems: 'center', gap: 9, paddingHorizontal: 10, paddingVertical: 9 },
  context: { flexDirection: 'row', flexWrap: 'wrap', justifyContent: 'space-between', gap: 8, paddingHorizontal: 12, paddingVertical: 7, borderTopWidth: 1, borderColor: o.color.border, backgroundColor: o.color.navy },
  copy: { flex: 1, minWidth: 0 },
  title: { fontFamily: font.heavy, color: o.color.text, fontSize: 26, lineHeight: 30 },
  titleCompact: { fontSize: 19, lineHeight: 23 },
  subtitle: { flexShrink: 1, fontFamily: font.regular, color: o.color.muted, fontSize: 13, marginTop: 2 },
  offline: { fontFamily: font.bold, color: o.color.warning, fontSize: 13 },
  actions: { flexDirection: 'row', alignItems: 'center', gap: 8 },
});
