<?php

namespace Tests\Feature\Api;

use App\Exceptions\ConflictoOperacion;
use App\Http\Controllers\Api\VerificacionUbicacionController;
use App\Models\Dispositivo;
use App\Models\Posicion;
use App\Models\User;
use App\Services\Autenticacion\ContextoOperacional;
use App\Services\Gerencia\ServicioPanelGerencial;
use App\Services\Temporadas\ServicioTemporadaActiva;
use App\Services\Verificaciones\ServicioVerificacionesUbicacion;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/** Esquema mínimo aislado: no depende de las migraciones históricas de la aplicación. */
class VerificacionUbicacionApiTest extends TestCase
{
    private User $camarero;

    private Dispositivo $tablet;

    private string $temporadaId;

    protected function setUp(): void
    {
        parent::setUp();
        config(['verificaciones.habilitada' => true, 'verificaciones.posiciones_por_ronda' => 1]);
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

        $this->temporadaId = (string) Str::uuid();
        DB::table('temporadas')->insert(['id' => $this->temporadaId, 'activa' => true,
            'fecha_inicio' => '2026-01-01', 'created_at' => now(), 'updated_at' => now()]);
        $id = DB::table('users')->insertGetId(['name' => 'Camarero', 'rol' => 'camarero_frio', 'activo' => true,
            'created_at' => now(), 'updated_at' => now()]);
        $this->camarero = User::findOrFail($id);
        $dispositivoId = (string) Str::uuid();
        DB::table('dispositivos')->insert(['id' => $dispositivoId, 'activo' => true,
            'created_at' => now(), 'updated_at' => now()]);
        $this->tablet = Dispositivo::findOrFail($dispositivoId);
    }

    public function test_desactivada_no_genera_ronda_y_dos_lecturas_crean_solo_una(): void
    {
        $this->posicion();
        $servicio = app(ServicioVerificacionesUbicacion::class);
        config(['verificaciones.habilitada' => false]);
        $this->assertNull($servicio->actual($this->camarero, $this->tablet));
        $this->assertDatabaseCount('verificaciones_ubicacion', 0);

        config(['verificaciones.habilitada' => true]);
        $primera = $servicio->actual($this->camarero, $this->tablet);
        $this->assertSame($primera->id, $servicio->actual($this->camarero, $this->tablet)->id);
        $this->assertDatabaseCount('verificaciones_ubicacion', 1);
        $this->assertSame(1, $primera->items->count());
    }

    public function test_una_consulta_de_supervisor_no_genera_ronda(): void
    {
        $this->posicion();
        DB::table('users')->where('id', $this->camarero->id)->update(['rol' => 'supervisor_frio']);
        $this->assertNull(app(ServicioVerificacionesUbicacion::class)->actual(
            $this->camarero->fresh(), $this->tablet,
        ));
        $this->assertDatabaseCount('verificaciones_ubicacion', 0);
    }

    public function test_respuesta_api_nunca_expone_folio_esperado_ni_despues_de_registrar(): void
    {
        $posicion = $this->posicion();
        $this->folioEn($posicion, 'PT-PRIVADO');
        $servicio = app(ServicioVerificacionesUbicacion::class);
        $contexto = Mockery::mock(ContextoOperacional::class);
        $contexto->shouldReceive('obtener')->andReturn([$this->camarero, $this->tablet]);
        $controller = app(VerificacionUbicacionController::class);
        $respuesta = $controller->actual(Request::create('/api/verificaciones-ubicacion/actual'), $contexto, $servicio);
        $this->assertSame(200, $respuesta->status());
        $this->assertStringNotContainsString('PT-PRIVADO', $respuesta->getContent());
        $this->assertStringNotContainsString('folio_esperado', $respuesta->getContent());

        $item = $servicio->actual($this->camarero, $this->tablet)->items->first();
        $servicio->registrar($item, $this->camarero, $this->tablet, (string) Str::uuid(), 1, 'PT-PRIVADO');
        $despues = $controller->actual(Request::create('/api/verificaciones-ubicacion/actual'), $contexto, $servicio);
        $this->assertStringNotContainsString('PT-PRIVADO', $despues->getContent());
        $this->assertStringNotContainsString('folio_esperado', $despues->getContent());
    }

