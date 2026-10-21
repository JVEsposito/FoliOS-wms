<?php

namespace Tests\Unit;

use App\Rrhh\Asistencia\IntervalosAsistencia;
use App\Rrhh\Asistencia\MedidasDotacion;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class IntervalosAsistenciaTest extends TestCase
{
    public function test_recorta_el_turno_nocturno_y_omite_anulados(): void
    {
        $r = $this->calcular([['tipo' => 'entrada', 'instante_at' => '2026-11-01T21:55:00-03:00'], ['tipo' => 'salida', 'instante_at' => '2026-11-01T23:00:00-03:00', 'anulado_at' => '2026-11-02'], ['tipo' => 'salida', 'instante_at' => '2026-11-02T06:05:00-03:00']]);
        $this->assertSame(28800, $r['segundos']);
        $this->assertTrue($r['valida']);
    }

    public function test_no_adivina_horas_cuando_falta_una_salida_o_hay_entradas_repetidas(): void
    {
        $entrada = ['tipo' => 'entrada', 'instante_at' => '2026-11-01T22:00:00-03:00'];
        $this->assertFalse($this->calcular([$entrada])['valida']);
        $this->assertContains('sin_marcajes', $this->calcular([])['incidencias']);
        $this->assertContains('entradas_consecutivas', $this->calcular([$entrada, ['tipo' => 'entrada', 'instante_at' => '2026-11-01T23:00:00-03:00'], ['tipo' => 'salida', 'instante_at' => '2026-11-02T06:00:00-03:00']])['incidencias']);
    }

    public function test_descuenta_pausas_reales_sin_descontar_dos_veces_el_descanso_programado(): void
    {
        $a = ['desde_at' => '2026-11-02T01:00:00Z', 'hasta_at' => '2026-11-02T09:00:00Z', 'descanso_programado_minutos' => 30];
        $r = (new MedidasDotacion(new IntervalosAsistencia))->calcular($a, [
            ['tipo' => 'entrada', 'instante_at' => '2026-11-02T01:00:00Z'], ['tipo' => 'salida', 'instante_at' => '2026-11-02T05:00:00Z'],
            ['tipo' => 'entrada', 'instante_at' => '2026-11-02T05:30:00Z'], ['tipo' => 'salida', 'instante_at' => '2026-11-02T09:00:00Z'],
        ]);
        $this->assertSame('7.5', $r['horas_programadas']);
        $this->assertSame('7.5', $r['horas_asistidas']);
        $this->assertTrue($r['asistencia_valida']);
    }

    public function test_calcula_horas_transcurridas_reales_durante_un_cambio_de_huso(): void
    {
        $r = (new MedidasDotacion(new IntervalosAsistencia))->calcular(['desde_at' => '2026-04-04T22:00:00-03:00', 'hasta_at' => '2026-04-05T06:00:00-04:00', 'descanso_programado_minutos' => 0], []);
        $this->assertSame('9', $r['horas_programadas']);
    }

    private function calcular(array $marcas): array
    {
        return (new IntervalosAsistencia)->calcular($marcas, CarbonImmutable::parse('2026-11-01T22:00:00-03:00'), CarbonImmutable::parse('2026-11-02T06:00:00-03:00'));
    }
}
