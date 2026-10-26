<?php

namespace Tests\Unit\Existencias;

use App\Services\Existencias\DesgloseProductoTerminado;
use PHPUnit\Framework\TestCase;

class DesgloseProductoTerminadoTest extends TestCase
{
    public function test_pallet_mixto_desglosa_csg_predio_y_cajas_sin_multiplicar_el_total(): void
    {
        $cabecera = ['folio' => '1000000059', 'tipo_bulto' => 'Pallet', 'cantidad_cajas' => 135, 'csg' => 'MIX', 'predio' => 'MIX', 'calibre' => '2J', 'camara' => 'C5'];
        $filas = (new DesgloseProductoTerminado)->filas($cabecera, ['composicion' => [
            ['csg' => '119003', 'predio' => 'LONGO SA', 'cantidad_cajas' => 90],
            ['csg' => '3126339', 'predio' => 'AGROFRUTICOLA S', 'cantidad_cajas' => 45],
        ]]);
        $this->assertCount(2, $filas);
        $this->assertSame(135, array_sum(array_column($filas, 'cantidad_cajas')));
        $this->assertSame(['119003', '3126339'], array_column($filas, 'csg'));
        $this->assertSame(['LONGO SA', 'AGROFRUTICOLA S'], array_column($filas, 'predio'));
        $this->assertSame(['2J', '2J'], array_column($filas, 'calibre'));
        $this->assertSame(['C5', 'C5'], array_column($filas, 'camara'));
        $this->assertSame(['1000000059', '1000000059'], array_column($filas, 'folio'));
    }

    public function test_saldo_conserva_todos_los_atributos_de_cada_linea_y_no_cruza_combinaciones(): void
    {
        $campos = ['cliente', 'marca', 'especie', 'variedad', 'calibre', 'envase', 'categoria', 'csg', 'predio'];
        $cabecera = ['folio' => 'SAL-67', 'tipo_bulto' => 'Saldo', 'cantidad_cajas' => 67, ...array_fill_keys($campos, 'MIX')];
        $a = array_combine($campos, ['Cliente A', 'Marca A', 'Cereza', 'Santina', '2J', '5 kg', 'Cat A', '00123', 'Predio A']);
        $b = array_combine($campos, ['Cliente B', 'Marca B', 'Cereza', 'Lapins', '3J', '2 kg', 'Cat B', '00456', 'Predio B']);
        $filas = (new DesgloseProductoTerminado)->filas($cabecera, ['composicion' => [
            [...$a, 'cantidad_cajas' => 50, 'fecha_embalaje' => '2026-10-24', 'lote_materia_prima' => 'L-1', 'proceso_packing' => 'P-1'],
            [...$b, 'cantidad_cajas' => 17, 'fecha_embalaje' => '2026-10-25', 'lote_materia_prima' => 'L-2', 'proceso_packing' => 'P-2'],
        ]]);
        $this->assertCount(2, $filas);
        foreach ($campos as $campo) {
            $this->assertSame([$a[$campo], $b[$campo]], array_column($filas, $campo));
        }
        $this->assertSame([50, 17], array_column($filas, 'cantidad_cajas'));
        $this->assertSame(['L-1', 'L-2'], array_column($filas, 'lote_materia_prima'));
        $this->assertSame(['P-1', 'P-2'], array_column($filas, 'proceso_packing'));
        $this->assertSame(['2026-10-24', '2026-10-25'], array_column($filas, 'fecha_embalaje'));
    }

    public function test_repaletizaje_hereda_especificaciones_solo_del_origen_que_corresponde(): void
    {
        $datos = ['composicion' => [['csg' => '111', 'cantidad_cajas' => 12], ['csg' => '222', 'cantidad_cajas' => 8]],
            'genealogia_repaletizaje' => [
                ['especificaciones' => ['variedad' => 'Santina', 'calibre' => '2J', 'envase' => '5 kg'], 'composicion_aportada' => [['csg' => '111']]],
                ['especificaciones' => ['variedad' => 'Lapins', 'calibre' => '3J', 'envase' => '2 kg'], 'composicion_aportada' => [['csg' => '222']]],
            ]];
        $filas = (new DesgloseProductoTerminado)->filas(['cantidad_cajas' => 20, 'variedad' => 'MIX', 'calibre' => 'MIX', 'envase' => 'MIX'], $datos);
        $this->assertSame(['Santina', 'Lapins'], array_column($filas, 'variedad'));
        $this->assertSame(['2J', '3J'], array_column($filas, 'calibre'));
        $this->assertSame(['5 kg', '2 kg'], array_column($filas, 'envase'));
    }

