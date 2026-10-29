<?php

namespace Tests\Unit\Services\Planificador;

use App\Models\Camara;
use App\Services\Planificador\ServicioDesplieguePlanificador;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ServicioDesplieguePlanificadorTest extends TestCase
{
    public function test_off_global_prevalece_sobre_el_rollout(): void
    {
        config([
            'planificador.mode' => 'off',
            'planificador.rollout_camaras' => ['CAM-01'],
        ]);

        $this->assertSame(
            'off',
            app(ServicioDesplieguePlanificador::class)->modoParaCamara($this->camara('CAM-01')),
        );
    }

    #[DataProvider('calculosDirigidos')]
    public function test_guided_se_limita_por_codigo_y_degrada_el_resto_a_shadow(string $compute): void
    {
        config([
            'planificador.mode' => 'guided',
            'planificador.compute' => $compute,
            'planificador.horizon' => 'rolling',
            'planificador.generacion_automatica' => true,
            'planificador.rollout_camaras' => ['cam-01'],
        ]);
        $servicio = app(ServicioDesplieguePlanificador::class);

        $this->assertSame('guided', $servicio->modoParaCamara($this->camara('CAM-01')));
        $this->assertSame('shadow', $servicio->modoParaCamara($this->camara('CAM-02')));
        $this->assertTrue($servicio->dirige([$this->camara('CAM-01')]));
        $this->assertFalse($servicio->dirige([$this->camara('CAM-02')]));
    }

    public function test_lista_vacia_conserva_el_modo_global_sin_limitar_camaras(): void
    {
        config([
            'planificador.mode' => 'guided',
            'planificador.rollout_camaras' => [],
        ]);

        $this->assertSame(
            'guided',
            app(ServicioDesplieguePlanificador::class)->modoParaCamara($this->camara('CAM-99')),
        );
    }

    public static function calculosDirigidos(): array
    {
        return [
            'tablet' => ['tablet'],
            'servidor' => ['server'],
        ];
    }

    private function camara(string $codigo): Camara
    {
        $camara = new Camara;
        $camara->forceFill([
            'id' => fake()->uuid(),
            'codigo' => $codigo,
            'nombre' => $codigo,
        ]);

        return $camara;
    }
}
