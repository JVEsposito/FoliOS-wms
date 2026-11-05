<?php

namespace App\Services\Prefrio\PuntoRocio;

use App\Enums\AudienciaNotificacionOperacional;
use App\Enums\EstadoAdministrativoTunelPrefrio;
use App\Enums\EstadoMedicionPuntoRocio;
use App\Enums\EtapaMedicionPuntoRocio;
use App\Enums\GotasVisiblesPuntoRocio;
use App\Enums\RolUsuario;
use App\Enums\SeveridadNotificacionOperacional;
use App\Enums\TipoNotificacionOperacional;
use App\Enums\TipoPuntoControlRocio;
use App\Exceptions\ConflictoOperacion;
use App\Models\Camara;
use App\Models\Dispositivo;
use App\Models\MargenPuntoRocioEtapa;
use App\Models\MedicionPuntoRocio;
use App\Models\NotificacionOperacional;
use App\Models\SalaControlRocio;
use App\Models\TunelPrefrio;
use App\Models\User;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use JsonException;

/**
 * Chequeos diarios de condensación en prefrío y sus etapas vecinas. Cada medición
 * guarda el aire (T° y humedad), el punto de rocío y el margen frente a la T°
 * superficial de la fruta. Las de riesgo avisan a supervisión de frío y quedan
 * pendientes hasta registrar una acción correctiva. No bloquean la operación.
 */
class ServicioPuntoRocio
{
    private const RELACIONES = [
        'registradoPor:id,name',
        'accionRegistradaPor:id,name',
        'anuladaPor:id,name',
        'dispositivo:id,codigo,nombre',
        'selladoReferencia:id,etapa,punto_nombre,referencia,medido_at,temperatura_ambiente_c,humedad_relativa,punto_rocio_c,anulada_at',
        'origenReferencia:id,etapa,punto_nombre,referencia,medido_at,temperatura_superficie_c,anulada_at',
    ];

    public function __construct(
        private readonly ServicioTemporadaActiva $temporadas,
        private readonly ServicioPlanCadenaFrio $plan,
    ) {}

