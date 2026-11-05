import { useEffect, useRef } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';

import { operatorTheme as o } from '../../theme/operatorTheme';

type Props = {
  /** En pantallas con acciones abajo (maniobra en ejecución) va en el flujo, no encima. */
  inline?: boolean;
  message: string;
  onDismiss: () => void;
};

const MIN_MS = 5_000;
const MAX_MS = 12_000;
const MS_PER_CHARACTER = 60;
const SUCCESS = /completad|confirmad|reservad|liberad|guardad/i;

/**
 * Aviso de nivel 1 (informativo): aparece abajo a la derecha, nunca tapa la maniobra y se
 * cierra solo. El tiempo crece con el largo del texto para que las indicaciones más largas
 * («No repitas el movimiento…») alcancen a leerse; tocarlo lo cierra antes.
 * Ver docs/diseno/folios-v2/pr-12-tablet-portada.md.
 */
export function OperatorToast({ inline = false, message, onDismiss }: Props) {
  // La bandeja se vuelve a dibujar con cada consulta; el plazo depende solo del mensaje.
  const dismiss = useRef(onDismiss);
  dismiss.current = onDismiss;
  useEffect(() => {
    if (!message) return;
    const timer = setTimeout(() => dismiss.current(), toastDuration(message));
    return () => clearTimeout(timer);
  }, [message]);

  if (!message) return null;
  const success = SUCCESS.test(message);

  return (
    <View pointerEvents="box-none" style={inline ? styles.inline : styles.layer}>
      <Pressable
        accessibilityHint="Toca para cerrar"
        accessibilityLiveRegion="polite"
        accessibilityRole="alert"
        onPress={onDismiss}
        style={[styles.toast, inline && styles.toastInline, success ? styles.toastSuccess : styles.toastInfo]}
      >
        <View style={[styles.mark, success ? styles.markSuccess : styles.markInfo]}>
          <Text style={[styles.markText, success ? styles.markTextSuccess : styles.markTextInfo]}>{success ? '✓' : 'i'}</Text>
        </View>
        <Text style={styles.text}>{message}</Text>
      </Pressable>
    </View>
  );
}

export function toastDuration(message: string) {
  return Math.min(MAX_MS, Math.max(MIN_MS, message.length * MS_PER_CHARACTER));
}

const styles = StyleSheet.create({
  // Va antes del contenido en el árbol: zIndex y elevation lo dejan por encima.
  layer: { position: 'absolute', right: o.space[4], bottom: o.space[4], left: o.space[4], alignItems: 'flex-end', zIndex: 10, elevation: 10 },
  toast: {
    width: 380,
    maxWidth: '100%',
    minHeight: o.touch.prominent,
    paddingHorizontal: o.space[4],
    paddingVertical: o.space[3],
    flexDirection: 'row',
    alignItems: 'center',
    gap: o.space[3],
    borderRadius: o.radius.panel,
    borderWidth: 1,
    shadowColor: o.color.shadow,
    shadowOpacity: 0.35,
    shadowRadius: 12,
    shadowOffset: { width: 0, height: 6 },
    elevation: 6,
  },
  inline: { marginBottom: o.space[3] },
  toastInline: { width: '100%', shadowOpacity: 0, elevation: 0 },
  toastSuccess: { backgroundColor: o.color.successSurface, borderColor: o.color.successBorder },
  toastInfo: { backgroundColor: o.color.surfaceMuted, borderColor: o.color.border },
  mark: { width: 36, height: 36, borderRadius: o.radius.pill, alignItems: 'center', justifyContent: 'center' },
  markSuccess: { backgroundColor: o.color.success },
  markInfo: { borderWidth: 1, borderColor: o.color.borderStrong },
  markText: { fontSize: o.type.body, fontWeight: '900' },
  markTextSuccess: { color: o.color.successSurface },
  markTextInfo: { color: o.color.textSecondary },
  text: { flex: 1, color: o.color.text, fontSize: o.type.small, lineHeight: 20, fontWeight: '600' },
});
