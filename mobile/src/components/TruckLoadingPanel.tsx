import { useState } from 'react';
import { Modal, Pressable, StyleSheet, Text, TextInput, View } from 'react-native';

import { LoadFolio, RefrigeratedLoad } from '../domain/estiba';
import { colors } from '../theme/colors';

type Props = {
  load: RefrigeratedLoad;
  disabled: boolean;
  onRegister: (folioNumber: string) => Promise<boolean>;
  onUndo: (folio: LoadFolio, reason: string) => Promise<boolean>;
};

/**
 * Carguío en el andén: «en andén» no significa «arriba del camión». El camarero
 * lee cada pallet al subirlo; el servidor rechaza los folios de otra carga.
 */
export function TruckLoadingPanel({ load, disabled, onRegister, onUndo }: Props) {
  const [folioNumber, setFolioNumber] = useState('');
  const [undoTarget, setUndoTarget] = useState<LoadFolio | null>(null);
  const [undoReason, setUndoReason] = useState('');
  const atDock = load.folios.filter((folio) => folio.estado_carga === 'en_anden' || folio.estado_carga === 'cargado');
  const loaded = load.progreso.cargados ?? atDock.filter((folio) => folio.estado_carga === 'cargado').length;
  const waiting = atDock.filter((folio) => folio.estado_carga === 'en_anden');
  const total = load.total_folios;
  const percent = total ? Math.min(100, Math.round((loaded / total) * 100)) : 0;

  if (!atDock.length) return null;

  async function submit(number = folioNumber) {
    const value = number.trim().toUpperCase();
    if (!value || disabled) return;
    if (await onRegister(value)) setFolioNumber('');
  }

  async function confirmUndo() {
    if (!undoTarget || undoReason.trim().length < 3) return;
    if (await onUndo(undoTarget, undoReason.trim())) {
      setUndoTarget(null);
      setUndoReason('');
    }
  }

  return (
    <View style={styles.panel}>
      <View style={styles.header}>
        <View style={styles.headerCopy}>
          <Text style={styles.eyebrow}>CARGUÍO AL CAMIÓN</Text>
          <Text style={styles.title}>{loaded}/{total} pallets cargados</Text>
          <Text style={styles.subtitle}>
            {waiting.length ? `${waiting.length} esperan en el andén` : loaded >= total ? 'Carga completa: oficina puede cerrar el despacho' : 'Sin pallets esperando en el andén'}
            {load.camion_en_anden?.patente ? ` · camión ${load.camion_en_anden.patente}` : ''}
          </Text>
        </View>
        <Text style={styles.percent}>{percent}%</Text>
      </View>
      <View style={styles.track}><View style={[styles.fill, { width: `${percent}%` }]} /></View>

      <View style={styles.scanRow}>
        <TextInput
          accessibilityLabel="Folio que sube al camión"
          autoCapitalize="characters"
          autoCorrect={false}
          blurOnSubmit={false}
          editable={!disabled}
          onChangeText={setFolioNumber}
          onSubmitEditing={() => void submit()}
          placeholder="Lee o digita el folio que sube"
          placeholderTextColor={colors.muted}
          returnKeyType="done"
          style={styles.input}
          value={folioNumber}
        />
        <Pressable
          accessibilityRole="button"
          disabled={disabled || !folioNumber.trim()}
          onPress={() => void submit()}
          style={[styles.primaryButton, (disabled || !folioNumber.trim()) && styles.disabled]}
        >
          <Text style={styles.primaryButtonText}>Registrar cargado</Text>
        </Pressable>
      </View>

      <View style={styles.list}>
        {atDock.map((folio) => (
          <View key={folio.asignacion_id} style={[styles.row, folio.estado_carga === 'cargado' && styles.rowLoaded]}>
            <View style={styles.rowCopy}>
              <Text style={styles.folio}>{folio.numero_folio}</Text>
              <Text style={styles.rowMeta}>
                {folio.estado_carga === 'cargado'
                  ? `Cargado ${folio.cargado_at ? time(folio.cargado_at) : ''}`
                  : `En andén ${folio.anden?.codigo ?? ''}`}
              </Text>
            </View>
            {folio.estado_carga === 'cargado' ? (
              <Pressable
                accessibilityLabel={`Bajar ${folio.numero_folio} del camión`}
                disabled={disabled}
                onPress={() => { setUndoTarget(folio); setUndoReason(''); }}
                style={[styles.secondaryButton, disabled && styles.disabled]}
              >
                <Text style={styles.secondaryButtonText}>Bajar</Text>
              </Pressable>
            ) : (
              <Pressable
                accessibilityLabel={`Registrar ${folio.numero_folio} como cargado`}
                disabled={disabled}
                onPress={() => void submit(folio.numero_folio)}
                style={[styles.loadButton, disabled && styles.disabled]}
              >
                <Text style={styles.loadButtonText}>Subió</Text>
              </Pressable>
            )}
          </View>
        ))}
      </View>

      <Modal animationType="fade" onRequestClose={() => setUndoTarget(null)} transparent visible={undoTarget !== null}>
        <View style={styles.backdrop}>
          <View style={styles.modal}>
            <Text style={styles.eyebrow}>BAJAR DEL CAMIÓN</Text>
            <Text style={styles.modalTitle}>{undoTarget?.numero_folio}</Text>
            <Text style={styles.subtitle}>El pallet vuelve a quedar en el andén. Indica el motivo para la trazabilidad.</Text>
            <TextInput
              accessibilityLabel="Motivo para bajar el pallet"
              maxLength={500}
              multiline
              onChangeText={setUndoReason}
              placeholder="Ej.: leído por error, pallet cambiado"
              placeholderTextColor={colors.muted}
              style={styles.reason}
              value={undoReason}
            />
            <View style={styles.modalActions}>
              <Pressable onPress={() => setUndoTarget(null)} style={styles.secondaryButton}>
                <Text style={styles.secondaryButtonText}>Cancelar</Text>
              </Pressable>
              <Pressable
                disabled={disabled || undoReason.trim().length < 3}
                onPress={() => void confirmUndo()}
                style={[styles.primaryButton, (disabled || undoReason.trim().length < 3) && styles.disabled]}
              >
                <Text style={styles.primaryButtonText}>Bajar del camión</Text>
              </Pressable>
            </View>
          </View>
        </View>
      </Modal>
    </View>
  );
}

