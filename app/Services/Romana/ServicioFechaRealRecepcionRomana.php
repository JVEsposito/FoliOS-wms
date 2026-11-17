<?php

namespace App\Services\Romana;

use App\Enums\EstadoRecepcionRomana;
use App\Enums\RolUsuario;
use App\Enums\TipoEventoRomana;
use App\Enums\TipoMovimientoEnvase;
use App\Enums\TipoRecepcionRomana;
use App\Exceptions\ConflictoOperacion;
use App\Models\EventoLoteMateriaPrima;
use App\Models\EventoRecepcionRomana;
use App\Models\MovimientoEnvase;
use App\Models\RecepcionRomana;
use App\Models\User;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Cuando la recepción de la noche se digita al día siguiente, su fecha de registro no es la
 * del camión. Esta corrección deja en la recepción y en sus movimientos de envases la fecha
 * y hora reales de ingreso y salida, que son las que leen los informes por día y jornada.
 */
class ServicioFechaRealRecepcionRomana
{
    public function __construct(private readonly ServicioRecepcionRomana $recepciones) {}

    public static function diasPermitidos(): int
    {
        return max(0, (int) config('materia_prima.dias_fecha_real', 3));
    }

    /** Lo que la ficha necesita para ofrecer la corrección a este usuario. */
    public function disponible(RecepcionRomana $recepcion, ?User $usuario): bool
    {
        if (! $usuario || ! Gate::forUser($usuario)->allows('corregir-fecha-real-romana')
            || ! $this->corregible($recepcion)) {
            return false;
        }

        return $this->esAdministrador($usuario)
            || CarbonImmutable::instance($recepcion->created_at)->gte(CarbonImmutable::now()->subDays(self::diasPermitidos()));
    }

    /** @param array<string, mixed> $datos */
    public function corregir(RecepcionRomana $recepcion, array $datos, User $usuario): RecepcionRomana
    {
        Gate::forUser($usuario)->authorize('corregir-fecha-real-romana');
        $zona = (string) config('app.operational_timezone');
        $ingreso = $this->instante($datos['ingreso_fecha'], $datos['ingreso_hora'], $zona);
        $salida = filled($datos['salida_fecha'] ?? null)
            ? $this->instante($datos['salida_fecha'], $datos['salida_hora'], $zona)
            : null;
        $payload = [
            'accion' => 'corregir_fecha_real',
            'recepcion_id' => $recepcion->id,
            'version_conocida' => (int) $datos['version_conocida'],
            'ingreso_at' => $ingreso->toAtomString(),
            'salida_at' => $salida?->toAtomString(),
            'motivo' => $datos['motivo'],
        ];
        ksort($payload);
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        return DB::transaction(function () use ($recepcion, $datos, $usuario, $payload, $hash, $ingreso, $salida, $zona): RecepcionRomana {
            $recepcion = RecepcionRomana::query()->lockForUpdate()->findOrFail($recepcion->id);
            $evento = EventoRecepcionRomana::query()->where('operacion_id', $datos['operacion_id'])->first();
            if ($evento) {
                if ($evento->recepcion_romana_id !== $recepcion->id || $evento->payload_hash !== $hash
                    || $evento->tipo !== TipoEventoRomana::FechaRealCorregida) {
                    throw new ConflictoOperacion('El identificador de operación ya fue utilizado con datos diferentes.');
                }

                return $this->recepciones->cargar($recepcion);
            }
            if (! $this->corregible($recepcion)) {
                throw new ConflictoOperacion('Solo puedes corregir la fecha real de una recepción de fruta vigente de la temporada activa.');
            }
            if ($recepcion->version !== $payload['version_conocida']) {
                throw new ConflictoOperacion('La recepción cambió. Actualiza el expediente antes de corregir su fecha real.');
            }

            $ahora = CarbonImmutable::now();
            $dias = self::diasPermitidos();
            $administrador = $this->esAdministrador($usuario);
            $registrada = CarbonImmutable::instance($recepcion->created_at);
            if (! $administrador && $registrada->lt($ahora->subDays($dias))) {
                throw new ConflictoOperacion("Esta recepción se registró hace más de {$dias} días: su fecha real la corrige administración.");
            }
            $this->validarIngreso($recepcion, $ingreso, $registrada, $administrador, $dias, $zona);
            $this->validarSalida($recepcion, $ingreso, $salida, $ahora, $zona);

            $anteriorIngreso = CarbonImmutable::instance($recepcion->ingreso_at);
            $anteriorSalida = $recepcion->salida_at ? CarbonImmutable::instance($recepcion->salida_at) : null;
            if ($ingreso->equalTo($anteriorIngreso) && ($salida === null || $salida->equalTo($anteriorSalida))) {
                throw ValidationException::withMessages(['ingreso_hora' => 'La fecha y hora indicadas son las mismas ya registradas.']);
            }

            $movimientos = $this->moverMovimientos($recepcion, $anteriorIngreso, $ingreso, $anteriorSalida, $salida);
            $recepcion->update([
                'ingreso_at' => $ingreso,
                ...($salida ? ['salida_at' => $salida] : []),
                'version' => $recepcion->version + 1,
            ]);
            EventoRecepcionRomana::create([
                'operacion_id' => $datos['operacion_id'],
                'payload_hash' => $hash,
                'recepcion_romana_id' => $recepcion->id,
                'tipo' => TipoEventoRomana::FechaRealCorregida,
                'estado_anterior' => $recepcion->estado,
                'estado_nuevo' => $recepcion->estado,
                'user_id' => $usuario->id,
                'ocurrido_at' => $ahora,
                'datos' => [
                    'motivo' => $datos['motivo'],
                    'version' => $recepcion->version,
                    'registrada_at' => $registrada->toAtomString(),
                    'anterior' => ['ingreso_at' => $anteriorIngreso->toAtomString(), 'salida_at' => $anteriorSalida?->toAtomString()],
                    'posterior' => ['ingreso_at' => $ingreso->toAtomString(), 'salida_at' => ($salida ?? $anteriorSalida)?->toAtomString()],
                    'movimientos_envases' => $movimientos,
                ],
            ]);

            return $this->recepciones->cargar($recepcion);
        }, attempts: 3);
    }

