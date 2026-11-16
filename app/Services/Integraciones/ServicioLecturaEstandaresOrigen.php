<?php

namespace App\Services\Integraciones;

use App\Enums\RedondeoEstandarMaterial;
use App\Exceptions\ConflictoOperacion;
use App\Models\ConexionExterna;
use App\Models\ConfiguracionOrigen;
use App\Models\EstandarOrigen;
use App\Models\LecturaEstandarOrigen;
use App\Models\User;
use App\Services\Temporadas\ServicioTemporadaActiva;
use DomainException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Lee la vista de estándares de materiales del sistema de planta (odbc_estandar_materiales)
 * y guarda una copia por empresa y artículo. Solo SELECT de columnas fijas, desde el servidor
 * de FoliOS; las columnas de creador y modificador (correos) no se leen.
 *
 * La vista no trae el identificador del estándar ni sus variaciones: un mismo artículo puede
 * aparecer con varias cabeceras que solo se distinguen por la fecha de creación. Se toma la
 * más reciente y se informan las descartadas; las filas repetidas exactas se cuentan una vez
 * y un insumo repetido dentro de la cabecera se suma.
 */
class ServicioLecturaEstandaresOrigen
{
    public const TABLA_PREDETERMINADA = 'odbc_estandar_materiales';

    public const MAX_FILAS = 50000;

    public const COLUMNAS = [
        'cod_empresa', 'cod_temporada', 'codigo_estandar', 'descripcion_estandar', 'cod_articulo', 'descripcion_articulo',
        'cod_insumo', 'descripcion_insumo', 'tipo_rebaja_insumo', 'cantidad', 'cod_especie', 'desc_especie',
        'fecha_creacion', 'fecha_edicion',
    ];

    public function __construct(
        private readonly ConectorMySqlLectura $conector,
        private readonly ServicioTemporadaActiva $temporadas,
    ) {}

    /** @return array{conexion: ?ConexionExterna, conexion_id: ?string, tabla: string, temporada_origen: ?string, empresas: list<string>, habilitada: bool, version: int} */
    public function configuracion(): array
    {
        $perfil = ConfiguracionOrigen::find(ConfiguracionOrigen::ESTANDARES);
        $ajustes = $perfil?->ajustes ?? [];
        $conexion = $perfil ? ConexionExterna::find($perfil->conexion_externa_id) : null;

        return [
            'conexion' => $conexion?->activa ? $conexion : null,
            'conexion_id' => $perfil?->conexion_externa_id,
            'tabla' => (string) ($ajustes['tabla'] ?? self::TABLA_PREDETERMINADA),
            'temporada_origen' => filled($ajustes['temporada_origen'] ?? null) ? (string) $ajustes['temporada_origen'] : null,
            'empresas' => array_values($ajustes['empresas'] ?? []),
            'habilitada' => (bool) ($ajustes['habilitada'] ?? false),
            'version' => $perfil?->version ?? 0,
        ];
    }

