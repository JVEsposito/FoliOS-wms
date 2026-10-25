<?php

namespace Tests\Unit;

use App\Models\ClienteMaterial;
use App\Models\CuadraturaTransformacionMaterial;
use App\Models\Folio;
use App\Models\FolioMaterial;
use App\Models\ImputacionConsumoMaterial;
use App\Models\ItemMaterial;
use App\Models\LoteTransformacionMaterial;
use App\Models\MovimientoInventarioMaterial;
use App\Models\OrdenTransformacionMaterial;
use App\Models\User;
use App\Services\Materiales\ConsultaConsumosOrdenMaterial;
use App\Services\Materiales\ServicioImputacionConsumoMaterial;
use Illuminate\Database\Eloquent\Collection;
use Tests\TestCase;

class ConsumosOrdenMaterialTest extends TestCase
{
    private function orden(string $modo = 'por_lote', string $estado = 'en_proceso'): OrdenTransformacionMaterial
    {
        $o = new OrdenTransformacionMaterial(['estado' => $estado, 'modo_operacion' => $modo, 'cantidad_planificada_salida' => 100,
            'operador_nombre' => 'Operador físico', 'snapshot_receta' => ['salida' => ['cantidad_base' => 10, 'unidad_medida' => 'cajas'],
                'componentes' => [['item_id' => 'film', 'codigo' => 'FILM', 'nombre' => 'Film original', 'unidad_medida' => 'kg', 'cantidad_estandar' => 2, 'tolerancia_porcentaje' => 5]]]]);
        $o->id = 'orden';
        foreach (['temporada', 'cliente', 'almacenOrigen', 'creadoPor'] as $r) {
            $o->setRelation($r, null);
        }
        $o->setRelation('lotes', new Collection([new LoteTransformacionMaterial(['estado' => 'cerrado', 'cantidad_real_salida' => 50, 'merma_real' => 4]),
            new LoteTransformacionMaterial(['estado' => 'anulado', 'cantidad_real_salida' => 50, 'merma_real' => 99])]));
        $o->setRelation('movimientosConsumo', new Collection);
        $o->setRelation('imputacionesConsumo', new Collection);
        $o->setRelation('cuadraturas', new Collection);

        return $o;
    }

    private function movimiento(string $tipo, float $cantidad): MovimientoInventarioMaterial
    {
        return new MovimientoInventarioMaterial(['item_material_id' => 'film', 'tipo' => $tipo, 'cantidad' => $cantidad]);
    }

    public function test_compara_con_salida_real_y_netea_devoluciones_y_reversas_sin_sumar_produccion(): void
    {
        $o = $this->orden();
        $o->setRelation('movimientosConsumo', new Collection([
            $this->movimiento('consumo_transformacion', -16), $this->movimiento('devolucion_transformacion', 2),
            $this->movimiento('reversa_transformacion', 3), $this->movimiento('produccion_transformacion', 50), $this->movimiento('reversa_transformacion', -50),
        ]));
        $datos = app(ConsultaConsumosOrdenMaterial::class)->detalle($o, false);
        $f = $datos['componentes'][0];
        $this->assertEquals(50, $datos['salida_real']);
        $this->assertEquals(4, $datos['merma_produccion']);
        $this->assertEquals(20, $f['estandar_planificado']);
        $this->assertEquals(10, $f['esperado']);
        $this->assertEquals(11, $f['utilizado']);
        $this->assertEquals(1, $f['desviacion']);
        $this->assertEquals(10, $f['desviacion_pct']);
        $this->assertSame('fuera_tolerancia', $f['estado']);
        $this->assertNull($f['merma_declarada']); // No convierte merma de cajas en kg de film.
    }

    public function test_retiro_a_linea_no_se_declara_utilizado_antes_de_cuadrar(): void
    {
        $o = $this->orden('cuadratura_cierre');
        $o->setRelation('movimientosConsumo', new Collection([$this->movimiento('consumo_transformacion', -20)]));
        $f = app(ConsultaConsumosOrdenMaterial::class)->detalle($o, false)['componentes'][0];
        $this->assertEquals(20, $f['consumo_neto']);
        $this->assertNull($f['utilizado']);
        $this->assertNull($f['desviacion']);
        $this->assertSame('pendiente_cuadratura', $f['estado']);
    }

    public function test_cuadratura_cerrada_separa_merma_de_lo_utilizado_y_respeta_unidades(): void
    {
        $o = $this->orden('cuadratura_cierre', 'cerrada');
        $o->setRelation('movimientosConsumo', new Collection([$this->movimiento('consumo_transformacion', -20), $this->movimiento('devolucion_transformacion', 7)]));
        $o->setRelation('cuadraturas', new Collection([new CuadraturaTransformacionMaterial(['item_material_id' => 'film', 'cantidad_merma' => 3])]));
        $f = app(ConsultaConsumosOrdenMaterial::class)->detalle($o, false)['componentes'][0];
        $this->assertEquals(13, $f['consumo_neto']);
        $this->assertEquals(3, $f['merma_declarada']);
        $this->assertEquals(3, $f['desviacion_neta']);
        $this->assertEquals(30, $f['desviacion_neta_pct']);
        $this->assertEquals(10, $f['utilizado']);
        $this->assertSame('dentro_tolerancia', $f['estado']);
    }

