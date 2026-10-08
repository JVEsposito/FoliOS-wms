<?php

namespace App\Services\Materiales;

use App\Enums\ContenidoCamara;
use App\Enums\EstadoCamara;
use App\Enums\EstadoOperacionalFolio;
use App\Enums\EstadoPosicion;
use App\Enums\TipoAlmacenMaterial;
use App\Models\SaldoMaterialAlmacen;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ServicioConsultaInventarioMaterialDistribuido extends ServicioConsultaInventarioMaterial
{
    public function detalle(array $filtros): LengthAwarePaginator
    {
        $consulta = $this->consultaBodega()
            ->with([
                'almacen',
                'camara',
                'posicion',
                'folioMaterial.item.cliente.temporada',
                'folioMaterial.item.cliente.cliente',
                'folioMaterial.folio',
                'folioMaterial.bultoRecepcion.detalle.recepcion.proveedor',
            ])
            ->when($filtros['cliente_id'] ?? null, function (
                Builder $query,
                string $clienteId,
            ): void {
                $query->whereHas(
                    'folioMaterial.item',
                    fn (Builder $items) => $items
                        ->where('cliente_material_id', $clienteId),
                );
            })
            ->when($filtros['q'] ?? null, function (
                Builder $query,
                string $busqueda,
            ): void {
                $termino = '%'.trim($busqueda).'%';
                $query->where(function (Builder $coincidencias) use ($termino): void {
                    $coincidencias
                        ->whereHas('folioMaterial', fn (Builder $materiales) => $materiales
                            ->where('lote', 'like', $termino))
                        ->orWhereHas('folioMaterial.folio', fn (Builder $folios) => $folios
                            ->where('numero_folio', 'like', $termino))
                        ->orWhereHas('folioMaterial.item', fn (Builder $items) => $items
                            ->where('codigo', 'like', $termino)
                            ->orWhere('nombre', 'like', $termino)
                            ->orWhereHas('cliente', fn (Builder $clientes) => $clientes
                                ->where('codigo', 'like', $termino)
                                ->orWhere('nombre', 'like', $termino)))
                        ->orWhereHas('camara', fn (Builder $camaras) => $camaras
                            ->where('codigo', 'like', $termino)
                            ->orWhere('nombre', 'like', $termino))
                        ->orWhereHas('posicion', fn (Builder $posiciones) => $posiciones
                            ->where('etiqueta', 'like', $termino));
                });
            })
            ->orderBy('folio_id');

        $paginacion = $consulta
            ->paginate((int) ($filtros['per_page'] ?? 25))
            ->withQueryString();
        $paginacion->setCollection(
            $paginacion->getCollection()
                ->map(fn (SaldoMaterialAlmacen $saldo): array => $this->serializar($saldo)),
        );

        return $paginacion;
    }

    public function resumen(?string $clienteId = null): array
    {
        $saldos = $this->consultaBodega()
            ->with([
                'almacen',
                'camara',
                'posicion',
                'folioMaterial.item.cliente.temporada',
                'folioMaterial.item.cliente.cliente',
                'folioMaterial.folio',
            ])
            ->when($clienteId, fn (Builder $query, string $id) => $query
                ->whereHas(
                    'folioMaterial.item',
                    fn (Builder $items) => $items
                        ->where('cliente_material_id', $id),
                ))
            ->get();

        $items = $saldos
            ->groupBy(fn (SaldoMaterialAlmacen $saldo): string => sprintf(
                '%s:%s',
                $saldo->folioMaterial->item_material_id,
                $saldo->folioMaterial->categoria_operacional?->value ?? 'sin_categoria',
            ))
            ->map(fn (Collection $grupo): array => $this->resumenItem($grupo))
            ->sortBy(fn (array $fila): string => sprintf(
                '%s-%s',
                $fila['item']['cliente']['codigo'],
                $fila['item']['codigo'],
            ))
            ->values();

        $clientes = $saldos
            ->groupBy(fn (SaldoMaterialAlmacen $saldo): string => $saldo->folioMaterial->item->cliente->id)
            ->map(function (Collection $grupo): array {
                $primero = $grupo->first();
                $cliente = $primero->folioMaterial->item->cliente;
                $saldosUnidad = $grupo
                    ->groupBy(fn (SaldoMaterialAlmacen $saldo): string => $saldo->folioMaterial->unidad_medida)
                    ->map(function (Collection $unidad, string $nombre): array {
                        return [
                            'unidad_medida' => $nombre,
                            'cantidad_actual' => $this->cantidad($unidad->sum('cantidad_actual')),
                            'cantidad_pendiente_ubicacion' => $this->cantidad(
                                $unidad->filter(fn (SaldoMaterialAlmacen $saldo): bool => ! $saldo->camara_id)
                                    ->sum('cantidad_actual'),
                            ),
                            'cantidad_bloqueada' => $this->cantidad(
                                $unidad->filter(fn (SaldoMaterialAlmacen $saldo): bool => $saldo->folioMaterial->motivo_bloqueo !== null)
                                    ->sum('cantidad_actual'),
                            ),
                            'cantidad_reservada' => $this->cantidad($unidad->sum('cantidad_reservada')),
                            'cantidad_disponible' => $this->cantidad(
                                $unidad->sum(fn (SaldoMaterialAlmacen $saldo): float => $this->disponible($saldo)),
                            ),
                        ];
                    })
                    ->values();

                return [
                    'cliente' => $this->cliente($cliente),
                    'folios' => $grupo->pluck('folio_id')->unique()->count(),
                    'folios_pendientes_ubicacion' => $grupo
                        ->filter(fn (SaldoMaterialAlmacen $saldo): bool => ! $saldo->camara_id)
                        ->pluck('folio_id')
                        ->unique()
                        ->count(),
                    'folios_bloqueados' => $grupo
                        ->filter(fn (SaldoMaterialAlmacen $saldo): bool => $saldo->folioMaterial->motivo_bloqueo !== null)
                        ->pluck('folio_id')
                        ->unique()
                        ->count(),
                    'items' => $grupo
                        ->pluck('folioMaterial.item_material_id')
                        ->unique()
                        ->count(),
                    'posiciones' => $grupo->pluck('posicion_id')->filter()->unique()->count(),
                    'saldos' => $saldosUnidad,
                ];
            })
            ->values();

        return [
            'resumen' => [
                'folios' => $saldos->pluck('folio_id')->unique()->count(),
                'clientes' => $clientes->count(),
                'items' => $items->pluck('item.id')->unique()->count(),
            ],
            'resumen_clientes' => $clientes,
            'resumen_items' => $items,
        ];
    }

    private function consultaBodega(): Builder
    {
        return SaldoMaterialAlmacen::query()
            ->where('cantidad_actual', '>', 0)
            ->whereHas('almacen', fn (Builder $almacenes) => $almacenes
                ->where('tipo', TipoAlmacenMaterial::Fisica->value)
                ->where('activo', true))
            ->whereHas('folioMaterial.folio', fn (Builder $folios) => $folios
                ->where('activo', true)
                ->whereHas('temporada', fn (Builder $temporadas) => $temporadas
                    ->where('activa', true)));
    }

    /**
     * @param  Collection<int, SaldoMaterialAlmacen>  $grupo
     */
    private function resumenItem(Collection $grupo): array
    {
        $primero = $grupo->first();
        $item = $primero->folioMaterial->item;

        return [
            'item' => [
                'id' => $item->id,
                'cliente' => $this->cliente($item->cliente),
                'codigo' => $item->codigo,
                'nombre' => $item->nombre,
            ],
            'categoria_operacional' => $primero->folioMaterial->categoria_operacional?->value,
            'unidad_medida' => $primero->folioMaterial->unidad_medida,
            'folios' => $grupo->pluck('folio_id')->unique()->count(),
            'cantidad_actual' => $this->cantidad($grupo->sum('cantidad_actual')),
            'cantidad_reservada' => $this->cantidad($grupo->sum('cantidad_reservada')),
            'cantidad_disponible' => $this->cantidad(
                $grupo->sum(fn (SaldoMaterialAlmacen $saldo): float => $this->disponible($saldo)),
            ),
        ];
    }

    private function serializar(SaldoMaterialAlmacen $saldo): array
    {
        $material = $saldo->folioMaterial;
        $folio = $material->folio;
        $recepcion = $material->bultoRecepcion?->detalle?->recepcion;

        return [
            'folio_id' => $folio->id,
            'numero_folio' => $folio->numero_folio,
            'estado_operacional' => $folio->estado_operacional->value,
            'estado_ubicacion' => ! $saldo->camara_id
                ? 'pendiente_ubicacion'
                : ($saldo->posicion_id ? 'ubicado' : 'solo_camara'),
            'reservable' => $this->reservable($saldo),
            'motivo_bloqueo' => $material->motivo_bloqueo,
            'vencimiento' => $material->informacionVencimiento(),
            'item' => [
                'id' => $material->item->id,
                'cliente' => $this->cliente($material->item->cliente),
                'codigo' => $material->item->codigo,
                'nombre' => $material->item->nombre,
            ],
            'categoria_operacional' => $material->categoria_operacional?->value,
            'cantidad_inicial' => $material->cantidad_inicial,
            'cantidad_actual' => $saldo->cantidad_actual,
            'cantidad_reservada' => $saldo->cantidad_reservada,
            'cantidad_disponible' => $this->cantidad($this->disponible($saldo)),
            'cantidad_total_empresa' => $material->cantidad_actual,
            'unidad_medida' => $material->unidad_medida,
            'lote' => $material->lote,
            'fecha_ingreso' => $folio->fecha_ingreso?->toAtomString(),
            'almacen' => [
                'id' => $saldo->almacen->id,
                'codigo' => $saldo->almacen->codigo,
                'nombre' => $saldo->almacen->nombre,
                'tipo' => $saldo->almacen->tipo->value,
                'centro_costo' => $saldo->almacen->centro_costo,
            ],
            'camara' => $saldo->camara ? [
                'id' => $saldo->camara->id,
                'codigo' => $saldo->camara->codigo,
                'nombre' => $saldo->camara->nombre,
            ] : null,
            'posicion' => $saldo->posicion ? [
                'id' => $saldo->posicion->id,
                'etiqueta' => $saldo->posicion->etiqueta,
            ] : null,
            'recepcion' => $recepcion ? [
                'id' => $recepcion->id,
                'numero_guia_despacho' => $recepcion->numero_guia_despacho,
                'proveedor' => $recepcion->proveedor ? [
                    'id' => $recepcion->proveedor->id,
                    'codigo' => $recepcion->proveedor->codigo,
                    'nombre' => $recepcion->proveedor->nombre,
                ] : null,
            ] : null,
        ];
    }

    private function reservable(SaldoMaterialAlmacen $saldo): bool
    {
        return $saldo->camara?->contenido === ContenidoCamara::Materiales
            && $saldo->camara?->estado === EstadoCamara::Activa
            && (! $saldo->posicion || $saldo->posicion->estado === EstadoPosicion::Activa)
            && $saldo->folioMaterial->folio->estado_operacional === EstadoOperacionalFolio::Disponible
            && ! $saldo->folioMaterial->estaVencido()
            && $saldo->folioMaterial->motivo_bloqueo === null;
    }

    private function disponible(SaldoMaterialAlmacen $saldo): float
    {
        return $this->reservable($saldo)
            ? max(
                0,
                round(
                    (float) $saldo->cantidad_actual - (float) $saldo->cantidad_reservada,
                    3,
                ),
            )
            : 0;
    }

    private function cliente($cliente): array
    {
        return [
            'id' => $cliente->id,
            'cliente_global_id' => $cliente->cliente_id,
            'temporada' => [
                'id' => $cliente->temporada->id,
                'codigo' => $cliente->temporada->codigo,
                'nombre' => $cliente->temporada->nombre,
                'activa' => $cliente->temporada->activa,
            ],
            'codigo' => $cliente->codigo,
            'nombre' => $cliente->nombre,
            'activo' => $cliente->activo,
        ];
    }

    private function cantidad(mixed $valor): string
    {
        return number_format((float) $valor, 3, '.', '');
    }
}
