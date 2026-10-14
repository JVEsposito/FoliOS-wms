import {
  ApiItem,
  ApiList,
  ApiMode,
  AuthSession,
  AvailableMaterialFolio,
  CameraPlan,
  CameraSummary,
  CreateMaterialDispatchPayload,
  Dock,
  ExtractionPlan,
  FolioLookup,
  LocatePayload,
  LoginPayload,
  Movement,
  MaterialCatalog,
  MaterialDispatch,
  MaterialDispatchSummary,
  MovePayload,
  OpenedSession,
  OperationalNotification,
  OperationalNotificationFeed,
  OperationalNotificationSummary,
  RefrigeratedLoad,
  ReportLoadIncidentPayload,
  SagCondition,
  SendLoadFolioToDockPayload,
  WithdrawMaterialPayload,
} from '../domain/estiba';
import { ApiError } from './apiError';
import { fetchWithTimeout } from './httpClient';
import { normalizeApiBaseUrl } from './apiConfiguration';
import { DemoEstibaApi } from './estibaApiDemo';
import {
  CloseMaterialTransformationLotPayload,
  CloseMaterialTransformationOrderPayload,
  ConfirmMaterialTransformationOutputPayload,
  MaterialTransformationOrder,
  MaterialTransformationOrderSummary,
  OpenMaterialTransformationLotPayload,
  ReverseMaterialTransformationLotPayload,
  StartMaterialTransformationPayload,
} from '../domain/materialTransformation';
import {
  GenerateMaterialLabelsPayload,
  LabelPrintProfile,
  MaterialPrintJob,
  MaterialPrintOutcomePayload,
} from '../domain/materialReception';
import { isDemoRuntime } from '../config/appVariant';

