<?php

namespace App\Services\Validacion;

use App\Models\ArticuloValidacion;
use App\Models\CategoriaValidacion;
use App\Models\ClienteValidacion;
use App\Models\CombinacionValidacion;
use App\Models\OrigenValidacion;
use App\Models\Temporada;
use App\Services\Clientes\ServicioCliente;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ServicioCatalogoValidacion
{
    public function __construct(
        private readonly ServicioCliente $clientes,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function datosAdministracion(?Temporada $seleccionada = null, bool $resumen = false): array
    {
        $temporadas = Temporada::query()
            ->when(! $resumen, fn ($consulta) => $consulta->withCount(['articulos', 'origenes', 'combinaciones']))
            ->orderByDesc('activa')
            ->orderByDesc('fecha_inicio')
            ->orderByDesc('created_at')
            ->get();

        $temporada = $seleccionada
            ?? $temporadas->firstWhere('activa', true)
            ?? $temporadas->first();

        // Maestros utiliza la jerarquía: no necesita descargar la proyección
        // cartesiana para la PDA ni las filas completas de las importaciones.
        if ($resumen) {
            return [
                'temporadas' => $temporadas->values(),
                'temporada' => $temporada,
                'importaciones' => $temporada
                    ? $temporada->importaciones()
                        ->select(['id', 'temporada_id', 'nombre_archivo', 'estado', 'resumen', 'created_at'])
                        ->latest()
                        ->limit(10)
                        ->get()
                    : [],
            ];
        }

        if (! $temporada) {
            return [
                'temporadas' => [],
                'temporada' => null,
                'articulos' => [],
                'origenes' => [],
                'categorias' => [],
                'combinaciones' => [],
                'importaciones' => [],
            ];
        }

        return [
            'temporadas' => $temporadas->values(),
            'temporada' => $temporada,
            'articulos' => ArticuloValidacion::query()
                ->where('temporada_id', $temporada->id)
                ->withCount('combinaciones')
                ->orderBy('especie')
                ->orderBy('variedad')
                ->orderBy('calibre')
                ->orderBy('envase')
                ->get(),
            'origenes' => OrigenValidacion::query()
                ->where('temporada_id', $temporada->id)
                ->withCount('combinaciones')
                ->orderBy('cliente')
                ->orderBy('marca')
                ->orderBy('csg')
                ->get(),
            'categorias' => CategoriaValidacion::query()
                ->where('temporada_id', $temporada->id)
                ->orderByDesc('activo')
                ->orderBy('nombre')
                ->get(),
            'combinaciones' => CombinacionValidacion::query()
                ->where('temporada_id', $temporada->id)
                ->with([
                    'articulo:id,especie,variedad,calibre,envase,activo',
                    'origen:id,cliente,marca,csg,predio,activo',
                ])
                ->latest('activo')
                ->latest('updated_at')
                ->get(),
            'importaciones' => $temporada->importaciones()
                ->with(['creadoPor:id,name', 'confirmadoPor:id,name'])
                ->latest()
                ->limit(10)
                ->get(),
        ];
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function guardarArticulo(array $datos, ?ArticuloValidacion $articulo = null): ArticuloValidacion
    {
        $atributos = [
            'temporada_id' => $datos['temporada_id'],
            'especie' => Str::of($datos['especie'])->squish()->toString(),
            'variedad' => Str::of($datos['variedad'])->squish()->toString(),
            'calibre' => mb_strtoupper(Str::of($datos['calibre'])->squish()->toString()),
            'envase' => Str::of($datos['envase'])->squish()->toString(),
        ];

        $duplicado = ArticuloValidacion::query()
            ->where($atributos)
            ->when($articulo, fn ($consulta) => $consulta->whereKeyNot($articulo->id))
            ->exists();

        if ($duplicado) {
            throw new DomainException('Esa combinación de artículo ya existe en la temporada.');
        }

        return $this->guardarCatalogo(
            $articulo ?? new ArticuloValidacion,
            [
                ...$atributos,
                'codigo_externo' => $this->codigoOpcional($datos['codigo_externo'] ?? null),
                'activo' => (bool) ($datos['activo'] ?? true),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function guardarOrigen(array $datos, ?OrigenValidacion $origen = null): OrigenValidacion
    {
        $clienteGlobal = $this->clientes->buscarPorReferencia((string) $datos['cliente']);
        $clienteTemporada = $clienteGlobal
            ? ClienteValidacion::query()
                ->where('temporada_id', $datos['temporada_id'])
                ->where('cliente_id', $clienteGlobal->id)
                ->where('activo', true)
                ->first()
            : null;
        if (! $clienteGlobal || ! $clienteGlobal->activo || ! $clienteTemporada) {
            throw new DomainException(
                'El cliente debe existir y estar activo en el maestro global de Accesos.',
            );
        }

        $atributos = [
            'temporada_id' => $datos['temporada_id'],
            'cliente_validacion_id' => $clienteTemporada->id,
            'cliente' => $clienteGlobal->nombre,
            'marca' => Str::of($datos['marca'])->squish()->toString(),
            'csg' => mb_strtoupper(Str::of($datos['csg'])->squish()->toString()),
        ];

        $duplicado = OrigenValidacion::query()
            ->where($atributos)
            ->when($origen, fn ($consulta) => $consulta->whereKeyNot($origen->id))
            ->exists();

        if ($duplicado) {
            throw new DomainException('Ese origen comercial ya existe en la temporada.');
        }

        return $this->guardarCatalogo(
            $origen ?? new OrigenValidacion,
            [
                ...$atributos,
                'predio' => $this->textoOpcional($datos['predio'] ?? null),
                'codigo_externo' => $this->codigoOpcional($datos['codigo_externo'] ?? null),
                'activo' => (bool) ($datos['activo'] ?? true),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function guardarCombinacion(
        array $datos,
        ?CombinacionValidacion $combinacion = null,
    ): CombinacionValidacion {
        $articulo = ArticuloValidacion::query()->findOrFail($datos['articulo_validacion_id']);
        $origen = OrigenValidacion::query()->findOrFail($datos['origen_validacion_id']);

        if ($articulo->temporada_id !== $datos['temporada_id']
            || $origen->temporada_id !== $datos['temporada_id']) {
            throw new DomainException('El artículo y el origen deben pertenecer a la misma temporada.');
        }

        if ($articulo->cliente_validacion_id !== null
            && $articulo->cliente_validacion_id !== $origen->cliente_validacion_id) {
            throw new DomainException(
                'El envase del artículo no está habilitado para el cliente del origen.',
            );
        }

        $atributos = [
            'temporada_id' => $datos['temporada_id'],
            'articulo_validacion_id' => $articulo->id,
            'origen_validacion_id' => $origen->id,
        ];
        $duplicada = CombinacionValidacion::query()
            ->where($atributos)
            ->when($combinacion, fn ($consulta) => $consulta->whereKeyNot($combinacion->id))
            ->exists();

        if ($duplicada) {
            throw new DomainException('La combinación artículo–origen ya se encuentra habilitada.');
        }

        return $this->guardarCatalogo(
            $combinacion ?? new CombinacionValidacion,
            [
                ...$atributos,
                'codigo_externo' => $this->codigoOpcional($datos['codigo_externo'] ?? null),
                'activo' => (bool) ($datos['activo'] ?? true),
            ],
            ['articulo', 'origen'],
        );
    }

    /**
     * @param  array<string, mixed>  $atributos
     * @param  array<int, string>  $relaciones
     */
    private function guardarCatalogo(Model $modelo, array $atributos, array $relaciones = []): Model
    {
        return DB::transaction(function () use ($modelo, $atributos, $relaciones): Model {
            $modelo->fill($atributos);
            $cambio = ! $modelo->exists || $modelo->isDirty();
            $modelo->save();

            if ($cambio) {
                Temporada::query()
                    ->whereKey($atributos['temporada_id'])
                    ->increment('version_catalogo');
            }

            return $modelo->refresh()->load($relaciones);
        });
    }

    private function textoOpcional(mixed $valor): ?string
    {
        $texto = Str::of((string) $valor)->squish()->toString();

        return $texto !== '' ? $texto : null;
    }

    private function codigoOpcional(mixed $valor): ?string
    {
        $texto = $this->textoOpcional($valor);

        return $texto !== null ? mb_strtoupper($texto) : null;
    }
}

