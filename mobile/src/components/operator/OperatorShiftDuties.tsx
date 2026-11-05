import { StyleSheet, Text, View } from 'react-native';
import type { OperationalShiftContext } from '../../domain/operationalTasks';
import { operatorTheme as o } from '../../theme/operatorTheme';
import { font } from '../../theme/typography';

const names: Record<string, string> = {
  recepcion_tuneles: 'Salidas de túnel', recepcion_packing: 'Recepción de packing', separacion_cargas: 'Separación de cargas',
  preparacion_sag: 'Preparación SAG', recepcion_repa: 'Recepción de repaletizaje', reingreso_camara: 'Reingreso a cámara', apoyo_camaras: 'Apoyo en cámaras',
};
export function OperatorShiftDuties({ context }: { context: OperationalShiftContext | null }) {
  if (!context || (!context.turno && !context.labor_actual && !context.en_espera.length)) return null;
  return <View style={styles.card}>
    <Text accessibilityRole="header" style={styles.title}>{context.turno?.nombre || 'Mi labor en curso'}</Text>
    {context.funciones ? <Text style={styles.copy}>Principal: {context.funciones.principal_nombre}</Text> : null}
    {context.funciones?.apoyos.length ? <Text style={styles.copy}>Apoyos: {context.funciones.apoyos.map((code) => names[code] || code).join(' → ')}</Text> : null}
    {!context.disponible ? <Text style={styles.copy}>No disponible para nuevas labores. Termina la maniobra tomada y solicita habilitación en Oficina.</Text> : null}
    {context.labor_actual ? <Text style={styles.copy}>Continuar: {context.labor_actual.titulo}</Text> : null}
    {context.en_espera.slice(0, 3).map((labor) => <View key={labor.id}>
      <Text style={styles.wait}>En espera: {labor.titulo}</Text>
      <Text style={styles.copy}>{labor.motivo}</Text>
    </View>)}
  </View>;
}
const styles = StyleSheet.create({
  card: { padding: 16, gap: 8, borderRadius: 12, borderWidth: 1, borderColor: o.color.primary, marginBottom: 12 },
  title: { fontFamily: font.bold, fontSize: 18, color: o.color.text },
  copy: { fontFamily: font.regular, fontSize: 15, lineHeight: 22, color: o.color.text },
  wait: { fontFamily: font.semibold, fontSize: 16, lineHeight: 22, color: o.color.text },
});
