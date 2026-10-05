import { Pressable, StyleSheet, Text, TextInput, View } from 'react-native';
import { ContainerInspectionDraft, InspectionItem } from '../domain/containerInspection';
import { containerLabel } from '../services/containerCatalog';
import { colors } from '../theme/colors';
type Props = { value: ContainerInspectionDraft; quantities: Array<{ tipo_envase: string; cantidad_validada: number }>; disabled: boolean; onChange: (value: ContainerInspectionDraft) => void };
export function ContainerInspectionPanel({ value, quantities, disabled, onChange }: Props) {
  function itemChange(type: string, patch: Partial<InspectionItem>) { onChange({ ...value, items: value.items.map((item) => item.tipo_envase === type ? { ...item, ...patch } : item) }); }
  function choice(label: string, selected: boolean, onPress: () => void) { return <Pressable key={label} accessibilityRole="radio" accessibilityState={{ checked: selected, disabled }} disabled={disabled} onPress={onPress} style={[styles.choice, selected && styles.selected]}><Text style={styles.text}>{label}</Text></Pressable>; }
  return <View style={styles.panel}><Text style={styles.title}>RC-02 · Inspección de recepción</Text><Text style={styles.muted}>Completa la inspección antes de confirmar. Las cantidades provienen del conteo real.</Text>
    {quantities.filter((q) => q.cantidad_validada > 0).map((q) => { const item = value.items.find((i) => i.tipo_envase === q.tipo_envase); return <View key={q.tipo_envase} style={styles.item}>
      <Text style={styles.title}>{containerLabel(q.tipo_envase)} · {q.cantidad_validada}</Text>
      <Text style={styles.text}>Limpieza</Text><View style={styles.row}>{[true, false].map((v) => choice(v ? 'Sí' : 'No', item?.limpieza === v, () => itemChange(q.tipo_envase, { limpieza: v })))}</View>
      <Text style={styles.text}>Condición</Text><View style={styles.row}>{(['buena', 'regular', 'mala'] as const).map((v) => choice(v[0].toUpperCase() + v.slice(1), item?.condicion === v, () => itemChange(q.tipo_envase, { condicion: v })))}</View>
      <TextInput editable={!disabled} accessibilityLabel={`Nota de ${containerLabel(q.tipo_envase)}`} maxLength={500} onChangeText={(nota) => itemChange(q.tipo_envase, { nota })} placeholder="Nota opcional" placeholderTextColor={colors.muted} style={styles.input} value={item?.nota || ''}/>
    </View>; })}
    {([['coincide_especie_variedad', 'Coincide especie y variedad'], ['coincide_cantidad_bins', 'Coincide cantidad de bins'], ['bins_bien_etiquetados', 'Bins bien etiquetados']] as const).map(([field, label]) => <View key={field}><Text style={styles.text}>{label}</Text><View style={styles.row}>{[true, false].map((v) => choice(v ? 'Sí' : 'No', value[field] === v, () => onChange({ ...value, [field]: v })))}</View></View>)}
    <TextInput editable={!disabled} accessibilityLabel="Observación de inspección RC-02" multiline maxLength={2000} onChangeText={(observacion) => onChange({ ...value, observacion })} placeholder="Observación de inspección" placeholderTextColor={colors.muted} style={styles.input} value={value.observacion}/>
  </View>;
}
const styles = StyleSheet.create({ panel: { gap: 10, paddingVertical: 14 }, item: { gap: 8, paddingVertical: 10, borderBottomWidth: 1, borderColor: colors.border }, title: { color: colors.text, fontSize: 15, fontWeight: '700' }, text: { color: colors.text, fontSize: 14 }, muted: { color: colors.muted, fontSize: 12 }, row: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 }, choice: { borderWidth: 1, borderColor: colors.border, borderRadius: 8, padding: 10 }, selected: { borderColor: colors.cyan, backgroundColor: colors.panel }, input: { color: colors.text, borderWidth: 1, borderColor: colors.border, borderRadius: 8, padding: 10 } });