    /** @param array<string, mixed> $config */
    public function huella(array $config): string
    {
        return hash('sha256', json_encode([
            $config['conexion']?->id, $config['conexion']?->version, $config['tabla'],
            $config['temporada_origen'], $config['empresas'], $config['habilitada'], $config['version'],
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array{conexion_id: string, tabla: string, temporada_origen: string, empresas?: list<string>, habilitada: bool, version_conocida: int}  $datos
     * @return array<string, mixed>
     */
    public function guardarConfiguracion(array $datos, User $usuario): array
    {
        ExploradorMySql::identificador($datos['tabla']);

        return DB::transaction(function () use ($datos, $usuario): array {
            $perfil = ConfiguracionOrigen::query()->whereKey(ConfiguracionOrigen::ESTANDARES)->lockForUpdate()->first();
            if (($perfil?->version ?? 0) !== (int) $datos['version_conocida']) {
                throw new ConflictoOperacion('La configuración cambió. Vuelve a abrirla antes de guardar.');
            }
            $conexion = ConexionExterna::query()->whereKey($datos['conexion_id'])->where('activa', true)->first()
                ?? throw new DomainException('Elige una conexión externa activa.');
            $empresas = collect($datos['empresas'] ?? [])->map(fn ($valor): string => mb_strtoupper(Str::squish((string) $valor)))
                ->filter()->unique()->values()->all();
            $perfil ??= new ConfiguracionOrigen(['clave' => ConfiguracionOrigen::ESTANDARES]);
            $perfil->fill([
                'conexion_externa_id' => $conexion->id,
                'ajustes' => [
                    'tabla' => $datos['tabla'],
                    'temporada_origen' => mb_strtoupper(Str::squish($datos['temporada_origen'])),
                    'empresas' => $empresas,
                    'habilitada' => (bool) $datos['habilitada'],
                ],
                'version' => ($perfil->version ?? 0) + 1,
                'actualizado_por_user_id' => $usuario->id,
            ])->save();
            $conexion->eventos()->create([
                'user_id' => $usuario->id, 'accion' => 'estandares_configurados',
                'campos_modificados' => ['tabla', 'temporada_origen', 'empresas', 'habilitada'],
            ]);

            return $this->configuracion();
        }, 3);
    }

    /** Lee la vista y reemplaza la copia. Una lectura fallida conserva la última copia correcta. */
    public function leer(?User $usuario = null, ?string $operacionId = null): LecturaEstandarOrigen
    {
        $config = $this->configuracion();
        if (! $config['habilitada'] || ! $config['conexion'] || ! $config['temporada_origen']) {
            throw new DomainException('Configura y habilita primero la lectura de estándares de planta.');
        }

        try {
            return Cache::lock('lectura-estandares-origen', 300)->block(0, fn () => $this->ejecutar($config, $usuario, $operacionId));
        } catch (LockTimeoutException) {
            throw new ConflictoOperacion('Otra lectura de estándares está en curso. Se conserva la última copia correcta.');
        }
    }

    /** @return array{0: string, 1: list<string>} */
    public function consulta(array $config): array
    {
        $tabla = ExploradorMySql::identificador($config['tabla']);
        $seleccion = collect(self::COLUMNAS)
            ->map(fn (string $columna): string => 'LEFT(CAST('.ExploradorMySql::identificador($columna).' AS CHAR), 501) AS '.ExploradorMySql::identificador($columna))
            ->implode(', ');
        $where = ' WHERE '.ExploradorMySql::identificador('cod_temporada').' = ?';
        $parametros = [$config['temporada_origen']];
        if ($config['empresas'] !== []) {
            $where .= ' AND '.ExploradorMySql::identificador('cod_empresa').' IN ('.implode(', ', array_fill(0, count($config['empresas']), '?')).')';
            array_push($parametros, ...$config['empresas']);
        }

        return ['SELECT '.$seleccion.' FROM '.$tabla.$where.' LIMIT '.(self::MAX_FILAS + 1), $parametros];
    }

    /**
     * Agrupa las filas de la vista en un estándar por empresa y artículo.
     *
     * @param  list<array<string, mixed>>  $filas
     * @return array{estandares: list<array<string, mixed>>, filas: int, duplicadas: int, descartadas: int, invalidas: int}
     */
    public static function agrupar(array $filas): array
    {
        $vistas = [];
        $duplicadas = 0;
        $invalidas = 0;
        $porArticulo = [];
        foreach ($filas as $fila) {
            $fila = array_change_key_case($fila, CASE_LOWER);
            $dato = fn (string $columna): ?string => ($texto = Str::squish((string) ($fila[$columna] ?? ''))) === '' ? null : $texto;
            $empresa = $dato('cod_empresa');
            $articulo = $dato('cod_articulo');
            $insumo = $dato('cod_insumo');
            $cantidad = $dato('cantidad');
            if ($empresa === null || $articulo === null || $insumo === null || ! is_numeric($cantidad) || (float) $cantidad <= 0) {
                $invalidas++;

                continue;
            }
            $normalizada = array_map(fn (string $columna): ?string => $dato($columna), self::COLUMNAS);
            $firma = hash('sha256', json_encode($normalizada, JSON_THROW_ON_ERROR));
            if (isset($vistas[$firma])) {
                $duplicadas++;

                continue;
            }
            $vistas[$firma] = true;
            $empresa = mb_strtoupper($empresa);
            $cabecera = implode('|', [$dato('fecha_creacion'), $dato('codigo_estandar'), $dato('descripcion_estandar')]);
            $porArticulo[$empresa."\u{1F}".$articulo][$cabecera][] = [
                'empresa' => $empresa,
                'articulo' => $articulo,
                'codigo_estandar' => $dato('codigo_estandar'),
                'descripcion_estandar' => $dato('descripcion_estandar'),
                'descripcion_articulo' => $dato('descripcion_articulo'),
                'cod_especie' => $dato('cod_especie'),
                'desc_especie' => $dato('desc_especie'),
                'fecha_creacion' => $dato('fecha_creacion'),
                'fecha_edicion' => $dato('fecha_edicion'),
                'cod_insumo' => $insumo,
                'descripcion_insumo' => $dato('descripcion_insumo') ?? $insumo,
                'redondeo' => (RedondeoEstandarMaterial::desdeTexto($dato('tipo_rebaja_insumo')) ?? RedondeoEstandarMaterial::Decimal)->value,
                'cantidad' => round((float) $cantidad, 6),
            ];
        }

        $descartadas = 0;
        $estandares = [];
        foreach ($porArticulo as $cabeceras) {
            // La cabecera vigente es la creada más tarde; a igual creación, la editada más tarde.
            uasort($cabeceras, fn (array $a, array $b): int => [$b[0]['fecha_creacion'] ?? '', $b[0]['fecha_edicion'] ?? '']
                <=> [$a[0]['fecha_creacion'] ?? '', $a[0]['fecha_edicion'] ?? '']);
            $descartadas += count($cabeceras) - 1;
            $filasCabecera = reset($cabeceras);
            $primera = $filasCabecera[0];
            $lineas = [];
            foreach ($filasCabecera as $linea) {
                $clave = mb_strtoupper($linea['cod_insumo']);
                if (isset($lineas[$clave])) {
                    $lineas[$clave]['cantidad'] = round($lineas[$clave]['cantidad'] + $linea['cantidad'], 6);
                    $lineas[$clave]['repetido']++;

                    continue;
                }
                $lineas[$clave] = [
                    'cod_insumo' => $linea['cod_insumo'],
                    'descripcion' => mb_substr($linea['descripcion_insumo'], 0, 200),
                    'redondeo' => $linea['redondeo'],
                    'cantidad' => $linea['cantidad'],
                    'repetido' => 1,
                ];
            }
            $lineas = array_values(array_map(fn (array $linea): array => [...$linea, 'cantidad' => number_format($linea['cantidad'], 6, '.', '')], $lineas));
            $estandar = [
                'cod_empresa' => mb_substr($primera['empresa'], 0, 40),
                'cod_articulo' => mb_substr($primera['articulo'], 0, 100),
                'codigo_estandar' => self::corto($primera['codigo_estandar'], 100),
                'descripcion_estandar' => self::corto($primera['descripcion_estandar'], 200),
                'descripcion_articulo' => self::corto($primera['descripcion_articulo'], 200),
                'cod_especie' => self::corto($primera['cod_especie'], 40),
                'desc_especie' => self::corto($primera['desc_especie'], 100),
                'fecha_creacion' => self::fecha($primera['fecha_creacion']),
                'fecha_edicion' => self::fecha($primera['fecha_edicion']),
                'cabeceras' => count($cabeceras),
                'lineas' => $lineas,
            ];
            $estandar['huella'] = hash('sha256', json_encode([
                $estandar['codigo_estandar'], $estandar['descripcion_estandar'], $estandar['descripcion_articulo'],
                collect($lineas)->sortBy('cod_insumo')->values()->all(),
            ], JSON_THROW_ON_ERROR));
            $estandares[] = $estandar;
        }
        usort($estandares, fn (array $a, array $b): int => [$a['cod_empresa'], $a['cod_articulo']] <=> [$b['cod_empresa'], $b['cod_articulo']]);

        return ['estandares' => $estandares, 'filas' => count($filas), 'duplicadas' => $duplicadas, 'descartadas' => $descartadas, 'invalidas' => $invalidas];
    }

    /** @param array<string, mixed> $config */
    private function ejecutar(array $config, ?User $usuario, ?string $operacionId): LecturaEstandarOrigen
    {
        $base = [
            'temporada_id' => $this->temporadas->buscar()?->id,
            'temporada_origen' => $config['temporada_origen'],
            'conexion_externa_id' => $config['conexion']->id,
            'operacion_integracion_id' => $operacionId,
            'user_id' => $usuario?->id,
            'leida_at' => now(),
        ];

        try {
            [$sql, $parametros] = $this->consulta($config);
            $filas = $this->conector->consultar($config['conexion']->credenciales, $sql, $parametros, 60);
            if (count($filas) > self::MAX_FILAS) {
                throw new DomainException('La vista trae más de 50.000 filas para esa temporada. Acota la lectura por empresa.');
            }
            $resultado = self::agrupar($filas);
            if ($resultado['estandares'] === []) {
                throw new DomainException("La vista no trae estándares para la temporada {$config['temporada_origen']}. Se conserva la última copia.");
            }
        } catch (Throwable $error) {
            $mensaje = $error instanceof DomainException ? $error->getMessage() : ConectorMySqlLectura::diagnosticar($error)['mensaje'];

            return LecturaEstandarOrigen::create([...$base, 'estado' => 'fallida', 'mensaje' => mb_substr($mensaje, 0, 500)]);
        }

        return DB::transaction(function () use ($base, $resultado, $config, $operacionId): LecturaEstandarOrigen {
            if ($this->huella($this->configuracion()) !== $this->huella($config)) {
                throw new ConflictoOperacion('La configuración cambió durante la lectura. Se conserva la última copia.');
            }
            app(ServicioOperacionesIntegracion::class)->exigirVigente($operacionId);
            $lectura = LecturaEstandarOrigen::create([
                ...$base,
                'estado' => 'correcta',
                'filas' => $resultado['filas'],
                'estandares' => count($resultado['estandares']),
                'filas_duplicadas' => $resultado['duplicadas'],
                'cabeceras_descartadas' => $resultado['descartadas'],
                'mensaje' => $resultado['invalidas'] > 0 ? "{$resultado['invalidas']} filas sin empresa, artículo, insumo o cantidad positiva no se consideraron." : null,
            ]);
            $ahora = now();
            foreach (array_chunk($resultado['estandares'], 200) as $bloque) {
                EstandarOrigen::query()->insert(array_map(fn (array $estandar): array => [
                    ...$estandar,
                    'id' => (string) Str::uuid(),
                    'lectura_estandar_origen_id' => $lectura->id,
                    'lineas' => json_encode($estandar['lineas'], JSON_THROW_ON_ERROR),
                    'created_at' => $ahora,
                    'updated_at' => $ahora,
                ], $bloque));
            }
            // Solo la última copia correcta queda disponible; las lecturas anteriores conservan su resumen.
            EstandarOrigen::query()->where('lectura_estandar_origen_id', '!=', $lectura->id)->delete();

            return $lectura;
        }, 3);
    }

    private static function fecha(?string $valor): ?string
    {
        if ($valor === null || ! preg_match('/^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}(?::\d{2})?)?/', $valor, $coincidencia)) {
            return null;
        }

        return strlen($coincidencia[0]) === 10 ? $coincidencia[0].' 00:00:00' : str_replace('T', ' ', substr($coincidencia[0].':00', 0, 19));
    }

    private static function corto(?string $valor, int $largo): ?string
    {
        return $valor === null ? null : mb_substr($valor, 0, $largo);
    }
}
