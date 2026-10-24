<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Exceptions\ConflictoOperacion;
use App\Models\Carga;
use App\Models\CargaFolio;
use App\Models\Dispositivo;
use App\Models\Folio;
use App\Models\FuncionCamareroTurno;
use App\Models\LaborCamarero;
use App\Models\PlanOperacional;
use App\Models\ReservaCargaFolio;
use App\Models\TareaMovimiento;
use App\Models\Temporada;
use App\Models\TurnoPlanificador;
use App\Models\User;
use App\Services\Estiba\ServicioPlanesOperacionales;
use App\Services\Planificador\ServicioLaboresTurno;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LaboresTurnoTest extends TestCase
{
    use RefreshDatabase;

    private Temporada $temporada;

    private User $supervisor;

    private User $camarero;

    private Dispositivo $tablet;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-24 18:00:00', 'UTC'));
        config(['planificador.mode' => 'off', 'planificador.horizon' => 'rolling', 'verificaciones.habilitada' => false]);
        $this->temporada = Temporada::query()->where('activa', true)->firstOrFail();
        $this->supervisor = User::factory()->create(['rol' => RolUsuario::SupervisorFrio, 'activo' => true]);
        $this->camarero = User::factory()->create(['rol' => RolUsuario::CamareroFrio, 'activo' => true]);
        $this->tablet = Dispositivo::create(['codigo' => 'LABOR-01', 'nombre' => 'Tablet A']);
    }

    public function test_supervisor_guarda_turno_nocturno_con_cuatro_funciones_y_control_de_version(): void
    {
        Sanctum::actingAs($this->supervisor);
        $datos = $this->datos();
        foreach (['recepcion_packing', 'separacion_cargas', 'preparacion_sag'] as $funcion) {
            $user = User::factory()->create(['rol' => RolUsuario::CamareroFrio, 'activo' => true]);
            $datos['asignaciones'][] = [...$datos['asignaciones'][0], 'user_id' => $user->id, 'funcion_principal' => $funcion, 'funciones_apoyo' => []];
        }
        $respuesta = $this->postJson('/api/labores-turno', $datos)->assertOk()->assertJsonPath('data.version', 1);
        $id = $respuesta->json('data.id');
        $this->assertDatabaseHas('turnos_planificador', ['id' => $id, 'inicio_at' => '2026-10-25 01:00:00', 'fin_at' => '2026-10-25 09:00:00']);
        $this->assertDatabaseCount('funciones_camarero_turno', 4);
        $this->postJson('/api/labores-turno', [...$datos, 'id' => $id, 'version' => 1, 'nombre' => 'Noche corregida'])->assertOk()->assertJsonPath('data.version', 2);
        $this->postJson('/api/labores-turno', [...$datos, 'id' => $id, 'version' => 1])->assertStatus(409);
        $this->getJson('/api/labores-turno')->assertOk()->assertJsonPath('data.0.nombre', 'Noche corregida')->assertJsonCount(4, 'data.0.asignaciones');
        $this->assertDatabaseCount('eventos_labores_turno', 2);
    }

    public function test_rechaza_superposicion_usuarios_repetidos_apoyos_repetidos_y_horario_invalido(): void
    {
        Sanctum::actingAs($this->supervisor);
        $datos = $this->datos();
        $this->postJson('/api/labores-turno', $datos)->assertOk();
        $this->postJson('/api/labores-turno', $datos)->assertUnprocessable()->assertJsonValidationErrors('inicio');
        $datos['inicio'] = '2026-10-25T22:00';
        $datos['fin'] = '2026-10-26T06:00';
        $this->postJson('/api/labores-turno', [...$datos, 'asignaciones' => [$datos['asignaciones'][0], $datos['asignaciones'][0]]])->assertUnprocessable();
        $datos['asignaciones'][0]['funciones_apoyo'] = ['recepcion_tuneles'];
        $this->postJson('/api/labores-turno', $datos)->assertUnprocessable();
        $this->postJson('/api/labores-turno', [...$this->datos(), 'fin' => '2026-10-24T21:00'])->assertUnprocessable();
        $this->assertDatabaseCount('turnos_planificador', 1);
    }

    public function test_camarero_no_configura_turnos_y_no_se_puede_asignar_usuario_inhabilitado(): void
    {
        Sanctum::actingAs($this->camarero);
        $this->getJson('/api/labores-turno')->assertForbidden();
        $this->postJson('/api/labores-turno', $this->datos())->assertForbidden();
        Sanctum::actingAs($this->supervisor);
        $this->camarero->update(['activo' => false]);
        $this->postJson('/api/labores-turno', $this->datos())->assertUnprocessable();
        $this->assertDatabaseCount('turnos_planificador', 0);
    }

    public function test_otra_temporada_o_preferencia_ajena_no_se_puede_editar(): void
    {
        Sanctum::actingAs($this->supervisor);
        $otro = Temporada::create(['codigo' => 'LAB-OTRA', 'nombre' => 'Otra', 'fecha_inicio' => '2025-01-01', 'activa' => false]);
        $turno = $this->turno(['temporada_id' => $otro->id]);
        $this->postJson('/api/labores-turno', [...$this->datos(), 'id' => $turno->id, 'version' => 1])->assertNotFound();
        $datos = $this->datos();
        $datos['asignaciones'][0]['preferencia'] = ['tipo' => 'carga', 'id' => $turno->id];
        $this->postJson('/api/labores-turno', $datos)->assertUnprocessable();
    }

    public function test_labor_de_varios_pallets_tiene_un_solo_responsable_y_continua_hasta_el_ultimo(): void
    {
        $this->asignacion();
        $a = $this->tarea('recepcion_tunel');
        $b = $this->tarea('recepcion_tunel', $a->planOperacional);
        app(ServicioPlanesOperacionales::class)->asumir($a, $this->camarero, $this->tablet);
        $otro = User::factory()->create(['rol' => RolUsuario::CamareroFrio, 'activo' => true]);
        try {
            app(ServicioPlanesOperacionales::class)->asumir($b, $otro, $this->tablet);
            $this->fail('Otro camarero tomó el mismo túnel.');
        } catch (ConflictoOperacion $e) {
            $this->assertStringContainsString('otro camarero', $e->getMessage());
        }
        $this->assertCount(0, $this->servicio()->ordenar(collect([$b]), $otro->id));
        $a->refresh()->update(['estado' => 'completada']);
        $this->assertNotNull($this->servicio()->contexto($this->temporada, $this->camarero)['labor_actual']);
        app(ServicioPlanesOperacionales::class)->asumir($b, $this->camarero, $this->tablet);
        $this->assertDatabaseCount('labores_camarero', 1);
        $b->refresh()->update(['estado' => 'completada']);
        $this->assertNull($this->servicio()->contexto($this->temporada, $this->camarero)['labor_actual']);
        $this->assertDatabaseHas('labores_camarero', ['estado' => 'completada', 'bloqueo_labor' => null]);
    }

    public function test_cambio_de_funcion_no_interrumpe_reserva_ni_pallet_en_proceso(): void
    {
        $asignacion = $this->asignacion();
        $tarea = $this->tarea();
        app(ServicioPlanesOperacionales::class)->asumir($tarea, $this->camarero, $this->tablet);
        $asignacion->update(['funcion_principal' => 'separacion_cargas', 'disponible' => false, 'version' => 2]);
        $tarea->refresh()->update(['estado' => 'en_proceso']);
        $this->travel(20)->minutes();
        $contexto = $this->servicio()->contexto($this->temporada, $this->camarero);
        $this->assertFalse($contexto['disponible']);
        $this->assertNotNull($contexto['labor_actual']);
        $labor = LaborCamarero::sole();
        try {
            $this->servicio()->liberar($labor, $this->supervisor, $labor->version);
            $this->fail('Liberó un pallet en proceso.');
        } catch (ConflictoOperacion) {
            $this->assertSame('en_curso', $labor->fresh()->estado);
        }
        $tarea->refresh()->update(['estado' => 'completada']);
        $this->assertNull($this->servicio()->contexto($this->temporada, $this->camarero)['labor_actual']);
        $this->assertSame('liberada', $labor->fresh()->estado);
    }

    public function test_foco_en_otra_tablet_y_labor_distinta_no_se_pueden_tomar(): void
    {
        $this->asignacion();
        $a = $this->tarea();
        $b = $this->tarea('recepcion_tunel', $a->planOperacional);
        $this->tomar($a);
        $tablet = Dispositivo::create(['codigo' => 'LAB-02', 'nombre' => 'Otra tablet']);
        try {
            $this->tomar($b, $tablet);
            $this->fail('La misma labor pasó a otra tablet.');
        } catch (ConflictoOperacion) {
            $this->assertDatabaseCount('labores_camarero', 1);
        }
        try {
            $this->tomar($this->tarea('concentracion_carga'));
            $this->fail('Mezcló labores.');
        } catch (ConflictoOperacion) {
            $this->assertDatabaseCount('labores_camarero', 1);
        }
        $this->travel(16)->minutes();
        $this->servicio()->pulso($this->temporada);
        $this->assertDatabaseHas('labores_camarero', ['estado' => 'liberada', 'bloqueo_labor' => null]);
        $this->assertDatabaseCount('movimientos', 0);
    }

    public function test_carga_en_espera_libera_operador_y_se_actualiza_al_recibir_pallet_faltante(): void
    {
        $this->asignacion(['funcion_principal' => 'separacion_cargas']);
        $carga = Carga::create(['temporada_id' => $this->temporada->id, 'codigo' => 'LAB-CAR-A', 'estado' => 'en_preparacion', 'prioridad' => 'alta', 'creada_por_user_id' => $this->supervisor->id, 'actualizada_por_user_id' => $this->supervisor->id]);
        $tarea = $this->tarea('concentracion_carga');
        $tarea->planOperacional->update(['referencia_tipo' => 'carga_concentracion', 'referencia_id' => $carga->id]);
        $asignacion = CargaFolio::create(['carga_id' => $carga->id, 'folio_id' => $tarea->folio_id, 'estado' => 'pendiente', 'asignado_por_user_id' => $this->supervisor->id, 'asignado_at' => now()]);
        ReservaCargaFolio::create(['folio_id' => $tarea->folio_id, 'carga_folio_id' => $asignacion->id]);
        $this->tomar($tarea);
        $tarea->update(['estado' => 'completada']);
        $contexto = $this->servicio()->contexto($this->temporada, $this->camarero);
        $this->assertNull($contexto['labor_actual']);
        $this->assertSame('esperando_dependencias', $contexto['en_espera'][0]['estado']);
        $this->assertSame(0, $contexto['en_espera'][0]['espera']['porcentaje_fisico']);
        $segunda = $this->tarea('concentracion_carga');
        $segunda->planOperacional->update(['referencia_tipo' => 'carga_concentracion', 'referencia_id' => $segunda->plan_operacional_id]);
        $this->tomar($segunda);
        $asignacion->update(['estado' => 'en_anden']);
        $this->servicio()->actualizarEsperasCarga($carga->id);
        $this->assertSame([], $this->servicio()->contexto($this->temporada, $this->camarero)['en_espera']);
        $this->assertSame(1, LaborCamarero::where('estado', 'completada')->count());
        $this->assertSame(1, LaborCamarero::where('estado', 'en_curso')->count());
    }

    public function test_funciones_son_preferencias_y_el_orden_se_aplica_antes_de_paginar(): void
    {
        $this->asignacion(['funcion_principal' => 'preparacion_sag', 'funciones_apoyo' => ['separacion_cargas']]);
        for ($n = 0; $n < 11; $n++) {
            $this->tarea('recepcion_tunel');
        }
        $apoyo = $this->tarea('concentracion_carga');
        $principal = $this->tarea('preparacion_inspeccion');
        $token = $this->camarero->crearTokenParaDispositivo($this->tablet, 'labores')->plainTextToken;
        $this->withToken($token)->getJson('/api/tareas-movimiento?asignacion=disponibles&per_page=10')->assertOk()
            ->assertJsonPath('data.0.id', $principal->id)->assertJsonPath('data.1.id', $apoyo->id)
            ->assertJsonPath('labores.funciones.principal', 'preparacion_sag');
        // La principal no convierte las demás funciones en permisos exclusivos.
        $this->tomar($apoyo);
        $this->assertSame('separacion_cargas', LaborCamarero::sole()->funcion);
    }

    public function test_modo_guiado_entrega_contexto_de_turno_junto_al_arbitraje(): void
    {
        $this->asignacion();
        config(['planificador.mode' => 'guided']);
        $token = $this->camarero->crearTokenParaDispositivo($this->tablet, 'guiado')->plainTextToken;
        $this->withToken($token)->getJson('/api/tareas-movimiento?asignacion=disponibles')->assertOk()
            ->assertJsonPath('labores.funciones.principal', 'recepcion_tuneles')
            ->assertJsonStructure(['arbitraje' => ['vigencia'], 'labores' => ['turno', 'labor_actual']]);
    }

    public function test_liberar_labor_respeta_el_control_central_de_temporada(): void
    {
        $asignacion = $this->asignacion();
        $this->tomar($this->tarea());
        $labor = LaborCamarero::sole();
        $otraTemporada = Temporada::create(['codigo' => 'LAB-ARCHIVO', 'nombre' => 'Archivo', 'fecha_inicio' => '2025-01-01', 'activa' => false]);
        $turnoArchivo = $this->turno(['temporada_id' => $otraTemporada->id]);
        $laborArchivo = $labor->replicate();
        $laborArchivo->fill(['turno_id' => $turnoArchivo->id, 'bloqueo_labor' => null, 'bloqueo_camarero' => null])->save();
        $this->assertSame($this->temporada->id, $asignacion->temporadaOperacionalId());
        $this->assertSame($otraTemporada->id, $laborArchivo->temporadaOperacionalId());
        Sanctum::actingAs($this->supervisor);
        $this->postJson('/api/labores-turno/labores/'.$laborArchivo->id.'/liberar', ['version' => $laborArchivo->version])
            ->assertStatus(409)->assertJsonPath('codigo', 'temporada_no_activa')->assertJsonPath('temporada_registro', 'LAB-ARCHIVO');
        $this->assertSame('en_curso', $laborArchivo->refresh()->estado);
        $this->postJson('/api/labores-turno/labores/'.$labor->id.'/liberar', ['version' => $labor->version])
            ->assertOk()->assertJsonPath('data.estado', 'liberada');
        $this->assertSame('liberada', $labor->refresh()->estado);
    }

    public function test_un_fallo_al_reservar_no_deja_labor_huerfana(): void
    {
        $this->asignacion();
        $tarea = $this->tarea();
        $tarea->update(['estado' => 'cancelada']);
        try {
            app(ServicioPlanesOperacionales::class)->asumir($tarea, $this->camarero, $this->tablet);
            $this->fail();
        } catch (\DomainException) {
            $this->assertDatabaseCount('labores_camarero', 0);
            $this->assertDatabaseCount('eventos_labores_turno', 0);
        }
    }

    private function servicio(): ServicioLaboresTurno
    {
        return app(ServicioLaboresTurno::class);
    }

    private function tomar(TareaMovimiento $tarea, ?Dispositivo $tablet = null): void
    {
        DB::transaction(fn () => $this->servicio()->tomar($tarea->fresh('planOperacional'), $this->camarero, $tablet ?? $this->tablet));
    }

    private function datos(): array
    {
        return ['nombre' => 'Turno noche', 'inicio' => '2026-10-24T22:00', 'fin' => '2026-10-25T06:00', 'asignaciones' => [['user_id' => $this->camarero->id, 'funcion_principal' => 'recepcion_tuneles', 'funciones_apoyo' => ['separacion_cargas', 'preparacion_sag'], 'disponible' => true, 'preferencia' => null]]];
    }

    private function turno(array $extra = []): TurnoPlanificador
    {
        return TurnoPlanificador::create([...['temporada_id' => $this->temporada->id, 'nombre' => 'Turno actual', 'inicio_at' => now()->subHour(), 'fin_at' => now()->addHours(7), 'creado_por_user_id' => $this->supervisor->id, 'actualizado_por_user_id' => $this->supervisor->id], ...$extra]);
    }

    private function asignacion(array $extra = []): FuncionCamareroTurno
    {
        $t = $this->turno();

        return FuncionCamareroTurno::create([...['turno_id' => $t->id, 'user_id' => $this->camarero->id, 'funcion_principal' => 'recepcion_tuneles', 'funciones_apoyo' => [], 'disponible' => true], ...$extra]);
    }

    private function tarea(string $tipo = 'recepcion_tunel', ?PlanOperacional $plan = null): TareaMovimiento
    {
        $plan ??= PlanOperacional::create(['temporada_id' => $this->temporada->id, 'tipo' => $tipo, 'estado' => 'programado', 'prioridad' => 'normal', 'titulo' => 'Labor '.$tipo, 'creado_por_user_id' => $this->supervisor->id, 'programado_at' => now(), 'contexto' => ['planner_horizon' => 'rolling']]);
        $folio = Folio::create(['temporada_id' => $this->temporada->id, 'numero_folio' => 'LAB-'.fake()->unique()->numerify('########'), 'tipo_bulto' => 'pallet', 'fecha_ingreso' => now()]);

        return TareaMovimiento::create(['plan_operacional_id' => $plan->id, 'folio_id' => $folio->id, 'secuencia' => $plan->tareas()->count() + 1, 'tipo_movimiento' => 'ubicacion_inicial', 'estado' => 'pendiente', 'prioridad' => 'normal', 'contexto' => []])->load('planOperacional');
    }
}
