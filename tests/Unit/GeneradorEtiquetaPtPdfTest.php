<?php

namespace Tests\Unit;

use App\Services\Validacion\GeneradorEtiquetaPtPdf;
use DomainException;
use PHPUnit\Framework\TestCase;

class GeneradorEtiquetaPtPdfTest extends TestCase
{
    private function etiqueta(): array
    {
        return ['numero_folio' => '0000000003', 'tipo_bulto' => 'saldo', 'cantidad_cajas' => 80,
            'especie' => 'Cereza', 'variedad' => 'Santina', 'calibre' => '2J', 'envase' => '5 kg',
            'categoria' => 'CAT-1', 'cliente' => 'DIS', 'marca' => 'ATLAS', 'temporada' => '26-27',
            'estado_operacional' => 'pendiente_prefrio', 'linea_proceso' => 1, 'turno' => 'A',
            'validador' => 'María Pérez', 'csg' => '105410', 'predio' => 'Los Olmos',
            'fecha_embalaje' => '2026-10-05', 'composicion' => []];
    }

    public function test_ambos_formatos_conservan_folio_y_tienen_tamano_fisico_y_copias(): void
    {
        $generador = new GeneradorEtiquetaPtPdf;
        foreach (['folio' => '283.46 141.73', 'ventana' => '283.46 566.93'] as $tipo => $dimensiones) {
            $pdf = $generador->generarPt([$this->etiqueta()], $tipo, 2);
            $this->assertStringStartsWith('%PDF-1.4', $pdf);
            $this->assertStringContainsString('/MediaBox [0 0 '.$dimensiones.']', $pdf);
            $this->assertStringContainsString('/Count 2', $pdf);
            $this->assertStringContainsString('(0000000003)', $pdf);
            $this->assertStringContainsString('80 cajas', $pdf);
            $this->assertSame(2, substr_count($pdf, '% QR folio'));
        }
    }

    public function test_ventana_conserva_todos_los_segmentos_del_pallet(): void
    {
        $datos = $this->etiqueta();
        $datos['composicion'] = [
            ['csg' => '105410', 'predio' => 'Olmos', 'cantidad_cajas' => 50, 'fecha_embalaje' => '2026-10-05'],
            ['csg' => '105411', 'predio' => 'Sol', 'cantidad_cajas' => 30, 'fecha_embalaje' => '2026-10-04'],
        ];
        $pdf = (new GeneradorEtiquetaPtPdf)->generarPt([$datos], 'ventana');
        $this->assertStringContainsString('105410', $pdf);
        $this->assertStringContainsString('105411', $pdf);
        $this->assertStringContainsString('2026-10-04', $pdf);
    }

    public function test_planta_imprime_cuatro_paginas_de_107_por_74_con_datos_y_barcode_del_folio(): void
    {
        $datos = [...$this->etiqueta(), 'cliente' => 'FRUTOS LA AGUADA S.A',
            'envase_codigo' => 'RGEN90BAM', 'envase' => 'REJILLA 9KG CIRUELA BA',
            'origen' => 'repaletizaje', 'fecha_proceso' => '2026-02-06',
            'kilos_netos' => '693.00', 'cantidad_cajas' => 77];
        $generador = new class extends GeneradorEtiquetaPtPdf
        {
            public function barcode(string $numero): string
            {
                return $this->codigoBarras($numero, 9, 10, 107 / 25.4 * 72 - 18, 39, true);
            }
        };
        $pdf = $generador->generarPt([$datos], 'planta');
        $this->assertStringContainsString('/MediaBox [0 0 303.31 209.76]', $pdf);
        $this->assertStringContainsString('/Count 4', $pdf);
        foreach (['FRUTOS LA AGUADA S.A', 'REPALETIZADO', 'RGEN90BAM', 'REJILLA 9KG CIRUELA BA', '06-02-2026', '693,00', 'PALLET', '0000000003'] as $texto) {
            $this->assertSame(4, substr_count($pdf, '('.$texto.')'));
        }
        $this->assertSame(4, substr_count($pdf, $generador->barcode($datos['numero_folio'])));
        $this->assertSame(4, substr_count($pdf, '% QR folio'));
        $datos['origen'] = 'externo';
        $externo = $generador->generarPt([$datos], 'planta', 1);
        $this->assertStringContainsString('(EXTERNO)', $externo);
        $this->assertSame(1, substr_count($externo, '% QR folio'));
        $this->assertStringContainsString($generador->barcode($datos['numero_folio']), $externo);
        $datos['origen'] = 'validacion';
        $datos['kilos_netos'] = null;
        $datos['fecha_proceso'] = null;
        $pdf = $generador->generarPt([$datos], 'planta', 1);
        $this->assertStringContainsString('(PROCESO)', $pdf);
        $this->assertSame(2, substr_count($pdf, "(\x97)"));
    }

    public function test_no_descarta_caracteres_del_folio_al_generar_codigo_de_barras(): void
    {
        $datos = $this->etiqueta();
        $datos['numero_folio'] = 'PAL-Ñ';
        $this->expectException(DomainException::class);
        (new GeneradorEtiquetaPtPdf)->generarPt([$datos], 'ventana');
    }

    public function test_agregar_qr_conserva_el_ancho_del_barcode_para_folios_alfanumericos_largos(): void
    {
        $datos = [...$this->etiqueta(), 'numero_folio' => 'REPA-'.str_repeat('A', 33),
            'envase_codigo' => 'RGEN90BAM', 'origen' => 'repaletizaje',
            'fecha_proceso' => '2026-02-06', 'kilos_netos' => '693.00'];
        $generador = new class extends GeneradorEtiquetaPtPdf
        {
            public function barcode(string $numero, string $tipo): string
            {
                $margen = $tipo === 'planta' ? 9 : 12;
                $ancho = ($tipo === 'planta' ? 107 : 100) / 25.4 * 72;
                $y = match ($tipo) {
                    'planta' => 10,
                    'ventana' => 200 / 25.4 * 72 - 130,
                    default => 50 / 25.4 * 72 - 73,
                };
                $alto = match ($tipo) {
                    'planta' => 39,
                    'ventana' => 55,
                    default => 24,
                };

                return $this->codigoBarras($numero, $margen, $y, $ancho - 2 * $margen, $alto, true);
            }
        };
        foreach (['folio', 'ventana', 'planta'] as $tipo) {
            $pdf = $generador->generarPt([$datos], $tipo, 1);
            $this->assertStringContainsString($generador->barcode($datos['numero_folio'], $tipo), $pdf);
            $this->assertStringContainsString('('.$datos['numero_folio'].')', $pdf);
            $this->assertSame(1, substr_count($pdf, '% QR folio'));
        }
    }

    public function test_rechaza_composicion_demasiado_extensa_sin_recortarla(): void
    {
        $datos = $this->etiqueta();
        $datos['composicion'] = array_fill(0, 50, ['csg' => '105410', 'predio' => 'Los Olmos', 'cantidad_cajas' => 1]);
        $this->expectException(DomainException::class);
        (new GeneradorEtiquetaPtPdf)->generarPt([$datos], 'ventana');
    }
}