export interface EstibaApi {
  readonly mode: ApiMode;
  readonly baseUrl: string | null;
  readonly configurationError: string | null;
  login(payload: LoginPayload): Promise<AuthSession>;
  changePassword(token: string, currentPassword: string, newPassword: string): Promise<void>;
  logout(token: string): Promise<void>;
  listCameras(token: string): Promise<CameraSummary[]>;
  refreshCameras(token: string): Promise<CameraSummary[] | null>;
  listConditions(token: string): Promise<SagCondition[]>;
  getPlan(token: string, cameraId: string): Promise<CameraPlan>;
  refreshPlan(token: string, cameraId: string): Promise<CameraPlan | null>;
  listRecent(token: string, cameraId: string): Promise<Movement[]>;
  refreshRecent(token: string, cameraId: string): Promise<Movement[] | null>;
  openSession(token: string, cameraId: string): Promise<OpenedSession>;
  closeSession(token: string, sessionId: string): Promise<void>;
  lookupFolio(token: string, folioNumber: string, cameraId?: string): Promise<FolioLookup>;
  searchAvailableMaterialFolios(token: string, prefix: string, cameraId: string, cameraOnly: boolean): Promise<AvailableMaterialFolio[]>;
  locate(token: string, payload: LocatePayload): Promise<void>;
  move(token: string, payload: MovePayload): Promise<void>;
  getMaterialCatalog(token: string): Promise<MaterialCatalog>;
  listMaterialDispatches(
    token: string,
    states?: MaterialDispatch['estado'][],
  ): Promise<MaterialDispatch[]>;
  listMaterialDispatchSummaries(
    token: string,
    states?: MaterialDispatch['estado'][],
  ): Promise<MaterialDispatchSummary[]>;
  getMaterialDispatch(token: string, dispatchId: string): Promise<MaterialDispatch>;
  createMaterialDispatch(token: string, payload: CreateMaterialDispatchPayload): Promise<MaterialDispatch>;
  withdrawMaterial(token: string, dispatchId: string, payload: WithdrawMaterialPayload): Promise<MaterialDispatch>;
  listMaterialTransformations(token: string): Promise<MaterialTransformationOrderSummary[]>;
  getMaterialTransformation(token: string, orderId: string): Promise<MaterialTransformationOrder>;
  startMaterialTransformation(token: string, orderId: string, payload: StartMaterialTransformationPayload): Promise<MaterialTransformationOrder>;
  openMaterialTransformationLot(token: string, orderId: string, payload: OpenMaterialTransformationLotPayload): Promise<MaterialTransformationOrder>;
  closeMaterialTransformationLot(token: string, lotId: string, payload: CloseMaterialTransformationLotPayload): Promise<MaterialTransformationOrder>;
  confirmMaterialTransformationOutput(token: string, orderId: string, payload: ConfirmMaterialTransformationOutputPayload): Promise<MaterialTransformationOrder>;
  reverseMaterialTransformationLot(token: string, lotId: string, payload: ReverseMaterialTransformationLotPayload): Promise<MaterialTransformationOrder>;
  closeMaterialTransformationOrder(token: string, orderId: string, payload: CloseMaterialTransformationOrderPayload): Promise<MaterialTransformationOrder>;
  materialLabelProfiles(token: string): Promise<LabelPrintProfile[]>;
  materialTransformationPrintJobs(token: string, orderId: string): Promise<MaterialPrintJob[]>;
  generateMaterialTransformationLabels(token: string, orderId: string, payload: GenerateMaterialLabelsPayload): Promise<{ jobId: string; zpl: string }>;
  reportMaterialTransformationPrintOutcome(token: string, jobId: string, payload: MaterialPrintOutcomePayload): Promise<void>;
  listRefrigeratedLoads(token: string): Promise<RefrigeratedLoad[]>;
  refreshRefrigeratedLoads(token: string): Promise<RefrigeratedLoad[] | null>;
  getExtractionPlan(token: string, loadId: string): Promise<ExtractionPlan>;
  refreshExtractionPlan(token: string, loadId: string): Promise<ExtractionPlan | null>;
  listDocks(token: string): Promise<Dock[]>;
  reportLoadIncident(token: string, assignmentId: string, payload: ReportLoadIncidentPayload): Promise<void>;
  sendLoadFolioToDock(token: string, assignmentId: string, payload: SendLoadFolioToDockPayload): Promise<RefrigeratedLoad>;
  getOperationalNotificationSummary(token: string): Promise<OperationalNotificationSummary>;
  listOperationalNotifications(token: string): Promise<OperationalNotificationFeed>;
  readOperationalNotification(token: string, notificationId: string): Promise<OperationalNotification>;
  confirmOperationalNotification(token: string, notificationId: string): Promise<OperationalNotification>;
}

function validationMessage(data: unknown, fallback: string) {
  if (!data || typeof data !== 'object') return fallback;

  const response = data as { message?: string; errors?: Record<string, string[]> };
  const firstValidationMessage = response.errors
    ? Object.values(response.errors).flat()[0]
    : null;

  return firstValidationMessage ?? response.message ?? fallback;
}

class HttpEstibaApi implements EstibaApi {
  readonly mode = 'connected' as const;
  readonly configurationError = null;
  private camerasEtag: string | null = null;
  private refrigeratedLoadsEtag: string | null = null;
  private readonly planEtags = new Map<string, string>();
  private readonly recentEtags = new Map<string, string>();
  private readonly extractionPlanEtags = new Map<string, string>();

  constructor(public readonly baseUrl: string) {}

  private async request<T>(path: string, token?: string, init: RequestInit = {}): Promise<T> {
    const headers = new Headers(init.headers);
    headers.set('Accept', 'application/json');

    if (token) headers.set('Authorization', `Bearer ${token}`);
    if (init.body) headers.set('Content-Type', 'application/json');

    let response: Response;

    try {
      response = await fetchWithTimeout(`${this.baseUrl}${path}`, { ...init, headers });
    } catch {
      throw new ApiError(
        `No fue posible conectar con ${this.baseUrl}. Revisa la IP, Laravel y el firewall.`,
        0,
      );
    }

    const data = response.status === 204
      ? null
      : await response.json().catch(() => ({}));

    if (!response.ok) {
      throw new ApiError(
        validationMessage(data, 'La operación no pudo completarse.'),
        response.status,
        data,
      );
    }

    return data as T;
  }