    /** @param array<string, mixed> $datos */
    public function registrar(array $datos, User $usuario, ?Dispositivo $dispositivo = null): MedicionPuntoRocio
    {
        $medidoAt = CarbonImmutable::parse($datos['medido_at'])->utc();
        $payload = [
            'etapa' => (string) $datos['etapa'],
            'punto_tipo' => (string) $datos['punto_tipo'],
            'punto_id' => (string) $datos['punto_id'],
            'referencia' => filled($datos['referencia'] ?? null) ? trim((string) $datos['referencia']) : null,
            'temperatura_ambiente_c' => round((float) $datos['temperatura_ambiente_c'], 2),
            'humedad_relativa' => round((float) $datos['humedad_relativa'], 2),
            'temperatura_superficie_c' => isset($datos['temperatura_superficie_c']) && $datos['temperatura_superficie_c'] !== ''
                ? round((float) $datos['temperatura_superficie_c'], 2)
                : null,
            'gotas_visibles' => (string) ($datos['gotas_visibles'] ?? GotasVisiblesPuntoRocio::Pendiente->value),
            'observacion' => filled($datos['observacion'] ?? null) ? trim((string) $datos['observacion']) : null,
            'medido_at' => $medidoAt->toAtomString(),
        ];
        // Los vínculos entran al hash solo si vienen: una medición antigua reenviada conserva su huella.
        foreach (['sellado_referencia_id', 'origen_referencia_id'] as $campo) {
            if (filled($datos[$campo] ?? null)) {
                $payload[$campo] = (string) $datos[$campo];
            }
        }
        $hash = $this->hash($payload);

        $existente = $this->existente((string) $datos['operacion_id'], $hash, $usuario);
        if ($existente) {
            return $existente;
        }

        $ahora = CarbonImmutable::now();
        if ($medidoAt->greaterThan($ahora->addMinutes(5))) {
            throw ValidationException::withMessages(['medido_at' => 'La hora de medición no puede estar en el futuro.']);
        }
        $dias = (int) config('prefrio.punto_rocio.dias_registro_retroactivo', 7);
        if ($medidoAt->lessThan($ahora->subDays($dias))) {
            throw ValidationException::withMessages(['medido_at' => "Solo se registran mediciones de los últimos {$dias} días."]);
        }

        $etapa = EtapaMedicionPuntoRocio::from($payload['etapa']);
        $punto = $this->punto(TipoPuntoControlRocio::from($payload['punto_tipo']), $payload['punto_id']);
        $margenMinimo = $this->margenMinimo($etapa);
        $rocio = round(CalculadoraPuntoRocio::puntoRocio($payload['temperatura_ambiente_c'], $payload['humedad_relativa']), 2);
        $margen = CalculadoraPuntoRocio::margen($payload['temperatura_superficie_c'], $rocio);
        $estado = CalculadoraPuntoRocio::estado($margen, $margenMinimo);
        $gotas = GotasVisiblesPuntoRocio::from($payload['gotas_visibles']);

        // Aire del envase: el rocío del sellado frente a la superficie de esta medición.
        $sellado = $this->referencia($payload['sellado_referencia_id'] ?? null, 'sellado_referencia_id', $medidoAt);
        if ($sellado && $sellado->etapa !== EtapaMedicionPuntoRocio::Sellado) {
            throw ValidationException::withMessages(['sellado_referencia_id' => 'El aire del envase debe venir de una medición de sellado.']);
        }
        if ($sellado && $etapa === EtapaMedicionPuntoRocio::Sellado) {
            throw ValidationException::withMessages(['sellado_referencia_id' => 'Una medición de sellado ya evalúa su propio aire: no necesita otro sellado.']);
        }
        $envaseMargen = $sellado ? CalculadoraPuntoRocio::margen($payload['temperatura_superficie_c'], $sellado->punto_rocio_c) : null;
        $envaseEstado = $sellado ? CalculadoraPuntoRocio::estado($envaseMargen, $margenMinimo) : null;

        // Fruta que llega: la superficie de la medición de origen frente al rocío de este punto.
        $origen = $this->referencia($payload['origen_referencia_id'] ?? null, 'origen_referencia_id', $medidoAt);
        if ($origen && $origen->temperatura_superficie_c === null) {
            throw ValidationException::withMessages(['origen_referencia_id' => 'La medición de origen no tiene T° superficial de la fruta.']);
        }
        $origenMargen = $origen ? CalculadoraPuntoRocio::margen($origen->temperatura_superficie_c, $rocio) : null;
        $origenEstado = $origen ? CalculadoraPuntoRocio::estado($origenMargen, $margenMinimo) : null;

        try {
            $medicion = DB::transaction(fn (): MedicionPuntoRocio => MedicionPuntoRocio::create([
                'operacion_id' => $datos['operacion_id'],
                'payload_hash' => $hash,
                'temporada_id' => $this->temporadas->obtener()->id,
                'etapa' => $payload['etapa'],
                'punto_tipo' => $payload['punto_tipo'],
                'sala_control_rocio_id' => $punto['sala_control_rocio_id'],
                'tunel_prefrio_id' => $punto['tunel_prefrio_id'],
                'camara_id' => $punto['camara_id'],
                'punto_nombre' => $punto['nombre'],
                'referencia' => $payload['referencia'],
                'temperatura_ambiente_c' => $payload['temperatura_ambiente_c'],
                'humedad_relativa' => $payload['humedad_relativa'],
                'temperatura_superficie_c' => $payload['temperatura_superficie_c'],
                'punto_rocio_c' => $rocio,
                'margen_c' => $margen !== null ? round($margen, 2) : null,
                'vapor_g_m3' => round(CalculadoraPuntoRocio::vapor($payload['temperatura_ambiente_c'], $payload['humedad_relativa']), 2),
                'margen_minimo_c' => $margenMinimo,
                'sellado_referencia_id' => $sellado?->id,
                'envase_punto_rocio_c' => $sellado?->punto_rocio_c,
                'envase_margen_c' => $envaseMargen !== null ? round($envaseMargen, 2) : null,
                'envase_estado' => $envaseEstado,
                'origen_referencia_id' => $origen?->id,
                'origen_superficie_c' => $origen?->temperatura_superficie_c,
                'origen_margen_c' => $origenMargen !== null ? round($origenMargen, 2) : null,
                'origen_estado' => $origenEstado,
                // Foto del plan vigente: si después cambia, la medición conserva contra qué se comparó.
                'plan_rocio_maximo_c' => $this->plan->rocioMaximo($etapa),
                'estado' => $estado,
                'gotas_visibles' => $gotas,
                'requiere_accion' => $estado->esAlerta()
                    || $envaseEstado?->esAlerta() === true
                    || $origenEstado?->esAlerta() === true
                    || $gotas === GotasVisiblesPuntoRocio::Si,
                'observacion' => $payload['observacion'],
                'medido_at' => $medidoAt,
                'fecha_operacional' => $medidoAt->setTimezone($this->zona())->toDateString(),
                'registrado_por_user_id' => $usuario->id,
                'dispositivo_id' => $dispositivo?->id,
            ]), attempts: 3);
        } catch (UniqueConstraintViolationException) {
            // Dos envíos de la misma medición a la vez: gana el primero.
            return $this->existente((string) $datos['operacion_id'], $hash, $usuario)
                ?? throw new ConflictoOperacion('No fue posible registrar la medición. Vuelve a intentarlo.');
        }

        if ($medicion->requiere_accion) {
            $this->notificar($medicion);
        }

        return $this->cargar($medicion);
    }

