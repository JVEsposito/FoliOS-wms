<?php

namespace Tests\Unit;

use App\Services\Verificaciones\VentanasVerificacion;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class VentanasVerificacionTest extends TestCase
{
    public function test_los_tres_turnos_cruzan_medianoche_sin_perder_la_hora_local(): void
    {
        config(['verificaciones.turnos.inicio' => '06:00', 'verificaciones.turnos.duracion_horas' => 8]);
        $ventanas = app(VentanasVerificacion::class);

        foreach (['05:59' => '22:00–06:00', '06:00' => '06:00–14:00',
            '13:59' => '06:00–14:00', '14:00' => '14:00–22:00',
            '22:00' => '22:00–06:00'] as $hora => $esperado) {
            $this->assertSame($esperado, $ventanas->actual(
                CarbonImmutable::parse("2026-09-29 {$hora}", 'America/Santiago'),
            )['nombre']);
        }
    }

    public function test_los_turnos_mantienen_su_hora_local_al_cambiar_el_horario_de_verano(): void
    {
        config(['verificaciones.turnos.inicio' => '06:00', 'verificaciones.turnos.duracion_horas' => 8]);
        $ventanas = app(VentanasVerificacion::class);

        foreach (['2026-09-06 06:00', '2026-04-05 06:00'] as $fecha) {
            $this->assertSame('06:00–14:00', $ventanas->actual(
                CarbonImmutable::parse($fecha, 'America/Santiago'),
            )['nombre']);
        }
    }
}
