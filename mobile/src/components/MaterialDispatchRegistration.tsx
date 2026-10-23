import * as Crypto from 'expo-crypto';
import { useRef, useState } from 'react';
import { Pressable, StyleSheet, Text, TextInput, View } from 'react-native';
import { MaterialDelivery, MaterialDispatch, MaterialDispatchRegistrationInput } from '../domain/estiba';
import { EstibaApi } from '../services/estibaApi';
import { colors } from '../theme/colors';

const date = (value?: string | null) => value ? new Intl.DateTimeFormat('es-CL', { timeZone: 'America/Santiago', dateStyle: 'short', timeStyle: 'short' }).format(new Date(value)) : 'No declarada';

export function MaterialDispatchRegistrationFields({ value, onChange, canAuthorize }: { value: MaterialDispatchRegistrationInput; onChange: (value: MaterialDispatchRegistrationInput) => void; canAuthorize: boolean }) {
  const [open, setOpen] = useState(false);
  return <View style={styles.box}>
    <Pressable accessibilityRole="button" onPress={() => setOpen(!open)} style={styles.button}><Text style={styles.text}>Fecha física y respaldo de esta entrega {open ? '−' : '+'}</Text></Pressable>
    {open ? <>
      <Text style={styles.text}>Opcional. Horas en America/Santiago. El registro conserva su hora real.</Text>
      <TextInput accessibilityLabel="Fecha de entrega física" placeholder="YYYY-MM-DDTHH:mm" placeholderTextColor={colors.muted} value={value.fecha_despacho || ''} onChangeText={fecha_despacho => onChange({ ...value, fecha_despacho })} maxLength={25} style={styles.input} />
      <TextInput accessibilityLabel="Número de documento de entrega" placeholder="Número de documento o vale" placeholderTextColor={colors.muted} value={value.numero_documento || ''} onChangeText={numero_documento => onChange({ ...value, numero_documento })} maxLength={50} style={styles.input} />
      <TextInput accessibilityLabel="Fecha de emisión del documento de entrega" placeholder="Emisión YYYY-MM-DD" placeholderTextColor={colors.muted} value={value.fecha_documento || ''} onChangeText={fecha_documento => onChange({ ...value, fecha_documento })} maxLength={10} style={styles.input} />
      {canAuthorize ? <>
        <Pressable accessibilityRole="checkbox" accessibilityState={{ checked: value.provisional === true }} onPress={() => onChange({ ...value, provisional: !value.provisional })} style={styles.button}><Text style={styles.text}>{value.provisional ? '✓ ' : ''}Entrega provisional · cinco días corridos</Text></Pressable>
        <TextInput accessibilityLabel="Motivo del despacho retroactivo o provisional" placeholder="Motivo del registro (mínimo 5 caracteres)" placeholderTextColor={colors.muted} value={value.motivo || ''} onChangeText={motivo => onChange({ ...value, motivo })} multiline maxLength={1000} style={styles.input} />
        <TextInput accessibilityLabel="Motivo de autorización del despacho" placeholder="Motivo de autorización (mínimo 5 caracteres)" placeholderTextColor={colors.muted} value={value.motivo_autorizacion || ''} onChangeText={motivo_autorizacion => onChange({ ...value, motivo_autorizacion })} multiline maxLength={1000} style={styles.input} />
        <Pressable accessibilityRole="checkbox" accessibilityState={{ checked: value.aceptar_fuera_plazo === true }} onPress={() => onChange({ ...value, aceptar_fuera_plazo: !value.aceptar_fuera_plazo })} style={styles.button}><Text style={styles.text}>{value.aceptar_fuera_plazo ? '✓ ' : ''}Autorizo expresamente fuera de cinco días corridos; quedará auditado</Text></Pressable>
      </> : <Text style={styles.text}>La fecha retroactiva y el modo provisional requieren autorización de supervisión.</Text>}
    </> : null}
  </View>;
}