    public function registrarAccion(MedicionPuntoRocio $medicion, string $accion, User $usuario): MedicionPuntoRocio
    {
        $accion = trim($accion);

        return DB::transaction(function () use ($medicion, $accion, $usuario): MedicionPuntoRocio {
            $medicion = MedicionPuntoRocio::query()->lockForUpdate()->findOrFail($medicion->id);
            if ($medicion->anulada_at !== null) {
                throw new ConflictoOperacion('La medición fue anulada.');
            }
            if (! $medicion->requiere_accion) {
                throw new ConflictoOperacion('La medición no tiene una alerta que atender.');
            }
            if ($medicion->accion_registrada_at !== null) {
                if ($medicion->accion_correctiva === $accion && $medicion->accion_registrada_por_user_id === $usuario->id) {
                    return $this->cargar($medicion);
                }

                throw new ConflictoOperacion('La alerta ya tiene una acción registrada.');
            }
            $medicion->update([
                'accion_correctiva' => $accion,
                'accion_registrada_por_user_id' => $usuario->id,
                'accion_registrada_at' => now(),
            ]);

            return $this->cargar($medicion);
        }, attempts: 3);
    }

    public function anular(MedicionPuntoRocio $medicion, string $motivo, User $usuario): MedicionPuntoRocio
    {
        $motivo = trim($motivo);

        return DB::transaction(function () use ($medicion, $motivo, $usuario): MedicionPuntoRocio {
            $medicion = MedicionPuntoRocio::query()->lockForUpdate()->findOrFail($medicion->id);
            if ($medicion->anulada_at !== null) {
                if ($medicion->motivo_anulacion === $motivo && $medicion->anulada_por_user_id === $usuario->id) {
                    return $this->cargar($medicion);
                }

                throw new ConflictoOperacion('La medición ya fue anulada.');
            }
            $medicion->update([
                'anulada_at' => now(),
                'anulada_por_user_id' => $usuario->id,
                'motivo_anulacion' => $motivo,
            ]);

            return $this->cargar($medicion);
        }, attempts: 3);
    }

    /** @param array{nombre: string, activa?: bool, version_conocida?: int} $datos */
    public function guardarSala(array $datos, User $usuario, ?SalaControlRocio $sala = null): SalaControlRocio
    {
        $nombre = trim(preg_replace('/\s+/u', ' ', $datos['nombre']));

        return DB::transaction(function () use ($datos, $usuario, $sala, $nombre): SalaControlRocio {
            $repetida = SalaControlRocio::query()
                ->where('nombre', $nombre)
                ->when($sala, fn (Builder $consulta) => $consulta->whereKeyNot($sala->id))
                ->exists();
            if ($repetida) {
                throw ValidationException::withMessages(['nombre' => 'Ya existe una sala con ese nombre.']);
            }
            if (! $sala) {
                return SalaControlRocio::create([
                    'nombre' => $nombre,
                    'activa' => $datos['activa'] ?? true,
                    'creada_por_user_id' => $usuario->id,
                ]);
            }
            $sala = SalaControlRocio::query()->lockForUpdate()->findOrFail($sala->id);
            if (isset($datos['version_conocida']) && (int) $datos['version_conocida'] !== $sala->version) {
                throw new ConflictoOperacion('La sala cambió mientras la editabas. Recarga la página.');
            }
            $sala->update([
                'nombre' => $nombre,
                'activa' => $datos['activa'] ?? $sala->activa,
                'version' => $sala->version + 1,
            ]);

            return $sala->refresh();
        }, attempts: 3);
    }

    /**
     * Margen mínimo de cada etapa: el ajustado por supervisión o el de configuración.
     *
     * @return list<array{etapa: string, etiqueta: string, margen_minimo_c: float, version: int, ajustado: bool}>
     */
    public function margenes(): array
    {
        $ajustes = MargenPuntoRocioEtapa::query()->get()->keyBy(fn (MargenPuntoRocioEtapa $fila): string => $fila->etapa->value);

        return collect(EtapaMedicionPuntoRocio::cases())
            ->map(function (EtapaMedicionPuntoRocio $etapa) use ($ajustes): array {
                $ajuste = $ajustes->get($etapa->value);

                return [
                    'etapa' => $etapa->value,
                    'etiqueta' => $etapa->etiqueta(),
                    'margen_minimo_c' => $ajuste?->margen_minimo_c ?? $this->margenPorDefecto(),
                    'version' => $ajuste?->version ?? 0,
                    'ajustado' => $ajuste !== null,
                ];
            })
            ->all();
    }

