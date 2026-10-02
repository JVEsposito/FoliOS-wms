import { ContainerOption, containerLabel, getContainerCatalog } from '../services/containerCatalog';
import * as Crypto from 'expo-crypto';
import * as ImagePicker from 'expo-image-picker';
import { useEffect, useState } from 'react';
import { ActivityIndicator, Image, Pressable, StyleSheet, Text, TextInput, View } from 'react-native';

import { ReceptionDefect, ReceptionDefectCategory, ReceptionDefectContainer, ReceptionDefectDraft, ReceptionDefectPhoto } from '../domain/receptionDefects';
import { ApiError } from '../services/apiError';
import { createReceptionDefect, listReceptionDefects } from '../services/receptionDefectsApi';
import { colors } from '../theme/colors';

type Props = { baseUrl: string; token: string; receptionId: string; guideNumber: string };
const categories: Array<{ value: ReceptionDefectCategory; label: string }> = [
  { value: 'envase_danado', label: 'Envase dañado' },
  { value: 'envase_sucio', label: 'Envase sucio' },
  { value: 'producto_danado', label: 'Producto dañado' },
  { value: 'otro', label: 'Otro defecto' },
];

const maximumPhotoBytes = 5 * 1024 * 1024;

function blankDraft(): ReceptionDefectDraft {
  return { operacionId: Crypto.randomUUID(), categoria: 'envase_danado', tipoEnvase: null,
    cantidadAfectada: '', descripcion: '', fotografias: [], fotografiaGuia: null };
}

function asPhoto(asset: ImagePicker.ImagePickerAsset): ReceptionDefectPhoto {
  const type = asset.mimeType ?? (asset.uri.toLowerCase().endsWith('.png') ? 'image/png' : 'image/jpeg');
  if (type !== 'image/jpeg' && type !== 'image/png' && type !== 'image/webp') {
    throw new Error('La foto debe ser JPG, PNG o WebP. Toma otra foto con la cámara.');
  }
  if (asset.fileSize && asset.fileSize > maximumPhotoBytes) {
    throw new Error(`La foto pesa ${(asset.fileSize / 1024 / 1024).toFixed(1)} MB y supera el máximo de 5 MB. Elige otra imagen o vuelve a tomarla.`);
  }
  return { uri: asset.uri, name: `recepcion-${Crypto.randomUUID()}.${type === 'image/png' ? 'png' : type === 'image/webp' ? 'webp' : 'jpg'}`, type, fileSize: asset.fileSize };
}

