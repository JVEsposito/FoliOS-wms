<?php

namespace Tests\Unit;

use App\Services\Documentos\CodigoQrPdf;
use DomainException;
use PHPUnit\Framework\TestCase;

class CodigoQrPdfTest extends TestCase
{
    public function test_conserva_folio_con_ceros_y_margen_blanco_de_cuatro_modulos(): void
    {
        $contenido = (new CodigoQrPdf)->generar('0000000003', 10, 20, 58);
        $this->assertStringContainsString('1 1 1 rg 10.000 20.000 58.000 58.000 re f', $contenido);
        $this->assertStringEndsWith("Q\n", $contenido);
        preg_match_all('/([\d.]+) ([\d.]+) 2\.000 2\.000 re f/', $contenido, $rectangulos, PREG_SET_ORDER);
        $this->assertNotEmpty($rectangulos);
        $filas = array_fill(0, 21, str_repeat('0', 21));
        foreach ($rectangulos as $rectangulo) {
            $x = (float) $rectangulo[1];
            $y = (float) $rectangulo[2];
            $this->assertGreaterThanOrEqual(18, $x);
            $this->assertLessThanOrEqual(58, $x);
            $this->assertGreaterThanOrEqual(28, $y);
            $this->assertLessThanOrEqual(68, $y);
            $filas[20 - (int) (($y - 28) / 2)][(int) (($x - 18) / 2)] = '1';
        }
        // Matriz de referencia cuya lectura se comprobó con un decodificador QR independiente.
        $referencia = trim(file_get_contents(__DIR__.'/../Fixtures/qr-folio-0000000003.txt'));
        $this->assertSame($referencia, implode("\n", $filas));
        $this->assertNotSame($contenido, (new CodigoQrPdf)->generar('3', 10, 20, 58));
    }

    public function test_rechaza_un_qr_demasiado_pequeno_para_imprimir_legiblemente(): void
    {
        $this->expectException(DomainException::class);
        (new CodigoQrPdf)->generar('0000000003', 10, 20, 20);
    }

    public function test_rechaza_folio_vacio(): void
    {
        $this->expectException(DomainException::class);
        (new CodigoQrPdf)->generar('', 10, 20, 58);
    }

    public function test_rechaza_caracteres_no_representables_sin_alterar_el_folio(): void
    {
        $this->expectException(DomainException::class);
        (new CodigoQrPdf)->generar('PAL-Ñ', 10, 20, 58);
    }
}
