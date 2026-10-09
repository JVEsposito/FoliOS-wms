<?php

namespace App\Services\Temporadas;

use App\Enums\EstadoDespachoMaterial;
use App\Models\ClienteMaterial;
use App\Models\DetalleDespachoMaterial;
use App\Models\FolioMaterial;
use App\Models\ItemMaterial;
use App\Models\MigracionTemporada;
use App\Models\MigracionTemporadaFolio;
use App\Models\Temporada;
use App\Models\TemporadaMaterial;
use App\Models\User;
use App\Services\Materiales\ServicioFotosItemMaterial;
use App\Services\Temporadas\Cierre\ServicioDiagnosticoCierreTemporada;
use App\Services\Validacion\ServicioCopiaCatalogoValidacion;
use App\Services\Validacion\ServicioProyeccionCatalogoValidacion;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ServicioMigracionTemporada
{
    public function __construct(
        private readonly ServicioCopiaCatalogoValidacion $copiaValidacion,
        private readonly ServicioProyeccionCatalogoValidacion $proyeccionValidacion,
        private readonly ServicioTemporadaGlobal $temporadas,
        private readonly ServicioDiagnosticoCierreTemporada $cierre,
    ) {}

    /**
     * @param  array<string, mixed>  $opciones
     */
    public function migrar(
        Temporada $origen,
        Temporada $destino,
        array $opciones,
        User $usuario,
    ): MigracionTemporada {
        $copiarValidacion = (bool) ($opciones['copiar_catalogo_validacion'] ?? false);
        $copiarMateriales = (bool) ($opciones['copiar_catalogo_materiales'] ?? false);
        $migrarInventario = (bool) ($opciones['migrar_inventario_materiales'] ?? false);
        $activarDestino = (bool) ($opciones['activar_destino'] ?? false);

        if (! $copiarValidacion && ! $copiarMateriales && ! $migrarInventario) {
            throw new DomainException('Selecciona al menos un catálogo o el inventario de bodega para migrar.');
        }

        if ($origen->is($destino)) {
            throw new DomainException('La temporada de origen y destino deben ser diferentes.');
        }

        return DB::transaction(function () use (
            $origen,
            $destino,
            $usuario,
            $copiarValidacion,
            $copiarMateriales,
            $migrarInventario,
            $activarDestino,
        ): MigracionTemporada {
            $origen = Temporada::query()->lockForUpdate()->findOrFail($origen->id);
            $destino = Temporada::query()->lockForUpdate()->findOrFail($destino->id);

            if ($migrarInventario && ! $destino->activa && ! $activarDestino) {
                throw new DomainException(
                    'Para migrar inventario, la temporada de destino debe quedar activa en la misma operación.',
                );
            }

            // Activar el destino cierra la temporada vigente: no debe quedar
            // nada abierto en ella (ver docs/CIERRE_DE_TEMPORADA.md).
            if ($activarDestino && ! $destino->activa) {
                $this->cierre->asegurarPuedeActivarse($destino);
            }

            $resumen = [
                'validacion' => ['clientes' => 0, 'categorias' => 0, 'especies' => 0, 'csg' => 0],
                'materiales' => ['clientes' => 0, 'items' => 0],
                'inventario' => ['folios' => 0, 'cantidad_total' => 0],
            ];

            if ($copiarValidacion) {
                $catalogo = $this->copiaValidacion->copiar($origen, $destino);
                $this->proyeccionValidacion->reconstruir($catalogo);
                $resumen['validacion'] = [
                    'clientes' => $catalogo->clientes_count,
                    'categorias' => $catalogo->categorias_count,
                    'especies' => $catalogo->especies_count,
                    'csg' => $catalogo->csg_count,
                ];
            }

            $configuracionOrigen = null;
            $configuracionDestino = null;

            if ($copiarMateriales || $migrarInventario) {
                $configuracionOrigen = $this->temporadas
                    ->asegurarConfiguracionMaterial($origen, $usuario->id);
                $configuracionDestino = $this->temporadas
                    ->asegurarConfiguracionMaterial($destino, $usuario->id);

                $configuracionesIds = [$configuracionOrigen->id, $configuracionDestino->id];
                sort($configuracionesIds, SORT_STRING);

                TemporadaMaterial::query()
                    ->whereKey($configuracionesIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
            }

            if ($copiarMateriales) {
                $resumen['materiales'] = $this->copiarCatalogoMateriales(
                    $configuracionOrigen,
                    $configuracionDestino,
                    $usuario,
                );
            }

            $migracion = MigracionTemporada::create([
                'temporada_origen_id' => $origen->id,
                'temporada_destino_id' => $destino->id,
                'copio_catalogo_validacion' => $copiarValidacion,
                'copio_catalogo_materiales' => $copiarMateriales,
                'migro_inventario_materiales' => $migrarInventario,
                'activo_destino' => $activarDestino,
                'resumen' => $resumen,
                'creado_por_user_id' => $usuario->id,
            ]);

            if ($migrarInventario) {
                $resumen['inventario'] = $this->migrarInventarioMateriales(
                    $migracion,
                    $configuracionOrigen,
                    $configuracionDestino,
                );
            }

            if ($activarDestino) {
                $this->temporadas->activar($destino, $usuario->id);
            }

            $migracion->update(['resumen' => $resumen]);

            return $migracion->refresh()->load(['origen', 'destino', 'creadoPor:id,name']);
        }, attempts: 3);
    }

    /**
     * @return array{clientes: int, items: int}
     */
    private function copiarCatalogoMateriales(
        TemporadaMaterial $origen,
        TemporadaMaterial $destino,
        User $usuario,
    ): array {
        if ($destino->items()->exists()) {
            throw new DomainException('La temporada de destino ya posee ítems de bodega.');
        }

        $origen->load(['clientes.cliente', 'clientes.items']);
        $clientes = 0;
        $items = 0;

        foreach ($origen->clientes as $cliente) {
            $clienteNuevo = ClienteMaterial::query()->updateOrCreate(
                [
                    'temporada_material_id' => $destino->id,
                    'cliente_id' => $cliente->cliente_id,
                ],
                [
                    'codigo' => $cliente->cliente?->codigo ?? $cliente->codigo,
                    'nombre' => $cliente->cliente?->nombre ?? $cliente->nombre,
                    'codigo_externo' => $cliente->cliente?->codigo_externo,
                    'activo' => $cliente->cliente?->activo ?? $cliente->activo,
                    'creado_por_user_id' => $usuario->id,
                    'actualizado_por_user_id' => $usuario->id,
                ],
            );
            $clientes++;

            foreach ($cliente->items as $item) {
                $nuevo = ItemMaterial::create([
                    'cliente_material_id' => $clienteNuevo->id,
                    'codigo' => $item->codigo,
                    'nombre' => $item->nombre,
                    'categoria' => $item->categoria,
                    'categoria_operacional' => $item->categoria_operacional,
                    'unidad_medida' => $item->unidad_medida,
                    'stock_minimo' => $item->stock_minimo,
                    'punto_reorden' => $item->punto_reorden,
                    'stock_maximo' => $item->stock_maximo,
                    'codigo_externo' => $item->codigo_externo,
                    'origen_sistema' => $item->origen_sistema,
                    'sincronizado_at' => $item->sincronizado_at,
                    'activo' => $item->activo,
                    'creado_por_user_id' => $usuario->id,
                    'actualizado_por_user_id' => $usuario->id,
                ]);
                app(ServicioFotosItemMaterial::class)->copiar($item, $nuevo);
                $items++;
            }
        }

        return compact('clientes', 'items');
    }

    /**
     * @return array{folios: int, cantidad_total: float}
     */
    private function migrarInventarioMateriales(
        MigracionTemporada $migracion,
        TemporadaMaterial $origen,
        TemporadaMaterial $destino,
    ): array {
        $hayDespachosAbiertos = DetalleDespachoMaterial::query()
            ->whereHas('item.cliente', fn ($consulta) => $consulta
                ->where('temporada_material_id', $origen->id))
            ->whereHas('despacho', fn ($consulta) => $consulta->whereIn('estado', [
                EstadoDespachoMaterial::Pendiente->value,
                EstadoDespachoMaterial::Parcial->value,
            ]))
            ->exists();

        if ($hayDespachosAbiertos) {
            throw new DomainException(
                'No se puede migrar el inventario mientras existan despachos de materiales abiertos.',
            );
        }

        $folios = FolioMaterial::query()
            ->whereHas('item.cliente', fn ($consulta) => $consulta
                ->where('temporada_material_id', $origen->id))
            ->whereHas('folio', fn ($consulta) => $consulta->where('activo', true))
            ->where('cantidad_actual', '>', 0)
            ->with(['folio:id,temporada_id,activo', 'item.cliente'])
            ->orderBy('folio_id')
            ->lockForUpdate()
            ->get();

        if ($folios->contains(fn (FolioMaterial $folio): bool => (float) $folio->cantidad_reservada > 0)) {
            throw new DomainException(
                'No se puede migrar el inventario mientras existan reservas de materiales abiertas.',
            );
        }

        $itemsDestino = $this->itemsDestinoPorClave($destino);
        $cantidadTotal = 0.0;

        foreach ($folios as $folioMaterial) {
            $clave = $this->claveItem($folioMaterial->item->cliente->cliente_id, $folioMaterial->item->codigo);
            $itemDestino = $itemsDestino->get($clave);

            if (! $itemDestino) {
                throw new DomainException(
                    "El ítem {$folioMaterial->item->codigo} no tiene equivalencia en la temporada de destino.",
                );
            }

            MigracionTemporadaFolio::create([
                'migracion_temporada_id' => $migracion->id,
                'folio_id' => $folioMaterial->folio_id,
                'item_material_origen_id' => $folioMaterial->item_material_id,
                'item_material_destino_id' => $itemDestino->id,
                'cantidad' => $folioMaterial->cantidad_actual,
            ]);
            $folioMaterial->update(['item_material_id' => $itemDestino->id]);
            $folioMaterial->folio->update(['temporada_id' => $destino->temporada_id]);
            $cantidadTotal += (float) $folioMaterial->cantidad_actual;
        }

        return [
            'folios' => $folios->count(),
            'cantidad_total' => round($cantidadTotal, 3),
        ];
    }

    /** @return Collection<string, ItemMaterial> */
    private function itemsDestinoPorClave(TemporadaMaterial $destino): Collection
    {
        return ItemMaterial::query()
            ->whereHas('cliente', fn ($consulta) => $consulta
                ->where('temporada_material_id', $destino->id))
            ->with('cliente:id,cliente_id')
            ->get()
            ->keyBy(fn (ItemMaterial $item): string => $this->claveItem(
                $item->cliente->cliente_id,
                $item->codigo,
            ));
    }

    private function claveItem(?string $clienteId, string $codigo): string
    {
        return ($clienteId ?? 'sin-cliente').'|'.mb_strtoupper(trim($codigo));
    }
}
