<?php

namespace Tests\Unit;

use App\Services\Envases\GeneradorEstadoCuentaEnvasesPdf;
use Tests\Fixtures\EstadoCuentaEnvases;
use Tests\TestCase;

class EstadoCuentaEnvasesPdfTest extends TestCase
{
    public function test_seiscientos_movimientos_conservan_guias_productores_y_columnas_en_todas_las_paginas(): void
    {
        $estado = EstadoCuentaEnvases::crear();
        $pdf = app(GeneradorEstadoCuentaEnvasesPdf::class)->generar($estado);
        $paginas = substr_count($pdf, '/Type /Page /Parent');
        $this->assertGreaterThan(1, $paginas);
        $this->assertLessThanOrEqual(22, $paginas);
        $this->assertSame(1, substr_count($pdf, '(RESUMEN EJECUTIVO)'));
        $this->assertLessThanOrEqual(18, substr_count($pdf, '(Saldo del envase)'));
        $this->assertLessThan(strpos($pdf, '(DETALLE POR DOCUMENTO)'), strpos($pdf, '(RESUMEN POR PRODUCTOR)'));
        $this->assertLessThan(strpos($pdf, '(RESUMEN POR PRODUCTOR)'), strpos($pdf, '(RESUMEN EJECUTIVO)'));
        $this->assertStringNotContainsString('DETALLE POR DOCUMENTO', $this->paginas($pdf)[0]);
        $this->assertStringNotContainsString('EJ-00001', $this->paginas($pdf)[0]);
        $this->assertSame($paginas, substr_count($pdf, '/Logo Do'));
        foreach ($estado['grupos_documentos'] as $grupo) {
            $this->assertSame(1, substr_count($pdf, $grupo['documento']));
        }
        foreach (array_count_values(array_column($estado['grupos_documentos'], 'productor')) as $productor => $veces) {
            $this->assertSame($veces + 1, substr_count($pdf, 'CSG '.substr($productor, 4, 6)));
        }
        $this->assertSame(212, preg_match_all('/\/F1 8\.50 Tf [\d.]+ [\d.]+ Td \(Tote\)/', $pdf));
        $this->assertSame(200, preg_match_all('/\/F1 8\.00 Tf [\d.]+ [\d.]+ Td \(CSG /', $pdf));
        $this->assertStringContainsString('No incluidas en el saldo.', $pdf);
        $this->comprobarCoordenadas($pdf);
    }

    public function test_una_guia_excepcional_continua_sin_perder_ninguna_fila(): void
    {
        $estado = EstadoCuentaEnvases::crear(1);
        $grupo = &$estado['grupos_documentos'][0];
        $base = $grupo['lineas'][0];
        $grupo['lineas'] = [];
        for ($i = 0; $i < 160; $i++) {
            $grupo['lineas'][] = [...$base, 'tipo_envase' => sprintf('ENVASE%04d', $i)];
        }
        $estado['movimientos'] = $grupo['lineas'];
        unset($estado['resumen_csg']);
        $pdf = app(GeneradorEstadoCuentaEnvasesPdf::class)->generar($estado);
        $this->assertGreaterThan(1, substr_count($pdf, '/Type /Page /Parent'));
        $this->assertStringContainsString('continuaci', $pdf);
        for ($i = 0; $i < 160; $i++) {
            // Cada tipo aparece una vez en su resumen y una vez en el detalle.
            $this->assertSame(2, substr_count($pdf, sprintf('ENVASE%04d', $i)));
        }
        $this->comprobarCoordenadas($pdf);
    }