    /**
     * @param  list<array{etapa: string, margen_minimo_c: float|int|string, version_conocida?: int}>  $filas
     * @return list<array<string, mixed>>
     */
    public function guardarMargenes(array $filas, User $usuario): array
    {
        DB::transaction(function () use ($filas, $usuario): void {
            foreach ($filas as $fila) {
                $etapa = EtapaMedicionPuntoRocio::from($fila['etapa']);
                $valor = round((float) $fila['margen_minimo_c'], 2);
                $actual = MargenPuntoRocioEtapa::query()->lockForUpdate()->find($etapa->value);
                if (isset($fila['version_conocida']) && (int) $fila['version_conocida'] !== ($actual?->version ?? 0)) {
                    throw new ConflictoOperacion("El margen de {$etapa->etiqueta()} cambió mientras lo editabas. Recarga la página.");
                }
                if ($actual && $actual->margen_minimo_c === $valor) {
                    continue;
                }
                if (! $actual) {
                    MargenPuntoRocioEtapa::create([
                        'etapa' => $etapa->value,
                        'margen_minimo_c' => $valor,
                        'actualizado_por_user_id' => $usuario->id,
                    ]);

                    continue;
                }
                $actual->update([
                    'margen_minimo_c' => $valor,
                    'version' => $actual->version + 1,
                    'actualizado_por_user_id' => $usuario->id,
                ]);
            }
        }, attempts: 3);

        return $this->margenes();
    }

    /**
     * Mediciones que se pueden vincular a una medición del día consultado: sellados
     * (aire del envase) y mediciones con T° superficial (fruta que llega).
     *
     * @return list<array<string, mixed>>
     */
    public function referencias(CarbonImmutable $fecha): array
    {
        $horas = $this->horasReferencia();
        $inicio = $fecha->setTimezone($this->zona())->startOfDay();
        $fin = $inicio->endOfDay();
        $ahora = CarbonImmutable::now();

        return MedicionPuntoRocio::query()
            ->whereNull('anulada_at')
            ->whereBetween('medido_at', [$inicio->subHours($horas)->utc(), ($fin->lessThan($ahora) ? $fin : $ahora)->utc()])
            ->where(fn (Builder $consulta) => $consulta
                ->where('etapa', EtapaMedicionPuntoRocio::Sellado->value)
                ->orWhereNotNull('temperatura_superficie_c'))
            ->orderByDesc('medido_at')
            ->limit(200)
            ->get()
            ->map(fn (MedicionPuntoRocio $medicion): array => [
                'id' => $medicion->id,
                'etapa' => $medicion->etapa->value,
                'etapa_etiqueta' => $medicion->etapa->etiqueta(),
                'punto' => $medicion->punto_nombre,
                'referencia' => $medicion->referencia,
                'medido_at' => $medicion->medido_at->toAtomString(),
                'temperatura_ambiente_c' => $medicion->temperatura_ambiente_c,
                'humedad_relativa' => $medicion->humedad_relativa,
                'temperatura_superficie_c' => $medicion->temperatura_superficie_c,
                'punto_rocio_c' => $medicion->punto_rocio_c,
            ])
            ->all();
    }

    public function horasReferencia(): int
    {
        return (int) config('prefrio.punto_rocio.horas_referencia', 72);
    }

    public function margenPorDefecto(): float
    {
        return (float) config('prefrio.punto_rocio.margen_minimo_c', 2.0);
    }

    /**
     * Mediciones de un día operacional con su resumen.
     *
     * @return array<string, mixed>
     */
    public function dia(CarbonImmutable $fecha, ?EtapaMedicionPuntoRocio $etapa = null): array
    {
        $mediciones = MedicionPuntoRocio::query()
            ->whereDate('fecha_operacional', $fecha->toDateString())
            ->when($etapa, fn (Builder $consulta) => $consulta->where('etapa', $etapa->value))
            ->with(self::RELACIONES)
            ->orderBy('medido_at')
            ->get();
        $vigentes = $mediciones->whereNull('anulada_at');

        return [
            'fecha' => $fecha->toDateString(),
            'resumen' => $this->resumen($vigentes),
            'por_etapa' => collect(EtapaMedicionPuntoRocio::cases())
                ->map(function (EtapaMedicionPuntoRocio $caso) use ($vigentes): array {
                    $deEtapa = $vigentes->where('etapa', $caso);

                    return [
                        'etapa' => $caso->value,
                        'etiqueta' => $caso->etiqueta(),
                        'registros' => $deEtapa->count(),
                        'alertas' => $deEtapa->where('requiere_accion', true)->count(),
                        'margen_minimo_c' => $deEtapa->whereNotNull('margen_c')->min('margen_c'),
                        'ultima_medicion_at' => $deEtapa->max('medido_at')?->toAtomString(),
                    ];
                })
                ->filter(fn (array $fila): bool => $fila['registros'] > 0)
                ->values()
                ->all(),
            'mediciones' => $mediciones->map(fn (MedicionPuntoRocio $medicion): array => $this->serializar($medicion))->values()->all(),
        ];
    }

