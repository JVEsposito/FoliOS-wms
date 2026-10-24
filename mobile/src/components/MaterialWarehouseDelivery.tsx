import * as Crypto from 'expo-crypto';
import { useRef, useState } from 'react';
import { Pressable, StyleSheet, Text, TextInput, View } from 'react-native';
import { AuthSession, MaterialDispatch, MaterialDispatchItem, MaterialDispatchRegistrationInput, WithdrawMaterialPayload } from '../domain/estiba';
import { EstibaApi } from '../services/estibaApi';
import { colors } from '../theme/colors';
import { MaterialDispatchRegistrationFields } from './MaterialDispatchRegistration';

type Props = { dispatch: MaterialDispatch; item: MaterialDispatchItem; api: EstibaApi; auth: AuthSession; onChange: (dispatch: MaterialDispatch) => void };

/** Las bodegas sin plano verifican el folio; las cámaras conservan su sesión de estiba. */
export function MaterialWarehouseDelivery({ dispatch, item, api, auth, onChange }: Props) {
  const [open, setOpen] = useState(false);
  const [folio, setFolio] = useState('');
  const [amount, setAmount] = useState('');
  const [registration, setRegistration] = useState<MaterialDispatchRegistrationInput>({});
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const sending = useRef(false);
  const retry = useRef<{ key: string; id: string } | null>(null);
  const suggestion = item.sugerencias_fifo[0];
  const allowed = auth.usuario.capacidades.puede_retirar_materiales === true
    && dispatch.almacen_origen?.requiere_ubicacion_fisica === false
    && (!dispatch.asignado_a_user_id || String(dispatch.asignado_a_user_id) === String(auth.usuario.id))
    && ['pendiente', 'parcial'].includes(dispatch.estado);
  if (!allowed || !suggestion) return null;
  const quantity = Number(amount.replace(',', '.'));
  const maximum = Math.min(Number(suggestion.cantidad), Number(item.cantidad_pendiente));
  const matches = folio.trim().toUpperCase() === suggestion.numero_folio.trim().toUpperCase();
  const valid = matches && Number.isFinite(quantity) && quantity > 0 && quantity <= maximum
    && /^\d+(?:[.,]\d{1,3})?$/.test(amount.trim());

  async function submit() {
    if (sending.current || !valid || !suggestion || !allowed) return;
    sending.current = true; setBusy(true); setError('');
    const registro = Object.fromEntries(Object.entries(registration).filter(([, value]) => value !== '' && value !== false && value !== undefined));
    const body = { retiros: [{ folio_id: suggestion.folio_id, cantidad: quantity }], ...(Object.keys(registro).length ? { registro } : {}) };
    const key = JSON.stringify(body);
    if (retry.current?.key !== key) retry.current = { key, id: Crypto.randomUUID() };
    const payload: WithdrawMaterialPayload = { ...body, operacion_id: retry.current.id };
    try {
      const updated = await api.withdrawMaterial(auth.token, dispatch.id, payload);
      onChange(updated); retry.current = null; setOpen(false); setFolio(''); setAmount(''); setRegistration({});
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'No fue posible confirmar la entrega. Reintenta con los mismos datos.'); }
    finally { sending.current = false; setBusy(false); }
  }

  return <View style={styles.box}>
    <Pressable accessibilityRole="button" disabled={busy} onPress={() => setOpen(!open)} style={styles.button}><Text style={styles.buttonText}>{open ? 'Cerrar confirmación' : 'Entregar desde esta bodega'}</Text></Pressable>
    {open ? <>
      <Text style={styles.text}>Origen: {dispatch.almacen_origen?.nombre}. Verifica físicamente el folio FIFO {suggestion.numero_folio} antes de entregar.</Text>
      <TextInput accessibilityLabel="Folio a entregar desde bodega" editable={!busy} autoCapitalize="characters" placeholder="Escanea o escribe el folio" placeholderTextColor={colors.muted} value={folio} onChangeText={setFolio} style={styles.input} />
      <Text style={styles.text}>Cantidad a entregar · máximo {maximum.toLocaleString('es-CL')} {item.unidad_medida}</Text>
      <TextInput accessibilityLabel="Cantidad a entregar desde bodega" editable={!busy} keyboardType="decimal-pad" placeholder="0" placeholderTextColor={colors.muted} value={amount} onChangeText={setAmount} style={styles.input} />
      <View pointerEvents={busy ? 'none' : 'auto'}><MaterialDispatchRegistrationFields value={registration} onChange={setRegistration} canAuthorize={auth.usuario.capacidades.puede_autorizar_despachos_tardios_materiales === true} /></View>
      {error ? <Text accessibilityRole="alert" style={styles.error}>{error}</Text> : null}
      <Pressable accessibilityRole="button" disabled={busy || !valid} onPress={() => void submit()} style={[styles.button, (busy || !valid) && styles.disabled]}><Text style={styles.buttonText}>{busy ? 'Confirmando entrega…' : 'Confirmar entrega de bodega'}</Text></Pressable>
    </> : null}
  </View>;
}

const styles = StyleSheet.create({
  box: { padding: 12, gap: 10, borderTopWidth: 1, borderColor: colors.border },
  text: { color: colors.text, fontSize: 14, lineHeight: 20 },
  input: { minHeight: 48, padding: 12, borderRadius: 8, borderWidth: 1, borderColor: colors.border, color: colors.text, backgroundColor: colors.panel, fontSize: 16 },
  button: { minHeight: 48, padding: 12, alignItems: 'center', justifyContent: 'center', borderRadius: 8, backgroundColor: colors.cyan },
  buttonText: { color: colors.accentText, fontSize: 14, fontWeight: '800' },
  disabled: { opacity: 0.45 },
  error: { color: colors.red, fontSize: 14 },
});
