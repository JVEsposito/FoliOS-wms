<?php

namespace App\Services\Validacion;

use App\Models\ArticuloValidacion;
use App\Models\CalibreValidacion;
use App\Models\CategoriaValidacion;
use App\Models\ClienteValidacion;
use App\Models\CsgValidacion;
use App\Models\EnvaseValidacion;
use App\Models\EspecieValidacion;
use App\Models\MarcaValidacion;
use App\Models\OrigenValidacion;
use App\Models\Temporada;
use App\Models\VariedadValidacion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** La temporada debe estar bloqueada por el escritor; versión y proyección se publican juntas. */
class ServicioProyeccionParcialValidacion
{
    public function sincronizar(Temporada $temporada, Model $elemento): void
    {
        if ($elemento instanceof CategoriaValidacion) {
            return; // Las categorías no participan del producto cartesiano.
        }
        $articulo = match ($elemento::class) {
            EspecieValidacion::class => 'especie_validacion_id',
            VariedadValidacion::class => 'variedad_validacion_id',
            CalibreValidacion::class => 'calibre_validacion_id',
            EnvaseValidacion::class => 'envase_validacion_id',
            default => null,
        };
        if (! $elemento->wasRecentlyCreated && ! $elemento instanceof CsgValidacion
            && ! array_intersect(array_keys($elemento->getChanges()), ['nombre', 'activo', 'especie_validacion_id', 'cliente_validacion_id'])) {
            return;
        }
        $campo = $articulo ?? ($elemento instanceof MarcaValidacion ? 'marca_validacion_id' : 'csg_validacion_id');
        $tabla = $articulo ? 'articulos_validacion' : 'origenes_validacion';
        // Retira también las filas con el nombre o padre anterior, conservando sus IDs e historial.
        DB::table($tabla)->where('temporada_id', $temporada->id)->where($campo, $elemento->id)
            ->where('activo', true)->update(['activo' => false, 'updated_at' => now()]);
        DB::table('combinaciones_validacion')->where('temporada_id', $temporada->id)->where('activo', true)
            ->whereIn($articulo ? 'origen_validacion_id' : 'articulo_validacion_id',
                DB::table($articulo ? 'origenes_validacion' : 'articulos_validacion')->select('id')
                    ->where('temporada_id', $temporada->id)->whereNotNull($articulo ? 'csg_validacion_id' : 'especie_validacion_id'))
            ->whereIn($articulo ? 'articulo_validacion_id' : 'origen_validacion_id',
                DB::table($tabla)->select('id')->where('temporada_id', $temporada->id)->where($campo, $elemento->id))
            ->update(['activo' => false, 'updated_at' => now()]);

        if ($articulo) {
            $this->articulos($temporada, $elemento, $campo);
        } else {
            $this->origenes($temporada, $elemento);
        }
        $this->combinaciones($temporada, $articulo ? 'a' : 'o', $campo, $elemento->id);
    }

    private function articulos(Temporada $temporada, Model $elemento, string $campo): void
    {
        $especie = EspecieValidacion::findOrFail($elemento instanceof EspecieValidacion ? $elemento->id : $elemento->especie_validacion_id);
        $especie->load([
            'variedades' => fn ($q) => $q->when($elemento instanceof VariedadValidacion, fn ($q) => $q->whereKey($elemento->id)),
            'calibres' => fn ($q) => $q->when($elemento instanceof CalibreValidacion, fn ($q) => $q->whereKey($elemento->id)),
            'envases' => fn ($q) => $q->when($elemento instanceof EnvaseValidacion, fn ($q) => $q->whereKey($elemento->id)),
        ]);
        // Natural keys permiten reutilizar las filas legadas; la FK no siempre existía en ellas.
        $existentes = ArticuloValidacion::where('temporada_id', $temporada->id)->where('especie', $especie->nombre)
            ->whereIn('variedad', $especie->variedades->pluck('nombre'))->whereIn('calibre', $especie->calibres->pluck('nombre'))
            ->whereIn('envase', $especie->envases->pluck('nombre'))
            ->get(['id', 'cliente_validacion_id', 'especie', 'variedad', 'calibre', 'envase'])
            ->keyBy(fn ($a) => $this->clave([$a->cliente_validacion_id, $a->especie, $a->variedad, $a->calibre, $a->envase]));
        $filas = [];
        foreach ($especie->variedades as $variedad) {
            foreach ($especie->calibres as $calibre) {
                foreach ($especie->envases as $envase) {
                    $clave = $this->clave([$envase->cliente_validacion_id, $especie->nombre, $variedad->nombre, $calibre->nombre, $envase->nombre]);
                    $filas[] = ['id' => $existentes->get($clave)?->id ?? (string) Str::uuid(), 'temporada_id' => $temporada->id,
                        'cliente_validacion_id' => $envase->cliente_validacion_id, 'especie_validacion_id' => $especie->id,
                        'variedad_validacion_id' => $variedad->id, 'calibre_validacion_id' => $calibre->id, 'envase_validacion_id' => $envase->id,
                        'especie' => $especie->nombre, 'variedad' => $variedad->nombre, 'calibre' => $calibre->nombre, 'envase' => $envase->nombre,
                        'activo' => $especie->activo && $variedad->activo && $calibre->activo && $envase->activo,
                        'created_at' => now(), 'updated_at' => now()];
                    if (count($filas) === 500) {
                        $this->publicarArticulos($filas);
                        $filas = [];
                    }
                }
            }
        }
        $this->publicarArticulos($filas);
    }

