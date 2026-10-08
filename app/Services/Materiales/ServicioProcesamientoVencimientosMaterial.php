<?php

namespace App\Services\Materiales;

use App\Enums\EstadoReservaMaterial;
use App\Models\DespachoMaterial;
use App\Models\DetalleDespachoMaterial;
use App\Models\FolioMaterial;
use App\Models\OrdenTransformacionMaterial;
use App\Models\ReservaMaterial;
use App\Models\ReservaTransformacionMaterial;
use App\Models\SaldoMaterialAlmacen;
use App\Services\Gerencia\ServicioPanelGerencial;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

class ServicioProcesamientoVencimientosMaterial
{
    public function __construct(
        private readonly ServicioReservaFifoMaterial $fefo,
        private readonly ServicioAlmacenMaterial $almacenes,
        private readonly ServicioBloqueoMaterial $bloqueos,
    ) {}

    public function procesar(): array
    {
        return DB::transaction(function (): array {
            $resumen = ['folios_procesados' => 0, 'reservas_liberadas' => 0,
                'reservas_reasignadas' => 0, 'lineas_insuficientes' => []];
            $temporada = app(ServicioTemporadaActiva::class)->buscarConBloqueoCompartido();
            $ids = $temporada ? FolioMaterial::query()
                ->where('fecha_vencimiento', '<', ServicioVencimientoMaterial::hoyChile())
                ->where('bloqueado_por_vencimiento', false)
                ->where('cantidad_actual', '>', 0)
                ->whereHas('folio', fn ($q) => $q->where('activo', true)->where('temporada_id', $temporada->id))
                ->orderBy('folio_id')->pluck('folio_id') : collect();

            // Se bloquean primero las solicitudes y órdenes, como en el retiro/cierre.
            $detalles = DetalleDespachoMaterial::query()->whereHas('reservas', fn ($q) => $q
                ->whereIn('folio_id', $ids)->where('estado', EstadoReservaMaterial::Activa->value))->get();
            DespachoMaterial::query()->whereIn('id', $detalles->pluck('despacho_material_id'))
                ->orderBy('id')->lockForUpdate()->get();
            $ordenes = OrdenTransformacionMaterial::query()->whereHas('reservas', fn ($q) => $q
                ->whereIn('folio_id', $ids)->where('estado', EstadoReservaMaterial::Activa->value))
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $materiales = FolioMaterial::query()->with('folio')->whereIn('folio_id', $ids)
                ->orderBy('folio_id')->lockForUpdate()->get()
                ->filter(fn ($m) => ! $m->bloqueado_por_vencimiento && $m->estaVencido());
            $ids = $materiales->pluck('folio_id');
            $despachosLiberados = [];
            $transformacionesLiberadas = [];
            foreach ([ReservaMaterial::class, ReservaTransformacionMaterial::class] as $clase) {
                $reservas = $clase::query()->whereIn('folio_id', $ids)
                    ->where('estado', EstadoReservaMaterial::Activa->value)
                    ->orderBy('folio_id')->orderBy('id')->lockForUpdate()->get();
                foreach ($reservas as $reserva) {
                    $restante = round((float) $reserva->cantidad - (float) ($reserva->cantidad_consumida ?? 0), 3);
                    $saldo = SaldoMaterialAlmacen::query()->lockForUpdate()->findOrFail($reserva->saldo_material_almacen_id);
                    if ($restante > (float) $saldo->cantidad_reservada + 0.0001) {
                        throw new LogicException('La reserva vencida no coincide con el saldo del almacén.');
                    }
                    $saldo->update(['cantidad_reservada' => max(0, round((float) $saldo->cantidad_reservada - $restante, 3))]);
                    $reserva->update(['estado' => EstadoReservaMaterial::Liberada]);
                    $resumen['reservas_liberadas']++;
                    if ($reserva instanceof ReservaMaterial) {
                        $id = $reserva->detalle_despacho_material_id;
                        $despachosLiberados[$id] = ($despachosLiberados[$id] ?? 0) + $restante;
                    } else {
                        $id = $reserva->orden_transformacion_material_id;
                        $item = $reserva->item_material_id;
                        $transformacionesLiberadas[$id][$item] = ($transformacionesLiberadas[$id][$item] ?? 0) + $restante;
                    }
                }
            }
            foreach ($materiales as $material) {
                $this->almacenes->sincronizarProyeccion($material);
                $this->bloqueos->bloquearPorVencimiento($material->refresh(), (string) Str::uuid());
                $resumen['folios_procesados']++;
            }
            foreach ($despachosLiberados as $id => $cantidad) {
                $detalle = DetalleDespachoMaterial::query()->lockForUpdate()->findOrFail($id);
                $faltante = $this->reasignarDespacho($detalle, $cantidad, $resumen);
                $detalle->update(['cantidad_sin_reserva_por_vencimiento' => round(
                    (float) $detalle->cantidad_sin_reserva_por_vencimiento + $faltante, 3,
                )]);
                if ($faltante > 0.0001) {
                    $resumen['lineas_insuficientes'][] = ['tipo' => 'despacho', 'detalle_id' => $id,
                        'item_id' => $detalle->item_material_id, 'cantidad' => $faltante];
                }
            }
            foreach ($transformacionesLiberadas as $id => $items) {
                $orden = $ordenes->get($id);
                $faltantes = $orden->faltantes_por_vencimiento ?? [];
                foreach ($items as $itemId => $cantidad) {
                    $componente = collect(data_get($orden->snapshot_receta, 'componentes', []))->firstWhere('item_id', $itemId);
                    $faltante = $this->reasignarTransformacion($orden, $itemId, $cantidad,
                        isset($componente['categoria_operacional']) ? [$componente['categoria_operacional']] : null, $resumen);
                    if ($faltante > 0.0001) {
                        $faltantes[$itemId] = round(($faltantes[$itemId] ?? 0) + $faltante, 3);
                        $resumen['lineas_insuficientes'][] = ['tipo' => 'transformacion', 'orden_id' => $id,
                            'item_id' => $itemId, 'cantidad' => $faltante];
                    }
                }
                $orden->update(['faltantes_por_vencimiento' => $faltantes ?: null, 'version' => $orden->version + 1]);
            }
            DB::table('procesamientos_vencimientos_materiales')->insert([
                'id' => (string) Str::uuid(), 'fecha_chile' => ServicioVencimientoMaterial::hoyChile(),
                ...$resumen, 'lineas_insuficientes' => json_encode($resumen['lineas_insuficientes'], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);
            app(ServicioPanelGerencial::class)->invalidar();

            return $resumen;
        }, attempts: 3);
    }

    private function reasignarDespacho(DetalleDespachoMaterial $detalle, float $cantidad, array &$resumen): float
    {
        $faltante = $this->fefo->reservar($detalle->item_material_id, $cantidad,
            function (FolioMaterial $material, float $asignada, int $orden) use ($detalle, &$resumen): void {
                $reserva = ReservaMaterial::query()->firstOrNew([
                    'detalle_despacho_material_id' => $detalle->id, 'folio_id' => $material->folio_id,
                ]);
                $anterior = $reserva->estado === EstadoReservaMaterial::Activa ? (float) $reserva->cantidad : 0;
                $reserva->fill(['cantidad' => round($anterior + $asignada, 3),
                    'estado' => EstadoReservaMaterial::Activa, 'orden_fifo' => $orden])->save();
                $resumen['reservas_reasignadas']++;
            });
        $this->ordenarFefo(ReservaMaterial::query()->where('detalle_despacho_material_id', $detalle->id));

        return $faltante;
    }

    private function reasignarTransformacion(OrdenTransformacionMaterial $orden, string $item, float $cantidad, ?array $categorias, array &$resumen): float
    {
        $faltante = $this->fefo->reservar($item, $cantidad,
            function (FolioMaterial $material, float $asignada, int $ordenFifo) use ($orden, $item, &$resumen): void {
                $reserva = ReservaTransformacionMaterial::query()->firstOrNew([
                    'orden_transformacion_material_id' => $orden->id, 'folio_id' => $material->folio_id,
                ]);
                $anterior = $reserva->estado === EstadoReservaMaterial::Activa ? (float) $reserva->cantidad
                    : (float) ($reserva->cantidad_consumida ?? 0);
                $reserva->fill(['item_material_id' => $item, 'cantidad' => round($anterior + $asignada, 3),
                    'estado' => EstadoReservaMaterial::Activa, 'orden_fifo' => $ordenFifo])->save();
                $resumen['reservas_reasignadas']++;
            }, $categorias);
        $this->ordenarFefo(ReservaTransformacionMaterial::query()->where('orden_transformacion_material_id', $orden->id)->where('item_material_id', $item));

        return $faltante;
    }

    private function ordenarFefo($consulta): void
    {
        $reservas = $consulta->where('estado', EstadoReservaMaterial::Activa->value)
            ->with('folioMaterial.folio')->get()->sortBy(fn ($r) => sprintf('%s:%s:%s',
                $r->folioMaterial->fecha_vencimiento?->toDateString() ?? '9999-12-31',
                $r->folioMaterial->fecha_fabricacion?->toDateString() ?? '9999-12-31',
                $r->folioMaterial->folio->fecha_ingreso?->toAtomString() ?? '',
            ))->values();
        foreach ($reservas as $i => $reserva) {
            $reserva->update(['orden_fifo' => $i + 1]);
        }
    }
}
