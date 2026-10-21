<?php

namespace Tests\Unit;

use App\Models\DocumentoSag;
use App\Services\InspeccionSag\GeneradorDespachoSag;
use Tests\TestCase;
use ZipArchive;

class DespachoSagTest extends TestCase
{
    public function documento(): DocumentoSag
    {
        return new DocumentoSag(['tipo' => 'despacho', 'estado' => 'emitido', 'numero' => 25, 'fecha_actividad' => '2026-10-21',
            'formato_snapshot' => ['codigo' => 'F-CER-PPS-PA-001', 'version' => '01', 'fecha_vigencia' => '2026-10-21'],
            'planta_snapshot' => ['nombre_establecimiento' => 'Planta prueba', 'csp' => '3176555', 'region' => '06', 'provincia' => 'Cachapoal', 'comuna' => 'Rengo'],
            'responsable_snapshot' => ['nombre' => 'Despachador prueba', 'cargo' => 'despachador'], 'encabezado' => ['observaciones' => 'Prueba de despacho'],
            'detalle' => ['solicitud' => 'EMB-1', 'pallets' => 5, 'transporte' => ['modo' => 'contingencia', 'motivo_contingencia' => 'Caída de plataforma', 'destino_pais' => 'Holanda', 'guias' => '136', 'nimf15' => true, 'inspeccionado' => true, 'tipo_produccion' => 'CONVENCIONAL', 'transgenico' => 'NO', 'tratamiento' => 'sin_tratamiento'],
                'lineas' => collect(range(1, 5))->map(fn ($i) => ['folio_id' => 'id-'.$i, 'folio' => '000'.$i, 'especie' => 'CEREZA', 'variedad' => 'SANTINA', 'lote' => '',
                    'cantidad' => 240, 'kilos' => 600, 'provincia' => 'Colchagua', 'comuna' => 'Placilla', 'csg' => '00102778', 'csp' => '3176555'])->all()]]);
    }

    public function test_despacho_agrupa_producto_y_anexa_los_cinco_pallets_con_sus_totales(): void
    {
        $doc = $this->documento();
        $generador = app(GeneradorDespachoSag::class);
        $datos = $generador->datos($doc, 'despacho');
        $this->assertCount(1, $datos['filas']);
        $this->assertSame(1200, $datos['filas'][0][7]);
        $this->assertEquals(3000, $datos['filas'][0][9]);
        $this->assertCount(5, $datos['anexo']);
        $pdf = $generador->pdf($doc, 'despacho');
        $this->assertStringContainsString('CONTINGENCIA', $pdf);
        $this->assertStringContainsString('ANEXO POR PALLET', $pdf);
        $this->assertStringContainsString('1200 cajas / 3000 kg', $pdf);
        $this->assertStringContainsString('(0005)', $pdf);
        $this->assertStringContainsString('00102778', $pdf);
        $ruta = $generador->xlsx($doc, 'despacho');
        $zip = new ZipArchive;
        try {
            $zip->open($ruta);
            $hojas = $zip->getFromName('xl/worksheets/sheet1.xml').$zip->getFromName('xl/worksheets/sheet2.xml').$zip->getFromName('xl/worksheets/sheet3.xml');
            $this->assertStringContainsString('1200', $hojas);
            $this->assertStringContainsString('3000', $hojas);
            $this->assertStringContainsString('0005', $hojas);
            $this->assertStringContainsString('ANEXO POR PALLET', $hojas);
            $this->assertStringNotContainsString('{{', $hojas);
        } finally {
            $zip->close();
            unlink($ruta);
        }
    }

    public function test_no_presenta_datos_para_multipuerto_como_planilla_oficial(): void
    {
        $doc = $this->documento();
        $detalle = $doc->detalle;
        $detalle['transporte']['modo'] = 'multipuerto';
        $doc->detalle = $detalle;
        $pdf = app(GeneradorDespachoSag::class)->pdf($doc, 'despacho');
        $this->assertStringContainsString('DATOS PARA MULTIPUERTO', $pdf);
        $this->assertStringNotContainsString('PLANILLA DE DESPACHO', $pdf);
    }

    public function test_un_borrador_cancelado_no_se_imprime_como_emitido(): void
    {
        $doc = $this->documento();
        $doc->numero = null;
        $doc->estado = 'cancelado';
        $datos = app(GeneradorDespachoSag::class)->datos($doc, 'despacho');
        $this->assertSame('CANCELADO', $datos['campos']['estado']);
    }

    public function test_despacho_de_25_pallets_no_pierde_folios_ni_observaciones_en_los_anexos(): void
    {
        $doc = $this->documento();
        $detalle = $doc->detalle;
        $original = $detalle['lineas'][0];
        $detalle['lineas'] = collect(range(1, 25))->map(fn ($i) => [...$original, 'folio_id' => 'id-'.$i, 'folio' => 'PALLET-'.str_pad((string) $i, 4, '0', STR_PAD_LEFT)])->all();
        $detalle['pallets'] = 25;
        $doc->detalle = $detalle;
        $doc->encabezado = ['observaciones' => str_repeat('Observación extensa de despacho. ', 70).'ULTIMOANTECEDENTE'];
        $generador = app(GeneradorDespachoSag::class);
        $pdf = $generador->pdf($doc, 'despacho');
        $this->assertStringContainsString('PALLET-0025', $pdf);
        $this->assertStringContainsString('ULTIMOANTECEDENTE', $pdf);
        $this->assertStringContainsString('6000 cajas / 15000 kg', $pdf);
        $ruta = $generador->xlsx($doc, 'despacho');
        $zip = new ZipArchive;
        try {
            $zip->open($ruta);
            $this->assertStringContainsString('PALLET-0025', $zip->getFromName('xl/worksheets/sheet3.xml'));
            $contenido = '';
            for ($i = 0; $i < $zip->numFiles; $i++) {
                if (preg_match('~^xl/worksheets/sheet\d+\.xml$~', $zip->getNameIndex($i))) {
                    $contenido .= $zip->getFromIndex($i);
                }
            }
            $this->assertStringContainsString('ULTIMOANTECEDENTE', $contenido);
            $this->assertStringNotContainsString('{{', $contenido);
        } finally {
            $zip->close();
            unlink($ruta);
        }
    }
}
