<?php

namespace Tests\Feature\Api;

use App\Enums\CondicionTermicaFolio;
use App\Enums\EstadoFolioProcesoPrefrio;
use App\Enums\EstadoOperacionalFolio;
use App\Enums\HabilitacionAlmacenamientoFolio;
use App\Enums\RolUsuario;
use App\Enums\TipoBulto;
use App\Models\Folio;
use App\Models\PlanOperacional;
use App\Models\PosicionTunelPrefrio;
use App\Models\ProcesoPrefrio;
use App\Models\ProcesoPrefrioFolio;
use App\Models\Temporada;
use App\Models\TunelPrefrio;
use App\Models\User;
use App\Services\Estiba\ServicioConfirmacionInicioTarea;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConciliacionPalletsHistoricosPlanificadorTest extends TestCase
{
    use RefreshDatabase;

    public function test_diagnostico_es_solo_lectura_y_ejecucion_crea_tarea_sin_inventar_origen(): void
    {
        $this->habilitarPlanificador();
        [$usuario, $folio] = $this->crearPalletHistorico();

        $this->assertSame(0, Artisan::call('planificador:conciliar-pallets'));
        $this->assertStringContainsString($folio->numero_folio, Artisan::output());
        $this->assertDatabaseCount('planes_operacionales', 0);

        $this->assertSame(0, Artisan::call('planificador:conciliar-pallets', [
            '--aplicar' => true,
            '--usuario' => $usuario->email,
        ]));

        $plan = PlanOperacional::query()->where('referencia_tipo', 'folio_pendiente_ubicacion')->firstOrFail();
        $tarea = $plan->tareas()->firstOrFail();
        $this->assertSame($folio->id, $plan->referencia_id);
        $this->assertSame($usuario->id, $plan->creado_por_user_id);
        $this->assertSame('rolling', $plan->contexto['planner_horizon']);
        $this->assertSame('ubicacion_historica_por_verificar', $plan->contexto['origen_logico']);
        $this->assertSame('almacenamiento_pallet', $plan->tipo->value);
        $this->assertSame('ubicacion_inicial', $tarea->tipo_movimiento->value);
        $this->assertNull($tarea->camara_origen_id);
        $this->assertNull($tarea->camara_destino_id);
        $this->assertStringContainsString('Localizar físicamente', $tarea->instruccion);
        $this->assertStringNotContainsString('Retirar', $tarea->instruccion);

        $this->assertSame(0, Artisan::call('planificador:conciliar-pallets', [
            '--aplicar' => true,
            '--usuario' => (string) $usuario->id,
        ]));
        $this->assertStringContainsString('0 para revisión manual', Artisan::output());
        $this->assertDatabaseCount('planes_operacionales', 1);
        $this->assertDatabaseCount('tareas_movimiento', 1);
    }

    public function test_excluye_pallets_no_habilitados_y_procesos_sin_aprobacion(): void
    {
        $this->habilitarPlanificador();
        [$usuario, $elegible, $proceso] = $this->crearPalletHistorico();

        $sinPrefrio = Folio::create([
            'temporada_id' => $elegible->temporada_id,
            'numero_folio' => 'PAL-SIN-PREFRIO',
            'tipo_bulto' => TipoBulto::Pallet,
            'estado_operacional' => EstadoOperacionalFolio::PendienteUbicacion,
            'condicion_termica' => CondicionTermicaFolio::PrefrioAprobado,
            'habilitacion_almacenamiento' => HabilitacionAlmacenamientoFolio::Habilitado,
            'activo' => true,
            'fecha_ingreso' => now(),
        ]);
        $bloqueado = Folio::create([
            'temporada_id' => $elegible->temporada_id,
            'numero_folio' => 'PAL-PENDIENTE',
            'tipo_bulto' => TipoBulto::Pallet,
            'estado_operacional' => EstadoOperacionalFolio::PendienteUbicacion,
            'condicion_termica' => CondicionTermicaFolio::PendientePrefrio,
            'habilitacion_almacenamiento' => HabilitacionAlmacenamientoFolio::NoHabilitado,
            'activo' => true,
            'fecha_ingreso' => now(),
        ]);
        $otraPosicion = PosicionTunelPrefrio::create([
            'tunel_prefrio_id' => $proceso->tunel_prefrio_id,
            'numero' => 2,
            'etiqueta' => 'TUN-HIST-P02',
            'activa' => true,
        ]);
        ProcesoPrefrioFolio::create([
            'proceso_prefrio_id' => $proceso->id,
            'folio_id' => $bloqueado->id,
            'posicion_tunel_prefrio_id' => $otraPosicion->id,
            'estado' => EstadoFolioProcesoPrefrio::Aprobado,
            'cargado_at' => now()->subDay(),
            'cargado_por_user_id' => $usuario->id,
        ]);

        $this->assertSame(0, Artisan::call('planificador:conciliar-pallets', [
            '--aplicar' => true,
            '--usuario' => (string) $usuario->id,
        ]));
        $this->assertDatabaseCount('planes_operacionales', 1);
        $this->assertDatabaseMissing('tareas_movimiento', ['folio_id' => $sinPrefrio->id]);
        $this->assertDatabaseMissing('tareas_movimiento', ['folio_id' => $bloqueado->id]);
    }

    public function test_se_niega_a_escribir_si_no_hay_modo_dirigido_o_supervisor_activo(): void
    {
        [$usuario] = $this->crearPalletHistorico();
        config(['planificador.mode' => 'off']);
        $this->assertSame(1, Artisan::call('planificador:conciliar-pallets', [
            '--aplicar' => true,
            '--usuario' => (string) $usuario->id,
        ]));

        $this->habilitarPlanificador();
        $this->assertSame(1, Artisan::call('planificador:conciliar-pallets', [
            '--aplicar' => true,
        ]));
        $this->assertDatabaseCount('planes_operacionales', 0);
    }

    public function test_el_pallet_historico_siempre_exige_confirmacion_fisica_aunque_exista_rollback_global(): void
    {
        $this->habilitarPlanificador();
        [$usuario] = $this->crearPalletHistorico();
        config(['planificador.confirmacion_inicio_tarea' => false]);

        $this->assertSame(0, Artisan::call('planificador:conciliar-pallets', [
            '--aplicar' => true,
            '--usuario' => (string) $usuario->id,
        ]));
        $tarea = PlanOperacional::query()->firstOrFail()->tareas()->firstOrFail();
        $this->assertTrue(app(ServicioConfirmacionInicioTarea::class)->exigida($tarea));
    }

    public function test_incorpora_pallet_historico_disponible_sin_ubicacion(): void
    {
        $this->habilitarPlanificador();
        [$usuario, $folio] = $this->crearPalletHistorico();
        $folio->update(['estado_operacional' => EstadoOperacionalFolio::Disponible]);

        $this->assertSame(0, Artisan::call('planificador:conciliar-pallets', [
            '--aplicar' => true,
            '--usuario' => (string) $usuario->id,
        ]));
        $this->assertDatabaseHas('tareas_movimiento', ['folio_id' => $folio->id]);
    }

    /** @return array{User, Folio, ProcesoPrefrio} */
    private function crearPalletHistorico(): array
    {
        Temporada::query()->update(['activa' => false]);
        $temporada = Temporada::create([
            'codigo' => 'TEMP-HIST',
            'nombre' => 'Temporada histórica',
            'fecha_inicio' => '2026-09-01',
            'activa' => true,
        ]);
        $usuario = User::factory()->create(['rol' => RolUsuario::SupervisorFrio, 'activo' => true]);
        $tunel = TunelPrefrio::create([
            'codigo' => 'TUN-HIST',
            'nombre' => 'Túnel histórico',
            'capacidad_posiciones' => 2,
            'setpoint_habitual' => -1.5,
            'estado_administrativo' => 'activo',
            'estado_tecnico' => 'operativo',
            'version_configuracion' => 1,
            'creado_por_user_id' => $usuario->id,
        ]);
        $proceso = ProcesoPrefrio::create([
            'temporada_id' => $temporada->id,
            'codigo' => 'PFR-HIST',
            'operacion_id' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', 'proceso-historico'),
            'tunel_prefrio_id' => $tunel->id,
            'estado' => 'aprobado',
            'setpoint' => -1.5,
            'version' => 5,
            'creado_por_user_id' => $usuario->id,
            'finalizado_por_user_id' => $usuario->id,
            'finalizado_at' => now()->subDay(),
        ]);
        $posicion = PosicionTunelPrefrio::create([
            'tunel_prefrio_id' => $tunel->id,
            'numero' => 1,
            'etiqueta' => 'TUN-HIST-P01',
            'activa' => true,
        ]);
        $folio = Folio::create([
            'temporada_id' => $temporada->id,
            'numero_folio' => 'PAL-HIST-001',
            'tipo_bulto' => TipoBulto::Pallet,
            'estado_operacional' => EstadoOperacionalFolio::PendienteUbicacion,
            'condicion_termica' => CondicionTermicaFolio::PrefrioAprobado,
            'habilitacion_almacenamiento' => HabilitacionAlmacenamientoFolio::Habilitado,
            'activo' => true,
            'fecha_ingreso' => now()->subDay(),
            'exportadora' => 'Exportadora Norte',
            'marca' => 'Cordillera',
            'datos_externos' => ['envase' => 'Caja 5 kg'],
        ]);
        ProcesoPrefrioFolio::create([
            'proceso_prefrio_id' => $proceso->id,
            'folio_id' => $folio->id,
            'posicion_tunel_prefrio_id' => $posicion->id,
            'estado' => EstadoFolioProcesoPrefrio::Aprobado,
            'cargado_at' => now()->subDay(),
            'cargado_por_user_id' => $usuario->id,
            'retirado_at' => now()->subDay(),
        ]);

        return [$usuario, $folio, $proceso];
    }

    private function habilitarPlanificador(): void
    {
        config([
            'planificador.generacion_automatica' => true,
            'planificador.mode' => 'guided',
            'planificador.compute' => 'tablet',
            'planificador.horizon' => 'rolling',
            'planificador.confirmacion_inicio_tarea' => true,
        ]);
    }
}
