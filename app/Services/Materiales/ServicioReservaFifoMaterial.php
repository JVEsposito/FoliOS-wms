<?php

namespace App\Services\Materiales;

use App\Enums\CategoriaOperacionalMaterial;
use App\Enums\ContenidoCamara;
use App\Enums\EstadoCamara;
use App\Enums\EstadoOperacionalFolio;
use App\Enums\EstadoPosicion;
use App\Models\FolioMaterial;
use Closure;
use LogicException;

class ServicioReservaFifoMaterial
{
    /**
     * Reserva únicamente los folios necesarios y mantiene bloqueado cada candidato
     * seleccionado hasta que finaliza la transacción que envuelve la operación.
     *
     * @param  Closure(FolioMaterial, float, int): void  $registrarReserva
     */
    public function reservar(
        string $itemMaterialId,
        float $cantidadRequerida,
        Closure $registrarReserva,
        ?array $categoriasOperacionales = null,
    ): float {
        $pendiente = round($cantidadRequerida, 3);
        $ordenFifo = 1;

        while ($pendiente > 0.0001) {
            $folio = $this->siguienteDisponibleBloqueado(
                $itemMaterialId,
                $categoriasOperacionales,
            );

            if (! $folio) {
                break;
            }

            $disponible = round(
                (float) $folio->cantidad_actual - (float) $folio->cantidad_reservada,
                3,
            );

            if ($disponible <= 0) {
                throw new LogicException(
                    'El selector FIFO bloqueó un folio sin saldo disponible.',
                );
            }

            $cantidad = min($pendiente, $disponible);
            $registrarReserva($folio, $cantidad, $ordenFifo++);
            $folio->increment('cantidad_reservada', $cantidad);
            $pendiente = round($pendiente - $cantidad, 3);
        }

        return max(0, $pendiente);
    }

    /**
     * @param  array<int, CategoriaOperacionalMaterial|string>|null  $categoriasOperacionales
     */
    private function siguienteDisponibleBloqueado(
        string $itemMaterialId,
        ?array $categoriasOperacionales,
    ): ?FolioMaterial {
        $categorias = collect($categoriasOperacionales)
            ->map(fn (mixed $categoria): string => $categoria instanceof CategoriaOperacionalMaterial
                ? $categoria->value
                : trim((string) $categoria))
            ->filter()
            ->values()
            ->all();

        return FolioMaterial::query()
            ->join('folios', 'folios.id', '=', 'folios_materiales.folio_id')
            ->select('folios_materiales.*')
            ->where('folios_materiales.item_material_id', $itemMaterialId)
            ->when($categorias !== [], fn ($consulta) => $consulta
                ->whereIn('folios_materiales.categoria_operacional', $categorias))
            ->whereColumn(
                'folios_materiales.cantidad_actual',
                '>',
                'folios_materiales.cantidad_reservada',
            )
            ->tap(fn ($consulta) => ServicioVencimientoMaterial::filtrarVigentes($consulta, 'folios_materiales.fecha_vencimiento'))
            ->whereNull('folios_materiales.motivo_bloqueo')
            ->where('folios.activo', true)
            ->where('folios.estado_operacional', EstadoOperacionalFolio::Disponible->value)
            ->whereHas('folio.ubicacionActual', fn ($consulta) => $consulta
                ->whereHas('camara', fn ($camaras) => $camaras
                    ->where('contenido', ContenidoCamara::Materiales->value)
                    ->where('estado', EstadoCamara::Activa->value))
                ->where(fn ($ubicaciones) => $ubicaciones
                    ->whereNull('posicion_id')
                    ->orWhereHas('posicion', fn ($posiciones) => $posiciones
                        ->where('estado', EstadoPosicion::Activa->value))))
            ->orderByRaw('folios_materiales.fecha_vencimiento IS NULL')
            ->orderBy('folios_materiales.fecha_vencimiento')
            ->orderBy('folios.fecha_ingreso')
            ->orderBy('folios.numero_folio')
            ->orderBy('folios_materiales.folio_id')
            ->lockForUpdate()
            ->first();
    }
}
