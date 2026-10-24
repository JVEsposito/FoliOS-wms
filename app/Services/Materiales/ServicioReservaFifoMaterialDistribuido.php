<?php

namespace App\Services\Materiales;

use App\Enums\CategoriaOperacionalMaterial;
use App\Enums\ContenidoCamara;
use App\Enums\EstadoCamara;
use App\Enums\EstadoOperacionalFolio;
use App\Enums\EstadoPosicion;
use App\Enums\TipoAlmacenMaterial;
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
        ?string $almacenOrigenId = null,
    ): float {
        $bodega = $this->almacenes->origenDespacho($almacenOrigenId);
        $pendiente = round($cantidadRequerida, 3);
        $ordenFifo = 1;

        while ($pendiente > 0.0001) {
            $saldo = $this->siguienteDisponibleBloqueado(
                $itemMaterialId,
                $categoriasOperacionales,
                $bodega->id,
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
        string $almacenOrigenId,
    ): ?SaldoMaterialAlmacen {
        while (true) {
            $candidato = $this->consultaCandidatos(
                $itemMaterialId,
                $categoriasOperacionales,
                $almacenOrigenId,
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
                $almacenOrigenId,
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
        string $almacenOrigenId,
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
            ->where('am.id', $almacenOrigenId)
            ->where('am.tipo', 'fisica')
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
            ->whereIn('f.estado_operacional', [EstadoOperacionalFolio::Disponible->value, EstadoOperacionalFolio::PendienteUbicacion->value])
            ->where(fn (Builder $q) => $q->where('am.requiere_ubicacion_fisica', false)->orWhere(fn (Builder $q) => $q
                ->where('ca.contenido', ContenidoCamara::Materiales->value)->where('ca.estado', EstadoCamara::Activa->value)
                ->where(fn (Builder $p) => $p->whereNull('saldos_materiales_almacenes.posicion_id')->orWhere('p.estado', EstadoPosicion::Activa->value))))
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
        string $almacenOrigenId,
    ): bool {
        $categorias = $this->normalizarCategorias($categoriasOperacionales);

        return $saldo->almacen_material_id === $almacenOrigenId && $saldo->almacen?->tipo === TipoAlmacenMaterial::Fisica
            && $saldo->almacen?->activo
            && $saldo->cantidadDisponible() > 0
            && ! $folio->estaVencido()
            && $folio->motivo_bloqueo === null
            && $folio->folio?->activo
            && in_array($folio->folio?->estado_operacional, [EstadoOperacionalFolio::Disponible, EstadoOperacionalFolio::PendienteUbicacion], true)
            && ($categorias === [] || in_array(
                $folio->categoria_operacional?->value,
                $categorias,
                true,
            ))
            && (! $saldo->almacen->requiere_ubicacion_fisica || ($saldo->camara?->contenido === ContenidoCamara::Materiales
                && $saldo->camara?->estado === EstadoCamara::Activa
                && (! $saldo->posicion || $saldo->posicion->estado === EstadoPosicion::Activa)));
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
