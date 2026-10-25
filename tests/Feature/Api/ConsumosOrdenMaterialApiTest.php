<?php

namespace Tests\Feature\Api;

use App\Models\ItemMaterial;
use App\Models\OrdenTransformacionMaterial;
use App\Services\Materiales\ConsultaConsumosOrdenMaterial;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Tests\Support\MultibodegaMaterialFixture;
use Tests\TestCase;

class ConsumosOrdenMaterialApiTest extends TestCase
{
    use MultibodegaMaterialFixture, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararMultibodega();
    }

    private function crearOt(string $referencia = 'OT-PRODUCCION', string $proceso = 'Armado'): array
    {
        $items = [];
        foreach (['material_mp', 'material_pt'] as $tipo) {
            $items[] = ItemMaterial::create(['cliente_material_id' => $this->itemA->cliente_material_id, 'codigo' => Str::upper(Str::random(8)),
                'nombre' => $tipo, 'categoria' => 'Embalaje', 'categoria_operacional' => $tipo, 'unidad_medida' => 'rollos', 'activo' => true,
                'creado_por_user_id' => $this->admin->id, 'actualizado_por_user_id' => $this->admin->id]);
        }
        $receta = $this->postJson('/api/materiales/transformaciones/recetas', ['cliente_id' => $this->clienteA->id, 'item_salida_id' => $items[1]->id,
            'nombre' => 'Receta OT', 'cantidad_base_salida' => 10,
            'componentes' => [['item_entrada_id' => $items[0]->id, 'cantidad_estandar' => 10, 'es_componente_principal' => true]],
        ])->assertCreated()->json('data');
        $orden = $this->postJson('/api/materiales/transformaciones/ordenes', ['operacion_id' => (string) Str::uuid(), 'version_receta_material_id' => $receta['versiones'][0]['id'],
            'cantidad_planificada_salida' => 10, 'fecha_operacional' => now()->toDateString(), 'referencia_ot' => $referencia,
            'proceso' => $proceso, 'linea' => 'L1', 'turno' => 'Día', 'operador_nombre' => 'Operador de producción',
        ])->assertCreated()->assertJsonPath('data.referencia_ot', $referencia)->assertJsonPath('data.proceso', $proceso)->json('data');
        // Fixture de órdenes ya iniciadas: este conjunto verifica imputación, no el inicio de transformación.
        DB::table('ordenes_transformacion_materiales')->where('id', $orden['id'])->update(['estado' => 'en_proceso']);

        return $orden;
    }

    private function consumo(array $ordenes): array
    {
        return ['operacion_id' => (string) Str::uuid(), 'tipo' => 'consumo', 'folio_id' => $this->origen->folio_id, 'cantidad' => 10,
            'almacen_origen_id' => $this->central->id, 'centro_costo_id' => $this->centroCosto->id, 'motivo' => 'Consumo real compartido de film', 'operador_nombre' => 'Persona en línea',
            'imputaciones_ot' => [['orden_id' => $ordenes[0]['id'], 'cantidad' => 3.5, 'cantidad_merma' => 0.5], ['orden_id' => $ordenes[1]['id'], 'cantidad' => 6.5, 'cantidad_merma' => 0]],
        ];
    }

    public function test_consumo_compartido_descuenta_una_vez_y_reintento_reordenado_conserva_imputaciones(): void
    {
        $ordenes = [$this->crearOt('OT-1'), $this->crearOt('OT-2')];
        $payload = $this->consumo($ordenes);
        $ruta = '/api/materiales/almacenes/movimientos';
        $id = $this->postJson($ruta, $payload)->assertCreated()->json('data.id');
        $payload['imputaciones_ot'] = array_reverse($payload['imputaciones_ot']);
        $this->postJson($ruta, $payload)->assertCreated()->assertJsonPath('data.id', $id);
        $this->assertEquals(90, $this->saldo($this->central->id)->cantidad_actual);
        $this->assertDatabaseCount('imputaciones_consumo_materiales', 2);
        $this->assertSame(1, DB::table('movimientos_almacenes_materiales')->where('operacion_id', $payload['operacion_id'])->count());
        $detalle = $this->getJson('/api/materiales/transformaciones/ordenes/'.$ordenes[0]['id'].'/consumos')->assertOk()->json('data');
        $fila = collect($detalle['componentes'])->firstWhere('item_id', $this->itemA->id);
        $this->assertEquals(3.5, $fila['consumo_neto']);
        $this->assertEquals(3, $fila['utilizado']);
        $this->assertSame('sin_estandar', $fila['estado']);
        $this->assertSame('Persona en línea', $detalle['registros'][0]['operador']);
        $this->assertSame($this->admin->name, $detalle['registros'][0]['registrado_por']);
        $payload['imputaciones_ot'][0]['cantidad'] = 6.4;
        $this->postJson($ruta, $payload)->assertConflict();
        $this->assertEquals(90, $this->saldo($this->central->id)->cantidad_actual);
    }

    public function test_distribucion_incompleta_duplicada_o_merma_excesiva_no_modifica_stock(): void
    {
        $p = $this->consumo([$this->crearOt('OT-1'), $this->crearOt('OT-2')]);
        $sinCuadrar = $p;
        $sinCuadrar['imputaciones_ot'][0]['cantidad'] = 3;
        $merma = $p;
        $merma['imputaciones_ot'][0]['cantidad_merma'] = 4;
        $duplicada = $p;
        $duplicada['imputaciones_ot'][1]['orden_id'] = $p['imputaciones_ot'][0]['orden_id'];
        foreach ([$sinCuadrar, $merma, $duplicada] as $datos) {
            $this->postJson('/api/materiales/almacenes/movimientos', $datos)->assertUnprocessable();
        }
        $this->assertEquals(100, $this->saldo($this->central->id)->cantidad_actual);
        $this->assertDatabaseCount('imputaciones_consumo_materiales', 0);
    }

    public function test_no_imputa_a_ot_cerrada_ot_ajena_ni_temporada_inactiva(): void
    {
        $ordenes = [$this->crearOt('OT-1'), $this->crearOt('OT-2')];
        $p = $this->consumo($ordenes);
        $consulta = DB::table('ordenes_transformacion_materiales')->where('id', $ordenes[1]['id']);
        $consulta->update(['estado' => 'cerrada']);
        $this->postJson('/api/materiales/almacenes/movimientos', $p)->assertUnprocessable();
        $consulta->update(['estado' => 'en_proceso', 'cliente_id' => $this->clienteB->id]);
        $this->postJson('/api/materiales/almacenes/movimientos', $p)->assertUnprocessable();
        $consulta->update(['cliente_id' => $this->clienteA->id]);
        DB::table('temporadas')->update(['activa' => false]);
        $this->postJson('/api/materiales/almacenes/movimientos', $p)->assertUnprocessable();
        $this->assertEquals(100, $this->saldo($this->central->id)->cantidad_actual);
        $this->assertDatabaseCount('imputaciones_consumo_materiales', 0);
    }

    public function test_consulta_historica_filtros_y_excel_usan_el_mismo_alcance_y_no_escriben(): void
    {
        $o = $this->crearOt('OT-HISTORICA', 'Embalaje');
        $this->crearOt('OT-OTRA', 'Mantención');
        $temporada = OrdenTransformacionMaterial::findOrFail($o['id'])->temporada_id;
        DB::table('temporadas')->update(['activa' => false]);
        $q = http_build_query(['temporada_id' => $temporada, 'proceso' => 'Embalaje', 'turno' => 'Día', 'linea' => 'L1', 'operador' => 'producción', 'hasta' => now()->toDateString()]);
        $this->getJson('/api/materiales/transformaciones/consumos?'.$q)->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.referencia_ot', 'OT-HISTORICA');
        $excel = $this->get('/api/materiales/transformaciones/consumos/excel?'.$q)->assertOk();
        $zip = new \ZipArchive;
        $zip->open($excel->baseResponse->getFile()->getPathname());
        $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        $this->assertStringContainsString('OT-HISTORICA', $xml);
        $this->assertStringNotContainsString('OT-OTRA', $xml);
        $this->getJson('/api/materiales/transformaciones/consumos?desde=2026-10-25&hasta=2026-10-24')->assertUnprocessable();
        $this->assertDatabaseCount('imputaciones_consumo_materiales', 0);
        $this->assertEquals(100, $this->saldo($this->central->id)->cantidad_actual);
    }

    public function test_permisos_y_compatibilidad_de_consumos_sin_ot(): void
    {
        $p = $this->consumo([$this->crearOt('OT-1'), $this->crearOt('OT-2')]);
        Gate::define('gestionar-transformaciones-materiales', fn () => false);
        $this->postJson('/api/materiales/almacenes/movimientos', $p)->assertForbidden();
        unset($p['imputaciones_ot']);
        $this->postJson('/api/materiales/almacenes/movimientos', $p)->assertCreated();
        $this->assertEquals(90, $this->saldo($this->central->id)->cantidad_actual);
        Gate::define('consultar-transformaciones-materiales', fn () => false);
        $this->getJson('/api/materiales/transformaciones/consumos')->assertForbidden();
    }

    public function test_cambiar_catalogo_no_reescribe_el_snapshot_de_un_consumo_compartido(): void
    {
        $p = $this->consumo([$this->crearOt('OT-1'), $this->crearOt('OT-2')]);
        $this->postJson('/api/materiales/almacenes/movimientos', $p)->assertCreated();
        $orden = OrdenTransformacionMaterial::findOrFail($p['imputaciones_ot'][0]['orden_id']);
        $original = app(ConsultaConsumosOrdenMaterial::class)->detalle($orden, false)['componentes'];
        DB::table('items_materiales')->where('id', $this->itemA->id)->update(['nombre' => 'Otro nombre']);
        $this->assertSame($original, app(ConsultaConsumosOrdenMaterial::class)->detalle($orden->fresh(), false)['componentes']);
    }
}
