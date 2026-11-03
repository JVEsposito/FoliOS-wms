<?php

namespace App\Services\Cargas;

use App\Enums\EstadoCargaFolio;
use App\Models\CargaFolio;
use Illuminate\Support\Collection;

class CalculadorConcentracionCarga
{
    public const UMBRAL_PORCENTAJE = 80;

    /**
     * Considera concentrados los folios en andén (incluidos los ya cargados en el
     * camión) y, dentro de una misma cámara,
     * las bandas completas más el mayor grupo parcial conectado. Dos posiciones
     * están conectadas si son correlativas en una banda o pertenecen a bandas
     * consecutivas con la misma profundidad o una profundidad adyacente. Los
     * niveles consecutivos conectan solo si son la misma columna de la banda.
     *
     * @param  Collection<int, CargaFolio>  $asignaciones
     * @return array<string, mixed>
     */
    public function calcular(Collection $asignaciones): array
    {
        $analisis = $this->analizar($asignaciones);

        unset(
            $analisis['grupo_principal_folio_ids'],
            $analisis['grupo_principal_puntos'],
        );
        if (isset($analisis['segregaciones'])) {
            $analisis['segregaciones'] = collect($analisis['segregaciones'])->map(function (array $grupo): array {
                unset($grupo['grupo_principal_folio_ids'], $grupo['grupo_principal_puntos']);

                return $grupo;
            })->all();
        }

        return $analisis;
    }

    /**
     * Variante operacional que conserva la geometría del grupo principal para
     * que el planificador rolling pueda demostrar que una propuesta aumenta la
     * concentración en vez de mover pallets sin beneficio físico.
     *
     * @param  Collection<int, CargaFolio>  $asignaciones
     * @return array<string, mixed>
     */
    public function analizar(
        Collection $asignaciones,
        ?string $camaraObjetivoId = null,
        array $bandasMezcladas = [],
    ): array {
        $grupos = $asignaciones->groupBy(fn (CargaFolio $a): string => $a->instructivo_embarque_id ?? '');
        if ($grupos->count() > 1) {
            $bandasMezcladas = $asignaciones->filter(fn ($a): bool => $a->folio?->ubicacionActual?->posicion !== null)
                ->groupBy(fn ($a): string => $a->folio->ubicacionActual->posicion->camara_id.':'.$a->folio->ubicacionActual->posicion->banda)
                ->filter(fn (Collection $banda): bool => $banda->pluck('instructivo_embarque_id')->unique()->count() > 1)
                ->keys()->all();
            $analisis = $grupos->map(fn (Collection $grupo): array => $this->analizar($grupo, $camaraObjetivoId, $bandasMezcladas));
            $salida = $analisis->sortByDesc('concentrados')->first();
            foreach (['concentrados', 'faltantes', 'total', 'en_anden', 'cargados', 'por_cargar', 'despachados', 'con_incidencia', 'pendientes'] as $campo) {
                $salida[$campo] = (int) $analisis->sum($campo);
            }
            $salida['porcentaje'] = $salida['total'] ? (int) round($salida['concentrados'] / $salida['total'] * 100) : 0;
            $salida['cumple_umbral'] = $analisis->every(fn ($a): bool => $a['cumple_umbral']);
            $salida['segregaciones'] = $analisis->all();

            return $salida;
        }
        $total = $asignaciones->count();
        // «en_anden» cuenta todo lo que salió de la cámara; «cargados» es el
        // subconjunto que el camarero ya registró arriba del camión.
        $enAnden = $asignaciones
            ->filter(fn (CargaFolio $asignacion): bool => $asignacion->estado->fueraDeCamara())
            ->count();
        $cargados = $asignaciones
            ->where('estado', EstadoCargaFolio::Cargado)
            ->count();
        $despachados = $asignaciones
            ->where('estado', EstadoCargaFolio::Despachado)
            ->count();
        $conIncidencia = $asignaciones
            ->where('estado', EstadoCargaFolio::ConIncidencia)
            ->count();
        $ubicadas = $asignaciones
            ->filter(fn (CargaFolio $asignacion): bool => $asignacion
                ->folio
                ?->ubicacionActual
                ?->posicion !== null)
            ->reject(fn ($a): bool => in_array($a->folio->ubicacionActual->posicion->camara_id.':'.$a->folio->ubicacionActual->posicion->banda, $bandasMezcladas, true))
            ->values();

        $grupoPrincipal = $this->grupoPrincipal($ubicadas, $camaraObjetivoId);
        $concentrados = min($total, $enAnden + $despachados + $grupoPrincipal['cantidad']);
        $porcentaje = $total === 0
            ? 0
            : (int) round(($concentrados / $total) * 100);

        return [
            'porcentaje' => $porcentaje,
            'umbral_porcentaje' => self::UMBRAL_PORCENTAJE,
            'cumple_umbral' => $total > 0 && $porcentaje >= self::UMBRAL_PORCENTAJE,
            'concentrados' => $concentrados,
            'faltantes' => max(0, $total - $concentrados),
            'total' => $total,
            'en_anden' => $enAnden,
            'cargados' => $cargados,
            'por_cargar' => max(0, $total - $cargados - $despachados),
            'despachados' => $despachados,
            'con_incidencia' => $conIncidencia,
            'pendientes' => max(0, $total - $enAnden - $despachados - $conIncidencia),
            'grupo_principal' => $grupoPrincipal['ubicacion'],
            'grupo_principal_folio_ids' => $grupoPrincipal['folio_ids'],
            'grupo_principal_puntos' => $grupoPrincipal['puntos'],
        ];
    }

