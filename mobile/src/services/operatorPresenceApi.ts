import { fetchWithTimeout } from './httpClient';

export async function sendOperatorPresence(baseUrl: string, token: string): Promise<void> {
  const response = await fetchWithTimeout(`${baseUrl}/api/operacion/camareros/presencia`, {
    method: 'POST', headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
  });
  if (!response.ok) throw new Error('No se pudo actualizar la presencia del operador.');
}
