export type MaterialReceptionState = 'borrador' | 'confirmada' | 'anulada';

export type ReceptionSeason = {
  id: string;
  catalogo_material_id: string;
  codigo: string;
  nombre: string;
};

export type ReceptionClient = {
  id: string;
  cliente_material_id: string;
  codigo: string;
  codigo_folio_materiales: string | null;
  nombre: string;
};

export type ReceptionSupplier = {
  id: string;
  codigo: string;
  nombre: string;
  cliente_ids: string[];
  categorias: { cliente_id: string; categoria: string }[];
};

export type ReceptionCatalogItem = {
  foto_principal?: { id: string; miniatura_url: string; url?: string } | null;
  id: string;
  cliente_id: string;
  cliente_material_id: string;
  codigo: string;
  nombre: string;
  categoria: string | null;
  categoria_operacional: string;
  categoria_operacional_etiqueta: string;
  unidad_medida: string;
};

export type MaterialReceptionCatalog = {
  temporada: ReceptionSeason | null;
  clientes: ReceptionClient[];
  proveedores: ReceptionSupplier[];
  items: ReceptionCatalogItem[];
};

export type ReceptionPackage = {
  id: string;
  cantidad: string;
  lote_proveedor: string | null;
  fecha_fabricacion: string | null;
  fecha_vencimiento: string | null;
  bloqueado: boolean;
  motivo_bloqueo: string | null;
  folio: {
    id: string;
    numero_folio: string;
    estado_operacional: string;
    ubicacion: { camara: string | null; posicion: string | null } | null;
  } | null;
};

export type ReceptionDetail = {
  id: string;
  item: { id: string; codigo: string; nombre: string } | null;
  categoria_operacional: string;
  categoria_operacional_etiqueta: string;
  unidad_medida: string;
  cantidad_documental: string;
  cantidad_contada: string;
  cantidad_aceptada: string;
  cantidad_recibida: string;
  cantidad_rechazada: string;
  observacion: string | null;
  bultos: ReceptionPackage[];
};

export type MaterialReception = {
  id: string;
  temporada: { id: string; codigo: string; nombre: string; activa: boolean } | null;
  cliente: {
    id: string;
    codigo: string;
    codigo_folio_materiales: string | null;
    nombre: string;
  } | null;
  proveedor: { id: string; codigo: string; nombre: string } | null;
  numero_guia_despacho: string;
  fecha_documento: string | null;
  orden_compra: string | null;
  patente: string | null;
  transportista: string | null;
  estado: MaterialReceptionState;
  version: number;
  observacion: string | null;
  detalles?: ReceptionDetail[];
  confirmado_at: string | null;
  anulado_at: string | null;
  motivo_anulacion: string | null;
  created_at: string | null;
  updated_at: string | null;
};

export type PendingReceptionFolio = {
  folio_id: string;
  numero_folio: string;
  estado_operacional: string;
  cliente: { id: string; codigo: string; nombre: string } | null;
  item: { id: string; codigo: string; nombre: string };
  categoria_operacional: string;
  cantidad_actual: string;
  unidad_medida: string;
  lote_proveedor: string | null;
  bloqueado: boolean;
  motivo_bloqueo: string | null;
  recepcion: {
    id: string;
    numero_guia_despacho: string;
    proveedor: string | null;
    confirmado_at: string | null;
  } | null;
};

export type LabelPrintProfile = {
  id: string;
  codigo: string;
  nombre: string;
  fabricante: string;
  modelo: string | null;
  lenguaje: 'zpl';
  dpi: number;
  ancho_mm: string | number;
  alto_mm: string | number;
  orientacion: 'horizontal' | 'vertical';
  predeterminado: boolean;
  activo: boolean;
};

export type MaterialPrintJob = {
  id: string;
  operacion_id: string;
  formato: 'pdf' | 'zpl';
  canal: 'oficina_descarga' | 'pda_directa';
  estado: 'generado' | 'enviado' | 'fallido' | 'indeterminado';
  copias: number;
  motivo_reimpresion: string | null;
  perfil: LabelPrintProfile;
  folios: Array<{
    id: string;
    numero_folio: string;
    es_reimpresion: boolean;
  }>;
  solicitado_por: string;
  solicitado_at: string;
  enviado_at: string | null;
  bytes_enviados: number | null;
  destino_impresion: {
    nombre: string;
    host: string;
    puerto: number;
  } | null;
  ultimo_error: string | null;
};

export type GenerateMaterialLabelsPayload = {
  operacion_id: string;
  perfil_id: string;
  formato: 'zpl';
  canal: 'pda_directa';
  folio_ids: string[];
  copias: number;
  motivo_reimpresion: string | null;
};

export type MaterialPrintOutcomePayload = {
  operacion_id: string;
  estado: 'enviado' | 'fallido' | 'indeterminado';
  bytes_enviados: number;
  error: string | null;
  impresora: {
    nombre: string;
    host: string;
    puerto: number;
  };
};

export type ReceptionDraftPackage = {
  local_id: string;
  cantidad: string;
  lote_proveedor: string;
  fecha_fabricacion: string;
  fecha_vencimiento: string;
  bloqueado: boolean;
  motivo_bloqueo: string;
};

export type ReceptionDraftDetail = {
  local_id: string;
  item_material_id: string;
  cantidad_documental: string;
  cantidad_contada: string;
  cantidad_por_bulto: string;
  observacion: string;
  bultos: ReceptionDraftPackage[];
};

export type CreateMaterialReceptionPayload = {
  operacion_id: string;
  cliente_id: string;
  proveedor_material_id: string;
  numero_guia_despacho: string;
  fecha_documento: string | null;
  orden_compra: string | null;
  patente: string | null;
  transportista: string | null;
  observacion: string | null;
  detalles: Array<{
    item_material_id: string;
    cantidad_documental: number;
    cantidad_contada: number;
    cantidad_aceptada: number;
    cantidad_recibida: number;
    cantidad_rechazada: number;
    observacion: string | null;
    bultos: Array<{
      cantidad: number;
      lote_proveedor: string | null;
      fecha_fabricacion: string | null;
      fecha_vencimiento: string | null;
      bloqueado: boolean;
      motivo_bloqueo: string | null;
    }>;
  }>;
};

export type UpdateMaterialReceptionPayload = CreateMaterialReceptionPayload & {
  version_conocida: number;
};
