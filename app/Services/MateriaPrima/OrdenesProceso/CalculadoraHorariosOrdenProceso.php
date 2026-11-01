<?php

namespace App\Services\MateriaPrima\OrdenesProceso;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Horarios estimativos de una orden de proceso, como los calcula hoy la PRE-SIF:
 * cada lote dura sus kilos divididos por el rendimiento de la línea, en cadena desde la
 * hora de inicio. La colación se salta una vez: si cae dentro de un lote lo alarga, y si
 * un lote empezaría dentro de ella, parte al terminar.
 */
class CalculadoraHorariosOrdenProceso
{
    /**
     * @param  array<int, float>  $kilos  Kilos de cada lote, en secuencia.
     * @return array<int, array{inicio: CarbonImmutable, termino: CarbonImmutable}>
     */
    public function calcular(
        CarbonImmutable $fecha,
        string $horaInicio,
        ?string $colacionDesde,
        ?string $colacionHasta,
        float $rendimientoKgHora,
        array $kilos,
    ): array {
        if ($rendimientoKgHora <= 0) {
            throw new InvalidArgumentException('El rendimiento de la línea debe ser mayor que cero.');
        }

        $cursor = $this->enFecha($fecha, $horaInicio);
        [$colacionInicio, $colacionFin] = $this->colacion($cursor, $colacionDesde, $colacionHasta);
        $horarios = [];

        foreach ($kilos as $indice => $kilosLote) {
            if ($colacionInicio && $cursor->greaterThanOrEqualTo($colacionInicio)) {
                $cursor = $cursor->max($colacionFin);
                $colacionInicio = null;
            }

            $segundos = (int) round(max(0.0, $kilosLote) / $rendimientoKgHora * 3600);
            $termino = $cursor->addSeconds($segundos);
            if ($colacionInicio && $colacionInicio->lessThan($termino)) {
                $termino = $termino->addSeconds((int) $colacionInicio->diffInSeconds($colacionFin, true));
                $colacionInicio = null;
            }

            $horarios[$indice] = ['inicio' => $cursor, 'termino' => $termino];
            $cursor = $termino;
        }

        return $horarios;
    }

    /** @return array{0: CarbonImmutable|null, 1: CarbonImmutable|null} */
    private function colacion(CarbonImmutable $inicioOrden, ?string $desde, ?string $hasta): array
    {
        if (blank($desde) || blank($hasta)) {
            return [null, null];
        }

        $inicio = $this->enFecha($inicioOrden->startOfDay(), $desde);
        if ($inicio->lessThan($inicioOrden)) {
            $inicio = $inicio->addDay();
        }
        $fin = $this->enFecha($inicio->startOfDay(), $hasta);
        if ($fin->lessThanOrEqualTo($inicio)) {
            $fin = $fin->addDay();
        }

        return [$inicio, $fin];
    }

    private function enFecha(CarbonImmutable $fecha, string $hora): CarbonImmutable
    {
        [$h, $m, $s] = array_pad(array_map('intval', explode(':', $hora)), 3, 0);

        return $fecha->startOfDay()->setTime($h, $m, $s);
    }
}