    /**
     * Evolución de los últimos días hasta la fecha consultada.
     *
     * @return list<array<string, mixed>>
     */
    public function historial(CarbonImmutable $hasta, int $dias = 14): array
    {
        $desde = $hasta->subDays($dias - 1);
        $porDia = MedicionPuntoRocio::query()
            ->whereNull('anulada_at')
            ->whereBetween('fecha_operacional', [$desde->toDateString(), $hasta->toDateString()])
            ->get(['fecha_operacional', 'punto_rocio_c', 'margen_c', 'requiere_accion', 'gotas_visibles', 'accion_registrada_at', 'plan_rocio_maximo_c'])
            ->groupBy(fn (MedicionPuntoRocio $medicion): string => $medicion->fecha_operacional->toDateString());

        return collect(range(0, $dias - 1))
            ->map(function (int $desplazamiento) use ($desde, $porDia): array {
                $fecha = $desde->addDays($desplazamiento)->toDateString();
                $resumen = $this->resumen($porDia->get($fecha, collect()));

                return ['fecha' => $fecha, ...$resumen];
            })
            ->all();
    }

    /**
     * Puntos donde se puede medir: salas configuradas, túneles y cámaras.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function puntos(): array
    {
        return [
            'salas' => SalaControlRocio::query()->orderBy('nombre')->get()
                ->map(fn (SalaControlRocio $sala): array => [
                    'id' => $sala->id, 'nombre' => $sala->nombre, 'activa' => $sala->activa, 'version' => $sala->version,
                ])->all(),
            'tuneles' => TunelPrefrio::query()
                ->where('estado_administrativo', EstadoAdministrativoTunelPrefrio::Activo->value)
                ->orderBy('codigo')->get(['id', 'codigo', 'nombre'])
                ->map(fn (TunelPrefrio $tunel): array => ['id' => $tunel->id, 'nombre' => $this->nombreTunel($tunel)])->all(),
            'camaras' => Camara::query()
                ->where('estado', 'activa')
                ->orderBy('codigo')->get(['id', 'codigo', 'nombre'])
                ->map(fn (Camara $camara): array => ['id' => $camara->id, 'nombre' => $this->nombreCamara($camara)])->all(),
        ];
    }

    /**
     * Alertas sin acción de las últimas horas, para Operación ahora.
     *
     * @return list<array<string, mixed>>
     */
    public function alertasPendientes(int $horas = 48): array
    {
        return MedicionPuntoRocio::query()
            ->where('requiere_accion', true)
            ->whereNull('accion_registrada_at')
            ->whereNull('anulada_at')
            ->where('medido_at', '>=', now()->subHours($horas))
            ->orderByDesc('medido_at')
            ->limit(20)
            ->with(['selladoReferencia:id,etapa,punto_nombre,medido_at', 'origenReferencia:id,etapa,punto_nombre,medido_at'])
            ->get()
            ->map(fn (MedicionPuntoRocio $medicion): array => [
                'id' => $medicion->id,
                ...$this->condicion($medicion),
                'etapa' => $medicion->etapa->value,
                'etapa_etiqueta' => $medicion->etapa->etiqueta(),
                'punto' => $medicion->punto_nombre,
                'estado' => $medicion->estado->value,
                'estado_etiqueta' => $medicion->estado->etiqueta(),
                'gotas_visibles' => $medicion->gotas_visibles->value,
                'punto_rocio_c' => $medicion->punto_rocio_c,
                'temperatura_superficie_c' => $medicion->temperatura_superficie_c,
                'margen_c' => $medicion->margen_c,
                'medido_at' => $medicion->medido_at->toAtomString(),
                'fecha' => $medicion->fecha_operacional->toDateString(),
            ])
            ->all();
    }

