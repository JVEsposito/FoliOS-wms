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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ServicioCatalogoJerarquicoValidacion
{
    public function __construct(
        private readonly ServicioProyeccionCatalogoValidacion $proyector,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function datos(Temporada $temporada): array
    {
        return [
            'temporada' => $temporada,
            'clientes' => ClienteValidacion::query()
                ->where('temporada_id', $temporada->id)
                ->with('marcas')
                ->orderBy('nombre')
                ->get(),
            'categorias' => CategoriaValidacion::query()
                ->where('temporada_id', $temporada->id)
                ->orderBy('nombre')
                ->get(),
            'especies' => EspecieValidacion::query()
                ->where('temporada_id', $temporada->id)
                ->with([
                    'variedades' => fn ($query) => $query->orderBy('nombre'),
                    'calibres' => fn ($query) => $query->orderBy('nombre'),
                    'envases' => fn ($query) => $query
                        ->with('cliente:id,nombre,codigo_externo')
                        ->orderBy('nombre'),
                ])
                ->orderBy('nombre')
                ->get(),
            'csg' => CsgValidacion::query()
                ->where('temporada_id', $temporada->id)
                ->with([
                    'variedades:id,especie_validacion_id,nombre,activo',
                    'productor.clientes' => fn ($consulta) => $consulta
                        ->where('clientes_productores_csg.activo', true),
                ])
                ->orderBy('codigo')
                ->get(),
            'proyeccion' => $this->proyector->conteos($temporada),
        ];
    }

    /** @param array<string, mixed> $datos */
    public function guardarMarca(array $datos, ?MarcaValidacion $modelo = null): MarcaValidacion
    {
        $cliente = ClienteValidacion::query()->findOrFail($datos['cliente_validacion_id']);
        if ($modelo?->exists && $modelo->cliente()->value('temporada_id') !== $cliente->temporada_id) {
            throw new DomainException('No se puede mover una marca a otra temporada.');
        }
        $nombre = $this->texto($datos['nombre']);
        $this->asegurarUnico(
            MarcaValidacion::query()->where('cliente_validacion_id', $cliente->id)->where('nombre', $nombre),
            $modelo,
            'Esa marca ya existe para el cliente.',
        );

        return $this->guardar($modelo ?? new MarcaValidacion, [
            'cliente_validacion_id' => $cliente->id,
            'nombre' => $nombre,
            'codigo_externo' => $this->codigo($datos['codigo_externo'] ?? null),
            'activo' => (bool) ($datos['activo'] ?? true),
        ], $cliente->temporada_id);
    }

    /** @param array<string, mixed> $datos */
    public function guardarEspecie(array $datos, ?EspecieValidacion $modelo = null): EspecieValidacion
    {
        $this->asegurarMismaTemporada($modelo, $datos['temporada_id']);
        $nombre = $this->texto($datos['nombre']);
        $this->asegurarUnico(
            EspecieValidacion::query()->where('temporada_id', $datos['temporada_id'])->where('nombre', $nombre),
            $modelo,
            'Esa especie ya existe en la temporada.',
        );

        return $this->guardar($modelo ?? new EspecieValidacion, [
            'temporada_id' => $datos['temporada_id'],
            'nombre' => $nombre,
            'codigo_externo' => $this->codigo($datos['codigo_externo'] ?? null),
            'activo' => (bool) ($datos['activo'] ?? true),
        ], $datos['temporada_id']);
    }

    /** @param array<string, mixed> $datos */
    public function guardarCategoria(array $datos, ?CategoriaValidacion $modelo = null): CategoriaValidacion
    {
        $this->asegurarMismaTemporada($modelo, $datos['temporada_id']);
        $nombre = $this->texto($datos['nombre']);
        $this->asegurarUnico(
            CategoriaValidacion::query()
                ->where('temporada_id', $datos['temporada_id'])
                ->where('nombre', $nombre),
            $modelo,
            'Esa categoría ya existe en la temporada.',
        );

        return $this->guardar($modelo ?? new CategoriaValidacion, [
            'temporada_id' => $datos['temporada_id'],
            'nombre' => $nombre,
            'codigo_externo' => $this->codigo($datos['codigo_externo'] ?? null),
            'activo' => (bool) ($datos['activo'] ?? true),
        ], $datos['temporada_id']);
    }

    /** @param array<string, mixed> $datos */
    public function guardarVariedad(array $datos, ?VariedadValidacion $modelo = null): VariedadValidacion
    {
        return $this->guardarHijoEspecie(
            $datos,
            $modelo ?? new VariedadValidacion,
            VariedadValidacion::class,
            'Esa variedad ya existe para la especie.',
        );
    }

    /** @param array<string, mixed> $datos */
    public function guardarCalibre(array $datos, ?CalibreValidacion $modelo = null): CalibreValidacion
    {
        $datos['nombre'] = mb_strtoupper($this->texto($datos['nombre']));

        return $this->guardarHijoEspecie(
            $datos,
            $modelo ?? new CalibreValidacion,
            CalibreValidacion::class,
            'Ese calibre ya existe para la especie.',
        );
    }

    /** @param array<string, mixed> $datos */
    public function guardarEnvase(array $datos, ?EnvaseValidacion $modelo = null): EnvaseValidacion
    {
        $especie = EspecieValidacion::query()->findOrFail($datos['especie_validacion_id']);
        $cliente = ClienteValidacion::query()->findOrFail($datos['cliente_validacion_id']);

        if ($especie->temporada_id !== $cliente->temporada_id) {
            throw new DomainException(
                'El cliente y la especie del envase deben pertenecer a la misma temporada.',
            );
        }

        if ($modelo?->exists
            && $modelo->especie()->value('temporada_id') !== $especie->temporada_id) {
            throw new DomainException('No se puede mover un envase a otra temporada.');
        }

        $nombre = $this->texto($datos['nombre']);
        $this->asegurarUnico(
            EnvaseValidacion::query()
                ->where('especie_validacion_id', $especie->id)
                ->where('cliente_validacion_id', $cliente->id)
                ->where('nombre', $nombre),
            $modelo,
            'Ese envase ya existe para el cliente y la especie.',
        );

        /** @var EnvaseValidacion $envase */
        $envase = $this->guardar($modelo ?? new EnvaseValidacion, [
            'especie_validacion_id' => $especie->id,
            'cliente_validacion_id' => $cliente->id,
            'nombre' => $nombre,
            'codigo_externo' => $this->codigo($datos['codigo_externo'] ?? null),
            'kilos_netos_por_caja' => array_key_exists('kilos_netos_por_caja', $datos) ? $datos['kilos_netos_por_caja'] : $modelo?->kilos_netos_por_caja,
            'activo' => (bool) ($datos['activo'] ?? true),
        ], $especie->temporada_id);

        return $envase->load('cliente:id,nombre,codigo_externo');
    }

    /** @param array<string, mixed> $datos */
    public function guardarCsg(array $datos, ?CsgValidacion $modelo = null): CsgValidacion
    {
        $this->asegurarMismaTemporada($modelo, $datos['temporada_id']);
        $codigo = mb_strtoupper($this->texto($datos['codigo']));
        $this->asegurarUnico(
            CsgValidacion::query()->where('temporada_id', $datos['temporada_id'])->where('codigo', $codigo),
            $modelo,
            'Ese CSG ya existe en la temporada.',
        );

        $variedades = VariedadValidacion::query()
            ->whereIn('id', $datos['variedad_ids'])
            ->whereHas('especie', fn ($query) => $query->where('temporada_id', $datos['temporada_id']))
            ->pluck('id');

        if ($variedades->count() !== count(array_unique($datos['variedad_ids']))) {
            throw new DomainException('Todas las variedades del CSG deben pertenecer a la temporada.');
        }

        return DB::transaction(function () use ($datos, $modelo, $codigo, $variedades): CsgValidacion {
            $modelo ??= new CsgValidacion;
            $modelo->fill([
                'temporada_id' => $datos['temporada_id'],
                'codigo' => $codigo,
                'predio' => $this->opcional($datos['predio'] ?? null),
                'codigo_externo' => $this->codigo($datos['codigo_externo'] ?? null),
                'activo' => (bool) ($datos['activo'] ?? true),
            ]);
            $cambio = ! $modelo->exists || $modelo->isDirty();
            $modelo->save();

            $anteriores = $modelo->variedades()->pluck('variedades_validacion.id')->sort()->values();
            $nuevas = $variedades->sort()->values();
            if ($anteriores->all() !== $nuevas->all()) {
                $modelo->variedades()->sync($nuevas->all());
                $cambio = true;
            }

            if ($cambio) {
                Temporada::query()->whereKey($datos['temporada_id'])->increment('version_catalogo');
                $this->proyector->reconstruir(Temporada::query()->findOrFail($datos['temporada_id']));
            }

            return $modelo->refresh()->load('variedades');
        }, attempts: 3);
    }

    public function eliminar(Model $modelo): Model
    {
        $temporadaId = match ($modelo::class) {
            MarcaValidacion::class => $modelo->cliente()->value('temporada_id'),
            VariedadValidacion::class,
            CalibreValidacion::class,
            EnvaseValidacion::class => $modelo->especie()->value('temporada_id'),
            CategoriaValidacion::class,
            EspecieValidacion::class,
            CsgValidacion::class => $modelo->getAttribute('temporada_id'),
            default => throw new DomainException('Ese tipo de elemento no pertenece al catálogo jerárquico.'),
        };

        if (! is_string($temporadaId) || $temporadaId === '') {
            throw new DomainException('No fue posible determinar la temporada del elemento.');
        }

        return DB::transaction(function () use ($modelo, $temporadaId): Model {
            $bloqueado = $modelo->newQuery()
                ->lockForUpdate()
                ->findOrFail($modelo->getKey());

            if (! (bool) $bloqueado->getAttribute('activo')) {
                return $bloqueado->refresh();
            }

            $bloqueado->forceFill(['activo' => false])->save();
            Temporada::query()->whereKey($temporadaId)->increment('version_catalogo');
            $this->proyector->reconstruir(Temporada::query()->findOrFail($temporadaId));

            return $bloqueado->refresh();
        }, attempts: 3);
    }

    /**
     * @param  array<string, mixed>  $datos
     * @param  class-string<Model>  $clase
     */
    private function guardarHijoEspecie(
        array $datos,
        Model $modelo,
        string $clase,
        string $mensaje,
    ): Model {
        $especie = EspecieValidacion::query()->findOrFail($datos['especie_validacion_id']);
        if ($modelo->exists) {
            $temporadaActual = $modelo->especie()->value('temporada_id');
            if ($temporadaActual !== $especie->temporada_id) {
                throw new DomainException('No se puede mover un elemento a otra temporada.');
            }
        }
        $nombre = $this->texto($datos['nombre']);
        $this->asegurarUnico(
            $clase::query()->where('especie_validacion_id', $especie->id)->where('nombre', $nombre),
            $modelo,
            $mensaje,
        );

        return $this->guardar($modelo, [
            'especie_validacion_id' => $especie->id,
            'nombre' => $nombre,
            'codigo_externo' => $this->codigo($datos['codigo_externo'] ?? null),
            'activo' => (bool) ($datos['activo'] ?? true),
        ], $especie->temporada_id);
    }

    /**
     * @param  array<string, mixed>  $atributos
     *
     * @template T of Model
     *
     * @param  T  $modelo
     * @return T
     */
    private function guardar(Model $modelo, array $atributos, string $temporadaId): Model
    {
        return DB::transaction(function () use ($modelo, $atributos, $temporadaId): Model {
            $modelo->fill($atributos);
            $cambio = ! $modelo->exists || $modelo->isDirty();
            $modelo->save();

            if ($cambio) {
                Temporada::query()->whereKey($temporadaId)->increment('version_catalogo');
                $this->proyector->reconstruir(Temporada::query()->findOrFail($temporadaId));
            }

            return $modelo->refresh();
        }, attempts: 3);
    }

    private function asegurarMismaTemporada(?Model $modelo, string $temporadaId): void
    {
        if ($modelo?->exists && $modelo->getAttribute('temporada_id') !== $temporadaId) {
            throw new DomainException('No se puede mover un elemento a otra temporada.');
        }
    }

    private function asegurarUnico($consulta, ?Model $modelo, string $mensaje): void
    {
        if ($modelo) {
            $consulta->whereKeyNot($modelo->getKey());
        }

        if ($consulta->exists()) {
            throw new DomainException($mensaje);
        }
    }

    private function texto(mixed $valor): string
    {
        return Str::of((string) $valor)->squish()->toString();
    }

    private function opcional(mixed $valor): ?string
    {
        $texto = $this->texto($valor);

        return $texto !== '' ? $texto : null;
    }

    private function codigo(mixed $valor): ?string
    {
        $texto = $this->opcional($valor);

        return $texto !== null ? mb_strtoupper($texto) : null;
    }
}