    private function corregible(RecepcionRomana $recepcion): bool
    {
        return $recepcion->tipo_recepcion !== TipoRecepcionRomana::SoloEnvases
            && $recepcion->estado !== EstadoRecepcionRomana::Anulada
            && $recepcion->eliminada_at === null
            && app(ServicioTemporadaActiva::class)->buscar()?->id === $recepcion->temporada_id;
    }

    private function esAdministrador(User $usuario): bool
    {
        return $usuario->activo && $usuario->rol === RolUsuario::Administrador;
    }

    private function instante(string $fecha, string $hora, string $zona): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d H:i', "{$fecha} {$hora}", $zona)->startOfMinute()->utc();
    }

    private function validarIngreso(
        RecepcionRomana $recepcion,
        CarbonImmutable $ingreso,
        CarbonImmutable $registrada,
        bool $administrador,
        int $dias,
        string $zona,
    ): void {
        $formato = fn (CarbonImmutable $momento): string => $momento->setTimezone($zona)->format('d-m-Y H:i');
        if ($ingreso->gt($registrada)) {
            throw ValidationException::withMessages(['ingreso_hora' => 'El ingreso real no puede ser posterior a su registro en FoliOS ('.$formato($registrada).').']);
        }
        if (! $administrador && $ingreso->lt($registrada->subDays($dias))) {
            throw ValidationException::withMessages(['ingreso_fecha' => "Puedes indicar hasta {$dias} días antes del registro. Para fechas anteriores, pide la corrección a administración."]);
        }
        $temporada = app(ServicioTemporadaActiva::class)->buscar();
        if ($temporada && ! $temporada->incluyeFecha($ingreso->setTimezone($zona)->toDateString())) {
            throw ValidationException::withMessages(['ingreso_fecha' => 'La fecha real queda fuera de la temporada activa.']);
        }
        // Un lote de la recepción no pudo moverse antes de que llegara el camión.
        $primerEventoLote = EventoLoteMateriaPrima::query()
            ->whereIn('lote_materia_prima_id', $recepcion->lotesMateriaPrima()->select('id'))
            ->min('ocurrido_at');
        if ($primerEventoLote !== null && $ingreso->gt(CarbonImmutable::parse($primerEventoLote, 'UTC'))) {
            throw ValidationException::withMessages(['ingreso_hora' => 'Un lote de esta recepción ya registra movimientos desde el '
                .$formato(CarbonImmutable::parse($primerEventoLote, 'UTC')).', antes del ingreso indicado.']);
        }
    }

    private function validarSalida(
        RecepcionRomana $recepcion,
        CarbonImmutable $ingreso,
        ?CarbonImmutable $salida,
        CarbonImmutable $ahora,
        string $zona,
    ): void {
        if ($recepcion->salida_at === null) {
            if ($salida !== null) {
                throw ValidationException::withMessages(['salida_fecha' => 'La recepción aún no registra la salida del camión.']);
            }

            return;
        }
        if ($salida === null) {
            throw ValidationException::withMessages(['salida_fecha' => 'Indica también la fecha y hora reales de salida del camión.']);
        }
        if ($salida->lt($ingreso)) {
            throw ValidationException::withMessages(['salida_hora' => 'La salida real debe ser posterior al ingreso.']);
        }
        if ($salida->gt($ahora)) {
            throw ValidationException::withMessages(['salida_hora' => 'La salida real no puede ser futura.']);
        }
        // Los envases devueltos con la guía de este destare no pudieron reingresar antes de salir.
        if (filled($recepcion->numero_guia_salida)) {
            $devolucion = RecepcionRomana::query()
                ->where('temporada_id', $recepcion->temporada_id)
                ->where('cliente_id', $recepcion->cliente_id)
                ->where('devolucion_guia_salida', $recepcion->numero_guia_salida)
                ->where('estado', '!=', EstadoRecepcionRomana::Anulada->value)
                ->whereNull('eliminada_at')
                ->where('ingreso_at', '<', $salida)
                ->orderBy('ingreso_at')
                ->first(['numero_recepcion', 'ingreso_at']);
            if ($devolucion) {
                throw ValidationException::withMessages(['salida_hora' => sprintf(
                    'La devolución %s reingresó los envases de la guía %s el %s, antes de la salida indicada.',
                    $devolucion->numero_recepcion,
                    $recepcion->numero_guia_salida,
                    CarbonImmutable::instance($devolucion->ingreso_at)->setTimezone($zona)->format('d-m-Y H:i'),
                )]);
            }
        }
    }

    /**
     * Se mueven los movimientos anclados al ingreso o a la salida: los registrados en ese
     * momento y los ajustes de cuenta que se fechan un segundo después. Conservan su desfase.
     * Las correcciones posteriores, fechadas cuando se hicieron, no cambian.
     *
     * @return array{ingreso: int, salida: int}
     */
    private function moverMovimientos(
        RecepcionRomana $recepcion,
        CarbonImmutable $anteriorIngreso,
        CarbonImmutable $ingreso,
        ?CarbonImmutable $anteriorSalida,
        ?CarbonImmutable $salida,
    ): array {
        $contados = ['ingreso' => 0, 'salida' => 0];
        $movimientos = MovimientoEnvase::query()
            ->where('recepcion_romana_id', $recepcion->id)
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
        $cerca = fn (?CarbonInterface $momento, ?CarbonImmutable $ancla): bool => $momento !== null && $ancla !== null
            && $momento->gte($ancla) && $momento->lt($ancla->addMinute());
        $ladoSalida = function (MovimientoEnvase $movimiento) use ($movimientos): bool {
            $origen = $movimientos->get((string) $movimiento->movimiento_origen_id);

            return $movimiento->salida_at !== null
                || in_array($movimiento->tipo_movimiento, [TipoMovimientoEnvase::SalidaRecepcionFruta, TipoMovimientoEnvase::DevolucionPrestamoInterno], true)
                || data_get($movimiento->datos, 'ajuste_recepcion.lado', data_get($origen?->datos, 'ajuste_recepcion.lado', 'recibidos')) !== 'recibidos';
        };
        foreach ($movimientos as $movimiento) {
            $ocurrido = $movimiento->ocurrido_at ? CarbonImmutable::instance($movimiento->ocurrido_at) : null;
            $enSalida = $salida !== null && $cerca($ocurrido, $anteriorSalida)
                && (! $cerca($ocurrido, $anteriorIngreso) || $ladoSalida($movimiento));
            $enIngreso = ! $enSalida && $cerca($ocurrido, $anteriorIngreso);
            $cambios = [];
            if ($enIngreso) {
                $cambios['ocurrido_at'] = $ingreso->addSeconds((int) round($anteriorIngreso->diffInSeconds($ocurrido)));
                $contados['ingreso']++;
            } elseif ($enSalida) {
                $cambios['ocurrido_at'] = $salida->addSeconds((int) round($anteriorSalida->diffInSeconds($ocurrido)));
                $contados['salida']++;
            }
            if ($movimiento->ingreso_at?->equalTo($anteriorIngreso)) {
                $cambios['ingreso_at'] = $ingreso;
            }
            if ($salida && $anteriorSalida && $movimiento->salida_at?->equalTo($anteriorSalida)) {
                $cambios['salida_at'] = $salida;
            }
            if ($cambios !== []) {
                $movimiento->update($cambios);
            }
        }

        return $contados;
    }
}