    /** @return array<string, mixed> */
    public function serializar(MedicionPuntoRocio $medicion): array
    {
        return [
            'id' => $medicion->id,
            'etapa' => $medicion->etapa->value,
            'etapa_etiqueta' => $medicion->etapa->etiqueta(),
            'punto_tipo' => $medicion->punto_tipo->value,
            'punto_id' => $medicion->sala_control_rocio_id ?? $medicion->tunel_prefrio_id ?? $medicion->camara_id,
            'punto' => $medicion->punto_nombre,
            'referencia' => $medicion->referencia,
            'temperatura_ambiente_c' => $medicion->temperatura_ambiente_c,
            'humedad_relativa' => $medicion->humedad_relativa,
            'temperatura_superficie_c' => $medicion->temperatura_superficie_c,
            'punto_rocio_c' => $medicion->punto_rocio_c,
            'margen_c' => $medicion->margen_c,
            'vapor_g_m3' => $medicion->vapor_g_m3,
            'margen_minimo_c' => $medicion->margen_minimo_c,
            'envase' => $medicion->sellado_referencia_id === null ? null : [
                'referencia_id' => $medicion->sellado_referencia_id,
                'punto' => $medicion->selladoReferencia?->punto_nombre,
                'lote' => $medicion->selladoReferencia?->referencia,
                'medido_at' => $medicion->selladoReferencia?->medido_at?->toAtomString(),
                'temperatura_ambiente_c' => $medicion->selladoReferencia?->temperatura_ambiente_c,
                'humedad_relativa' => $medicion->selladoReferencia?->humedad_relativa,
                'punto_rocio_c' => $medicion->envase_punto_rocio_c,
                'margen_c' => $medicion->envase_margen_c,
                'estado' => $medicion->envase_estado?->value,
                'estado_etiqueta' => $medicion->envase_estado?->etiqueta(),
                'referencia_anulada' => $medicion->selladoReferencia?->anulada_at !== null,
            ],
            'origen' => $medicion->origen_referencia_id === null ? null : [
                'referencia_id' => $medicion->origen_referencia_id,
                'etapa_etiqueta' => $medicion->origenReferencia?->etapa?->etiqueta(),
                'punto' => $medicion->origenReferencia?->punto_nombre,
                'lote' => $medicion->origenReferencia?->referencia,
                'medido_at' => $medicion->origenReferencia?->medido_at?->toAtomString(),
                'temperatura_superficie_c' => $medicion->origen_superficie_c,
                'margen_c' => $medicion->origen_margen_c,
                'estado' => $medicion->origen_estado?->value,
                'estado_etiqueta' => $medicion->origen_estado?->etiqueta(),
                'referencia_anulada' => $medicion->origenReferencia?->anulada_at !== null,
            ],
            ...$this->condicion($medicion),
            'plan' => $medicion->plan_rocio_maximo_c === null ? null : [
                'rocio_maximo_c' => $medicion->plan_rocio_maximo_c,
                // HR que habría permitido el plan a la T° de aire que se midió.
                'humedad_maxima' => round(CalculadoraPuntoRocio::humedadParaRocio($medicion->temperatura_ambiente_c, $medicion->plan_rocio_maximo_c), 1),
                'fuera' => $medicion->punto_rocio_c > $medicion->plan_rocio_maximo_c,
            ],
            'estado' => $medicion->estado->value,
            'estado_etiqueta' => $medicion->estado->etiqueta(),
            'gotas_visibles' => $medicion->gotas_visibles->value,
            'requiere_accion' => $medicion->requiere_accion,
            'alerta_pendiente' => $medicion->alertaPendiente(),
            'observacion' => $medicion->observacion,
            'medido_at' => $medicion->medido_at->toAtomString(),
            'fecha' => $medicion->fecha_operacional->toDateString(),
            'registrado_por' => $medicion->registradoPor?->name,
            'dispositivo' => $medicion->dispositivo?->nombre ?? $medicion->dispositivo?->codigo,
            'accion_correctiva' => $medicion->accion_correctiva,
            'accion_registrada_por' => $medicion->accionRegistradaPor?->name,
            'accion_registrada_at' => $medicion->accion_registrada_at?->toAtomString(),
            'anulada' => $medicion->anulada_at !== null,
            'anulada_at' => $medicion->anulada_at?->toAtomString(),
            'anulada_por' => $medicion->anuladaPor?->name,
            'motivo_anulacion' => $medicion->motivo_anulacion,
        ];
    }

    public function zona(): string
    {
        return (string) config('app.operational_timezone', 'America/Santiago');
    }

    /**
     * @param  Collection<int, MedicionPuntoRocio>  $mediciones  vigentes
     * @return array<string, mixed>
     */
    private function resumen(Collection $mediciones): array
    {
        $alertas = $mediciones->where('requiere_accion', true);

        return [
            'registros' => $mediciones->count(),
            'con_superficie' => $mediciones->whereNotNull('temperatura_superficie_c')->count(),
            'punto_rocio_maximo_c' => $mediciones->max('punto_rocio_c'),
            'margen_minimo_c' => $mediciones->whereNotNull('margen_c')->min('margen_c'),
            'alertas' => $alertas->count(),
            'condensacion_posible' => $mediciones->where('estado', EstadoMedicionPuntoRocio::CondensacionPosible)->count(),
            'margen_bajo' => $mediciones->where('estado', EstadoMedicionPuntoRocio::MargenBajo)->count(),
            'gotas_visibles' => $mediciones->where('gotas_visibles', GotasVisiblesPuntoRocio::Si)->count(),
            'alertas_pendientes' => $alertas->whereNull('accion_registrada_at')->count(),
            'fuera_de_plan' => $mediciones
                ->filter(fn (MedicionPuntoRocio $medicion): bool => $medicion->plan_rocio_maximo_c !== null && $medicion->punto_rocio_c > $medicion->plan_rocio_maximo_c)
                ->count(),
        ];
    }

