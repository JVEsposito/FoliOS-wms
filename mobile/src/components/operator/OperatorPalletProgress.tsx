import { StyleSheet, Text, View } from 'react-native';

import { operatorLoadPalletProgress, type OperatorLoad, type OperatorLoadPhase } from '../../domain/operatorLoads';
import { operatorTheme as o } from '../../theme/operatorTheme';
import { font } from '../../theme/typography';

export function OperatorPalletProgress({ load, phase, folioId }: {
  load: OperatorLoad;
  phase: OperatorLoadPhase;
  folioId?: string;
}) {
  const { total, done, dock, selected } = operatorLoadPalletProgress(load, phase, folioId);
  const title = phase === 'separacion' ? 'Separación' : 'Carguío';
  const waiting = dock > 0 ? ` · ${dock} en andén por subir` : '';
  return (
    <View accessible accessibilityLabel={`${title}, ${done} de ${total} bultos${waiting}`} style={styles.progress}>
      <Text style={styles.caption}>{title} · <Text style={styles.count}>{done}/{total}</Text>{waiting}</Text>
      <View style={styles.cells} aria-hidden>
        {Array.from({ length: total }, (_, index) => {
          const status = index < done ? 'done' : index < done + dock ? 'dock' : 'pending';
          return (
            <View key={index} style={[styles.ring, index === selected && styles.selection]}>
              <View style={[styles.cell, styles[status]]}>{status === 'pending' ? <View style={styles.slash} /> : null}</View>
            </View>
          );
        })}
      </View>
      {selected >= 0 ? <Text style={styles.caption}>El marco exterior marca el folio de esta maniobra.</Text> : null}
    </View>
  );
}

const styles = StyleSheet.create({
  progress: { gap: 3, minWidth: 0 },
  caption: { color: o.color.textSecondary, fontFamily: font.regular, fontSize: o.type.caption },
  count: { color: o.color.text, fontFamily: font.monoBold },
  cells: { flexDirection: 'row', flexWrap: 'wrap', gap: 2 },
  ring: { padding: 1, borderWidth: 1, borderColor: 'transparent', borderRadius: 3 },
  selection: { borderColor: o.color.text },
  cell: { width: 11, height: 11, borderWidth: 1, borderRadius: 2, overflow: 'hidden' },
  done: { backgroundColor: o.color.textSecondary, borderColor: o.color.textSecondary },
  dock: { borderColor: o.color.primary },
  pending: { borderColor: o.color.borderStrong },
  slash: { position: 'absolute', left: 4, top: -3, height: 16, width: 1, backgroundColor: o.color.borderStrong, transform: [{ rotate: '45deg' }] },
});
