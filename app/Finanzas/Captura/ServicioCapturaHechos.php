<?php

namespace App\Finanzas\Captura;

use App\Finanzas\EsquemaFinanzas;
use App\Finanzas\Models\ConceptoServicio;
use App\Finanzas\Models\Cursor;
use App\Finanzas\Models\Hecho;
use App\Finanzas\Models\Lectura;
use App\Finanzas\Valorizacion\ReglaValorizacion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Telescope\Telescope;
use Throwable;

/**
 * Lee la operación y registra en Finanzas los hechos nuevos o cambiados, como versiones.
 *
 * La operación nunca se entera: se consulta por LectorOperacion (solo lectura) y todo lo
 * que se escribe va a la base de Finanzas. Releer un registro sin cambios no hace nada, por
 * eso cada lectura puede repasar un margen hacia atrás sin duplicar hechos.
 */
final class ServicioCapturaHechos
{
    /** Mientras una lectura lo tiene, nadie más registra hechos: el cierre lo toma para congelar el mes. */
    public const CANDADO = 'captura-hechos';

    /** Una vez al día se revisa que los hechos vigentes sigan existiendo en la operación. */
    private const HORAS_ENTRE_CONCILIACIONES = 24;

    public function __construct(
        private readonly LectorOperacion $lector,
        private readonly CatalogoFuentes $fuentes,
        private readonly EsquemaFinanzas $esquema,
    ) {}

    /**
     * @param  list<string>|null  $fuentes  Claves de las fuentes a leer; null lee todas.
     *
     * @throws CapturaEnCurso
     */
    public function capturar(string $origen, ?User $usuario = null, ?array $fuentes = null, bool $conciliar = false): Lectura
    {
        // En un entorno local con Telescope, registrar cada consulta de la captura la hace
        // varias veces más lenta y llena sus tablas; la captura no necesita ese registro.
        if (class_exists(Telescope::class)) {
            return Telescope::withoutRecording(fn (): Lectura => $this->ejecutar($origen, $usuario, $fuentes, $conciliar));
        }

        return $this->ejecutar($origen, $usuario, $fuentes, $conciliar);
    }

    /** @param  list<string>|null  $fuentes */
    private function ejecutar(string $origen, ?User $usuario, ?array $fuentes, bool $conciliar): Lectura
    {
        $seleccion = $this->fuentes->seleccion($fuentes);
        // Una selección parcial también actualiza los cálculos que dependen de ella.
        // Si se solicita solo el cálculo, primero se releen todas sus entradas.
        $dependencias = ConceptoServicio::query()->where('fuente', ReglaValorizacion::FUENTE)->pluck('regla')
            ->flatMap(fn (array $regla): array => array_column($regla['terminos'], 'fuente'))->unique()->all();
        if ($dependencias !== [] && (isset($seleccion[ReglaValorizacion::FUENTE]) || array_intersect(array_keys($seleccion), $dependencias) !== [])) {
            $seleccion = $this->fuentes->seleccion(array_unique([...array_keys($seleccion), ...$dependencias, ReglaValorizacion::FUENTE]));
        }
        if (! $this->esquema->tomarCandado(self::CANDADO)) {
            throw new CapturaEnCurso;
        }

        $inicio = microtime(true);
        $lectura = null;
        try {
            $conciliaciones = [];
            foreach ($seleccion as $clave => $fuente) {
                $conciliaciones[$clave] = $clave === ReglaValorizacion::FUENTE || $conciliar || $this->tocaConciliar($clave);
            }
            $lectura = Lectura::query()->create([
                'origen' => $origen,
                'conciliacion' => in_array(true, $conciliaciones, true),
                'estado' => Lectura::ESTADO_EN_CURSO,
                'solicitada_por_user_id' => $usuario?->id,
                'solicitada_por_nombre' => $usuario?->name,
                'iniciada_at' => now(),
            ]);
            $contexto = new ContextoCaptura($this->lector, fn (string $fuente): array => $this->abiertos($fuente));
            $resumen = [];
            $fallidas = [];
            foreach ($seleccion as $clave => $fuente) {
                try {
                    if ($clave === ReglaValorizacion::FUENTE && $fallidas !== []) {
                        throw new \RuntimeException('Cálculos pendientes: primero corrija las fuentes que no se pudieron leer.');
                    }
                    $resumen[$clave] = $this->capturarFuente($fuente, $contexto, $lectura, $conciliaciones[$clave]);
                } catch (Throwable $error) {
                    report($error);
                    $resumen[$clave] = ['error' => Str::limit($error->getMessage(), 300)];
                    $fallidas[] = $fuente->nombre();
                }
            }

            $lectura->update([
                'estado' => $fallidas === [] ? Lectura::ESTADO_COMPLETADA : Lectura::ESTADO_CON_ERRORES,
                'resumen' => $resumen,
                'error' => $fallidas === [] ? null : 'No se pudo leer: '.implode(', ', $fallidas).'.',
                'terminada_at' => now(),
                'duracion_ms' => $this->milisegundos($inicio),
            ]);
        } catch (Throwable $error) {
            $lectura?->update([
                'estado' => Lectura::ESTADO_FALLIDA,
                'error' => Str::limit($error->getMessage(), 500),
                'terminada_at' => now(),
                'duracion_ms' => $this->milisegundos($inicio),
            ]);

            throw $error;
        } finally {
            $this->esquema->liberarCandado(self::CANDADO);
        }

        return $lectura->refresh();
    }

