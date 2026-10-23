<?php

namespace Tests\Unit;

use App\Services\Materiales\GeometriaCapacidadMaterial;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class GeometriaCapacidadMaterialTest extends TestCase
{
    private function zona(array $cambios = []): array
    {
        return [...['id' => 'Z', 'nombre' => 'Zona A', 'largo_mm' => 3000, 'ancho_mm' => 2000, 'altura_util_mm' => 3000,
            'max_niveles' => 3, 'permite_giro' => true, 'limite_pallets' => null, 'peso_maximo_kg' => null, 'tipo_pallet_ids' => ['T']], ...$cambios];
    }

    private function simular(array $zona = [], array $carga = [], array $tipo = []): array
    {
        return (new GeometriaCapacidadMaterial)->simular(['zonas' => [$this->zona($zona)]],
            [...['id' => 'T', 'activo' => true, 'max_niveles' => 3, 'peso_maximo_kg' => null], ...$tipo],
            [...['largo_mm' => 1000, 'ancho_mm' => 1000, 'altura_total_mm' => 1000, 'max_niveles' => 3, 'peso_unitario_kg' => null], ...$carga]);
    }

    public function test_volumen_suficiente_no_implica_que_quepa_la_carga(): void
    {
        $r = $this->simular(['largo_mm' => 900, 'ancho_mm' => 100000]);
        $this->assertSame(0, $r['pallets_referencia']);
        $this->assertSame('incompatible', $r['zonas'][0]['estado']);
        $this->assertEquals(270, $r['zonas'][0]['volumen_util_m3']);
    }

    public function test_giro_se_aplica_solo_si_la_zona_lo_permite(): void
    {
        $z = ['largo_mm' => 1000, 'ancho_mm' => 1200, 'max_niveles' => 1];
        $c = ['largo_mm' => 1200, 'ancho_mm' => 1000];
        $this->assertSame(0, $this->simular([...$z, 'permite_giro' => false], $c)['pallets_referencia']);
        $r = $this->simular($z, $c);
        $this->assertSame(1, $r['pallets_referencia']);
        $this->assertSame('girada', $r['zonas'][0]['orientacion']);
    }

    public function test_altura_total_y_limites_de_apilado_y_posiciones_se_respetan(): void
    {
        $this->assertSame(12, $this->simular([], ['altura_total_mm' => 1500])['pallets_referencia']);
        $this->assertSame(6, $this->simular([], ['max_niveles' => 1])['pallets_referencia']);
        $this->assertSame(6, $this->simular([], [], ['max_niveles' => 1])['pallets_referencia']);
        $this->assertSame(6, $this->simular(['max_niveles' => 1])['pallets_referencia']);
        $this->assertSame(5, $this->simular(['limite_pallets' => 5])['pallets_referencia']);
    }

    public function test_peso_desconocido_no_cuenta_como_capacidad_verificada(): void
    {
        $r = $this->simular(['peso_maximo_kg' => 500]);
        $this->assertSame('pendiente_peso', $r['zonas'][0]['estado']);
        $this->assertSame(0, $r['pallets_referencia']);
        $this->assertSame('incompatible', $this->simular(['peso_maximo_kg' => 500], ['peso_unitario_kg' => 501])['zonas'][0]['estado']);
        $this->assertSame(18, $this->simular(['peso_maximo_kg' => 500], ['peso_unitario_kg' => 500])['pallets_referencia']);
        $this->assertSame(0, $this->simular([], ['peso_unitario_kg' => 100], ['peso_maximo_kg' => 99])['pallets_referencia']);
    }

    public function test_tipo_inactivo_o_no_habilitado_no_aporta_posiciones(): void
    {
        $this->assertSame(0, $this->simular([], [], ['activo' => false])['pallets_referencia']);
        $this->assertSame(0, $this->simular(['tipo_pallet_ids' => ['OTRO']])['pallets_referencia']);
    }

    public function test_milimetros_evitan_perder_una_posicion_por_redondeo_binario(): void
    {
        $r = $this->simular(['largo_mm' => GeometriaCapacidadMaterial::mm('4.530'), 'ancho_mm' => 800, 'permite_giro' => false, 'max_niveles' => 1],
            ['largo_mm' => GeometriaCapacidadMaterial::mm('1.510'), 'ancho_mm' => 800]);
        $this->assertSame(3, $r['pallets_referencia']);
    }

    public function test_zonas_adyacentes_son_validas_y_la_superposicion_se_rechaza(): void
    {
        $z = ['id' => 'A', 'nombre' => 'A', 'x_m' => 0, 'y_m' => 0, 'largo_m' => 2, 'ancho_m' => 2, 'altura_util_m' => 3,
            'max_niveles' => 1, 'permite_giro' => true, 'tipo_pallet_ids' => ['T']];
        $d = ['largo_m' => 4, 'ancho_m' => 2, 'altura_util_m' => 3, 'zonas' => [$z, [...$z, 'id' => 'B', 'nombre' => 'B', 'x_m' => 2]]];
        $this->assertCount(2, (new GeometriaCapacidadMaterial)->normalizarAlmacen($d)['zonas']);
        $d['zonas'][1]['x_m'] = 1.999;
        $this->expectException(ValidationException::class);
        (new GeometriaCapacidadMaterial)->normalizarAlmacen($d);
    }

    public function test_rack_usa_huecos_declarados_y_no_apila_pallets_dentro_de_ellos(): void
    {
        $zona = ['modo' => 'rack', 'limite_pallets' => 10, 'hueco_largo_mm' => 1200, 'hueco_ancho_mm' => 1200, 'hueco_altura_mm' => 1500];
        $r = $this->simular($zona, ['max_niveles' => 1], ['max_niveles' => 1]);
        $this->assertSame(10, $r['pallets_referencia']);
        $this->assertSame(1, $r['zonas'][0]['niveles']);
        $this->assertSame(0, $this->simular($zona, ['altura_total_mm' => 1600])['pallets_referencia']);
    }
}
