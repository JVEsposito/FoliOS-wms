<?php

namespace Tests\Unit;

use App\Services\Documentos\ActaTomaInventarioMaterialPdf;
use Tests\TestCase;

class ActaTomaInventarioMaterialPdfTest extends TestCase
{
    public function test_acta_pagina_sin_recortar_folios_motivos_y_firmas(): void
    {
        $datos = ['id' => 'INV-001', 'estado' => 'aprobada', 'camaras' => ['MAT-01'], 'categoria' => 'Embalaje', 'abierta_at' => '2026-10-08', 'abierta_por' => 'Supervisor', 'revisada_at' => null, 'revisada_por' => null, 'aprobada_at' => '2026-10-08', 'aprobada_por' => 'Administrador', 'exactitud_presencia_pct' => 100, 'exactitud_cantidad_pct' => 0, 'totales_categorias' => [], 'diferencias' => []];
        for ($i = 1; $i <= 55; $i++) {
            $datos['diferencias'][] = ['folio' => 'FM'.str_pad($i, 5, '0', STR_PAD_LEFT), 'camara' => 'MAT-01', 'posicion' => 'B1 P'.$i, 'item' => 'Film', 'esperado' => 100, 'contado' => 99, 'unidad' => 'kg', 'tipo' => 'diferencia_cantidad', 'accion' => 'ajustar', 'motivo' => 'Conteo revisado', 'ajuste_id' => 'AJ'.$i, 'posicion_destino_id' => null];
        }
        $pdf = app(ActaTomaInventarioMaterialPdf::class)->generar($datos);
        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        preg_match('/\/Type \/Pages .*?\/Count (\d+)/s', $pdf, $match);
        $paginas = (int) $match[1];
        $this->assertGreaterThan(1, $paginas);
        $this->assertSame($paginas, substr_count($pdf, '(Supervisor de materiales)'));
        $this->assertSame($paginas, substr_count($pdf, '(Administrador)'));
        foreach ($datos['diferencias'] as $folio) {
            $this->assertSame(1, substr_count($pdf, $folio['folio']));
        }
        $this->assertStringContainsString('AJ55', $pdf);
        $this->assertStringContainsString('Conteo revisado', $pdf);
    }
}
