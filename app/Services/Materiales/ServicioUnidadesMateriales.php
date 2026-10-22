<?php

namespace App\Services\Materiales;

use App\Exceptions\ConflictoOperacion;
use App\Models\ConversionUnidadMaterial;
use App\Models\EventoUnidadMaterial;
use App\Models\ItemMaterial;
use App\Models\UnidadMaterial;
use DomainException;
use Illuminate\Support\Facades\DB;

class ServicioUnidadesMateriales
{
    public function equivalentes(string $a, string $b): bool
    {
        if (UnidadesMateriales::canonica($a) === UnidadesMateriales::canonica($b)) {
            return true;
        }
        $ids = DB::table('alias_unidades_materiales')->whereIn('clave', [UnidadesMateriales::clave($a), UnidadesMateriales::clave($b)])->pluck('unidad_material_id', 'clave');

        return $ids->count() === 2 && $ids->unique()->count() === 1;
    }

    /** También admite importaciones y clientes anteriores que todavía envían texto. */
    public function asegurar(string $texto): UnidadMaterial
    {
        return DB::transaction(function () use ($texto): UnidadMaterial {
            $this->bloquearCatalogo();
            $clave = UnidadesMateriales::clave($texto);
            if ($clave === '' || mb_strlen($clave) > 40) {
                throw new DomainException('La unidad debe contener entre 1 y 40 caracteres.');
            }
            $id = DB::table('alias_unidades_materiales')->where('clave', $clave)->value('unidad_material_id');
            if ($id) {
                return UnidadMaterial::findOrFail($id);
            }
            $unidad = UnidadMaterial::firstOrCreate(['codigo' => UnidadesMateriales::canonica($texto)], ['nombre' => trim($texto), 'activa' => true, 'version' => 1]);
            $this->alias($unidad, [$clave, $unidad->codigo]);
            if ($unidad->wasRecentlyCreated) {
                $this->evento($unidad, null, 'unidad_creada', null, $unidad->toArray(), 'Alta desde catálogo de ítems o importación');
            }

            return $unidad;
        }, 3);
    }

    public function prepararItem(ItemMaterial $item): void
    {
        $unidad = $this->asegurar((string) $item->unidad_medida);
        $anterior = $item->exists ? $this->asegurar((string) $item->getOriginal('unidad_medida')) : null;
        if (! $unidad->activa && $unidad->id !== $anterior?->id) {
            throw new DomainException('La unidad está inactiva. Seleccione una unidad activa.');
        }
        if ($anterior && $this->referenciado($item)) {
            if ($anterior->id !== $unidad->id) {
                throw new DomainException('La unidad base no puede cambiar porque el ítem tiene documentos, recetas o equivalencias. Registre una equivalencia para ingresar cantidades en otra unidad.');
            }
            // No relabelar el stock existente, aun cuando se elija un sinónimo del catálogo.
            $item->unidad_medida = $item->getOriginal('unidad_medida');
        }
        $item->unidad_material_id = $unidad->id;
    }

    public function referenciado(ItemMaterial $item): bool
    {
        foreach (['folios_materiales' => 'item_material_id', 'detalles_recepciones_materiales' => 'item_material_id', 'detalles_despacho_materiales' => 'item_material_id', 'recetas_materiales' => 'item_salida_id', 'detalles_versiones_recetas_materiales' => 'item_entrada_id', 'conversiones_unidad_materiales' => 'item_material_id'] as $tabla => $campo) {
            if (DB::table($tabla)->where($campo, $item->id)->exists()) {
                return true;
            }
        }

        return false;
    }

    public function guardarUnidad(array $datos, ?UnidadMaterial $unidad = null): UnidadMaterial
    {
        return DB::transaction(function () use ($datos, $unidad): UnidadMaterial {
            $this->bloquearCatalogo();
            $unidad = $unidad ? UnidadMaterial::query()->lockForUpdate()->findOrFail($unidad->id) : null;
            $this->version($unidad?->version ?? 0, (int) $datos['version_conocida']);
            $codigo = UnidadesMateriales::canonica($datos['codigo']);
            if ($unidad && $codigo !== $unidad->codigo) {
                throw new DomainException('El código de una unidad es permanente; puede editar su nombre y agregar sinónimos.');
            }
            if (! $unidad && (UnidadMaterial::where('codigo', $codigo)->exists() || DB::table('alias_unidades_materiales')->where('clave', UnidadesMateriales::clave($datos['codigo']))->exists())) {
                throw new DomainException('Esa unidad o sinónimo ya está registrado.');
            }
            $antes = $unidad?->toArray();
            $unidad ??= new UnidadMaterial(['codigo' => $codigo]);
            $unidad->fill(['nombre' => trim($datos['nombre']), 'activa' => $datos['activa'], 'version' => ($unidad->version ?? 0) + 1, 'actualizado_por_user_id' => auth()->id()])->save();
            $nombre = UnidadesMateriales::clave($datos['nombre']);
            if ($nombre === '') {
                throw new DomainException('El nombre de la unidad no puede estar vacío.');
            }
            $this->alias($unidad, [$codigo, UnidadesMateriales::clave($datos['codigo']), ...(mb_strlen($nombre) <= 40 ? [$nombre] : []), ...$datos['alias']]);
            $this->evento($unidad, null, $antes ? 'unidad_actualizada' : 'unidad_creada', $antes, [...$unidad->toArray(), 'alias' => $datos['alias']], $datos['motivo']);

            return $unidad;
        }, 3);
    }

