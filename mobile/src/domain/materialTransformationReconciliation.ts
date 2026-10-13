import type {
  MaterialTransformationOrder,
  MaterialTransformationOutputFolio,
  MaterialTransformationReservation,
} from './materialTransformation';

/**
 * Lógica del modo cuadratura (sin React): retiro de componentes al iniciar, confirmación
 * de folios de salida pistoleados y cuadratura de merma y sin armar al cerrar la orden.
 * El servidor repite cada cálculo; aquí solo se anticipa lo que verá el camarero.
 */

export type WithdrawalLine = {
  reservationId: string;
  folioId: string;
  numeroFolio: string;
  itemId: string;
  codigo: string;
  cantidad: number;
  unidad: string;
  ubicacion: string | null;
};

export type ReconciliationDraftLine = {
  sinArmar: string;
  merma: string;
  /** Mientras el camarero no edite la merma, se propone la que cuadra con la receta. */
  mermaEditada: boolean;
};

export type ReconciliationDraft = Record<string, ReconciliationDraftLine>;

export type ReturnLine = {
  numeroFolio: string;
  cantidad: number;
  /** El folio había quedado vacío: vuelve pendiente de ubicación. */
  requiereUbicacion: boolean;
};

export type ReconciliationRow = {
  itemId: string;
  codigo: string;
  nombre: string;
  unidad: string;
  principal: boolean;
  retirado: number;
  teorico: number;
  sinArmar: number;
  merma: number;
  mermaSugerida: number;
  utilizado: number;
  desviacion: number;
  fueraDeTolerancia: boolean;
  devoluciones: ReturnLine[];
  error: string | null;
};

export function round3(value: number): number {
  return Math.round(value * 1000) / 1000;
}

/** Cantidad escrita por el camarero; acepta coma decimal. Vacío cuenta como cero. */
export function parseDeclaredQuantity(value: string): number | null {
  const normalized = value.trim().replace(',', '.');
  if (normalized === '') return 0;
  const parsed = Number(normalized);
  return Number.isFinite(parsed) && parsed >= 0 ? round3(parsed) : null;
}

export function isReconciliationOrder(order: MaterialTransformationOrder | null): boolean {
  return order?.modo_operacion === 'cuadratura_cierre';
}

/** Una orden con folios de salida reservados se inicia en modo cuadratura. */
export function startsWithReconciliation(order: MaterialTransformationOrder | null): boolean {
  return order?.estado === 'planificada'
    && (order.folios_salida ?? []).some((folio) => folio.estado === 'reservado');
}

export function withdrawalLines(order: MaterialTransformationOrder): WithdrawalLine[] {
  return order.reservas
    .filter((reservation) => reservation.estado === 'activa' && reservation.folio)
    .map((reservation) => {
      const component = order.receta_snapshot.componentes.find(
        (candidate) => candidate.item_id === reservation.item_material_id,
      );
      return {
        reservationId: reservation.id,
        folioId: reservation.folio!.id,
        numeroFolio: reservation.folio!.numero_folio,
        itemId: reservation.item_material_id,
        codigo: component?.codigo ?? 'Ítem',
        cantidad: round3(Number(reservation.cantidad) - Number(reservation.cantidad_consumida)),
        unidad: component?.unidad_medida ?? '',
        ubicacion: reservation.folio!.ubicacion
          ? `${reservation.folio!.ubicacion.camara} / ${reservation.folio!.ubicacion.posicion}`
          : null,
      };
    });
}

export function matchWithdrawalScan(
  lines: WithdrawalLine[],
  scan: string,
): { line: WithdrawalLine } | { error: string } {
  const normalized = scan.trim().toUpperCase();
  const line = lines.find((candidate) => candidate.numeroFolio.toUpperCase() === normalized);
  if (!line) return { error: `El folio ${normalized} no está reservado para esta orden.` };
  if (!line.ubicacion) return { error: `El folio ${line.numeroFolio} debe ubicarse antes de retirarlo.` };
  return { line };
}

/** Etiqueta pistoleada en la línea: debe ser un folio reservado de la orden y sin usar. */
export function matchOutputScan(
  order: MaterialTransformationOrder,
  scan: string,
): { folio: MaterialTransformationOutputFolio } | { error: string } {
  const normalized = scan.trim().toUpperCase();
  const folio = (order.folios_salida ?? []).find(
    (candidate) => candidate.numero_folio.toUpperCase() === normalized,
  );
  if (!folio) return { error: `El folio ${normalized} no está reservado para esta orden.` };
  if (folio.estado === 'usado') return { error: `El folio ${folio.numero_folio} ya fue confirmado.` };
  if (folio.estado === 'anulado') return { error: `El folio ${folio.numero_folio} fue anulado: retira esa etiqueta.` };
  return { folio };
}

