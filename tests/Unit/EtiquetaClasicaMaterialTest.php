<?php

namespace Tests\Unit;

use App\Services\Materiales\DisenoEtiquetaClasicaMaterial;
use App\Services\Materiales\DisenoTarjaArmado;
use App\Services\Materiales\GeneradorEtiquetaMaterialNlbl;
use App\Services\Materiales\GeneradorEtiquetaMaterialPdf;
use App\Services\Materiales\GeneradorEtiquetaMaterialZpl;
use App\Services\Materiales\LogoAgrorosarioEtiqueta;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class EtiquetaClasicaMaterialTest extends TestCase
{
    private function datos(): array
    {
        return ['cliente' => 'LA AGUADA', 'fecha' => '29/10/2026', 'guia' => '697996', 'folio' => 'FAG000001',
            'proveedor' => 'IMPRESOS Y CARTONAJES', 'codigo' => '020019', 'descripcion' => 'FONDO 2,5KG GENERICO',
            'cantidad' => '1.500', 'unidad' => 'UN', 'bloqueado' => false, 'motivo_bloqueo' => null];
    }

    private function perfil(string $orientacion = 'vertical', int $dpi = 203): array
    {
        return ['fabricante' => 'Bixolon', 'modelo' => 'SLP-TX400', 'dpi' => $dpi, 'ancho_mm' => 100, 'alto_mm' => 200,
            'orientacion' => $orientacion, 'diseno' => 'clasica_bodega', 'diseno_version' => 1];
    }

    public function test_la_cantidad_destaca_y_los_datos_y_logo_caben_sin_solapar_el_codigo(): void
    {
        $diseno = DisenoEtiquetaClasicaMaterial::disponer($this->datos());
        foreach ($diseno['textos'] as $t) {
            $this->assertGreaterThanOrEqual(2.1, $t['tamano']);
            $this->assertLessThanOrEqual(197, $t['x'] + $t['ancho']);
            $this->assertLessThanOrEqual(97, $t['y'] + $t['alto']);
            $this->assertLessThanOrEqual($t['ancho'], DisenoTarjaArmado::anchoRelativo($t['valor'], $t['negrita']) * $t['tamano']);
            if ($t['nombre'] === 'Cantidad') {
                $this->assertSame('1.500', $t['valor']);
                $this->assertGreaterThan(20, $t['tamano']);
            }
        }
        $this->assertSame('FAG000001', $diseno['codigo']['valor']);
        $this->assertNotEmpty(LogoAgrorosarioEtiqueta::rectangulos($diseno['logo']));
        foreach (LogoAgrorosarioEtiqueta::rectangulos($diseno['logo']) as $r) {
            $this->assertLessThanOrEqual(29.001, $r['x'] + $r['ancho']);
            $this->assertLessThanOrEqual(21.001, $r['y'] + $r['alto']);
        }
    }

    public function test_descripcion_larga_se_distribuye_completa_en_dos_lineas_y_bloqueo_es_visible(): void
    {
        $datos = [...$this->datos(), 'descripcion' => 'CAJA DE CARTÓN CORRUGADO PARA CEREZA DE EXPORTACIÓN CON IMPRESIÓN ESPECIAL DEL CLIENTE',
            'bloqueado' => true, 'motivo_bloqueo' => 'Retenido por recepción dañada'];
        $diseno = DisenoEtiquetaClasicaMaterial::disponer($datos);
        $lineas = array_values(array_filter($diseno['textos'], fn ($t) => str_starts_with($t['nombre'], 'Ítem')));
        $this->assertCount(2, $lineas);
        $this->assertSame($datos['codigo'].' · '.$datos['descripcion'], implode(' ', array_column($lineas, 'valor')));
        $this->assertStringContainsString('BLOQUEADO', implode(' ', array_column($diseno['textos'], 'valor')));
    }

    public function test_pdf_es_apaisado_con_logo_datos_y_una_pagina_por_copia(): void
    {
        $pdf = (new GeneradorEtiquetaMaterialPdf)->generar([['clasica' => $this->datos()]], $this->perfil(), 2);
        $this->assertStringContainsString('/MediaBox [0 0 566.93 283.46]', $pdf);
        $this->assertSame(2, substr_count($pdf, '/Type /Page /Parent'));
        foreach (['LA AGUADA', 'FAG000001', '1.500', '29/10/2026'] as $valor) {
            $this->assertStringContainsString('('.$valor.')', $pdf);
        }
        $this->assertStringContainsString('/ImageMask true', $pdf);
        $this->assertGreaterThan(30, substr_count($pdf, ' re f'));
    }

    public static function orientaciones(): array
    {
        return [['vertical', 203], ['horizontal', 203], ['vertical', 300], ['vertical', 600]];
    }

    #[DataProvider('orientaciones')]
    public function test_zpl_respeta_ancho_fisico_y_gira_campos_y_folio_completos(string $orientacion, int $dpi): void
    {
        $perfil = $this->perfil($orientacion, $dpi);
        $zpl = (new GeneradorEtiquetaMaterialZpl)->generar([['clasica' => $this->datos()]], $perfil);
        $ancho = (int) round(($orientacion === 'vertical' ? 100 : 200) / 25.4 * $dpi);
        $alto = (int) round(($orientacion === 'vertical' ? 200 : 100) / 25.4 * $dpi);
        $this->assertStringContainsString('^PW'.$ancho, $zpl);
        $this->assertStringContainsString('^LL'.$alto, $zpl);
        $this->assertStringContainsString($orientacion === 'vertical' ? '^BCR,' : '^BCN,', $zpl);
        $this->assertStringContainsString('^FDFAG000001^FS', $zpl);
        $this->assertStringContainsString('^FD1.500^FS', $zpl);
        preg_match_all('/\^FO(\d+),(\d+)\^GB(\d+),(\d+),/', $zpl, $cajas, PREG_SET_ORDER);
        foreach ($cajas as $c) {
            $this->assertLessThanOrEqual($ancho, (int) $c[1] + (int) $c[3]);
            $this->assertLessThanOrEqual($alto, (int) $c[2] + (int) $c[4]);
        }
    }

    public function test_nlbl_contiene_celdas_y_datos_editables_con_code128_y_zonas_de_silencio(): void
    {
        $xml = (new ReflectionMethod(GeneradorEtiquetaMaterialNlbl::class, 'formato'))->invoke(new GeneradorEtiquetaMaterialNlbl, ['clasica' => $this->datos()], $this->perfil(), 'code128', 'Clásica');
        $doc = simplexml_load_string($xml);
        $this->assertSame('200000', (string) $doc->Media->Width);
        $this->assertSame('100000', (string) $doc->Media->Height);
        $this->assertStringContainsString('RectangleDocumentItem', $xml);
        $this->assertStringContainsString('<HumanInterpretationPosition>0</HumanInterpretationPosition>', $xml);
        $valores = [];
        foreach ($doc->xpath('//Item[@Type="TextDocumentItem"]') as $item) {
            $valores[] = base64_decode((string) $item->FixedContents);
        }
        foreach (['LA AGUADA', 'FAG000001', '1.500', 'UN', '29/10/2026'] as $valor) {
            $this->assertContains($valor, $valores);
        }
        $this->assertStringContainsString('<FixedContents>FAG000001</FixedContents>', $xml);
    }

    public function test_textos_ilegibles_no_se_recortan_silenciosamente(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('utiliza el diseño Actual');
        DisenoEtiquetaClasicaMaterial::disponer([...$this->datos(), 'cliente' => str_repeat('CLIENTE ', 70)]);
    }

    public function test_el_folio_humano_y_el_codigo_preservan_ceros_y_mayusculas_del_identificador(): void
    {
        $diseno = DisenoEtiquetaClasicaMaterial::disponer([...$this->datos(), 'folio' => 'Fag000012']);
        $this->assertSame('Fag000012', $diseno['codigo']['valor']);
        foreach ($diseno['textos'] as $t) {
            if (str_starts_with($t['nombre'], 'Folio')) {
                $this->assertSame('Fag000012', $t['valor']);
            }
        }
    }
}
