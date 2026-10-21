<?php

namespace App\Rrhh\Asistencia;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/** Cálculo común de cantidades. No consulta bases ni determina cobros ni remuneraciones. */
final class MedidasDotacion
{
    public function __construct(private IntervalosAsistencia $intervalos) {}

    public function calcular(array $asignacion, iterable $marcajes): array
    {
        $desde = CarbonImmutable::parse($asignacion['desde_at'], 'UTC');
        $hasta = CarbonImmutable::parse($asignacion['hasta_at'], 'UTC');
        $programados = max(0, $hasta->getTimestamp() - $desde->getTimestamp() - $asignacion['descanso_programado_minutos'] * 60);
        $asistencia = $this->intervalos->calcular($marcajes, $desde, $hasta);
        // Las pausas reales se respaldan con salida/entrada. No se descuenta además la pausa programada.
        $horas = fn (int $segundos): string => (string) BigDecimal::of($segundos)->dividedBy(3600, 6, RoundingMode::HalfUp)->strippedOfTrailingZeros();

        return ['horas_programadas' => $horas($programados), 'horas_asistidas' => $horas($asistencia['segundos']),
            'jornadas_programadas' => '1', 'jornadas_asistidas' => $asistencia['valida'] && $asistencia['segundos'] > 0 ? '1' : '0',
            'asistencia_valida' => $asistencia['valida'], 'incidencias' => $asistencia['incidencias']];
    }
}
