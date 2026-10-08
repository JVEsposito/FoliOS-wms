<?php

namespace Tests\Feature\Api;

use App\Http\Middleware\ExigirCambioPasswordTablet;
use App\Models\Dispositivo;
use App\Models\Posicion;
use App\Models\User;
use App\Models\VerificacionUbicacion;
use App\Models\VerificacionUbicacionItem;
use App\Services\Autenticacion\ContextoOperacional;
use App\Services\Verificaciones\ServicioIndicadoresVerificacionMateriales;
use App\Services\Verificaciones\ServicioVerificacionesUbicacion;
use App\Services\Verificaciones\ServicioVerificacionMateriales;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/** Esquema mínimo aislado: no depende de las migraciones históricas de la aplicación. */
class VerificacionMaterialesApiTest extends TestCase
{
    private User $camarero;

    private Dispositivo $tablet;

    private string $temporadaId;

    protected function setUp(): void
    {
        parent::setUp();
        // CI migra previamente la base MySQL completa. Esta suite utiliza un
        // esquema mínimo en una conexión privada para no recrear ni alterar
        // sus tablas y para probar exactamente las consultas del servicio.
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        config(['verificaciones.habilitada' => true, 'verificaciones.posiciones_por_ronda' => 1, 'verificaciones.productos.posiciones_por_ronda' => 1]);
        Schema::create('temporadas', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->boolean('activa');
            $t->date('fecha_inicio');
            $t->timestamps();
        });
        Schema::create('users', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->string('rol');
            $t->boolean('activo');
            $t->timestamps();
        });
        Schema::create('dispositivos', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->boolean('activo');
            $t->timestamps();
        });
        Schema::create('camaras', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('codigo');
            $t->string('estado');
            $t->string('contenido');
            $t->integer('cantidad_bandas');
            $t->integer('posiciones_por_banda');
            $t->integer('cantidad_niveles');
            $t->timestamps();
        });
        Schema::create('posiciones', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('camara_id');
            $t->integer('banda');
            $t->integer('posicion');
            $t->integer('nivel');
            $t->string('estado');
            $t->timestamps();
        });
        Schema::create('folios', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('temporada_id');
            $t->string('numero_folio');
            $t->timestamps();
        });
        Schema::create('ubicaciones_actuales', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('posicion_id');
            $t->uuid('folio_id');
            $t->timestamps();
        });
        Schema::create('tareas_movimiento', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('folio_id');
            $t->string('estado');
        });
        Schema::create('reservas_tareas_movimiento', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('bloqueo_posicion_id')->nullable();
            $t->uuid('bloqueo_tarea_id')->nullable();
        });
        Schema::create('reservas_posiciones_inspeccion_sag', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('clave_bloqueo')->nullable();
        });
        Schema::create('custodias_temporales_maniobra', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('folio_id');
            $t->string('estado');
        });
        Schema::create('carga_folios', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('folio_id');
            $t->uuid('carga_id');
            $t->string('estado');
            $t->uuid('bloqueo_folio_id')->nullable();
        });
        Schema::create('reservas_carga_folio', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('folio_id');
        });
        Schema::create('presencias_carga_anden', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('carga_id');
            $t->string('estado');
        });
        Schema::create('movimientos', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('posicion_origen_id')->nullable();
            $t->uuid('posicion_destino_id')->nullable();
            $t->timestamps();
        });
        (require database_path('migrations/2026_09_29_150000_crear_verificaciones_ubicacion.php'))->up();

        Schema::table('verificaciones_ubicacion', function (Blueprint $t): void {
            $t->string('contenido')->default('productos');
            $t->boolean('verificar_cantidad')->default(false);
            $t->decimal('tolerancia_cantidad_pct', 6, 3)->default(2);
        });

        Schema::table('folios', fn (Blueprint $t) => $t->boolean('activo')->default(true));
        Schema::create('items_materiales', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('unidad_medida');
        });
        Schema::create('folios_materiales', function (Blueprint $t): void {
            $t->uuid('folio_id')->primary();
            $t->uuid('item_material_id');
            $t->decimal('cantidad_actual', 14, 3);
            $t->decimal('cantidad_reservada', 14, 3)->default(0);
            $t->string('motivo_bloqueo')->nullable();
            $t->timestamps();
        });
        Schema::create('saldos_materiales_almacenes', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('folio_id');
            $t->uuid('almacen_material_id');
            $t->uuid('camara_id')->nullable();
            $t->uuid('posicion_id')->nullable();
            $t->decimal('cantidad_actual', 14, 3);
            $t->decimal('cantidad_reservada', 14, 3)->default(0);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });
        foreach (['reservas_materiales', 'reservas_transformacion_materiales'] as $tabla) {
            Schema::create($tabla, function (Blueprint $t): void {
                $t->uuid('id')->primary();
                $t->uuid('folio_id');
                $t->string('estado');
            });
        }
        Schema::create('retiros_materiales', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('folio_id');
            $t->uuid('posicion_id');
            $t->timestamps();
        });
        Schema::create('movimientos_almacenes_materiales', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('folio_id');
            $t->timestamps();
        });
        // La migración real incluye los campos de ronda agregados arriba por el fixture de frío.
        Schema::table('verificaciones_ubicacion', fn (Blueprint $t) => $t->dropColumn(['contenido', 'verificar_cantidad', 'tolerancia_cantidad_pct']));
        (require database_path('migrations/2026_10_08_140000_extender_verificaciones_materiales.php'))->up();
        config(['verificaciones.materiales.habilitada' => true, 'verificaciones.materiales.posiciones_por_ronda' => 1]);
        $this->temporadaId = (string) Str::uuid();
        DB::table('temporadas')->insert(['id' => $this->temporadaId, 'activa' => true,
            'fecha_inicio' => '2026-01-01', 'created_at' => now(), 'updated_at' => now()]);
        $id = DB::table('users')->insertGetId(['name' => 'Camarero', 'rol' => 'camarero_materiales', 'activo' => true,
            'created_at' => now(), 'updated_at' => now()]);
        $this->camarero = User::findOrFail($id);
        $dispositivoId = (string) Str::uuid();
        DB::table('dispositivos')->insert(['id' => $dispositivoId, 'activo' => true,
            'created_at' => now(), 'updated_at' => now()]);
        $this->tablet = Dispositivo::findOrFail($dispositivoId);
    }

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

    private function posicion(string $contenido = 'materiales'): Posicion
    {
        $camara = (string) Str::uuid();
        DB::table('camaras')->insert(['id' => $camara, 'codigo' => $contenido.'-'.substr($camara, 0, 4), 'estado' => 'activa', 'contenido' => $contenido,
            'cantidad_bandas' => 1, 'posiciones_por_banda' => 10, 'cantidad_niveles' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $id = (string) Str::uuid();
        DB::table('posiciones')->insert(['id' => $id, 'camara_id' => $camara, 'banda' => 1, 'posicion' => 1, 'nivel' => 1, 'estado' => 'activa', 'created_at' => now(), 'updated_at' => now()]);

        return Posicion::findOrFail($id);
    }

    private function folioEn(Posicion $posicion, string $numero, float $cantidad = 10, bool $bloqueado = false): string
    {
        $id = (string) Str::uuid();
        $item = (string) Str::uuid();
        DB::table('folios')->insert(['id' => $id, 'temporada_id' => $this->temporadaId, 'numero_folio' => $numero, 'activo' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('items_materiales')->insert(['id' => $item, 'unidad_medida' => 'unidad']);
        DB::table('folios_materiales')->insert(['folio_id' => $id, 'item_material_id' => $item, 'cantidad_actual' => $cantidad, 'motivo_bloqueo' => $bloqueado ? 'Vencido' : null]);
        DB::table('ubicaciones_actuales')->insert(['id' => (string) Str::uuid(), 'folio_id' => $id, 'posicion_id' => $posicion->id]);
        DB::table('saldos_materiales_almacenes')->insert(['id' => (string) Str::uuid(), 'folio_id' => $id, 'almacen_material_id' => (string) Str::uuid(), 'camara_id' => $posicion->camara_id, 'posicion_id' => $posicion->id, 'cantidad_actual' => $cantidad, 'version' => 1]);

        return $id;
    }

    private function ronda(): VerificacionUbicacion
    {
        return VerificacionUbicacion::create(['temporada_id' => $this->temporadaId, 'user_id' => $this->camarero->id, 'dispositivo_id' => $this->tablet->id,
            'turno_inicio_at' => now()->startOfHour(), 'turno_fin_at' => now()->addHours(8), 'vence_at' => now()->addHours(8), 'contenido' => 'materiales', 'verificar_cantidad' => true, 'tolerancia_cantidad_pct' => 2, 'objetivo' => 1]);
    }

    private function itemEn(Posicion $posicion): VerificacionUbicacionItem
    {
        return $this->ronda()->items()->create(['posicion_id' => $posicion->id,
            'snapshot_materiales' => app(ServicioVerificacionMateriales::class)->snapshot($posicion, $this->temporadaId)]);
    }

    private function confirmar($item, array $folios, bool $vacia = false): array
    {
        return app(ServicioVerificacionesUbicacion::class)->registrar($item, $this->camarero, $this->tablet, (string) Str::uuid(), 1, null, $folios, $vacia);
    }

    private function apiCiega(): void
    {
        $contexto = Mockery::mock(ContextoOperacional::class);
        $contexto->shouldReceive('obtener')->andReturn([$this->camarero, $this->tablet]);
        $this->app->instance(ContextoOperacional::class, $contexto);
        $this->withoutMiddleware([Authenticate::class, Authorize::class, ExigirCambioPasswordTablet::class]);
    }

    private function assertCiega(string $contenido): void
    {
        foreach (['MAT-A', 'MAT-B', 'MAT-C', 'cantidad_esperada', 'folio_esperado', 'snapshot_materiales', 'cantidad_actual'] as $secreto) {
            $this->assertStringNotContainsString($secreto, $contenido);
        }
    }
}
