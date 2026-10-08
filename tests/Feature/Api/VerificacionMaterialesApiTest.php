<?php

namespace Tests\Feature\Api;

use App\Services\Verificaciones\ServicioIndicadoresVerificacionMateriales;
use App\Services\Verificaciones\ServicioVerificacionesUbicacion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\VerificacionMaterialesFixture;
use Tests\TestCase;

/** Esquema mínimo aislado: no depende de las migraciones históricas de la aplicación. */
class VerificacionMaterialesApiTest extends TestCase
{
    use VerificacionMaterialesFixture;

    public function test_materiales_desactivado_no_crea_ronda_y_frio_conserva_su_comportamiento(): void
    {
        $this->posicion();
        $this->posicion('productos');
        config(['verificaciones.materiales.habilitada' => false]);
        $servicio = app(ServicioVerificacionesUbicacion::class);
        $this->assertNull($servicio->actual($this->camarero, $this->tablet));
        DB::table('users')->where('id', $this->camarero->id)->update(['rol' => 'camarero_frio']);
        $ronda = $servicio->actual($this->camarero->fresh(), $this->tablet);
        $this->assertSame('productos', $ronda->contenido);
        $this->assertSame('productos', $ronda->items->first()->posicion->camara->contenido->value);
    }

    public function test_asigna_solo_materiales_excluye_toda_reserva_retiro_y_recientes_pero_incluye_bloqueados(): void
    {
        $this->posicion('productos');
        $valida = $this->posicion();
        $this->folioEn($valida, 'BLOQUEADO', 10, true);
        foreach (['reservas_materiales', 'reservas_transformacion_materiales', 'tarea', 'reciente'] as $caso) {
            $posicion = $this->posicion();
            $folio = $this->folioEn($posicion, 'NO-'.$caso);
            // Un segundo folio en la posición: hay que excluirla completa.
            $this->folioEn($posicion, 'SEGUNDO-'.$caso);
            if (str_starts_with($caso, 'reservas')) {
                DB::table($caso)->insert(['id' => (string) Str::uuid(), 'folio_id' => $folio, 'estado' => 'activa']);
            } elseif ($caso === 'tarea') {
                DB::table('tareas_movimiento')->insert(['id' => (string) Str::uuid(), 'folio_id' => $folio, 'estado' => 'en_proceso']);
            } else {
                $ronda = $this->ronda();
                $ronda->items()->create(['posicion_id' => $posicion->id, 'resultado' => 'coincide', 'verificada_at' => now()->subDay()]);
                DB::table('verificaciones_ubicacion')->where('id', $ronda->id)->update(['turno_inicio_at' => now()->subDay()]);
            }
        }
        $ronda = app(ServicioVerificacionesUbicacion::class)->actual($this->camarero, $this->tablet);
        $this->assertSame('materiales', $ronda->contenido);
        $this->assertSame($valida->id, $ronda->items->first()->posicion_id);
        $this->assertNull($ronda->items->first()->folio_esperado_id);
    }

    public function test_tres_folios_coinciden_y_api_es_ciega_antes_y_despues(): void
    {
        $posicion = $this->posicion();
        foreach (['MAT-A', 'MAT-B', 'MAT-C'] as $numero) {
            $this->folioEn($posicion, $numero);
        }
        $this->apiCiega();
        $respuesta = $this->getJson('/api/verificaciones-ubicacion/actual')->assertOk();
        $this->assertCiega($respuesta->getContent());
        $item = $respuesta->json('data.items.0');
        $payload = ['operacion_id' => (string) Str::uuid(), 'version' => $item['version'], 'respuesta' => 'folios',
            'folios' => array_map(fn ($n) => ['numero_folio' => $n, 'cantidad_contada' => 10], ['MAT-A', 'MAT-B', 'MAT-C'])];
        $ruta = "/api/verificaciones-ubicacion/items/{$item['id']}/resultado";
        $antes = DB::table('saldos_materiales_almacenes')->orderBy('id')->get()->toJson();
        $respuesta = $this->postJson($ruta, $payload)->assertOk()->assertJsonPath('resultado', 'coincide');
        $this->assertCiega($respuesta->getContent());
        $this->postJson($ruta, $payload)->assertOk();
        $this->assertDatabaseCount('verificaciones_ubicacion_folios', 3);
        $this->assertDatabaseCount('incidencias_verificacion_ubicacion', 0);
        $this->assertSame($antes, DB::table('saldos_materiales_almacenes')->orderBy('id')->get()->toJson());
        $this->getJson('/api/verificaciones-ubicacion/material?numero_folio=MAT-A')->assertOk()
            ->assertExactJson(['data' => ['unidad_medida' => 'unidad']]);
        $payload['folios'][0]['cantidad_contada'] = 5;
        $this->postJson($ruta, $payload)->assertConflict();
    }

