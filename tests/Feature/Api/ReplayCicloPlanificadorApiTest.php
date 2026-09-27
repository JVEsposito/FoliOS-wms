<?php

namespace Tests\Feature\Api;

use App\Enums\DecisionArbitrajeManiobra as DecisionArbitraje;
use App\Enums\EstadoManiobraOperacional;
use App\Enums\EstadoPlanOperacional;
use App\Enums\PrioridadOperacional;
use App\Enums\RolUsuario;
use App\Enums\TipoPlanOperacional;
use App\Models\CicloArbitrajeManiobras;
use App\Models\DecisionArbitrajeManiobra;
use App\Models\ManiobraOperacional;
use App\Models\PlanOperacional;
use App\Models\Temporada;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReplayCicloPlanificadorApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['planificador.mode' => 'guided']);
    }

    public function test_reproduce_el_ciclo_sin_escribir_y_sin_publicar_identificadores(): void
    {
        [$temporada, $consulta] = $this->contexto();
        $seleccionada = $this->crearManiobra(
            $temporada,
            $consulta,
            'seleccionada',
            'Evacuar pallet prioritario',
            TipoPlanOperacional::EvacuacionEmergencia,
            PrioridadOperacional::Critica,
        );
        $alternativa = $this->crearManiobra(
            $temporada,
            $consulta,
            'alternativa',
            'Preparar pallet alternativo',
            TipoPlanOperacional::ConcentracionCarga,
            PrioridadOperacional::Alta,
        );
        $ciclo = $this->crearCiclo($temporada, 'actual', 1, 2);
        $this->crearDecision(
            $ciclo,
            $seleccionada,
            1,
            DecisionArbitraje::Seleccionada,
            'cupo_disponible',
            40,
            40,
            500,
            2,
            3,
        );
        $this->crearDecision(
            $ciclo,
            $alternativa,
            2,
            DecisionArbitraje::Alternativa,
            'alternativa_sin_reserva',
            20,
            10,
            300,
            1,
            4,
        );

        $camarero = User::factory()->create(['rol' => RolUsuario::CamareroFrio]);
        $this->getJson('/api/operacion-ahora/planificador/replay')->assertUnauthorized();
        $this->actingAs($camarero, 'sanctum')
            ->getJson('/api/operacion-ahora/planificador/replay')
            ->assertForbidden();

        $conteos = $this->conteosReplay();
        $respuesta = $this->actingAs($consulta, 'sanctum')
            ->getJson('/api/operacion-ahora/planificador/replay?ciclo=actual')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.disponible', true)
            ->assertJsonPath('data.estado', 'coincide')
            ->assertJsonPath('data.referencia', 'actual')
            ->assertJsonPath('data.ciclo.reglas', 'arbitraje_global_v4_explicabilidad')
            ->assertJsonPath('data.resumen.decisiones', 2)
            ->assertJsonPath('data.resumen.coinciden', 2)
            ->assertJsonPath('data.resumen.difieren', 0)
            ->assertJsonPath('data.verificaciones.0.maniobra.titulo', 'Evacuar pallet prioritario')
            ->assertJsonPath('data.verificaciones.0.persistido.decision', 'seleccionada')
            ->assertJsonPath('data.verificaciones.0.reproducido.decision', 'seleccionada')
            ->assertJsonPath('data.verificaciones.1.persistido.decision', 'alternativa')
            ->assertJsonCount(0, 'data.verificaciones.0.diferencias')
            ->assertJsonPath('data.truncada', false)
            ->assertJsonPath('data.limite', 100);

        $json = json_encode($respuesta->json('data'), JSON_THROW_ON_ERROR);
        foreach ([$ciclo->id, $seleccionada->id, $alternativa->id] as $uuid) {
            $this->assertStringNotContainsString($uuid, $json);
        }
        $this->assertSame($conteos, $this->conteosReplay());
    }

    public function test_detecta_una_diferencia_y_el_resultado_es_determinista(): void
    {
        [$temporada, $consulta] = $this->contexto();
        $maniobra = $this->crearManiobra(
            $temporada,
            $consulta,
            'alterada',
            'Ubicar pallet verificable',
            TipoPlanOperacional::AlmacenamientoPallet,
            PrioridadOperacional::Alta,
        );
        $ciclo = $this->crearCiclo($temporada, 'alterado', 3, 4);
        $decision = $this->crearDecision(
            $ciclo,
            $maniobra,
            1,
            DecisionArbitraje::Seleccionada,
            'cupo_disponible',
            20,
            10,
            100,
            4,
            2,
        );
        $decision->update(['puntaje' => $decision->puntaje + 7]);

        $primera = $this->actingAs($consulta, 'sanctum')
            ->getJson('/api/operacion-ahora/planificador/replay')
            ->assertOk()
            ->assertJsonPath('data.estado', 'difiere')
            ->assertJsonPath('data.resumen.difieren', 1)
            ->assertJsonPath('data.verificaciones.0.estado', 'difiere')
            ->assertJsonPath('data.verificaciones.0.diferencias.0.campo', 'puntaje')
            ->json('data');
        $segunda = $this->actingAs($consulta, 'sanctum')
            ->getJson('/api/operacion-ahora/planificador/replay')
            ->assertOk()
            ->json('data');

        $this->assertSame($primera, $segunda);
    }

    public function test_explica_ciclos_antiguos_sin_inventar_una_reproduccion(): void
    {
        [$temporada, $consulta] = $this->contexto();
        $maniobra = $this->crearManiobra(
            $temporada,
            $consulta,
            'legada',
            'Maniobra anterior al contrato',
            TipoPlanOperacional::ConcentracionCarga,
            PrioridadOperacional::Normal,
        );
        $ciclo = CicloArbitrajeManiobras::create([
            'temporada_id' => $temporada->id,
            'snapshot_version' => hash('sha256', 'ciclo-legado'),
            'capacidad_ejecucion' => 3,
            'frontera_max' => 4,
            'contexto' => ['version_reglas' => 'arbitraje_global_v3'],
        ]);
        $ciclo->decisiones()->create([
            'maniobra_operacional_id' => $maniobra->id,
            'orden' => 1,
            'decision' => DecisionArbitraje::Seleccionada,
            'puntaje' => 100,
            'beneficio_neto' => 10,
            'motivo' => 'Decisión histórica.',
        ]);

        $this->actingAs($consulta, 'sanctum')
            ->getJson('/api/operacion-ahora/planificador/replay')
            ->assertOk()
            ->assertJsonPath('data.disponible', false)
            ->assertJsonPath('data.estado', 'informacion_insuficiente')
            ->assertJsonPath('data.resumen.insuficientes', 1)
            ->assertJsonCount(0, 'data.verificaciones');
    }

    public function test_permite_reproducir_el_ciclo_anterior_sin_exponer_su_id(): void
    {
        [$temporada, $consulta] = $this->contexto();
        $maniobra = $this->crearManiobra(
            $temporada,
            $consulta,
            'historica',
            'Maniobra con historial',
            TipoPlanOperacional::ConcentracionCarga,
            PrioridadOperacional::Alta,
        );

        $this->travelTo(CarbonImmutable::parse('2026-09-16 10:00:00 UTC'));
        $anterior = $this->crearCiclo($temporada, 'anterior', 3, 4);
        $this->crearDecision(
            $anterior,
            $maniobra,
            1,
            DecisionArbitraje::Seleccionada,
            'cupo_disponible',
            20,
            10,
            100,
            1,
            0,
        );

        $this->travelTo(CarbonImmutable::parse('2026-09-16 10:05:00 UTC'));
        $actual = $this->crearCiclo($temporada, 'actual', 3, 4);
        $this->crearDecision(
            $actual,
            $maniobra,
            1,
            DecisionArbitraje::Seleccionada,
            'cupo_disponible',
            20,
            10,
            100,
            1,
            0,
        );

        $respuesta = $this->actingAs($consulta, 'sanctum')
            ->getJson('/api/operacion-ahora/planificador/replay?ciclo=anterior')
            ->assertOk()
            ->assertJsonPath('data.estado', 'coincide')
            ->assertJsonPath('data.referencia', 'anterior')
            ->assertJsonPath('data.ciclo.generado_at', '2026-09-16T10:00:00+00:00');

        $json = json_encode($respuesta->json('data'), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($anterior->id, $json);
        $this->assertStringNotContainsString($actual->id, $json);

        $this->actingAs($consulta, 'sanctum')
            ->getJson('/api/operacion-ahora/planificador/replay?ciclo=desconocido')
            ->assertUnprocessable();
    }

    /** @return array{Temporada, User} */
    private function contexto(): array
    {
        $temporada = $this->crearTemporadaActivaPrueba([
            'codigo' => 'TEMP-REPLAY-2026',
            'nombre' => 'Temporada replay',
            'fecha_inicio' => '2026-09-01',
            'activa' => true,
        ]);
        $consulta = User::factory()->create(['rol' => RolUsuario::Consulta]);

        return [$temporada, $consulta];
    }

    private function crearManiobra(
        Temporada $temporada,
        User $usuario,
        string $clave,
        string $titulo,
        TipoPlanOperacional $tipo,
        PrioridadOperacional $prioridad,
    ): ManiobraOperacional {
        $plan = PlanOperacional::create([
            'temporada_id' => $temporada->id,
            'tipo' => $tipo,
            'estado' => EstadoPlanOperacional::EnEjecucion,
            'prioridad' => $prioridad,
            'titulo' => 'Objetivo '.$titulo,
            'creado_por_user_id' => $usuario->id,
            'programado_at' => now(),
        ]);

        return ManiobraOperacional::create([
            'plan_operacional_id' => $plan->id,
            'creado_por_user_id' => $usuario->id,
            'estado' => EstadoManiobraOperacional::Pendiente,
            'prioridad' => $prioridad,
            'candidate_key' => 'replay-'.$clave,
            'titulo' => $titulo,
            'costo_movimientos' => 1,
            'beneficio_estimado' => 100,
        ]);
    }

    private function crearCiclo(
        Temporada $temporada,
        string $version,
        int $capacidad,
        int $frontera,
    ): CicloArbitrajeManiobras {
        return CicloArbitrajeManiobras::create([
            'temporada_id' => $temporada->id,
            'snapshot_version' => hash('sha256', 'replay-'.$version),
            'capacidad_ejecucion' => $capacidad,
            'frontera_max' => $frontera,
            'contexto' => [
                'version_reglas' => 'arbitraje_global_v4_explicabilidad',
                'camaras_rollout' => null,
            ],
        ]);
    }

    private function crearDecision(
        CicloArbitrajeManiobras $ciclo,
        ManiobraOperacional $maniobra,
        int $orden,
        DecisionArbitraje $decision,
        string $factor,
        int $pesoPrioridad,
        int $pesoObjetivo,
        int $beneficio,
        int $costo,
        int $riesgo,
    ): DecisionArbitrajeManiobra {
        $neto = $beneficio - $costo - $riesgo;
        $puntaje = ($pesoPrioridad * 1_000_000_000) + ($pesoObjetivo * 10_000_000) + $neto;
        $plan = $maniobra->planOperacional()->firstOrFail();

        return $ciclo->decisiones()->create([
            'maniobra_operacional_id' => $maniobra->id,
            'orden' => $orden,
            'decision' => $decision,
            'puntaje' => $puntaje,
            'beneficio_neto' => $neto,
            'motivo' => 'Decisión reproducible.',
            'conflictos' => [],
            'explicacion' => [
                'version' => 1,
                'reglas' => 'arbitraje_global_v4_explicabilidad',
                'resumen' => 'Decisión reproducible.',
                'factor_decisivo' => ['codigo' => $factor, 'etiqueta' => 'Factor histórico'],
                'componentes' => [
                    'realidad_fisica' => false,
                    'prioridad' => [
                        'valor' => $maniobra->prioridad->value,
                        'peso' => $pesoPrioridad,
                        'aporte' => $pesoPrioridad * 1_000_000_000,
                    ],
                    'objetivo' => [
                        'tipo' => $plan->tipo->value,
                        'titulo' => $plan->titulo,
                        'peso' => $pesoObjetivo,
                        'aporte' => $pesoObjetivo * 10_000_000,
                    ],
                    'beneficio' => [
                        'estimado' => $beneficio,
                        'costo_movimientos' => $costo,
                        'riesgo_operacional' => $riesgo,
                        'neto' => $neto,
                        'aporte' => $neto,
                    ],
                    'puntaje' => $puntaje,
                ],
                'recursos' => ['requeridos' => [], 'conflictos' => []],
                'snapshot' => [
                    'maniobra' => [
                        'id' => $maniobra->id,
                        'version' => 1,
                        'titulo' => $maniobra->titulo,
                        'estado' => EstadoManiobraOperacional::Pendiente->value,
                        'prioridad' => $maniobra->prioridad->value,
                        'creada_at' => $maniobra->created_at->toIso8601String(),
                    ],
                    'objetivo' => [
                        'id' => $plan->id,
                        'version' => 1,
                        'tipo' => $plan->tipo->value,
                        'estado' => EstadoPlanOperacional::EnEjecucion->value,
                        'titulo' => $plan->titulo,
                    ],
                    'objetivos' => [[
                        'id' => $plan->id,
                        'tipo' => $plan->tipo->value,
                        'estado' => EstadoPlanOperacional::EnEjecucion->value,
                        'prioridad' => $maniobra->prioridad->value,
                        'titulo' => $plan->titulo,
                        'beneficio_estimado' => $beneficio,
                    ]],
                    'pasos' => [],
                ],
            ],
        ]);
    }

    /** @return array<int, int> */
    private function conteosReplay(): array
    {
        return [
            CicloArbitrajeManiobras::query()->count(),
            DecisionArbitrajeManiobra::query()->count(),
            ManiobraOperacional::query()->count(),
        ];
    }
}
