<?php

namespace Tests\Unit;

use App\Services\Materiales\UnidadesMateriales;
use DomainException;
use PHPUnit\Framework\TestCase;

class UnidadesMaterialesTest extends TestCase
{
    public function test_normaliza_sinonimos_sin_confundir_unidades_distintas(): void
    {
        foreach ([' UNIDAD ', 'Unidades', 'UN', 'u'] as $texto) {
            $this->assertSame('unidades', UnidadesMateriales::canonica($texto));
        }
        $this->assertSame('kg', UnidadesMateriales::canonica('KILÓGRAMOS'));
        $this->assertNotSame(UnidadesMateriales::canonica('kg'), UnidadesMateriales::canonica('gramos'));
        $this->assertNotSame(UnidadesMateriales::canonica('cajas'), UnidadesMateriales::canonica('unidades'));
    }

    public function test_convierte_con_decimales_exactos_y_sin_perder_ceros(): void
    {
        $this->assertSame('250.000', UnidadesMateriales::convertir('2.5', '100.000000'));
        $this->assertSame('0.003', UnidadesMateriales::convertir('0.1', '0.030000'));
        $this->assertSame('99999999999.999', UnidadesMateriales::convertir('99999999999.999', '1'));
        $this->assertSame('0.000', UnidadesMateriales::convertir('0', '100'));
    }

    public function test_rechaza_redondeos_factores_no_validos_y_desbordamientos(): void
    {
        foreach ([['0.001', '0.5'], ['1', '0'], ['1', '-1'], ['1.0001', '1'], ['99999999999.999', '2'], ['1', '1.0000001']] as [$cantidad, $factor]) {
            try {
                UnidadesMateriales::convertir($cantidad, $factor);
                $this->fail('Aceptó una conversión inválida.');
            } catch (DomainException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
