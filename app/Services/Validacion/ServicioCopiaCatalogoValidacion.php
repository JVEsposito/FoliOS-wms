<?php

namespace App\Services\Validacion;

use App\Models\CalibreValidacion;
use App\Models\CategoriaValidacion;
use App\Models\ClienteValidacion;
use App\Models\CsgValidacion;
use App\Models\EnvaseValidacion;
use App\Models\EspecieValidacion;
use App\Models\MarcaValidacion;
use App\Models\Temporada;
use App\Models\VariedadValidacion;
use DomainException;
use Illuminate\Support\Facades\DB;

class ServicioCopiaCatalogoValidacion
{
    public function copiar(Temporada $origen, Temporada $destino): Temporada
    {
        if ($origen->is($destino)) {
            throw new DomainException('La temporada de origen y destino deben ser diferentes.');
        }

        return DB::transaction(function () use ($origen, $destino): Temporada {
            $origen = Temporada::query()->lockForUpdate()->findOrFail($origen->id);
            $destino = Temporada::query()->lockForUpdate()->findOrFail($destino->id);

            $destinoOcupado = $destino->especies()->exists()
                || $destino->categorias()->exists()
                || $destino->csg()->exists()
                || MarcaValidacion::query()
                    ->whereHas('cliente', fn ($consulta) => $consulta
                        ->where('temporada_id', $destino->id))
                    ->exists();

            if ($destinoOcupado) {
                throw new DomainException('La temporada de destino ya posee un catálogo jerárquico.');
            }

            $origen->load([
                'clientes.cliente',
                'clientes.marcas',
                'categorias',
                'especies.variedades',
                'especies.calibres',
                'especies.envases',
                'csg.variedades',
            ]);

            $clientes = [];
            foreach ($origen->clientes as $cliente) {
                $clienteNuevo = ClienteValidacion::query()->updateOrCreate(
                    [
                        'cliente_id' => $cliente->cliente_id,
                        'temporada_id' => $destino->id,
                    ],
                    [
                        'nombre' => $cliente->cliente?->nombre ?? $cliente->nombre,
                        'codigo_externo' => $cliente->cliente?->codigo ?? $cliente->codigo_externo,
                        'activo' => $cliente->cliente?->activo ?? $cliente->activo,
                    ],
                );
                $clientes[$cliente->id] = $clienteNuevo->id;

                foreach ($cliente->marcas as $marca) {
                    MarcaValidacion::create([
                        'cliente_validacion_id' => $clienteNuevo->id,
                        'nombre' => $marca->nombre,
                        'codigo_externo' => $marca->codigo_externo,
                        'activo' => $marca->activo,
                    ]);
                }
            }

            foreach ($origen->categorias as $categoria) {
                CategoriaValidacion::create([
                    'temporada_id' => $destino->id,
                    'nombre' => $categoria->nombre,
                    'codigo_externo' => $categoria->codigo_externo,
                    'activo' => $categoria->activo,
                ]);
            }

            $variedades = [];
            foreach ($origen->especies as $especie) {
                $especieNueva = EspecieValidacion::create([
                    'temporada_id' => $destino->id,
                    'nombre' => $especie->nombre,
                    'codigo_externo' => $especie->codigo_externo,
                    'activo' => $especie->activo,
                ]);

                foreach ($especie->variedades as $variedad) {
                    $variedadNueva = VariedadValidacion::create([
                        'especie_validacion_id' => $especieNueva->id,
                        'nombre' => $variedad->nombre,
                        'codigo_externo' => $variedad->codigo_externo,
                        'activo' => $variedad->activo,
                    ]);
                    $variedades[$variedad->id] = $variedadNueva->id;
                }

                foreach ($especie->calibres as $calibre) {
                    CalibreValidacion::create([
                        'especie_validacion_id' => $especieNueva->id,
                        'nombre' => $calibre->nombre,
                        'codigo_externo' => $calibre->codigo_externo,
                        'activo' => $calibre->activo,
                    ]);
                }

                foreach ($especie->envases as $envase) {
                    EnvaseValidacion::create([
                        'especie_validacion_id' => $especieNueva->id,
                        'cliente_validacion_id' => $envase->cliente_validacion_id !== null
                            ? ($clientes[$envase->cliente_validacion_id] ?? null)
                            : null,
                        'nombre' => $envase->nombre,
                        'kilos_netos_por_caja' => $envase->kilos_netos_por_caja,
                        'codigo_externo' => $envase->codigo_externo,
                        'activo' => $envase->activo,
                    ]);
                }
            }

            foreach ($origen->csg as $csg) {
                $csgNuevo = CsgValidacion::create([
                    'productor_csg_id' => $csg->productor_csg_id,
                    'temporada_id' => $destino->id,
                    'codigo' => $csg->codigo,
                    'predio' => $csg->predio,
                    'codigo_externo' => $csg->codigo_externo,
                    'activo' => $csg->activo,
                ]);
                $csgNuevo->variedades()->attach(
                    $csg->variedades
                        ->map(fn (VariedadValidacion $variedad): ?string => $variedades[$variedad->id] ?? null)
                        ->filter()
                        ->values()
                        ->all(),
                );
            }

            $destino->increment('version_catalogo');

            return $destino->refresh()->loadCount(['clientes', 'categorias', 'especies', 'csg']);
        }, attempts: 3);
    }
}
