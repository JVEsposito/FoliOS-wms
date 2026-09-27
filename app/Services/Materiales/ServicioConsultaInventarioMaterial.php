<?php

namespace App\Services\Materiales;

use App\Enums\ContenidoCamara;
use App\Enums\EstadoCamara;
use App\Enums\EstadoOperacionalFolio;
use App\Enums\EstadoPosicion;
use App\Models\FolioMaterial;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

class ServicioConsultaInventarioMaterial
{
    public function detalle(array $filtros): LengthAwarePaginator
    {
        $consulta = FolioMaterial::query()
            ->with([
                'item.cliente.temporada',
                'item.cliente.cliente',
                'folio.ubicacionActual.camara',
                'folio.ubicacionActual.posicion',
                'bultoRecepcion.detalle.recepcion.proveedor',
            ]);

        $this->aplicarAlcanceOperacional($consulta);

        $consulta
            ->when($filtros['item_material_id'] ?? null, fn (EloquentBuilder $query, string $itemId) => $query
                ->where('item_material_id', $itemId))
            ->when($filtros['cliente_id'] ?? null, fn (EloquentBuilder $query, string $clienteId) => $query
                ->whereHas('item', fn (EloquentBuilder $items) => $items
                    ->where('cliente_material_id', $clienteId)))
            ->when($filtros['q'] ?? null, function (EloquentBuilder $query, string $busqueda): void {
                $termino = '%'.trim($busqueda).'%';

                $query->where(function (EloquentBuilder $coincidencias) use ($termino): void {
                    $coincidencias
                        ->where('lote', 'like', $termino)
                        ->orWhereHas('folio', fn (EloquentBuilder $folios) => $folios
                            ->where('numero_folio', 'like', $termino))
                        ->orWhereHas('item', fn (EloquentBuilder $items) => $items
                            ->where('codigo', 'like', $termino)
                            ->orWhere('nombre', 'like', $termino)
                            ->orWhereHas('cliente', fn (EloquentBuilder $clientes) => $clientes
                                ->where('codigo', 'like', $termino)
                                ->orWhere('nombre', 'like', $termino)))
                        ->orWhereHas('folio.ubicacionActual.posicion', fn (EloquentBuilder $posiciones) => $posiciones
                            ->where('etiqueta', 'like', $termino))
                        ->orWhereHas('folio.ubicacionActual.camara', fn (EloquentBuilder $camaras) => $camaras
                            ->where('codigo', 'like', $termino)
                            ->orWhere('nombre', 'like', $termino));
                });
            })
            ->orderBy('item_material_id')
            ->orderBy('folio_id');

        $paginacion = $consulta
            ->paginate((int) ($filtros['per_page'] ?? 25))
            ->withQueryString();
        $paginacion->setCollection(
            $paginacion->getCollection()
                ->map(fn (FolioMaterial $material): array => $this->serializar($material)),
        );

        return $paginacion;
    }