    /** @return array<string, int|bool> */
    private function capturarFuente(FuenteHechos $fuente, ContextoCaptura $contexto, Lectura $lectura, bool $conciliar): array
    {
        $inicio = microtime(true);
        $clave = $fuente->clave();
        $conteo = ['leidos' => 0, 'nuevos' => 0, 'versiones' => 0, 'anulados' => 0, 'sin_cambios' => 0, 'eliminados' => 0];

        // La marca se toma antes de leer: lo que se confirme durante la lectura entra en la próxima.
        $marca = CarbonImmutable::now();
        $cursor = Cursor::query()->find($clave);
        $desde = $cursor?->marca_at === null
            ? null
            : CarbonImmutable::instance($cursor->marca_at)->subMinutes((int) config('finanzas.captura.margen_minutos', 15));

        if ($conciliar) {
            // Lectura completa: recoge lo que una lectura incremental no alcanzó a ver y deja
            // a la vista lo que ya no existe.
            $vistos = [];
            $this->registrar($clave, $this->anotar($fuente->cambios($contexto, null), $vistos), $lectura, $conteo);
            $this->anularNoVistos($clave, $vistos, $lectura, $conteo);
        } else {
            $this->registrar($clave, $fuente->cambios($contexto, $desde), $lectura, $conteo);

            // Un hecho sin cliente se vuelve a leer: el cliente pudo vincularse en los maestros.
            $sinCliente = Hecho::query()->vigentes()
                ->where('fuente', $clave)
                ->where('estado', Hecho::ESTADO_VIGENTE)
                ->whereNull('cliente_id')
                ->pluck('origen_id')
                ->all();
            if ($sinCliente !== []) {
                $this->registrar($clave, $fuente->porIds($contexto, $sinCliente), $lectura, $conteo, contarLeidos: false);
            }
        }

        Cursor::query()->updateOrCreate(
            ['fuente' => $clave],
            ['marca_at' => $marca, 'lectura_id' => $lectura->id, 'actualizado_at' => now(),
                'conciliada_at' => $conciliar ? $marca : $cursor?->conciliada_at],
        );

        return [...$conteo, 'conciliacion' => $conciliar, 'duracion_ms' => $this->milisegundos($inicio)];
    }

