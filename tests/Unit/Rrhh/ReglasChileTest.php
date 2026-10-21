<?php

namespace Tests\Unit\Rrhh;

use App\Rrhh\Nomina\ReglasChile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ReglasChileTest extends TestCase
{
    #[DataProvider('descuentosFacultativos')]
    public function test_topes_individuales_y_conjunto_sin_limite_compartido_del_25_por_ciento(array $totales, string $bruta, array $esperados): void
    {
        $this->assertSame($esperados, (new ReglasChile)->limites($totales, $bruta));
    }

    public static function descuentosFacultativos(): array
    {
        return [
            '15% voluntario más 20% cooperativa' => [['voluntario' => '150000', 'cooperativa' => '200000'], '1000000', []],
            '10% voluntario más 25% cooperativa' => [['voluntario' => '100000', 'cooperativa' => '250000'], '1000000', []],
            '15% más 25% más 5% alcanza 45%' => [['voluntario' => '150000', 'cooperativa' => '250000', 'vivienda' => '50000'], '1000000', []],
            '15% voluntario más 30% vivienda' => [['voluntario' => '150000', 'vivienda' => '300000'], '1000000', []],
            '25% cooperativa más 20% vivienda' => [['cooperativa' => '250000', 'vivienda' => '200000'], '1000000', []],
            'voluntario excede 15% por un peso' => [['voluntario' => '150001'], '1000000', ['El grupo voluntario supera el 15% de la remuneración bruta.']],
            'cooperativa excede 25% por un peso' => [['cooperativa' => '250001'], '1000000', ['El grupo cooperativa supera el 25% de la remuneración bruta.']],
            'vivienda excede 30% por un peso' => [['vivienda' => '300001'], '1000000', ['El grupo vivienda supera el 30% de la remuneración bruta.']],
            'cada grupo cumple pero juntos exceden 45%' => [['voluntario' => '150000', 'cooperativa' => '200000', 'vivienda' => '100001'], '1000000', ['Los descuentos facultativos superan juntos el 45%.']],
            'tope exacto sin redondear la base' => [['voluntario' => '150000.15'], '1000001', []],
            'exceso decimal no se redondea para permitirlo' => [['voluntario' => '150000.16'], '1000001', ['El grupo voluntario supera el 15% de la remuneración bruta.']],
        ];
    }
}
