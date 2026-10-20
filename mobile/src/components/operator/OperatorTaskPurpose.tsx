import { StyleSheet, Text, View } from 'react-native';

import type { OperationalTask } from '../../domain/operationalTasks';
import { operatorTaskPurpose } from '../../domain/operatorTaskPurpose';
import { operatorTheme as o } from '../../theme/operatorTheme';

export function OperatorTaskPurpose({ task }: { task: OperationalTask }) {
  const purpose = operatorTaskPurpose(task);
  return (
    <View style={styles.panel}>
      <Text style={styles.caption}>PARA QUÉ ES ESTE MOVIMIENTO</Text>
      <Text accessibilityRole="header" style={styles.title}>{purpose.label}</Text>
      <Text style={styles.goal}>{purpose.goal}</Text>
      {purpose.reason ? <Text style={styles.reason}>{purpose.reason}</Text> : null}
      <Text style={styles.step}>Este paso: {purpose.step}</Text>
      {purpose.otherGoals.length ? <Text style={styles.reason}>También contribuye a: {purpose.otherGoals.join(' · ')}</Text> : null}
    </View>
  );
}

const styles = StyleSheet.create({
  panel: { backgroundColor: o.color.surface, borderWidth: 1, borderColor: o.color.infoBorder, borderLeftWidth: 4, borderLeftColor: o.color.primary, borderRadius: o.radius.panel, padding: 16, gap: 6 },
  caption: { color: o.color.primary, fontSize: o.type.caption, fontWeight: '800' },
  title: { color: o.color.text, fontSize: o.type.heading, fontWeight: '800' },
  goal: { color: o.color.text, fontSize: o.type.body, fontWeight: '700' },
  reason: { color: o.color.textSecondary, fontSize: o.type.small },
  step: { color: o.color.primaryPressed, fontSize: o.type.body, fontWeight: '700' },
});
