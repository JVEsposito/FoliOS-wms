import { useEffect, useMemo, useRef, useState } from 'react';
import { Text, View } from 'react-native';
import * as Crypto from 'expo-crypto';
import type { AuthSession } from '../domain/estiba';
import type { MaterialVerificationReading, ShiftVerification, VerificationItem } from '../domain/verification';
import { useOperationalPolling } from '../hooks/useOperationalPolling';
import { OperationalTasksApi } from '../services/operationalTasksApi';
import { TurnVerification } from './operator/TurnVerification';

type Props = { baseUrl: string; auth: AuthSession };
export function MaterialVerificationPanel({ baseUrl, auth }: Props) {
  const api = useMemo(() => new OperationalTasksApi(baseUrl), [baseUrl]);
  const [round, setRound] = useState<ShiftVerification | null>(null);
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState('');
  const pending = useRef<{ hash: string; id: string } | null>(null);
  async function load() {
    try { setRound(await api.currentVerification(auth.token)); }
    catch (reason) { setNotice(reason instanceof Error ? reason.message : 'No se pudo cargar la ronda.'); }
  }
  useEffect(() => { void load(); }, [api, auth.token]);
  useOperationalPolling(() => load(), { intervalMs: 60_000 });
  async function confirm(item: VerificationItem, readings: MaterialVerificationReading[]) {
    if (busy) return;
    const hash = JSON.stringify({ item: item.id, readings });
    if (pending.current?.hash !== hash) pending.current = { hash, id: Crypto.randomUUID() };
    setBusy(true);
    try {
      const response = await api.recordMaterialVerification(auth.token, item.id, item.version, pending.current.id, readings);
      setRound(response.data); pending.current = null;
      setNotice(response.resultado === 'no_aplica' ? 'La posición cambió por un movimiento. Se asignará otra posición.'
        : response.resultado === 'coincide' ? 'Posición confirmada.' : 'Diferencia registrada para revisión del supervisor.');
    } catch (reason) { setNotice(reason instanceof Error ? reason.message : 'No se pudo confirmar la posición.'); }
    finally { setBusy(false); }
  }
  return <View>
    <TurnVerification round={round} busy={busy} onVerify={() => {}} onVerifyMaterials={(item, readings) => void confirm(item, readings)} lookupUnit={(number) => api.verificationMaterialUnit(auth.token, number)} />
    {notice ? <Text accessibilityRole="alert">{notice}</Text> : null}
    {!round && !notice ? <Text>Sin ronda de materiales asignada. Puedes continuar en Plano y operación.</Text> : null}
  </View>;
}
