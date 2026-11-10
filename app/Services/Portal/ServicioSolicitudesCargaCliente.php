<?php

namespace App\Services\Portal;

use App\Enums\EstadoEmbarque;
use App\Enums\ModalidadEmbarque;
use App\Exceptions\ConflictoOperacion;
use App\Models\Cliente;
use App\Models\Embarque;
use App\Models\SolicitudCargaCliente;
use App\Models\Temporada;
use App\Models\User;
use App\Services\Embarques\ServicioCalendarioEmbarques;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Horarios de carga pedidos por clientes. La solicitud reserva el bloque frente a otros
 * clientes, pero no es un embarque: despacho la acepta (y se crea el embarque tentativo
 * del calendario) o la rechaza con un motivo.
 */
class ServicioSolicitudesCargaCliente
{
    public const DIAS_VISIBLES = 31;

    public function __construct(
        private readonly ServicioCalendarioEmbarques $calendario,
    ) {}

    /**
     * Bloques del calendario como los ve un cliente: libre, ocupado o propio, sin revelar
     * a quién pertenecen los bloques ocupados.
     *
     * @return list<array<string, mixed>>
     */
    public function horarios(Cliente $cliente, CarbonImmutable $desde, CarbonImmutable $hasta): array
    {
        $temporada = app(ServicioTemporadaActiva::class)->buscar();
        if (! $temporada || ! $temporada->intervalo_embarques_minutos) {
            return [];
        }
        $intervalo = (int) $temporada->intervalo_embarques_minutos;
        $embarques = Embarque::where('temporada_id', $temporada->id)->where('estado', '!=', EstadoEmbarque::Cancelado->value)
            ->whereBetween('fecha_programada', [$desde->subDay()->toDateString(), $hasta->toDateString()])
            ->get(['id', 'cliente_id', 'fecha_programada', 'hora_programada', 'intervalo_minutos']);
        $solicitudes = $this->pendientes($temporada, $desde->subDay(), $hasta);
        $ocupaciones = $embarques->toBase()->map(fn (Embarque $e): array => [
            'inicio' => $this->inicio($e->fecha_programada->toDateString(), $e->hora_programada),
            'minutos' => $e->intervalo_minutos, 'propio' => $e->cliente_id === $cliente->id,
        ])->merge($solicitudes->map(fn (SolicitudCargaCliente $s): array => [
            'inicio' => $this->inicio($s->fecha->toDateString(), $s->hora),
            'minutos' => $s->intervalo_minutos, 'propio' => $s->cliente_id === $cliente->id,
        ]));
        $ahora = CarbonImmutable::now(config('app.timezone_operacional', 'America/Santiago'));
        $bloques = [];
        for ($dia = $desde->startOfDay(); $dia->lte($hasta); $dia = $dia->addDay()) {
            for ($minutos = 0; $minutos < 1440; $minutos += $intervalo) {
                $inicio = $dia->addMinutes($minutos);
                $fin = $inicio->addMinutes($intervalo);
                if ($inicio->lte($ahora)) {
                    continue;
                }
                $cruces = $ocupaciones->filter(fn (array $o): bool => $inicio->lt($o['inicio']->addMinutes($o['minutos'])) && $fin->gt($o['inicio']));
                $bloques[] = ['fecha' => $inicio->toDateString(), 'hora' => $inicio->format('H:i'), 'fin' => $fin->format('H:i'),
                    'estado' => $cruces->isEmpty() ? 'libre' : ($cruces->contains('propio', true) ? 'propio' : 'ocupado')];
            }
        }

        return $bloques;
    }