    /**
     * @param  Collection<int, CargaFolio>  $asignaciones
     * @return array{
     *     cantidad: int,
     *     ubicacion: array<string, mixed>|null,
     *     folio_ids: array<int, string>,
     *     puntos: array<int, array{banda: int, posicion: int, nivel: int}>
     * }
     */
    private function grupoPrincipal(
        Collection $asignaciones,
        ?string $camaraObjetivoId = null,
    ): array {
        $mejor = [
            'cantidad' => 0,
            'ubicacion' => null,
            'folio_ids' => [],
            'puntos' => [],
        ];

        $grupos = $asignaciones
            ->when(
                $camaraObjetivoId !== null,
                fn (Collection $coleccion): Collection => $coleccion->filter(
                    fn (CargaFolio $asignacion): bool => $asignacion
                        ->folio
                        ->ubicacionActual
                        ->posicion
                        ->camara_id === $camaraObjetivoId,
                ),
            )
            ->groupBy(function (CargaFolio $asignacion): string {
                $posicion = $asignacion->folio->ubicacionActual->posicion;

                return (string) $posicion->camara_id;
            })
            ->sortKeys();

        $grupos->each(function (Collection $grupo) use (&$mejor): void {
            $componentes = $this->componentes($grupo);
            $completas = $grupo->groupBy(fn ($a) => $a->folio->ubicacionActual->posicion->banda)
                ->filter(function (Collection $banda): bool {
                    $camara = $banda->first()->folio->ubicacionActual->posicion->camara;
                    $numero = (int) $banda->first()->folio->ubicacionActual->posicion->banda;
                    $profundidad = (int) $camara->posiciones_por_banda;
                    $niveles = (int) $camara->cantidad_niveles;
                    if ($numero < 1 || $numero > (int) $camara->cantidad_bandas
                        || $profundidad < 1 || $niveles < 1 || $banda->count() !== $profundidad * $niveles) {
                        return false;
                    }
                    $posiciones = $banda->map(fn ($a) => $a->folio->ubicacionActual->posicion);

                    return $posiciones->every(fn ($p) => $p->posicion >= 1 && $p->posicion <= $profundidad && $p->nivel >= 1 && $p->nivel <= $niveles)
                        && $posiciones->unique(fn ($p) => $p->posicion.':'.$p->nivel)->count() === $banda->count();
                })->flatten(1);

            foreach ($componentes as $componente) {
                // No desarmar una banda completa porque otra carga ocupa bandas
                // intermedias. Las parciales dispersas siguen sin sumar entre sí.
                $componente = $componente->concat($completas)->unique('folio_id');
                $cantidad = $componente->count();
                if ($cantidad < $mejor['cantidad']) {
                    continue;
                }

                /** @var CargaFolio $primera */
                $primera = $componente->sortBy('folio_id')->first();
                $posiciones = $componente
                    ->map(fn (CargaFolio $asignacion) => $asignacion
                        ->folio
                        ->ubicacionActual
                        ->posicion);
                $posicion = $primera->folio->ubicacionActual->posicion;
                $camara = $posicion->camara;
                $ubicacion = [
                    'camara' => [
                        'id' => $camara->id,
                        'codigo' => $camara->codigo,
                        'nombre' => $camara->nombre,
                    ],
                    'nivel' => (int) $posiciones->min('nivel'),
                    'niveles' => $posiciones->pluck('nivel')->unique()->sort()->values()->all(),
                    'bandas' => $posiciones->pluck('banda')->unique()->sort()->values()->all(),
                    'banda_desde' => (int) $posiciones->min('banda'),
                    'banda_hasta' => (int) $posiciones->max('banda'),
                    'posicion_desde' => (int) $posiciones->min('posicion'),
                    'posicion_hasta' => (int) $posiciones->max('posicion'),
                ];

                if ($cantidad === $mejor['cantidad']
                    && $mejor['ubicacion'] !== null
                    && $this->claveUbicacion($ubicacion) >= $this->claveUbicacion($mejor['ubicacion'])) {
                    continue;
                }

                $mejor = [
                    'cantidad' => $cantidad,
                    'ubicacion' => $ubicacion,
                    'folio_ids' => $componente
                        ->pluck('folio_id')
                        ->sort()
                        ->values()
                        ->all(),
                    'puntos' => $posiciones
                        ->map(fn ($p): array => [
                            'banda' => (int) $p->banda,
                            'posicion' => (int) $p->posicion,
                            'nivel' => (int) $p->nivel,
                        ])
                        ->sortBy(fn (array $p): string => sprintf(
                            '%05d:%05d:%05d',
                            $p['nivel'],
                            $p['banda'],
                            $p['posicion'],
                        ))
                        ->values()
                        ->all(),
                ];
            }
        });

        return $mejor;
    }

