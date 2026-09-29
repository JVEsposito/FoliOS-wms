<?php

namespace Tests\Unit\Services;

use App\Services\Planificador\MotorReplayArbitrajeV4;
use PHPUnit\Framework\TestCase;

class MotorReplayArbitrajeV4Test extends TestCase
{
    public function test_recepcion_de_tunel_precede_a_conciliacion_historica_con_un_solo_cupo(): void
    {
        $conciliacion = $this->candidato('conciliacion', 'pendiente', 10, 10, false, [], ['camara-1'], 1);
        $tunel = $this->candidato('tunel', 'pendiente', 20, 10, false, [], ['camara-1'], 2);
        $resultado = (new MotorReplayArbitrajeV4)->reproducir([$conciliacion, $tunel], 1, 2, ['camara-1']);

        $this->assertSame('seleccionada', $resultado['tunel']['decision']);
        $this->assertSame('alternativa', $resultado['conciliacion']['decision']);
    }

    public function test_reproduce_capacidad_conflictos_rollout_y_pausas_sin_depender_del_orden_de_entrada(): void
    {
        $candidatos = [
            $this->candidato('fisica', 'en_ejecucion', 10, 10, true, ['posicion:1'], ['camara-1'], 1),
            $this->candidato('conflicto', 'pendiente', 40, 10, false, ['posicion:1'], ['camara-1'], 2),
            $this->candidato('rollout', 'pendiente', 30, 10, false, ['posicion:2'], ['camara-2'], 3),
            $this->candidato('alternativa', 'pendiente', 20, 10, false, ['posicion:3'], ['camara-1'], 4),
            $this->candidato('pausada', 'pausada_supervision', 10, 10, false, [], ['camara-1'], 5),
        ];
        $motor = new MotorReplayArbitrajeV4;

        $resultado = $motor->reproducir($candidatos, 1, 4, ['camara-1']);
        $invertido = $motor->reproducir(array_reverse($candidatos), 1, 4, ['camara-1']);

        $this->assertSame($resultado, $invertido);
        $this->assertSame('en_ejecucion', $resultado['fisica']['decision']);
        $this->assertSame('realidad_fisica_iniciada', $resultado['fisica']['factor_decisivo']);
        $this->assertSame('excluida_conflicto', $resultado['conflicto']['decision']);
        $this->assertSame('fuera_rollout', $resultado['rollout']['decision']);
        $this->assertSame('alternativa', $resultado['alternativa']['decision']);
        $this->assertSame('alternativa_sin_reserva', $resultado['alternativa']['factor_decisivo']);
        $this->assertSame('fuera_frontera', $resultado['pausada']['decision']);
        $this->assertSame('pausa_supervision', $resultado['pausada']['factor_decisivo']);
    }

    /**
     * @param  array<int, string>  $recursos
     * @param  array<int, string>  $camaras
     * @return array<string, mixed>
     */
    private function candidato(
        string $id,
        string $estado,
        int $pesoPrioridad,
        int $pesoObjetivo,
        bool $realidadFisica,
        array $recursos,
        array $camaras,
        int $segundo,
    ): array {
        return [
            'id' => $id,
            'estado' => $estado,
            'creada_timestamp' => 1_700_000_000 + $segundo,
            'plan_tipo' => 'almacenamiento_pallet',
            'plan_estado' => 'en_ejecucion',
            'realidad_fisica' => $realidadFisica,
            'peso_prioridad' => $pesoPrioridad,
            'peso_objetivo' => $pesoObjetivo,
            'beneficio_estimado' => 100,
            'costo_movimientos' => 10,
            'riesgo_operacional' => 5,
            'recursos' => $recursos,
            'camaras' => $camaras,
        ];
    }
}
