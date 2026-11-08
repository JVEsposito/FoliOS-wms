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

    public const TAMANOS_RAPIDOS = ['categorias' => 1000, 'articulos' => 5000, 'origenes' => 1000, 'combinaciones' => 10000];

    public const COLECCIONES = ['categorias', 'articulos', 'origenes', 'combinaciones'];

    public function indice(bool $compacto = false): array
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
            ...($compacto ? ['transporte' => 'compacto-v1', 'tamanos_pagina' => self::TAMANOS_RAPIDOS] : []),
        ];
    }

    /** Cuatro intervalos disjuntos de PK; solo lee los extremos del índice existente. */
    public function rangosCombinaciones(string $temporadaId): array
    {
        $base = DB::table('combinaciones_validacion')->where('temporada_id', $temporadaId)->where('activo', true);
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $base->forceIndex('comb_val_temporada_activo');
        }
        $minimo = (clone $base)->orderBy('id')->value('id');
        $maximo = (clone $base)->orderByDesc('id')->value('id');
        if ($minimo === null || $maximo === null || $minimo === $maximo) {
            return [['inicio' => null, 'hasta' => null]];
        }
        $minimo = strtolower($minimo);
        $maximo = strtolower($maximo);
        // UUID de 128 bits sin depender de BCMath.
        $mitad = function (string $a, string $b): string {
            $a = str_replace('-', '', $a);
            $b = str_replace('-', '', $b);
            $suma = '';
            $acarreo = 0;
            for ($i = 31; $i >= 0; $i--) {
                $n = hexdec($a[$i]) + hexdec($b[$i]) + $acarreo;
                $suma = dechex($n % 16).$suma;
                $acarreo = intdiv($n, 16);
            }
            $suma = dechex($acarreo).$suma;
            $resultado = '';
            $resto = 0;
            for ($i = 0; $i < 33; $i++) {
                $n = $resto * 16 + hexdec($suma[$i]);
                $resultado .= dechex(intdiv($n, 2));
                $resto = $n % 2;
            }
            $hex = substr($resultado, -32);

            return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20);
        };
        $centro = $mitad($minimo, $maximo);
        $limites = array_values(array_unique(array_filter([$mitad($minimo, $centro), $centro, $mitad($centro, $maximo)], fn ($p): bool => $p > $minimo && $p < $maximo)));
        $puntos = [null, ...$limites, null];
        $rangos = [];
        for ($i = 0; $i < count($puntos) - 1; $i++) {
            $rangos[] = ['inicio' => $puntos[$i], 'hasta' => $puntos[$i + 1]];
        }

        return $rangos;
    }

    public function pagina(string $temporadaId, int $version, string $coleccion, ?string $desde,
        bool $compacto = false, ?string $inicio = null, ?string $hasta = null): string
    {
        abort_unless(in_array($coleccion, self::COLECCIONES, true), 404);

        return DB::transaction(function () use ($temporadaId, $version, $coleccion, $desde, $compacto, $inicio, $hasta): string {
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
                // Leer en el orden del cursor evita ordenar el cruce completo por cada página.
                'combinaciones' => [DB::table('combinaciones_validacion as c')
                    ->join('articulos_validacion as a', 'a.id', '=', 'c.articulo_validacion_id')
                    ->join('origenes_validacion as o', 'o.id', '=', 'c.origen_validacion_id')
                    ->where('c.temporada_id', $temporadaId)->where('c.activo', true)->where('a.activo', true)->where('o.activo', true),
                    'c.id', [
                        DB::raw('/*+ JOIN_PREFIX(c) INDEX(c comb_val_temporada_activo) */ c.id'),
                        'c.articulo_validacion_id', 'c.origen_validacion_id', 'c.codigo_externo',
                    ]],
            };
            $tamano = $compacto ? self::TAMANOS_RAPIDOS[$coleccion] : self::TAMANO;
            // El índice temporada/activo más la PK permite continuar por ID sin OFFSET
            // ni ordenar el cruce completo por especie/cliente en cada solicitud.
            $filas = $consulta->when($desde, fn ($q) => $q->where($id, '>', $desde))
                ->when($inicio, fn ($q) => $q->where($id, '>=', $inicio))
                ->when($hasta, fn ($q) => $q->where($id, '<', $hasta))
                ->orderBy($id)->limit($tamano + 1)->get($columnas);
            $hayMas = $filas->count() > $tamano;
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
                ...($compacto ? ['transporte' => 'compacto-v1', 'inicio' => $inicio, 'hasta' => $hasta,
                    'ultimo_id' => $filas->last()?->id, 'cantidad' => $filas->count(),
                    'datos' => app(TransferenciaCatalogoValidacion::class)->codificarFilas(
                        $coleccion === 'combinaciones' ? ['articulo_validacion_id', 'origen_validacion_id'] : TransferenciaCatalogoValidacion::COLUMNAS[$coleccion],
                        $filas->values()->all())] : ['filas' => $filas->values()->all()]),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        });
    }
}
