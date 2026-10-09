import { ApiError } from './apiError';
import {
  FotoRecepcion,
  TipoFotoRecepcion,
  CreateMaterialReceptionPayload,
  GenerateMaterialLabelsPayload,
  LabelPrintProfile,
  MaterialPrintJob,
  MaterialPrintOutcomePayload,
  MaterialReception,
  MaterialReceptionCatalog,
  MaterialReceptionState,
  PendingReceptionFolio,
  UpdateMaterialReceptionPayload,
} from '../domain/materialReception';

export type MaterialReceptionApi = ReturnType<typeof createMaterialReceptionApi>;

type ApiItem<T> = { data: T };
type ApiList<T> = { data: T[] };
type PaginatedApiList<T> = { data: T[] };

export function createMaterialReceptionApi(baseUrl: string, token: string) {
  async function request<T>(path: string, init: RequestInit = {}): Promise<T> {
    const headers = new Headers(init.headers);
    headers.set('Accept', 'application/json');
    headers.set('Authorization', `Bearer ${token}`);
    if (init.body && !(init.body instanceof FormData)) headers.set('Content-Type', 'application/json');

    let response: Response;
    try {
      response = await fetch(`${baseUrl}${path}`, { ...init, headers });
    } catch {
      throw new Error(`No fue posible conectar con ${baseUrl}. Revisa Laravel, la IP y el firewall.`);
    }

    const data = response.status === 204
      ? null
      : await response.json().catch(() => ({}));

    if (!response.ok) {
      throw new ApiError(validationMessage(data, 'La operación no pudo completarse.'), response.status, data);
    }

    return data as T;
  }

  return {
    async listarFotos(id: string): Promise<FotoRecepcion[]> {
      return (await request<ApiList<FotoRecepcion>>(`/api/materiales/recepciones/${encodeURIComponent(id)}/fotos`)).data;
    },
    async subirFoto(id: string, tipo: TipoFotoRecepcion, operacionId: string, archivo: { uri: string; name: string; type: string }): Promise<FotoRecepcion> {
      const body = new FormData();
      body.append('tipo', tipo); body.append('operacion_id', operacionId);
      body.append('archivo', archivo as unknown as Blob);
      return (await request<ApiItem<FotoRecepcion>>(`/api/materiales/recepciones/${encodeURIComponent(id)}/fotos`, { method: 'POST', body })).data;
    },
    async eliminarFoto(id: string, fotoId: string, motivo?: string): Promise<void> {
      await request(`/api/materiales/recepciones/${encodeURIComponent(id)}/fotos/${encodeURIComponent(fotoId)}`, { method: 'DELETE', body: JSON.stringify({ motivo }) });
    },
    async catalog(): Promise<MaterialReceptionCatalog> {
      return request<MaterialReceptionCatalog>('/api/materiales/recepciones/catalogos');
    },

    async list(state?: MaterialReceptionState): Promise<MaterialReception[]> {
      const query = state ? `?estado=${encodeURIComponent(state)}&per_page=100` : '?per_page=100';
      return (await request<PaginatedApiList<MaterialReception>>(
        `/api/materiales/recepciones${query}`,
      )).data;
    },

    async show(id: string): Promise<MaterialReception> {
      return (await request<ApiItem<MaterialReception>>(
        `/api/materiales/recepciones/${encodeURIComponent(id)}`,
      )).data;
    },

    async create(payload: CreateMaterialReceptionPayload): Promise<MaterialReception> {
      return (await request<ApiItem<MaterialReception>>('/api/materiales/recepciones', {
        method: 'POST',
        body: JSON.stringify(payload),
      })).data;
    },

    async update(id: string, payload: UpdateMaterialReceptionPayload): Promise<MaterialReception> {
      return (await request<ApiItem<MaterialReception>>(
        `/api/materiales/recepciones/${encodeURIComponent(id)}`,
        {
          method: 'PUT',
          body: JSON.stringify(payload),
        },
      )).data;
    },

    async confirm(id: string, operationId: string, knownVersion: number): Promise<MaterialReception> {
      return (await request<ApiItem<MaterialReception>>(
        `/api/materiales/recepciones/${encodeURIComponent(id)}/confirmar`,
        {
          method: 'POST',
          body: JSON.stringify({
            operacion_id: operationId,
            version_conocida: knownVersion,
          }),
        },
      )).data;
    },

    async annul(id: string, operationId: string, reason: string): Promise<MaterialReception> {
      return (await request<ApiItem<MaterialReception>>(
        `/api/materiales/recepciones/${encodeURIComponent(id)}/anular`,
        {
          method: 'POST',
          body: JSON.stringify({ operacion_id: operationId, motivo: reason }),
        },
      )).data;
    },

    async pendingFolios(): Promise<PendingReceptionFolio[]> {
      return (await request<ApiList<PendingReceptionFolio>>(
        '/api/materiales/recepciones/folios-pendientes?limit=1000',
      )).data;
    },

    async printProfiles(): Promise<LabelPrintProfile[]> {
      return (await request<ApiList<LabelPrintProfile>>(
        '/api/materiales/recepciones/perfiles-impresion',
      )).data;
    },

    async printJobs(receptionId: string): Promise<MaterialPrintJob[]> {
      return (await request<ApiList<MaterialPrintJob>>(
        `/api/materiales/recepciones/${encodeURIComponent(receptionId)}/impresiones`,
      )).data;
    },

    async generateLabels(
      receptionId: string,
      payload: GenerateMaterialLabelsPayload,
    ): Promise<{ jobId: string; zpl: string }> {
      let response: Response;
      try {
        response = await fetch(
          `${baseUrl}/api/materiales/recepciones/${encodeURIComponent(receptionId)}/etiquetas`,
          {
            method: 'POST',
            headers: {
              Accept: 'application/zpl',
              Authorization: `Bearer ${token}`,
              'Content-Type': 'application/json',
            },
            body: JSON.stringify(payload),
          },
        );
      } catch {
        throw new Error(`No fue posible conectar con ${baseUrl}. No se envió nada a la impresora.`);
      }

      if (!response.ok) {
        const data = await response.json().catch(() => ({}));
        throw new Error(validationMessage(data, 'No fue posible preparar las etiquetas.'));
      }
      const jobId = response.headers.get('X-Estiba-Print-Job');
      if (!jobId) {
        throw new Error('La API no devolvió el identificador auditable de impresión.');
      }

      return { jobId, zpl: await response.text() };
    },

    async reportPrintOutcome(
      jobId: string,
      payload: MaterialPrintOutcomePayload,
    ): Promise<void> {
      await request<ApiItem<MaterialPrintJob>>(
        `/api/materiales/recepciones/trabajos-impresion/${encodeURIComponent(jobId)}/resultado`,
        {
          method: 'POST',
          body: JSON.stringify(payload),
        },
      );
    },
  };
}

function validationMessage(data: unknown, fallback: string): string {
  if (!data || typeof data !== 'object') return fallback;

  const response = data as {
    message?: string;
    codigo?: string;
    errors?: Record<string, string[]>;
  };
  const firstValidationMessage = response.errors
    ? Object.values(response.errors).flat()[0]
    : null;

  return firstValidationMessage ?? response.message ?? fallback;
}
