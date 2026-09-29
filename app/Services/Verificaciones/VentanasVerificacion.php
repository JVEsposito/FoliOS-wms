<?php

namespace App\Services\Verificaciones;

use Carbon\CarbonImmutable;

final class VentanasVerificacion
{
    /** @return array{inicio: CarbonImmutable, fin: CarbonImmutable, nombre: string} */
    public function actual(?CarbonImmutable $ahora = null): array
    {
        $zona = (string) config('app.operational_timezone', 'America/Santiago');
        $ahora = ($ahora ?? CarbonImmutable::now($zona))->setTimezone($zona);
        [$hora, $minuto] = array_map('intval', explode(':', (string) config('verificaciones.turnos.inicio', '06:00')));
        $duracion = max(1, min(24, (int) config('verificaciones.turnos.duracion_horas', 8)));
        // Cada inicio es una hora local fija; sumar segundos UTC desplazaría
        // los turnos de 06/14/22 durante un cambio de horario de verano.
        $inicios = [];
        foreach ([$ahora->subDay(), $ahora] as $dia) {
            for ($h = 0; $h < 24; $h += $duracion) {
                $total = $hora + $h;
                $inicios[] = $dia->startOfDay()->addDays(intdiv($total, 24))
                    ->setTime($total % 24, $minuto);
            }
        }
        $inicio = collect($inicios)->filter(fn (CarbonImmutable $c) => $c->lte($ahora))
            ->sortByDesc(fn (CarbonImmutable $c) => $c->getTimestamp())->first();
        $totalFin = $inicio->hour + $duracion;
        $fin = $inicio->startOfDay()->addDays(intdiv($totalFin, 24))
            ->setTime($totalFin % 24, $minuto);

        return [
            'inicio' => $inicio->utc(),
            'fin' => $fin->utc(),
            'nombre' => $inicio->format('H:i').'–'.$fin->format('H:i'),
        ];
    }
}