    public function guardarConversion(ItemMaterial $item, array $datos): ConversionUnidadMaterial
    {
        return DB::transaction(function () use ($item, $datos): ConversionUnidadMaterial {
            $item = ItemMaterial::query()->lockForUpdate()->findOrFail($item->id);
            if (! $item->activo) {
                throw new DomainException('El ítem está inactivo.');
            }
            $unidad = $this->asegurar($datos['unidad_origen']);
            if ((! $unidad->activa && $datos['activa']) || $unidad->id === $item->unidad_material_id) {
                throw new DomainException('Seleccione una unidad de ingreso activa y distinta de la unidad base.');
            }
            $conversion = ConversionUnidadMaterial::where('item_material_id', $item->id)->where('unidad_origen_id', $unidad->id)->lockForUpdate()->first();
            $this->version($conversion?->version ?? 0, (int) $datos['version_conocida']);
            $antes = $conversion?->toArray();
            $conversion ??= new ConversionUnidadMaterial(['item_material_id' => $item->id, 'unidad_origen_id' => $unidad->id]);
            $conversion->fill(['unidad_base_id' => $item->unidad_material_id, 'factor' => $datos['factor'], 'activa' => $datos['activa'], 'version' => ($conversion->version ?? 0) + 1, 'actualizado_por_user_id' => auth()->id()])->save();
            $this->evento($unidad, $item, 'equivalencia_guardada', $antes, $conversion->toArray(), $datos['motivo']);

            return $conversion->load('origen');
        }, 3);
    }

    /** Convierte una línea completa antes de generar stock. El respaldo queda congelado en el detalle. */
    public function convertirLinea(ItemMaterial $item, array $linea): array
    {
        if (! filled($linea['unidad_ingreso'] ?? null)) {
            return [$linea, null];
        }
        $clave = UnidadesMateriales::clave($linea['unidad_ingreso']);
        $unidad = UnidadMaterial::whereIn('id', DB::table('alias_unidades_materiales')->where('clave', $clave)->select('unidad_material_id'))->lockForUpdate()->first();
        if (! $unidad || ! $unidad->activa || $unidad->id === $item->unidad_material_id) {
            throw new DomainException('La unidad alternativa no está habilitada. Para la unidad base deje la conversión vacía.');
        }
        $conversion = ConversionUnidadMaterial::where('item_material_id', $item->id)->where('unidad_origen_id', $unidad->id)->where('activa', true)->lockForUpdate()->first();
        if (! $conversion || $conversion->unidad_base_id !== $item->unidad_material_id) {
            throw new DomainException('El ítem no tiene una equivalencia activa para esa unidad.');
        }
        $this->version($conversion->version, (int) ($linea['conversion_version'] ?? -1));
        $campos = ['cantidad_documental', 'cantidad_contada', 'cantidad_aceptada', 'cantidad_rechazada'];
        $entrada = array_intersect_key($linea, array_flip($campos));
        $bultos = array_column($linea['bultos'], 'cantidad');
        foreach ($campos as $campo) {
            $linea[$campo] = UnidadesMateriales::convertir($linea[$campo] ?? 0, $conversion->factor);
        }
        foreach ($linea['bultos'] as &$bulto) {
            $bulto['cantidad'] = UnidadesMateriales::convertir($bulto['cantidad'], $conversion->factor);
        }
        unset($bulto);

        return [$linea, ['unidad_origen' => $unidad->codigo, 'unidad_base' => $item->unidad_medida, 'factor' => $conversion->factor, 'version' => $conversion->version, 'conversion_id' => $conversion->id, 'entrada' => $entrada, 'bultos_entrada' => $bultos]];
    }

    private function bloquearCatalogo(): void
    {
        UnidadMaterial::where('codigo', 'kg')->lockForUpdate()->firstOrFail();
    }

    private function alias(UnidadMaterial $unidad, array $alias): void
    {
        foreach (array_unique(array_map(UnidadesMateriales::clave(...), $alias)) as $clave) {
            if ($clave === '' || mb_strlen($clave) > 40) {
                throw new DomainException('Los códigos y sinónimos admiten entre 1 y 40 caracteres.');
            }
            $actual = DB::table('alias_unidades_materiales')->where('clave', $clave)->value('unidad_material_id');
            if ($actual && $actual !== $unidad->id) {
                throw new DomainException('Un sinónimo ya pertenece a otra unidad.');
            }
            DB::table('alias_unidades_materiales')->insertOrIgnore(['clave' => $clave, 'unidad_material_id' => $unidad->id]);
        }
    }

    private function version(int $actual, int $conocida): void
    {
        if ($actual !== $conocida) {
            throw new ConflictoOperacion('La unidad o equivalencia cambió. Actualice el catálogo y revise las cantidades.');
        }
    }

    private function evento(?UnidadMaterial $unidad, ?ItemMaterial $item, string $accion, ?array $antes, array $despues, ?string $motivo): void
    {
        EventoUnidadMaterial::create(['unidad_material_id' => $unidad?->id, 'item_material_id' => $item?->id, 'user_id' => auth()->id(), 'accion' => $accion, 'antes' => $antes, 'despues' => $despues, 'motivo' => $motivo]);
    }
}
