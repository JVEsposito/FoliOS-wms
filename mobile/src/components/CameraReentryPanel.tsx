import { useState } from 'react';
import { Modal, Pressable, StyleSheet, Text, TextInput, View } from 'react-native';

import { CameraReentry } from '../domain/estiba';
import { colors } from '../theme/colors';

type Props = {
  reentries: CameraReentry[];
  disabled: boolean;
  onRegisterTemperature: (reentry: CameraReentry, temperature: number) => Promise<boolean>;
};

/**
 * Pallets que salieron de cámara por una carga y deben volver. La tarea guiada
 * con destino calculado aparece en Labores; aquí se ve qué falta y, si
 * corresponde, se registra la temperatura de pulpa (opcional).
 */
export function CameraReentryPanel({ reentries, disabled, onRegisterTemperature }: Props) {
  const [target, setTarget] = useState<CameraReentry | null>(null);
  const [value, setValue] = useState('');
  const pending = reentries.filter((reentry) => reentry.estado === 'pendiente');
  const parsed = Number(value.replace(',', '.'));
  const valid = value.trim() !== '' && Number.isFinite(parsed) && parsed >= -5 && parsed <= 40;

  if (!pending.length) return null;

  async function confirm() {
    if (!target || !valid) return;
    if (await onRegisterTemperature(target, Math.round(parsed * 10) / 10)) {
      setTarget(null);
      setValue('');
    }
  }

  return (
    <View style={styles.panel}>
      <Text style={styles.eyebrow}>REINGRESO A CÁMARA</Text>
      <Text style={styles.title}>{pending.length} {pending.length === 1 ? 'pallet vuelve' : 'pallets vuelven'} a cámara</Text>
      <Text style={styles.subtitle}>La tarea con destino calculado está en Labores. El folio no cambia.</Text>
      <View style={styles.list}>
        {pending.map((reentry) => (
          <View key={reentry.id} style={styles.row}>
            <View style={styles.rowCopy}>
              <Text style={styles.folio}>{reentry.folio.numero_folio}</Text>
              <Text style={styles.meta}>
                {[reentry.origen_etiqueta, reentry.carga?.codigo, reentry.anden ? `andén ${reentry.anden}` : null].filter(Boolean).join(' · ')}
              </Text>
              <Text style={styles.meta}>
                {reentry.temperatura_pulpa === null
                  ? 'Temperatura de pulpa sin registrar'
                  : `Pulpa ${reentry.temperatura_pulpa.toFixed(1)} °C`}
                {reentry.tarea ? ` · tarea ${reentry.tarea.estado.replaceAll('_', ' ')}` : ''}
              </Text>
            </View>
            <Pressable
              accessibilityLabel={`Registrar temperatura de ${reentry.folio.numero_folio}`}
              disabled={disabled}
              onPress={() => { setTarget(reentry); setValue(reentry.temperatura_pulpa?.toFixed(1) ?? ''); }}
              style={[styles.button, disabled && styles.disabled]}
            >
              <Text style={styles.buttonText}>{reentry.temperatura_pulpa === null ? 'Temperatura' : 'Corregir'}</Text>
            </Pressable>
          </View>
        ))}
      </View>

      <Modal animationType="fade" onRequestClose={() => setTarget(null)} transparent visible={target !== null}>
        <View style={styles.backdrop}>
          <View style={styles.modal}>
            <Text style={styles.eyebrow}>TEMPERATURA DE PULPA · OPCIONAL</Text>
            <Text style={styles.modalTitle}>{target?.folio.numero_folio}</Text>
            <Text style={styles.subtitle}>Registro interno del reingreso. No es obligatorio para ubicar el pallet.</Text>
            <TextInput
              accessibilityLabel="Temperatura de pulpa en grados Celsius"
              keyboardType="decimal-pad"
              onChangeText={setValue}
              placeholder="Ej.: 1,5"
              placeholderTextColor={colors.muted}
              style={styles.input}
              value={value}
            />
            <View style={styles.actions}>
              <Pressable onPress={() => setTarget(null)} style={styles.button}>
                <Text style={styles.buttonText}>Cancelar</Text>
              </Pressable>
              <Pressable disabled={disabled || !valid} onPress={() => void confirm()} style={[styles.primary, (disabled || !valid) && styles.disabled]}>
                <Text style={styles.primaryText}>Guardar °C</Text>
              </Pressable>
            </View>
          </View>
        </View>
      </Modal>
    </View>
  );
}

const styles = StyleSheet.create({
  panel: { marginBottom: 10, padding: 13, borderWidth: 1, borderColor: colors.amber, borderRadius: 16, backgroundColor: colors.panel, gap: 6 },
  eyebrow: { color: colors.amber, fontSize: 11, fontWeight: '900', letterSpacing: 1.1 },
  title: { color: colors.text, fontSize: 16, fontWeight: '900' },
  subtitle: { color: colors.muted, fontSize: 13 },
  list: { marginTop: 4, flexDirection: 'row', flexWrap: 'wrap', gap: 6 },
  row: { width: 300, padding: 9, borderRadius: 10, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.background, flexDirection: 'row', alignItems: 'center', gap: 8 },
  rowCopy: { flex: 1, minWidth: 0 },
  folio: { color: colors.text, fontSize: 13, fontWeight: '900' },
  meta: { marginTop: 2, color: colors.muted, fontSize: 11 },
  button: { paddingHorizontal: 11, paddingVertical: 8, borderRadius: 8, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.background, alignItems: 'center' },
  buttonText: { color: colors.text, fontSize: 12, fontWeight: '900' },
  primary: { paddingHorizontal: 14, paddingVertical: 10, borderRadius: 9, backgroundColor: colors.cyan, alignItems: 'center' },
  primaryText: { color: colors.accentText, fontSize: 13, fontWeight: '900' },
  disabled: { opacity: 0.35 },
  backdrop: { flex: 1, padding: 20, backgroundColor: 'rgba(0,0,0,0.75)', alignItems: 'center', justifyContent: 'center' },
  modal: { width: '100%', maxWidth: 480, padding: 20, borderRadius: 18, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.panel },
  modalTitle: { marginTop: 4, color: colors.text, fontSize: 20, fontWeight: '900' },
  input: { minHeight: 50, marginTop: 14, paddingHorizontal: 12, borderRadius: 9, borderWidth: 1, borderColor: colors.border, color: colors.text, backgroundColor: colors.background, fontSize: 20, fontWeight: '900' },
  actions: { marginTop: 14, flexDirection: 'row', justifyContent: 'flex-end', gap: 8 },
});
