<?php

namespace App\Services\Validacion;

use App\Models\ArticuloValidacion;
use App\Models\ClienteValidacion;
use App\Models\CombinacionValidacion;
use App\Models\CsgValidacion;
use App\Models\OrigenValidacion;
use App\Models\ProductorCsg;
use App\Models\Temporada;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ServicioProyeccionCatalogoValidacion
{
    /**
     * @return array{articulos: int, origenes: int, combinaciones: int}
     */
    public function conteos(Temporada $temporada): array
    {
        return [
            'articulos' => ArticuloValidacion::query()
                ->where('temporada_id', $temporada->id)
                ->where('activo', true)
                ->count(),
            'origenes' => OrigenValidacion::query()
                ->where('temporada_id', $temporada->id)
                ->where('activo', true)
                ->count(),
            'combinaciones' => CombinacionValidacion::query()
                ->where('temporada_id', $temporada->id)
                ->where('activo', true)
                ->count(),
        ];
    }

    /**
     * Actualiza únicamente la porción del catálogo afectada por un CSG.
     *
     * La reconstrucción completa queda reservada para importaciones, cambios
     * estructurales y reparaciones. Consultar SAG o asociar un productor no
     * debe reescribir los artículos y orígenes del resto de la temporada.
     */
    public function sincronizarCsg(Temporada $temporada, ProductorCsg $productor): void
    {
        DB::transaction(function () use ($temporada, $productor): void {
            $temporada = Temporada::query()
                ->lockForUpdate()
                ->findOrFail($temporada->id);
            $csg = CsgValidacion::query()
                ->where('temporada_id', $temporada->id)
                ->where('productor_csg_id', $productor->id)
                ->with([
                    'variedades.especie.calibres',
                    'variedades.especie.envases',
                    'productor.clientes' => fn ($consulta) => $consulta
                        ->where('clientes_productores_csg.activo', true),
                ])
                ->first();

            if (! $csg) {
                return;
            }

            $articulos = $this->sincronizarArticulosCsg($temporada, $csg);
            $origenes = $this->sincronizarOrigenesCsg($temporada, $csg);
            $this->sincronizarCombinacionesCsg($temporada, $csg, $articulos, $origenes);
        }, attempts: 3);
    }

    public function reconstruir(Temporada $temporada): void
    {
        DB::transaction(function () use ($temporada): void {
            $temporada = Temporada::query()
                ->lockForUpdate()
                ->findOrFail($temporada->id);

            $temporada->load([
                'clientes.marcas',
                'especies.variedades',
                'especies.calibres',
                'especies.envases',
                'csg.variedades:id',
                'csg.productor.clientes' => fn ($consulta) => $consulta
                    ->where('clientes_productores_csg.activo', true),
            ]);

            ArticuloValidacion::query()
                ->where('temporada_id', $temporada->id)
                ->whereNotNull('especie_validacion_id')
                ->update(['activo' => false]);
            OrigenValidacion::query()
                ->where('temporada_id', $temporada->id)
                ->whereNotNull('csg_validacion_id')
                ->update(['activo' => false]);
            CombinacionValidacion::query()
                ->where('temporada_id', $temporada->id)
                ->whereHas('articulo', fn ($query) => $query->whereNotNull('especie_validacion_id'))
                ->whereHas('origen', fn ($query) => $query->whereNotNull('csg_validacion_id'))
                ->update(['activo' => false]);

            $articulos = [];
            foreach ($temporada->especies as $especie) {
                foreach ($especie->variedades as $variedad) {
                    foreach ($especie->calibres as $calibre) {
                        foreach ($especie->envases as $envase) {
                            $articulo = ArticuloValidacion::query()->firstOrNew([
                                'temporada_id' => $temporada->id,
                                'cliente_validacion_id' => $envase->cliente_validacion_id,
                                'especie' => $especie->nombre,
                                'variedad' => $variedad->nombre,
                                'calibre' => $calibre->nombre,
                                'envase' => $envase->nombre,
                            ]);
                            $articulo->fill([
                                'especie_validacion_id' => $especie->id,
                                'variedad_validacion_id' => $variedad->id,
                                'calibre_validacion_id' => $calibre->id,
                                'envase_validacion_id' => $envase->id,
                                'activo' => $especie->activo
                                    && $variedad->activo
                                    && $calibre->activo
                                    && $envase->activo,
                            ])->save();

                            $articulos[] = [
                                // No retener decenas de miles de modelos Eloquent y
                                // sus originales/relaciones durante todo el cruce.
                                'id' => $articulo->id,
                                'activo' => $articulo->activo,
                                'variedad_id' => $variedad->id,
                                'cliente_id' => $envase->cliente_validacion_id,
                            ];
                        }
                    }
                }
            }

            $origenes = [];
            foreach ($temporada->clientes as $cliente) {
                foreach ($cliente->marcas as $marca) {
                    foreach ($temporada->csg as $csg) {
                        if (! $this->disponibleParaCliente($csg, $cliente)) {
                            continue;
                        }
                        $origen = OrigenValidacion::query()->firstOrNew([
                            'temporada_id' => $temporada->id,
                            'cliente' => $cliente->nombre,
                            'marca' => $marca->nombre,
                            'csg' => $csg->codigo,
                        ]);
                        $origen->fill([
                            'cliente_validacion_id' => $cliente->id,
                            'marca_validacion_id' => $marca->id,
                            'csg_validacion_id' => $csg->id,
                            'predio' => $csg->predio,
                            'activo' => $cliente->activo && $marca->activo && $csg->activo,
                        ])->save();

                        $origenes[] = [
                            'modelo' => $origen,
                            'variedades' => $csg->variedades->pluck('id')->all(),
                            'cliente_id' => $cliente->id,
                        ];
                    }
                }
            }

            $porVariedad = [];
            foreach ($origenes as $origen) {
                foreach ($origen['variedades'] as $variedad) {
                    $porVariedad[$variedad][] = $origen;
                }
            }
            $lote = [];
            $ahora = now();
            foreach ($articulos as $articulo) {
                foreach ($porVariedad[$articulo['variedad_id']] ?? [] as $origen) {
                    if ($articulo['cliente_id'] !== null
                        && $articulo['cliente_id'] !== $origen['cliente_id']) {
                        continue;
                    }

                    $lote[] = [
                        'id' => (string) Str::uuid7(),
                        'temporada_id' => $temporada->id,
                        'articulo_validacion_id' => $articulo['id'],
                        'origen_validacion_id' => $origen['modelo']->id,
                        'activo' => $articulo['activo'] && $origen['modelo']->activo,
                        'created_at' => $ahora, 'updated_at' => $ahora,
                    ];
                    if (count($lote) === 500) {
                        $this->guardarCombinaciones($lote);
                    }
                }
            }
            $this->guardarCombinaciones($lote);
        }, attempts: 3);
    }

    /** @return Collection<int, ArticuloValidacion> */
    private function sincronizarArticulosCsg(
        Temporada $temporada,
        CsgValidacion $csg,
    ): Collection {
        $articulos = collect();

        foreach ($csg->variedades as $variedad) {
            $especie = $variedad->especie;
            if (! $especie) {
                continue;
            }

            foreach ($especie->calibres as $calibre) {
                foreach ($especie->envases as $envase) {
                    $articulo = ArticuloValidacion::query()->firstOrNew([
                        'temporada_id' => $temporada->id,
                        'cliente_validacion_id' => $envase->cliente_validacion_id,
                        'especie' => $especie->nombre,
                        'variedad' => $variedad->nombre,
                        'calibre' => $calibre->nombre,
                        'envase' => $envase->nombre,
                    ]);
                    $articulo->fill([
                        'especie_validacion_id' => $especie->id,
                        'variedad_validacion_id' => $variedad->id,
                        'calibre_validacion_id' => $calibre->id,
                        'envase_validacion_id' => $envase->id,
                        'activo' => $especie->activo
                            && $variedad->activo
                            && $calibre->activo
                            && $envase->activo,
                    ])->save();
                    $articulos->push($articulo);
                }
            }
        }

        return $articulos->unique('id')->values();
    }

    /** @return Collection<int, OrigenValidacion> */
    private function sincronizarOrigenesCsg(
        Temporada $temporada,
        CsgValidacion $csg,
    ): Collection {
        OrigenValidacion::query()
            ->where('temporada_id', $temporada->id)
            ->where('csg_validacion_id', $csg->id)
            ->update(['activo' => false]);

        $clienteIds = $csg->productor?->clientes->pluck('id')->all() ?? [];
        if ($clienteIds === []) {
            return collect();
        }

        $clientes = ClienteValidacion::query()
            ->where('temporada_id', $temporada->id)
            ->whereIn('cliente_id', $clienteIds)
            ->with('marcas')
            ->get();
        $origenes = collect();

        foreach ($clientes as $cliente) {
            foreach ($cliente->marcas as $marca) {
                $origen = OrigenValidacion::query()->firstOrNew([
                    'temporada_id' => $temporada->id,
                    'cliente' => $cliente->nombre,
                    'marca' => $marca->nombre,
                    'csg' => $csg->codigo,
                ]);
                $origen->fill([
                    'cliente_validacion_id' => $cliente->id,
                    'marca_validacion_id' => $marca->id,
                    'csg_validacion_id' => $csg->id,
                    'predio' => $csg->predio,
                    'activo' => $cliente->activo && $marca->activo && $csg->activo,
                ])->save();
                $origenes->push($origen);
            }
        }

        return $origenes->unique('id')->values();
    }

    /**
     * @param  Collection<int, ArticuloValidacion>  $articulos
     * @param  Collection<int, OrigenValidacion>  $origenes
     */
    private function sincronizarCombinacionesCsg(
        Temporada $temporada,
        CsgValidacion $csg,
        Collection $articulos,
        Collection $origenes,
    ): void {
        $origenIds = OrigenValidacion::query()
            ->where('temporada_id', $temporada->id)
            ->where('csg_validacion_id', $csg->id)
            ->pluck('id');
        if ($origenIds->isNotEmpty()) {
            CombinacionValidacion::query()
                ->where('temporada_id', $temporada->id)
                ->whereIn('origen_validacion_id', $origenIds)
                ->update(['activo' => false]);
        }

        $lote = [];
        $ahora = now();
        foreach ($origenes as $origen) {
            foreach ($articulos as $articulo) {
                if ($articulo->cliente_validacion_id !== null
                    && $articulo->cliente_validacion_id !== $origen->cliente_validacion_id) {
                    continue;
                }

                $lote[] = [
                    'id' => (string) Str::uuid7(),
                    'temporada_id' => $temporada->id,
                    'articulo_validacion_id' => $articulo->id,
                    'origen_validacion_id' => $origen->id,
                    'activo' => $articulo->activo && $origen->activo,
                    'created_at' => $ahora, 'updated_at' => $ahora,
                ];
                if (count($lote) === 500) {
                    $this->guardarCombinaciones($lote);
                }
            }
        }
        $this->guardarCombinaciones($lote);
    }

    private function guardarCombinaciones(array &$lote): void
    {
        if ($lote !== []) {
            // La restricción única existente conserva ID, código externo e historia
            // del vínculo. Solo cambia su vigencia, sin SELECT/UPDATE por cada par.
            DB::table('combinaciones_validacion')->upsert($lote,
                ['temporada_id', 'articulo_validacion_id', 'origen_validacion_id'], ['activo', 'updated_at']);
            $lote = [];
        }
    }

    private function disponibleParaCliente(
        CsgValidacion $csg,
        ClienteValidacion $cliente,
    ): bool {
        if (! $csg->productor_csg_id) {
            return true;
        }

        return $csg->productor?->clientes->contains(
            fn ($clienteGlobal): bool => $clienteGlobal->id === $cliente->cliente_id,
        ) === true;
    }
}
