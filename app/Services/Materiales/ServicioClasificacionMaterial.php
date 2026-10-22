<?php

namespace App\Services\Materiales;

use App\Models\CategoriaComercialMaterial;
use App\Models\ClienteProveedorMaterial;
use App\Models\ItemMaterial;
use App\Models\TipoItemMaterial;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ServicioClasificacionMaterial
{
    public function catalogos(): array
    {
        $referencias = $this->referenciasCategorias();

        return [
            'categorias' => CategoriaComercialMaterial::orderBy('nombre')->get()->map(fn ($categoria) => [
                'id' => $categoria->id, 'nombre' => $categoria->nombre, 'activo' => $categoria->activo,
                'en_uso' => $referencias->has($categoria->nombre_normalizado),
            ])->all(),
            'tipos_item' => TipoItemMaterial::withCount('items')->orderBy('nombre')->get()->map(fn ($tipo) => [
                'id' => $tipo->id, 'nombre' => $tipo->nombre, 'activo' => $tipo->activo,
                'categoria_operacional' => $tipo->categoria_operacional->value,
                'categoria_operacional_etiqueta' => $tipo->categoria_operacional->etiqueta(),
                'codigo_base' => $tipo->codigo_base, 'en_uso' => $tipo->items_count > 0,
            ])->all(),
        ];
    }

    public function guardarCategoria(array $datos, User $usuario, ?CategoriaComercialMaterial $categoria = null): CategoriaComercialMaterial
    {
        try {
            return DB::transaction(function () use ($datos, $usuario, $categoria) {
                $categoria = $categoria ? CategoriaComercialMaterial::whereKey($categoria->id)->lockForUpdate()->firstOrFail() : new CategoriaComercialMaterial;
                if ($categoria->exists && $datos['nombre'] !== $categoria->nombre
                    && $this->referenciasCategorias()->has($categoria->nombre_normalizado)) {
                    throw ValidationException::withMessages(['nombre' => 'Una categoría en uso conserva su nombre para mantener proveedores e historial. Puedes desactivarla o crear otra.']);
                }
                $this->validarNombreUnico(CategoriaComercialMaterial::class, $datos['nombre'], $categoria->id);
                $categoria->fill([
                    ...$datos, 'nombre_normalizado' => NombreClasificacionMaterial::normalizar($datos['nombre']),
                    'creado_por_user_id' => $categoria->creado_por_user_id ?? $usuario->id,
                    'actualizado_por_user_id' => $usuario->id,
                ])->save();

                return $categoria;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['nombre' => 'Ya existe una categoría con ese nombre, incluso si está inactiva.']);
        }
    }

    public function guardarTipo(array $datos, User $usuario, ?TipoItemMaterial $tipo = null): TipoItemMaterial
    {
        try {
            return DB::transaction(function () use ($datos, $usuario, $tipo) {
                $tipo = $tipo ? TipoItemMaterial::whereKey($tipo->id)->lockForUpdate()->firstOrFail() : new TipoItemMaterial;
                if ($tipo->exists && $tipo->categoria_operacional->value !== $datos['categoria_operacional']
                    && ($tipo->codigo_base !== null || $tipo->items()->exists())) {
                    throw ValidationException::withMessages(['categoria_operacional' => 'La función operacional de un tipo base o en uso no puede cambiar. Crea otro tipo para una función diferente.']);
                }
                $this->validarNombreUnico(TipoItemMaterial::class, $datos['nombre'], $tipo->id);
                $tipo->fill([
                    ...$datos, 'nombre_normalizado' => NombreClasificacionMaterial::normalizar($datos['nombre']),
                    'creado_por_user_id' => $tipo->creado_por_user_id ?? $usuario->id,
                    'actualizado_por_user_id' => $usuario->id,
                ])->save();

                return $tipo;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['nombre' => 'Ya existe un tipo de ítem con ese nombre, incluso si está inactivo.']);
        }
    }

    /** Conserva el contrato anterior para importaciones y clientes que envían el enum operacional. */
    public function resolverParaItem(array $datos, ?ItemMaterial $actual, User $usuario): array
    {
        if (isset($datos['categoria'])) {
            $nombre = NombreClasificacionMaterial::limpiar($datos['categoria']);
            $clave = NombreClasificacionMaterial::normalizar($nombre);
            $categoria = CategoriaComercialMaterial::firstOrCreate(['nombre_normalizado' => $clave], [
                'nombre' => $nombre, 'activo' => true,
                'creado_por_user_id' => $usuario->id, 'actualizado_por_user_id' => $usuario->id,
            ]);
            $categoria = CategoriaComercialMaterial::whereKey($categoria->id)->lockForUpdate()->firstOrFail();
            if (! $categoria->activo && NombreClasificacionMaterial::normalizar($actual?->categoria ?? '') !== $clave) {
                throw ValidationException::withMessages(['categoria' => 'La categoría comercial está inactiva. Selecciona una categoría activa.']);
            }
            // La representación histórica no se sustituye por la del maestro.
            $datos['categoria'] = $nombre;
        }

        $tipoId = $datos['tipo_item_material_id'] ?? null;
        $operacional = $datos['categoria_operacional'] ?? null;
        if ($tipoId === null && $actual?->tipo_item_material_id && $actual->categoria_operacional?->value === $operacional) {
            $tipoId = $actual->tipo_item_material_id;
        }
        $tipo = $tipoId
            ? TipoItemMaterial::whereKey($tipoId)->lockForUpdate()->first()
            : ($operacional ? TipoItemMaterial::where('codigo_base', $operacional)->lockForUpdate()->first() : null);

        if (($tipoId || $operacional) && $tipo === null) {
            throw ValidationException::withMessages(['tipo_item_material_id' => 'El tipo de ítem no existe. Selecciona un tipo del catálogo.']);
        }
        if ($tipo) {
            if (! $tipo->activo && $actual?->tipo_item_material_id !== $tipo->id) {
                throw ValidationException::withMessages(['tipo_item_material_id' => 'El tipo de ítem está inactivo. Selecciona un tipo activo.']);
            }
            if ($operacional !== null && $operacional !== $tipo->categoria_operacional->value) {
                throw ValidationException::withMessages(['categoria_operacional' => 'La clasificación operacional no corresponde al tipo de ítem seleccionado.']);
            }
            $datos['categoria_operacional'] = $tipo->categoria_operacional->value;
        }
        $datos['tipo_item_material_id'] = $tipo?->id;

        return $datos;
    }

    /** @return Collection<string, bool> */
    private function referenciasCategorias(): Collection
    {
        $nombres = ItemMaterial::whereNotNull('categoria')->distinct()->pluck('categoria');
        foreach (ClienteProveedorMaterial::whereNotNull('categorias')->get(['categorias']) as $asignacion) {
            $nombres = $nombres->merge($asignacion->categorias ?? []);
        }

        return $nombres->mapWithKeys(fn ($nombre) => [NombreClasificacionMaterial::normalizar($nombre) => true]);
    }

    private function validarNombreUnico(string $modelo, string $nombre, ?string $id): void
    {
        if ($modelo::where('nombre_normalizado', NombreClasificacionMaterial::normalizar($nombre))
            ->when($id, fn ($consulta) => $consulta->whereKeyNot($id))->exists()) {
            throw ValidationException::withMessages(['nombre' => 'El nombre ya está registrado, incluso si el registro está inactivo.']);
        }
    }
}
