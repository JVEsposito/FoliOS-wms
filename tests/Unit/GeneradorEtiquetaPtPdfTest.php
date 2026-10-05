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

    public function test_no_descarta_caracteres_del_folio_al_generar_codigo_de_barras(): void
    {
        $datos = $this->etiqueta();
        $datos['numero_folio'] = 'PAL-Ñ';
        $this->expectException(DomainException::class);
        (new GeneradorEtiquetaPtPdf)->generarPt([$datos], 'ventana');
    }

    public function test_rechaza_composicion_demasiado_extensa_sin_recortarla(): void
    {
        $datos = $this->etiqueta();
        $datos['composicion'] = array_fill(0, 50, ['csg' => '105410', 'predio' => 'Los Olmos', 'cantidad_cajas' => 1]);
        $this->expectException(DomainException::class);
        (new GeneradorEtiquetaPtPdf)->generarPt([$datos], 'ventana');
    }
}
