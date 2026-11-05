import { StyleSheet, Text, View } from 'react-native';

import { cameraDisplayName } from '../domain/cameras';
import { Movement } from '../domain/estiba';
import { colors } from '../theme/colors';
import { font } from '../theme/typography';

type RecentMovementsProps = {
  movements: Movement[];
  lastSync: string | null;
};

const labels: Record<Movement['tipo_movimiento'], string> = {
  ubicacion_inicial: 'Ubicación inicial',
  reubicacion: 'Reubicación',
  traslado_entre_camaras: 'Cambio de cámara',
  retiro: 'Retiro',
  reversion: 'Reversión',
};

function movementEndLabel(end: Movement['origen'], fallback: string) {
  if (!end) return fallback;

  const position = end.posicion
    ? end.posicion.etiqueta ?? `B${end.posicion.banda}`
    : 'Sin posición';

  return `${cameraDisplayName(end.camara)} · ${position}`;
}

export function RecentMovements({ movements, lastSync }: RecentMovementsProps) {
  return (
    <View style={styles.panel}>
      <View style={styles.heading}>
        <View>
          <Text style={styles.eyebrow}>TRAZABILIDAD</Text>
          <Text style={styles.title}>Movimientos recientes</Text>
        </View>
        <Text style={styles.sync}>{lastSync ? `Actualizado ${lastSync}` : 'Sin sincronizar'}</Text>
      </View>

      {movements.length === 0 ? (
        <Text style={styles.empty}>Aún no hay movimientos registrados en esta cámara.</Text>
      ) : (
        <View style={styles.list}>
          {movements.slice(0, 4).map((movement) => {
            const origin = movementEndLabel(movement.origen, 'Ingreso');
            const destination = movementEndLabel(movement.destino, 'Salida');
            const time = new Date(movement.created_at).toLocaleTimeString('es-CL', {
              hour: '2-digit',
              minute: '2-digit',
            });

            return (
              <View key={movement.id} style={styles.item}>
                <Text style={styles.icon}>{movement.tipo_movimiento === 'ubicacion_inicial' ? '＋' : '⇄'}</Text>
                <View style={styles.copy}>
                  <Text numberOfLines={1} style={styles.itemTitle}>
                    {movement.folio.numero_folio} · {labels[movement.tipo_movimiento]}
                  </Text>
                  <Text numberOfLines={1} style={styles.route}>{origin} → {destination}</Text>
                </View>
                <Text style={styles.time}>{time}</Text>
              </View>
            );
          })}
        </View>
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  panel: {
    marginTop: 12,
    padding: 14,
    borderRadius: 18,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.panel,
  },
  heading: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'flex-end' },
  eyebrow: { fontFamily: font.heavy, color: colors.cyan, fontSize: 12, letterSpacing: 1.2 },
  title: { fontFamily: font.heavy, marginTop: 3, color: colors.text, fontSize: 15 },
  sync: { fontFamily: font.regular, color: colors.muted, fontSize: 12 },
  list: { marginTop: 10, flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  item: {
    minWidth: 245,
    flex: 1,
    padding: 10,
    borderRadius: 10,
    backgroundColor: colors.panelStrong,
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
  },
  icon: { fontFamily: font.heavy, color: colors.cyan, fontSize: 18 },
  copy: { flex: 1, minWidth: 0 },
  itemTitle: { fontFamily: font.heavy, color: colors.text, fontSize: 13 },
  route: { fontFamily: font.regular, marginTop: 3, color: colors.muted, fontSize: 12 },
  time: { fontFamily: font.regular, color: colors.muted, fontSize: 12 },
  empty: { fontFamily: font.regular, marginTop: 10, color: colors.muted, fontSize: 13 },
});
