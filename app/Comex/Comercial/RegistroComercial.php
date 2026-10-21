<?php

namespace App\Comex\Comercial;

use App\Comex\EsquemaComex;
use App\Comex\Models\EventoComercial;
use App\Comex\Models\OperacionComercial;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final class RegistroComercial
{
    public function __construct(private EsquemaComex $esquema) {}

    public function operar(string $id, string $accion, array $datos, callable $fn): array
    {
        $hash = $this->hash($datos);
        if (! $this->esquema->tomarCandado('comercial')) {
            $this->error('operacion_id', 'Hay otra operación comercial en curso. Reintente con los mismos datos.');
        }
        try {
            return $this->esquema->transaccion(function () use ($id, $accion, $hash, $fn) {
                $previa = OperacionComercial::query()->find($id);
                if ($previa) {
                    if ($previa->accion !== $accion || $previa->payload_hash !== $hash) {
                        $this->error('operacion_id', 'Este identificador ya corresponde a otra solicitud.');
                    }

                    return $previa->resultado;
                }
                $resultado = $fn();
                OperacionComercial::query()->create(['id' => $id, 'accion' => $accion, 'payload_hash' => $hash, 'resultado' => $resultado, 'created_at' => now()]);

                return $resultado;
            });
        } finally {
            $this->esquema->liberarCandado('comercial');
        }
    }

    public function evento(string $entidad, string $id, string $accion, array $datos, User $usuario): void
    {
        EventoComercial::query()->create(['entidad' => $entidad, 'entidad_id' => $id, 'accion' => $accion, 'datos' => $datos, 'user_id' => $usuario->id, 'user_nombre' => $usuario->name, 'created_at' => now()]);
    }

    public function hash(array $datos): string
    {
        $ordenar = function (array $valor) use (&$ordenar): array {
            if (! array_is_list($valor)) {
                ksort($valor);
            }
            foreach ($valor as &$item) {
                if (is_array($item)) {
                    $item = $ordenar($item);
                }
            }

            return $valor;
        };

        return hash('sha256', json_encode($ordenar($datos), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function error(string $campo, string $mensaje): never
    {
        throw ValidationException::withMessages([$campo => $mensaje]);
    }
}