    public function test_compartido_se_suma_una_vez_y_no_inventa_estandar_ni_mezcla_unidades(): void
    {
        $o = $this->orden();
        $o->setRelation('imputacionesConsumo', new Collection([
            new ImputacionConsumoMaterial(['cantidad' => 3, 'cantidad_merma' => 1, 'snapshot' => ['item_id' => 'film', 'codigo' => 'FILM', 'nombre' => 'Film', 'unidad' => 'rollos']]),
        ]));
        $filas = app(ConsultaConsumosOrdenMaterial::class)->detalle($o, false)['componentes'];
        $this->assertCount(2, $filas);
        $this->assertSame('kg', $filas[0]['unidad']);
        $this->assertSame('rollos', $filas[1]['unidad']);
        $this->assertEquals(3, $filas[1]['consumo_neto']);
        $this->assertEquals(2, $filas[1]['utilizado']);
        $this->assertNull($filas[1]['esperado']);
        $this->assertNull($filas[1]['desviacion_pct']);
        $this->assertSame('sin_estandar', $filas[1]['estado']);
    }

    public function test_cancelacion_devuelta_completa_no_aparece_como_consumo_neto(): void
    {
        $o = $this->orden('cuadratura_cierre', 'cancelada');
        $o->setRelation('movimientosConsumo', new Collection([$this->movimiento('consumo_transformacion', -20), $this->movimiento('devolucion_transformacion', 20)]));
        $f = app(ConsultaConsumosOrdenMaterial::class)->detalle($o, false)['componentes'][0];
        $this->assertEquals(0, $f['consumo_neto']);
        $this->assertEquals(0, $f['utilizado']);
    }

    private function contextoImputacion(): array
    {
        $usuario = new User(['name' => 'Registrador']);
        $usuario->id = 8;
        $item = new ItemMaterial(['codigo' => 'FILM', 'nombre' => 'Film', 'unidad_medida' => 'kg']);
        $item->setRelation('cliente', new ClienteMaterial(['cliente_id' => 'c1']));
        $folio = new FolioMaterial(['categoria_operacional' => 'insumo', 'item_material_id' => 'film']);
        $folio->setRelation('item', $item);
        $folio->setRelation('folio', new Folio(['temporada_id' => 's1', 'numero_folio' => 'F001']));
        $ordenes = new Collection;
        foreach (['o1', 'o2'] as $id) {
            $o = $this->orden();
            $o->id = $id;
            $o->cliente_id = 'c1';
            $o->temporada_id = 's1';
            $ordenes->push($o);
        }

        return [$folio, $ordenes, $usuario];
    }

    public function test_distribucion_decimal_guarda_operador_y_registrador_distintos(): void
    {
        [$folio, $ordenes, $usuario] = $this->contextoImputacion();
        $filas = app(ServicioImputacionConsumoMaterial::class)->preparar(['cantidad' => 0.3, 'operador_nombre' => 'Operador real',
            'imputaciones_ot' => [['orden_id' => 'o1', 'cantidad' => 0.1], ['orden_id' => 'o2', 'cantidad' => 0.2]]], $folio, $ordenes, $usuario);
        $this->assertEquals(0.1, $filas[0]['cantidad']);
        $this->assertEquals(0.2, $filas[1]['cantidad']);
        $this->assertSame('Operador real', $filas[0]['snapshot']['operador']);
        $this->assertSame('Registrador', $filas[0]['snapshot']['registrado_por']);
    }

    public function test_reparto_incompleto_y_merma_excesiva_se_rechazan_antes_de_descontar(): void
    {
        [$folio, $ordenes, $usuario] = $this->contextoImputacion();
        foreach ([['orden_id' => 'o1', 'cantidad' => 0.1], ['orden_id' => 'o1', 'cantidad' => 0.3, 'cantidad_merma' => 0.4]] as $linea) {
            try {
                app(ServicioImputacionConsumoMaterial::class)->preparar(['cantidad' => 0.3, 'imputaciones_ot' => [$linea]], $folio, $ordenes, $usuario);
                $this->fail('La distribución debe rechazarse');
            } catch (\DomainException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
    }

    public function test_no_distribuye_materiales_ajenos_o_que_no_sean_insumos(): void
    {
        [$folio, $ordenes, $usuario] = $this->contextoImputacion();
        $ordenes[0]->cliente_id = 'otro';
        $datos = ['cantidad' => 1, 'imputaciones_ot' => [['orden_id' => 'o1', 'cantidad' => 1]]];
        foreach (['insumo', 'material_mp'] as $tipo) {
            $folio->categoria_operacional = $tipo;
            try {
                app(ServicioImputacionConsumoMaterial::class)->preparar($datos, $folio, $ordenes, $usuario);
                $this->fail('No debe imputar este material');
            } catch (\DomainException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
    }
}
