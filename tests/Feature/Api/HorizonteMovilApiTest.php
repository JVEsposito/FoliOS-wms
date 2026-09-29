<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Enums\TipoBulto;
use App\Enums\TipoMovimiento;
use App\Enums\TipoPlanOperacional;
use App\Models\Camara;
use App\Models\Dispositivo;
use App\Models\Folio;
use App\Models\Posicion;
use App\Models\Temporada;
use App\Models\User;
use App\Services\Autenticacion\ServicioPinOperacional;
use App\Services\Camaras\ServicioBandasOperacionales;
use App\Services\Estiba\ServicioMovimientoEstiba;
use App\Services\Estiba\ServicioPlanesOperacionales;
use App\Services\Estiba\ServicioReservasTareasMovimiento;
use App\Services\Estiba\ServicioSesionEstiba;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class HorizonteMovilApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'planificador.mode' => 'guided',
            'planificador.compute' => 'tablet',
            'planificador.horizon' => 'rolling',
            'planificador.generacion_automatica' => true,
            'planificador.frontier_max' => 4,
            'planificador.reserva_tarea_minutos' => 10,
        ]);
    }

    public function test_asumir_en_rolling_reclama_la_tarea_sin_reservar_posicion(): void
    {
        $contexto = $this->crearContexto();
        $plan = $this->crearPlanRolling($contexto, [$contexto['folios'][0]]);
        $tarea = $plan->tareas->firstOrFail();

        $this->conToken($contexto['token'])
            ->postJson("/api/tareas-movimiento/{$tarea->id}/asumir")
            ->assertOk()
            ->assertJsonPath('data.estado', 'asumida')
            ->assertJsonPath('data.destino', null)
            ->assertJsonPath('data.reserva.tipo_compromiso', 'claim')
            ->assertJsonPath('data.reserva.destino_reservado', false)
            ->assertJsonPath('data.punto_no_retorno', false);

        $this->assertDatabaseHas('reservas_tareas_movimiento', [
            'tarea_movimiento_id' => $tarea->id,
            'bloqueo_tarea_id' => $tarea->id,
            'bloqueo_posicion_id' => null,
            'estado' => 'activa',
        ]);
    }

    public function test_lease_vencido_limpia_destino_rolling_obsoleto(): void
    {
        $contexto = $this->crearContexto();
        $tarea = $this->crearPlanRolling($contexto, [$contexto['folios'][0]])->tareas()->sole();
        $this->conToken($contexto['token'])->postJson("/api/tareas-movimiento/{$tarea->id}/asumir")->assertOk();
        $tarea->update([
            'camara_destino_id' => $contexto['camara']->id,
            'posicion_destino_id' => $contexto['posiciones'][0]->id,
        ]);

        $this->travel(11)->minutes();
        app(ServicioReservasTareasMovimiento::class)->expirarVencidas();

        $this->assertNull($tarea->refresh()->camara_destino_id);
        $this->assertNull($tarea->posicion_destino_id);
        $this->assertSame('pendiente', $tarea->estado->value);
    }

    public function test_liberacion_rolling_limpia_destino_sin_retiro(): void
    {
        $contexto = $this->crearContexto();
        $tarea = $this->crearPlanRolling($contexto, [$contexto['folios'][0]])->tareas()->sole();
        $this->conToken($contexto['token'])->postJson("/api/tareas-movimiento/{$tarea->id}/asumir")->assertOk();
        $tarea->update([
            'camara_destino_id' => $contexto['camara']->id,
            'posicion_destino_id' => $contexto['posiciones'][0]->id,
        ]);

        $this->conToken($contexto['token'])->postJson("/api/tareas-movimiento/{$tarea->id}/liberar")->assertOk();

        $this->assertNull($tarea->refresh()->camara_destino_id);
        $this->assertNull($tarea->posicion_destino_id);
    }

    public function test_snapshot_respeta_el_horizonte_persistido_en_el_plan(): void
    {
        config(['planificador.horizon' => 'batch']);
        $contexto = $this->crearContexto();
        $plan = $this->crearPlanRolling($contexto, [$contexto['folios'][0]]);

        $this->conToken($contexto['token'])
            ->getJson("/api/planes-operacionales/{$plan->id}/snapshot")
            ->assertOk()
            ->assertJsonPath('data.planner.horizon', 'rolling');
    }

    public function test_servidor_materializa_una_frontera_parcial_y_rechaza_conflictos(): void
    {
        $contexto = $this->crearContexto();
        $plan = $this->crearPlanRolling($contexto, $contexto['folios']);
        $tareas = $plan->tareas->values();

        foreach ($tareas as $tarea) {
            $this->conToken($contexto['token'])
                ->postJson("/api/tareas-movimiento/{$tarea->id}/asumir")
                ->assertOk();
        }

        $snapshotResponse = $this->conToken($contexto['token'])
            ->getJson("/api/planes-operacionales/{$plan->id}/snapshot")
            ->assertOk()
            ->assertJsonPath('data.planner.compute', 'tablet')
            ->assertJsonPath('data.planner.horizon', 'rolling')
            ->assertJsonPath('data.planner.frontier_max', 4);
        $snapshot = $snapshotResponse->json('data');
        $versiones = collect($snapshot['tareas'])->keyBy('id');
        $posicion = $contexto['posiciones'][0];

        $this->conToken($contexto['token'])
            ->postJson("/api/planes-operacionales/{$plan->id}/frontera", [
                'snapshot_version' => $snapshot['snapshot_version'],
                'planner_version' => 'rolling-test-1',
                'propuestas' => $tareas->map(fn ($tarea): array => [
                    'tarea_id' => $tarea->id,
                    'posicion_destino_id' => $posicion->id,
                    'tarea_version' => $versiones[$tarea->id]['version'],
                    'plan_version' => $snapshot['plan']['version'],
                    'version_camara_conocida' => $contexto['camara']->refresh()->version_plano,
                    'score' => 100,
                    'motivo' => 'Prueba de arbitraje parcial.',
                ])->all(),
            ])
            ->assertOk()
            ->assertJsonCount(1, 'data.aceptadas')
            ->assertJsonCount(1, 'data.rechazadas')
            ->assertJsonPath('data.recalcular', true)
            ->assertJsonPath('data.aceptadas.0.tarea.reserva.tipo_compromiso', 'fisica')
            ->assertJsonPath('data.aceptadas.0.tarea.reserva.destino_reservado', true);

        $this->assertSame(1, $plan->tareas()
            ->whereNotNull('posicion_destino_id')
            ->count());
    }

    public function test_snapshot_obsoleto_no_materializa_ninguna_propuesta(): void
    {
        $contexto = $this->crearContexto();
        $plan = $this->crearPlanRolling($contexto, [$contexto['folios'][0]]);
        $tarea = $plan->tareas->firstOrFail();
        $this->conToken($contexto['token'])
            ->postJson("/api/tareas-movimiento/{$tarea->id}/asumir")
            ->assertOk();
        $snapshot = $this->conToken($contexto['token'])
            ->getJson("/api/planes-operacionales/{$plan->id}/snapshot")
            ->assertOk()
            ->json('data');

        $plan->increment('version');

        $this->conToken($contexto['token'])
            ->postJson("/api/planes-operacionales/{$plan->id}/frontera", [
                'snapshot_version' => $snapshot['snapshot_version'],
                'planner_version' => 'rolling-test-1',
                'propuestas' => [[
                    'tarea_id' => $tarea->id,
                    'posicion_destino_id' => $contexto['posiciones'][0]->id,
                    'tarea_version' => $snapshot['tareas'][0]['version'],
                    'plan_version' => $snapshot['plan']['version'],
                    'version_camara_conocida' => $contexto['camara']->version_plano,
                ]],
            ])
            ->assertConflict()
            ->assertJsonPath('codigo', 'snapshot_obsoleto')
            ->assertJsonCount(0, 'data.aceptadas');

        $this->assertDatabaseMissing('reservas_tareas_movimiento', [
            'tarea_movimiento_id' => $tarea->id,
            'bloqueo_posicion_id' => $contexto['posiciones'][0]->id,
        ]);
    }

    public function test_frontera_no_materializa_fuera_de_guided_tablet(): void
    {
        $contexto = $this->crearContexto();
        $plan = $this->crearPlanRolling($contexto, [$contexto['folios'][0]]);
        $tarea = $plan->tareas->firstOrFail();
        $this->conToken($contexto['token'])
            ->postJson("/api/tareas-movimiento/{$tarea->id}/asumir")
            ->assertOk();
        $snapshot = $this->conToken($contexto['token'])
            ->getJson("/api/planes-operacionales/{$plan->id}/snapshot")
            ->assertOk()
            ->json('data');

        config(['planificador.mode' => 'shadow']);

        $this->conToken($contexto['token'])
            ->postJson("/api/planes-operacionales/{$plan->id}/frontera", [
                'snapshot_version' => $snapshot['snapshot_version'],
                'planner_version' => 'rolling-test-1',
                'propuestas' => [[
                    'tarea_id' => $tarea->id,
                    'posicion_destino_id' => $contexto['posiciones'][0]->id,
                    'tarea_version' => $snapshot['tareas'][0]['version'],
                    'plan_version' => $snapshot['plan']['version'],
                    'version_camara_conocida' => $contexto['camara']->version_plano,
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('codigo', 'regla_de_negocio');

        $this->assertDatabaseMissing('reservas_tareas_movimiento', [
            'tarea_movimiento_id' => $tarea->id,
            'bloqueo_posicion_id' => $contexto['posiciones'][0]->id,
        ]);
    }

    public function test_frontera_rechaza_un_destino_fuera_del_rollout_guided(): void
    {
        $contexto = $this->crearContexto();
        config(['planificador.rollout_camaras' => ['CAM-OTRA']]);
        Camara::create([
            'codigo' => 'CAM-OTRA',
            'nombre' => 'Cámara habilitada en rollout',
            'cantidad_bandas' => 1,
            'posiciones_por_banda' => 1,
            'cantidad_niveles' => 1,
        ]);
        $plan = $this->crearPlanRolling($contexto, [$contexto['folios'][0]]);
        $tarea = $plan->tareas->firstOrFail();
        $this->conToken($contexto['token'])
            ->postJson("/api/tareas-movimiento/{$tarea->id}/asumir")
            ->assertOk();
        $snapshot = $this->conToken($contexto['token'])
            ->getJson("/api/planes-operacionales/{$plan->id}/snapshot")
            ->assertOk()
            ->assertJsonPath('data.planner.mode_efectivo', 'shadow')
            ->json('data');

        $this->conToken($contexto['token'])
            ->postJson("/api/planes-operacionales/{$plan->id}/frontera", [
                'snapshot_version' => $snapshot['snapshot_version'],
                'planner_version' => 'rolling-rollout-test',
                'propuestas' => [[
                    'tarea_id' => $tarea->id,
                    'posicion_destino_id' => $contexto['posiciones'][0]->id,
                    'tarea_version' => $snapshot['tareas'][0]['version'],
                    'plan_version' => $snapshot['plan']['version'],
                    'version_camara_conocida' => $contexto['camara']->version_plano,
                ]],
            ])
            ->assertOk()
            ->assertJsonCount(0, 'data.aceptadas')
            ->assertJsonCount(1, 'data.rechazadas')
            ->assertJsonPath('data.recalcular', true)
            ->assertJsonPath(
                'data.rechazadas.0.motivo',
                'La propuesta involucra una cámara fuera del rollout dirigido o el planificador no está habilitado completamente.',
            );

        $this->assertDatabaseMissing('reservas_tareas_movimiento', [
            'tarea_movimiento_id' => $tarea->id,
            'bloqueo_posicion_id' => $contexto['posiciones'][0]->id,
        ]);
    }

    public function test_en_proceso_es_punto_de_no_retorno_y_no_expira(): void
    {
        $contexto = $this->crearContexto();
        $plan = $this->crearPlanRolling($contexto, [$contexto['folios'][0]]);
        $tarea = $plan->tareas->firstOrFail();
        $servicio = app(ServicioPlanesOperacionales::class);

        $servicio->asumir($tarea, $contexto['camarero'], $contexto['dispositivo']);
        $servicio->materializarDestino(
            $tarea->refresh(),
            $contexto['posiciones'][0],
            $contexto['camarero'],
            $contexto['dispositivo'],
        );

        app(ServicioPinOperacional::class)->configurar($contexto['camarero'], '2580', null);

        $this->conToken($contexto['token'])
            ->postJson("/api/tareas-movimiento/{$tarea->id}/iniciar", [
                'confirmacion_folio' => '001',
                'pin' => '2580',
            ])
            ->assertOk()
            ->assertJsonPath('data.estado', 'en_proceso')
            ->assertJsonPath('data.punto_no_retorno', true)
            ->assertJsonPath('data.reserva.tipo_compromiso', 'fisica')
            ->assertJsonPath('data.reserva.segundos_restantes', null);

        $this->conToken($contexto['token'])
            ->postJson("/api/tareas-movimiento/{$tarea->id}/liberar")
            ->assertConflict();

        $this->travel(15)->minutes();

        $this->conToken($contexto['token'])
            ->getJson('/api/tareas-movimiento?asignacion=mias')
            ->assertOk()
            ->assertJsonPath('data.0.id', $tarea->id)
            ->assertJsonPath('data.0.estado', 'en_proceso')
            ->assertJsonPath('data.0.punto_no_retorno', true);
    }

    public function test_completar_una_frontera_rolling_no_completa_el_objetivo_por_quedarse_sin_tareas(): void
    {
        $contexto = $this->crearContexto();
        $plan = $this->crearPlanRolling($contexto, [$contexto['folios'][0]]);
        $tarea = $plan->tareas->firstOrFail();
        $servicio = app(ServicioPlanesOperacionales::class);

        $servicio->asumir($tarea, $contexto['camarero'], $contexto['dispositivo']);
        $servicio->materializarDestino(
            $tarea->refresh(),
            $contexto['posiciones'][0],
            $contexto['camarero'],
            $contexto['dispositivo'],
        );
        $servicio->iniciar($tarea->refresh(), $contexto['camarero'], $contexto['dispositivo']);
        config(['planificador.horizon' => 'batch']);
        $sesion = app(ServicioSesionEstiba::class)->abrir(
            $contexto['camara'],
            $contexto['camarero'],
            $contexto['dispositivo'],
        );

        app(ServicioMovimientoEstiba::class)->ubicar(
            operacionId: (string) Str::uuid(),
            numeroFolio: $contexto['folios'][0]->numero_folio,
            tipoBulto: TipoBulto::Pallet,
            posicionDestino: $contexto['posiciones'][0],
            sesionDestino: $sesion,
            usuario: $contexto['camarero'],
            dispositivo: $contexto['dispositivo'],
            versionDestinoConocida: $contexto['camara']->refresh()->version_plano,
            generadoDispositivoAt: now(),
            tareaMovimiento: $tarea->refresh(),
        );

        $this->assertSame('completada', $tarea->refresh()->estado->value);
        $this->assertSame('en_ejecucion', $plan->refresh()->estado->value);
    }

    /** @param array<int, Folio> $folios */
    private function crearPlanRolling(array $contexto, array $folios)
    {
        return app(ServicioPlanesOperacionales::class)->crear(
            temporada: $contexto['temporada'],
            tipo: TipoPlanOperacional::AlmacenamientoPallet,
            titulo: 'Objetivo rolling de almacenamiento',
            creadoPor: $contexto['supervisor'],
            tareas: array_map(fn (Folio $folio): array => [
                'folio_id' => $folio->id,
                'tipo_movimiento' => TipoMovimiento::UbicacionInicial,
                'instruccion' => 'Resolver ubicación sin comprometer el destino por anticipado.',
            ], $folios),
            contexto: ['planner_horizon' => 'rolling'],
        );
    }

    /** @return array<string, mixed> */
    private function crearContexto(): array
    {
        Temporada::query()->update(['activa' => false]);
        $temporada = Temporada::create([
            'codigo' => 'TEMP-ROLLING-2026',
            'nombre' => 'Temporada rolling 2026',
            'fecha_inicio' => '2026-01-01',
            'activa' => true,
        ]);
        $supervisor = User::factory()->create([
            'rol' => RolUsuario::SupervisorFrio,
            'activo' => true,
        ]);
        $camarero = User::factory()->create([
            'rol' => RolUsuario::CamareroFrio,
            'activo' => true,
        ]);
        $dispositivo = Dispositivo::create([
            'codigo' => 'TABLET-ROLLING-01',
            'nombre' => 'Tablet rolling 01',
        ]);
        $token = $camarero
            ->crearTokenParaDispositivo($dispositivo, 'tablet-rolling')
            ->plainTextToken;
        $camara = Camara::create([
            'codigo' => 'CAM-ROLL',
            'nombre' => 'Cámara rolling',
            'cantidad_bandas' => 1,
            'posiciones_por_banda' => 4,
            'cantidad_niveles' => 1,
        ]);
        app(ServicioBandasOperacionales::class)->sincronizar($camara->refresh(), $supervisor);
        $posiciones = [];

        for ($indice = 1; $indice <= 4; $indice++) {
            $posiciones[] = Posicion::create([
                'camara_id' => $camara->id,
                'banda' => 1,
                'posicion' => $indice,
                'nivel' => 1,
                'etiqueta' => sprintf('B01-P%02d-N1', $indice),
            ]);
        }

        $folios = array_map(fn (int $indice): Folio => Folio::create([
            'temporada_id' => $temporada->id,
            'numero_folio' => sprintf('ROLL-%03d', $indice),
            'tipo_bulto' => TipoBulto::Pallet,
            'fecha_ingreso' => now(),
        ]), [1, 2]);

        return compact(
            'temporada',
            'supervisor',
            'camarero',
            'dispositivo',
            'token',
            'camara',
            'posiciones',
            'folios',
        );
    }

    private function conToken(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }
}