    private function publicarArticulos(array $filas): void
    {
        if ($filas !== []) {
            DB::table('articulos_validacion')->upsert($filas, ['id'], ['cliente_validacion_id', 'especie_validacion_id',
                'variedad_validacion_id', 'calibre_validacion_id', 'envase_validacion_id', 'activo', 'updated_at']);
        }
    }

    private function origenes(Temporada $temporada, Model $elemento): void
    {
        $clientes = ClienteValidacion::where('temporada_id', $temporada->id)
            ->when($elemento instanceof MarcaValidacion, fn ($q) => $q->whereKey($elemento->cliente_validacion_id))
            ->with(['marcas' => fn ($q) => $q->when($elemento instanceof MarcaValidacion, fn ($q) => $q->whereKey($elemento->id))])->get();
        foreach ($clientes as $cliente) {
            $csgs = CsgValidacion::where('temporada_id', $temporada->id)
                ->when($cliente->cliente_id, fn ($q) => $q->disponibleParaCliente($cliente->cliente_id), fn ($q) => $q->whereNull('productor_csg_id'))
                ->when($elemento instanceof CsgValidacion, fn ($q) => $q->whereKey($elemento->id))->get();
            foreach ($cliente->marcas as $marca) {
                $existentes = OrigenValidacion::where('temporada_id', $temporada->id)->where('cliente', $cliente->nombre)
                    ->where('marca', $marca->nombre)->whereIn('csg', $csgs->pluck('codigo'))->pluck('id', 'csg');
                foreach ($csgs->chunk(500) as $grupo) {
                    $filas = $grupo->map(fn ($csg) => ['id' => $existentes->get($csg->codigo) ?? (string) Str::uuid(),
                        'temporada_id' => $temporada->id, 'cliente_validacion_id' => $cliente->id, 'marca_validacion_id' => $marca->id,
                        'csg_validacion_id' => $csg->id, 'cliente' => $cliente->nombre, 'marca' => $marca->nombre,
                        'csg' => $csg->codigo, 'predio' => $csg->predio, 'activo' => $cliente->activo && $marca->activo && $csg->activo,
                        'created_at' => now(), 'updated_at' => now()])->all();
                    DB::table('origenes_validacion')->upsert($filas, ['id'],
                        ['cliente_validacion_id', 'marca_validacion_id', 'csg_validacion_id', 'predio', 'activo', 'updated_at']);
                }
            }
        }
    }

    private function combinaciones(Temporada $temporada, string $alias, string $campo, string $id): void
    {
        // SQL cruza solo variedades autorizadas y el cliente propietario del embalaje.
        // No hidrata la temporada ni compara todos los artículos con todos los orígenes en PHP.
        DB::table('articulos_validacion as a')
            ->join('csg_variedades_validacion as cv', 'cv.variedad_validacion_id', '=', 'a.variedad_validacion_id')
            ->join('origenes_validacion as o', 'o.csg_validacion_id', '=', 'cv.csg_validacion_id')
            ->where('a.temporada_id', $temporada->id)->where('o.temporada_id', $temporada->id)
            ->where('a.activo', true)->where('o.activo', true)->where($alias.'.'.$campo, $id)
            ->where(fn ($q) => $q->whereNull('a.cliente_validacion_id')->orWhereColumn('a.cliente_validacion_id', 'o.cliente_validacion_id'))
            ->orderBy('a.id')->orderBy('o.id')->select(['a.id as articulo_id', 'o.id as origen_id'])
            ->chunk(500, function ($pares) use ($temporada): void {
                $ahora = now();
                $filas = $pares->map(fn ($p) => ['id' => (string) Str::uuid(), 'temporada_id' => $temporada->id,
                    'articulo_validacion_id' => $p->articulo_id, 'origen_validacion_id' => $p->origen_id,
                    'activo' => true, 'created_at' => $ahora, 'updated_at' => $ahora])->all();
                DB::table('combinaciones_validacion')->upsert($filas,
                    ['temporada_id', 'articulo_validacion_id', 'origen_validacion_id'], ['activo', 'updated_at']);
            });
    }

    private function clave(array $valores): string
    {
        return json_encode($valores, JSON_THROW_ON_ERROR);
    }
}
