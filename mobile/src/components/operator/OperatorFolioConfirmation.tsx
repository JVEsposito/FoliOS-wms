import { useEffect, useState } from 'react';
import { ActivityIndicator, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';

import {
  folioConfirmationLength,
  matchesFolioConfirmation,
  OPERATOR_PIN_LENGTH,
  operatorPinProblem,
  splitFolioForConfirmation,
} from '../../domain/folioConfirmation';
import { operationalTaskPositionLabel, type OperationalTask } from '../../domain/operationalTasks';
import type { OperatorPinStatus, StartConfirmation } from '../../services/operationalTasksApi';
import { operatorTheme as o } from '../../theme/operatorTheme';
import { font } from '../../theme/typography';

export type FolioConfirmationState = {
  pinStatus: OperatorPinStatus | null;
  error: string;
};

type Step = 'folio' | 'pin' | 'new_pin' | 'repeat_pin';

type Props = {
  busy: boolean;
  onCancel: () => void;
  onConfirm: (confirmation: StartConfirmation) => void;
  onCreatePin: (pin: string) => Promise<boolean>;
  onMismatch: () => void;
  state: FolioConfirmationState;
  task: OperationalTask;
};

const MISMATCHES_BEFORE_REPORT = 2;

/**
 * Antes de retirar el pallet: el camarero lee la etiqueta, digita los últimos
 * dígitos del folio y confirma con su PIN. El teclado propio evita el teclado
 * del sistema y mantiene teclas grandes para operar con guantes delgados.
 */
export function OperatorFolioConfirmation({
  busy,
  onCancel,
  onConfirm,
  onCreatePin,
  onMismatch,
  state,
  task,
}: Props) {
  const folioNumber = task.folio?.numero_folio ?? '';
  const folioLength = Math.max(1, folioConfirmationLength(folioNumber));
  const { lead, tail } = splitFolioForConfirmation(folioNumber);
  const [step, setStep] = useState<Step>('folio');
  const [digits, setDigits] = useState('');
  const [confirmedDigits, setConfirmedDigits] = useState('');
  const [pin, setPin] = useState('');
  const [newPin, setNewPin] = useState('');
  const [localError, setLocalError] = useState('');
  const [mismatches, setMismatches] = useState(0);

  const pinStatus = state.pinStatus;
  const blockedUntil = pinStatus?.bloqueado_hasta ?? null;
  const loadingStatus = pinStatus === null;

  // Un rechazo del servidor por PIN limpia el PIN; uno por folio vuelve al primer paso.
  useEffect(() => {
    if (!state.error) return;
    setPin('');
    if (/folio|dígitos|etiqueta/i.test(state.error)) {
      setStep('folio');
      setDigits('');
    }
  }, [state.error]);

  const value = step === 'folio' ? digits : step === 'pin' ? pin : step === 'new_pin' ? newPin : pin;
  const length = step === 'folio' ? folioLength : OPERATOR_PIN_LENGTH;
  const masked = step !== 'folio';

  function press(key: string) {
    if (busy) return;
    setLocalError('');
    const next = key === 'borrar' ? value.slice(0, -1) : (value + key).slice(0, length);
    if (step === 'folio') setDigits(next);
    else if (step === 'new_pin') setNewPin(next);
    else setPin(next);
    if (key !== 'borrar' && next.length === length) complete(next);
  }

  function complete(entered: string) {
    if (step === 'folio') {
      if (!matchesFolioConfirmation(folioNumber, entered)) {
        setMismatches((count) => count + 1);
        setDigits('');
        setLocalError('No coincide con el folio de la tarea. Vuelve a leer la etiqueta del pallet.');
        return;
      }
      setConfirmedDigits(entered);
      setStep(pinStatus?.configurado === false ? 'new_pin' : 'pin');
      return;
    }

    if (step === 'new_pin') {
      const problem = operatorPinProblem(entered);
      if (problem) {
        setNewPin('');
        setLocalError(problem);
        return;
      }
      setStep('repeat_pin');
      setPin('');
      return;
    }

    if (step === 'repeat_pin') {
      if (entered !== newPin) {
        setNewPin('');
        setPin('');
        setStep('new_pin');
        setLocalError('Los dos PIN no coinciden. Créalo nuevamente.');
        return;
      }
      void onCreatePin(entered).then((created) => {
        if (created) onConfirm({ folioDigits: confirmedDigits, pin: entered });
        else {
          setNewPin('');
          setPin('');
          setStep('new_pin');
        }
      });
      return;
    }

    onConfirm({ folioDigits: confirmedDigits, pin: entered });
  }

  const title = step === 'folio'
    ? '¿Corresponde el folio?'
    : step === 'pin'
      ? 'Confirma con tu PIN'
      : step === 'new_pin'
        ? 'Crea tu PIN personal'
        : 'Repite tu PIN';
  const instruction = step === 'folio'
    ? `Lee la etiqueta del pallet y digita los últimos ${folioLength} dígitos del folio.`
    : step === 'pin'
      ? 'Tu PIN deja registrado quién retiró este pallet.'
      : step === 'new_pin'
        ? 'Elige 4 dígitos que recuerdes. Lo usarás para confirmar cada retiro.'
        : 'Vuelve a digitar el mismo PIN para guardarlo.';
  const error = localError || state.error;

  return (
    <ScrollView contentContainerStyle={styles.screen} keyboardShouldPersistTaps="handled">
      <View style={styles.header}>
        <Text style={styles.kicker}>CONFIRMACIÓN ANTES DE RETIRAR</Text>
        <Text accessibilityRole="header" style={styles.title}>{title}</Text>
        <Text style={styles.instruction}>{instruction}</Text>
      </View>

      <View style={styles.folioCard}>
        <Text style={styles.folioLabel}>Folio indicado por el WMS</Text>
        <Text accessibilityLabel={`Folio ${folioNumber}`} style={styles.folio}>
          <Text style={styles.folioLead}>{lead}</Text>
          <Text style={step === 'folio' ? styles.folioTailHidden : styles.folioTail}>
            {step === 'folio' ? '•'.repeat(tail.length) : tail}
          </Text>
        </Text>
        <Text style={styles.origin}>Origen: {operationalTaskPositionLabel(task.origen)}</Text>
      </View>

      {loadingStatus ? (
        <View style={styles.loading}>
          <ActivityIndicator color={o.color.primary} size="large" />
          <Text style={styles.loadingText}>Consultando tu PIN…</Text>
        </View>
      ) : blockedUntil && step !== 'folio' ? (
        <View style={styles.blocked}>
          <Text style={styles.blockedTitle}>PIN bloqueado</Text>
          <Text style={styles.blockedText}>{state.error || 'Demasiados intentos fallidos. Espera unos minutos o pide a un administrador que lo restablezca.'}</Text>
        </View>
      ) : (
        <>
          <View accessibilityLabel={`${value.length} de ${length} dígitos`} style={styles.slots}>
            {Array.from({ length }, (_, index) => (
              <View key={index} style={[styles.slot, index < value.length && styles.slotFilled]}>
                <Text style={styles.slotText}>
                  {index < value.length ? (masked ? '●' : value[index]) : ''}
                </Text>
              </View>
            ))}
          </View>

          {error ? <Text accessibilityRole="alert" style={styles.error}>{error}</Text> : null}

          <View style={styles.keypad}>
            {['1', '2', '3', '4', '5', '6', '7', '8', '9', '', '0', 'borrar'].map((key, index) => key === '' ? (
              <View key={`vacio-${index}`} style={styles.keySpacer} />
            ) : (
              <Pressable
                accessibilityLabel={key === 'borrar' ? 'Borrar dígito' : `Dígito ${key}`}
                disabled={busy}
                key={key}
                onPress={() => press(key)}
                style={({ pressed }) => [styles.key, key === 'borrar' && styles.keyErase, pressed && styles.keyPressed]}
              >
                <Text style={[styles.keyText, key === 'borrar' && styles.keyEraseText]}>
                  {key === 'borrar' ? '⌫ BORRAR' : key}
                </Text>
              </Pressable>
            ))}
          </View>
        </>
      )}

      {busy ? (
        <View style={styles.loading}>
          <ActivityIndicator color={o.color.primary} size="large" />
          <Text style={styles.loadingText}>Confirmando con el servidor…</Text>
        </View>
      ) : null}

      {step === 'folio' && mismatches >= MISMATCHES_BEFORE_REPORT ? (
        <Pressable disabled={busy} onPress={onMismatch} style={styles.mismatchButton}>
          <Text style={styles.mismatchText}>EL PALLET NO CORRESPONDE · REPORTAR</Text>
        </Pressable>
      ) : null}

      <Pressable disabled={busy} onPress={onCancel} style={styles.cancelButton}>
        <Text style={styles.cancelText}>‹ CANCELAR, NO RETIRAR</Text>
      </Pressable>
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  screen: { gap: o.space[4], padding: o.space[4], paddingBottom: o.space[6] },
  header: { gap: o.space[2] },
  kicker: { fontFamily: font.heavy, color: o.color.critical, fontSize: o.type.small, letterSpacing: 0.6 },
  title: { fontFamily: font.heavy, color: o.color.text, fontSize: o.type.title },
  instruction: { fontFamily: font.regular, color: o.color.muted, fontSize: o.type.body, lineHeight: 22 },
  folioCard: {
    gap: o.space[2],
    padding: o.space[4],
    borderRadius: o.radius.panel,
    borderWidth: 2,
    borderColor: o.color.borderStrong,
    backgroundColor: o.color.surface,
  },
  folioLabel: { fontFamily: font.heavy, color: o.color.muted, fontSize: o.type.small },
  folio: { fontFamily: font.heavy, fontSize: 40, fontVariant: ['tabular-nums'], letterSpacing: 1 },
  folioLead: { color: o.color.muted },
  folioTail: { color: o.color.primaryPressed, textDecorationLine: 'underline' },
  folioTailHidden: { color: o.color.critical },
  origin: { fontFamily: font.bold, color: o.color.text, fontSize: o.type.body },
  slots: { flexDirection: 'row', justifyContent: 'center', gap: o.space[3] },
  slot: {
    width: 68,
    height: 76,
    alignItems: 'center',
    justifyContent: 'center',
    borderRadius: o.radius.control,
    borderWidth: 2,
    borderColor: o.color.border,
    backgroundColor: o.color.surface,
  },
  slotFilled: { borderColor: o.color.primary, backgroundColor: o.color.selected },
  slotText: { fontFamily: font.heavy, color: o.color.text, fontSize: 34 },
  error: {
    fontFamily: font.heavy,
    padding: o.space[3],
    borderRadius: o.radius.control,
    backgroundColor: o.color.criticalSurface,
    color: o.color.critical,
    fontSize: o.type.body,
    textAlign: 'center',
  },
  keypad: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    justifyContent: 'center',
    gap: o.space[3],
    alignSelf: 'center',
    width: '100%',
    maxWidth: 420,
  },
  key: {
    width: '30%',
    minHeight: o.touch.prominent + 8,
    alignItems: 'center',
    justifyContent: 'center',
    borderRadius: o.radius.control,
    borderWidth: 1,
    borderColor: o.color.borderStrong,
    backgroundColor: o.color.surface,
  },
  keySpacer: { width: '30%' },
  keyErase: { backgroundColor: o.color.surfaceMuted },
  keyPressed: { backgroundColor: o.color.selected },
  keyText: { fontFamily: font.heavy, color: o.color.text, fontSize: 32 },
  keyEraseText: { fontSize: o.type.body },
  loading: { alignItems: 'center', gap: o.space[2], padding: o.space[3] },
  loadingText: { fontFamily: font.bold, color: o.color.muted, fontSize: o.type.body },
  blocked: {
    gap: o.space[2],
    padding: o.space[4],
    borderRadius: o.radius.panel,
    backgroundColor: o.color.criticalSurface,
  },
  blockedTitle: { fontFamily: font.heavy, color: o.color.critical, fontSize: o.type.heading },
  blockedText: { fontFamily: font.regular, color: o.color.critical, fontSize: o.type.body, lineHeight: 22 },
  mismatchButton: {
    minHeight: o.touch.prominent,
    alignItems: 'center',
    justifyContent: 'center',
    borderRadius: o.radius.control,
    backgroundColor: o.color.critical,
  },
  mismatchText: { fontFamily: font.heavy, color: o.color.onPrimary, fontSize: o.type.body },
  cancelButton: {
    minHeight: o.touch.minimum,
    alignItems: 'center',
    justifyContent: 'center',
    borderRadius: o.radius.control,
    borderWidth: 1,
    borderColor: o.color.border,
  },
  cancelText: { fontFamily: font.heavy, color: o.color.text, fontSize: o.type.body },
});