    public function test_una_herencia_ambigua_no_inventa_un_calibre(): void
    {
        $filas = (new DesgloseProductoTerminado)->filas(['cantidad_cajas' => 20, 'calibre' => 'MIX'], [
            'composicion' => [['csg' => '111', 'cantidad_cajas' => 20]], 'genealogia_repaletizaje' => [
                ['especificaciones' => ['calibre' => '2J'], 'composicion_aportada' => [['csg' => '111']]],
                ['especificaciones' => ['calibre' => '3J'], 'composicion_aportada' => [['csg' => '111']]],
            ],
        ]);
        $this->assertSame('MIX', $filas[0]['calibre']);
        $this->assertStringContainsString('parcial', $filas[0]['estado_desglose']);
    }

    public function test_composicion_incompleta_conserva_las_cajas_faltantes_sin_repartirlas(): void
    {
        $filas = (new DesgloseProductoTerminado)->filas(['cantidad_cajas' => 67, 'csg' => 'MIX'], ['composicion' => [
            ['csg' => '111', 'cantidad_cajas' => 50], ['csg' => '222', 'cantidad_cajas' => 0],
        ]]);
        $this->assertSame([50, 17], array_column($filas, 'cantidad_cajas'));
        $this->assertStringContainsString('sin composición', $filas[1]['estado_desglose']);
    }

    public function test_composicion_que_supera_el_total_no_infla_la_existencia(): void
    {
        $filas = (new DesgloseProductoTerminado)->filas(['cantidad_cajas' => 67, 'csg' => 'MIX'], ['composicion' => [
            ['csg' => '111', 'cantidad_cajas' => 50], ['csg' => '222', 'cantidad_cajas' => 30],
        ]]);
        $this->assertCount(1, $filas);
        $this->assertSame(67, $filas[0]['cantidad_cajas']);
        $this->assertStringContainsString('inconsistente', $filas[0]['estado_desglose']);
    }

    public function test_folio_antiguo_sin_composicion_conserva_su_cantidad_y_atributos(): void
    {
        $base = ['folio' => 'LEGADO', 'cantidad_cajas' => 84, 'csg' => '111', 'calibre' => '2J'];
        $filas = (new DesgloseProductoTerminado)->filas($base, []);
        $this->assertCount(1, $filas);
        foreach ($base as $campo => $valor) {
            $this->assertSame($valor, $filas[0][$campo]);
        }
    }

    public function test_origen_sin_atributo_no_se_completa_con_el_de_otro_origen(): void
    {
        $filas = (new DesgloseProductoTerminado)->filas(['cantidad_cajas' => 20, 'categoria' => 'MIX'], [
            'composicion' => [['csg' => '111', 'cantidad_cajas' => 20]], 'genealogia_repaletizaje' => [
                ['especificaciones' => ['categoria' => 'CAT A'], 'composicion_aportada' => [['csg' => '111']]],
                ['especificaciones' => [], 'composicion_aportada' => [['csg' => '111']]],
            ],
        ]);
        $this->assertSame('MIX', $filas[0]['categoria']);
        $this->assertStringContainsString('parcial', $filas[0]['estado_desglose']);
    }

    public function test_cantidades_invalidas_no_se_redondean_ni_reparten(): void
    {
        foreach ([1.5, -1, 'error', []] as $cantidad) {
            $filas = (new DesgloseProductoTerminado)->filas(['cantidad_cajas' => 67, 'csg' => 'MIX'], [
                'composicion' => [['csg' => '111', 'cantidad_cajas' => $cantidad]],
            ]);
            $this->assertCount(1, $filas);
            $this->assertSame(67, $filas[0]['cantidad_cajas']);
            $this->assertStringContainsString('inválida', $filas[0]['estado_desglose']);
        }
    }
}
