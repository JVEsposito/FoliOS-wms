import { File } from 'expo-file-system';
import { fetch as expoFetch } from 'expo/fetch';

import { ReceptionDefect, ReceptionDefectDraft, ReceptionDefectPhoto } from '../domain/receptionDefects';
import { ApiError } from './apiError';

const path = (receptionId: string) => `/api/validacion-mp/recepciones/${encodeURIComponent(receptionId)}/defectos`;
type JsonResponse = Pick<Response, 'ok' | 'status' | 'json'>;

async function readResponse(response: JsonResponse): Promise<{ data?: ReceptionDefect; message?: string; errors?: Record<string, string[]> }> {
  const raw = await response.json().catch(() => null);
  const body = raw && typeof raw === 'object' ? raw : {};
  if (response.status === 413) {
    throw new ApiError('Las fotos superan el límite de carga del servidor. Solicita revisar ese límite o elige imágenes más pequeñas.', 413);
  }
  if (!response.ok) {
    const detail = body as { message?: string; errors?: Record<string, string[]> };
    throw new ApiError(Object.values(detail.errors ?? {}).flat()[0] ?? detail.message ?? 'No fue posible guardar el defecto.', response.status, body);
  }
  return body;
}

export async function listReceptionDefects(baseUrl: string, token: string, receptionId: string): Promise<ReceptionDefect[]> {
  return readReceptionDefects(baseUrl, token, receptionId);
}

export async function findReceptionDefectOperation(baseUrl: string, token: string, receptionId: string, operationId: string): Promise<ReceptionDefect | null> {
  const records = await readReceptionDefects(baseUrl, token, receptionId, operationId);
  return records.find((record) => record.operacion_id === operationId) ?? null;
}

async function readReceptionDefects(baseUrl: string, token: string, receptionId: string, operationId?: string): Promise<ReceptionDefect[]> {
  let response: JsonResponse;
  try {
    const query = operationId ? `?operacion_id=${encodeURIComponent(operationId)}` : '';
    response = await expoFetch(`${baseUrl}${path(receptionId)}${query}`, { headers: { Accept: 'application/json', Authorization: `Bearer ${token}` } });
  } catch {
    throw new ApiError('Sin conexión. Intenta actualizar el registro de defectos.', 0);
  }
  const body = await readResponse(response) as { data?: ReceptionDefect[] };
  if (!Array.isArray(body.data)) throw new ApiError('No se pudo consultar el registro de defectos. Conserva el formulario y vuelve a revisar el envío.', 0);
  return body.data;
}

function attachPhoto(form: FormData, field: string, photo: ReceptionDefectPhoto) {
  let file: File;
  try {
    file = new File(photo.uri);
    if (!file.exists || file.size <= 0) throw new Error('Unreadable local image');
  } catch {
    throw new ApiError('No se puede leer una de las fotos seleccionadas. Vuelve a elegirla antes de enviar.', 422);
  }
  if (file.size > 5 * 1024 * 1024) {
    throw new ApiError('Una de las fotos supera los 5 MB. Elige otra imagen o vuelve a tomarla.', 422);
  }
  // Expo 57's multipart encoder accepts File/Blob, not RN's legacy {uri, name,
  // type}. Let expo/fetch generate the multipart Content-Type and boundary.
  form.append(field, file);
}

export async function createReceptionDefect(baseUrl: string, token: string, receptionId: string, draft: ReceptionDefectDraft): Promise<ReceptionDefect> {
  const form = new FormData();
  form.append('operacion_id', draft.operacionId);
  form.append('categoria', draft.categoria);
  form.append('descripcion', draft.descripcion.trim());
  if (draft.tipoEnvase) form.append('tipo_envase', draft.tipoEnvase);
  if (draft.cantidadAfectada.trim()) form.append('cantidad_afectada', draft.cantidadAfectada.trim());
  draft.fotografias.forEach((photo, index) => attachPhoto(form, `fotografias[${index}]`, photo));
  if (draft.fotografiaGuia) attachPhoto(form, 'fotografia_guia', draft.fotografiaGuia);

  let response: JsonResponse;
  try {
    response = await expoFetch(`${baseUrl}${path(receptionId)}`, {
      method: 'POST',
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
      body: form,
    });
  } catch {
    throw new ApiError('No se pudo confirmar el envío. Reintenta sin cambiar los datos para evitar duplicados.', 0);
  }
  const body = await readResponse(response);
  if (!body.data) throw new ApiError('El servidor no devolvió el registro. Reintenta sin cambiar los datos.', 0);
  return body.data;
}