export function ReceptionDefectsPanel({ baseUrl, token, receptionId, guideNumber }: Props) {
  const [containers, setContainers] = useState<ContainerOption[]>([]);
  const [records, setRecords] = useState<ReceptionDefect[]>([]);
  const [draft, setDraft] = useState<ReceptionDefectDraft>(blankDraft);
  const [showForm, setShowForm] = useState(false);
  const [loading, setLoading] = useState(true);
  const [sending, setSending] = useState(false);
  const [uncertain, setUncertain] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    let active = true;
    void getContainerCatalog(baseUrl, token).then((items) => { if (active) setContainers(items); }).catch((reason) => { if (active) setError(message(reason)); });
    void listReceptionDefects(baseUrl, token, receptionId)
      .then((items) => { if (active) setRecords(items); })
      .catch((reason) => { if (active) setError(message(reason)); })
      .finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, [baseUrl, token, receptionId]);

  function change(patch: Partial<ReceptionDefectDraft>) {
    if (sending || uncertain) return;
    setDraft((current) => ({ ...current, ...patch, operacionId: Crypto.randomUUID() }));
    setError('');
  }

  async function pickPhoto(forGuide: boolean, fromCamera: boolean) {
    if (sending || uncertain) return;
    if (!forGuide && draft.fotografias.length >= 3) return;
    try {
      if (fromCamera) {
        const permission = await ImagePicker.requestCameraPermissionsAsync();
        if (!permission.granted) throw new Error('Autoriza la cámara para fotografiar el defecto.');
      }
      const result = fromCamera
        ? await ImagePicker.launchCameraAsync({ mediaTypes: ['images'], quality: 0.35, allowsEditing: false })
        : await ImagePicker.launchImageLibraryAsync({ mediaTypes: ['images'], quality: 0.35, allowsEditing: false });
      if (result.canceled || !result.assets[0]) return;
      const photo = asPhoto(result.assets[0]);
      change(forGuide ? { fotografiaGuia: photo } : { fotografias: [...draft.fotografias, photo] });
    } catch (reason) { setError(message(reason)); }
  }

  async function refresh() {
    setLoading(true); setError('');
    try { setRecords(await listReceptionDefects(baseUrl, token, receptionId)); }
    catch (reason) { setError(message(reason)); }
    finally { setLoading(false); }
  }

  async function submit() {
    if (sending) return;
    if (!draft.descripcion.trim()) { setError('Describe el defecto antes de guardar.'); return; }
    if (!draft.fotografias.length) { setError('Agrega al menos una foto del defecto.'); return; }
    if (draft.cantidadAfectada && (!/^\d+$/.test(draft.cantidadAfectada) || Number(draft.cantidadAfectada) < 1 || Number(draft.cantidadAfectada) > 100000)) {
      setError('La cantidad afectada debe estar entre 1 y 100000.'); return;
    }
    setSending(true); setError('');
    try {
      const saved = await createReceptionDefect(baseUrl, token, receptionId, draft);
      setRecords((previous) => [saved, ...previous.filter((item) => item.id !== saved.id)]);
      setDraft(blankDraft()); setShowForm(false); setUncertain(false);
    } catch (reason) {
      // El servidor puede haber guardado la foto antes de perder la conexión.
      // Conservar el mismo UUID y las mismas imágenes para reintentar sin duplicar.
      if (reason instanceof ApiError && reason.status === 0) setUncertain(true);
      setError(message(reason));
    } finally { setSending(false); }
  }

  return <View style={styles.panel}>
    <View style={styles.heading}>
      <View style={styles.headingCopy}><Text style={styles.eyebrow}>EVIDENCIA DE RECEPCIÓN</Text><Text style={styles.title}>Defectos detectados</Text><Text style={styles.muted}>Guía {guideNumber} · el registro no modifica el conteo de envases.</Text></View>
      <Pressable accessibilityRole="button" onPress={() => void refresh()} style={styles.outline}><Text style={styles.outlineText}>Actualizar</Text></Pressable>
    </View>
    {loading ? <ActivityIndicator color={colors.cyan}/> : null}
    {records.map((item) => <View key={item.id} style={styles.record}>
      <Text style={styles.recordTitle}>{categories.find((category) => category.value === item.categoria)?.label ?? item.categoria}{item.tipo_envase ? ` · ${containerLabel(item.tipo_envase) ?? item.tipo_envase}` : ''}</Text>
      <Text style={styles.body}>{item.descripcion}</Text>
      <Text style={styles.muted}>{item.cantidad_afectada ? `${item.cantidad_afectada} afectados · ` : ''}{item.evidencias.filter((photo) => photo.tipo === 'defecto').length} foto(s) · {new Date(item.registrado_at).toLocaleString('es-CL')}</Text>
    </View>)}
    {!loading && records.length === 0 ? <Text style={styles.muted}>Aún no se han registrado defectos en esta recepción.</Text> : null}
    {!showForm ? <Pressable accessibilityRole="button" onPress={() => { setDraft(blankDraft()); setUncertain(false); setError(''); setShowForm(true); }} style={styles.primary}><Text style={styles.primaryText}>+ Registrar defecto</Text></Pressable> : <View style={styles.form}>
      <Text style={styles.title}>Nuevo defecto</Text>
      <Text style={styles.muted}>Categoría *</Text>
      <View style={styles.options}>{categories.map((category) => <Choice key={category.value} label={category.label} selected={draft.categoria === category.value} onPress={() => change({ categoria: category.value })} disabled={sending || uncertain}/>)}</View>
      <Text style={styles.muted}>Tipo de envase (si corresponde)</Text>
      <View style={styles.options}><Choice label="No aplica" selected={!draft.tipoEnvase} onPress={() => change({ tipoEnvase: null })} disabled={sending || uncertain}/>{containers.map((container) => <Choice key={container.codigo} label={container.nombre} selected={draft.tipoEnvase === container.codigo} onPress={() => change({ tipoEnvase: container.codigo })} disabled={sending || uncertain}/>)}</View>
      <Text style={styles.muted}>Cantidad afectada (opcional)</Text>
      <TextInput editable={!sending && !uncertain} keyboardType="number-pad" onChangeText={(value) => change({ cantidadAfectada: value.replace(/\D/g, '') })} placeholder="Ej. 2" placeholderTextColor={colors.muted} style={styles.input} value={draft.cantidadAfectada}/>
      <Text style={styles.muted}>Descripción del defecto *</Text>
      <TextInput editable={!sending && !uncertain} maxLength={2000} multiline onChangeText={(value) => change({ descripcion: value })} placeholder="Describe qué se encontró y dónde" placeholderTextColor={colors.muted} style={[styles.input, styles.description]} textAlignVertical="top" value={draft.descripcion}/>
      <Text style={styles.muted}>Fotos del defecto * ({draft.fotografias.length}/3, hasta 5 MB cada una)</Text>
      <View style={styles.options}><Pressable disabled={sending || uncertain || draft.fotografias.length >= 3} onPress={() => void pickPhoto(false, true)} style={styles.outline}><Text style={styles.outlineText}>Tomar foto</Text></Pressable><Pressable disabled={sending || uncertain || draft.fotografias.length >= 3} onPress={() => void pickPhoto(false, false)} style={styles.outline}><Text style={styles.outlineText}>Elegir imagen</Text></Pressable></View>
      <View style={styles.previews}>{draft.fotografias.map((photo, index) => <View key={photo.uri} style={styles.preview}><Image source={{ uri: photo.uri }} style={styles.photo}/><Pressable disabled={sending || uncertain} onPress={() => change({ fotografias: draft.fotografias.filter((_, position) => position !== index) })}><Text style={styles.remove}>Quitar foto {index + 1}</Text></Pressable></View>)}</View>
      <Text style={styles.muted}>Foto de la guía (opcional)</Text>
      <View style={styles.options}><Pressable disabled={sending || uncertain} onPress={() => void pickPhoto(true, true)} style={styles.outline}><Text style={styles.outlineText}>Fotografiar guía</Text></Pressable><Pressable disabled={sending || uncertain} onPress={() => void pickPhoto(true, false)} style={styles.outline}><Text style={styles.outlineText}>Elegir imagen</Text></Pressable></View>
      {draft.fotografiaGuia ? <View style={styles.preview}><Image source={{ uri: draft.fotografiaGuia.uri }} style={styles.photo}/><Pressable disabled={sending || uncertain} onPress={() => change({ fotografiaGuia: null })}><Text style={styles.remove}>Quitar guía</Text></Pressable></View> : null}
      {error ? <Text style={styles.error}>{error}</Text> : null}
      {uncertain ? <Text style={styles.warning}>El envío podría haberse guardado. Conserva este formulario y reintenta con los mismos datos cuando vuelva la conexión.</Text> : null}
      <View style={styles.actions}><Pressable disabled={sending || uncertain} onPress={() => { setShowForm(false); setError(''); }} style={styles.outline}><Text style={styles.outlineText}>Cancelar</Text></Pressable><Pressable disabled={sending} onPress={() => void submit()} style={styles.primary}><Text style={styles.primaryText}>{sending ? 'Enviando fotos…' : uncertain ? 'Reintentar envío' : 'Guardar defecto'}</Text></Pressable></View>
    </View>}
    {!showForm && error ? <Text style={styles.error}>{error}</Text> : null}
  </View>;
}

