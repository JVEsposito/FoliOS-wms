<?php

namespace App\Services\Embarques;

use App\Enums\EstadoCarga;
use App\Enums\EstadoEmbarque;
use App\Enums\PrioridadCarga;
use App\Exceptions\ConflictoOperacion;
use App\Exceptions\OperacionNoAutorizada;
use App\Models\Cliente;
use App\Models\Embarque;
use App\Models\EventoEmbarque;
use App\Models\Pais;
use App\Models\Puerto;
use App\Models\Temporada;
use App\Models\User;
use App\Services\Autorizacion\AlcanceOperacionalUsuario;
use App\Services\Cargas\ServicioCarga;
use App\Services\Temporadas\GuardiaTemporadaActiva;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;

class ServicioCalendarioEmbarques
{
    public function __construct(
        private readonly ServicioCorrelativoEmbarque $correlativos,
        private readonly ServicioCarga $cargas,
        private readonly AlcanceOperacionalUsuario $alcance,
        private readonly GuardiaTemporadaActiva $guardiaTemporada,
    ) {}

    /** @param array<string, mixed> $datos */
    public function crear(array $datos, User $usuario): Embarque
    {
        return DB::transaction(function () use ($datos, $usuario): Embarque {
            $temporada = app(ServicioTemporadaActiva::class)->buscar(bloquear: true)
                ?? throw new DomainException('No existe una temporada activa.');
            $cliente = Cliente::query()->whereKey($datos['cliente_id'])
                ->where('activo', true)->lockForUpdate()->firstOrFail();
            $intervalo = (int) $temporada->intervalo_embarques_minutos;
            $inicio = $this->inicio($datos['fecha_programada'], $datos['hora_programada']);
            $this->asegurarVentanaConfigurada($inicio, $intervalo);
            $conflictos = $this->conflictos($temporada, $inicio, $intervalo);
            $sobrecupo = $this->resolverSobrecupo($conflictos, $datos, $usuario);
            $correlativo = $this->correlativos->siguiente($cliente);

            $embarque = Embarque::query()->create(array_merge(
                [
                    'temporada_id' => $temporada->id,
                    'cliente_id' => $cliente->id,
                    'codigo' => $correlativo['codigo'],
                    'numero_correlativo' => $correlativo['numero'],
                    'fecha_programada' => $inicio->toDateString(),
                    'hora_programada' => $inicio->format('H:i:s'),
                    'intervalo_minutos' => $intervalo,
                    'modalidad' => $datos['modalidad'],
                    'estado' => EstadoEmbarque::Tentativo,
                ],
                $this->camposEditables($datos),
                [
                    'version' => 1,
                    'creado_por_user_id' => $usuario->id,
                    'actualizado_por_user_id' => $usuario->id,
                ],
                $sobrecupo,
            ));
            $this->sincronizarInstructivos($embarque, $datos['instructivos']);
            $this->evento($embarque, $usuario, 'creado', [
                'ventana' => $inicio->toIso8601String(),
                'intervalo_minutos' => $intervalo,
                'instructivos' => count($datos['instructivos']),
            ]);

            if ($sobrecupo !== []) {
                $this->evento($embarque, $usuario, 'sobrecupo_autorizado', [
                    'motivo' => $sobrecupo['sobrecupo_motivo'],
                    'conflictos' => $conflictos->pluck('codigo')->values()->all(),
                ]);
            }

            return $this->cargar($embarque);
        }, attempts: 3);
    }

