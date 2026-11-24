<?php

namespace App\Services\Validacion;

use App\Exceptions\ConflictoOperacion;
use App\Models\CsgValidacion;
use App\Models\EspecieValidacion;
use App\Models\ProductorCsg;
use App\Models\VariedadValidacion;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ServicioSincronizacionCatalogoSag
{
    public function __construct(
        private readonly ServicioProyeccionCatalogoValidacion $proyector,
        private readonly ServicioTemporadaActiva $temporadas,
        private readonly BloqueoCatalogoValidacion $bloqueo,
    ) {}

    /**
     * @param  array<int, array{especie: string, variedad: string, texto: string}>  $pares
     * @return array{
     *     temporada_id: ?string,
     *     csg_creados: int,
     *     csg_actualizados: int,
     *     especies_creadas: int,
     *     variedades_creadas: int,
     *     variedades_vinculadas: int,
     *     catalogo_actualizado: bool
     * }
     */
    public function sincronizar(
        ProductorCsg $productor,
        array $pares,
        bool $proyectar = true,
    ): array {
        $resultado = [
            'temporada_id' => null,
            'csg_creados' => 0,
            'csg_actualizados' => 0,
            'especies_creadas' => 0,
            'variedades_creadas' => 0,
            'variedades_vinculadas' => 0,
            'catalogo_actualizado' => false,
        ];

        return DB::transaction(function () use ($productor, $pares, $proyectar, $resultado): array {
            $activa = $this->temporadas->buscar();
            if (! $activa) {
                return $resultado;
            }
            // Primero el catálogo y después la temporada, en el mismo orden que las demás
            // escrituras del catálogo. Compartido: la temporada sigue activa hasta el commit,
            // sin frenar a Romana ni lotes.
            $this->bloqueo->adquirir($activa->id);
            $temporada = $this->temporadas->buscarConBloqueoCompartido();
            if ($temporada?->id !== $activa->id) {
                throw new ConflictoOperacion('La temporada activa cambió durante la sincronización con SAG. Vuelve a intentarlo.');
            }

            $resultado['temporada_id'] = $temporada->id;
            $variedadIds = [];
            $catalogoCambio = false;

            foreach ($pares as $par) {
                $nombreEspecie = $this->nombreCatalogo($par['especie']);
                $nombreVariedad = $this->nombreCatalogo($par['variedad']);

                $especie = EspecieValidacion::query()
                    ->where('temporada_id', $temporada->id)
                    ->where('nombre', $nombreEspecie)
                    ->first();
                if (! $especie) {
                    $especie = EspecieValidacion::query()->create([
                        'temporada_id' => $temporada->id,
                        'nombre' => $nombreEspecie,
                        'activo' => true,
                    ]);
                    $resultado['especies_creadas']++;
                    $catalogoCambio = true;
                }

                $variedad = VariedadValidacion::query()
                    ->where('especie_validacion_id', $especie->id)
                    ->where('nombre', $nombreVariedad)
                    ->first();
                if (! $variedad) {
                    $variedad = VariedadValidacion::query()->create([
                        'especie_validacion_id' => $especie->id,
                        'nombre' => $nombreVariedad,
                        'activo' => true,
                    ]);
                    $resultado['variedades_creadas']++;
                    $catalogoCambio = true;
                }

                $variedadIds[] = $variedad->id;
            }

            $csg = CsgValidacion::query()
                ->where('temporada_id', $temporada->id)
                ->whereRaw('UPPER(codigo) = ?', [mb_strtoupper($productor->codigo)])
                ->first();
            $csgNuevo = $csg === null;
            $csg ??= new CsgValidacion;
            $csg->fill([
                'productor_csg_id' => $productor->id,
                'temporada_id' => $temporada->id,
                'codigo' => mb_strtoupper($productor->codigo),
                'predio' => filled($productor->predio) ? $productor->predio : null,
                'activo' => $csgNuevo
                    ? mb_strtolower($productor->estado_sag) === 'activo'
                    : $csg->activo,
            ]);
            $csgCambio = $csgNuevo || $csg->isDirty();
            $csg->save();
            if ($csgNuevo) {
                $resultado['csg_creados']++;
            } elseif ($csgCambio) {
                $resultado['csg_actualizados']++;
            }

            $cambios = $csg->variedades()->syncWithoutDetaching(
                array_values(array_unique($variedadIds)),
            );
            $resultado['variedades_vinculadas'] = count($cambios['attached']);
            $catalogoCambio = $catalogoCambio
                || $csgCambio
                || $resultado['variedades_vinculadas'] > 0;
            $resultado['catalogo_actualizado'] = $catalogoCambio;

            if ($catalogoCambio) {
                if ($proyectar) {
                    $this->proyector->sincronizarCsg($temporada, $productor);
                }
                $this->bloqueo->publicar($temporada);
            }

            return $resultado;
        }, attempts: 3);
    }

    private function nombreCatalogo(string $valor): string
    {
        return mb_convert_case(
            Str::of($valor)->squish()->toString(),
            MB_CASE_TITLE,
            'UTF-8',
        );
    }
}
