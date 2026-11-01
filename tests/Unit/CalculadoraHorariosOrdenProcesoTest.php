<?php

namespace Tests\Unit;

use App\Services\MateriaPrima\OrdenesProceso\CalculadoraHorariosOrdenProceso;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class CalculadoraHorariosOrdenProcesoTest extends TestCase
{
    public function test_encadena_los_lotes_con_el_rendimiento_de_la_linea_como_la_pre_sif(): void
    {
        // Kilos del turno 2, línea 1 de la PRE-SIF de ejemplo: 6.000 kg/h desde las 17:00.
        $horarios = (new CalculadoraHorariosOrdenProceso)->calcular(
            CarbonImmutable::parse('2025-12-05'), '17:00', null, null, 6000,
            [3300, 15030, 14140, 1590],
        );

        $this->assertSame('2025-12-05 17:00:00', $horarios[0]['inicio']->toDateTimeString());
        $this->assertSame('2025-12-05 17:33:00', $horarios[0]['termino']->toDateTimeString());
        $this->assertSame('2025-12-05 20:03:18', $horarios[1]['termino']->toDateTimeString());
        $this->assertSame('2025-12-05 22:24:42', $horarios[2]['termino']->toDateTimeString());
        $this->assertSame('2025-12-05 22:40:36', $horarios[3]['termino']->toDateTimeString());
    }

    public function test_la_colacion_alarga_el_lote_que_la_cruza_y_sigue_despues_de_medianoche(): void
    {
        $horarios = (new CalculadoraHorariosOrdenProceso)->calcular(
            CarbonImmutable::parse('2025-12-05'), '22:00', '23:30', '00:30', 6000,
            [6000, 6000],
        );

        $this->assertSame('2025-12-05 23:00:00', $horarios[0]['termino']->toDateTimeString());
        $this->assertSame('2025-12-05 23:00:00', $horarios[1]['inicio']->toDateTimeString());
        $this->assertSame('2025-12-06 01:00:00', $horarios[1]['termino']->toDateTimeString());
    }

    public function test_un_lote_que_empezaria_en_colacion_parte_al_volver(): void
    {
        $horarios = (new CalculadoraHorariosOrdenProceso)->calcular(
            CarbonImmutable::parse('2026-10-28'), '10:30', '11:30', '12:30', 6000,
            [6000, 3000],
        );

        $this->assertSame('2026-10-28 11:30:00', $horarios[0]['termino']->toDateTimeString());
        $this->assertSame('2026-10-28 12:30:00', $horarios[1]['inicio']->toDateTimeString());
        $this->assertSame('2026-10-28 13:00:00', $horarios[1]['termino']->toDateTimeString());
    }

    public function test_una_colacion_anterior_al_inicio_se_toma_del_dia_siguiente(): void
    {
        $horarios = (new CalculadoraHorariosOrdenProceso)->calcular(
            CarbonImmutable::parse('2026-10-28'), '15:00', '11:30', '12:30', 6000,
            [12000],
        );

        $this->assertSame('2026-10-28 17:00:00', $horarios[0]['termino']->toDateTimeString());
    }

    public function test_rechaza_un_rendimiento_sin_valor(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new CalculadoraHorariosOrdenProceso)->calcular(CarbonImmutable::parse('2026-10-28'), '08:00', null, null, 0, [100]);
    }
}
