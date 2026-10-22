<?php

namespace App\Finanzas\Valorizacion;

use App\Finanzas\Captura\CatalogoFuentes;
use App\Finanzas\Captura\LectorOperacion;
use App\Finanzas\Models\Hecho;
use Illuminate\Validation\ValidationException;

/** Catálogos para configurar servicios antes de que exista su primera ejecución. */
final class ReferenciasValorizacion
{
    public function __construct(private readonly LectorOperacion $lector, private readonly CatalogoFuentes $fuentes) {}

    public function valores(string $fuente, string $campo): array
    {
        if (! array_key_exists($campo, $this->fuentes->contexto($fuente)['dimensiones']) || ! preg_match('/^[a-z_]+$/', $campo)) {
            throw ValidationException::withMessages(['campo' => 'La dimensión no está disponible.']);
        }
        if ($fuente === 'material_transformacion' && in_array($campo, ['receta_id', 'version_receta_id'], true)) {
            $q = $this->lector->tabla('versiones_recetas_materiales', 'v')->join('recetas_materiales as r', 'r.id', '=', 'v.receta_material_id');
            $filas = $q->orderBy('r.nombre')->orderByDesc('v.numero_version')->limit(500)->get(['r.id', 'r.nombre', 'v.id as version_id', 'v.numero_version']);

            return $filas->map(fn ($r): array => ['valor' => $campo === 'receta_id' ? $r->id : $r->version_id, 'nombre' => $r->nombre.($campo === 'version_receta_id' ? ' · versión '.$r->numero_version : '')])->unique('valor')->values()->all();
        }
        if ($campo === 'unidad_medida') {
            return $this->lector->tabla('items_materiales')->whereNotNull('unidad_medida')->distinct()->orderBy('unidad_medida')->limit(500)->pluck('unidad_medida')
                ->map(fn (string $unidad): array => ['valor' => $unidad, 'nombre' => $unidad])->all();
        }
        if ($fuente === 'packing_retorno' && $campo === 'tipo_resultado') {
            return $this->lector->tabla('tipos_resultado_packing')->orderBy('orden')->get(['codigo', 'nombre'])->map(fn ($r): array => ['valor' => $r->codigo, 'nombre' => $r->nombre])->all();
        }
        $columna = "JSON_UNQUOTE(JSON_EXTRACT(datos, '$.{$campo}'))";

        return Hecho::query()->vigentes()->where('fuente', $fuente)->toBase()->selectRaw("{$columna} as valor")->whereRaw("{$columna} IS NOT NULL")->distinct()->orderBy('valor')->limit(500)->get()
            ->map(fn ($r): array => ['valor' => (string) $r->valor, 'nombre' => (string) $r->valor])->all();
    }
}
