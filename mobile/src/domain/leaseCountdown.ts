/**
 * Cuenta regresiva de una reserva tomada y aún no iniciada.
 *
 * La reserva se considera vencida cuando le queda menos de un segundo entero: es la misma
 * regla que usaba la bandeja al calcular `Math.floor((vence - ahora) / 1000)` cada segundo,
 * y la que muestra la insignia como 00:00 / VENCIDA.
 */

/** Segundos enteros que le quedan a la reserva (mínimo 0), o null si no hay vencimiento válido. */
export function leaseSecondsRemaining(expiresAt: string | null | undefined, now: number): number | null {
  const expiry = leaseExpiryTime(expiresAt);
  if (expiry === null) return null;
  return Math.max(0, Math.floor((expiry - now) / 1000));
}

/** true cuando la reserva ya llegó a 0 segundos. */
export function isLeaseExpired(expiresAt: string | null | undefined, now: number): boolean {
  return leaseSecondsRemaining(expiresAt, now) === 0;
}

/**
 * Milisegundos hasta que la reserva llegue a 0 segundos (0 si ya llegó), o null si no hay
 * vencimiento válido. Permite programar un único aviso en vez de revisar cada segundo.
 */
export function msUntilLeaseExpires(expiresAt: string | null | undefined, now: number): number | null {
  const expiry = leaseExpiryTime(expiresAt);
  if (expiry === null) return null;
  return Math.max(0, expiry - 1000 - now + 1);
}

function leaseExpiryTime(expiresAt: string | null | undefined): number | null {
  if (!expiresAt) return null;
  const time = new Date(expiresAt).getTime();
  return Number.isFinite(time) ? time : null;
}