export function MaterialDeliveryRegistrationPanel({ delivery, dispatchId, api, token, canAuthorize, onChange }: { delivery: MaterialDelivery; dispatchId: string; api: EstibaApi; token: string; canAuthorize: boolean; onChange: (dispatch: MaterialDispatch) => void }) {
  const [open, setOpen] = useState(false);
  const [number, setNumber] = useState(delivery.numero_documento || '');
  const [issued, setIssued] = useState(delivery.fecha_documento || '');
  const [reason, setReason] = useState('');
  const [accepted, setAccepted] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const saving = useRef(false);
  const operations = useRef(new Map<string, string>());
  const late = delivery.estado === 'vencida' || Boolean(delivery.fecha_limite && new Date(delivery.fecha_limite).getTime() < Date.now());
  async function save() {
    if (saving.current) return;
    saving.current = true; setBusy(true); setError('');
    try {
      if (!number.trim() || !/^\d{4}-\d{2}-\d{2}$/.test(issued) || reason.trim().length < 5 || (late && !accepted)) throw new Error('Completa documento, fecha y motivo. Reconoce el atraso si venció el plazo.');
      const data = { version_conocida: delivery.version, numero_documento: number.trim(), fecha_documento: issued, motivo: reason.trim(), aceptar_fuera_plazo: accepted };
      const key = JSON.stringify(data);
      if (!operations.current.has(key)) operations.current.set(key, Crypto.randomUUID());
      onChange(await api.regularizeMaterialDelivery(token, dispatchId, delivery.id, { ...data, operacion_id: operations.current.get(key)! }));
      setOpen(false);
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'No se pudo regularizar.'); }
    finally { saving.current = false; setBusy(false); }
  }
  return <View style={styles.box}>
    <Text style={styles.title}>{delivery.provisional ? `Provisional · ${delivery.estado}` : 'Entrega registrada'}{delivery.retroactivo ? ' · Retroactiva' : ''}{delivery.regularizada_fuera_plazo ? ' · Fuera de plazo' : ''}</Text>
    <Text style={styles.text}>Entrega física: {date(delivery.fecha_despacho)} · Registro real: {date(delivery.registrado_at)} · {delivery.registrado_por?.name}</Text>
    <Text style={styles.text}>Documento: {delivery.numero_documento || 'No declarado'} · Emisión: {delivery.fecha_documento || 'No declarada'}</Text>
    {delivery.provisional ? <Text style={styles.text}>Plazo: {date(delivery.fecha_limite)} · Cinco días corridos</Text> : null}
    {delivery.motivo ? <Text style={styles.text}>Motivo: {delivery.motivo}</Text> : null}
    {delivery.autorizado_at ? <Text style={styles.text}>Autorizó {delivery.autorizado_por?.name} · {date(delivery.autorizado_at)}: {delivery.motivo_autorizacion}</Text> : null}
    {(delivery.retiros || []).map(r => <Text key={r.id} style={styles.text}>{r.numero_folio || 'Folio registrado'} · {r.cantidad_retirada}</Text>)}
    {(delivery.regularizaciones || []).map(e => <View key={e.id}><Text style={styles.text}>Regularizó {e.usuario?.name} · {date(e.ocurrido_at)}: {e.motivo}{e.fuera_plazo ? ' · Fuera de plazo' : ''}</Text><Text style={styles.text}>Documento anterior: {e.antes.numero_documento || 'Pendiente'} · Resultante: {e.despues.numero_documento}</Text></View>)}
    {canAuthorize && ['pendiente', 'vencida'].includes(delivery.estado) ? <Pressable accessibilityRole="button" disabled={busy} onPress={() => setOpen(!open)} style={styles.button}><Text style={styles.text}>Regularizar respaldo</Text></Pressable> : null}
    {open ? <>
      <Text style={styles.text}>Conserva folios, cantidades, reservas y movimientos de la entrega ejecutada.</Text>
      <TextInput accessibilityLabel="Documento para regularizar entrega" value={number} onChangeText={setNumber} maxLength={50} style={styles.input} />
      <TextInput accessibilityLabel="Emisión para regularizar entrega" placeholder="YYYY-MM-DD" placeholderTextColor={colors.muted} value={issued} onChangeText={setIssued} maxLength={10} style={styles.input} />
      <TextInput accessibilityLabel="Motivo para regularizar entrega" value={reason} onChangeText={setReason} multiline maxLength={1000} style={styles.input} />
      {late ? <Pressable accessibilityRole="checkbox" accessibilityState={{ checked: accepted }} onPress={() => setAccepted(!accepted)} style={styles.button}><Text style={styles.text}>{accepted ? '✓ ' : ''}Regularizo expresamente fuera de cinco días corridos</Text></Pressable> : null}
      {error ? <Text style={styles.text} accessibilityRole="alert">{error}</Text> : null}
      <Pressable accessibilityRole="button" disabled={busy} onPress={() => void save()} style={styles.button}><Text style={styles.text}>{busy ? 'Guardando…' : 'Guardar regularización'}</Text></Pressable>
    </> : null}
  </View>;
}

const styles = StyleSheet.create({ box: { padding: 12, gap: 12, borderWidth: 1, borderColor: colors.border, borderRadius: 12 }, title: { color: colors.text, fontSize: 18, fontWeight: '700' }, text: { color: colors.text, fontSize: 16 }, input: { minHeight: 48, padding: 12, borderWidth: 1, borderColor: colors.border, borderRadius: 8, color: colors.text }, button: { minHeight: 48, padding: 12, borderWidth: 1, borderColor: colors.border, borderRadius: 8, justifyContent: 'center' } });