    public function resumen(?string $clienteId = null): array
    {
        $base = $this->consultaAgregadaBase($clienteId);
        [$disponible, $bindingsDisponible] = $this->expresionDisponible();

        $items = (clone $base)
            ->select([
                'im.id as item_id',
                'im.codigo as item_codigo',
                'im.nombre as item_nombre',
                'cm.id as cliente_id',
                'cm.cliente_id as cliente_global_id',
                'cm.codigo as cliente_codigo',
                'cm.nombre as cliente_nombre',
                'cm.activo as cliente_activo',
                'tm.id as temporada_material_id',
                'tm.codigo as temporada_codigo',
                'tm.nombre as temporada_nombre',
                'tm.activa as temporada_activa',
                'fm.categoria_operacional',
                'fm.unidad_medida',
            ])
            ->selectRaw('COUNT(*) as folios')
            ->selectRaw('SUM(fm.cantidad_actual) as cantidad_actual')
            ->selectRaw('SUM(fm.cantidad_reservada) as cantidad_reservada')
            ->selectRaw("SUM({$disponible}) as cantidad_disponible", $bindingsDisponible)
            ->groupBy([
                'im.id',
                'im.codigo',
                'im.nombre',
                'cm.id',
                'cm.cliente_id',
                'cm.codigo',
                'cm.nombre',
                'cm.activo',
                'tm.id',
                'tm.codigo',
                'tm.nombre',
                'tm.activa',
                'fm.categoria_operacional',
                'fm.unidad_medida',
            ])
            ->orderBy('cm.codigo')
            ->orderBy('im.codigo')
            ->orderBy('fm.categoria_operacional')
            ->get()
            ->map(fn (object $fila): array => [
                'item' => [
                    'id' => $fila->item_id,
                    'cliente' => $this->clienteDesdeFila($fila),
                    'codigo' => $fila->item_codigo,
                    'nombre' => $fila->item_nombre,
                ],
                'categoria_operacional' => $fila->categoria_operacional,
                'unidad_medida' => $fila->unidad_medida,
                'folios' => (int) $fila->folios,
                'cantidad_actual' => $this->cantidad($fila->cantidad_actual),
                'cantidad_reservada' => $this->cantidad($fila->cantidad_reservada),
                'cantidad_disponible' => $this->cantidad($fila->cantidad_disponible),
            ])
            ->values();

        $estadisticasClientes = (clone $base)
            ->select([
                'cm.id as cliente_id',
                'cm.cliente_id as cliente_global_id',
                'cm.codigo as cliente_codigo',
                'cm.nombre as cliente_nombre',
                'cm.activo as cliente_activo',
                'tm.id as temporada_material_id',
                'tm.codigo as temporada_codigo',
                'tm.nombre as temporada_nombre',
                'tm.activa as temporada_activa',
            ])
            ->selectRaw('COUNT(*) as folios')
            ->selectRaw(
                'SUM(CASE WHEN ua.id IS NULL AND f.estado_operacional <> ? THEN 1 ELSE 0 END) as folios_pendientes_ubicacion',
                [EstadoOperacionalFolio::Bloqueado->value],
            )
            ->selectRaw(
                'SUM(CASE WHEN f.estado_operacional = ? THEN 1 ELSE 0 END) as folios_bloqueados',
                [EstadoOperacionalFolio::Bloqueado->value],
            )
            ->selectRaw('COUNT(DISTINCT im.id) as items')
            ->selectRaw('COUNT(DISTINCT ua.posicion_id) as posiciones')
            ->groupBy([
                'cm.id',
                'cm.cliente_id',
                'cm.codigo',
                'cm.nombre',
                'cm.activo',
                'tm.id',
                'tm.codigo',
                'tm.nombre',
                'tm.activa',
            ])
            ->orderBy('cm.codigo')
            ->get();

        $saldosClientes = (clone $base)
            ->select(['cm.id as cliente_id', 'fm.unidad_medida'])
            ->selectRaw('SUM(fm.cantidad_actual) as cantidad_actual')
            ->selectRaw(
                'SUM(CASE WHEN ua.id IS NULL AND f.estado_operacional <> ? THEN fm.cantidad_actual ELSE 0 END) as cantidad_pendiente_ubicacion',
                [EstadoOperacionalFolio::Bloqueado->value],
            )
            ->selectRaw(
                'SUM(CASE WHEN f.estado_operacional = ? THEN fm.cantidad_actual ELSE 0 END) as cantidad_bloqueada',
                [EstadoOperacionalFolio::Bloqueado->value],
            )
            ->selectRaw('SUM(fm.cantidad_reservada) as cantidad_reservada')
            ->selectRaw("SUM({$disponible}) as cantidad_disponible", $bindingsDisponible)
            ->groupBy(['cm.id', 'fm.unidad_medida'])
            ->get()
            ->groupBy('cliente_id');

        $clientes = $estadisticasClientes
            ->map(function (object $fila) use ($saldosClientes): array {
                $saldos = $saldosClientes
                    ->get($fila->cliente_id, collect())
                    ->map(fn (object $saldo): array => [
                        'unidad_medida' => $saldo->unidad_medida,
                        'cantidad_actual' => $this->cantidad($saldo->cantidad_actual),
                        'cantidad_pendiente_ubicacion' => $this->cantidad($saldo->cantidad_pendiente_ubicacion),
                        'cantidad_bloqueada' => $this->cantidad($saldo->cantidad_bloqueada),
                        'cantidad_reservada' => $this->cantidad($saldo->cantidad_reservada),
                        'cantidad_disponible' => $this->cantidad($saldo->cantidad_disponible),
                    ])
                    ->values();

                return [
                    'cliente' => $this->clienteDesdeFila($fila),
                    'folios' => (int) $fila->folios,
                    'folios_pendientes_ubicacion' => (int) $fila->folios_pendientes_ubicacion,
                    'folios_bloqueados' => (int) $fila->folios_bloqueados,
                    'items' => (int) $fila->items,
                    'posiciones' => (int) $fila->posiciones,
                    'saldos' => $saldos,
                ];
            })
            ->values();

        return [
            'resumen' => [
                'folios' => $clientes->sum('folios'),
                'clientes' => $clientes->count(),
                'items' => $items->pluck('item.id')->unique()->count(),
            ],
            'resumen_clientes' => $clientes,
            'resumen_items' => $items,
        ];
    }

