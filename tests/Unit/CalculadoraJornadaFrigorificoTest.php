<?php

namespace Tests\Unit;

use App\Services\Frigorifico\CalculadoraJornada;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CalculadoraJornadaFrigorificoTest extends TestCase
{
    public function test_turno_nocturno_descontando_colacion_sin_redondear_horas_extra(): void
    {
        $r = (new CalculadoraJornada)->calcular(CarbonImmutable::parse('2026-11-04T20:00:00-03:00'), CarbonImmutable::parse('2026-11-05T06:17:00-03:00'), 480, 60);
        $this->assertSame(['minutos_trabajados' => 557, 'minutos_extra' => 77], $r);
    }

    public function test_pendientes_jornada_corta_y_dia_extra_completo(): void
    {
        $c = new CalculadoraJornada;
        $inicio = CarbonImmutable::parse('2026-11-04T08:00:00-03:00');
        $this->assertSame(['minutos_trabajados' => null, 'minutos_extra' => null], $c->calcular($inicio, null, 480, 0));
        $this->assertSame(['minutos_trabajados' => 360, 'minutos_extra' => 0], $c->calcular($inicio, $inicio->addHours(6), 480, 0));
        $this->assertSame(['minutos_trabajados' => 360, 'minutos_extra' => 360], $c->calcular($inicio, $inicio->addHours(6), 0, 0));
    }

    public function test_calcula_tiempo_real_al_cruzar_el_cambio_de_hora(): void
    {
        $c = new CalculadoraJornada;
        $this->assertSame(480, $c->calcular(CarbonImmutable::parse('2026-09-05T20:00:00-04:00'), CarbonImmutable::parse('2026-09-06T05:00:00-03:00'), 480, 0)['minutos_trabajados']);
    }

    public function test_rechaza_salida_antes_del_ingreso_y_colacion_excesiva(): void
    {
        $c = new CalculadoraJornada;
        $inicio = CarbonImmutable::parse('2026-11-04T08:00:00Z');
        foreach ([[$inicio->subMinute(), 0], [$inicio, 0], [$inicio->addHours(37), 0], [$inicio->addHour(), 60]] as [$fin, $colacion]) {
            try {
                $c->calcular($inicio, $fin, 480, $colacion);
                $this->fail('El intervalo inválido debía rechazarse.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('salida', $e->errors());
            }
        }
    }

    public function test_hora_inexistente_de_planta_y_marcacion_futura_se_rechazan(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-06T12:00:00Z'));
        foreach (['2026-09-06T00:30', '2026-11-07T12:00'] as $fecha) {
            try {
                (new CalculadoraJornada)->fecha($fecha, 'ingreso');
                $this->fail('La fecha inválida debía rechazarse.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('ingreso', $e->errors());
            }
        }
    }
}
