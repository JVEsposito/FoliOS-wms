<?php

namespace App\Rrhh;

use App\Models\User;
use App\Rrhh\Models\EventoRrhh;

final class Auditoria
{
    public function registrar(string $entidad, string $id, string $accion, array $datos, ?User $actor): void
    {
        EventoRrhh::query()->create(['entidad' => $entidad, 'registro_id' => $id, 'accion' => $accion,
            'datos' => $datos, 'user_id' => $actor?->id, 'user_nombre' => $actor?->nombre, 'created_at' => now()]);
    }
}
