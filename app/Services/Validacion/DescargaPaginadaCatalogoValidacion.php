<?php

namespace App\Services\Validacion;

use App\Enums\MotivoValidacionPallet;
use App\Enums\ResultadoValidacionPallet;
use App\Enums\TipoBulto;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Illuminate\Support\Facades\DB;

/** Cada petición lee como máximo una página; no prepara ni bloquea un catálogo completo. */
class DescargaPaginadaCatalogoValidacion
{
    public const TAMANO = 1000;

    public const COLECCIONES = ['categorias', 'articulos', 'origenes', 'combinaciones'];

    public function indice(): array
    {
        $temporada = app(ServicioTemporadaActiva::class)->buscar();
        abort_unless($temporada, 404, 'No existe una temporada activa para validación.');

        return [
            'formato' => 'paginas-v1', 'temporada' => $temporada->toArray(),
            'tamano_pagina' => self::TAMANO, 'colecciones' => self::COLECCIONES,
            'tipos_bulto' => [TipoBulto::Pallet->value, TipoBulto::Saldo->value],
            'resultados' => array_column(ResultadoValidacionPallet::cases(), 'value'),
            'motivos' => array_column(MotivoValidacionPallet::cases(), 'value'),
            'generado_at' => now()->toAtomString(),
        ];
    }

    public function pagina(string $temporadaId, int $version, string $coleccion, ?string $desde): string
    {
        abort_unless(in_array($coleccion, self::COLECCIONES, true), 404);

        return DB::transaction(function () use ($temporadaId, $version, $coleccion, $desde): string {
            $temporadas = app(ServicioTemporadaActiva::class);
            // Lectura de snapshot, sin esperar al bloqueo exclusivo de una edición.
            // La versión se comprueba en cada petición y antes de entregar la página.
            $temporada = $temporadas->buscar();
            abort_unless($temporada && $temporada->id === $temporadaId && $temporada->version_catalogo === $version,
                409, 'El catálogo cambió durante la descarga. Reintenta para recibir la versión vigente.');
            [$consulta, $id, $columnas] = match ($coleccion) {
                'categorias' => [DB::table('categorias_validacion')->where('temporada_id', $temporadaId)->where('activo', true),
                    'id', ['id', 'temporada_id', 'nombre', 'codigo_externo', 'activo']],
                'articulos' => [DB::table('articulos_validacion as a')->where('a.temporada_id', $temporadaId)->where('a.activo', true),
                    'a.id', ['a.id', 'a.temporada_id', 'a.variedad_validacion_id', 'a.especie', 'a.variedad', 'a.calibre', 'a.envase', 'a.codigo_externo', 'a.activo']],
                'origenes' => [DB::table('origenes_validacion')->where('temporada_id', $temporadaId)->where('activo', true),
                    'id', ['id', 'temporada_id', 'cliente_validacion_id', 'csg_validacion_id', 'cliente', 'marca', 'csg', 'predio', 'codigo_externo', 'activo']],
                'combinaciones' => [DB::table('combinaciones_validacion as c')
                    ->join('articulos_validacion as a', 'a.id', '=', 'c.articulo_validacion_id')
                    ->join('origenes_validacion as o', 'o.id', '=', 'c.origen_validacion_id')
                    ->where('c.temporada_id', $temporadaId)->where('c.activo', true)->where('a.activo', true)->where('o.activo', true),
                    'c.id', ['c.id', 'c.articulo_validacion_id', 'c.origen_validacion_id', 'c.codigo_externo']],
            };
            // El índice temporada/activo más la PK permite continuar por ID sin OFFSET
            // ni ordenar el cruce completo por especie/cliente en cada solicitud.
            $filas = $consulta->when($desde, fn ($q) => $q->where($id, '>', $desde))
                ->orderBy($id)->limit(self::TAMANO + 1)->get($columnas);
            $hayMas = $filas->count() > self::TAMANO;
            if ($hayMas) {
                $filas->pop();
            }
            if ($coleccion === 'origenes') {
                $variedades = DB::table('csg_variedades_validacion')
                    ->whereIn('csg_validacion_id', $filas->pluck('csg_validacion_id')->filter()->unique())
                    ->get(['csg_validacion_id', 'variedad_validacion_id'])->groupBy('csg_validacion_id');
                $filas->each(function ($fila) use ($variedades): void {
                    $fila->variedad_ids = $fila->csg_validacion_id === null ? null
                        : $variedades->get($fila->csg_validacion_id, collect())->pluck('variedad_validacion_id')->sort()->values()->all();
                });
            }
            if ($coleccion !== 'combinaciones') {
                $filas->each(function ($fila): void {
                    $fila->activo = true;
                });
            }
            $vigente = $temporadas->buscar();
            abort_unless($vigente && $vigente->id === $temporadaId && $vigente->version_catalogo === $version,
                409, 'El catálogo cambió durante la descarga. Reintenta para recibir la versión vigente.');

            return json_encode([
                'formato' => 'paginas-v1', 'temporada_id' => $temporadaId, 'version' => $version,
                'coleccion' => $coleccion, 'desde' => $desde, 'siguiente' => $hayMas ? $filas->last()->id : null,
                'filas' => $filas->values()->all(),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        });
    }
}
