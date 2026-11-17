<?php

namespace Tests\Unit;

use App\Services\Validacion\ComparadorConciliacionValidacion;
use PHPUnit\Framework\TestCase;

class ComparadorConciliacionValidacionTest extends TestCase
{
    public function test_compara_una_mezcla_por_cajas_de_calibre_y_no_solo_por_el_resumen_mix(): void
    {
        $comparador = new ComparadorConciliacionValidacion;
        $fisico = [...$this->fisico(), 'calibre' => 'MIX', 'calibres' => ['2J' => 60, '3J' => 40]];
        $planta = [...$this->planta(), 'calibre' => '2J / 3J', 'mezclados' => ['calibre'], 'calibres' => ['3J' => 40, '2J' => 60]];
        $this->assertSame('coincide', $comparador->comparar($fisico, $planta)['calibre']['estado']);
        $planta['calibres'] = ['2J' => 40, '3J' => 60];
        $this->assertSame('diferente', $comparador->comparar($fisico, $planta)['calibre']['estado']);
        $this->assertSame('diferente', $comparador->comparar($fisico, $this->planta())['calibre']['estado']);
        $this->assertSame('diferente', $comparador->comparar($this->fisico(), $planta)['calibre']['estado']);
        $planta['calibres'] = [];
        $this->assertSame('por_revisar', $comparador->comparar($fisico, $planta)['calibre']['estado']);
        $this->assertSame(['2J' => 60, '3J' => 40], $comparador->porCalibre([
            ['calibre' => '2j', 'cajas' => 60], ['calibre' => '3J', 'cajas' => 40],
        ], 'cajas'));
    }

    private function fisico(): array
    {
        return ['cajas' => 100, 'marca' => 'Marca A', 'calibre' => '2J', 'csg' => ['001234' => 60, '001567' => 40]];
    }

    private function planta(): array
    {
        return [...$this->fisico(), 'presente' => true, 'marca_homologado' => 'Marca A', 'calibre_homologado' => '2J'];
    }

    public function test_compara_los_cuatro_campos_y_el_delta_de_cajas(): void
    {
        $p = [...$this->planta(), 'cajas' => 104, 'csg' => ['001234' => 64, '001567' => 40], 'marca_homologado' => 'Marca B'];
        $r = (new ComparadorConciliacionValidacion)->comparar($this->fisico(), $p);
        $this->assertSame(-4, $r['cajas']['diferencia']);
        $this->assertSame('diferente', $r['marca']['estado']);
        $this->assertSame('coincide', $r['calibre']['estado']);
        $this->assertSame('diferente', $r['csg']['estado']);
    }

    public function test_un_total_igual_no_oculta_una_distribucion_distinta_por_csg(): void
    {
        $p = [...$this->planta(), 'csg' => ['001234' => 40, '001567' => 60]];
        $r = (new ComparadorConciliacionValidacion)->comparar($this->fisico(), $p);
        $this->assertSame('coincide', $r['cajas']['estado']);
        $this->assertSame('diferente', $r['csg']['estado']);
    }

    public function test_sin_fuente_o_equivalencia_o_composicion_no_se_afirma_coincidencia(): void
    {
        $c = new ComparadorConciliacionValidacion;
        $this->assertSame(['por_revisar'], array_values(array_unique(array_column($c->comparar($this->fisico(), null), 'estado'))));
        $p = [...$this->planta(), 'marca_homologado' => null, 'csg' => []];
        $r = $c->comparar($this->fisico(), $p);
        $this->assertSame('por_revisar', $r['marca']['estado']);
        $this->assertSame('por_revisar', $r['csg']['estado']);
        $this->assertSame('coincide', $r['calibre']['estado']);
    }

    public function test_csg_conserva_ceros_y_agrupa_sin_depender_del_orden_de_las_lineas(): void
    {
        $c = new ComparadorConciliacionValidacion;
        $lineas = [['csg' => '001234', 'cantidad_cajas' => 40], ['csg' => '001567', 'cantidad_cajas' => 40], ['csg' => '001234', 'cantidad_cajas' => 20]];
        $this->assertSame($this->fisico()['csg'], $c->porCsg($lineas, 'cantidad_cajas'));
        $this->assertSame($c->porCsg($lineas, 'cantidad_cajas'), $c->porCsg(array_reverse($lineas), 'cantidad_cajas'));
        $this->assertTrue($c->iguales('csg', ['001234' => 60, '001567' => 40], ['001567' => 40, '001234' => 60]));
        $r = $c->comparar($this->fisico(), [...$this->planta(), 'csg' => ['1234' => 60, '001567' => 40]]);
        $this->assertSame('diferente', $r['csg']['estado']);
    }

    public function test_usa_la_equivalencia_y_no_crea_diferencias_por_acentos_o_espacios(): void
    {
        $p = [...$this->planta(), 'marca' => 'COD-PLANTA', 'marca_homologado' => ' MÁRCA  a '];
        $r = (new ComparadorConciliacionValidacion)->comparar($this->fisico(), $p);
        $this->assertSame('coincide', $r['marca']['estado']);
        $this->assertSame('COD-PLANTA', $r['marca']['planta']);
    }
}