    /** @param array<string, mixed> $datos */
    public function actualizar(
        Embarque $embarque,
        array $datos,
        User $usuario,
        int $versionEsperada,
    ): Embarque {
        return DB::transaction(function () use (
            $embarque,
            $datos,
            $usuario,
            $versionEsperada,
        ): Embarque {
            $embarque = Embarque::query()->lockForUpdate()->findOrFail($embarque->id);
            $this->asegurarVersion($embarque, $versionEsperada);

            if ($embarque->estado === EstadoEmbarque::Cancelado) {
                throw new DomainException('Un embarque cancelado no puede modificarse.');
            }

            if ($embarque->cliente_id !== $datos['cliente_id']) {
                throw new DomainException(
                    'El cliente no puede cambiar porque forma parte del código interno del embarque.',
                );
            }

            $inicioAnterior = $this->inicioModelo($embarque);
            $inicioNuevo = $this->inicio($datos['fecha_programada'], $datos['hora_programada']);
            $reprogramado = $inicioAnterior->equalTo($inicioNuevo) === false;
            $temporada = $reprogramado
                ? Temporada::query()->lockForUpdate()->findOrFail($embarque->temporada_id)
                : $embarque->temporada;
            $intervalo = $reprogramado
                ? (int) $temporada->intervalo_embarques_minutos
                : $embarque->intervalo_minutos;
            $this->asegurarVentanaConfigurada($inicioNuevo, $intervalo);
            $sobrecupo = [];

            if ($reprogramado) {
                $conflictos = $this->conflictos(
                    $temporada,
                    $inicioNuevo,
                    $intervalo,
                    $embarque->id,
                );
                $sobrecupo = $this->resolverSobrecupo($conflictos, $datos, $usuario);

                if ($sobrecupo === []) {
                    $sobrecupo = [
                        'sobrecupo_autorizado_por_user_id' => null,
                        'sobrecupo_motivo' => null,
                        'sobrecupo_autorizado_at' => null,
                    ];
                }
            }

            $embarque->update(array_merge(
                [
                    'fecha_programada' => $inicioNuevo->toDateString(),
                    'hora_programada' => $inicioNuevo->format('H:i:s'),
                    'intervalo_minutos' => $intervalo,
                    'modalidad' => $datos['modalidad'],
                ],
                $this->camposEditables($datos),
                $sobrecupo,
                [
                    'version' => $embarque->version + 1,
                    'actualizado_por_user_id' => $usuario->id,
                ],
            ));
            $this->sincronizarInstructivos($embarque, $datos['instructivos']);
            $this->evento($embarque, $usuario, $reprogramado ? 'reprogramado' : 'actualizado', [
                'ventana_anterior' => $inicioAnterior->toIso8601String(),
                'ventana_actual' => $inicioNuevo->toIso8601String(),
                'instructivos' => count($datos['instructivos']),
            ]);

            return $this->cargar($embarque);
        }, attempts: 3);
    }

    /** @param array<string, mixed> $datos */
    public function confirmar(
        Embarque $embarque,
        array $datos,
        User $usuario,
        int $versionEsperada,
    ): Embarque {
        return DB::transaction(function () use (
            $embarque,
            $datos,
            $usuario,
            $versionEsperada,
        ): Embarque {
            $embarque = Embarque::query()->lockForUpdate()->findOrFail($embarque->id);
            // La carga se crea en la temporada activa: el embarque debe ser de esa misma temporada.
            $this->guardiaTemporada->asegurar($embarque);
            $this->asegurarVersion($embarque, $versionEsperada);

            if ($embarque->estado !== EstadoEmbarque::Tentativo) {
                throw new DomainException('Solo un embarque tentativo puede confirmarse.');
            }

            if ($embarque->instructivos()->exists() === false) {
                throw new DomainException('El embarque debe conservar al menos un instructivo.');
            }

            $carga = $this->cargas->crear([
                'numero_orden_externa' => null,
                'prioridad' => $datos['prioridad'] ?? PrioridadCarga::Normal->value,
                'camara_objetivo_id' => $datos['camara_objetivo_id'] ?? null,
                'anden_previsto_id' => $datos['anden_previsto_id'] ?? null,
                'observacion' => "Embarque {$embarque->codigo}. ".($embarque->observacion ?? ''),
            ], $usuario);

            $embarque->update([
                'carga_id' => $carga->id,
                'estado' => EstadoEmbarque::Confirmado,
                'version' => $embarque->version + 1,
                'actualizado_por_user_id' => $usuario->id,
                'confirmado_por_user_id' => $usuario->id,
                'confirmado_at' => now(),
            ]);
            $this->evento($embarque, $usuario, 'confirmado', [
                'carga_id' => $carga->id,
                'carga_codigo' => $carga->codigo,
            ]);

            return $this->cargar($embarque);
        }, attempts: 3);
    }

    public function cancelar(
        Embarque $embarque,
        User $usuario,
        int $versionEsperada,
        string $motivo,
    ): Embarque {
        return DB::transaction(function () use (
            $embarque,
            $usuario,
            $versionEsperada,
            $motivo,
        ): Embarque {
            $embarque = Embarque::query()->with('carga')->lockForUpdate()->findOrFail($embarque->id);
            $this->asegurarVersion($embarque, $versionEsperada);

            if ($embarque->estado === EstadoEmbarque::Cancelado) {
                return $this->cargar($embarque);
            }

            if ($embarque->carga) {
                if (in_array($embarque->carga->estado, [
                    EstadoCarga::Borrador,
                    EstadoCarga::Pendiente,
                ], true) === false) {
                    throw new DomainException(
                        'La orden CAR ya inició su operación y debe resolverse desde Cargas.',
                    );
                }

                $this->cargas->cancelar(
                    $embarque->carga,
                    $usuario,
                    $embarque->carga->version,
                    $motivo,
                );
            }

            $embarque->update([
                'estado' => EstadoEmbarque::Cancelado,
                'version' => $embarque->version + 1,
                'actualizado_por_user_id' => $usuario->id,
                'cancelado_por_user_id' => $usuario->id,
                'cancelacion_motivo' => $motivo,
                'cancelado_at' => now(),
            ]);
            $this->evento($embarque, $usuario, 'cancelado', ['motivo' => $motivo]);

            return $this->cargar($embarque);
        }, attempts: 3);
    }

