<?php

namespace App\Rrhh\Asistencia;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final class FechaMarcaje
{
    public static function leer(string $valor): CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/', $valor) !== 1) {
            throw ValidationException::withMessages(['instante' => 'Use fecha ISO 8601 con hora, segundos y zona explícita (Z o ±hh:mm).']);
        }
        try {
            $fecha = CarbonImmutable::parse($valor);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['instante' => 'La fecha del marcaje no es válida.']);
        }
        if ($fecha->format('Y-m-d\TH:i:s') !== substr($valor, 0, 19)) {
            throw ValidationException::withMessages(['instante' => 'La fecha del marcaje no existe.']);
        }

        return $fecha->utc();
    }
}
