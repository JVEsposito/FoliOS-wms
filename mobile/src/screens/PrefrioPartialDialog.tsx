import { useState } from 'react';
import { Modal, Pressable, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
import {
  partialThermalResults,
  prefrioPartialResultLabel,
  type PrefrioPartialOutcome,
  type PrefrioPartialResult,
  type PrefrioPartialSelection,
  type PrefrioProcessFolio,
} from '../domain/prefrio';
import { colors } from '../theme/colors';
import { font } from '../theme/typography';

type Fields = { result?: PrefrioPartialResult; temperature: string; reason: string };
const emptyFields: Fields = { temperature: '', reason: '' };
const results = Object.keys(partialThermalResults) as PrefrioPartialResult[];

export function PrefrioPartialDialog({
  finalizing, candidates, initialTime, busy, onClose, onSubmit,
}: {
  finalizing: boolean;
  candidates: PrefrioProcessFolio[];
  initialTime: string;
  busy: boolean;
  onClose: () => void;
  onSubmit: (selection: PrefrioPartialSelection, time: string) => Promise<string | null>;
}) {
  const [selected, setSelected] = useState<Record<string, Fields>>({});
  const [number, setNumber] = useState('');
  const [time, setTime] = useState(initialTime);
  const [reason, setReason] = useState('');
  const [error, setError] = useState('');
  const count = Object.keys(selected).length;
  const allSelected = finalizing && count === candidates.length;
  const disabled = busy || !count || allSelected;
  const title = finalizing ? 'Finalizar parcialmente' : 'Confirmar retiro';

  function toggle(id: string) {
    setSelected((current) => {
      const next = { ...current };
      if (next[id]) delete next[id];
      else next[id] = emptyFields;
      return next;
    });
    setError('');
  }

  function markScanned() {
    const item = candidates.find((candidate) => candidate.folio?.numero_folio.toUpperCase() === number.trim().toUpperCase());
    if (!item) { setError('El folio escaneado no está en la lista de esta acción.'); return; }
    setSelected((current) => ({ ...current, [item.id]: current[item.id] ?? emptyFields }));
    setNumber('');
    setError('');
  }

  function updateFields(id: string, fields: Partial<Fields>) {
    setSelected((current) => ({ ...current, [id]: { ...current[id], ...fields } }));
  }

  async function submit() {
    if (disabled) return;
    const folios = candidates.flatMap((item) => item.folio && selected[item.id]
      ? [{ folio: item.folio, fields: selected[item.id] }] : []);
    if (!finalizing) {
      setError(await onSubmit({ folios: folios.map(({ folio }) => folio.id) }, time) ?? '');
      return;
    }
    const motive = reason.trim();
    if (motive.length < 3) { setError('Ingresa un motivo general de al menos 3 caracteres.'); return; }
    const outcomes: PrefrioPartialOutcome[] = [];
    for (const { folio, fields } of folios) {
      const temperature = fields.temperature.trim();
      const value = Number(temperature.replace(',', '.'));
      const folioReason = fields.reason.trim();
      if (!fields.result) { setError(`Elige el resultado de ${folio.numero_folio}.`); return; }
      if (temperature && (!Number.isFinite(value) || value < -20 || value > 50)) {
        setError(`La temperatura de ${folio.numero_folio} debe estar entre -20 y 50 °C.`);
        return;
      }
      if (folioReason && folioReason.length < 3) { setError(`Revisa el motivo de ${folio.numero_folio}.`); return; }
      outcomes.push({
        folio_id: folio.id, resultado: fields.result,
        ...(temperature ? { temperatura_final: value } : {}), ...(folioReason ? { motivo: folioReason } : {}),
      });
    }
    setError(await onSubmit({ motivo: motive, resultados: outcomes }, time) ?? '');
  }

  return (
    <Modal animationType="fade" onRequestClose={() => { if (!busy) onClose(); }} transparent visible>
      <View style={styles.backdrop}>
        <ScrollView style={styles.dialog} contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled">
          <Text style={styles.title}>{title}</Text>
          <Text style={styles.hint}>{finalizing
            ? 'Selecciona los folios que saldrán e indica su resultado. Los demás siguen en el ciclo.'
            : 'Marca los folios que ya salieron físicamente del túnel.'}</Text>
          <View style={styles.row}>
            <TextInput accessibilityLabel="Escanear folio" placeholder="Escanear folio" placeholderTextColor={colors.muted}
              style={[styles.input, styles.scan]} autoCapitalize="characters" autoCorrect={false} returnKeyType="done"
              value={number} editable={!busy} onChangeText={setNumber} onSubmitEditing={markScanned} />
            <Pressable disabled={busy} onPress={markScanned} style={styles.button}><Text style={styles.label}>Marcar</Text></Pressable>
          </View>
          {candidates.map((item) => {
            const fields = selected[item.id];
            return (
              <View key={item.id} style={styles.folio}>
                <Pressable accessibilityRole="checkbox" accessibilityState={{ checked: Boolean(fields) }}
                  disabled={busy} onPress={() => toggle(item.id)} style={styles.selection}>
                  <Text style={styles.label}>{fields ? '✓' : '○'}  {item.folio?.numero_folio} · {item.posicion?.etiqueta}</Text>
                  <Text style={styles.hint}>{finalizing ? (item.folio?.tipo_bulto === 'saldo' ? 'Saldo' : 'Pallet') : prefrioPartialResultLabel(item)}</Text>
                </Pressable>
                {finalizing && fields ? <>
                  <Text style={styles.label}>Resultado térmico</Text>
                  <View style={styles.results}>
                    {results.map((result) => (
                      <Pressable key={result} accessibilityRole="radio" accessibilityState={{ checked: fields.result === result }}
                        disabled={busy} onPress={() => updateFields(item.id, { result })}
                        style={[styles.button, fields.result === result && styles.selected]}>
                        <Text style={styles.label}>{partialThermalResults[result]}</Text>
                      </Pressable>
                    ))}
                  </View>
                  <TextInput accessibilityLabel="Temperatura final" placeholder="T° final (°C) opcional" placeholderTextColor={colors.muted}
                    keyboardType="decimal-pad" style={styles.input} editable={!busy} value={fields.temperature}
                    onChangeText={(temperature) => updateFields(item.id, { temperature })} />
                  <TextInput accessibilityLabel="Motivo del folio" placeholder="Motivo del folio opcional" placeholderTextColor={colors.muted}
                    maxLength={100} style={styles.input} editable={!busy} value={fields.reason}
                    onChangeText={(folioReason) => updateFields(item.id, { reason: folioReason })} />
                </> : null}
              </View>
            );
          })}
          {finalizing ? <>
            <Text style={styles.label}>Motivo general</Text>
            <TextInput accessibilityLabel="Motivo general" maxLength={100} style={styles.input} editable={!busy} value={reason} onChangeText={setReason} />
          </> : null}
          <Text style={styles.label}>Fecha y hora real de planta</Text>
          <TextInput accessibilityLabel="Fecha y hora real de planta" placeholder="DD-MM-AAAA HH:mm" placeholderTextColor={colors.muted}
            style={styles.input} editable={!busy} value={time} onChangeText={setTime} />
          <Text style={styles.hint}>{allSelected ? 'Usa Finalizar proceso para terminar todos los folios restantes.'
            : finalizing ? `${count} seleccionados · ${candidates.length - count} seguirán en proceso` : `${count} seleccionados`}</Text>
          {error ? <Text accessibilityRole="alert" style={styles.error}>{error}</Text> : null}
          <View style={styles.row}>
            <Pressable disabled={busy} onPress={onClose} style={styles.button}><Text style={styles.label}>Cancelar</Text></Pressable>
            <Pressable disabled={disabled} onPress={() => void submit()} style={[styles.button, styles.primary, disabled && styles.disabled]}>
              <Text style={styles.primaryLabel}>{title}</Text>
            </Pressable>
          </View>
        </ScrollView>
      </View>
    </Modal>
  );
}

const styles = StyleSheet.create({
  backdrop: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: 24, backgroundColor: 'rgba(0,0,0,0.72)' },
  dialog: { flexGrow: 0, width: '100%', maxWidth: 600, maxHeight: '92%', borderRadius: 16, backgroundColor: colors.panel, borderWidth: 1, borderColor: colors.cyanDark },
  content: { padding: 20, gap: 10 },
  title: { color: colors.text, fontSize: 22, fontFamily: font.heavy },
  hint: { color: colors.muted, fontFamily: font.regular },
  label: { color: colors.text, fontFamily: font.bold },
  row: { flexDirection: 'row', alignItems: 'center', gap: 8 },
  scan: { flex: 1 },
  input: { minHeight: 48, paddingHorizontal: 12, borderRadius: 8, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.backgroundDeep, color: colors.text },
  folio: { padding: 8, gap: 8, borderWidth: 1, borderColor: colors.borderSoft, borderRadius: 8 },
  selection: { padding: 7, gap: 4, minHeight: 46 },
  results: { flexDirection: 'row', flexWrap: 'wrap', gap: 6 },
  button: { minHeight: 44, alignItems: 'center', justifyContent: 'center', paddingHorizontal: 12, borderWidth: 1, borderColor: colors.border, borderRadius: 8 },
  selected: { borderColor: colors.cyan, backgroundColor: colors.panelStrong },
  primary: { backgroundColor: colors.cyan },
  primaryLabel: { color: colors.accentText, fontFamily: font.heavy },
  disabled: { opacity: 0.4 },
  error: { color: colors.red, fontFamily: font.bold },
});
