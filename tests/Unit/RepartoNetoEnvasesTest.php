<?php

namespace Tests\Unit;

use App\Services\Romana\ServicioRepartoEnvases;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RepartoNetoEnvasesTest extends TestCase
{
    public function test_un_envase_no_necesita_referencia_y_dos_usan_proporcion_de_pesos(): void
    {
        $servicio = new ServicioRepartoEnvases;
        $simple = $servicio->calcular(9000, ['smartpick' => 30], ['smartpick'], []);
        $this->assertSame(300.0, $simple[0]['peso_neto_unitario']);
        $mixto = $servicio->calcular(9000, ['bins' => 2, 'caja_3_4' => 6], ['bins', 'caja_3_4'], ['bins' => 210, 'caja_3_4' => 10]);
        $this->assertSame(3937.5, $mixto[0]['peso_neto_unitario']);
        $this->assertSame(187.5, $mixto[1]['peso_neto_unitario']);
        $this->assertSame(4312500, $servicio->miligramos($mixto, ['bins' => 1, 'caja_3_4' => 2], 9000));
    }

    public function test_dos_envases_sin_referencia_no_pueden_repartirse(): void
    {
        $this->expectException(ValidationException::class);
        (new ServicioRepartoEnvases)->calcular(9000, ['bins' => 2, 'caja_3_4' => 6], ['bins', 'caja_3_4'], ['bins' => 210]);
    }
}
