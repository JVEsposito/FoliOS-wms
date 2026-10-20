<?php

namespace Tests\Unit;

use App\Services\Materiales\ServicioArmadoMaterial;
use Tests\TestCase;

class ServicioArmadoMaterialTest extends TestCase
{
    public function test_sin_codigo_de_bodega_la_tarja_de_armado_queda_apagada(): void
    {
        $armado = new ServicioArmadoMaterial;

        foreach ([null, '', 'AGRO', '1234567', '10 0'] as $codigo) {
            config(['materiales.armado.codigo_bodega' => $codigo]);
            $this->assertFalse($armado->activo(), 'Código de bodega: '.var_export($codigo, true));
            $this->assertNull($armado->codigoBodega());
        }

        config(['materiales.armado.codigo_bodega' => ' 100 ', 'materiales.armado.nombre_bodega' => 'BODEGA AGROROSARIO']);
        $this->assertTrue($armado->activo());
        $this->assertSame([
            'activo' => true,
            'codigo_bodega' => '100',
            'nombre_bodega' => 'BODEGA AGROROSARIO',
            'turnos' => [
                ['codigo' => '01', 'nombre' => 'Día'],
                ['codigo' => '02', 'nombre' => 'Tarde'],
            ],
        ], $armado->configuracion());
    }

    public function test_la_maquina_lleva_dos_digitos_y_el_codigo_del_item_va_sin_espacios(): void
    {
        $armado = new ServicioArmadoMaterial;

        $this->assertSame('03', $armado->maquina('3'));
        $this->assertSame('03', $armado->maquina(' 03 '));
        $this->assertSame('12', $armado->maquina('12'));
        foreach ([null, '', '0', '00', '100', 'Línea 1', '-1'] as $valor) {
            $this->assertNull($armado->maquina($valor), 'Máquina: '.var_export($valor, true));
        }
        $this->assertSame('CJ021021325', $armado->codigoItem(' cj021 021325 '));
    }
}
