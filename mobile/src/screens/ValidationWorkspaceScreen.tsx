import { useState } from 'react';
import { StyleSheet, View } from 'react-native';

import { AuthSession } from '../domain/estiba';
import { FieldTabs } from '../components/field/FieldTabs';
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
      <FieldTabs<Section> area="frigorifico" current={section} onSelect={setSection} options={[
        { key: 'validacion', label: 'Validar folio' },
        { key: 'repaletizaje', label: 'Repaletizajes', disabled: !baseUrl },
      ]} />

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
  content: { flex: 1 },
});
