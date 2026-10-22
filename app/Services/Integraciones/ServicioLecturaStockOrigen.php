<?php

namespace App\Services\Integraciones;

use App\Models\ConexionExterna;
use App\Models\ConfiguracionOrigen;
use App\Models\LecturaStockOrigen;
use App\Models\PalletStockOrigen;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Lee el stock de producto terminado del sistema de origen (tabla odbc_stock) y guarda una
 * copia por pallet. Solo SELECT, desde el servidor de FoliOS; nada de lo leído crea folios.
 *
 * El origen trae una fila por línea de pallet. Aquí se agrupan por número de pallet: un
 * artículo (especie, variedad, calibre, envase, categoría, marca) y una composición por CSG,
 * lote, proceso, productor y variedad, igual que la Validación de FoliOS.
 */
class ServicioLecturaStockOrigen
{
    /** Columnas leídas del origen; ninguna otra sale de su servidor. */
    public const COLUMNAS = [
        'cod_empresa', 'cod_temporada', 'temporada', 'cod_articulo', 'descripcion_articulo', 'peso_neto_articulo',
        'cod_especie', 'descripcion_especie', 'codigo_entidad', 'razon_social_entidad', 'variedad', 'codigo_variedad',
        'lote_recepcion', 'cod_calibre', 'cod_categoria', 'codigo_csg_predio', 'descripcion_predio', 'fecha_embalaje',
        'cantidad', 'kilos_netos', 'Pallet', 'cod_bodega', 'origen_i', 'cod_estado_stock_paletizado', 'marca', 'Proceso',
        'fecha_recepcion',
    ];

    private const CAMPOS_ARTICULO = [
        'empresa' => 'cod_empresa',
        'especie' => 'descripcion_especie',
        'especie_codigo' => 'cod_especie',
        'variedad' => 'variedad',
        'variedad_codigo' => 'codigo_variedad',
        'calibre' => 'cod_calibre',
        'categoria' => 'cod_categoria',
        'envase_codigo' => 'cod_articulo',
        'envase' => 'descripcion_articulo',
        'marca' => 'marca',
        'origen' => 'origen_i',
        'temporada_origen' => 'cod_temporada',
        'temporada_origen_nombre' => 'temporada',
    ];

    public function __construct(
        private readonly ConectorMySqlLectura $conector,
        private readonly ExploradorMySql $explorador,
        private readonly MapeoStockOrigen $mapeo,
    ) {}

    /** La configuración de Oficina tiene prioridad; sin ella se conserva el contrato anterior. */
    public function configuracion(): array
    {
        $perfil = ConfiguracionOrigen::find(ConfiguracionOrigen::STOCK);
        if ($perfil) {
            $conexion = ConexionExterna::find($perfil->conexion_externa_id);

            return [
                'conexion' => $conexion?->activa ? $conexion : null,
                'tabla' => $perfil->ajustes['tabla'], 'bodega' => '', 'temporada' => null,
                'habilitada' => $perfil->ajustes['habilitada'], 'ambito' => $perfil->ambito($conexion),
                'perfil' => $perfil->ajustes,
            ];
        }
        $config = config('integraciones.stock_pt');
        $nombre = trim((string) ($config['conexion'] ?? ''));
        $activas = ConexionExterna::query()->where('activa', true);
        $conexion = $nombre !== ''
            ? $activas->where('nombre', $nombre)->first()
            : (($candidatas = $activas->limit(2)->get())->count() === 1 ? $candidatas->first() : null);

        return [
            'conexion' => $conexion,
            'tabla' => (string) $config['tabla'],
            'bodega' => (string) $config['bodega'],
            'temporada' => filled($config['temporada'] ?? null) ? (string) $config['temporada'] : null,
            'habilitada' => (bool) $config['habilitada'],
            'ambito' => 'legacy',
            'perfil' => null,
        ];
    }

    /** Lee el origen y actualiza la copia. Null si la lectura no está configurada o ya corre otra. */
    public function leer(?User $usuario = null): ?LecturaStockOrigen
    {
        $config = $this->configuracion();
        if (! $config['habilitada'] || ! $config['conexion']) {
            return null;
        }

        try {
            return Cache::lock('lectura-stock-origen', 300)->block(0, fn () => $this->ejecutar($config, $usuario));
        } catch (LockTimeoutException) {
            return null;
        }
    }