    private function aplicarAlcanceOperacional(EloquentBuilder $consulta): void
    {
        $consulta
            ->whereHas('folio', fn (EloquentBuilder $folios) => $folios
                ->where('activo', true)
                ->where('temporada_id', '=', $this->consultaTemporadaActiva()))
            ->where(function (EloquentBuilder $ubicaciones): void {
                $ubicaciones
                    ->whereDoesntHave('folio.ubicacionActual')
                    ->orWhereHas('folio.ubicacionActual.camara', fn (EloquentBuilder $camaras) => $camaras
                        ->where('contenido', ContenidoCamara::Materiales->value));
            });
    }

    private function consultaAgregadaBase(?string $clienteId): QueryBuilder
    {
        return DB::table('folios_materiales as fm')
            ->join('folios as f', 'f.id', '=', 'fm.folio_id')
            ->join('items_materiales as im', 'im.id', '=', 'fm.item_material_id')
            ->join('clientes_materiales as cm', 'cm.id', '=', 'im.cliente_material_id')
            ->join('temporadas_materiales as tm', 'tm.id', '=', 'cm.temporada_material_id')
            ->leftJoin('ubicaciones_actuales as ua', 'ua.folio_id', '=', 'f.id')
            ->leftJoin('posiciones as p', 'p.id', '=', 'ua.posicion_id')
            ->leftJoin('camaras as ca', 'ca.id', '=', 'ua.camara_id')
            ->where('f.activo', true)
            ->where('f.temporada_id', '=', $this->consultaTemporadaActiva())
            ->where(function (QueryBuilder $ubicaciones): void {
                $ubicaciones
                    ->whereNull('ua.id')
                    ->orWhere('ca.contenido', ContenidoCamara::Materiales->value);
            })
            ->when($clienteId, fn (QueryBuilder $query, string $id) => $query
                ->where('cm.id', $id));
    }

    private function consultaTemporadaActiva(): EloquentBuilder
    {
        return app(ServicioTemporadaActiva::class)->subconsultaId();
    }

    private function expresionDisponible(): array
    {
        return [
            'CASE WHEN ua.id IS NOT NULL'
                .' AND ca.contenido = ?'
                .' AND ca.estado = ?'
                .' AND (ua.posicion_id IS NULL OR p.estado = ?)'
                .' AND f.estado_operacional = ?'
                .' AND fm.motivo_bloqueo IS NULL'
                .' THEN CASE WHEN fm.cantidad_actual > fm.cantidad_reservada'
                .' THEN fm.cantidad_actual - fm.cantidad_reservada ELSE 0 END'
                .' ELSE 0 END',
            [
                ContenidoCamara::Materiales->value,
                EstadoCamara::Activa->value,
                EstadoPosicion::Activa->value,
                EstadoOperacionalFolio::Disponible->value,
            ],
        ];
    }