    public function test_textos_largos_se_conservan_completos_y_el_cliente_no_pisa_el_periodo(): void
    {
        $estado = EstadoCuentaEnvases::crear(1);
        $estado['cliente']['nombre'] = str_repeat('EXPORTADORA DEL VALLE ', 7).'FINCLIENTE';
        $grupo = &$estado['grupos_documentos'][0];
        $grupo['documento'] = str_repeat('DOCUMENTO-', 9).'FINGUIA';
        $grupo['productor'] = str_repeat('PRODUCTOR AGRICOLA LOS ROBLES ', 100).'FINPRODUCTOR';
        $grupo['lineas'][0]['tipo_envase'] = str_repeat('ENVASE CON DESCRIPCION LARGA ', 6).'FINENVASE';
        $pdf = app(GeneradorEstadoCuentaEnvasesPdf::class)->generar($estado);
        preg_match_all('/\((.*?)\) Tj/', $pdf, $textos);
        $todo = preg_replace('/\s+/', '', implode('', $textos[1]));
        foreach (['FINCLIENTE', 'FINGUIA', 'FINPRODUCTOR', 'FINENVASE'] as $marca) {
            $this->assertStringContainsString($marca, $todo);
        }
        $this->comprobarCoordenadas($pdf);
    }

    public function test_documentos_con_identicos_datos_visibles_siguen_separados(): void
    {
        $estado = EstadoCuentaEnvases::crear(1);
        $estado['grupos_documentos'][] = $estado['grupos_documentos'][0];
        $estado['movimientos'] = [...$estado['movimientos'], ...$estado['movimientos']];
        $pdf = app(GeneradorEstadoCuentaEnvasesPdf::class)->generar($estado);
        $this->assertSame(2, substr_count($pdf, 'EJ-00001'));
    }

    public function test_periodo_vacio_y_numeros_grandes_no_crean_paginas_vacias(): void
    {
        $generador = app(GeneradorEstadoCuentaEnvasesPdf::class);
        $pdf = $generador->generar(EstadoCuentaEnvases::crear(0));
        $this->assertSame(1, substr_count($pdf, '/Type /Page /Parent'));
        $this->assertStringContainsString('Sin movimientos en el', $pdf);
        $estado = EstadoCuentaEnvases::crear(1);
        $estado['grupos_documentos'][0]['lineas'][0]['entrada'] = 1234567;
        $estado['grupos_documentos'][0]['lineas'][0]['saldo'] = 1234567;
        $pdf = $generador->generar($estado);
        $this->assertStringContainsString('(1.234.567)', $pdf);
        $this->assertStringContainsString('(+1.234.567)', $pdf);
        $this->assertSame(2, substr_count($pdf, '/Type /Page /Parent'));
    }

    public function test_una_guia_corta_empieza_en_hoja_dos_y_no_muestra_propiedad_en_ninguna_seccion(): void
    {
        $estado = EstadoCuentaEnvases::crear(1);
        $original = $estado;
        $pdf = app(GeneradorEstadoCuentaEnvasesPdf::class)->generar($estado);
        $paginas = $this->paginas($pdf);
        $this->assertCount(2, $paginas);
        $this->assertStringContainsString('RESUMEN EJECUTIVO', $paginas[0]);
        $this->assertStringContainsString('RESUMEN POR PRODUCTOR', $paginas[0]);
        $this->assertStringContainsString('Productor de ejemplo PROD-00001', $paginas[0]);
        $this->assertStringNotContainsString('EJ-00001', $paginas[0]);
        $this->assertStringNotContainsString('DETALLE POR DOCUMENTO', $paginas[0]);
        $this->assertStringContainsString('DETALLE POR DOCUMENTO', $paginas[1]);
        $this->assertStringContainsString('EJ-00001', $paginas[1]);
        foreach (['(Propiedad)', '(Propia)', '(Arrendada)', '(De la planta)', 'Entregados:'] as $texto) {
            $this->assertStringNotContainsString($texto, $pdf);
        }
        $this->assertSame($original, $estado);
    }

