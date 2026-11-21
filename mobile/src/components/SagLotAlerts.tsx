import { Text, View, StyleSheet } from 'react-native';
import { sagLotAlertText, sagLotOperationalAlerts, type SagLotAlert } from '../domain/sagLotHolds';
import { operatorTheme as o } from '../theme/operatorTheme';
import { font } from '../theme/typography';

export function SagLotAlerts({ alerts }: { alerts?: SagLotAlert[] }) {
  const active = sagLotOperationalAlerts(alerts);
  if (!active.length) return null;
  return <View accessibilityRole="alert" accessibilityLiveRegion="polite" style={styles.panel}>
    <Text style={styles.title}>SAG · Control de lotes</Text>
    {active.map((alert, index) => <Text key={alert.id + '-' + (alert.folio || index)} style={styles.text}>{sagLotAlertText(alert)}</Text>)}
    {active.some((alert) => alert.bloquea_salida || alert.todos_destinos) && (
      <Text style={styles.text}>Puedes continuar la movilización. Para confirmar la salida, revisa el destino del instructivo.</Text>
    )}
  </View>;
}
const styles = StyleSheet.create({
  panel: { margin: 12, padding: 14, gap: 8, borderWidth: 1, borderColor: o.color.warningBorder, backgroundColor: o.color.warningSurface },
  title: { color: o.color.warning, fontFamily: font.bold, fontSize: 16 },
  text: { color: o.color.text, fontFamily: font.regular, fontSize: 14, lineHeight: 20 },
});