    /**
     * @param  iterable<HechoCapturado>  $hechos
     * @param  array<string, int>  $conteo
     */
    private function registrar(string $fuente, iterable $hechos, Lectura $lectura, array &$conteo, bool $contarLeidos = true): void
    {
        $tamano = max(1, (int) config('finanzas.captura.lote', 500));
        $lote = [];
        foreach ($hechos as $hecho) {
            // Un registro que aparece dos veces en la misma pasada vale por su última lectura.
            $lote[$hecho->origenId] = $hecho;
            if (count($lote) >= $tamano) {
                $this->escribir($fuente, $lote, $lectura, $conteo, $contarLeidos);
                $lote = [];
            }
        }

        if ($lote !== []) {
            $this->escribir($fuente, $lote, $lectura, $conteo, $contarLeidos);
        }
    }

    /**
     * @param  array<string, HechoCapturado>  $lote
     * @param  array<string, int>  $conteo
     */
    private function escribir(string $fuente, array $lote, Lectura $lectura, array &$conteo, bool $contarLeidos): void
    {
        $this->esquema->transaccion(function () use ($fuente, $lote, $lectura, &$conteo, $contarLeidos): void {
            $actuales = Hecho::query()
                ->where('fuente', $fuente)
                ->whereIn('origen_id', array_keys($lote))
                ->where('vigente', true)
                ->lockForUpdate()
                ->get()
                ->keyBy('origen_id');

            $filas = [];
            $reemplazados = [];
            foreach ($lote as $origenId => $hecho) {
                $conteo['leidos'] += $contarLeidos ? 1 : 0;
                $actual = $actuales->get($origenId);

                // Lo que nunca fue un hecho, o ya estaba anulado, no necesita otra versión.
                if ($hecho->anulado() && ($actual === null || $actual->estado === Hecho::ESTADO_ANULADO)) {
                    continue;
                }

                $huella = $hecho->huella();
                if ($actual !== null && $actual->huella === $huella) {
                    $conteo['sin_cambios'] += $contarLeidos ? 1 : 0;

                    continue;
                }

                $motivo = match (true) {
                    $actual === null => Hecho::MOTIVO_CAPTURA,
                    $hecho->anulado() => Hecho::MOTIVO_ANULACION,
                    default => Hecho::MOTIVO_CAMBIO,
                };
                $conteo[match ($motivo) {
                    Hecho::MOTIVO_CAPTURA => 'nuevos',
                    Hecho::MOTIVO_ANULACION => 'anulados',
                    default => 'versiones',
                }]++;

                if ($actual !== null) {
                    $reemplazados[] = $actual->id;
                }
                $filas[] = $this->fila($fuente, $hecho, ($actual?->version ?? 0) + 1, $motivo, $huella, $lectura);
            }

            $this->reemplazar($reemplazados, $filas);
        });
    }

    /**
     * Anula los hechos vigentes que la lectura completa no volvió a encontrar: su registro ya
     * no existe en la operación (por ejemplo, tras un reinicio de datos de prueba) o dejó de
     * formar un hecho. Nada se borra: queda la última versión conocida.
     *
     * @param  array<string, true>  $vistos
     * @param  array<string, int>  $conteo
     */
    private function anularNoVistos(string $fuente, array $vistos, Lectura $lectura, array &$conteo): void
    {
        $hasta = (int) Hecho::query()->max('id');

        Hecho::query()->vigentes()
            ->where('fuente', $fuente)
            ->where('estado', Hecho::ESTADO_VIGENTE)
            ->where('id', '<=', $hasta)
            ->select(['id', 'origen_id'])
            ->chunkById(1000, function (Collection $hechos) use ($fuente, $vistos, $lectura, &$conteo): void {
                $faltantes = $hechos->reject(fn (Hecho $hecho): bool => isset($vistos[$hecho->origen_id]))->pluck('id')->all();
                if ($faltantes === []) {
                    return;
                }

                $this->esquema->transaccion(function () use ($fuente, $faltantes, $lectura, &$conteo): void {
                    $actuales = Hecho::query()->whereIn('id', $faltantes)->where('vigente', true)->lockForUpdate()->get();
                    $filas = [];
                    foreach ($actuales as $actual) {
                        $eliminado = new HechoCapturado(
                            origenId: $actual->origen_id,
                            estado: Hecho::ESTADO_ANULADO,
                            ocurridoAt: CarbonImmutable::instance($actual->ocurrido_at),
                            terminaAt: $actual->termina_at === null ? null : CarbonImmutable::instance($actual->termina_at),
                            clienteId: $actual->cliente_id,
                            clienteNombre: $actual->cliente_nombre,
                            temporadaId: $actual->temporada_id,
                            temporadaCodigo: $actual->temporada_codigo,
                            referencia: $actual->referencia,
                            medidas: $actual->medidas,
                            datos: [...$actual->datos, 'eliminado_en_operacion' => true],
                        );
                        $filas[] = $this->fila($fuente, $eliminado, $actual->version + 1, Hecho::MOTIVO_ELIMINADO, $eliminado->huella(), $lectura);
                    }

                    $this->reemplazar($actuales->pluck('id')->all(), $filas);
                    $conteo['eliminados'] += count($filas);
                });
            });
    }

