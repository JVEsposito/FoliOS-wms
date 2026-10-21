<?php

namespace Tests\Unit\Comex;

use App\Finanzas\Comex\ValorCosto;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ValorCostoTest extends TestCase
{
    public function test_tasa_manual_redondea_solo_conversion_final_sin_float(): void
    {
        $r = (new ValorCosto)->convertir(['moneda' => 'USD', 'previsto' => '100.01', 'comprometido' => '90.99', 'tasa_clp' => '942.12345678', 'fecha_cambio' => '2026-10-01', 'fuente_cambio' => 'Cotización documentada']);
        $this->assertSame('94222', $r['previsto_clp']);
        $this->assertSame('85724', $r['comprometido_clp']);
        $this->assertSame('942.12345678', $r['tasa_clp']);
    }

    public function test_clp_nominal_no_aplica_tasa_ajena_y_distingue_compromiso_no_informado(): void
    {
        $r = (new ValorCosto)->convertir(['moneda' => 'CLP', 'fecha' => '2026-10-01', 'previsto' => '999999999999', 'tasa_clp' => '50']);
        $this->assertSame('999999999999', $r['previsto_clp']);
        $this->assertSame('1', $r['tasa_clp']);
        $this->assertNull($r['comprometido_clp']);
    }

    public function test_moneda_rechaza_decimales_excedentes_en_vez_de_alterar_costo_original(): void
    {
        $this->expectException(ValidationException::class);
        (new ValorCosto)->convertir(['moneda' => 'CLP', 'previsto' => '10.5']);
    }

    public function test_conversion_no_acepta_tasa_cero(): void
    {
        $this->expectException(ValidationException::class);
        (new ValorCosto)->convertir(['moneda' => 'USD', 'previsto' => '10', 'tasa_clp' => '0']);
    }
}