    /** @return array{nombre: string, sala_control_rocio_id: ?string, tunel_prefrio_id: ?string, camara_id: ?string} */
    private function punto(TipoPuntoControlRocio $tipo, string $id): array
    {
        $vacio = ['sala_control_rocio_id' => null, 'tunel_prefrio_id' => null, 'camara_id' => null];
        $noDisponible = fn (string $texto) => ValidationException::withMessages(['punto_id' => $texto]);

        return match ($tipo) {
            TipoPuntoControlRocio::Sala => (function () use ($id, $vacio, $noDisponible): array {
                $sala = SalaControlRocio::query()->whereKey($id)->where('activa', true)->first()
                    ?? throw $noDisponible('La sala no existe o está inactiva.');

                return [...$vacio, 'nombre' => $sala->nombre, 'sala_control_rocio_id' => $sala->id];
            })(),
            TipoPuntoControlRocio::Tunel => (function () use ($id, $vacio, $noDisponible): array {
                $tunel = TunelPrefrio::query()->whereKey($id)
                    ->where('estado_administrativo', EstadoAdministrativoTunelPrefrio::Activo->value)->first()
                    ?? throw $noDisponible('El túnel no existe o está inactivo.');

                return [...$vacio, 'nombre' => $this->nombreTunel($tunel), 'tunel_prefrio_id' => $tunel->id];
            })(),
            TipoPuntoControlRocio::Camara => (function () use ($id, $vacio, $noDisponible): array {
                $camara = Camara::query()->whereKey($id)->where('estado', 'activa')->first()
                    ?? throw $noDisponible('La cámara no existe o está inactiva.');

                return [...$vacio, 'nombre' => $this->nombreCamara($camara), 'camara_id' => $camara->id];
            })(),
        };
    }

    private function nombreTunel(TunelPrefrio $tunel): string
    {
        return $tunel->nombre && $tunel->nombre !== $tunel->codigo ? "{$tunel->codigo} · {$tunel->nombre}" : $tunel->codigo;
    }

    private function nombreCamara(Camara $camara): string
    {
        return $camara->nombre && $camara->nombre !== $camara->codigo ? "{$camara->codigo} · {$camara->nombre}" : $camara->codigo;
    }

    private function notificar(MedicionPuntoRocio $medicion): void
    {
        $hallazgos = $this->hallazgos($medicion->loadMissing(['selladoReferencia', 'origenReferencia']));
        if ($hallazgos === []) {
            return;
        }
        $detalles = collect($hallazgos)->pluck('detalle')->filter()->unique()->values();

        NotificacionOperacional::query()->firstOrCreate(
            ['clave' => "punto-rocio:{$medicion->id}:rol:supervisor-frio"],
            [
                'tipo' => TipoNotificacionOperacional::CondensacionPrefrio,
                'audiencia_tipo' => AudienciaNotificacionOperacional::Rol,
                'audiencia_valor' => RolUsuario::SupervisorFrio->value,
                'severidad' => collect($hallazgos)->contains('critica', true)
                    ? SeveridadNotificacionOperacional::Critica
                    : SeveridadNotificacionOperacional::Advertencia,
                'titulo' => "{$hallazgos[0]['titulo']} · {$medicion->etapa->etiqueta()} · {$medicion->punto_nombre}",
                'mensaje' => ($detalles->isEmpty() ? '' : ucfirst($detalles->implode('; ')).'. ').'Registra una acción correctiva en Prefrío → Punto de rocío.',
                'datos' => [
                    'medicion_punto_rocio_id' => $medicion->id,
                    'temporada_id' => $medicion->temporada_id,
                    'fecha' => $medicion->fecha_operacional->toDateString(),
                    'href' => '/oficina/prefrio/punto-rocio?fecha='.$medicion->fecha_operacional->toDateString(),
                ],
            ],
        );
    }

