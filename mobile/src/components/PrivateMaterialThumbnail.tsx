import { useEffect, useState } from 'react';
import { Image, StyleSheet, View } from 'react-native';

export type MaterialItemPhoto = { id: string; miniatura_url: string; url?: string };

/** Autenticación en cabecera; la miniatura se mantiene exclusivamente en memoria. */
export function PrivateMaterialThumbnail({ photo, baseUrl, token }: {
  photo?: MaterialItemPhoto | null;
  baseUrl: string | null;
  token: string;
}) {
  const key = `${baseUrl}:${photo?.miniatura_url}:${token}`;
  const [loaded, setLoaded] = useState<{ key: string; uri: string } | null>(null);
  useEffect(() => {
    if (!baseUrl || !photo || !/^\/api\/materiales\/(fotos-items\/|recepciones\/[a-zA-Z0-9-]+\/fotos\/)/.test(photo.miniatura_url)) return;
    const controller = new AbortController(); let active = true;
    void (async () => {
      try {
        const response = await fetch(`${baseUrl}${photo.miniatura_url}`, {
          headers: { Authorization: `Bearer ${token}`, Accept: 'image/jpeg' }, cache: 'no-store', signal: controller.signal,
        });
        if (!response.ok) return;
        const blob = await response.blob();
        const uri = await new Promise<string>((resolve, reject) => {
          const reader = new FileReader(); reader.onload = () => resolve(String(reader.result));
          reader.onerror = () => reject(new Error('No se pudo leer la miniatura.')); reader.readAsDataURL(blob);
        });
        if (active) setLoaded({ key, uri });
      } catch { /* La operación sigue disponible aunque la foto no pueda descargarse. */ }
    })();
    return () => { active = false; controller.abort(); };
  }, [key, photo?.miniatura_url, baseUrl, token]);
  if (!photo) return null;
  return loaded?.key === key
    ? <Image accessibilityLabel="Foto del ítem" source={{ uri: loaded.uri }} style={styles.image} resizeMode="contain" />
    : <View style={styles.image} />;
}

const styles = StyleSheet.create({ image: { width: 48, height: 48, borderRadius: 6, flexShrink: 0, marginRight: 8 } });
