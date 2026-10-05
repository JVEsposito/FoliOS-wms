<?php

namespace Tests\Feature;

use App\Enums\EstadoHidrocoolerMateriaPrima;
use App\Models\CsgValidacion;
use App\Models\LoteMateriaPrima;
use App\Models\ProcesoHidrocoolerMateriaPrima;
use App\Models\ProductorCsg;
use App\Models\RecepcionRomana;
use App\Services\MateriaPrima\RegistroControlHidrocooler;
use App\Services\MateriaPrima\RegistroHidrocoolerPdf;
use App\Services\MateriaPrima\RegistroHidrocoolerXlsx;
use Tests\TestCase;
use ZipArchive;

class RegistroControlHidrocoolerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $registro = \Mockery::mock(RegistroControlHidrocooler::class)->makePartial();
        $registro->shouldReceive('vigente')->andReturn(RegistroControlHidrocooler::OFICIAL);
        $this->app->instance(RegistroControlHidrocooler::class, $registro);
    }

    public function test_treinta_ciclos_dos_hojas_24_y_6_con_encabezados_firmas_y_datos_comunes(): void
    {
        $ciclos = collect(range(1, 30))->map(fn ($i) => $this->ciclo($i));
        $registro = app(RegistroControlHidrocooler::class);
        $hojas = $registro->hojas($ciclos);
        $this->assertCount(2, $hojas);
        $this->assertCount(24, $hojas[0]['filas']);
        $this->assertCount(6, $hojas[1]['filas']);
        $pdf = app(RegistroHidrocoolerPdf::class)->generar($ciclos);
        preg_match_all('/\(([^()]*)\) Tj/', $pdf, $textos);
        $textoPdf = preg_replace('/\s+/', '', implode(' ', $textos[1]));
        $this->assertStringContainsString('/Count 2', $pdf);
        $this->assertSame(2, substr_count($pdf, 'REGISTRO CONTROL HIDROCOOLER'));
        $this->assertSame(2, substr_count($pdf, 'Jefe de Calidad'));
        $ruta = app(RegistroHidrocoolerXlsx::class)->generar($ciclos);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($ruta) === true);
        try {
            $this->assertNotFalse($zip->getFromName('xl/media/logo.jpg'));
            foreach ([1, 2] as $pagina) {
                $xml = $zip->getFromName('xl/worksheets/sheet'.$pagina.'.xml');
                $this->assertStringContainsString('POEMP-R3', $xml);
                $this->assertStringContainsString('Versión: 2', $xml);
                $this->assertStringContainsString('31-08-2026', $xml);
                $this->assertStringContainsString('Jefe de Calidad', $xml);
                $this->assertStringContainsString('Responsable', $xml);
                $dom = simplexml_load_string($xml);
                $dom->registerXPathNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                $cabeceras = array_map(fn ($c) => (string) $c->is->t, $dom->xpath('//s:row[@r="5"]/s:c'));
                $this->assertSame(RegistroControlHidrocooler::COLUMNAS, $cabeceras);
                $this->assertCount(24, $dom->xpath('//s:row[@r>=6 and @r<=29]'));
                $this->assertStringNotContainsString('Bombas', $xml);
                $this->assertStringNotContainsString('Equipo', $xml);
                $this->assertStringNotContainsString('Turno', $xml);
                $this->assertStringNotContainsString('SOP', $xml);
            }
            $this->assertFalse($zip->getFromName('xl/worksheets/sheet3.xml'));
            $paginas = $registro->paginas($ciclos);
            foreach ($paginas as $i => $pagina) {
                $xml = html_entity_decode($zip->getFromName('xl/worksheets/sheet'.($i + 1).'.xml'), ENT_XML1 | ENT_QUOTES, 'UTF-8');
                foreach ($pagina['filas'] as $fila) {
                    foreach ($fila as $valor) {
                        $this->assertStringContainsString($valor, $xml);
                        $valorPdf = iconv('UTF-8', 'Windows-1252//TRANSLIT', $valor);
                        $this->assertStringContainsString(preg_replace('/\s+/', '', $valorPdf), $textoPdf);
                    }
                }
            }
        } finally {
            $zip->close();
            unlink($ruta);
        }
        foreach (['Bombas', 'Equipo', 'Turno', 'SOP', 'objetivo'] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $pdf);
        }
    }

    public function test_historico_campos_vacios_en_curso_sin_salida_y_versiones_separadas(): void
    {
        $registro = app(RegistroControlHidrocooler::class);
        $historico = $this->ciclo(1);
        $historico->fill(['formato_registro_snapshot' => null, 'temperatura_ambiente_c' => null,
            'humedad_relativa_pct' => null, 'pozo_accutab_mv' => null, 'recarga_pastilla' => null,
            'correccion_cloro_ppm' => null, 'aplicacion_producto' => null, 'producto_nombre_snapshot' => null,
            'producto_dosis' => null, 'producto_unidad_dosis' => null]);
        $fila = $registro->valores($historico);
        foreach ([6, 7, 11, 12, 13, 14, 15, 17] as $columna) {
            $this->assertSame('', $fila[$columna]);
        }
        $curso = $this->ciclo(2)->fill(['termino_at' => null]);
        $fila = $registro->valores($curso);
        $this->assertSame('', $fila[9]);
        $this->assertSame('', $fila[19]);
        $version3 = $this->ciclo(3)->fill(['formato_registro_snapshot' => [...RegistroControlHidrocooler::OFICIAL, 'version' => '3']]);
        $nuevoMaestro = \Mockery::mock(RegistroControlHidrocooler::class)->makePartial();
        $nuevoMaestro->shouldReceive('vigente')->andReturn([...RegistroControlHidrocooler::OFICIAL, 'version' => '99']);
        $this->app->instance(RegistroControlHidrocooler::class, $nuevoMaestro);
        $hojas = $registro->hojas(collect([$historico, $curso, $version3]));
        $this->assertCount(2, $hojas);
        $this->assertSame('2', $hojas[0]['formato']['version']);
        $this->assertSame('3', $hojas[1]['formato']['version']);
        $this->assertStringContainsString(iconv('UTF-8', 'Windows-1252', 'Versión: 99'), app(RegistroHidrocoolerPdf::class)->generarEnBlanco());
    }

    public function test_en_blanco_24_filas_y_observaciones_largas_sin_perder_texto(): void
    {
        $registro = app(RegistroControlHidrocooler::class);
        $this->assertSame([], $registro->hojas(collect(), true)[0]['filas']);
        $pdf = app(RegistroHidrocoolerPdf::class)->generarEnBlanco();
        $this->assertStringContainsString('/Count 1', $pdf);
        $ciclo = $this->ciclo(1)->fill(['observacion_inicio' => str_repeat('Observación extensa verificada. ', 200).'MARCADOR FINAL']);
        $paginas = $registro->paginas(collect([$ciclo]));
        $this->assertGreaterThan(1, count($paginas));
        $this->assertStringContainsString('MARCADOR FINAL', implode(' ', array_merge(...array_column($paginas, 'notas'))));
        $pdf = app(RegistroHidrocoolerPdf::class)->generar(collect([$ciclo]));
        $this->assertStringContainsString('MARCADOR FINAL', $pdf);
        $this->assertSame(count($paginas), substr_count($pdf, 'Jefe de Calidad'));
    }

    private function ciclo(int $i): ProcesoHidrocoolerMateriaPrima
    {
        $recepcion = new RecepcionRomana(['ingreso_at' => '2026-10-05 06:01:00', 'tipo_servicio' => 'proceso']);
        $csg = (new CsgValidacion)->setRelation('productor', new ProductorCsg(['razon_social' => 'Productor CSG']));
        $lote = (new LoteMateriaPrima(['numero_lote' => 'LOTE-'.$i, 'variedad_snapshot' => 'Santina']))
            ->setRelation('recepcion', $recepcion)->setRelation('csg', $csg);

        return (new ProcesoHidrocoolerMateriaPrima([
            'inicio_at' => '2026-10-05 06:05:00', 'termino_at' => '2026-10-05 06:08:00',
            'estado' => EstadoHidrocoolerMateriaPrima::Completado,
            'duracion_minutos' => 3, 'temperatura_agua_inicial_c' => 1.5,
            'temperatura_ambiente_c' => 21.5, 'humedad_relativa_pct' => 60,
            'temperatura_inicial_c' => 18, 'temperatura_c' => 4,
            'cloro_libre_ppm' => 95, 'correccion_cloro_ppm' => 100,
            'recarga_pastilla' => false, 'aplicacion_producto' => true,
            'producto_nombre_snapshot' => 'Ensayo', 'producto_dosis' => 0.1254,
            'producto_unidad_dosis' => 'ml/L', 'ph_agua' => 6.5, 'pozo_accutab_mv' => 700,
            'operador_snapshot' => 'Responsable QA', 'formato_registro_snapshot' => RegistroControlHidrocooler::OFICIAL,
        ]))->setRelation('lote', $lote);
    }
}
