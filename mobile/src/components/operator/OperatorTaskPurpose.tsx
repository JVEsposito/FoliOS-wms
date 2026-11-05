import { StyleSheet, Text, View } from 'react-native';

import type { OperationalTask } from '../../domain/operationalTasks';
import { operatorTaskPurpose } from '../../domain/operatorTaskPurpose';
import { operatorTheme as o } from '../../theme/operatorTheme';
import { font } from '../../theme/typography';

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
  caption: { fontFamily: font.heavy, color: o.color.primary, fontSize: o.type.caption },
  title: { fontFamily: font.heavy, color: o.color.text, fontSize: o.type.heading },
  goal: { fontFamily: font.bold, color: o.color.text, fontSize: o.type.body },
  reason: { fontFamily: font.regular, color: o.color.textSecondary, fontSize: o.type.small },
  step: { fontFamily: font.bold, color: o.color.primaryPressed, fontSize: o.type.body },
});
