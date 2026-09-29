import { useEffect, useState } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import type { ShiftVerification, VerificationItem } from '../../domain/verification';
import { operatorTheme as o } from '../../theme/operatorTheme';
import { ScanInput } from '../ui/ScanInput';

type Props = {
  round: ShiftVerification | null;
  busy: boolean;
  onVerify: (item: VerificationItem, number: string | null) => void;
};

export function TurnVerification({ round, busy, onVerify }: Props) {
  const [selected, setSelected] = useState<string | null>(null);
  const [number, setNumber] = useState('');
  useEffect(() => { setNumber(''); }, [selected, round?.completadas]);
  if (!round) return null;
  const pending = round.items.filter((item) => item.resultado === null);
  const current = pending.find((item) => item.id === selected) ?? pending[0];
  const expired = round.estado !== 'pendiente';

  return (
    <View style={styles.card}>
      <Text style={styles.eyebrow}>CONTEO CÍCLICO · SIN DATOS PREVIOS DEL PALLET</Text>
      <Text style={styles.title}>Revisión de turno · {round.completadas} de {round.objetivo}</Text>
      <Text style={styles.detail}>Plazo: {new Date(round.vence_at).toLocaleString('es-CL')} · {round.estado === 'vencida' ? 'Vencida' : round.estado === 'completada' ? 'Completada' : 'Pendiente'}</Text>
      {pending.map((item) => (
        <Pressable key={item.id} onPress={() => setSelected(item.id)} style={[styles.position, current?.id === item.id && styles.selected]}>
          <Text style={styles.positionText}>{item.posicion.camara} · B{String(item.posicion.banda).padStart(2, '0')} · P{String(item.posicion.posicion).padStart(2, '0')} · N{item.posicion.nivel}</Text>
        </Pressable>
      ))}
      {current && !expired ? (
        <View style={styles.actions}>
          <Text style={styles.label}>Escanea o digita el folio completo en la posición seleccionada</Text>
          <ScanInput value={number} onChangeText={setNumber} onSubmit={(code) => onVerify(current, code)} placeholder="Folio completo" submitLabel="Verificar folio" disabled={busy} />
          <Pressable disabled={busy} onPress={() => onVerify(current, null)} style={styles.emptyButton}>
            <Text style={styles.emptyText}>Posición vacía</Text>
          </Pressable>
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
  label: { color: o.color.text, fontSize: o.type.small },
  emptyButton: { padding: o.space[3], alignItems: 'center', borderColor: o.color.primary, borderWidth: 1, borderRadius: o.radius.control },
  emptyText: { color: o.color.primary, fontWeight: '700' },
});
