<?php

namespace Tests\Unit;

use App\Services\Envases\ResumenEnvasesPorCsg;
use PHPUnit\Framework\TestCase;

class ResumenEnvasesPorCsgTest extends TestCase
{
    private function linea(array $cambios = []): array
    {
        return [...[
            'movimiento' => 'recepcion_fruta', 'recepcion_id' => 'r1',
            'tipo_envase' => 'bins', 'nombre_envase' => 'Bins plástico', 'orden' => 1,
            'entradas' => 10, 'salidas' => 0, 'csg' => null,
        ], ...$cambios];
    }

    private function csg(string $codigo): array
    {
        return ['codigo' => $codigo, 'nombre' => 'Productor '.$codigo];
    }

    public function test_agrupa_entradas_salidas_y_reversas_por_codigo_sin_sumar_envases_distintos(): void
    {
        $csg = $this->csg('012345');
        $movimientos = [
            $this->linea(),
            $this->linea(['movimiento' => 'despacho_cliente', 'csg' => $csg, 'entradas' => 0, 'salidas' => 8]),
            $this->linea(['movimiento' => 'reversion_despacho', 'csg' => $csg, 'entradas' => 0, 'salidas' => -2]),
            $this->linea(['tipo_envase' => 'totes', 'nombre_envase' => 'Tote', 'orden' => 3, 'entradas' => 240]),
        ];
        $r = ResumenEnvasesPorCsg::generar($movimientos, ['r1' => ['csg' => [$csg]]]);
        $this->assertCount(1, $r);
        $this->assertSame('012345', $r[0]['codigo']);
        $this->assertSame([10, 240], array_column($r[0]['tipos'], 'entradas'));
        $this->assertSame([6, 0], array_column($r[0]['tipos'], 'salidas'));
    }

    public function test_reparte_una_recepcion_multicsg_solo_con_cantidades_validadas_exactas(): void
    {
        $a = $this->csg('11111');
        $b = $this->csg('22222');
        $movimientos = [$this->linea(['entradas' => 4]), $this->linea(['entradas' => 6])];
        $recepciones = ['r1' => ['csg' => [$a, $b], 'envases' => ['bins' => [
            [...$a, 'cantidad' => 3], [...$b, 'cantidad' => 7],
        ]]]];
        $r = ResumenEnvasesPorCsg::generar($movimientos, $recepciones);
        $this->assertSame(['11111', '22222'], array_column($r, 'codigo'));
        $this->assertSame([3, 7], array_map(fn ($grupo) => $grupo['tipos'][0]['entradas'], $r));
        // Una corrección posterior sin reparto actualizado no autoriza un prorrateo.
        $movimientos[0]['entradas'] = 5;
        $r = ResumenEnvasesPorCsg::generar($movimientos, $recepciones);
        $this->assertNull($r[0]['codigo']);
        $this->assertSame(11, $r[0]['tipos'][0]['entradas']);
    }

    public function test_saldo_final_suma_arrastre_y_devoluciones_por_productor_y_envase(): void
    {
        $a = $this->csg('152425');
        $b = $this->csg('162425');
        $despacho = ['movimiento' => 'despacho_cliente', 'entradas' => 0, 'csg' => $a];
        $anteriores = [
            $this->linea([...$despacho, 'salidas' => 198]),
            $this->linea([...$despacho, 'salidas' => 20, 'csg' => $b]),
            $this->linea([...$despacho, 'tipo_envase' => 'totes', 'nombre_envase' => 'Tote', 'orden' => 3, 'salidas' => 4752]),
        ];
        $movimientos = [
            $this->linea(['entradas' => 18]),
            $this->linea(['movimiento' => 'salida_recepcion_fruta', 'entradas' => 0, 'salidas' => 5]),
        ];

        $resumen = ResumenEnvasesPorCsg::generar($movimientos, ['r1' => ['csg' => [$a]]], $anteriores);

        $this->assertSame(['152425', '162425'], array_column($resumen, 'codigo'));
        $this->assertSame([18, 0], array_column($resumen[0]['tipos'], 'entradas'));
        $this->assertSame([5, 0], array_column($resumen[0]['tipos'], 'salidas'));
        $this->assertSame([-185, -4752], array_column($resumen[0]['tipos'], 'saldo_final'));
        $this->assertSame(-20, $resumen[1]['tipos'][0]['saldo_final']);
    }

