import { Pressable, StyleSheet, Text, View } from 'react-native';

import { cameraDisplayName } from '../domain/cameras';
import { CameraSummary } from '../domain/estiba';
import { colors } from '../theme/colors';
import { font } from '../theme/typography';

type CameraCardProps = {
  camera: CameraSummary;
  selected: boolean;
  onPress: () => void;
};

export function CameraCard({ camera, selected, onPress }: CameraCardProps) {
  const ownSession = camera.acceso.modo === 'edicion' && camera.acceso.sesion?.es_propia;
  const locked = camera.acceso.modo === 'solo_lectura';
  const readOnly = camera.contenido === 'materia_prima';
  const status = readOnly ? 'Solo consulta' : ownSession ? 'Edición propia' : locked ? 'En uso' : 'Disponible';
  const statusColor = readOnly ? colors.muted : ownSession ? colors.cyan : locked ? colors.amber : colors.green;
  const contentLabel = camera.contenido === 'materiales'
    ? 'MATERIALES'
    : camera.contenido === 'materia_prima'
      ? 'MATERIA PRIMA'
      : 'PRODUCTOS';
  const displayName = cameraDisplayName(camera);

  return (
    <Pressable
      accessibilityLabel={`Abrir ${displayName}, ${status}`}
      accessibilityRole="button"
      onPress={onPress}
      style={({ pressed }) => [
        styles.card,
        selected && styles.selected,
        pressed && styles.pressed,
      ]}
    >
      <View style={styles.topRow}>
        <Text numberOfLines={1} style={styles.title}>{displayName}</Text>
        <View style={styles.state}>
          <View style={[styles.dot, { backgroundColor: statusColor }]} />
          <Text style={[styles.stateText, { color: statusColor }]}>{status}</Text>
        </View>
      </View>
      <Text style={styles.content}>{contentLabel}</Text>
      <View style={styles.occupancyRow}>
        <Text style={styles.occupancyLabel}>Ocupación</Text>
        <Text style={styles.occupancyValue}>{camera.ocupacion.porcentaje}%</Text>
      </View>
      <View style={styles.progress}>
        <View
          style={[
            styles.progressValue,
            { width: `${Math.min(100, camera.ocupacion.porcentaje)}%` },
          ]}
        />
      </View>
      <Text style={styles.detail}>
        {camera.ocupacion.ocupadas} de {camera.ocupacion.total} posiciones
      </Text>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  card: {
    width: 238,
    padding: 15,
    borderRadius: 15,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.panel,
  },
  selected: {
    borderColor: colors.cyan,
    backgroundColor: colors.selected,
  },
  pressed: { opacity: 0.78 },
  topRow: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    gap: 8,
  },
  title: {
    fontFamily: font.heavy,
    flex: 1,
    color: colors.text,
    fontSize: 18,
  },
  state: { flexDirection: 'row', alignItems: 'center', gap: 6 },
  dot: { width: 8, height: 8, borderRadius: 4 },
  stateText: { fontFamily: font.heavy, fontSize: 14 },
  content: { fontFamily: font.heavy, marginTop: 7, color: colors.muted, fontSize: 14 },
  occupancyRow: {
    marginTop: 13,
    flexDirection: 'row',
    justifyContent: 'space-between',
  },
  occupancyLabel: { fontFamily: font.bold, color: colors.muted, fontSize: 14 },
  occupancyValue: { fontFamily: font.heavy, color: colors.text, fontSize: 12 },
  progress: {
    height: 6,
    marginTop: 7,
    borderRadius: 3,
    overflow: 'hidden',
    backgroundColor: colors.borderSoft,
  },
  progressValue: { height: '100%', borderRadius: 3, backgroundColor: colors.cyan },
  detail: { fontFamily: font.regular, marginTop: 7, color: colors.muted, fontSize: 13 },
});