    public function test_el_resumen_extenso_continua_sin_mezclarse_con_los_documentos(): void
    {
        $estado = EstadoCuentaEnvases::crear(80, 40);
        $pdf = app(GeneradorEstadoCuentaEnvasesPdf::class)->generar($estado);
        $paginas = $this->paginas($pdf);
        $enDetalle = false;
        $hojasResumen = 0;
        foreach ($paginas as $pagina) {
            if (str_contains($pagina, 'DETALLE POR DOCUMENTO')) {
                $enDetalle = true;
            }
            if ($enDetalle) {
                $this->assertStringNotContainsString('RESUMEN POR PRODUCTOR', $pagina);
                $this->assertStringNotContainsString('RESUMEN EJECUTIVO', $pagina);
            } else {
                $hojasResumen++;
                $this->assertStringNotContainsString('EJ-', $pagina);
            }
        }
        $this->assertGreaterThan(1, $hojasResumen);
        foreach ($estado['grupos_documentos'] as $grupo) {
            $this->assertSame(1, substr_count($pdf, $grupo['documento']));
        }
        $this->comprobarCoordenadas($pdf);
    }

    public function test_el_detalle_por_csg_agrupa_en_orden_y_repite_el_productor_al_cambiar_de_hoja(): void
    {
        $estado = EstadoCuentaEnvases::conDetalleCsg();
        $pdf = app(GeneradorEstadoCuentaEnvasesPdf::class)->generar($estado);
        $paginas = $this->paginas($pdf);
        $this->assertStringNotContainsString('DETALLE POR DOCUMENTO', $pdf);
        $this->assertStringNotContainsString('DETALLE POR PRODUCTOR', $paginas[0]);
        // Cada guía aparece una sola vez, con su chofer en la misma línea.
        foreach ($estado['grupos_documentos'] as $grupo) {
            $this->assertSame(1, substr_count($pdf, $grupo['documento'].' '.iconv('UTF-8', 'Windows-1252', '·').' Chofer CONDUCTOR '.substr($grupo['documento'], -3)));
        }
        // Los CSG van en orden y cada uno cierra con su total, igual al resumen.
        preg_match_all('/\(CSG (\d+) \S+ Productor de ejemplo PROD-\d+\) Tj/', $pdf, $titulos);
        $this->assertSame(array_map(fn ($g) => (string) $g['codigo'], $estado['resumen_csg']), $titulos[1]);
        $this->assertSame(count($estado['detalle_csg']), substr_count($pdf, '(Total del CSG en el per'));
        foreach ($estado['detalle_csg'] as $grupo) {
            $resumen = collect($estado['resumen_csg'])->firstWhere('codigo', $grupo['codigo']);
            $this->assertSame(array_column($resumen['tipos'], 'saldo_final'), array_column($grupo['totales'], 'saldo_final'));
        }
        // Un CSG que sigue en otra hoja repite su título como continuación.
        $this->assertGreaterThan(0, preg_match_all('/PROD-\d+ \\\\\\(continuaci/', $pdf));
        foreach (array_slice($paginas, 1) as $pagina) {
            if (str_contains($pagina, 'DETALLE POR PRODUCTOR')) {
                $this->assertStringContainsString('(Saldo del CSG)', $pagina);
            }
        }
        $this->comprobarCoordenadas($pdf);
    }

    /** Contenido de cada hoja del PDF, excluyendo la imagen del logo. */
    private function paginas(string $pdf): array
    {
        preg_match_all('/\d+ 0 obj\n<< \/Length \d+ >>\nstream\n(.*?)endstream/s', $pdf, $coincidencias);

        return $coincidencias[1];
    }

    private function comprobarCoordenadas(string $pdf): void
    {
        preg_match_all('/BT \/F[12] ([\d.]+) Tf ([\d.-]+) ([\d.-]+) Td /', $pdf, $textos, PREG_SET_ORDER);
        foreach ($textos as $texto) {
            $this->assertGreaterThanOrEqual(30, (float) $texto[2]);
            if ((float) $texto[3] > 46) {
                $this->assertGreaterThanOrEqual(64, (float) $texto[3]);
                $this->assertLessThanOrEqual(806, (float) $texto[3]);
            }
        }
    }
}