    public function test_salidas_multiorigen_no_heredan_un_reparto_de_entrada(): void
    {
        $a = $this->csg('11111');
        $b = $this->csg('22222');
        $lineas = [$this->linea(['movimiento' => 'salida_recepcion_fruta', 'entradas' => 0, 'salidas' => 4])];
        $r = ResumenEnvasesPorCsg::generar($lineas, ['r1' => ['csg' => [$a, $b]]]);
        $this->assertNull($r[0]['codigo']);
        $this->assertSame(4, $r[0]['tipos'][0]['salidas']);
        $r = ResumenEnvasesPorCsg::generar($lineas, ['r1' => ['csg' => [$a]]]);
        $this->assertSame('11111', $r[0]['codigo']);
    }

    public function test_segmentos_sin_codigo_conservan_su_parte_y_los_ajustes_no_desaparecen(): void
    {
        $a = $this->csg('11111');
        $lineas = [$this->linea(), $this->linea(['movimiento' => 'ajuste_cuenta', 'entradas' => -2])];
        $r = ResumenEnvasesPorCsg::generar($lineas, ['r1' => ['csg' => [$a, ['codigo' => null]], 'envases' => ['bins' => [
            [...$a, 'cantidad' => 6], ['codigo' => null, 'cantidad' => 4],
        ]]]]);
        $this->assertSame(['11111', null], array_column($r, 'codigo'));
        $this->assertSame([6, 2], array_map(fn ($grupo) => $grupo['tipos'][0]['entradas'], $r));
    }

    public function test_sin_reparto_historico_o_con_totales_negativos_no_inventa_atribucion(): void
    {
        $a = $this->csg('11111');
        $b = $this->csg('22222');
        foreach ([[], ['bins' => [[...$a, 'cantidad' => -2], [...$b, 'cantidad' => 12]]]] as $envases) {
            $r = ResumenEnvasesPorCsg::generar([$this->linea()], ['r1' => ['csg' => [$a, $b], 'envases' => $envases]]);
            $this->assertNull($r[0]['codigo']);
            $this->assertSame(10, $r[0]['tipos'][0]['entradas']);
        }
    }

    public function test_totales_por_envase_cuadran_aun_con_mezcla_de_origenes_y_direcciones(): void
    {
        $movimientos = [];
        for ($i = 1; $i <= 500; $i++) {
            $movimientos[] = $this->linea([
                'movimiento' => $i % 2 ? 'ajuste_cuenta' : 'despacho_cliente',
                'tipo_envase' => $i % 3 ? 'bins' : 'totes',
                'entradas' => $i % 2 ? $i % 17 - 4 : 0,
                'salidas' => $i % 2 ? 0 : $i % 23 - 2,
                'csg' => $i % 5 ? $this->csg((string) (1000 + $i % 8)) : null,
            ]);
        }
        $r = ResumenEnvasesPorCsg::generar($movimientos, []);
        foreach (['bins', 'totes'] as $tipo) {
            foreach (['entradas', 'salidas'] as $campo) {
                $esperado = array_sum(array_column(array_filter($movimientos, fn ($m) => $m['tipo_envase'] === $tipo), $campo));
                $actual = 0;
                foreach ($r as $grupo) {
                    $actual += array_sum(array_column(array_filter($grupo['tipos'], fn ($t) => $t['tipo_envase'] === $tipo), $campo));
                }
                $this->assertSame($esperado, $actual);
            }
        }
    }
}
