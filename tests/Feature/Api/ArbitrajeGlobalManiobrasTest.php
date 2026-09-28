<?php

namespace Tests\Feature\Api;

use App\Enums\DecisionArbitrajeManiobra;
use App\Enums\EstadoManiobraOperacional;
use App\Enums\EstadoPlanOperacional;
use App\Enums\EstadoTareaMovimiento;
use App\Enums\PrioridadOperacional;
use App\Enums\RolUsuario;
use App\Enums\TipoBulto;
use App\Enums\TipoMovimiento;
use App\Enums\TipoPlanOperacional;
use App\Jobs\RecalcularArbitrajePlanificador;
use App\Models\Camara;
use App\Models\Dispositivo;
use App\Models\Folio;
use App\Models\ManiobraOperacional;
use App\Models\Posicion;
use App\Models\Temporada;
use App\Models\User;
use App\Services\Camaras\ServicioBandasOperacionales;
use App\Services\Estiba\ServicioPlanesOperacionales;
use App\Services\Planificador\ServicioArbitrajeManiobras;
use App\Services\Planificador\ServicioEstadoArbitrajePlanificador;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArbitrajeGlobalManiobrasTest extends TestCase
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

    public function test_prioridad_y_objetivo_dominan_el_beneficio_y_el_ciclo_es_idempotente(): void
    {
        $contexto = $this->crearContexto();
        $normal = $this->crearManiobra(
            $contexto,
            0,
            TipoPlanOperacional::ConcentracionCarga,
            PrioridadOperacional::Normal,
            1_000_000,
        );
        $urgente = $this->crearManiobra(
            $contexto,
            1,
            TipoPlanOperacional::ConcentracionCarga,
            PrioridadOperacional::Urgente,
            5_000,
        );
        $retenido = $this->crearManiobra(
            $contexto,
            2,
            TipoPlanOperacional::SegregacionRetenido,
            PrioridadOperacional::Critica,
            500,
        );
        $despacho = $this->crearManiobra(
            $contexto,
            3,
            TipoPlanOperacional::DespachoDirecto,
            PrioridadOperacional::Critica,
            100,
        );
        $emergencia = $this->crearManiobra(
            $contexto,
            4,
            TipoPlanOperacional::EvacuacionEmergencia,
            PrioridadOperacional::Critica,
            0,
        );

        $servicio = app(ServicioArbitrajeManiobras::class);
        $ciclo = $servicio->arbitrar($contexto['temporada']);
        $decisiones = $ciclo->decisiones->keyBy('maniobra_operacional_id');

        $this->assertSame(
            DecisionArbitrajeManiobra::Seleccionada,
            $decisiones[$emergencia->id]->decision,
        );
        $this->assertSame(
            DecisionArbitrajeManiobra::Seleccionada,
            $decisiones[$despacho->id]->decision,
        );
        $this->assertSame(
            DecisionArbitrajeManiobra::Seleccionada,
            $decisiones[$retenido->id]->decision,
        );
        $this->assertSame(
            DecisionArbitrajeManiobra::Alternativa,
            $decisiones[$urgente->id]->decision,
        );
        $this->assertSame(
            DecisionArbitrajeManiobra::FueraFrontera,
            $decisiones[$normal->id]->decision,
        );
        $this->assertSame(
            [$emergencia->id, $despacho->id, $retenido->id, $urgente->id],
            $servicio->idsPublicables($ciclo)->all(),
        );
        $explicacionEmergencia = $decisiones[$emergencia->id]->explicacion;
        $this->assertSame(1, $explicacionEmergencia['version']);
        $this->assertSame(
            'arbitraje_global_v5_pausa_fisica',
            $explicacionEmergencia['reglas'],
        );
        $this->assertSame(
            'cupo_disponible',
            $explicacionEmergencia['factor_decisivo']['codigo'],
        );
        $this->assertSame(
            PrioridadOperacional::Critica->peso() * 1_000_000_000,
            $explicacionEmergencia['componentes']['prioridad']['aporte'],
        );
        $this->assertSame(
            TipoPlanOperacional::EvacuacionEmergencia->value,
            $explicacionEmergencia['componentes']['objetivo']['tipo'],
        );
        $this->assertSame(
            $decisiones[$emergencia->id]->puntaje,
            $explicacionEmergencia['componentes']['puntaje'],
        );
        $this->assertSame(
            $emergencia->titulo,
            $explicacionEmergencia['snapshot']['maniobra']['titulo'],
        );
        $this->assertSame(
            $contexto['folios'][4]->numero_folio,
            $explicacionEmergencia['snapshot']['pasos'][0]['folio']['numero_folio'],
        );

        $repetido = $servicio->arbitrar($contexto['temporada']);
        $this->assertSame($ciclo->id, $repetido->id);
        $this->assertSame(
            $explicacionEmergencia,
            $repetido->decisiones
                ->firstWhere('maniobra_operacional_id', $emergencia->id)
                ?->explicacion,
        );
        $this->assertDatabaseCount('ciclos_arbitraje_maniobras', 1);
        $this->assertDatabaseCount('decisiones_arbitraje_maniobras', 5);

        $planEmergencia = $emergencia->planOperacional()->firstOrFail();
        $planEmergencia->update([
            'estado' => EstadoPlanOperacional::Pausado,
            'version' => $planEmergencia->version + 1,
        ]);
        $actualizado = $servicio->arbitrar($contexto['temporada']);

        $this->assertNotSame($ciclo->id, $actualizado->id);
        $this->assertSame(
            DecisionArbitrajeManiobra::FueraFrontera,
            $actualizado->decisiones
                ->firstWhere('maniobra_operacional_id', $emergencia->id)
                ?->decision,
        );
        $this->assertDatabaseCount('ciclos_arbitraje_maniobras', 2);
        $this->assertDatabaseCount('decisiones_arbitraje_maniobras', 10);
    }

    public function test_bandeja_publica_solo_compatibles_y_servidor_protege_la_alternativa(): void
    {
        $contexto = $this->crearContexto();
        $principal = $this->crearManiobra(
            $contexto,
            0,
            TipoPlanOperacional::ConcentracionCarga,
            PrioridadOperacional::Alta,
            500,
            0,
        );
        $conflictiva = $this->crearManiobra(
            $contexto,
            1,
            TipoPlanOperacional::ConcentracionCarga,
            PrioridadOperacional::Alta,
            400,
            0,
        );
        $segunda = $this->crearManiobra(
            $contexto,
            2,
            TipoPlanOperacional::ConcentracionCarga,
            PrioridadOperacional::Alta,
            300,
            1,
        );
        $tercera = $this->crearManiobra(
            $contexto,
            3,
            TipoPlanOperacional::ConcentracionCarga,
            PrioridadOperacional::Alta,
            200,
            2,
        );
        $alternativa = $this->crearManiobra(
            $contexto,
            4,
            TipoPlanOperacional::ConcentracionCarga,
            PrioridadOperacional::Alta,
            100,
            3,
        );
        app(ServicioEstadoArbitrajePlanificador::class)
            ->solicitar($contexto['temporada'], 'prueba_bandeja_arbitraje');
        RecalcularArbitrajePlanificador::dispatchSync($contexto['temporada']->id);

        $respuesta = $this->conToken($contexto['token'])
            ->getJson('/api/tareas-movimiento?asignacion=disponibles')
            ->assertOk()
            ->assertJsonPath('meta.total', 4)
            ->assertJsonPath('arbitraje.capacidad_ejecucion', 3)
            ->assertJsonPath('arbitraje.frontera_max', 4)
            ->assertJsonPath('arbitraje.vigencia.estado', 'actual')
            ->assertJsonPath('arbitraje.seleccionadas', 3)
            ->assertJsonPath('arbitraje.alternativas', 1)
            ->assertJsonPath('data.0.maniobra.id', $principal->id)
            ->assertJsonPath('data.0.maniobra.arbitraje.decision', 'seleccionada')
            ->assertJsonPath('data.1.maniobra.id', $segunda->id)
            ->assertJsonPath('data.2.maniobra.id', $tercera->id)
            ->assertJsonPath('data.3.maniobra.id', $alternativa->id)
            ->assertJsonPath('data.3.maniobra.arbitraje.decision', 'alternativa');

        $this->assertNotContains(
            $conflictiva->id,
            collect($respuesta->json('data'))->pluck('maniobra.id')->all(),
        );
        $decisionConflictiva = $conflictiva->ultimaDecisionArbitraje()->firstOrFail();
        $this->assertSame(
            DecisionArbitrajeManiobra::ExcluidaConflicto,
            $decisionConflictiva->decision,
        );
        $this->assertSame(
            $principal->id,
            $decisionConflictiva->conflictos[0]['maniobra_id'],
        );
        $this->assertSame(
            'conflicto_recursos',
            $decisionConflictiva->explicacion['factor_decisivo']['codigo'],
        );
        $this->assertSame(
            $principal->titulo,
            $decisionConflictiva->explicacion['recursos']['conflictos'][0]['maniobra_titulo'],
        );
        $this->assertStringContainsString(
            'B01-P01-N1',
            $decisionConflictiva->explicacion['recursos']['conflictos'][0]['nombre'],
        );

        $tareaAlternativa = $alternativa->pasos()->sole();
        $this->conToken($contexto['token'])
            ->postJson("/api/tareas-movimiento/{$tareaAlternativa->id}/asumir")
            ->assertConflict()
            ->assertJsonPath('codigo', 'conflicto_operacional');
    }

    public function test_realidad_fisica_iniciada_conserva_el_recurso_ante_una_prioridad_mayor(): void
    {
        $contexto = $this->crearContexto();
        $iniciada = $this->crearManiobra(
            $contexto,
            0,
            TipoPlanOperacional::ConcentracionCarga,
            PrioridadOperacional::Normal,
            100,
            0,
        );
        $iniciada->update([
            'estado' => EstadoManiobraOperacional::EnEjecucion,
            'version' => $iniciada->version + 1,
        ]);
        $pasoIniciado = $iniciada->pasos()->sole();
        $pasoIniciado->update([
            'estado' => EstadoTareaMovimiento::EnProceso,
            'version' => $pasoIniciado->version + 1,
        ]);
        $urgente = $this->crearManiobra(
            $contexto,
            1,
            TipoPlanOperacional::EvacuacionEmergencia,
            PrioridadOperacional::Critica,
            10_000,
            0,
        );

        $ciclo = app(ServicioArbitrajeManiobras::class)
            ->arbitrar($contexto['temporada']);
        $decisiones = $ciclo->decisiones->keyBy('maniobra_operacional_id');

        $this->assertSame(
            DecisionArbitrajeManiobra::EnEjecucion,
            $decisiones[$iniciada->id]->decision,
        );
        $this->assertSame(
            DecisionArbitrajeManiobra::ExcluidaConflicto,
            $decisiones[$urgente->id]->decision,
        );
        $this->assertSame(
            $iniciada->id,
            $decisiones[$urgente->id]->conflictos[0]['maniobra_id'],
        );
    }

    public function test_pausa_antes_del_retiro_libera_cupo_y_pausa_con_retiro_lo_conserva(): void
    {
        config(['planificador.maniobras_simultaneas_max' => 1]);
        $contexto = $this->crearContexto();
        $pausada = $this->crearManiobra(
            $contexto, 0, TipoPlanOperacional::ConcentracionCarga, PrioridadOperacional::Critica, 100,
        );
        $siguiente = $this->crearManiobra(
            $contexto, 1, TipoPlanOperacional::ConcentracionCarga, PrioridadOperacional::Normal, 100,
        );
        $pausada->update(['estado' => EstadoManiobraOperacional::PausadaDiscrepancia, 'version' => 2]);
        $paso = $pausada->pasos()->sole();
        $paso->update(['estado' => EstadoTareaMovimiento::Bloqueada, 'version' => 2]);

        $servicio = app(ServicioArbitrajeManiobras::class);
        $decisiones = $servicio->arbitrar($contexto['temporada'])->decisiones->keyBy('maniobra_operacional_id');
        $this->assertSame(DecisionArbitrajeManiobra::FueraFrontera, $decisiones[$pausada->id]->decision);
        $this->assertSame('pausa_discrepancia_sin_retiro', $decisiones[$pausada->id]->explicacion['factor_decisivo']['codigo']);
        $this->assertSame(DecisionArbitrajeManiobra::Seleccionada, $decisiones[$siguiente->id]->decision);

        $paso->update(['estado' => EstadoTareaMovimiento::EnProceso, 'version' => 3]);
        $decisiones = $servicio->arbitrar($contexto['temporada'])->decisiones->keyBy('maniobra_operacional_id');
        $this->assertSame(DecisionArbitrajeManiobra::EnEjecucion, $decisiones[$pausada->id]->decision);
        $this->assertSame(1, $decisiones[$pausada->id]->explicacion['capacidad']['ocupantes_fisicos']);
        $this->assertSame(DecisionArbitrajeManiobra::Alternativa, $decisiones[$siguiente->id]->decision);
    }

    public function test_la_tablet_puede_reportar_pallet_no_encontrado_antes_del_retiro(): void
    {
        $contexto = $this->crearContexto();
        $maniobra = $this->crearManiobra(
            $contexto, 0, TipoPlanOperacional::ConcentracionCarga, PrioridadOperacional::Alta, 100,
        );
        $tarea = $maniobra->pasos()->sole();
        app(ServicioPlanesOperacionales::class)->asumir(
            $tarea, $contexto['camarero'], $contexto['dispositivo'],
        );

        $this->conToken($contexto['token'])
            ->postJson("/api/tareas-movimiento/{$tarea->id}/no-coincide", [
                'tipo' => 'pallet_no_encontrado',
                'detalle' => 'El pallet no se encuentra en la zona de retiro.',
            ])
            ->assertOk()
            ->assertJsonPath('data.estado', 'abierta');
        $this->assertDatabaseHas('discrepancias_maniobra', [
            'tarea_movimiento_id' => $tarea->id,
            'tipo' => 'pallet_no_encontrado',
        ]);
    }

    public function test_rollout_excluye_maniobras_fuera_de_camara_sin_consumir_la_frontera(): void
    {
        $contexto = $this->crearContexto();
        config(['planificador.rollout_camaras' => [$contexto['camara']->codigo]]);
        $camaraFuera = Camara::create([
            'codigo' => 'CAM-ARB-FUERA',
            'nombre' => 'Cámara arbitraje fuera',
            'cantidad_bandas' => 1,
            'posiciones_por_banda' => 2,
            'cantidad_niveles' => 1,
        ]);
        $posicionFuera = Posicion::create([
            'camara_id' => $camaraFuera->id,
            'banda' => 1,
            'posicion' => 1,
            'nivel' => 1,
            'etiqueta' => 'ARB-FUERA-01',
        ]);
        $dirigida = $this->crearManiobra(
            $contexto,
            0,
            TipoPlanOperacional::ConcentracionCarga,
            PrioridadOperacional::Alta,
            100,
        );
        $fuera = $this->crearManiobra(
            $contexto,
            1,
            TipoPlanOperacional::EvacuacionEmergencia,
            PrioridadOperacional::Critica,
            10_000,
            null,
            $camaraFuera,
            $posicionFuera,
        );

        $servicio = app(ServicioArbitrajeManiobras::class);
        $ciclo = $servicio->arbitrar($contexto['temporada']);
        $decisiones = $ciclo->decisiones->keyBy('maniobra_operacional_id');

        $this->assertSame(
            DecisionArbitrajeManiobra::Seleccionada,
            $decisiones[$dirigida->id]->decision,
        );
        $this->assertSame(
            DecisionArbitrajeManiobra::FueraRollout,
            $decisiones[$fuera->id]->decision,
        );
        $this->assertSame([$dirigida->id], $servicio->idsPublicables($ciclo)->all());
    }

    public function test_cambio_de_rollout_preserva_solo_la_realidad_fisica_ya_iniciada(): void
    {
        $contexto = $this->crearContexto();
        $camaraFuera = Camara::create([
            'codigo' => 'CAM-ARB-FISICA',
            'nombre' => 'Cámara realidad física',
            'cantidad_bandas' => 1,
            'posiciones_por_banda' => 2,
            'cantidad_niveles' => 1,
        ]);
        $posicionesFuera = collect([1, 2])->map(fn (int $posicion) => Posicion::create([
            'camara_id' => $camaraFuera->id,
            'banda' => 1,
            'posicion' => $posicion,
            'nivel' => 1,
            'etiqueta' => "ARB-FISICA-{$posicion}",
        ]));
        $soloAsumida = $this->crearManiobra(
            $contexto,
            0,
            TipoPlanOperacional::ConcentracionCarga,
            PrioridadOperacional::Alta,
            100,
            null,
            $camaraFuera,
            $posicionesFuera[0],
        );
        $fisica = $this->crearManiobra(
            $contexto,
            1,
            TipoPlanOperacional::ConcentracionCarga,
            PrioridadOperacional::Normal,
            50,
            null,
            $camaraFuera,
            $posicionesFuera[1],
        );
        foreach ([$soloAsumida, $fisica] as $maniobra) {
            $maniobra->update([
                'estado' => EstadoManiobraOperacional::EnEjecucion,
                'version' => $maniobra->version + 1,
            ]);
        }
        $pasoAsumido = $soloAsumida->pasos()->sole();
        $pasoAsumido->update([
            'estado' => EstadoTareaMovimiento::Asumida,
            'version' => $pasoAsumido->version + 1,
        ]);
        $pasoFisico = $fisica->pasos()->sole();
        $pasoFisico->update([
            'estado' => EstadoTareaMovimiento::EnProceso,
            'version' => $pasoFisico->version + 1,
        ]);
        config(['planificador.rollout_camaras' => [$contexto['camara']->codigo]]);

        $decisiones = app(ServicioArbitrajeManiobras::class)
            ->arbitrar($contexto['temporada'])
            ->decisiones
            ->keyBy('maniobra_operacional_id');

        $this->assertSame(
            DecisionArbitrajeManiobra::FueraRollout,
            $decisiones[$soloAsumida->id]->decision,
        );
        $this->assertSame(
            DecisionArbitrajeManiobra::EnEjecucion,
            $decisiones[$fisica->id]->decision,
        );
    }

    /**
     * @param  array<string, mixed>  $contexto
     */
    private function crearManiobra(
        array $contexto,
        int $indiceFolio,
        TipoPlanOperacional $tipo,
        PrioridadOperacional $prioridad,
        int $beneficio,
        ?int $indicePosicion = null,
        ?Camara $camaraDestino = null,
        ?Posicion $posicionDestino = null,
    ): ManiobraOperacional {
        $indicePosicion ??= $indiceFolio;
        $camaraDestino ??= $contexto['camara'];
        $posicionDestino ??= $contexto['posiciones'][$indicePosicion];
        $plan = app(ServicioPlanesOperacionales::class)->crear(
            temporada: $contexto['temporada'],
            tipo: $tipo,
            titulo: "Objetivo arbitraje {$indiceFolio}",
            creadoPor: $contexto['supervisor'],
            tareas: [[
                'folio_id' => $contexto['folios'][$indiceFolio]->id,
                'tipo_movimiento' => TipoMovimiento::UbicacionInicial,
                'camara_destino_id' => $camaraDestino->id,
                'posicion_destino_id' => $posicionDestino->id,
                'contexto' => [
                    'beneficio_estimado' => $beneficio,
                    'riesgo_operacional' => 0,
                ],
            ]],
            prioridad: $prioridad,
            contexto: ['planner_horizon' => 'rolling'],
        );

        return $plan->maniobras()->sole();
    }

    /** @return array<string, mixed> */
    private function crearContexto(): array
    {
        Temporada::query()->update(['activa' => false]);
        $temporada = Temporada::create([
            'codigo' => 'TEMP-ARB-2026',
            'nombre' => 'Temporada arbitraje global',
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
            'codigo' => 'TABLET-ARB-01',
            'nombre' => 'Tablet arbitraje 01',
        ]);
        $token = $camarero
            ->crearTokenParaDispositivo($dispositivo, 'tablet-arbitraje')
            ->plainTextToken;
        $camara = Camara::create([
            'codigo' => 'CAM-ARB-01',
            'nombre' => 'Cámara arbitraje',
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
                'numero_folio' => sprintf('ARB-%03d', $indice + 1),
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
