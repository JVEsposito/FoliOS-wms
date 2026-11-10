import * as Crypto from 'expo-crypto';
import { useRef, useState } from 'react';
import { ActivityIndicator, Pressable, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
import { ScanInput } from '../components/ui/ScanInput';
import { AuthSession } from '../domain/estiba';
import { RepalletizingFolio } from '../domain/repaletizaje';
import { RepackingResult, repackingBalance } from '../domain/repacking';
import { createRepalletizing, findRepalletizingFolio } from '../services/repaletizajeApi';
import { colors } from '../theme/colors';

type Props = { auth: AuthSession; baseUrl: string; shift: 'A' | 'B' | null; chooseShift: (s: 'A' | 'B') => void;
  day: 'hoy' | 'ayer'; chooseDay: (s: 'hoy' | 'ayer') => void; onConfirmed: () => Promise<void> };
const kg = (units: number) => `${new Intl.NumberFormat('es-CL', { maximumFractionDigits: 4 }).format(units / 10000)} kg`;
export function RepackingEditor({ auth, baseUrl, shift, chooseShift, day, chooseDay, onConfirmed }: Props) {
  const [lookup, setLookup] = useState(''); const [source, setSource] = useState<RepalletizingFolio | null>(null);
  const [outputs, setOutputs] = useState<RepackingResult[]>([]); const [losses, setLosses] = useState<Record<string, string>>({});
  const [reason, setReason] = useState(''); const [busy, setBusy] = useState(false); const [error, setError] = useState(''); const [message, setMessage] = useState('');
  const operation = useRef<{ signature: string; id: string } | null>(null);
  const balance = source?.reembalaje ? repackingBalance(source.reembalaje, outputs, losses) : null;
  const makeResult = (number = ''): RepackingResult => ({ key: Crypto.randomUUID(), numero_folio: number, tipo_resultado: 'saldo', cantidad_objetivo: '120', envase_validacion_id: '', cantidades: {} });
  const change = (key: string, patch: Partial<RepackingResult>) => setOutputs((rows) => rows.map((r) => r.key === key ? { ...r, ...patch } : r));
  async function find(number: string) {
    if (busy || !number.trim()) return;
    setBusy(true); setError(''); setMessage(''); setSource(null); setOutputs([]);
    try {
      const loaded = await findRepalletizingFolio(baseUrl, auth.token, number.trim(), true);
      if (!loaded.existe || !loaded.activo || loaded.cantidad_cajas < 1) throw Error('Consulta un pallet o saldo activo con cajas.');
      if (loaded.desglose_incompleto) throw Error('Regulariza el desglose del MIX antes de reembalar.');
      if (!loaded.reembalaje) throw Error('Actualiza el servidor para habilitar reembalajes.');
      if (loaded.reembalaje.errores.length) throw Error(loaded.reembalaje.errores.join(' '));
      setSource(loaded); setOutputs([makeResult(loaded.numero_folio)]); setLosses({}); setReason(''); operation.current = null;
    } catch (e) { setError(e instanceof Error ? e.message : 'No se pudo consultar el folio.'); } finally { setBusy(false); }
  }
  async function confirm() {
    if (busy || !source?.reembalaje || !balance) return;
    setError('');
    if (!shift) { setError('Selecciona el turno.'); return; }
    if (!balance.balanced) { setError('Deben cuadrar los kilos de cada componente: entrada = salida + merma.'); return; }
    if (outputs.some((r) => !r.numero_folio.trim() || !r.envase_validacion_id)) { setError('Completa el folio y formato de cada salida.'); return; }
    if (new Set(outputs.map((r) => r.numero_folio.trim().toUpperCase())).size !== outputs.length) { setError('No repitas folios entre las salidas.'); return; }
    if (balance.loss > 0 && !reason.trim()) { setError('Indica el motivo de la merma.'); return; }
    const payload = { modalidad: 'reembalaje' as const, turno: shift, fecha_operacional: shift === 'B' ? day : 'hoy',
      huella_reembalaje: source.reembalaje.huella, origenes: [{ folio_id: source.id, cantidad_aportada: source.cantidad_cajas, composicion: source.reembalaje.componentes.map((c) => ({ clave: c.clave, cantidad_aportada: c.cantidad_cajas })) }],
      resultados: outputs.map((r) => ({ numero_folio: r.numero_folio.trim().toUpperCase(), tipo_resultado: r.tipo_resultado,
        cantidad_objetivo: Number(r.cantidad_objetivo || 0) || null, envase_validacion_id: r.envase_validacion_id,
        cantidad_resultante: Object.values(r.cantidades).reduce((n, c) => n + Number(c || 0), 0),
        composicion: Object.entries(r.cantidades).filter(([, c]) => Number(c) > 0).map(([clave, c]) => ({ clave, cantidad_cajas: Number(c) })) })),
      mermas: source.reembalaje.componentes.map((c) => ({ clave: c.clave, kilos: losses[c.clave] || '0' })), motivo_merma: reason.trim() || null, observacion: null };
    const signature = JSON.stringify(payload);
    if (operation.current?.signature !== signature) operation.current = { signature, id: Crypto.randomUUID() };
    setBusy(true);
    try {
      const result = await createRepalletizing(baseUrl, auth.token, { ...payload, operacion_id: operation.current.id });
      setMessage(`${result.codigo}: reembalaje confirmado y kilos cuadrados. Imprime las etiquetas del nuevo formato.`);
      setSource(null); setOutputs([]); setLookup(''); operation.current = null; await onConfirmed();
    } catch (e) { setError(e instanceof Error ? e.message : 'No fue posible confirmar el reembalaje.'); } finally { setBusy(false); }
  }
  return <View style={styles.editor}>
    <Text style={styles.title}>Reembalaje · control de kilos netos</Text>
    <Text style={styles.muted}>Un folio completo puede salir en distintos formatos. Entrada = salida + merma declarada. Para trabajar una parte, divide primero el folio.</Text>
    <View style={styles.row}>{(['A', 'B'] as const).map((s) => <Choice key={s} active={shift === s} label={`Turno ${s}`} onPress={() => chooseShift(s)}/>)}</View>
    {shift === 'B' ? <View style={styles.row}>{(['hoy', 'ayer'] as const).map((d) => <Choice key={d} active={day === d} label={d === 'hoy' ? 'Hoy' : 'Ayer'} onPress={() => chooseDay(d)}/>)}</View> : null}
    <ScanInput value={lookup} onChangeText={setLookup} onSubmit={(code) => void find(code)} disabled={busy} placeholder="Folio de origen" submitLabel="Consultar"/>
    {busy ? <ActivityIndicator color={colors.cyan}/> : null}{error ? <Text accessibilityRole="alert" style={styles.error}>{error}</Text> : null}{message ? <Text style={styles.success}>{message}</Text> : null}
    {source?.reembalaje && balance ? <>
      <Text style={styles.title}>{source.numero_folio} · {source.cliente}</Text><Text style={styles.muted}>{source.cantidad_cajas} cajas · {kg(balance.input)} de entrada</Text>
      {outputs.map((r, index) => <View key={r.key} style={styles.card}>
        <Text style={styles.title}>Salida {index + 1}</Text>
        {index === 0 ? <View style={styles.row}><Choice active={r.numero_folio === source.numero_folio} label="Conservar folio" onPress={() => change(r.key, { numero_folio: source.numero_folio })}/><Choice active={r.numero_folio !== source.numero_folio} label="Otro folio" onPress={() => change(r.key, { numero_folio: '' })}/></View> : null}
        <TextInput accessibilityLabel={`Folio de salida ${index + 1}`} style={styles.input} value={r.numero_folio} maxLength={80} onChangeText={(v) => change(r.key, { numero_folio: v.toUpperCase() })}/>
        <Text style={styles.muted}>Formato de salida</Text><ScrollView horizontal>{source.reembalaje!.formatos.map((f) => <Choice key={f.id} active={r.envase_validacion_id === f.id} label={`${f.nombre} · ${f.kilos_netos_por_caja} kg`} onPress={() => change(r.key, { envase_validacion_id: f.id })}/>)}</ScrollView>
        <View style={styles.row}><Choice active={r.tipo_resultado === 'pallet'} label="Pallet completo" onPress={() => change(r.key, { tipo_resultado: 'pallet' })}/><Choice active={r.tipo_resultado === 'saldo'} label="Saldo" onPress={() => change(r.key, { tipo_resultado: 'saldo' })}/></View>
        <Text style={styles.muted}>Capacidad del pallet</Text><TextInput accessibilityLabel={`Capacidad salida ${index + 1}`} keyboardType="number-pad" style={styles.input} value={r.cantidad_objetivo} onChangeText={(v) => change(r.key, { cantidad_objetivo: v.replace(/\D/g, '') })}/>
        {source.reembalaje!.componentes.map((c) => <View key={c.clave} style={styles.count}><Text style={styles.identity}>CSG {c.csg} · {c.variedad} · {c.calibre} · {c.fecha_embalaje}</Text><TextInput accessibilityLabel={`Cajas salida ${index + 1} CSG ${c.csg}`} keyboardType="number-pad" style={styles.quantity} value={r.cantidades[c.clave] || ''} placeholder="Cajas" placeholderTextColor={colors.muted} onChangeText={(v) => change(r.key, { cantidades: { ...r.cantidades, [c.clave]: v.replace(/\D/g, '') } })}/></View>)}
        {index > 0 ? <Choice active={false} label="Quitar salida" onPress={() => setOutputs((rows) => rows.filter((x) => x.key !== r.key))}/> : null}
      </View>)}
      {outputs.length < 20 ? <Choice active={false} label="+ Agregar salida / formato" onPress={() => setOutputs((rows) => [...rows, makeResult()])}/> : null}
      <Text style={styles.title}>Merma por componente</Text><Text style={styles.muted}>Declárala expresamente; no se calcula como ajuste automático.</Text>
      {source.reembalaje.componentes.map((c) => <View key={c.clave} style={styles.card}><Text style={styles.identity}>CSG {c.csg} · {c.variedad} · {c.calibre}</Text><Text style={styles.muted}>{c.cantidad_cajas} cajas × {c.kilos_netos_por_caja} kg · diferencia {kg(balance.rows.find((x) => x.clave === c.clave)!.difference)}</Text><TextInput accessibilityLabel={`Merma kg CSG ${c.csg}`} keyboardType="decimal-pad" style={styles.input} value={losses[c.clave] ?? '0'} onChangeText={(v) => setLosses((old) => ({ ...old, [c.clave]: v.replace(',', '.') }))}/></View>)}
      <Text style={styles.muted}>Motivo de la merma</Text><TextInput accessibilityLabel="Motivo de la merma" multiline maxLength={1000} style={styles.input} value={reason} onChangeText={setReason}/>
      <Text style={[styles.title, balance.balanced ? styles.success : styles.error]}>Entrada {kg(balance.input)} · Salida {kg(balance.output)} · Merma {kg(balance.loss)} · {balance.balanced ? 'Cuadrado' : 'Pendiente de cuadrar'}</Text>
      <Pressable disabled={busy} onPress={() => void confirm()} style={styles.primary}><Text style={styles.primaryText}>Confirmar reembalaje</Text></Pressable>
    </> : null}
  </View>;
}
function Choice({ active, label, onPress }: { active: boolean; label: string; onPress: () => void }) { return <Pressable onPress={onPress} style={[styles.choice, active && styles.active]}><Text style={styles.text}>{label}</Text></Pressable>; }
const styles = StyleSheet.create({ editor: { gap: 14 }, title: { color: colors.text, fontSize: 17, fontWeight: '700' }, muted: { color: colors.muted, fontSize: 13 },
  row: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 }, card: { borderColor: colors.border, borderWidth: 1, borderRadius: 10, padding: 12, gap: 10, backgroundColor: colors.panel },
  input: { minHeight: 48, borderWidth: 1, borderColor: colors.border, borderRadius: 8, color: colors.text, padding: 10, fontSize: 17, backgroundColor: colors.background },
  count: { flexDirection: 'row', alignItems: 'center', gap: 8 }, identity: { color: colors.text, fontSize: 14, flex: 1 }, quantity: { width: 90, minHeight: 48, color: colors.text, borderColor: colors.border, borderWidth: 1, borderRadius: 8, padding: 10, backgroundColor: colors.background },
  choice: { borderWidth: 1, borderColor: colors.border, borderRadius: 8, padding: 12, minHeight: 44, marginRight: 6 }, active: { borderColor: colors.cyan, backgroundColor: colors.panelStrong }, text: { color: colors.text },
  error: { color: colors.red }, success: { color: colors.green }, primary: { backgroundColor: colors.cyan, borderRadius: 8, padding: 16 }, primaryText: { color: colors.accentText, fontWeight: '700', textAlign: 'center' } });