    public function test_el_indice_unico_protege_la_ronda_ante_dos_creaciones_del_mismo_turno(): void
    {
        $this->posicion();
        $ronda = app(ServicioVerificacionesUbicacion::class)->actual($this->camarero, $this->tablet);
        $this->expectException(QueryException::class);
        DB::table('verificaciones_ubicacion')->insert([
            'id' => (string) Str::uuid(), 'temporada_id' => $this->temporadaId,
            'user_id' => $this->camarero->id, 'dispositivo_id' => $this->tablet->id,
            'turno_inicio_at' => $ronda->turno_inicio_at,
            'turno_fin_at' => $ronda->turno_fin_at, 'vence_at' => $ronda->vence_at,
            'estado' => 'pendiente', 'objetivo' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_registra_coincidencia_y_termina_ronda_sin_bloquear_tareas(): void
    {
        $posicion = $this->posicion();
        $this->folioEn($posicion, 'PT-001');
        $servicio = app(ServicioVerificacionesUbicacion::class);
        $item = $servicio->actual($this->camarero, $this->tablet)->items->first();
        [$ronda, $registrado] = $servicio->registrar($item, $this->camarero, $this->tablet,
            (string) Str::uuid(), 1, 'PT-001');
        $this->assertSame('coincide', $registrado->resultado);
        $this->assertSame('completada', $ronda->estado);
        $this->assertDatabaseCount('incidencias_verificacion_ubicacion', 0);
        $metricas = (new \ReflectionMethod(ServicioPanelGerencial::class, 'verificaciones'))
            ->invoke(app(ServicioPanelGerencial::class), $this->temporadaId);
        $this->assertSame(100.0, $metricas['periodos'][7]['camaras'][0]['exactitud_porcentaje']);
        $this->assertSame(100.0, $metricas['periodos'][30]['cumplimiento']['porcentaje']);
    }

    public function test_operacion_repetida_es_idempotente_y_no_duplica_incidencia(): void
    {
        $posicion = $this->posicion();
        $this->folioEn($posicion, 'PT-001');
        $servicio = app(ServicioVerificacionesUbicacion::class);
        $item = $servicio->actual($this->camarero, $this->tablet)->items->first();
        $operacion = (string) Str::uuid();
        $servicio->registrar($item, $this->camarero, $this->tablet, $operacion, 1, 'PT-OTRO');
        [, $repetido] = $servicio->registrar($item, $this->camarero, $this->tablet,
            $operacion, 1, 'PT-OTRO');
        $this->assertSame('otro_folio', $repetido->resultado);
        $this->assertDatabaseCount('incidencias_verificacion_ubicacion', 1);
        $this->expectException(ConflictoOperacion::class);
        $servicio->registrar($item, $this->camarero, $this->tablet, $operacion, 1, null);
    }

    public function test_diferencia_y_posicion_reportada_vacia_generan_incidencias(): void
    {
        $posicion = $this->posicion();
        $this->folioEn($posicion, 'PT-001');
        $item = app(ServicioVerificacionesUbicacion::class)->actual($this->camarero, $this->tablet)->items->first();
        [, $registrado] = app(ServicioVerificacionesUbicacion::class)->registrar(
            $item, $this->camarero, $this->tablet, (string) Str::uuid(), 1, null);
        $this->assertSame('posicion_vacia', $registrado->resultado);
        $this->assertDatabaseHas('incidencias_verificacion_ubicacion', ['tipo' => 'posicion_vacia',
            'folio_esperado_id' => $registrado->folio_esperado_id]);
        $metricas = (new \ReflectionMethod(ServicioPanelGerencial::class, 'verificaciones'))
            ->invoke(app(ServicioPanelGerencial::class), $this->temporadaId);
        $this->assertSame(0.0, $metricas['periodos'][7]['camaras'][0]['exactitud_porcentaje']);
    }

    public function test_otro_folio_de_la_temporada_y_referencia_a_otra_posicion(): void
    {
        $primera = $this->posicion();
        $segunda = $this->posicion(2);
        $this->folioEn($primera, 'PT-001');
        $this->folioEn($segunda, 'PT-002');
        $servicio = app(ServicioVerificacionesUbicacion::class);
        $ronda = $servicio->actual($this->camarero, $this->tablet);
        $asignada = $ronda->items->first()->posicion_id;
        $otra = $asignada === $primera->id ? $segunda : $primera;
        $numero = $asignada === $primera->id ? 'PT-002' : 'PT-001';
        [, $item] = $servicio->registrar($ronda->items->first(), $this->camarero,
            $this->tablet, (string) Str::uuid(), 1, $numero);
        $this->assertSame('otro_folio', $item->resultado);
        $this->assertDatabaseHas('incidencias_verificacion_ubicacion', [
            'tipo' => 'otro_folio', 'otra_posicion_id' => $otra->id,
            'folio_encontrado_numero' => $numero,
        ]);
    }

    public function test_excluye_pallet_tomado_reserva_y_posicion_verificada_recientemente(): void
    {
        $tomada = $this->posicion();
        $reservada = $this->posicion(2);
        $libre = $this->posicion(3);
        $folioId = $this->folioEn($tomada, 'PT-OCUPADO');
        DB::table('tareas_movimiento')->insert(['id' => (string) Str::uuid(),
            'folio_id' => $folioId, 'estado' => 'asumida']);
        DB::table('reservas_tareas_movimiento')->insert(['id' => (string) Str::uuid(),
            'bloqueo_posicion_id' => $reservada->id]);
        $ronda = app(ServicioVerificacionesUbicacion::class)->actual($this->camarero, $this->tablet);
        $this->assertSame($libre->id, $ronda->items->first()->posicion_id);
        app(ServicioVerificacionesUbicacion::class)->registrar($ronda->items->first(),
            $this->camarero, $this->tablet, (string) Str::uuid(), 1, null);
        $otroId = DB::table('users')->insertGetId(['name' => 'Otro', 'rol' => 'camarero_frio', 'activo' => true,
            'created_at' => now(), 'updated_at' => now()]);
        $otra = app(ServicioVerificacionesUbicacion::class)->actual(User::findOrFail($otroId), $this->tablet);
        $this->assertCount(0, $otra->items);
    }

    public function test_un_movimiento_posterior_invalida_el_item_y_asigna_reemplazo(): void
    {
        $primera = $this->posicion();
        $segunda = $this->posicion(2);
        $this->folioEn($primera, 'PT-001');
        config(['verificaciones.posiciones_por_ronda' => 2]);
        $servicio = app(ServicioVerificacionesUbicacion::class);
        $ronda = $servicio->actual($this->camarero, $this->tablet);
        $item = $ronda->items->firstWhere('posicion_id', $primera->id);
        $tercera = $this->posicion(3);
        DB::table('movimientos')->insert(['id' => (string) Str::uuid(), 'posicion_origen_id' => $primera->id,
            'created_at' => now()->addSecond(), 'updated_at' => now()->addSecond()]);
        [$actual, $registrado] = $servicio->registrar($item, $this->camarero, $this->tablet,
            (string) Str::uuid(), 1, null);
        $this->assertSame('no_aplica', $registrado->resultado);
        $this->assertCount(3, $actual->items);
        $this->assertNotNull($actual->items->firstWhere('posicion_id', $tercera->id));
        $this->assertDatabaseCount('incidencias_verificacion_ubicacion', 0);
    }

    public function test_ronda_vencida_cuenta_en_cumplimiento_sin_impedir_siguiente_turno(): void
    {
        $this->posicion();
        $servicio = app(ServicioVerificacionesUbicacion::class);
        $ronda = $servicio->actual($this->camarero, $this->tablet);
        DB::table('verificaciones_ubicacion')->where('id', $ronda->id)
            ->update(['vence_at' => now()->subMinute()]);
        $servicio->vencer(app(ServicioTemporadaActiva::class)->obtener());
        $this->assertDatabaseHas('verificaciones_ubicacion', ['id' => $ronda->id, 'estado' => 'vencida']);
        $metricas = (new \ReflectionMethod(ServicioPanelGerencial::class, 'verificaciones'))
            ->invoke(app(ServicioPanelGerencial::class), $this->temporadaId);
        $this->assertSame(['completadas' => 0, 'generadas' => 1, 'porcentaje' => 0.0],
            $metricas['periodos'][7]['cumplimiento']);
    }

    private function posicion(int $numero = 1): Posicion
    {
        $camaraId = (string) Str::uuid();
        DB::table('camaras')->insert(['id' => $camaraId, 'codigo' => "CAM-{$numero}",
            'estado' => 'activa', 'contenido' => 'productos', 'cantidad_bandas' => 1,
            'posiciones_por_banda' => 5, 'cantidad_niveles' => 1,
            'created_at' => now(), 'updated_at' => now()]);
        $id = (string) Str::uuid();
        DB::table('posiciones')->insert(['id' => $id, 'camara_id' => $camaraId,
            'banda' => 1, 'posicion' => $numero, 'nivel' => 1, 'estado' => 'activa',
            'created_at' => now(), 'updated_at' => now()]);

        return Posicion::findOrFail($id);
    }

    private function folioEn(Posicion $posicion, string $numero): string
    {
        $id = (string) Str::uuid();
        DB::table('folios')->insert(['id' => $id, 'temporada_id' => $this->temporadaId,
            'numero_folio' => $numero, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('ubicaciones_actuales')->insert(['id' => (string) Str::uuid(),
            'posicion_id' => $posicion->id, 'folio_id' => $id,
            'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }
}
