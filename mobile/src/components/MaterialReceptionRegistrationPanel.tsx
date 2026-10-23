import * as Crypto from 'expo-crypto';
import { useRef, useState } from 'react';
import { Pressable, StyleSheet, Text, TextInput, View } from 'react-native';
import { MaterialReception, ReceptionAuthorization } from '../domain/materialReception';
import { MaterialReceptionApi } from '../services/materialReceptionApi';
import { colors } from '../theme/colors';

const date = (value: string | null | undefined) => value ? new Intl.DateTimeFormat('es-CL', { timeZone: 'America/Santiago', dateStyle: 'short', timeStyle: 'short' }).format(new Date(value)) : 'No declarada';
export function ReceptionAuthorizationFields({ value, onChange, late }: { value: ReceptionAuthorization; onChange: (value: ReceptionAuthorization) => void; late: boolean }) {
  return <View style={styles.box}><Text style={styles.title}>Autorización del ingreso</Text><Text style={styles.text}>Motivo de la autorización (mínimo 5 caracteres)</Text><TextInput accessibilityLabel="Motivo de la autorización" value={value.motivo_autorizacion || ''} onChangeText={(motivo_autorizacion) => onChange({ ...value, motivo_autorizacion })} multiline maxLength={1000} style={styles.input} />{late ? <Pressable accessibilityRole="checkbox" accessibilityState={{ checked: value.aceptar_fuera_plazo === true }} onPress={() => onChange({ ...value, aceptar_fuera_plazo: !value.aceptar_fuera_plazo })} style={styles.button}><Text style={styles.text}>{value.aceptar_fuera_plazo ? '✓ ' : ''}Reconozco la autorización fuera de cinco días corridos. Quedará auditada.</Text></Pressable> : null}</View>;
}
export function MaterialReceptionRegistrationPanel({ reception, api, canAuthorize, onChange }: { reception: MaterialReception; api: MaterialReceptionApi; canAuthorize: boolean; onChange: (record: MaterialReception) => void }) {
  const info = reception.registro;
  const [open, setOpen] = useState(false);
  const [guide, setGuide] = useState(reception.numero_guia_despacho || '');
  const [issued, setIssued] = useState(reception.fecha_documento || '');
  const [reason, setReason] = useState('');
  const [lateAccepted, setLateAccepted] = useState(false);
  const [quantities, setQuantities] = useState<Record<string, string>>({});
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const saving = useRef(false);
  const operations = useRef(new Map<string, string>());
  if (!info?.fecha_recepcion && !info?.provisional) return null;
  const late = Boolean(info.fecha_limite && new Date(info.fecha_limite) < new Date());
  async function save() {
    if (saving.current) return;
    saving.current = true; setBusy(true); setError('');
    try {
      if (!guide.trim() || !/^\d{4}-\d{2}-\d{2}$/.test(issued) || reason.trim().length < 5) throw new Error('Completa guía, fecha de emisión YYYY-MM-DD y motivo (mínimo 5 caracteres).');
      if (late && !lateAccepted) throw new Error('Reconoce expresamente la regularización fuera de plazo.');
      if (!reception.fotos?.some((photo) => photo.tipo === 'documento')) throw new Error('Agrega primero la foto de la guía o factura.');
      const detalles = (reception.detalles || []).map((line) => {
        const value = (quantities[line.id] ?? line.cantidad_documental ?? '').trim().replace(',', '.');
        if (!/^\d+(?:\.\d{1,3})?$/.test(value) || Number(value) <= 0) throw new Error(`Indica la cantidad documental de ${line.item?.nombre} en ${line.unidad_medida}.`);
        return { id: line.id, cantidad_documental: Number(value) };
      });
      const data = { version_conocida: reception.version, numero_guia_despacho: guide.trim(), fecha_documento: issued, motivo: reason.trim(), aceptar_fuera_plazo: lateAccepted, detalles };
      const key = JSON.stringify(data);
      if (!operations.current.has(key)) operations.current.set(key, Crypto.randomUUID());
      const updated = await api.regularize(reception.id, { ...data, operacion_id: operations.current.get(key)! });
      onChange(updated); setOpen(false);
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'No se pudo regularizar.'); }
    finally { saving.current = false; setBusy(false); }
  }
  return <View style={styles.box}><Text style={styles.title}>{info.provisional ? `Provisional · ${info.estado}${info.regularizada_fuera_plazo ? ' fuera de plazo' : ''}` : 'Ingreso físico'}{info.retroactivo ? ' · Retroactivo' : ''}</Text><Text style={styles.text}>Recepción física: {date(info.fecha_recepcion)}</Text><Text style={styles.text}>Registro en sistema: {date(reception.created_at)}</Text>{info.provisional ? <Text style={styles.text}>Plazo: {date(info.fecha_limite)}</Text> : null}<Text style={styles.text}>Motivo: {info.motivo || '—'}</Text>{info.autorizado_at ? <Text style={styles.text}>Autorizó {info.autorizado_por?.name || 'Usuario auditado'} · {date(info.autorizado_at)}: {info.motivo_autorizacion}</Text> : null}{info.regularizado_at ? <Text style={styles.text}>Regularizó {info.regularizado_por?.name || 'Usuario auditado'} · {date(info.regularizado_at)}: {info.motivo_regularizacion}</Text> : null}{canAuthorize && reception.estado === 'confirmada' && ['pendiente', 'vencida'].includes(info.estado) ? <Pressable style={styles.button} accessibilityRole="button" onPress={() => setOpen(!open)} disabled={busy}><Text style={styles.text}>Regularizar documentos</Text></Pressable> : null}{open ? <View style={styles.box}><Text style={styles.text}>Las cantidades documentales se completan en las unidades base indicadas. Se conservan los folios, el conteo físico y el inventario.</Text><TextInput accessibilityLabel="Número de guía para regularizar" placeholder="Número de guía" value={guide} onChangeText={setGuide} maxLength={50} style={styles.input} /><TextInput accessibilityLabel="Fecha de emisión para regularizar" placeholder="Fecha emisión YYYY-MM-DD" value={issued} onChangeText={setIssued} maxLength={10} style={styles.input} />{(reception.detalles || []).map((line) => <View key={line.id}><Text style={styles.text}>{line.item?.nombre} · Cantidad documental ({line.unidad_medida})</Text><TextInput accessibilityLabel={`Cantidad documental ${line.item?.nombre}`} value={quantities[line.id] ?? line.cantidad_documental ?? ''} onChangeText={(value) => setQuantities({ ...quantities, [line.id]: value })} keyboardType="decimal-pad" style={styles.input} /></View>)}<TextInput accessibilityLabel="Motivo de regularización" placeholder="Motivo de regularización" value={reason} onChangeText={setReason} multiline maxLength={1000} style={styles.input} />{late ? <Pressable accessibilityRole="checkbox" accessibilityState={{ checked: lateAccepted }} style={styles.button} onPress={() => setLateAccepted(!lateAccepted)}><Text style={styles.text}>{lateAccepted ? '✓ ' : ''}Regularizo expresamente fuera de cinco días corridos. Quedará auditado.</Text></Pressable> : null}{error ? <Text style={styles.text} accessibilityRole="alert">{error}</Text> : null}<Pressable style={styles.button} accessibilityRole="button" disabled={busy} onPress={() => void save()}><Text style={styles.text}>{busy ? 'Guardando…' : 'Guardar regularización'}</Text></Pressable></View> : null}</View>;
}
const styles = StyleSheet.create({ text: { color: colors.text, fontSize: 16 }, box: { padding: 16, gap: 12, borderWidth: 1, borderColor: colors.border, borderRadius: 12 }, title: { fontSize: 18, fontWeight: '700', color: colors.text }, input: { minHeight: 48, padding: 12, borderWidth: 1, borderColor: colors.border, borderRadius: 8, color: colors.text }, button: { minHeight: 48, padding: 12, borderWidth: 1, borderColor: colors.border, borderRadius: 8, justifyContent: 'center' } });
