<?php

namespace App\Services\Frigorifico;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class CalculadoraJornada
{
    public function fecha(string $valor, string $campo): CarbonImmutable
    {
        $fecha = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $valor, config('app.operational_timezone', 'America/Santiago'));
        if ($fecha->format('Y-m-d\TH:i') !== $valor || $fecha->isFuture()) {
            throw ValidationException::withMessages([$campo => 'Ingresa una fecha y hora válida de planta, sin anticipar marcaciones.']);
        }

        return $fecha->utc();
    }

    public function calcular(CarbonImmutable $ingreso, ?CarbonImmutable $salida, int $base, int $colacion): array
    {
        if (! $salida) {
            return ['minutos_trabajados' => null, 'minutos_extra' => null];
        }
        $minutos = (int) floor(($salida->getTimestamp() - $ingreso->getTimestamp()) / 60);
        if ($minutos <= 0 || $minutos > 36 * 60 || $colacion >= $minutos) {
            throw ValidationException::withMessages(['salida' => 'La salida debe ser posterior al ingreso, dentro de 36 horas; la colación debe ser menor al tiempo de permanencia.']);
        }
        $trabajados = $minutos - $colacion;

        return ['minutos_trabajados' => $trabajados, 'minutos_extra' => max(0, $trabajados - $base)];
    }
}
