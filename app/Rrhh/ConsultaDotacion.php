<?php

namespace App\Rrhh;

use App\Rrhh\Asistencia\MedidasDotacion;
use App\Rrhh\Models\AsignacionPersonal;
use App\Rrhh\Models\Marcaje;
use Brick\Math\BigDecimal;

final class ConsultaDotacion
{
    public function __construct(private MedidasDotacion $medidas) {}

    public function detalle(AsignacionPersonal $asignacion): array
    {
        $marcas = Marcaje::query()->where('persona_id', $asignacion->persona_id)->where('fuente_codigo', $asignacion->fuente_marcaje)
            ->whereBetween('instante_at', [$asignacion->desde_at->subDay(), $asignacion->hasta_at->addDay()])
            ->orderBy('instante_at')->orderBy('id')->get();
        $cantidades = $this->medidas->calcular($asignacion->toArray(), $marcas->toArray());
        $costo = fn (string $horas): ?string => $asignacion->costo_hora === null ? null : (string) BigDecimal::of($horas)->multipliedBy($asignacion->costo_hora)->strippedOfTrailingZeros();

        return [...$asignacion->toArray(), ...$cantidades, 'costo_programado' => $costo($cantidades['horas_programadas']),
            'costo_asistencia' => $cantidades['asistencia_valida'] ? $costo($cantidades['horas_asistidas']) : null];
    }
}
