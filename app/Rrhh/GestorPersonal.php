<?php

namespace App\Rrhh;

use App\Models\User;
use App\Rrhh\Asistencia\FechaMarcaje;
use App\Rrhh\Models\AsignacionPersonal;
use App\Rrhh\Models\FuenteMarcaje;
use App\Rrhh\Models\Persona;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class GestorPersonal
{
    public function __construct(private EsquemaRrhh $esquema, private Auditoria $auditoria, private LectorOperacion $operacion) {}

    public function persona(array $datos, ?Persona $persona, User $actor): Persona
    {
        return $this->esquema->transaccion(function () use ($datos, $persona, $actor): Persona {
            $existente = $persona !== null;
            $persona = $existente ? Persona::query()->lockForUpdate()->findOrFail($persona->id) : new Persona(['id' => (string) Str::uuid()]);
            if ($existente && $persona->version !== (int) $datos['version']) {
                throw ValidationException::withMessages(['version' => 'La persona cambió. Recargue antes de guardar.']);
            }
            if ($datos['fuente_marcaje'] !== 'folios' && ! FuenteMarcaje::query()->where('codigo', $datos['fuente_marcaje'])->where('activa', true)->exists()) {
                throw ValidationException::withMessages(['fuente_marcaje' => 'Seleccione una fuente activa de marcajes.']);
            }
            if (! $existente && Persona::query()->where('codigo', $datos['codigo'])->exists()) {
                throw ValidationException::withMessages(['codigo' => 'El código ya pertenece a otra persona.']);
            }
            if ($existente && $persona->codigo !== $datos['codigo']) {
                throw ValidationException::withMessages(['codigo' => 'El código de la persona es permanente.']);
            }
            $pin = $datos['pin'] ?? null;
            unset($datos['pin'], $datos['version']);
            $persona->fill($datos);
            if ($pin !== null && $pin !== '') {
                $persona->pin_hash = Hash::make($pin);
            }
            $persona->version = $existente ? $persona->version + 1 : 1;
            $persona->save();
            $this->auditoria->registrar('persona', $persona->id, $existente ? 'actualizada' : 'creada', [...$persona->toArray(), 'pin_modificado' => $pin !== null && $pin !== ''], $actor);

            return $persona;
        });
    }

    public function asignar(array $datos, User $actor): AsignacionPersonal
    {
        $desde = FechaMarcaje::leer($datos['desde']);
        $hasta = FechaMarcaje::leer($datos['hasta']);
        $segundos = $hasta->getTimestamp() - $desde->getTimestamp();
        if ($segundos < 60 || $segundos > config('rrhh.maximo_horas_asignacion') * 3600 || $segundos <= $datos['descanso_programado_minutos'] * 60) {
            throw ValidationException::withMessages(['hasta' => 'La asignación debe durar entre 1 minuto y 24 horas; su descanso debe ser menor que la duración.']);
        }
        $datos['desde_at'] = $desde->format('Y-m-d H:i:s');
        $datos['hasta_at'] = $hasta->format('Y-m-d H:i:s');
        unset($datos['desde'], $datos['hasta']);
        $datos['costo_hora'] = isset($datos['costo_hora']) ? (string) BigDecimal::of($datos['costo_hora'])->strippedOfTrailingZeros() : null;
        ksort($datos);
        $hash = hash('sha256', json_encode($datos, JSON_THROW_ON_ERROR));
        $candado = 'persona-'.substr(hash('sha256', $datos['persona_id']), 0, 32);
        if (! $this->esquema->tomarCandado($candado, 3)) {
            throw ValidationException::withMessages(['persona_id' => 'La persona está siendo actualizada. Reintente.']);
        }
        try {
            return $this->esquema->transaccion(function () use ($datos, $hash, $actor): AsignacionPersonal {
                $anterior = AsignacionPersonal::query()->where('operacion_id', $datos['operacion_id'])->first();
                if ($anterior !== null) {
                    if (! hash_equals($anterior->payload_hash, $hash)) {
                        throw ValidationException::withMessages(['operacion_id' => 'La solicitud ya se usó con otros datos.']);
                    }

                    return $anterior;
                }
                $persona = Persona::query()->lockForUpdate()->findOrFail($datos['persona_id']);
                if (! $persona->activa) {
                    throw ValidationException::withMessages(['persona_id' => 'La persona está inactiva.']);
                }
                if (AsignacionPersonal::query()->where('persona_id', $persona->id)->where('estado', '!=', 'cancelada')->where('desde_at', '<', $datos['hasta_at'])->where('hasta_at', '>', $datos['desde_at'])->exists()) {
                    throw ValidationException::withMessages(['desde' => 'La persona ya tiene una asignación en ese horario.']);
                }
                $cliente = isset($datos['cliente_id']) ? $this->operacion->nombreCliente($datos['cliente_id']) : null;
                if (isset($datos['cliente_id']) && $cliente === null) {
                    throw ValidationException::withMessages(['cliente_id' => 'El cliente no está disponible.']);
                }
                $asignacion = AsignacionPersonal::query()->create([...$datos, 'id' => (string) Str::uuid(), 'payload_hash' => $hash,
                    'persona_nombre' => $persona->nombre, 'fuente_marcaje' => $persona->fuente_marcaje, 'cliente_nombre' => $cliente]);
                $this->auditoria->registrar('asignacion', $asignacion->id, 'programada', $asignacion->toArray(), $actor);

                return $asignacion;
            });
        } finally {
            $this->esquema->liberarCandado($candado);
        }
    }

    public function estado(AsignacionPersonal $asignacion, array $datos, User $actor): AsignacionPersonal
    {
        $candado = 'persona-'.substr(hash('sha256', $asignacion->persona_id), 0, 32);
        if (! $this->esquema->tomarCandado($candado, 3)) {
            throw ValidationException::withMessages(['estado' => 'La persona está siendo actualizada. Reintente.']);
        }
        try {
            return $this->esquema->transaccion(function () use ($asignacion, $datos, $actor): AsignacionPersonal {
                $fila = AsignacionPersonal::query()->lockForUpdate()->findOrFail($asignacion->id);
                if ($fila->version !== (int) $datos['version']) {
                    throw ValidationException::withMessages(['version' => 'La asignación cambió. Recargue.']);
                }
                if ($fila->estado === 'cancelada' && $datos['estado'] !== 'cancelada') {
                    throw ValidationException::withMessages(['estado' => 'Una asignación cancelada conserva su historial. Cree otra si se requiere.']);
                }
                $antes = $fila->estado;
                $fila->update(['estado' => $datos['estado'], 'version' => $fila->version + 1]);
                $this->auditoria->registrar('asignacion', $fila->id, 'estado', ['antes' => $antes, ...$datos], $actor);

                return $fila;
            });
        } finally {
            $this->esquema->liberarCandado($candado);
        }
    }
}
