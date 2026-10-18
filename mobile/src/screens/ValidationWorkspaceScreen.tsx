import { useState } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';

import { AuthSession } from '../domain/estiba';
import { areas } from '../theme/areas';
import { operatorTheme as o } from '../theme/operatorTheme';
import { RepalletizingScreen } from './RepalletizingScreen';
import { ValidationScreen } from './ValidationScreen';

type Props = {
  auth: AuthSession;
  baseUrl: string | null;
  onLogout: () => void;
};

type Section = 'validacion' | 'repaletizaje';

/**
 * Pestañas de Validación PT: subrayado con el color de Frigorífico en la activa, como las
 * pestañas de Oficina. Sin cajas rellenas.
 */
export function ValidationWorkspaceScreen({ auth, baseUrl, onLogout }: Props) {
  const [section, setSection] = useState<Section>('validacion');

  return (
    <View style={styles.screen}>
      <View accessibilityRole="tablist" style={styles.tabs}>
        <Pressable
          accessibilityRole="tab"
          accessibilityState={{ selected: section === 'validacion' }}
          onPress={() => setSection('validacion')}
          style={[styles.tab, section === 'validacion' && styles.tabActive]}
        >
          <Text style={[styles.tabText, section === 'validacion' && styles.tabTextActive]}>
            Validar folio
          </Text>
        </Pressable>
        <Pressable
          accessibilityRole="tab"
          accessibilityState={{ selected: section === 'repaletizaje', disabled: !baseUrl }}
          disabled={!baseUrl}
          onPress={() => setSection('repaletizaje')}
          style={[
            styles.tab,
            section === 'repaletizaje' && styles.tabActive,
            !baseUrl && styles.tabDisabled,
          ]}
        >
          <Text style={[styles.tabText, section === 'repaletizaje' && styles.tabTextActive]}>
            Repaletizajes
          </Text>
        </Pressable>
      </View>

      <View style={styles.content}>
        {section === 'repaletizaje' && baseUrl ? (
          <RepalletizingScreen auth={auth} baseUrl={baseUrl} onLogout={onLogout} />
        ) : (
          <ValidationScreen auth={auth} baseUrl={baseUrl} onLogout={onLogout} />
        )}
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  screen: { flex: 1, backgroundColor: o.color.canvas },
  tabs: {
    flexDirection: 'row',
    paddingHorizontal: 12,
    borderBottomWidth: 1,
    borderBottomColor: o.color.disabled,
    backgroundColor: o.color.navy,
  },
  tab: {
    flex: 1,
    minHeight: 52,
    alignItems: 'center',
    justifyContent: 'center',
    borderBottomWidth: 3,
    borderBottomColor: 'transparent',
  },
  tabActive: { borderBottomColor: areas.frigorifico.bright },
  tabDisabled: { opacity: 0.45 },
  tabText: { color: o.color.muted, fontSize: 16, fontWeight: '600' },
  tabTextActive: { color: o.color.text, fontWeight: '800' },
  content: { flex: 1 },
});
