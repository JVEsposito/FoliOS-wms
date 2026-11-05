import { font } from '../../theme/typography';
import { forwardRef, useEffect, useImperativeHandle, useRef, useState } from 'react';
import { Pressable, StyleProp, StyleSheet, Text, TextInput, TextStyle, View } from 'react-native';

import { isPdaBuild } from '../../config/appVariant';
import { colors } from '../../theme/colors';

type Props = {
  value: string;
  onChangeText: (value: string) => void;
  onSubmit: (value: string) => void;
  placeholder: string;
  submitLabel: string;
  disabled?: boolean;
  autoFocus?: boolean;
  inputStyle?: StyleProp<TextStyle>;
  accessibilityLabel?: string;
};

export type ScanInputHandle = { focus: () => void };

/**
 * Campo de lectura para códigos de folio o REC.
 *
 * En la PDA (Unitech EA520) el lector integrado escribe el código como teclado y
 * termina con Enter. Por eso el campo no abre el teclado en pantalla al recibir
 * el foco, conserva el foco después de cada lectura y selecciona el texto para
 * que el siguiente disparo reemplace al anterior. El botón ⌨ habilita el
 * teclado cuando hay que digitar a mano una etiqueta ilegible.
 */
export const ScanInput = forwardRef<ScanInputHandle, Props>(function ScanInput(
  { value, onChangeText, onSubmit, placeholder, submitLabel, disabled, autoFocus, inputStyle, accessibilityLabel },
  ref,
) {
  const input = useRef<TextInput>(null);
  const initialFocusDone = useRef(false);
  const pendingSelection = useRef<string | null>(null);
  const disabledRef = useRef(disabled);
  disabledRef.current = disabled;
  const [manualKeyboard, setManualKeyboard] = useState(!isPdaBuild);
  useImperativeHandle(ref, () => ({ focus: () => input.current?.focus() }));

  useEffect(() => {
    if (autoFocus && !disabled && !initialFocusDone.current) {
      initialFocusDone.current = true;
      input.current?.focus();
    }
  }, [autoFocus, disabled]);

  useEffect(() => {
    if (!isPdaBuild || disabled || pendingSelection.current === null) return;
    // Android no devuelve el foco mientras el TextInput está deshabilitado.
    const timer = setTimeout(restoreScannerFocus, 60);
    return () => clearTimeout(timer);
  }, [disabled]);

  function restoreScannerFocus() {
    if (disabledRef.current || pendingSelection.current === null) return;
    const code = pendingSelection.current;
    input.current?.focus();
    input.current?.setSelection?.(0, code.length);
    pendingSelection.current = null;
  }

  function submit(rawValue = value) {
    if (disabled) return;
    const code = normalizeScannedCode(rawValue);
    if (!code) return;
    if (code !== value) onChangeText(code);
    if (isPdaBuild) pendingSelection.current = code;
    onSubmit(code);
    if (isPdaBuild) {
      // Si la consulta ya terminó, prepara la próxima lectura. Si sigue
      // ocupada, el efecto lo hará al volver a habilitar el campo.
      setTimeout(restoreScannerFocus, 120);
    }
  }

  return (
    <View style={styles.row}>
      <TextInput
        ref={input}
        accessibilityLabel={accessibilityLabel ?? placeholder}
        autoCapitalize="characters"
        autoCorrect={false}
        autoFocus={autoFocus}
        blurOnSubmit={!isPdaBuild}
        editable={!disabled}
        onChangeText={onChangeText}
        onSubmitEditing={(event) => submit(event.nativeEvent.text)}
        placeholder={placeholder}
        placeholderTextColor={colors.muted}
        returnKeyType="search"
        selectTextOnFocus
        showSoftInputOnFocus={manualKeyboard}
        style={[styles.input, isPdaBuild && styles.inputPda, inputStyle]}
        value={value}
      />
      {isPdaBuild ? (
        <Pressable
          accessibilityLabel={manualKeyboard ? 'Ocultar teclado y volver al lector' : 'Digitar a mano'}
          onPress={() => {
            setManualKeyboard((current) => !current);
            input.current?.blur();
            setTimeout(() => input.current?.focus(), 60);
          }}
          style={[styles.keyboard, manualKeyboard && styles.keyboardActive]}
        >
          <Text style={[styles.keyboardText, manualKeyboard && styles.keyboardTextActive]}>⌨</Text>
        </Pressable>
      ) : null}
      <Pressable
        disabled={disabled || !value.trim()}
        onPress={() => submit()}
        style={[styles.submit, isPdaBuild && styles.submitPda, (disabled || !value.trim()) && styles.disabled]}
      >
        <Text style={styles.submitText}>{submitLabel}</Text>
      </Pressable>
    </View>
  );
});

/** Quita espacios y saltos que algunos lectores agregan como prefijo o sufijo. */
export function normalizeScannedCode(value: string): string {
  return value.replace(/[\r\n\t]/g, '').trim().toUpperCase();
}

const styles = StyleSheet.create({
  row: { flexDirection: 'row', alignItems: 'stretch', gap: 8 },
  input: {
    flex: 1,
    minWidth: 0,
    minHeight: 56,
    paddingHorizontal: 14,
    borderRadius: 12,
    borderWidth: 2,
    borderColor: colors.cyan,
    color: colors.text,
    backgroundColor: colors.backgroundDeep,
    fontSize: 21,
    fontFamily: font.monoBold,
    letterSpacing: 1,
  },
  inputPda: { minHeight: 52, paddingHorizontal: 10, fontSize: 18, letterSpacing: 0.4 },
  keyboard: { width: 44, alignItems: 'center', justifyContent: 'center', borderRadius: 10, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.backgroundDeep },
  keyboardActive: { borderColor: colors.cyan, backgroundColor: colors.selected },
  keyboardText: { fontFamily: font.regular, color: colors.muted, fontSize: 20 },
  keyboardTextActive: { color: colors.cyan },
  submit: { minWidth: 118, alignItems: 'center', justifyContent: 'center', paddingHorizontal: 14, borderRadius: 12, backgroundColor: colors.cyan },
  submitPda: { minWidth: 0, paddingHorizontal: 12, borderRadius: 10 },
  submitText: { color: colors.accentText, fontSize: 13, fontFamily: font.heavy },
  disabled: { opacity: 0.45 },
});
