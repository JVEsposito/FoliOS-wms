<?php

namespace Tests\Unit\Rrhh;

use App\Rrhh\Nomina\CalculosLegalesChile;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CalculosLegalesChileTest extends TestCase
{
    public function test_ejemplo_del_estudio_separa_descuentos_y_costo_patronal_sin_duplicar_sis(): void
    {
        $r = (new CalculosLegalesChile)->cotizaciones($this->datos(), $this->parametros());
        $this->assertSame('190400', $r['totales']['descuento']);
        $this->assertSame('68300', $r['totales']['aporte_empleador']);
        $this->assertSame('3.5', $r['detalle']['pension_empleador_tasa_total']);
        $this->assertTrue($r['detalle']['sis_incluido_en_ssp']);
        $this->assertArrayNotHasKey('SIS_ADICIONAL', $this->lineas($r));
    }

    public function test_fonasa_y_caja_reparten_el_mismo_siete_por_ciento(): void
    {
        $r = (new CalculosLegalesChile)->cotizaciones($this->datos(['distribuir_ccaf' => true]), $this->parametros());
        $lineas = $this->lineas($r);
        $this->assertSame('39000', $lineas['FONASA']['monto']);
        $this->assertSame('31000', $lineas['SALUD_CCAF']['monto']);
        $this->assertSame('190400', $r['totales']['descuento']);
    }

    public function test_isapre_distingue_adicional_pagado_y_tope_deducible_tributario(): void
    {
        $motor = new CalculosLegalesChile;
        $r = $motor->cotizaciones($this->datos(['tipo_salud' => 'isapre', 'plan_salud_clp' => '110000']), $this->parametros());
        $this->assertSame('40000', $this->lineas($r)['ISAPRE_ADICIONAL']['monto']);
        $this->assertSame('230400', $r['totales']['descuento']);
        $caro = $motor->cotizaciones($this->datos(['tipo_salud' => 'isapre', 'plan_salud_clp' => '400000']), $this->parametros());
        $this->assertSame('400000', $caro['detalle']['salud_total']);
        $this->assertSame('252000', $caro['detalle']['salud_deducible_tributaria_maxima']);
    }

    public function test_afc_por_tipo_de_contrato_y_cuenta_individual_completada(): void
    {
        $motor = new CalculosLegalesChile;
        $plazo = $motor->cotizaciones($this->datos(['tipo_contrato' => 'plazo_fijo']), $this->parametros());
        $this->assertSame('0', $this->lineas($plazo)['AFC_TRABAJADOR']['monto']);
        $this->assertSame('30000', $this->lineas($plazo)['AFC_EMPLEADOR']['monto']);
        $completa = $motor->cotizaciones($this->datos(['cic_completa' => true]), $this->parametros());
        $this->assertSame('0', $this->lineas($completa)['AFC_TRABAJADOR']['monto']);
        $this->assertSame('8000', $this->lineas($completa)['AFC_EMPLEADOR']['monto']);
    }

    public function test_afp_y_afc_tienen_topes_distintos_y_los_aportes_no_reducen_el_liquido(): void
    {
        $r = (new CalculosLegalesChile)->cotizaciones($this->datos(['base_imponible' => '10000000']), $this->parametros());
        $this->assertSame('3600000', $r['detalle']['base_previsional']);
        $this->assertSame('5408000', $r['detalle']['base_afc']);
        $this->assertSame('696288', $r['totales']['descuento']);
        $this->assertSame('289272', $r['totales']['aporte_empleador']);
    }

    #[DataProvider('tablaOctubre')]
    public function test_tabla_mensual_octubre_sii_por_factor_y_rebaja(string $base, string $esperado): void
    {
        $r = (new CalculosLegalesChile)->impuesto($base, '72151', '2026-10');
        $this->assertSame($esperado, $r['lineas'][0]['monto']);
    }

    public static function tablaOctubre(): array
    {
        return [['900000', '0'], ['1500000', '21038'], ['3000000', '114457'], ['4000000', '216042'], ['6000000', '576238'], ['8000000', '1147712'], ['10000000', '1817439'], ['25000000', '7199098']];
    }

    public function test_gratificacion50_anticipo_tope_anual_y_proporcionalidad_conservan_precision(): void
    {
        $motor = new CalculosLegalesChile;
        $this->assertSame('200000', $motor->anticipo50('800000', '553553')['lineas'][0]['monto']);
        $this->assertSame('219115', $motor->anticipo50('2000000', '553553')['lineas'][0]['monto']);
        $a = $motor->anual50('12800000', '553553', '12', [['mes' => '2026-01', 'monto' => '2400000', 'factor_reajuste' => '1']]);
        $this->assertSame('229377', $this->lineas($a)['SALDO_GRATIFICACION']['monto']);
        $parcial = $motor->anual50('8000000', '553553', '6', []);
        $this->assertSame('1314688', $this->lineas($parcial)['GRAT50_ANUAL']['monto']);
    }

    public function test_reajusta_anticipos_y_no_ordena_descontar_un_exceso(): void
    {
        $anticipos = array_map(fn ($mes) => ['mes' => '2026-'.str_pad((string) $mes, 2, '0', STR_PAD_LEFT), 'monto' => '200000', 'factor_reajuste' => '1.02'], range(1, 12));
        $r = (new CalculosLegalesChile)->anual50('9600000', '553553', '12', $anticipos);
        $this->assertSame('0', $this->lineas($r)['SALDO_GRATIFICACION']['monto']);
        $this->assertSame('48000', $r['detalle']['exceso_a_revisar']);
        $this->assertFalse($r['detalle']['descuento_automatico_del_exceso']);
    }

    public function test_articulo47_reparte_utilidad_legal_sin_aplicar_el_tope_del_50(): void
    {
        $motor = new CalculosLegalesChile;
        $this->assertSame('360000', $motor->reparto47('10000000', '30', '12000000', '100000000')['lineas'][0]['monto']);
        $this->assertSame('30000000', $motor->reparto47('100000000', '30', '100000000', '100000000')['lineas'][0]['monto']);
        $this->expectException(ValidationException::class);
        $motor->reparto47('10000000', '29.99', '1000000', '1000000');
    }

    public function test_honorarios_distingue_retencion_del_receptor_de_ppm_del_emisor_y_calendario(): void
    {
        $motor = new CalculosLegalesChile;
        $this->assertSame('847500', $this->lineas($motor->honorarios('1000000', '2026-10', true))['HONORARIOS_PAGO']['monto']);
        $sinRetencion = $this->lineas($motor->honorarios('1000000', '2026-10', false));
        $this->assertSame('1000000', $sinRetencion['HONORARIOS_PAGO']['monto']);
        $this->assertSame('152500', $sinRetencion['HONORARIOS_RETENCION']['monto']);
        $this->assertSame('840000', $this->lineas($motor->honorarios('1000000', '2027-01', true))['HONORARIOS_PAGO']['monto']);
        $this->assertSame('830000', $this->lineas($motor->honorarios('1000000', '2028-01', true))['HONORARIOS_PAGO']['monto']);
    }

    public function test_no_extrapola_topes_previsionales_a_2027(): void
    {
        $this->expectException(ValidationException::class);
        (new CalculosLegalesChile)->cotizaciones($this->datos(), ['mes' => '2027-01', 'uf' => '40000']);
    }

    private function datos(array $cambios = []): array
    {
        return [...['base_imponible' => '1000000', 'comision_afp' => '1.44', 'tipo_contrato' => 'indefinido', 'tipo_salud' => 'fonasa', 'plan_salud_clp' => '0', 'adicional_accidentes' => '0', 'cic_completa' => false], ...$cambios];
    }

    private function parametros(): array
    {
        return ['mes' => '2026-10', 'uf' => '40000'];
    }

    private function lineas(array $r): array
    {
        return array_column($r['lineas'], null, 'codigo');
    }
}
