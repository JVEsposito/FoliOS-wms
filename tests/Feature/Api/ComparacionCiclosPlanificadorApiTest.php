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

class ComparacionCiclosPlanificadorApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['planificador.mode' => 'guided']);
    }

    public function test_requiere_acceso_gerencial_y_no_escribe_al_consultar(): void
    {
        $temporada = $this->crearTemporada();
        $consulta = User::factory()->create(['rol' => RolUsuario::Consulta]);
        $camarero = User::factory()->create(['rol' => RolUsuario::CamareroFrio]);

        $this->getJson('/api/operacion-ahora/planificador/comparacion')
            ->assertUnauthorized();
        $this->actingAs($camarero, 'sanctum')
            ->getJson('/api/operacion-ahora/planificador/comparacion')
            ->assertForbidden();

        $conteos = [
            CicloArbitrajeManiobras::query()->count(),
            DecisionArbitrajeManiobra::query()->count(),
        ];

        $this->actingAs($consulta, 'sanctum')
            ->getJson('/api/operacion-ahora/planificador/comparacion')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.disponible', false)
            ->assertJsonPath('data.motivo', 'sin_ciclo_actual')
            ->assertJsonPath('data.actual', null)
            ->assertJsonCount(0, 'data.cambios');

        $this->assertSame($conteos, [
            CicloArbitrajeManiobras::query()->count(),
            DecisionArbitrajeManiobra::query()->count(),
        ]);
        $this->assertTrue($temporada->exists);
    }

    public function test_compara_el_ciclo_vigente_con_el_anterior_sin_publicar_uuid(): void
    {
        $temporada = $this->crearTemporada();
        $consulta = User::factory()->create(['rol' => RolUsuario::Consulta]);
        $plan = $this->crearPlan($temporada, $consulta);
        $modificada = $this->crearManiobra($plan, $consulta, 'modificada', 'Despachar pallet prioritario');
        $estable = $this->crearManiobra($plan, $consulta, 'estable', 'Mantener pallet en frontera');
        $retirada = $this->crearManiobra($plan, $consulta, 'retirada', 'Retirar alternativa vencida');
        $incorporada = $this->crearManiobra($plan, $consulta, 'incorporada', 'Incorporar reposición urgente');

        $this->travelTo(CarbonImmutable::parse('2026-09-15 12:00:00 UTC'));
        $anterior = $this->crearCiclo($temporada, 'anterior');
        $this->crearDecision(
            $anterior,
            $modificada,
            3,
            DecisionArbitraje::Alternativa,
            PrioridadOperacional::Alta,
            100,
            20,
            'alternativa_sin_reserva',
            'Esperaba un cupo de ejecución.',
            'PAL-COMP-001',
        );
        $this->crearDecision(
            $anterior,
            $estable,
            2,
            DecisionArbitraje::Seleccionada,
            PrioridadOperacional::Alta,
            80,
            15,
            'cupo_disponible',
            'Conserva el cupo asignado.',
            'PAL-COMP-002',
        );
        $this->crearDecision(
            $anterior,
            $retirada,
            4,
            DecisionArbitraje::FueraFrontera,
            PrioridadOperacional::Normal,
            50,
            5,
            'frontera_completa',
            'No alcanzó la frontera anterior.',
            'PAL-COMP-003',
        );

        $this->travelTo(CarbonImmutable::parse('2026-09-15 12:05:00 UTC'));
        $actual = $this->crearCiclo($temporada, 'actual');
        $this->crearDecision(
            $actual,
            $modificada,
            1,
            DecisionArbitraje::Seleccionada,
            PrioridadOperacional::Urgente,
            150,
            40,
            'cupo_disponible',
            'Subió de prioridad y obtuvo un cupo.',
            'PAL-COMP-001',
        );
        $this->crearDecision(
            $actual,
            $estable,
            2,
            DecisionArbitraje::Seleccionada,
            PrioridadOperacional::Alta,
            80,
            15,
            'cupo_disponible',
            'Conserva el cupo asignado.',
            'PAL-COMP-002',
        );
        $this->crearDecision(
            $actual,
            $incorporada,
            3,
            DecisionArbitraje::Alternativa,
            PrioridadOperacional::Alta,
            70,
            12,
            'alternativa_sin_reserva',
            'Entró como alternativa disponible.',
            'PAL-COMP-004',
        );

        $conteos = [
            CicloArbitrajeManiobras::query()->count(),
            DecisionArbitrajeManiobra::query()->count(),
        ];
        $respuesta = $this->actingAs($consulta, 'sanctum')
            ->getJson('/api/operacion-ahora/planificador/comparacion')
            ->assertOk()
            ->assertJsonPath('data.disponible', true)
            ->assertJsonPath('data.actual.capacidad_ejecucion', 3)
            ->assertJsonPath('data.anterior.frontera_max', 4)
            ->assertJsonPath('data.resumen.incorporadas', 1)
            ->assertJsonPath('data.resumen.retiradas', 1)
            ->assertJsonPath('data.resumen.modificadas', 1)
            ->assertJsonPath('data.resumen.sin_cambios', 1)
            ->assertJsonPath('data.resumen.total_cambios', 3)
            ->assertJsonCount(3, 'data.cambios')
            ->assertJsonPath('data.cambios.0.tipo', 'modificada')
            ->assertJsonPath('data.cambios.0.maniobra.titulo', 'Despachar pallet prioritario')
            ->assertJsonPath('data.cambios.0.maniobra.folio', 'PAL-COMP-001')
            ->assertJsonPath('data.cambios.0.maniobra.ruta', 'Cámara Norte · B01-P01-N1 → Andén 2')
            ->assertJsonPath('data.cambios.0.anterior.decision', 'alternativa')
            ->assertJsonPath('data.cambios.0.actual.decision', 'seleccionada')
            ->assertJsonPath('data.cambios.0.actual.factor_decisivo', 'Cupo de ejecución disponible')
            ->assertJsonFragment([
                'campo' => 'decision',
                'anterior' => 'alternativa',
                'actual' => 'seleccionada',
            ])
            ->assertJsonFragment([
                'campo' => 'orden',
                'anterior' => 3,
                'actual' => 1,
            ])
            ->assertJsonPath('data.cambios.1.tipo', 'incorporada')
            ->assertJsonPath('data.cambios.2.tipo', 'retirada')
            ->assertJsonPath('data.truncada', false)
            ->assertJsonPath('data.limite', 100);

        $json = json_encode($respuesta->json('data'), JSON_THROW_ON_ERROR);
        foreach ([$actual->id, $anterior->id, $modificada->id, $estable->id, $retirada->id, $incorporada->id] as $uuid) {
            $this->assertStringNotContainsString($uuid, $json);
        }
        $this->assertSame($conteos, [
            CicloArbitrajeManiobras::query()->count(),
            DecisionArbitrajeManiobra::query()->count(),
        ]);
    }

    public function test_declara_el_primer_ciclo_confirmado_sin_inventar_cambios(): void
    {
        $temporada = $this->crearTemporada();
        $consulta = User::factory()->create(['rol' => RolUsuario::Consulta]);
        $ciclo = $this->crearCiclo($temporada, 'primero');

        $this->actingAs($consulta, 'sanctum')
            ->getJson('/api/operacion-ahora/planificador/comparacion')
            ->assertOk()
            ->assertJsonPath('data.disponible', false)
            ->assertJsonPath('data.motivo', 'sin_ciclo_anterior')
            ->assertJsonPath('data.detalle', 'Este es el primer ciclo confirmado de la temporada.')
            ->assertJsonPath('data.actual.capacidad_ejecucion', 3)
            ->assertJsonPath('data.anterior', null)
            ->assertJsonPath('data.resumen.total_cambios', 0)
            ->assertJsonCount(0, 'data.cambios');

        $this->assertDatabaseCount('ciclos_arbitraje_maniobras', 1);
        $this->assertSame($ciclo->id, CicloArbitrajeManiobras::query()->sole()->id);
    }

    private function crearTemporada(): Temporada
    {
        return $this->crearTemporadaActivaPrueba([
            'codigo' => 'TEMP-COMP-2026',
            'nombre' => 'Temporada comparación',
            'fecha_inicio' => '2026-09-01',
            'activa' => true,
        ]);
    }

    private function crearPlan(Temporada $temporada, User $usuario): PlanOperacional
    {
        return PlanOperacional::create([
            'temporada_id' => $temporada->id,
            'tipo' => TipoPlanOperacional::ConcentracionCarga,
            'estado' => EstadoPlanOperacional::EnEjecucion,
            'prioridad' => PrioridadOperacional::Alta,
            'titulo' => 'Concentrar carga de comparación',
            'creado_por_user_id' => $usuario->id,
            'programado_at' => now(),
        ]);
    }

    private function crearManiobra(
        PlanOperacional $plan,
        User $usuario,
        string $clave,
        string $titulo,
    ): ManiobraOperacional {
        return ManiobraOperacional::create([
            'plan_operacional_id' => $plan->id,
            'creado_por_user_id' => $usuario->id,
            'estado' => EstadoManiobraOperacional::Pendiente,
            'prioridad' => PrioridadOperacional::Alta,
            'candidate_key' => 'comparacion-'.$clave,
            'titulo' => $titulo,
            'costo_movimientos' => 1,
            'beneficio_estimado' => 100,
        ]);
    }

    private function crearCiclo(Temporada $temporada, string $version): CicloArbitrajeManiobras
    {
        return CicloArbitrajeManiobras::create([
            'temporada_id' => $temporada->id,
            'snapshot_version' => hash('sha256', 'comparacion-'.$version),
            'capacidad_ejecucion' => 3,
            'frontera_max' => 4,
            'contexto' => ['reglas' => 'arbitraje_global_v4_explicabilidad'],
        ]);
    }

    private function crearDecision(
        CicloArbitrajeManiobras $ciclo,
        ManiobraOperacional $maniobra,
        int $orden,
        DecisionArbitraje $decision,
        PrioridadOperacional $prioridad,
        int $puntaje,
        int $beneficioNeto,
        string $factor,
        string $motivo,
        string $folio,
    ): DecisionArbitrajeManiobra {
        $etiquetaFactor = match ($factor) {
            'cupo_disponible' => 'Cupo de ejecución disponible',
            'alternativa_sin_reserva' => 'Alternativa sin reserva',
            default => 'Frontera operacional completa',
        };

        return $ciclo->decisiones()->create([
            'maniobra_operacional_id' => $maniobra->id,
            'orden' => $orden,
            'decision' => $decision,
            'puntaje' => $puntaje,
            'beneficio_neto' => $beneficioNeto,
            'motivo' => $motivo,
            'conflictos' => [],
            'explicacion' => [
                'version' => 1,
                'reglas' => 'arbitraje_global_v4_explicabilidad',
                'resumen' => $motivo,
                'factor_decisivo' => [
                    'codigo' => $factor,
                    'etiqueta' => $etiquetaFactor,
                ],
                'componentes' => [
                    'prioridad' => ['valor' => $prioridad->value],
                    'objetivo' => ['titulo' => 'Concentrar carga de comparación'],
                ],
                'snapshot' => [
                    'maniobra' => [
                        'titulo' => $maniobra->titulo,
                        'estado' => EstadoManiobraOperacional::Pendiente->value,
                        'prioridad' => $prioridad->value,
                    ],
                    'objetivo' => [
                        'titulo' => 'Concentrar carga de comparación',
                    ],
                    'pasos' => [[
                        'secuencia' => 1,
                        'folio' => ['numero_folio' => $folio],
                        'origen' => ['etiqueta' => 'Cámara Norte · B01-P01-N1'],
                        'destino' => ['etiqueta' => 'Andén 2'],
                    ]],
                ],
            ],
        ]);
    }
}
