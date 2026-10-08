import { useEffect, useState } from 'react';
import { Pressable, StyleSheet, Text, TextInput, View } from 'react-native';
import type { ShiftVerification, VerificationItem, MaterialVerificationReading } from '../../domain/verification';
import { operatorTheme as o } from '../../theme/operatorTheme';
import { ScanInput } from '../ui/ScanInput';

type Props = {
  round: ShiftVerification | null;
  busy: boolean;
  onVerify: (item: VerificationItem, number: string | null) => void;
  onVerifyMaterials?: (item: VerificationItem, readings: MaterialVerificationReading[]) => void;
  lookupUnit?: (number: string) => Promise<string | null>;
};

export function TurnVerification({ round, busy, onVerify, onVerifyMaterials, lookupUnit }: Props) {
  const [selected, setSelected] = useState<string | null>(null);
  const [number, setNumber] = useState('');
  useEffect(() => { setNumber(''); }, [selected, round?.completadas]);
  const [readings, setReadings] = useState<{ number: string; quantity: string; unit: string | null }[]>([]);
  const [empty, setEmpty] = useState(false);
  const [error, setError] = useState('');
  const [scanning, setScanning] = useState(false);
  const pending = round?.items.filter((item) => item.resultado === null) ?? [];
  const current = pending.find((item) => item.id === selected) ?? pending[0];
  useEffect(() => { setReadings([]); setEmpty(false); setError(''); }, [current?.id, round?.completadas]);
  if (!round) return null;
  const expired = round.estado !== 'pendiente';

  const materials = round.contenido === 'materiales';
  async function scan(code: string) {
    const normalized = code.trim().toUpperCase();
    if (!normalized || scanning) return;
    if (readings.some((r) => r.number === normalized)) { setError('Este folio ya está escaneado.'); return; }
    setScanning(true); setError('');
    try {
      const unit = lookupUnit ? await lookupUnit(normalized) : null;
      setReadings((rows) => [...rows, { number: normalized, quantity: '', unit }]);
      setEmpty(false); setNumber('');
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'No fue posible consultar la unidad.'); }
    finally { setScanning(false); }
  }
  function confirm() {
    if (!current || !onVerifyMaterials) return;
    if (!empty && !readings.length) { setError('Escanea los folios o marca la posición vacía.'); return; }
    const values = readings.map((r) => ({ numero_folio: r.number, cantidad_contada: r.quantity.trim() ? Number(r.quantity.replace(',', '.')) : null }));
    if (round?.verificar_cantidad && values.some((r) => r.cantidad_contada === null || !Number.isFinite(r.cantidad_contada) || r.cantidad_contada < 0 || r.cantidad_contada > 99999999999.999)) {
      setError('Indica una cantidad contada válida para cada folio.'); return;
    }
    onVerifyMaterials(current, empty ? [] : values);
  }

  return (
    <View style={styles.card}>
      <Text style={styles.eyebrow}>{materials ? 'VERIFICACIÓN CIEGA · MATERIALES' : 'CONTEO CÍCLICO · SIN DATOS PREVIOS DEL PALLET'}</Text>
      <Text style={styles.title}>Revisión de turno · {round.completadas} de {round.objetivo}</Text>
      <Text style={styles.detail}>Plazo: {new Date(round.vence_at).toLocaleString('es-CL')} · {round.estado === 'vencida' ? 'Vencida' : round.estado === 'completada' ? 'Completada' : 'Pendiente'}</Text>
      {pending.map((item) => (
        <Pressable key={item.id} disabled={busy || scanning} onPress={() => setSelected(item.id)} style={[styles.position, current?.id === item.id && styles.selected]}>
          <Text style={styles.positionText}>{item.posicion.camara} · B{String(item.posicion.banda).padStart(2, '0')} · P{String(item.posicion.posicion).padStart(2, '0')} · N{item.posicion.nivel}</Text>
        </Pressable>
      ))}
      {current && !expired ? (
        <View style={styles.actions}>
          {materials ? <>
            <Text style={styles.label}>Escanea todos los folios de esta posición, uno por uno.</Text>
            <ScanInput value={number} onChangeText={setNumber} onSubmit={(code) => void scan(code)} placeholder="Folio completo" submitLabel="Agregar folio" disabled={busy || scanning} />
            {readings.map((r) => <View key={r.number} style={styles.position}>
              <Text style={styles.positionText}>{r.number}{r.unit ? ` · ${r.unit}` : ''}</Text>
              {round.verificar_cantidad ? <TextInput accessibilityLabel={`Cantidad contada ${r.number}`} value={r.quantity} keyboardType="decimal-pad" placeholder={`Cantidad contada${r.unit ? ` (${r.unit})` : ''}`} onChangeText={(quantity) => setReadings((rows) => rows.map((row) => row.number === r.number ? { ...row, quantity } : row))} style={styles.quantity} editable={!busy} /> : null}
              <Pressable disabled={busy} onPress={() => setReadings((rows) => rows.filter((row) => row.number !== r.number))}><Text style={styles.emptyText}>Quitar {r.number}</Text></Pressable>
            </View>)}
            <Pressable disabled={busy || scanning} onPress={() => { setReadings([]); setEmpty(true); setError(''); }} style={styles.emptyButton}><Text style={styles.emptyText}>{empty ? 'Marcada: posición vacía' : 'Posición vacía'}</Text></Pressable>
            {error ? <Text accessibilityRole="alert" style={styles.detail}>{error}</Text> : null}
            <Pressable disabled={busy || scanning} onPress={confirm} style={styles.emptyButton}><Text style={styles.emptyText}>Confirmar posición</Text></Pressable>
          </> : <>
          <Text style={styles.label}>Escanea o digita el folio completo en la posición seleccionada</Text>
          <ScanInput value={number} onChangeText={setNumber} onSubmit={(code) => onVerify(current, code)} placeholder="Folio completo" submitLabel="Verificar folio" disabled={busy} />
          <Pressable disabled={busy} onPress={() => onVerify(current, null)} style={styles.emptyButton}>
            <Text style={styles.emptyText}>Posición vacía</Text>
          </Pressable>
          </>}
        </View>
      ) : null}
      {round.estado === 'vencida' ? <Text style={styles.detail}>La ronda vencida queda registrada sin bloquear tus labores.</Text> : null}
    </View>
  );
}

const styles = StyleSheet.create({
  card: { backgroundColor: o.color.surface, borderColor: o.color.border, borderWidth: 1, borderRadius: o.radius.panel, padding: o.space[4], gap: o.space[2] },
  eyebrow: { color: o.color.primary, fontWeight: '800', fontSize: o.type.caption },
  title: { color: o.color.text, fontSize: o.type.heading, fontWeight: '800' },
  detail: { color: o.color.muted, fontSize: o.type.small },
  position: { borderColor: o.color.border, borderWidth: 1, padding: o.space[3], borderRadius: o.radius.control },
  selected: { backgroundColor: o.color.selected, borderColor: o.color.primary },
  positionText: { color: o.color.text, fontSize: o.type.body, fontWeight: '700' },
  actions: { gap: o.space[2], marginTop: o.space[2] },
  quantity: { minHeight: o.touch.minimum, padding: o.space[3], color: o.color.text, borderColor: o.color.border, borderWidth: 1, borderRadius: o.radius.control },
  label: { color: o.color.text, fontSize: o.type.small },
  emptyButton: { padding: o.space[3], alignItems: 'center', borderColor: o.color.primary, borderWidth: 1, borderRadius: o.radius.control },
  emptyText: { color: o.color.primary, fontWeight: '700' },
});
