<?php

namespace Tests\Feature\Api;

use App\Enums\CondicionTermicaFolio;
use App\Enums\EstadoFolioProcesoPrefrio;
use App\Enums\EstadoManiobraOperacional;
use App\Enums\EstadoOperacionalFolio;
use App\Enums\EstadoPlanOperacional;
use App\Enums\EstadoTareaMovimiento;
use App\Enums\HabilitacionAlmacenamientoFolio;
use App\Enums\RolUsuario;
use App\Enums\PrioridadOperacional;
use App\Enums\EstadoPlanOperacional;
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
use App\Services\Planificador\ServicioConciliacionPalletsHistoricos;
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
        $this->assertSame(PrioridadOperacional::Normal, $plan->prioridad);
        $this->assertSame(PrioridadOperacional::Normal, $tarea->prioridad);
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

    public function test_migracion_normaliza_planes_vivos_y_sus_tareas_sin_alterar_finalizados(): void
    {
        $this->habilitarPlanificador();
        [$usuario] = $this->crearPalletHistorico();
        Artisan::call('planificador:conciliar-pallets', [
            '--aplicar' => true, '--usuario' => (string) $usuario->id,
        ]);
        $plan = PlanOperacional::query()->where('referencia_tipo', 'folio_pendiente_ubicacion')->sole();
        $tarea = $plan->tareas()->sole();
        $plan->update(['prioridad' => PrioridadOperacional::Alta]);
        $tarea->update(['prioridad' => PrioridadOperacional::Alta]);
        $finalizado = $plan->replicate();
        $finalizado->referencia_id = (string) Str::uuid();
        $finalizado->estado = EstadoPlanOperacional::Completado;
        $finalizado->save();

        $migracion = require database_path('migrations/2026_09_29_100000_normalizar_prioridad_conciliacion_historica.php');
        $migracion->up();
        $primeraVersion = $plan->refresh()->version;
        $migracion->up();

        $this->assertSame(PrioridadOperacional::Normal, $plan->refresh()->prioridad);
        $this->assertSame(PrioridadOperacional::Normal, $tarea->refresh()->prioridad);
        $this->assertSame($primeraVersion, $plan->version);
        $this->assertSame(PrioridadOperacional::Alta, $finalizado->refresh()->prioridad);
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
        Folio::create([
            'temporada_id' => $elegible->temporada_id,
            'numero_folio' => 'SAL-SIN-UBICACION',
            'tipo_bulto' => TipoBulto::Saldo,
            'estado_operacional' => EstadoOperacionalFolio::PendienteUbicacion,
            'activo' => true,
            'fecha_ingreso' => now(),
        ]);

        $diagnostico = app(ServicioConciliacionPalletsHistoricos::class)
            ->diagnosticar($elegible->temporada);
        $this->assertSame(2, $diagnostico['requieren_revision']);
        $this->assertSame(1, $diagnostico['saldos_sin_objetivo']);
        $this->assertSame(['SAL-SIN-UBICACION'], $diagnostico['saldos']);
        $motivos = collect($diagnostico['folios_revision'])->keyBy('folio');
        $this->assertContains('sin_prefrio_aprobado_temporada', $motivos['PAL-SIN-PREFRIO']['motivos']);
        $this->assertContains('no_habilitado', $motivos['PAL-PENDIENTE']['motivos']);

        $this->assertSame(0, Artisan::call('planificador:conciliar-pallets', [
            '--aplicar' => true,
            '--usuario' => (string) $usuario->id,
        ]));
        $this->assertDatabaseCount('planes_operacionales', 1);
        $this->assertDatabaseMissing('tareas_movimiento', ['folio_id' => $sinPrefrio->id]);
        $this->assertDatabaseMissing('tareas_movimiento', ['folio_id' => $bloqueado->id]);
    }

    public function test_una_tarea_cancelada_no_impide_conciliar_otra_vez_el_pallet(): void
    {
        $this->habilitarPlanificador();
        [$usuario, $folio] = $this->crearPalletHistorico();
        $argumentos = ['--aplicar' => true, '--usuario' => (string) $usuario->id];
        $this->assertSame(0, Artisan::call('planificador:conciliar-pallets', $argumentos));
        $anterior = PlanOperacional::query()->firstOrFail();
        $anterior->tareas()->update(['estado' => EstadoTareaMovimiento::Cancelada->value]);
        $anterior->maniobras()->update(['estado' => EstadoManiobraOperacional::Cancelada->value]);
        $anterior->update(['estado' => EstadoPlanOperacional::Cancelado]);

        $this->assertSame(1, app(ServicioConciliacionPalletsHistoricos::class)
            ->diagnosticar($folio->temporada)['total']);
        $this->assertSame(0, Artisan::call('planificador:conciliar-pallets', $argumentos));
        $this->assertSame(2, PlanOperacional::query()->where('referencia_id', $folio->id)->count());
        $this->assertSame([1, 2], PlanOperacional::query()->where('referencia_id', $folio->id)
            ->orderBy('ciclo_referencia')->pluck('ciclo_referencia')->all());
        $this->assertSame(1, $folio->tareasMovimiento()->where('estado', EstadoTareaMovimiento::Pendiente->value)->count());
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
