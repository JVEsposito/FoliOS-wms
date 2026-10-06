<?php

namespace Tests\Unit;

use App\Services\Documentos\RegistroRecepcionFrutaEmbaladaPdf;
use Tests\TestCase;

class RegistroRecepcionFrutaEmbaladaPdfTest extends TestCase
{
    private function formato(): array
    {
        return ['codigo' => 'RRFE-01', 'version' => '1', 'fecha_vigencia' => '2026-03-02', 'localidad' => 'Rengo'];
    }

    public function test_veinticinco_pallets_dos_hojas_con_totales_encabezados_y_firmas_sin_guia_ni_sag(): void
    {
        $datos = ['cliente' => 'EXPORTADORA', 'numero_guia' => 'GUIA-RESERVADA', 'condicion_sag' => 'SAG-RESERVADO', 'observacion' => 'Fruta recibida conforme.',
            'pallets' => array_map(fn ($n) => ['orden' => $n, 'folio' => 'EX-'.$n, 'embalaje' => 'REJILLA 9KG', 'especie' => 'UVA', 'variedad' => 'THOMPSON', 'csg' => '12345', 'csp' => 'CSP-100', 'calibre' => 'XL', 'cantidad_cajas' => 77, 'temperatura_pulpa_c' => 1.5], range(1, 25))];
        $pdf = app(RegistroRecepcionFrutaEmbaladaPdf::class)->generar($datos, $this->formato());
        $this->assertStringContainsString('/Count 2', $pdf);
        foreach (['RRFE-01', 'Supervisor de fr', 'Jefe de frigor', 'Total recepci', '1925'] as $texto) {
            $this->assertSame(2, substr_count($pdf, $texto));
        }
        foreach (['1540', '385', '(EX-20)', '(EX-21)', '(EX-25)', '(CSP-100)', '02-03-2026', '1,50'] as $texto) {
            $this->assertStringContainsString($texto, $pdf);
        }
        $this->assertStringNotContainsString('GUIA-RESERVADA', $pdf);
        $this->assertStringNotContainsString('SAG-RESERVADO', $pdf);
        $this->assertSame(40, substr_count($pdf, ' l S 818 '));
    }

    public function test_blanco_tiene_veinte_filas_codigo_y_firmas(): void
    {
        $pdf = app(RegistroRecepcionFrutaEmbaladaPdf::class)->generar([], $this->formato(), blanco: true);
        $this->assertStringContainsString('/Count 1', $pdf);
        $this->assertSame(20, substr_count($pdf, ' l S 818 '));
        $this->assertStringContainsString('(RRFE-01)', $pdf);
        $this->assertStringContainsString('02-03-2026', $pdf);
        $this->assertStringContainsString('Supervisor de fr', $pdf);
        $this->assertStringNotContainsString('(EX-', $pdf);
    }

    public function test_observaciones_extensas_se_conservan_en_anexo_sin_recortarlas(): void
    {
        $obs = str_repeat('Una observación de recepción. ', 100).'FINAL OBSERVACIONES';
        $pdf = app(RegistroRecepcionFrutaEmbaladaPdf::class)->generar(['pallets' => [], 'observacion' => $obs], $this->formato());
        $this->assertStringContainsString('FINAL OBSERVACIONES', $pdf);
        $this->assertStringContainsString('Anexo de observaciones', $pdf);
    }
}