function Choice({ label, selected, onPress, disabled }: { label: string; selected: boolean; onPress: () => void; disabled: boolean }) {
  return <Pressable accessibilityRole="button" disabled={disabled} onPress={onPress} style={[styles.choice, selected && styles.choiceSelected]}><Text style={[styles.choiceText, selected && styles.choiceSelectedText]}>{label}</Text></Pressable>;
}
function message(reason: unknown) { return reason instanceof Error ? reason.message : 'No fue posible completar la operación.'; }

const styles = StyleSheet.create({
  panel: { padding: 14, borderRadius: 14, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.panel, gap: 12 },
  heading: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'flex-start', gap: 12 },
  headingCopy: { flex: 1 }, eyebrow: { color: colors.cyan, fontSize: 14, fontWeight: '900', letterSpacing: 1.1 },
  title: { color: colors.text, fontSize: 17, fontWeight: '900', marginTop: 3 }, muted: { color: colors.muted, fontSize: 12, marginTop: 3 },
  body: { color: colors.text, fontSize: 14, marginTop: 4 }, record: { padding: 12, borderRadius: 10, backgroundColor: colors.backgroundDeep, borderWidth: 1, borderColor: colors.border },
  recordTitle: { color: colors.cyan, fontSize: 14, fontWeight: '900' },
  form: { padding: 12, borderRadius: 10, borderWidth: 1, borderColor: colors.cyanDark, backgroundColor: colors.backgroundDeep, gap: 10 },
  options: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  choice: { borderWidth: 1, borderColor: colors.border, borderRadius: 9, paddingHorizontal: 12, paddingVertical: 9 },
  choiceSelected: { backgroundColor: colors.cyan, borderColor: colors.cyan }, choiceText: { color: colors.text, fontWeight: '700' },
  choiceSelectedText: { color: colors.accentText },
  input: { padding: 12, color: colors.text, fontSize: 15, borderWidth: 1, borderColor: colors.border, borderRadius: 9 },
  description: { height: 90 }, previews: { flexDirection: 'row', flexWrap: 'wrap', gap: 10 }, preview: { alignItems: 'center', gap: 5 },
  photo: { width: 104, height: 104, borderRadius: 9 }, remove: { color: colors.red, fontSize: 12, fontWeight: '800' },
  primary: { paddingHorizontal: 16, paddingVertical: 12, borderRadius: 9, backgroundColor: colors.cyan, alignItems: 'center' },
  primaryText: { color: colors.accentText, fontWeight: '900' }, outline: { paddingHorizontal: 12, paddingVertical: 10, borderRadius: 9, borderWidth: 1, borderColor: colors.cyanDark, alignItems: 'center' },
  outlineText: { color: colors.cyan, fontWeight: '800' }, actions: { flexDirection: 'row', justifyContent: 'flex-end', gap: 8, flexWrap: 'wrap' },
  error: { color: colors.red, fontWeight: '800', padding: 8 }, warning: { color: colors.text, padding: 10, borderRadius: 8, backgroundColor: colors.panel },
});