    /** @return array<int, array<string, mixed>> */
    public function ventanas(Temporada $temporada, CarbonImmutable $desde, CarbonImmutable $hasta): array
    {
        $intervalo = (int) $temporada->intervalo_embarques_minutos;
        $embarques = Embarque::query()
            ->where('temporada_id', $temporada->id)
            ->where('estado', '!=', EstadoEmbarque::Cancelado->value)
            ->whereBetween('fecha_programada', [
                $desde->copy()->subDay()->toDateString(),
                $hasta->toDateString(),
            ])
            ->with(['cliente:id,codigo,nombre', 'carga:id,codigo,estado'])
            ->orderBy('fecha_programada')
            ->orderBy('hora_programada')
            ->get();
        $ventanas = [];

        for ($dia = $desde->startOfDay(); $dia->lte($hasta->endOfDay()); $dia = $dia->addDay()) {
            for ($minutos = 0; $minutos < 1440; $minutos += $intervalo) {
                $inicio = $dia->addMinutes($minutos);
                $fin = $inicio->addMinutes($intervalo);
                $conflictos = $embarques->filter(fn (Embarque $embarque): bool => $this->intersecta(
                    $inicio,
                    $fin,
                    $this->inicioModelo($embarque),
                    $this->inicioModelo($embarque)->addMinutes($embarque->intervalo_minutos),
                ));
                $inicianAqui = $conflictos->filter(
                    fn (Embarque $embarque): bool => $this->inicioModelo($embarque)->equalTo($inicio),
                );

                $ventanas[] = [
                    'fecha' => $inicio->toDateString(),
                    'hora' => $inicio->format('H:i'),
                    'inicio' => $inicio->toIso8601String(),
                    'fin' => $fin->toIso8601String(),
                    'disponible' => $conflictos->isEmpty(),
                    'ocupada_por' => $conflictos->pluck('codigo')->values()->all(),
                    'embarques' => $inicianAqui->map(fn (Embarque $embarque): array => [
                        'id' => $embarque->id,
                        'codigo' => $embarque->codigo,
                        'cliente' => $embarque->cliente?->nombre,
                        'modalidad' => $embarque->modalidad->value,
                        'estado' => $embarque->estado->value,
                        'sobrecupo' => $embarque->sobrecupo_autorizado_at !== null,
                        'carga_codigo' => $embarque->carga?->codigo,
                    ])->values()->all(),
                ];
            }
        }

        return $ventanas;
    }

    public function cargar(Embarque $embarque): Embarque
    {
        return $embarque->load([
            'temporada:id,codigo,nombre,activa,intervalo_embarques_minutos',
            'cliente:id,codigo,nombre,codigo_folio_materiales',
            'carga:id,codigo,estado,version',
            'puertoEmbarque.pais:id,iso_alpha2,nombre_es',
            'instructivos.paisDestino:id,iso_alpha2,nombre_es',
            'instructivos.puertoDestino:id,pais_id,codigo,nombre,tipo',
            'creadoPor:id,name',
            'actualizadoPor:id,name',
            'sobrecupoAutorizadoPor:id,name',
            'eventos.usuario:id,name',
        ]);
    }

    /** @param array<string, mixed> $datos */
    private function camposEditables(array $datos): array
    {
        $campos = collect([
            'referencia_correo', 'nave_vuelo', 'transportista',
            'contenedor', 'sello', 'patente_camion', 'patente_trasera',
            'documentos', 'observacion',
        ])->mapWithKeys(fn (string $campo): array => [$campo => $datos[$campo] ?? null])->all();
        $puerto = isset($datos['puerto_embarque_id'])
            ? Puerto::query()->find($datos['puerto_embarque_id'])
            : null;

        return array_merge($campos, [
            'puerto_embarque_id' => $puerto?->id,
            'puerto_embarque' => $puerto?->nombre,
        ]);
    }

