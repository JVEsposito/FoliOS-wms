<?php

namespace Tests\Unit\Comex;

use App\Comex\Comercial\CantidadesComerciales;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CantidadesComercialesTest extends TestCase
{
    public function test_suma_importes_redondeados_por_linea_en_moneda_sin_float(): void
    {
        $r = (new CantidadesComerciales)->calcular([['cantidad' => '3', 'precio' => '0.1'], ['cantidad' => '1', 'precio' => '0.105']], 'firme', 'USD');
        $this->assertSame('0.41', $r['total']);
        $this->assertSame('0.11', $r['lineas'][1]['importe']);
        $this->assertSame('0.1', $r['lineas'][0]['precio']);
    }

    public function test_montos_grandes_se_conservan_y_clp_redondea_a_pesos(): void
    {
        $r = (new CantidadesComerciales)->calcular([['cantidad' => '999999999999', 'precio' => '999999999999.999999']], 'firme', 'CLP');
        $this->assertSame('999999999998999999000000', $r['total']);
        $r = (new CantidadesComerciales)->calcular([['cantidad' => '1', 'precio' => '0.5']], 'firme', 'CLP');
        $this->assertSame('1', $r['total']);
    }

    public function test_precio_abierto_no_presenta_como_total_el_subtotal_conocido(): void
    {
        $r = (new CantidadesComerciales)->calcular([['cantidad' => '10', 'precio' => '2'], ['cantidad' => '20']], 'consignacion', 'USD');
        $this->assertNull($r['total']);
        $this->assertSame('20.00', $r['subtotal_conocido']);
        $this->assertSame(1, $r['lineas_sin_precio']);
    }

    public function test_venta_firme_exige_precio_y_cantidad_positiva(): void
    {
        $this->expectException(ValidationException::class);
        (new CantidadesComerciales)->calcular([['cantidad' => '1']], 'firme', 'USD');
    }

    public function test_cantidad_cero_no_crea_un_compromiso(): void
    {
        $this->expectException(ValidationException::class);
        (new CantidadesComerciales)->calcular([['cantidad' => '0', 'precio' => '1']], 'firme', 'USD');
    }

    public function test_kilos_son_consistentes_cuando_la_unidad_del_contrato_es_kg(): void
    {
        $r = (new CantidadesComerciales)->calcular([['unidad' => 'kg', 'cantidad' => '10.5', 'precio' => '1']], 'firme', 'USD');
        $this->assertSame('10.5', $r['lineas'][0]['kilos']);
        $this->expectException(ValidationException::class);
        (new CantidadesComerciales)->calcular([['unidad' => 'kg', 'cantidad' => '10.5', 'kilos' => '11', 'precio' => '1']], 'firme', 'USD');
    }
}