    /**
     * @param  array<string, mixed>  $ubicacion
     */
    private function claveUbicacion(array $ubicacion): string
    {
        return sprintf(
            '%s:%05d:%05d:%05d',
            $ubicacion['camara']['id'],
            $ubicacion['nivel'],
            $ubicacion['banda_desde'],
            $ubicacion['posicion_desde'],
        );
    }

    /**
     * @param  Collection<int, CargaFolio>  $grupo
     * @return array<int, Collection<int, CargaFolio>>
     */
    private function componentes(Collection $grupo): array
    {
        $pendientes = $grupo->sortBy('id')->keyBy('id');
        $componentes = [];

        while ($pendientes->isNotEmpty()) {
            /** @var CargaFolio $inicio */
            $inicio = $pendientes->first();
            $pendientes->forget($inicio->id);
            $cola = collect([$inicio]);
            $componente = collect();

            while ($cola->isNotEmpty()) {
                /** @var CargaFolio $actual */
                $actual = $cola->shift();
                $componente->push($actual);

                $vecinas = $pendientes
                    ->filter(fn (CargaFolio $candidata): bool => $this->sonVecinas($actual, $candidata))
                    ->values();

                foreach ($vecinas as $vecina) {
                    $pendientes->forget($vecina->id);
                    $cola->push($vecina);
                }
            }

            $componentes[] = $componente;
        }

        return $componentes;
    }

    private function sonVecinas(CargaFolio $una, CargaFolio $otra): bool
    {
        $primera = $una->folio->ubicacionActual->posicion;
        $segunda = $otra->folio->ubicacionActual->posicion;

        return SectorAcopioCarga::vecinos($primera->only(['banda', 'posicion', 'nivel']), $segunda->only(['banda', 'posicion', 'nivel']));
    }
}
