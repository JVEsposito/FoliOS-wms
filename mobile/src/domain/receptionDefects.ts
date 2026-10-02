export type ReceptionDefectCategory = 'envase_danado' | 'envase_sucio' | 'producto_danado' | 'otro';
export type ReceptionDefectContainer = import('../services/containerCatalog').ContainerCode;

export type ReceptionDefectPhoto = {
  uri: string;
  name: string;
  type: 'image/jpeg' | 'image/png' | 'image/webp';
  fileSize?: number;
};

export type ReceptionDefect = {
  id: string;
  numero_recepcion: string;
  numero_guia_despacho: string;
  categoria: ReceptionDefectCategory;
  tipo_envase: ReceptionDefectContainer | null;
  cantidad_afectada: number | null;
  descripcion: string;
  registrado_at: string;
  validador: { id: string; nombre: string };
  evidencias: Array<{ id: string; tipo: 'defecto' | 'guia'; url: string }>;
};

export type ReceptionDefectDraft = {
  operacionId: string;
  categoria: ReceptionDefectCategory;
  tipoEnvase: ReceptionDefectContainer | null;
  cantidadAfectada: string;
  descripcion: string;
  fotografias: ReceptionDefectPhoto[];
  fotografiaGuia: ReceptionDefectPhoto | null;
};
