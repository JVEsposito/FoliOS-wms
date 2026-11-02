<?php

namespace Tests\Unit;

use App\Services\MateriaPrima\OrdenesProceso\PreSifPdf;
use Tests\TestCase;

class PreSifPdfTest extends TestCase
{
    public function test_conserva_textos_largos_y_el_total_sin_salirse_de_la_pagina(): void
    {
        $filas = [];
        $textos = [];
        for ($i = 0; $i < 80; $i++) {
            $exportadora = str_repeat('EXPORTADORA ', 10).sprintf('FINEXPORT%03d', $i);
            $productor = str_repeat('PRODUCTOR ', 17).sprintf('FINPROD%03d', $i);
            $embalaje = str_repeat('EMBALAJE ', 14).sprintf('FINEMB%03d', $i);
            $textos = [...$textos, $exportadora, $productor, $embalaje];
            $filas[] = [$exportadora, 'P-'.$i, 'L-'.$i, 'CEREZA', 'SANTINA', 'SANTINA',
                '02-11-2026', 'CSG123', 'CACHAPOAL', 'SAN FERNANDO', $productor,
                10, 240, 2100.0, '0:21', $embalaje, 378.0, null, '08:21', 'CHINA'];
        }
        $pdf = app(PreSifPdf::class)->generar([
            'encabezado' => ['titulo' => 'PRE-SIF', 'orden' => 'OP-000001', 'fecha' => '02-11-2026',
                'version' => 'V1', 'turno' => 'T1', 'linea' => 'L1', 'csp' => '3176555',
                'horario_inicio' => '08:00', 'vuelta_colacion' => '12:30'],
            'filas' => $filas,
            'colacion' => ['despues_de' => 40, 'texto' => 'SALIDA A COLACION 11:30 - VUELTA 12:30'],
            'totales' => ['bins' => 800, 'totes' => 19200, 'kilos' => 168000.0],
        ]);

        // El generador escribe los operadores de texto sin compresión. Reúne todos sus
        // renglones para comprobar cada campo completo aunque se envuelva en varias líneas.
        preg_match_all('/\((.*?)\) Tj/s', $pdf, $operadores);
        $contenido = preg_replace('/\s+/', '', implode('', $operadores[1]));
        foreach ($textos as $texto) {
            $this->assertStringContainsString(preg_replace('/\s+/', '', $texto), $contenido);
        }
        $this->assertStringContainsString('TOTAL', $contenido);
        $this->assertStringContainsString('SALIDAACOLACION11:30-VUELTA12:30', $contenido);
        preg_match('/\/Type \/Pages.*?\/Count (\d+)/', $pdf, $paginas);
        $this->assertGreaterThan(1, (int) $paginas[1]);
        // Todos los bordes de celdas quedan entre el encabezado repetido y el pie.
        preg_match_all('/([\d.]+) ([\d.]+) ([\d.]+) ([\d.]+) re S/', $pdf, $bordes, PREG_SET_ORDER);
        $this->assertNotEmpty($bordes);
        foreach ($bordes as $borde) {
            $this->assertGreaterThanOrEqual(36, (float) $borde[2]);
            $this->assertLessThanOrEqual(516.01, (float) $borde[2] + (float) $borde[4]);
        }
    }
}
