import { Modal, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';

import { operatorTheme as o } from '../../theme/operatorTheme';

type Props = {
  message: string;
  onAcknowledge: () => void;
};

/** Los errores operacionales requieren lectura; un refresco no los descarta. */
export function OperatorErrorDialog({ message, onAcknowledge }: Props) {
  if (!message) return null;

  return (
    <Modal animationType="fade" transparent visible onRequestClose={onAcknowledge}>
      <View style={styles.backdrop}>
        <View accessibilityViewIsModal style={styles.dialog}>
          <Text accessibilityRole="header" style={styles.title}>No se pudo completar la operación</Text>
          <ScrollView style={styles.body} contentContainerStyle={styles.content}>
            <Text selectable accessibilityLiveRegion="assertive" style={styles.message}>{message}</Text>
          </ScrollView>
          <Pressable accessibilityRole="button" onPress={onAcknowledge} style={styles.button}>
            <Text style={styles.buttonText}>Entendido</Text>
          </Pressable>
        </View>
      </View>
    </Modal>
  );
}

const styles = StyleSheet.create({
  backdrop: { flex: 1, justifyContent: 'center', alignItems: 'center', padding: o.space[4], backgroundColor: 'rgba(7,24,33,0.72)' },
  dialog: { width: '100%', maxWidth: 620, maxHeight: '85%', backgroundColor: o.color.surface, borderRadius: o.radius.panel, borderWidth: 2, borderColor: o.color.critical, padding: o.space[4], gap: o.space[3] },
  title: { color: o.color.critical, fontSize: o.type.heading, fontWeight: '900' },
  body: { flexShrink: 1 },
  content: { paddingVertical: o.space[2] },
  message: { color: o.color.text, fontSize: o.type.body, lineHeight: 25 },
  button: { minHeight: o.touch.prominent, alignItems: 'center', justifyContent: 'center', backgroundColor: o.color.primary, borderRadius: o.radius.control },
  buttonText: { color: o.color.onPrimary, fontSize: o.type.body, fontWeight: '900' },
});