    /**
     * @param  iterable<HechoCapturado>  $hechos
     * @param  array<string, true>|null  $vistos
     * @return iterable<HechoCapturado>
     */
    private function anotar(iterable $hechos, ?array &$vistos): iterable
    {
        foreach ($hechos as $hecho) {
            if ($vistos !== null) {
                $vistos[$hecho->origenId] = true;
            }

            yield $hecho;
        }
    }

    /**
     * @param  list<int>  $reemplazados
     * @param  list<array<string, mixed>>  $filas
     */
    private function reemplazar(array $reemplazados, array $filas): void
    {
        if ($reemplazados !== []) {
            Hecho::query()->whereIn('id', $reemplazados)->update(['vigente' => false]);
        }
        if ($filas !== []) {
            Hecho::query()->insert($filas);
        }
    }

    /** @return array<string, mixed> */
    private function fila(string $fuente, HechoCapturado $hecho, int $version, string $motivo, string $huella, Lectura $lectura): array
    {
        $json = JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

        return [
            'fuente' => $fuente,
            'origen_id' => $hecho->origenId,
            'version' => $version,
            'vigente' => true,
            'estado' => $hecho->estado,
            'motivo_version' => $motivo,
            'cliente_id' => $hecho->clienteId,
            'cliente_nombre' => $hecho->clienteNombre === null ? null : Str::limit($hecho->clienteNombre, 180, ''),
            'temporada_id' => $hecho->temporadaId,
            'temporada_codigo' => $hecho->temporadaCodigo,
            'referencia' => $hecho->referencia === null ? null : Str::limit($hecho->referencia, 100, ''),
            'ocurrido_at' => $hecho->ocurridoAt->utc()->format('Y-m-d H:i:s'),
            'termina_at' => $hecho->terminaAt?->utc()->format('Y-m-d H:i:s'),
            'fecha_operacional' => $hecho->fechaOperacional(),
            'medidas' => json_encode($hecho->medidas, $json),
            'datos' => json_encode($hecho->datos, $json),
            'huella' => $huella,
            'lectura_id' => $lectura->id,
            'capturado_at' => now()->utc()->format('Y-m-d H:i:s'),
        ];
    }

    /** @return list<string> */
    private function abiertos(string $fuente): array
    {
        return Hecho::query()->vigentes()
            ->where('fuente', $fuente)
            ->where('estado', Hecho::ESTADO_VIGENTE)
            ->whereNull('termina_at')
            ->pluck('origen_id')
            ->all();
    }

    private function tocaConciliar(string $fuente): bool
    {
        $ultima = Cursor::query()->find($fuente)?->conciliada_at;

        return $ultima === null || $ultima->lt(now()->subHours(self::HORAS_ENTRE_CONCILIACIONES));
    }

    private function milisegundos(float $inicio): int
    {
        return (int) round((microtime(true) - $inicio) * 1000);
    }
}
