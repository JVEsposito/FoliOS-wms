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

    /**
     * Para corregir una validación: categorías y orígenes de toda la temporada (son pocos),
     * pero artículos y combinaciones solo del cliente elegido. Con un catálogo grande, las
     * combinaciones de todos los clientes pesan decenas de MB.
     *
     * @return array{categorias: Collection, origenes: Collection, articulos: Collection, articulos_por_origen: array<string, list<string>>}
     */
    public function paraCorreccion(string $temporadaId, string $cliente): array
    {
        $origenes = DB::table('origenes_validacion')
            ->where('temporada_id', $temporadaId)
            ->where('activo', true)
            ->orderBy('cliente')
            ->orderBy('marca')
            ->orderBy('csg')
            ->get(['id', 'temporada_id', 'cliente_validacion_id', 'csg_validacion_id', 'cliente', 'marca', 'csg', 'predio', 'codigo_externo', 'activo']);
        $variedadesPorCsg = DB::table('csg_variedades_validacion')
            ->whereIn('csg_validacion_id', $origenes->pluck('csg_validacion_id')->filter()->unique())
            ->get(['csg_validacion_id', 'variedad_validacion_id'])
            ->groupBy('csg_validacion_id');
        $origenes->each(function (object $origen) use ($variedadesPorCsg): void {
            $origen->variedad_ids = $origen->csg_validacion_id === null
                ? null
                : $variedadesPorCsg->get($origen->csg_validacion_id, collect())->pluck('variedad_validacion_id')->values()->all();
        });

        return [
            'categorias' => DB::table('categorias_validacion')
                ->where('temporada_id', $temporadaId)
                ->where('activo', true)
                ->orderBy('nombre')
                ->get(['id', 'nombre', 'codigo_externo', 'activo']),
            'origenes' => $origenes,
            ...$this->delCliente($temporadaId, $cliente),
        ];
    }

    /**
     * Artículos habilitados para los orígenes de un cliente y, por cada origen, los artículos
     * combinables (solo identificadores).
     *
     * @return array{cliente: string, articulos: Collection, articulos_por_origen: array<string, list<string>>}
     */
    public function delCliente(string $temporadaId, string $cliente): array
    {
        $pares = DB::table('combinaciones_validacion as combinacion')
            ->join('articulos_validacion as articulo', 'articulo.id', '=', 'combinacion.articulo_validacion_id')
            ->join('origenes_validacion as origen', 'origen.id', '=', 'combinacion.origen_validacion_id')
            ->where('combinacion.temporada_id', $temporadaId)
            ->where('combinacion.activo', true)
            ->where('articulo.activo', true)
            ->where('origen.activo', true)
            ->where('origen.cliente', $cliente)
            ->get(['combinacion.origen_validacion_id', 'combinacion.articulo_validacion_id']);
        $articulos = DB::table('articulos_validacion as articulo')
            ->leftJoin('envases_validacion as envase', 'envase.id', '=', 'articulo.envase_validacion_id')
            ->whereIn('articulo.id', $pares->pluck('articulo_validacion_id')->unique()->values())
            ->orderBy('articulo.especie')
            ->orderBy('articulo.variedad')
            ->orderBy('articulo.calibre')
            ->orderBy('articulo.envase')
            ->get([
                'articulo.id', 'articulo.variedad_validacion_id', 'articulo.especie', 'articulo.variedad',
                'articulo.calibre', 'articulo.envase', 'articulo.codigo_externo', 'articulo.activo',
                'envase.codigo_externo as envase_codigo',
            ]);

        return [
            'cliente' => $cliente,
            'articulos' => $articulos,
            'articulos_por_origen' => $pares->groupBy('origen_validacion_id')
                ->map(fn (Collection $grupo): array => $grupo->pluck('articulo_validacion_id')->values()->all())
                ->all(),
        ];
    }
}