function time(value: string) {
  const date = new Date(value);
  return Number.isNaN(date.getTime())
    ? ''
    : new Intl.DateTimeFormat('es-CL', { hour: '2-digit', minute: '2-digit' }).format(date);
}

const styles = StyleSheet.create({
  panel: { padding: 13, borderWidth: 1, borderColor: colors.border, borderRadius: 16, backgroundColor: colors.panel, gap: 9 },
  header: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', gap: 12 },
  headerCopy: { flex: 1, minWidth: 0 },
  eyebrow: { color: colors.cyan, fontSize: 11, fontWeight: '900', letterSpacing: 1.1 },
  title: { marginTop: 3, color: colors.text, fontSize: 17, fontWeight: '900' },
  subtitle: { marginTop: 3, color: colors.muted, fontSize: 13 },
  percent: { color: colors.text, fontSize: 22, fontWeight: '900' },
  track: { height: 8, borderRadius: 4, backgroundColor: colors.borderSoft, overflow: 'hidden' },
  fill: { height: '100%', backgroundColor: colors.green },
  scanRow: { flexDirection: 'row', gap: 8, alignItems: 'center' },
  input: { flex: 1, minHeight: 46, paddingHorizontal: 12, borderRadius: 9, borderWidth: 1, borderColor: colors.border, color: colors.text, backgroundColor: colors.background, fontSize: 16, fontWeight: '800' },
  list: { flexDirection: 'row', flexWrap: 'wrap', gap: 6 },
  row: { width: 210, padding: 8, borderRadius: 9, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.background, flexDirection: 'row', alignItems: 'center', gap: 8 },
  rowLoaded: { borderColor: colors.green, backgroundColor: colors.greenDark },
  rowCopy: { flex: 1, minWidth: 0 },
  folio: { color: colors.text, fontSize: 13, fontWeight: '900' },
  rowMeta: { marginTop: 2, color: colors.muted, fontSize: 11 },
  primaryButton: { paddingHorizontal: 14, paddingVertical: 12, borderRadius: 9, backgroundColor: colors.cyan, alignItems: 'center' },
  primaryButtonText: { color: colors.accentText, fontSize: 13, fontWeight: '900' },
  loadButton: { paddingHorizontal: 12, paddingVertical: 8, borderRadius: 8, borderWidth: 1, borderColor: colors.green, alignItems: 'center' },
  loadButtonText: { color: colors.text, fontSize: 12, fontWeight: '900' },
  secondaryButton: { paddingHorizontal: 12, paddingVertical: 8, borderRadius: 8, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.background, alignItems: 'center' },
  secondaryButtonText: { color: colors.text, fontSize: 12, fontWeight: '900' },
  disabled: { opacity: 0.35 },
  backdrop: { flex: 1, padding: 20, backgroundColor: 'rgba(0,0,0,0.75)', alignItems: 'center', justifyContent: 'center' },
  modal: { width: '100%', maxWidth: 560, padding: 20, borderRadius: 18, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.panel },
  modalTitle: { marginTop: 4, color: colors.text, fontSize: 20, fontWeight: '900' },
  reason: { minHeight: 80, marginTop: 14, padding: 11, borderRadius: 9, borderWidth: 1, borderColor: colors.border, color: colors.text, backgroundColor: colors.background, textAlignVertical: 'top' },
  modalActions: { marginTop: 14, flexDirection: 'row', justifyContent: 'flex-end', gap: 8 },
});
