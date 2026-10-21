<?php

namespace App\Finanzas\Proveedores;

use App\Finanzas\EsquemaFinanzas;
use App\Finanzas\Models\EventoCompra;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final class RegistroCompra
{
    public function __construct(private readonly EsquemaFinanzas $esquema) {}

    public function exclusivo(string $clave, callable $accion): mixed
    {
        if (! $this->esquema->tomarCandado('compras-'.$clave)) {
            throw ValidationException::withMessages(['operacion' => 'Hay otra operación en curso. Reintente con los mismos datos.']);
        }
        try {
            return $this->esquema->transaccion($accion);
        } finally {
            $this->esquema->liberarCandado('compras-'.$clave);
        }
    }

    public function evento(string $entidad, string $id, string $accion, array $datos, User $usuario): void
    {
        EventoCompra::query()->create(['entidad' => $entidad, 'entidad_id' => $id, 'accion' => $accion, 'datos' => $datos,
            'user_id' => $usuario->id, 'user_nombre' => $usuario->name, 'created_at' => now()]);
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
}
