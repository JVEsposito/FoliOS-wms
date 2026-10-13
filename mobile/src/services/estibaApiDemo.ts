import {
  AuthSession,
  CreateMaterialDispatchPayload,
  Dock,
  ExtractionPlan,
  LocatePayload,
  LoginPayload,
  MaterialCatalog,
  MaterialDispatch,
  MaterialDispatchSummary,
  MovePayload,
  OperationalNotification,
  OperationalNotificationFeed,
  RefrigeratedLoad,
  WithdrawMaterialPayload,
} from '../domain/estiba';
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
import {
  closeDemoSession,
  getDemoPlan,
  listDemoCameras,
  listDemoRecentMovements,
  locateDemoFolio,
  lookupDemoFolio,
  moveDemoFolio,
  openDemoSession,
} from '../demo/demoOperationalEngine';
import { demoSagConditions } from '../demo/demoOperationalSeed';
import {
  confirmDemoOperationalNotification,
  decorateDemoPlanWithLoads,
  getDemoExtractionPlan,
  getDemoOperationalNotificationSummary,
  listDemoDocks,
  listDemoOperationalNotifications,
  listDemoRefrigeratedLoads,
  readDemoOperationalNotification,
  reportDemoLoadIncident,
  sendDemoLoadFolioToDock,
} from '../demo/demoLoadEngine';
import { ApiError } from './apiError';
import type { EstibaApi } from './estibaApi';

const demoIdentity: AuthSession = {
  token: `local-session-${Date.now()}-${Math.random().toString(36).slice(2)}`,
  token_type: 'Bearer',
  usuario: {
    id: 'user-demo',
    nombre: 'Administrador Demo',
    email: 'administrador@folios.demo',
    rol: 'administrador',
    ambito_camaras: 'ambos',
    modulos_tablet: ['demo_administracion', 'operacion'],
    capacidades: {
      modulos_tablet: ['demo_administracion', 'operacion'],
      ambito_camaras: 'ambos',
      puede_supervisar: true,
      puede_operar_productos: true,
      puede_operar_materiales: true,
      puede_consultar_cargas: true,
      puede_consultar_catalogo_cargas: false,
      puede_gestionar_cargas: true,
      puede_gestionar_andenes: false,
      puede_consultar_despachos_materiales: false,
      puede_gestionar_despachos_materiales: false,
      puede_retirar_materiales: false,
      puede_cancelar_despachos_materiales: false,
      puede_consultar_kardex_materiales: false,
      puede_validar_pallets: false,
      puede_rechazar_pallets: false,
      puede_consultar_validaciones_pallet: false,
    },
  },
  dispositivo: {
    id: 'device-demo',
    codigo: 'DEMO-01',
    nombre: 'Tablet autónoma Demo',
  },
};

function clone<T>(value: T): T {
  return JSON.parse(JSON.stringify(value)) as T;
}

export class DemoEstibaApi implements EstibaApi {
  readonly mode = 'demo' as const;
  readonly baseUrl = null;
  readonly configurationError = null;

  async login(payload: LoginPayload) {
    if (!payload.email || !payload.password || !payload.codigo_dispositivo) {
      throw new ApiError('Completa las credenciales y el código de tablet.', 422);
    }
    return clone(demoIdentity);
  }

  async logout() {}

  async changePassword() {}

  async listCameras() {
    return listDemoCameras();
  }

  async refreshCameras() {
    return listDemoCameras();
  }

  async listConditions() {
    return clone(demoSagConditions);
  }

  async getMaterialCatalog(): Promise<MaterialCatalog> {
    return { temporada: null, clientes: [], items: [], destinos: [] };
  }

  async listMaterialDispatches(
    _token: string,
    _states?: MaterialDispatch['estado'][],
  ): Promise<MaterialDispatch[]> {
    return [];
  }

  async listMaterialDispatchSummaries(
    _token: string,
    _states?: MaterialDispatch['estado'][],
  ): Promise<MaterialDispatchSummary[]> {
    return [];
  }

  async getMaterialDispatch(_token: string, _dispatchId: string): Promise<MaterialDispatch> {
    throw new ApiError('No existen despachos de materiales en modo demo.', 404);
  }

  async listRefrigeratedLoads(_token: string): Promise<RefrigeratedLoad[]> {
    return listDemoRefrigeratedLoads();
  }

  async refreshRefrigeratedLoads(_token: string): Promise<RefrigeratedLoad[]> {
    return listDemoRefrigeratedLoads();
  }

  async getExtractionPlan(_token: string, loadId: string): Promise<ExtractionPlan> {
    return getDemoExtractionPlan(loadId);
  }

  async refreshExtractionPlan(_token: string, loadId: string): Promise<ExtractionPlan> {
    return getDemoExtractionPlan(loadId);
  }

  async listDocks(_token: string): Promise<Dock[]> {
    return listDemoDocks();
  }

  async reportLoadIncident(
    _token: string,
    assignmentId: string,
    payload: Parameters<typeof reportDemoLoadIncident>[1],
  ): Promise<void> {
    return reportDemoLoadIncident(assignmentId, payload);
  }

