import { useEffect, useMemo, useRef, useState } from 'react';
import { Text, View } from 'react-native';
import * as Crypto from 'expo-crypto';
import type { AuthSession } from '../domain/estiba';
import type { MaterialVerificationReading, ShiftVerification, VerificationItem } from '../domain/verification';
import { OperationalTasksApi } from '../services/operationalTasksApi';
import { useOperationalPolling } from '../hooks/useOperationalPolling';
import { TurnVerification } from './operator/TurnVerification';

type Props = { baseUrl: string; auth: AuthSession };
export function MaterialInventoryCountPanel({ baseUrl, auth }: Props) {
  const api = useMemo(() => new OperationalTasksApi(baseUrl), [baseUrl]);
  const [takes, setTakes] = useState<ShiftVerification[]>([]);
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState('');
  const retry = useRef<{ key: string; id: string } | null>(null);
  async function load() {
    try { setTakes(await api.inventoryCounts(auth.token)); }
    catch (e) { setNotice(e instanceof Error ? e.message : 'No se pudo cargar la toma.'); }
  }
  useEffect(() => { void load(); }, [api, auth.token]);
  useOperationalPolling(() => load(), { intervalMs: 60_000, enabled: !busy });
  async function confirm(item: VerificationItem, readings: MaterialVerificationReading[]) {
    if (busy) return;
    const key = JSON.stringify({ item: item.id, version: item.version, readings });
    if (retry.current?.key !== key) retry.current = { key, id: Crypto.randomUUID() };
    setBusy(true);
    try {
      const updated = await api.countInventoryPosition(auth.token, item.id, item.version, retry.current.id, readings);
      setTakes((rows) => rows.map((row) => row.id === updated.id ? updated : row));
      retry.current = null; setNotice('Conteo registrado. El supervisor revisará la toma.');
    } catch (e) { setNotice(e instanceof Error ? e.message : 'No se pudo confirmar el conteo.'); }
    finally { setBusy(false); }
  }
  return <View>
    {takes.map((take) => <TurnVerification key={take.id} round={take} busy={busy} title={`Toma de inventario ${take.id.slice(0, 8)}${take.categoria ? ` · ${take.categoria}` : ''}`} hideDeadline
      onVerify={() => {}} onVerifyMaterials={(item, readings) => void confirm(item, readings)} lookupUnit={(number) => api.verificationMaterialUnit(auth.token, number)} />)}
    {notice ? <Text accessibilityRole="alert">{notice}</Text> : null}
  </View>;
}