    /** @param array<int, array<string, mixed>> $instructivos */
    private function sincronizarInstructivos(Embarque $embarque, array $instructivos): void
    {
        $embarque->instructivos()->delete();

        foreach (array_values($instructivos) as $indice => $instructivo) {
            $pais = isset($instructivo['pais_destino_id'])
                ? Pais::query()->find($instructivo['pais_destino_id'])
                : null;
            $puerto = isset($instructivo['puerto_destino_id'])
                ? Puerto::query()->find($instructivo['puerto_destino_id'])
                : null;

            $embarque->instructivos()->create(array_merge(
                [
                    'orden' => $indice + 1,
                    'pais_destino_id' => $pais?->id,
                    'destino_pais' => $pais?->nombre_es,
                    'puerto_destino_id' => $puerto?->id,
                    'destino_ciudad' => $puerto?->nombre,
                ],
                collect($instructivo)->only([
                    'numero_externo', 'recibidor',
                    'cantidad_pallets', 'cantidad_cajas', 'booking', 'sps', 'dus',
                    'planilla_sag', 'sello_sag', 'observacion',
                ])->all(),
            ));
        }
    }

    private function inicio(string $fecha, string $hora): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat(
            'Y-m-d H:i',
            "{$fecha} {$hora}",
            config('app.timezone'),
        )->startOfMinute();
    }

    private function inicioModelo(Embarque $embarque): CarbonImmutable
    {
        return CarbonImmutable::parse(
            $embarque->fecha_programada->toDateString().' '.$embarque->hora_programada,
            config('app.timezone'),
        )->startOfMinute();
    }

    private function asegurarVentanaConfigurada(CarbonImmutable $inicio, int $intervalo): void
    {
        $minutos = ($inicio->hour * 60) + $inicio->minute;

        if ($inicio->second !== 0 || $minutos % $intervalo !== 0) {
            throw new DomainException(sprintf(
                'La hora debe corresponder a una ventana de %d minutos visible en el calendario.',
                $intervalo,
            ));
        }
    }

    private function conflictos(
        Temporada $temporada,
        CarbonImmutable $inicio,
        int $intervalo,
        ?string $exceptoId = null,
    ): EloquentCollection {
        $fin = $inicio->addMinutes($intervalo);

        return Embarque::query()
            ->where('temporada_id', $temporada->id)
            ->where('estado', '!=', EstadoEmbarque::Cancelado->value)
            ->when($exceptoId, fn ($consulta) => $consulta->whereKeyNot($exceptoId))
            ->whereBetween('fecha_programada', [
                $inicio->subDay()->toDateString(),
                $fin->toDateString(),
            ])
            ->lockForUpdate()
            ->get()
            ->filter(fn (Embarque $embarque): bool => $this->intersecta(
                $inicio,
                $fin,
                $this->inicioModelo($embarque),
                $this->inicioModelo($embarque)->addMinutes($embarque->intervalo_minutos),
            ))
            ->values();
    }

    private function intersecta(
        CarbonImmutable $inicioA,
        CarbonImmutable $finA,
        CarbonImmutable $inicioB,
        CarbonImmutable $finB,
    ): bool {
        return $inicioA->lt($finB) && $finA->gt($inicioB);
    }

    /**
     * @param  EloquentCollection<int, Embarque>  $conflictos
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    private function resolverSobrecupo(
        EloquentCollection $conflictos,
        array $datos,
        User $usuario,
    ): array {
        if ($conflictos->isEmpty()) {
            return [];
        }

        if (($datos['autorizar_sobrecupo'] ?? false) === false) {
            throw new ConflictoOperacion(
                'La ventana ya está ocupada. Solo un supervisor puede autorizar un sobrecupo.',
            );
        }

        if ($this->alcance->puedeAutorizarSobrecupoEmbarques($usuario) === false) {
            throw new OperacionNoAutorizada(
                'Tu perfil no puede autorizar sobrecupos en el calendario de embarques.',
            );
        }

        return [
            'sobrecupo_autorizado_por_user_id' => $usuario->id,
            'sobrecupo_motivo' => $datos['motivo_sobrecupo'],
            'sobrecupo_autorizado_at' => now(),
        ];
    }

    private function asegurarVersion(Embarque $embarque, int $versionEsperada): void
    {
        if ($embarque->version !== $versionEsperada) {
            throw new ConflictoOperacion(
                "El embarque {$embarque->codigo} cambió en otra sesión. Actualiza el calendario.",
            );
        }
    }

    /** @param array<string, mixed> $datos */
    private function evento(Embarque $embarque, User $usuario, string $tipo, array $datos): void
    {
        EventoEmbarque::query()->create([
            'embarque_id' => $embarque->id,
            'user_id' => $usuario->id,
            'tipo' => $tipo,
            'datos' => array_merge(['version' => $embarque->version], $datos),
        ]);
    }
}
