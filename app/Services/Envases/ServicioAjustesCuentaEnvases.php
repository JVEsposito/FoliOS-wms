<?php

namespace App\Services\Envases;

use App\Enums\EstadoRevisionMovimientoEnvase;
use App\Enums\TipoMovimientoEnvase;
use App\Exceptions\ConflictoOperacion;
use App\Models\Cliente;
use App\Models\MovimientoEnvase;
use App\Models\Temporada;
use App\Models\User;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Obligaciones de cuenta: nunca ingresan, retiran ni reservan existencia física. */
class ServicioAjustesCuentaEnvases
{
    public function registrar(array $datos, User $actor): MovimientoEnvase
    {
        $payload = [
            'temporada_id' => $datos['temporada_id'], 'cliente_id' => $datos['cliente_id'],
            'tipo_movimiento' => $datos['tipo_movimiento'], 'tipo_envase' => $datos['tipo_envase'],
            'propiedad' => $datos['propiedad'], 'cantidad' => (int) $datos['cantidad'],
            'fecha' => $datos['fecha'], 'referencia' => trim($datos['referencia']), 'motivo' => trim($datos['motivo']),
        ];

        return DB::transaction(function () use ($datos, $payload, $actor): MovimientoEnvase {
            $temporada = app(ServicioTemporadaActiva::class)->obtener(bloquear: true);
            if ($existente = $this->reintento($datos['operacion_id'], $payload)) {
                return $existente;
            }
            if ($temporada->id !== $payload['temporada_id']) {
                throw new ConflictoOperacion('Los ajustes se registran en la temporada activa. Actualice la cuenta.');
            }
            Cliente::query()->where('activo', true)->lockForUpdate()->findOrFail($payload['cliente_id']);
            $fecha = $this->fecha($payload['fecha'], $temporada);
            if ($payload['tipo_movimiento'] === TipoMovimientoEnvase::AperturaCuenta->value) {
                $cuenta = MovimientoEnvase::query()->where('temporada_id', $temporada->id)
                    ->where('cliente_id', $payload['cliente_id'])->where('tipo_envase', $payload['tipo_envase'])
                    ->where('propiedad', $payload['propiedad']);
                if ((clone $cuenta)->where('tipo_movimiento', TipoMovimientoEnvase::AperturaCuenta->value)->exists()) {
                    throw new ConflictoOperacion('Esta cuenta ya tiene apertura. Use un ajuste o revierta el asiento equivocado.');
                }
                if ((clone $cuenta)->where('signo_cuenta', '!=', 0)->where('ocurrido_at', '<', $fecha)->exists()) {
                    throw new ConflictoOperacion('La apertura debe ser anterior o igual al primer movimiento de esta cuenta.');
                }
            }

            return $this->crear($datos['operacion_id'], $payload, $fecha, $actor);
        }, attempts: 3);
    }

    public function revertir(MovimientoEnvase $seleccionado, array $datos, User $actor): MovimientoEnvase
    {
        $payload = ['origen_id' => $seleccionado->id, 'fecha' => $datos['fecha'], 'motivo' => trim($datos['motivo'])];

        return DB::transaction(function () use ($seleccionado, $datos, $payload, $actor): MovimientoEnvase {
            $temporada = app(ServicioTemporadaActiva::class)->obtener(bloquear: true);
            if ($existente = $this->reintento($datos['operacion_id'], $payload)) {
                return $existente;
            }
            $origen = MovimientoEnvase::query()->lockForUpdate()->findOrFail($seleccionado->id);
            if ($origen->temporada_id !== $temporada->id
                || ! in_array($origen->tipo_movimiento, [TipoMovimientoEnvase::AperturaCuenta, TipoMovimientoEnvase::AjusteCuenta], true)
                || $origen->signo_existencia !== 0 || ! in_array($origen->signo_cuenta, [-1, 1], true)) {
                throw new ConflictoOperacion('Solo se revierten aperturas y ajustes de cuenta de la temporada activa.');
            }
            if (MovimientoEnvase::query()->where('movimiento_origen_id', $origen->id)
                ->whereIn('tipo_movimiento', [TipoMovimientoEnvase::ReversionAjusteCuenta->value, TipoMovimientoEnvase::CorreccionCuenta->value])->exists()) {
                throw new ConflictoOperacion('El asiento ya tiene una reversa o corrección de saldo.');
            }
            $fecha = $this->fecha($payload['fecha'], $temporada);
            if ($fecha->lt($origen->ocurrido_at)) {
                throw ValidationException::withMessages(['fecha' => 'La reversa no puede ser anterior al asiento original.']);
            }

            return $this->crear($datos['operacion_id'], [
                'temporada_id' => $origen->temporada_id, 'cliente_id' => $origen->cliente_id,
                'tipo_envase' => $origen->tipo_envase->value, 'propiedad' => $origen->propiedad->value,
                'tipo_movimiento' => TipoMovimientoEnvase::ReversionAjusteCuenta->value,
                'cantidad' => -$origen->cantidad * $origen->signo_cuenta,
                'referencia' => $origen->numero_documento, 'motivo' => $payload['motivo'],
            ], $fecha, $actor, $origen->id, $payload);
        }, attempts: 3);
    }

    private function reintento(string $operacion, array $payload): ?MovimientoEnvase
    {
        $existente = MovimientoEnvase::query()->where('operacion_id', $operacion)->first();
        if ($existente && ($existente->datos['ajuste_cuenta']['payload_hash'] ?? null) !== $this->hash($payload)) {
            throw new ConflictoOperacion('El identificador de operación ya se usó con otros datos.');
        }

        return $existente;
    }

    private function hash(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function fecha(string $dia, Temporada $temporada): CarbonImmutable
    {
        $fecha = CarbonImmutable::parse($dia, config('app.operational_timezone'))->startOfDay();
        if ($fecha->isFuture() || ($temporada->fecha_inicio && $dia < $temporada->fecha_inicio->toDateString())
            || ($temporada->fecha_fin && $dia > $temporada->fecha_fin->toDateString())) {
            throw ValidationException::withMessages(['fecha' => 'Seleccione un día transcurrido dentro de la temporada activa.']);
        }

        return $fecha->utc();
    }

    private function crear(string $operacion, array $datos, CarbonImmutable $fecha, User $actor, ?string $origen = null, ?array $payload = null): MovimientoEnvase
    {
        return MovimientoEnvase::create([
            'operacion_id' => $operacion, 'temporada_id' => $datos['temporada_id'], 'cliente_id' => $datos['cliente_id'],
            'documento_tipo' => 'ajuste_cuenta_envases', 'numero_documento' => $datos['referencia'],
            'tipo_movimiento' => $datos['tipo_movimiento'], 'tipo_envase' => $datos['tipo_envase'], 'propiedad' => $datos['propiedad'],
            'cantidad' => abs($datos['cantidad']), 'signo_cuenta' => $datos['cantidad'] < 0 ? -1 : 1, 'signo_existencia' => 0,
            'movimiento_origen_id' => $origen, 'ocurrido_at' => $fecha, 'estado_revision' => EstadoRevisionMovimientoEnvase::Pendiente,
            'creado_por_user_id' => $actor->id,
            'datos' => ['ajuste_cuenta' => ['motivo' => $datos['motivo'], 'registrado_at' => now()->toAtomString(), 'payload_hash' => $this->hash($payload ?? $datos)]],
        ]);
    }
}
