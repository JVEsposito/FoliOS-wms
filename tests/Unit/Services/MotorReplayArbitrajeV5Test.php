<?php

namespace Tests\Unit\Services;

use App\Services\Planificador\MotorReplayArbitrajeV4;
use App\Services\Planificador\MotorReplayArbitrajeV5;
use PHPUnit\Framework\TestCase;

class MotorReplayArbitrajeV5Test extends TestCase
{
    public function test_una_pausa_sin_retiro_libera_el_cupo_sin_alterar_el_replay_historico(): void
    {
        $pausada = $this->candidato('pausada', 'pausada_discrepancia', false, 40);
        $siguiente = $this->candidato('siguiente', 'pendiente', false, 10);
        $motor = new MotorReplayArbitrajeV5;

        $antes = $motor->reproducir([$siguiente, $pausada], 1, 2, null);
        $this->assertSame('fuera_frontera', $antes['pausada']['decision']);
        $this->assertSame('pausa_discrepancia_sin_retiro', $antes['pausada']['factor_decisivo']);
        $this->assertSame('seleccionada', $antes['siguiente']['decision']);
        $this->assertSame('en_ejecucion', (new MotorReplayArbitrajeV4)
            ->reproducir([$siguiente, $pausada], 1, 2, null)['pausada']['decision']);

        $pausada['realidad_fisica'] = true;
        $despues = $motor->reproducir([$siguiente, $pausada], 1, 2, null);
        $this->assertSame('en_ejecucion', $despues['pausada']['decision']);
        $this->assertSame('alternativa', $despues['siguiente']['decision']);
    }

    private function candidato(string $id, string $estado, bool $fisica, int $prioridad): array
    {
        return [
            'id' => $id, 'estado' => $estado, 'realidad_fisica' => $fisica,
            'creada_timestamp' => 1, 'plan_tipo' => 'almacenamiento_pallet',
            'plan_estado' => 'programado', 'peso_prioridad' => $prioridad,
            'peso_objetivo' => 10, 'beneficio_estimado' => 100,
            'costo_movimientos' => 1, 'riesgo_operacional' => 0,
            'recursos' => ["folio:{$id}"], 'camaras' => [],
        ];
    }
}