    /** @param array{conexion: ConexionExterna, tabla: string, bodega: string, temporada: ?string} $config */
    private function ejecutar(array $config, ?User $usuario): LecturaStockOrigen
    {
        $lectura = LecturaStockOrigen::create([
            'conexion_externa_id' => $config['conexion']->id,
            'ambito' => $config['ambito'],
            'tabla' => $config['tabla'],
            'bodega' => $config['bodega'],
            'temporada_origen' => $config['temporada'],
            'estado' => 'en_curso',
            'solicitada_por_user_id' => $usuario?->id,
            'iniciada_at' => now(),
        ]);

        try {
            if ($config['perfil'] !== null) {
                $columnas = $this->explorador->columnas($config['conexion']->credenciales, $config['tabla']);
                [$sql, $parametros] = $this->mapeo->consulta($config['perfil'], $columnas);
            } else {
                if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $config['tabla']) !== 1) {
                    throw new LecturaStockOrigenFallida('tabla_invalida', 'El nombre de la tabla de origen solo admite letras, números y guion bajo.');
                }
                $sql = $this->consulta($config['tabla'], $config['temporada'] !== null);
                $parametros = array_values(array_filter([$config['bodega'], $config['temporada']], fn (?string $valor): bool => $valor !== null));
            }
            $filas = $this->conector->consultar($config['conexion']->credenciales, $sql, $parametros);
            if ($filas === []) {
                // Una vista caída o filtrada por error devolvería vacío: no se da todo por retirado.
                throw new LecturaStockOrigenFallida('sin_filas', 'La fuente no devolvió filas con los filtros configurados; se conserva la lectura anterior.');
            }
            if ($config['perfil'] !== null) {
                $this->mapeo->validarFilas($filas);
            }
            $pallets = $this->agrupar($filas);
            foreach ($pallets as $pallet) {
                if ($pallet['cajas'] > 4294967295 || $pallet['kilos'] > 9999999999.99) {
                    throw new DomainException('La suma de cajas o kilos de un pallet supera el máximo admitido. Revise las columnas asignadas.');
                }
            }
            $resultado = $this->guardar($pallets, $config['bodega'], $lectura);
            $lectura->update([
                'estado' => 'correcta',
                'filas' => count($filas),
                'pallets' => count($pallets),
                ...$resultado,
                'terminada_at' => now(),
            ]);
        } catch (Throwable $error) {
            [$causa, $mensaje] = $error instanceof LecturaStockOrigenFallida
                ? [$error->causa, $error->getMessage()]
                : ($error instanceof DomainException
                    ? ['mapeo_invalido', $error->getMessage()]
                    : array_values(array_intersect_key(ConectorMySqlLectura::diagnosticar($error), array_flip(['causa', 'mensaje']))));
            if ($causa === 'tabla_inexistente') {
                $mensaje = "No existe la tabla o vista {$config['tabla']} en la base de origen. Revisa ".($config['perfil'] ? 'el mapeo en Conexiones externas.' : 'WMS_STOCK_ORIGEN_TABLA.');
            }
            $lectura->update([
                'estado' => 'fallida',
                'causa' => $causa,
                'mensaje' => mb_substr($mensaje, 0, 255),
                'terminada_at' => now(),
            ]);
        }

        LecturaStockOrigen::query()
            ->where('iniciada_at', '<', now()->subDays((int) config('integraciones.stock_pt.conservar_lecturas_dias', 30)))
            ->whereNotIn('id', PalletStockOrigen::query()->whereNotNull('lectura_stock_origen_id')->select('lectura_stock_origen_id'))
            ->delete();

        return $lectura->refresh();
    }

    private function consulta(string $tabla, bool $conTemporada): string
    {
        $columnas = implode(', ', array_map(fn (string $columna): string => "`{$columna}`", self::COLUMNAS));

        return "SELECT {$columnas} FROM `{$tabla}` WHERE `cod_bodega` = ? AND `cod_tipo_articulo` = 'PT'"
            .($conTemporada ? ' AND `cod_temporada` = ?' : '')
            .' ORDER BY `Pallet`';
    }

    /**
     * @param  list<array<string, mixed>>  $filas
     * @return array<string, array<string, mixed>> por número de pallet
     */
    public function agrupar(array $filas): array
    {
        $zona = config('app.operational_timezone');

        return collect($filas)
            ->map(fn (array $fila): array => array_change_key_case($fila, CASE_LOWER))
            ->filter(fn (array $fila): bool => $this->texto($fila['pallet'] ?? null) !== null)
            ->groupBy(fn (array $fila): string => mb_strtoupper($this->texto($fila['pallet'])))
            ->map(function (Collection $lineas, string $numero) use ($zona): array {
                $cajas = (int) $lineas->sum(fn (array $fila): int => (int) ($fila['cantidad'] ?? 0));
                $articulo = [];
                $mezclados = [];
                foreach (self::CAMPOS_ARTICULO as $campo => $columna) {
                    $valores = $lineas->map(fn (array $fila): ?string => $this->texto($fila[strtolower($columna)] ?? null))
                        ->filter()->unique()->values();
                    $articulo[$campo] = $valores->isEmpty() ? null : mb_substr($valores->implode(' / '), 0, 200);
                    if ($valores->count() > 1) {
                        $mezclados[] = $campo;
                    }
                }
                $cajasPorEstado = $lineas->groupBy(fn (array $fila): string => mb_strtoupper((string) $this->texto($fila['cod_estado_stock_paletizado'] ?? null)))
                    ->map(fn (Collection $grupo): int => (int) $grupo->sum(fn (array $fila): int => (int) ($fila['cantidad'] ?? 0)));
                $composicion = $lineas
                    ->groupBy(fn (array $fila): string => implode('|', [
                        $this->texto($fila['codigo_csg_predio'] ?? null),
                        $this->codigo($fila['lote_recepcion'] ?? null),
                        $this->codigo($fila['proceso'] ?? null),
                        $this->texto($fila['codigo_entidad'] ?? null),
                        $this->texto($fila['variedad'] ?? null),
                    ]))
                    ->map(fn (Collection $grupo): array => [
                        'csg' => $this->texto($grupo->first()['codigo_csg_predio'] ?? null),
                        'predio' => $this->texto($grupo->first()['descripcion_predio'] ?? null),
                        'variedad' => $this->texto($grupo->first()['variedad'] ?? null),
                        'lote' => $this->codigo($grupo->first()['lote_recepcion'] ?? null),
                        'proceso' => $this->codigo($grupo->first()['proceso'] ?? null),
                        'productor_codigo' => $this->texto($grupo->first()['codigo_entidad'] ?? null),
                        'productor' => $this->texto($grupo->first()['razon_social_entidad'] ?? null),
                        'cajas' => (int) $grupo->sum(fn (array $fila): int => (int) ($fila['cantidad'] ?? 0)),
                        'kilos' => round((float) $grupo->sum(fn (array $fila): float => (float) ($fila['kilos_netos'] ?? 0)), 2),
                    ])
                    ->sortBy(fn (array $linea): string => implode('|', [$linea['csg'], $linea['lote'], $linea['proceso'], $linea['productor_codigo'], $linea['variedad']]))
                    ->values()
                    ->all();
                $embalajes = $lineas->map(fn (array $fila): ?string => $this->fecha($fila['fecha_embalaje'] ?? null, $zona)?->toDateString())->filter()->sort()->values();
                $recepciones = $lineas->map(fn (array $fila): ?CarbonImmutable => $this->fecha($fila['fecha_recepcion'] ?? null, $zona))->filter()->sort()->values();

                $pallet = [
                    'numero_pallet' => mb_substr($numero, 0, 50),
                    // PC = pallet completo, PI = saldo. Si el origen mezcla ambos, manda la mayoría de cajas.
                    'tipo_bulto' => ($cajasPorEstado['PI'] ?? 0) > ($cajasPorEstado['PC'] ?? 0) ? 'saldo' : 'pallet',
                    'tipo_mezclado' => $cajasPorEstado->keys()->intersect(['PC', 'PI'])->count() > 1,
                    ...$articulo,
                    'mezclados' => $mezclados,
                    'cajas' => $cajas,
                    'kilos' => round((float) $lineas->sum(fn (array $fila): float => (float) ($fila['kilos_netos'] ?? 0)), 2),
                    'envase_kilos' => is_numeric($lineas->first()['peso_neto_articulo'] ?? null) ? round((float) $lineas->first()['peso_neto_articulo'], 3) : null,
                    'fecha_embalaje' => $embalajes->first(),
                    'recibido_origen_at' => $recepciones->first()?->utc()->toDateTimeString(),
                    'composicion' => $composicion,
                ];
                $pallet['huella'] = hash('sha256', json_encode($pallet, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

                return $pallet;
            })
            ->all();
    }

    /**
     * @param  array<string, array<string, mixed>>  $pallets
     * @return array{nuevos: int, modificados: int, retirados: int}
     */
    private function guardar(array $pallets, string $bodega, LecturaStockOrigen $lectura): array
    {
        return DB::transaction(function () use ($pallets, $bodega, $lectura): array {
            // Un cambio mientras respondía el servidor externo no debe publicar una copia obsoleta.
            ConfiguracionOrigen::query()->whereKey(ConfiguracionOrigen::STOCK)->lockForUpdate()->first();
            ConexionExterna::query()->whereKey($lectura->conexion_externa_id)->lockForUpdate()->first();
            $vigente = $this->configuracion();
            if (! $vigente['habilitada'] || ! $vigente['conexion'] || $vigente['ambito'] !== $lectura->ambito) {
                throw new LecturaStockOrigenFallida('configuracion_cambiada', 'La configuración cambió durante la lectura. Repita la lectura con la selección vigente.');
            }
            $ahora = now();
            $existentes = PalletStockOrigen::query()->where('ambito', $lectura->ambito)->where('bodega', $bodega)->get()->keyBy('numero_pallet');
            $nuevos = 0;
            $modificados = 0;
            foreach ($pallets as $numero => $datos) {
                $existente = $existentes->get($numero);
                $cambio = $existente === null || $existente->huella !== $datos['huella'] || ! $existente->presente;
                if ($existente === null) {
                    $nuevos++;
                } elseif ($existente->huella !== $datos['huella']) {
                    $modificados++;
                }
                $modelo = $existente ?? new PalletStockOrigen(['ambito' => $lectura->ambito, 'bodega' => $bodega, 'primera_lectura_at' => $ahora]);
                $modelo->fill([
                    ...$datos,
                    'presente' => true,
                    'ausente_desde' => null,
                    'ultima_lectura_at' => $ahora,
                    'modificado_origen_at' => $cambio ? $ahora : $modelo->modificado_origen_at,
                    'lectura_stock_origen_id' => $lectura->id,
                ])->save();
            }
            $retirados = PalletStockOrigen::query()
                ->where('ambito', $lectura->ambito)
                ->where('bodega', $bodega)
                ->where('presente', true)
                ->whereNotIn('numero_pallet', array_keys($pallets))
                ->update(['presente' => false, 'ausente_desde' => $ahora, 'updated_at' => $ahora]);

            return ['nuevos' => $nuevos, 'modificados' => $modificados, 'retirados' => $retirados];
        }, attempts: 3);
    }

    private function texto(mixed $valor): ?string
    {
        $texto = trim((string) $valor);

        return $texto === '' ? null : $texto;
    }

    /** Lotes y procesos en mayúsculas, como en Validación; «0»/«00000» significa sin dato. */
    private function codigo(mixed $valor): ?string
    {
        $texto = $this->texto($valor);

        return $texto === null || preg_match('/^0+$/', $texto) === 1 ? null : mb_strtoupper($texto);
    }

    private function fecha(mixed $valor, string $zona): ?CarbonImmutable
    {
        if ($valor instanceof \DateTimeInterface) {
            return CarbonImmutable::parse($valor->format('Y-m-d H:i:s'), $zona);
        }
        $texto = $this->texto($valor);
        if ($texto === null || str_starts_with($texto, '0000')) {
            return null;
        }
        try {
            return CarbonImmutable::parse($texto, $zona);
        } catch (Throwable) {
            return null;
        }
    }
}
