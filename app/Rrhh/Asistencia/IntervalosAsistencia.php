<?php

namespace App\Rrhh\Asistencia;

use Carbon\CarbonImmutable;

/** Función sin acceso a bases ni escritura: reutilizable por los lectores de dotación. */
final class IntervalosAsistencia
{
    public function calcular(iterable $marcajes, CarbonImmutable $desde, CarbonImmutable $hasta): array
    {
        $entrada = null;
        $segundos = 0;
        $incidencias = [];
        $vistos = 0;
        $anterior = null;
        foreach ($marcajes as $marca) {
            if (($marca['anulado_at'] ?? null) !== null) {
                continue;
            }
            $hora = CarbonImmutable::parse($marca['instante_at'], 'UTC');
            if ($hora >= $desde && $hora <= $hasta) {
                $vistos++;
            }
            if ($anterior !== null && $hora->equalTo($anterior) && $hora >= $desde && $hora <= $hasta) {
                $incidencias[] = 'marcajes_simultaneos';
            }
            $anterior = $hora;
            if ($marca['tipo'] === 'entrada') {
                if ($entrada !== null && $entrada < $hasta && $hora >= $desde) {
                    $incidencias[] = 'entradas_consecutivas';
                }
                $entrada ??= $hora;
            } else {
                if ($entrada === null) {
                    if ($hora > $desde && $hora <= $hasta) {
                        $incidencias[] = 'salida_sin_entrada';
                    }
                } else {
                    $inicio = max($entrada->getTimestamp(), $desde->getTimestamp());
                    $fin = min($hora->getTimestamp(), $hasta->getTimestamp());
                    $segundos += max(0, $fin - $inicio);
                    $entrada = null;
                }
            }
        }
        if ($entrada !== null && $entrada < $hasta) {
            $incidencias[] = 'entrada_sin_salida';
        }
        if ($segundos === 0 && $vistos === 0) {
            $incidencias[] = 'sin_marcajes';
        }

        return ['segundos' => $segundos, 'valida' => $incidencias === [], 'incidencias' => array_values(array_unique($incidencias))];
    }
}
