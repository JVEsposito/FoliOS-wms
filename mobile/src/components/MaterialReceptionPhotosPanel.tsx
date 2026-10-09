import * as Crypto from 'expo-crypto';
import * as ImagePicker from 'expo-image-picker';
import { useEffect, useState } from 'react';
import { ActivityIndicator, Image, TextInput, Pressable, StyleSheet, Text, View } from 'react-native';
import { FotoRecepcion, MaterialReceptionState, TipoFotoRecepcion } from '../domain/materialReception';
import { MaterialReceptionApi } from '../services/materialReceptionApi';
import { PrivateMaterialThumbnail } from './PrivateMaterialThumbnail';
import { colors } from '../theme/colors';

type PendingPhoto = { operationId: string; tipo: TipoFotoRecepcion; archivo: { uri: string; name: string; type: string }; error?: string };
type Props = { receptionId: string; state: MaterialReceptionState; api: MaterialReceptionApi; baseUrl: string; token: string; canManage: boolean; canAdminister: boolean; onChange: (fotos: FotoRecepcion[]) => void };

export function MaterialReceptionPhotosPanel({ receptionId, state, api, baseUrl, token, canManage, canAdminister, onChange }: Props) {
  const [fotos, setFotos] = useState<FotoRecepcion[]>([]);
  const [pending, setPending] = useState<PendingPhoto[]>([]);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [toDelete, setToDelete] = useState<FotoRecepcion | null>(null);
  const [reason, setReason] = useState('');
  const editable = canManage && state !== 'anulada';
  useEffect(() => {
    let active = true;
    setFotos([]); setPending([]); setError('');
    void api.listarFotos(receptionId).then((loaded) => { if (active) { setFotos(loaded); onChange(loaded); } }).catch((reason) => { if (active) setError(message(reason)); });
    return () => { active = false; };
  }, [api, receptionId]);

  async function upload(photo: PendingPhoto) {
    setBusy(true); setError('');
    try {
      const saved = await api.subirFoto(receptionId, photo.tipo, photo.operationId, photo.archivo);
      const loaded = [...fotos.filter((f) => f.id !== saved.id), saved];
      setFotos(loaded); onChange(loaded);
      setPending((current) => current.filter((p) => p.operationId !== photo.operationId));
    } catch (reason) {
      setPending((current) => current.map((p) => p.operationId === photo.operationId ? { ...p, error: message(reason) } : p));
    } finally { setBusy(false); }
  }

  async function pick(tipo: TipoFotoRecepcion, camera: boolean) {
    if (busy || !editable) return;
    try {
      if (camera && !(await ImagePicker.requestCameraPermissionsAsync()).granted) throw new Error('Autoriza la cámara para fotografiar el documento.');
      const options = { mediaTypes: ['images'] as ImagePicker.MediaType[], quality: 0.5, allowsEditing: false };
      const result = camera ? await ImagePicker.launchCameraAsync(options) : await ImagePicker.launchImageLibraryAsync(options);
      if (result.canceled || !result.assets[0]) return;
      const asset = result.assets[0];
      const type = asset.mimeType ?? 'image/jpeg';
      if (!['image/jpeg', 'image/png', 'image/webp'].includes(type)) throw new Error('Usa JPG, PNG o WebP.');
      if ((asset.fileSize ?? 0) > 5 * 1024 * 1024) throw new Error('La foto supera el máximo de 5 MiB.');
      const operationId = Crypto.randomUUID();
      const ext = type === 'image/png' ? 'png' : type === 'image/webp' ? 'webp' : 'jpg';
      const photo = { operationId, tipo, archivo: { uri: asset.uri, name: `${operationId}.${ext}`, type } };
      setPending((current) => [...current, photo]);
      await upload(photo);
    } catch (reason) { setError(message(reason)); }
  }

  async function remove(foto: FotoRecepcion, motivo?: string) {
    setBusy(true); setError('');
    try {
      await api.eliminarFoto(receptionId, foto.id, motivo);
      const loaded = fotos.filter((f) => f.id !== foto.id); setFotos(loaded); onChange(loaded); setToDelete(null); setReason('');
    } catch (reason) { setError(message(reason)); }
    finally { setBusy(false); }
  }

  function requestRemove(foto: FotoRecepcion) {
    if (state === 'confirmada') {
      setToDelete(foto); setReason('');
    } else { void remove(foto); }
  }

  return <View style={styles.panel}>
    <Text style={styles.title}>Fotos</Text>
    {(['documento', 'referencial'] as const).map((tipo) => <View key={tipo} style={styles.group}>
      <Text style={styles.title}>{tipo === 'documento' ? 'Guía / factura (obligatoria)' : 'Foto de la recepción (opcional)'} · {fotos.filter((f) => f.tipo === tipo).length}/5</Text>
      <View style={styles.row}>{fotos.filter((f) => f.tipo === tipo).map((foto) => <View key={foto.id}>
        <PrivateMaterialThumbnail photo={foto} baseUrl={baseUrl} token={token}/>
        {editable && (state === 'borrador' || canAdminister) ? <Pressable disabled={busy} onPress={() => requestRemove(foto)}><Text style={styles.link}>Quitar foto</Text></Pressable> : null}
      </View>)}</View>
      {pending.filter((p) => p.tipo === tipo).map((photo) => <View key={photo.operationId}>
        <Image source={{ uri: photo.archivo.uri }} style={styles.photo}/>
        <Text style={styles.error}>{photo.error ?? 'Subiendo…'}</Text>
        {photo.error ? <Pressable disabled={busy} onPress={() => void upload(photo)}><Text style={styles.link}>Reintentar</Text></Pressable> : null}
      </View>)}
      {editable ? <View style={styles.row}>
        <Pressable disabled={busy || fotos.filter((f) => f.tipo === tipo).length + pending.filter((p) => p.tipo === tipo).length >= 5} onPress={() => void pick(tipo, true)}><Text style={styles.link}>Tomar foto</Text></Pressable>
        <Pressable disabled={busy || fotos.filter((f) => f.tipo === tipo).length + pending.filter((p) => p.tipo === tipo).length >= 5} onPress={() => void pick(tipo, false)}><Text style={styles.link}>Galería</Text></Pressable>
      </View> : null}
    </View>)}
    {toDelete ? <View style={styles.group}>
      <Text style={styles.title}>Motivo de eliminación</Text>
      <TextInput value={reason} onChangeText={setReason} maxLength={2000} placeholder="Indica el motivo obligatorio" placeholderTextColor={colors.muted} style={{ color: colors.text, padding: 10, borderWidth: 1, borderColor: colors.border }}/>
      <Pressable disabled={busy || !reason.trim()} onPress={() => void remove(toDelete, reason)}><Text style={styles.link}>Eliminar con motivo</Text></Pressable>
      <Pressable onPress={() => setToDelete(null)}><Text style={styles.link}>Cancelar</Text></Pressable>
    </View> : null}
    {busy ? <ActivityIndicator color={colors.cyan}/> : null}
    {error ? <Text style={styles.error}>{error}</Text> : null}
  </View>;
}
function message(reason: unknown) { return reason instanceof Error ? reason.message : 'No fue posible completar la operación.'; }
const styles = StyleSheet.create({ panel: { padding: 16, borderWidth: 1, borderColor: colors.border, borderRadius: 12, backgroundColor: colors.panel, gap: 14 }, group: { gap: 10 }, title: { color: colors.text, fontWeight: '800' }, row: { flexDirection: 'row', flexWrap: 'wrap', gap: 16 }, link: { color: colors.cyan, fontWeight: '800', paddingVertical: 8 }, error: { color: colors.red }, photo: { width: 100, height: 100 } });
