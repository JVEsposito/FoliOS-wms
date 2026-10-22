<?php

namespace Tests\Unit\Finanzas;

use App\Finanzas\Valorizacion\HistorialStock;
use DomainException;
use PHPUnit\Framework\TestCase;

class HistorialStockTest extends TestCase
{
    public function test_conserva_cantidades_historicas_y_no_cobra_el_dia_de_salida_a_medianoche(): void
    {
        $h = (new HistorialStock)->construir('2026-11-01 12:00:00', '120', '0', [
            $this->movimiento('1', '2026-11-01 13:00:00', '0', '120'),
            $this->movimiento('2', '2026-11-03 15:00:00', '120', '80'),
            $this->movimiento('3', '2026-11-05 03:00:00', '80', '0'),
        ], 'America/Santiago');
        $this->assertSame('120', $h['picos_diarios']['2026-11-03']['unidades']);
        $this->assertSame('0', $h['picos_diarios']['2026-11-05']['unidades']);
        $this->assertSame('2026-11-05T03:00:00+00:00', $h['fin']->toIso8601String());
        $this->assertSame(['0', '120', '80', '0'], array_column(array_column($h['tramos'], 'medidas'), 'unidades'));
    }

    public function test_ordena_movimientos_simultaneos_por_sus_saldos_y_conserva_el_maximo_del_dia(): void
    {
        $h = (new HistorialStock)->construir('2026-11-01 12:00:00', '120', '40', [
            $this->movimiento('a', '2026-11-02 15:00:00', '120', '40'),
            $this->movimiento('z', '2026-11-02 15:00:00', '80', '120'),
            $this->movimiento('b', '2026-11-02 15:00:00', '120', '80'),
        ], 'America/Santiago');
        $this->assertSame(['b', 'z', 'a'], array_column($h['aportes'], 'id'));
        $this->assertSame('120', $h['picos_diarios']['2026-11-02']['unidades']);
        $this->assertNull($h['fin']);
    }

    public function test_un_saldo_actual_sin_movimiento_no_reescribe_los_dias_anteriores(): void
    {
        $this->expectException(DomainException::class);
        (new HistorialStock)->construir('2026-11-01 12:00:00', '120', '60', [$this->movimiento('1', '2026-11-02 15:00:00', '120', '80')], 'America/Santiago');
    }

    public function test_los_saldos_discontinuos_se_detienen_aunque_el_saldo_final_coincida(): void
    {
        $this->expectException(DomainException::class);
        (new HistorialStock)->construir('2026-11-01 12:00:00', '120', '40', [
            $this->movimiento('1', '2026-11-02 15:00:00', '120', '80'),
            $this->movimiento('2', '2026-11-03 15:00:00', '60', '40'),
        ], 'America/Santiago');
    }

    private function movimiento(string $id, string $at, string $antes, string $despues): array
    {
        return ['id' => $id, 'ocurrido_at' => $at, 'cantidad_anterior' => $antes, 'cantidad_resultante' => $despues];
    }
}
