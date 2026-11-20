import { useEffect, useRef, useState } from 'react';

import type { OperatorLoadFeed } from '../domain/operatorLoads';
import type { OperationalTasksApi } from '../services/operationalTasksApi';
import { useOperationalPolling } from './useOperationalPolling';

export function useOperatorLoadFeed(api?: OperationalTasksApi, token?: string, intervalMs?: number) {
  const [feed, setFeed] = useState<OperatorLoadFeed | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [checkedAt, setCheckedAt] = useState<string | null>(null);
  const request = useRef<{ api: OperationalTasksApi; token: string; busy: boolean; received: boolean } | null>(null);

  async function refresh() {
    const current = request.current;
    if (!current || current.busy) return;
    current.busy = true;
    setBusy(true);
    try {
      const response = await current.api.loadStatus(current.token, current.received);
      if (request.current !== current) return;
      if (response) {
        setFeed(response);
        current.received = true;
      }
      setCheckedAt(new Date().toISOString());
      setError('');
    } catch {
      if (request.current === current) setError('No se pudo actualizar el estado de las cargas.');
    } finally {
      current.busy = false;
      if (request.current === current) setBusy(false);
    }
  }

  useEffect(() => {
    request.current = api && token ? { api, token, busy: false, received: false } : null;
    setFeed(null);
    setCheckedAt(null);
    setError('');
    setBusy(false);
    void refresh();
    return () => { request.current = null; };
  }, [api, token]);

  useOperationalPolling(refresh, { enabled: Boolean(api && token), intervalMs });
  const active = Boolean(api && token && request.current?.api === api && request.current?.token === token);
  return { feed: active ? feed : null, busy: active && busy, error: active ? error : '', checkedAt: active ? checkedAt : null, refresh };
}