    /** @param array<string, mixed> $datos */
    public function solicitar(User $usuario, Cliente $cliente, array $datos): SolicitudCargaCliente
    {
        $hash = hash('sha256', json_encode([$cliente->id, $datos['fecha'], $datos['hora'], $datos['modalidad'] ?? null,
            $datos['cantidad_pallets'] ?? null, $datos['referencia'] ?? null, $datos['observacion'] ?? null], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($usuario, $cliente, $datos, $hash): SolicitudCargaCliente {
            $temporada = app(ServicioTemporadaActiva::class)->buscar(bloquear: true)
                ?? throw new DomainException('No hay una temporada activa para solicitar horarios.');
            $previa = SolicitudCargaCliente::where('operacion_id', $datos['operacion_id'])->first();
            if ($previa) {
                if ($previa->solicitado_por_user_id !== $usuario->id || ! hash_equals($previa->payload_hash, $hash)) {
                    throw new ConflictoOperacion('El identificador de la solicitud ya se usó con otros datos.');
                }

                return $previa;
            }
            $intervalo = (int) $temporada->intervalo_embarques_minutos;
            $inicio = $this->inicio($datos['fecha'], $datos['hora']);
            if ($intervalo < 1 || (($inicio->hour * 60) + $inicio->minute) % $intervalo !== 0) {
                throw new DomainException('Elige uno de los horarios que muestra el calendario.');
            }
            if ($inicio->lte(CarbonImmutable::now($inicio->timezone))) {
                throw new DomainException('El horario ya pasó. Elige uno futuro.');
            }
            if ($inicio->gt(CarbonImmutable::now($inicio->timezone)->addDays(self::DIAS_VISIBLES + 1))) {
                throw new DomainException('Solo se pueden solicitar horarios del próximo mes.');
            }
            $this->asegurarLibre($temporada, $inicio, $intervalo);

            // Un reinicio puede dejar huecos si se conservan solicitudes de otras temporadas.
            $ultimoNumero = SolicitudCargaCliente::query()->max(DB::raw('CAST(SUBSTR(codigo, 4) AS DECIMAL(20, 0))'));

            return SolicitudCargaCliente::create([
                'operacion_id' => $datos['operacion_id'], 'payload_hash' => $hash, 'temporada_id' => $temporada->id,
                'cliente_id' => $cliente->id, 'codigo' => sprintf('SC-%05d', (int) $ultimoNumero + 1),
                'fecha' => $inicio->toDateString(), 'hora' => $inicio->format('H:i:s'), 'intervalo_minutos' => $intervalo,
                'estado' => SolicitudCargaCliente::SOLICITADA,
                'modalidad' => $datos['modalidad'] ?? ModalidadEmbarque::PorConfirmar->value,
                'cantidad_pallets' => $datos['cantidad_pallets'] ?? null, 'referencia' => $datos['referencia'] ?? null,
                'observacion' => $datos['observacion'] ?? null, 'solicitado_por_user_id' => $usuario->id, 'version' => 1,
            ]);
        }, attempts: 3);
    }

    public function anular(User $usuario, Cliente $cliente, SolicitudCargaCliente $solicitud, int $version): SolicitudCargaCliente
    {
        return DB::transaction(function () use ($usuario, $cliente, $solicitud, $version): SolicitudCargaCliente {
            $solicitud = $this->bloquear($solicitud, $version);
            if ($solicitud->cliente_id !== $cliente->id) {
                throw new DomainException('La solicitud no corresponde a este cliente.');
            }

            return $this->resolver($solicitud, $usuario, SolicitudCargaCliente::ANULADA, null);
        }, attempts: 3);
    }

    /** @param array<string, mixed> $datos */
    public function aceptar(User $usuario, SolicitudCargaCliente $solicitud, array $datos): SolicitudCargaCliente
    {
        return DB::transaction(function () use ($usuario, $solicitud, $datos): SolicitudCargaCliente {
            $solicitud = $this->bloquear($solicitud, (int) $datos['version_esperada']);
            $embarque = $this->calendario->crear([
                'cliente_id' => $solicitud->cliente_id,
                'fecha_programada' => $solicitud->fecha->toDateString(),
                'hora_programada' => substr((string) $solicitud->hora, 0, 5),
                'modalidad' => $datos['modalidad'] ?? $solicitud->modalidad->value,
                'referencia_correo' => $solicitud->referencia,
                'observacion' => trim("Solicitado por el cliente ({$solicitud->codigo}). ".($solicitud->observacion ?? '')),
                'instructivos' => [['cantidad_pallets' => $solicitud->cantidad_pallets, 'booking' => $solicitud->referencia]],
                'autorizar_sobrecupo' => (bool) ($datos['autorizar_sobrecupo'] ?? false),
                'motivo_sobrecupo' => $datos['motivo_sobrecupo'] ?? null,
            ], $usuario);
            $solicitud->embarque_id = $embarque->id;

            return $this->resolver($solicitud, $usuario, SolicitudCargaCliente::ACEPTADA, $datos['comentario'] ?? null);
        }, attempts: 3);
    }

    public function rechazar(User $usuario, SolicitudCargaCliente $solicitud, int $version, string $motivo): SolicitudCargaCliente
    {
        return DB::transaction(fn (): SolicitudCargaCliente => $this->resolver(
            $this->bloquear($solicitud, $version), $usuario, SolicitudCargaCliente::RECHAZADA, $motivo,
        ), attempts: 3);
    }

    /** @return Collection<int, SolicitudCargaCliente> */
    public function pendientes(Temporada $temporada, CarbonImmutable $desde, CarbonImmutable $hasta, bool $bloquear = false): Collection
    {
        return SolicitudCargaCliente::where('temporada_id', $temporada->id)->where('estado', SolicitudCargaCliente::SOLICITADA)
            ->whereBetween('fecha', [$desde->toDateString(), $hasta->toDateString()])
            ->when($bloquear, fn ($q) => $q->lockForUpdate())->get();
    }

    public function serializar(SolicitudCargaCliente $s, bool $interno = false): array
    {
        $s->loadMissing(['embarque:id,codigo,estado', ...($interno ? ['cliente:id,codigo,nombre', 'solicitadoPor:id,name', 'resueltoPor:id,name'] : [])]);

        return [
            'id' => $s->id, 'codigo' => $s->codigo, 'fecha' => $s->fecha->toDateString(), 'hora' => substr((string) $s->hora, 0, 5),
            'intervalo_minutos' => $s->intervalo_minutos, 'estado' => $s->estado, 'modalidad' => $s->modalidad->value,
            'cantidad_pallets' => $s->cantidad_pallets, 'referencia' => $s->referencia, 'observacion' => $s->observacion,
            'motivo_resolucion' => $s->motivo_resolucion, 'resuelto_at' => $s->resuelto_at?->toAtomString(),
            'embarque' => $s->embarque ? ['codigo' => $s->embarque->codigo, 'estado' => $s->embarque->estado->value] : null,
            'version' => $s->version, 'creado_at' => $s->created_at?->toAtomString(),
            ...($interno ? [
                'cliente' => $s->cliente?->only(['id', 'codigo', 'nombre']),
                'solicitado_por' => $s->solicitadoPor?->name, 'resuelto_por' => $s->resueltoPor?->name,
            ] : []),
        ];
    }

    private function asegurarLibre(Temporada $temporada, CarbonImmutable $inicio, int $intervalo): void
    {
        $fin = $inicio->addMinutes($intervalo);
        $cruza = fn (CarbonImmutable $otroInicio, int $minutos): bool => $inicio->lt($otroInicio->addMinutes($minutos)) && $fin->gt($otroInicio);
        $embarque = Embarque::where('temporada_id', $temporada->id)->where('estado', '!=', EstadoEmbarque::Cancelado->value)
            ->whereBetween('fecha_programada', [$inicio->subDay()->toDateString(), $fin->toDateString()])->lockForUpdate()->get()
            ->contains(fn (Embarque $e): bool => $cruza($this->inicio($e->fecha_programada->toDateString(), $e->hora_programada), $e->intervalo_minutos));
        $solicitud = $this->pendientes($temporada, $inicio->subDay(), $fin, bloquear: true)
            ->contains(fn (SolicitudCargaCliente $s): bool => $cruza($this->inicio($s->fecha->toDateString(), $s->hora), $s->intervalo_minutos));
        if ($embarque || $solicitud) {
            throw new ConflictoOperacion('Ese horario ya no está disponible. Elige otro.');
        }
    }

    private function bloquear(SolicitudCargaCliente $solicitud, int $version): SolicitudCargaCliente
    {
        $solicitud = SolicitudCargaCliente::lockForUpdate()->findOrFail($solicitud->id);
        if ($solicitud->estado !== SolicitudCargaCliente::SOLICITADA) {
            throw new ConflictoOperacion("La solicitud {$solicitud->codigo} ya fue resuelta. Actualiza la lista.");
        }
        if ($solicitud->version !== $version) {
            throw new ConflictoOperacion("La solicitud {$solicitud->codigo} cambió en otra sesión. Actualiza la lista.");
        }

        return $solicitud;
    }

    private function resolver(SolicitudCargaCliente $solicitud, User $usuario, string $estado, ?string $motivo): SolicitudCargaCliente
    {
        $solicitud->fill(['estado' => $estado, 'motivo_resolucion' => $motivo, 'resuelto_por_user_id' => $usuario->id,
            'resuelto_at' => now(), 'version' => $solicitud->version + 1])->save();

        return $solicitud;
    }

    private function inicio(string $fecha, string $hora): CarbonImmutable
    {
        return CarbonImmutable::parse("{$fecha} ".substr($hora, 0, 5), 'America/Santiago');
    }
}
