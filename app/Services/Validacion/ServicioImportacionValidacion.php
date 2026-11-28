<?php

namespace App\Services\Validacion;

use App\Models\ArticuloValidacion;
use App\Models\CalibreValidacion;
use App\Models\CategoriaValidacion;
use App\Models\ClienteValidacion;
use App\Models\CombinacionValidacion;
use App\Models\CsgValidacion;
use App\Models\EnvaseValidacion;
use App\Models\EspecieValidacion;
use App\Models\ImportacionValidacion;
use App\Models\MarcaValidacion;
use App\Models\OrigenValidacion;
use App\Models\Temporada;
use App\Models\User;
use App\Models\VariedadValidacion;
use App\Services\Clientes\ServicioCliente;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ServicioImportacionValidacion
{
    public function __construct(
        private readonly LectorPlanillaValidacion $lector,
        private readonly ServicioProyeccionCatalogoValidacion $proyector,
        private readonly ServicioCliente $clientes,
        private readonly BloqueoCatalogoValidacion $bloqueo,
    ) {}

    public function previsualizar(
        UploadedFile $archivo,
        Temporada $temporada,
        User $usuario,
    ): ImportacionValidacion {
        $filasLeidas = $this->lector->leer($archivo);

        if ($filasLeidas === []) {
            throw new DomainException('La planilla no contiene filas de datos para importar.');
        }

        $errores = [];
        $filas = [];
        $vistas = [];

        foreach ($filasLeidas as $fila) {
            $normalizada = $this->normalizarFila($fila);
            $faltantes = collect([
                'especie',
                'variedad',
                'calibre',
                'envase',
                'cliente',
                'marca',
                'csg',
            ])->filter(fn (string $campo): bool => ($normalizada[$campo] ?? '') === '')->values();

            if ($faltantes->isNotEmpty()) {
                $errores[] = [
                    'fila' => $normalizada['fila'],
                    'mensaje' => 'Faltan campos obligatorios: '.$faltantes->implode(', ').'.',
                ];

                continue;
            }

            $clienteGlobal = $this->clientes->buscarPorReferencia((string) $normalizada['cliente']);
            $clienteTemporada = $clienteGlobal
                ? ClienteValidacion::query()
                    ->where('temporada_id', $temporada->id)
                    ->where('cliente_id', $clienteGlobal->id)
                    ->where('activo', true)
                    ->first()
                : null;
            if (! $clienteGlobal || ! $clienteGlobal->activo || ! $clienteTemporada) {
                $errores[] = [
                    'fila' => $normalizada['fila'],
                    'mensaje' => "El cliente {$normalizada['cliente']} no está activo en el maestro global de Accesos.",
                ];

                continue;
            }
            $normalizada['cliente'] = $clienteGlobal->nombre;
            $normalizada['cliente_id'] = $clienteGlobal->id;

            $clave = $this->claveFila($normalizada);
            if (isset($vistas[$clave])) {
                $errores[] = [
                    'fila' => $normalizada['fila'],
                    'mensaje' => sprintf(
                        'La combinación ya fue declarada en la fila %d.',
                        $vistas[$clave],
                    ),
                ];

                continue;
            }

            $vistas[$clave] = $normalizada['fila'];
            $filas[] = $normalizada;
        }

        $resumen = $this->calcularResumen($temporada, $filas, $errores);
        $checksum = hash_file('sha256', $archivo->getRealPath());

        return ImportacionValidacion::create([
            'temporada_id' => $temporada->id,
            'nombre_archivo' => $archivo->getClientOriginalName(),
            'tipo_archivo' => mb_strtolower($archivo->getClientOriginalExtension()),
            'checksum' => $checksum,
            'estado' => $errores === [] ? 'borrador' : 'con_errores',
            'resumen' => $resumen,
            'filas' => $filas,
            'errores' => $errores !== [] ? $errores : null,
            'creado_por_user_id' => $usuario->id,
        ])->load('temporada:id,codigo,nombre,version_catalogo');
    }

    public function confirmar(ImportacionValidacion $importacion, User $usuario): ImportacionValidacion
    {
        return DB::transaction(function () use ($importacion, $usuario): ImportacionValidacion {
            $importacion = ImportacionValidacion::query()
                ->lockForUpdate()
                ->findOrFail($importacion->id);

            if ($importacion->estado === 'confirmada') {
                return $importacion->load('temporada:id,codigo,nombre,version_catalogo');
            }

            if (($importacion->errores ?? []) !== []) {
                throw new DomainException('La importación posee errores y no puede confirmarse.');
            }

            if (($importacion->filas ?? []) === []) {
                throw new DomainException('La importación no contiene filas de datos y no puede confirmarse.');
            }

            $temporada = $this->bloqueo->adquirir($importacion->temporada_id);
            // La reconstrucción conserva todas las filas históricas. Contar también
            // las inactivas distingue registros nuevos de reactivaciones.
            $proyeccionAnterior = $this->proyector->conteos($temporada, soloActivos: false);
            $claves = [
                'clientes', 'marcas', 'categorias', 'especies', 'variedades',
                'calibres', 'envases', 'csg', 'autorizaciones_csg',
                'articulos', 'origenes', 'combinaciones',
            ];
            $creados = array_fill_keys($claves, 0);
            $actualizados = array_fill_keys($claves, 0);

            foreach ($importacion->filas as $fila) {
                if (($fila['categoria'] ?? '') !== '') {
                    $this->persistir(
                        CategoriaValidacion::query()->firstOrNew([
                            'temporada_id' => $temporada->id,
                            'nombre' => $fila['categoria'],
                        ]),
                        ['activo' => true],
                        'categorias',
                        $creados,
                        $actualizados,
                    );
                }

                $especie = $this->persistir(
                    EspecieValidacion::query()->firstOrNew([
                        'temporada_id' => $temporada->id,
                        'nombre' => $fila['especie'],
                    ]),
                    ['activo' => true],
                    'especies',
                    $creados,
                    $actualizados,
                );
                $variedad = $this->persistir(
                    VariedadValidacion::query()->firstOrNew([
                        'especie_validacion_id' => $especie->id,
                        'nombre' => $fila['variedad'],
                    ]),
                    ['activo' => true],
                    'variedades',
                    $creados,
                    $actualizados,
                );
                $calibre = $this->persistir(
                    CalibreValidacion::query()->firstOrNew([
                        'especie_validacion_id' => $especie->id,
                        'nombre' => $fila['calibre'],
                    ]),
                    ['activo' => true],
                    'calibres',
                    $creados,
                    $actualizados,
                );
                $cliente = ClienteValidacion::query()
                    ->where('temporada_id', $temporada->id)
                    ->where('cliente_id', $fila['cliente_id'])
                    ->where('activo', true)
                    ->firstOrFail();
                $this->persistir(
                    EnvaseValidacion::query()->firstOrNew([
                        'especie_validacion_id' => $especie->id,
                        'cliente_validacion_id' => $cliente->id,
                        'nombre' => $fila['envase'],
                    ]),
                    ['activo' => true],
                    'envases',
                    $creados,
                    $actualizados,
                );
                $marca = $this->persistir(
                    MarcaValidacion::query()->firstOrNew([
                        'cliente_validacion_id' => $cliente->id,
                        'nombre' => $fila['marca'],
                    ]),
                    ['activo' => true],
                    'marcas',
                    $creados,
                    $actualizados,
                );
                $csgModelo = CsgValidacion::query()->firstOrNew([
                    'temporada_id' => $temporada->id,
                    'codigo' => $fila['csg'],
                ]);
                $csg = $this->persistir(
                    $csgModelo,
                    [
                        'predio' => $fila['predio'] ?: $csgModelo->predio,
                        'activo' => true,
                    ],
                    'csg',
                    $creados,
                    $actualizados,
                );

                $cambioPivot = $csg->variedades()->syncWithoutDetaching([$variedad->id]);
                if ($cambioPivot['attached'] !== []) {
                    $creados['autorizaciones_csg'] += count($cambioPivot['attached']);
                }
            }

            $this->proyector->reconstruir($temporada);

            foreach ($this->proyector->conteos($temporada, soloActivos: false) as $clave => $total) {
                $creados[$clave] = $total - $proyeccionAnterior[$clave];
            }

            foreach ($importacion->filas as $fila) {
                if (! $fila['codigo_articulo'] && ! $fila['codigo_origen'] && ! $fila['codigo_combinacion']) {
                    continue;
                }
                $cliente = ClienteValidacion::query()
                    ->where('temporada_id', $temporada->id)
                    ->where('cliente_id', $fila['cliente_id'])
                    ->firstOrFail();
                $articulo = ArticuloValidacion::query()->where([
                    'temporada_id' => $temporada->id,
                    'cliente_validacion_id' => $cliente->id,
                    'especie' => $fila['especie'],
                    'variedad' => $fila['variedad'],
                    'calibre' => $fila['calibre'],
                    'envase' => $fila['envase'],
                ])->firstOrFail();
                $origen = OrigenValidacion::query()->where([
                    'temporada_id' => $temporada->id,
                    'cliente' => $fila['cliente'],
                    'marca' => $fila['marca'],
                    'csg' => $fila['csg'],
                ])->firstOrFail();

                if ($fila['codigo_articulo']) {
                    $articulo->update(['codigo_externo' => $fila['codigo_articulo']]);
                }
                if ($fila['codigo_origen']) {
                    $origen->update(['codigo_externo' => $fila['codigo_origen']]);
                }
                if ($fila['codigo_combinacion']) {
                    CombinacionValidacion::query()->where([
                        'temporada_id' => $temporada->id,
                        'articulo_validacion_id' => $articulo->id,
                        'origen_validacion_id' => $origen->id,
                    ])->update(['codigo_externo' => $fila['codigo_combinacion']]);
                }
            }

            $this->bloqueo->publicar($temporada);

            $importacion->update([
                'estado' => 'confirmada',
                'resumen' => [
                    ...$importacion->resumen,
                    'creados' => $creados,
                    'actualizados' => $actualizados,
                    'version_catalogo_resultante' => $temporada->version_catalogo,
                ],
                'confirmado_por_user_id' => $usuario->id,
                'confirmado_at' => now(),
            ]);

            return $importacion->refresh()->load('temporada:id,codigo,nombre,version_catalogo');
        }, attempts: 3);
    }

    /**
     * @param  array<string, mixed>  $atributos
     * @param  array<string, int>  $creados
     * @param  array<string, int>  $actualizados
     */
    private function persistir(
        Model $modelo,
        array $atributos,
        string $clave,
        array &$creados,
        array &$actualizados,
    ): Model {
        $nuevo = ! $modelo->exists;
        $modelo->fill($atributos);
        $cambio = $nuevo || $modelo->isDirty();
        $modelo->save();

        if ($cambio) {
            $nuevo ? $creados[$clave]++ : $actualizados[$clave]++;
        }

        return $modelo;
    }

    /**
     * @param  array<string, string|int>  $fila
     * @return array<string, string|int>
     */
    private function normalizarFila(array $fila): array
    {
        return [
            'fila' => (int) $fila['fila'],
            'categoria' => $this->texto($fila['categoria'] ?? ''),
            'especie' => $this->texto($fila['especie'] ?? ''),
            'variedad' => $this->texto($fila['variedad'] ?? ''),
            'calibre' => mb_strtoupper($this->texto($fila['calibre'] ?? '')),
            'envase' => $this->texto($fila['envase'] ?? ''),
            'cliente' => $this->texto($fila['cliente'] ?? ''),
            'marca' => $this->texto($fila['marca'] ?? ''),
            'csg' => mb_strtoupper($this->texto($fila['csg'] ?? '')),
            'predio' => $this->texto($fila['predio'] ?? ''),
            'codigo_articulo' => mb_strtoupper($this->texto($fila['codigo_articulo'] ?? '')),
            'codigo_origen' => mb_strtoupper($this->texto($fila['codigo_origen'] ?? '')),
            'codigo_combinacion' => mb_strtoupper($this->texto($fila['codigo_combinacion'] ?? '')),
        ];
    }

    private function texto(mixed $valor): string
    {
        return Str::of((string) $valor)->squish()->toString();
    }

    /**
     * @param  array<string, string|int>  $fila
     */
    private function claveFila(array $fila): string
    {
        return mb_strtolower(implode('|', [
            $fila['categoria'],
            $fila['especie'],
            $fila['variedad'],
            $fila['calibre'],
            $fila['envase'],
            $fila['cliente'],
            $fila['marca'],
            $fila['csg'],
        ]));
    }

    /**
     * @param  array<int, array<string, string|int>>  $filas
     * @param  array<int, array<string, string|int>>  $errores
     * @return array<string, int>
     */
    private function calcularResumen(Temporada $temporada, array $filas, array $errores): array
    {
        $articulos = collect($filas)->unique(fn (array $fila): string => mb_strtolower(implode('|', [
            $fila['cliente_id'],
            $fila['especie'],
            $fila['variedad'],
            $fila['calibre'],
            $fila['envase'],
        ])));
        $origenes = collect($filas)->unique(fn (array $fila): string => mb_strtolower(implode('|', [
            $fila['cliente'],
            $fila['marca'],
            $fila['csg'],
        ])));

        $articulosNuevos = $articulos->filter(function (array $fila) use ($temporada): bool {
            $clienteId = ClienteValidacion::query()
                ->where('temporada_id', $temporada->id)
                ->where('cliente_id', $fila['cliente_id'])
                ->value('id');

            return ! ArticuloValidacion::query()->where([
                'temporada_id' => $temporada->id,
                'cliente_validacion_id' => $clienteId,
                'especie' => $fila['especie'],
                'variedad' => $fila['variedad'],
                'calibre' => $fila['calibre'],
                'envase' => $fila['envase'],
            ])->exists();
        })->count();
        $origenesNuevos = $origenes->filter(function (array $fila) use ($temporada): bool {
            return ! OrigenValidacion::query()->where([
                'temporada_id' => $temporada->id,
                'cliente' => $fila['cliente'],
                'marca' => $fila['marca'],
                'csg' => $fila['csg'],
            ])->exists();
        })->count();

        return [
            'filas_validas' => count($filas),
            'filas_con_error' => count($errores),
            'articulos_detectados' => $articulos->count(),
            'articulos_nuevos_estimados' => $articulosNuevos,
            'origenes_detectados' => $origenes->count(),
            'origenes_nuevos_estimados' => $origenesNuevos,
            'combinaciones_detectadas' => count($filas),
        ];
    }
}
