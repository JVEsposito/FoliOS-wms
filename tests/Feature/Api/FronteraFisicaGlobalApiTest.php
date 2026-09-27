<?php

namespace Tests\Feature\Api;

use App\Enums\EstadoManiobraOperacional;
use App\Enums\RolUsuario;
use App\Enums\TipoBulto;
use App\Enums\TipoMovimiento;
use App\Enums\TipoPlanOperacional;
use App\Jobs\RecalcularArbitrajePlanificador;
use App\Models\Camara;
use App\Models\Dispositivo;
use App\Models\Folio;
use App\Models\Posicion;
use App\Models\Temporada;
use App\Models\User;
use App\Services\Camaras\ServicioBandasOperacionales;
use App\Services\Estiba\ServicioPlanesOperacionales;
use App\Services\Planificador\ServicioEstadoArbitrajePlanificador;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FronteraFisicaGlobalApiTest extends TestCase
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
            'planificador.maniobras_simultaneas_max' => 3,
        ]);
    }

    public function test_materializa_en_un_ciclo_tareas_asumidas_de_planes_distintos(): void
    {
        $contexto = $this->crearContexto();
        config(['planificador.camara_preferente_despacho' => $contexto['camara']->codigo]);
        $primera = $this->crearPlan(
            $contexto,
            $contexto['folios'][0],
            TipoPlanOperacional::AlmacenamientoPallet,
        )->tareas()->sole();
        $segunda = $this->crearPlan(
            $contexto,
            $contexto['folios'][1],
            TipoPlanOperacional::RecepcionTunel,
        )->tareas()->sole();
        $planes = app(ServicioPlanesOperacionales::class);
        $planes->asumir($primera, $contexto['camarero'], $contexto['dispositivo']);
        $planes->asumir($segunda, $contexto['camarero'], $contexto['dispositivo']);
        $this->actualizarArbitraje($contexto['temporada']);

        $snapshot = $this->conToken($contexto['token'])
            ->getJson('/api/frontera-fisica/snapshot')
            ->assertOk()
            ->assertJsonCount(2, 'data.tareas')
            ->assertJsonPath('data.arbitraje.en_ejecucion', 2)
            ->assertJsonPath('data.frontera.tareas_materializables', 2)
            ->assertJsonPath('data.frontera.solo_paso_actual', true)
            ->assertJsonPath('data.planner.camara_preferente_despacho_id', $contexto['camara']->id)
            ->json('data');
        $tareas = collect($snapshot['tareas'])->keyBy('id');

        $this->conToken($contexto['token'])
            ->postJson('/api/frontera-fisica/materializar', [
                'snapshot_version' => $snapshot['snapshot_version'],
                'planner_version' => 'frontera-global-test',
                'propuestas' => [
                    $this->propuesta(
                        $tareas[$primera->id],
                        $contexto['posiciones'][0],
                        $contexto['camara'],
                    ),
                    $this->propuesta(
                        $tareas[$segunda->id],
                        $contexto['posiciones'][1],
                        $contexto['camara'],
                    ),
                ],
            ])
            ->assertOk()
            ->assertJsonCount(2, 'data.aceptadas')
            ->assertJsonCount(0, 'data.rechazadas')
            ->assertJsonPath('data.recalcular', false)
            ->assertJsonPath('data.snapshot.frontera.tareas_materializables', 0);

        $this->assertSame($contexto['posiciones'][0]->id, $primera->refresh()->posicion_destino_id);
        $this->assertSame($contexto['posiciones'][1]->id, $segunda->refresh()->posicion_destino_id);
        $this->assertNotSame($primera->plan_operacional_id, $segunda->plan_operacional_id);
    }

    public function test_acepta_parcialmente_y_protege_el_destino_entre_planes(): void
    {
        $contexto = $this->crearContexto();
        $primera = $this->crearPlan(
            $contexto,
            $contexto['folios'][0],
            TipoPlanOperacional::AlmacenamientoPallet,
        )->tareas()->sole();
        $segunda = $this->crearPlan(
            $contexto,
            $contexto['folios'][1],
            TipoPlanOperacional::RecepcionTunel,
        )->tareas()->sole();
        $planes = app(ServicioPlanesOperacionales::class);
        $planes->asumir($primera, $contexto['camarero'], $contexto['dispositivo']);
        $planes->asumir($segunda, $contexto['camarero'], $contexto['dispositivo']);
        $this->actualizarArbitraje($contexto['temporada']);
        $snapshot = $this->conToken($contexto['token'])
            ->getJson('/api/frontera-fisica/snapshot')
            ->assertOk()
            ->json('data');
        $tareas = collect($snapshot['tareas'])->keyBy('id');

        $this->conToken($contexto['token'])
            ->postJson('/api/frontera-fisica/materializar', [
                'snapshot_version' => $snapshot['snapshot_version'],
                'planner_version' => 'frontera-global-test',
                'propuestas' => [
                    $this->propuesta(
                        $tareas[$primera->id],
                        $contexto['posiciones'][0],
                        $contexto['camara'],
                    ),
                    $this->propuesta(
                        $tareas[$segunda->id],
                        $contexto['posiciones'][0],
                        $contexto['camara'],
                    ),
                ],
            ])
            ->assertOk()
            ->assertJsonCount(1, 'data.aceptadas')
            ->assertJsonCount(1, 'data.rechazadas')
            ->assertJsonPath('data.recalcular', true);

        $this->assertSame(
            $contexto['posiciones'][0]->id,
            $primera->refresh()->posicion_destino_id,
        );
        $this->assertNull($segunda->refresh()->posicion_destino_id);
    }

    public function test_snapshot_obsoleto_no_reserva_ninguna_posicion(): void
    {
        $contexto = $this->crearContexto();
        $tarea = $this->crearPlan(
            $contexto,
            $contexto['folios'][0],
            TipoPlanOperacional::AlmacenamientoPallet,
        )->tareas()->sole();
        app(ServicioPlanesOperacionales::class)->asumir(
            $tarea,
            $contexto['camarero'],
            $contexto['dispositivo'],
        );
        $this->actualizarArbitraje($contexto['temporada']);
        $snapshot = $this->conToken($contexto['token'])
            ->getJson('/api/frontera-fisica/snapshot')
            ->assertOk()
            ->json('data');
        $tareaSnapshot = collect($snapshot['tareas'])->firstWhere('id', $tarea->id);
        $contexto['camara']->increment('version_plano');

        $this->conToken($contexto['token'])
            ->postJson('/api/frontera-fisica/materializar', [
                'snapshot_version' => $snapshot['snapshot_version'],
                'planner_version' => 'frontera-global-test',
                'propuestas' => [$this->propuesta(
                    $tareaSnapshot,
                    $contexto['posiciones'][0],
                    $contexto['camara'],
                )],
            ])
            ->assertConflict()
            ->assertJsonPath('codigo', 'snapshot_fisico_obsoleto')
            ->assertJsonCount(0, 'data.aceptadas');

        $this->assertNull($tarea->refresh()->posicion_destino_id);
    }

    public function test_rollout_restringe_snapshot_y_rechaza_un_destino_forjado_fuera_de_el(): void
    {
        $contexto = $this->crearContexto();
        config(['planificador.rollout_camaras' => [$contexto['camara']->codigo]]);
        $camaraFuera = Camara::create([
            'codigo' => 'CAM-FUERA-ROLLOUT',
            'nombre' => 'Cámara fuera de rollout',
            'cantidad_bandas' => 1,
            'posiciones_por_banda' => 1,
            'cantidad_niveles' => 1,
        ]);
        $posicionFuera = Posicion::create([
            'camara_id' => $camaraFuera->id,
            'banda' => 1,
            'posicion' => 1,
            'nivel' => 1,
            'etiqueta' => 'FUERA-B01-P01-N1',
        ]);
        $tarea = $this->crearPlan(
            $contexto,
            $contexto['folios'][0],
            TipoPlanOperacional::AlmacenamientoPallet,
        )->tareas()->sole();
        app(ServicioPlanesOperacionales::class)->asumir(
            $tarea,
            $contexto['camarero'],
            $contexto['dispositivo'],
        );
        $this->actualizarArbitraje($contexto['temporada']);

        $snapshot = $this->conToken($contexto['token'])
            ->getJson('/api/frontera-fisica/snapshot')
            ->assertOk()
            ->assertJsonCount(1, 'data.camaras')
            ->assertJsonPath('data.camaras.0.id', $contexto['camara']->id)
            ->assertJsonPath('data.planner.rollout_limitado', true)
            ->assertJsonPath('data.planner.camaras_dirigidas.0', $contexto['camara']->id)
            ->assertJsonPath('data.tareas.0.materializable', true)
            ->json('data');

        $this->conToken($contexto['token'])
            ->postJson('/api/frontera-fisica/materializar', [
                'snapshot_version' => $snapshot['snapshot_version'],
                'planner_version' => 'frontera-global-test',
                'propuestas' => [$this->propuesta(
                    $snapshot['tareas'][0],
                    $posicionFuera,
                    $camaraFuera,
                )],
            ])
            ->assertOk()
            ->assertJsonCount(0, 'data.aceptadas')
            ->assertJsonCount(1, 'data.rechazadas')
            ->assertJsonPath(
                'data.rechazadas.0.motivo',
                'La propuesta involucra una cámara fuera del rollout dirigido o el planificador no está habilitado completamente.',
            );

        $this->assertNull($tarea->refresh()->posicion_destino_id);
    }

    public function test_generacion_desactivada_cierra_completamente_la_frontera_fisica(): void
    {
        $contexto = $this->crearContexto();
        $tarea = $this->crearPlan(
            $contexto,
            $contexto['folios'][0],
            TipoPlanOperacional::AlmacenamientoPallet,
        )->tareas()->sole();
        app(ServicioPlanesOperacionales::class)->asumir(
            $tarea,
            $contexto['camarero'],
            $contexto['dispositivo'],
        );
        config(['planificador.generacion_automatica' => false]);
        $this->actualizarArbitraje($contexto['temporada']);

        $this->conToken($contexto['token'])
            ->getJson('/api/frontera-fisica/snapshot')
            ->assertOk()
            ->assertJsonCount(0, 'data.camaras')
            ->assertJsonPath('data.planner.camaras_dirigidas', [])
            ->assertJsonPath('data.arbitraje.fuera_rollout', 1)
            ->assertJsonPath('data.frontera.tareas_materializables', 0)
            ->assertJsonPath('data.tareas.0.materializable', false);
    }

    public function test_punto_de_no_retorno_no_admite_otro_destino(): void
    {
        $contexto = $this->crearContexto();
        $tarea = $this->crearPlan(
            $contexto,
            $contexto['folios'][0],
            TipoPlanOperacional::AlmacenamientoPallet,
        )->tareas()->sole();
        $planes = app(ServicioPlanesOperacionales::class);
        $planes->asumir($tarea, $contexto['camarero'], $contexto['dispositivo']);
        $planes->materializarDestino(
            $tarea->refresh(),
            $contexto['posiciones'][0],
            $contexto['camarero'],
            $contexto['dispositivo'],
        );
        $planes->iniciar(
            $tarea->refresh(),
            $contexto['camarero'],
            $contexto['dispositivo'],
        );
        $this->actualizarArbitraje($contexto['temporada']);
        $snapshot = $this->conToken($contexto['token'])
            ->getJson('/api/frontera-fisica/snapshot')
            ->assertOk()
            ->assertJsonPath('data.tareas.0.punto_no_retorno', true)
            ->assertJsonPath('data.tareas.0.materializable', false)
            ->json('data');
        $tareaSnapshot = $snapshot['tareas'][0];

        $this->conToken($contexto['token'])
            ->postJson('/api/frontera-fisica/materializar', [
                'snapshot_version' => $snapshot['snapshot_version'],
                'planner_version' => 'frontera-global-test',
                'propuestas' => [$this->propuesta(
                    $tareaSnapshot,
                    $contexto['posiciones'][1],
                    $contexto['camara']->refresh(),
                )],
            ])
            ->assertOk()
            ->assertJsonCount(0, 'data.aceptadas')
            ->assertJsonCount(1, 'data.rechazadas')
            ->assertJsonPath(
                'data.rechazadas.0.motivo',
                'La tarea no es el paso actual materializable de esta tablet.',
            );

        $this->assertSame($contexto['posiciones'][0]->id, $tarea->refresh()->posicion_destino_id);
    }

    public function test_maniobra_pausada_permanece_visible_pero_no_materializable(): void
    {
        $contexto = $this->crearContexto();
        $tarea = $this->crearPlan(
            $contexto,
            $contexto['folios'][0],
            TipoPlanOperacional::AlmacenamientoPallet,
        )->tareas()->sole();
        app(ServicioPlanesOperacionales::class)->asumir(
            $tarea,
            $contexto['camarero'],
            $contexto['dispositivo'],
        );
        $maniobra = $tarea->refresh()->maniobraOperacional;
        $maniobra->update([
            'estado' => EstadoManiobraOperacional::PausadaDiscrepancia,
            'version' => $maniobra->version + 1,
        ]);
        $this->actualizarArbitraje($contexto['temporada']);

        $snapshot = $this->conToken($contexto['token'])
            ->getJson('/api/frontera-fisica/snapshot')
            ->assertOk()
            ->assertJsonPath('data.tareas.0.materializable', false)
            ->assertJsonPath('data.frontera.tareas_materializables', 0)
            ->json('data');

        $this->conToken($contexto['token'])
            ->postJson('/api/frontera-fisica/materializar', [
                'snapshot_version' => $snapshot['snapshot_version'],
                'planner_version' => 'frontera-global-test',
                'propuestas' => [$this->propuesta(
                    $snapshot['tareas'][0],
                    $contexto['posiciones'][0],
                    $contexto['camara'],
                )],
            ])
            ->assertOk()
            ->assertJsonCount(0, 'data.aceptadas')
            ->assertJsonCount(1, 'data.rechazadas');

        $this->assertNull($tarea->refresh()->posicion_destino_id);
    }

    private function actualizarArbitraje(Temporada $temporada): void
    {
        app(ServicioEstadoArbitrajePlanificador::class)
            ->solicitar($temporada, 'prueba_frontera_fisica');
        RecalcularArbitrajePlanificador::dispatchSync($temporada->id);
    }

    /** @return array<string, mixed> */
    private function propuesta(array $tarea, Posicion $posicion, Camara $camara): array
    {
        return [
            'tarea_id' => $tarea['id'],
            'posicion_destino_id' => $posicion->id,
            'tarea_version' => $tarea['version'],
            'plan_version' => $tarea['plan_version'],
            'version_camara_conocida' => $camara->refresh()->version_plano,
            'score' => 100,
            'motivo' => 'Destino próximo calculado por la tablet.',
        ];
    }

    private function crearPlan(
        array $contexto,
        Folio $folio,
        TipoPlanOperacional $tipo,
    ) {
        return app(ServicioPlanesOperacionales::class)->crear(
            temporada: $contexto['temporada'],
            tipo: $tipo,
            titulo: "Frontera física {$folio->numero_folio}",
            creadoPor: $contexto['supervisor'],
            tareas: [[
                'folio_id' => $folio->id,
                'tipo_movimiento' => TipoMovimiento::UbicacionInicial,
                'instruccion' => 'Materializar solamente el próximo destino.',
            ]],
            contexto: ['planner_horizon' => 'rolling'],
        );
    }

    /** @return array<string, mixed> */
    private function crearContexto(): array
    {
        Temporada::query()->update(['activa' => false]);
        $temporada = Temporada::create([
            'codigo' => 'TEMP-FRONTERA-FISICA',
            'nombre' => 'Temporada frontera física',
            'fecha_inicio' => '2026-09-01',
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
            'codigo' => 'TABLET-FRONTERA-FISICA',
            'nombre' => 'Tablet frontera física',
        ]);
        $token = $camarero
            ->crearTokenParaDispositivo($dispositivo, 'frontera-fisica')
            ->plainTextToken;
        $camara = Camara::create([
            'codigo' => 'CAM-FRONTERA-FISICA',
            'nombre' => 'Cámara frontera física',
            'cantidad_bandas' => 1,
            'posiciones_por_banda' => 6,
            'cantidad_niveles' => 1,
        ]);
        app(ServicioBandasOperacionales::class)->sincronizar($camara->refresh(), $supervisor);
        $posiciones = [];
        $folios = [];

        for ($indice = 0; $indice < 6; $indice++) {
            $posiciones[] = Posicion::create([
                'camara_id' => $camara->id,
                'banda' => 1,
                'posicion' => $indice + 1,
                'nivel' => 1,
                'etiqueta' => sprintf('B01-P%02d-N1', $indice + 1),
            ]);
            $folios[] = Folio::create([
                'temporada_id' => $temporada->id,
                'numero_folio' => sprintf('FIS-%03d', $indice + 1),
                'tipo_bulto' => TipoBulto::Pallet,
                'fecha_ingreso' => now()->subMinutes($indice),
            ]);
        }

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