  async sendLoadFolioToDock(
    _token: string,
    assignmentId: string,
    payload: Parameters<typeof sendDemoLoadFolioToDock>[1],
  ): Promise<RefrigeratedLoad> {
    return sendDemoLoadFolioToDock(assignmentId, payload);
  }

  async listOperationalNotifications(): Promise<OperationalNotificationFeed> {
    return listDemoOperationalNotifications();
  }

  async getOperationalNotificationSummary() {
    return getDemoOperationalNotificationSummary();
  }

  async readOperationalNotification(
    _token: string,
    notificationId: string,
  ): Promise<OperationalNotification> {
    return readDemoOperationalNotification(notificationId);
  }

  async confirmOperationalNotification(
    _token: string,
    notificationId: string,
  ): Promise<OperationalNotification> {
    return confirmDemoOperationalNotification(notificationId);
  }

  async createMaterialDispatch(_token: string, _payload: CreateMaterialDispatchPayload): Promise<MaterialDispatch> {
    throw new ApiError('El despacho de materiales no está disponible en modo demo.', 422);
  }

  async withdrawMaterial(_token: string, _dispatchId: string, _payload: WithdrawMaterialPayload): Promise<MaterialDispatch> {
    throw new ApiError('El despacho de materiales no está disponible en modo demo.', 422);
  }

  async listMaterialTransformations(): Promise<MaterialTransformationOrderSummary[]> {
    return [];
  }

  async getMaterialTransformation(
    _token: string,
    _orderId: string,
  ): Promise<MaterialTransformationOrder> {
    throw new ApiError('La transformación de materiales no está disponible en modo demo.', 422);
  }

  async startMaterialTransformation(
    _token: string,
    _orderId: string,
    _payload: StartMaterialTransformationPayload,
  ): Promise<MaterialTransformationOrder> {
    throw new ApiError('La transformación de materiales no está disponible en modo demo.', 422);
  }

  async openMaterialTransformationLot(
    _token: string,
    _orderId: string,
    _payload: OpenMaterialTransformationLotPayload,
  ): Promise<MaterialTransformationOrder> {
    throw new ApiError('La transformación de materiales no está disponible en modo demo.', 422);
  }

  async closeMaterialTransformationLot(
    _token: string,
    _lotId: string,
    _payload: CloseMaterialTransformationLotPayload,
  ): Promise<MaterialTransformationOrder> {
    throw new ApiError('La transformación de materiales no está disponible en modo demo.', 422);
  }

  async confirmMaterialTransformationOutput(
    _token: string,
    _orderId: string,
    _payload: ConfirmMaterialTransformationOutputPayload,
  ): Promise<MaterialTransformationOrder> {
    throw new ApiError('La transformación de materiales no está disponible en modo demo.', 422);
  }

  async reverseMaterialTransformationLot(
    _token: string,
    _lotId: string,
    _payload: ReverseMaterialTransformationLotPayload,
  ): Promise<MaterialTransformationOrder> {
    throw new ApiError('La transformación de materiales no está disponible en modo demo.', 422);
  }

  async closeMaterialTransformationOrder(
    _token: string,
    _orderId: string,
    _payload: CloseMaterialTransformationOrderPayload,
  ): Promise<MaterialTransformationOrder> {
    throw new ApiError('La transformación de materiales no está disponible en modo demo.', 422);
  }

  async materialLabelProfiles(): Promise<LabelPrintProfile[]> {
    return [];
  }

  async materialTransformationPrintJobs(): Promise<MaterialPrintJob[]> {
    return [];
  }

  async generateMaterialTransformationLabels(
    _token: string,
    _orderId: string,
    _payload: GenerateMaterialLabelsPayload,
  ): Promise<{ jobId: string; zpl: string }> {
    throw new ApiError('La impresión directa requiere conexión con la API.', 422);
  }

  async reportMaterialTransformationPrintOutcome(
    _token: string,
    _jobId: string,
    _payload: MaterialPrintOutcomePayload,
  ): Promise<void> {
    throw new ApiError('La impresión directa requiere conexión con la API.', 422);
  }

  async getPlan(_token: string, cameraId: string) {
    return decorateDemoPlanWithLoads(await getDemoPlan(cameraId));
  }

  async refreshPlan(token: string, cameraId: string) {
    return this.getPlan(token, cameraId);
  }

  async listRecent(_token: string, cameraId: string) {
    return listDemoRecentMovements(cameraId);
  }

  async refreshRecent(_token: string, cameraId: string) {
    return listDemoRecentMovements(cameraId);
  }

  async openSession(_token: string, cameraId: string) {
    return openDemoSession(cameraId);
  }

  async closeSession(_token: string, sessionId: string) {
    return closeDemoSession(sessionId);
  }

  async lookupFolio(_token: string, folioNumber: string, _cameraId?: string) {
    return lookupDemoFolio(folioNumber);
  }

  async searchAvailableMaterialFolios() {
    return [];
  }

  async locate(_token: string, payload: LocatePayload) {
    return locateDemoFolio(payload);
  }

  async move(_token: string, payload: MovePayload) {
    return moveDemoFolio(payload);
  }
}
