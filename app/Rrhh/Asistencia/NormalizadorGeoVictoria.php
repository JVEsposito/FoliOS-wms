<?php

namespace App\Rrhh\Asistencia;

use App\Rrhh\Integracion\FechaGeoVictoria;
use Illuminate\Validation\ValidationException;

/** Adaptador de registros Punch documentados. La conexión HTTP se implementa aparte. */
final class NormalizadorGeoVictoria
{
    public function normalizar(array $fila, ?string $zona = null): array
    {
        $tipo = match ($fila['Type'] ?? $fila['type'] ?? null) {
            'Ingreso', 'entry' => 'entrada', 'Salida', 'exit' => 'salida', default => null
        };
        $id = $fila['id_punch'] ?? null;
        $persona = $fila['UserIdentifier'] ?? $fila['identifier'] ?? null;
        if ($tipo === null || ! is_string($id) || $id === '' || ! is_string($persona) || $persona === '') {
            throw ValidationException::withMessages(['fila' => 'Se requieren id_punch estable, identificador externo y tipo Ingreso/Salida o entry/exit.']);
        }

        return ['id_externo' => $id, 'identificador' => $persona, 'tipo' => $tipo, 'instante' => FechaGeoVictoria::normalizar(is_string($fila['Date'] ?? null) ? $fila['Date'] : '', $zona)];
    }
}
