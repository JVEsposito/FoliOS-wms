<?php

namespace App\Rrhh\Asistencia;

use App\Models\User;
use App\Rrhh\Auditoria;
use App\Rrhh\EsquemaRrhh;
use App\Rrhh\Models\Marcaje;
use App\Rrhh\Models\Persona;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ServicioMarcajes
{
    public function __construct(private EsquemaRrhh $esquema, private Auditoria $auditoria) {}

    public function propio(array $datos, User $actor): Marcaje
    {
        $persona = Persona::query()->where('codigo', $datos['codigo'])->where('activa', true)->first();
        if ($persona === null || $persona->pin_hash === null || ! Hash::check($datos['pin'], $persona->pin_hash)) {
            throw ValidationException::withMessages(['pin' => 'Código o PIN no válido.']);
        }
        if ($persona->fuente_marcaje !== 'folios') {
            throw ValidationException::withMessages(['codigo' => 'La persona registra su asistencia en la fuente externa asignada.']);
        }
        // El reloj es el servidor. Repetir el UUID devuelve el recibo original, sin cambiar la hora.
        $anterior = Marcaje::query()->where('fuente_codigo', 'folios')->where('id_externo', $datos['operacion_id'])->first();
        if ($anterior !== null) {
            if ($anterior->persona_id !== $persona->id || $anterior->tipo !== $datos['tipo']) {
                throw ValidationException::withMessages(['operacion_id' => 'La solicitud ya pertenece a otro marcaje.']);
            }

            return $anterior;
        }

        return $this->registrar($persona, 'folios', $datos['operacion_id'], $datos['tipo'], CarbonImmutable::now('UTC'), 'propio', $actor);
    }

    /** @return array{marcaje: Marcaje, repetido: bool} */
    public function importar(Persona $persona, string $fuente, array $fila, User $actor): array
    {
        $fecha = FechaMarcaje::leer($fila['instante']);
        $existia = Marcaje::query()->where('fuente_codigo', $fuente)->where('id_externo', $fila['id_externo'])->exists();

        return ['marcaje' => $this->registrar($persona, $fuente, $fila['id_externo'], $fila['tipo'], $fecha, 'integracion', $actor), 'repetido' => $existia];
    }

    private function registrar(Persona $persona, string $fuente, string $externo, string $tipo, CarbonImmutable $instante, string $canal, User $actor): Marcaje
    {
        if ($instante->isAfter(CarbonImmutable::now('UTC')->addMinutes(5))) {
            throw ValidationException::withMessages(['instante' => 'No se admiten marcajes futuros.']);
        }
        $hash = hash('sha256', json_encode([$persona->id, $fuente, $externo, $tipo, $instante->format('Y-m-d H:i:s.u'), $canal], JSON_THROW_ON_ERROR));

        return $this->conPersona($persona->id, function () use ($persona, $fuente, $externo, $tipo, $instante, $canal, $actor, $hash): Marcaje {
            $anterior = Marcaje::query()->where('fuente_codigo', $fuente)->where('id_externo', $externo)->first();
            if ($anterior !== null) {
                // Dos solicitudes simultáneas del reloj pueden diferir solo en su hora del servidor.
                $reintentoPropio = $canal === 'propio' && $anterior->canal === 'propio' && $anterior->persona_id === $persona->id && $anterior->tipo === $tipo;
                if (! $reintentoPropio && ! hash_equals($anterior->payload_hash, $hash)) {
                    throw ValidationException::withMessages(['id_externo' => 'El identificador ya existe con contenido distinto; requiere corrección con motivo.']);
                }

                return $anterior;
            }
            $marca = Marcaje::query()->create(['id' => (string) Str::uuid(), 'persona_id' => $persona->id, 'fuente_codigo' => $fuente,
                'id_externo' => $externo, 'payload_hash' => $hash, 'tipo' => $tipo, 'instante_at' => $instante->format('Y-m-d H:i:s.u'), 'canal' => $canal]);
            $this->auditoria->registrar('marcaje', $marca->id, 'registrado', $marca->toArray(), $actor);

            return $marca;
        });
    }

    public function corregir(Marcaje $original, array $datos, User $actor): Marcaje
    {
        $fecha = isset($datos['instante']) ? FechaMarcaje::leer($datos['instante']) : null;
        if ($fecha?->isAfter(CarbonImmutable::now('UTC')->addMinutes(5))) {
            throw ValidationException::withMessages(['instante' => 'No se admiten marcajes futuros.']);
        }

        return $this->conPersona($original->persona_id, function () use ($original, $datos, $fecha, $actor): Marcaje {
            $marca = Marcaje::query()->lockForUpdate()->findOrFail($original->id);
            if ($marca->anulado_at !== null) {
                throw ValidationException::withMessages(['marcaje' => 'El registro ya fue anulado; consulte su historial.']);
            }
            $marca->update(['anulado_at' => now()]);
            $nuevo = null;
            if ($fecha !== null) {
                $nuevo = Marcaje::query()->create(['id' => (string) Str::uuid(), 'persona_id' => $marca->persona_id, 'fuente_codigo' => $marca->fuente_codigo,
                    'id_externo' => 'correccion:'.Str::uuid(), 'payload_hash' => hash('sha256', json_encode($datos, JSON_THROW_ON_ERROR)),
                    'tipo' => $datos['tipo'], 'instante_at' => $fecha->format('Y-m-d H:i:s.u'), 'canal' => 'correccion', 'corrige_id' => $marca->id]);
            }
            $this->auditoria->registrar('marcaje', $marca->id, $nuevo ? 'corregido' : 'anulado', ['motivo' => $datos['motivo'], 'original' => $marca->toArray(), 'reemplazo' => $nuevo?->toArray()], $actor);

            return $nuevo ?? $marca;
        });
    }

    private function conPersona(string $id, callable $hacer): mixed
    {
        $candado = 'marcajes-'.substr(hash('sha256', $id), 0, 32);
        if (! $this->esquema->tomarCandado($candado, 3)) {
            throw ValidationException::withMessages(['marcaje' => 'El reloj está ocupado; reintente con la misma solicitud.']);
        }
        try {
            return $this->esquema->transaccion($hacer);
        } finally {
            $this->esquema->liberarCandado($candado);
        }
    }
}
