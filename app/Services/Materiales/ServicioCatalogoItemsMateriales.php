<?php

namespace App\Services\Materiales;

use App\Models\ClienteMaterial;
use App\Models\ItemMaterial;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ServicioCatalogoItemsMateriales
{
    public const COLUMNAS_IMPORTACION = ['temporada_codigo', 'cliente_codigo', 'codigo', 'nombre', 'categoria', 'tipo_item', 'unidad_medida', 'codigo_externo', 'activo', 'stock_minimo', 'punto_reorden', 'stock_maximo'];

    public const COLUMNAS_INFORMATIVAS = ['cliente_nombre', 'dias_alerta_vencimiento', 'stock_bodega', 'disponible_bodega', 'estado_reposicion', 'cantidad_fotos', 'modificado_at', 'modificado_por'];

    public function consulta(array $filtros): Builder
    {
        return ItemMaterial::query()->with(['cliente.temporada', 'actualizadoPor:id,name', 'fotoPrincipal', 'tipoItem'])->withCount('fotos')
            ->when($filtros['temporada_id'] ?? null, fn ($q, $id) => $q->whereHas('cliente', fn ($c) => $c->where('temporada_material_id', $id)))
            ->when($filtros['cliente_id'] ?? null, fn ($q, $id) => $q->where('cliente_material_id', $id))
            ->when($filtros['cliente_global_id'] ?? null, fn ($q, $id) => $q->whereHas('cliente', fn ($c) => $c->where('cliente_id', $id)))
            ->when($filtros['categoria'] ?? null, fn ($q, $c) => $q->where('categoria', $c))
            ->when($filtros['tipo_item_material_id'] ?? null, fn ($q, $id) => $q->where('tipo_item_material_id', $id))
            ->when($filtros['tipo_item'] ?? null, fn ($q, $t) => $t === 'sin_tipo' ? $q->whereNull('categoria_operacional') : $q->where('categoria_operacional', $t))
            ->when(($filtros['estado'] ?? 'todos') !== 'todos', fn ($q) => $q->where('activo', $filtros['estado'] === 'activos'));
    }

    /** Una opción por identidad; los registros antiguos sin maestro mantienen su identidad propia. */
    public function clientes(array $filtros): Collection
    {
        return ClienteMaterial::with('cliente:id,codigo,nombre')
            ->when($filtros['temporada_id'] ?? null, fn ($q, $id) => $q->where('temporada_material_id', $id))
            ->get(['id', 'cliente_id', 'nombre', 'codigo', 'temporada_material_id'])
            ->map(fn ($c) => [
                'id' => $c->cliente_id ?? $c->id,
                'es_maestro' => $c->cliente_id !== null,
                'codigo' => $c->cliente?->codigo ?? $c->codigo,
                'nombre' => $c->cliente?->nombre ?? $c->nombre,
            ])
            ->unique(fn ($c) => ($c['es_maestro'] ? 'maestro:' : 'historico:').$c['id'])
            ->sortBy(fn ($c) => mb_strtoupper($c['nombre'].' '.$c['codigo']))->values();
    }

    public function columnas(): array
    {
        return collect([...self::COLUMNAS_IMPORTACION, ...self::COLUMNAS_INFORMATIVAS])->map(fn ($c) => ['clave' => $c, 'titulo' => $c, 'ancho' => in_array($c, ['nombre', 'cliente_nombre', 'modificado_por'], true) ? 38 : 24,
            'tipo' => in_array($c, ['stock_minimo', 'punto_reorden', 'stock_maximo', 'dias_alerta_vencimiento', 'stock_bodega', 'disponible_bodega', 'cantidad_fotos'], true) ? 'numero' : 'texto',
            'informativa' => in_array($c, self::COLUMNAS_INFORMATIVAS, true)])->all();
    }

    /** Generación por bloques: nunca se carga el catálogo completo ni todas sus fotos. */
    public function filas(array $filtros): \Generator
    {
        foreach ($this->consulta($filtros)->lazyById(500)->chunk(500) as $bloque) {
            $datos = app(ServicioReposicionMaterial::class)->datosCatalogo(collect($bloque->all()));
            foreach ($bloque as $item) {
                yield ['temporada_codigo' => $item->cliente->temporada->codigo, 'cliente_codigo' => $item->cliente->codigo,
                    ...$item->only(['codigo', 'nombre', 'categoria', 'unidad_medida', 'codigo_externo', 'stock_minimo', 'punto_reorden', 'stock_maximo']),
                    'unidad_medida' => NombreClasificacionMaterial::mayusculas($item->unidad_medida),
                    'tipo_item' => $item->categoria_operacional?->value, 'activo' => $item->activo ? 'si' : 'no',
                    'cliente_nombre' => $item->cliente->nombre, 'dias_alerta_vencimiento' => $item->dias_alerta_vencimiento,
                    ...($datos[$item->id] ?? []), 'cantidad_fotos' => (int) $item->fotos_count,
                    'modificado_at' => $item->updated_at?->toAtomString(), 'modificado_por' => $item->actualizadoPor?->name];
            }
        }
    }
}