    /**
     * Riesgos de una medición, del más grave al más leve: gotas, condensación
     * (propia, en el envase, al llegar) y margen bajo.
     *
     * @return list<array{titulo: string, detalle: ?string, critica: bool}>
     */
    private function hallazgos(MedicionPuntoRocio $medicion): array
    {
        $condensacion = EstadoMedicionPuntoRocio::CondensacionPosible;
        $bajo = EstadoMedicionPuntoRocio::MargenBajo;
        $propio = sprintf('rocío %s °C, superficie %s °C, margen %s °C', $this->numero($medicion->punto_rocio_c), $this->numero($medicion->temperatura_superficie_c), $this->numero($medicion->margen_c));
        $sellado = $medicion->selladoReferencia;
        $envase = sprintf(
            'dentro del envase: rocío del sellado %s °C%s, superficie %s °C, margen %s °C',
            $this->numero($medicion->envase_punto_rocio_c),
            $sellado ? " ({$this->hora($sellado->medido_at)}, {$sellado->punto_nombre})" : '',
            $this->numero($medicion->temperatura_superficie_c),
            $this->numero($medicion->envase_margen_c),
        );
        $origenMedicion = $medicion->origenReferencia;
        $origen = sprintf(
            'al llegar: fruta%s a %s °C, rocío de este punto %s °C, margen %s °C',
            $origenMedicion ? " desde {$origenMedicion->etapa->etiqueta()} ({$this->hora($origenMedicion->medido_at)}, {$origenMedicion->punto_nombre})" : '',
            $this->numero($medicion->origen_superficie_c),
            $this->numero($medicion->punto_rocio_c),
            $this->numero($medicion->origen_margen_c),
        );

        return collect([
            [$medicion->gotas_visibles === GotasVisiblesPuntoRocio::Si, 'Gotas visibles', null, true],
            [$medicion->estado === $condensacion, $condensacion->etiqueta(), $propio, true],
            [$medicion->envase_estado === $condensacion, 'Condensación posible en el envase', $envase, true],
            [$medicion->origen_estado === $condensacion, 'Condensación posible al llegar', $origen, true],
            [$medicion->estado === $bajo, $bajo->etiqueta(), $propio, false],
            [$medicion->envase_estado === $bajo, 'Margen bajo en el envase', $envase, false],
            [$medicion->origen_estado === $bajo, 'Margen bajo al llegar', $origen, false],
        ])
            ->filter(fn (array $fila): bool => $fila[0])
            ->map(fn (array $fila): array => ['titulo' => $fila[1], 'detalle' => $fila[2], 'critica' => $fila[3]])
            ->values()
            ->all();
    }

    /**
     * Riesgo principal de la medición para mostrarlo en listas y alertas.
     *
     * @return array{condicion: ?string, condicion_detalle: ?string, critica: bool}
     */
    private function condicion(MedicionPuntoRocio $medicion): array
    {
        $hallazgos = $this->hallazgos($medicion);

        return [
            'condicion' => $hallazgos[0]['titulo'] ?? null,
            'condicion_detalle' => $hallazgos[0]['detalle'] ?? null,
            'critica' => collect($hallazgos)->contains('critica', true),
        ];
    }

    private function hora(?CarbonImmutable $instante): string
    {
        return $instante?->setTimezone($this->zona())->format('H:i') ?? '—';
    }

    /** Medición anterior y vigente que se vincula a la que se registra. */
    private function referencia(?string $id, string $campo, CarbonImmutable $medidoAt): ?MedicionPuntoRocio
    {
        if ($id === null) {
            return null;
        }
        $medicion = MedicionPuntoRocio::query()->find($id)
            ?? throw ValidationException::withMessages([$campo => 'La medición vinculada no existe.']);
        if ($medicion->anulada_at !== null) {
            throw ValidationException::withMessages([$campo => 'La medición vinculada fue anulada.']);
        }
        if (! $medicion->medido_at->lessThanOrEqualTo($medidoAt)) {
            throw ValidationException::withMessages([$campo => 'La medición vinculada debe ser anterior a esta.']);
        }
        $horas = $this->horasReferencia();
        if ($medicion->medido_at->lessThan($medidoAt->subHours($horas))) {
            throw ValidationException::withMessages([$campo => "Solo se vinculan mediciones de las {$horas} horas anteriores."]);
        }

        return $medicion;
    }

    private function margenMinimo(EtapaMedicionPuntoRocio $etapa): float
    {
        return MargenPuntoRocioEtapa::query()->find($etapa->value)?->margen_minimo_c ?? $this->margenPorDefecto();
    }

    private function numero(?float $valor): string
    {
        return $valor === null ? '—' : str_replace('.', ',', number_format($valor, 1, '.', ''));
    }

    private function existente(string $operacionId, string $hash, User $usuario): ?MedicionPuntoRocio
    {
        $existente = MedicionPuntoRocio::query()->where('operacion_id', $operacionId)->first();
        if (! $existente) {
            return null;
        }
        if ($existente->registrado_por_user_id !== $usuario->id || ! hash_equals($existente->payload_hash, $hash)) {
            throw new ConflictoOperacion('El UUID de la medición ya fue utilizado con datos diferentes.');
        }

        return $this->cargar($existente);
    }

    /** @param array<string, mixed> $payload */
    private function hash(array $payload): string
    {
        try {
            return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        } catch (JsonException $exception) {
            throw new ConflictoOperacion('No fue posible preparar la medición.', previous: $exception);
        }
    }

    private function cargar(MedicionPuntoRocio $medicion): MedicionPuntoRocio
    {
        return $medicion->refresh()->load(self::RELACIONES);
    }
}
