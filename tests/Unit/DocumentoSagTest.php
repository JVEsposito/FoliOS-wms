<?php

namespace Tests\Unit;

use App\Models\DocumentoSag;
use App\Services\InspeccionSag\GeneradorDocumentoSag;
use Tests\TestCase;
use ZipArchive;

class DocumentoSagTest extends TestCase
{
    private function generador(string $tipo, int $cantidad, string $observacion = ''): GeneradorDocumentoSag
    {
        return new class($tipo, $cantidad, $observacion) extends GeneradorDocumentoSag
        {
            public function __construct(private string $tipo, private int $cantidad, private string $observacion) {}

            public function datos(?DocumentoSag $documento, string $tipo): array
            {
                $repa = $tipo === 'repaletizaje';
                $filas = [];
                foreach (range(1, $this->cantidad) as $i) {
                    $filas[] = $repa ? ['INSPECCIÓN', '1513', '000'.$i, 180, 'RE-'.$i, 180]
                        : ['INSPECCIÓN', '1513', '000'.$i, 'UVA', 'FRESCO', 'FRUTO', 1, 'CAJAS', 'DECISIÓN COMERCIAL'];
                }

                return ['tipo' => $tipo, 'formato' => [], 'filas' => $filas, 'capacidad' => $repa ? 32 : 20,
                    'columnas' => $repa ? ['Actividad', 'Número', 'Folio original', 'Envases originales', 'Folio resultado', 'Envases resultantes']
                        : ['Actividad', 'Número', 'Folio', 'Especie', 'Condición', 'Producto', 'Envases', 'Tipo envase', 'Causal'],
                    'campos' => ['codigo' => $repa ? 'F-PA-CFT-DR-010' : 'F-PA-CFT-DR-011', 'version' => '01', 'vigencia' => '2026-10-21',
                        'numero' => 1, 'fecha' => '21-10-2026', 'estado' => 'EMITIDO', 'establecimiento' => 'Planta de prueba',
                        'csp' => '00123', 'oficina_sag' => 'Rengo', 'especie' => 'UVA', 'solicitud' => 'REPA-1',
                        'certificados' => 0, 'devueltos' => 0, 'asignados' => 0, 'total' => $this->cantidad * ($repa ? 180 : 1),
                        'observaciones' => $this->observacion, 'responsable' => 'Responsable prueba', 'cargo' => 'tecnica', 'nota' => '']];
            }
        };
    }

    public function test_repa_pdf_sin_destino_y_con_todas_las_paginas_y_firmas(): void
    {
        $pdf = $this->generador('repaletizaje', 40)->pdf(null, 'repaletizaje');
        $this->assertStringContainsString('/Count 2', $pdf);
        $this->assertSame(2, substr_count($pdf, 'F-PA-CFT-DR-010'));
        $this->assertSame(2, substr_count($pdf, 'Firma contraparte:'));
        $this->assertStringContainsString('(0001)', $pdf);
        $this->assertStringContainsString('(RE-40)', $pdf);
        $this->assertStringNotContainsString('DESTINO', $pdf);
        $this->assertStringNotContainsString('CHINA', $pdf);
    }

    public function test_excel_anulacion_25_folios_20_y_5_tipos_y_ceros_iniciales(): void
    {
        $ruta = $this->generador('anulacion', 25)->xlsx(null, 'anulacion');
        $zip = new ZipArchive;
        try {
            $this->assertTrue($zip->open($ruta));
            $libro = $zip->getFromName('xl/workbook.xml');
            $xml = new \DOMDocument;
            $xml->loadXML($libro);
            $this->assertSame(2, (new \DOMXPath($xml))->query('//*[local-name()="sheet"]')->length);
            $primera = $zip->getFromName('xl/worksheets/sheet1.xml');
            $segunda = $zip->getFromName('xl/worksheets/sheet2.xml');
            $this->assertStringContainsString('0001', $primera);
            $this->assertStringContainsString('0025', $segunda);
            $this->assertStringContainsString('FRESCO', $segunda);
            $this->assertStringContainsString('Versión de planta: 01', $segunda);
            $this->assertStringContainsString('TOTAL DOCUMENTO: 25', $segunda);
            $this->assertStringNotContainsString('{{', $primera.$segunda);
            $this->assertStringNotContainsString('<x:f>', $primera.$segunda);
            $this->assertStringContainsString('fitToHeight="1"', $primera);
        } finally {
            $zip->close();
            unlink($ruta);
        }
    }

    public function test_observaciones_largas_tienen_anexo_y_no_se_pierde_el_final(): void
    {
        $pdf = $this->generador('anulacion', 1, str_repeat('Observación de prueba. ', 100).'ULTIMAOBSERVACION')->pdf(null, 'anulacion');
        $this->assertStringContainsString('ANEXO DE OBSERVACIONES', $pdf);
        $this->assertStringContainsString('ULTIMAOBSERVACION', $pdf);
        $this->assertStringNotContainsString('{{', $pdf);
    }
}
