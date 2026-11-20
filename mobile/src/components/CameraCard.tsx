import { Pressable, StyleSheet, Text, View } from 'react-native';

import { cameraDisplayName } from '../domain/cameras';
import { CameraSummary } from '../domain/estiba';
import { colors } from '../theme/colors';
import { font } from '../theme/typography';
import { areas } from '../theme/areas';
import { operatorTheme as o } from '../theme/operatorTheme';
import { FieldCorner } from './field/FieldMark';

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
  const area = camera.contenido === 'materiales' ? 'materiales' : readOnly ? 'materia-prima' : 'frigorifico';
  const progress = Math.max(0, Math.min(100, Number(camera.ocupacion.porcentaje) || 0));

  return (
    <Pressable
      accessibilityLabel={`Abrir ${displayName}, ${status}`}
      accessibilityRole="button"
      accessibilityState={{ selected }}
      onPress={onPress}
      style={({ pressed }) => [
        styles.card,
        selected && styles.selected,
        pressed && styles.pressed,
      ]}
    >
      <FieldCorner area={area} />
      {/* El nombre va solo en su línea: junto al estado se cortaba en «Camara d…». */}
      <Text numberOfLines={2} style={styles.title}>{displayName}</Text>
      <View style={styles.state}>
        <View style={[styles.dot, { backgroundColor: statusColor }]} />
        <Text style={[styles.stateText, { color: statusColor }]}>{status}</Text>
        <Text style={styles.content}>· {contentLabel}</Text>
      </View>
      <View style={styles.occupancyRow}>
        <Text style={styles.occupancyLabel}>Ocupación</Text>
        <Text style={styles.occupancyValue}>{camera.ocupacion.porcentaje}%</Text>
      </View>
      <View style={styles.progress} aria-hidden>
        {Array.from({ length: 10 }, (_, index) => (
          <View key={index} style={styles.progressSlot}>
            <View style={[styles.progressValue, {
              width: `${Math.max(0, Math.min(100, (progress - index * 10) * 10))}%`,
              backgroundColor: areas[area].bright,
            }]} />
          </View>
        ))}
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
    borderRadius: o.radius.panel,
    borderTopRightRadius: 2,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.panel,
  },
  selected: {
    borderColor: colors.cyan,
    backgroundColor: colors.selected,
  },
  pressed: { opacity: 0.78 },
  title: {
    fontFamily: font.heavy,
    color: colors.text,
    fontSize: 18,
    lineHeight: 23,
  },
  state: { marginTop: 6, flexDirection: 'row', flexWrap: 'wrap', alignItems: 'center', gap: 6 },
  dot: { width: 8, height: 8, borderRadius: 4 },
  stateText: { fontFamily: font.heavy, fontSize: 14 },
  content: { fontFamily: font.medium, color: colors.muted, fontSize: 13 },
  occupancyRow: {
    marginTop: 13,
    flexDirection: 'row',
    justifyContent: 'space-between',
  },
  occupancyLabel: { fontFamily: font.bold, color: colors.muted, fontSize: 14 },
  occupancyValue: { fontFamily: font.heavy, color: colors.text, fontSize: 14 },
  progress: {
    flexDirection: 'row',
    gap: 3,
    height: 8,
    marginTop: 7,
  },
  progressSlot: { flex: 1, borderRadius: 2, overflow: 'hidden', backgroundColor: colors.borderSoft },
  progressValue: { height: '100%' },
  detail: { fontFamily: font.regular, marginTop: 7, color: colors.muted, fontSize: 13 },
});
