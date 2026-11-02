import { useEffect, useState } from 'react';

import { isLeaseExpired, leaseSecondsRemaining, msUntilLeaseExpires } from '../domain/leaseCountdown';

// setTimeout acepta como máximo 2^31-1 ms (~24 días); más allá se reprograma por tramos.
const MAX_TIMEOUT_MS = 2_147_483_647;

/**
 * true cuando la reserva vence. Programa un solo aviso para el momento del vencimiento,
 * así quien lo usa se vuelve a dibujar una vez y no cada segundo.
 */
export function useLeaseExpired(expiresAt: string | null | undefined): boolean {
  const [expired, setExpired] = useState(() => isLeaseExpired(expiresAt, Date.now()));

  useEffect(() => {
    let timer: ReturnType<typeof setTimeout> | undefined;
    const check = () => {
      const now = Date.now();
      setExpired(isLeaseExpired(expiresAt, now));
      const wait = msUntilLeaseExpires(expiresAt, now);
      if (wait !== null && wait > 0) timer = setTimeout(check, Math.min(wait, MAX_TIMEOUT_MS));
    };
    check();
    return () => { if (timer !== undefined) clearTimeout(timer); };
  }, [expiresAt]);

  return expired;
}

/**
 * Segundos que le quedan a la reserva, actualizados cada segundo. Usarlo solo en el
 * componente que muestra la cuenta regresiva, para que el resto de la pantalla no se
 * vuelva a dibujar con cada tic.
 */
export function useLeaseSecondsRemaining(expiresAt: string | null | undefined): number | null {
  const [now, setNow] = useState(() => Date.now());
  const seconds = leaseSecondsRemaining(expiresAt, now);
  const running = seconds !== null && seconds > 0;

  useEffect(() => {
    setNow(Date.now());
    if (!running) return undefined;
    const interval = setInterval(() => setNow(Date.now()), 1_000);
    return () => clearInterval(interval);
  }, [expiresAt, running]);

  return seconds;
}