  login(payload: LoginPayload) {
    return this.request<AuthSession>('/api/acceso-tablet', undefined, {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  }

  async changePassword(token: string, currentPassword: string, newPassword: string) {
    await this.request('/api/usuario/password', token, {
      method: 'PUT',
      body: JSON.stringify({
        password_actual: currentPassword,
        password_nueva: newPassword,
        password_nueva_confirmation: newPassword,
      }),
    });
  }

  async logout(token: string) {
    await this.request<null>('/api/acceso-tablet', token, { method: 'DELETE' });
    this.camerasEtag = null;
    this.refrigeratedLoadsEtag = null;
    this.planEtags.clear();
    this.recentEtags.clear();
    this.extractionPlanEtags.clear();
  }

  async listCameras(token: string) {
    return (await this.requestCameras(token, false))!;
  }

  async refreshCameras(token: string) {
    return this.requestCameras(token, true);
  }

  async listConditions(token: string) {
    return (await this.request<ApiList<SagCondition>>('/api/condiciones-sag', token)).data;
  }

  async getPlan(token: string, cameraId: string) {
    return (await this.requestPlan(token, cameraId, false))!;
  }

  async refreshPlan(token: string, cameraId: string) {
    return this.requestPlan(token, cameraId, true);
  }

  async listRecent(token: string, cameraId: string) {
    return (await this.requestRecent(token, cameraId, false))!;
  }

  async refreshRecent(token: string, cameraId: string) {
    return this.requestRecent(token, cameraId, true);
  }

  private async requestCameras(
    token: string,
    conditional: boolean,
  ): Promise<CameraSummary[] | null> {
    return this.requestConditionalList<CameraSummary>(
      '/api/camaras',
      token,
      conditional ? this.camerasEtag : null,
      (etag) => { this.camerasEtag = etag; },
    );
  }

  private async requestRecent(
    token: string,
    cameraId: string,
    conditional: boolean,
  ): Promise<Movement[] | null> {
    const path = `/api/movimientos/recientes?camara_id=${encodeURIComponent(cameraId)}&limite=8`;
    return this.requestConditionalList<Movement>(
      path,
      token,
      conditional ? this.recentEtags.get(cameraId) ?? null : null,
      (etag) => { this.recentEtags.set(cameraId, etag); },
    );
  }

  private async requestConditionalList<T>(
    path: string,
    token: string,
    etag: string | null,
    rememberEtag: (etag: string) => void,
  ): Promise<T[] | null> {
    return this.requestConditionalData<T[]>(path, token, etag, rememberEtag);
  }

  private async requestConditionalData<T>(
    path: string,
    token: string,
    etag: string | null,
    rememberEtag: (etag: string) => void,
  ): Promise<T | null> {
    const headers = new Headers({
      Accept: 'application/json',
      Authorization: `Bearer ${token}`,
    });
    if (etag) headers.set('If-None-Match', etag);

    let response: Response;

    try {
      response = await fetchWithTimeout(`${this.baseUrl}${path}`, { headers });
    } catch {
      throw new ApiError(
        `No fue posible conectar con ${this.baseUrl}. Revisa la IP, Laravel y el firewall.`,
        0,
      );
    }

    if (response.status === 304) return null;

    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
      throw new ApiError(
        validationMessage(data, 'La operación no pudo completarse.'),
        response.status,
        data,
      );
    }

    const nextEtag = response.headers.get('ETag');
    if (nextEtag) rememberEtag(nextEtag);

    return (data as ApiItem<T>).data;
  }

  private async requestPlan(
    token: string,
    cameraId: string,
    conditional: boolean,
  ): Promise<CameraPlan | null> {
    const path = `/api/camaras/${cameraId}/plano`;
    const headers = new Headers({
      Accept: 'application/json',
      Authorization: `Bearer ${token}`,
    });
    const etag = conditional ? this.planEtags.get(cameraId) : null;
    if (etag) headers.set('If-None-Match', etag);

    let response: Response;

    try {
      response = await fetchWithTimeout(`${this.baseUrl}${path}`, { headers });
    } catch {
      throw new ApiError(
        `No fue posible conectar con ${this.baseUrl}. Revisa la IP, Laravel y el firewall.`,
        0,
      );
    }

    if (response.status === 304) return null;

    const data = await response.json().catch(() => ({}));

    if (!response.ok) {
      throw new ApiError(
        validationMessage(data, 'La operación no pudo completarse.'),
        response.status,
        data,
      );
    }

    const nextEtag = response.headers.get('ETag');
    if (nextEtag) this.planEtags.set(cameraId, nextEtag);

    return (data as ApiItem<CameraPlan>).data;
  }

  async openSession(token: string, cameraId: string) {
    return (await this.request<ApiItem<OpenedSession>>(
      `/api/camaras/${cameraId}/sesiones`,
      token,
      { method: 'POST' },
    )).data;
  }

  async closeSession(token: string, sessionId: string) {
    await this.request(`/api/sesiones/${sessionId}/cerrar`, token, {
      method: 'POST',
      body: JSON.stringify({ motivo: 'Cierre desde aplicación Expo' }),
    });
  }

  async lookupFolio(token: string, folioNumber: string, cameraId?: string) {
    const camera = cameraId ? `&camara_id=${encodeURIComponent(cameraId)}` : '';
    const path = `/api/movimientos/consultar-folio?numero_folio=${encodeURIComponent(folioNumber)}${camera}`;
    return (await this.request<ApiItem<FolioLookup>>(path, token)).data;
  }

  async searchAvailableMaterialFolios(token: string, prefix: string, cameraId: string, cameraOnly: boolean) {
    const params = new URLSearchParams({ prefijo: prefix, camara_id: cameraId, solo_camara: cameraOnly ? '1' : '0' });
    return (await this.request<ApiItem<AvailableMaterialFolio[]>>(
      `/api/movimientos/folios-materiales-disponibles?${params.toString()}`,
      token,
    )).data;
  }

  async locate(token: string, payload: LocatePayload) {
    await this.request('/api/movimientos/ubicar', token, {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  }

  async move(token: string, payload: MovePayload) {
    await this.request('/api/movimientos/mover', token, {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  }

  getMaterialCatalog(token: string) {
    return this.request<MaterialCatalog>('/api/materiales/catalogo', token);
  }

  async listMaterialDispatches(
    token: string,
    states: MaterialDispatch['estado'][] = ['pendiente', 'parcial'],
  ) {
    const stateFilter = encodeURIComponent(states.join(','));
    const response = await this.request<ApiList<MaterialDispatch>>(
      `/api/materiales/despachos?estados=${stateFilter}&vista=operacion`,
      token,
    );
    return response.data;
  }

  async listMaterialDispatchSummaries(
    token: string,
    states: MaterialDispatch['estado'][] = ['pendiente', 'parcial'],
  ) {
    const stateFilter = encodeURIComponent(states.join(','));
    const response = await this.request<ApiList<MaterialDispatchSummary>>(
      `/api/materiales/despachos?estados=${stateFilter}&vista=resumen`,
      token,
    );
    return response.data;
  }

  async getMaterialDispatch(token: string, dispatchId: string) {
    return (await this.request<ApiItem<MaterialDispatch>>(
      `/api/materiales/despachos/${dispatchId}`,
      token,
    )).data;
  }

  async createMaterialDispatch(token: string, payload: CreateMaterialDispatchPayload) {
    return (await this.request<ApiItem<MaterialDispatch>>('/api/materiales/despachos', token, {
      method: 'POST',
      body: JSON.stringify(payload),
    })).data;
  }

  async withdrawMaterial(token: string, dispatchId: string, payload: WithdrawMaterialPayload) {
    return (await this.request<ApiItem<MaterialDispatch>>(
      `/api/materiales/despachos/${dispatchId}/retirar`,
      token,
      { method: 'POST', body: JSON.stringify(payload) },
    )).data;
  }

  async listMaterialTransformations(token: string) {
    return (await this.request<ApiList<MaterialTransformationOrderSummary>>(
      '/api/materiales/transformaciones/ordenes?per_page=100',
      token,
    )).data;
  }

  async getMaterialTransformation(token: string, orderId: string) {
    return (await this.request<ApiItem<MaterialTransformationOrder>>(
      `/api/materiales/transformaciones/ordenes/${encodeURIComponent(orderId)}`,
      token,
    )).data;
  }

  async startMaterialTransformation(
    token: string,
    orderId: string,
    payload: StartMaterialTransformationPayload,
  ) {
    return (await this.request<ApiItem<MaterialTransformationOrder>>(
      `/api/materiales/transformaciones/ordenes/${encodeURIComponent(orderId)}/iniciar`,
      token,
      { method: 'POST', body: JSON.stringify(payload) },
    )).data;
  }

  async openMaterialTransformationLot(
    token: string,
    orderId: string,
    payload: OpenMaterialTransformationLotPayload,
  ) {
    return (await this.request<ApiItem<MaterialTransformationOrder>>(
      `/api/materiales/transformaciones/ordenes/${encodeURIComponent(orderId)}/lotes`,
      token,
      { method: 'POST', body: JSON.stringify(payload) },
    )).data;
  }

  async closeMaterialTransformationLot(
    token: string,
    lotId: string,
    payload: CloseMaterialTransformationLotPayload,
  ) {
    return (await this.request<ApiItem<MaterialTransformationOrder>>(
      `/api/materiales/transformaciones/lotes/${encodeURIComponent(lotId)}/cerrar`,
      token,
      { method: 'POST', body: JSON.stringify(payload) },
    )).data;
  }

  async confirmMaterialTransformationOutput(
    token: string,
    orderId: string,
    payload: ConfirmMaterialTransformationOutputPayload,
  ) {
    return (await this.request<ApiItem<MaterialTransformationOrder>>(
      `/api/materiales/transformaciones/ordenes/${encodeURIComponent(orderId)}/folios-salida/confirmar`,
      token,
      { method: 'POST', body: JSON.stringify(payload) },
    )).data;
  }

  async reverseMaterialTransformationLot(
    token: string,
    lotId: string,
    payload: ReverseMaterialTransformationLotPayload,
  ) {
    return (await this.request<ApiItem<MaterialTransformationOrder>>(
      `/api/materiales/transformaciones/lotes/${encodeURIComponent(lotId)}/revertir`,
      token,
      { method: 'POST', body: JSON.stringify(payload) },
    )).data;
  }

  async closeMaterialTransformationOrder(
    token: string,
    orderId: string,
    payload: CloseMaterialTransformationOrderPayload,
  ) {
    return (await this.request<ApiItem<MaterialTransformationOrder>>(
      `/api/materiales/transformaciones/ordenes/${encodeURIComponent(orderId)}/cerrar`,
      token,
      { method: 'POST', body: JSON.stringify(payload) },
    )).data;
  }

  async materialLabelProfiles(token: string) {
    return (await this.request<ApiList<LabelPrintProfile>>(
      '/api/materiales/recepciones/perfiles-impresion',
      token,
    )).data;
  }

  async materialTransformationPrintJobs(token: string, orderId: string) {
    return (await this.request<ApiList<MaterialPrintJob>>(
      `/api/materiales/transformaciones/ordenes/${encodeURIComponent(orderId)}/impresiones`,
      token,
    )).data;
  }

  async generateMaterialTransformationLabels(
    token: string,
    orderId: string,
    payload: GenerateMaterialLabelsPayload,
  ) {
    let response: Response;
    try {
      response = await fetchWithTimeout(
        `${this.baseUrl}/api/materiales/transformaciones/ordenes/${encodeURIComponent(orderId)}/etiquetas`,
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
      throw new ApiError('No fue posible preparar la etiqueta de transformación.', 0);
    }
    if (!response.ok) {
      const data = await response.json().catch(() => ({}));
      throw new ApiError(
        validationMessage(data, 'No fue posible preparar la etiqueta de transformación.'),
        response.status,
        data,
      );
    }
    const jobId = response.headers.get('X-Estiba-Print-Job');
    if (!jobId) {
      throw new ApiError('La API no devolvió el identificador auditable de impresión.', 500);
    }

    return { jobId, zpl: await response.text() };
  }

  async reportMaterialTransformationPrintOutcome(
    token: string,
    jobId: string,
    payload: MaterialPrintOutcomePayload,
  ) {
    await this.request(
      `/api/materiales/transformaciones/trabajos-impresion/${encodeURIComponent(jobId)}/resultado`,
      token,
      { method: 'POST', body: JSON.stringify(payload) },
    );
  }

  async listRefrigeratedLoads(token: string) {
    return (await this.requestRefrigeratedLoads(token, false))!;
  }

  async refreshRefrigeratedLoads(token: string) {
    return this.requestRefrigeratedLoads(token, true);
  }

  async getExtractionPlan(token: string, loadId: string) {
    return (await this.requestExtractionPlan(token, loadId, false))!;
  }

  async refreshExtractionPlan(token: string, loadId: string) {
    return this.requestExtractionPlan(token, loadId, true);
  }

  private async requestRefrigeratedLoads(
    token: string,
    conditional: boolean,
  ): Promise<RefrigeratedLoad[] | null> {
    return this.requestConditionalList<RefrigeratedLoad>(
      '/api/cargas/pendientes',
      token,
      conditional ? this.refrigeratedLoadsEtag : null,
      (etag) => { this.refrigeratedLoadsEtag = etag; },
    );
  }

  private async requestExtractionPlan(
    token: string,
    loadId: string,
    conditional: boolean,
  ): Promise<ExtractionPlan | null> {
    return this.requestConditionalData<ExtractionPlan>(
      `/api/cargas/${loadId}/plan-extraccion`,
      token,
      conditional ? this.extractionPlanEtags.get(loadId) ?? null : null,
      (etag) => { this.extractionPlanEtags.set(loadId, etag); },
    );
  }

  async listDocks(token: string) {
    return (await this.request<ApiList<Dock>>('/api/andenes', token)).data;
  }

  async reportLoadIncident(
    token: string,
    assignmentId: string,
    payload: ReportLoadIncidentPayload,
  ) {
    await this.request(`/api/cargas/asignaciones/${assignmentId}/incidencias`, token, {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  }

  async sendLoadFolioToDock(
    token: string,
    assignmentId: string,
    payload: SendLoadFolioToDockPayload,
  ) {
    return (await this.request<ApiItem<RefrigeratedLoad>>(
      `/api/cargas/asignaciones/${assignmentId}/enviar-anden`,
      token,
      { method: 'POST', body: JSON.stringify(payload) },
    )).data;
  }

  async listOperationalNotifications(token: string): Promise<OperationalNotificationFeed> {
    const response = await this.request<{
      data: OperationalNotification[];
      resumen: { no_leidas: number; sincronizado_at: string };
    }>('/api/notificaciones-operacionales?per_page=50', token);
    return {
      items: response.data,
      unread: response.resumen.no_leidas,
      syncedAt: response.resumen.sincronizado_at,
    };
  }

  async getOperationalNotificationSummary(token: string): Promise<OperationalNotificationSummary> {
    const response = await this.request<ApiItem<{
      no_leidas: number;
      sincronizado_at: string;
    }>>('/api/notificaciones-operacionales/resumen', token);

    return {
      unread: response.data.no_leidas,
      syncedAt: response.data.sincronizado_at,
    };
  }

  async readOperationalNotification(token: string, notificationId: string) {
    return (await this.request<ApiItem<OperationalNotification>>(
      `/api/notificaciones-operacionales/${notificationId}/leer`,
      token,
      { method: 'POST' },
    )).data;
  }

  async confirmOperationalNotification(token: string, notificationId: string) {
    return (await this.request<ApiItem<OperationalNotification>>(
      `/api/notificaciones-operacionales/${notificationId}/confirmar`,
      token,
      { method: 'POST' },
    )).data;
  }
}

export function createEstibaApi(
  runtimeUrl: string | null = process.env.EXPO_PUBLIC_API_URL?.trim() || null,
): EstibaApi {
  if (isDemoRuntime) return new DemoEstibaApi();

  if (!runtimeUrl) {
    return createUnavailableApi(
      'La API no está configurada. Abre Configurar servidor e ingresa la IP de Laravel.',
    );
  }

  let configuredUrl: string;
  try {
    configuredUrl = normalizeApiBaseUrl(runtimeUrl);
  } catch {
    return createUnavailableApi(
      'La dirección configurada no es válida. Usa, por ejemplo, 192.168.1.100:8000.',
    );
  }

  return new HttpEstibaApi(configuredUrl);
}

function createUnavailableApi(message: string): EstibaApi {
  const unavailable = async (): Promise<never> => {
    throw new ApiError(message, 0);
  };

  return {
    mode: 'unconfigured',
    baseUrl: null,
    configurationError: message,
    login: unavailable,
    changePassword: unavailable,
    logout: unavailable,
    listCameras: unavailable,
    refreshCameras: unavailable,
    listConditions: unavailable,
    getPlan: unavailable,
    refreshPlan: unavailable,
    listRecent: unavailable,
    refreshRecent: unavailable,
    openSession: unavailable,
    closeSession: unavailable,
    lookupFolio: unavailable,
    searchAvailableMaterialFolios: unavailable,
    locate: unavailable,
    move: unavailable,
    getMaterialCatalog: unavailable,
    listMaterialDispatches: unavailable,
    listMaterialDispatchSummaries: unavailable,
    getMaterialDispatch: unavailable,
    createMaterialDispatch: unavailable,
    withdrawMaterial: unavailable,
    listMaterialTransformations: unavailable,
    getMaterialTransformation: unavailable,
    startMaterialTransformation: unavailable,
    openMaterialTransformationLot: unavailable,
    closeMaterialTransformationLot: unavailable,
    confirmMaterialTransformationOutput: unavailable,
    reverseMaterialTransformationLot: unavailable,
    closeMaterialTransformationOrder: unavailable,
    materialLabelProfiles: unavailable,
    materialTransformationPrintJobs: unavailable,
    generateMaterialTransformationLabels: unavailable,
    reportMaterialTransformationPrintOutcome: unavailable,
    listRefrigeratedLoads: unavailable,
    refreshRefrigeratedLoads: unavailable,
    getExtractionPlan: unavailable,
    refreshExtractionPlan: unavailable,
    listDocks: unavailable,
    reportLoadIncident: unavailable,
    sendLoadFolioToDock: unavailable,
    getOperationalNotificationSummary: unavailable,
    listOperationalNotifications: unavailable,
    readOperationalNotification: unavailable,
    confirmOperationalNotification: unavailable,
  };
}
