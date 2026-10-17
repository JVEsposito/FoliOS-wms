<?php

namespace App\Services\Validacion;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Catálogo activo de una temporada con la forma que usa la PDA para validar: artículos,
 * categorías, orígenes (con las variedades autorizadas en su CSG) y combinaciones
 * habilitadas. La corrección administrativa en Oficina usa el mismo catálogo, así ambas
 * eligen entre las mismas opciones.
 */
class CatalogoOperativoValidacion
{
    /**
     * @return array{categorias: Collection, articulos: Collection, origenes: Collection, combinaciones: Collection}
     */
    public function datos(string $temporadaId): array
    {
        // El código del embalaje (cod_articulo del sistema de origen) permite buscarlo por código.
        $articulos = DB::table('articulos_validacion as articulo')
            ->leftJoin('envases_validacion as envase', 'envase.id', '=', 'articulo.envase_validacion_id')
            ->where('articulo.temporada_id', $temporadaId)
            ->where('articulo.activo', true)
            ->orderBy('articulo.especie')
            ->orderBy('articulo.variedad')
            ->orderBy('articulo.calibre')
            ->orderBy('articulo.envase')
            ->get(['articulo.*', 'envase.codigo_externo as envase_codigo']);
        $categorias = DB::table('categorias_validacion')
            ->where('temporada_id', $temporadaId)
            ->where('activo', true)
            ->orderBy('nombre')
            ->get();
        $origenes = DB::table('origenes_validacion')
            ->where('temporada_id', $temporadaId)
            ->where('activo', true)
            ->orderBy('cliente')
            ->orderBy('marca')
            ->orderBy('csg')
            ->get();
        $variedadesPorCsg = DB::table('csg_variedades_validacion')
            ->whereIn('csg_validacion_id', $origenes->pluck('csg_validacion_id')->filter()->unique())
            ->get(['csg_validacion_id', 'variedad_validacion_id'])
            ->groupBy('csg_validacion_id');
        $origenes->each(function (object $origen) use ($variedadesPorCsg): void {
            $origen->variedad_ids = $origen->csg_validacion_id === null
                ? null // Origen legado sin CSG normalizado: conserva el filtro por combinaciones.
                : $variedadesPorCsg->get($origen->csg_validacion_id, collect())
                    ->pluck('variedad_validacion_id')->values()->all();
        });
        $combinaciones = DB::table('combinaciones_validacion as combinacion')
            ->join('articulos_validacion as articulo', 'articulo.id', '=', 'combinacion.articulo_validacion_id')
            ->join('origenes_validacion as origen', 'origen.id', '=', 'combinacion.origen_validacion_id')
            ->where('combinacion.temporada_id', $temporadaId)
            ->where('combinacion.activo', true)
            ->where('articulo.activo', true)
            ->where('origen.activo', true)
            ->orderBy('articulo.especie')
            ->orderBy('origen.cliente')
            ->get([
                'combinacion.id',
                'combinacion.articulo_validacion_id',
                'combinacion.origen_validacion_id',
                'combinacion.codigo_externo',
            ]);

        return [
            'categorias' => $categorias,
            'articulos' => $articulos,
            'origenes' => $origenes,
            'combinaciones' => $combinaciones,
        ];
    }
}
