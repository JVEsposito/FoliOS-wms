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
        $this->assertLessThanOrEqual(18, $paginas);
        $this->assertSame(1, substr_count($pdf, '(RESUMEN POR TIPO DE ENVASE)'));
        $this->assertSame($paginas, substr_count($pdf, '(Saldo del envase)'));
        $this->assertSame($paginas, substr_count($pdf, '/Logo Do'));
        foreach ($estado['grupos_documentos'] as $grupo) {
            $this->assertSame(1, substr_count($pdf, $grupo['documento']));
            $this->assertSame(1, substr_count($pdf, 'CSG '.substr($grupo['productor'], 4, 6)));
        }
        $this->assertSame(200, preg_match_all('/\/F1 8\.50 Tf [\d.]+ [\d.]+ Td \(Tote\)/', $pdf));
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
        $pdf = app(GeneradorEstadoCuentaEnvasesPdf::class)->generar($estado);
        $this->assertGreaterThan(1, substr_count($pdf, '/Type /Page /Parent'));
        $this->assertStringContainsString('continuaci', $pdf);
        for ($i = 0; $i < 160; $i++) {
            $this->assertSame(1, substr_count($pdf, sprintf('ENVASE%04d', $i)));
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
        $this->assertSame(1, substr_count($pdf, '/Type /Page /Parent'));
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