export function producedQuantity(order: MaterialTransformationOrder): number {
  return round3(order.lotes
    .filter((lot) => lot.estado === 'cerrado')
    .reduce((sum, lot) => sum + Number(lot.cantidad_real_salida ?? 0), 0));
}

/** Lo sin armar vuelve primero al último folio retirado (FIFO inverso). */
export function returnPlan(
  reservations: MaterialTransformationReservation[],
  quantity: number,
): ReturnLine[] {
  let pending = round3(quantity);
  const plan: ReturnLine[] = [];
  const withdrawn = reservations
    .filter((reservation) => reservation.estado === 'consumida' && reservation.folio)
    .sort((a, b) => b.orden_fifo - a.orden_fifo);
  for (const reservation of withdrawn) {
    if (pending <= 0.0001) break;
    const back = round3(Math.min(pending, Number(reservation.cantidad_consumida)));
    if (back <= 0.0001) continue;
    plan.push({
      numeroFolio: reservation.folio!.numero_folio,
      cantidad: back,
      requiereUbicacion: Number(reservation.folio!.cantidad_actual) <= 0.0001,
    });
    pending = round3(pending - back);
  }
  return plan;
}

export function reconciliationRows(
  order: MaterialTransformationOrder,
  draft: ReconciliationDraft,
): ReconciliationRow[] {
  const produced = producedQuantity(order);
  const base = Number(order.receta_snapshot.salida.cantidad_base);

  return [...order.receta_snapshot.componentes]
    .sort((a, b) => Number(b.es_componente_principal) - Number(a.es_componente_principal))
    .map((component) => {
      const reservations = order.reservas.filter(
        (reservation) => reservation.item_material_id === component.item_id,
      );
      const retirado = round3(reservations
        .filter((reservation) => reservation.estado === 'consumida')
        .reduce((sum, reservation) => sum + Number(reservation.cantidad_consumida), 0));
      const teorico = base > 0 ? round3(Number(component.cantidad_estandar) * produced / base) : 0;
      const line = draft[component.item_id];
      const sinArmar = parseDeclaredQuantity(line?.sinArmar ?? '');
      const mermaSugerida = round3(Math.max(0, retirado - (sinArmar ?? 0) - teorico));
      const merma = line?.mermaEditada ? parseDeclaredQuantity(line.merma) : mermaSugerida;
      const utilizado = round3(retirado - (merma ?? 0) - (sinArmar ?? 0));
      const desviacion = round3(utilizado - teorico);
      const tolerancia = round3(teorico * Number(component.tolerancia_porcentaje || 0) / 100);
      let error: string | null = null;
      if (sinArmar === null || merma === null) {
        error = 'Ingresa cantidades iguales o mayores que cero.';
      } else if (merma + sinArmar - retirado > 0.0001) {
        error = `La merma y lo sin armar superan lo retirado (${formatPlain(retirado)} ${component.unidad_medida}).`;
      }

      return {
        itemId: component.item_id,
        codigo: component.codigo,
        nombre: component.nombre,
        unidad: component.unidad_medida,
        principal: component.es_componente_principal,
        retirado,
        teorico,
        sinArmar: sinArmar ?? 0,
        merma: merma ?? 0,
        mermaSugerida,
        utilizado,
        desviacion,
        fueraDeTolerancia: Math.abs(desviacion) - tolerancia > 0.0005,
        devoluciones: error ? [] : returnPlan(reservations, sinArmar ?? 0),
        error,
      };
    });
}

/** Motivo obligatorio: producción distinta de lo planificado o un componente que no cuadra. */
export function closeRequiresReason(
  order: MaterialTransformationOrder,
  rows: ReconciliationRow[],
): boolean {
  return Math.abs(producedQuantity(order) - Number(order.cantidad_planificada_salida)) > 0.0001
    || rows.some((row) => row.fueraDeTolerancia);
}

export function closeReconciliationLines(rows: ReconciliationRow[]) {
  return rows.map((row) => ({
    item_id: row.itemId,
    cantidad_merma: row.merma,
    cantidad_sin_armar: row.sinArmar,
  }));
}

function formatPlain(value: number): string {
  return String(round3(value)).replace('.', ',');
}
