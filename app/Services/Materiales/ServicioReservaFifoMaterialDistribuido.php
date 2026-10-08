<?php

namespace App\Services\Materiales;

use App\Enums\CategoriaOperacionalMaterial;
use App\Enums\ContenidoCamara;
use App\Enums\EstadoCamara;
use App\Enums\EstadoOperacionalFolio;
use App\Enums\EstadoPosicion;
use App\Models\AlmacenMaterial;
use App\Models\FolioMaterial;
use App\Models\SaldoMaterialAlmacen;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use LogicException;

class ServicioReservaFifoMaterialDistribuido extends ServicioReservaFifoMaterial
{
    public function __construct(
        private readonly ServicioAlmacenMaterial $almacenes,
        private readonly ContextoSaldoReservaMaterial $contextoReserva,
    ) {}

    /**
     * Reserva únicamente saldos pertenecientes a Bodega Central.
     *
     * El cuarto argumento enviado al callback es opcional para conservar
     * compatibilidad con consumidores históricos de este servicio.
     *
     * @param  Closure(FolioMaterial, float, int, SaldoMaterialAlmacen=): void  $registrarReserva
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
            $saldo = $this->siguienteDisponibleBloqueado(
                $itemMaterialId,
                $categoriasOperacionales,
            );

            if (! $saldo) {
                break;
            }

            $disponible = $saldo->cantidadDisponible();

            if ($disponible <= 0) {
                throw new LogicException(
                    'El selector FIFO bloqueó un saldo de almacén sin disponibilidad.',
                );
            }

            $folio = $saldo->folioMaterial;
            $cantidad = min($pendiente, $disponible);

            $this->contextoReserva->ejecutar(
                $saldo,
                fn () => $registrarReserva(
                    $folio,
                    $cantidad,
                    $ordenFifo++,
                    $saldo,
                ),
            );
            $versionResultante = (int) $saldo->version + 1;
            $saldo->update([
                'cantidad_reservada' => round(
                    (float) $saldo->cantidad_reservada + $cantidad,
                    3,
                ),
                'version' => $versionResultante,
            ]);
            $this->almacenes->sincronizarProyeccion($folio);
            $pendiente = round($pendiente - $cantidad, 3);
        }

        return max(0, $pendiente);
    }

    private function siguienteDisponibleBloqueado(
        string $itemMaterialId,
        ?array $categoriasOperacionales,
    ): ?SaldoMaterialAlmacen {
        while (true) {
            $candidato = $this->consultaCandidatos(
                $itemMaterialId,
                $categoriasOperacionales,
            )
                ->first([
                    'saldos_materiales_almacenes.id',
                    'saldos_materiales_almacenes.folio_id',
                ]);

            if (! $candidato) {
                return null;
            }

            $folio = FolioMaterial::query()
                ->lockForUpdate()
                ->findOrFail($candidato->folio_id);
            $saldo = SaldoMaterialAlmacen::query()
                ->with(['folioMaterial', 'almacen', 'camara', 'posicion'])
                ->lockForUpdate()
                ->find($candidato->id);

            if (! $saldo || ! $this->continuaDisponible(
                $saldo,
                $folio,
                $categoriasOperacionales,
            )) {
                continue;
            }

            return $saldo;
        }
    }

    /**
     * @param  array<int, CategoriaOperacionalMaterial|string>|null  $categoriasOperacionales
     */
    private function consultaCandidatos(
        string $itemMaterialId,
        ?array $categoriasOperacionales,
    ): Builder {
        $categorias = $this->normalizarCategorias($categoriasOperacionales);

        return SaldoMaterialAlmacen::query()
            ->join(
                'destinos_materiales as am',
                'am.id',
                '=',
                'saldos_materiales_almacenes.almacen_material_id',
            )
            ->join(
                'folios_materiales as fm',
                'fm.folio_id',
                '=',
                'saldos_materiales_almacenes.folio_id',
            )
            ->join('folios as f', 'f.id', '=', 'fm.folio_id')
            ->leftJoin(
                'camaras as ca',
                'ca.id',
                '=',
                'saldos_materiales_almacenes.camara_id',
            )
            ->leftJoin(
                'posiciones as p',
                'p.id',
                '=',
                'saldos_materiales_almacenes.posicion_id',
            )
            ->where('am.codigo', AlmacenMaterial::CODIGO_BODEGA_CENTRAL)
            ->where('am.activo', true)
            ->where('fm.item_material_id', $itemMaterialId)
            ->when($categorias !== [], fn (Builder $consulta) => $consulta
                ->whereIn('fm.categoria_operacional', $categorias))
            ->whereColumn(
                'saldos_materiales_almacenes.cantidad_actual',
                '>',
                'saldos_materiales_almacenes.cantidad_reservada',
            )
            ->tap(fn ($consulta) => ServicioVencimientoMaterial::filtrarVigentes($consulta, 'fm.fecha_vencimiento'))
            ->whereNull('fm.motivo_bloqueo')
            ->where('f.activo', true)
            ->where('f.estado_operacional', EstadoOperacionalFolio::Disponible->value)
            ->where('ca.contenido', ContenidoCamara::Materiales->value)
            ->where('ca.estado', EstadoCamara::Activa->value)
            ->where(function (Builder $ubicacion): void {
                $ubicacion
                    ->whereNull('saldos_materiales_almacenes.posicion_id')
                    ->orWhere('p.estado', EstadoPosicion::Activa->value);
            })
            ->orderByRaw('fm.fecha_vencimiento IS NULL')
            ->orderBy('fm.fecha_vencimiento')
            ->orderByRaw('fm.fecha_fabricacion IS NULL')
            ->orderBy('fm.fecha_fabricacion')
            ->orderBy('f.fecha_ingreso')
            ->orderBy('f.numero_folio')
            ->orderBy('f.id');
    }

    private function continuaDisponible(
        SaldoMaterialAlmacen $saldo,
        FolioMaterial $folio,
        ?array $categoriasOperacionales,
    ): bool {
        $categorias = $this->normalizarCategorias($categoriasOperacionales);

        return $saldo->almacen?->codigo === AlmacenMaterial::CODIGO_BODEGA_CENTRAL
            && $saldo->almacen?->activo
            && $saldo->cantidadDisponible() > 0
            && ! $folio->estaVencido()
            && $folio->motivo_bloqueo === null
            && $folio->folio?->activo
            && $folio->folio?->estado_operacional === EstadoOperacionalFolio::Disponible
            && ($categorias === [] || in_array(
                $folio->categoria_operacional?->value,
                $categorias,
                true,
            ))
            && $saldo->camara?->contenido === ContenidoCamara::Materiales
            && $saldo->camara?->estado === EstadoCamara::Activa
            && (! $saldo->posicion || $saldo->posicion->estado === EstadoPosicion::Activa);
    }

    /**
     * @param  array<int, CategoriaOperacionalMaterial|string>|null  $categoriasOperacionales
     * @return array<int, string>
     */
    private function normalizarCategorias(?array $categoriasOperacionales): array
    {
        return collect($categoriasOperacionales)
            ->map(fn (mixed $categoria): string => $categoria instanceof CategoriaOperacionalMaterial
                ? $categoria->value
                : trim((string) $categoria))
            ->filter()
            ->values()
            ->all();
    }
}