    public function test_faltante_y_sobrante_abren_una_incidencia_por_folio_con_la_otra_posicion(): void
    {
        $posicion = $this->posicion();
        $otra = $this->posicion();
        $ids = [];
        foreach (['MAT-A', 'MAT-B', 'MAT-C'] as $numero) {
            $ids[] = $this->folioEn($posicion, $numero);
        }
        $ajeno = $this->folioEn($otra, 'MAT-AJENO');
        $item = $this->itemEn($posicion);
        [$ronda] = $this->confirmar($item, [['numero_folio' => 'MAT-A', 'cantidad_contada' => 10],
            ['numero_folio' => 'MAT-B', 'cantidad_contada' => 10], ['numero_folio' => 'MAT-AJENO', 'cantidad_contada' => 10]]);
        $this->assertDatabaseHas('verificaciones_ubicacion_folios', ['folio_esperado_id' => $ids[2], 'resultado' => 'folio_faltante']);
        $this->assertDatabaseHas('incidencias_verificacion_ubicacion', ['folio_encontrado_id' => $ajeno, 'tipo' => 'folio_sobrante', 'otra_posicion_id' => $otra->id]);
        $this->assertDatabaseCount('incidencias_verificacion_ubicacion', 2);
        $this->assertSame('completada', $ronda->estado);
    }

    public function test_el_orden_de_claves_json_no_invalida_una_posicion_sin_movimientos(): void
    {
        $posicion = $this->posicion();
        $this->folioEn($posicion, 'MAT-A');
        $item = $this->itemEn($posicion);
        $snapshot = array_reverse($item->snapshot_materiales, true);
        $snapshot['saldos'] = array_map(fn ($s) => array_reverse($s, true), $snapshot['saldos']);
        $item->update(['snapshot_materiales' => $snapshot]);
        [, $registrado] = $this->confirmar($item, [['numero_folio' => 'MAT-A', 'cantidad_contada' => 10]]);
        $this->assertSame('coincide', $registrado->resultado);
    }

    public function test_cantidad_se_compara_con_custodia_local_y_tolerancia_uno_y_cinco_por_ciento(): void
    {
        $posicion = $this->posicion();
        $a = $this->folioEn($posicion, 'MAT-A', 100);
        $this->folioEn($posicion, 'MAT-B', 100);
        // El total del folio está distribuido entre dos almacenes.
        DB::table('folios_materiales')->where('folio_id', $a)->update(['cantidad_actual' => 150]);
        DB::table('saldos_materiales_almacenes')->insert(['id' => (string) Str::uuid(), 'folio_id' => $a,
            'almacen_material_id' => (string) Str::uuid(), 'cantidad_actual' => 50, 'version' => 1]);
        [, $item] = $this->confirmar($this->itemEn($posicion), [['numero_folio' => 'MAT-A', 'cantidad_contada' => 101], ['numero_folio' => 'MAT-B', 'cantidad_contada' => 105]]);
        $this->assertSame('diferencia_cantidad', $item->resultado);
        $this->assertDatabaseHas('verificaciones_ubicacion_folios', ['folio_esperado_id' => $a, 'cantidad_esperada' => 100, 'resultado' => 'coincide']);
        $this->assertDatabaseHas('incidencias_verificacion_ubicacion', ['cantidad_esperada' => 100, 'cantidad_contada' => 105, 'tipo' => 'diferencia_cantidad']);
        $this->assertSame(150.0, (float) DB::table('folios_materiales')->where('folio_id', $a)->value('cantidad_actual'));
    }

