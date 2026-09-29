export type VerificationItem = {
  id: string;
  version: number;
  resultado: 'coincide' | 'otro_folio' | 'posicion_vacia' | null;
  posicion: { camara: string; banda: number; posicion: number; nivel: number };
};

// El contrato de tablet no contiene folio_esperado_id ni número esperado.
export type ShiftVerification = {
  id: string;
  estado: 'pendiente' | 'completada' | 'vencida';
  inicio_at: string;
  vence_at: string;
  objetivo: number;
  completadas: number;
  items: VerificationItem[];
};