    private function serializar(FolioMaterial $material): array
    {
        $folio = $material->folio;
        $ubicacion = $folio->ubicacionActual;
        $posicion = $ubicacion?->posicion;
        $camara = $ubicacion?->camara ?? $posicion?->camara;
        $enCamara = $camara?->contenido === ContenidoCamara::Materiales;
        $reservable = $enCamara
            && $camara->estado === EstadoCamara::Activa
            && (! $posicion || $posicion->estado === EstadoPosicion::Activa)
            && $folio->estado_operacional === EstadoOperacionalFolio::Disponible
            && $material->motivo_bloqueo === null;
        $disponible = $reservable
            ? max(0, (float) $material->cantidad_actual - (float) $material->cantidad_reservada)
            : 0;
        $recepcion = $material->bultoRecepcion?->detalle?->recepcion;

        return [
            'folio_id' => $folio->id,
            'numero_folio' => $folio->numero_folio,
            'estado_operacional' => $folio->estado_operacional->value,
            'estado_ubicacion' => ! $enCamara
                ? 'pendiente_ubicacion'
                : ($posicion ? 'ubicado' : 'solo_camara'),
            'reservable' => $reservable,
            'motivo_bloqueo' => $material->motivo_bloqueo,
            'item' => [
                'id' => $material->item->id,
                'cliente' => [
                    'id' => $material->item->cliente->id,
                    'cliente_global_id' => $material->item->cliente->cliente_id,
                    'temporada' => [
                        'id' => $material->item->cliente->temporada->id,
                        'codigo' => $material->item->cliente->temporada->codigo,
                        'nombre' => $material->item->cliente->temporada->nombre,
                        'activa' => $material->item->cliente->temporada->activa,
                    ],
                    'codigo' => $material->item->cliente->codigo,
                    'nombre' => $material->item->cliente->nombre,
                    'activo' => $material->item->cliente->activo,
                ],
                'codigo' => $material->item->codigo,
                'nombre' => $material->item->nombre,
            ],
            'categoria_operacional' => $material->categoria_operacional?->value,
            'cantidad_inicial' => $material->cantidad_inicial,
            'cantidad_actual' => $material->cantidad_actual,
            'cantidad_reservada' => $material->cantidad_reservada,
            'cantidad_disponible' => $this->cantidad($disponible),
            'unidad_medida' => $material->unidad_medida,
            'lote' => $material->lote,
            'fecha_ingreso' => $folio->fecha_ingreso?->toAtomString(),
            'camara' => $camara ? [
                'id' => $camara->id,
                'codigo' => $camara->codigo,
                'nombre' => $camara->nombre,
            ] : null,
            'posicion' => $posicion ? [
                'id' => $posicion->id,
                'etiqueta' => $posicion->etiqueta,
            ] : null,
            'recepcion' => $recepcion ? [
                'id' => $recepcion->id,
                'numero_guia_despacho' => $recepcion->numero_guia_despacho,
                'proveedor' => $recepcion->proveedor?->nombre,
                'confirmado_at' => $recepcion->confirmado_at?->toAtomString(),
            ] : null,
        ];
    }

    private function clienteDesdeFila(object $fila): array
    {
        return [
            'id' => $fila->cliente_id,
            'cliente_global_id' => $fila->cliente_global_id,
            'temporada' => [
                'id' => $fila->temporada_material_id,
                'codigo' => $fila->temporada_codigo,
                'nombre' => $fila->temporada_nombre,
                'activa' => (bool) $fila->temporada_activa,
            ],
            'codigo' => $fila->cliente_codigo,
            'nombre' => $fila->cliente_nombre,
            'activo' => (bool) $fila->cliente_activo,
        ];
    }

    private function cantidad(float|int|string|null $valor): string
    {
        return number_format((float) ($valor ?? 0), 3, '.', '');
    }
}