    public function test_consumo_en_packing_no_invalida_la_posicion_en_bodega_incluso_con_foto_anterior(): void
    {
        $posicion = $this->posicion();
        $folio = $this->folioEn($posicion, 'DISTRIBUIDO', 100);
        $packing = (string) Str::uuid();
        DB::table('folios_materiales')->where('folio_id', $folio)->update(['cantidad_actual' => 150]);
        DB::table('saldos_materiales_almacenes')->insert(['id' => $packing, 'folio_id' => $folio,
            'almacen_material_id' => (string) Str::uuid(), 'cantidad_actual' => 50, 'version' => 1]);
        $item = $this->itemEn($posicion);
        $foto = $item->snapshot_materiales;
        $this->assertArrayNotHasKey('movimientos_materiales', $foto);
        // Una ronda pendiente de la versión anterior también sigue siendo válida.
        $item->update(['snapshot_materiales' => $foto + ['movimientos_materiales' => 0]]);
        DB::table('movimientos_almacenes_materiales')->insert(['id' => (string) Str::uuid(),
            'folio_id' => $folio, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('saldos_materiales_almacenes')->where('id', $packing)->update(['cantidad_actual' => 45, 'version' => 2]);
        DB::table('folios_materiales')->where('folio_id', $folio)->update(['cantidad_actual' => 145]);
        [$ronda, $resultado] = $this->confirmar($item, [['numero_folio' => 'DISTRIBUIDO', 'cantidad_contada' => 100]]);
        $this->assertSame('coincide', $resultado->resultado);
        $this->assertSame('completada', $ronda->estado);
        $this->assertCount(1, $ronda->items);
        $this->assertDatabaseCount('incidencias_verificacion_ubicacion', 0);
        $this->assertDatabaseHas('saldos_materiales_almacenes', ['folio_id' => $folio, 'posicion_id' => $posicion->id, 'cantidad_actual' => 100, 'version' => 1]);
    }

    public function test_retiro_posterior_invalida_la_posicion_y_la_reemplaza_sin_incidencia(): void
    {
        $posicion = $this->posicion();
        $folio = $this->folioEn($posicion, 'MAT-A');
        $otra = $this->posicion();
        $this->folioEn($otra, 'MAT-B');
        $item = $this->itemEn($posicion);
        DB::table('retiros_materiales')->insert(['id' => (string) Str::uuid(), 'folio_id' => $folio, 'posicion_id' => $posicion->id, 'created_at' => now(), 'updated_at' => now()]);
        [$ronda, $item] = $this->confirmar($item, [['numero_folio' => 'MAT-A', 'cantidad_contada' => 9]]);
        $this->assertSame('no_aplica', $item->resultado);
        $this->assertSame($otra->id, $ronda->items->whereNull('resultado')->first()->posicion_id);
        $this->assertDatabaseCount('incidencias_verificacion_ubicacion', 0);
        $this->assertDatabaseCount('verificaciones_ubicacion_folios', 0);
    }

    public function test_vacia_con_folios_crea_faltantes_y_vacia_real_coincide(): void
    {
        $posicion = $this->posicion();
        $this->folioEn($posicion, 'MAT-A');
        [, $item] = $this->confirmar($this->itemEn($posicion), [], true);
        $this->assertSame('folio_faltante', $item->resultado);
        $vacia = $this->posicion();
        DB::table('verificaciones_ubicacion')->update(['turno_inicio_at' => now()->subDay()]);
        [, $item] = $this->confirmar($this->itemEn($vacia), [], true);
        $this->assertSame('coincide', $item->resultado);
    }

    public function test_cantidad_obligatoria_duplicados_y_version_se_validan_sin_escribir(): void
    {
        $posicion = $this->posicion();
        $this->folioEn($posicion, 'MAT-A');
        $item = $this->itemEn($posicion);
        $this->apiCiega();
        $base = ['operacion_id' => (string) Str::uuid(), 'version' => 1, 'respuesta' => 'folios'];
        $ruta = "/api/verificaciones-ubicacion/items/{$item->id}/resultado";
        $this->postJson($ruta, $base + ['folios' => [['numero_folio' => 'MAT-A']]])->assertUnprocessable();
        $lectura = ['numero_folio' => 'MAT-A', 'cantidad_contada' => 10];
        $this->postJson($ruta, $base + ['folios' => [$lectura, $lectura]])->assertUnprocessable();
        $this->postJson($ruta, array_replace($base, ['version' => 2, 'folios' => [$lectura]]))->assertConflict();
        $this->assertDatabaseCount('verificaciones_ubicacion_folios', 0);
        config(['verificaciones.materiales.verificar_cantidad' => false]);
        $item->ronda->update(['verificar_cantidad' => false]);
        [, $registrado] = $this->confirmar($item, [['numero_folio' => 'MAT-A']]);
        $this->assertSame('coincide', $registrado->resultado);
    }

    public function test_indicadores_separan_presencia_cantidad_y_cumplimiento_por_camara_y_periodo(): void
    {
        $posicion = $this->posicion();
        $this->folioEn($posicion, 'MAT-A', 100);
        $this->folioEn($posicion, 'MAT-B', 100);
        $this->confirmar($this->itemEn($posicion), [['numero_folio' => 'MAT-A', 'cantidad_contada' => 101], ['numero_folio' => 'MAT-B', 'cantidad_contada' => 105]]);
        $data = app(ServicioIndicadoresVerificacionMateriales::class)->resumen($this->temporadaId);
        foreach ([7, 30] as $dias) {
            $camera = $data['periodos'][$dias]['camaras'][0];
            $this->assertSame(100.0, $camera['ubicacion']['porcentaje']);
            $this->assertSame(50.0, $camera['cantidad']['porcentaje']);
            $this->assertSame(100.0, $camera['cumplimiento']['porcentaje']);
        }
        DB::table('verificaciones_ubicacion_items')->update(['verificada_at' => now()->subDays(10)]);
        DB::table('verificaciones_ubicacion')->update(['turno_inicio_at' => now()->subDays(10)]);
        $data = app(ServicioIndicadoresVerificacionMateriales::class)->resumen($this->temporadaId);
        $this->assertNull($data['periodos'][7]['camaras'][0]['cantidad']['porcentaje']);
        $this->assertSame(50.0, $data['periodos'][30]['camaras'][0]['cantidad']['porcentaje']);
    }
}
